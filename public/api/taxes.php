<?php
require_once __DIR__ . '/../../app/Models/Database.php';
use App\Models\Database;

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM taxes WHERE ativo = 1 ORDER BY id ASC");
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
    $stmt = $pdo->prepare("INSERT INTO taxes (nome, percentual) VALUES (:nome, :percentual)");
    $stmt->execute(['nome' => $nome, 'percentual' => $percentual]);
    $id = (int)$pdo->lastInsertId();
    $row = $pdo->prepare("SELECT * FROM taxes WHERE id = :id");
    $row->execute(['id' => $id]);
    echo json_encode(['success' => true, 'data' => $row->fetch(PDO::FETCH_ASSOC)]);
    exit;
}

if ($method === 'PUT') {
    $id         = (int)($input['id'] ?? 0);
    $nome       = trim($input['nome'] ?? '');
    $percentual = (float)str_replace(',', '.', $input['percentual'] ?? '0');
    if (!$id)                                    { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    if (!$nome)                                  { http_response_code(400); echo json_encode(['error' => 'Nome é obrigatório']); exit; }
    if ($percentual < 0 || $percentual > 100)    { http_response_code(400); echo json_encode(['error' => 'Alíquota inválida (0–100)']); exit; }
    $stmt = $pdo->prepare("UPDATE taxes SET nome = :nome, percentual = :percentual, updated_at = NOW() WHERE id = :id AND ativo = 1");
    $stmt->execute(['nome' => $nome, 'percentual' => $percentual, 'id' => $id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $stmt = $pdo->prepare("UPDATE taxes SET ativo = 0, updated_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
