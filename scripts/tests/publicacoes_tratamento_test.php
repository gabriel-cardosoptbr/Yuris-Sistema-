<?php
/**
 * publicacoes_tratamento_test.php — tratamento das publicações e link do tribunal.
 *
 * O que este arquivo trava (reunião de 23/10/2026):
 *   1. Publicação tem estado do ESCRITÓRIO, com autor, data e providência.
 *   2. Mudar o estado nunca apaga o que veio antes: o histórico só cresce.
 *   3. Descartar exige motivo.
 *   4. Criar tarefa ou prazo conta como providência, sem pisar num tratamento já feito.
 *   5. Publicação de outro escritório não é alcançada.
 *   6. Link do tribunal só aceita endereço web: `javascript:` num href executa código.
 *
 * ESCREVE NO BANCO (registros com marca, apagados no fim). Não rode em produção.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/publicacoes_tratamento_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Processos\Processo;
use App\Processos\PushEvent;

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
secao('1. Link do tribunal');
/* ===================================================================== */

$n = fn ($v) => Processo::normalizarLinkTribunal($v);
eq('https://esaj.tjsp.jus.br/cpopg/show.do?processo.codigo=1', $n('https://esaj.tjsp.jus.br/cpopg/show.do?processo.codigo=1'), 'https passa');
eq('http://pje.trt2.jus.br/consulta', $n('http://pje.trt2.jus.br/consulta'), 'http passa');
eq('https://www.tjsp.jus.br/x', $n('www.tjsp.jus.br/x'), 'sem protocolo vira https');
eq('https://tjsp.jus.br', $n('  https://tjsp.jus.br  '), 'espaço nas pontas sai');
eq(null, $n(''), 'vazio limpa o campo');
eq(null, $n(null), 'null limpa o campo');
eq(false, $n('javascript:alert(1)'), 'javascript: é recusado');
eq(false, $n('JaVaScRiPt:alert(1)'), 'javascript: com maiúsculas também');
eq(false, $n('data:text/html,<script>1</script>'), 'data: é recusado');
eq(false, $n('ftp://tjsp.jus.br/a'), 'ftp é recusado');
eq(false, $n('https://'), 'sem host é recusado');
eq(false, $n('https://a.com/"onclick=1'), 'aspas são recusadas');
eq(false, $n('https://a.com/<script>'), 'sinal de menor é recusado');
eq(false, $n("https://a.com/\nx"), 'quebra de linha é recusada');
eq(false, $n('https://a.com/' . str_repeat('a', 500)), 'mais de 500 caracteres é recusado');

/* ===================================================================== */
/* cenário no banco                                                      */
/* ===================================================================== */

$st = $pdo->query("SELECT account_id, MIN(id) uid FROM users WHERE deleted_at IS NULL GROUP BY account_id ORDER BY account_id LIMIT 2");
$contas = $st->fetchAll(\PDO::FETCH_ASSOC);
if (count($contas) < 2) { echo "\nSKIP: precisa de duas contas.\n"; goto fim; }
$A = (int) $contas[0]['account_id']; $UA = (int) $contas[0]['uid'];
$B = (int) $contas[1]['account_id'];

$MARCA = 'ZZPUB' . bin2hex(random_bytes(4));
$novo = fn (int $conta, string $s) => PushEvent::upsert([
    'account_id' => $conta, 'source_id' => 'djen', 'tipo_evento' => 'publicacao', 'titulo' => $MARCA,
    'data_evento' => date('Y-m-d H:i:s'), 'data_disponibilizacao' => date('Y-m-d'), 'tribunal' => 'TJAC',
    'hash_conteudo' => hash('sha256', $MARCA . $s), 'status' => 'ativo', 'origem_busca' => 'manual',
]);
$EV = (int) $novo($A, 'a');
$EV_B = (int) $novo($B, 'b');
$hist = function (int $id) use ($pdo): array {
    $s = $pdo->prepare('SELECT status_anterior, status_novo, nota FROM push_event_tratamentos WHERE event_id = ? ORDER BY id');
    $s->execute([$id]);
    return $s->fetchAll(\PDO::FETCH_ASSOC);
};
$estado = fn (int $id, int $c) => PushEvent::findByIdForAccount($id, $c);

try {
    secao('2. Estado e autor');
    eq('pendente', $estado($EV, $A)['tratamento_status'], 'publicação nova nasce pendente');
    $antes = PushEvent::contarPendentes($A);

    ok(PushEvent::registrarTratamento($EV, $A, 'tratada', 'Petição protocolada', $UA, 'Fulano'), 'registra');
    $e = $estado($EV, $A);
    eq('tratada', $e['tratamento_status'], 'fica tratada');
    eq('Petição protocolada', $e['tratamento_nota'], 'com a providência');
    eq('Fulano', $e['tratamento_nome'], 'quem tratou');
    ok(!empty($e['tratamento_em']), 'e quando');
    eq($antes - 1, PushEvent::contarPendentes($A), 'sai da conta de pendentes');

    secao('3. Histórico só cresce');
    PushEvent::registrarTratamento($EV, $A, 'sem_providencia', 'Mero expediente', $UA, 'Fulano');
    PushEvent::registrarTratamento($EV, $A, 'pendente', null, $UA, 'Fulano');
    $h = $hist($EV);
    eq(3, count($h), 'três mudanças, três linhas');
    eq('pendente', $h[0]['status_anterior'], 'guarda de onde veio');
    eq('Petição protocolada', $h[0]['nota'], 'a primeira providência continua lá');
    eq('pendente', $estado($EV, $A)['tratamento_status'], 'voltou para pendente');
    eq(null, $estado($EV, $A)['tratamento_nome'], 'pendente não tem autor de tratamento');

    secao('4. Descartar exige motivo');
    $erro = '';
    try { PushEvent::registrarTratamento($EV, $A, 'descartada', '   ', $UA, 'Fulano'); }
    catch (\InvalidArgumentException $x) { $erro = $x->getMessage(); }
    eq('Informe o motivo do descarte.', $erro, 'sem motivo não descarta');
    eq(3, count($hist($EV)), 'e não grava nada');
    ok(PushEvent::registrarTratamento($EV, $A, 'descartada', 'Homônimo', $UA, 'Fulano'), 'com motivo descarta');
    ok($estado($EV, $A) !== null, 'descartar não apaga a publicação');

    $erro = '';
    try { PushEvent::registrarTratamento($EV, $A, 'qualquer', null, $UA, 'Fulano'); }
    catch (\InvalidArgumentException $x) { $erro = 'recusou'; }
    eq('recusou', $erro, 'situação desconhecida é recusada');

    secao('5. Tarefa ou prazo contam como providência');
    PushEvent::registrarTratamento($EV, $A, 'pendente', null, $UA, 'Fulano');
    PushEvent::marcarTratadaSePendente($EV, $A, 'Tarefa #9 criada', $UA, 'Fulano');
    eq('tratada', $estado($EV, $A)['tratamento_status'], 'pendente vira tratada');
    PushEvent::registrarTratamento($EV, $A, 'descartada', 'Homônimo', $UA, 'Fulano');
    PushEvent::marcarTratadaSePendente($EV, $A, 'Tarefa #10 criada', $UA, 'Fulano');
    eq('descartada', $estado($EV, $A)['tratamento_status'], 'não pisa num tratamento já registrado');

    secao('6. Isolamento entre escritórios');
    eq(false, PushEvent::registrarTratamento($EV_B, $A, 'tratada', 'x', $UA, 'Fulano'), 'publicação de outra conta não é alcançada');
    eq('pendente', $estado($EV_B, $B)['tratamento_status'], 'e continua como estava');
    eq([], $hist($EV_B), 'sem histórico');

    secao('7. Reimportar não apaga o tratamento');
    $de_novo = (int) $novo($A, 'a');
    eq($EV, $de_novo, 'a mesma publicação cai no mesmo registro');
    eq('descartada', $estado($EV, $A)['tratamento_status'], 'o tratamento continua');
} finally {
    $pdo->prepare('DELETE FROM push_event_tratamentos WHERE event_id IN (?, ?)')->execute([$EV, $EV_B]);
    $pdo->prepare('DELETE FROM push_events WHERE id IN (?, ?)')->execute([$EV, $EV_B]);
}

fim:
echo "\n{$PASSES} ok, {$FAILS} falha(s)\n";
exit($FAILS ? 1 : 0);
