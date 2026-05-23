<?php
/**
 * API: /api/resource_shares.php
 * Compartilhamento seletivo de recursos entre contas.
 *
 * GET    /api/resource_shares.php?resource_type=processo&resource_id=42  → lista quem tem acesso
 * GET    /api/resource_shares.php?shared_with_me=1&resource_type=processo → recursos compartilhados comigo
 * POST   /api/resource_shares.php                                         → cria share
 *          Body: { resource_type, resource_id, to_account_id?, to_user_id?, permission_level }
 * DELETE /api/resource_shares.php?id=5                                    → revoga share
 *
 * SEGURANÇA:
 *   - from_account_id SEMPRE vem da sessão (nunca do body)
 *   - Valida que a conta dona é realmente dona do recurso
 *   - Valida vínculo ativo entre as contas antes de compartilhar
 */

require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Models/AccountNotification.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Models\Account;
use App\Models\ResourceShare;
use App\Models\AccountNotification;
use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx    = AccountContext::fromSession();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF
if (in_array($method, ['POST', 'DELETE', 'PATCH'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido']);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// GET
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    // Lista módulos liberados PELA conta atual (shares de tipo module emitidos por ela)
    if (!empty($_GET['listar_modulos'])) {
        $pdo  = \App\Models\Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT rs.*, a.nome AS to_account_nome, tu.nome AS to_user_nome
             FROM resource_shares rs
             LEFT JOIN accounts a  ON a.id  = rs.to_account_id
             LEFT JOIN users    tu ON tu.id = rs.to_user_id
             WHERE rs.resource_type   = 'module'
               AND rs.from_account_id = :acc
             ORDER BY rs.created_at DESC"
        );
        $stmt->execute(['acc' => $ctx->getAccountId()]);
        echo json_encode(['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
        exit;
    }

    // Lista recursos compartilhados COM a conta logada
    if (!empty($_GET['shared_with_me'])) {
        $type  = $_GET['resource_type'] ?? null;
        $data  = ResourceShare::listSharedWithAccount($ctx->getAccountId(), $type ?: null);
        echo json_encode(['data' => $data]);
        exit;
    }

    // Lista shares de um recurso específico
    $type       = $_GET['resource_type'] ?? null;
    $resourceId = (int) ($_GET['resource_id'] ?? 0);

    if (!$type || !$resourceId) {
        http_response_code(400);
        echo json_encode(['error' => 'resource_type e resource_id são obrigatórios']);
        exit;
    }

    // Verifica que o usuário tem acesso ao recurso
    $ctx->assertCanRead($type, $resourceId);

    $shares = ResourceShare::listByResource($type, $resourceId);
    echo json_encode(['data' => $shares]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST — cria novo compartilhamento
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $resourceType  = $input['resource_type']  ?? null;
    $resourceId    = (int) ($input['resource_id'] ?? 0);
    $moduleKey     = isset($input['module_key']) ? trim((string)$input['module_key']) : null;
    $toAccountId   = isset($input['to_account_id'])  ? (int)$input['to_account_id']  : null;
    $toUserId      = isset($input['to_user_id'])      ? (int)$input['to_user_id']      : null;
    $permLevel     = $input['permission_level']       ?? 'view';

    // Validações básicas
    if (!$resourceType) {
        http_response_code(400);
        echo json_encode(['error' => 'resource_type é obrigatório']);
        exit;
    }

    // Validação especial para módulos
    $modulesAllowed = ['processos','juridico','dashboard','planejamento','prospeccao','financas','tarefas','chat','chat_interno'];
    if ($resourceType === 'module') {
        if (!$moduleKey || !in_array($moduleKey, $modulesAllowed, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'module_key obrigatório e deve ser um de: ' . implode(', ', $modulesAllowed)]);
            exit;
        }
        $resourceId = 0;  // convenção: módulo não tem id de recurso
    } elseif (!$resourceId) {
        http_response_code(400);
        echo json_encode(['error' => 'resource_id é obrigatório quando resource_type != module']);
        exit;
    }

    if (!in_array($permLevel, ['view', 'edit', 'full'])) {
        http_response_code(400);
        echo json_encode(['error' => 'permission_level inválido. Use: view, edit ou full']);
        exit;
    }

    // Apenas owner/admin pode compartilhar
    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode compartilhar recursos']);
        exit;
    }

    // A conta logada precisa ser DONA do recurso (somente para recursos com id)
    if ($resourceType !== 'module') {
        $ctx->assertIsOwnerOfResource($resourceType, $resourceId);
    }

    // Valida que a conta de destino existe e está ativa
    // Advogado Associado não exige vínculo Matriz/Filial formal — apenas conta Yuris ativa
    if ($toAccountId !== null) {
        $contaDestino = Account::findById($toAccountId);
        if (!$contaDestino || $contaDestino['status'] !== 'active') {
            http_response_code(400);
            echo json_encode(['error' => 'Conta de destino não encontrada ou inativa']);
            exit;
        }
    }

    try {
        $shareId = ResourceShare::create([
            'resource_type'   => $resourceType,
            'resource_id'     => $resourceId,
            'module_key'      => $moduleKey,
            'from_account_id' => $ctx->getAccountId(),
            'to_account_id'   => $toAccountId,
            'to_user_id'      => $toUserId,
            'permission_level'=> $permLevel,
            'criado_por'      => $ctx->getUserId(),
        ]);
    } catch (\InvalidArgumentException $e) {
        // P1 LGPD (2D.1): InvalidArgumentException tem mensagem segura
        // (validação de input) — mantém visível ao cliente mesmo em prod
        // mas usa o helper pra padronizar resposta + correlation_id
        require_once __DIR__ . '/../../app/Helpers/ErrorReporter.php';
        \App\Helpers\ErrorReporter::handle($e, 400, $e->getMessage());
    }

    // Notifica a conta de destino (se especificada)
    if ($toAccountId) {
        $sourceAccount = Account::findById($ctx->getAccountId());
        AccountNotification::criar([
            'account_id' => $toAccountId,
            'user_id'    => null,
            'tipo'       => 'share.criado',
            'titulo'     => "Recurso compartilhado por {$sourceAccount['nome']}",
            'mensagem'   => "Um {$resourceType} foi compartilhado com sua conta com permissão: {$permLevel}.",
            'payload'    => [
                'share_id'      => $shareId,
                'resource_type' => $resourceType,
                'resource_id'   => $resourceId,
                'permission'    => $permLevel,
            ],
        ]);
    }

    Account::audit($ctx->getAccountId(), 'share.criado', [
        'user_id'       => $ctx->getUserId(),
        'resource_type' => $resourceType,
        'resource_id'   => $resourceId,
        'detalhes'      => ['share_id' => $shareId, 'to_account_id' => $toAccountId, 'permission' => $permLevel],
    ]);

    echo json_encode(['success' => true, 'id' => $shareId]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE — revoga share
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? ($input['id'] ?? 0));
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id obrigatório']); exit; }

    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode revogar compartilhamentos']);
        exit;
    }

    $share = ResourceShare::findById($id);
    if (!$share) { http_response_code(404); echo json_encode(['error' => 'Share não encontrado']); exit; }

    // Apenas a conta dona pode revogar
    if ((int)$share['from_account_id'] !== $ctx->getAccountId()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas a conta dona do recurso pode revogar o compartilhamento']);
        exit;
    }

    $ok = ResourceShare::revogar($id, $ctx->getUserId());

    Account::audit($ctx->getAccountId(), 'share.revogado', [
        'user_id'       => $ctx->getUserId(),
        'resource_type' => $share['resource_type'],
        'resource_id'   => $share['resource_id'],
        'detalhes'      => ['share_id' => $id],
    ]);

    echo json_encode(['success' => $ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
