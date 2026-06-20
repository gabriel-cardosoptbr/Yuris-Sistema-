<?php
/**
 * discover.php — varre páginas de mensagens da Evolution API
 * para descobrir chats/mensagens novas sem depender do webhook.
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppMessage.php';
require_once __DIR__ . '/../../../app/Services/EvolutionApiService.php';
require_once __DIR__ . '/../../../app/Services/WhatsAppChannelAccessService.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';

use App\Models\Database;
use App\Helpers\AccountContext;

session_start(['read_and_close' => true]);
$_uid = $_SESSION['user_id'] ?? null;

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
if (!$_uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

// P0 LGPD (1.8): contexto de tenant per-tenant
$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();

try {
    $msgModel   = new WhatsAppMessage();
    $pdo        = Database::getConnection();

    // Descobrir mensagens novas = 'sync' no canal (deny-by-default). Canal e
    // credenciais resolvidos no backend (dono do canal) — nunca do front.
    $ch         = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $_GET['channel_id'] ?? null, 'sync');
    $cfg        = $ch['cfg'];
    $name       = $ch['instance_name'];
    $instanceId = (int)$ch['channel_id'];
    $evo        = new EvolutionApiService($cfg);

    // account_id do DONO do canal (nao do visualizador) — pra gravar no chat sem NULL.
    $ownerStmt = $pdo->prepare('SELECT account_id FROM whatsapp_instances WHERE id = ? LIMIT 1');
    $ownerStmt->execute([$instanceId]);
    $ownerAccRaw = $ownerStmt->fetchColumn();
    $ownerAcc    = ($ownerAccRaw !== false && $ownerAccRaw !== null) ? (int)$ownerAccRaw : null;

    $newChats    = 0;
    $newMessages = 0;

    // Descobre total de páginas e busca as 2 últimas (mensagens mais recentes)
    $probe      = $evo->request('POST', "/chat/findMessages/{$name}", ['limit' => 50, 'page' => 1]);
    $totalPages = (int)($probe['messages']['pages'] ?? 1);
    $startPage  = max(1, $totalPages - 1);

    for ($page = $startPage; $page <= $totalPages; $page++) {
        $apiResp = $evo->request('POST', "/chat/findMessages/{$name}", [
            'limit' => 50,
            'page'  => $page,
        ]);
        $msgList = $apiResp['messages']['records'] ?? [];
        if (empty($msgList)) break;

        foreach ($msgList as $r) {
            if (!is_array($r) || empty($r['key'])) continue;
            $key2   = $r['key'];
            $wamid  = $key2['id']              ?? null;
            $remJid = $key2['remoteJid']        ?? null;
            $fromMe = (bool)($key2['fromMe']    ?? false);
            $participantJid = $key2['participant'] ?? null;
            if (!$remJid || !$wamid) continue;
            // Ignora status/broadcast e newsletter: nao sao conversas.
            if (str_ends_with($remJid, '@broadcast') || str_contains($remJid, '@newsletter')) continue;
            // Normaliza @lid -> telefone quando conhecido (de grupos/contatos). Evita
            // criar um shell @lid vazio: o save() deduplica por wamid e a conversa real
            // ja existe sob o telefone. Faz UMA vez no topo: tudo abaixo usa o jid certo.
            $remJid = WhatsAppMessage::resolvePhoneJid($pdo, $instanceId, $remJid);

            $msgTypeRaw = $r['messageType'] ?? 'text';
            $msgObj     = $r['message']     ?? [];
            $ts         = $r['messageTimestamp'] ?? time();
            $push       = $r['pushName'] ?? null;
            if ($participantJid && (!$push || preg_match('/^\d{12,}$/', (string)$push))) {
                $pp = preg_replace('/[^0-9]/', '', explode('@', $participantJid)[0]);
                if ($pp) $push = $pp;
            }

            $isGroup = str_ends_with($remJid, '@g.us') ? 1 : 0;
            // phone: so guarda numero discavel. @lid nao resolvido -> NULL (nao polui com o id de privacidade).
            $phone   = ($isGroup || str_ends_with($remJid, '@lid')) ? null : preg_replace('/[^0-9]/', '', explode('@', $remJid)[0]);
            $chatName = $isGroup ? null : ($push ?? null);

            // Garante que o chat existe no banco (com account_id do dono, sem NULL).
            $ins = $pdo->prepare(
                'INSERT INTO whatsapp_chats
                 (account_id, instance_id, remote_jid, contact_name, phone, is_group, unread_count)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   account_id   = IF(account_id IS NULL, VALUES(account_id), account_id),
                   contact_name = IF(is_group = 0 AND VALUES(contact_name) IS NOT NULL AND VALUES(contact_name) != "" AND contact_name IS NULL, VALUES(contact_name), contact_name),
                   phone        = IF(VALUES(phone) IS NOT NULL AND VALUES(phone) != "" AND (phone IS NULL OR phone = ""), VALUES(phone), phone)'
            );
            $ins->execute([$ownerAcc, $instanceId, $remJid, $chatName, $phone, $isGroup, 0]);
            if ($ins->rowCount() === 1) $newChats++;  // INSERT (não era duplicata)

            // Salva a mensagem (save() ignora duplicatas por wamid)
            $type = match ($msgTypeRaw) {
                'imageMessage'    => 'image',
                'videoMessage'    => 'video',
                'audioMessage'    => 'audio',
                'documentMessage' => 'document',
                'stickerMessage'  => 'sticker',
                default           => 'text',
            };
            $content  = $msgObj['conversation'] ?? ($msgObj['extendedTextMessage']['text'] ?? null);
            $mediaUrl = $msgObj[$msgTypeRaw]['url']     ?? null;
            $caption  = $msgObj[$msgTypeRaw]['caption'] ?? null;
            $fname    = $msgObj[$msgTypeRaw]['fileName'] ?? null;
            $mime     = $msgObj[$msgTypeRaw]['mimetype'] ?? null;

            $msgModel->save([
                'instance_id'     => $instanceId,
                'wamid'           => $wamid,
                'remote_jid'      => $remJid,
                'participant_jid' => $participantJid,
                'contact_name'    => $push,
                'phone'           => preg_replace('/[^0-9]/', '', explode('@', $remJid)[0]),
                'message_type'    => $type,
                'message_content' => $content,
                'caption'         => $caption,
                'media_url'       => $mediaUrl,
                'media_mimetype'  => $mime,
                'media_filename'  => $fname,
                'direction'       => $fromMe ? 'outbound' : 'inbound',
                'status'          => $fromMe ? 'sent' : 'delivered',
                'created_at'      => date('Y-m-d H:i:s', (int)$ts),
            ]);
            $newMessages++;
        }
    }

    echo json_encode([
        'ok'           => true,
        'new_chats'    => $newChats,
        'new_messages' => $newMessages,
    ]);

} catch (Throwable $e) {
    require_once __DIR__ . '/../../../app/Helpers/ErrorReporter.php';
    \App\Helpers\ErrorReporter::handle($e);  // P1 LGPD (2D.1)
}
