<?php
namespace App\Models;

class TaskChecklist
{
    public static function findByTask(int $taskId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM task_checklist_items WHERE task_id = ? ORDER BY ordem, id');
        $stmt->execute([$taskId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function create(int $taskId, string $descricao, ?string $prazo = null): int
    {
        $pdo = Database::getConnection();

        // verifica se coluna prazo existe; adiciona se não existir
        $hasPrazo = false;
        try {
            $chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'task_checklist_items'
                  AND COLUMN_NAME  = 'prazo'");
            $hasPrazo = (int)$chk->fetchColumn() > 0;
            if (!$hasPrazo) {
                $pdo->exec('ALTER TABLE task_checklist_items ADD COLUMN prazo DATE DEFAULT NULL');
                $hasPrazo = true;
            }
        } catch (\Throwable $e) {
            $hasPrazo = false;
        }

        $maxOrdem = $pdo->prepare('SELECT COALESCE(MAX(ordem),0)+1 FROM task_checklist_items WHERE task_id = ?');
        $maxOrdem->execute([$taskId]);
        $ordem = $maxOrdem->fetchColumn();

        if ($hasPrazo) {
            $pdo->prepare('INSERT INTO task_checklist_items (task_id, descricao, prazo, ordem) VALUES (?,?,?,?)')
                ->execute([$taskId, $descricao, $prazo ?: null, $ordem]);
        } else {
            $pdo->prepare('INSERT INTO task_checklist_items (task_id, descricao, ordem) VALUES (?,?,?)')
                ->execute([$taskId, $descricao, $ordem]);
        }
        return (int)$pdo->lastInsertId();
    }

    public static function toggle(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('UPDATE task_checklist_items SET concluido = NOT concluido WHERE id = ?')->execute([$id]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM task_checklist_items WHERE id = ?')->execute([$id]);
    }

    public static function update(int $id, string $descricao, ?string $prazo): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('UPDATE task_checklist_items SET descricao = ?, prazo = ? WHERE id = ?')
            ->execute([$descricao, $prazo ?: null, $id]);
    }

    public static function reorder(int $taskId, array $ids): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE task_checklist_items SET ordem = ? WHERE id = ? AND task_id = ?');
        foreach ($ids as $pos => $id) {
            $stmt->execute([$pos + 1, (int)$id, $taskId]);
        }
    }
}
