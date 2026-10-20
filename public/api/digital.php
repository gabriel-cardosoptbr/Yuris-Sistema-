<?php
/**
 * Operação de Digital / Marketing (App\Digital) e os extras dos cartões de quadro
 * de área (responsáveis a mais, materiais esperados).
 *
 *   GET  ?quadro=N                       entregas e responsáveis a mais de cada cartão do quadro
 *   GET  ?cartao=N                       entrega, materiais e responsáveis do cartão
 *   GET  ?cliente=N [&mes=AAAA-MM]        a operação do cliente: quadros, frequência, campanhas, números do mês
 *   GET  ?entregas=1 [&mes=] [&cliente=]  as entregas do mês (tela Entregas)
 *   GET  ?campanhas=1 [&status=]          campanhas dos clientes que a pessoa vê
 *   GET  ?apoio=1                         tipos, estados, plataformas, modelos de quadro e a equipe
 *   GET  ?clientes=1                      clientes que a pessoa vê (id, nome), para escolher num formulário
 *   POST {acao:'entrega_salvar', task_id, tipo, producao, aprovacao, entrega, publicacao, competencia}
 *   POST {acao:'participantes', task_id, user_ids:[...]}
 *   POST {acao:'material_adicionar', task_id, nome}
 *   POST {acao:'material_receber', task_id, id, arquivo:{id, nome, link}}
 *   POST {acao:'material_desfazer' | 'material_remover', task_id, id}
 *   POST {acao:'frequencia_salvar', cliente_id, id?, tipo, quantidade, periodo, inicio, fim}
 *   POST {acao:'frequencia_remover', cliente_id, id}
 *   POST {acao:'campanha_salvar', id?, cliente_id, nome, plataforma, ...}
 *   POST {acao:'campanha_remover', id}
 *
 * Só na conta com ambientes. Cartão: a mesma regra de quem vê e edita o quadro
 * (TaskBoard). Cliente: da conta, e do vendedor quando quem pede é vendedor.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Ambientes\Ambiente;
use App\Ambientes\AreaDoCliente;
use App\Ambientes\QuadroDaArea;
use App\Core\AccountContext;
use App\Core\Database;
use App\Digital\Campanha;
use App\Digital\Entrega;
use App\Digital\Frequencia;
use App\Digital\Material;
use App\Tarefas\TaskBoard;
use App\Tarefas\TaskParticipante;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function dgResponder(array $corpo, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) dgResponder(['ok' => false, 'error' => 'Sessão expirada.'], 401);
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$acc = (int) $ctx->getAccountId();
$uid = (int) $ctx->getUserId();
$admin = $ctx->isOwnerOrAdmin();
if (!Ambiente::habilitado(\App\Master\Account::findById($acc) ?? [])) dgResponder(['ok' => false, 'error' => 'Ambientes não estão ligados nesta conta.'], 404);
$podeTarefas = Ambiente::podePagina('tarefas');
$podeClientes = Ambiente::podePagina('clientes');
if (!$podeTarefas && !$podeClientes) dgResponder(['ok' => false, 'error' => 'Sem permissão.'], 403);
$accTarefas = $ctx->getAccessibleAccountIds('tarefas') ?: [$acc];
$vendedor = AccountContext::vendedorDaSessao();
$mes = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mes'] ?? '')) ? (string) $_GET['mes'] : date('Y-m');

/** O cliente da conta que a pessoa pode ver (vendedor: só o dele). */
function dgCliente(int $acc, int $id, ?int $vendedor): array
{
    $sql = 'SELECT id, nome, responsavel_id FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL';
    $args = [$id, $acc];
    if ($vendedor !== null) { $sql .= ' AND responsavel_id = ?'; $args[] = $vendedor; }
    $st = Database::getConnection()->prepare($sql);
    $st->execute($args);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) dgResponder(['ok' => false, 'error' => 'Cliente não encontrado.'], 404);
    return $c;
}

/** Ids dos clientes que a pessoa vê na conta (vendedor: só os dele). */
function dgClientesVisiveis(int $acc, ?int $vendedor): ?array
{
    if ($vendedor === null) return null;
    $st = Database::getConnection()->prepare('SELECT id FROM clientes WHERE account_id = ? AND responsavel_id = ? AND deleted_at IS NULL');
    $st->execute([$acc, $vendedor]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['apoio'])) {
            $st = Database::getConnection()->prepare("SELECT id, nome FROM users WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL ORDER BY nome");
            $st->execute([$acc]);
            dgResponder(['ok' => true, 'data' => [
                'tipos' => Entrega::TIPOS, 'estados' => Entrega::ESTADOS, 'plataformas' => Campanha::PLATAFORMAS, 'status_campanha' => Campanha::STATUS,
                'periodos' => Frequencia::PERIODOS, 'areas' => QuadroDaArea::AREAS,
                'modelos' => array_map(fn($m) => $m[0], QuadroDaArea::MODELOS),
                'equipe' => array_map(fn($u) => ['id' => (int) $u['id'], 'nome' => $u['nome']], $st->fetchAll(PDO::FETCH_ASSOC)),
            ]]);
        }
        if (!empty($_GET['clientes'])) {
            // Para escolher o cliente de um quadro ou campanha: os que a pessoa vê.
            $sql = 'SELECT id, nome FROM clientes WHERE account_id = ? AND deleted_at IS NULL';
            $args = [$acc];
            if ($vendedor !== null) { $sql .= ' AND responsavel_id = ?'; $args[] = $vendedor; }
            $st = Database::getConnection()->prepare($sql . ' ORDER BY nome LIMIT 2000');
            $st->execute($args);
            dgResponder(['ok' => true, 'data' => array_map(fn($c) => ['id' => (int) $c['id'], 'nome' => $c['nome']], $st->fetchAll(PDO::FETCH_ASSOC))]);
        }
        if (!empty($_GET['quadro'])) {
            if (!$podeTarefas) dgResponder(['ok' => false, 'error' => 'Sem permissão para Tarefas.'], 403);
            $bid = (int) $_GET['quadro'];
            if (!TaskBoard::canView($bid, $uid, $accTarefas, $admin)) dgResponder(['ok' => false, 'error' => 'Quadro não encontrado.'], 404);
            dgResponder(['ok' => true, 'data' => ['entregas' => (object) Entrega::doQuadro($bid), 'participantes' => (object) TaskParticipante::doQuadro($bid)]]);
        }
        if (!empty($_GET['cartao'])) {
            if (!$podeTarefas) dgResponder(['ok' => false, 'error' => 'Sem permissão para Tarefas.'], 403);
            $c = Entrega::cartaoVisivel((int) $_GET['cartao'], $uid, $accTarefas, $admin);
            dgResponder(['ok' => true, 'data' => [
                'entrega' => Entrega::doCartao((int) $c['task']['id']), 'materiais' => Material::doCartao((int) $c['task']['id']),
                'participantes' => TaskParticipante::daTarefa((int) $c['task']['id']),
                'quadro' => ['id' => (int) $c['board']['id'], 'area' => $c['board']['area'] ?? null, 'cliente_id' => $c['board']['cliente_id'] ? (int) $c['board']['cliente_id'] : null],
                'pode_editar' => TaskBoard::canEdit((int) $c['board']['id'], $uid, $accTarefas, $admin),
            ]]);
        }
        if (!empty($_GET['entregas'])) {
            if (!$podeTarefas) dgResponder(['ok' => false, 'error' => 'Sem permissão para Tarefas.'], 403);
            // Só cartões de quadros que a pessoa vê.
            $ids = array_map(fn($b) => (int) $b['id'], TaskBoard::findForUser($uid, $accTarefas, $admin));
            if (!$ids) dgResponder(['ok' => true, 'data' => []]);
            $sql = 'SELECT e.*, t.titulo, t.prazo, t.board_id, t.status AS task_status, b.nome AS quadro, cl.nome AS cliente, u.nome AS responsavel
                      FROM task_entregas_digital e JOIN tasks t ON t.id = e.task_id JOIN task_boards b ON b.id = t.board_id
                      LEFT JOIN clientes cl ON cl.id = e.cliente_id AND cl.account_id = e.account_id LEFT JOIN users u ON u.id = t.responsavel_id
                     WHERE e.account_id = ? AND e.competencia = ? AND t.status <> \'arquivada\' AND t.board_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $args = array_merge([$acc, $mes . '-01'], $ids);
            if (!empty($_GET['cliente'])) { $sql .= ' AND e.cliente_id = ?'; $args[] = (int) $_GET['cliente']; }
            $st = Database::getConnection()->prepare($sql . ' ORDER BY cl.nome, t.prazo IS NULL, t.prazo');
            $st->execute($args);
            $agora = date('Y-m-d H:i:s');
            $lista = array_map(function ($e) use ($agora) {
                $f = Entrega::doCartao((int) $e['task_id']) ?? [];
                return $f + ['titulo' => $e['titulo'], 'prazo' => $e['prazo'], 'board_id' => (int) $e['board_id'], 'quadro' => $e['quadro'], 'cliente' => $e['cliente'],
                    'responsavel' => $e['responsavel'], 'atrasada' => ($f['situacao'] ?? '') !== 'concluida' && $e['prazo'] && $e['prazo'] < $agora];
            }, $st->fetchAll(PDO::FETCH_ASSOC));
            dgResponder(['ok' => true, 'data' => $lista]);
        }
        if (!empty($_GET['cliente'])) {
            $cli = dgCliente($acc, (int) $_GET['cliente'], $vendedor);
            $quadros = $podeTarefas ? QuadroDaArea::filtrar(TaskBoard::findForUser($uid, $accTarefas, $admin), null, (int) $cli['id']) : [];
            $resumo = Entrega::resumo($acc, $mes, [(int) $cli['id']]);
            $numQuadros = \App\Ambientes\Visao::tarefas(array_map(fn($b) => (int) $b['id'], $quadros));
            dgResponder(['ok' => true, 'data' => [
                'cliente' => ['id' => (int) $cli['id'], 'nome' => $cli['nome']],
                'areas' => AreaDoCliente::mapa($acc, [(int) $cli['id']])[(int) $cli['id']] ?? [],
                'quadros' => array_map(fn($b) => ['id' => (int) $b['id'], 'nome' => $b['nome'], 'area' => $b['area'] ?? null, 'modelo' => $b['modelo'] ?? null]
                    + ($numQuadros['por_quadro'][(int) $b['id']] ?? ['abertas' => 0, 'atrasadas' => 0, 'proximo' => null]), $quadros),
                'frequencias' => Frequencia::doCliente($acc, (int) $cli['id']),
                'campanhas' => Campanha::listar($acc, [(int) $cli['id']]),
                'mes' => $mes,
                'resumo' => $resumo['clientes'][(int) $cli['id']] ?? null,
                'pode_tarefas' => $podeTarefas,
            ]]);
        }
        if (!empty($_GET['campanhas'])) {
            $ids = AreaDoCliente::clientesDaArea($acc, 'digital', $vendedor);
            $todos = dgClientesVisiveis($acc, $vendedor);
            $lista = Campanha::listar($acc, $todos, isset($_GET['status']) ? (string) $_GET['status'] : null);
            dgResponder(['ok' => true, 'data' => $lista, 'clientes_digitais' => $ids]);
        }
        dgResponder(['ok' => false, 'error' => 'Pedido desconhecido.'], 400);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') dgResponder(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) dgResponder(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 419);
    $acao = (string) ($in['acao'] ?? '');

    // Ações do cartão: quem edita o quadro.
    if (in_array($acao, ['entrega_salvar', 'participantes', 'material_adicionar', 'material_receber', 'material_desfazer', 'material_remover'], true)) {
        if (!$podeTarefas) dgResponder(['ok' => false, 'error' => 'Sem permissão para Tarefas.'], 403);
        $c = Entrega::cartaoEditavel((int) ($in['task_id'] ?? 0), $uid, $accTarefas, $admin);
        $tid = (int) $c['task']['id'];
        switch ($acao) {
            case 'entrega_salvar':
                dgResponder(['ok' => true, 'data' => Entrega::salvar($c, $uid, $in)]);
            case 'participantes':
                dgResponder(['ok' => true, 'data' => TaskParticipante::definir($tid, (int) $c['board']['account_id'], $uid, (array) ($in['user_ids'] ?? []),
                    $c['task']['responsavel_id'] ? (int) $c['task']['responsavel_id'] : null)]);
            case 'material_adicionar':
                Material::adicionar($c, $uid, (string) ($in['nome'] ?? ''));
                break;
            case 'material_receber':
                Material::receber($c, $uid, (int) ($in['id'] ?? 0), (array) ($in['arquivo'] ?? []));
                break;
            case 'material_desfazer':
                Material::desfazer($c, $uid, (int) ($in['id'] ?? 0));
                break;
            case 'material_remover':
                Material::remover($c, $uid, (int) ($in['id'] ?? 0));
                break;
        }
        dgResponder(['ok' => true, 'data' => Material::doCartao($tid)]);
    }

    if ($acao === 'frequencia_salvar' || $acao === 'frequencia_remover') {
        $cli = dgCliente($acc, (int) ($in['cliente_id'] ?? 0), $vendedor);
        if ($acao === 'frequencia_salvar') Frequencia::salvar($acc, $uid, (int) $cli['id'], $in);
        else Frequencia::remover($acc, (int) $cli['id'], (int) ($in['id'] ?? 0));
        dgResponder(['ok' => true, 'data' => Frequencia::doCliente($acc, (int) $cli['id'])]);
    }
    if ($acao === 'campanha_salvar') {
        dgCliente($acc, (int) ($in['cliente_id'] ?? 0), $vendedor);
        if (!empty($in['id'])) {
            $atual = Campanha::clienteDa($acc, (int) $in['id']);
            if ($atual === null) dgResponder(['ok' => false, 'error' => 'Campanha não encontrada.'], 404);
            dgCliente($acc, $atual, $vendedor);
        }
        dgResponder(['ok' => true, 'id' => Campanha::salvar($acc, $uid, $in)]);
    }
    if ($acao === 'campanha_remover') {
        $atual = Campanha::clienteDa($acc, (int) ($in['id'] ?? 0));
        if ($atual === null) dgResponder(['ok' => false, 'error' => 'Campanha não encontrada.'], 404);
        dgCliente($acc, $atual, $vendedor);
        Campanha::remover($acc, (int) $in['id']);
        dgResponder(['ok' => true]);
    }
    dgResponder(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (\DomainException $e) {
    dgResponder(['ok' => false, 'error' => $e->getMessage()], 404);
} catch (\InvalidArgumentException $e) {
    dgResponder(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log('[api/digital] ' . $e->getMessage());
    dgResponder(['ok' => false, 'error' => 'Não foi possível concluir agora.'], 500);
}
