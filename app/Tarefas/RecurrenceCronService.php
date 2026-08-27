<?php
namespace App\Tarefas;

use App\Core\Database;
use App\Tarefas\Task;
use App\Tarefas\TaskColumn;
use App\Tarefas\TaskRecurrence;

/**
 * RecurrenceCronService
 *
 * Dispara a geração de instâncias de tarefas recorrentes diretamente
 * dentro do processo PHP — sem cron externo, sem Task Scheduler.
 *
 * Funciona como piggyback: é chamado a cada GET /api/tasks.php?board_id=X
 * mas só executa a lógica real uma vez por hora (controlado por arquivo de lock).
 *
 * Fluxo:
 *   1. Verifica se o lock file tem menos de 1 hora → se sim, sai imediatamente.
 *   2. Atualiza o lock file com o timestamp atual.
 *   3. Varre task_recurrences ativas e cria instâncias atrasadas que não existem.
 */
class RecurrenceCronService
{
    private const LOCK_FILE    = __DIR__ . '/../../storage/recurrence_cron.lock';
    private const INTERVAL_SEC = 3600; // 1 hora — ajuste se quiser rodar com mais frequência

    /**
     * Ponto de entrada. Silencioso: nunca lança exceção para o caller.
     */
    public static function tickIfDue(): void
    {
        try {
            if (!self::isDue()) return;
            self::updateLock();
            self::run();
        } catch (\Throwable $e) {
            // falha silenciosa — não pode quebrar a resposta principal
            error_log('[RecurrenceCronService] ' . $e->getMessage());
        }
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    private static function isDue(): bool
    {
        $file = self::LOCK_FILE;
        if (!file_exists($file)) return true;
        return (time() - (int)file_get_contents($file)) >= self::INTERVAL_SEC;
    }

    private static function updateLock(): void
    {
        $dir = dirname(self::LOCK_FILE);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents(self::LOCK_FILE, (string)time(), LOCK_EX);
    }

    private static function run(): void
    {
        $pdo = Database::getConnection();

        // Busca tarefas recorrentes ativas com prazo já vencido.
        // Isso cobre casos em que o usuário não clicou "Concluir" manualmente —
        // o cron avança o prazo in-place para a próxima ocorrência.
        $stmt = $pdo->prepare("
            SELECT t.*
            FROM tasks t
            JOIN task_recurrences tr ON tr.id = t.recorrencia_id
            WHERE t.recorrencia_id IS NOT NULL
              AND t.status = 'ativa'
              AND t.prazo < NOW()
              AND tr.ativa = 1
        ");
        $stmt->execute();
        $tarefas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($tarefas as $taskData) {
            try {
                $rec = TaskRecurrence::loadById((int)$taskData['recorrencia_id']);
                if (!$rec) continue;

                $proximaData = $rec->calcularProximaData($taskData['prazo'] ?? date('Y-m-d'));

                // data_fim atingida → desativa a recorrência
                if ($proximaData === '' || $proximaData === false) {
                    TaskRecurrence::deactivate((int)$taskData['recorrencia_id']);
                    continue;
                }

                // Renova in-place: mesma coluna, mesmo card, só avança o prazo
                $pdo->prepare(
                    "UPDATE tasks SET prazo = ? WHERE id = ?"
                )->execute([$proximaData, $taskData['id']]);

                Task::history(
                    (int)$taskData['id'],
                    null,
                    'renovada_auto',
                    ['prazo' => $taskData['prazo']],
                    ['prazo' => $proximaData]
                );

            } catch (\Throwable $e) {
                error_log('[RecurrenceCronService] tarefa #' . $taskData['id'] . ': ' . $e->getMessage());
            }
        }
    }
}
