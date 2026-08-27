<?php
/**
 * /api/master/lgpd_retention_approve.php — aprovação 4-eyes de justificativa
 * de retenção (LGPD Art. 7/16).
 *
 * Princípio 4-eyes (segregação de funções): quem CRIOU a justificativa não
 * pode aprová-la. Bloqueio implementado no Model via LogicException.
 *
 * Trigger SQL permite UPDATE em `lgpd_request_retention_justifications`
 * APENAS nos campos aprovado_por_user_id + aprovado_em. Qualquer outra
 * mudança dispara SIGNAL 45000.
 *
 * POST body: { csrf_token, justification_id }
 *
 * Acesso: super_admin com master_mode.
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/Lgpd/LgpdRequest.php';
require_once __DIR__ . '/../../../app/Lgpd/LgpdRequestRetentionJustification.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ApiResponse.php';
require_once __DIR__ . '/../../../app/Master/MasterAudit.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;
use App\Lgpd\LgpdRequest;
use App\Lgpd\LgpdRequestRetentionJustification;

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

$justId = (int)($input['justification_id'] ?? 0);
if (!$justId) ApiResponse::badRequest('justification_id obrigatório');

$userId = $ctx->getUserId();

// Carrega dados pra audit e evento
$pdo = \App\Core\Database::getConnection();
$st = $pdo->prepare("SELECT request_id, entidade, entidade_id, base_legal_retencao,
                            responsavel_user_id, aprovado_em
                     FROM lgpd_request_retention_justifications WHERE id = ?");
$st->execute([$justId]);
$j = $st->fetch(\PDO::FETCH_ASSOC);
if (!$j) ApiResponse::notFound('Justificativa não encontrada.');

if (!empty($j['aprovado_em'])) {
    ApiResponse::badRequest('Justificativa já aprovada em ' . $j['aprovado_em']);
}

try {
    $ok = LgpdRequestRetentionJustification::approve($justId, $userId);
} catch (\LogicException $e) {
    // 4-eyes: quem criou não pode aprovar
    ApiResponse::forbidden($e->getMessage());
} catch (\Throwable $e) {
    error_log('[lgpd_retention_approve] ' . $e->getMessage());
    ApiResponse::serverError('Falha ao aprovar: ' . $e->getMessage());
}

if (!$ok) ApiResponse::serverError('UPDATE falhou (possivelmente bloqueado pelo trigger).');

// Evento + audit
LgpdRequest::addEvent((int)$j['request_id'], 'retencao_aprovada',
    "Justificativa #$justId aprovada (4-eyes): {$j['entidade']}#{$j['entidade_id']} " .
    "/ base={$j['base_legal_retencao']} / criada_por_user={$j['responsavel_user_id']}",
    $userId);

MasterAudit::log('lgpd.retention_approve', 'lgpd_request_retention_justification', $justId,
    "Retenção aprovada via 4-eyes",
    ['request_id' => (int)$j['request_id'], 'entidade' => $j['entidade'],
     'entidade_id' => (int)$j['entidade_id'], 'base_legal' => $j['base_legal_retencao'],
     'criador_user_id' => (int)$j['responsavel_user_id'], 'aprovador_user_id' => $userId]);

ApiResponse::ok([
    'approved' => true,
    'id' => $justId,
    'aprovado_em' => date('Y-m-d H:i:s'),
]);
