<?php
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Master/Account.php';
require_once __DIR__ . '/../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';

use App\Core\Database;
use App\Core\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();
// Escopo CONSOLIDADO: a matriz enxerga os impostos das filiais/advogados vinculados, em linha
// com o render server-side de financas.php e com o dashboard. Antes hardcodava [$accountId],
// fazendo o painel de impostos recalcular só com a receita da matriz após o load do JS.
$tenantIds = $ctx->getAccessibleAccountIds('financas');
if (empty($tenantIds)) $tenantIds = [$accountId]; // guard: evita SQL "IN ()" inválido

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// Cláusula tenant
$ph = []; $tenantParams = [];
foreach ($tenantIds as $i => $aid) {
    $k = "tax_{$i}";
    $ph[] = ":{$k}";
    $tenantParams[$k] = (int)$aid;
}
$tenantIn = '(' . implode(',', $ph) . ')';

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM taxes WHERE ativo = 1 AND account_id IN $tenantIn ORDER BY id ASC");
    $stmt->execute($tenantParams);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

if ($method === 'POST') {
    $nome       = trim($input['nome'] ?? '');
    $percentual = (float)str_replace(',', '.', $input['percentual'] ?? '0');
    if (!$nome)                                  { http_response_code(400); echo json_encode(['error' => 'Nome é obrigatório']); exit; }
    if ($percentual < 0 || $percentual > 100)    { http_response_code(400); echo json_encode(['error' => 'Alíquota inválida (0–100)']); exit; }
    $stmt = $pdo->prepare("INSERT INTO taxes (account_id, nome, percentual) VALUES (:account_id, :nome, :percentual)");
    $stmt->execute(['account_id' => $accountId, 'nome' => $nome, 'percentual' => $percentual]);
    $id = (int)$pdo->lastInsertId();
    $row = $pdo->prepare("SELECT * FROM taxes WHERE id = :id");
    $row->execute(['id' => $id]);
    $taxRow = $row->fetch(PDO::FETCH_ASSOC);
    \App\Master\Account::audit($accountId, 'tax.created', [
        'user_id'     => $ctx->getUserId(),
        'entidade'    => 'tax',
        'entidade_id' => $id,
        'detalhes'    => ['nome' => $nome, 'percentual' => $percentual],
    ]);
    echo json_encode(['success' => true, 'data' => $taxRow]);
    exit;
}

if ($method === 'PUT') {
    $id         = (int)($input['id'] ?? 0);
    $nome       = trim($input['nome'] ?? '');
    $percentual = (float)str_replace(',', '.', $input['percentual'] ?? '0');
    if (!$id)                                 { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    if (!$nome)                               { http_response_code(400); echo json_encode(['error' => 'Nome é obrigatório']); exit; }
    if ($percentual < 0 || $percentual > 100) { http_response_code(400); echo json_encode(['error' => 'Alíquota inválida (0–100)']); exit; }
    $stPrev = $pdo->prepare("SELECT * FROM taxes WHERE id = :id AND account_id IN $tenantIn");
    $stPrev->execute(['id' => $id] + $tenantParams);
    $dadosAntes = $stPrev->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt = $pdo->prepare("UPDATE taxes SET nome = :nome, percentual = :percentual, updated_at = NOW() WHERE id = :id AND ativo = 1 AND account_id IN $tenantIn");
    $stmt->execute(['nome' => $nome, 'percentual' => $percentual, 'id' => $id] + $tenantParams);
    if ($stmt->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit; }
    \App\Master\Account::audit($accountId, 'tax.updated', [
        'user_id'     => $ctx->getUserId(),
        'entidade'    => 'tax',
        'entidade_id' => $id,
        'dados_antes' => $dadosAntes,
        'detalhes'    => ['nome' => $nome, 'percentual' => $percentual],
    ]);
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $stPrev = $pdo->prepare("SELECT * FROM taxes WHERE id = :id AND account_id IN $tenantIn");
    $stPrev->execute(['id' => $id] + $tenantParams);
    $dadosAntes = $stPrev->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt = $pdo->prepare("UPDATE taxes SET ativo = 0, updated_at = NOW() WHERE id = :id AND account_id IN $tenantIn");
    $stmt->execute(['id' => $id] + $tenantParams);
    if ($stmt->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit; }
    \App\Master\Account::audit($accountId, 'tax.deleted', [
        'user_id'     => $ctx->getUserId(),
        'entidade'    => 'tax',
        'entidade_id' => $id,
        'dados_antes' => $dadosAntes,
    ]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
