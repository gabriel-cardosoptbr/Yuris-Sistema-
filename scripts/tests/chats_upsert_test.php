<?php
/**
 * chats_upsert_test.php: o evento chats.upsert/chats.update da Evolution v2 cria a
 * conversa pelo `remoteJid`, nunca pelo `id` interno da Evolution
 * (WhatsAppWebhookEntitySync::syncChats, 23/10/2026, C2 Motors). E o script
 * scripts/manutencao/conversas_vazias_duplicadas.php tira da lista só a conversa
 * vazia com código no lugar do número, sem tocar na que tem mensagem.
 *
 * Conta 304 local, número 11; desfaz tudo no fim.
 * Uso: php scripts/tests/chats_upsert_test.php
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\WhatsAppWebhookEntitySync as S;

$OK = 0; $FALHAS = [];
function ok(string $m, bool $c): void { global $OK, $FALHAS; if ($c) { $OK++; echo "  [ok]   $m\n"; } else { $FALHAS[] = $m; echo "  [FALHA] $m\n"; } }

$pdo = Database::getConnection();
$CONTA = 304; $INST = 11;
$jid = '5511900006666@s.whatsapp.net';
$codigo = 'zzteste' . bin2hex(random_bytes(6));
$codigoAntigo = 'zzteste' . bin2hex(random_bytes(6));
$conta = fn(string $j) => (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_chats WHERE instance_id = $INST AND remote_jid = " . $pdo->quote($j))->fetchColumn();
try {
    echo "== chats.upsert ==\n";
    S::syncChats($CONTA, $INST, [['id' => $codigo, 'remoteJid' => $jid, 'name' => 'ZZTESTE Cliente', 'unreadCount' => 0]]);
    ok('cria a conversa pelo remoteJid', $conta($jid) === 1);
    ok('não cria conversa com o id interno da Evolution', $conta($codigo) === 0);
    S::syncChats($CONTA, $INST, ['id' => $codigo, 'name' => 'ZZTESTE Sem endereço']);
    ok('evento sem JID de verdade não cria nada', $conta($codigo) === 0);
    S::syncChats($CONTA, $INST, ['remoteJid' => $jid, 'name' => 'ZZTESTE Cliente novo nome']);
    $nome = $pdo->query("SELECT contact_name FROM whatsapp_chats WHERE instance_id = $INST AND remote_jid = " . $pdo->quote($jid))->fetchColumn();
    ok('evento único (objeto) também vale, e atualiza o nome', $nome === 'ZZTESTE Cliente novo nome');

    echo "\n== limpeza das vazias com código ==\n";
    // A conversa-fantasma como o código antigo criava.
    $pdo->prepare('INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, is_group) VALUES (?, ?, ?, ?, 0)')->execute([$CONTA, $INST, $codigoAntigo, 'ZZTESTE Fantasma']);
    $script = __DIR__ . '/../manutencao/conversas_vazias_duplicadas.php';
    $saida = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . " --conta=$CONTA");
    ok('conferir lista a fantasma e não grava', str_contains((string) $saida, $codigoAntigo) && (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_chats WHERE remote_jid = '$codigoAntigo' AND excluida_em IS NULL")->fetchColumn() === 1);
    shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . " --conta=$CONTA --aplicar");
    ok('--aplicar tira a fantasma da lista (sem apagar)', (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_chats WHERE remote_jid = '$codigoAntigo' AND excluida_em IS NOT NULL")->fetchColumn() === 1);
    ok('a conversa de verdade continua na lista', (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_chats WHERE instance_id = $INST AND remote_jid = " . $pdo->quote($jid) . " AND excluida_em IS NULL")->fetchColumn() === 1);
} catch (\Throwable $e) {
    $FALHAS[] = $e->getMessage(); echo '  [FALHA] ' . $e->getMessage() . "\n";
} finally {
    $pdo->prepare('DELETE FROM whatsapp_chats WHERE instance_id = ? AND remote_jid IN (?, ?, ?)')->execute([$INST, $jid, $codigo, $codigoAntigo]);
}
echo "\n" . ($FALHAS ? count($FALHAS) . ' FALHA(S): ' . implode(' | ', $FALHAS) : "$OK ok/0") . "\n";
exit($FALHAS ? 1 : 0);
