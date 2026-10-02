<?php
/**
 * scripts/tests/tema_escuro_test.php — App\Master\TemaEscuroCrm, o conversor que
 * gera o tema escuro da edição CRM a partir do desenho claro.
 *
 * Não usa banco. Confere: só regras do tema claro entram (e com o prefixo
 * trocado), as cores claras viram as escuras, a cor da marca como texto vira o
 * tom claro dela mas continua igual como fundo, regras sem prefixo só entram
 * quando pedido (e nunca :root), @media é respeitado, e as folhas reais
 * (fichas, abas do chat) convertem sem sobrar seletor de tema claro.
 *
 * Uso: php scripts/tests/tema_escuro_test.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Master\TemaEscuroCrm as T;

$ok = 0; $falhas = 0;
function ok(string $nome, bool $cond): void {
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  [ok]   $nome\n"; } else { $falhas++; echo "  [FALHA] $nome\n"; }
}

echo "== 1. Seleção e prefixo ==\n";
$css = 'html[data-theme="light"] .a{ color:#3D3D3D; } .b{ color:#3D3D3D; } :root{ --x:#FFFFFF; } html[data-theme="light"] .c, .d{ background:#FFFFFF; }';
$e = T::converter($css);
ok('regra do tema claro entra com o prefixo escuro', str_contains($e, 'html:not([data-theme="light"]) .a{'));
ok('regra sem prefixo fica de fora por padrão', !str_contains($e, '.b{'));
ok(':root fica de fora', !str_contains($e, ':root'));
ok('em lista de seletores, só o do tema claro entra', str_contains($e, 'html:not([data-theme="light"]) .c{') && !str_contains($e, ' .d'));
ok('nenhum seletor de tema claro sobra', !preg_match('/(?<!:not\()html\[data-theme="light"\]/', $e));

echo "\n== 2. Cores ==\n";
ok('branco vira superfície escura', T::cores('background:#FFFFFF') === 'background:#121821');
ok('#fff curto também', T::cores('color:#fff') === 'color:#121821');
ok('texto escuro vira texto claro', T::cores('color:#3D3D3D') === 'color:#e7ebf1');
ok('borda ardósia da lista', T::cores('border-color:rgba(17,29,45,0.08)') === 'border-color:rgba(255,255,255,0.08)');
ok('borda ardósia fora da lista (,.14) vira branca translúcida', T::cores('border:1px solid rgba(17,29,45,.14)') === 'border:1px solid rgba(255,255,255,0.14)');
ok('cor desconhecida fica como está', T::cores('color:#123456') === 'color:#123456');

echo "\n== 3. Cor da marca ==\n";
ok('marca como texto vira o tom claro', T::cores('color:var(--ff-marca) !important') === 'color:var(--ff-marca-clara) !important');
ok('marca como fundo continua a marca', T::cores('background-color:var(--ff-marca)') === 'background-color:var(--ff-marca)');
ok('marca forte (com cor reserva) vira o tom claro', T::cores('color:var(--ff-marca-forte, #013DF2)') === 'color:var(--ff-marca-clara)');
ok('marca suave vira fundo translúcido', T::cores('background:var(--ff-marca-suave, #D6E4FF)') === 'background:rgba(var(--ff-marca-rgb),0.20)');
ok('fundo do item ativo fica mais denso', T::cores('background:rgba(var(--ff-marca-rgb),0.07)') === 'background:rgba(var(--ff-marca-rgb),0.22)');

echo "\n== 4. Sem prefixo (folhas de dois temas), @media e estilo() ==\n";
$e2 = T::converter('.ff-cel{ background:#FFFFFF; padding:4px } .so-layout{ padding:4px } :root{ --y:#FFFFFF }', true);
ok('regra sem prefixo com cor clara entra quando pedido', str_contains($e2, 'html:not([data-theme="light"]) .ff-cel{'));
ok('regra sem cor nenhuma não entra', !str_contains($e2, 'so-layout'));
ok(':root nunca entra', !str_contains($e2, ':root'));
$e3 = T::converter('@media (max-width:900px){ html[data-theme="light"] .s{ display:none } .t{ color:#fff } }');
ok('@media mantém só as regras do tema claro', str_contains($e3, '@media (max-width:900px){') && str_contains($e3, 'html:not([data-theme="light"]) .s{') && !str_contains($e3, '.t{'));
ok('sem regra clara, estilo() devolve vazio', T::estilo('.x{ color:#fff }') === '');
ok('estilo() embrulha numa tag style', str_starts_with(T::estilo('html[data-theme="light"] .x{ color:#fff }'), '<style>'));

echo "\n== 5. Folhas reais ==\n";
$ficha = T::converter((string) file_get_contents(__DIR__ . '/../../public/assets/ff-ficha.css'), true);
ok('fichas: gera dezenas de regras escuras', substr_count($ficha, 'html:not([data-theme="light"])') > 40);
ok('fichas: a faixa de resumo ganha fundo escuro', (bool) preg_match('/html:not\(\[data-theme="light"\]\) \.ff-cel\{[^}]*#121821/', $ficha));
ok('fichas: nenhum seletor de tema claro sobra', !preg_match('/(?<!:not\()html\[data-theme="light"\]/', $ficha));
$chat = T::converter((string) file_get_contents(__DIR__ . '/../../public/assets/chat-numeros.css'), true);
ok('abas do chat: geram regras escuras', substr_count($chat, 'html:not([data-theme="light"])') > 10);

echo "\n----\nResultado: $ok ok · $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
