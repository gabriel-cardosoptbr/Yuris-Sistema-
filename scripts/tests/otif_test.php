<?php
/**
 * otif_test.php: tranca as regras do OTIF (On Time In Full) das tarefas.
 *
 * O que cada bloco protege:
 *
 *   1. A regra de uma entrega (TaskEntrega::avaliar): no limite exato do prazo
 *      é no prazo; sem checklist é completa; sem prazo não é avaliado.
 *   2. O FUSO. O prazo é gravado no horário de Brasília e o servidor é UTC.
 *      Sem a conversão, quem conclui até 3h antes do prazo sairia atrasado.
 *   3. A conta do painel (Otif::metricas): o denominador é COMPROMISSOS, e
 *      não entregue conta contra, senão deixar vencer daria nota melhor do que
 *      entregar atrasado. Sem prazo fica fora.
 *   4. Série mensal, período da URL e os motivos da lista de pendências.
 *   5. Integração com banco, dentro de transação desfeita no fim: concluir
 *      grava a foto; concluir de novo não grava outra; o cron que renova
 *      ANTES do vencimento local (skew de fuso) não conta perdida.
 *
 * Uso: php scripts/tests/otif_test.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Tarefas\Otif;
use App\Tarefas\Task;
use App\Tarefas\TaskEntrega;

$OK = 0;
$FALHAS = [];

function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}

/* ── 1. regra de uma entrega ─────────────────────────────────────────────── */
echo "\n== 1. TaskEntrega::avaliar ==\n";
$a = TaskEntrega::avaliar('2026-09-10 18:00:00', '2026-09-10 17:59:00', 0, 0);
ok('antes do prazo, sem checklist: no prazo e completa', $a === ['no_prazo' => 1, 'completa' => 1]);
$a = TaskEntrega::avaliar('2026-09-10 18:00:00', '2026-09-10 18:00:00', 3, 3);
ok('exatamente no limite do prazo conta como no prazo', $a['no_prazo'] === 1);
$a = TaskEntrega::avaliar('2026-09-10 18:00:00', '2026-09-10 18:00:01', 3, 3);
ok('um segundo depois do prazo é atrasada', $a['no_prazo'] === 0);
$a = TaskEntrega::avaliar('2026-09-10 18:00:00', '2026-09-10 10:00:00', 4, 3);
ok('checklist 3 de 4: incompleta', $a['completa'] === 0);
$a = TaskEntrega::avaliar(null, '2026-09-10 10:00:00', 0, 0);
ok('sem prazo: no_prazo fica null (fora do OTIF)', $a['no_prazo'] === null);

/* ── 2. fuso ─────────────────────────────────────────────────────────────── */
echo "\n== 2. Fuso: prazo local x relógio UTC ==\n";
$fuso = TaskEntrega::fusoDoPrazo()->getName();
ok("fuso do prazo é America/Sao_Paulo (atual: $fuso)", $fuso === 'America/Sao_Paulo');
ok('20:00 UTC vira 17:00 em Brasília', TaskEntrega::paraHorarioLocal('2026-09-27 20:00:00') === '2026-09-27 17:00:00');
// Prazo 18:00 local, concluída às 16:00 local (= 19:00 UTC).
$a = TaskEntrega::avaliar('2026-09-27 18:00:00', TaskEntrega::paraHorarioLocal('2026-09-27 19:00:00'), 0, 0);
ok('concluída 2h antes do prazo local NÃO sai atrasada (sem conversão sairia)', $a['no_prazo'] === 1);

/* ── 3. a conta do painel ────────────────────────────────────────────────── */
echo "\n== 3. Otif::metricas ==\n";
$L = static fn(array $o) => $o + ['tipo' => 'concluida', 'prazo' => '2026-09-01 12:00:00', 'no_prazo' => 1, 'completa' => 1, 'origem' => 'registro', 'dia' => '2026-09-01', 'responsavel_id' => 1];
$linhas = [
    $L([]),                                            // OTIF
    $L([]),                                            // OTIF
    $L(['no_prazo' => 0]),                             // atrasada, completa
    $L(['completa' => 0]),                             // no prazo, incompleta
    $L(['tipo' => 'perdida', 'no_prazo' => 0, 'completa' => 0]),   // não entregue
    $L(['tipo' => 'em_atraso', 'no_prazo' => 0, 'completa' => 0]), // não entregue
    $L(['prazo' => null, 'no_prazo' => null]),         // sem prazo: fora
];
$m = Otif::metricas($linhas);
ok('compromissos = 6 (sem prazo fica fora)', $m['compromissos'] === 6);
ok('otif = 2, no prazo = 3, completas = 3', $m['otif'] === 2 && $m['no_prazo'] === 3 && $m['completas'] === 3);
ok('não entregues = 2 (perdida + em atraso)', $m['nao_entregues'] === 2 && $m['perdidas'] === 1 && $m['em_atraso'] === 1);
ok('OTIF % = 2/6 = 33,3', $m['otif_pct'] === 33.3);
ok('No prazo % = 3/6 = 50', $m['ot_pct'] === 50.0);
ok('sem prazo contado à parte', $m['sem_prazo'] === 1);
$vazio = Otif::metricas([]);
ok('sem compromissos: percentuais null (não 0%)', $vazio['otif_pct'] === null && $vazio['ot_pct'] === null);
// Quem deixa vencer não pode superar quem entrega atrasado.
$deixaVencer = Otif::metricas([$L(['tipo' => 'perdida', 'no_prazo' => 0, 'completa' => 0])]);
$atrasa      = Otif::metricas([$L(['no_prazo' => 0])]);
ok('deixar vencer não dá In Full melhor que entregar atrasado', $deixaVencer['if_pct'] <= $atrasa['if_pct']);
$g = Otif::porColaborador([$L(['responsavel_id' => 7]), $L(['responsavel_id' => null, 'no_prazo' => 0])]);
ok('agrupa por responsável, sem responsável na chave 0', isset($g[7], $g[0]) && $g[0]['atrasadas'] === 1);

/* ── 4. série, período e pendências ──────────────────────────────────────── */
echo "\n== 4. porMes, periodo, pendencias, faixa ==\n";
$s = Otif::porMes([$L(['dia' => '2026-08-15']), $L(['dia' => '2026-09-02', 'no_prazo' => 0])], '2026-09-20', 3);
ok('série tem 3 meses terminando em set/26', $s['meses'] === ['2026-07', '2026-08', '2026-09'] && $s['rotulos'][2] === 'Set/26');
ok('mês sem dado fica null; ago 100%, set 0%', $s['otif'][0] === null && $s['otif'][1] === 100.0 && $s['otif'][2] === 0.0);
[$d1, $d2] = Otif::periodo('2026-09-30', '2026-09-01');
ok('período invertido é trocado', $d1 === '2026-09-01' && $d2 === '2026-09-30');
[$d1, $d2] = Otif::periodo('lixo', '2026-02-31');
ok('datas inválidas voltam ao padrão de 30 dias', intdiv(strtotime($d2) - strtotime($d1), 86400) === 29);
[$d1, $d2] = Otif::periodo('2010-01-01', '2026-09-27');
ok('período limitado a ' . Otif::DIAS_MAXIMOS . ' dias', intdiv(strtotime($d2) - strtotime($d1), 86400) === Otif::DIAS_MAXIMOS - 1);
$p = Otif::pendencias([
    $L(['task_id' => 1, 'titulo' => 'A', 'concluida_local' => null, 'dia' => '2026-09-01']),
    $L(['task_id' => 2, 'titulo' => 'B', 'concluida_local' => '2026-09-03 10:00:00', 'no_prazo' => 0, 'completa' => 0, 'checklist_total' => 4, 'checklist_feitos' => 1, 'dia' => '2026-09-03']),
    $L(['task_id' => 3, 'titulo' => 'C', 'concluida_local' => null, 'tipo' => 'perdida', 'dia' => '2026-09-02']),
]);
ok('pendências excluem o que foi OTIF', count($p) === 2);
ok('mais recente primeiro, com motivo e itens do checklist', $p[0]['task_id'] === 2 && str_contains($p[0]['motivo'], '1 de 4 itens'));
ok('perdida explica que a recorrência renovou', str_contains($p[1]['motivo'], 'recorrência renovou'));
ok('faixas: 90 bom, 75 atenção, 74,9 crítico, null sem dados',
    Otif::faixa(90.0) === 'bom' && Otif::faixa(75.0) === 'atencao' && Otif::faixa(74.9) === 'ruim' && Otif::faixa(null) === 'sem');

/* ── 5. integração com o banco (desfeita no fim) ─────────────────────────── */
echo "\n== 5. Integração: foto na conclusão ==\n";
try {
    $pdo = Database::getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $temTabela = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_entregas'")->fetchColumn();
    $quadro = $pdo->query('SELECT b.id, b.account_id, c.id AS coluna, (SELECT u.id FROM users u WHERE u.account_id = b.account_id LIMIT 1) AS usuario
                             FROM task_boards b JOIN task_columns c ON c.board_id = b.id
                            WHERE b.ativo = 1 ORDER BY b.id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $temTabela = false; $quadro = null;
    echo "  [pulado] sem banco: " . $e->getMessage() . "\n";
}

if (!$temTabela || !$quadro || !$quadro['usuario']) {
    echo "  [pulado] precisa da tabela task_entregas (migration 131) e de um quadro com usuário\n";
} else {
    $pdo->beginTransaction();
    try {
        $uid   = (int) $quadro['usuario'];
        $conta = (int) $quadro['account_id'];
        $prazoFuturo = date('Y-m-d H:i:s', strtotime(TaskEntrega::agoraLocal() . ' +2 hours'));
        $tid = (int) Task::create([
            'board_id' => $quadro['id'], 'column_id' => $quadro['coluna'], 'titulo' => '[otif_test] entrega',
            'prazo' => $prazoFuturo, 'responsavel_id' => $uid, 'criado_por_id' => $uid,
        ]);
        $pdo->prepare('INSERT INTO task_checklist_items (task_id, descricao, concluido) VALUES (?,?,1),(?,?,0)')
            ->execute([$tid, 'feito', $tid, 'faltando']);

        Task::complete($tid, $uid);
        $fotos = $pdo->prepare('SELECT * FROM task_entregas WHERE task_id = ?');
        $fotos->execute([$tid]);
        $f = $fotos->fetchAll(\PDO::FETCH_ASSOC);
        ok('concluir grava 1 foto', count($f) === 1);
        ok('foto: no prazo, incompleta, checklist 1 de 2, origem registro, conta do quadro',
            $f && (int) $f[0]['no_prazo'] === 1 && (int) $f[0]['completa'] === 0
               && (int) $f[0]['checklist_total'] === 2 && (int) $f[0]['checklist_feitos'] === 1
               && $f[0]['origem'] === 'registro' && (int) $f[0]['account_id'] === $conta);

        // Marcar o item DEPOIS não muda a foto.
        $pdo->prepare('UPDATE task_checklist_items SET concluido = 1 WHERE task_id = ?')->execute([$tid]);
        $fotos->execute([$tid]);
        $f2 = $fotos->fetchAll(\PDO::FETCH_ASSOC);
        ok('marcar o checklist depois não altera a foto', (int) $f2[0]['completa'] === 0);

        Task::complete($tid, $uid);
        $fotos->execute([$tid]);
        ok('concluir de novo o que já está concluído não grava outra foto', count($fotos->fetchAll()) === 1);

        // Cron renovando antes do vencimento LOCAL (prazo ainda no futuro em Brasília).
        $tid2 = (int) Task::create([
            'board_id' => $quadro['id'], 'column_id' => $quadro['coluna'], 'titulo' => '[otif_test] skew',
            'prazo' => date('Y-m-d H:i:s', strtotime(TaskEntrega::agoraLocal() . ' +1 hour')), 'responsavel_id' => $uid, 'criado_por_id' => $uid,
        ]);
        TaskEntrega::registrarPerdida(Task::findById($tid2));
        $c = $pdo->prepare('SELECT COUNT(*) FROM task_entregas WHERE task_id = ?');
        $c->execute([$tid2]);
        ok('prazo ainda não venceu no horário local: não conta perdida', (int) $c->fetchColumn() === 0);

        $pdo->prepare('UPDATE tasks SET prazo = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', strtotime(TaskEntrega::agoraLocal() . ' -1 hour')), $tid2]);
        TaskEntrega::registrarPerdida(Task::findById($tid2));
        $c->execute([$tid2]);
        ok('prazo vencido no horário local: conta 1 perdida', (int) $c->fetchColumn() === 1);

        // Duplicata de recorrência (sistema antigo): só a instância visível no
        // quadro conta perdida. Mesma regra de Task::findByBoard.
        $pdo->prepare("INSERT INTO task_recurrences (tipo, intervalo, data_inicio, ativa) VALUES ('diaria', 1, CURDATE(), 1)")->execute();
        $rec = (int) $pdo->lastInsertId();
        $prazoVencido = date('Y-m-d H:i:s', strtotime(TaskEntrega::agoraLocal() . ' -5 hours'));
        $dups = [];
        foreach ([1, 2] as $i) {
            $dups[] = (int) Task::create([
                'board_id' => $quadro['id'], 'column_id' => $quadro['coluna'], 'titulo' => "[otif_test] dup $i",
                'prazo' => $prazoVencido, 'responsavel_id' => $uid, 'criado_por_id' => $uid, 'recorrencia_id' => $rec,
            ]);
        }
        TaskEntrega::registrarPerdida(Task::findById($dups[0]));
        TaskEntrega::registrarPerdida(Task::findById($dups[1]));
        $c->execute([$dups[0]]); $nInvisivel = (int) $c->fetchColumn();
        $c->execute([$dups[1]]); $nVisivel   = (int) $c->fetchColumn();
        ok('duplicata invisível da recorrência não conta perdida; a visível conta 1', $nInvisivel === 0 && $nVisivel === 1);

        $hoje = substr(TaskEntrega::agoraLocal(), 0, 10);
        $rel  = Otif::relatorio([$conta], $hoje, $hoje, $uid);
        $m    = $rel['foco']['metricas'];
        ok('relatório do dia enxerga a entrega incompleta e a perdida', $m['incompletas'] >= 1 && $m['perdidas'] >= 1);
        ok('isolamento: conta inexistente não enxerga nada', Otif::relatorio([999999999], $hoje, $hoje)['equipe']['compromissos'] === 0);
    } catch (\Throwable $e) {
        ok('integração sem exceção: ' . $e->getMessage(), false);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

/* ── resultado ─────────────────────────────────────────────────────────────── */
echo "\n----\n";
if (!$FALHAS) {
    echo "Resultado: {$OK} ok · 0 falha(s)\n";
    exit(0);
}
echo 'Resultado: ' . $OK . ' ok · ' . count($FALHAS) . " falha(s)\n\n";
foreach ($FALHAS as $f) echo "  - $f\n";
exit(1);
