<?php
/**
 * scripts/manutencao/conversas_vazias_duplicadas.php: tira da lista do Chat as
 * conversas VAZIAS que duplicam uma conversa de verdade (23/10/2026, C2 Motors na
 * conta 116: a conversa com o telefone tinha 9 mensagens, e a que abria era uma
 * cópia vazia).
 *
 * Duas origens, as duas sem mensagem nenhuma gravada:
 *   codigo  o remote_jid é o id interno da Evolution (sem @), criado pelo
 *           chats.upsert antes de 23/10/2026 (WhatsAppWebhookEntitySync::syncChats
 *           usava o `id` do evento no lugar do `remoteJid`);
 *   lid     conversa @lid cujo telefone já é conhecido (whatsapp_identidades) e
 *           cuja conversa pelo telefone existe no mesmo número.
 *
 * Esconde do mesmo jeito que o "Excluir conversa" do Chat (excluida_em): nada é
 * apagado, e a conversa volta sozinha se chegar mensagem nela. Só confere por
 * padrão; --aplicar grava. Conversa com mensagem nunca é tocada.
 *
 * USO
 *   php scripts/manutencao/conversas_vazias_duplicadas.php                 (todas as contas, só confere)
 *   php scripts/manutencao/conversas_vazias_duplicadas.php --conta=116
 *   php scripts/manutencao/conversas_vazias_duplicadas.php --aplicar
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$o = getopt('', ['conta:', 'aplicar']);
$conta   = (int) ($o['conta'] ?? 0);
$aplicar = isset($o['aplicar']);
$pdo = Database::getConnection();

$semMensagem = 'NOT EXISTS (SELECT 1 FROM whatsapp_messages m WHERE m.instance_id = c.instance_id AND m.remote_jid = c.remote_jid)';
$daConta = $conta > 0 ? ' AND c.account_id = ' . $conta : '';
$sql = "SELECT c.id, c.account_id, c.instance_id, c.remote_jid, c.contact_name, 'codigo' AS origem
          FROM whatsapp_chats c
         WHERE c.excluida_em IS NULL AND c.remote_jid NOT LIKE '%@%' AND $semMensagem $daConta
        UNION ALL
        SELECT c.id, c.account_id, c.instance_id, c.remote_jid, c.contact_name, 'lid'
          FROM whatsapp_chats c
          JOIN whatsapp_identidades i ON i.instance_id = c.instance_id AND i.lid = c.remote_jid AND i.jid IS NOT NULL
          JOIN whatsapp_chats t ON t.instance_id = c.instance_id AND t.remote_jid = i.jid
         WHERE c.excluida_em IS NULL AND c.is_group = 0 AND c.remote_jid LIKE '%@lid' AND $semMensagem $daConta
         ORDER BY account_id, origem, id";
$linhas = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

$porConta = [];
foreach ($linhas as $l) {
    $k = $l['account_id'] . ' / ' . $l['origem'];
    $porConta[$k] = ($porConta[$k] ?? 0) + 1;
}
echo ($conta > 0 ? "Conta $conta" : 'Todas as contas') . ': ' . count($linhas) . " conversa(s) vazia(s) duplicada(s)\n";
foreach ($porConta as $k => $n) echo "  conta / origem $k: $n\n";
foreach (array_slice($linhas, 0, 15) as $l) echo "  #{$l['id']} conta {$l['account_id']} número {$l['instance_id']} {$l['origem']} {$l['remote_jid']} \"{$l['contact_name']}\"\n";
if (count($linhas) > 15) echo '  ... e mais ' . (count($linhas) - 15) . "\n";

if (!$aplicar) { echo "\nSó conferido. Rode com --aplicar para tirar da lista.\n"; exit(0); }
$st = $pdo->prepare("UPDATE whatsapp_chats c SET c.excluida_em = NOW(), c.unread_count = 0, c.is_pinned = 0
                      WHERE c.id = ? AND c.excluida_em IS NULL AND $semMensagem");
$n = 0;
foreach ($linhas as $l) { $st->execute([(int) $l['id']]); $n += $st->rowCount(); }
echo "\nTiradas da lista: $n.\n";
