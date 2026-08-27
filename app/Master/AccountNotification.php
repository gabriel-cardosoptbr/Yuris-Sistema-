<?php
namespace App\Master;

use App\Core\Database;

/**
 * Model: AccountNotification
 *
 * Central de notificações internas por conta.
 * Padrão: Linear notification inbox / Slack notification center.
 */
class AccountNotification
{
    // ─────────────────────────────────────────────────────────────────────────
    // LEITURA
    // ─────────────────────────────────────────────────────────────────────────

    /** Lista notificações de um usuário (inbox pessoal + notificações da conta) */
    public static function listForUser(int $userId, int $accountId, bool $apenasNaoLidas = false): array
    {
        $pdo  = Database::getConnection();
        $sql  = 'SELECT * FROM account_notifications
                 WHERE account_id = :acc
                   AND (user_id = :uid OR user_id IS NULL)';
        if ($apenasNaoLidas) $sql .= ' AND lida = 0';
        $sql .= ' ORDER BY created_at DESC LIMIT 50';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['acc' => $accountId, 'uid' => $userId]);
        return $stmt->fetchAll();
    }

    public static function countNaoLidas(int $userId, int $accountId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM account_notifications
             WHERE account_id = :acc AND (user_id = :uid OR user_id IS NULL) AND lida = 0'
        );
        $stmt->execute(['acc' => $accountId, 'uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ESCRITA
    // ─────────────────────────────────────────────────────────────────────────

    public static function criar(array $data): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO account_notifications
               (account_id, user_id, tipo, titulo, mensagem, payload, created_at)
             VALUES
               (:acc, :uid, :tipo, :titulo, :msg, :payload, NOW())'
        );
        $stmt->execute([
            'acc'     => $data['account_id'],
            'uid'     => $data['user_id']   ?? null,
            'tipo'    => $data['tipo'],
            'titulo'  => $data['titulo'],
            'msg'     => $data['mensagem']  ?? null,
            'payload' => isset($data['payload']) ? json_encode($data['payload']) : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function marcarLida(int $id, int $userId, int $accountId): bool
    {
        // Escopo por tenant (Auditoria 2026-06-01, BAIXA #3): notificações de
        // conta (user_id IS NULL) poderiam ser marcadas por usuário de outro
        // tenant adivinhando o id. Exigir account_id fecha essa escrita cross-tenant.
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE account_notifications
             SET lida = 1, lida_em = NOW()
             WHERE id = :id AND account_id = :acc AND (user_id = :uid OR user_id IS NULL)'
        );
        return $stmt->execute(['id' => $id, 'acc' => $accountId, 'uid' => $userId]);
    }

    public static function marcarTodasLidas(int $userId, int $accountId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE account_notifications
             SET lida = 1, lida_em = NOW()
             WHERE account_id = :acc AND (user_id = :uid OR user_id IS NULL) AND lida = 0'
        );
        $stmt->execute(['acc' => $accountId, 'uid' => $userId]);
        return $stmt->rowCount();
    }
}
