<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/DRECode.php';

use App\Models\DRECode;
use App\Models\Database;

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $rec = DRECode::find((int)$_GET['id']);
        echo json_encode(['data' => $rec]);
        exit;
    }
    $list = DRECode::listAll();
    echo json_encode(['data' => $list]);
    exit;
}

// CSRF for state changes
if (in_array($method, ['POST','PUT','PATCH','DELETE'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error'=>'Invalid CSRF token']);
        exit;
    }
}

if ($method === 'POST') {
    if (empty($input['code'])) { http_response_code(400); echo json_encode(['error'=>'Missing code']); exit; }
    $id = DRECode::create($input);
    echo json_encode(['success'=>true,'id'=>$id]);
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
    if (empty($input['id'])) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    $ok = DRECode::update((int)$input['id'], $input);
    echo json_encode(['success'=>(bool)$ok]);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? ($input['id'] ?? null);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    $ok = DRECode::softDelete((int)$id);
    echo json_encode(['success'=>(bool)$ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);
