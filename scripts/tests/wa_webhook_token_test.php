<?php
/**
 * wa_webhook_token_test.php — teste de COMPORTAMENTO do 2o fator do webhook (B3).
 *
 * Cobre a maquina de 4 estados de WhatsAppWebhookAuth::verify (o segredo dedicado
 * webhook_token, separado da evolution_api_key). Self-contained (so requer a classe),
 * roda igual local e em prod. Prova o modo COMPATIVEL: enquanto a Evolution nao
 * enviar o token, a entrega NAO e bloqueada — so um token PRESENTE e ERRADO rejeita.
 * Regex nao prova isso; este teste sim.
 *
 * Uso: php scripts/tests/wa_webhook_token_test.php   (exit 0 = tudo passou)
 */
require_once __DIR__ . '/../../app/WhatsAppAgente/WhatsAppWebhookAuth.php';

use App\WhatsAppAgente\WhatsAppWebhookAuth as A;

$pass = 0; $fail = 0;
function check(string $label, $actual, $expected): void {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; return; }
    $fail++;
    fwrite(STDERR, "  [FAIL] $label\n    esperado: " . json_encode($expected) . "\n    obtido:   " . json_encode($actual) . "\n");
}

$T = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6'; // 64 hex (256 bits)

// 1) tenant SEM token configurado -> OK sempre (comportamento atual, retrocompat).
check('sem_config/vazio_vazio',      A::verify('',    ''),     A::OK);
check('sem_config/null_null',        A::verify(null,  null),   A::OK);
check('sem_config/vazio_qualquer',   A::verify('',    'lixo'), A::OK);
check('sem_config/so_espacos',       A::verify('   ', 'x'),    A::OK); // expected vira vazio no trim

// 2) tenant COM token, requisicao SEM token -> COMPAT (janela Fase A->B: aceita e loga).
check('compat/provided_vazio',       A::verify($T, ''),        A::COMPAT);
check('compat/provided_null',        A::verify($T, null),      A::COMPAT);
check('compat/provided_espacos',     A::verify($T, '   '),     A::COMPAT); // provided vira vazio no trim

// 3) tenant COM token, requisicao COM token CORRETO -> OK.
check('match/exato',                 A::verify($T, $T),               A::OK);
check('match/espacos_nas_bordas',    A::verify($T, '  ' . $T . ' '),  A::OK); // trim so nas bordas

// 4) tenant COM token, requisicao COM token ERRADO -> REJECT (401).
check('reject/totalmente_diferente', A::verify($T, 'deadbeefdeadbeef'),      A::REJECT);
check('reject/prefixo_do_certo',     A::verify($T, substr($T, 0, 32)),       A::REJECT); // token parcial
check('reject/caixa_trocada',        A::verify($T, strtoupper($T)),          A::REJECT); // hash_equals e case-sensitive
check('reject/difere_no_ultimo',     A::verify($T, substr($T, 0, 63) . '0'), A::REJECT); // 1 char de diferenca

// 5) MODO ESTRITO (Fase C, 3o arg = true): o token AUSENTE tambem rejeita (COMPAT vira REJECT).
//    Fecha a janela de compat, mas SO por canal que ja foi confirmado enviando o cracha.
check('estrito/provided_vazio_rejeita',   A::verify($T, '',     true), A::REJECT); // era COMPAT sem estrito
check('estrito/provided_null_rejeita',    A::verify($T, null,   true), A::REJECT);
check('estrito/provided_espacos_rejeita', A::verify($T, '   ',  true), A::REJECT); // trim -> vazio -> reject
check('estrito/correto_ainda_OK',         A::verify($T, $T,     true), A::OK);     // caminho feliz inalterado
check('estrito/errado_ainda_rejeita',     A::verify($T, 'deadbeef', true), A::REJECT);
// Sem token configurado, o estrito e inocuo (expected vazio curto-circuita em OK): nao trava canal sem cracha.
check('estrito/sem_token_vazio_OK',       A::verify('',  '',     true), A::OK);
check('estrito/sem_token_qualquer_OK',    A::verify('',  'lixo', true), A::OK);
// Retrocompat: sem o 3o arg, o comportamento e o de sempre (token ausente = COMPAT, nao REJECT).
check('nao_estrito_default/compat',       A::verify($T, ''),           A::COMPAT);

echo "== wa_webhook_token: $pass PASS · $fail FAIL ==\n";
exit($fail === 0 ? 0 : 1);
