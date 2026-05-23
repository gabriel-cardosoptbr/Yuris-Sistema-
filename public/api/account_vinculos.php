<?php
/**
 * API: /api/account_vinculos.php
 * Gerencia vínculos Matriz ↔ Filial.
 *
 * GET    /api/account_vinculos.php              → lista vínculos da conta atual
 * POST   /api/account_vinculos.php              → filial solicita vínculo com uma matriz
 *          Body: { codigo_vinculo: "abc123", csrf_token: "..." }
 * PATCH  /api/account_vinculos.php              → matriz aprova/rejeita/suspende vínculo
 *          Body: { id: 5, action: "aprovar"|"rejeitar"|"suspender"|"reativar", motivo?: "..." }
 * DELETE /api/account_vinculos.php?id=5         → cancela vínculo (owner/admin de qualquer lado)
 *
 * FLUXO:
 *   1. Matriz compartilha seu codigo_vinculo (GET /api/accounts.php?action=codigo)
 *   2. Filial POST aqui com o codigo_vinculo → status = pending
 *   3. Notificação chega para owner/admin da Matriz
 *   4. Matriz PATCH { id, action: "aprovar" } → status = active
 */

require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/AccountVinculo.php';
require_once __DIR__ . '/../../app/Models/AccountNotification.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Models\Account;
use App\Models\AccountVinculo;
use App\Models\AccountNotification;
use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx    = AccountContext::fromSession();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF para mutações
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido']);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// GET — lista vínculos da conta
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $vinculos = AccountVinculo::listByAccount($ctx->getAccountId());
    echo json_encode(['data' => $vinculos]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST — filial solicita vínculo com uma matriz
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $codigoVinculo = trim($input['codigo_vinculo'] ?? '');

    if (empty($codigoVinculo)) {
        http_response_code(400);
        echo json_encode(['error' => 'codigo_vinculo é obrigatório']);
        exit;
    }

    // Apenas owner/admin pode solicitar vínculo
    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode solicitar vínculo']);
        exit;
    }

    // Conta atual deve ser filial para solicitar
    if ($ctx->getAccountTipo() !== 'filial') {
        http_response_code(400);
        echo json_encode(['error' => 'Apenas contas do tipo filial podem solicitar vínculo com uma matriz. Verifique o tipo da sua conta nas configurações.']);
        exit;
    }

    // Busca a matriz pelo código
    $matriz = Account::findByCodigoVinculo($codigoVinculo);
    if (!$matriz) {
        http_response_code(404);
        echo json_encode(['error' => 'Código de vínculo inválido ou matriz não encontrada']);
        exit;
    }

    // Não pode vincular a si mesmo
    if ($matriz['id'] === $ctx->getAccountId()) {
        http_response_code(400);
        echo json_encode(['error' => 'Uma conta não pode se vincular a si mesma']);
        exit;
    }

    // Cria solicitação (idempotente)
    $vinculoId = AccountVinculo::solicitar($matriz['id'], $ctx->getAccountId(), $ctx->getUserId());

    // Notifica owners/admins da Matriz
    $pdo  = \App\Models\Database::getConnection();
    $stmt = $pdo->prepare(
        "SELECT id FROM users WHERE account_id = :acc AND role IN ('owner','admin') AND deleted_at IS NULL"
    );
    $stmt->execute(['acc' => $matriz['id']]);
    $admins = $stmt->fetchAll(\PDO::FETCH_COLUMN);

    $filialAccount = Account::findById($ctx->getAccountId());
    foreach ($admins as $adminId) {
        AccountNotification::criar([
            'account_id' => $matriz['id'],
            'user_id'    => $adminId,
            'tipo'       => 'vinculo.pendente',
            'titulo'     => "Solicitação de vínculo: {$filialAccount['nome']}",
            'mensagem'   => "A filial \"{$filialAccount['nome']}\" solicitou vínculo com sua matriz. Acesse Configurações → Vínculos para aprovar ou rejeitar.",
            'payload'    => ['vinculo_id' => $vinculoId, 'filial_account_id' => $ctx->getAccountId()],
        ]);
    }

    Account::audit($ctx->getAccountId(), 'vinculo.solicitado', [
        'user_id'  => $ctx->getUserId(),
        'detalhes' => ['vinculo_id' => $vinculoId, 'matriz_id' => $matriz['id']],
    ]);

    echo json_encode(['success' => true, 'vinculo_id' => $vinculoId, 'status' => 'pending']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// PATCH — aprovar / rejeitar / suspender / reativar
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    $vinculoId = (int) ($input['id'] ?? 0);
    $action    = $input['action'] ?? '';
    $motivo    = $input['motivo'] ?? '';

    if (!$vinculoId || !$action) {
        http_response_code(400);
        echo json_encode(['error' => 'id e action são obrigatórios']);
        exit;
    }

    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode alterar vínculos']);
        exit;
    }

    $vinculo = AccountVinculo::findById($vinculoId);
    if (!$vinculo) {
        http_response_code(404);
        echo json_encode(['error' => 'Vínculo não encontrado']);
        exit;
    }

    // Apenas a MATRIZ pode aprovar/rejeitar/suspender
    if ((int)$vinculo['matriz_account_id'] !== $ctx->getAccountId()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas a conta Matriz pode gerenciar este vínculo']);
        exit;
    }

    $ok = false;
    switch ($action) {
        case 'aprovar':
            $ok = AccountVinculo::aprovar($vinculoId, $ctx->getUserId());
            if ($ok) {
                // Notifica toda a conta filial (user_id = null = broadcast para todos os usuários da conta)
                AccountNotification::criar([
                    'account_id' => (int)$vinculo['filial_account_id'],
                    'user_id'    => null,
                    'tipo'       => 'vinculo.aprovado',
                    'titulo'     => 'Vínculo com matriz aprovado!',
                    'mensagem'   => 'Seu vínculo com a matriz foi aprovado. Agora você pode receber recursos compartilhados.',
                    'payload'    => ['vinculo_id' => $vinculoId],
                ]);
            }
            break;

        case 'rejeitar':
            $ok = AccountVinculo::rejeitar($vinculoId, $ctx->getUserId());
            break;

        case 'suspender':
            $ok = AccountVinculo::suspender($vinculoId, $ctx->getUserId(), $motivo);
            if ($ok) {
                AccountNotification::criar([
                    'account_id' => $vinculo['filial_account_id'],
                    'user_id'    => null,
                    'tipo'       => 'vinculo.suspenso',
                    'titulo'     => 'Vínculo com matriz suspenso',
                    'mensagem'   => $motivo ?: 'Seu vínculo com a matriz foi suspenso. Entre em contato para mais informações.',
                    'payload'    => ['vinculo_id' => $vinculoId],
                ]);
            }
            break;

        case 'reativar':
            $ok = AccountVinculo::reativar($vinculoId);
            break;

        case 'update_sync':
            // Atualiza flags de sincronização (toggle mestre + por módulo).
            // Vínculo precisa estar 'active' — não faz sentido configurar sync de pending/suspended.
            if (($vinculo['status'] ?? '') !== 'active') {
                http_response_code(409);
                echo json_encode(['error' => 'Vínculo precisa estar ativo para configurar sincronização']);
                exit;
            }
            $pdoUp = \App\Models\Database::getConnection();
            $stmtUp = $pdoUp->prepare(
                'UPDATE account_vinculos
                 SET sync_enabled   = :se,
                     sync_cards     = :sc,
                     sync_processos = :sp,
                     sync_tarefas   = :st,
                     sync_updated_at = NOW()
                 WHERE id = :id'
            );
            $ok = $stmtUp->execute([
                'se' => !empty($input['sync_enabled'])   ? 1 : 0,
                'sc' => !empty($input['sync_cards'])     ? 1 : 0,
                'sp' => !empty($input['sync_processos']) ? 1 : 0,
                'st' => !empty($input['sync_tarefas'])   ? 1 : 0,
                'id' => $vinculoId,
            ]);
            if ($ok) {
                Account::audit($ctx->getAccountId(), 'vinculo.sync_atualizada', [
                    'user_id'  => $ctx->getUserId(),
                    'detalhes' => [
                        'vinculo_id'    => $vinculoId,
                        'sync_enabled'  => (int)!empty($input['sync_enabled']),
                        'sync_cards'    => (int)!empty($input['sync_cards']),
                        'sync_processos'=> (int)!empty($input['sync_processos']),
                        'sync_tarefas'  => (int)!empty($input['sync_tarefas']),
                    ],
                ]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'action inválido: ' . $action]);
            exit;
    }

    Account::audit($ctx->getAccountId(), 'vinculo.' . $action, [
        'user_id'  => $ctx->getUserId(),
        'detalhes' => ['vinculo_id' => $vinculoId, 'motivo' => $motivo],
    ]);

    echo json_encode(['success' => $ok]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE — cancela vínculo
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? ($input['id'] ?? 0));
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id obrigatório']); exit; }

    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode cancelar vínculos']);
        exit;
    }

    $vinculo = AccountVinculo::findById($id);
    if (!$vinculo) { http_response_code(404); echo json_encode(['error' => 'Vínculo não encontrado']); exit; }

    // Qualquer um dos dois lados pode cancelar
    $isRelated = in_array($ctx->getAccountId(), [$vinculo['matriz_account_id'], $vinculo['filial_account_id']]);
    if (!$isRelated) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão para cancelar este vínculo']);
        exit;
    }

    $pdo  = \App\Models\Database::getConnection();
    $stmt = $pdo->prepare("UPDATE account_vinculos SET status = 'rejected', updated_at = NOW() WHERE id = :id");
    $ok   = $stmt->execute(['id' => $id]);

    Account::audit($ctx->getAccountId(), 'vinculo.cancelado', ['user_id' => $ctx->getUserId(), 'detalhes' => ['vinculo_id' => $id]]);

    echo json_encode(['success' => $ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
