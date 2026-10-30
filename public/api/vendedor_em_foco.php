<?php
/**
 * Vendedor em foco da edição CRM (App\Core\VendedorEmFoco).
 *
 *   GET                                   {pode_escolher, atual:{id,nome}|null, vendedores:[{id,nome}]}
 *   POST {vendedor_id: N|null, csrf_token} grava a escolha na sessão (null = todos)
 *
 * Só dono e admin da edição CRM escolhem. Para o vendedor, `atual` é ele mesmo e a
 * lista vem vazia.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\VendedorEmFoco;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function vefResponder(array $corpo, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) vefResponder(['ok' => false, 'error' => 'Sessão expirada.'], 401);
$ctx = AccountContext::fromSession();
$pode = VendedorEmFoco::podeEscolher($ctx);
$vendedores = $pode ? VendedorEmFoco::vendedores($ctx) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) {
        vefResponder(['ok' => false, 'error' => 'Sessão inválida. Recarregue a página.'], 403);
    }
    if (!$pode) vefResponder(['ok' => false, 'error' => 'Só administradores escolhem o vendedor.'], 403);
    $id = (int) ($input['vendedor_id'] ?? 0);
    if ($id && !in_array($id, array_column($vendedores, 'id'), true)) {
        vefResponder(['ok' => false, 'error' => 'Vendedor não encontrado.'], 404);
    }
    VendedorEmFoco::definir($id ?: null);
}
session_write_close();

$atual = VendedorEmFoco::id();
$nome = null;
if ($atual) {
    foreach ($vendedores as $v) if ($v['id'] === $atual) $nome = $v['nome'];
    if ($nome === null) foreach ($ctx->getAccessibleUsers(false) as $u) if ((int) $u['id'] === $atual) $nome = (string) $u['nome'];
}
vefResponder([
    'ok'            => true,
    'pode_escolher' => $pode,
    'atual'         => $atual ? ['id' => $atual, 'nome' => $nome] : null,
    'vendedores'    => $vendedores,
]);
