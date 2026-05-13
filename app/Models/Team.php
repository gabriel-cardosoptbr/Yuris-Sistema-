<?php
namespace App\Models;

class Team
{
    /**
     * Retorna os times visíveis para um usuário na aba de filtro.
     *
     * - owner/admin  → vê TODOS os times da conta
     * - demais roles → vê apenas os times dos quais é membro
     *
     * Usado pelo frontend para popular o dropdown de filtro de setores.
     */
    public static function getTeamsForUser(int $userId, int $accountId, bool $isAdmin = false): array
    {
        $pdo = Database::getConnection();

        if ($isAdmin) {
            // owner/admin enxerga todos os times
            $stmt = $pdo->prepare(
                'SELECT t.id, t.nome, t.cor, t.descricao,
                        (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) AS membros_count
                 FROM teams t
                 WHERE t.account_id = ? AND t.deleted_at IS NULL
                 ORDER BY t.nome ASC'
            );
            $stmt->execute([$accountId]);
        } else {
            // usuário comum vê somente os times dos quais faz parte
            $stmt = $pdo->prepare(
                'SELECT t.id, t.nome, t.cor, t.descricao,
                        (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) AS membros_count
                 FROM teams t
                 INNER JOIN team_members m ON m.team_id = t.id AND m.user_id = :uid
                 WHERE t.account_id = :acc AND t.deleted_at IS NULL
                 ORDER BY t.nome ASC'
            );
            $stmt->execute(['uid' => $userId, 'acc' => $accountId]);
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['membros_count'] = (int)$row['membros_count'];
        }
        return $rows;
    }


    public static function list(int $accountId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT t.id, t.nome, t.cor, t.descricao, t.created_at,
                    (SELECT GROUP_CONCAT(tm.user_id ORDER BY tm.user_id SEPARATOR ",")
                     FROM team_members tm WHERE tm.team_id = t.id) AS membro_ids,
                    (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) AS membros_count
             FROM teams t
             WHERE t.account_id = ? AND t.deleted_at IS NULL
             ORDER BY t.nome ASC'
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['membro_ids']    = $row['membro_ids']
                ? array_map('intval', explode(',', $row['membro_ids']))
                : [];
            $row['membros_count'] = (int)$row['membros_count'];
        }
        return $rows;
    }

    public static function findById(int $id, int $accountId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, nome, cor, descricao, created_at, updated_at
             FROM teams WHERE id = ? AND account_id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$id, $accountId]);
        $team = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$team) return null;

        $mStmt = $pdo->prepare('SELECT user_id FROM team_members WHERE team_id = ?');
        $mStmt->execute([$id]);
        $team['membro_ids'] = array_map('intval', $mStmt->fetchAll(\PDO::FETCH_COLUMN));
        return $team;
    }

    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO teams (account_id, nome, cor, descricao, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $data['account_id'],
            $data['nome'],
            $data['cor']      ?? '#3B82F6',
            $data['descricao'] ?? null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $pdo = Database::getConnection();
        $allowed = ['nome', 'cor', 'descricao'];
        $fields  = [];
        $params  = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (!$fields) return true;
        $params[] = $id;
        return $pdo->prepare('UPDATE teams SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?')
                   ->execute($params);
    }

    public static function delete(int $id, int $accountId): bool
    {
        $pdo = Database::getConnection();
        return $pdo->prepare('UPDATE teams SET deleted_at = NOW() WHERE id = ? AND account_id = ?')
                   ->execute([$id, $accountId]);
    }

    public static function setMembers(int $teamId, array $userIds): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM team_members WHERE team_id = ?')->execute([$teamId]);
        if ($userIds) {
            $ins = $pdo->prepare('INSERT IGNORE INTO team_members (team_id, user_id) VALUES (?, ?)');
            foreach ($userIds as $uid) {
                $ins->execute([$teamId, (int)$uid]);
            }
        }
    }
}
