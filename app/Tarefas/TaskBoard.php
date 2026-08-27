<?php
namespace App\Tarefas;

use App\Core\Database;

class TaskBoard
{
    /**
     * Lista os boards visíveis para o usuário DENTRO da sua conta (tenant).
     * Aceita um único accountId (legado) OU um array de account_ids
     * (sessão matriz vê boards das filiais vinculadas).
     */
    public static function findForUser(int $userId, int|array $accountIds): array
    {
        $ids = is_array($accountIds)
            ? array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0))
            : [(int) $accountIds];
        if (empty($ids)) return [];

        $ph     = [];
        $params = ['uid1' => $userId, 'uid2' => $userId];
        foreach ($ids as $i => $aid) {
            $k          = "tbacc_{$i}";
            $ph[]       = ":{$k}";
            $params[$k] = (int) $aid;
        }
        $inSql = implode(',', $ph);

        $pdo  = Database::getConnection();
        // LEFT JOIN com accounts devolve origem (matriz/filial — Nome) por board
        // → permite o frontend renderizar selo visual no seletor de boards.
        $stmt = $pdo->prepare("
            SELECT DISTINCT b.*,
                   a.nome AS origin_account_nome,
                   a.tipo AS origin_account_tipo,
                   b.account_id AS origin_account_id
            FROM task_boards b
            LEFT JOIN task_board_members m ON m.board_id = b.id AND m.user_id = :uid2
            LEFT JOIN accounts a ON a.id = b.account_id
            WHERE b.ativo = 1
              AND b.account_id IN ({$inSql})
              AND (b.owner_id = :uid1 OR m.user_id IS NOT NULL)
            ORDER BY
              CASE WHEN a.tipo = 'matriz' THEN 0 ELSE 1 END,
              a.nome ASC,
              b.ordem, b.id
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Busca board por id.
     * Quando $accountIds é passado, restringe ao tenant (segurança).
     */
    public static function findById(int $id, int|array|null $accountIds = null): array|false
    {
        $pdo = Database::getConnection();
        if ($accountIds === null) {
            $stmt = $pdo->prepare('SELECT * FROM task_boards WHERE id = ? AND ativo = 1');
            $stmt->execute([$id]);
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        $ids = is_array($accountIds)
            ? array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0))
            : [(int)$accountIds];
        if (empty($ids)) return false;
        $ph = []; $params = ['id' => $id];
        foreach ($ids as $i => $aid) { $k = "tbf_{$i}"; $ph[] = ":{$k}"; $params[$k] = (int)$aid; }
        $stmt = $pdo->prepare('SELECT * FROM task_boards WHERE id = :id AND ativo = 1 AND account_id IN (' . implode(',', $ph) . ')');
        $stmt->execute($params);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public static function create(array $data): int
    {
        if (empty($data['account_id'])) {
            throw new \InvalidArgumentException('account_id é obrigatório para criar um board');
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT INTO task_boards (nome, descricao, tipo, owner_id, account_id, cor, ordem)
            VALUES (:nome, :descricao, :tipo, :owner_id, :account_id, :cor, :ordem)
        ');
        $stmt->execute([
            'nome'       => $data['nome'],
            'descricao'  => $data['descricao'] ?? null,
            'tipo'       => $data['tipo'] ?? 'pessoal',
            'owner_id'   => $data['owner_id'],
            'account_id' => $data['account_id'],
            'cor'        => $data['cor'] ?? '#6366f1',
            'ordem'      => $data['ordem'] ?? 0,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo = Database::getConnection();
        $fields = [];
        $params = [];
        foreach (['nome','descricao','cor','ordem','tipo'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = :$f";
                $params[$f] = $data[$f];
            }
        }
        if (!$fields) return;
        $params['id'] = $id;
        $pdo->prepare('UPDATE task_boards SET ' . implode(', ', $fields) . ' WHERE id = :id')
            ->execute($params);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('UPDATE task_boards SET ativo = 0 WHERE id = ?')->execute([$id]);
    }

    public static function members(int $boardId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT m.*, u.nome, u.login
            FROM task_board_members m
            JOIN users u ON u.id = m.user_id
            WHERE m.board_id = ?
        ');
        $stmt->execute([$boardId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function addMember(int $boardId, int $userId, string $papel = 'editor'): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('
            INSERT INTO task_board_members (board_id, user_id, papel)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE papel = VALUES(papel)
        ')->execute([$boardId, $userId, $papel]);
    }

    public static function removeMember(int $boardId, int $userId): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM task_board_members WHERE board_id = ? AND user_id = ?')
            ->execute([$boardId, $userId]);
    }

    /**
     * Pode editar o board?
     *
     * P1 LGPD (2B.3): $accountIds opcional — quando informado, findById restringe
     * ao tenant antes de checar membership. Antes, admin de tenant A podia
     * adicionar-se como member de board de tenant B (IDOR via board_id conhecido).
     */
    public static function canEdit(int $boardId, int $userId, int|array|null $accountIds = null): bool
    {
        $board = self::findById($boardId, $accountIds);
        if (!$board) return false;
        if ((int)$board['owner_id'] === $userId) return true;
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT papel FROM task_board_members WHERE board_id = ? AND user_id = ?');
        $stmt->execute([$boardId, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row && in_array($row['papel'], ['owner','editor']);
    }

    public static function canView(int $boardId, int $userId, int|array|null $accountIds = null): bool
    {
        $board = self::findById($boardId, $accountIds);
        if (!$board) return false;
        if ((int)$board['owner_id'] === $userId) return true;
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM task_board_members WHERE board_id = ? AND user_id = ?');
        $stmt->execute([$boardId, $userId]);
        return (bool)$stmt->fetch();
    }
}
