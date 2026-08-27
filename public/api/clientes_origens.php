<?php
/**
 * /api/clientes_origens.php — CRUD das origens do cadastro de Cliente.
 *
 * GET                       → lista origens ativas do tenant
 * GET     ?id=N             → origem individual
 * POST                      → cria origem
 * PATCH                     → update individual OU reorder em lote
 * DELETE  ?id=N             → arquiva origem (recusa se há clientes usando)
 *
 * Segurança: sessão + CSRF + AccountContext::fromSession.
 * Multi-tenant: cada conta tem suas próprias origens. Sem herança.
 */

require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Prospeccao/ClienteOrigem.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';

use App\Prospeccao\ClienteOrigem;
use App\Core\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$ctx->assertAccountActive();
$accountId = $ctx->getAccountId();
$tenantIds = [$accountId];

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $r = ClienteOrigem::find((int)$_GET['id'], $tenantIds);
        if (!$r) { http_response_code(404); echo json_encode(['error' => 'Origem não encontrada']); exit; }
        echo json_encode(['data' => $r]);
        exit;
    }
    $filters = ['account_ids' => $tenantIds];
    if (!empty($_GET['include_inactive'])) $filters['include_inactive'] = true;
    echo json_encode(['data' => ClienteOrigem::listAll($filters)]);
    exit;
}

if ($method === 'POST') {
    $input['account_id'] = $accountId;
    try {
        $id = ClienteOrigem::create($input);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'validation']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Falha ao criar origem']);
    }
    exit;
}

if ($method === 'PATCH' || $method === 'PUT') {
    if (!empty($input['reorder']) && is_array($input['reorder'])) {
        $n = ClienteOrigem::reorder($input['reorder'], $tenantIds);
        echo json_encode(['success' => true, 'updated' => $n]);
        exit;
    }
    if (empty($input['id'])) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $ok = ClienteOrigem::update((int)$input['id'], $input, $tenantIds);
    if (!$ok) { http_response_code(403); echo json_encode(['error' => 'Sem permissão ou nada a atualizar']); exit; }
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? ($input['id'] ?? null);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $r = ClienteOrigem::archive((int)$id, $tenantIds);
    if (!empty($r['ok'])) { echo json_encode(['success' => true]); exit; }
    if (($r['reason'] ?? '') === 'has_clients') {
        http_response_code(409);
        echo json_encode([
            'error' => 'Origem em uso por clientes ativos não pode ser arquivada',
            'code'  => 'has_clients',
            'count' => $r['count'] ?? 0,
            'hint'  => 'Reclassifique os clientes pra outra origem antes de arquivar.',
        ]);
        exit;
    }
    http_response_code($r['reason'] === 'not_found' ? 404 : 400);
    echo json_encode(['error' => 'Falha ao arquivar origem', 'code' => $r['reason'] ?? 'unknown']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
