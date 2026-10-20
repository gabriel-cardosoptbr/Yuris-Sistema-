<?php
/**
 * Recebimentos de clientes: o painel da tela Finanças (edição CRM) com as cobranças
 * que saem dos valores combinados de cada cliente (App\Clientes\CobrancaDoCliente).
 *
 *   GET  ?filtro=abertas|atrasadas|pagas   números do mês e a lista
 *   POST {acao:'pagar', id, pago_em, valor_pago, forma_pagamento}
 *   POST {acao:'desfazer' | 'cancelar', id}
 *
 * Financeiro é de ADM: vendedor da edição CRM recebe 403. CSRF no POST.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Clientes\CobrancaDoCliente;
use App\Core\AccountContext;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rcResponder(array $corpo, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) rcResponder(['ok' => false, 'error' => 'Sessão expirada.'], 401);
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if ($ctx->getProduto() !== 'fleetiflow') rcResponder(['ok' => false, 'error' => 'Disponível só na edição CRM.'], 404);
if ($ctx->vendedorCrm()) rcResponder(['ok' => false, 'error' => 'O financeiro é do administrador.'], 403);
$acc = (int) $ctx->getAccountId();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        rcResponder(['ok' => true, 'data' => CobrancaDoCliente::painel($acc, (string) ($_GET['filtro'] ?? 'abertas')), 'formas' => CobrancaDoCliente::FORMAS]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') rcResponder(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) rcResponder(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 419);
    $id = (int) ($in['id'] ?? 0);
    match ((string) ($in['acao'] ?? '')) {
        'pagar'    => CobrancaDoCliente::baixar($acc, (int) $ctx->getUserId(), $id, $in),
        'desfazer' => CobrancaDoCliente::desfazer($acc, $id),
        'cancelar' => CobrancaDoCliente::cancelar($acc, $id),
        default    => throw new \InvalidArgumentException('Ação desconhecida.'),
    };
    rcResponder(['ok' => true]);
} catch (\InvalidArgumentException $e) {
    rcResponder(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log('[recebimentos_clientes] ' . get_class($e) . ' ' . $e->getMessage());
    rcResponder(['ok' => false, 'error' => 'Não foi possível concluir agora.'], 500);
}
