<?php
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Master/Account.php';
require_once __DIR__ . '/../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../app/Financas/DRECode.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';

use App\Financas\DRECode;
use App\Core\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();
// Escopo CONSOLIDADO: a matriz enxerga o plano de contas das filiais/advogados vinculados,
// coerente com financas.php (render) e com o dashboard. Antes hardcodava [$accountId], o que
// deixava o <select> de códigos do DRE inconsistente com os lançamentos consolidados.
$tenantIds = $ctx->getAccessibleAccountIds('financas');
if (empty($tenantIds)) $tenantIds = [$accountId]; // guard: evita SQL "IN ()" inválido

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $rec = DRECode::find((int)$_GET['id'], $tenantIds);
        echo json_encode(['data' => $rec]);
        exit;
    }
    $list = DRECode::listAll(['account_ids' => $tenantIds]);
    echo json_encode(['data' => $list]);
    exit;
}

if (in_array($method, ['POST','PUT','PATCH','DELETE'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

if ($method === 'POST') {
    if (empty($input['code'])) { http_response_code(400); echo json_encode(['error' => 'Missing code']); exit; }
    $input['account_id'] = $accountId;
    $id = DRECode::create($input);
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
    if (empty($input['id'])) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $ok = DRECode::update((int)$input['id'], $input, $tenantIds);
    if (!$ok) { http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit; }
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? ($input['id'] ?? null);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $ok = DRECode::softDelete((int)$id, $tenantIds);
    if (!$ok) { http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit; }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
