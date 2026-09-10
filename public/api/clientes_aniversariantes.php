<?php

/**
 * /api/clientes_aniversariantes.php — quem faz aniversário no mês.
 *
 * GET ?mes=1..12   (sem `mes`, usa o mês corrente)
 *
 * Devolve a lista, a contagem de cada mês do ano e a cobertura (quantos clientes
 * ainda estão sem data). A cobertura vai junto de propósito: um relatório que
 * enxerga 3 de 46 clientes precisa dizer isso, senão o escritório conclui que só
 * tem três aniversários no ano e o campo nunca chega a ser preenchido.
 *
 * Multi-tenant pelas contas acessíveis do módulo `clientes`.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Clientes\Aniversariantes;
use App\Core\AccountContext;

// read_and_close: esta rota só lê.
session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$tenantIds = $ctx->getAccessibleAccountIds('clientes');

$mes = (int) ($_GET['mes'] ?? 0);
if ($mes < 1 || $mes > 12) {
    $mes = (int) date('n');
}

echo json_encode([
    'success'      => true,
    'mes'          => $mes,
    'aniversariantes' => Aniversariantes::doMes($tenantIds, $mes),
    'por_mes'      => Aniversariantes::porMes($tenantIds),
    'cobertura'    => Aniversariantes::cobertura($tenantIds),
], JSON_UNESCAPED_UNICODE);
