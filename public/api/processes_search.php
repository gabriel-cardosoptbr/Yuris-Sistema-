<?php
/**
 * API: /api/processes_search.php
 * Autocomplete de processos da conta logada.
 *
 * GET /api/processes_search.php?q=texto
 * Retorna: array de { id, numero, cliente_nome }
 */

require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';

use App\Core\Database;
use App\Core\AccountContext;

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

// Multi-tenant: a matriz precisa ver processos das filiais no autocomplete
// (achado MEDIA #18). Antes escopava só account_id próprio; usamos
// getAccessibleAccountIds('processos') — fonte canônica (account_vinculos +
// shares), coerente com /api/processes.php e push/search_processos.php.
$accIds = $ctx->getAccessibleAccountIds('processos');
if (empty($accIds)) {
    echo json_encode(['data' => []]);
    exit;
}
$accPh  = [];
$params = [];
foreach ($accIds as $i => $aid) {
    $k = "acc{$i}";
    $accPh[]    = ":{$k}";
    $params[$k] = (int)$aid;
}

$sql    = 'SELECT id, numero, cliente_nome
           FROM processos
           WHERE account_id IN (' . implode(',', $accPh) . ')
             AND deleted_at IS NULL';

if ($q !== '') {
    $sql .= ' AND (numero LIKE :q OR cliente_nome LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY created_at DESC LIMIT 20';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo json_encode(['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
