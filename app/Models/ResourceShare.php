<?php
namespace App\Models;

use App\Models\Database;

/**
 * Model: ResourceShare
 *
 * Compartilhamento seletivo de recursos entre contas.
 * Padrão: "Shared with" do Notion + ACL do Google Drive.
 *
 * Lógica de acesso:
 *   1. Recurso pertence à conta do usuário → acesso total
 *   2. Existe resource_share ativo → acesso com permission_level do share
 *   3. Sem share → acesso negado
 */
class ResourceShare
{
    private static array $validTypes = ['card', 'processo', 'contato', 'task_board'];
    private static array $validPerms = ['view', 'edit', 'full'];

    // ─────────────────────────────────────────────────────────────────────────
    // LEITURA
    // ─────────────────────────────────────────────────────────────────────────

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM resource_shares WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Lista todos os shares de um recurso específico */
    public static function listByResource(string $type, int $resourceId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT rs.*,
                    a.nome AS to_account_nome,
                    u.nome AS criado_por_nome
             FROM resource_shares rs
             LEFT JOIN accounts a ON a.id = rs.to_account_id
             LEFT JOIN users u    ON u.id = rs.criado_por
             WHERE rs.resource_type = :type
               AND rs.resource_id   = :rid
               AND rs.status        = "active"
             ORDER BY rs.created_at DESC'
        );
        $stmt->execute(['type' => $type, 'rid' => $resourceId]);
        return $stmt->fetchAll();
    }

    /** Lista recursos compartilhados COM uma conta (o que ela pode ver de fora) */
    public static function listSharedWithAccount(int $accountId, string $type = null): array
    {
        $pdo  = Database::getConnection();
        $sql  = 'SELECT rs.*, a.nome AS from_account_nome
                 FROM resource_shares rs
                 LEFT JOIN accounts a ON a.id = rs.from_account_id
                 WHERE rs.status = "active"
                   AND (rs.to_account_id = :acc OR rs.to_account_id IS NULL)';
        $params = ['acc' => $accountId];
        if ($type) {
            $sql .= ' AND rs.resource_type = :type';
            $params['type'] = $type;
        }
        $sql .= ' ORDER BY rs.created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Verifica se uma conta tem acesso a um recurso específico.
     * Retorna o permission_level ou null se não tiver acesso.
     */
    public static function getPermission(string $type, int $resourceId, int $forAccountId): ?string
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT permission_level
             FROM resource_shares
             WHERE resource_type = :type
               AND resource_id   = :rid
               AND status        = "active"
               AND (to_account_id = :acc OR to_account_id IS NULL)
             ORDER BY
               FIELD(permission_level, "full", "edit", "view") ASC
             LIMIT 1'
        );
        $stmt->execute(['type' => $type, 'rid' => $resourceId, 'acc' => $forAccountId]);
        $row = $stmt->fetch();
        return $row ? $row['permission_level'] : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ESCRITA
    // ─────────────────────────────────────────────────────────────────────────

    public static function create(array $data): int
    {
        if (!in_array($data['resource_type'], self::$validTypes)) {
            throw new \InvalidArgumentException('resource_type inválido: ' . $data['resource_type']);
        }
        if (!in_array($data['permission_level'] ?? 'view', self::$validPerms)) {
            throw new \InvalidArgumentException('permission_level inválido');
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO resource_shares
               (resource_type, resource_id, from_account_id, to_account_id, to_user_id,
                permission_level, criado_por, status, created_at)
             VALUES
               (:type, :rid, :from, :to, :to_user, :perm, :criado_por, "active", NOW())'
        );
        $stmt->execute([
            'type'       => $data['resource_type'],
            'rid'        => $data['resource_id'],
            'from'       => $data['from_account_id'],
            'to'         => $data['to_account_id'] ?? null,
            'to_user'    => $data['to_user_id'] ?? null,
            'perm'       => $data['permission_level'] ?? 'view',
            'criado_por' => $data['criado_por'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function revogar(int $id, int $revogadoPor): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE resource_shares
             SET status = "revoked", revoked_at = NOW(), revoked_by = :rb
             WHERE id = :id AND status = "active"'
        );
        return $stmt->execute(['rb' => $revogadoPor, 'id' => $id]) && $stmt->rowCount() > 0;
    }

    /** Revoga TODOS os shares de um recurso para uma conta específica */
    public static function revogarTodosParaConta(string $type, int $resourceId, int $toAccountId, int $revogadoPor): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE resource_shares
             SET status = "revoked", revoked_at = NOW(), revoked_by = :rb
             WHERE resource_type = :type
               AND resource_id   = :rid
               AND to_account_id = :to
               AND status        = "active"'
        );
        $stmt->execute(['rb' => $revogadoPor, 'type' => $type, 'rid' => $resourceId, 'to' => $toAccountId]);
        return $stmt->rowCount();
    }
}
