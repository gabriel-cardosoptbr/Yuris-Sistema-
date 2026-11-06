<?php
/**
 * paleta_tema_test.php — a paleta do Yuris (App\Core\PaletaTema).
 *
 * O PEDIDO (06/11/2026): o tema escuro no tom de um leão azul-preto ("não é um
 * azul, é um azul com preto"), o claro no prata das penas de uma águia ("não tem
 * que ser branco"), e os botões no azul dos olhos.
 *
 * O que este arquivo trava:
 *   1. As cores: o escuro escurece e vai para o anil; o branco vira prata; o
 *      azul de botão acende sem deixar o texto branco ilegível.
 *   2. O que NÃO pode mudar: texto branco, avisos coloridos, contraste de leitura.
 *   3. A folha gerada é CSS inteiro, sem resto de PHP e sem regra de marca.
 *   4. A cascata: toda regra gerada tem a especificidade da original.
 *   5. Conta de marca (Fleetiflow, Inovaize, Via Autodoc) não carrega a paleta.
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

/* ===================================================================== */
secao('1. Escuro: o azul com preto do leão');
/* ===================================================================== */

foreach (['#070F1C' => 'fundo da página', '#0D1C30' => 'cartão', '#081220' => 'campo', '#0F172A' => 'painel'] as $cor => $nome) {
    $n = PaletaTema::mapear($hex($cor), 'escuro', 'fundo');
    [$h, , $l] = PaletaTema::hsl($n);
    [, , $lAntes] = PaletaTema::hsl($hex($cor));
    ok($n !== null && $l < $lAntes, "$nome ($cor) fica mais escuro");
    ok($h >= 227 && $h <= 234, "$nome vai para o anil do pelo (matiz " . round($h) . ')');
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
ok($l >= 91 && $l <= 95 && $s >= 20, 'branco vira prata, não cinza nem branco (luz ' . round($l, 1) . '%)');
$ordem = array_map(fn ($c) => PaletaTema::hsl(PaletaTema::mapear($hex($c), 'claro', 'fundo'))[2], ['#FFFFFF', '#F8FAFC', '#F1F5F9', '#E2E8F0']);
$ordenada = $ordem; rsort($ordenada);
eq($ordenada, $ordem, 'a ordem dos tons continua: o que era mais claro segue mais claro');
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
secao('4. A folha gerada');
/* ===================================================================== */

$publico = dirname(__DIR__, 2) . '/public';
foreach (['escuro', 'claro'] as $tema) {
    $css = PaletaTema::gerar($publico, $tema);
    ok(strlen($css) > 20000, "$tema: folha gerada (" . round(strlen($css) / 1024) . ' KB)');
    eq(substr_count($css, '{'), substr_count($css, '}'), "$tema: chaves fecham");
    ok(!str_contains($css, '__PHP__') && !str_contains($css, '<?'), "$tema: sem resto de PHP");
    ok(!preg_match('/--ff-|data-ambiente|\.ffc\b/', $css), "$tema: nenhuma regra de marca ou de ambiente");
    ok(!preg_match('/@media[^{]*print/', $css), "$tema: impressão fica de fora");

    $semTema = 0; $subiu = 0;
    foreach (explode("\n", $css) as $linha) {
        if ($linha === '' || $linha[0] === '@' || $linha[0] === '}') continue;
        $seletor = substr($linha, 0, (int) strpos($linha, '{'));
        if (!str_contains($seletor, 'data-theme')) $semTema++;
        // O prefixo posto pelo conversor tem de estar dentro de :where().
        if (preg_match('/(^|,)\s*html(:not\(\[data-theme="light"\]\)|\[data-theme="light"\])\s+:where/', $seletor)) $subiu++;
    }
    eq(0, $semTema, "$tema: toda regra é presa ao tema, nenhuma vaza para o outro");
    eq(0, $subiu, "$tema: nenhum prefixo fora de :where()");
}
$escuro = PaletaTema::gerar($publico, 'escuro');
ok(str_contains($escuro, ':where(html:not([data-theme="light"])) '), 'escuro: prefixo com especificidade zero');
ok((bool) preg_match('/(^|\n)(html|:root):where\(:not\(\[data-theme="light"\]\)\)/', $escuro), 'escuro: regra de html e :root recebe a condição no próprio elemento');
$claro = PaletaTema::gerar($publico, 'claro');
ok(str_contains($claro, 'background-image:linear-gradient(160deg,#F4F6F9'), 'claro: painel ganha o brilho da pena');

/* ===================================================================== */
secao('5. Só as contas Yuris');
/* ===================================================================== */

$menu = (string) file_get_contents($publico . '/includes/sidebar.php');
ok((bool) preg_match('/if \(!\$_isFleetiflow\):[^\n]*\n[^\n]*PaletaTema::versao.*?api\/tema\.php\?t=escuro.*?api\/tema\.php\?t=claro.*?endif/s', $menu),
   'o menu carrega as duas folhas dentro de "se não é conta de marca"');
eq(1, substr_count($menu, 'api/tema.php?t=escuro'), 'e em nenhum outro lugar');
// A primeira versão foi ao ar para todos e foi reprovada. Enquanto for prévia,
// a folha só entra no navegador de quem ligou, e antes de qualquer <link>.
ok((bool) preg_match("/localStorage\.getItem\('yuris_paleta'\) !== '1'\) return;.*?api\/tema\.php/s", $menu),
   'prévia: quem não ligou sai antes de carregar a folha');
ok(!preg_match('/<link[^>]*api\/tema\.php/', (string) preg_replace("/document\.write\('.*?'\);/s", '', $menu)),
   'prévia: nenhum <link> fixo da paleta no menu');
ok(PaletaTema::versao($publico) >= filemtime($publico . '/assets/yuris-theme.css'), 'a versão acompanha as folhas de origem: mexeu no CSS, o cache renova');

echo "\n{$PASSES} ok, {$FAILS} falha(s)\n";
exit($FAILS ? 1 : 0);
