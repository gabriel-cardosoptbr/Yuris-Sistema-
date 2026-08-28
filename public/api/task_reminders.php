<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Tarefas\TaskReminder;
use App\Core\AccountContext;
use App\Core\TenantGuard;

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

if ($method === 'GET') {
    $taskId = (int)($_GET['task_id'] ?? 0);
    if ($taskId <= 0) fail('task_id obrigatório');
    TenantGuard::assertTaskAcessivel($ctx, $taskId);
    ok(TaskReminder::findByTask($taskId));
}
if (!csrfOk()) fail('CSRF inválido');

if ($method === 'POST') {
    if (empty($input['lembrar_em'])) fail('lembrar_em obrigatório');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) fail('task_id obrigatório');
    TenantGuard::assertTaskAcessivel($ctx, $taskId);
    $id = TaskReminder::create($taskId, $userId, $input['lembrar_em'], $input['canal'] ?? 'sistema');
    ok(['id' => $id]);
}

if ($method === 'DELETE') {
    $remId = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if ($remId <= 0) fail('id obrigatório');
    // Busca a task associada para validar tenant
    $pdo  = \App\Core\Database::getConnection();
    $stmt = $pdo->prepare('SELECT task_id FROM task_reminders WHERE id = ? LIMIT 1');
    $stmt->execute([$remId]);
    $tid = (int)($stmt->fetchColumn() ?: 0);
    if ($tid > 0) TenantGuard::assertTaskAcessivel($ctx, $tid);
    TaskReminder::delete($remId);
    ok();
}

fail('Método não suportado', 405);
