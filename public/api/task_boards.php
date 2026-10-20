<?php
require_once __DIR__ . '/_json_guard.php';   // avisos PHP nunca vazam como HTML no JSON (anti "Unexpected token '<'")
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Tarefas\TaskBoard;
use App\Tarefas\TaskColumn;
use App\Core\AccountContext;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx       = AccountContext::fromSession();   // aborta com 401 se não autenticado
$userId    = $ctx->getUserId();
$accountId = $ctx->getAccountId();
$isAdmin   = $ctx->isOwnerOrAdmin();          // usa role do multi-tenancy (owner|admin)
$accIds    = $ctx->getAccessibleAccountIds('tarefas');  // P1 LGPD (2B.3): escopo tenant
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
// Ambientes da Inovaize (app/Ambientes, 20/10/2026): quadro com área, cliente e
// modelo de colunas. Só na conta com o módulo; nas outras nada disto muda.
$ambientes = false;
try { $ambientes = \App\Ambientes\Ambiente::habilitado(\App\Master\Account::findById((int) $accountId) ?? []); } catch (\Throwable $e) { $ambientes = false; }

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        // P1 LGPD (2B.3): findById restrito ao tenant; canView idem
        $b = TaskBoard::findById((int)$_GET['id'], $accIds);
        if (!$b || !TaskBoard::canView((int)$_GET['id'], $userId, $accIds, $isAdmin)) fail('Não encontrado', 404);
        $b['colunas']  = \App\Tarefas\TaskColumn::findByBoard($b['id']);
        $b['membros']  = TaskBoard::members($b['id']);
        ok($b);
    }
    // Matriz vê os boards das filiais vinculadas; filial vê só os seus
    $lista = TaskBoard::findForUser($userId, $accIds, $isAdmin);
    if ($ambientes) {
        // ?area=software|digital: só os quadros daquela área (a tela de Tarefas no
        // ambiente); ?cliente=N: só os daquele cliente. Sem filtro: todos, como sempre.
        $area = isset($_GET['area']) && isset(\App\Ambientes\QuadroDaArea::AREAS[$_GET['area']]) ? (string) $_GET['area'] : null;
        $lista = \App\Ambientes\QuadroDaArea::filtrar($lista, $area, !empty($_GET['cliente']) ? (int) $_GET['cliente'] : null);
        $lista = \App\Ambientes\QuadroDaArea::comNomeDoCliente((int) $accountId, $lista);
    }
    ok($lista);
}

if (in_array($method, ['POST','PUT','DELETE'])) {
    if (!csrfOk()) fail('CSRF inválido');
}

if ($method === 'POST') {
    if ($action === 'members') {
        $boardId = (int)($input['board_id'] ?? 0);
        // Gerenciar membros e administrar o quadro, não editar tarefas: canManage.
        if (!TaskBoard::canManage($boardId, $userId, $accIds, $isAdmin)) fail('Sem permissão', 403);
        $op = $input['op'] ?? 'add';
        if ($op === 'remove') {
            TaskBoard::removeMember($boardId, (int)$input['user_id']);
        } else {
            TaskBoard::addMember($boardId, (int)$input['user_id'], $input['papel'] ?? 'editor');
        }
        ok();
    }
    if (empty($input['nome'])) fail('Nome obrigatório');
    if ($ambientes && (!empty($input['area']) || !empty($input['cliente_id']) || !empty($input['modelo']))) {
        try { \App\Ambientes\QuadroDaArea::validar((int) $accountId, $input); } catch (\InvalidArgumentException $e) { fail($e->getMessage()); }
    }
    $id = TaskBoard::create([
        'nome'       => $input['nome'],
        'descricao'  => $input['descricao'] ?? null,
        'tipo'       => $input['tipo'] ?? 'pessoal',
        'owner_id'   => $userId,
        'account_id' => $accountId,   // sempre da sessão, nunca do body
        'cor'        => $input['cor'] ?? '#6366f1',
    ]);
    if ($ambientes && (!empty($input['area']) || !empty($input['cliente_id']) || !empty($input['modelo']))) {
        \App\Ambientes\QuadroDaArea::definir((int) $accountId, $id, $input);
        \App\Ambientes\QuadroDaArea::semearColunas($id, !empty($input['modelo']) ? (string) $input['modelo'] : null);
    }
    ok(['id' => $id]);
}

if ($method === 'PUT') {
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    // Renomear e trocar o tipo é administrar o quadro. Com canEdit, qualquer
    // pessoa da equipe poderia transformar um quadro compartilhado em pessoal.
    if (!TaskBoard::canManage($id, $userId, $accIds, $isAdmin)) fail('Sem permissão', 403);
    if ($ambientes && (array_key_exists('area', $input) || array_key_exists('cliente_id', $input))) {
        $b = TaskBoard::findById($id, $accIds);
        try {
            \App\Ambientes\QuadroDaArea::definir((int) $b['account_id'], $id, [
                'area' => array_key_exists('area', $input) ? $input['area'] : ($b['area'] ?? null),
                'cliente_id' => array_key_exists('cliente_id', $input) ? $input['cliente_id'] : ($b['cliente_id'] ?? null),
                'modelo' => $b['modelo'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) { fail($e->getMessage()); }
    }
    TaskBoard::update($id, $input);
    ok();
}

if ($method === 'DELETE') {
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    // P1 LGPD (2B.3): findById restrito ao tenant — admin de tenant A não
    // pode deletar board de tenant B mesmo com ID conhecido
    $b  = TaskBoard::findById($id, $accIds);
    if (!$b || ((int)$b['owner_id'] !== $userId && !$isAdmin)) fail('Sem permissão', 403);
    TaskBoard::delete($id);
    ok();
}

fail('Método não suportado', 405);
