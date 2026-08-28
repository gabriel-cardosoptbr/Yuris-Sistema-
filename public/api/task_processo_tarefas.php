<?php
/**
 * task_processo_tarefas.php
 * Retorna as tarefas processuais de todos os processos vinculados a uma tarefa.
 * Mutações (add/toggle/edit/delete) usam processo_tarefas.php diretamente.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\TenantGuard;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx       = AccountContext::fromSession();
$tenantIds = $ctx->getAccessibleAccountIds('processos');

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function ok($data = null): void {
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

$pdo    = \App\Core\Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$taskId = (int)($_GET['task_id'] ?? 0);

if ($method !== 'GET') fail('Método não suportado', 405);
if (!$taskId) fail('task_id obrigatório');

// Valida que a task pertence ao tenant atual
TenantGuard::assertTaskAcessivel($ctx, $taskId);

// Cláusula IN dos tenants acessíveis
$ph = []; $tenantParams = [':tid' => $taskId];
foreach ($tenantIds as $i => $aid) {
    $k = "tpt_{$i}";
    $ph[] = ":{$k}";
    $tenantParams[$k] = (int)$aid;
}
$tenantIn = '(' . implode(',', $ph) . ')';

// 1. Busca processos vinculados à tarefa — apenas dentro do tenant
$stmt = $pdo->prepare("
    SELECT tl.id         AS link_id,
           tl.link_id    AS processo_id,
           COALESCE(p.numero_cnj, p.numero, CONCAT('#', p.id)) AS numero,
           COALESCE(p.cliente_nome, '')                         AS cliente_nome
    FROM task_links tl
    INNER JOIN processos p ON p.id = tl.link_id
    WHERE tl.task_id   = :tid
      AND tl.link_type = 'processo'
      AND p.deleted_at IS NULL
      AND p.account_id IN $tenantIn
    ORDER BY tl.id ASC
");
$stmt->execute($tenantParams);
$processos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// 2. Para cada processo, busca suas tarefas processuais
$result = [];
foreach ($processos as $proc) {
    $pid = (int)$proc['processo_id'];

    $ts = $pdo->prepare("
        SELECT id, titulo, concluido, prioridade, responsavel, data_tarefa, ordem
        FROM processo_tarefas
        WHERE processo_id = :pid
        ORDER BY concluido ASC, ordem ASC, id ASC
    ");
    $ts->execute([':pid' => $pid]);
    $tarefas = $ts->fetchAll(\PDO::FETCH_ASSOC);

    // Transforma tipos para consistência
    foreach ($tarefas as &$t) {
        $t['id']       = (int)$t['id'];
        $t['concluido'] = (bool)$t['concluido'];
    }
    unset($t);

    $total     = count($tarefas);
    $concluido = count(array_filter($tarefas, fn($t) => $t['concluido']));

    $result[] = [
        'processo_id'   => $pid,
        'numero'        => $proc['numero'],
        'cliente_nome'  => $proc['cliente_nome'],
        'tarefas'       => $tarefas,
        'total'         => $total,
        'concluido'     => $concluido,
    ];
}

ok($result);
