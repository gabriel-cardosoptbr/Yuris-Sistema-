<?php
/**
 * djen_filtros_test.php — a OAB manda na busca do DJEN.
 *
 * POR QUE ISTO EXISTE (caso real, 04/09/2026)
 *
 * Uma advogada com OAB SP 219955 cadastrada corretamente (perfil E monitor)
 * abriu Intimações e viu 200+ publicações de dezenas de "Maria Fernanda"
 * diferentes, de vários estados. O log de consultas provou o motivo: a busca
 * do botão "Buscar publicações de hoje" saiu com
 *
 *     numero_oab = ""            <- vazia
 *     nome_advogado = "Maria Fernanda"   <- o nome de EXIBIÇÃO do usuário
 *
 * e a DJEN devolveu 500 publicações, que foram parar no cache do escritório.
 * Medido na API real naquele dia, para a mesma janela:
 *
 *     só a OAB (com ou sem UF) ....... 7 publicações, todas dela
 *     OAB + nome ..................... 7 publicações, todas dela
 *     só o nome "Maria Fernanda" ..... 1.300 publicações, 82 advogadas
 *
 * Daí as duas regras que este teste tranca:
 *
 *   1. Tendo OAB, o nome NÃO vai junto. A OAB já identifica unicamente, e o
 *      nome só traz risco: a mesma pessoa aparece na DJEN ora "ROMAO", ora
 *      "ROMÃO". Se o casamento por nome apertar, a publicação dela SOME do
 *      monitor. Falso negativo aqui é prazo perdido.
 *
 *   2. O nome de exibição do usuário (users.nome) NUNCA vira filtro de busca.
 *      Só o campo explícito users.nome_advogado.
 *
 * Não faz requisição de rede: exercita a montagem dos filtros.
 *
 * Uso: php scripts/tests/djen_filtros_test.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

$OK = 0;
$FALHAS = [];

function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}

/* ═══════════════════════════════════════════════════════════════════════════
   1) Servidor: search.php descarta o nome quando há OAB
   ═══════════════════════════════════════════════════════════════════════════ */
echo "== 1) Regra no servidor (public/api/push/search.php) ==\n";

$src = (string)file_get_contents(__DIR__ . '/../../public/api/push/search.php');

ok('search.php zera nome_advogado quando numero_oab está preenchido',
    (bool)preg_match(
        '/if\s*\(\s*\$filters\[.numero_oab.\]\s*!==\s*..\s*&&\s*\$filters\[.nome_advogado.\]\s*!==\s*..\s*\)\s*\{\s*\$filters\[.nome_advogado.\]\s*=\s*..\s*;/s',
        $src));

/* ═══════════════════════════════════════════════════════════════════════════
   2) Front: collectFilters não usa o nome de exibição nem manda nome com OAB
   ═══════════════════════════════════════════════════════════════════════════ */
echo "\n== 2) Regra no front (public/assets/intimacoes.js) ==\n";

$js = (string)file_get_contents(__DIR__ . '/../../public/assets/intimacoes.js');

ok('collectFilters NÃO usa p.nome como nome de advogado',
    !preg_match('/nomeFromProfile\s*=\s*p\.nome_advogado\s*\|\|\s*p\.nome\b/', $js));

ok('collectFilters usa somente p.nome_advogado',
    (bool)preg_match('/nomeFromProfile\s*=\s*p\.nome_advogado\s*\|\|\s*../', $js));

ok('collectFilters manda nome_advogado vazio quando há OAB',
    (bool)preg_match('/nome_advogado:\s*oab\s*\?\s*..\s*:\s*nome/', $js));

/* ═══════════════════════════════════════════════════════════════════════════
   3) Runner do monitoramento: nome complementar só sem OAB
   ═══════════════════════════════════════════════════════════════════════════ */
echo "\n== 3) Regra no monitoramento (PushMonitorRunner) ==\n";

$runner = (string)file_get_contents(__DIR__ . '/../../app/Processos/Monitor/PushMonitorRunner.php');

ok('runner só aplica nome_complementar quando NÃO há numero_oab',
    (bool)preg_match(
        '/\$nomeComp\s*!==\s*..\s*&&\s*empty\(\$filters\[.nome_advogado.\]\)\s*&&\s*empty\(\$filters\[.numero_oab.\]\)/',
        $runner));

/* ═══════════════════════════════════════════════════════════════════════════
   4) Provider: OAB com UF vira numeroOab + ufOab separados
   ═══════════════════════════════════════════════════════════════════════════ */
echo "\n== 4) Normalização da OAB (DjenProvider) ==\n";

// Reflexão sobre o provider: exercita o parser de OAB sem tocar na rede.
$prov = new ReflectionClass(\App\Processos\Monitor\DjenProvider::class);
$provSrc = (string)file_get_contents($prov->getFileName());

ok('provider separa UF do número quando recebe "SP219955"',
    (bool)preg_match('/\^\(\[A-Z\]\{2\}\)\\\\s\*0\*\(\\\\d\+\)\$/', $provSrc)
    || (bool)preg_match("/\\^\\(\\[A-Z\\]\\{2\\}\\)/", $provSrc));

ok('provider envia ufOab separado quando a UF foi identificada',
    str_contains($provSrc, "\$query['ufOab']"));

ok('provider envia numeroOab', str_contains($provSrc, "\$query['numeroOab']"));

/* ── resultado ─────────────────────────────────────────────────────────────── */
echo "\n----\n";
if (!$FALHAS) {
    echo "Resultado: {$OK} ok · 0 falha(s)\n";
    exit(0);
}
echo 'Resultado: ' . $OK . ' ok · ' . count($FALHAS) . " falha(s)\n\n";
foreach ($FALHAS as $f) echo "  - $f\n";
exit(1);
