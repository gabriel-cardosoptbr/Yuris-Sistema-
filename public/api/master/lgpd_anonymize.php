<?php
/**
 * /api/master/lgpd_anonymize.php — disparo manual do Anonymizer pelo DPO.
 *
 * POST  body: { lgpd_request_id, entidade, entidade_id, motivo? }
 *        → executa Anonymizer::<entidade>(<id>, motivo, userId, lgpd_request_id)
 *
 * POST ?action=export body: { lgpd_request_id, email }
 *        → gera ZIP de portabilidade. Retorna URL relativa pra download.
 *
 * GET  ?action=download&file=<basename> → serve ZIP via PHP (autenticado)
 *
 * Acesso: super_admin com master_mode.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/Anonymizer.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';
require_once __DIR__ . '/../../../app/Models/LgpdRequest.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\Anonymizer;
use App\Helpers\MasterAudit;
use App\Models\LgpdRequest;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = $ctx->getUserId();

// ─── GET ?action=download — serve ZIP via PHP (autenticado) ─────────────────
if ($method === 'GET' && ($_GET['action'] ?? null) === 'download') {
    $file = basename((string)($_GET['file'] ?? ''));
    if ($file === '' || !preg_match('/^export_[a-z0-9_]+\.zip$/i', $file)) {
        ApiResponse::badRequest('Arquivo inválido');
    }
    $storageDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'lgpd_exports';
    $path = realpath($storageDir . DIRECTORY_SEPARATOR . $file);
    if (!$path || strpos($path, $storageDir) !== 0 || !file_exists($path)) {
        ApiResponse::notFound('Arquivo não encontrado');
    }
    while (ob_get_level() > 0) ob_end_clean();
    header_remove('Cache-Control');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ─── CSRF para mutações ────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    ApiResponse::badRequest('CSRF inválido');
}

// ─── POST ?action=export — gera ZIP de portabilidade ───────────────────────
if ($method === 'POST' && ($_GET['action'] ?? null) === 'export') {
    $reqId = (int)($input['lgpd_request_id'] ?? 0);
    $email = trim((string)($input['email'] ?? ''));
    if ($email === '') ApiResponse::badRequest('email obrigatório');

    try {
        $path = Anonymizer::exportTitular($email, $reqId ?: null);
    } catch (\Throwable $e) {
        ApiResponse::serverError('Falha ao exportar: ' . $e->getMessage());
    }

    $basename = basename($path);
    $downloadUrl = '/sistema_vendas/public/api/master/lgpd_anonymize.php?action=download&file=' . urlencode($basename);

    // Anexa o path na solicitação
    if ($reqId) {
        LgpdRequest::update($reqId, ['arquivo_resposta_path' => $basename], $userId);
        LgpdRequest::addEvent($reqId, 'exportado', "Export ZIP gerado: {$basename}", $userId);
    }
    MasterAudit::log('lgpd.export_titular', 'lgpd_request', $reqId, 'Export de portabilidade gerado', [
        'email' => $email, 'file' => $basename,
    ]);

    ApiResponse::ok([
        'file'         => $basename,
        'download_url' => $downloadUrl,
        'size_bytes'   => filesize($path),
    ]);
}

// ─── POST — anonimiza ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $reqId      = (int)($input['lgpd_request_id'] ?? 0);
    $entidade   = strtolower(trim((string)($input['entidade']     ?? '')));
    $entidadeId = (int)($input['entidade_id'] ?? 0);
    $motivo     = trim((string)($input['motivo'] ?? ($reqId ? "Solicitação LGPD #{$reqId}" : 'Anonimização manual')));

    if (!$entidadeId) ApiResponse::badRequest('entidade_id obrigatório');

    $ok = false;
    switch ($entidade) {
        case 'user':
        case 'usuario':
            $ok = Anonymizer::user($entidadeId, $motivo, $userId, $reqId ?: null);
            break;
        case 'contato':
            $ok = Anonymizer::contato($entidadeId, $motivo, $userId, $reqId ?: null);
            break;
        case 'card':
            $ok = Anonymizer::card($entidadeId, $motivo, $userId, $reqId ?: null);
            break;
        case 'processo':
        case 'processoparte':
            $ok = Anonymizer::processoParte($entidadeId, $motivo, $userId, $reqId ?: null);
            break;
        default:
            ApiResponse::badRequest('entidade desconhecida (use user|contato|card|processo)');
    }
    if (!$ok) ApiResponse::notFound("{$entidade} #{$entidadeId} não encontrado");

    if ($reqId) {
        LgpdRequest::addEvent($reqId, 'anonimizado',
            "Anonimizado: {$entidade} #{$entidadeId}", $userId);
    }
    MasterAudit::log('lgpd.anonimizado', $entidade, $entidadeId, "Anonimização executada", [
        'motivo' => $motivo, 'lgpd_request_id' => $reqId,
    ]);
    ApiResponse::ok(['anonimizado' => true, 'entidade' => $entidade, 'id' => $entidadeId]);
}

ApiResponse::methodNotAllowed();
