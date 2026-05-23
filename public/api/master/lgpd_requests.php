<?php
/**
 * /api/master/lgpd_requests.php — Painel Master: gestão LGPD (Art. 18).
 *
 * GET                                → lista com filtros (status, tipo, atrasada, q)
 * GET    ?id=N                       → detalhe + eventos
 * GET    ?counts=1                   → contagem por status (pra badge)
 * PATCH  body: { id, status?, resposta?, motivo_rejeicao? }
 * POST   ?action=add_event body: { id, evento, observacao }
 *
 * Acesso: super_admin com sessão master_mode.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/LgpdRequest.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
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

// ─── GET ─────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['counts'])) {
        ApiResponse::ok(LgpdRequest::countByStatus());
    }
    if (!empty($_GET['id'])) {
        $req = LgpdRequest::findById((int)$_GET['id']);
        if (!$req) ApiResponse::notFound();
        ApiResponse::ok([
            'request' => $req,
            'eventos' => LgpdRequest::listEvents((int)$req['id']),
        ]);
    }
    $list = LgpdRequest::listForAdmin([
        'status'   => $_GET['status']   ?? null,
        'tipo'     => $_GET['tipo']     ?? null,
        'atrasada' => $_GET['atrasada'] ?? null,
        'q'        => $_GET['q']        ?? null,
    ]);
    ApiResponse::ok($list);
}

// ─── Validações CSRF para mutações ───────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        ApiResponse::badRequest('CSRF inválido');
    }
}

// ─── POST ?action=add_event — adiciona evento manual ─────────────────────────
if ($method === 'POST' && ($_GET['action'] ?? null) === 'add_event') {
    $id = (int)($input['id'] ?? 0);
    $ev = trim((string)($input['evento'] ?? ''));
    $ob = trim((string)($input['observacao'] ?? ''));
    if (!$id || $ev === '') ApiResponse::badRequest('id e evento obrigatórios');
    if (!LgpdRequest::findById($id)) ApiResponse::notFound();

    LgpdRequest::addEvent($id, $ev, $ob ?: null, $userId);
    MasterAudit::log('lgpd_request.event_added', 'lgpd_request', $id,
        "Evento '{$ev}' adicionado", ['observacao' => $ob]);
    ApiResponse::ok(['added' => true]);
}

// ─── PATCH — atualiza status/resposta ───────────────────────────────────────
if ($method === 'PATCH') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');
    $req = LgpdRequest::findById($id);
    if (!$req) ApiResponse::notFound();

    $up = [];
    if (isset($input['status']))          $up['status']           = $input['status'];
    if (isset($input['resposta']))        $up['resposta']         = $input['resposta'];
    if (isset($input['motivo_rejeicao'])) $up['motivo_rejeicao']  = $input['motivo_rejeicao'];

    $ok = LgpdRequest::update($id, $up, $userId);
    if ($ok) {
        MasterAudit::log('lgpd_request.updated', 'lgpd_request', $id,
            'Solicitação LGPD atualizada', ['changes' => array_keys($up)]);
    }
    ApiResponse::ok(['updated' => $ok]);
}

ApiResponse::methodNotAllowed();
