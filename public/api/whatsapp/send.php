<?php
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppMessage.php';
require_once __DIR__ . '/../../../app/Services/EvolutionApiService.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;

session_start(['read_and_close' => true]);
$_uid  = $_SESSION['user_id']    ?? null;
$_csrf = $_SESSION['csrf_token'] ?? '';
header('Content-Type: application/json; charset=utf-8');
if (!$_uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$csrf    = $_csrf;
$payload = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($payload['_csrf']) || $payload['_csrf'] !== $csrf) {
    http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit;
}

// P0 LGPD (1.8): contexto de tenant — settings/instance now per-tenant
$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();

$instModel  = new WhatsAppInstance();
$msgModel   = new WhatsAppMessage();
$cfg        = $instModel->getSettings($accountId);
$instName   = $cfg['evolution_instance'] ?? 'yuris-crm';
$row        = $instModel->findOrCreate($instName, '', $accountId);
$instanceId = (int)$row['id'];
$evo        = new EvolutionApiService($cfg);

$remoteJid  = $payload['remote_jid'] ?? '';
$type       = $payload['type']       ?? 'text'; // text | image | video | document | audio
$text       = $payload['text']       ?? '';
$mediaData  = $payload['media']      ?? '';     // base64
$caption    = $payload['caption']    ?? '';
$filename   = $payload['filename']   ?? '';
$mimetype   = $payload['mimetype']   ?? '';
$quotedId   = $payload['quoted_id']  ?? null;

if (!$remoteJid) {
    http_response_code(400); echo json_encode(['error' => 'remote_jid obrigatório']); exit;
}

// ── Envio via Evolution API ─────────────────────────────────────────────────
$res = match ($type) {
    'text'  => $evo->sendText($instName, $remoteJid, $text, $quotedId),
    'audio' => $evo->sendAudio($instName, $remoteJid, $mediaData),
    default => $evo->sendMedia($instName, $remoteJid, $type, $mediaData, $caption, $filename, $mimetype),
};

if (!empty($res['_error'])) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Falha ao enviar: ' . $res['_error']]);
    exit;
}

if (($res['_http'] ?? 0) >= 400) {
    $errMsg = $res['message'] ?? ($res['error'] ?? 'Erro desconhecido da API');
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $errMsg]);
    exit;
}

// ── Salvar no banco ─────────────────────────────────────────────────────────
$wamid = $res['key']['id'] ?? ($res['id'] ?? null);
$content = ($type === 'text') ? $text : $caption;

// Para mídia, salva o base64 imediatamente para o proxy funcionar
$saveBase64 = ($type !== 'text' && $mediaData) ? $mediaData : null;
// Remove prefixo data:mime;base64, se presente
if ($saveBase64 && str_contains($saveBase64, ',')) {
    $saveBase64 = explode(',', $saveBase64, 2)[1];
}

$msgId = $msgModel->save([
    'instance_id'     => $instanceId,
    'wamid'           => $wamid,
    'remote_jid'      => $remoteJid,
    'contact_name'    => null,
    'phone'           => preg_replace('/[^0-9]/', '', explode('@', $remoteJid)[0]),
    'message_type'    => $type,
    'message_content' => $content,
    'caption'         => ($type !== 'text') ? $caption : null,
    'media_base64'    => $saveBase64,
    'media_mimetype'  => $mimetype ?: null,
    'media_filename'  => $filename ?: null,
    'direction'       => 'outbound',
    'status'          => 'sent',
    'quoted_wamid'    => $quotedId,
    'created_at'      => date('Y-m-d H:i:s'),
]);

echo json_encode([
    'ok'         => true,
    'message_id' => $msgId,
    'wamid'      => $wamid,
]);
