<?php
/**
 * Migration 176: recupera as entregas feitas arrastando para a coluna de concluído.
 *
 * Até 05/11/2026, arrastar o cartão para uma coluna `is_coluna_concluido` só trocava
 * a coluna: a tarefa seguia `ativa` e nada caía em `task_entregas`. A entrega não
 * contava e, vencido o prazo, o cartão ainda saía como "não entregue". O código novo
 * grava na hora (Task::aoTrocarDeColuna); esta migration cuida do que já estava lá.
 *
 * Para cada tarefa ativa em coluna de concluído, sem entrega para o prazo atual,
 * grava uma linha `concluida` com `origem = retroativo`:
 *   quando        o último `movida` para aquela coluna no task_history; sem ele,
 *                 tasks.concluida_em; sem ele, tasks.updated_at
 *   quem          quem moveu (o responsável da tarefa, sem ele, quem moveu)
 *   checklist     o ATUAL, porque a foto não existia (a tela avisa dos retroativos)
 *
 * NÃO ALTERA NENHUMA LINHA EXISTENTE: só insere em `task_entregas`.
 * IDEMPOTENTE: quem já tem entrega para o prazo atual fica de fora.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_176.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_176.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Tarefas\TaskEntrega;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo "== Migration 176: entregas pela coluna de concluido ==" . PHP_EOL;
echo($dryRun ? "   MODO DRY-RUN: nada sera gravado." : "   MODO APLICACAO.") . PHP_EOL . PHP_EOL;

$tarefas = $pdo->query("
    SELECT t.id, t.board_id, t.column_id, t.prazo, t.responsavel_id, t.concluida_em, t.updated_at, t.recorrencia_id, b.account_id
      FROM tasks t
      JOIN task_boards b ON b.id = t.board_id
      JOIN task_columns col ON col.id = t.column_id
     WHERE t.status = 'ativa' AND col.is_coluna_concluido = 1
       AND NOT EXISTS (SELECT 1 FROM task_entregas e
                        WHERE e.task_id = t.id AND e.tipo = 'concluida' AND e.prazo <=> t.prazo)
")->fetchAll(\PDO::FETCH_ASSOC);

$historico = $pdo->prepare("SELECT user_id, created_at, depois_json FROM task_history
                             WHERE task_id = ? AND acao = 'movida' ORDER BY id DESC");

$porConta = []; $gravadas = 0; $semHistorico = 0;
foreach ($tarefas as $t) {
    $quando = null; $quem = null;
    $historico->execute([(int) $t['id']]);
    foreach ($historico->fetchAll(\PDO::FETCH_ASSOC) as $h) {
        $depois = json_decode((string) $h['depois_json'], true);
        if ((int) ($depois['column_id'] ?? 0) === (int) $t['column_id']) {
            $quando = (string) $h['created_at'];
            $quem   = $h['user_id'] !== null ? (int) $h['user_id'] : null;
            break;
        }
    }
    if ($quando === null) { $semHistorico++; $quando = (string) ($t['concluida_em'] ?: $t['updated_at']); }
    if ($quando === '') continue;

    [$total, $feitos] = TaskEntrega::checklist((int) $t['id']);
    $prazo = $t['prazo'] !== null ? (string) $t['prazo'] : null;
    $av    = TaskEntrega::avaliar($prazo, TaskEntrega::paraHorarioLocal($quando), $total, $feitos);
    $conta = (int) $t['account_id'];
    $porConta[$conta] = ($porConta[$conta] ?? 0) + 1;
    $gravadas++;

    if ($dryRun) continue;
    TaskEntrega::inserir([
        'account_id'       => $conta,
        'task_id'          => (int) $t['id'],
        'responsavel_id'   => $t['responsavel_id'] !== null ? (int) $t['responsavel_id'] : $quem,
        'concluida_por_id' => $quem,
        'tipo'             => 'concluida',
        'prazo'            => $prazo,
        'concluida_em'     => $quando,
        'no_prazo'         => $av['no_prazo'],
        'checklist_total'  => $total,
        'checklist_feitos' => $feitos,
        'completa'         => $av['completa'],
        'origem'           => 'retroativo',
    ]);
}

ksort($porConta);
foreach ($porConta as $conta => $n) echo "  conta $conta: $n entrega(s)" . PHP_EOL;
echo PHP_EOL . ($dryRun ? "Gravaria" : "Gravadas") . ": $gravadas (sem registro de movimento, usando a data da tarefa: $semHistorico)" . PHP_EOL;
echo "Fim." . PHP_EOL;
