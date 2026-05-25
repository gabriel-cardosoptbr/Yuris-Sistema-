<?php
/**
 * API: /api/accounts_lookup.php
 * Busca conta/usuário pelo codigo_vinculo para compartilhamento de recursos.
 *
 * Aceita dois tipos de código:
 *   1. codigo_vinculo de CONTA  (tabela accounts) → compartilha com a conta inteira
 *   2. codigo_vinculo de USUÁRIO (tabela users)   → compartilha especificamente com aquela pessoa
 *
 * GET /api/accounts_lookup.php?codigo_vinculo=xxxx-xxxx-xxxx-xxxx
 */

require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Models\Account;
use App\Models\Database;
use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx = AccountContext::fromSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$codigo = trim($_GET['codigo_vinculo'] ?? '');
if (!$codigo) {
    http_response_code(400);
    echo json_encode(['error' => 'codigo_vinculo é obrigatório']);
    exit;
}

$pdo = Database::getConnection();

// ── 1. Tenta encontrar como código de USUÁRIO ─────────────────────────────
// Aceita tanto o novo codigo_advogado (ADV-XXXXXX, ID universal) quanto o legado codigo_vinculo.
$stmtUser = $pdo->prepare(
    'SELECT u.id AS user_id, u.nome AS user_nome, u.login AS user_email,
            u.perfil, u.account_id, u.codigo_advogado,
            a.nome AS account_nome, a.tipo AS account_tipo, a.status AS account_status
     FROM users u
     INNER JOIN accounts a ON a.id = u.account_id
     WHERE (u.codigo_advogado = ? OR u.codigo_vinculo = ?) AND u.deleted_at IS NULL
     LIMIT 1'
);
$stmtUser->execute([$codigo, $codigo]);
$user = $stmtUser->fetch(\PDO::FETCH_ASSOC);

if ($user) {
    // Código pertence a um usuário específico
    if ((int)$user['account_id'] === $ctx->getAccountId()) {
        http_response_code(400);
        echo json_encode(['error' => 'Este usuário pertence à sua própria conta']);
        exit;
    }
    if ($user['account_status'] !== 'active') {
        http_response_code(400);
        echo json_encode(['error' => 'A conta deste usuário está inativa']);
        exit;
    }
    echo json_encode([
        'data' => [
            'tipo_lookup'  => 'usuario',
            'user_id'      => (int)$user['user_id'],
            'user_nome'    => $user['user_nome'],
            'user_email'   => $user['user_email'],
            'account_id'   => (int)$user['account_id'],
            'account_nome' => $user['account_nome'],
            'account_tipo' => $user['account_tipo'],
            // alias para compatibilidade com o modal
            'id'           => (int)$user['account_id'],
            'nome'         => $user['user_nome'],
            'tipo'         => $user['account_tipo'],
        ]
    ]);
    exit;
}

// ── 2. Tenta encontrar como código de CONTA ───────────────────────────────
$conta = Account::findByCodigoVinculo($codigo);

if (!$conta) {
    http_response_code(404);
    echo json_encode(['error' => 'Nenhum usuário ou escritório encontrado com este código']);
    exit;
}

if ((int)$conta['id'] === $ctx->getAccountId()) {
    http_response_code(400);
    echo json_encode(['error' => 'Você não pode compartilhar recursos com sua própria conta']);
    exit;
}

// Defensivo: findByCodigoVinculo já filtra por status utilizável
// (active/trial/overdue), mas mantemos a checagem caso o critério
// mude no Model. Mesma whitelist usada no AuthController.
$statusOk = ['active','trial','overdue'];
if (!in_array($conta['status'] ?? '', $statusOk, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Esta conta está indisponível']);
    exit;
}

echo json_encode([
    'data' => [
        'tipo_lookup'  => 'conta',
        'account_id'   => (int)$conta['id'],
        'account_nome' => $conta['nome'],
        'account_tipo' => $conta['tipo'],
        // alias para compatibilidade com o modal
        'id'           => (int)$conta['id'],
        'nome'         => $conta['nome'],
        'tipo'         => $conta['tipo'],
    ]
]);
