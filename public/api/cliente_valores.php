<?php
/**
 * Valores do cliente: o "Financeiro" da ficha do cliente (App\Clientes\ValoresDoCliente).
 *
 *   GET  ?cliente_id=N             as linhas e o resumo (por mês, anual, fixo, por demanda)
 *   POST {acao:'salvar', cliente_id, id?, tipo, descricao, valor, dia_vencimento, inicio, fim, ativo, observacao}
 *   POST {acao:'remover', cliente_id, id}
 *
 * Sessão do CRM, CSRF no POST, quem abre Clientes. Cliente de outra conta: 400.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

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

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        cvResponder(['ok' => true, 'data' => ValoresDoCliente::doCliente($acc, (int) ($_GET['cliente_id'] ?? 0)), 'tipos' => ValoresDoCliente::TIPOS]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cvResponder(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) cvResponder(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 419);
    $cliente = (int) ($in['cliente_id'] ?? 0);
    if (($in['acao'] ?? '') === 'salvar') {
        $id = ValoresDoCliente::salvar($acc, (int) $ctx->getUserId(), $cliente, $in);
        cvResponder(['ok' => true, 'id' => $id, 'data' => ValoresDoCliente::doCliente($acc, $cliente)]);
    }
    if (($in['acao'] ?? '') === 'remover') {
        ValoresDoCliente::remover($acc, $cliente, (int) ($in['id'] ?? 0));
        cvResponder(['ok' => true, 'data' => ValoresDoCliente::doCliente($acc, $cliente)]);
    }
    cvResponder(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (\InvalidArgumentException $e) {
    cvResponder(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log('[cliente_valores] ' . get_class($e) . ' ' . $e->getMessage());
    cvResponder(['ok' => false, 'error' => 'Não foi possível concluir agora.'], 500);
}
