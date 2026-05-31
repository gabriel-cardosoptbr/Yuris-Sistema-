<?php
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppMessage.php';
require_once __DIR__ . '/../../../app/Services/EvolutionApiService.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;

session_start(['read_and_close' => true]);
$_uid = $_SESSION['user_id'] ?? null;

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
if (!$_uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$remoteJid = trim($_GET['jid'] ?? '');
if (!$remoteJid) { http_response_code(400); echo json_encode(['error' => 'jid obrigatório']); exit; }

// P0 LGPD (1.8): contexto de tenant per-tenant
$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();

try {
    $instModel  = new WhatsAppInstance();
    $msgModel   = new WhatsAppMessage();
    $cfg        = $instModel->getSettings($accountId);
    $name       = $cfg['evolution_instance'] ?? 'yuris-crm';
    $row        = $instModel->findOrCreate($name, '', $accountId);
    $instanceId = (int)$row['id'];
    $evo        = new EvolutionApiService($cfg);
    // Não pendurar a conexão se a Evolution estiver lenta/instável (era ilimitado).
    // Esse refresh roda em segundo plano; se estourar o tempo, o banco já foi exibido.
    $evo->setTimeout(8);

    // Busca a última página de mensagens do JID (as mais recentes)
    $apiMsgs = $evo->findMessages($name, $remoteJid, 50);
    $msgList = $apiMsgs['messages']['records'] ?? [];

    $saved = 0;
    foreach ($msgList as $r) {
        if (!is_array($r) || empty($r['key'])) continue;
        $key2   = $r['key'];
        $wamid  = $key2['id']              ?? null;
        $remJid = $key2['remoteJid']       ?? $remoteJid;
        $fromMe = (bool)($key2['fromMe']   ?? false);
        $participantJid = $key2['participant'] ?? null;
        $msgTypeRaw = $r['messageType'] ?? 'text';
        $msgObj     = $r['message']     ?? [];
        $ts         = $r['messageTimestamp'] ?? time();
        $createdAt  = date('Y-m-d H:i:s', (int)$ts);
        $push       = $r['pushName'] ?? null;
        // Se pushName é um LID (≥14 dígitos), usa telefone do participant como fallback
        if ($participantJid && (!$push || preg_match('/^\d{14,}$/', (string)$push))) {
            $pPhone = preg_replace('/[^0-9]/', '', explode('@', $participantJid)[0]);
            if ($pPhone) $push = $pPhone;
        }

        $type = match ($msgTypeRaw) {
            'imageMessage'    => 'image',
            'videoMessage'    => 'video',
            'audioMessage'    => 'audio',
            'documentMessage' => 'document',
            'stickerMessage'  => 'sticker',
            default           => 'text',
        };
        $content  = $msgObj['conversation'] ?? ($msgObj['extendedTextMessage']['text'] ?? null);
        $mediaUrl = $msgObj[$msgTypeRaw]['url']      ?? null;
        $caption  = $msgObj[$msgTypeRaw]['caption']  ?? null;
        $fname    = $msgObj[$msgTypeRaw]['fileName']  ?? null;
        $mime     = $msgObj[$msgTypeRaw]['mimetype']  ?? null;

        // Salva raw_payload para mensagens de mídia (necessário para buscar base64 depois)
        $isMedia    = in_array($type, ['image','video','audio','document','sticker']);
        $rawPayload = $isMedia ? json_encode($r, JSON_UNESCAPED_UNICODE) : null;

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
            'created_at'      => $createdAt,
            'raw_payload'     => $rawPayload,
        ]);
        $saved++;
    }

    echo json_encode(['ok' => true, 'checked' => count($msgList), 'saved' => $saved]);

} catch (Throwable $e) {
    require_once __DIR__ . '/../../../app/Helpers/ErrorReporter.php';
    \App\Helpers\ErrorReporter::handle($e);  // P1 LGPD (2D.1)
}
