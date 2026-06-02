<?php
require_once __DIR__ . '/_json_guard.php';   // avisos PHP nunca vazam como HTML no JSON (anti "Unexpected token '<'")
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/TaskBoard.php';
require_once __DIR__ . '/../../app/Models/TaskColumn.php';
require_once __DIR__ . '/../../app/Models/TaskRecurrence.php';
require_once __DIR__ . '/../../app/Models/TaskLink.php';
require_once __DIR__ . '/../../app/Models/Task.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../app/Helpers/ProcessoAudit.php';
require_once __DIR__ . '/../../app/Helpers/TaskAudit.php';
require_once __DIR__ . '/../../app/Services/RecurrenceCronService.php';

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskRecurrence;
use App\Helpers\AccountContext;
use App\Helpers\TaskAudit;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx     = AccountContext::fromSession();   // aborta com 401 se não autenticado
$userId  = $ctx->getUserId();
$isAdmin = $ctx->isOwnerOrAdmin();          // usa role do multi-tenancy (owner|admin)
$method  = $_SERVER['REQUEST_METHOD'];
$input   = json_decode(file_get_contents('php://input'), true) ?? [];

// P1 LGPD (2B.3): contas acessíveis para escopar TaskBoard::canView/canEdit
$accIds  = $ctx->getAccessibleAccountIds('tarefas');

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

function canEditTask(array $task, int $userId, bool $isAdmin, array $accIds = []): bool {
    if ($isAdmin) return true;
    if ((int)$task['criado_por_id'] === $userId) return true;
    if ((int)$task['responsavel_id'] === $userId) return true;
    // P1 LGPD (2B.3): escopa por tenant também via canEdit
    return TaskBoard::canEdit((int)$task['board_id'], $userId, $accIds ?: null);
}

$action = $_GET['action'] ?? null;

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $task = Task::withDetails((int)$_GET['id']);
        if (!$task || !TaskBoard::canView((int)$task['board_id'], $userId, $accIds)) fail('Não encontrado', 404);
        ok($task);
    }
    $boardId = (int)($_GET['board_id'] ?? 0);
    if (!$boardId || !TaskBoard::canView($boardId, $userId, $accIds)) fail('Sem acesso', 403);

    // Cron interno: gera instâncias atrasadas de tarefas recorrentes (máx. 1x/hora)
    \App\Services\RecurrenceCronService::tickIfDue();

    $filtros = [];
    if (isset($_GET['column_id']))     $filtros['column_id']     = (int)$_GET['column_id'];
    if (isset($_GET['responsavel_id']))$filtros['responsavel_id']= (int)$_GET['responsavel_id'];
    if (isset($_GET['prioridade']))    $filtros['prioridade']    = $_GET['prioridade'];
    if (isset($_GET['prazo']))         $filtros['prazo']         = $_GET['prazo'];
    if (isset($_GET['busca']))         $filtros['busca']         = $_GET['busca'];

    ok(Task::findByBoard($boardId, $filtros));
}

// ── Mutations precisam de CSRF ────────────────────────────────────────────────
if (in_array($method, ['POST','PUT','DELETE'])) {
    if (!csrfOk()) fail('CSRF inválido');
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    // move (drag-and-drop)
    if ($action === 'move') {
        $task = Task::findById((int)($input['id'] ?? 0));
        if (!$task || !TaskBoard::canView((int)$task['board_id'], $userId, $accIds)) fail('Não encontrado', 404);
        Task::move((int)$task['id'], (int)$input['column_id'], (int)($input['ordem'] ?? 0), $userId);
        // Propaga ao histórico processual se a tarefa está vinculada a algum processo
        $colNome = null;
        try {
            $colInfo = TaskColumn::findById((int)$input['column_id']);
            $colNome = $colInfo['nome'] ?? null;
        } catch (\Throwable $_e) {}
        TaskAudit::onTaskMoved((int)$task['id'], $colNome);
        ok();
    }

    // concluir
    if ($action === 'complete') {
        $task = Task::findById((int)($input['id'] ?? 0));
        if (!$task) fail('Não encontrado', 404);
        if (!canEditTask($task, $userId, $isAdmin, $accIds)) fail('Sem permissão', 403);
        $result = Task::complete((int)$task['id'], $userId);
        // Propaga ao histórico processual se vinculada
        TaskAudit::onTaskCompleted((int)$task['id']);
        ok($result); // devolve { renovada, proxima_data } ao frontend
    }

    // criar
    $boardId  = (int)($input['board_id']  ?? 0);
    $columnId = (int)($input['column_id'] ?? 0);
    if (!$boardId || !TaskBoard::canEdit($boardId, $userId, $accIds)) fail('Sem permissão', 403);
    if (empty($input['titulo'])) fail('Título obrigatório');

    // coluna padrão se não informada
    if (!$columnId) {
        $col = TaskColumn::initialColumn($boardId);
        if (!$col) fail('Board sem colunas');
        $columnId = $col['id'];
    }

    // recorrência
    $recId = null;
    if (!empty($input['recorrencia'])) {
        $rec = $input['recorrencia'];
        $rec['data_inicio'] = $rec['data_inicio'] ?? date('Y-m-d');
        $recId = TaskRecurrence::create($rec);
    }

    $id = Task::create([
        'board_id'       => $boardId,
        'column_id'      => $columnId,
        'titulo'         => $input['titulo'],
        'descricao'      => $input['descricao'] ?? null,
        'prioridade'     => $input['prioridade'] ?? 'media',
        'prazo'          => $input['prazo'] ?? null,
        'prazo_tipo'     => $input['prazo_tipo'] ?? 'interno',
        'responsavel_id' => $input['responsavel_id'] ?? null,
        'criado_por_id'  => $userId,
        'recorrencia_id' => $recId,
    ]);
    // NOTA: vínculos com processo são criados em /api/task_links.php (POST) — propagação acontece lá.
    // Se o frontend enviar vínculos junto na criação, a propagação roda quando o link for criado.
    ok(['id' => $id]);
}

// ── PUT ───────────────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $id   = (int)($input['id'] ?? $_GET['id'] ?? 0);
    $task = Task::findById($id);
    if (!$task) fail('Não encontrado', 404);
    if (!canEditTask($task, $userId, $isAdmin, $accIds)) fail('Sem permissão', 403);

    // Captura "antes" para propagar diff ao histórico processual (se vinculada)
    $diffFields = ['titulo','descricao','prioridade','prazo','prazo_tipo','responsavel_id','status'];
    $changes = [];
    foreach ($diffFields as $f) {
        if (array_key_exists($f, $input) && (string)($task[$f] ?? '') !== (string)$input[$f]) {
            $changes[$f] = [(string)($task[$f] ?? ''), (string)$input[$f]];
        }
    }

    Task::update($id, $input, $userId);

    if (!empty($changes)) TaskAudit::onTaskUpdated($id, $changes);

    // recorrência: criar nova, atualizar existente ou desativar
    if (array_key_exists('recorrencia', $input)) {
        $pdo = \App\Models\Database::getConnection();
        if (empty($input['recorrencia'])) {
            // desativar
            if ($task['recorrencia_id']) {
                TaskRecurrence::deactivate((int)$task['recorrencia_id']);
                $pdo->prepare('UPDATE tasks SET recorrencia_id = NULL WHERE id = ?')->execute([$id]);
            }
        } else {
            $rec = $input['recorrencia'];
            $rec['data_inicio'] = $rec['data_inicio'] ?? date('Y-m-d');
            if ($task['recorrencia_id']) {
                // atualiza existente
                // FIX (auditoria 2026-06-01 / ALTA #19): inclui 'unidade' nos campos
                // atualizaveis — sem isso a edicao de uma recorrencia 'custom' nunca
                // gravava a unidade (semana/mes/ano) e ela voltava a 'day'.
                $fields = []; $params = [];
                foreach (['tipo','intervalo','unidade','dias_semana','dia_mes','data_inicio','data_fim'] as $f) {
                    if (array_key_exists($f, $rec)) {
                        $fields[] = "$f = :$f";
                        $params[$f] = $f === 'dias_semana' ? json_encode($rec[$f]) : $rec[$f];
                    }
                }
                if ($fields) {
                    $params['id'] = $task['recorrencia_id'];
                    $pdo->prepare('UPDATE task_recurrences SET ativa = 1, ' . implode(', ', $fields) . ' WHERE id = :id')
                        ->execute($params);
                }
            } else {
                // cria nova e vincula
                $recId = TaskRecurrence::create($rec);
                $pdo->prepare('UPDATE tasks SET recorrencia_id = ? WHERE id = ?')->execute([$recId, $id]);
            }
        }
    }

    ok();
}

// ── DELETE (arquivar) ─────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id   = (int)($input['id'] ?? $_GET['id'] ?? 0);
    $task = Task::findById($id);
    if (!$task) fail('Não encontrado', 404);
    if (!canEditTask($task, $userId, $isAdmin, $accIds)) fail('Sem permissão', 403);
    // Captura ANTES de arquivar, pois TaskAudit lê task_links que ainda existem
    TaskAudit::onTaskArchived($id);
    Task::archive($id, $userId);
    ok();
}

fail('Método não suportado', 405);
