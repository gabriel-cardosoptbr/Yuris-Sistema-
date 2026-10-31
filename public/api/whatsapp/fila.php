<?php
/**
 * GET /api/whatsapp/fila.php: o painel Operação › Fila (public/fila.php).
 *
 *   -> { ok, disponivel, agora, aberturas: { fluxos:[...], da_vez } }
 *
 * As aberturas (robô da planilha e disparo das listas) vêm do retrato que o n8n manda
 * (App\WhatsAppAgente\FilaDaProspeccao). Os follow-ups e os erros deles vêm de
 * /api/followup.php, que a tela chama junto. Só owner/admin, e só na conta que os fluxos
 * atendem, como a Roleta.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\WhatsAppAgente\FilaDaProspeccao;
use App\WhatsAppAgente\SdrFleetiflow;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$accountId = (int)$ctx->getAccountId();
if (!$ctx->isOwnerOrAdmin()) { http_response_code(403); echo json_encode(['error' => 'A fila é vista pela administração da conta']); exit; }
if ($accountId <= 0 || !SdrFleetiflow::contaDoTokenGlobal($accountId)) { echo json_encode(['ok' => true, 'disponivel' => false]); exit; }

try {
    echo json_encode(['ok' => true, 'disponivel' => true, 'agora' => date('c'), 'aberturas' => FilaDaProspeccao::painel($accountId)], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[fila] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível montar a fila']);
}
