<?php
require_once __DIR__ . '/../../app/Models/Database.php';

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$type  = $_GET['type']  ?? '';
$q     = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 15), 50);
$like  = '%' . $q . '%';
$pdo   = \App\Models\Database::getConnection();
$items = [];

try {
    switch ($type) {
        case 'contato':
            $stmt = $pdo->prepare("
                SELECT id,
                       nome AS label,
                       COALESCE(telefone, email, '') AS sub
                FROM contatos
                WHERE nome LIKE ? OR telefone LIKE ? OR email LIKE ?
                ORDER BY nome
                LIMIT $limit
            ");
            $stmt->execute([$like, $like, $like]);
            break;

        case 'processo':
            $stmt = $pdo->prepare("
                SELECT id,
                       COALESCE(numero, CONCAT('Processo #', id)) AS label,
                       COALESCE(cliente_nome, '') AS sub
                FROM processos
                WHERE deleted_at IS NULL
                  AND (numero LIKE ? OR cliente_nome LIKE ? OR tipo_acao LIKE ?)
                ORDER BY updated_at DESC
                LIMIT $limit
            ");
            $stmt->execute([$like, $like, $like]);
            break;

        case 'card':
            $stmt = $pdo->prepare("
                SELECT id,
                       COALESCE(cliente_nome, CONCAT('Card #', id)) AS label,
                       COALESCE(empresa_nome, '') AS sub
                FROM cards
                WHERE deleted_at IS NULL
                  AND (cliente_nome LIKE ? OR empresa_nome LIKE ?)
                ORDER BY updated_at DESC
                LIMIT $limit
            ");
            $stmt->execute([$like, $like]);
            break;

        case 'dre_account':
            $stmt = $pdo->prepare("
                SELECT id,
                       nome AS label,
                       tipo AS sub
                FROM dre_accounts
                WHERE nome LIKE ? OR codigo LIKE ?
                ORDER BY nome
                LIMIT $limit
            ");
            $stmt->execute([$like, $like]);
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
