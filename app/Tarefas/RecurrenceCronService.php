<?php
namespace App\Tarefas;

use App\Core\Database;
use App\Tarefas\Task;
use App\Tarefas\TaskColumn;
use App\Tarefas\TaskRecurrence;

/**
 * RecurrenceCronService — renova tarefas recorrentes vencidas.
 *
 * ---------------------------------------------------------------------------
 * ELE NÃO RODA MAIS DENTRO DA REQUISIÇÃO DO USUÁRIO
 * ---------------------------------------------------------------------------
 * O desenho original era um "piggyback": `GET /api/tasks.php` chamava
 * `tickIfDue()` e, no máximo uma vez por hora, o trabalho era feito ali mesmo.
 * A hora era controlada por um arquivo de trava em `storage/`.
 *
 * Em produção isso desandou de um jeito que ninguém veria olhando o código:
 *
 *   - `storage/` pertencia ao root, e o Apache NÃO conseguia escrever nela
 *   - `updateLock()` falhava, e a falha era engolida pelo try/catch
 *   - a trava ficou congelada em 01/06, 102 dias parada
 *   - logo `isDue()` respondia SEMPRE que sim
 *
 * Resultado medido em 12/09/2026: o renovador rodava em TODA abertura da tela,
 * processando 214 tarefas a 24 ms cada, ou seja cerca de 5 segundos de espera
 * para a pessoa, toda vez. E gravava 214 linhas de histórico imutável por vez,
 * que é de onde vinham as 14.508 linhas em `task_history` para 344 tarefas.
 *
 * A lição vale mais que a correção: uma trava em arquivo que falha em silêncio
 * não degrada, ela DESLIGA a proteção inteira. Hoje `run()` é chamado só pelo
 * cron de verdade do servidor, e `tickIfDue()` continua existindo para quem não
 * tem cron, mas avisa alto quando não consegue gravar a trava.
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

            /*
             * A TRAVA PRECISA GRAVAR DE VERDADE ANTES DE RODAR.
             *
             * Antes era `updateLock(); run();`, e como a gravação falhava em
             * silêncio (pasta sem permissão), `isDue()` respondia sempre que sim
             * e o renovador rodava em TODA requisição, e não uma vez por hora.
             * Cinco segundos de espera por abertura de tela.
             *
             * Agora, se a trava não gravou, NÃO roda: rodar sem trava é pior que
             * não rodar, porque vira trabalho pesado repetido a cada clique. O
             * aviso no log é a única forma de isso não voltar a passar
             * despercebido por três meses.
             */
            if (!self::updateLock()) {
                error_log('[RecurrenceCronService] trava nao gravavel em ' . self::LOCK_FILE
                          . ' — renovacao NAO executada nesta requisicao. '
                          . 'Corrija a permissao de storage/ ou use o cron do servidor.');
                return;
            }

            self::run();
        } catch (\Throwable $e) {
            // falha silenciosa — não pode quebrar a resposta principal
            error_log('[RecurrenceCronService] ' . $e->getMessage());
        }
    }

    /**
     * Executa a renovação SEM trava e SEM piggyback: é o ponto de entrada do
     * cron de verdade do servidor.
     *
     * @return int quantas tarefas foram renovadas
     */
    public static function executar(): int
    {
        return self::run();
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    private static function isDue(): bool
    {
        $file = self::LOCK_FILE;
        if (!file_exists($file)) return true;
        return (time() - (int)file_get_contents($file)) >= self::INTERVAL_SEC;
    }

    /** @return bool true se a trava foi mesmo gravada no disco. */
    private static function updateLock(): bool
    {
        $dir = dirname(self::LOCK_FILE);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        // `@` porque a falha aqui é esperada em ambiente com permissão errada, e
        // quem decide o que fazer com ela é o chamador, não um warning no HTML.
        return @file_put_contents(self::LOCK_FILE, (string) time(), LOCK_EX) !== false;
    }

    private static function run(): int
    {
        $renovadas = 0;
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

                /*
                 * `proximaDataFutura`, e NAO `calcularProximaData`.
                 *
                 * A segunda avanca UM periodo. Numa tarefa vencida ha meses, ela
                 * continuava vencida depois de avancar, voltava na passada
                 * seguinte, e assim para sempre: as mesmas 214 tarefas,
                 * reprocessadas e re-historiadas em toda abertura de tela.
                 */
                $proximaData = $rec->proximaDataFutura($taskData['prazo'] ?? date('Y-m-d'));

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
                $renovadas++;

            } catch (\Throwable $e) {
                error_log('[RecurrenceCronService] tarefa #' . $taskData['id'] . ': ' . $e->getMessage());
            }
        }

        return $renovadas;
    }
}
