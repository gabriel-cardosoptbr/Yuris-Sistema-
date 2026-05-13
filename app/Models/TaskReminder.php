<?php
namespace App\Models;

class TaskReminder
{
    public static function findByTask(int $taskId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM task_reminders WHERE task_id = ? ORDER BY lembrar_em');
        $stmt->execute([$taskId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function create(int $taskId, int $userId, string $lembrarEm, string $canal = 'sistema'): int
    {
        $pdo = Database::getConnection();
        $pdo->prepare('INSERT INTO task_reminders (task_id, user_id, lembrar_em, canal) VALUES (?,?,?,?)')
            ->execute([$taskId, $userId, $lembrarEm, $canal]);
        return (int)$pdo->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM task_reminders WHERE id = ?')->execute([$id]);
    }

    /** Retorna lembretes pendentes para o cron processar */
    public static function pending(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT r.*, t.titulo AS task_titulo, u.nome AS user_nome, u.login AS user_login
            FROM task_reminders r
            JOIN tasks t ON t.id = r.task_id
            JOIN users u ON u.id = r.user_id
            WHERE r.enviado = 0 AND r.lembrar_em <= NOW()
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function markSent(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('UPDATE task_reminders SET enviado = 1, enviado_em = NOW() WHERE id = ?')->execute([$id]);
    }
}
