<?php
namespace App\Tarefas;

use App\Core\Database;

class TaskTimeEntry
{
    public static function findByTask(int $taskId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT te.*, u.nome AS autor
            FROM task_time_entries te
            JOIN users u ON u.id = te.user_id
            WHERE te.task_id = ?
            ORDER BY te.inicio DESC
        ');
        $stmt->execute([$taskId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function startTimer(int $taskId, int $userId): int
    {
        $pdo = Database::getConnection();
        // para qualquer timer aberto do mesmo usuário nessa tarefa
        $pdo->prepare("UPDATE task_time_entries SET fim = NOW(), duracao_minutos = TIMESTAMPDIFF(MINUTE, inicio, NOW()) WHERE task_id = ? AND user_id = ? AND fim IS NULL")
            ->execute([$taskId, $userId]);
        $pdo->prepare('INSERT INTO task_time_entries (task_id, user_id, inicio) VALUES (?,?,NOW())')
            ->execute([$taskId, $userId]);
        return (int)$pdo->lastInsertId();
    }

    public static function stopTimer(int $taskId, int $userId): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare("UPDATE task_time_entries SET fim = NOW(), duracao_minutos = TIMESTAMPDIFF(MINUTE, inicio, NOW()) WHERE task_id = ? AND user_id = ? AND fim IS NULL")
            ->execute([$taskId, $userId]);
    }

    public static function addManual(int $taskId, int $userId, array $data): int
    {
        $pdo = Database::getConnection();
        $pdo->prepare('INSERT INTO task_time_entries (task_id, user_id, inicio, fim, duracao_minutos, valor_hora, descricao) VALUES (?,?,?,?,?,?,?)')
            ->execute([
                $taskId, $userId,
                $data['inicio'],
                $data['fim'] ?? null,
                $data['duracao_minutos'] ?? null,
                $data['valor_hora'] ?? null,
                $data['descricao'] ?? null,
            ]);
        return (int)$pdo->lastInsertId();
    }

    public static function totalMinutos(int $taskId): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(duracao_minutos),0) FROM task_time_entries WHERE task_id = ?');
        $stmt->execute([$taskId]);
        return (int)$stmt->fetchColumn();
    }

    public static function activeTimer(int $taskId, int $userId): array|false
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM task_time_entries WHERE task_id = ? AND user_id = ? AND fim IS NULL LIMIT 1');
        $stmt->execute([$taskId, $userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
