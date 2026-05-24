<?php
/**
 * /api/master/reviews.php — Painel interno: pendências de revisão.
 *
 * USO EXCLUSIVO super_admin em sessão master_mode.
 *
 * Endpoints:
 *   GET                              → lista (filtros: status, categoria, prioridade,
 *                                              responsavel, bloqueia=1, q)
 *   GET ?id=N                        → detalhe
 *   GET ?counts=1                    → contagens (badge)
 *
 *   PATCH                            → atualiza (body: { id, status?, prioridade?,
 *                                              notas_internas?, prazo?, responsavel?,
 *                                              bloqueia_producao? })
 *
 * Toda mutação registrada em master_audit_log.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/PendingReview.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';
require_once __DIR__ . '/../../../app/Helpers/RequestId.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;
use App\Models\PendingReview;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = $ctx->getUserId();

// ─── GET ─────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['counts'])) {
        ApiResponse::ok(PendingReview::counts());
    }
    if (!empty($_GET['id'])) {
        $r = PendingReview::findById((int)$_GET['id']);
        if (!$r) ApiResponse::notFound();
        ApiResponse::ok($r);
    }
    $list = PendingReview::listAll([
        'status'      => $_GET['status']      ?? null,
        'categoria'   => $_GET['categoria']   ?? null,
        'prioridade'  => $_GET['prioridade']  ?? null,
        'responsavel' => $_GET['responsavel'] ?? null,
        'bloqueia'    => $_GET['bloqueia']    ?? '',
        'q'           => $_GET['q']           ?? null,
    ]);
    ApiResponse::ok($list);
}

// ─── CSRF p/ mutações ────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        ApiResponse::badRequest('CSRF inválido');
    }
}

// ─── PATCH — atualiza item ───────────────────────────────────────────────────
if ($method === 'PATCH') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');
    $old = PendingReview::findById($id);
    if (!$old) ApiResponse::notFound();

    $up = $input;
    unset($up['id'], $up['csrf_token']);

    $ok = PendingReview::update($id, $up, $userId);
    if ($ok) {
        $changes = array_keys($up);
        MasterAudit::log('review.update', 'pending_review', $id,
            "Revisão #{$id} '{$old['titulo']}' atualizada",
            ['campos' => $changes, 'novo_status' => $input['status'] ?? null]);
    }
    ApiResponse::ok(['updated' => $ok]);
}

ApiResponse::methodNotAllowed();
