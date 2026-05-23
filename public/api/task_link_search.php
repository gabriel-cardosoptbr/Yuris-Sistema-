<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx       = AccountContext::fromSession();
$tenantIds = $ctx->getAccessibleAccountIds();

$type  = $_GET['type']  ?? '';
$q     = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 15), 50);
$like  = '%' . $q . '%';
$pdo   = \App\Models\Database::getConnection();
$items = [];

// Cláusula IN para os account_ids acessíveis
$ph = []; $tenantParams = [];
foreach ($tenantIds as $i => $aid) {
    $k = "tls_{$i}";
    $ph[] = ":{$k}";
    $tenantParams[$k] = (int)$aid;
}
$tenantIn = '(' . implode(',', $ph) . ')';

try {
    switch ($type) {
        case 'contato':
            $stmt = $pdo->prepare("
                SELECT id,
                       nome AS label,
                       COALESCE(telefone, email, '') AS sub
                FROM contatos
                WHERE (nome LIKE :q1 OR telefone LIKE :q2 OR email LIKE :q3)
                  AND account_id IN $tenantIn
                ORDER BY nome
                LIMIT $limit
            ");
            $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like] + $tenantParams);
            break;

        case 'processo':
            // Label: número do processo (humano). Se faltar, usa cliente — nunca expõe ID interno.
            $stmt = $pdo->prepare("
                SELECT id,
                       COALESCE(NULLIF(numero,''), NULLIF(cliente_nome,''), 'Processo sem número') AS label,
                       COALESCE(cliente_nome, '') AS sub
                FROM processos
                WHERE deleted_at IS NULL
                  AND (numero LIKE :q1 OR cliente_nome LIKE :q2 OR tipo_acao LIKE :q3)
                  AND account_id IN $tenantIn
                ORDER BY updated_at DESC
                LIMIT $limit
            ");
            $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like] + $tenantParams);
            break;

        case 'card':
            // Label: nome do cliente/lead. Se faltar, tenta empresa — nunca expõe ID interno.
            $stmt = $pdo->prepare("
                SELECT id,
                       COALESCE(NULLIF(cliente_nome,''), NULLIF(empresa_nome,''), NULLIF(titulo,''), 'Lead sem nome') AS label,
                       COALESCE(empresa_nome, '') AS sub
                FROM cards
                WHERE deleted_at IS NULL
                  AND (cliente_nome LIKE :q1 OR empresa_nome LIKE :q2)
                  AND account_id IN $tenantIn
                ORDER BY updated_at DESC
                LIMIT $limit
            ");
            $stmt->execute(['q1' => $like, 'q2' => $like] + $tenantParams);
            break;

        case 'dre_account':
            $stmt = $pdo->prepare("
                SELECT id,
                       nome AS label,
                       tipo AS sub
                FROM dre_accounts
                WHERE (nome LIKE :q1 OR codigo LIKE :q2)
                  AND account_id IN $tenantIn
                ORDER BY nome
                LIMIT $limit
            ");
            $stmt->execute(['q1' => $like, 'q2' => $like] + $tenantParams);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => 'Tipo inválido']);
            exit;
    }

    $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => $items]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
