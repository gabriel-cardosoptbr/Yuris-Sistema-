<?php
/**
 * API: /api/accounts.php
 * Gerencia a conta do tenant logado.
 *
 * GET    /api/accounts.php          → dados da conta atual + filiais vinculadas
 * GET    /api/accounts.php?id=X     → dados de conta específica (apenas contas vinculadas)
 * PUT    /api/accounts.php          → atualiza nome/plano/configurações da conta atual
 * GET    /api/accounts.php?action=codigo → retorna o codigo_vinculo da conta (para filial usar)
 *
 * SEGURANÇA: account_id SEMPRE vem da sessão, nunca do request.
 */

require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Master/Account.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';

use App\Master\Account;
use App\Core\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx    = AccountContext::fromSession();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// ─────────────────────────────────────────────────────────────────────────────
// GET
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $action = $_GET['action'] ?? null;

    // Retorna o código de vínculo da conta atual (para compartilhar com filial)
    if ($action === 'codigo') {
        if (!$ctx->isOwnerOrAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Apenas owner/admin pode ver o código de vínculo']);
            exit;
        }
        $account = Account::findById($ctx->getAccountId());
        echo json_encode(['codigo_vinculo' => $account['codigo_vinculo'] ?? null]);
        exit;
    }

    // Dados completos da conta atual
    $account = Account::findById($ctx->getAccountId());
    if (!$account) {
        http_response_code(404);
        echo json_encode(['error' => 'Conta não encontrada']);
        exit;
    }

    // Oculta codigo_vinculo para não-admins
    if (!$ctx->isOwnerOrAdmin()) unset($account['codigo_vinculo']);

    // Se for matriz, lista filiais vinculadas
    $filiais = [];
    if ($account['tipo'] === 'matriz') {
        $filiais = Account::listFiliaisVinculadas($ctx->getAccountId());
    }

    // Se for filial, busca a matriz
    $matriz = null;
    if ($account['tipo'] === 'filial') {
        $matriz = Account::findMatrizDaFilial($ctx->getAccountId());
    }

    echo json_encode([
        'data'    => $account,
        'filiais' => $filiais,
        'matriz'  => $matriz,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// PUT — atualiza conta atual
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'PUT' || $method === 'PATCH') {
    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode editar a conta']);
        exit;
    }

    // CSRF
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido']);
        exit;
    }

    // ─── LGPD P1 (2B.4): 'tipo' removido do allowed ──────────────────────────
    // Antes desta correção, owner do tenant podia mudar 'tipo' de filial→matriz
    // (escalation de privilégio: matriz herda dados das filiais vinculadas).
    // Mudança de tipo agora é exclusiva do super_admin via /api/master/accounts.php
    // (que tem audit log e checagem dedicada).
    $allowed = ['nome', 'plano', 'configuracoes'];
    $update  = array_intersect_key($input, array_flip($allowed));

    if (empty($update)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nenhum campo válido para atualizar']);
        exit;
    }

    $ok = Account::update($ctx->getAccountId(), $update);

    Account::audit($ctx->getAccountId(), 'conta.atualizada', [
        'user_id'  => $ctx->getUserId(),
        'detalhes' => array_keys($update),
    ]);

    echo json_encode(['success' => $ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
