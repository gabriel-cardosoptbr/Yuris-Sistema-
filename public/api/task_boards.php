<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/TaskBoard.php';
require_once __DIR__ . '/../../app/Models/TaskColumn.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Helpers\AccountContext;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx       = AccountContext::fromSession();   // aborta com 401 se não autenticado
$userId    = $ctx->getUserId();
$accountId = $ctx->getAccountId();
$isAdmin   = $ctx->isOwnerOrAdmin();          // usa role do multi-tenancy (owner|admin)
$method    = $_SERVER['REQUEST_METHOD'];
$input     = json_decode(file_get_contents('php://input'), true) ?? [];

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
    if (isset($_GET['id'])) {
        $b = TaskBoard::findById((int)$_GET['id']);
        if (!$b || !TaskBoard::canView((int)$_GET['id'], $userId)) fail('Não encontrado', 404);
        $b['colunas']  = \App\Models\TaskColumn::findByBoard($b['id']);
        $b['membros']  = TaskBoard::members($b['id']);
        ok($b);
    }
    // Matriz vê os boards das filiais vinculadas; filial vê só os seus
    ok(TaskBoard::findForUser($userId, $ctx->getAccessibleAccountIds('tarefas')));
}

if (in_array($method, ['POST','PUT','DELETE'])) {
    if (!csrfOk()) fail('CSRF inválido');
}

if ($method === 'POST') {
    if ($action === 'members') {
        $boardId = (int)($input['board_id'] ?? 0);
        if (!TaskBoard::canEdit($boardId, $userId)) fail('Sem permissão', 403);
        $op = $input['op'] ?? 'add';
        if ($op === 'remove') {
            TaskBoard::removeMember($boardId, (int)$input['user_id']);
        } else {
            TaskBoard::addMember($boardId, (int)$input['user_id'], $input['papel'] ?? 'editor');
        }
        ok();
    }
    if (empty($input['nome'])) fail('Nome obrigatório');
    $id = TaskBoard::create([
        'nome'       => $input['nome'],
        'descricao'  => $input['descricao'] ?? null,
        'tipo'       => $input['tipo'] ?? 'pessoal',
        'owner_id'   => $userId,
        'account_id' => $accountId,   // sempre da sessão, nunca do body
        'cor'        => $input['cor'] ?? '#6366f1',
    ]);
    ok(['id' => $id]);
}

if ($method === 'PUT') {
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if (!TaskBoard::canEdit($id, $userId)) fail('Sem permissão', 403);
    TaskBoard::update($id, $input);
    ok();
}

if ($method === 'DELETE') {
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    $b  = TaskBoard::findById($id);
    if (!$b || ((int)$b['owner_id'] !== $userId && !$isAdmin)) fail('Sem permissão', 403);
    TaskBoard::delete($id);
    ok();
}

fail('Método não suportado', 405);
