<?php
/**
 * Valores do cliente: o "Financeiro" da ficha do cliente (App\Clientes\ValoresDoCliente).
 *
 *   GET  ?cliente_id=N             as linhas, o resumo, as cobranças (contas a receber), os contratos e os setores do cliente
 *   GET  ?quadro=1                 por cliente: setores e total por mês (o card do quadro de Clientes)
 *   POST {acao:'setores', cliente_id, setores:[team_id...]}
 *   POST {acao:'salvar', cliente_id, id?, tipo, descricao, valor, dia_vencimento, inicio, fim, ativo, observacao, contrato_id}
 *   POST {acao:'remover', cliente_id, id}
 *   POST {acao:'cobranca_pagar', cliente_id, id, pago_em, valor_pago, forma_pagamento}
 *   POST {acao:'cobranca_desfazer' | 'cobranca_cancelar' | 'cobranca_reabrir', cliente_id, id}
 *
 * Sessão do CRM, CSRF no POST, quem abre Clientes. Baixa de cobrança é financeiro:
 * vendedor da edição CRM não faz. Cliente de outra conta: 400.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Clientes\CobrancaDoCliente;
use App\Clientes\SetoresDoCliente;
use App\Clientes\ValoresDoCliente;
use App\Core\AccountContext;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvResponder(array $corpo, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) cvResponder(['ok' => false, 'error' => 'Sessão expirada.'], 401);
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$perms = $_SESSION['user_permissions'] ?? [];
$admin = strtolower((string) ($_SESSION['user_perfil'] ?? '')) === 'admin';
if (!$admin && !in_array('*', $perms, true) && !in_array('clientes', $perms, true)) cvResponder(['ok' => false, 'error' => 'Sem permissão para clientes.'], 403);
$acc = (int) $ctx->getAccountId();

/** Tudo o que a seção Financeiro mostra. */
function cvTudo(int $acc, int $cliente): array
{
    $d = ValoresDoCliente::doCliente($acc, $cliente);
    $d['cobrancas'] = CobrancaDoCliente::doCliente($acc, $cliente);
    $k = \App\Core\Database::getConnection()->prepare("SELECT id, titulo, status FROM crm_contratos WHERE account_id = ? AND cliente_id = ? ORDER BY id DESC LIMIT 100");
    $k->execute([$acc, $cliente]);
    $d['contratos'] = array_map(fn($c) => ['id' => (int) $c['id'], 'titulo' => $c['titulo'], 'status' => $c['status']], $k->fetchAll(\PDO::FETCH_ASSOC));
    $d['setores'] = SetoresDoCliente::doCliente($acc, $cliente);
    $d['setores_conta'] = SetoresDoCliente::daConta($acc);
    return $d;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['quadro'])) {
        cvResponder(['ok' => true, 'data' => SetoresDoCliente::quadro($ctx->getAccessibleAccountIds('clientes') ?: [$acc])]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        cvResponder(['ok' => true, 'data' => cvTudo($acc, (int) ($_GET['cliente_id'] ?? 0)), 'tipos' => ValoresDoCliente::TIPOS, 'formas' => CobrancaDoCliente::FORMAS,
            'pode_baixar' => !$ctx->vendedorCrm()]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cvResponder(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) cvResponder(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 419);
    $cliente = (int) ($in['cliente_id'] ?? 0);
    if (($in['acao'] ?? '') === 'salvar') {
        $id = ValoresDoCliente::salvar($acc, (int) $ctx->getUserId(), $cliente, $in);
        cvResponder(['ok' => true, 'id' => $id, 'data' => cvTudo($acc, $cliente)]);
    }
    if (($in['acao'] ?? '') === 'setores') {
        SetoresDoCliente::salvar($acc, (int) $ctx->getUserId(), $cliente, (array) ($in['setores'] ?? []));
        cvResponder(['ok' => true, 'data' => cvTudo($acc, $cliente)]);
    }
    if (($in['acao'] ?? '') === 'remover') {
        ValoresDoCliente::remover($acc, $cliente, (int) ($in['id'] ?? 0));
        cvResponder(['ok' => true, 'data' => cvTudo($acc, $cliente)]);
    }
    if (str_starts_with((string) ($in['acao'] ?? ''), 'cobranca_')) {
        if ($ctx->vendedorCrm()) cvResponder(['ok' => false, 'error' => 'Baixa de cobrança é do financeiro.'], 403);
        $cid = (int) ($in['id'] ?? 0);
        // A cobrança tem de ser deste cliente (e da conta).
        $chk = \App\Core\Database::getConnection()->prepare('SELECT COUNT(*) FROM crm_cliente_cobrancas WHERE id = ? AND account_id = ? AND cliente_id = ?');
        $chk->execute([$cid, $acc, $cliente]);
        if (!(int) $chk->fetchColumn()) cvResponder(['ok' => false, 'error' => 'Cobrança não encontrada.'], 400);
        match ($in['acao']) {
            'cobranca_pagar'    => CobrancaDoCliente::baixar($acc, (int) $ctx->getUserId(), $cid, $in),
            'cobranca_desfazer' => CobrancaDoCliente::desfazer($acc, $cid),
            'cobranca_cancelar' => CobrancaDoCliente::cancelar($acc, $cid),
            'cobranca_reabrir'  => CobrancaDoCliente::reabrir($acc, $cid),
            default             => throw new \InvalidArgumentException('Ação desconhecida.'),
        };
        cvResponder(['ok' => true, 'data' => cvTudo($acc, $cliente)]);
    }
    cvResponder(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (\InvalidArgumentException $e) {
    cvResponder(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log('[cliente_valores] ' . get_class($e) . ' ' . $e->getMessage());
    cvResponder(['ok' => false, 'error' => 'Não foi possível concluir agora.'], 500);
}
