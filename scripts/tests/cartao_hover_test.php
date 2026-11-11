<?php
/**
 * cartao_hover_test.php: a regra única do hover de cartão (interface-nova-hover).
 *
 * O PEDIDO (11/11/2026): "todo card do sistema tem que ter uma animação de
 * posição; se for card de texto, para o lado direito; se for outro tipo de
 * card, para cima. Deixa uma regra citada e segue ela para todos os cards."
 *
 * Antes, o cartão da agenda da home andava para a direita e a lista que rola
 * comia a borda dele. Cada bloco abaixo trava uma parte da regra:
 *
 *   1. A regra está escrita no topo do .js, e o .css só faz os dois movimentos.
 *   2. Carrega em toda tela da interface nova, home inclusive (fora do bloco
 *      que exclui a home), e depois da folha do menu.
 *   3. O movimento é `translate`, não `transform` (as entradas animadas usam
 *      transform), e só com mouse (no toque o hover gruda).
 *   4. Nenhum hover de cartão antigo continua mexendo sozinho (somaria dois
 *      movimentos).
 *   5. A folga: o cartão que encosta na borda de uma caixa que corta ganha espaço.
 *   6. Coluna, painel, grade e lista nunca são cartão, mesmo vazios.
 *
 * O teste que este arquivo não faz é o do navegador: passar o mouse de verdade.
 * Não escreve em banco nem em disco.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/cartao_hover_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$raiz = dirname(__DIR__, 2);
$ok = 0; $falhas = 0;
function ok(bool $cond, string $nome): void
{
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  [PASS] $nome\n"; } else { $falhas++; echo "  [FAIL] $nome\n"; }
}
$ler = fn(string $c) => str_replace("\r", '', (string) @file_get_contents($raiz . '/' . $c));

$js  = $ler('public/assets/interface-nova-hover.js');
$css = $ler('public/assets/interface-nova-hover.css');
$sb  = $ler('public/includes/sidebar.php');
$ini = $ler('public/assets/inicio.css');
$sis = $ler('public/assets/interface-nova-sistema.css');

echo "1. A regra escrita\n";
ok($js !== '' && $css !== '', 'os dois arquivos existem');
ok(str_contains($js, 'Cartão em LISTA, um embaixo do outro') && str_contains($js, 'desliza 2 px para a DIREITA'), 'o .js cita a regra do cartão de texto (direita)');
ok(str_contains($js, 'Cartão em FILEIRA ou GRADE') && str_contains($js, 'SOBE 2 px'), 'o .js cita a regra do outro cartão (para cima)');
ok(str_contains($js, "if (numeroGrande(el)) return CIMA;") && str_contains($js, "if (lado) return CIMA;") && str_contains($js, "if (empilhado) return LADO;"), 'a direção segue a regra: número grande e fileira sobem, lista vai para o lado');
ok(str_contains($js, 'return el.offsetWidth > el.offsetHeight * 2.6 ? LADO : CIMA;'), 'cartão sozinho: largo e baixo vai para o lado, o resto sobe');

echo "2. Onde carrega\n";
$pHover = strpos($sb, '/assets/interface-nova-hover.js');
$pMenu  = strpos($sb, "<!-- Interface nova (prévia): veste o menu.");
$pSoTelas = strpos($sb, "<?php if (\$_ap !== 'dashboard' || !empty(\$_GET['gestao'])): ?>", (int) $pMenu);
ok($pHover !== false && str_contains($sb, '/assets/interface-nova-hover.css'), 'o menu carrega o .js e o .css');
ok($pHover !== false && $pMenu !== false && $pHover > $pMenu, 'depois da folha do menu da interface nova (só na prévia)');
ok($pHover !== false && $pSoTelas !== false && $pHover < $pSoTelas, 'antes do bloco só das telas de dentro: vale na home também');

echo "3. O movimento\n";
ok(str_contains($css, 'html.inn-hv .inn-hv-lado:hover{ translate:2px 0; }'), 'texto: 2 px para a direita');
ok(str_contains($css, 'html.inn-hv .inn-hv-cima:hover{ translate:0 -2px; }'), 'outro cartão: 2 px para cima');
ok(preg_match('/@media \(hover: hover\)\{[^}]*inn-hv-lado:hover/', $css) === 1, 'só com mouse (no toque o hover gruda)');
ok(!preg_match('/inn-hv-(lado|cima):hover\{[^}]*transform/', $css), 'usa translate, nunca transform');
ok(str_contains($css, 'prefers-reduced-motion'), 'quem pede menos movimento não vê');
ok(str_contains($js, "if (/(^|,\\s*)(all|translate)(\\s*,|$)/.test(props)) return;") && str_contains($js, "partes.push(TRANSICAO);"), 'a transição do translate é somada à do cartão, sem apagar a dele');

echo "4. Sem movimento em dobro\n";
ok(!preg_match('/\.ini-cartao:hover\{[^}]*transform/', $ini), 'o cartão da home não anda sozinho');
ok(!preg_match('/\.ini-kpi:hover\{[^}]*transform/', $ini), 'o indicador da home não sobe sozinho');
ok(!preg_match('/\.ini-ctr:hover\{[^}]*transform/', $ini), 'o contador de prazos da home não sobe sozinho');
ok(!preg_match('/\.inn-cartao-acao:hover\{[^}]*translate/', $sis), 'o cartão de indicador das telas não sobe sozinho');

echo "5. A folga\n";
ok(str_contains($js, 'function darFolga(el, dir)') && str_contains($js, "a.style.setProperty('padding-right'") && str_contains($js, "a.style.setProperty('padding-top'"), 'a caixa que corta ganha padding do lado para onde o cartão vai');
ok(str_contains($js, 'var FOLGA = PASSO + 1;'), 'a folga é maior que o passo');

echo "6. O que nunca é cartão\n";
ok(str_contains($js, 'var RE_RECIPIENTE') && preg_match('/RE_RECIPIENTE = \/[^\n]*\(col\|cols\|column[^\n]*panel\|painel\|grid[^\n]*list\|lista/', $js) === 1, 'coluna, painel, grade e lista, pelo nome');
ok(str_contains($js, 'function irmaoEhPainel(el)'), 'a coluna vazia, irmã de coluna cheia, também não');
ok(str_contains($js, "if (h < 44 && el.children.length <= 1) return false;"), 'uma linha com um filho é botão ou etiqueta');
ok(str_contains($js, "{ childList: true, subtree: true }") && !str_contains($js, 'attributes: true'), 'só observa filhos novos, nunca atributo (a camada não acorda a si mesma)');
ok(str_contains($js, 'function agendar(ms) { if (timer) return;'), 'a varredura marcada não é adiada para sempre por tela que muda o tempo todo');

echo "\n$ok ok, $falhas falhas\n";
exit($falhas ? 1 : 0);
