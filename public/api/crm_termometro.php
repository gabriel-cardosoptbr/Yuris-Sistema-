<?php
/**
 * /api/crm_termometro.php: a regra do termômetro dos leads da edição CRM.
 *
 *   GET  → { ok, regra }   (o que faltar vem do padrão)
 *   POST { quente_dias, morno_dias, frio_dias, etapas_quentes[], etapas_congeladas[] }
 *        → grava. Só dono/admin, CSRF. Ver App\Prospeccao\Termometro.
 *   POST { padrao: true } → volta à regra padrão.
 *
 * Conta da edição jurídica recebe 403.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Prospeccao\Termometro;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$accountId = (int)$ctx->getAccountId();

if ($ctx->getProduto() !== 'fleetiflow') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Disponível só na edição CRM.']);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo === 'GET') {
    echo json_encode(['ok' => true, 'regra' => Termometro::daConta($accountId)], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($metodo !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido']);
    exit;
}

$in   = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido']);
    exit;
}
if (!$ctx->isOwnerOrAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Só dono ou administrador da conta altera o termômetro.']);
    exit;
}

try {
    Termometro::gravar($accountId, !empty($in['padrao']) ? Termometro::PADRAO : $in);
    echo json_encode(['ok' => true, 'regra' => Termometro::daConta($accountId)], JSON_UNESCAPED_UNICODE);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[crm_termometro] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro ao salvar.']);
}
