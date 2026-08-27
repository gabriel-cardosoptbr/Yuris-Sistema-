<?php
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Master/Account.php';
require_once __DIR__ . '/../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../app/Tarefas/TaskChecklist.php';
require_once __DIR__ . '/../../app/Tarefas/Task.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../app/Core/TenantGuard.php';
require_once __DIR__ . '/../../app/Processos/ProcessoAudit.php';
require_once __DIR__ . '/../../app/Tarefas/TaskAudit.php';

use App\Tarefas\TaskChecklist;
use App\Tarefas\Task;
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

function tcCsrfOk(): bool {
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($GLOBALS['input']['csrf_token'] ?? null);
    return $tok && $tok === ($_SESSION['csrf_token'] ?? '');
}
function tcFail(string $msg, int $c = 400): void { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
function tcOk($d = null): void { echo json_encode(['ok'=>true,'data'=>$d]); exit; }

$action = $_GET['action'] ?? null;

// Helper local: descobre o task_id de um checklist_item e valida tenant
function tc_assertChecklistTenant(\App\Core\AccountContext $ctx, int $checklistId): void {
    $pdo = \App\Core\Database::getConnection();
    $stmt = $pdo->prepare('SELECT task_id FROM task_checklist_items WHERE id = ? LIMIT 1');
    $stmt->execute([$checklistId]);
    $tid = (int)($stmt->fetchColumn() ?: 0);
    if (!$tid) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Item não encontrado']); exit; }
    \App\Core\TenantGuard::assertTaskAcessivel($ctx, $tid);
}

try {
    if ($method === 'GET') {
        $taskId = (int)($_GET['task_id'] ?? 0);
        if (!$taskId) tcFail('task_id obrigatório');
        TenantGuard::assertTaskAcessivel($ctx, $taskId);
        tcOk(TaskChecklist::findByTask($taskId));
    }

    if (!tcCsrfOk()) tcFail('CSRF inválido');

    if ($method === 'POST') {
        if ($action === 'toggle') {
            $id = (int)($input['id'] ?? 0);
            if (!$id) tcFail('id obrigatório');
            tc_assertChecklistTenant($ctx, $id);
            // Captura info do item antes do toggle pra logar bem
            $pdo  = \App\Core\Database::getConnection();
            $stmt = $pdo->prepare('SELECT task_id, descricao, concluido FROM task_checklist_items WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            TaskChecklist::toggle($id);
            if ($row) {
                $acao = empty($row['concluido']) ? 'item concluído' : 'item reaberto';
                TaskAudit::onChecklistChange((int)$row['task_id'], $acao, (string)$row['descricao']);
            }
            tcOk();
        }
        if ($action === 'reorder') {
            $taskId = (int)($input['task_id'] ?? 0);
            if (!$taskId) tcFail('task_id obrigatório');
            TenantGuard::assertTaskAcessivel($ctx, $taskId);
            TaskChecklist::reorder($taskId, $input['ids'] ?? []);
            tcOk();
        }
        if (empty($input['descricao'])) tcFail('Descrição obrigatória');
        $taskId = (int)($input['task_id'] ?? 0);
        if (!$taskId) tcFail('task_id obrigatório');
        TenantGuard::assertTaskAcessivel($ctx, $taskId);
        $id = TaskChecklist::create($taskId, $input['descricao'], $input['prazo'] ?? null);
        TaskAudit::onChecklistChange($taskId, 'item criado', (string)$input['descricao']);
        tcOk(['id' => $id]);
    }

    if ($method === 'PATCH' || ($method === 'POST' && $action === 'update')) {
        $id = (int)($input['id'] ?? 0);
        if (!$id) tcFail('ID obrigatório');
        if (empty($input['descricao'])) tcFail('Descrição obrigatória');
        tc_assertChecklistTenant($ctx, $id);
        // Captura task_id pro audit
        $pdo  = \App\Core\Database::getConnection();
        $stmt = $pdo->prepare('SELECT task_id FROM task_checklist_items WHERE id = ?');
        $stmt->execute([$id]);
        $tid = (int)($stmt->fetchColumn() ?: 0);
        TaskChecklist::update($id, $input['descricao'], $input['prazo'] ?? null);
        if ($tid > 0) TaskAudit::onChecklistChange($tid, 'item editado', (string)$input['descricao']);
        tcOk();
    }

    if ($method === 'DELETE') {
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) tcFail('id obrigatório');
        tc_assertChecklistTenant($ctx, $id);
        // Captura task_id e descrição antes de deletar
        $pdo  = \App\Core\Database::getConnection();
        $stmt = $pdo->prepare('SELECT task_id, descricao FROM task_checklist_items WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        TaskChecklist::delete($id);
        if ($row) TaskAudit::onChecklistChange((int)$row['task_id'], 'item removido', (string)$row['descricao']);
        tcOk();
    }

    tcFail('Método não suportado', 405);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
