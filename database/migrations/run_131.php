<?php
/**
 * Migration 131: task_entregas, a base do OTIF (On Time In Full) das tarefas.
 *
 * ---------------------------------------------------------------------------
 * POR QUE UMA TABELA NOVA
 * ---------------------------------------------------------------------------
 * `tasks` não serve de base para medir entrega:
 *
 *   - tarefa recorrente é renovada na MESMA linha (volta para `ativa` com o
 *     próximo prazo), então só a última conclusão sobrevive
 *   - o checklist pode ser marcado depois de concluir, e o número mudaria
 *   - o prazo pode ser editado depois, e o "no prazo" mudaria junto
 *
 * Cada linha aqui é uma entrega (ou uma ocorrência que venceu sem entrega),
 * com a foto do prazo e do checklist daquele momento. Tem `account_id`, como
 * toda tabela com dado de cliente.
 *
 * ---------------------------------------------------------------------------
 * O RETROATIVO
 * ---------------------------------------------------------------------------
 * Para o painel já nascer com histórico, esta migration reconstrói o passado:
 *
 *   concluida  cada `concluida` do task_history (prazo = o que valia antes de
 *              concluir, lido do antes_json), mais as tarefas concluídas que
 *              não têm linha no histórico (usa o prazo e a data atuais)
 *   perdida    cada `renovada_auto` do task_history: o cron só renova
 *              recorrente vencida e NÃO concluída, então cada uma é uma
 *              ocorrência não entregue. Deduplica por (tarefa, prazo).
 *
 * O checklist do retroativo é o ATUAL, porque a foto não existia. Essas linhas
 * ficam com `origem = retroativo` e a tela avisa quantas entraram no período.
 * Daqui para frente o sistema grava `origem = registro`, no ato
 * (App\Tarefas\TaskEntrega).
 *
 * Responsável do retroativo: o responsável atual da tarefa (sem ele, quem
 * concluiu). É a melhor informação disponível.
 *
 * ---------------------------------------------------------------------------
 * NÃO ALTERA NENHUMA LINHA EXISTENTE. IDEMPOTENTE.
 * ---------------------------------------------------------------------------
 * A tabela é criada com IF NOT EXISTS, e o retroativo só roda se ainda não
 * houver nenhuma linha `retroativo`. Rodar duas vezes não duplica.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_131.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_131.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Tarefas\TaskEntrega;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function temTabela(\PDO $pdo, string $t): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (bool) $st->fetchColumn();
}

linha('== Migration 131: task_entregas (OTIF das tarefas) ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

/* ---------------------------------------------------------------------------
 * 1. A tabela
 * ------------------------------------------------------------------------- */
$ddl = "CREATE TABLE IF NOT EXISTS `task_entregas` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `account_id`       INT NOT NULL,
  `task_id`          INT NOT NULL,
  `responsavel_id`   INT NULL COMMENT 'quem responde pela entrega no momento dela',
  `concluida_por_id` INT NULL COMMENT 'quem clicou em concluir (null em perdida)',
  `tipo`             ENUM('concluida','perdida') NOT NULL COMMENT 'perdida = recorrente que venceu sem conclusao',
  `prazo`            DATETIME NULL COMMENT 'prazo que valia no momento, horario local como digitado',
  `concluida_em`     DATETIME NULL COMMENT 'UTC, mesmo relogio de tasks.concluida_em',
  `no_prazo`         TINYINT(1) NULL COMMENT '1 no prazo, 0 atrasada, null sem prazo',
  `checklist_total`  INT NOT NULL DEFAULT 0,
  `checklist_feitos` INT NOT NULL DEFAULT 0,
  `completa`         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'checklist inteiro marcado (sem checklist = 1)',
  `origem`           ENUM('registro','retroativo') NOT NULL DEFAULT 'registro',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_te_conta_concl` (`account_id`, `concluida_em`),
  KEY `idx_te_conta_prazo` (`account_id`, `prazo`),
  KEY `idx_te_conta_resp`  (`account_id`, `responsavel_id`),
  KEY `idx_te_task`        (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$existia = temTabela($pdo, 'task_entregas');
if ($existia) {
    linha('  [ja existe] tabela task_entregas');
} elseif ($dryRun) {
    linha('  [criaria]   tabela task_entregas');
} else {
    $pdo->exec($ddl);
    linha('  [criada]    tabela task_entregas');
}

/* ---------------------------------------------------------------------------
 * 2. O retroativo
 * ------------------------------------------------------------------------- */
if ($existia) {
    $ja = (int) $pdo->query("SELECT COUNT(*) FROM task_entregas WHERE origem = 'retroativo'")->fetchColumn();
    if ($ja > 0) {
        linha("  [ja feito]  retroativo: $ja linhas ja reconstruidas. Nada a fazer.");
        linha();
        linha('Concluido.');
        exit(0);
    }
}

// Checklist atual de todas as tarefas, de uma vez.
$check = [];
foreach ($pdo->query('SELECT task_id, COUNT(*) AS total, COALESCE(SUM(concluido = 1), 0) AS feitos FROM task_checklist_items GROUP BY task_id') as $r) {
    $check[(int) $r['task_id']] = [(int) $r['total'], (int) $r['feitos']];
}

$novas   = [];
$ignoradas = ['reconclusao' => 0, 'sem_conta' => 0, 'perdida_sem_prazo' => 0, 'perdida_repetida' => 0];

// 2a. Conclusões registradas no histórico.
$concluidasRec = []; // (recorrência|prazo) que foram entregues: não podem virar perdida
$sqlConcl = "SELECT h.task_id, h.user_id, h.antes_json, h.created_at, t.responsavel_id, t.recorrencia_id, b.account_id
               FROM task_history h
               JOIN tasks t        ON t.id = h.task_id
               JOIN task_boards b  ON b.id = t.board_id
              WHERE h.acao = 'concluida'
              ORDER BY h.id";
foreach ($pdo->query($sqlConcl) as $r) {
    $antes = json_decode((string) $r['antes_json'], true) ?: [];
    if (($antes['status'] ?? '') === 'concluida') { $ignoradas['reconclusao']++; continue; }
    if ((int) $r['account_id'] <= 0) { $ignoradas['sem_conta']++; continue; }

    $tid   = (int) $r['task_id'];
    $prazo = !empty($antes['prazo']) ? (string) $antes['prazo'] : null;
    if ($prazo !== null && $r['recorrencia_id'] !== null) $concluidasRec['r' . $r['recorrencia_id'] . '|' . $prazo] = true;
    [$tot, $feitos] = $check[$tid] ?? [0, 0];
    $av = TaskEntrega::avaliar($prazo, TaskEntrega::paraHorarioLocal((string) $r['created_at']), $tot, $feitos);
    $novas[] = [
        'account_id' => (int) $r['account_id'], 'task_id' => $tid,
        'responsavel_id'   => $r['responsavel_id'] !== null ? (int) $r['responsavel_id'] : ($r['user_id'] !== null ? (int) $r['user_id'] : null),
        'concluida_por_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
        'tipo' => 'concluida', 'prazo' => $prazo, 'concluida_em' => (string) $r['created_at'],
        'no_prazo' => $av['no_prazo'], 'checklist_total' => $tot, 'checklist_feitos' => $feitos,
        'completa' => $av['completa'], 'origem' => 'retroativo',
    ];
}
$doHistorico = count($novas);

// 2b. Concluídas sem linha de conclusão no histórico (anteriores a ele).
$sqlSemHist = "SELECT t.id, t.responsavel_id, t.prazo, t.concluida_em, b.account_id
                 FROM tasks t
                 JOIN task_boards b ON b.id = t.board_id
                WHERE t.status = 'concluida' AND t.concluida_em IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM task_history h WHERE h.task_id = t.id AND h.acao = 'concluida')";
foreach ($pdo->query($sqlSemHist) as $r) {
    if ((int) $r['account_id'] <= 0) { $ignoradas['sem_conta']++; continue; }
    $tid   = (int) $r['id'];
    $prazo = $r['prazo'] !== null ? (string) $r['prazo'] : null;
    [$tot, $feitos] = $check[$tid] ?? [0, 0];
    $av = TaskEntrega::avaliar($prazo, TaskEntrega::paraHorarioLocal((string) $r['concluida_em']), $tot, $feitos);
    $novas[] = [
        'account_id' => (int) $r['account_id'], 'task_id' => $tid,
        'responsavel_id' => $r['responsavel_id'] !== null ? (int) $r['responsavel_id'] : null,
        'concluida_por_id' => null,
        'tipo' => 'concluida', 'prazo' => $prazo, 'concluida_em' => (string) $r['concluida_em'],
        'no_prazo' => $av['no_prazo'], 'checklist_total' => $tot, 'checklist_feitos' => $feitos,
        'completa' => $av['completa'], 'origem' => 'retroativo',
    ];
}
$semHistorico = count($novas) - $doHistorico;

// 2c. Ocorrências recorrentes que venceram sem conclusão.
//
// Deduplica por (RECORRÊNCIA, prazo), e não por tarefa: o sistema antigo criava
// uma linha por ciclo, e essas duplicatas invisíveis no quadro também eram
// renovadas pelo cron. Medido em produção em 27/09/2026: 17.614 eventos por
// tarefa, 836 por recorrência. Uma ocorrência é um compromisso, não importa
// quantas linhas-fantasma ela tinha.
$vistas = [];
$sqlPerd = "SELECT h.task_id, h.antes_json, t.responsavel_id, t.recorrencia_id, b.account_id
              FROM task_history h
              JOIN tasks t       ON t.id = h.task_id
              JOIN task_boards b ON b.id = t.board_id
             WHERE h.acao = 'renovada_auto'
             ORDER BY h.id";
foreach ($pdo->query($sqlPerd) as $r) {
    $antes = json_decode((string) $r['antes_json'], true) ?: [];
    $prazo = !empty($antes['prazo']) ? (string) $antes['prazo'] : null;
    if ($prazo === null) { $ignoradas['perdida_sem_prazo']++; continue; }
    if ((int) $r['account_id'] <= 0) { $ignoradas['sem_conta']++; continue; }
    $chave = ($r['recorrencia_id'] !== null ? 'r' . $r['recorrencia_id'] : 't' . $r['task_id']) . '|' . $prazo;
    if (isset($vistas[$chave])) { $ignoradas['perdida_repetida']++; continue; }
    // A instância visível foi concluída nesse prazo; a duplicata renovada não é perda.
    if (isset($concluidasRec[$chave])) { $ignoradas['perdida_ja_entregue'] = ($ignoradas['perdida_ja_entregue'] ?? 0) + 1; continue; }
    $vistas[$chave] = true;

    $tid = (int) $r['task_id'];
    [$tot, $feitos] = $check[$tid] ?? [0, 0];
    $novas[] = [
        'account_id' => (int) $r['account_id'], 'task_id' => $tid,
        'responsavel_id' => $r['responsavel_id'] !== null ? (int) $r['responsavel_id'] : null,
        'concluida_por_id' => null,
        'tipo' => 'perdida', 'prazo' => $prazo, 'concluida_em' => null,
        'no_prazo' => 0, 'checklist_total' => $tot, 'checklist_feitos' => $feitos,
        'completa' => 0, 'origem' => 'retroativo',
    ];
}
$perdidas = count($novas) - $doHistorico - $semHistorico;

linha();
linha("  retroativo: $doHistorico conclusoes do historico, $semHistorico concluidas sem historico, $perdidas ocorrencias perdidas");
linha('  ignoradas:  ' . json_encode($ignoradas));

if ($dryRun) {
    linha("  [gravaria]  " . count($novas) . " linhas em task_entregas (origem = retroativo)");
    linha();
    linha('Dry-run concluido. Nada foi gravado.');
    exit(0);
}

$pdo->beginTransaction();
try {
    foreach ($novas as $l) TaskEntrega::inserir($l);
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    linha('  [ERRO] ' . $e->getMessage());
    exit(1);
}
linha('  [gravadas]  ' . count($novas) . ' linhas em task_entregas (origem = retroativo)');
linha();
linha('Concluido.');
