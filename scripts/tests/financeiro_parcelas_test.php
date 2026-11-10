<?php
/**
 * financeiro_parcelas_test.php — receitas e despesas com cliente, processo, parcelas e baixa
 * (T2, 10/11/2026, migration 179, App\Financas\Parcelamento).
 *
 * Trava:
 *   1. Regras puras: vencimento mensal pelo dia do primeiro (31/01 → 28 ou 29/02,
 *      31/03, 30/04, 31/05, 30/06) e divisão em centavos com o resto na última
 *      (R$ 100,00 em 3 = 33,33 + 33,33 + 33,34).
 *   2. Pelo HTTP real (api/financeiro_parcelas.php e api/dre_accounts.php):
 *      parcelamento gera N lançamentos com o mesmo cliente e processo; cada um
 *      editável (valor e data); processo de outro cliente é recusado; baixa grava
 *      a data e tira das pendências, e pendente = total - baixado (SELECT);
 *      a visão por cliente e por processo bate com o SELECT; lançamento simples
 *      sem cliente nem processo continua igual; título não é editado pelo editor
 *      antigo (409).
 *
 * ESCREVE NO BANCO LOCAL e NÃO APAGA. Cliente "[TESTE-AUTO] T2 Cliente" e processo
 * TESTE-AUTO-T2 são reaproveitados; cada rodada cria 2 parcelamentos (6 + 3
 * parcelas, vencimentos em 2031) e 1 lançamento simples "[TESTE-AUTO] T2 ...".
 *
 * Precisa do servidor local em http://localhost:8090 (ou YURIS_TESTE_URL) e da migration 179.
 * Uso: C:\xampp\php\php.exe scripts/tests/financeiro_parcelas_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Financas\Parcelamento;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function eq($esp, $obt, string $m): void { $esp === $obt ? pass($m) : fail($m . ' (esperado ' . json_encode($esp) . ', obtido ' . json_encode($obt) . ')'); }

// Sessão de teste antes de qualquer saída (session_id não muda depois do primeiro echo).
$BASE = rtrim(getenv('YURIS_TESTE_URL') ?: 'http://localhost:8090', '/');
$CONTA = 1;
$u = $pdo->query("SELECT u.*, a.tipo acc_tipo, a.nome acc_nome, a.plano acc_plano FROM users u JOIN accounts a ON a.id = u.account_id
                   WHERE u.account_id = $CONTA AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
$CSRF = str_repeat('f', 32);
$sid = 't2fin' . bin2hex(random_bytes(8));
session_id($sid);
@session_start();
$_SESSION = [
    'user_id' => (int) $u['id'], 'user_nome' => $u['nome'], 'user_perfil' => $u['perfil'],
    'user_role' => $u['role'] ?? 'owner', 'account_id' => $CONTA,
    'account_tipo' => $u['acc_tipo'] ?? 'matriz', 'account_nome' => $u['acc_nome'] ?? '',
    'account_plano' => $u['acc_plano'] ?? 'basico', 'user_permissions' => ['*'], 'csrf_token' => $CSRF,
];
session_write_close();

/* ===================================================================== */
secao('1. Regras de data e valor (CA3)');
/* ===================================================================== */
eq(['2031-01-31', '2031-02-28', '2031-03-31', '2031-04-30', '2031-05-31', '2031-06-30'],
   Parcelamento::vencimentos('2031-01-31', 6), '31/01 em ano comum: 28/02, 31/03, 30/04, 31/05, 30/06');
eq(['2032-01-31', '2032-02-29', '2032-03-31'], Parcelamento::vencimentos('2032-01-31', 3), '31/01 em ano bissexto: 29/02');
eq(['2031-11-30', '2031-12-30', '2032-01-30', '2032-02-29'], Parcelamento::vencimentos('2031-11-30', 4), 'vira o ano e volta ao dia 30 depois de fevereiro');
eq(['2031-03-15'], Parcelamento::vencimentos('2031-03-15', 1), 'parcela única no próprio dia');
eq([33.33, 33.33, 33.34], Parcelamento::dividir(100, 3), 'R$ 100,00 em 3 = 33,33 + 33,33 + 33,34');
eq(10000, (int) round(array_sum(Parcelamento::dividir(100, 3)) * 100), 'a soma é exatamente R$ 100,00');
eq([10000.0, 10000.0, 10000.0], Parcelamento::dividir(30000, 3), 'R$ 30.000,00 em 3 = 10.000,00 cada');
eq(1234.56, Parcelamento::numero('1.234,56'), 'lê 1.234,56');
eq(1234.56, Parcelamento::numero('1234.56'), 'lê 1234.56');
$recusa = function (callable $f): bool { try { $f(); return false; } catch (\InvalidArgumentException $e) { return true; } };
ok($recusa(fn () => Parcelamento::vencimentos('2031-02-30', 2)), 'data inexistente é recusada');
ok($recusa(fn () => Parcelamento::vencimentos('2031-01-31', 0)), 'zero parcelas é recusado');
ok($recusa(fn () => Parcelamento::dividir(0, 3)), 'valor zero é recusado');
ok($recusa(fn () => Parcelamento::dividir(0.02, 3)), 'valor menor que um centavo por parcela é recusado');

/* ===================================================================== */
/* cenário HTTP                                                          */
/* ===================================================================== */
$tem = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'dre_accounts' AND COLUMN_NAME = 'parcelamento_id'")->fetchColumn();
if (!$tem) { echo "\nSKIP do resto: falta a migration 179.\n"; goto fim; }

function http(string $metodo, string $caminho, ?array $corpo = null): array
{
    global $BASE, $sid, $CSRF;
    $ch = curl_init($BASE . $caminho);
    $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $metodo,
           CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
           CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-TOKEN: ' . $CSRF]];
    if ($corpo !== null) $op[CURLOPT_POSTFIELDS] = json_encode($corpo + ['csrf_token' => $CSRF]);
    curl_setopt_array($ch, $op);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $resp, true)];
}
$valor = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); };
$API = '/api/financeiro_parcelas.php';

// Cliente e processo de teste, e um segundo cliente sem processo.
$cliente = function (string $nome) use ($valor, $CONTA): int {
    $id = (int) $valor('SELECT id FROM clientes WHERE account_id = ? AND nome = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$CONTA, $nome]);
    if ($id) return $id;
    [$code, $j] = http('POST', '/api/clientes.php', ['nome' => $nome, 'tipo_cliente' => 'PF']);
    return (int) ($j['id'] ?? 0);
};
$CLI  = $cliente('[TESTE-AUTO] T2 Cliente');
$CLI2 = $cliente('[TESTE-AUTO] T2 Outro cliente');
$PROC = (int) $valor('SELECT id FROM processos WHERE account_id = ? AND numero = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$CONTA, 'TESTE-AUTO-T2']);
if ($PROC && (int) $valor('SELECT COALESCE(cliente_id,0) FROM processos WHERE id = ?', [$PROC]) !== $CLI && $CLI > 0) {
    // Processo de teste sem o cliente de teste (rodada anterior que falhou): só esta linha de teste é ajustada.
    $pdo->prepare('UPDATE processos SET cliente_id = ? WHERE id = ? AND numero = ?')->execute([$CLI, $PROC, 'TESTE-AUTO-T2']);
}
if (!$PROC) {
    $PROC = (int) \App\Processos\Processo::create(['account_id' => $CONTA, 'numero' => 'TESTE-AUTO-T2', 'cliente_id' => $CLI,
                                                   'cliente_nome' => '[TESTE-AUTO] T2 Cliente', 'status' => 'ativo']);
}
ok($CLI > 0 && $CLI2 > 0 && $PROC > 0, "dados de teste: clientes $CLI e $CLI2, processo $PROC (do cliente $CLI)");

secao('2. Campos cliente e processo (CA1)');
[$code, $j] = http('GET', $API . '?clientes=1');
ok($code === 200 && in_array($CLI, array_map('intval', array_column($j['data'] ?? [], 'id')), true), 'lista de clientes traz o cliente de teste');
[$code, $j] = http('GET', $API . '?processos_do_cliente=' . $CLI);
ok(in_array($PROC, array_map('intval', array_column($j['data'] ?? [], 'id')), true), 'processos do cliente traz o processo dele');
[$code, $j] = http('GET', $API . '?processos_do_cliente=' . $CLI2);
ok(!in_array($PROC, array_map('intval', array_column($j['data'] ?? [], 'id')), true), 'o processo não aparece para outro cliente');
foreach (['receita', 'despesa'] as $tipo) {
    [$code, $j] = http('POST', '/api/dre_accounts.php', ['nome' => "[TESTE-AUTO] T2 $tipo com cliente", 'tipo' => $tipo, 'recorrencia' => 'variavel',
                                                         'valor_fixo' => 10, 'data_referencia' => '2031-01-01', 'cliente_id' => $CLI, 'processo_id' => $PROC]);
    $id = (int) ($j['id'] ?? 0);
    ok($code === 200 && (int) $valor('SELECT cliente_id FROM dre_accounts WHERE id = ?', [$id]) === $CLI
       && (int) $valor('SELECT processo_id FROM dre_accounts WHERE id = ?', [$id]) === $PROC, "$tipo simples grava cliente e processo (id $id)");
}
[$code] = http('POST', '/api/dre_accounts.php', ['nome' => '[TESTE-AUTO] T2 nao grava', 'tipo' => 'receita', 'valor_fixo' => 1,
                                                 'data_referencia' => '2031-01-01', 'cliente_id' => $CLI2, 'processo_id' => $PROC]);
ok($code === 400, "processo de outro cliente é recusado (HTTP $code)");
ok($recusa(fn () => Parcelamento::validarVinculos($CLI, null, [(int) $valor('SELECT COALESCE(MAX(id),0)+1000 FROM accounts')])), 'cliente de outra conta é recusado');

secao('3. Parcelamento gera N lançamentos (CA2, CA3)');
[$code, $j] = http('POST', $API, ['acao' => 'criar', 'tipo' => 'receita', 'descricao' => '[TESTE-AUTO] T2 Honorários 6x',
                                   'cliente_id' => $CLI, 'processo_id' => $PROC, 'quantidade' => 6,
                                   'primeiro_vencimento' => '2031-01-31', 'valor_parcela' => '1.000,00']);
$P6 = array_map('intval', $j['parcelas'] ?? []);
ok($code === 200 && count($P6) === 6, 'criar 6 parcelas responde ok (HTTP ' . $code . ')');
$st = $pdo->prepare('SELECT vencimento, data_referencia, valor_fixo, cliente_id, processo_id, parcela_numero, parcela_total, recorrencia, pago_em
                       FROM dre_accounts WHERE id IN (' . implode(',', $P6 ?: [0]) . ') ORDER BY parcela_numero');
$st->execute();
$linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
eq(['2031-01-31', '2031-02-28', '2031-03-31', '2031-04-30', '2031-05-31', '2031-06-30'], array_column($linhas, 'vencimento'), 'SELECT: vencimentos no último dia válido de cada mês');
ok(array_column($linhas, 'vencimento') === array_column($linhas, 'data_referencia'), 'SELECT: a competência é o vencimento (o DRE conta no mês certo)');
eq(['1000.00'], array_values(array_unique(array_column($linhas, 'valor_fixo'))), 'SELECT: R$ 1.000,00 em cada');
ok(array_values(array_unique(array_map('intval', array_column($linhas, 'cliente_id')))) === [$CLI] && array_values(array_unique(array_map('intval', array_column($linhas, 'processo_id')))) === [$PROC],
   'SELECT: todas com o mesmo cliente e processo');
eq([1, 2, 3, 4, 5, 6], array_map('intval', array_column($linhas, 'parcela_numero')), 'SELECT: parcelas numeradas 1 a 6 de 6');
ok(array_unique(array_column($linhas, 'pago_em')) === [null], 'SELECT: todas pendentes');

[$code, $j] = http('POST', $API, ['acao' => 'criar', 'tipo' => 'despesa', 'descricao' => '[TESTE-AUTO] T2 Custas 3x',
                                   'cliente_id' => $CLI, 'quantidade' => 3, 'primeiro_vencimento' => '2031-03-10', 'valor_total' => '100,00']);
$P3 = array_map('intval', $j['parcelas'] ?? []);
ok($code === 200 && count($P3) === 3, 'criar 3 parcelas pelo total responde ok');
eq('100.00', $valor('SELECT SUM(valor_fixo) FROM dre_accounts WHERE id IN (' . implode(',', $P3 ?: [0]) . ')'), 'SELECT: R$ 100,00 em 3 soma exatamente 100,00');
eq(['33.33', '33.33', '33.34'], $pdo->query('SELECT valor_fixo FROM dre_accounts WHERE id IN (' . implode(',', $P3 ?: [0]) . ') ORDER BY parcela_numero')->fetchAll(\PDO::FETCH_COLUMN), 'SELECT: 33,33 + 33,33 + 33,34');
[$code, $j] = http('POST', $API, ['acao' => 'criar', 'tipo' => 'receita', 'descricao' => 'x', 'cliente_id' => $CLI2, 'processo_id' => $PROC,
                                   'quantidade' => 2, 'primeiro_vencimento' => '2031-01-01', 'valor_total' => 10]);
ok($code === 400, "parcelamento com processo de outro cliente é recusado (HTTP $code)");

secao('4. Cada parcela é editável (CA2)');
[$code] = http('POST', $API, ['acao' => 'editar', 'id' => $P6[1], 'valor' => '1.100,50', 'vencimento' => '2031-02-27']);
ok($code === 200, 'editar a parcela 2 responde ok');
eq(['1100.50', '2031-02-27', '2031-02-27'], array_values($pdo->query('SELECT valor_fixo, vencimento, data_referencia FROM dre_accounts WHERE id = ' . $P6[1])->fetch(\PDO::FETCH_NUM)),
   'SELECT: valor e data da parcela 2 mudaram (competência junto)');
[$code, $j] = http('PUT', '/api/dre_accounts.php', ['id' => $P6[2], 'nome' => 'x', 'tipo' => 'receita', 'valor_fixo' => 1, 'data_referencia' => '2031-03-01']);
ok($code === 409 && $valor('SELECT valor_fixo FROM dre_accounts WHERE id = ?', [$P6[2]]) === '1000.00', "o editor antigo (só mês) não mexe em título: 409 (HTTP $code)");

secao('5. Baixa (CA4)');
$totalAntes = (float) $valor('SELECT SUM(valor_fixo) FROM dre_accounts WHERE parcelamento_id = (SELECT parcelamento_id FROM dre_accounts WHERE id = ?)', [$P6[0]]);
[$code] = http('POST', $API, ['acao' => 'baixar', 'id' => $P6[0], 'data' => '2031-01-31']);
ok($code === 200, 'baixar a parcela 1 responde ok');
eq('2031-01-31', $valor('SELECT pago_em FROM dre_accounts WHERE id = ?', [$P6[0]]), 'SELECT: data da baixa gravada');
ok((int) $valor('SELECT pago_por FROM dre_accounts WHERE id = ?', [$P6[0]]) === (int) $u['id'], 'SELECT: quem baixou gravado');
[$code] = http('POST', $API, ['acao' => 'baixar', 'id' => $P6[0]]);
ok($code === 400, 'baixar de novo é recusado');
[$code] = http('POST', $API, ['acao' => 'editar', 'id' => $P6[0], 'valor' => 1, 'vencimento' => '2031-01-31']);
ok($code === 400 && $valor('SELECT valor_fixo FROM dre_accounts WHERE id = ?', [$P6[0]]) === '1000.00', 'parcela baixada não é editada');
$grupo = (int) $valor('SELECT parcelamento_id FROM dre_accounts WHERE id = ?', [$P6[0]]);
$pendSel = (float) $valor('SELECT SUM(valor_fixo) FROM dre_accounts WHERE parcelamento_id = ? AND pago_em IS NULL AND ativo = 1', [$grupo]);
$pagoSel = (float) $valor('SELECT SUM(valor_fixo) FROM dre_accounts WHERE parcelamento_id = ? AND pago_em IS NOT NULL AND ativo = 1', [$grupo]);
ok(abs(($totalAntes - $pagoSel) - $pendSel) < 0.005, sprintf('SELECT: pendente %.2f = total %.2f - baixado %.2f', $pendSel, $totalAntes, $pagoSel));
[$code, $j] = http('GET', $API . '?visao=1&situacao=pendente&cliente_id=' . $CLI);
$ids = array_map('intval', array_column($j['data']['itens'] ?? [], 'id'));
ok(!in_array($P6[0], $ids, true) && in_array($P6[1], $ids, true), 'a parcela baixada saiu das pendências; a outra ficou');
[$code] = http('POST', $API, ['acao' => 'desfazer', 'id' => $P6[5]]);
ok($code === 200 && $valor('SELECT pago_em FROM dre_accounts WHERE id = ?', [$P6[5]]) === null, 'desfazer numa pendente não quebra nada');

secao('5b. Atraso, juros e observação na baixa, refletidos no DRE (pedido de 10/11/2026)');
// A parcela 3 (vence 31/03/2031) é recebida com 5 dias de atraso, R$ 15,50 de juros e observação.
$dreReceita = function (string $de, string $ate): float {
    [, $j] = http('GET', '/api/dre_accounts.php?start=' . $de . '&end=' . $ate);
    return (float) ($j['combined']['receita'] ?? -1);
};
$antesDre = $dreReceita('2031-04-01', '2031-04-30');
[$code] = http('POST', $API, ['acao' => 'baixar', 'id' => $P6[2], 'data' => '2031-04-05', 'juros' => '15,50', 'obs' => '[TESTE-AUTO] pagou com atraso, juros combinados']);
ok($code === 200, 'baixa com juros e observação responde ok');
eq(['2031-04-05', '[TESTE-AUTO] pagou com atraso, juros combinados'],
   array_values($pdo->query("SELECT pago_em, baixa_obs FROM dre_accounts WHERE id = {$P6[2]}")->fetch(\PDO::FETCH_NUM)), 'SELECT: data e observação da baixa gravadas');
$jur = $pdo->query("SELECT id, tipo, valor_fixo, data_referencia, cliente_id, processo_id, vencimento, ativo FROM dre_accounts WHERE juros_de_id = {$P6[2]}")->fetchAll(\PDO::FETCH_ASSOC);
ok(count($jur) === 1 && $jur[0]['tipo'] === 'receita' && $jur[0]['valor_fixo'] === '15.50' && $jur[0]['data_referencia'] === '2031-04-05'
   && (int) $jur[0]['cliente_id'] === $CLI && (int) $jur[0]['processo_id'] === $PROC && $jur[0]['vencimento'] === null && (int) $jur[0]['ativo'] === 1,
   'SELECT: juros viram 1 lançamento de receita de R$ 15,50 na data da baixa, com o cliente e o processo, ligado à parcela');
$depoisDre = $dreReceita('2031-04-01', '2031-04-30');
ok(abs(($depoisDre - $antesDre) - 15.50) < 0.005, sprintf('DRE de abril/2031: receita %.2f → %.2f, os juros entram no DRE', $antesDre, $depoisDre));
[$code, $j] = http('GET', $API . '?visao=1&processo_id=' . $PROC);
$it = array_values(array_filter($j['data']['itens'] ?? [], fn ($x) => (int) $x['id'] === $P6[2]))[0] ?? [];
ok(($it['dias_atraso'] ?? null) === 5 && (float) ($it['juros'] ?? 0) === 15.5 && ($it['baixa_obs'] ?? '') !== '', 'a visão mostra 5 dias de atraso, juros R$ 15,50 e a observação');
[$code, $j] = http('GET', '/api/dre_accounts.php');
$lj = array_values(array_filter($j['data'] ?? [], fn ($x) => (int) $x['id'] === (int) ($jur[0]['id'] ?? 0)))[0] ?? [];
ok(($lj['juros_de_nome'] ?? '') !== '' && (int) ($lj['cliente_id'] ?? 0) === $CLI && ($lj['cliente_nome'] ?? '') === '[TESTE-AUTO] T2 Cliente',
   'a lista do DRE diz de onde vem o juro: a parcela e o cliente');
[$code] = http('POST', $API, ['acao' => 'desfazer', 'id' => $P6[2]]);
ok($code === 200 && $valor('SELECT pago_em FROM dre_accounts WHERE id = ?', [$P6[2]]) === null
   && $valor('SELECT baixa_obs FROM dre_accounts WHERE id = ?', [$P6[2]]) === null, 'desfazer volta a pendente e limpa a observação');
eq(0, (int) $valor('SELECT ativo FROM dre_accounts WHERE juros_de_id = ?', [$P6[2]]), 'desfazer tira os juros do DRE (ativo = 0, nada apagado)');
ok(abs($dreReceita('2031-04-01', '2031-04-30') - $antesDre) < 0.005, 'DRE de abril/2031 volta ao valor de antes');
[$code] = http('POST', $API, ['acao' => 'baixar', 'id' => $P6[3], 'juros' => '-1']);
ok($code === 400, 'juros negativo é recusado');

secao('6. Visão por cliente e por processo (CA5)');
[$code, $j] = http('GET', $API . '?visao=1&processo_id=' . $PROC);
$v = $j['data'] ?? [];
$totProc = (float) $valor("SELECT SUM(valor_fixo) FROM dre_accounts WHERE ativo = 1 AND vencimento IS NOT NULL AND processo_id = ? AND tipo = 'receita' AND account_id = ?", [$PROC, $CONTA]);
$pagoProc = (float) $valor("SELECT COALESCE(SUM(valor_fixo),0) FROM dre_accounts WHERE ativo = 1 AND vencimento IS NOT NULL AND processo_id = ? AND tipo = 'receita' AND pago_em IS NOT NULL AND account_id = ?", [$PROC, $CONTA]);
ok($code === 200 && abs(($v['totais']['receita']['total'] ?? -1) - $totProc) < 0.005, sprintf('total a receber do processo bate com o SELECT (%.2f)', $totProc));
ok(abs(($v['totais']['receita']['pago'] ?? -1) - $pagoProc) < 0.005, sprintf('recebido do processo bate (%.2f)', $pagoProc));
ok(abs(($v['totais']['receita']['pendente'] ?? -1) - ($totProc - $pagoProc)) < 0.005, 'pendente do processo = total - recebido');
$porProc = array_values(array_filter($v['por_processo'] ?? [], fn ($x) => (int) $x['id'] === $PROC));
ok($porProc && abs($porProc[0]['totais']['receita']['total'] - $totProc) < 0.005, 'o agrupamento por processo traz o processo de teste');
[$code, $j] = http('GET', $API . '?visao=1&cliente_id=' . $CLI);
$v = $j['data'] ?? [];
$totCli = (float) $valor("SELECT SUM(valor_fixo) FROM dre_accounts WHERE ativo = 1 AND vencimento IS NOT NULL AND cliente_id = ? AND tipo = 'despesa' AND account_id = ?", [$CLI, $CONTA]);
ok(abs(($v['totais']['despesa']['total'] ?? -1) - $totCli) < 0.005, sprintf('total a pagar do cliente bate com o SELECT (%.2f)', $totCli));
ok((bool) array_filter($v['por_cliente'] ?? [], fn ($x) => (int) $x['id'] === $CLI), 'o agrupamento por cliente traz o cliente de teste');
ok(!array_filter($v['itens'] ?? [], fn ($x) => (int) $x['cliente_id'] !== $CLI), 'o filtro por cliente não traz título de outro cliente');

secao('6b. Projeção: este mês, próximo mês, saldo futuro total e atraso (pedido de 10/11/2026)');
$hoje = Parcelamento::hoje();
// Um título vencido ontem e outro que vence no último dia deste mês: atraso e "este mês" com valor.
$ontem = (new \DateTimeImmutable($hoje))->modify('-1 day')->format('Y-m-d');
$ultimoDia = (new \DateTimeImmutable($hoje))->format('Y-m-t');
[$cA] = http('POST', $API, ['acao' => 'criar', 'tipo' => 'receita', 'descricao' => '[TESTE-AUTO] T2 vencido ontem', 'cliente_id' => $CLI,
                             'quantidade' => 1, 'primeiro_vencimento' => $ontem, 'valor_total' => '7,00']);
[$cB] = http('POST', $API, ['acao' => 'criar', 'tipo' => 'receita', 'descricao' => '[TESTE-AUTO] T2 vence este mês', 'cliente_id' => $CLI,
                             'quantidade' => 1, 'primeiro_vencimento' => $ultimoDia, 'valor_total' => '8,00']);
ok($cA === 200 && $cB === 200, 'títulos de teste: vencido ontem (R$ 7,00) e vencendo no fim do mês (R$ 8,00)');
[$code, $j] = http('GET', $API . '?projecao=1&meses=12');
$pj = $j['data'] ?? [];
ok((float) ($pj['receita']['atrasado'] ?? 0) >= 7 && (float) ($pj['receita']['mes_atual'] ?? 0) >= 8, 'o atraso e o "ainda neste mês" aparecem com valor');
$fimMes = (new \DateTimeImmutable($hoje))->format('Y-m-t');
$ini2 = (new \DateTimeImmutable($hoje))->modify('first day of next month')->format('Y-m-d');
$fim2 = (new \DateTimeImmutable($ini2))->format('Y-m-t');
$soma = fn (string $tipo, string $cond, array $p) => (float) $valor("SELECT COALESCE(SUM(valor_fixo),0) FROM dre_accounts
    WHERE account_id = ? AND ativo = 1 AND vencimento IS NOT NULL AND tipo = ? AND $cond", array_merge([$CONTA, $tipo], $p));
foreach (['receita', 'despesa'] as $tipo) {
    $esp = [
        'atrasado'     => $soma($tipo, 'pago_em IS NULL AND vencimento < ?', [$hoje]),
        'mes_atual'    => $soma($tipo, 'pago_em IS NULL AND vencimento BETWEEN ? AND ?', [$hoje, $fimMes]),
        'proximo_mes'  => $soma($tipo, 'pago_em IS NULL AND vencimento BETWEEN ? AND ?', [$ini2, $fim2]),
        'saldo_futuro' => $soma($tipo, 'pago_em IS NULL AND vencimento >= ?', [$hoje]),
    ];
    $okT = $code === 200;
    foreach ($esp as $k => $v) $okT = $okT && abs((float) ($pj[$tipo][$k] ?? -1) - $v) < 0.005;
    ok($okT, "projeção de $tipo bate com o SELECT " . json_encode($esp));
    ok(abs((float) ($pj[$tipo]['total_aberto'] ?? -1) - ($esp['atrasado'] + $esp['saldo_futuro'])) < 0.005, "total em aberto de $tipo = atraso + saldo futuro");
}
$somaMeses = array_sum(array_column($pj['meses'] ?? [], 'receita'));
ok($somaMeses <= (float) ($pj['receita']['saldo_futuro'] ?? 0) + 0.005, 'mês a mês (12 meses) nunca passa do saldo futuro total');

secao('7. Lançamento simples continua igual (CA6)');
[$code, $j] = http('POST', '/api/dre_accounts.php', ['nome' => '[TESTE-AUTO] T2 lançamento simples', 'tipo' => 'despesa', 'recorrencia' => 'fixa',
                                                     'valor_fixo' => 50, 'data_referencia' => '2031-01-01']);
$S = (int) ($j['id'] ?? 0);
ok($code === 200 && $S > 0, 'POST de lançamento sem cliente, processo nem parcelas');
eq([null, null, null, null], array_values($pdo->query("SELECT cliente_id, processo_id, vencimento, parcelamento_id FROM dre_accounts WHERE id = $S")->fetch(\PDO::FETCH_NUM)),
   'SELECT: as colunas novas ficam vazias');
[$code] = http('PUT', '/api/dre_accounts.php', ['id' => $S, 'nome' => '[TESTE-AUTO] T2 lançamento simples', 'tipo' => 'despesa', 'recorrencia' => 'fixa',
                                                'valor_fixo' => 55, 'data_referencia' => '2031-01-01']);
ok($code === 200 && $valor('SELECT valor_fixo FROM dre_accounts WHERE id = ?', [$S]) === '55.00', 'PUT do lançamento simples continua funcionando');
[$code, $j] = http('GET', $API . '?visao=1');
ok(!in_array($S, array_map('intval', array_column($j['data']['itens'] ?? [], 'id')), true), 'lançamento sem vencimento não vira pendência');

fim:
echo "\n=== $PASSES ok / $FAILS falha(s) ===\n";
exit($FAILS > 0 ? 1 : 0);
