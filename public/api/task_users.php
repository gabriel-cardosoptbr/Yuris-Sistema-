<?php
require_once __DIR__ . '/../../app/Models/Database.php';

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo  = \App\Models\Database::getConnection();
$stmt = $pdo->query("SELECT id, nome FROM users WHERE deleted_at IS NULL AND status = 'active' ORDER BY nome");
$users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
echo json_encode(['ok' => true, 'data' => $users]);
