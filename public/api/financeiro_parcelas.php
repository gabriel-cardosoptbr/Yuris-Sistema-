<?php
/**
 * financeiro_parcelas.php — receitas e despesas com cliente, processo, parcelas e baixa
 * (T2, 10/11/2026, migration 179). Regras em App\Financas\Parcelamento.
 *
 * GET  ?clientes=1                    clientes das contas alcançadas [{id, nome}]
 * GET  ?processos_do_cliente=ID       processos desse cliente [{id, numero, cliente_nome}]
 * GET  ?visao=1[&cliente_id&processo_id&tipo&situacao]   títulos, totais por cliente e por processo
 * GET  ?projecao=1[&meses=12]       este mês, o próximo, o saldo futuro total, o atraso e mês a mês
 * POST {acao:'criar', tipo, descricao, codigo?, cliente_id?, processo_id?, quantidade,
 *       primeiro_vencimento, valor_total | valor_parcela}
 * POST {acao:'baixar', id, data?, juros?, obs?}  ·  {acao:'desfazer', id}  ·  {acao:'editar', id, valor, vencimento}
 *      Juros na baixa viram um lançamento próprio no DRE, ligado à parcela (migration 181).
 *
 * Financeiro é de ADM na edição CRM (vendedor recebe 403), como dre_accounts.php.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\Financas\Parcelamento;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Não autenticado']); exit; }

$ctx = AccountContext::fromSession();
if ($ctx->vendedorCrm()) { http_response_code(403); echo json_encode(['error' => 'Financeiro disponível só para administradores.']); exit; }
$accountId = $ctx->getAccountId();
$userId    = $ctx->getUserId();
$tenantIds = $ctx->getAccessibleAccountIds('financas');
if (empty($tenantIds)) $tenantIds = [$accountId];
$in = implode(',', array_fill(0, count($tenantIds), '?'));
$pdo = Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        if (!empty($_GET['clientes'])) {
            $st = $pdo->prepare("SELECT id, nome, tipo_cliente FROM clientes WHERE deleted_at IS NULL AND account_id IN ($in) ORDER BY nome LIMIT 2000");
            $st->execute($tenantIds);
            echo json_encode(['data' => $st->fetchAll(\PDO::FETCH_ASSOC)]);
            exit;
        }
        if (isset($_GET['processos_do_cliente'])) {
            $dados = [];
            if ($ctx->moduloJuridicoDisponivel()) {
                $st = $pdo->prepare("SELECT id, numero, cliente_nome FROM processos
                                      WHERE cliente_id = ? AND deleted_at IS NULL AND account_id IN ($in) ORDER BY id DESC LIMIT 500");
                $st->execute(array_merge([(int) $_GET['processos_do_cliente']], $tenantIds));
                $dados = $st->fetchAll(\PDO::FETCH_ASSOC);
            }
            echo json_encode(['data' => $dados]);
            exit;
        }
        if (!empty($_GET['projecao'])) {
            echo json_encode(['data' => Parcelamento::projecao($tenantIds, (int) ($_GET['meses'] ?? 12))]);
            exit;
        }
        if (!empty($_GET['visao'])) {
            echo json_encode(['data' => Parcelamento::visao($tenantIds, [
                'cliente_id'  => (int) ($_GET['cliente_id'] ?? 0),
                'processo_id' => (int) ($_GET['processo_id'] ?? 0),
                'tipo'        => (string) ($_GET['tipo'] ?? ''),
                'situacao'    => (string) ($_GET['situacao'] ?? ''),
            ])]);
            exit;
        }
        http_response_code(400);
        echo json_encode(['error' => 'Consulta inválida']);
        exit;
    }

    if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Método não permitido']); exit; }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) {
        http_response_code(400); echo json_encode(['error' => 'Invalid CSRF token']); exit;
    }
    $acao = (string) ($input['acao'] ?? '');
    $id = (int) ($input['id'] ?? 0);

    switch ($acao) {
        case 'criar':
            $r = Parcelamento::criar(['origem' => 'manual'] + $input, $accountId, $tenantIds, $userId);
            \App\Master\Account::audit($accountId, 'dre.parcelamento_criado', [
                'user_id' => $userId, 'entidade' => 'fin_parcelamento', 'entidade_id' => $r['parcelamento_id'],
                'detalhes' => ['parcelas' => $r['parcelas'], 'tipo' => $input['tipo'] ?? null, 'descricao' => $input['descricao'] ?? null],
            ]);
            echo json_encode(['success' => true] + $r);
            break;
        case 'baixar':
            Parcelamento::baixar($id, $tenantIds, !empty($input['data']) ? (string) $input['data'] : null, $userId,
                                 $input['juros'] ?? null, isset($input['obs']) ? (string) $input['obs'] : null);
            \App\Master\Account::audit($accountId, 'dre.baixa', ['user_id' => $userId, 'entidade' => 'dre_account', 'entidade_id' => $id,
                'detalhes' => ['data' => $input['data'] ?? null, 'juros' => $input['juros'] ?? null, 'obs' => $input['obs'] ?? null]]);
            echo json_encode(['success' => true]);
            break;
        case 'desfazer':
            Parcelamento::desfazerBaixa($id, $tenantIds);
            \App\Master\Account::audit($accountId, 'dre.baixa_desfeita', ['user_id' => $userId, 'entidade' => 'dre_account', 'entidade_id' => $id]);
            echo json_encode(['success' => true]);
            break;
        case 'editar':
            $antes = Parcelamento::titulo($id, $tenantIds);
            Parcelamento::editarParcela($id, $tenantIds, $input['valor'] ?? null, isset($input['vencimento']) ? (string) $input['vencimento'] : null);
            \App\Master\Account::audit($accountId, 'dre.parcela_editada', ['user_id' => $userId, 'entidade' => 'dre_account', 'entidade_id' => $id,
                'dados_antes' => $antes, 'detalhes' => ['valor' => $input['valor'] ?? null, 'vencimento' => $input['vencimento'] ?? null]]);
            echo json_encode(['success' => true]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Ação inválida']);
    }
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    \App\Core\ErrorReporter::handle($e);
}
