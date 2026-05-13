<?php
/**
 * webhook.php — recebe eventos da Evolution API e salva no banco.
 *
 * Este endpoint é chamado pela Evolution API, portanto:
 *  - Não exige sessão PHP
 *  - Não exige CSRF
 *  - Valida opcionalmente via header apikey
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppMessage.php';
require_once __DIR__ . '/../../../app/Services/WebhookDispatcher.php';
require_once __DIR__ . '/../../../app/Services/EvolutionApiService.php';

use App\Models\Database;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

$event        = strtolower($payload['event']    ?? '');
$instanceName = $payload['instance']            ?? '';
$data         = $payload['data']                ?? [];

if (!$instanceName) {
    http_response_code(400);
    echo json_encode(['error' => 'Instance name ausente']);
    exit;
}

try {
    $instModel  = new WhatsAppInstance();
    $msgModel   = new WhatsAppMessage();
    $cfg        = $instModel->getSettings();

    // Valida apikey se configurada
    $configuredKey = $cfg['evolution_api_key'] ?? '';
    if ($configuredKey) {
        $sentKey = $_SERVER['HTTP_APIKEY'] ?? ($_SERVER['HTTP_API_KEY'] ?? '');
        if ($sentKey !== $configuredKey) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }

    $row        = $instModel->findOrCreate($instanceName);
    $instanceId = (int)$row['id'];

    switch ($event) {

        // ── Mensagens recebidas ou enviadas ─────────────────────────────
        case 'messages.upsert':
        case 'send_message':
            $msgs = $data;
            if (isset($data['key'])) $msgs = [$data]; // único
            foreach ($msgs as $msg) {
                handleMessageUpsert($msg, $instanceId, $msgModel);
            }
            break;

        // ── Atualização de status (enviado, entregue, lido) ─────────────
        case 'messages.update':
            $updates = $data;
            if (isset($data['key'])) $updates = [$data];
            foreach ($updates as $upd) {
                $wamid  = $upd['key']['id']  ?? null;
                $status = strtolower($upd['update']['status'] ?? '');
                if ($wamid && $status) {
                    $mapped = match ($status) {
                        'server_ack','delivery_ack' => 'delivered',
                        'read'                      => 'read',
                        'played'                    => 'read',
                        default                     => 'sent',
                    };
                    $msgModel->updateStatus($wamid, $mapped);
                }
            }
            break;

        // ── Atualização de conexão ───────────────────────────────────────
        case 'connection.update':
            $state = strtolower($data['state'] ?? ($data['connection'] ?? 'close'));
            $allowed = ['open', 'close', 'connecting'];
            if (!in_array($state, $allowed, true)) $state = 'close';

            $extra = [];
            if (!empty($data['profileName'])) $extra['profile_name'] = $data['profileName'];
            if (!empty($data['wuid']))        $extra['phone']        = $data['wuid'];

            $instModel->updateStatus($instanceId, $state, $extra);

            if ($state === 'open') {
                $instModel->clearQrCode($instanceId);
            }
            break;

        // ── QR Code atualizado ───────────────────────────────────────────
        case 'qrcode.updated':
            $qr = $data['qrcode']['base64'] ?? ($data['base64'] ?? ($data['code'] ?? ''));
            if ($qr) {
                if (!str_starts_with($qr, 'data:')) {
                    $qr = 'data:image/png;base64,' . $qr;
                }
                $instModel->updateQrCode($instanceId, $qr);
            }
            break;

        // ── Atualização de contato ───────────────────────────────────────
        case 'contacts.update':
        case 'contacts.upsert':
            // Atualiza nome de exibição nos chats
            $contacts = $data;
            if (isset($data['id'])) $contacts = [$data];
            $pdo = Database::getConnection();
            foreach ($contacts as $c) {
                $jid  = $c['id'] ?? null;
                $name = $c['pushName'] ?? ($c['name'] ?? null);
                if ($jid && $name) {
                    $s = $pdo->prepare(
                        'UPDATE whatsapp_chats SET contact_name = ?
                         WHERE instance_id = ? AND remote_jid = ?'
                    );
                    $s->execute([$name, $instanceId, $jid]);
                }
            }
            break;

        // ── Chats upsert (sincronização inicial) ─────────────────────────
        case 'chats.upsert':
        case 'chats.update':
            $chats = $data;
            if (isset($data['id'])) $chats = [$data];
            $pdo = Database::getConnection();
            foreach ($chats as $c) {
                $jid      = $c['id']       ?? null;
                $name     = $c['name']     ?? null;
                $unread   = (int)($c['unreadCount'] ?? 0);
                $isGroup  = str_ends_with((string)$jid, '@g.us') ? 1 : 0;
                if (!$jid) continue;
                $s = $pdo->prepare(
                    'INSERT INTO whatsapp_chats (instance_id, remote_jid, contact_name, unread_count, is_group)
                     VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       contact_name = IF(VALUES(contact_name) IS NOT NULL, VALUES(contact_name), contact_name),
                       unread_count = VALUES(unread_count)'
                );
                $s->execute([$instanceId, $jid, $name, $unread, $isGroup]);
            }
            break;
    }

    echo json_encode(['ok' => true, 'event' => $event]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno', 'msg' => $e->getMessage()]);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function handleMessageUpsert(array $msg, int $instanceId, WhatsAppMessage $model): void
{
    $key       = $msg['key']       ?? [];
    $message   = $msg['message']   ?? [];
    $wamid     = $key['id']        ?? null;
    $remoteJid = $key['remoteJid'] ?? null;
    $fromMe    = (bool)($key['fromMe'] ?? false);

    if (!$remoteJid) return;

    $pushName  = $msg['pushName']          ?? null;
    $ts        = $msg['messageTimestamp']  ?? time();
    $createdAt = date('Y-m-d H:i:s', is_numeric($ts) ? (int)$ts : time());

    [$msgType, $msgContent, $caption, $mediaUrl, $mimetype, $filename] =
        extractMessageContent($message);

    // Para mídias: tenta baixar base64 completo via Evolution (mídia ainda está em cache local)
    $mediaBase64 = null;
    $isMediaType = in_array($msgType, ['image', 'video', 'audio', 'sticker', 'document']);
    if ($isMediaType) {
        try {
            $instModel = new WhatsAppInstance();
            $cfg       = $instModel->getSettings();
            $evo       = new EvolutionApiService($cfg);
            $name      = $cfg['evolution_instance'] ?? 'yuris-crm';
            $b64 = $evo->getMediaBase64($name, $msg);
            if ($b64) {
                $mediaBase64 = str_contains($b64, ',') ? explode(',', $b64, 2)[1] : $b64;
            }
        } catch (\Throwable $_) {}

        // Fallback: thumbnail embarcado (jpegThumbnail)
        if (!$mediaBase64) {
            $subMap = ['image'=>'imageMessage','video'=>'videoMessage','sticker'=>'stickerMessage','document'=>'documentMessage'];
            $subKey = $subMap[$msgType] ?? null;
            if ($subKey) {
                $thumb = $message[$subKey]['jpegThumbnail'] ?? null;
                if ($thumb) {
                    $mediaBase64 = str_contains($thumb, ',') ? explode(',', $thumb, 2)[1] : $thumb;
                }
            }
        }
    }

    $phone = preg_replace('/[^0-9]/', '', explode('@', $remoteJid)[0]);

    $savedId = $model->save([
        'instance_id'     => $instanceId,
        'wamid'           => $wamid,
        'remote_jid'      => $remoteJid,
        'contact_name'    => $pushName,
        'phone'           => $phone,
        'message_type'    => $msgType,
        'message_content' => $msgContent,
        'caption'         => $caption,
        'media_url'       => $mediaUrl,
        'media_mimetype'  => $mimetype,
        'media_filename'  => $filename,
        'media_base64'    => $mediaBase64,
        'direction'       => $fromMe ? 'outbound' : 'inbound',
        'status'          => $fromMe ? 'sent' : 'delivered',
        'raw_payload'     => json_encode($msg),
        'created_at'      => $createdAt,
    ]);

    // Fire webhook only for inbound messages (received from contacts)
    if (!$fromMe) {
        \App\Services\WebhookDispatcher::fire('whatsapp.mensagem', \App\Services\WebhookDispatcher::buildPayload('whatsapp.mensagem', [
            'entity'    => 'whatsapp_message',
            'entity_id' => $savedId,
            'data' => [
                'wamid'        => $wamid,
                'remote_jid'   => $remoteJid,
                'phone'        => $phone,
                'contact_name' => $pushName,
                'message_type' => $msgType,
                'content'      => $msgContent,
                'caption'      => $caption,
                'created_at'   => $createdAt,
            ],
        ]));
    }
}

function extractMessageContent(array $message): array
{
    // text
    if (!empty($message['conversation'])) {
        return ['text', $message['conversation'], null, null, null, null];
    }
    if (!empty($message['extendedTextMessage']['text'])) {
        return ['text', $message['extendedTextMessage']['text'], null, null, null, null];
    }
    // image
    if (!empty($message['imageMessage'])) {
        $m = $message['imageMessage'];
        return ['image', null, $m['caption'] ?? null, $m['url'] ?? null, $m['mimetype'] ?? 'image/jpeg', null];
    }
    // video
    if (!empty($message['videoMessage'])) {
        $m = $message['videoMessage'];
        return ['video', null, $m['caption'] ?? null, $m['url'] ?? null, $m['mimetype'] ?? 'video/mp4', null];
    }
    // document
    if (!empty($message['documentMessage'])) {
        $m = $message['documentMessage'];
        return ['document', null, $m['caption'] ?? null, $m['url'] ?? null, $m['mimetype'] ?? null, $m['fileName'] ?? null];
    }
    if (!empty($message['documentWithCaptionMessage']['message']['documentMessage'])) {
        $m = $message['documentWithCaptionMessage']['message']['documentMessage'];
        return ['document', null, $m['caption'] ?? null, $m['url'] ?? null, $m['mimetype'] ?? null, $m['fileName'] ?? null];
    }
    // audio
    if (!empty($message['audioMessage'])) {
        $m = $message['audioMessage'];
        return ['audio', null, null, $m['url'] ?? null, $m['mimetype'] ?? 'audio/ogg', null];
    }
    // sticker
    if (!empty($message['stickerMessage'])) {
        return ['sticker', null, null, $message['stickerMessage']['url'] ?? null, 'image/webp', null];
    }
    // reaction
    if (!empty($message['reactionMessage'])) {
        return ['reaction', $message['reactionMessage']['text'] ?? '👍', null, null, null, null];
    }

    return ['text', null, null, null, null, null];
}
