<?php
/**
 * API: /api/processes_search.php
 * Autocomplete de processos da conta logada.
 *
 * GET /api/processes_search.php?q=texto
 * Retorna: array de { id, numero, cliente_nome }
 */

require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

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

$q   = trim($_GET['q'] ?? '');
$pdo = Database::getConnection();

$sql    = 'SELECT id, numero, cliente_nome
           FROM processos
           WHERE account_id = :account_id
             AND deleted_at IS NULL';
$params = ['account_id' => $ctx->getAccountId()];

if ($q !== '') {
    $sql .= ' AND (numero LIKE :q OR cliente_nome LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY created_at DESC LIMIT 20';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo json_encode(['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
