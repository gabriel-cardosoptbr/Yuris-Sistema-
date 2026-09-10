<?php
/**
 * GET/POST /api/whatsapp/captacao_toggle.php
 *
 * Liga e desliga a CAPTAÇÃO AUTOMÁTICA da conta: quando ligada, cada pessoa
 * nova que manda mensagem no WhatsApp vira um card na Prospecção, já vinculado
 * à conversa.
 *
 *   GET                       -> { ok, ligada }
 *   POST { ligada:0|1, _csrf } -> { ok, ligada, saved:true }
 *
 * ---------------------------------------------------------------------------
 * AUTORIZAÇÃO: OWNER/ADMIN, COMO O TOGGLE DO AGENTE
 * ---------------------------------------------------------------------------
 * A opção muda o que o sistema faz sozinho com o funil do escritório inteiro,
 * não o que uma pessoa vê. Quem atende não decide isso: um clique errado passa
 * a despejar card de banco e de cobrança no funil de todo mundo.
 *
 * A diferença para o `agent_channel_toggle.php` é o escopo: aquele é por CANAL
 * (e por isso resolve grant), este é por CONTA, porque o funil é da conta e não
 * do canal. Uma conta com dois canais capta pelos dois, para o mesmo funil.
 *
 * ---------------------------------------------------------------------------
 * SEM MIGRATION
 * ---------------------------------------------------------------------------
 * `whatsapp_settings` já é uma tabela chave/valor por conta. Uma coluna nova
 * seria ALTER em produção para guardar um zero ou um um.
 */

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Prospeccao\CaptacaoAutomatica;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid  = $_SESSION['user_id']    ?? null;
$csrf = $_SESSION['csrf_token'] ?? '';
if (!$uid) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$ctx       = AccountContext::fromSession();
$ctx->assertAccountActive();
$accountId = (int) $ctx->getAccountId();
if ($accountId <= 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem acesso']);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'GET') {
    echo json_encode([
        'ok'             => true,
        'ligada'         => CaptacaoAutomatica::ligada($accountId),
        // O front esconde o botão de quem não pode mexer: oferecer e recusar
        // com 403 depois é pior que não oferecer.
        'pode_alterar'   => $ctx->isOwnerOrAdmin(),
    ]);
    exit;
}

if ($metodo !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

if (!$ctx->isOwnerOrAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Apenas owner ou admin pode mudar a captação automática']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($payload['_csrf']) || !hash_equals((string) $csrf, (string) $payload['_csrf'])) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF inválido']);
    exit;
}

$ligar = !empty($payload['ligada']);
if (!CaptacaoAutomatica::definir($accountId, $ligar)) {
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível salvar a opção']);
    exit;
}

echo json_encode([
    'ok'     => true,
    'ligada' => CaptacaoAutomatica::ligada($accountId), // relê do banco: confirma que gravou
    'saved'  => true,
]);
