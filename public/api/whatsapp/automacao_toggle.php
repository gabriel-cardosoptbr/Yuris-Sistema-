<?php
/**
 * GET/POST /api/whatsapp/automacao_toggle.php
 *
 * Os botões "Disparo" e "Follow-up" do Chat do CRM (App\WhatsAppAgente\AutomacaoSdr):
 * ligam e desligam o robô de disparo (abertura para lead novo) e a cadência de
 * follow-up que rodam no n8n. O n8n pergunta antes de cada mensagem
 * (sdr_automacao.php), então desligar vale na hora.
 *
 *   GET                                         -> { ok, disponivel, pode_alterar, disparo, followup }
 *   POST { qual:'disparo'|'followup', ligado:0|1, _csrf } -> { ok, disparo, followup }
 *
 * Só existe para a conta que os fluxos atendem (a dona do token da prospecção, a
 * Fleetiflow): nas outras, `disponivel` é false e o botão não aparece. Mudar é de
 * owner/admin, como a captação e o agente: decide o que o sistema faz sozinho.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\WhatsAppAgente\AutomacaoSdr;
use App\WhatsAppAgente\SdrFleetiflow;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid  = $_SESSION['user_id']    ?? null;
$csrf = $_SESSION['csrf_token'] ?? '';
if (!$uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$accountId = (int)$ctx->getAccountId();
$disponivel = $accountId > 0 && SdrFleetiflow::contaDoTokenGlobal($accountId);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$disponivel) { echo json_encode(['ok' => true, 'disponivel' => false]); exit; }
    echo json_encode(['ok' => true, 'disponivel' => true, 'pode_alterar' => $ctx->isOwnerOrAdmin()] + AutomacaoSdr::estado($accountId));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Método não permitido']); exit; }
if (!$disponivel) { http_response_code(404); echo json_encode(['error' => 'Indisponível para esta conta']); exit; }
if (!$ctx->isOwnerOrAdmin()) { http_response_code(403); echo json_encode(['error' => 'Apenas owner ou admin pode ligar e desligar o disparo e o follow-up']); exit; }

$in = json_decode((string)file_get_contents('php://input'), true) ?? [];
if (empty($in['_csrf']) || !hash_equals((string)$csrf, (string)$in['_csrf'])) { http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit; }

$qual = (string)($in['qual'] ?? '');
if (!isset(AutomacaoSdr::CHAVES[$qual])) { http_response_code(422); echo json_encode(['error' => 'Opção inválida']); exit; }
if (!AutomacaoSdr::definir($accountId, $qual, !empty($in['ligado']), (int)$uid)) {
    http_response_code(500); echo json_encode(['error' => 'Não foi possível salvar']); exit;
}
echo json_encode(['ok' => true] + AutomacaoSdr::estado($accountId)); // relê do banco: confirma que gravou
