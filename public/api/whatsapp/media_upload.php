<?php
/**
 * media_upload.php — recebe arquivo via multipart ou base64 JSON.
 * Retorna base64 para envio direto à Evolution API.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';

session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$csrf = $_SESSION['csrf_token'] ?? '';

// ─── LGPD P1 (2A.3): allowlist de MIME + validação via finfo ────────────────
// Antes desta correção, $file['type'] vinha do cliente (forjável) sem revalidar
// no servidor. Atacante podia subir .exe/.php/.html como "image/jpeg" e a Evolution
// receberia. Agora validamos com finfo_file (lê magic bytes do conteúdo real)
// e exigimos que esteja na allowlist. Limite 64 MB mantido.
const WA_ALLOWED_MIME = [
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
];
// ────────────────────────────────────────────────────────────────────────────

// ── Suporte a multipart/form-data (file upload real) ─────────────────────
if (!empty($_FILES['file'])) {
    $csrfPost = $_POST['_csrf'] ?? '';
    if ($csrfPost !== $csrf) {
        http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit;
    }

    $file     = $_FILES['file'];
    $error    = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($error !== UPLOAD_ERR_OK) {
        http_response_code(400); echo json_encode(['error' => 'Erro no upload: ' . $error]); exit;
    }

    $maxSize = 64 * 1024 * 1024; // 64 MB
    if ($file['size'] > $maxSize) {
        http_response_code(400); echo json_encode(['error' => 'Arquivo muito grande (máx 64 MB)']); exit;
    }

    // P1 LGPD: NÃO confia em $file['type'] do cliente — usa finfo_file (magic bytes)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimetype = $finfo ? finfo_file($finfo, $file['tmp_name']) : ($file['type'] ?? null);
    if ($finfo) finfo_close($finfo);
    if (!$mimetype) {
        http_response_code(400); echo json_encode(['error' => 'Tipo de arquivo não detectado']); exit;
    }
    if (!in_array($mimetype, WA_ALLOWED_MIME, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de arquivo não permitido: ' . $mimetype]);
        exit;
    }

    $filename = basename($file['name']);
    $content  = file_get_contents($file['tmp_name']);
    $base64   = base64_encode($content);

    echo json_encode([
        'ok'       => true,
        'base64'   => $base64,
        'mimetype' => $mimetype,
        'filename' => $filename,
        'size'     => $file['size'],
        'type'     => detectMediaType($mimetype),
    ]);
    exit;
}

// ── Fallback: nenhum arquivo ─────────────────────────────────────────────
http_response_code(400);
echo json_encode(['error' => 'Nenhum arquivo enviado']);

function detectMediaType(string $mime): string
{
    if (str_starts_with($mime, 'image/'))    return 'image';
    if (str_starts_with($mime, 'video/'))    return 'video';
    if (str_starts_with($mime, 'audio/'))    return 'audio';
    return 'document';
}
