<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Tarefas\TaskLink;
use App\Core\AccountContext;
use App\Core\TenantGuard;
use App\Tarefas\TaskAudit;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx = AccountContext::fromSession();   // aborta 401 se não autenticado

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
    if ($taskId <= 0) fail('task_id obrigatório');
    // Valida tenant ANTES de retornar dados — bloqueia leitura cross-tenant
    TenantGuard::assertTaskAcessivel($ctx, $taskId);
    ok(TaskLink::findByTask($taskId));
}

if (!csrfOk()) fail('CSRF inválido');

if ($method === 'POST') {
    $taskId   = (int)($input['task_id'] ?? 0);
    $linkType = $input['link_type'] ?? '';
    $linkId   = (int)($input['link_id'] ?? 0);
    if ($taskId <= 0 || $linkType === '' || $linkId <= 0) fail('Parâmetros inválidos');
    if (!in_array($linkType, ['contato','processo','card','dre_account'], true)) fail('link_type inválido');

    // Valida tenant da TAREFA (deve estar acessível pelo usuário)
    TenantGuard::assertTaskAcessivel($ctx, $taskId);
    // Valida tenant do ALVO do vínculo (não pode vincular a processo/card de outro tenant)
    if ($linkType === 'processo') TenantGuard::assertProcessoAcessivel($ctx, $linkId);
    if ($linkType === 'card')     TenantGuard::assertCardAcessivel($ctx, $linkId);
    // (contato e dre_account ficam restritos via SQL nos respectivos endpoints; aqui aceitamos)

    $id = TaskLink::add($taskId, $linkType, $linkId);

    // Propaga ao histórico processual quando o vínculo é com um processo
    TaskAudit::onLinkCreated($taskId, $linkType, $linkId);

    ok(['id' => $id]);
}

if ($method === 'DELETE') {
    $linkRowId = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if ($linkRowId <= 0) fail('id obrigatório');

    // Busca o vínculo para validar tenant ANTES de remover
    $pdo  = \App\Core\Database::getConnection();
    $stmt = $pdo->prepare('SELECT task_id, link_type, link_id FROM task_links WHERE id = ? LIMIT 1');
    $stmt->execute([$linkRowId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) fail('Vínculo não encontrado', 404);
    TenantGuard::assertTaskAcessivel($ctx, (int)$row['task_id']);

    TaskLink::remove($linkRowId);

    // Propaga ao histórico processual quando desvincula de um processo
    TaskAudit::onLinkRemoved((int)$row['task_id'], (string)$row['link_type'], (int)$row['link_id']);

    ok();
}

fail('Método não suportado', 405);
