<?php
/**
 * sdr_treino_comando_test.php: a Vitória nos números de treino só com comando
 * (SdrFleetiflow, migration 145).
 *
 *   - "#ativar" liga, "#desativar" desliga, com ou sem acento, maiúscula e ponto;
 *   - qualquer outra frase (até "ativar" sem #) não é comando e vai para a conversa;
 *   - o número de treino começa DESLIGADO, liga e desliga, e o estado é por número.
 *
 * A parte com banco grava só a linha do número de teste e devolve o que havia.
 * Uso: php scripts/tests/sdr_treino_comando_test.php
 */
if (PHP_SAPI !== 'cli') exit;

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\SdrFleetiflow;

$OK = 0;
$FALHAS = [];
function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}

echo "\n== 1. Comandos (sem banco) ==\n";
foreach (['#ativar', '#Ativar', ' #ATIVAR. ', '#ativar vitória', '#vitoria', '#Vitória', '#ia on'] as $t) {
    ok("liga: \"$t\"", SdrFleetiflow::comandoDeTreino($t) === true);
}
foreach (['#desativar', '#Desativar!', '#parar', '#desativar Vitória', '#ia off'] as $t) {
    ok("desliga: \"$t\"", SdrFleetiflow::comandoDeTreino($t) === false);
}
foreach (['ativar', 'Tem o link?', 'quero #ativar o plano', '#ativarr', ''] as $t) {
    ok("não é comando: \"$t\"", SdrFleetiflow::comandoDeTreino($t) === null);
}
ok('a confirmação diz como desfazer', str_contains(SdrFleetiflow::textoDoComando(true), '#desativar') && str_contains(SdrFleetiflow::textoDoComando(false), '#ativar'));

if (file_exists('/.dockerenv') && getenv('TESTE_NO_SERVIDOR') !== '1') {
    echo "\n  [pulado] no servidor (produção) a parte com banco não roda\n";
    echo "\nResultado: $OK ok · " . count($FALHAS) . " falha(s)\n";
    exit($FALHAS ? 1 : 0);
}

echo "\n== 2. Estado por número (com banco) ==\n";
try {
    $pdo = Database::getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $temTabela = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sdr_treino_estado'")->fetchColumn();
} catch (\Throwable $e) {
    $temTabela = false;
}
if (!$temTabela) {
    echo "  [pulado] precisa do banco e da migration 145\n";
} else {
    $jid = '5511930118329@s.whatsapp.net';  // um dos números de treino (SdrFleetiflow::NUMEROS_TREINO)
    $chave = '1130118329';
    $antes = $pdo->query("SELECT ativo, mudado_em FROM sdr_treino_estado WHERE chave_fone = '$chave'")->fetch(\PDO::FETCH_ASSOC);
    try {
        $pdo->exec("DELETE FROM sdr_treino_estado WHERE chave_fone = '$chave'");
        ok('é número de treino', SdrFleetiflow::ehTreino(0, $jid));
        ok('começa desligado', SdrFleetiflow::treinoAtivo(0, $jid) === false);
        ok('#ativar liga', SdrFleetiflow::definirTreino(0, $jid, [], true) && SdrFleetiflow::treinoAtivo(0, $jid) === true);
        ok('o mesmo número com 9 e sem 55 é o mesmo estado', SdrFleetiflow::treinoAtivo(0, '11930118329@s.whatsapp.net') === true);
        ok('o outro número de treino continua desligado', SdrFleetiflow::treinoAtivo(0, '5511925592706@s.whatsapp.net') === (bool) $pdo->query("SELECT ativo FROM sdr_treino_estado WHERE chave_fone = '1125592706'")->fetchColumn());
        ok('#desativar desliga', SdrFleetiflow::definirTreino(0, $jid, [], false) && SdrFleetiflow::treinoAtivo(0, $jid) === false);
        ok('número que não é de treino não grava', SdrFleetiflow::ehTreino(0, '5511900000000@s.whatsapp.net') === false);
    } finally {
        $pdo->exec("DELETE FROM sdr_treino_estado WHERE chave_fone = '$chave'");
        if ($antes) {
            $pdo->prepare('INSERT INTO sdr_treino_estado (chave_fone, ativo, mudado_em) VALUES (?, ?, ?)')
                ->execute([$chave, (int) $antes['ativo'], $antes['mudado_em']]);
        }
    }
}

echo "\nResultado: $OK ok · " . count($FALHAS) . " falha(s)\n";
if ($FALHAS) exit(1);
