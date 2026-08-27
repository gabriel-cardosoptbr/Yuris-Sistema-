<?php
namespace App\Tarefas;

use App\Core\Database;

class TaskColumn
{
    public static function findByBoard(int $boardId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM task_columns WHERE board_id = ? ORDER BY ordem, id');
        $stmt->execute([$boardId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function findById(int $id): array|false
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM task_columns WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        $maxOrdem = $pdo->prepare('SELECT COALESCE(MAX(ordem),0)+1 FROM task_columns WHERE board_id = ?');
        $maxOrdem->execute([$data['board_id']]);
        $ordem = $maxOrdem->fetchColumn();

        $stmt = $pdo->prepare('
            INSERT INTO task_columns (board_id, nome, ordem, cor, is_coluna_inicial, is_coluna_concluido)
            VALUES (:board_id, :nome, :ordem, :cor, :inicial, :concluido)
        ');
        $stmt->execute([
            'board_id'  => $data['board_id'],
            'nome'      => $data['nome'],
            'ordem'     => $data['ordem'] ?? $ordem,
            'cor'       => $data['cor'] ?? '#94a3b8',
            'inicial'   => $data['is_coluna_inicial']   ?? 0,
            'concluido' => $data['is_coluna_concluido'] ?? 0,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo = Database::getConnection();
        $fields = [];
        $params = [];
        foreach (['nome','cor','ordem','is_coluna_inicial','is_coluna_concluido'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = :$f";
                $params[$f] = $data[$f];
            }
        }
        if (!$fields) return;
        $params['id'] = $id;
        $pdo->prepare('UPDATE task_columns SET ' . implode(', ', $fields) . ' WHERE id = :id')
            ->execute($params);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        // só apaga se não tiver tarefas ativas
        $count = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE column_id = ? AND status = 'ativa'");
        $count->execute([$id]);
        if ($count->fetchColumn() > 0) {
            throw new \RuntimeException('Coluna possui tarefas ativas. Mova-as antes de excluir.');
        }
        $pdo->prepare('DELETE FROM task_columns WHERE id = ?')->execute([$id]);
    }

    public static function reorder(int $boardId, array $idsOrdenados): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE task_columns SET ordem = ? WHERE id = ? AND board_id = ?');
        foreach ($idsOrdenados as $pos => $colId) {
            $stmt->execute([$pos + 1, (int)$colId, $boardId]);
        }
    }

    public static function initialColumn(int $boardId): array|false
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM task_columns WHERE board_id = ? AND is_coluna_inicial = 1 ORDER BY ordem LIMIT 1'
        );
        $stmt->execute([$boardId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) return $row;
        // fallback: primeira coluna
        $stmt2 = $pdo->prepare('SELECT * FROM task_columns WHERE board_id = ? ORDER BY ordem LIMIT 1');
        $stmt2->execute([$boardId]);
        return $stmt2->fetch(\PDO::FETCH_ASSOC);
    }
}
