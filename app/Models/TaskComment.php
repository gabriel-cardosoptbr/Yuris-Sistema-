<?php
namespace App\Models;

class TaskComment
{
    public static function findByTask(int $taskId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT tc.*, u.nome AS autor
            FROM task_comments tc
            JOIN users u ON u.id = tc.user_id
            WHERE tc.task_id = ?
            ORDER BY tc.created_at
        ');
        $stmt->execute([$taskId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function create(int $taskId, int $userId, string $mensagem): int
    {
        $pdo = Database::getConnection();
        $pdo->prepare('INSERT INTO task_comments (task_id, user_id, mensagem) VALUES (?,?,?)')
            ->execute([$taskId, $userId, $mensagem]);
        return (int)$pdo->lastInsertId();
    }

    public static function delete(int $id, int $userId): void
    {
        $pdo = Database::getConnection();
        // só o autor pode deletar
        $pdo->prepare('DELETE FROM task_comments WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    }
}
