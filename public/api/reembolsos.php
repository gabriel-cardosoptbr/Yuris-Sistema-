<?php
/**
 * /api/reembolsos.php: reembolsos da tela Finanças (edição CRM).
 *
 *   GET                         lista + resumo
 *   POST {acao:'criar', ...}    cria (favorecido, descricao, data_despesa, valor_total,
 *                               parcelas, primeiro_vencimento, observacao)
 *   POST {acao:'atualizar', id, ...}
 *   POST {acao:'excluir', id}
 *   POST {acao:'pagar', parcela_id, pago_em, pago_por_nome}  marca a parcela como paga
 *   POST {acao:'desfazer', parcela_id}           volta a parcela para em aberto
 *   POST {acao:'quitar', id, pago_em, pago_por_nome}         marca todas as em aberto como pagas
 *
 * Financeiro é só de ADM: vendedor da edição CRM recebe 403. Conta sem o módulo
 * ligado (configuracoes.modulos.reembolsos; Yuris nunca) recebe 404. Toda leitura e escrita passa pela lista de
 * contas acessíveis de 'financas'; criação vai sempre para a conta da sessão.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Financas\Reembolso;

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
if ($ctx->vendedorCrm()) responder(403, ['error' => 'Financeiro disponível só para administradores.']);
$accountId = (int) $ctx->getAccountId();
if (!Reembolso::habilitado(\App\Master\Account::findById($accountId) ?? [])) responder(404, ['error' => 'Reembolsos não estão disponíveis nesta conta.']);
$userId = (int) $ctx->getUserId();
$contas = $ctx->getAccessibleAccountIds('financas') ?: [$accountId];

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo === 'GET') {
    $lista = Reembolso::listar($contas);
    responder(200, ['success' => true, 'data' => $lista, 'resumo' => Reembolso::resumo($lista), 'hoje' => Reembolso::hoje()]);
}
if ($metodo !== 'POST') responder(405, ['error' => 'Método não permitido.']);

$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) responder(400, ['error' => 'Sessão inválida. Recarregue a página.']);

$acao = (string) ($in['acao'] ?? '');
$id = (int) ($in['id'] ?? 0);
$audit = fn(string $evento, int $entidadeId, array $det = []) => \App\Master\Account::audit($accountId, 'reembolso.' . $evento, [
    'user_id' => $userId, 'entidade' => 'reembolso', 'entidade_id' => $entidadeId, 'detalhes' => $det,
]);

switch ($acao) {
    case 'criar':
        [$d, $erro] = Reembolso::validar($in);
        if ($erro) responder(422, ['error' => $erro]);
        $novo = Reembolso::criar($accountId, $d, $userId);
        $audit('created', $novo, ['favorecido' => $d['favorecido'], 'valor_centavos' => $d['valor_centavos'], 'parcelas' => $d['parcelas']]);
        responder(200, ['success' => true, 'data' => Reembolso::buscar($novo, $contas)]);

    case 'atualizar':
        [$d, $erro] = Reembolso::validar($in);
        if ($erro) responder(422, ['error' => $erro]);
        $antes = Reembolso::buscar($id, $contas);
        if (!$antes) responder(404, ['error' => 'Reembolso não encontrado.']);
        if ($erro = Reembolso::atualizar($id, $contas, $d)) responder(422, ['error' => $erro]);
        $audit('updated', $id, ['antes' => ['favorecido' => $antes['favorecido'], 'valor_total' => $antes['valor_total'], 'parcelas' => $antes['qtd_parcelas']],
                                'depois' => ['favorecido' => $d['favorecido'], 'valor_centavos' => $d['valor_centavos'], 'parcelas' => $d['parcelas']]]);
        responder(200, ['success' => true, 'data' => Reembolso::buscar($id, $contas)]);

    case 'excluir':
        $antes = Reembolso::buscar($id, $contas);
        if (!$antes || !Reembolso::excluir($id, $contas)) responder(404, ['error' => 'Reembolso não encontrado.']);
        $audit('deleted', $id, ['favorecido' => $antes['favorecido'], 'valor_total' => $antes['valor_total'], 'valor_pago' => $antes['valor_pago']]);
        responder(200, ['success' => true]);

    case 'pagar':
    case 'desfazer':
        $parcela = (int) ($in['parcela_id'] ?? 0);
        $em = $acao === 'pagar' ? (string) ($in['pago_em'] ?? Reembolso::hoje()) : null;
        $quem = $acao === 'pagar' ? (string) ($in['pago_por_nome'] ?? '') : null;
        $reemb = Reembolso::marcarParcela($parcela, $contas, $em, $userId, $quem);
        if ($reemb === null) responder(404, ['error' => $acao === 'pagar' ? 'Parcela não encontrada, data inválida ou nome com mais de 150 caracteres.' : 'Parcela não encontrada.']);
        $audit($acao === 'pagar' ? 'parcela_paga' : 'parcela_desfeita', $reemb, ['parcela_id' => $parcela, 'pago_em' => $em, 'pago_por_nome' => $quem]);
        responder(200, ['success' => true, 'data' => Reembolso::buscar($reemb, $contas)]);

    case 'quitar':
        $em = (string) ($in['pago_em'] ?? Reembolso::hoje());
        $quem = (string) ($in['pago_por_nome'] ?? '');
        if (!Reembolso::quitar($id, $contas, $em, $userId, $quem)) responder(404, ['error' => 'Reembolso não encontrado, data inválida ou nome com mais de 150 caracteres.']);
        $audit('quitado', $id, ['pago_em' => $em, 'pago_por_nome' => $quem]);
        responder(200, ['success' => true, 'data' => Reembolso::buscar($id, $contas)]);
}

responder(400, ['error' => 'Ação desconhecida.']);
