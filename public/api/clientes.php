<?php
/**
 * /api/clientes.php — REST endpoint do módulo Clientes.
 *
 * GET     ?id=N            → cliente individual (com histórico)
 * GET                       → lista clientes do tenant (filtros: setor_id, responsavel_id, status, search, include_archived)
 * POST                      → cria cliente (account_id sempre da sessão)
 * PUT                       → atualiza cliente individual
 * PATCH                     → move 1 cliente (id + setor_id + ordem_no_setor) ou reorder em lote
 * DELETE  ?id=N             → arquiva cliente (soft-delete)
 *
 * Segurança:
 *   - Sessão obrigatória + AccountContext::fromSession
 *   - CSRF em POST/PUT/PATCH/DELETE (header X-CSRF-TOKEN ou body csrf_token)
 *   - account_id NUNCA vem do body — sempre do contexto
 *   - assertCanRead/Write valida ownership ou share
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Webhooks\WebhookDispatcher;

use App\Clientes\Cliente;
use App\Clientes\ClienteSetor;
use App\Core\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$ctx->assertAccountActive();
$userId    = $ctx->getUserId();
$accountId = $ctx->getAccountId();
$tenantIds = $ctx->getAccessibleAccountIds('clientes');  // matriz + filiais vinculadas

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// ── CSRF ───────────────────────────────────────────────────────────
if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

// ── Helpers ─────────────────────────────────────────────────────────

/** Garante que $id pertence a um dos tenants acessíveis. Aborta 403 se não. */
function _assertClienteTenant(int $id, array $tenantIds): array {
    $cli = Cliente::find($id);
    if (!$cli) { http_response_code(404); echo json_encode(['error' => 'Cliente não encontrado']); exit; }
    if (!in_array((int)$cli['account_id'], $tenantIds, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão']);
        exit;
    }
    return $cli;
}

/** Valida que $setorId pertence ao mesmo tenant onde o cliente vai morar. */
function _assertSetorBelongsToAccount(int $setorId, int $accountId): void {
    $s = ClienteSetor::find($setorId, [$accountId]);
    if (!$s) {
        http_response_code(400);
        echo json_encode(['error' => 'Setor inválido para este tenant', 'code' => 'invalid_setor']);
        exit;
    }
}

// ── GET ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $cli = _assertClienteTenant((int)$_GET['id'], $tenantIds);
        $cli['history'] = Cliente::getHistory((int)$cli['id']);
        echo json_encode(['data' => $cli]);
        exit;
    }

    $filters = ['account_ids' => $tenantIds];
    if (!empty($_GET['setor_id']))         $filters['setor_id']         = (int)$_GET['setor_id'];
    if (!empty($_GET['responsavel_id']))   $filters['responsavel_id']   = (int)$_GET['responsavel_id'];
    if (!empty($_GET['status']))           $filters['status']           = (string)$_GET['status'];
    if (!empty($_GET['search']))           $filters['search']           = (string)$_GET['search'];
    if (!empty($_GET['include_archived'])) $filters['include_archived'] = true;

    echo json_encode(['data' => Cliente::list($filters)]);
    exit;
}

// ── POST: criar ─────────────────────────────────────────────────────
if ($method === 'POST') {
    $data = $input;
    $data['account_id'] = $accountId;  // SEMPRE do contexto (NUNCA do body)

    // Trava: precisa haver pelo menos 1 setor ativo no tenant
    $setores = ClienteSetor::listAll(['account_ids' => [$accountId]]);
    if (empty($setores)) {
        http_response_code(409);
        echo json_encode([
            'error' => 'Crie pelo menos um setor antes de cadastrar um cliente.',
            'code'  => 'no_setores',
            'hint'  => 'Use o botão "Gerenciar Setores" para criar a primeira etapa.',
        ]);
        exit;
    }

    // Valida setor_id se enviado; senão usa o primeiro do tenant
    if (!empty($data['setor_id'])) {
        _assertSetorBelongsToAccount((int)$data['setor_id'], $accountId);
    } else {
        $data['setor_id'] = (int)$setores[0]['id'];
    }

    try {
        $id = Cliente::create($data, $userId);
        // Webhook cliente.created (auditoria 2026-06-01: evento existia no catalogo
        // mas nunca disparava). Falha do webhook nao quebra a resposta.
        try { WebhookDispatcher::fire($accountId, 'cliente.created', WebhookDispatcher::buildPayload('cliente.created', [
            'entity' => 'cliente', 'entity_id' => $id, 'cliente_id' => $id, 'data' => ['id' => $id, 'nome' => $data['nome'] ?? null],
        ])); } catch (\Throwable $_) {}
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'validation']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Falha ao criar cliente']);
    }
    exit;
}

// ── PATCH: mover 1 ou reorder em lote ───────────────────────────────
if ($method === 'PATCH') {
    // Reorder em lote
    if (!empty($input['reorder']) && is_array($input['reorder'])) {
        // Valida cada id pertence ao tenant — bulk já filtra, mas valida ANTES
        // (evita gravar history em ids inválidos)
        $n = Cliente::bulkUpdateOrders($input['reorder'], $tenantIds, $userId);
        echo json_encode(['success' => true, 'updated' => $n]);
        exit;
    }

    // Move individual
    if (empty($input['id'])) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $id  = (int)$input['id'];
    $cli = _assertClienteTenant($id, $tenantIds);

    if (isset($input['setor_id'])) {
        _assertSetorBelongsToAccount((int)$input['setor_id'], (int)$cli['account_id']);
    }

    $setorNovo = isset($input['setor_id']) ? (int)$input['setor_id'] : (int)$cli['setor_id'];
    $ordemNova = isset($input['ordem_no_setor']) ? (int)$input['ordem_no_setor'] : (int)$cli['ordem_no_setor'];
    $ok = Cliente::move($id, $setorNovo, $ordemNova, $userId);
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

// ── PUT: atualizar cliente ──────────────────────────────────────────
if ($method === 'PUT') {
    if (empty($input['id'])) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $id  = (int)$input['id'];
    $cli = _assertClienteTenant($id, $tenantIds);

    if (isset($input['setor_id'])) {
        _assertSetorBelongsToAccount((int)$input['setor_id'], (int)$cli['account_id']);
    }

    try {
        $ok = Cliente::update($id, $input, $userId);
        if ($ok) { try { WebhookDispatcher::fire((int)$cli['account_id'], 'cliente.updated', WebhookDispatcher::buildPayload('cliente.updated', [
            'entity' => 'cliente', 'entity_id' => $id, 'cliente_id' => $id, 'data' => ['id' => $id],
        ])); } catch (\Throwable $_) {} }
        echo json_encode(['success' => (bool)$ok]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'validation']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Falha ao atualizar']);
    }
    exit;
}

// ── DELETE: arquivar ────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? ($input['id'] ?? null);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $id  = (int)$id;
    $cliDel = _assertClienteTenant($id, $tenantIds);
    $ok = Cliente::archive($id, $userId);
    if ($ok) { try { WebhookDispatcher::fire((int)$cliDel['account_id'], 'cliente.deleted', WebhookDispatcher::buildPayload('cliente.deleted', [
        'entity' => 'cliente', 'entity_id' => $id, 'cliente_id' => $id, 'data' => ['id' => $id],
    ])); } catch (\Throwable $_) {} }
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
