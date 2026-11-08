<?php
/**
 * central_tarefas_test.php — a Central de Tarefas (App\Tarefas\Central).
 *
 * O RELATO (05/11/2026): "tudo de tarefa agora tem que aparecer [...] eu tenho
 * que ter uma central de tudo que eu tenho para fazer". Tarefa criada dentro do
 * processo não aparecia em Tarefas nem no calendário: quadros (`tasks`) e
 * processo (`processo_tarefas`, `processo_prazos`) eram mundos separados.
 *
 * O que este arquivo trava:
 *   1. Período: hoje, amanhã, semana de segunda a domingo, mês do calendário.
 *   2. A lista junta as três origens sem duplicar: é o MESMO registro, concluir
 *      numa tela conclui na outra.
 *   3. Filtros por tipo, responsável (texto × id), busca e processo.
 *   4. Isolamento entre escritórios, que é o risco 1 deste projeto.
 *
 * ESCREVE NO BANCO (registros com marca, apagados no fim). Não rode em produção.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/central_tarefas_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Tarefas\Central;
use App\Tarefas\Task;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function eq($esp, $obt, string $m): void {
    if ($esp === $obt) { pass($m); return; }
    fail($m . ' (esperado ' . var_export($esp, true) . ', obtido ' . var_export($obt, true) . ')');
}

/* ===================================================================== */
secao('1. Período: quinta, 05/11/2026');
/* ===================================================================== */

$H = '2026-11-05';
$c = fn (?string $d, bool $f = false) => Central::classificar($d, $H, $f);

eq(['hoje', 'semana', 'mes'], $c('2026-11-05')['periodos'], 'hoje também é da semana e do mês');
eq('hoje', $c('2026-11-05')['coluna'], 'hoje vai para a coluna Hoje');
eq(['amanha', 'semana', 'mes'], $c('2026-11-06')['periodos'], 'amanhã');
eq('amanha', $c('2026-11-06')['coluna'], 'amanhã vai para a coluna Amanhã');
eq(['semana', 'mes'], $c('2026-11-08')['periodos'], 'domingo ainda é desta semana');
eq(['mes'], $c('2026-11-09')['periodos'], 'segunda seguinte já não é desta semana');
eq('mes', $c('2026-11-09')['coluna'], 'e cai na coluna Este mês');
eq([], $c('2026-12-01')['periodos'], 'outro mês não entra em período nenhum');
eq('depois', $c('2026-12-01')['coluna'], 'e vai para Mais adiante');
eq(['atrasadas', 'semana', 'mes'], $c('2026-11-02')['periodos'], 'venceu na segunda: atrasada, e continua sendo desta semana');
eq('atrasadas', $c('2026-11-02')['coluna'], 'coluna Atrasadas');
eq(['atrasadas'], $c('2026-10-30')['periodos'], 'mês passado: só atrasada');
eq(['sem_data'], $c(null)['periodos'], 'sem data');
eq('sem_data', $c(null)['coluna'], 'coluna Sem data');
eq(['hoje', 'semana', 'mes'], $c('2026-11-05 14:30:00')['periodos'], 'tarefa de quadro tem hora, e a hora não muda o dia');
eq(['semana', 'mes'], $c('2026-11-02', true)['periodos'], 'concluída nunca é atrasada');
eq('concluidas', $c('2026-11-02', true)['coluna'], 'concluída vai para Concluídas');

eq(['hoje', 'semana', 'mes'], Central::classificar('2026-11-08', '2026-11-08')['periodos'], 'no domingo, hoje ainda é desta semana');
eq(['amanha', 'mes'], Central::classificar('2026-11-09', '2026-11-08')['periodos'], 'no domingo, amanhã já é a semana que vem');
eq(['amanha', 'semana'], Central::classificar('2026-12-01', '2026-11-30')['periodos'], 'no último dia do mês, amanhã é da semana, mas não do mês');
eq('amanha', Central::classificar('2026-12-01', '2026-11-30')['coluna'], 'e continua na coluna Amanhã');

/* ===================================================================== */
/* cenário no banco                                                      */
/* ===================================================================== */

$a = $pdo->query("SELECT u.account_id, MIN(u.id) uid, MAX(u.nome) nome FROM users u
                  JOIN task_boards b ON b.account_id = u.account_id AND b.ativo = 1
                  WHERE u.deleted_at IS NULL AND u.status = 'active'
                  GROUP BY u.account_id ORDER BY u.account_id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
$st = $pdo->prepare("SELECT account_id, MIN(id) uid FROM users WHERE deleted_at IS NULL AND account_id <> ? GROUP BY account_id ORDER BY account_id LIMIT 1");
$st->execute([(int) ($a['account_id'] ?? 0)]);
$b = $st->fetch(\PDO::FETCH_ASSOC);
if (!$a || !$b) { echo "\nSKIP do cenário de banco: precisa de duas contas.\n"; goto fim; }

$CONTA_A = (int) $a['account_id'];
$CONTA_B = (int) $b['account_id'];
$USER_A  = (int) $a['uid'];
$USER_B  = (int) $b['uid'];
$st = $pdo->prepare('SELECT nome FROM users WHERE id = ?');
$st->execute([$USER_A]);
$NOME_A = (string) $st->fetchColumn();

$MARCA = 'ZZCENTRAL' . bin2hex(random_bytes(3));
$hoje  = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();

$ins = $pdo->prepare("INSERT INTO processos (account_id, numero, numero_cnj, cliente_nome, status) VALUES (?, ?, ?, ?, 'ativo')");
$ins->execute([$CONTA_A, "{$MARCA}-A", '1009347-41.2024.8.26.0100', "Cliente {$MARCA}"]);
$PROC_A = (int) $pdo->lastInsertId();
$ins->execute([$CONTA_B, "{$MARCA}-B", null, "Outro {$MARCA}"]);
$PROC_B = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO processo_tarefas (processo_id, titulo, prioridade, responsavel, data_tarefa) VALUES (?, ?, 'alta', ?, ?)")
    ->execute([$PROC_A, "{$MARCA} replica", $NOME_A, $hoje]);
$TAREFA = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO processo_prazos (processo_id, descricao, data_limite, responsavel) VALUES (?, ?, ?, ?)")
    ->execute([$PROC_A, "{$MARCA} audiencia", date('Y-m-d', strtotime($hoje . ' +1 day')), 'Outra Pessoa']);
$PRAZO = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO processo_tarefas (processo_id, titulo) VALUES (?, ?)")->execute([$PROC_B, "{$MARCA} alheia"]);
$TAREFA_B = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO task_boards (nome, tipo, owner_id, account_id, ativo) VALUES (?, 'compartilhado', ?, ?, 1)")
    ->execute(["Quadro {$MARCA}", $USER_A, $CONTA_A]);
$QUADRO = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO task_columns (board_id, nome, ordem) VALUES (?, 'A fazer', 1)")->execute([$QUADRO]);
$COLUNA = (int) $pdo->lastInsertId();
$QUADRO_DE_ESCRITORIO = Task::create([
    'board_id' => $QUADRO, 'column_id' => $COLUNA, 'titulo' => "{$MARCA} escritorio", 'responsavel_id' => $USER_A,
    'criado_por_id' => $USER_A, 'prazo' => "{$hoje} 15:30:00",
]);

$escopo = ['user_id' => $USER_A, 'is_admin' => false, 'contas_tarefas' => [$CONTA_A], 'contas_processos' => [$CONTA_A]];
$lista  = fn (array $f = [], ?array $e = null) => Central::listar($e ?? $escopo, $f + ['busca' => $MARCA])['itens'];
$chaves = function (array $itens): array { $k = array_column($itens, 'chave'); sort($k); return $k; };

try {
    /* ================================================================= */
    secao('2. As três origens numa lista só');
    /* ================================================================= */

    $esp = ["prazo:{$PRAZO}", "processo_tarefa:{$TAREFA}", "tarefa:{$QUADRO_DE_ESCRITORIO}"];
    sort($esp);
    eq($esp, $chaves($lista()), 'tarefa do processo, prazo e tarefa de quadro aparecem, uma vez cada');

    $porChave = array_column($lista(), null, 'chave');
    $t = $porChave["processo_tarefa:{$TAREFA}"];
    eq('juridica', $t['tipo'], 'tarefa do processo é jurídica');
    eq($PROC_A, $t['processo_id'], 'e traz o processo');
    eq('1009347-41.2024.8.26.0100', $t['processo_numero'], 'o número CNJ');
    eq("Cliente {$MARCA}", $t['cliente_nome'], 'e o cliente');
    eq('hoje', $t['coluna_central'], 'na coluna Hoje');
    eq('amanha', $porChave["prazo:{$PRAZO}"]['coluna_central'], 'o prazo cai em Amanhã');
    eq('escritorio', $porChave["tarefa:{$QUADRO_DE_ESCRITORIO}"]['tipo'], 'tarefa de quadro sem processo é de escritório');
    eq('15:30', $porChave["tarefa:{$QUADRO_DE_ESCRITORIO}"]['hora'], 'e guarda a hora');

    $cont = Central::listar($escopo, ['busca' => $MARCA])['contagem'];
    eq(2, $cont['hoje'], 'contador de Hoje conta as duas de hoje');
    eq(1, $cont['amanha'], 'contador de Amanhã');

    /* ================================================================= */
    secao('3. Filtros');
    /* ================================================================= */

    eq(2, count($lista(['tipo' => 'juridica'])), 'só jurídicas');
    eq(["tarefa:{$QUADRO_DE_ESCRITORIO}"], $chaves($lista(['tipo' => 'escritorio'])), 'só escritório');
    eq(2, count($lista(['responsavel_id' => $USER_A])), 'responsável por id acha a tarefa de quadro e a processual (gravada como nome)');
    eq(1, count($lista(['prioridade' => 'alta'])), 'prioridade');
    eq([], $lista(['prioridade' => 'urgente', 'tipo' => 'juridica']), 'processo não tem urgente: filtro não quebra');
    eq(2, count($lista(['processo_id' => $PROC_A])), 'filtro por processo');

    eq(2, count(Central::listar($escopo, ['busca' => "Cliente {$MARCA}"])['itens']), 'busca pelo nome do cliente acha as duas do processo');

    /* ================================================================= */
    secao('4. É o mesmo registro: concluir de um lado reflete no outro');
    /* ================================================================= */

    $pdo->prepare('UPDATE processo_tarefas SET concluido = 1 WHERE id = ?')->execute([$TAREFA]);
    ok(!in_array("processo_tarefa:{$TAREFA}", $chaves($lista()), true), 'concluída pelo processo some de "A fazer"');
    ok(in_array("processo_tarefa:{$TAREFA}", $chaves($lista(['situacao' => 'concluidas'])), true), 'e aparece em Concluídas');
    $pdo->prepare('UPDATE processo_tarefas SET concluido = 0 WHERE id = ?')->execute([$TAREFA]);
    ok(in_array("processo_tarefa:{$TAREFA}", $chaves($lista()), true), 'reaberta volta para "A fazer"');

    $pdo->prepare("UPDATE processo_prazos SET status = 'concluido' WHERE id = ?")->execute([$PRAZO]);
    ok(!in_array("prazo:{$PRAZO}", $chaves($lista()), true), 'prazo concluído sai de "A fazer"');
    $pdo->prepare("UPDATE processo_prazos SET status = 'vencido' WHERE id = ?")->execute([$PRAZO]);
    ok(in_array("prazo:{$PRAZO}", $chaves($lista()), true), 'prazo vencido e não feito continua aparecendo');
    $pdo->prepare("UPDATE processo_prazos SET status = 'pendente' WHERE id = ?")->execute([$PRAZO]);

    Task::complete($QUADRO_DE_ESCRITORIO, $USER_A);
    ok(in_array("tarefa:{$QUADRO_DE_ESCRITORIO}", $chaves($lista(['situacao' => 'concluidas'])), true), 'tarefa de quadro concluída');
    Task::reopen($QUADRO_DE_ESCRITORIO, $USER_A);
    ok(in_array("tarefa:{$QUADRO_DE_ESCRITORIO}", $chaves($lista()), true), 'Task::reopen devolve a tarefa de quadro para "A fazer"');

    /* ================================================================= */
    secao('5. Vínculo tarefa de quadro + processo');
    /* ================================================================= */

    $pdo->prepare("INSERT INTO task_links (task_id, link_type, link_id) VALUES (?, 'processo', ?)")->execute([$QUADRO_DE_ESCRITORIO, $PROC_A]);
    $i = array_column($lista(), null, 'chave')["tarefa:{$QUADRO_DE_ESCRITORIO}"];
    eq('juridica', $i['tipo'], 'tarefa de quadro ligada a processo passa a ser jurídica');
    eq($PROC_A, $i['processo_id'], 'e mostra o processo');
    eq(3, count($lista(['processo_id' => $PROC_A])), 'e entra no filtro por processo');

    $pdo->prepare("INSERT INTO task_links (task_id, link_type, link_id) VALUES (?, 'processo', ?)")->execute([$QUADRO_DE_ESCRITORIO, $PROC_B]);
    $pdo->prepare('DELETE FROM task_links WHERE task_id = ? AND link_id = ?')->execute([$QUADRO_DE_ESCRITORIO, $PROC_A]);
    $i = array_column($lista(), null, 'chave')["tarefa:{$QUADRO_DE_ESCRITORIO}"];
    eq(null, $i['processo_numero'], 'vínculo com processo de OUTRO escritório não vaza o número');
    eq('escritorio', $i['tipo'], 'e a tarefa fica como de escritório');
    $pdo->prepare('DELETE FROM task_links WHERE task_id = ?')->execute([$QUADRO_DE_ESCRITORIO]);

    /* ================================================================= */
    secao('6. Isolamento entre escritórios');
    /* ================================================================= */

    ok(!in_array("processo_tarefa:{$TAREFA_B}", $chaves($lista()), true), 'tarefa do processo de outro escritório não aparece');
    $doB = ['user_id' => $USER_B, 'is_admin' => true, 'contas_tarefas' => [$CONTA_B], 'contas_processos' => [$CONTA_B]];
    eq(["processo_tarefa:{$TAREFA_B}"], $chaves($lista([], $doB)), 'o admin do outro escritório só vê o dele');

    eq([], Central::listar(['user_id' => 0, 'is_admin' => true, 'contas_tarefas' => [], 'contas_processos' => []], [])['itens'], 'sem conta, sem nada');

    eq(1, count(Central::buscarProcessos([$CONTA_A], $USER_A, $MARCA)), 'busca de processo acha o da conta');
    eq($PROC_A, (int) Central::buscarProcessos([$CONTA_A], $USER_A, "Cliente {$MARCA}")[0]['id'], 'pelo nome do cliente');
    ok(count(array_filter(Central::buscarProcessos([$CONTA_A], $USER_A, '1009347'), fn ($p) => (int) $p['id'] === $PROC_A)) === 1, 'pelos dígitos do número');
    ok(count(array_filter(Central::buscarProcessos([$CONTA_A], $USER_A, '1009347-41'), fn ($p) => (int) $p['id'] === $PROC_A)) === 1, 'pelo número com pontuação');
    eq([], Central::buscarProcessos([$CONTA_A], $USER_A, "Outro {$MARCA}"), 'processo de outro escritório não vem na busca');
    // 08/11/2026: sem termo, a lista já aparece com os mais recentes, mas só os do escritório.
    $vazia = Central::buscarProcessos([$CONTA_A], $USER_A, '');
    ok(count($vazia) > 0 && count($vazia) <= 12, 'busca vazia traz os mais recentes, com limite');
    ok(!in_array($PROC_B, array_map(fn ($p) => (int) $p['id'], $vazia), true), 'busca vazia não traz processo de outro escritório');
    eq([$PROC_A], array_map(fn ($p) => (int) $p['id'], Central::buscarProcessos([$CONTA_A], $USER_A, '', 20, "Cliente {$MARCA}")), 'filtro pelo cliente traz só os processos dele');
    eq([], Central::buscarProcessos([$CONTA_A], $USER_A, '', 20, "Outro {$MARCA}"), 'filtro por cliente de outro escritório não traz nada');
    $cli = Central::buscarClientes([$CONTA_A], $USER_A, $MARCA);
    eq([["nome" => "Cliente {$MARCA}", 'processos' => 1]], $cli, 'lista de clientes acha o da conta, com quantos processos');
    eq([], Central::buscarClientes([$CONTA_A], $USER_A, "Outro {$MARCA}"), 'cliente de outro escritório não vem na lista');
    eq([], Central::buscarClientes([], $USER_A, ''), 'sem conta, sem cliente');

    $pdo->prepare("INSERT INTO resource_shares (resource_type, resource_id, from_account_id, to_account_id, status) VALUES ('processo', ?, ?, ?, 'active')")
        ->execute([$PROC_B, $CONTA_B, $CONTA_A]);
    ok(in_array("processo_tarefa:{$TAREFA_B}", $chaves($lista()), true), 'processo compartilhado entra, como em Processos');

    /* ================================================================= */
    secao('7. Processo excluído e quadro pessoal alheio');
    /* ================================================================= */

    $pdo->prepare('UPDATE processos SET deleted_at = NOW() WHERE id = ?')->execute([$PROC_A]);
    ok(!in_array("processo_tarefa:{$TAREFA}", $chaves($lista()), true), 'tarefa de processo excluído sai da central');
    $pdo->prepare('UPDATE processos SET deleted_at = NULL WHERE id = ?')->execute([$PROC_A]);

    $pdo->prepare("UPDATE task_boards SET tipo = 'pessoal', owner_id = ? WHERE id = ?")->execute([$USER_A, $QUADRO]);
    $outro = ['user_id' => $USER_A + 100000, 'is_admin' => false, 'contas_tarefas' => [$CONTA_A], 'contas_processos' => [$CONTA_A]];
    ok(!in_array("tarefa:{$QUADRO_DE_ESCRITORIO}", $chaves($lista([], $outro)), true), 'colega não vê tarefa de quadro pessoal alheio');
    $adminDaConta = ['is_admin' => true] + $outro;
    ok(in_array("tarefa:{$QUADRO_DE_ESCRITORIO}", $chaves($lista([], $adminDaConta)), true), 'admin da conta vê');
    $st = $pdo->prepare('SELECT MIN(id) FROM users WHERE account_id = ? AND id <> ? AND deleted_at IS NULL');
    $st->execute([$CONTA_A, $USER_A]);
    if ($colega = (int) $st->fetchColumn()) {
        $pdo->prepare('UPDATE tasks SET responsavel_id = ? WHERE id = ?')->execute([$colega, $QUADRO_DE_ESCRITORIO]);
        ok(in_array("tarefa:{$QUADRO_DE_ESCRITORIO}", $chaves($lista([], ['user_id' => $colega] + $outro)), true), 'quem recebeu a tarefa vê, mesmo em quadro alheio');
    }
} finally {
    $pdo->prepare('DELETE FROM resource_shares WHERE resource_type = ? AND resource_id = ?')->execute(['processo', $PROC_B]);
    $pdo->prepare('DELETE FROM task_links WHERE task_id = ?')->execute([$QUADRO_DE_ESCRITORIO]);
    // task_history é imutável (LGPD): a tarefa de teste fica arquivada, num quadro desligado.
    $pdo->prepare("UPDATE tasks SET status = 'arquivada' WHERE id = ?")->execute([$QUADRO_DE_ESCRITORIO]);
    $pdo->prepare('UPDATE task_boards SET ativo = 0 WHERE id = ?')->execute([$QUADRO]);
    $pdo->prepare('DELETE FROM processo_tarefas WHERE processo_id IN (?, ?)')->execute([$PROC_A, $PROC_B]);
    $pdo->prepare('DELETE FROM processo_prazos WHERE processo_id = ?')->execute([$PROC_A]);
    $pdo->prepare('DELETE FROM processos WHERE id IN (?, ?)')->execute([$PROC_A, $PROC_B]);
}

fim:
echo "\n{$PASSES} ok, {$FAILS} falha(s)\n";
exit($FAILS ? 1 : 0);
