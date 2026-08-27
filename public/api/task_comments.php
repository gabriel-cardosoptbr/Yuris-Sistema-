<?php
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Master/Account.php';
require_once __DIR__ . '/../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../app/Tarefas/TaskComment.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../app/Core/TenantGuard.php';
require_once __DIR__ . '/../../app/Processos/ProcessoAudit.php';
require_once __DIR__ . '/../../app/Tarefas/TaskAudit.php';

use App\Tarefas\TaskComment;
use App\Core\AccountContext;
use App\Core\TenantGuard;
use App\Tarefas\TaskAudit;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx = AccountContext::fromSession();

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
    $taskId = (int)($_GET['task_id'] ?? 0);
    if (!$taskId) fail('task_id obrigatório');
    TenantGuard::assertTaskAcessivel($ctx, $taskId);
    ok(TaskComment::findByTask($taskId));
}

if (!csrfOk()) fail('CSRF inválido');

if ($method === 'POST') {
    if (empty($input['mensagem'])) fail('Mensagem obrigatória');
    $taskId = (int)($input['task_id'] ?? 0);
    if (!$taskId) fail('task_id obrigatório');
    TenantGuard::assertTaskAcessivel($ctx, $taskId);
    $id = TaskComment::create($taskId, $userId, $input['mensagem']);
    // Propaga ao histórico processual se a tarefa está vinculada a processo(s)
    TaskAudit::onCommentAdded($taskId, $input['mensagem']);
    ok(['id' => $id]);
}

if ($method === 'DELETE') {
    $commentId = (int)($input['id'] ?? $_GET['id'] ?? 0);
    // Busca a task associada ANTES de deletar (TaskAudit precisa do task_id)
    $pdo  = \App\Core\Database::getConnection();
    $stmt = $pdo->prepare('SELECT task_id FROM task_comments WHERE id = ? LIMIT 1');
    $stmt->execute([$commentId]);
    $tid = (int)($stmt->fetchColumn() ?: 0);
    if ($tid > 0) TenantGuard::assertTaskAcessivel($ctx, $tid);
    TaskComment::delete($commentId, $userId);
    if ($tid > 0) TaskAudit::onCommentRemoved($tid);
    ok();
}

fail('Método não suportado', 405);
