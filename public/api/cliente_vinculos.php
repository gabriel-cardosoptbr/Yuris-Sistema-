<?php

/**
 * /api/cliente_vinculos.php — o que está ligado a um cliente e mora do lado da
 * prospecção: conversas de WhatsApp e tarefas.
 *
 * GET ?cliente_id=N
 *
 * Nada é copiado na conversão: as duas listas são resolvidas pelas prospecções
 * que apontam para o cliente (`cards.cliente_id`). Ver App\Clientes\VinculosCliente.
 *
 * Multi-tenant: posse conferida ANTES de montar a resposta, e as consultas
 * filtram conta por dentro. Tarefa não tem account_id próprio, então o filtro
 * dela passa pelo quadro.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Clientes\VinculosCliente;
use App\Core\AccountContext;
use App\Core\Database;

// read_and_close: esta rota só lê a sessão.
session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$clienteId = (int) ($_GET['cliente_id'] ?? 0);
if ($clienteId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'cliente_id é obrigatório']);
    exit;
}

$tenantIds = $ctx->getAccessibleAccountIds('clientes');

/*
 * Posse antes de tudo. Sem esta checagem, um id de outra conta devolveria listas
 * vazias em vez de 403, e vazio é resposta ambígua: não distingue "não é seu" de
 * "ainda não tem nada".
 */
$pdo = Database::getConnection();
$st  = $pdo->prepare('SELECT account_id FROM clientes WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$st->execute([$clienteId]);
$dono = $st->fetchColumn();

if ($dono === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Cliente não encontrado']);
    exit;
}
if (!in_array((int) $dono, array_map('intval', $tenantIds), true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissão']);
    exit;
}

echo json_encode([
    'success'   => true,
    'conversas' => VinculosCliente::conversas($clienteId, $tenantIds),
    'tarefas'   => VinculosCliente::tarefas($clienteId, $tenantIds),
], JSON_UNESCAPED_UNICODE);
