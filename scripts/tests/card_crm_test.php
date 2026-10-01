<?php
/**
 * card_crm_test.php: os campos do card da edição CRM (migration 134).
 *
 *   - `temperatura` só aceita frio, morno e quente; qualquer outra coisa vira
 *     NULL (automática), nunca erro nem lixo;
 *   - `tipo_lead` é texto curto e limpo;
 *   - criar e editar gravam os dois, e a lista devolve `linked_chat_last_at`
 *     (última mensagem do WhatsApp ligado ao card).
 *
 * O que toca o banco roda em transação desfeita no fim.
 * Uso: php scripts/tests/card_crm_test.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Prospeccao\Card;

$OK = 0;
$FALHAS = [];

function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}

echo "\n== 1. Normalização ==\n";
ok('quente/morno/frio passam, em qualquer caixa', Card::_temperatura('Quente') === 'quente' && Card::_temperatura(' MORNO ') === 'morno' && Card::_temperatura('frio') === 'frio');
ok('vazio e inválido viram NULL (automática)', Card::_temperatura('') === null && Card::_temperatura('pelando') === null && Card::_temperatura(null) === null);
ok('tipo é limpo de HTML e espaços', Card::_tipoLead('  <b>Concessionária</b>   premium ') === 'Concessionária premium');
ok('tipo vazio vira NULL', Card::_tipoLead('   ') === null && Card::_tipoLead(null) === null);
ok('tipo é cortado em 60 caracteres', mb_strlen((string) Card::_tipoLead(str_repeat('a', 80))) === 60);

echo "\n== 2. Gravação e lista (banco, desfeito no fim) ==\n";
try {
    $pdo = \App\Core\Database::getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $temCol = (bool) $pdo->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cards' AND COLUMN_NAME = 'temperatura'")->fetchColumn();
    $conta  = $pdo->query('SELECT c.account_id, c.id AS coluna_id FROM pipeline_columns c JOIN accounts a ON a.id = c.account_id AND a.deleted_at IS NULL ORDER BY c.id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $pdo = null; $temCol = false; $conta = null;
    echo '  [pulado] sem banco: ' . $e->getMessage() . "\n";
}

if (!$pdo || !$temCol || !$conta) {
    echo "  [pulado] precisa do banco, da migration 134 e de uma conta com funil\n";
} else {
    $pdo->beginTransaction();
    try {
        $acc = (int) $conta['account_id'];
        $id = (int) Card::create([
            'account_id' => $acc, 'cliente_nome' => 'Teste Card CRM', 'empresa_nome' => 'Carrera Teste',
            'coluna_id' => (int) $conta['coluna_id'], 'tipo_lead' => ' Concessionária ', 'temperatura' => 'QUENTE',
        ]);
        $c = Card::find($id);
        ok('criar grava tipo e termômetro normalizados', $c['tipo_lead'] === 'Concessionária' && $c['temperatura'] === 'quente');

        Card::update($id, ['temperatura' => 'morno', 'tipo_lead' => 'Despachante']);
        $c = Card::find($id);
        ok('editar troca os dois', $c['temperatura'] === 'morno' && $c['tipo_lead'] === 'Despachante');

        Card::update($id, ['temperatura' => 'invalido', 'tipo_lead' => '']);
        $c = Card::find($id);
        ok('valor inválido volta para automático e tipo vazio vira NULL', $c['temperatura'] === null && $c['tipo_lead'] === null);

        $lista = Card::list(['account_ids' => [$acc], 'coluna_id' => (int) $conta['coluna_id']]);
        $meu = null;
        foreach ($lista as $l) { if ((int) $l['id'] === $id) { $meu = $l; break; } }
        ok('a lista traz o card com as chaves novas', $meu !== null && array_key_exists('tipo_lead', $meu) && array_key_exists('linked_chat_last_at', $meu));
        ok('sem conversa ligada, último contato do WhatsApp é NULL', $meu !== null && $meu['linked_chat_last_at'] === null);

        $inst = $pdo->query("SELECT id FROM whatsapp_instances WHERE account_id = $acc ORDER BY id LIMIT 1")->fetchColumn();
        if ($inst) {
            $pdo->prepare('INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, phone, is_group, linked_card_id, last_message_at) VALUES (?,?,?,?,?,0,?,?)')
                ->execute([$acc, (int) $inst, '5511977770009@s.whatsapp.net', 'Teste', '5511977770009', $id, '2026-09-30 10:24:00']);
            $lista = Card::list(['account_ids' => [$acc], 'coluna_id' => (int) $conta['coluna_id']]);
            foreach ($lista as $l) { if ((int) $l['id'] === $id) { $meu = $l; break; } }
            ok('com conversa ligada, a lista traz a hora da última mensagem', ($meu['linked_chat_last_at'] ?? null) === '2026-09-30 10:24:00');
        } else {
            echo "  [pulado] conta sem canal de WhatsApp: sem o caso da última mensagem\n";
        }
    } catch (\Throwable $e) {
        ok('integração sem exceção: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

echo "\n----\n";
if (!$FALHAS) {
    echo "Resultado: {$OK} ok · 0 falha(s)\n";
    exit(0);
}
echo 'Resultado: ' . $OK . ' ok · ' . count($FALHAS) . " falha(s)\n\n";
foreach ($FALHAS as $f) echo "  - $f\n";
exit(1);
