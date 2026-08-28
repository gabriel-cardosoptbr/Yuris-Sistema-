<?php
/**
 * /api/master/lgpd_request_attachments.php — CRUD de anexos de solicitação LGPD.
 *
 * Endpoints:
 *   GET  ?request_id=N            → lista anexos da solicitação
 *   GET  ?download=ID             → serve arquivo (autenticado, autorizado)
 *   POST                          → upload (multipart/form-data) com campos:
 *                                   request_id, categoria, descricao?, visibilidade?
 *                                   + file (anexo)
 *   PATCH                         → atualiza meta (visibilidade/descricao)
 *                                   body: { id, visibilidade?, descricao? }
 *
 * Acesso: super_admin + master_mode.
 * Allowlist MIME validada via finfo_file.
 * Limite: 25 MB por arquivo.
 *
 * Storage: storage/lgpd_requests/<request_id>/<sha256>.<ext>
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;
use App\Lgpd\LgpdRequest;
use App\Lgpd\LgpdRequestAttachment;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = $ctx->getUserId();

// Allowlist MIME para anexos LGPD (mais restritiva que upload geral)
const LGPD_ATT_ALLOWED_MIME = [
    'application/pdf',
    'application/zip',
    'application/x-zip-compressed',
    'application/json',
    'image/jpeg', 'image/png',
    'text/plain',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
const LGPD_ATT_MAX_SIZE = 25 * 1024 * 1024; // 25 MB

// ─── GET ?download=ID — serve arquivo via PHP ────────────────────────────────
if ($method === 'GET' && !empty($_GET['download'])) {
    $attId = (int)$_GET['download'];
    $att = LgpdRequestAttachment::findById($attId);
    if (!$att) ApiResponse::notFound('Anexo não encontrado.');

    $storageRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage';
    $fullPath = realpath($storageRoot . DIRECTORY_SEPARATOR . ltrim(str_replace('storage' . DIRECTORY_SEPARATOR, '', $att['caminho_arquivo']), '/\\'));
    // Tentativa simples: o caminho gravado pode ter sido relativo a sistema_vendas/
    if (!$fullPath || !file_exists($fullPath)) {
        // Fallback: tenta path absoluto a partir de sistema_vendas/
        $fullPath = realpath(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $att['caminho_arquivo']);
    }
    if (!$fullPath || strpos($fullPath, $storageRoot) !== 0 || !file_exists($fullPath)) {
        ApiResponse::notFound('Arquivo físico não encontrado.');
    }
    // Confirma integridade via SHA-256
    if (hash_file('sha256', $fullPath) !== $att['sha256']) {
        MasterAudit::log('lgpd.attachment_integrity_fail', 'lgpd_request_attachment', $attId,
            'Falha de integridade SHA-256 ao baixar anexo', ['esperado' => $att['sha256']]);
        ApiResponse::serverError('Falha de integridade do arquivo.');
    }

    MasterAudit::log('lgpd.attachment_download', 'lgpd_request_attachment', $attId,
        'Anexo baixado', ['request_id' => $att['request_id']]);

    while (ob_get_level() > 0) ob_end_clean();
    header_remove('Cache-Control');
    header('Content-Type: ' . $att['tipo_arquivo']);
    header('Content-Disposition: attachment; filename="' . $att['nome_arquivo'] . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
}

// ─── GET ?request_id=N — lista anexos ────────────────────────────────────────
if ($method === 'GET') {
    $reqId = (int)($_GET['request_id'] ?? 0);
    if (!$reqId) ApiResponse::badRequest('request_id obrigatório');
    if (!LgpdRequest::findById($reqId)) ApiResponse::notFound();

    $list = LgpdRequestAttachment::listByRequest($reqId);
    ApiResponse::ok($list);
}

// ─── CSRF para mutações ──────────────────────────────────────────────────────
$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true) ?? $_POST;
$csrf     = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    ApiResponse::badRequest('CSRF inválido');
}

// ─── PATCH — atualiza meta (visibilidade/descricao) ─────────────────────────
if ($method === 'PATCH') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');
    $att = LgpdRequestAttachment::findById($id);
    if (!$att) ApiResponse::notFound();

    try {
        $ok = LgpdRequestAttachment::updateMeta(
            $id,
            $input['visibilidade'] ?? null,
            $input['descricao']    ?? null
        );
    } catch (\InvalidArgumentException $e) {
        ApiResponse::badRequest($e->getMessage());
    }

    if ($ok) {
        MasterAudit::log('lgpd.attachment_update', 'lgpd_request_attachment', $id,
            'Anexo atualizado (visibilidade/descricao)',
            ['campos' => array_keys($input)]);
    }
    ApiResponse::ok(['updated' => $ok]);
}

// ─── POST — upload (multipart/form-data) ─────────────────────────────────────
if ($method === 'POST') {
    $reqId     = (int)($_POST['request_id'] ?? 0);
    $categoria = trim((string)($_POST['categoria'] ?? ''));
    $descricao = trim((string)($_POST['descricao']    ?? ''));
    $visib     = (string)($_POST['visibilidade'] ?? 'interno');

    if (!$reqId) ApiResponse::badRequest('request_id obrigatório');
    if (!LgpdRequest::findById($reqId)) ApiResponse::notFound('Solicitação não encontrada.');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        ApiResponse::badRequest('Arquivo não enviado ou erro no upload.');
    }
    $file = $_FILES['file'];

    if ($file['size'] > LGPD_ATT_MAX_SIZE) {
        ApiResponse::badRequest('Arquivo excede 25 MB.');
    }

    // Valida MIME via finfo (não confia em $_FILES['type'])
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);
    if (!in_array($realMime, LGPD_ATT_ALLOWED_MIME, true)) {
        ApiResponse::badRequest("Tipo de arquivo não permitido: $realMime");
    }

    $sha256 = hash_file('sha256', $file['tmp_name']);
    if (!$sha256) ApiResponse::serverError('Falha ao calcular hash.');

    // Determina extensão segura
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';

    // Cria diretório seguro
    $dir = LgpdRequestAttachment::ensureRequestDir($reqId);
    $destFilename = $sha256 . '.' . $ext;
    $destPath = $dir . DIRECTORY_SEPARATOR . $destFilename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        ApiResponse::serverError('Falha ao salvar arquivo.');
    }

    // Path relativo armazenado no banco (a partir de sistema_vendas/)
    $relPath = 'storage' . DIRECTORY_SEPARATOR . 'lgpd_requests' .
               DIRECTORY_SEPARATOR . $reqId . DIRECTORY_SEPARATOR . $destFilename;

    try {
        $attId = LgpdRequestAttachment::record(
            $reqId,
            $file['name'],     // nome original
            $relPath,
            $realMime,
            (int)$file['size'],
            $sha256,
            $categoria,
            $userId,
            $descricao ?: null,
            $visib
        );
    } catch (\InvalidArgumentException $e) {
        @unlink($destPath);
        ApiResponse::badRequest($e->getMessage());
    }

    LgpdRequest::addEvent($reqId, 'anexo_adicionado',
        "Anexo '{$file['name']}' adicionado (categoria={$categoria})", $userId);

    MasterAudit::log('lgpd.attachment_create', 'lgpd_request_attachment', $attId,
        "Anexo enviado: {$file['name']}",
        ['request_id' => $reqId, 'categoria' => $categoria, 'mime' => $realMime, 'size' => $file['size']]);

    ApiResponse::ok([
        'id'           => $attId,
        'nome_arquivo' => $file['name'],
        'sha256'       => $sha256,
        'categoria'    => $categoria,
        'tamanho'      => (int)$file['size'],
    ]);
}

ApiResponse::methodNotAllowed();
