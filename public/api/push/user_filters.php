<?php
/**
 * push/user_filters.php — Filtros padrão do usuário pra Intimações.
 *
 * GET   /api/push/user_filters.php
 *   → { ok, filters: {nome, oab, oab_uf, nome_advogado}, missing: [campos faltando] }
 *
 * PATCH /api/push/user_filters.php
 *   body: { _csrf, oab?, oab_uf?, nome_advogado?, auto_monitor? }
 *   - Salva os campos enviados em users.{oab, oab_uf, nome_advogado}
 *   - Se auto_monitor=true: cria/atualiza monitores DJEN automaticamente
 *     (1 por OAB e/ou 1 por nome, evitando duplicatas no tenant)
 *
 * Multi-tenant: só atualiza o próprio user da sessão. account_id sempre vem
 * do AccountContext, nunca do body.
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/Processos/PushMonitor.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ErrorReporter.php';
require_once __DIR__ . '/../../../app/Processos/MonitorQuota.php';  // Etapa 5 — graceful
require_once __DIR__ . '/../../../app/Processos/MonitorAudit.php';
require_once __DIR__ . '/../../../app/Billing/BillingGuard.php';
require_once __DIR__ . '/../../../app/Processos/MonitorPermission.php'; // audit fix #2 — bypass D3/D7

use App\Core\AccountContext;
use App\Processos\MonitorQuota;
use App\Processos\MonitorAudit;
use App\Core\Database;
use App\Processos\PushMonitor;

session_start(['read_and_close' => true]);
$csrfSession = $_SESSION['csrf_token'] ?? '';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $ctx       = AccountContext::fromSession();
    $accountId = $ctx->getAccountId();
    $userId    = (int)$_SESSION['user_id'];
    $pdo       = Database::getConnection();

    if ($method === 'GET') {
        $stmt = $pdo->prepare(
            'SELECT nome, oab, oab_uf, nome_advogado
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $filters = [
            'nome'          => (string)($row['nome']          ?? ''),
            'oab'           => (string)($row['oab']           ?? ''),
            'oab_uf'        => (string)($row['oab_uf']        ?? ''),
            'nome_advogado' => (string)($row['nome_advogado'] ?? ''),
        ];

        // Aponta o que está faltando — frontend pode mostrar nudge
        $missing = [];
        if ($filters['oab']    === '') $missing[] = 'oab';
        if ($filters['oab_uf'] === '') $missing[] = 'oab_uf';
        if ($filters['nome_advogado'] === '' && $filters['nome'] === '') $missing[] = 'nome_advogado';

        echo json_encode([
            'ok'           => true,
            'filters'      => $filters,
            'missing'      => $missing,
            // has_monitors = TENANT tem qualquer monitor ativo (independente de quem criou).
            // Antes filtrava por created_by=userId, mas isso fazia o modal "Configure perfil"
            // abrir indevidamente quando outro user do mesmo tenant já tinha configurado.
            'has_monitors' => PushUserFiltersHelpers::countMonitorsForTenant($pdo, $accountId) > 0,
        ]);
        exit;
    }

    if ($method === 'PATCH' || $method === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true) ?? [];

        if (empty($payload['_csrf']) || $payload['_csrf'] !== $csrfSession) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF inválido']);
            exit;
        }

        // Sanitização e allowlist
        $oab        = isset($payload['oab'])           ? preg_replace('/[^0-9]/', '', (string)$payload['oab']) : null;
        $uf         = isset($payload['oab_uf'])        ? strtoupper(preg_replace('/[^A-Z]/i', '', (string)$payload['oab_uf'])) : null;
        $nomeAdv    = isset($payload['nome_advogado']) ? trim(mb_substr((string)$payload['nome_advogado'], 0, 200)) : null;
        $autoMon    = !empty($payload['auto_monitor']);

        // Aceita "SP357838" no campo OAB → extrai UF
        if ($oab && preg_match('/^[A-Z]{2}/', (string)($payload['oab'] ?? ''), $m)) {
            $uf  = $uf ?: strtoupper($m[0]);
            $oab = ltrim($oab, '0');
        }
        if ($uf && strlen($uf) > 2) $uf = substr($uf, 0, 2);

        $sets   = [];
        $bind   = ['id' => $userId];
        if ($oab !== null)     { $sets[] = 'oab = :oab';                 $bind['oab'] = $oab ?: null; }
        if ($uf !== null)      { $sets[] = 'oab_uf = :uf';               $bind['uf']  = $uf  ?: null; }
        if ($nomeAdv !== null) { $sets[] = 'nome_advogado = :nadv';      $bind['nadv'] = $nomeAdv ?: null; }

        if (!empty($sets)) {
            $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($bind);
        }

        // Re-lê estado atualizado
        $stmt = $pdo->prepare(
            'SELECT nome, oab, oab_uf, nome_advogado FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $cur = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $monitorsCreated = [];
        if ($autoMon) {
            // Fix #2 (auditoria E2E 2026-05-26): respeita D3/D7. Antes, qualquer
            // user que salvasse perfil (oab+nome) tinha monitor auto-criado,
            // mesmo advogado com flag advogado_pode_criar_monitor=false ou user
            // perfil='user' sem permissão. Graceful — salva o perfil mesmo
            // sem criar monitor e retorna aviso na resposta.
            if (!\App\Processos\MonitorPermission::canCreate($ctx)) {
                $monitorsCreated[] = [
                    'tipo'    => 'oab_or_nome',
                    'valor'   => trim((string)($cur['oab_uf'] ?? '') . (string)($cur['oab'] ?? '')),
                    'monitor_id' => null,
                    'novo'    => false,
                    'label'   => 'sem_permissao',
                    'warning' => 'Perfil salvo, mas você não tem permissão para criar monitoramentos. Peça ao administrador da conta.',
                ];
            } else {
                $monitorsCreated = PushUserFiltersHelpers::createMonitorsForUser($pdo, $accountId, $userId, $cur);
            }
        }

        echo json_encode([
            'ok'                => true,
            'filters'           => [
                'nome'          => (string)($cur['nome']          ?? ''),
                'oab'           => (string)($cur['oab']           ?? ''),
                'oab_uf'        => (string)($cur['oab_uf']        ?? ''),
                'nome_advogado' => (string)($cur['nome_advogado'] ?? ''),
            ],
            'monitors_created'  => $monitorsCreated,
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Método não suportado (use GET ou PATCH)']);

} catch (\Throwable $e) {
    \App\Core\ErrorReporter::handle($e);
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS LOCAIS (escopo de arquivo via classe estática anônima)
// ─────────────────────────────────────────────────────────────────────────────
class PushUserFiltersHelpers {
    /**
     * Conta monitores ativos do TENANT (qualquer user do mesmo account_id).
     * Usado pra decidir se o modal "Configure perfil" deve aparecer no buscarHoje:
     * se algum user já configurou monitor pro tenant, ninguém precisa configurar de novo.
     */
    public static function countMonitorsForTenant(\PDO $pdo, int $accountId): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM push_monitors
             WHERE account_id = :acc AND status IN ('ativo','pausado')"
        );
        $stmt->execute(['acc' => $accountId]);
        return (int)$stmt->fetchColumn();
    }

    /** Conta apenas monitores criados pelo próprio user (uso interno se precisar). */
    public static function countMonitorsForUser(\PDO $pdo, int $accountId, int $userId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM push_monitors
             WHERE account_id = :acc AND created_by = :u'
        );
        $stmt->execute(['acc' => $accountId, 'u' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Cria/atualiza monitor DJEN do perfil do user.
     *
     * REGRA: busca COMBINADA (AND) tem precisão muito maior. Em vez de criar
     * 2 monitores separados (1 OAB + 1 nome), unificamos:
     *
     *   - Se tem OAB e Nome → 1 monitor tipo='oab' com nome_complementar=Nome
     *     (DJEN: numeroOab=X AND ufOab=Y AND nomeAdvogado=Z)
     *   - Se tem só OAB     → 1 monitor tipo='oab' (sem nome)
     *   - Se tem só Nome    → 1 monitor tipo='nome' (sem OAB)
     *
     * MIGRAÇÃO: se já existem monitores antigos separados (1 OAB + 1 nome)
     * pro mesmo user, atualiza o OAB com nome_complementar e remove o de nome
     * (cleanup automático — idempotente).
     *
     * Retorna lista resumo: [{tipo, valor, monitor_id, novo: bool, label}]
     */
    public static function createMonitorsForUser(\PDO $pdo, int $accountId, int $userId, array $cur): array
    {
        $oab  = trim((string)($cur['oab'] ?? ''));
        $uf   = trim((string)($cur['oab_uf'] ?? ''));
        $nome = trim((string)($cur['nome_advogado'] ?? '')) ?: trim((string)($cur['nome'] ?? ''));

        $created = [];

        if ($oab === '' && $nome === '') return $created;

        // ── CASO 1: TEM OAB (combinada ou pura) ──────────────────────────────
        if ($oab !== '') {
            $existing = $pdo->prepare(
                "SELECT id, nome_complementar FROM push_monitors
                 WHERE account_id = :acc AND tipo_monitoramento = 'oab'
                   AND valor_monitorado = :val AND COALESCE(uf, '') = :uf LIMIT 1"
            );
            $existing->execute(['acc' => $accountId, 'val' => $oab, 'uf' => $uf]);
            $row = $existing->fetch(\PDO::FETCH_ASSOC);
            $existingId = $row ? (int)$row['id'] : 0;
            $existingNome = $row ? (string)($row['nome_complementar'] ?? '') : '';

            if ($existingId) {
                // Atualiza nome_complementar se mudou
                if ($nome !== '' && $existingNome !== $nome) {
                    PushMonitor::setNomeComplementar($existingId, $accountId, $nome);
                    $created[] = ['tipo' => 'oab+nome', 'valor' => "{$uf}{$oab}", 'nome' => $nome, 'monitor_id' => $existingId, 'novo' => false, 'label' => 'OAB+Nome atualizado'];
                } else {
                    $created[] = ['tipo' => $nome ? 'oab+nome' : 'oab', 'valor' => "{$uf}{$oab}", 'nome' => $existingNome, 'monitor_id' => $existingId, 'novo' => false, 'label' => 'já existia'];
                }
            } else {
                // ── Cota (Etapa 5) ────────────────────────────────────
                // Auto-monitor é GRACEFUL: se cota esgotada, salva o
                // perfil mas não cria monitor + retorna aviso na resposta.
                // NÃO usa assertCanCreate (que mata o request com 402).
                if (MonitorQuota::getAvailable($accountId) <= 0) {
                    $created[] = [
                        'tipo' => 'oab', 'valor' => "{$uf}{$oab}",
                        'nome' => $nome, 'monitor_id' => null,
                        'novo' => false, 'label' => 'sem_cota',
                        'warning' => 'Cota esgotada. Contrate mais monitoramentos para criar este monitor automaticamente.',
                    ];
                } else {
                    $newId = PushMonitor::create([
                        'account_id'         => $accountId,
                        'source_id'          => 'djen',
                        'tipo_monitoramento' => 'oab',
                        'valor_monitorado'   => $oab,
                        'uf'                 => $uf ?: null,
                        'nome_complementar'  => $nome ?: null,
                        'status'             => 'ativo',
                        'intervalo_minutos'  => 120,
                        'proxima_consulta_em'=> date('Y-m-d H:i:s'),
                        'created_by'         => $userId,
                    ]);
                    MonitorAudit::log('monitor_created', $accountId, (int)$newId,
                        "Auto-monitor OAB criado: {$uf}{$oab}" . ($nome ? " (+nome: {$nome})" : ''),
                        null, ['tipo' => 'oab', 'valor' => $oab, 'uf' => $uf]);
                    $created[] = ['tipo' => $nome ? 'oab+nome' : 'oab', 'valor' => "{$uf}{$oab}", 'nome' => $nome, 'monitor_id' => $newId, 'novo' => true, 'label' => 'criado'];
                }
            }

            // Cleanup: se tem monitor antigo tipo='nome' redundante (mesmo nome do user), remove
            if ($nome !== '') {
                $del = $pdo->prepare(
                    "DELETE FROM push_monitors
                     WHERE account_id = :acc AND tipo_monitoramento = 'nome'
                       AND valor_monitorado = :nome"
                );
                $del->execute(['acc' => $accountId, 'nome' => $nome]);
                if ($del->rowCount() > 0) {
                    $created[] = ['tipo' => 'cleanup', 'valor' => $nome, 'removed' => $del->rowCount(), 'label' => 'monitor nome redundante removido (já combinado em OAB)'];
                }
            }
            return $created;
        }

        // ── CASO 2: SÓ TEM NOME (sem OAB) ────────────────────────────────────
        $existing = $pdo->prepare(
            "SELECT id FROM push_monitors
             WHERE account_id = :acc AND tipo_monitoramento = 'nome'
               AND valor_monitorado = :val LIMIT 1"
        );
        $existing->execute(['acc' => $accountId, 'val' => $nome]);
        $existingId = (int)$existing->fetchColumn();

        if ($existingId) {
            $created[] = ['tipo' => 'nome', 'valor' => $nome, 'monitor_id' => $existingId, 'novo' => false, 'label' => 'já existia'];
        } else {
            // ── Cota graceful (Etapa 5) — mesmo do CASO 1 ──────────────
            if (MonitorQuota::getAvailable($accountId) <= 0) {
                $created[] = [
                    'tipo' => 'nome', 'valor' => $nome,
                    'monitor_id' => null, 'novo' => false,
                    'label' => 'sem_cota',
                    'warning' => 'Cota esgotada. Contrate mais monitoramentos para criar este monitor automaticamente.',
                ];
            } else {
                $newId = PushMonitor::create([
                    'account_id'         => $accountId,
                    'source_id'          => 'djen',
                    'tipo_monitoramento' => 'nome',
                    'valor_monitorado'   => $nome,
                    'status'             => 'ativo',
                    'intervalo_minutos'  => 240,
                    'proxima_consulta_em'=> date('Y-m-d H:i:s'),
                    'created_by'         => $userId,
                ]);
                MonitorAudit::log('monitor_created', $accountId, (int)$newId,
                    "Auto-monitor nome criado: {$nome}",
                    null, ['tipo' => 'nome', 'valor' => $nome]);
                $created[] = ['tipo' => 'nome', 'valor' => $nome, 'monitor_id' => $newId, 'novo' => true, 'label' => 'criado'];
            }
        }
        return $created;
    }
}
