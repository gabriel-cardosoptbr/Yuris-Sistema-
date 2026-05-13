<?php
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/TaskChecklist.php';
require_once __DIR__ . '/../../app/Models/Task.php';

use App\Models\TaskChecklist;
use App\Models\Task;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$userId = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

function tcCsrfOk(): bool {
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($GLOBALS['input']['csrf_token'] ?? null);
    return $tok && $tok === ($_SESSION['csrf_token'] ?? '');
}
function tcFail(string $msg, int $c = 400): void { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
function tcOk($d = null): void { echo json_encode(['ok'=>true,'data'=>$d]); exit; }

$action = $_GET['action'] ?? null;

try {
    if ($method === 'GET') {
        tcOk(TaskChecklist::findByTask((int)($_GET['task_id'] ?? 0)));
    }

    if (!tcCsrfOk()) tcFail('CSRF inválido');

    if ($method === 'POST') {
        if ($action === 'toggle') {
            TaskChecklist::toggle((int)$input['id']);
            tcOk();
        }
        if ($action === 'reorder') {
            TaskChecklist::reorder((int)$input['task_id'], $input['ids'] ?? []);
            tcOk();
        }
        if (empty($input['descricao'])) tcFail('Descrição obrigatória');
        $id = TaskChecklist::create((int)$input['task_id'], $input['descricao'], $input['prazo'] ?? null);
        tcOk(['id' => $id]);
    }

    if ($method === 'PATCH' || ($method === 'POST' && $action === 'update')) {
        $id = (int)($input['id'] ?? 0);
        if (!$id) tcFail('ID obrigatório');
        if (empty($input['descricao'])) tcFail('Descrição obrigatória');
        TaskChecklist::update($id, $input['descricao'], $input['prazo'] ?? null);
        tcOk();
    }

    if ($method === 'DELETE') {
        TaskChecklist::delete((int)($input['id'] ?? $_GET['id'] ?? 0));
        tcOk();
    }

    tcFail('Método não suportado', 405);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
