<?php
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/WhatsAppMessage.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/EvolutionApiService.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/WhatsAppChannelAccessService.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';

use App\Core\AccountContext;

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
    $msgModel   = new WhatsAppMessage();
    $pdo        = \App\Core\Database::getConnection();

    // Refresh de uma conversa = 'sync' no canal (deny-by-default). Canal/credenciais
    // resolvidos no backend (dono do canal) — nunca do front.
    $ch         = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $_GET['channel_id'] ?? null, 'sync');
    $cfg        = $ch['cfg'];
    $name       = $ch['instance_name'];
    $instanceId = (int)$ch['channel_id'];

    // H1 (auditoria): freshness gate por (instance_id, remote_jid). Se esta conversa foi
    // sincronizada ha menos de 3s, serve do banco e NAO repete o POST a Evolution — evita
    // N atendentes na mesma conversa gerarem N chamadas identicas a cada 4s. Comparacao no
    // SQL (mesma TZ). Fail-open: se a coluna nao existir (pre-migration 105), segue o sync.
    try {
        $g = $pdo->prepare("SELECT (last_synced_at IS NOT NULL AND last_synced_at > (NOW() - INTERVAL 3 SECOND)) AS fresh
                              FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1");
        $g->execute([$instanceId, $remoteJid]);
        if ((int)$g->fetchColumn() === 1) { echo json_encode(['ok' => true, 'cached' => true, 'checked' => 0, 'saved' => 0]); exit; }
    } catch (\Throwable $_) { /* sem coluna: fail-open, faz o sync normal */ }

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
        // Pula status/broadcast e newsletter: nao sao conversas (nao re-salva status@broadcast).
        if (str_ends_with((string)$remJid, '@broadcast') || str_contains((string)$remJid, '@newsletter')) continue;
        // Normaliza @lid -> telefone quando conhecido (mesma anti-duplicacao dos demais caminhos).
        $remJid = WhatsAppMessage::resolvePhoneJid($pdo, $instanceId, $remJid);
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

    // H1: marca o momento do sync p/ o freshness gate dos proximos refresh.
    try { $pdo->prepare("UPDATE whatsapp_chats SET last_synced_at = NOW() WHERE instance_id = ? AND remote_jid = ?")->execute([$instanceId, $remoteJid]); } catch (\Throwable $_) {}

    echo json_encode(['ok' => true, 'checked' => count($msgList), 'saved' => $saved]);

} catch (Throwable $e) {
    require_once __DIR__ . '/../../../app/Core/ErrorReporter.php';
    \App\Core\ErrorReporter::handle($e);  // P1 LGPD (2D.1)
}
