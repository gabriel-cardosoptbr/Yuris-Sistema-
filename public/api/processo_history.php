<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../app/Helpers/TenantGuard.php';

use App\Models\Database;
use App\Helpers\AccountContext;
use App\Helpers\TenantGuard;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$ctx       = AccountContext::fromSession();
$tenantIds = $ctx->getAccessibleAccountIds('processos');

$pdo = Database::getConnection();

// Criar tabela processo_history se não existir
$pdo->exec("CREATE TABLE IF NOT EXISTS processo_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    processo_id INT NOT NULL,
    user_email VARCHAR(150),
    acao VARCHAR(100),
    descricao TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(processo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$method      = $_SERVER['REQUEST_METHOD'];
$userDisplay = $_SESSION['user_nome'] ?? $_SESSION['user_email'] ?? 'sistema';

try {
    if ($method === 'GET') {
        $processoId = (int)($_GET['processo_id'] ?? 0);
        if (!$processoId) { http_response_code(400); echo json_encode(['error' => 'Missing processo_id']); exit; }
        TenantGuard::assertProcessoAcessivel($ctx, $processoId);

        $stmt = $pdo->prepare(
            "SELECT * FROM processo_history WHERE processo_id = :pid ORDER BY created_at DESC LIMIT 50"
        );
        $stmt->execute(['pid' => $processoId]);
        echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($method === 'POST') {
        $input      = json_decode(file_get_contents('php://input'), true) ?? [];
        $processoId = (int)($input['processo_id'] ?? 0);
        $acao       = trim($input['acao'] ?? '');
        $descricao  = trim($input['descricao'] ?? '');
        if (!$processoId || !$acao) { http_response_code(400); echo json_encode(['error' => 'Missing fields']); exit; }
        TenantGuard::assertProcessoAcessivel($ctx, $processoId);

        // Inclui snapshot da conta do autor pra renderizar badge MATRIZ/FILIAL
        $pdo->prepare(
            "INSERT INTO processo_history
               (processo_id, user_email, acao, descricao,
                author_account_id, author_account_tipo, author_account_nome)
             VALUES (:pid, :user, :acao, :desc, :aid, :atipo, :anome)"
        )->execute([
            ':pid'   => $processoId,
            ':user'  => $userDisplay,
            ':acao'  => $acao,
            ':desc'  => $descricao,
            ':aid'   => isset($_SESSION['account_id']) ? (int)$_SESSION['account_id'] : null,
            ':atipo' => $_SESSION['account_tipo'] ?? null,
            ':anome' => $_SESSION['account_nome'] ?? null,
        ]);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
