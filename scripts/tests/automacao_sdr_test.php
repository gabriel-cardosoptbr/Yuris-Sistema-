<?php
/**
 * scripts/tests/automacao_sdr_test.php — App\WhatsAppAgente\AutomacaoSdr, as chaves
 * "Disparo" e "Follow-up" do Chat do CRM que o n8n consulta antes de cada mensagem.
 *
 * Confere: sem registro, as duas valem ligadas (como os fluxos rodavam antes do
 * botão); desligar e religar uma não mexe na outra; chave desconhecida e conta
 * inválida são recusadas; a conta do robô é a dona do token da prospecção. Usa a
 * conta CRM de teste e devolve o estado que encontrou.
 *
 * Uso: php scripts/tests/automacao_sdr_test.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\AutomacaoSdr as A;

$ok = 0; $falhas = 0;
function ok(string $nome, bool $cond): void {
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  [ok]   $nome\n"; } else { $falhas++; echo "  [FALHA] $nome\n"; }
}

$pdo = Database::getConnection();
$ACC = A::contaDoRobo();
if ($ACC === null) { echo "(pulado: precisa de exatamente uma conta Fleetiflow sem marca própria)\n"; exit(0); }
echo "conta do robô: #$ACC\n\n";

$st = $pdo->prepare('SELECT config_key, config_value FROM whatsapp_settings WHERE account_id = ? AND config_key IN (?, ?)');
$st->execute([$ACC, A::CHAVES['disparo'], A::CHAVES['followup']]);
$antes = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$apaga = $pdo->prepare('DELETE FROM whatsapp_settings WHERE account_id = ? AND config_key IN (?, ?)');
try {
    $apaga->execute([$ACC, A::CHAVES['disparo'], A::CHAVES['followup']]);
    ok('sem registro, as duas valem ligadas', A::estado($ACC) === ['disparo' => true, 'followup' => true]);
    ok('desliga o disparo', A::definir($ACC, 'disparo', false) && A::estado($ACC) === ['disparo' => false, 'followup' => true]);
    ok('desliga o follow-up sem mexer no disparo', A::definir($ACC, 'followup', false) && A::estado($ACC) === ['disparo' => false, 'followup' => false]);
    ok('religa o disparo', A::definir($ACC, 'disparo', true) && A::estado($ACC)['disparo'] === true && A::estado($ACC)['followup'] === false);
    ok('gravar de novo não duplica o registro', A::definir($ACC, 'disparo', true) && (function () use ($pdo, $ACC) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_settings WHERE account_id = ? AND config_key = ?');
        $c->execute([$ACC, A::CHAVES['disparo']]);
        return (int)$c->fetchColumn() === 1;
    })());
    ok('chave desconhecida é recusada', A::definir($ACC, 'outra', true) === false);
    ok('conta inválida: nada liga', A::definir(0, 'disparo', true) === false && A::estado(0) === ['disparo' => false, 'followup' => false]);
} finally {
    $apaga->execute([$ACC, A::CHAVES['disparo'], A::CHAVES['followup']]);
    $ins = $pdo->prepare('INSERT INTO whatsapp_settings (account_id, config_key, config_value, updated_at) VALUES (?, ?, ?, NOW())');
    foreach ($antes as $k => $v) $ins->execute([$ACC, $k, $v]);
}

echo "\n----\nResultado: $ok ok · $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
