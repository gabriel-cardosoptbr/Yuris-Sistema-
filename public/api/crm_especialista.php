<?php
/**
 * /api/crm_especialista.php: o especialista padrão da conta da edição CRM.
 *
 * É quem vira o responsável do card quando a conversa é respondida pelo celular
 * ou pelo WhatsApp Web, onde não há como saber quem digitou. Ver
 * App\WhatsAppAgente\SdrFleetiflow::atribuirEspecialista.
 *
 *   GET            → { ok, especialista_padrao: id|null }
 *   POST {user_id} → grava (user_id vazio ou 0 limpa). Só dono/admin, CSRF.
 *
 * Só para conta da edição CRM: na jurídica responde 403.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\WhatsAppAgente\SdrFleetiflow;

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
    echo json_encode(['ok' => true, 'especialista_padrao' => SdrFleetiflow::especialistaPadrao($accountId)]);
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
    echo json_encode(['ok' => false, 'error' => 'Só dono ou administrador da conta altera o especialista padrão.']);
    exit;
}

$uid = (int)($in['user_id'] ?? 0);
try {
    if (!SdrFleetiflow::definirEspecialistaPadrao($accountId, $uid > 0 ? $uid : null)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Usuário inválido para esta conta.']);
        exit;
    }
    echo json_encode(['ok' => true, 'especialista_padrao' => $uid > 0 ? $uid : null]);
} catch (\Throwable $e) {
    error_log('[crm_especialista] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro ao salvar.']);
}
