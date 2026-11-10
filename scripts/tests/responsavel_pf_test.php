<?php
/**
 * responsavel_pf_test.php — Pessoa Física responsável pelo cliente PJ (T1, migration 178).
 *
 * Pelo caminho real (HTTP em public/api/clientes.php, servidor local), trava:
 *   1. Vincular uma PF existente à PJ grava a referência e a ficha da PJ mostra
 *      nome e CPF da PF. A busca acha por nome e por CPF, só PF, nunca a própria.
 *   2. Cadastrar PF nova pela PJ cria exatamente 1 cliente PF, que aparece na
 *      lista de clientes por conta própria; o mesmo CPF de novo não duplica (409
 *      com o cadastro existente para vincular).
 *   3. Só PF pode ser vinculada: PJ, o próprio registro e PF de outra conta são
 *      recusados (400). Cliente PF não guarda responsável.
 *   4. Editar a PF muda o que a PJ mostra (registro único). Tirar o vínculo não
 *      apaga a PF.
 *   5. Salvar PF e PJ sem responsável continua funcionando.
 *   6. A tela tem o bloco, e ele só aparece para PJ.
 *
 * ESCREVE NO BANCO LOCAL e NÃO APAGA. A PJ "[TESTE-AUTO] T1 Empresa" e a PF
 * "[TESTE-AUTO] T1 Pessoa existente" são reaproveitadas; cada rodada cria 1 PF
 * "[TESTE-AUTO] T1 PF nova ..." com CPF de teste (começa por 999). Não rode em produção.
 *
 * Precisa do servidor local em http://localhost:8090 (ou YURIS_TESTE_URL) e da migration 178.
 * Uso: C:\xampp\php\php.exe scripts/tests/responsavel_pf_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }

$BASE = rtrim(getenv('YURIS_TESTE_URL') ?: 'http://localhost:8090', '/');
$CONTA = 1;

$tem = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'pf_responsavel_id'")->fetchColumn();
if (!$tem) { echo "SKIP: falta a migration 178.\n"; exit(0); }

/* ── sessão de teste (como a varredura_urls.php: só SELECT) ─────────────── */
$st = $pdo->prepare('SELECT u.*, a.tipo acc_tipo, a.nome acc_nome, a.plano acc_plano
                       FROM users u JOIN accounts a ON a.id = u.account_id
                      WHERE u.account_id = ? AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1');
$st->execute([$CONTA]);
$u = $st->fetch(\PDO::FETCH_ASSOC);
$CSRF = str_repeat('d', 32);
$sid = 't1pf' . bin2hex(random_bytes(8));
session_id($sid);
@session_start();
$_SESSION = [
    'user_id' => (int) $u['id'], 'user_nome' => $u['nome'], 'user_perfil' => $u['perfil'],
    'user_role' => $u['role'] ?? 'owner', 'account_id' => $CONTA,
    'account_tipo' => $u['acc_tipo'] ?? 'matriz', 'account_nome' => $u['acc_nome'] ?? '',
    'account_plano' => $u['acc_plano'] ?? 'basico', 'user_permissions' => ['*'],
    'csrf_token' => $CSRF,
];
session_write_close();

function api(string $metodo, string $qs = '', ?array $corpo = null): array
{
    global $BASE, $sid, $CSRF;
    $ch = curl_init($BASE . '/api/clientes.php' . ($qs !== '' ? '?' . $qs : ''));
    $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $metodo,
           CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
           CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-TOKEN: ' . $CSRF]];
    if ($corpo !== null) $op[CURLOPT_POSTFIELDS] = json_encode($corpo);
    curl_setopt_array($ch, $op);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $resp, true)];
}
$valor = function (string $sql, array $p) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); };

/* ── PJ e PF de teste (reaproveitadas) ─────────────────────────────────── */
$achaOuCria = function (string $nome, string $tipo, string $doc) use ($valor, $CONTA): int {
    $id = (int) $valor('SELECT id FROM clientes WHERE account_id = ? AND nome = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$CONTA, $nome]);
    if ($id) return $id;
    [$code, $j] = api('POST', '', ['nome' => $nome, 'tipo_cliente' => $tipo, 'cpf_cnpj' => $doc]);
    ok($code === 200 && !empty($j['id']), "cadastro de $tipo sem responsável funciona: $nome (HTTP $code)");
    return (int) ($j['id'] ?? 0);
};
$PJ  = $achaOuCria('[TESTE-AUTO] T1 Empresa', 'PJ', '99111222000199');
$PJ2 = $achaOuCria('[TESTE-AUTO] T1 Outra empresa', 'PJ', '99111222000288');
$PF  = $achaOuCria('[TESTE-AUTO] T1 Pessoa existente', 'PF', '99900000111');
ok($PJ > 0 && $PJ2 > 0 && $PF > 0, "dados de teste: PJ $PJ, PJ $PJ2, PF $PF");
// Nome da PF de volta ao original (a rodada anterior pode ter editado).
api('PUT', '', ['id' => $PF, 'nome' => '[TESTE-AUTO] T1 Pessoa existente']);
api('PUT', '', ['id' => $PJ, 'pf_responsavel_id' => '']);

secao('1. Vincular PF existente (CA2)');
[$code, $j] = api('GET', 'pf_busca=' . rawurlencode('T1 Pessoa exist') . '&cliente_id=' . $PJ);
$ids = array_column($j['data'] ?? [], 'id');
ok($code === 200 && in_array($PF, $ids, true), 'busca por nome acha a PF');
[$code, $j] = api('GET', 'pf_busca=99900000111&cliente_id=' . $PJ);
ok(in_array($PF, array_column($j['data'] ?? [], 'id'), true), 'busca por CPF (só dígitos) acha a PF');
[$code, $j] = api('GET', 'pf_busca=' . rawurlencode('999.000.001-11') . '&cliente_id=' . $PJ);
ok(in_array($PF, array_column($j['data'] ?? [], 'id'), true), 'busca por CPF com pontuação acha a PF');
[$code, $j] = api('GET', 'pf_busca=' . rawurlencode('[TESTE-AUTO] T1') . '&cliente_id=' . $PJ);
$tipos = array_unique(array_map(fn ($id) => $valor('SELECT tipo_cliente FROM clientes WHERE id = ?', [$id]), array_column($j['data'] ?? [], 'id')));
ok($tipos === ['PF'], 'a busca só devolve Pessoa Física (' . json_encode(array_values($tipos)) . ')');
ok(!in_array($PJ, array_column($j['data'] ?? [], 'id'), true), 'a busca nunca devolve o próprio cliente');

[$code, $j] = api('PUT', '', ['id' => $PJ, 'pf_responsavel_id' => $PF]);
ok($code === 200 && !empty($j['success']), "PUT vincula a PF $PF à PJ $PJ (HTTP $code)");
ok((int) $valor('SELECT pf_responsavel_id FROM clientes WHERE id = ?', [$PJ]) === $PF, 'SELECT: clientes.pf_responsavel_id gravado');
[$code, $j] = api('GET', 'id=' . $PJ);
ok(($j['data']['pf_responsavel_nome'] ?? '') === '[TESTE-AUTO] T1 Pessoa existente'
   && ($j['data']['pf_responsavel_cpf'] ?? '') === '99900000111', 'a ficha da PJ mostra nome e CPF da PF');

secao('2. Cadastrar PF nova a partir da PJ (CA3)');
$cpfNovo = '999' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$nomeNovo = '[TESTE-AUTO] T1 PF nova ' . date('His');
$antes = (int) $valor("SELECT COUNT(*) FROM clientes WHERE account_id = ? AND cpf_cnpj = ?", [$CONTA, $cpfNovo]);
[$code, $j] = api('POST', '', ['nome' => $nomeNovo, 'tipo_cliente' => 'PF', 'cpf_cnpj' => $cpfNovo, 'recusar_cpf_duplicado' => 1]);
$NOVA = (int) ($j['id'] ?? 0);
ok($code === 200 && $NOVA > 0, "POST cria a PF nova $NOVA (HTTP $code)");
ok($antes === 0 && (int) $valor("SELECT COUNT(*) FROM clientes WHERE account_id = ? AND cpf_cnpj = ?", [$CONTA, $cpfNovo]) === 1,
   'SELECT: exatamente 1 cliente com esse CPF');
[$code, $j] = api('PUT', '', ['id' => $PJ, 'pf_responsavel_id' => $NOVA]);
ok($code === 200 && (int) $valor('SELECT pf_responsavel_id FROM clientes WHERE id = ?', [$PJ]) === $NOVA, 'a PF nova fica vinculada à PJ');
[$code, $j] = api('GET', 'search=' . rawurlencode($nomeNovo));
ok(in_array($NOVA, array_map('intval', array_column($j['data'] ?? [], 'id')), true), 'a PF nova aparece na lista de clientes, independente');
[$code, $j] = api('POST', '', ['nome' => $nomeNovo . ' de novo', 'tipo_cliente' => 'PF', 'cpf_cnpj' => $cpfNovo, 'recusar_cpf_duplicado' => 1]);
ok($code === 409 && ($j['code'] ?? '') === 'cpf_existente' && (int) ($j['existente']['id'] ?? 0) === $NOVA,
   "o mesmo CPF não duplica: 409 com o cadastro existente (HTTP $code)");
ok((int) $valor("SELECT COUNT(*) FROM clientes WHERE account_id = ? AND cpf_cnpj = ?", [$CONTA, $cpfNovo]) === 1, 'continua 1 cliente com esse CPF');
[$code, $j] = api('GET', 'pf_por_cpf=' . $cpfNovo);
ok((int) ($j['data']['id'] ?? 0) === $NOVA, 'pf_por_cpf devolve o cadastro existente');

secao('3. Só Pessoa Física (CA4)');
$gravado = fn () => (int) $valor('SELECT pf_responsavel_id FROM clientes WHERE id = ?', [$PJ]);
[$code] = api('PUT', '', ['id' => $PJ, 'pf_responsavel_id' => $PJ2]);
ok($code === 400 && $gravado() === $NOVA, "vincular uma PJ é recusado (HTTP $code) e nada muda");
[$code] = api('PUT', '', ['id' => $PJ, 'pf_responsavel_id' => $PJ]);
ok($code === 400 && $gravado() === $NOVA, "vincular o próprio registro é recusado (HTTP $code)");
$outra = (int) $valor("SELECT id FROM clientes WHERE account_id <> ? AND tipo_cliente = 'PF' AND deleted_at IS NULL ORDER BY id LIMIT 1", [$CONTA]);
if ($outra) {
    [$code] = api('PUT', '', ['id' => $PJ, 'pf_responsavel_id' => $outra]);
    ok($code === 400 && $gravado() === $NOVA, "PF de outra conta é recusada (HTTP $code)");
} else {
    echo "  [SKIP] nenhuma PF em outra conta no banco local (a regra é conferida abaixo, direto na classe)\n";
}
$contaInexistente = (int) $valor('SELECT COALESCE(MAX(id), 0) + 1000 FROM accounts', []);
ok(\App\Clientes\ResponsavelPF::erro(null, $PF, $contaInexistente) !== null, 'ResponsavelPF::erro recusa PF quando a conta da PJ é outra');
ok(\App\Clientes\ResponsavelPF::buscar($contaInexistente, '[TESTE-AUTO] T1') === [], 'a busca de outra conta não enxerga as PF desta');
ok(\App\Clientes\ResponsavelPF::porCpf($contaInexistente, '99900000111') === null, 'pf_por_cpf de outra conta não enxerga o CPF desta');
[$code] = api('POST', '', ['nome' => '[TESTE-AUTO] T1 nao deve nascer', 'tipo_cliente' => 'PJ', 'cpf_cnpj' => '', 'pf_responsavel_id' => $PJ2]);
ok($code === 400 && !(int) $valor("SELECT COUNT(*) FROM clientes WHERE nome = '[TESTE-AUTO] T1 nao deve nascer'", []),
   "criar PJ com uma PJ de responsável é recusado e nada é gravado (HTTP $code)");
[$code] = api('PUT', '', ['id' => $PF, 'pf_responsavel_id' => $NOVA]);
ok($code === 200 && $valor('SELECT pf_responsavel_id FROM clientes WHERE id = ?', [$PF]) === null, 'cliente PF não guarda responsável (fica NULL)');
$r = \App\Clientes\ResponsavelPF::buscar($CONTA, '[TESTE-AUTO] T1', $PJ);
ok(!array_filter($r, fn ($x) => $x['id'] === $PJ || $x['id'] === $PJ2), 'ResponsavelPF::buscar não traz PJ');

secao('4. Registro único e remover vínculo (CA5)');
[$code] = api('PUT', '', ['id' => $NOVA, 'nome' => $nomeNovo . ' (editada)']);
[$code, $j] = api('GET', 'id=' . $PJ);
ok(($j['data']['pf_responsavel_nome'] ?? '') === $nomeNovo . ' (editada)', 'editar a PF muda o nome mostrado na PJ');
[$code] = api('PUT', '', ['id' => $PJ, 'pf_responsavel_id' => '']);
ok($code === 200 && $gravado() === 0, 'remover o vínculo grava NULL');
ok($valor('SELECT deleted_at FROM clientes WHERE id = ?', [$NOVA]) === null
   && (int) $valor('SELECT COUNT(*) FROM clientes WHERE id = ?', [$NOVA]) === 1, 'a PF continua cadastrada e ativa');
[$code] = api('PUT', '', ['id' => $PJ, 'pf_responsavel_id' => $PF]);
ok($code === 200 && $gravado() === $PF, 'vínculo de volta à PF existente (fica para a tela)');
[$code] = api('PUT', '', ['id' => $PJ2, 'tipo_cliente' => 'PJ', 'pf_responsavel_id' => $PF]);
[$code] = api('PUT', '', ['id' => $PJ2, 'tipo_cliente' => 'PF']);
ok($code === 200 && $valor('SELECT pf_responsavel_id FROM clientes WHERE id = ?', [$PJ2]) === null, 'virar PF solta o responsável');
api('PUT', '', ['id' => $PJ2, 'tipo_cliente' => 'PJ']);

secao('5. Salvar sem responsável continua funcionando (CA6)');
[$code, $j] = api('PUT', '', ['id' => $PJ2, 'observacoes' => '[TESTE-AUTO] salvo em ' . date('Y-m-d H:i:s')]);
ok($code === 200 && !empty($j['success']), "PUT de PJ sem responsável (HTTP $code)");
[$code, $j] = api('PUT', '', ['id' => $PF, 'observacoes' => '[TESTE-AUTO] salvo em ' . date('Y-m-d H:i:s')]);
ok($code === 200 && !empty($j['success']), "PUT de PF (HTTP $code)");
ok($valor('SELECT pf_responsavel_id FROM clientes WHERE id = ?', [$PJ2]) === null, 'a PJ sem responsável segue com NULL');

secao('6. A tela');
$php = file_get_contents(__DIR__ . '/../../public/clientes.php');
ok(str_contains($php, 'id="cliPfBloco" hidden'), 'o bloco nasce escondido');
ok(str_contains($php, "\$('#cliPfBloco').hidden = !ehPj;"), 'o bloco só aparece com o tipo PJ');
ok(str_contains($php, 'name="pf_responsavel_id" id="cliPfResponsavelId"'), 'só o id da PF vai no salvar');
ok(preg_match('/id="cliPfBusca"[^>]*name=/', $php) === 0 && preg_match('/id="cliPfNovaNome"[^>]*name=/', $php) === 0,
   'busca e cadastro rápido não vão no salvar (sem name)');
ok(!preg_match('/\b(alert|confirm|prompt)\(/', substr($php, strpos($php, 'function pfMostrar'), 6000)), 'sem diálogo nativo no bloco');

echo "\nDados de teste (não apagados): PJ $PJ e $PJ2, PF $PF, PF nova $NOVA (CPF $cpfNovo)\n";
echo "\n=== $PASSES ok / $FAILS falha(s) ===\n";
exit($FAILS > 0 ? 1 : 0);
