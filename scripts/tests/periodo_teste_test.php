<?php
/**
 * periodo_teste_test.php — o teste grátis com tag no topo (12/11/2026, App\Billing\PeriodoTeste).
 *
 * Trava:
 *   1. A regra pura: dias contados por data de São Paulo, banco em UTC, "falta 1 dia",
 *      "termina hoje", encerrado, aguardando o primeiro login. Sem travessão.
 *   2. Quem está em teste: só conta 'trial' + plano teste_gratis + assinatura trialing.
 *      Conta pagante com assinatura esquecida em 'trialing' não ganha tag.
 *   3. O primeiro login começa o prazo uma vez só e preserva o resto de configuracoes.
 *   4. O menu entrega a tag (CSS, fora do ?embed=1, fora da edição CRM) e o login chama
 *      o início do prazo sem poder derrubar o login.
 *
 * O bloco 2 e 3 cria uma conta [TESTE-AUTO] no banco local e a apaga no fim.
 *
 * Uso: C:\xampp\php\php.exe scripts/tests/periodo_teste_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Billing\PeriodoTeste;
use App\Core\Database;

$FAILS = 0; $PASSES = 0;
function ok(bool $c, string $m): void { global $PASSES, $FAILS; if ($c) { $PASSES++; echo "  [PASS] $m\n"; } else { $FAILS++; echo "  [FAIL] $m\n"; } }
function secao(string $t): void { echo "\n== $t ==\n"; }
function eq($esp, $obt, string $m): void { ok($esp === $obt, $m . ($esp === $obt ? '' : ' (esperado ' . json_encode($esp, JSON_UNESCAPED_UNICODE) . ', obtido ' . json_encode($obt, JSON_UNESCAPED_UNICODE) . ')')); }

$raiz = dirname(__DIR__, 2);
$sp = new DateTimeZone('America/Sao_Paulo');

secao('1. Regra pura');
$agora = new DateTimeImmutable('2026-11-12 10:00:00', $sp); // 13:00 UTC
$e = PeriodoTeste::estado('2026-11-15 13:00:00', false, $agora);
eq(3, $e['dias'], 'três dias de calendário');
eq('Teste grátis · faltam 3 dias', $e['rotulo'], 'rótulo com três dias');
ok(str_contains($e['detalhe'], '15/11 às 10:00'), 'detalhe na hora de São Paulo (UTC 13:00 vira 10:00)');
$e = PeriodoTeste::estado('2026-11-13 13:00:00', false, $agora);
eq('Teste grátis · falta 1 dia', $e['rotulo'], 'singular em 1 dia');
$e = PeriodoTeste::estado('2026-11-12 20:00:00', false, $agora);
eq(0, $e['dias'], 'termina hoje: zero dias');
eq('Teste grátis · termina hoje às 17:00', $e['rotulo'], 'rótulo de hoje com a hora local');
$e = PeriodoTeste::estado('2026-11-13 02:30:00', false, $agora); // 23:30 de hoje em SP
eq(0, $e['dias'], 'UTC já no dia seguinte, mas em SP ainda é hoje');
$e = PeriodoTeste::estado('2026-11-12 12:59:59', false, $agora);
ok($e['encerrado'] && $e['dias'] === 0, 'passou do prazo: encerrado');
eq('Teste grátis encerrado', $e['rotulo'], 'rótulo de encerrado');
$e = PeriodoTeste::estado(null, true, $agora, 3);
ok($e['aguardando_login'] && $e['dias'] === 3 && $e['rotulo'] === 'Teste grátis · faltam 3 dias', 'antes do primeiro login: os dias contratados');
$e = PeriodoTeste::estado(null, false, $agora);
eq('Teste grátis', $e['rotulo'], 'sem data: só o rótulo');
foreach ([PeriodoTeste::estado('2026-11-15 13:00:00', false, $agora), PeriodoTeste::estado('2026-11-01', false, $agora)] as $x) {
    ok(!str_contains($x['rotulo'] . $x['detalhe'], '—'), 'sem travessão: ' . $x['rotulo']);
}

secao('2 e 3. Banco: quem está em teste e o primeiro login');
$pdo = null;
try { $pdo = Database::getConnection(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch (Throwable $t) { echo "  (sem banco: bloco pulado)\n"; }
if ($pdo) {
    $planoTeste = (int) $pdo->query("SELECT id FROM plans WHERE slug = 'teste_gratis'")->fetchColumn();
    $planoPago  = (int) $pdo->query("SELECT id FROM plans WHERE slug <> 'teste_gratis' AND ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $criadas = [];
    $nova = function (string $status, int $plano, ?array $cfg) use ($pdo, &$criadas): int {
        $pdo->prepare("INSERT INTO accounts (nome, tipo, codigo_vinculo, plano, status, configuracoes) VALUES ('[TESTE-AUTO] periodo teste', 'advogado', :c, 'teste_gratis', :s, :cfg)")
            ->execute(['c' => 'teste-' . bin2hex(random_bytes(8)), 's' => $status, 'cfg' => $cfg === null ? null : json_encode($cfg)]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO subscriptions (account_id, plan_id, status, trial_ends_at) VALUES (?, ?, 'trialing', DATE_ADD(NOW(), INTERVAL 14 DAY))")->execute([$id, $plano]);
        $criadas[] = $id;
        return $id;
    };
    try {
        $teste = $nova('trial', $planoTeste, ['periodo_teste' => ['dias' => 3, 'inicio' => null], 'outra' => 'fica']);
        $pago  = $nova('active', $planoPago, null);
        $trialOutroPlano = $nova('trial', $planoPago, null);

        $e = PeriodoTeste::daConta($pdo, $teste);
        ok($e !== null && $e['aguardando_login'] && $e['rotulo'] === 'Teste grátis · faltam 3 dias', 'conta em teste antes do login: faltam 3 dias');
        eq(null, PeriodoTeste::daConta($pdo, $pago), 'conta ativa com assinatura em trialing: sem tag');
        eq(null, PeriodoTeste::daConta($pdo, $trialOutroPlano), 'conta em trial noutro plano: sem tag');
        eq(null, PeriodoTeste::daConta($pdo, 0), 'conta 0: sem tag');

        ok(PeriodoTeste::iniciarNoPrimeiroLogin($pdo, $teste) === true, 'primeiro login começa o prazo');
        ok(PeriodoTeste::iniciarNoPrimeiroLogin($pdo, $teste) === false, 'segundo login não mexe de novo');
        ok(PeriodoTeste::iniciarNoPrimeiroLogin($pdo, $pago) === false, 'conta pagante: login não mexe');
        $fim = $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, NOW(), trial_ends_at) FROM subscriptions WHERE account_id = $teste")->fetchColumn();
        ok(abs((int) $fim - 3 * 1440) <= 2, 'trial_ends_at = agora + 3 dias (era 14)');
        $cfg = json_decode((string) $pdo->query("SELECT configuracoes FROM accounts WHERE id = $teste")->fetchColumn(), true);
        ok(!empty($cfg['periodo_teste']['inicio']) && ($cfg['outra'] ?? null) === 'fica' && $cfg['periodo_teste']['dias'] === 3, 'início gravado e o resto de configuracoes preservado');
        $e = PeriodoTeste::daConta($pdo, $teste);
        ok($e !== null && !$e['aguardando_login'] && in_array($e['dias'], [2, 3], true), 'depois do login: conta pelo prazo (' . ($e['rotulo'] ?? '?') . ')');
        $semMarca = $nova('trial', $planoTeste, null);
        ok(PeriodoTeste::iniciarNoPrimeiroLogin($pdo, $semMarca) === false, 'conta em teste sem a marca: login não mexe');
        ok(PeriodoTeste::daConta($pdo, $semMarca) !== null, 'conta em teste sem a marca: tag com o prazo da assinatura');
    } finally {
        foreach ($criadas as $id) {
            $pdo->prepare('DELETE FROM subscriptions WHERE account_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        }
    }
}

secao('4. O menu e o login');
$sb = (string) file_get_contents("$raiz/public/includes/sidebar.php");
ok(str_contains($sb, 'PeriodoTeste::daConta('), 'o menu pergunta a PeriodoTeste');
ok((bool) preg_match('/\$_periodoTeste = null;\s*if \(!\$_isFleetiflow && \(\$_GET\[\'embed\'\] \?\? \'\'\) !== \'1\'/', $sb), 'fora da edição CRM e do ?embed=1');
ok(str_contains($sb, '/assets/periodo-teste.css') && is_file("$raiz/public/assets/periodo-teste.css"), 'CSS ligado e presente');
ok(str_contains($sb, "htmlspecialchars(\$_periodoTeste['rotulo'])") && str_contains($sb, "htmlspecialchars(\$_periodoTeste['detalhe'])"), 'texto escapado');
$css = (string) file_get_contents("$raiz/public/assets/periodo-teste.css");
ok(str_contains($css, 'html[data-theme="light"]') && str_contains($css, 'prefers-reduced-motion'), 'tema claro e reduzir movimento');
ok(!preg_match('/border-left\s*:/', $css), 'sem faixa lateral colorida');
$auth = (string) file_get_contents("$raiz/app/Usuarios/AuthController.php");
ok((bool) preg_match('/try \{ \\\\App\\\\Billing\\\\PeriodoTeste::iniciarNoPrimeiroLogin\(.*\}\s*catch \(\\\\Throwable/s', $auth), 'login chama o início do prazo dentro de try/catch');
foreach (['alert(', 'confirm(', 'prompt('] as $d) ok(!str_contains($sb . $css, "window.$d"), "sem $d nativo");

echo "\n$PASSES ok · $FAILS falha\n";
exit($FAILS ? 1 : 0);
