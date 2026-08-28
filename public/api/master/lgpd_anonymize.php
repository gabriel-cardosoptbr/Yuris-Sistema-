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
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Lgpd\Anonymizer;
use App\Master\MasterAudit;
use App\Lgpd\LgpdRequest;
use App\Lgpd\LgpdRequestRetentionJustification;

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

// ─── POST ?action=preview_impact — preview antes de anonimizar ──────────────
// Retorna estimativa de quantos registros vinculados seriam afetados se a
// entidade for anonimizada. Não modifica nada.
if ($method === 'POST' && ($_GET['action'] ?? null) === 'preview_impact') {
    $entidade   = strtolower(trim((string)($input['entidade']    ?? '')));
    $entidadeId = (int)($input['entidade_id'] ?? 0);
    if (!$entidadeId) ApiResponse::badRequest('entidade_id obrigatório');

    $pdo = \App\Core\Database::getConnection();
    $impact = ['entidade' => $entidade, 'entidade_id' => $entidadeId, 'vinculos' => []];

    switch ($entidade) {
        case 'user':
        case 'usuario':
            // Conta processos onde é responsável + cards + tasks
            $impact['vinculos']['processos_responsavel'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM processos WHERE responsavel_user_id = $entidadeId AND anonymized_at IS NULL"
            )->fetchColumn();
            $impact['vinculos']['cards_responsavel'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM cards WHERE responsavel_user_id = $entidadeId AND anonymized_at IS NULL"
            )->fetchColumn();
            $impact['vinculos']['mensagens_chat'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM chat_mensagens WHERE usuario_id = $entidadeId AND deleted_at IS NULL"
            )->fetchColumn();
            $impact['vinculos']['acoes_audit'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM account_audit_log WHERE user_id = $entidadeId"
            )->fetchColumn();
            $impact['nivel_risco'] = ($impact['vinculos']['processos_responsavel'] > 0)
                ? 'alto (processos ativos)' : 'medio';
            break;

        case 'contato':
            $impact['vinculos']['cards_vinculados'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM cards WHERE contato_id = $entidadeId AND anonymized_at IS NULL"
            )->fetchColumn();
            $impact['vinculos']['processos_vinculados'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM processos WHERE contato_id = $entidadeId AND anonymized_at IS NULL"
            )->fetchColumn();
            $impact['nivel_risco'] = ($impact['vinculos']['processos_vinculados'] > 0)
                ? 'alto (processos ativos)' : 'baixo';
            break;

        case 'card':
            $impact['vinculos']['processos_derivados'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM processos WHERE card_id = $entidadeId AND anonymized_at IS NULL"
            )->fetchColumn();
            $impact['nivel_risco'] = ($impact['vinculos']['processos_derivados'] > 0)
                ? 'alto (gerou processos)' : 'baixo';
            break;

        case 'processo':
        case 'processoparte':
            // Anonimizar parte_contraria não remove o processo
            $st = $pdo->prepare("SELECT status, data_inicio FROM processos WHERE id = ?");
            $st->execute([$entidadeId]);
            $p = $st->fetch(\PDO::FETCH_ASSOC);
            $impact['processo_status'] = $p['status'] ?? '?';
            $impact['processo_data_inicio'] = $p['data_inicio'] ?? '?';
            $impact['nivel_risco'] = (($p['status'] ?? '') === 'ativo')
                ? 'CRITICO (processo ativo - exercicio de direito Art. 7 VI)'
                : 'medio';
            break;

        default:
            ApiResponse::badRequest('entidade desconhecida');
    }

    // Verifica se há justificativa de retenção REGISTRADA para esta entidade
    $reqId = (int)($input['lgpd_request_id'] ?? 0);
    if ($reqId) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM lgpd_request_retention_justifications
             WHERE request_id = ? AND entidade = ? AND entidade_id = ?"
        );
        $st->execute([$reqId, $entidade, $entidadeId]);
        $impact['ja_tem_retencao_registrada'] = ((int)$st->fetchColumn() > 0);
    }

    ApiResponse::ok($impact);
}

// ─── POST ?action=register_retention — registra justificativa Art. 7 ────────
if ($method === 'POST' && ($_GET['action'] ?? null) === 'register_retention') {
    $reqId      = (int)($input['lgpd_request_id'] ?? 0);
    $entidade   = trim((string)($input['entidade']    ?? ''));
    $entidadeId = (int)($input['entidade_id']   ?? 0);
    $baseLegal  = trim((string)($input['base_legal_retencao'] ?? ''));
    $just       = trim((string)($input['justificativa'] ?? ''));
    $fund       = trim((string)($input['fundamentacao_juridica'] ?? ''));
    $prazo      = trim((string)($input['prazo_retencao_ate'] ?? ''));
    $findingId  = (int)($input['finding_id'] ?? 0);

    if (!$reqId || !$entidade || !$entidadeId || !$baseLegal || !$just) {
        ApiResponse::badRequest('lgpd_request_id, entidade, entidade_id, base_legal_retencao e justificativa são obrigatórios');
    }
    if (!LgpdRequest::findById($reqId)) ApiResponse::notFound('Solicitação não encontrada.');

    try {
        $jId = LgpdRequestRetentionJustification::create(
            $reqId, $entidade, $entidadeId, $baseLegal, $just, $userId,
            $findingId ?: null,
            $prazo ?: null,
            $fund ?: null
        );
    } catch (\InvalidArgumentException $e) {
        ApiResponse::badRequest($e->getMessage());
    }

    LgpdRequest::addEvent($reqId, 'retencao_registrada',
        "Retencao registrada para {$entidade}#{$entidadeId} - base: {$baseLegal}",
        $userId);
    MasterAudit::log('lgpd.retention_register', 'lgpd_request', $reqId,
        "Justificativa de retenção registrada",
        ['entidade' => $entidade, 'entidade_id' => $entidadeId, 'base_legal' => $baseLegal]);

    ApiResponse::ok(['id' => $jId, 'registered' => true]);
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
    $downloadUrl = '/api/master/lgpd_anonymize.php?action=download&file=' . urlencode($basename);

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
        case 'cliente':
            $ok = Anonymizer::cliente($entidadeId, $motivo, $userId, $reqId ?: null);
            break;
        case 'processo':
        case 'processoparte':
            $ok = Anonymizer::processoParte($entidadeId, $motivo, $userId, $reqId ?: null);
            break;
        default:
            ApiResponse::badRequest('entidade desconhecida (use user|contato|card|cliente|processo)');
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
