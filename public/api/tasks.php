<?php
require_once __DIR__ . '/_json_guard.php';   // avisos PHP nunca vazam como HTML no JSON (anti "Unexpected token '<'")
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Tarefas\Task;
use App\Tarefas\TaskBoard;
use App\Tarefas\TaskColumn;
use App\Tarefas\TaskRecurrence;
use App\Core\AccountContext;
use App\Tarefas\TaskAudit;

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
    /*
     * AQUI HAVIA UM `if ($isAdmin) return true;` NA PRIMEIRA LINHA.
     *
     * `Task::findById` não filtra por conta. Com o atalho vindo antes de qualquer
     * checagem de tenant, o dono ou admin de QUALQUER escritório concluía,
     * editava ou arquivava tarefa de OUTRO escritório só trocando o id, que é
     * sequencial. Encontrado em 28/09/2026 ao investigar por que um admin novo
     * não via os quadros.
     *
     * O poder de admin continua existindo, mas agora mora em TaskBoard::acesso e
     * só vale para quadros das contas acessíveis ao usuário. Sem contas, nega.
     */
    if (!$accIds) return false;

    // A tarefa tem que pertencer a um quadro das contas do usuário. É esta linha
    // que torna seguras as duas regras abaixo: sem ela, "sou o criador" valeria
    // para qualquer id.
    $contaDaTarefa = (int)($task['origin_account_id'] ?? 0);
    if (!in_array($contaDaTarefa, array_map('intval', $accIds), true)) return false;

    // Quem criou e quem é responsável editam a PRÓPRIA tarefa, inclusive num
    // quadro pessoal de outra pessoa: é assim que o responsável consegue
    // concluir o que lhe foi delegado.
    if ((int)$task['criado_por_id'] === $userId) return true;
    if ((int)$task['responsavel_id'] === $userId) return true;

    return TaskBoard::canEdit((int)$task['board_id'], $userId, $accIds, $isAdmin);
}

$action = $_GET['action'] ?? null;

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $task = Task::withDetails((int)$_GET['id']);
        // Abre a tarefa quem pode ver o quadro OU quem pode editá-la (criador e
        // responsável). Sem o segundo caso, o responsável de uma tarefa num quadro
        // pessoal recebia o aviso "você é o responsável", podia concluí-la, mas
        // ao abrir dava "não encontrado".
        if (!$task || !(TaskBoard::canView((int)$task['board_id'], $userId, $accIds, $isAdmin)
                        || canEditTask($task, $userId, $isAdmin, $accIds))) fail('Não encontrado', 404);
        ok($task);
    }
    $boardId = (int)($_GET['board_id'] ?? 0);
    if (!$boardId || !TaskBoard::canView($boardId, $userId, $accIds, $isAdmin)) fail('Sem acesso', 403);

    /*
     * AQUI RODAVA UM CRON, DENTRO DA ESPERA DA PESSOA.
     *
     * `RecurrenceCronService::tickIfDue()` prometia rodar no máximo uma vez por
     * hora, controlado por um arquivo de trava em `storage/`. Em produção a
     * pasta era do root, o Apache não conseguia escrever, a gravação falhava em
     * silêncio, e a trava ficou congelada por 102 dias. Com a trava parada, o
     * "máximo uma vez por hora" virou TODA abertura da tela: 214 tarefas
     * recorrentes vencidas, 24 ms cada, cerca de 5 segundos de espera por vez.
     *
     * A renovação agora é do cron do servidor (tasks_recurrence_tick.php), que
     * é onde trabalho em lote deve morar. Listar tarefa voltou a ser só listar.
     */

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
        if (!$task || !TaskBoard::canView((int)$task['board_id'], $userId, $accIds, $isAdmin)) fail('Não encontrado', 404);
        // Mesma regra da edição: só move para coluna do PRÓPRIO quadro.
        $colMove = TaskColumn::findById((int)($input['column_id'] ?? 0));
        if (!$colMove || (int)$colMove['board_id'] !== (int)$task['board_id']) fail('Coluna inválida para este quadro', 400);
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
    if (!$boardId || !TaskBoard::canEdit($boardId, $userId, $accIds, $isAdmin)) fail('Sem permissão', 403);
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

    /*
     * A COLUNA TEM QUE SER DO QUADRO DA TAREFA.
     *
     * `Task::update` grava `column_id` do jeito que chegar. Dois jeitos de isso
     * dar errado, ambos achados em 28/09/2026:
     *
     *  - o painel da tarefa manda a coluna escolhida no select, e o select mostra
     *    as colunas do quadro ABERTO. Quem abre pelo link do aviso uma tarefa de
     *    outro quadro (o responsável de uma tarefa num quadro pessoal alheio) ficava
     *    com o select vazio, e salvar gravava coluna vazia: a tarefa sumia do kanban.
     *  - qualquer `column_id` era aceito, inclusive de outro quadro.
     *
     * Coluna vazia, inexistente ou de outro quadro é ignorada: o resto da edição
     * vale, a tarefa fica onde estava.
     */
    if (array_key_exists('column_id', $input)) {
        $colDestino = (int)$input['column_id'] > 0 ? TaskColumn::findById((int)$input['column_id']) : false;
        if (!$colDestino || (int)$colDestino['board_id'] !== (int)$task['board_id']) {
            unset($input['column_id']);
        }
    }

    Task::update($id, $input, $userId);

    if (!empty($changes)) TaskAudit::onTaskUpdated($id, $changes);

    /*
     * NOTIFICACAO DE RESPONSAVEL. O $changes acima ja foi montado para o
     * historico processual e ja contem `responsavel_id` quando ele mudou:
     * reaproveitar e melhor que comparar de novo.
     *
     * A conta vem do QUADRO, nao da tarefa: `tasks` nao tem account_id.
     */
    if (isset($changes['responsavel_id'])) {
        try {
            $pdoN = \App\Core\Database::getConnection();
            $stN  = $pdoN->prepare('SELECT b.account_id FROM tasks t JOIN task_boards b ON b.id = t.board_id WHERE t.id = ? LIMIT 1');
            $stN->execute([$id]);
            $accTarefa = (int) ($stN->fetchColumn() ?: 0);
            if ($accTarefa > 0) {
                \App\Notificacoes\Aviso::responsavel(
                    $accTarefa, 'tarefa', $id,
                    (string)($input['titulo'] ?? $task['titulo'] ?? ('#' . $id)),
                    (int)$changes['responsavel_id'][1] ?: null,
                    (int)$changes['responsavel_id'][0] ?: null,
                    $userId,
                    \App\Notificacoes\Movimento::urlDe('tarefa', $id)
                );
            }
        } catch (\Throwable $e) { /* aviso nunca derruba a edicao */ }
    }

    // recorrência: criar nova, atualizar existente ou desativar
    if (array_key_exists('recorrencia', $input)) {
        $pdo = \App\Core\Database::getConnection();
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
