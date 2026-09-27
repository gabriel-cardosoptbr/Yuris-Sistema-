<?php
namespace App\Tarefas;

use App\Core\Database;
use App\Core\EnvLoader;

/**
 * TaskEntrega: a "foto" de cada entrega de tarefa, matéria-prima do OTIF.
 *
 * ---------------------------------------------------------------------------
 * POR QUE UMA TABELA PRÓPRIA, E NÃO LER `tasks`
 * ---------------------------------------------------------------------------
 * 1. Tarefa recorrente é renovada NA MESMA LINHA: concluir volta o status para
 *    `ativa` com o prazo seguinte. Em `tasks` só sobra a última conclusão; cada
 *    ocorrência precisa da sua linha aqui.
 * 2. O checklist muda depois. Aqui fica quantos itens havia e quantos estavam
 *    marcados NO MOMENTO da conclusão, e isso ninguém ajusta depois.
 * 3. O prazo muda depois. Aqui fica o prazo que valia quando a entrega aconteceu.
 *
 * Duas espécies de linha:
 *   concluida  alguém concluiu a tarefa
 *   perdida    ocorrência recorrente que venceu sem conclusão e foi renovada
 *              pelo cron: um compromisso que não foi entregue
 *
 * `origem = retroativo` são linhas reconstruídas pela migration 131 a partir do
 * histórico, com o checklist ATUAL (a foto não existia). `registro` são as
 * gravadas aqui, no ato.
 *
 * ---------------------------------------------------------------------------
 * FUSO: O PRAZO É LOCAL, O RELÓGIO DO SERVIDOR É UTC
 * ---------------------------------------------------------------------------
 * `tasks.prazo` guarda o horário que a pessoa digitou (Brasília). `NOW()` do
 * banco e do PHP é UTC. Comparar direto marcaria como atrasado quem concluiu
 * até 3 horas ANTES do prazo. Toda comparação aqui converte a conclusão para o
 * fuso do prazo (`APP_TIMEZONE`, padrão America/Sao_Paulo) antes.
 *
 * Gravar nunca derruba a conclusão: se falhar (tabela ainda não migrada, por
 * exemplo), registra no log e segue. Concluir a tarefa é mais importante que
 * medir a conclusão.
 */
final class TaskEntrega
{
    public static function fusoDoPrazo(): \DateTimeZone
    {
        $tz = (string) EnvLoader::get('APP_TIMEZONE', 'America/Sao_Paulo');
        try {
            return new \DateTimeZone($tz !== '' ? $tz : 'America/Sao_Paulo');
        } catch (\Throwable $e) {
            return new \DateTimeZone('America/Sao_Paulo');
        }
    }

    /** Converte um horário do servidor (UTC) para o fuso em que o prazo foi digitado. */
    public static function paraHorarioLocal(string $utc): string
    {
        $d = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        return $d->setTimezone(self::fusoDoPrazo())->format('Y-m-d H:i:s');
    }

    /** "Agora" no fuso do prazo. */
    public static function agoraLocal(): string
    {
        return (new \DateTimeImmutable('now', self::fusoDoPrazo()))->format('Y-m-d H:i:s');
    }

    /**
     * A regra do OTIF para uma entrega, sem banco: testável isolada.
     *
     * @param ?string $prazoLocal     prazo como a pessoa digitou (null = sem prazo)
     * @param ?string $concluidaLocal conclusão já convertida para o fuso do prazo
     * @return array{no_prazo: ?int, completa: int}
     *   no_prazo  1 no prazo, 0 atrasada, null quando não havia prazo
     *   completa  1 quando o checklist estava todo marcado (sem checklist conta
     *             como completa: não havia o que faltar)
     */
    public static function avaliar(?string $prazoLocal, ?string $concluidaLocal, int $total, int $feitos): array
    {
        $noPrazo = null;
        if ($prazoLocal !== null && $prazoLocal !== '') {
            $noPrazo = ($concluidaLocal !== null && strtotime($concluidaLocal) <= strtotime($prazoLocal)) ? 1 : 0;
        }
        $completa = ($total <= 0 || $feitos >= $total) ? 1 : 0;
        return ['no_prazo' => $noPrazo, 'completa' => $completa];
    }

    /** @return array{0:int,1:int} [total de itens, itens marcados] */
    public static function checklist(int $taskId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT COUNT(*) AS total, COALESCE(SUM(concluido = 1), 0) AS feitos
               FROM task_checklist_items WHERE task_id = ?'
        );
        $st->execute([$taskId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [(int) ($r['total'] ?? 0), (int) ($r['feitos'] ?? 0)];
    }

    /**
     * Grava a conclusão. Chamar ANTES de a tarefa mudar de prazo (a recorrente
     * renova o prazo no mesmo ato), com a linha de `tasks` lida antes.
     */
    public static function registrarConclusao(array $task, ?int $concluidaPorId): void
    {
        try {
            $conta = self::contaDoQuadro((int) ($task['board_id'] ?? 0));
            if ($conta <= 0) return;

            [$total, $feitos] = self::checklist((int) $task['id']);
            $agoraUtc   = gmdate('Y-m-d H:i:s');
            $prazo      = !empty($task['prazo']) ? (string) $task['prazo'] : null;
            $av         = self::avaliar($prazo, self::paraHorarioLocal($agoraUtc), $total, $feitos);
            $responsavel = !empty($task['responsavel_id']) ? (int) $task['responsavel_id'] : $concluidaPorId;

            self::inserir([
                'account_id'       => $conta,
                'task_id'          => (int) $task['id'],
                'responsavel_id'   => $responsavel,
                'concluida_por_id' => $concluidaPorId,
                'tipo'             => 'concluida',
                'prazo'            => $prazo,
                'concluida_em'     => $agoraUtc,
                'no_prazo'         => $av['no_prazo'],
                'checklist_total'  => $total,
                'checklist_feitos' => $feitos,
                'completa'         => $av['completa'],
                'origem'           => 'registro',
            ]);
        } catch (\Throwable $e) {
            error_log('[TaskEntrega::registrarConclusao] tarefa #' . ($task['id'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    /**
     * Ocorrência recorrente que venceu sem conclusão. Só grava se o prazo já
     * passou no horário LOCAL: o cron compara em UTC e pode renovar até 3 horas
     * antes do vencimento real, e isso não é culpa de quem ainda tinha tempo.
     */
    public static function registrarPerdida(array $task): void
    {
        try {
            $prazo = !empty($task['prazo']) ? (string) $task['prazo'] : null;
            if ($prazo === null || strtotime($prazo) >= strtotime(self::agoraLocal())) return;
            if (!self::instanciaVisivel($task)) return;

            $conta = self::contaDoQuadro((int) ($task['board_id'] ?? 0));
            if ($conta <= 0) return;

            [$total, $feitos] = self::checklist((int) $task['id']);
            self::inserir([
                'account_id'       => $conta,
                'task_id'          => (int) $task['id'],
                'responsavel_id'   => !empty($task['responsavel_id']) ? (int) $task['responsavel_id'] : null,
                'concluida_por_id' => null,
                'tipo'             => 'perdida',
                'prazo'            => $prazo,
                'concluida_em'     => null,
                'no_prazo'         => 0,
                'checklist_total'  => $total,
                'checklist_feitos' => $feitos,
                'completa'         => 0,
                'origem'           => 'registro',
            ]);
        } catch (\Throwable $e) {
            error_log('[TaskEntrega::registrarPerdida] tarefa #' . ($task['id'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    /**
     * A instância que o quadro MOSTRA para esta recorrência: a de prazo mais
     * recente (desempate pelo id), mesma regra de Task::findByBoard.
     *
     * O sistema antigo de recorrência criava uma linha nova por ciclo, e essas
     * linhas continuam ativas, invisíveis no quadro, sendo renovadas pelo cron
     * todo dia. Medido em produção em 27/09/2026: 317 linhas recorrentes ativas
     * para só 8 recorrências. Contar perda por linha inflaria o OTIF de quem
     * nem consegue ver (nem concluir) as duplicatas.
     */
    public static function instanciaVisivel(array $task): bool
    {
        if (empty($task['recorrencia_id'])) return true;
        $st = Database::getConnection()->prepare(
            "SELECT id FROM tasks WHERE recorrencia_id = ? AND status <> 'arquivada'
              ORDER BY prazo DESC, id DESC LIMIT 1"
        );
        $st->execute([(int) $task['recorrencia_id']]);
        return (int) $st->fetchColumn() === (int) $task['id'];
    }

    /** Usado também pela migration 131, para o retroativo sair no mesmo formato. */
    public static function inserir(array $l): void
    {
        Database::getConnection()->prepare(
            'INSERT INTO task_entregas
               (account_id, task_id, responsavel_id, concluida_por_id, tipo, prazo, concluida_em,
                no_prazo, checklist_total, checklist_feitos, completa, origem)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $l['account_id'], $l['task_id'], $l['responsavel_id'], $l['concluida_por_id'],
            $l['tipo'], $l['prazo'], $l['concluida_em'], $l['no_prazo'],
            $l['checklist_total'], $l['checklist_feitos'], $l['completa'], $l['origem'],
        ]);
    }

    /** `tasks` não tem account_id: a conta vem do quadro. */
    private static function contaDoQuadro(int $boardId): int
    {
        if ($boardId <= 0) return 0;
        $st = Database::getConnection()->prepare('SELECT account_id FROM task_boards WHERE id = ?');
        $st->execute([$boardId]);
        return (int) ($st->fetchColumn() ?: 0);
    }
}
