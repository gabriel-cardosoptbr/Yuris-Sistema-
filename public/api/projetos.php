<?php
/**
 * /api/projetos.php: a aba Projetos (edição CRM).
 *
 *   GET                                     painel: projetos (com itens, números e
 *                                           pendências), servidores, resumo, opções
 *   POST {acao:'projeto_salvar', id?, ...}  cria (sem id) ou atualiza o projeto
 *   POST {acao:'projeto_excluir', id}
 *   POST {acao:'item_salvar', id?, projeto_id, ...}   receita ou custo do projeto
 *   POST {acao:'item_excluir', id}
 *   POST {acao:'servidor_salvar', id?, ...}
 *   POST {acao:'servidor_excluir', id}
 *   POST {acao:'cotacao_salvar', valor}     cotação do dólar da conta
 *
 * Financeiro é só de ADM: vendedor da edição CRM recebe 403. Conta sem o módulo
 * ligado (configuracoes.modulos.projetos; Yuris nunca) recebe 404. Toda leitura
 * e escrita passa pela lista de contas acessíveis de 'financas'; criação vai
 * sempre para a conta da sessão (item, para a conta do projeto).
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Projetos\Projeto;

session_start();
header('Content-Type: application/json; charset=utf-8');

function responder(int $codigo, array $corpo): void
{
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) responder(401, ['error' => 'Sessão expirada.']);
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if ($ctx->vendedorCrm()) responder(403, ['error' => 'Projetos disponível só para administradores.']);
$accountId = (int) $ctx->getAccountId();
$conta = \App\Master\Account::findById($accountId) ?? [];
if (!Projeto::habilitado($conta)) responder(404, ['error' => 'Projetos não está disponível nesta conta.']);
$userId = (int) $ctx->getUserId();
$contas = $ctx->getAccessibleAccountIds('financas') ?: [$accountId];
$cotacao = Projeto::cotacaoUsd($conta);

function painel(array $contas, float $cotacao): array
{
    return ['success' => true, 'cotacao_usd' => $cotacao, 'hoje' => Projeto::hoje()] + Projeto::painel($contas, $cotacao) + [
        'opcoes' => ['tipos' => Projeto::TIPOS, 'status' => Projeto::STATUS, 'categorias' => Projeto::CATEGORIAS,
                     'recorrencias' => Projeto::RECORRENCIAS, 'moedas' => Projeto::MOEDAS],
    ];
}

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo === 'GET') responder(200, painel($contas, $cotacao));
if ($metodo !== 'POST') responder(405, ['error' => 'Método não permitido.']);

$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) responder(400, ['error' => 'Sessão inválida. Recarregue a página.']);

$acao = (string) ($in['acao'] ?? '');
$id = (int) ($in['id'] ?? 0);
$audit = fn(string $evento, string $entidade, int $entidadeId, array $det = []) => \App\Master\Account::audit($accountId, 'projeto.' . $evento, [
    'user_id' => $userId, 'entidade' => $entidade, 'entidade_id' => $entidadeId, 'detalhes' => $det,
]);

switch ($acao) {
    case 'projeto_salvar':
        [$d, $erro] = Projeto::validarProjeto($in);
        if ($erro) responder(422, ['error' => $erro]);
        if ($id > 0) {
            if (!Projeto::atualizarProjeto($id, $contas, $d)) responder(404, ['error' => 'Projeto não encontrado.']);
            $audit('updated', 'projeto', $id, ['nome' => $d['nome'], 'status' => $d['status']]);
        } else {
            $id = Projeto::criarProjeto($accountId, $d, $userId);
            $audit('created', 'projeto', $id, ['nome' => $d['nome']]);
        }
        responder(200, painel($contas, $cotacao) + ['id' => $id]);

    case 'projeto_excluir':
        if (!Projeto::excluirProjeto($id, $contas)) responder(404, ['error' => 'Projeto não encontrado.']);
        $audit('deleted', 'projeto', $id);
        responder(200, painel($contas, $cotacao));

    case 'item_salvar':
        [$d, $erro] = Projeto::validarItem($in);
        if ($erro) responder(422, ['error' => $erro]);
        if ($id > 0) {
            if (!Projeto::atualizarItem($id, $contas, $d)) responder(404, ['error' => 'Item não encontrado.']);
            $audit('item_updated', 'projeto_item', $id, ['descricao' => $d['descricao'], 'valor_centavos' => $d['valor_centavos']]);
        } else {
            $novo = Projeto::criarItem((int) ($in['projeto_id'] ?? 0), $contas, $d, $userId);
            if ($novo === null) responder(404, ['error' => 'Projeto não encontrado.']);
            $id = $novo;
            $audit('item_created', 'projeto_item', $id, ['projeto_id' => (int) $in['projeto_id'], 'descricao' => $d['descricao'], 'valor_centavos' => $d['valor_centavos']]);
        }
        responder(200, painel($contas, $cotacao) + ['id' => $id]);

    case 'item_excluir':
        if (!Projeto::excluirItem($id, $contas)) responder(404, ['error' => 'Item não encontrado.']);
        $audit('item_deleted', 'projeto_item', $id);
        responder(200, painel($contas, $cotacao));

    case 'servidor_salvar':
        [$d, $erro] = Projeto::validarServidor($in);
        if ($erro) responder(422, ['error' => $erro]);
        if ($id > 0) {
            if (!Projeto::atualizarServidor($id, $contas, $d)) responder(404, ['error' => 'Servidor não encontrado.']);
            $audit('servidor_updated', 'projeto_servidor', $id, ['nome' => $d['nome'], 'valor_centavos' => $d['valor_centavos']]);
        } else {
            $id = Projeto::criarServidor($accountId, $d, $userId);
            $audit('servidor_created', 'projeto_servidor', $id, ['nome' => $d['nome']]);
        }
        responder(200, painel($contas, $cotacao) + ['id' => $id]);

    case 'servidor_excluir':
        if (!Projeto::excluirServidor($id, $contas)) responder(404, ['error' => 'Servidor não encontrado.']);
        $audit('servidor_deleted', 'projeto_servidor', $id);
        responder(200, painel($contas, $cotacao));

    case 'cotacao_salvar':
        if (!Projeto::salvarCotacao(\App\Core\Database::getConnection(), $accountId, $in['valor'] ?? '')) {
            responder(422, ['error' => 'Cotação inválida. Use o formato 5,40.']);
        }
        $audit('cotacao_updated', 'conta', $accountId, ['valor' => (string) ($in['valor'] ?? '')]);
        responder(200, painel($contas, Projeto::cotacaoUsd(\App\Master\Account::findById($accountId) ?? [])));
}

responder(400, ['error' => 'Ação desconhecida.']);
