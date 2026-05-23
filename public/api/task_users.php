<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

// Carrega contexto de tenant (aborta 401 se sessão inválida)
$ctx = AccountContext::fromSession();
$accessibleAccountIds = $ctx->getAccessibleAccountIds('tarefas');
if (empty($accessibleAccountIds)) {
    echo json_encode(['ok' => true, 'data' => []]);
    exit;
}

// Filtra usuários apenas das contas acessíveis (matriz + filiais + módulos compartilhados)
$ph = []; $params = [];
foreach ($accessibleAccountIds as $i => $aid) {
    $k = "tu_{$i}";
    $ph[] = ":{$k}";
    $params[$k] = (int)$aid;
}

$pdo  = \App\Models\Database::getConnection();
$sql  = "SELECT id, nome
         FROM users
         WHERE deleted_at IS NULL
           AND status = 'active'
           AND account_id IN (" . implode(',', $ph) . ")
         ORDER BY nome";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'data' => $users]);
