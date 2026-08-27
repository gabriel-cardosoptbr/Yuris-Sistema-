<?php
namespace App\Processos;

use App\Core\Database;
use App\Core\RequestId;

/**
 * MonitorAudit — wrapper para inserir em monitor_audit_log.
 *
 * Centraliza logging de ações operacionais relacionadas ao add-on de
 * monitoramentos (cota liberada/reduzida, monitor criado/pausado/
 * cancelado, solicitação criada/aprovada/recusada, etc.).
 *
 * DIFERENÇA PRA MasterAudit:
 *   MasterAudit é EXCLUSIVO de super_admin via Painel Master.
 *   MonitorAudit aceita actor de qualquer role (matriz, filial,
 *   advogado) — desde que tenha sessão. Quando o actor for super_admin
 *   ATUANDO via Painel Master, registra também o super_admin_id.
 *
 * IMUTABILIDADE:
 *   Tabela tem triggers BEFORE UPDATE/DELETE (mig 074) — uma vez
 *   gravado, não dá pra modificar nem apagar (LGPD Art. 37).
 *
 * FAIL-SOFT:
 *   Exceções são silenciadas e logadas em error_log. Auditoria nunca
 *   pode derrubar o fluxo principal.
 *
 * USO TÍPICO:
 *   MonitorAudit::log('monitor_created', $accountId, $monitorId,
 *       'Monitor criado via modal de intimações',
 *       null,
 *       ['tipo' => 'oab', 'valor' => '12345', 'uf' => 'SP']);
 *
 *   MonitorAudit::log('quota_granted', $accountId, null,
 *       'Master liberou 5 monitors via grant promocional',
 *       null,
 *       ['limit' => 5, 'source' => 'master_grant', 'expires_at' => '...']);
 *
 * @since 2026-05-26 (Etapa 2 do add-on Monitoramentos)
 */
final class MonitorAudit
{
    /** Allowlist de ações — deve casar com o ENUM da tabela (mig 074). */
    public const ACOES = [
        'quota_granted', 'quota_reduced',
        'quota_allocated', 'quota_revoked',
        'monitor_created', 'monitor_activated',
        'monitor_paused', 'monitor_canceled',
        'monitor_updated', 'monitor_failed',
        'request_created', 'request_approved',
        'request_denied',
        'permission_changed',
    ];

    /**
     * Insere um registro no monitor_audit_log.
     *
     * @param string      $acao        Slug da ação (deve estar em ACOES)
     * @param int         $accountId   Conta afetada (escopo)
     * @param int|null    $monitorId   push_monitors.id se aplicável
     * @param string|null $descricao   Texto humano descrevendo a ação
     * @param array|null  $dadosAntes  Snapshot antes (ex: {status:'ativo'})
     * @param array|null  $dadosDepois Snapshot depois (ex: {status:'pausado'})
     */
    public static function log(
        string  $acao,
        int     $accountId,
        ?int    $monitorId   = null,
        ?string $descricao   = null,
        ?array  $dadosAntes  = null,
        ?array  $dadosDepois = null
    ): void {
        try {
            // Validação suave — ação fora do allowlist vira no-op pra não
            // estourar trigger / enum. Em dev isso aparece em error_log.
            if (!in_array($acao, self::ACOES, true)) {
                error_log("[MonitorAudit::log] Ação inválida: {$acao}");
                return;
            }

            if (session_status() === PHP_SESSION_NONE) @session_start();

            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $role   = $_SESSION['user_role'] ?? null;

            // Se actor for super_admin operando via Painel Master, captura
            // o super_admin_id pra rastrear que veio de lá (sem deixar de
            // logar — vai pra monitor_audit_log E também pra master_audit_log
            // quando aplicável; quem dispara cada um é responsabilidade do
            // endpoint).
            $saId = null;
            if (!empty($_SESSION['master_mode']) && $userId > 0) {
                try {
                    $pdo = Database::getConnection();
                    $stmt = $pdo->prepare(
                        "SELECT id FROM super_admins
                          WHERE user_id = :uid AND ativo = 1 LIMIT 1"
                    );
                    $stmt->execute(['uid' => $userId]);
                    $saId = (int) ($stmt->fetchColumn() ?: 0) ?: null;
                } catch (\Throwable $_e) {
                    // ignore — fail-soft
                }
            }

            // Carrega RequestId helper pra correlação LGPD
            if (!class_exists('App\\Core\\RequestId')) {
                require_once __DIR__ . '/../Core/RequestId.php';
            }
            $requestId = RequestId::get();

            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                "INSERT INTO monitor_audit_log
                   (account_id, monitor_id, acao, descricao,
                    actor_user_id, actor_role, actor_super_admin_id,
                    ip, user_agent,
                    dados_antes, dados_depois, request_id, created_at)
                 VALUES
                   (:acc, :mon, :acao, :desc,
                    :uid, :role, :sa,
                    :ip, :ua,
                    :da, :dd, :rid, NOW())"
            );
            $stmt->execute([
                'acc'  => $accountId,
                'mon'  => $monitorId,
                'acao' => $acao,
                'desc' => $descricao,
                'uid'  => $userId > 0 ? $userId : null,
                'role' => $role,
                'sa'   => $saId,
                'ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'da'   => $dadosAntes  !== null ? json_encode($dadosAntes,  JSON_UNESCAPED_UNICODE) : null,
                'dd'   => $dadosDepois !== null ? json_encode($dadosDepois, JSON_UNESCAPED_UNICODE) : null,
                'rid'  => $requestId,
            ]);
        } catch (\Throwable $e) {
            error_log('[MonitorAudit::log] ' . $e->getMessage());
        }
    }

    /**
     * Lista registros do audit log com filtros opcionais.
     * Pra UI de "Histórico" no Painel Master e em /configuracoes/monitoramentos.php.
     *
     * @param array $filters  account_id, monitor_id, acao, actor_user_id, from, to
     * @param int   $limit    1..500 (default 100)
     * @return array<int,array<string,mixed>>
     */
    public static function list(array $filters = [], int $limit = 100): array
    {
        try {
            $pdo    = Database::getConnection();
            $where  = ['1=1'];
            $params = [];

            if (!empty($filters['account_id'])) {
                $where[] = 'mal.account_id = :acc';
                $params['acc'] = (int) $filters['account_id'];
            }
            if (!empty($filters['account_ids']) && is_array($filters['account_ids'])) {
                $ph = [];
                foreach ($filters['account_ids'] as $i => $aid) {
                    $k = "macc{$i}";
                    $ph[] = ":{$k}";
                    $params[$k] = (int) $aid;
                }
                if ($ph) $where[] = 'mal.account_id IN (' . implode(',', $ph) . ')';
            }
            if (!empty($filters['monitor_id'])) {
                $where[] = 'mal.monitor_id = :mon';
                $params['mon'] = (int) $filters['monitor_id'];
            }
            if (!empty($filters['acao'])) {
                $where[] = 'mal.acao = :acao';
                $params['acao'] = $filters['acao'];
            }
            if (!empty($filters['actor_user_id'])) {
                $where[] = 'mal.actor_user_id = :uid';
                $params['uid'] = (int) $filters['actor_user_id'];
            }
            if (!empty($filters['from'])) {
                $where[] = 'mal.created_at >= :from';
                $params['from'] = $filters['from'];
            }
            if (!empty($filters['to'])) {
                $where[] = 'mal.created_at <= :to';
                $params['to'] = $filters['to'];
            }

            $limit = max(1, min(500, $limit));
            $sql =
                "SELECT mal.id, mal.account_id, mal.monitor_id, mal.acao,
                        mal.descricao, mal.actor_user_id, mal.actor_role,
                        mal.actor_super_admin_id, mal.ip,
                        mal.dados_antes, mal.dados_depois, mal.created_at,
                        u.nome AS actor_nome,
                        a.nome AS account_nome
                   FROM monitor_audit_log mal
                   LEFT JOIN users u    ON u.id = mal.actor_user_id
                   LEFT JOIN accounts a ON a.id = mal.account_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY mal.created_at DESC, mal.id DESC
                  LIMIT {$limit}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[MonitorAudit::list] ' . $e->getMessage());
            return [];
        }
    }
}
