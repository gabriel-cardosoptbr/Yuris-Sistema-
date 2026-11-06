<?php
/**
 * paleta_tema_test.php — a paleta do Yuris (App\Core\PaletaTema), em prévia.
 *
 * O PEDIDO (06/11/2026): o tema escuro no tom de um leão azul-preto ("não é um
 * azul, é um azul com preto"), o claro no prata das penas de uma águia ("não tem
 * que ser branco"), e os botões no azul dos olhos.
 *
 * A PRIMEIRA VERSÃO FOI AO AR E FOI REPROVADA: "tá meio roxo", "o menu tá com um
 * retângulo", "as peças tão meio transparentes". Cada bloco abaixo trava um dos
 * erros que causaram isso:
 *
 *   1. O escuro não gira para o anil: continua azul.
 *   2. O azul dos olhos nos botões, sem perder a leitura do texto branco.
 *   3. O prata do claro, com contraste de leitura.
 *   4. Cada tela tem a SUA folha: regra de outra tela não entra.
 *   5. Desenho de conta de marca não entra, nem o que está num `if` no meio do CSS.
 *   6. A folha copia TODA declaração de cor, não só a que muda.
 *   7. É prévia: só carrega em quem ligou, e nunca em conta de marca.
 *
 * A conferência que este teste não faz é a visual, elemento por elemento:
 * public/assets/_conferir_paleta.js, rodado no navegador.
 *
 * Não escreve em banco nem em disco.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/paleta_tema_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\PaletaTema;

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function eq($esp, $obt, string $m): void {
    if ($esp === $obt) { pass($m); return; }
    fail($m . ' (esperado ' . var_export($esp, true) . ', obtido ' . var_export($obt, true) . ')');
}

$hex = fn (string $x): array => [hexdec(substr($x, 1, 2)), hexdec(substr($x, 3, 2)), hexdec(substr($x, 5, 2))];
$contraste = function (array $a, array $b): float {
    $la = PaletaTema::luminancia($a); $lb = PaletaTema::luminancia($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
};
$BRANCO = [255, 255, 255];
$publico = dirname(__DIR__, 2) . '/public';

/* ===================================================================== */
secao('1. Escuro: o mesmo azul, mais perto do preto (e não roxo)');
/* ===================================================================== */

foreach (['#070F1C' => 'fundo da página', '#0D1C30' => 'cartão', '#081220' => 'campo', '#0F172A' => 'painel'] as $cor => $nome) {
    $n = PaletaTema::mapear($hex($cor), 'escuro', 'fundo');
    [$h, , $l] = PaletaTema::hsl($n);
    [, , $lAntes] = PaletaTema::hsl($hex($cor));
    ok($n !== null && $l < $lAntes, "$nome ($cor) fica mais escuro");
    ok($h >= 210 && $h <= 221, "$nome continua azul, sem ir para o anil (matiz " . round($h) . ')');
}
$fundo  = PaletaTema::hsl(PaletaTema::mapear($hex('#070F1C'), 'escuro', 'fundo'))[2];
$cartao = PaletaTema::hsl(PaletaTema::mapear($hex('#0D1C30'), 'escuro', 'fundo'))[2];
ok($cartao - $fundo >= 3.5, 'o cartão continua destacado do fundo');
eq(null, PaletaTema::mapear($hex('#D8E4F0'), 'escuro', 'texto'), 'texto claro não muda');
eq(null, PaletaTema::mapear($hex('#EF4444'), 'escuro', 'fundo'), 'vermelho de alerta não muda');
eq(null, PaletaTema::mapear($hex('#10B981'), 'escuro', 'fundo'), 'verde de sucesso não muda');

/* ===================================================================== */
secao('2. O azul dos olhos nos botões');
/* ===================================================================== */

foreach (['#1E5299', '#2D6FAD', '#244E7A', '#2563EB', '#1D4ED8'] as $cor) {
    $n = PaletaTema::mapear($hex($cor), 'escuro', 'fundo');
    [$h, $s] = PaletaTema::hsl($n);
    ok($n !== null && $h >= 198 && $h <= 211 && $s >= 78, "$cor vira azul do olho (matiz " . round($h) . ', saturação ' . round($s) . '%)');
    ok($contraste($n, $BRANCO) >= 4.5, "texto branco continua legível sobre ele (" . round($contraste($n, $BRANCO), 1) . ')');
    ok(PaletaTema::luminancia($n) >= PaletaTema::luminancia($hex($cor)) - 0.002, 'e ele não fica mais apagado que antes');
}
$antes = $hex('#60A5FA'); $depois = PaletaTema::mapear($antes, 'escuro', 'texto');
ok(abs(PaletaTema::luminancia($antes) - PaletaTema::luminancia($depois)) < 0.01, 'azul usado como texto mantém a luminância: o contraste não muda');
eq(null, PaletaTema::mapear($hex('#6366F1'), 'escuro', 'fundo'), 'roxo não é azul: fica como está');
eq(null, PaletaTema::mapear($hex('#0891B2'), 'escuro', 'fundo'), 'ciano também');

/* ===================================================================== */
secao('3. Claro: o prata da águia');
/* ===================================================================== */

$prata = PaletaTema::mapear($BRANCO, 'claro', 'fundo');
[$h, $s, $l] = PaletaTema::hsl($prata);
ok($l >= 92 && $l <= 96 && $s >= 20, 'branco vira prata, não cinza nem branco (luz ' . round($l, 1) . '%)');
$ordem = array_map(fn ($c) => PaletaTema::hsl(PaletaTema::mapear($hex($c), 'claro', 'fundo'))[2], ['#FFFFFF', '#F8FAFC', '#F1F5F9', '#E2E8F0']);
$ordenada = $ordem; rsort($ordenada);
eq($ordenada, $ordem, 'a ordem dos tons continua: o que era mais claro segue mais claro');
$pagina = PaletaTema::hsl(PaletaTema::mapear($hex('#DDE3EC'), 'claro', 'fundo'))[2];
ok($l - $pagina >= 9, 'o cartão se destaca do fundo da página (' . round($l - $pagina, 1) . ' pontos de luz): peça não parece transparente');
eq(null, PaletaTema::mapear($BRANCO, 'claro', 'texto'), 'texto branco de botão não vira prata');
eq(null, PaletaTema::mapear($hex('#FFF6E6'), 'claro', 'fundo'), 'fundo amarelo de aviso não muda');
eq(null, PaletaTema::mapear($hex('#DCFCE7'), 'claro', 'fundo'), 'fundo verde de sucesso não muda');
eq(null, PaletaTema::mapear($BRANCO, 'claro-base', 'fundo'), 'regra sem tema não ganha prata: ela vale no escuro também');
foreach (['#0F172A', '#475569', '#64748B'] as $cor) {
    $t = PaletaTema::mapear($hex($cor), 'claro', 'texto') ?? $hex($cor);
    ok($contraste($t, $prata) >= 4.5, "texto $cor lê bem no prata (" . round($contraste($t, $prata), 1) . ')');
}
$link = PaletaTema::mapear($hex('#2563EB'), 'claro', 'texto');
ok($contraste($link, $prata) >= 4.5, 'link azul lê bem no prata (' . round($contraste($link, $prata), 1) . ')');

/* ===================================================================== */
secao('4. Cada tela tem a sua folha');
/* ===================================================================== */

$folhas = [];
foreach (PaletaTema::PAGINAS as $p) {
    foreach (['escuro', 'claro'] as $t) { $folhas[$p][$t] = PaletaTema::gerar($publico, $p, $t); }
}
$ruins = [];
foreach ($folhas as $p => $porTema) {
    foreach ($porTema as $t => $css) {
        if (strlen($css) < 20000 || substr_count($css, '{') !== substr_count($css, '}') || str_contains($css, '<?')) $ruins[] = "$p/$t";
    }
}
eq([], $ruins, 'as ' . count(PaletaTema::PAGINAS) . ' telas geram as duas folhas, inteiras e sem resto de PHP');

// O caso que reprovou: a borda verde do .kpi-card de outra tela caía na Visão Geral.
ok(str_contains($folhas['financas']['claro'], '.reemb-'), 'a folha de Finanças leva as regras de Finanças (.reemb-...)');
ok(!str_contains($folhas['dashboard']['claro'], '.reemb-'), 'a folha da Visão Geral não leva regra da tela Finanças');
ok(!str_contains($folhas['dashboard']['claro'], '.tkc-tipo'), 'nem da tela Tarefas');
ok(str_contains($folhas['tarefas']['claro'], '.tkc-tipo'), 'a folha de Tarefas leva as regras de Tarefas');
ok(!str_contains($folhas['tarefas']['claro'], '.int-pub-card'), 'e não as de Publicações');
eq('', PaletaTema::gerar($publico, 'login', 'claro'), 'tela fora da lista não tem folha');
eq('', PaletaTema::gerar($publico, '../app/bootstrap', 'claro'), 'nem caminho montado na mão');

$semTema = 0; $subiu = 0;
foreach ($folhas['dashboard'] as $css) {
    foreach (explode("\n", $css) as $linha) {
        if ($linha === '' || $linha[0] === '@' || $linha[0] === '}') continue;
        $seletor = substr($linha, 0, (int) strpos($linha, '{'));
        if (!str_contains($seletor, 'data-theme')) $semTema++;
        if (preg_match('/(^|,)\s*html(:not\(\[data-theme="light"\]\)|\[data-theme="light"\])\s+:where/', $seletor)) $subiu++;
    }
}
eq(0, $semTema, 'toda regra é presa ao tema, nenhuma vaza para o outro');
eq(0, $subiu, 'o prefixo de tema fica dentro de :where(): a especificidade não sobe');
ok(!preg_match('/@media[^{]*print/', implode('', $folhas['relatorios'])), 'impressão fica de fora');

/* ===================================================================== */
secao('5. Desenho de conta de marca não entra');
/* ===================================================================== */

$menu = (string) file_get_contents($publico . '/includes/sidebar.php');
ok(str_contains($menu, '.sb-titulo'), 'o arquivo do menu tem o desenho da edição CRM (.sb-titulo)...');
$vazou = [];
foreach ($folhas as $p => $porTema) {
    if (preg_match('/\.sb-titulo|Manrope|--ff-marca\s*:|data-ambiente/', implode('', $porTema))) $vazou[] = $p;
}
eq([], $vazou, '...e ele não aparece na folha de nenhuma tela');

// Na tela do Chat há um `if ($isFleetiflow)` no meio do próprio <style>.
$chat = (string) file_get_contents($publico . '/chat.php');
ok((bool) preg_match('/if \(\$isFleetiflow\): \?>.*?#chatToast \{\s*background: #FFFFFF/s', $chat), 'o Chat tem regra de marca dentro do <style>...');
ok(str_contains($folhas['chat']['escuro'], '#chatToast{'), '...a regra comum do aviso entra...');
ok(!preg_match('/#chatToast\{background:#(FFFFFF|EEF2F7)/i', implode('', $folhas['chat'])), '...e a de marca, não');

/* ===================================================================== */
secao('6. A folha copia toda declaração de cor');
/* ===================================================================== */

// Foi a causa do "retângulo": só ia o que mudava, e a cópia de uma regra que
// perdia passou a ganhar de quem a vencia e não tinha sido copiado.
ok((bool) preg_match('/\{[^}]*background:\s*(transparent|none)/', $folhas['dashboard']['claro']), 'declaração que não muda ("transparent") também vai');
ok((bool) preg_match('/box-shadow:none/', $folhas['dashboard']['escuro']), 'e "box-shadow:none" também');
ok(str_contains($folhas['dashboard']['escuro'], 'var(--ff-marca, '), 'regra comum que usa a cor de reserva da marca é copiada');

/* ===================================================================== */
secao('7. É prévia, e só das contas Yuris');
/* ===================================================================== */

ok((bool) preg_match('/if \(!\$_isFleetiflow && \\\\App\\\\Core\\\\PaletaTema::conhece\(\$__temaPag\)\):/', $menu), 'o menu só entra no bloco se não é conta de marca e a tela tem folha');
ok((bool) preg_match("/localStorage\.getItem\('yuris_paleta'\) !== '1'\) return;.*?api\/tema\.php/s", $menu), 'quem não ligou a prévia sai antes de pedir a folha');
ok(!preg_match('/<link[^>]*api\/tema\.php/', (string) preg_replace("/document\.write\('.*?'\);/s", '', $menu)), 'nenhum <link> fixo da paleta no menu');
ok(PaletaTema::versao($publico, 'dashboard') >= filemtime($publico . '/assets/yuris-theme.css'), 'a versão acompanha as folhas de origem: mexeu no CSS, o cache renova');
ok(PaletaTema::conhece('tarefas') && !PaletaTema::conhece('login') && !PaletaTema::conhece(''), 'só as telas da lista têm paleta');

echo "\n{$PASSES} ok, {$FAILS} falha(s)\n";
exit($FAILS ? 1 : 0);
