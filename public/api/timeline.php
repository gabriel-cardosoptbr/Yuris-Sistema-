<?php

/**
 * /api/timeline.php — a linha do tempo de uma prospecção ou de um cliente.
 *
 * GET ?entidade=card|cliente&id=N
 *
 * Para um CLIENTE devolve também os eventos das prospecções que apontam para
 * ele, então a timeline não recomeça no dia da conversão. Nada é copiado: quem
 * costura os dois lados é App\Core\Timeline, lendo as duas tabelas de uma vez.
 *
 * Multi-tenant: o filtro é por getAccessibleAccountIds e é aplicado DENTRO das
 * consultas, inclusive no JOIN de card_history, que não tem account_id próprio.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\Core\Timeline;

// read_and_close: esta rota só lê a sessão. Sem isso o polling da timeline
// serializaria os outros AJAX da tela pelo lock de sessão.
session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$entidade = strtolower(trim((string) ($_GET['entidade'] ?? '')));
$id       = (int) ($_GET['id'] ?? 0);

if ($id <= 0 || !in_array($entidade, ['card', 'cliente'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Informe entidade=card|cliente e id']);
    exit;
}

$modulo    = $entidade === 'card' ? 'prospeccao' : 'clientes';
$tenantIds = $ctx->getAccessibleAccountIds($modulo);

/*
 * Confere a posse ANTES de montar a timeline. Sem isto, um id de outra conta
 * devolveria lista vazia em vez de 403, e lista vazia é uma resposta ambígua:
 * não dá para distinguir "não é seu" de "ainda não tem evento".
 */
$pdo    = Database::getConnection();
$tabela = $entidade === 'card' ? 'cards' : 'clientes';
$in     = implode(',', array_fill(0, max(1, count($tenantIds)), '?'));
$st     = $pdo->prepare("SELECT account_id FROM `$tabela` WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$st->execute([$id]);
$dono = $st->fetchColumn();

if ($dono === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Registro não encontrado']);
    exit;
}
if (!in_array((int) $dono, array_map('intval', $tenantIds), true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissão']);
    exit;
}

$eventos = $entidade === 'card'
    ? Timeline::paraCard($id, $tenantIds)
    : Timeline::paraCliente($id, $tenantIds);

echo json_encode([
    'success'    => true,
    'entidade'   => $entidade,
    'id'         => $id,
    'total'      => count($eventos),
    'categorias' => Timeline::CATEGORIAS,
    'eventos'    => $eventos,
], JSON_UNESCAPED_UNICODE);
