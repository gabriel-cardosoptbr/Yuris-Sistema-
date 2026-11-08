<?php
/**
 * tarefas_central.php: a lista única da tela Tarefas (App\Tarefas\Central).
 *
 * Só leitura. Concluir e editar continuam em tasks.php, processo_tarefas.php e
 * processo_prazos.php, que têm auditoria e webhook.
 *
 * GET                       itens, contagem por período e a data de hoje
 *     ?tipo=juridica|escritorio  ?situacao=abertas|concluidas|todas
 *     ?responsavel_id=N  ?prioridade=  ?busca=  ?processo_id=N
 * GET ?acao=processos&q=    processos por nome do cliente, número ou ação; sem q, os mais
 *     recentes; &cliente=NOME só os desse cliente (o termo é opcional)
 * GET ?acao=clientes&q=     clientes que têm processo, com quantos; sem q, os mais recentes
 */
require_once __DIR__ . '/_json_guard.php';
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Tarefas\Central;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx = AccountContext::fromSession();
$ctx->assertModuloJuridicoDisponivel();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não suportado']);
    exit;
}

$contasProcessos = $ctx->getAccessibleAccountIds('processos');

try {
    if (($_GET['acao'] ?? '') === 'processos') {
        echo json_encode(['ok' => true, 'data' => Central::buscarProcessos(
            $contasProcessos, $ctx->getUserId(), (string) ($_GET['q'] ?? ''), 20, (string) ($_GET['cliente'] ?? '')
        )]);
        exit;
    }
    if (($_GET['acao'] ?? '') === 'clientes') {
        echo json_encode(['ok' => true, 'data' => Central::buscarClientes(
            $contasProcessos, $ctx->getUserId(), (string) ($_GET['q'] ?? '')
        )]);
        exit;
    }

    $filtros = [
        'tipo'       => in_array($_GET['tipo'] ?? '', ['juridica', 'escritorio'], true) ? $_GET['tipo'] : '',
        'situacao'   => in_array($_GET['situacao'] ?? '', ['abertas', 'concluidas', 'todas'], true) ? $_GET['situacao'] : 'abertas',
        'prioridade' => in_array($_GET['prioridade'] ?? '', ['baixa', 'media', 'alta', 'urgente'], true) ? $_GET['prioridade'] : '',
        'busca'      => trim((string) ($_GET['busca'] ?? '')),
        'processo_id'=> (int) ($_GET['processo_id'] ?? 0),
    ];
    // Sem filtro na tela, vale o vendedor em foco do admin, como em tasks.php.
    if (isset($_GET['responsavel_id'])) {
        $filtros['responsavel_id'] = (int) $_GET['responsavel_id'];
    } elseif (($foco = \App\Core\VendedorEmFoco::escolhido()) !== null) {
        $filtros['responsavel_id'] = $foco;
    }

    echo json_encode(['ok' => true, 'data' => Central::listar([
        'user_id'          => $ctx->getUserId(),
        'is_admin'         => $ctx->isOwnerOrAdmin(),
        'contas_tarefas'   => $ctx->getAccessibleAccountIds('tarefas'),
        'contas_processos' => $contasProcessos,
    ], $filtros)], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    \App\Core\ErrorReporter::handle($e);
    exit;
}
