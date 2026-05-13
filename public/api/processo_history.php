<?php
require_once __DIR__ . '/../../app/Models/Database.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

use App\Models\Database;

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

$method = $_SERVER['REQUEST_METHOD'];
$userDisplay = $_SESSION['user_nome'] ?? $_SESSION['user_email'] ?? 'sistema';

try {
    if ($method === 'GET') {
        $processoId = (int)($_GET['processo_id'] ?? 0);
        if (!$processoId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing processo_id']);
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT * FROM processo_history WHERE processo_id = :pid ORDER BY created_at DESC LIMIT 50"
        );
        $stmt->execute(['pid' => $processoId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['data' => $history]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $processoId = (int)($input['processo_id'] ?? 0);
        $acao = trim($input['acao'] ?? '');
        $descricao = trim($input['descricao'] ?? '');
        if (!$processoId || !$acao) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing fields']);
            exit;
        }
        $pdo->prepare(
            "INSERT INTO processo_history (processo_id, user_email, acao, descricao) VALUES (:pid, :user, :acao, :desc)"
        )->execute([':pid' => $processoId, ':user' => $userDisplay, ':acao' => $acao, ':desc' => $descricao]);
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
