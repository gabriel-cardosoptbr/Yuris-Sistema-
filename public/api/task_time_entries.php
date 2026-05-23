<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Models/TaskTimeEntry.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../app/Helpers/TenantGuard.php';

use App\Models\TaskTimeEntry;
use App\Helpers\AccountContext;
use App\Helpers\TenantGuard;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx     = AccountContext::fromSession();
$userId  = $ctx->getUserId();
$method  = $_SERVER['REQUEST_METHOD'];
$input   = json_decode(file_get_contents('php://input'), true) ?? [];

function csrfOk(): bool {
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($GLOBALS['input']['csrf_token'] ?? null);
    return $tok && $tok === ($_SESSION['csrf_token'] ?? '');
}
function fail(string $msg, int $c = 400): void { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
function ok(mixed $d = null): void { echo json_encode(['ok'=>true,'data'=>$d]); exit; }

$action  = $_GET['action'] ?? $input['action'] ?? null;
$taskId  = (int)($input['task_id'] ?? $_GET['task_id'] ?? 0);

if ($method === 'GET') {
    if ($taskId <= 0) fail('task_id obrigatório');
    TenantGuard::assertTaskAcessivel($ctx, $taskId);
    $rows  = TaskTimeEntry::findByTask($taskId);
    $total = TaskTimeEntry::totalMinutos($taskId);
    $timer = TaskTimeEntry::activeTimer($taskId, $userId);
    ok(['entries' => $rows, 'total_minutos' => $total, 'timer_ativo' => $timer]);
}

if (!csrfOk()) fail('CSRF inválido');

if ($method === 'POST') {
    if ($taskId <= 0) fail('task_id obrigatório');
    TenantGuard::assertTaskAcessivel($ctx, $taskId);

    if ($action === 'start') { TaskTimeEntry::startTimer($taskId, $userId); ok(); }
    if ($action === 'stop')  { TaskTimeEntry::stopTimer($taskId, $userId);  ok(); }
    // manual
    if (empty($input['inicio'])) fail('Início obrigatório');
    $id = TaskTimeEntry::addManual($taskId, $userId, $input);
    ok(['id' => $id]);
}

fail('Método não suportado', 405);
