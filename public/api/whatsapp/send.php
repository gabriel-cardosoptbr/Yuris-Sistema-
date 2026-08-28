<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;

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

// ─── Allowlist de MIME para mídia (auditoria 2026-06-01 MEDIA #38) ───────────
// Mantida em sincronia com a de public/api/whatsapp/media_upload.php. Usada para
// revalidar, via finfo, o conteúdo real do base64 antes de enviar à Evolution.
if (!defined('WA_ALLOWED_MIME')) {
    define('WA_ALLOWED_MIME', [
        // imagens
        'image/jpeg','image/png','image/gif','image/webp',
        // áudio (incluindo formatos do WhatsApp)
        'audio/ogg','audio/mpeg','audio/mp4','audio/aac','audio/wav','audio/x-wav','audio/webm',
        // vídeo
        'video/mp4','video/quicktime','video/webm','video/x-msvideo','video/3gpp',
        // documentos
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain','text/csv',
        'application/zip','application/x-zip-compressed',
    ]);
}

// P0 LGPD (1.8): contexto de tenant — settings/instance now per-tenant
$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();

// Auditoria 2026-06-01 MEDIA #32: todo o fluxo (DB + Evolution) roda dentro de
// try/catch. Antes nao havia guarda: uma exceção (PDO/Evolution) escapava como
// fatal/warning, podendo vazar caminho/SQL ao cliente. Agora vai pro ErrorReporter
// (loga server-side, devolve generico em prod).
try {

$instModel  = new WhatsAppInstance();
$msgModel   = new WhatsAppMessage();
$pdo        = \App\Core\Database::getConnection();

// Envio = permissão 'send' no canal (deny-by-default). Resolve o canal próprio ou,
// com a flag ligada, o canal COMPARTILHADO (filial herdando o da matriz). A
// instância e as credenciais são SEMPRE resolvidas no backend, a partir do DONO
// do canal — nunca do front (instance_name/account_id/channel_id não confiáveis).
$ch         = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $payload['channel_id'] ?? null, 'send');
$cfg        = $ch['cfg'];
$instName   = $ch['instance_name'];
$instanceId = (int)$ch['channel_id'];

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

// ─── Auditoria 2026-06-01 MEDIA #38: validação de mídia server-side ──────────
// media_upload.php (multipart) já validava MIME via finfo contra uma allowlist,
// mas o fluxo real do chat manda o base64 direto pra cá (API.send) e este
// endpoint NUNCA revalidava — confiava no `type`/`mimetype` do cliente (forjável).
// Plugamos a MESMA allowlist aqui, no caminho que de fato é usado, decodificando
// o base64 e conferindo os magic bytes do conteúdo real. Fecha a brecha e dá
// propósito à lógica de validação antes órfã em media_upload.php.
if ($type !== 'text') {
    if ($mediaData === '') {
        http_response_code(400); echo json_encode(['error' => 'mídia obrigatória para envio não-texto']); exit;
    }
    // Decodifica (removendo prefixo data:...;base64, se presente) para inspecionar bytes.
    $rawB64 = str_contains($mediaData, ',') ? explode(',', $mediaData, 2)[1] : $mediaData;
    $bin    = base64_decode($rawB64, true);
    if ($bin === false || $bin === '') {
        http_response_code(400); echo json_encode(['error' => 'mídia inválida (base64)']); exit;
    }
    $maxSize = 64 * 1024 * 1024; // 64 MB — mesmo limite do media_upload.php
    if (strlen($bin) > $maxSize) {
        http_response_code(400); echo json_encode(['error' => 'Arquivo muito grande (máx 64 MB)']); exit;
    }
    // finfo lê o MIME real do conteúdo (magic bytes), não o que o cliente declarou.
    $detected = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) { $detected = finfo_buffer($finfo, $bin) ?: null; finfo_close($finfo); }
    }
    // Normaliza variações de container que o finfo reporta de forma genérica
    // (ex.: áudio gravado no navegador como Opus-in-Ogg vem como application/ogg;
    //  alguns ambientes reportam audio/x-wav). Sem isto, mensagens de voz legítimas
    //  seriam bloqueadas — regressão. NÃO afrouxa exe/php/html (continuam fora).
    $detected = strtolower(trim(explode(';', (string)$detected)[0]));
    $aliases  = [
        'application/ogg' => 'audio/ogg',
        'audio/x-hx-aac-adts' => 'audio/aac',
        'video/x-m4v' => 'video/mp4',
    ];
    if (isset($aliases[$detected])) $detected = $aliases[$detected];

    // Estratégia de decisão (à prova de regressão):
    //  1) finfo detectou e está na allowlist → usa o detectado (confiável).
    //  2) finfo falhou/indisponível OU devolveu genérico (octet-stream) →
    //     aceita o mimetype DECLARADO se ele estiver na allowlist.
    //  3) caso contrário → bloqueia.
    $declared = strtolower(trim(explode(';', (string)$mimetype)[0]));
    if ($detected && in_array($detected, WA_ALLOWED_MIME, true)) {
        $mimetype = $detected;
    } elseif (($detected === '' || $detected === 'application/octet-stream')
              && in_array($declared, WA_ALLOWED_MIME, true)) {
        $mimetype = $declared;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de arquivo não permitido']);
        exit;
    }
}

// ── Resolve estrutura completa do quoted (se fornecido) ────────────────────
// A Evolution API v2 precisa de mais do que o wamid pra montar o reply visível
// no celular: precisa de key.{remoteJid,fromMe,participant?} + message.conversation.
// Buscamos a mensagem original no DB pra montar a estrutura corretamente.
$quotedStruct = null;
if ($quotedId) {
    $pdo = \App\Core\Database::getConnection();
    $st = $pdo->prepare(
        'SELECT wamid, remote_jid, participant_jid, direction, message_content, caption, message_type
           FROM whatsapp_messages
          WHERE instance_id = ? AND wamid = ? LIMIT 1'
    );
    $st->execute([$instanceId, $quotedId]);
    $q = $st->fetch(\PDO::FETCH_ASSOC);
    if ($q) {
        $quotedStruct = [
            'id'          => $q['wamid'],
            'fromMe'      => ($q['direction'] === 'outbound'),
            'remoteJid'   => $q['remote_jid'],
            'participant' => $q['participant_jid'] ?: null,
            'content'     => $q['message_content'] ?: ($q['caption'] ?: ''),
        ];
    } else {
        // Fallback: só o ID — pode não renderizar no celular mas o quoted_wamid
        // fica gravado no DB pra exibir o preview na nossa UI.
        $quotedStruct = ['id' => $quotedId];
    }
}

// ── Envio via Evolution API ─────────────────────────────────────────────────
$res = match ($type) {
    'text'  => $evo->sendText($instName, $remoteJid, $text, $quotedStruct),
    'audio' => $evo->sendAudio($instName, $remoteJid, $mediaData),
    default => $evo->sendMedia($instName, $remoteJid, $type, $mediaData, $caption, $filename, $mimetype),
};

// Erros de rede/Evolution: o detalhe (curl_error, mensagem crua da API) pode
// vazar host/IP/path interno. Loga server-side e devolve mensagem GENERICA ao
// cliente (auditoria 2026-06-19 Fase 2 Parte E).
if (!empty($res['_error'])) {
    error_log('[whatsapp/send] curl/_error inst=' . $instName . ': ' . $res['_error']);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Falha ao enviar a mensagem. Tente novamente.']);
    exit;
}

if (($res['_http'] ?? 0) >= 400) {
    error_log('[whatsapp/send] Evolution http=' . ($res['_http'] ?? 0) . ' inst=' . $instName
        . ' msg=' . substr((string)($res['message'] ?? ($res['error'] ?? '')), 0, 300));
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Falha ao enviar a mensagem. Tente novamente.']);
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

// created_at do outbound: prefere o messageTimestamp da Evolution (mesmo relogio do
// inbound). Sem isso, se o relogio do servidor estiver adiantado, a msg enviada ficava
// "no futuro" e empurrava o cursor do poll a frente, escondendo a proxima msg do cliente
// ate sair/reentrar na conversa. Fallback: hora local.
$evoTs     = $res['messageTimestamp'] ?? null;
$createdAt = (is_numeric($evoTs) && (int)$evoTs > 0) ? date('Y-m-d H:i:s', (int)$evoTs) : date('Y-m-d H:i:s');

$msgId = $msgModel->save([
    // O3: account_id explícito do dono do canal (antes o save() resolvia via 2 SELECTs
    // extras a cada envio). $ch já traz o dono resolvido no backend.
    'account_id'      => (int)($ch['owner_account_id'] ?? 0) ?: null,
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
    'created_at'      => $createdAt,
]);

// 4B (cursor de eventos): novidade no canal -> outros atendentes/abas veem o envio
// sem esperar o proximo ciclo cheio. Best-effort (nunca derruba o envio).
$instModel->bumpEvents($instanceId);

echo json_encode([
    'ok'         => true,
    'message_id' => $msgId,
    'wamid'      => $wamid,
]);

} catch (\Throwable $e) {
    // Auditoria 2026-06-01 MEDIA #32: loga server-side e devolve generico
    // (nunca expõe $e->getMessage() em prod).
    require_once __DIR__ . '/../../../app/Core/ErrorReporter.php';
    \App\Core\ErrorReporter::handle($e);
}
