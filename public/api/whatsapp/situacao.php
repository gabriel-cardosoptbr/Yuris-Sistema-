<?php
/**
 * /api/whatsapp/situacao.php: a linha "Aguardando resposta" do Chat (edição CRM).
 *
 *   GET  ?jid=...&channel_id=...          situação da conversa (SituacaoConversa::daConversa)
 *        200 { ok, disponivel, situacao? }   disponivel=false fora da edição CRM
 *   POST { action:'mandar_agente', remote_jid, channel_id?, _csrf }
 *        botão "Mandar para a Vitória": entrega de novo ao agente as mensagens do
 *        lead que esperam resposta. 200 { ok, enviadas, situacao } · 409 { error }
 *
 * Canal resolvido e autorizado no backend (WhatsAppChannelAccessService): ler é
 * 'view', mandar é 'send'. A conversa é sempre procurada DENTRO desse canal.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\WhatsAppAgente\SdrFleetiflow;
use App\WhatsAppAgente\SituacaoConversa;
use App\WhatsAppAgente\WhatsAppChannelAccessService;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = $_SESSION['user_id'] ?? null;
if (!$uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$ctx = AccountContext::fromSession();
$accountId = (int)$ctx->getAccountId();
if ($accountId <= 0) { http_response_code(403); echo json_encode(['error' => 'Sem acesso']); exit; }

$metodo = $_SERVER['REQUEST_METHOD'];
$in = [];
if ($metodo === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['_csrf'] ?? null);
    if (!$tok || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$tok)) {
        http_response_code(400); echo json_encode(['error' => 'CSRF inválido']); exit;
    }
} elseif ($metodo !== 'GET') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}

$jid = trim((string)($metodo === 'POST' ? ($in['remote_jid'] ?? '') : ($_GET['jid'] ?? '')));
if ($jid === '' || str_ends_with($jid, '@g.us')) { echo json_encode(['ok' => true, 'disponivel' => false]); exit; }

try {
    $pdo = Database::getConnection();
    $canal = $metodo === 'POST' ? ($in['channel_id'] ?? null) : ($_GET['channel_id'] ?? null);
    $ch = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $canal, $metodo === 'POST' ? 'send' : 'view');
    $dono = (int)$ch['owner_account_id'];
    $instanceId = (int)$ch['channel_id'];

    if (!SdrFleetiflow::contaUsa($dono)) { echo json_encode(['ok' => true, 'disponivel' => false]); exit; }

    if ($metodo === 'POST') {
        if (($in['action'] ?? '') !== 'mandar_agente') { http_response_code(422); echo json_encode(['error' => 'Ação desconhecida']); exit; }
        $r = SituacaoConversa::mandarParaAgente($dono, $instanceId, $jid, (int)$uid);
        if (!$r['ok']) { http_response_code(409); echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
        echo json_encode(['ok' => true, 'enviadas' => $r['enviadas'],
                          'situacao' => SituacaoConversa::daConversa($dono, $instanceId, $jid)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sit = SituacaoConversa::daConversa($dono, $instanceId, $jid);
    if ($sit === null) { http_response_code(404); echo json_encode(['error' => 'Conversa não encontrada no seu acesso']); exit; }
    echo json_encode(['ok' => true, 'disponivel' => true, 'situacao' => $sit], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[whatsapp/situacao] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
}
