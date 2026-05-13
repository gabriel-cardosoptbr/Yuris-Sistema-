<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/TaskBoard.php';
require_once __DIR__ . '/../../app/Models/TaskColumn.php';

use App\Models\TaskBoard;
use App\Models\TaskColumn;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$userId = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

function csrfOk(): bool {
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($GLOBALS['input']['csrf_token'] ?? null);
    return $tok && $tok === ($_SESSION['csrf_token'] ?? '');
}
function fail(string $msg, int $code = 400): void {
    http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit;
}
function ok(mixed $data = null): void {
    echo json_encode(['ok'=>true,'data'=>$data]); exit;
}

$action = $_GET['action'] ?? null;

if ($method === 'GET') {
    $boardId = (int)($_GET['board_id'] ?? 0);
    if (!$boardId || !TaskBoard::canView($boardId, $userId)) fail('Sem acesso', 403);
    ok(TaskColumn::findByBoard($boardId));
}

if (in_array($method, ['POST','PUT','DELETE'])) {
    if (!csrfOk()) fail('CSRF inválido');
}

if ($method === 'POST') {
    if ($action === 'reorder') {
        $boardId = (int)($input['board_id'] ?? 0);
        if (!TaskBoard::canEdit($boardId, $userId)) fail('Sem permissão', 403);
        TaskColumn::reorder($boardId, $input['ids'] ?? []);
        ok();
    }
    $boardId = (int)($input['board_id'] ?? 0);
    if (!TaskBoard::canEdit($boardId, $userId)) fail('Sem permissão', 403);
    if (empty($input['nome'])) fail('Nome obrigatório');
    $id = TaskColumn::create([
        'board_id'           => $boardId,
        'nome'               => $input['nome'],
        'cor'                => $input['cor'] ?? '#94a3b8',
        'is_coluna_inicial'  => $input['is_coluna_inicial']   ?? 0,
        'is_coluna_concluido'=> $input['is_coluna_concluido'] ?? 0,
    ]);
    ok(['id' => $id]);
}

if ($method === 'PUT') {
    $id  = (int)($input['id'] ?? $_GET['id'] ?? 0);
    $col = TaskColumn::findById($id);
    if (!$col || !TaskBoard::canEdit($col['board_id'], $userId)) fail('Sem permissão', 403);
    TaskColumn::update($id, $input);
    ok();
}

if ($method === 'DELETE') {
    $id  = (int)($input['id'] ?? $_GET['id'] ?? 0);
    $col = TaskColumn::findById($id);
    if (!$col || !TaskBoard::canEdit($col['board_id'], $userId)) fail('Sem permissão', 403);
    try {
        TaskColumn::delete($id);
        ok();
    } catch (\RuntimeException $e) {
        fail($e->getMessage());
    }
}

fail('Método não suportado', 405);
