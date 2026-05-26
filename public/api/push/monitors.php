<?php
/**
 * push/monitors.php — CRUD de monitores de intimação.
 *
 * GET    /api/push/monitors.php             → lista monitores da conta
 * POST   /api/push/monitors.php             → cria novo monitor
 * PATCH  /api/push/monitors.php?id=X        → atualiza (status, intervalo, etc)
 * DELETE /api/push/monitors.php?id=X        → exclui
 *
 * Multi-tenant: AccountContext::fromSession() — payload nunca dita account_id.
 * CSRF obrigatório em POST/PATCH/DELETE.
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/PushMonitor.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ErrorReporter.php';
require_once __DIR__ . '/../../../app/Helpers/MonitorQuota.php';  // Etapa 5
require_once __DIR__ . '/../../../app/Helpers/MonitorAudit.php';  // Etapa 5
require_once __DIR__ . '/../../../app/Helpers/MonitorPermission.php'; // audit fix #1
require_once __DIR__ . '/../../../app/Helpers/BillingGuard.php';

use App\Helpers\AccountContext;
use App\Helpers\MonitorQuota;
use App\Helpers\MonitorAudit;
use App\Helpers\MonitorPermission;
use App\Models\Database;
use App\Models\PushMonitor;

session_start(['read_and_close' => true]);
$csrfSession = $_SESSION['csrf_token'] ?? '';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// CSRF check pra mutating methods
if (in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?? [];
    $tokenIn = $payload['_csrf'] ?? ($_GET['_csrf'] ?? '');
    if (!$tokenIn || $tokenIn !== $csrfSession) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF inválido']);
        exit;
    }
}

try {
    $ctx       = AccountContext::fromSession();
    $accountId = $ctx->getAccountId();
    $userId    = (int)$_SESSION['user_id'];
    $pdo       = Database::getConnection();

    switch ($method) {
        case 'GET':
            $rows = PushMonitor::listForAccount($accountId);
            echo json_encode([
                'ok'       => true,
                'monitors' => $rows,
                'total'    => count($rows),
            ]);
            break;

        case 'POST':
            $tipo  = trim((string)($payload['tipo_monitoramento'] ?? 'oab'));
            $valor = trim((string)($payload['valor_monitorado']   ?? ''));
            $uf    = strtoupper(preg_replace('/[^A-Z]/i', '', (string)($payload['uf'] ?? '')));
            $trib  = strtoupper(preg_replace('/[^A-Z0-9-]/i', '', (string)($payload['tribunal'] ?? '')));
            $intervalo = (int)($payload['intervalo_minutos'] ?? 120);

            if (!in_array($tipo, ['oab','processo','nome','customizado'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'tipo_monitoramento inválido']);
                exit;
            }
            if ($valor === '') {
                http_response_code(400);
                echo json_encode(['error' => 'valor_monitorado obrigatório']);
                exit;
            }
            if ($intervalo < 30 || $intervalo > 1440) {
                http_response_code(400);
                echo json_encode(['error' => 'intervalo_minutos deve estar entre 30 e 1440']);
                exit;
            }

            // OAB sem UF separada → tenta extrair do valor (ex: "SP357838")
            if ($tipo === 'oab' && $uf === '' && preg_match('/^([A-Z]{2})(\d+)$/i', $valor, $m)) {
                $uf    = strtoupper($m[1]);
                $valor = ltrim($m[2], '0');
            }

            // ── Guarda de PERMISSÃO (fix #1 auditoria E2E 2026-05-26) ────
            // Bloqueia user sem direito de criar monitoramento (D3/D7):
            //   • owner/admin sempre podem
            //   • advogado: depende da flag accounts.configuracoes.advogado_pode_criar_monitor
            //   • user comum: nunca cria direto — só via pedido (POST requests.php)
            // Antes desse guard, qualquer user com sessão válida e cota
            // disponível conseguia criar monitor via POST forjado.
            MonitorPermission::assertCanCreate($ctx);

            // ── Guarda de cota (Etapa 5 add-on) ─────────────────────────
            // Bloqueia criação se cota esgotada. Resposta 402 (Payment
            // Required) com mensagem amigável "contrate mais monitoramentos".
            // Frontend já desabilita o botão quando cota=0, mas isto é
            // defense-in-depth contra request forjado.
            MonitorQuota::assertCanCreate($accountId);

            $id = PushMonitor::create([
                'account_id'         => $accountId,
                'source_id'          => 'djen',
                'tipo_monitoramento' => $tipo,
                'valor_monitorado'   => $valor,
                'uf'                 => $uf ?: null,
                'tribunal'           => $trib ?: null,
                'status'             => 'ativo',
                'prioridade'         => max(1, min(10, (int)($payload['prioridade'] ?? 5))),
                'intervalo_minutos'  => $intervalo,
                'proxima_consulta_em'=> date('Y-m-d H:i:s'), // dispara já no próximo tick
                'created_by'         => $userId,
            ]);

            // Audit (graceful — fail-soft no helper)
            MonitorAudit::log(
                'monitor_created',
                $accountId,
                (int)$id,
                "Monitor {$tipo} criado: {$valor}" . ($uf ? "/{$uf}" : ''),
                null,
                ['tipo' => $tipo, 'valor' => $valor, 'uf' => $uf]
            );

            echo json_encode(['ok' => true, 'id' => $id]);
            break;

        case 'PATCH':
            $id = (int)($_GET['id'] ?? $payload['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'id obrigatório']);
                exit;
            }

            // Allowlist de campos editáveis
            $sets = [];
            $bind = ['id' => $id, 'acc' => $accountId];

            if (isset($payload['status']) && in_array($payload['status'], ['ativo','pausado','arquivado'], true)) {
                $sets[] = 'status = :status';
                $bind['status'] = $payload['status'];
                // Ao reativar, dispara na próxima execução
                if ($payload['status'] === 'ativo') {
                    $sets[] = 'proxima_consulta_em = NOW()';
                    $sets[] = 'erros_consecutivos = 0';
                    $sets[] = 'ultimo_erro = NULL';
                }
            }
            if (isset($payload['intervalo_minutos'])) {
                $i = (int)$payload['intervalo_minutos'];
                if ($i < 30 || $i > 1440) {
                    http_response_code(400);
                    echo json_encode(['error' => 'intervalo_minutos entre 30 e 1440']);
                    exit;
                }
                $sets[] = 'intervalo_minutos = :int';
                $bind['int'] = $i;
            }
            if (isset($payload['prioridade'])) {
                $sets[] = 'prioridade = :prio';
                $bind['prio'] = max(1, min(10, (int)$payload['prioridade']));
            }
            if (isset($payload['tribunal'])) {
                $sets[] = 'tribunal = :trib';
                $bind['trib'] = strtoupper(preg_replace('/[^A-Z0-9-]/i', '', (string)$payload['tribunal'])) ?: null;
            }

            if (empty($sets)) {
                http_response_code(400);
                echo json_encode(['error' => 'nenhum campo válido pra atualizar']);
                exit;
            }

            $sql = 'UPDATE push_monitors SET ' . implode(', ', $sets)
                 . ' WHERE id = :id AND account_id = :acc';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'monitor não encontrado nesta conta']);
                exit;
            }
            echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
            break;

        case 'DELETE':
            $id = (int)($_GET['id'] ?? $payload['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'id obrigatório']);
                exit;
            }
            $ok = PushMonitor::delete($id, $accountId);
            echo json_encode(['ok' => $ok]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Método não suportado']);
    }

} catch (\Throwable $e) {
    \App\Helpers\ErrorReporter::handle($e);
}
