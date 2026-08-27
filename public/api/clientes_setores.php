<?php
/**
 * /api/clientes_setores.php — CRUD dos setores (colunas) do kanban de Clientes.
 *
 * GET                      → lista setores do tenant (com count de clientes)
 * GET     ?id=N            → setor individual
 * POST                     → cria setor (account_id sempre do contexto)
 * PATCH                    → atualiza setor individual OU reorder em lote
 *                            ({reorder: [{id, ordem}, ...]})
 * DELETE  ?id=N            → arquiva setor (ativo=0). Recusa se houver clientes ativos.
 *
 * Segurança: sessão + CSRF + AccountContext::fromSession.
 * Multi-tenant: cada conta tem seus próprios setores. Sem herança matriz→filial.
 */

require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Clientes/ClienteSetor.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';

use App\Clientes\ClienteSetor;
use App\Core\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$ctx->assertAccountActive();
$accountId = $ctx->getAccountId();
// Setores de Clientes são por tenant (sem herança). Mas LISTAGEM pode incluir
// filiais visíveis pela matriz pra exibir kanbans separados se evoluir nessa direção.
// Hoje retornamos só os do próprio tenant — comportamento mais simples e seguro.
$tenantIds = [$accountId];

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

// ── GET ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $s = ClienteSetor::find((int)$_GET['id'], $tenantIds);
        if (!$s) { http_response_code(404); echo json_encode(['error' => 'Setor não encontrado']); exit; }
        echo json_encode(['data' => $s]);
        exit;
    }
    $filters = ['account_ids' => $tenantIds];
    if (!empty($_GET['include_inactive'])) $filters['include_inactive'] = true;
    echo json_encode(['data' => ClienteSetor::listAll($filters)]);
    exit;
}

// ── POST: criar setor ──────────────────────────────────────────────
if ($method === 'POST') {
    $input['account_id'] = $accountId;  // SEMPRE do contexto
    try {
        $id = ClienteSetor::create($input);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'validation']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Falha ao criar setor']);
    }
    exit;
}

// ── PATCH: update individual ou reorder em lote ────────────────────
if ($method === 'PATCH' || $method === 'PUT') {
    if (!empty($input['reorder']) && is_array($input['reorder'])) {
        $n = ClienteSetor::reorder($input['reorder'], $tenantIds);
        echo json_encode(['success' => true, 'updated' => $n]);
        exit;
    }

    if (empty($input['id'])) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $id = (int)$input['id'];
    $ok = ClienteSetor::update($id, $input, $tenantIds);
    if (!$ok) { http_response_code(403); echo json_encode(['error' => 'Sem permissão ou nada a atualizar']); exit; }
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE: arquivar setor (ativo=0). Recusa se tem clientes ativos. ──
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? ($input['id'] ?? null);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $r = ClienteSetor::archive((int)$id, $tenantIds);
    if (!empty($r['ok'])) { echo json_encode(['success' => true]); exit; }
    if (($r['reason'] ?? '') === 'has_clients') {
        http_response_code(409);
        echo json_encode([
            'error' => 'Setor com clientes ativos não pode ser arquivado',
            'code'  => 'has_clients',
            'count' => $r['count'] ?? 0,
            'hint'  => 'Mova os clientes deste setor para outro antes de arquivar.',
        ]);
        exit;
    }
    http_response_code($r['reason'] === 'not_found' ? 404 : 400);
    echo json_encode(['error' => 'Falha ao arquivar setor', 'code' => $r['reason'] ?? 'unknown']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
