<?php
/**
 * processo_valores_test.php — valores do processo, resultado, carteira e projeção
 * integrados ao Financeiro (T3, 10/11/2026, migration 180, App\Processos\ValoresDoProcesso).
 *
 * Pelo caminho real (HTTP em api/processes.php, api/processo_valores.php e
 * api/financeiro_parcelas.php, servidor local) com SELECT no banco, trava:
 *   1. Os campos existem e gravam; processo antigo, sem valores, abre e salva.
 *   2. Exemplo oficial: causa R$ 100.000,00, acordo de R$ 30.000,00 em 3 → resultado
 *      R$ 30.000,00 e 3 lançamentos pendentes de R$ 10.000,00 nos meses seguintes,
 *      com o cliente e o processo. À vista → 1 lançamento.
 *   3. Editar o resultado não duplica; parcela baixada nunca muda; só as pendentes.
 *   4. Carteira: 10 processos com R$ 100.000,00 projetados = R$ 1.000.000,00;
 *      encerrando 3 (20, 30 e 70 mil) e 1 improcedente, projetado x obtido bate.
 *   5. Projeção mensal com as parcelas previstas e o recebido.
 *   6. Fluxo completo: processo, projeção, resultado, parcelas, baixa, recebido.
 *
 * ESCREVE NO BANCO LOCAL e NÃO APAGA. Cada rodada cria 2 processos
 * "TESTE-AUTO-T3-<hora>" com as parcelas deles; os 10 da carteira
 * (TESTE-AUTO-T3-C01..C10) e o cliente "[TESTE-AUTO] T3 Cliente" são
 * reaproveitados (o resultado deles volta a vazio no começo, o que desativa as
 * parcelas pendentes antigas, sem apagar).
 *
 * Precisa do servidor local em http://localhost:8090 (ou YURIS_TESTE_URL) e das migrations 179 e 180.
 * Uso: C:\xampp\php\php.exe scripts/tests/processo_valores_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Financas\Parcelamento;
use App\Processos\ValoresDoProcesso;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

/* Sessão de teste antes de qualquer saída (como a varredura_urls.php: só SELECT). */
$BASE = rtrim(getenv('YURIS_TESTE_URL') ?: 'http://localhost:8090', '/');
$CONTA = 1;
$u = $pdo->query("SELECT u.*, a.tipo acc_tipo, a.nome acc_nome, a.plano acc_plano FROM users u JOIN accounts a ON a.id = u.account_id
                   WHERE u.account_id = $CONTA AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
$CSRF = str_repeat('g', 32);
$sid = 't3val' . bin2hex(random_bytes(8));
session_id($sid);
@session_start();
$_SESSION = [
    'user_id' => (int) $u['id'], 'user_nome' => $u['nome'], 'user_perfil' => $u['perfil'],
    'user_role' => $u['role'] ?? 'owner', 'account_id' => $CONTA, 'user_email' => $u['email'] ?? '',
    'account_tipo' => $u['acc_tipo'] ?? 'matriz', 'account_nome' => $u['acc_nome'] ?? '',
    'account_plano' => $u['acc_plano'] ?? 'basico', 'user_permissions' => ['*'], 'csrf_token' => $CSRF,
];
session_write_close();

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function eq($esp, $obt, string $m): void { $esp === $obt ? pass($m) : fail($m . ' (esperado ' . json_encode($esp) . ', obtido ' . json_encode($obt) . ')'); }
function http(string $metodo, string $caminho, ?array $corpo = null): array
{
    global $BASE, $sid, $CSRF;
    $ch = curl_init($BASE . $caminho);
    $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $metodo,
           CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
           // Um cabeçalho só: repetido, o PHP junta os dois valores e o CSRF não confere.
           CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $CSRF]];
    if ($corpo !== null) $op[CURLOPT_POSTFIELDS] = json_encode($corpo + ['csrf_token' => $CSRF]);
    curl_setopt_array($ch, $op);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $resp, true)];
}
$valor = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); };
$linhasDoProcesso = function (int $proc) use ($pdo): array {
    $s = $pdo->prepare("SELECT d.id, d.valor_fixo, d.vencimento, d.pago_em, d.cliente_id, d.processo_id, d.tipo, d.parcela_numero, d.ativo
                          FROM dre_accounts d JOIN fin_parcelamentos f ON f.id = d.parcelamento_id
                         WHERE f.origem = 'processo_resultado' AND f.origem_id = ? ORDER BY d.parcela_numero, d.id");
    $s->execute([$proc]);
    return $s->fetchAll(\PDO::FETCH_ASSOC);
};
$ativas = fn (array $l) => array_values(array_filter($l, fn ($x) => (int) $x['ativo'] === 1));

$tem = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'processos' AND COLUMN_NAME = 'resultado_tipo'")->fetchColumn();
if (!$tem) { echo "SKIP: falta a migration 180.\n"; exit(0); }

$HOJE = Parcelamento::hoje();
$proxMes = (new \DateTimeImmutable($HOJE))->modify('first day of next month')->format('Y-m-') . '10';
$esperadas = Parcelamento::vencimentos($proxMes, 3);

// Cliente de teste
$CLI = (int) $valor('SELECT id FROM clientes WHERE account_id = ? AND nome = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$CONTA, '[TESTE-AUTO] T3 Cliente']);
if (!$CLI) { [, $j] = http('POST', '/api/clientes.php', ['nome' => '[TESTE-AUTO] T3 Cliente', 'tipo_cliente' => 'PF']); $CLI = (int) ($j['id'] ?? 0); }

/* ===================================================================== */
secao('1. Campos e processo antigo (CA1)');
/* ===================================================================== */
$antigo = (int) $valor('SELECT id FROM processos WHERE account_id = ? AND deleted_at IS NULL AND valor_causa IS NULL AND resultado_tipo IS NULL AND numero = ?', [$CONTA, 'TESTE-AUTO-T5']);
if ($antigo) {
    [$code, $j] = http('GET', '/api/processes.php?id=' . $antigo);
    ok($code === 200 && (int) ($j['id'] ?? 0) === $antigo, "processo antigo sem valores abre (HTTP $code)");
    [$code, $j] = http('PUT', '/api/processes.php', ['id' => $antigo, 'numero' => 'TESTE-AUTO-T5', 'cliente_nome' => '[TESTE-AUTO] Cliente do T5', 'status' => 'ativo']);
    ok($code === 200 && !empty($j['success']), "processo antigo sem valores salva (HTTP $code)");
    [$code, $j] = http('GET', '/api/processo_valores.php?processo_id=' . $antigo);
    ok($code === 200 && array_key_exists('valor_causa', $j['data'] ?? []) && $j['data']['valor_causa'] === null && ($j['data']['financeiro']['parcelas'] ?? -1) === 0, 'valores do processo antigo vêm vazios, sem parcela');
    ok($valor('SELECT valor_causa FROM processos WHERE id = ?', [$antigo]) === null, 'SELECT: salvar o processo antigo não inventou valor');
} else {
    echo "  [SKIP] processo TESTE-AUTO-T5 não existe (rode publicacao_lida_test antes)\n";
}

secao('6. Fluxo completo e exemplo oficial (CA2, CA6)');
$num = 'TESTE-AUTO-T3-' . date('His') . random_int(10, 99);
[$code, $j] = http('POST', '/api/processes.php', ['numero' => $num, 'cliente_nome' => '[TESTE-AUTO] T3 Cliente', 'cliente_id' => $CLI, 'status' => 'ativo']);
$P = (int) ($j['id'] ?? 0);
ok($code === 200 && $P > 0, "processo criado pela API (id $P)");
[$code] = http('POST', '/api/processo_valores.php', ['processo_id' => $P, 'valor_causa' => '100.000,00', 'valor_estimado' => '40.000,00']);
ok($code === 200, 'valor da causa e projetado gravados');
eq(['100000.00', '40000.00', null], array_values($pdo->query("SELECT valor_causa, valor_estimado, resultado_tipo FROM processos WHERE id = $P")->fetch(\PDO::FETCH_NUM)), 'SELECT: causa R$ 100.000,00, projetado R$ 40.000,00, sem resultado');
eq([], $ativas($linhasDoProcesso($P)), 'sem resultado, nenhum lançamento no Financeiro');
[$code, $j] = http('POST', '/api/processo_valores.php', ['processo_id' => $P, 'valor_causa' => '100.000,00', 'valor_estimado' => '40.000,00',
    'resultado_tipo' => 'acordo', 'valor_resultado' => '30.000,00', 'resultado_forma' => 'parcelado', 'resultado_parcelas' => 3,
    'resultado_primeiro_vencimento' => $proxMes]);
ok($code === 200, 'acordo de R$ 30.000,00 em 3 gravado (HTTP ' . $code . ')');
eq('30000.00', $valor('SELECT valor_resultado FROM processos WHERE id = ?', [$P]), 'SELECT: resultado R$ 30.000,00');
$l = $ativas($linhasDoProcesso($P));
eq(3, count($l), 'SELECT: 3 lançamentos');
eq(['10000.00', '10000.00', '10000.00'], array_column($l, 'valor_fixo'), 'SELECT: R$ 10.000,00 cada');
eq($esperadas, array_column($l, 'vencimento'), 'SELECT: nos meses seguintes (' . implode(', ', $esperadas) . ')');
ok(array_column($l, 'pago_em') === [null, null, null], 'SELECT: todos pendentes');
ok(array_map('intval', array_column($l, 'cliente_id')) === [$CLI, $CLI, $CLI] && array_map('intval', array_column($l, 'processo_id')) === [$P, $P, $P],
   'SELECT: vinculados ao cliente e ao processo');
ok(array_unique(array_column($l, 'tipo')) === ['receita'], 'SELECT: são receitas');
$IDS = array_map('intval', array_column($l, 'id'));

[$code] = http('POST', '/api/financeiro_parcelas.php', ['acao' => 'baixar', 'id' => $IDS[0], 'data' => $HOJE]);
ok($code === 200, 'baixa da parcela 1 pelo Financeiro');
[$code, $j] = http('GET', '/api/processo_valores.php?processo_id=' . $P);
$fin = $j['data']['financeiro'] ?? [];
ok(($fin['recebido'] ?? null) === 10000 || ($fin['recebido'] ?? null) === 10000.0, 'total recebido do processo atualizado: R$ 10.000,00');
ok((float) ($fin['pendente'] ?? -1) === 20000.0 && (float) ($fin['total'] ?? -1) === 30000.0, 'pendente R$ 20.000,00 de R$ 30.000,00');
ok((int) $valor("SELECT COUNT(*) FROM processo_history WHERE processo_id = ? AND acao = 'Valores do processo atualizados'", [$P]) >= 2, 'o histórico do processo registra os valores');

$num2 = $num . 'V';
[$code, $j] = http('POST', '/api/processes.php', ['numero' => $num2, 'cliente_id' => $CLI, 'status' => 'ativo']);
$P2 = (int) ($j['id'] ?? 0);
[$code] = http('POST', '/api/processo_valores.php', ['processo_id' => $P2, 'resultado_tipo' => 'sentenca', 'valor_resultado' => '5.000,00',
                                                     'resultado_forma' => 'avista', 'resultado_primeiro_vencimento' => $proxMes]);
$l2 = $ativas($linhasDoProcesso($P2));
ok($code === 200 && count($l2) === 1 && $l2[0]['valor_fixo'] === '5000.00' && $l2[0]['vencimento'] === $proxMes, 'à vista gera 1 lançamento de R$ 5.000,00');

secao('3. Editar não duplica e baixada não muda (CA3)');
[$code] = http('POST', '/api/processo_valores.php', ['processo_id' => $P, 'valor_causa' => '100.000,00', 'valor_estimado' => '40.000,00',
    'resultado_tipo' => 'acordo', 'valor_resultado' => '30.000,00', 'resultado_forma' => 'parcelado', 'resultado_parcelas' => 3,
    'resultado_primeiro_vencimento' => $proxMes]);
$l = $ativas($linhasDoProcesso($P));
ok($code === 200 && array_map('intval', array_column($l, 'id')) === $IDS, 'salvar de novo igual: os mesmos 3 lançamentos, nenhum novo');
$pagaAntes = $pdo->query("SELECT valor_fixo, vencimento, pago_em, nome, parcela_numero, parcela_total, ativo FROM dre_accounts WHERE id = {$IDS[0]}")->fetch(\PDO::FETCH_ASSOC);
[$code] = http('POST', '/api/processo_valores.php', ['processo_id' => $P, 'valor_causa' => '100.000,00', 'valor_estimado' => '40.000,00',
    'resultado_tipo' => 'acordo', 'valor_resultado' => '36.000,00', 'resultado_forma' => 'parcelado', 'resultado_parcelas' => 3,
    'resultado_primeiro_vencimento' => $proxMes]);
$l = $ativas($linhasDoProcesso($P));
eq(3, count($l), 'resultado R$ 36.000,00: continuam 3 lançamentos');
eq(['10000.00', '13000.00', '13000.00'], array_column($l, 'valor_fixo'), 'a baixada fica com 10.000,00; o restante (26.000,00) nas pendentes');
eq($pagaAntes, $pdo->query("SELECT valor_fixo, vencimento, pago_em, nome, parcela_numero, parcela_total, ativo FROM dre_accounts WHERE id = {$IDS[0]}")->fetch(\PDO::FETCH_ASSOC),
   'SELECT: a parcela baixada não mudou em nada');
ok(array_map('intval', array_column($l, 'id')) === $IDS, 'as pendentes foram atualizadas no lugar (mesmos ids)');
[$code] = http('POST', '/api/processo_valores.php', ['processo_id' => $P, 'resultado_tipo' => 'acordo', 'valor_resultado' => '36.000,00',
    'resultado_forma' => 'parcelado', 'resultado_parcelas' => 2, 'resultado_primeiro_vencimento' => $proxMes]);
$l = $ativas($linhasDoProcesso($P));
eq(['10000.00', '26000.00'], array_column($l, 'valor_fixo'), 'em 2 parcelas: a baixada e uma pendente de 26.000,00');
eq(0, (int) $valor('SELECT ativo FROM dre_accounts WHERE id = ?', [$IDS[2]]), 'a pendente que sobrou foi desativada (não apagada)');
[$code] = http('POST', '/api/processo_valores.php', ['processo_id' => $P, 'resultado_tipo' => 'improcedente']);
$l = $ativas($linhasDoProcesso($P));
ok($code === 200 && count($l) === 1 && (int) $l[0]['id'] === $IDS[0] && $l[0]['pago_em'] !== null, 'improcedente: só a baixada fica; pendentes desativadas');
eq('0.00', $valor('SELECT valor_resultado FROM processos WHERE id = ?', [$P]), 'SELECT: improcedente grava resultado R$ 0,00');
[$code] = http('POST', '/api/processo_valores.php', ['processo_id' => $P, 'valor_causa' => '100.000,00', 'valor_estimado' => '40.000,00',
    'resultado_tipo' => 'acordo', 'valor_resultado' => '30.000,00', 'resultado_forma' => 'parcelado', 'resultado_parcelas' => 3,
    'resultado_primeiro_vencimento' => $proxMes]);
$l = $ativas($linhasDoProcesso($P));
eq(['10000.00', '10000.00', '10000.00'], array_column($l, 'valor_fixo'), 'de volta ao acordo de 30.000,00 em 3: a baixada e 2 pendentes de 10.000,00');
eq(1, (int) $valor("SELECT COUNT(*) FROM fin_parcelamentos WHERE origem = 'processo_resultado' AND origem_id = ?", [$P]), 'um só parcelamento para o processo');
[$code, $j] = http('POST', '/api/processo_valores.php', ['processo_id' => $P, 'resultado_tipo' => 'acordo', 'valor_resultado' => '0']);
ok($code === 400, "acordo sem valor é recusado (HTTP $code)");
[$code] = http('POST', '/api/processo_valores.php', ['processo_id' => $P, 'resultado_tipo' => 'acordo', 'valor_resultado' => '100', 'resultado_forma' => 'parcelado',
                                                     'resultado_parcelas' => 1, 'resultado_primeiro_vencimento' => $proxMes]);
ok($code === 400, "parcelado em 1 é recusado (HTTP $code)");
[$code] = http('POST', '/api/processo_valores.php', ['processo_id' => $P, 'resultado_tipo' => 'xyz']);
ok($code === 400, "tipo inválido é recusado (HTTP $code)");

secao('4. Carteira (CA4)');
$C = [];
for ($i = 1; $i <= 10; $i++) {
    $n = sprintf('TESTE-AUTO-T3-C%02d', $i);
    $id = (int) $valor('SELECT id FROM processos WHERE account_id = ? AND numero = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$CONTA, $n]);
    if (!$id) { [, $j] = http('POST', '/api/processes.php', ['numero' => $n, 'cliente_id' => $CLI, 'status' => 'ativo']); $id = (int) ($j['id'] ?? 0); }
    $C[] = $id;
    http('POST', '/api/processo_valores.php', ['processo_id' => $id, 'valor_estimado' => '100.000,00']);   // sem resultado
}
$cart = ValoresDoProcesso::carteira([$CONTA], ['processo_ids' => $C]);
eq(1000000.0, (float) $cart['potencial'], 'carteira potencial de 10 processos x R$ 100.000,00 = R$ 1.000.000,00');
eq([10, 0], [$cart['abertos'], $cart['encerrados']], '10 em aberto, nenhum encerrado');
$enc = [[$C[0], 'acordo', '20.000,00'], [$C[1], 'sentenca', '30.000,00'], [$C[2], 'execucao', '70.000,00'], [$C[3], 'improcedente', null]];
foreach ($enc as [$id, $tipo, $v]) {
    [$code] = http('POST', '/api/processo_valores.php', ['processo_id' => $id, 'valor_estimado' => '100.000,00', 'resultado_tipo' => $tipo,
        'valor_resultado' => $v, 'resultado_forma' => 'avista', 'resultado_primeiro_vencimento' => $proxMes]);
    ok($code === 200, "encerrado: processo $id como $tipo" . ($v ? " (R$ $v)" : ' (R$ 0,00)'));
}
$cart = ValoresDoProcesso::carteira([$CONTA], ['processo_ids' => $C]);
eq(1000000.0, (float) $cart['potencial'], 'potencial continua R$ 1.000.000,00');
eq([6, 600000.0], [$cart['abertos'], (float) $cart['em_aberto']], 'em aberto: 6 processos, R$ 600.000,00');
eq([4, 1], [$cart['encerrados'], $cart['improcedentes']], 'encerrados: 4, sendo 1 improcedente');
eq([400000.0, 120000.0, -280000.0, 30.0], [(float) $cart['projetado'], (float) $cart['obtido'], (float) $cart['diferenca'], (float) $cart['aproveitamento']],
   'projetado R$ 400.000,00 x obtido R$ 120.000,00 (diferença -R$ 280.000,00, 30%)');
ok(count($ativas($linhasDoProcesso($C[3]))) === 0, 'o improcedente não gera receita no Financeiro');
[$code, $j] = http('GET', '/api/processo_valores.php?carteira=1');
$sel = $pdo->query("SELECT COALESCE(SUM(valor_estimado),0), COALESCE(SUM(CASE WHEN resultado_tipo IS NOT NULL THEN COALESCE(valor_resultado,0) END),0)
                      FROM processos WHERE account_id = $CONTA AND deleted_at IS NULL")->fetch(\PDO::FETCH_NUM);
ok($code === 200 && abs((float) $j['data']['potencial'] - (float) $sel[0]) < 0.005 && abs((float) $j['data']['obtido'] - (float) $sel[1]) < 0.005,
   sprintf('API da carteira da conta bate com o SELECT (potencial %.2f, obtido %.2f)', $sel[0], $sel[1]));

secao('5. Projeção mensal (CA5)');
$proj = ValoresDoProcesso::projecaoMensal([$CONTA], 12, ['processo_ids' => [$P]]);
$mesesEsp = [substr($esperadas[0], 0, 7) => 10000.0, substr($esperadas[1], 0, 7) => 10000.0, substr($esperadas[2], 0, 7) => 10000.0];
$previsto = [];
foreach ($proj['meses'] as $m => $v) if ($v['previsto'] > 0) $previsto[$m] = (float) $v['previsto'];
// A parcela 1 foi baixada: o mês dela deixa de ser previsto.
unset($mesesEsp[substr($esperadas[0], 0, 7)]);
eq($mesesEsp, $previsto, 'previsto por mês: R$ 10.000,00 nos meses das parcelas pendentes');
eq(10000.0, (float) ($proj['meses'][substr($HOJE, 0, 7)]['recebido'] ?? 0), 'recebido no mês da baixa: R$ 10.000,00');
[$code, $j] = http('GET', '/api/processo_valores.php?projecao=1&meses=12');
$soma = array_sum(array_column($j['data']['meses'] ?? [], 'previsto'));
$fim = (new \DateTimeImmutable(substr($HOJE, 0, 7) . '-01'))->modify('+12 month')->format('Y-m-d');
$sel = (float) $valor("SELECT COALESCE(SUM(valor_fixo),0) FROM dre_accounts WHERE account_id = ? AND ativo = 1 AND tipo = 'receita' AND vencimento IS NOT NULL
                        AND pago_em IS NULL AND vencimento >= ? AND vencimento < ?", [$CONTA, $HOJE, $fim]);
ok($code === 200 && abs($soma - $sel) < 0.005, sprintf('API da projeção: previsto de 12 meses %.2f bate com o SELECT', $sel));

echo "\nDados de teste (não apagados): processos $P ($num) e $P2, carteira " . implode(',', $C) . ", cliente $CLI\n";
echo "\n=== $PASSES ok / $FAILS falha(s) ===\n";
exit($FAILS > 0 ? 1 : 0);
