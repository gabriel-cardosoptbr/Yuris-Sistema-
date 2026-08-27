<?php
namespace App\Master;

use App\Core\Database;
use App\Core\RequestId;

/**
 * MasterAudit — wrapper para inserir em master_audit_log.
 *
 * Centraliza logging de ações executadas no Painel Master.
 * Lê automaticamente super_admin_id a partir do user_id da sessão,
 * captura IP e serializa metadata em JSON.
 *
 * Uso típico:
 *   MasterAudit::log('account.create', 'account', $accountId, 'Nova conta criada via Painel Master', [
 *       'nome' => $nome, 'plano' => $plano
 *   ]);
 *
 * Falhas são silenciosas — auditoria NUNCA derruba o fluxo principal.
 */
final class MasterAudit
{
    /**
     * Insere um registro no master_audit_log.
     *
     * @param string $acao        Slug da ação. Ex: 'account.create', 'plan.update', 'payment.mark_paid'
     * @param string|null $type   Tipo do alvo. Ex: 'account', 'plan', 'subscription', 'invoice', 'user'
     * @param int|null $id        ID do alvo afetado
     * @param string $descricao   Texto humano descrevendo o que aconteceu
     * @param array  $metadata    JSON com detalhes extras (campos alterados, valores antes/depois, etc.)
     */
    public static function log(
        string $acao,
        ?string $type = null,
        ?int $id = null,
        string $descricao = '',
        array $metadata = []
    ): void {
        try {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            if ($userId <= 0) return; // sem sessão → sem log

            $pdo = Database::getConnection();

            // Resolve super_admin_id a partir do user_id
            $stmt = $pdo->prepare("SELECT id FROM super_admins WHERE user_id = :uid AND ativo = 1 LIMIT 1");
            $stmt->execute(['uid' => $userId]);
            $saId = (int) $stmt->fetchColumn();
            if ($saId <= 0) return; // user não é super_admin → não loga

            $payload = !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;

            // LGPD Etapa 4: user_agent + request_id pra trilha forense
            if (!class_exists('App\\Core\\RequestId')) {
                require_once __DIR__ . '/../Core/RequestId.php';
            }

            $ins = $pdo->prepare(
                "INSERT INTO master_audit_log
                   (super_admin_id, acao, target_type, target_id, descricao, ip, user_agent, request_id, metadata, created_at)
                 VALUES (:saId, :acao, :tt, :tid, :desc, :ip, :ua, :rid, :meta, NOW())"
            );
            $ins->execute([
                'saId' => $saId,
                'acao' => $acao,
                'tt'   => $type,
                'tid'  => $id,
                'desc' => $descricao,
                'ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'rid'  => RequestId::get(),
                'meta' => $payload,
            ]);
        } catch (\Throwable $e) {
            error_log('[MasterAudit::log] ' . $e->getMessage());
        }
    }

    /**
     * Lista registros do audit log com filtros opcionais.
     * Faz JOIN em super_admins + users pra trazer nome do operador.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function list(array $filters = [], int $limit = 100): array
    {
        try {
            $pdo = Database::getConnection();
            $where  = ['1=1'];
            $params = [];

            if (!empty($filters['super_admin_id'])) {
                $where[] = 'mal.super_admin_id = :saId';
                $params['saId'] = (int) $filters['super_admin_id'];
            }
            if (!empty($filters['target_type'])) {
                $where[] = 'mal.target_type = :tt';
                $params['tt'] = $filters['target_type'];
            }
            if (!empty($filters['target_id'])) {
                $where[] = 'mal.target_id = :tid';
                $params['tid'] = (int) $filters['target_id'];
            }
            if (!empty($filters['acao'])) {
                $where[] = 'mal.acao LIKE :acao';
                $params['acao'] = '%' . $filters['acao'] . '%';
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
                "SELECT mal.id, mal.acao, mal.target_type, mal.target_id, mal.descricao,
                        mal.ip, mal.metadata, mal.created_at,
                        sa.id AS sa_id, sa.nivel AS sa_nivel,
                        u.id  AS user_id, u.nome AS user_nome
                 FROM master_audit_log mal
                 LEFT JOIN super_admins sa ON sa.id = mal.super_admin_id
                 LEFT JOIN users u        ON u.id  = sa.user_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY mal.created_at DESC, mal.id DESC
                 LIMIT {$limit}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[MasterAudit::list] ' . $e->getMessage());
            return [];
        }
    }
}
