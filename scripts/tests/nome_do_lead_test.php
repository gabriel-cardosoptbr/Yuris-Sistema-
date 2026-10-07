<?php
/**
 * scripts/tests/nome_do_lead_test.php — scripts/manutencao/nome_do_lead_pela_abertura.php
 *
 * Confere só a leitura da saudação da abertura (nomePelaSaudacao): o nome que a
 * automação escreveu na primeira mensagem vira o nome do lead, e tudo que não é
 * nome (saudação genérica, sem o molde "Oi|Olá, NOME!") é descartado. Sem banco.
 *
 * Uso: php scripts/tests/nome_do_lead_test.php
 */
require_once __DIR__ . '/../manutencao/nome_do_lead_pela_abertura.php';

$ok = 0; $falhas = 0;
function ok(string $nome, bool $cond): void {
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  [ok]   $nome\n"; } else { $falhas++; echo "  [FALHA] $nome\n"; }
}

echo "== Saudação da abertura ==\n";
ok('Olá, NOME! pega o nome inteiro', nomePelaSaudacao('Olá, Sampaio e Dellova Campos Advogados! Tudo bem? Aqui é a Isa, da Inovaize.') === 'Sampaio e Dellova Campos Advogados');
ok('Oi, NOME! também', nomePelaSaudacao("Oi, Advocacia Carolina Rockenbach! Tudo certo? Aqui é a Isa, da Inovaize.\n\nPosso te fazer uma pergunta?") === 'Advocacia Carolina Rockenbach');
ok('"pessoal da" é tirado', nomePelaSaudacao('Oi, pessoal da Avance Motors! Aqui é a Vitória, da Fleetiflow') === 'Avance Motors');
ok('título e ponto no nome ficam (Dr.)', nomePelaSaudacao('Olá, Dr. Rafael Nascimento advocacia! Tudo bem?') === 'Dr. Rafael Nascimento advocacia');
ok('sem vírgula depois do Oi', nomePelaSaudacao('Oi Clínica Bela! Tudo bem?') === 'Clínica Bela');
ok('espaços repetidos se juntam', nomePelaSaudacao("Oi,   Studio   Lima! Tudo bem?") === 'Studio Lima');
ok('"Olá! Tudo bem?" não tem nome', nomePelaSaudacao('Olá! Tudo bem? Aqui é a Isa') === '');
ok('saudação genérica é descartada', nomePelaSaudacao('Oi, tudo bem? Aqui é a Isa, da Inovaize!') === '' && nomePelaSaudacao('Olá, pessoal! Tudo bem?') === '');
ok('fora do molde não vale', nomePelaSaudacao('Bom dia, Fulano! Tudo bem?') === '' && nomePelaSaudacao('') === '');
ok('só número não é nome', nomePelaSaudacao('Oi, 1234! Tudo bem?') === '');

echo "\n----\nResultado: $ok ok · $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
