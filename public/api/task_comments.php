<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/TaskComment.php';

use App\Models\TaskComment;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$userId = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

function csrfOk(): bool {
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($GLOBALS['input']['csrf_token'] ?? null);
    return $tok && $tok === ($_SESSION['csrf_token'] ?? '');
}
function fail(string $msg, int $c = 400): void { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
function ok(mixed $d = null): void { echo json_encode(['ok'=>true,'data'=>$d]); exit; }

if ($method === 'GET') {
    ok(TaskComment::findByTask((int)($_GET['task_id'] ?? 0)));
}

if (!csrfOk()) fail('CSRF inválido');

if ($method === 'POST') {
    if (empty($input['mensagem'])) fail('Mensagem obrigatória');
    $id = TaskComment::create((int)$input['task_id'], $userId, $input['mensagem']);
    ok(['id' => $id]);
}

if ($method === 'DELETE') {
    TaskComment::delete((int)($input['id'] ?? $_GET['id'] ?? 0), $userId);
    ok();
}

fail('Método não suportado', 405);
