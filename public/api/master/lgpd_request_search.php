<?php
/**
 * /api/master/lgpd_request_search.php — dispara busca multi-módulo numa
 * solicitação LGPD.
 *
 * Quem chama: Painel Master → modal LGPD → botão "Buscar dados deste titular".
 *
 * POST  body: { csrf_token, id, cpf?, telefone? }
 *   → executa Anonymizer::searchAllModules(id, email, cpf, tel, userId)
 *   → popula lgpd_request_modules + lgpd_request_findings
 *   → retorna sumário { modulo => total }
 *
 * Acesso: super_admin + master_mode.
 * Toda chamada registrada em master_audit_log.
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/Lgpd/LgpdRequest.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ApiResponse.php';
require_once __DIR__ . '/../../../app/Lgpd/Anonymizer.php';
require_once __DIR__ . '/../../../app/Master/MasterAudit.php';
require_once __DIR__ . '/../../../app/Core/RequestId.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Lgpd\Anonymizer;
use App\Master\MasterAudit;
use App\Lgpd\LgpdRequest;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::methodNotAllowed();
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    ApiResponse::badRequest('CSRF inválido');
}

$reqId = (int)($input['id'] ?? 0);
if (!$reqId) ApiResponse::badRequest('id obrigatório');

$req = LgpdRequest::findById($reqId);
if (!$req) ApiResponse::notFound('Solicitação não encontrada.');

$email = strtolower(trim((string)$req['titular_email']));
$cpf   = trim((string)($input['cpf']      ?? $req['titular_cpf']      ?? ''));
$tel   = trim((string)($input['telefone'] ?? $req['titular_telefone'] ?? ''));
$userId = $ctx->getUserId();

try {
    $totals = Anonymizer::searchAllModules(
        $reqId, $email, $cpf ?: null, $tel ?: null, $userId
    );
} catch (\Throwable $e) {
    error_log('[lgpd_request_search] ' . $e->getMessage());
    ApiResponse::serverError('Falha ao buscar: ' . $e->getMessage());
}

MasterAudit::log('lgpd.search_all_modules', 'lgpd_request', $reqId,
    'Busca multi-módulo executada para titular',
    ['totals' => $totals, 'cpf_informado' => $cpf !== '', 'tel_informado' => $tel !== '']);

ApiResponse::ok([
    'request_id'   => $reqId,
    'totals'       => $totals,
    'total_geral'  => array_sum($totals),
    'modulos_pesquisados' => array_keys($totals),
]);
