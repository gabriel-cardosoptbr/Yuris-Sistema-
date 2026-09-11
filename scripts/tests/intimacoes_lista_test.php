<?php
/**
 * intimacoes_lista_test.php — as linhas "Parte(s):" e "Adv:" da tela de
 * Intimações.
 *
 * O BUG RELATADO, com print: duas publicações do mesmo tribunal, uma formatada
 * com "Parte(s):" e "Adv:" e a outra não. E na que não tinha, os advogados
 * apareciam repetidos no texto corrido.
 *
 * A CAUSA, que é o contrário do que parece: a consulta dos eventos PERSISTIDOS
 * não trazia `payload_original`, e é dele que as duas linhas são extraídas.
 * Então a publicação aparecia formatada enquanto era cache do dia, e PERDIA a
 * formatação ao virar permanente. O card bonito era o temporário.
 *
 * O que está sob teste:
 *
 *  - a consulta SELECIONA payload_original, senão as duas linhas somem
 *  - e o payload é APAGADO antes de sair na resposta (LGPD: é o dado cru do
 *    tribunal, com id interno de advogado e outras coisas que não devem sair)
 *  - a extração dedupe o advogado que o tribunal manda duas vezes
 *
 * NÃO escreve no banco: só lê o código e exercita as duas funções puras.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/intimacoes_lista_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function eq($esp, $obt, string $m): void {
    if ($esp === $obt) { pass($m); return; }
    fail($m . ' (esperado ' . var_export($esp, true) . ', obtido ' . var_export($obt, true) . ')');
}

$arquivo = __DIR__ . '/../../public/api/push/list.php';
$fonte   = file_get_contents($arquivo);
if ($fonte === false) { echo "SKIP: não achei public/api/push/list.php\n"; exit(0); }

/*
 * O endpoint roda ao ser incluído (abre sessão, consulta o banco, imprime JSON).
 * Então em vez de incluir, recorta as duas funções puras e as define aqui. Assim
 * o teste exercita o CÓDIGO DE VERDADE, e não uma cópia que envelhece sozinha.
 */
function recortarFuncao(string $fonte, string $nome): string
{
    $ini = strpos($fonte, 'function ' . $nome . '(');
    if ($ini === false) { throw new RuntimeException("não achei a função $nome"); }
    $abre = strpos($fonte, '{', $ini);
    $nivel = 0;
    for ($i = $abre; $i < strlen($fonte); $i++) {
        if ($fonte[$i] === '{') { $nivel++; }
        if ($fonte[$i] === '}') {
            $nivel--;
            if ($nivel === 0) { return substr($fonte, $ini, $i - $ini + 1); }
        }
    }
    throw new RuntimeException("não achei o fim de $nome");
}

eval(recortarFuncao($fonte, 'listPhpExtractAdvogados'));
eval(recortarFuncao($fonte, 'listPhpExtractPartes'));

/* ===================================================================== */
secao('A consulta traz o payload, e ele não sai na resposta');

// Sem isto, as duas linhas somem de todo evento persistido. Foi exatamente o bug.
ok(str_contains($fonte, 'e.payload_original'),
   'o SELECT dos eventos inclui e.payload_original');

ok(str_contains($fonte, "unset(\$it['payload_original'])"),
   'e o payload é apagado antes de montar a resposta');

// A ordem importa: apagar antes de extrair devolveria lista vazia de novo.
$posExtrai = strpos($fonte, 'listPhpExtractAdvogados($it)');
$posUnset  = strpos($fonte, "unset(\$it['payload_original'])");
ok($posExtrai !== false && $posUnset !== false && $posExtrai < $posUnset,
   'a extração acontece ANTES do unset (invertido, as linhas voltariam a sumir)');

// O json_encode da resposta vem depois do unset: o cru nunca chega ao navegador.
$posEcho = strpos($fonte, "'items'     => \$combined");
ok($posEcho !== false && $posUnset < $posEcho,
   'e o unset acontece antes de a resposta ser montada');

/* ===================================================================== */
secao('DJEN: extrai advogados e partes do payload real');

// Payload com a forma REAL do TJSP, copiada de uma publicação de produção.
$djen = ['payload_original' => json_encode([
    'destinatarios' => [
        ['nome' => 'RENATO COSSA', 'polo' => 'A'],
        ['nome' => 'TRIADE SPE 001 NOVA UTINGA LTDA', 'polo' => 'P'],
        ['nome' => 'ÉRICA FABIANA NASCIMENTO SILVA COSSA', 'polo' => 'A'],
    ],
    'destinatarioadvogados' => [
        ['advogado' => ['nome' => 'BRUNO CARREIRA FERREIRA',     'numero_oab' => '357838', 'uf_oab' => 'SP']],
        ['advogado' => ['nome' => 'VICTOR ZOCARATO',             'numero_oab' => '399918', 'uf_oab' => 'SP']],
        ['advogado' => ['nome' => 'LAURA LOVATO PIRES DE LEMOS', 'numero_oab' => '405130', 'uf_oab' => 'SP']],
        ['advogado' => ['nome' => 'WILLIAM GRESPAN GARCIA',      'numero_oab' => '346592', 'uf_oab' => 'SP']],
    ],
], JSON_UNESCAPED_UNICODE)];

$advs = listPhpExtractAdvogados($djen);
eq(4, count($advs), 'os quatro advogados são extraídos');
eq('BRUNO CARREIRA FERREIRA', $advs[0]['nome'], '  com o nome');
eq('357838', $advs[0]['oab'], '  a OAB');
eq('SP', $advs[0]['uf'], '  e a UF');

$partes = listPhpExtractPartes($djen);
ok(count($partes) >= 3, 'as partes também: ' . count($partes));
ok(in_array('RENATO COSSA', $partes, true), '  incluindo o autor');

/* ===================================================================== */
secao('O advogado que o tribunal manda duas vezes aparece UMA');

/*
 * Acontece de verdade: quando o mesmo advogado representa duas partes do mesmo
 * processo, o tribunal repete a entrada. O ramo da AASP já deduplicava e o do
 * DJEN não, então a linha "Adv:" saía com o nome repetido lado a lado.
 */
$comRepetido = ['payload_original' => json_encode([
    'destinatarioadvogados' => [
        ['advogado' => ['nome' => 'BRUNO CARREIRA FERREIRA', 'numero_oab' => '357838', 'uf_oab' => 'SP']],
        ['advogado' => ['nome' => 'BRUNO CARREIRA FERREIRA', 'numero_oab' => '357838', 'uf_oab' => 'SP']],
        ['advogado' => ['nome' => 'WILLIAM GRESPAN GARCIA',  'numero_oab' => '346592', 'uf_oab' => 'SP']],
        ['advogado' => ['nome' => 'WILLIAM GRESPAN GARCIA',  'numero_oab' => '346592', 'uf_oab' => 'SP']],
    ],
])];
$advs = listPhpExtractAdvogados($comRepetido);
eq(2, count($advs), 'quatro entradas com dois advogados devolvem dois');

// Mesma OAB em UF diferente é OUTRA inscrição, e tem de continuar aparecendo.
$duasUfs = ['payload_original' => json_encode([
    'destinatarioadvogados' => [
        ['advogado' => ['nome' => 'FULANO', 'numero_oab' => '100000', 'uf_oab' => 'SP']],
        ['advogado' => ['nome' => 'FULANO', 'numero_oab' => '100000', 'uf_oab' => 'RJ']],
    ],
])];
eq(2, count(listPhpExtractAdvogados($duasUfs)), 'a mesma OAB em UF diferente não é deduplicada');

// Sem OAB, a chave cai no nome.
$semOab = ['payload_original' => json_encode([
    'destinatarioadvogados' => [
        ['advogado' => ['nome' => 'SEM INSCRICAO', 'numero_oab' => '', 'uf_oab' => '']],
        ['advogado' => ['nome' => 'sem inscricao', 'numero_oab' => '', 'uf_oab' => '']],
    ],
])];
eq(1, count(listPhpExtractAdvogados($semOab)), 'sem OAB, dedupe pelo nome, ignorando maiúscula');

/* ===================================================================== */
secao('Payload ausente ou quebrado não derruba nada');

eq([], listPhpExtractAdvogados([]), 'item sem payload devolve lista vazia');
eq([], listPhpExtractPartes([]), '  e as partes também');
eq([], listPhpExtractAdvogados(['payload_original' => 'isto não é json']), 'payload inválido devolve vazio');
eq([], listPhpExtractAdvogados(['payload_original' => null]), 'payload nulo devolve vazio');
eq([], listPhpExtractAdvogados(['payload_original' => '{}']), 'payload sem os campos devolve vazio');

// Entrada malformada no meio da lista não pode engolir as boas.
$sujo = ['payload_original' => json_encode([
    'destinatarioadvogados' => [
        ['advogado' => 'texto em vez de objeto'],
        ['sem_a_chave_advogado' => 1],
        ['advogado' => ['nome' => '', 'numero_oab' => '', 'uf_oab' => '']],
        ['advogado' => ['nome' => 'BOM ADVOGADO', 'numero_oab' => '111111', 'uf_oab' => 'SP']],
    ],
])];
$advs = listPhpExtractAdvogados($sujo);
eq(1, count($advs), 'lixo no meio da lista é descartado e o advogado bom continua');
eq('BOM ADVOGADO', $advs[0]['nome'], '  e é o certo');

/* ===================================================================== */
secao('AASP: o caminho por texto continua funcionando');

$aasp = ['payload_original' => json_encode([
    'textoPublicacao' => 'Intimação. BRUNO CARREIRA FERREIRA OAB SP-357838 e VICTOR ZOCARATO OAB SP-399918 ficam cientes.',
])];
$advs = listPhpExtractAdvogados($aasp);
ok(count($advs) >= 2, 'extrai do texto quando não há lista estruturada: ' . count($advs));
$oabs = array_column($advs, 'oab');
ok(in_array('357838', $oabs, true), '  achou a primeira OAB');
ok(in_array('399918', $oabs, true), '  e a segunda');

printf("\n%s  %d PASS, %d FAIL\n", $FAILS === 0 ? 'OK' : 'FALHOU', $PASSES, $FAILS);
exit($FAILS === 0 ? 0 : 1);
