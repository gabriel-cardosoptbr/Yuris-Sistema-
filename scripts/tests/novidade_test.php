<?php
/**
 * novidade_test.php — o aviso de "atualização nova" (10/11/2026, App\Inicio\Novidade).
 *
 * Trava:
 *   1. A regra de quem vê o quê: cada item só para quem tem a permissão da tela;
 *      a edição CRM não vê aviso nenhum ("tire isso do crm", 11/11/2026); sem
 *      nenhum item, sem aviso.
 *   2. O texto: sem travessão, em português, sem item sem título nem texto, e todo
 *      ícone pedido existe em assets/novidade.js.
 *   3. O menu compartilhado entrega o aviso: CSS e JS ligados fora do ?embed=1,
 *      configuração com id, usuário e o ?novidade=1 de prévia.
 *   4. O comportamento: lembra que viu por usuário e por id, fecha com Esc, volta o
 *      foco, espera a interface nova se vestir, e o CSS respeita "reduzir movimento"
 *      e o tema claro.
 *   5. Sem diálogo nativo (alert, confirm, prompt).
 *
 * Não escreve em banco nem precisa de servidor.
 *
 * Uso: C:\xampp\php\php.exe scripts/tests/novidade_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Inicio\Novidade;

$FAILS = 0; $PASSES = 0;
function ok(bool $c, string $m): void { global $PASSES, $FAILS; if ($c) { $PASSES++; echo "  [PASS] $m\n"; } else { $FAILS++; echo "  [FAIL] $m\n"; } }
function secao(string $t): void { echo "\n== $t ==\n"; }
function eq($esp, $obt, string $m): void { ok($esp === $obt, $m . ($esp === $obt ? '' : ' (esperado ' . json_encode($esp, JSON_UNESCAPED_UNICODE) . ', obtido ' . json_encode($obt, JSON_UNESCAPED_UNICODE) . ')')); }

$raiz = dirname(__DIR__, 2);
$titulos = fn (?array $n) => $n ? array_column($n['itens'], 'titulo') : [];
$todas = fn (string $p) => true;
$so = fn (array $chaves) => fn (string $p) => in_array($p, $chaves, true);

secao('1. Quem vê o quê');
$admin = Novidade::para($todas);
eq(5, count($admin['itens'] ?? []), 'admin do Yuris vê os 5 itens');
eq(Novidade::ID, $admin['id'] ?? null, 'o aviso leva o id da atualização');

eq(['Parcelas e baixa no Financeiro', 'Projeção de recebimentos'], $titulos(Novidade::para($so(['financas']))), 'só com Finanças: os dois itens do Financeiro');
eq(['Valores do processo'], $titulos(Novidade::para($so(['processos']))), 'só com Processos: valores do processo');
eq(['Responsável no cliente PJ'], $titulos(Novidade::para($so(['clientes']))), 'só com Clientes: o responsável PF');
eq(['Publicações mais claras'], $titulos(Novidade::para($so(['intimacoes']))), 'só com Publicações: o item de publicações');
eq(null, Novidade::para($so(['tarefas', 'chat'])), 'sem nenhuma das telas anunciadas: sem aviso (null)');
eq(null, Novidade::para(fn ($p) => false), 'sem nenhuma permissão: sem aviso');

eq(null, Novidade::para($todas, true), 'edição CRM: sem aviso, mesmo com todas as permissões ("tire isso do crm", 11/11/2026)');
eq(null, Novidade::para($so(['financas', 'clientes']), true), 'edição CRM com Finanças e Clientes: sem aviso');
ok(str_contains(json_encode($admin['itens'], JSON_UNESCAPED_UNICODE), 'cliente e processo'), 'no jurídico o texto do Financeiro fala em cliente e processo');

secao('2. O texto');
$js = (string) file_get_contents($raiz . '/public/assets/novidade.js');
$css = (string) file_get_contents($raiz . '/public/assets/novidade.css');
$fonte = (string) file_get_contents($raiz . '/app/Inicio/Novidade.php');
$sidebar = (string) file_get_contents($raiz . '/public/includes/sidebar.php');
foreach (Novidade::ITENS as $it) {
    ok(trim($it['titulo']) !== '' && trim($it['texto']) !== '', 'item "' . $it['titulo'] . '" tem título e texto');
    ok(str_contains($js, $it['icone'] . ': \'<svg'), 'o ícone "' . $it['icone'] . '" existe no novidade.js');
}
ok(!str_contains(Novidade::TITULO . Novidade::SUBTITULO . json_encode(Novidade::ITENS, JSON_UNESCAPED_UNICODE), '—'), 'os textos não usam travessão');
ok(!str_contains($js . $css, '—'), 'o JS e o CSS do aviso não usam travessão');
ok(preg_match('/^\d{4}-\d{2}-\d{2}-[a-z0-9-]+$/', Novidade::ID) === 1, 'o id tem o formato data-assunto');

secao('3. O menu compartilhado entrega o aviso');
ok(str_contains($sidebar, 'Novidade::para(\'_sidebarCan\', (bool) $_isFleetiflow)'), 'o menu pede o aviso com a regra de permissão do próprio menu e diz se é a edição CRM');
ok(preg_match('/embed.{0,40}!==.{0,6}\'1\'.{0,400}Novidade::para/s', $sidebar) === 1, 'não monta o aviso dentro do ?embed=1');
ok(str_contains($sidebar, '/assets/novidade.css') && str_contains($sidebar, '/assets/novidade.js'), 'liga o CSS e o JS do aviso');
ok(str_contains($sidebar, "'forcar'  => (\$_GET['novidade'] ?? '') === '1'"), '?novidade=1 abre de novo (prévia)');
ok(str_contains($sidebar, "'usuario' => (int) (\$_SESSION['user_id'] ?? 0)"), 'a configuração leva o usuário');
ok(str_contains($sidebar, 'JSON_HEX_TAG'), 'o JSON vai com JSON_HEX_TAG (sem fechar o script por dentro)');

secao('4. O comportamento');
ok(str_contains($js, "'yuris_novidade_' + (CFG.usuario || 0)"), 'lembra por usuário: a chave leva o id do usuário');
ok(str_contains($js, 'visto === CFG.id'), 'o que vale é o id: trocar o id anuncia de novo');
ok(str_contains($js, 'localStorage') && str_contains($js, 'sessionStorage') && str_contains($js, 'catch (_)'), 'localStorage com queda para sessionStorage, tudo em try/catch');
ok(str_contains($js, 'if (!armazem) return;'), 'sem como lembrar: não repete em toda tela');
ok(str_contains($js, "e.key === 'Escape'"), 'fecha com Esc');
ok(str_contains($js, "'mousedown'") && str_contains($js, 'e.target === p.fundo'), 'fecha com o clique fora');
ok(str_contains($js, "role', 'dialog'") && str_contains($js, "aria-modal', 'true'") && str_contains($js, 'aria-labelledby'), 'é um diálogo acessível (role, aria-modal, aria-labelledby)');
ok(str_contains($js, "e.key !== 'Tab'"), 'o foco fica preso no aviso (Tab)');
ok(str_contains($js, 'antes.focus'), 'o foco volta para onde estava');
ok(str_contains($js, 'inn-vestindo') && str_contains($js, 'inn:revelada'), 'espera a interface nova terminar de se vestir');
ok(str_contains($js, "classList.contains('yuris-embed')"), 'não abre dentro de pop-up embutido');
ok(str_contains($js, 'setTimeout(remover, 400)'), 'sai mesmo sem animationend (reduzir movimento)');
preg_match_all('/innerHTML\s*=\s*([^;]+);/', $js, $inner);
$soDesenhos = true; foreach ($inner[1] as $v) if (!preg_match('/^\s*SVG[.\[]/', $v)) $soDesenhos = false;
ok(str_contains($js, 'textContent') && count($inner[1]) >= 3 && $soDesenhos, 'o texto do servidor entra por textContent; innerHTML só recebe desenho da tabela SVG do próprio arquivo');

ok(str_contains($css, '@media (prefers-reduced-motion: reduce)'), 'o CSS respeita "reduzir movimento"');
ok(str_contains($css, 'html[data-theme="light"] .nov-card'), 'o CSS tem o tema claro');
ok(str_contains($css, '@media (max-width: 480px)'), 'o CSS tem o ajuste de celular');
ok(str_contains($css, 'html .nov-card svg { color: inherit !important; }'), 'o aviso reafirma a cor dos ícones contra o tema do sistema');
ok(!preg_match('/border-left\s*:\s*[2-9]px\s+solid/i', $css), 'sem faixa colorida na lateral');
ok(!preg_match('/text-overflow\s*:\s*ellipsis/i', $css), 'nenhum texto cortado com reticências');

secao('5. Sem diálogo nativo');
ok(!preg_match('/\b(alert|confirm|prompt)\s*\(/', $js), 'o JS do aviso não usa alert, confirm nem prompt');

echo "\n=== $PASSES ok / $FAILS falha(s) ===\n";
exit($FAILS > 0 ? 1 : 0);
