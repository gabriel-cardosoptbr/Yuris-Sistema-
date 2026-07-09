<?php
/**
 * POST /api/whatsapp/agent_takeover.php
 *
 * "Assumir conversa": liga/desliga a pausa do agente de IA em UMA conversa.
 * Quando agent_paused=1, o webhook nao dispara a IA naquela conversa (humano
 * assumiu). Ao DESpausar (paused=0) tambem REATIVA a sessao do agente
 * (controller_mode/current_state via resumeBot); sem isso a sessao fica presa
 * em human_takeover/terminal e o bot nunca mais responde aquela conversa.
 *
 * Autorizacao POR CANAL (grant), nao por hierarquia matriz/filial: a conversa
 * precisa estar num canal sobre o qual a conta tem permissao 'send' (dono, ou
 * compartilhado com can_send e a flag ligada). Assim uma filial nao pausa/despausa
 * o agente da matriz sem um compartilhamento explicito (e vice-versa).
 *
 * Body JSON: { paused: 0|1, chat_id?: int, instance_id?: int, remote_jid?: string, _csrf }
 * Identifica a conversa por chat_id OU por (instance_id + remote_jid) OU por remote_jid,
 * sempre DENTRO do canal autorizado.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Services/WhatsAppChannelAccessService.php';
require_once __DIR__ . '/../../../app/Services/AiIntake/IntakeSessionRepository.php';

use App\Helpers\AccountContext;
use App\Models\Database;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid  = $_SESSION['user_id']    ?? null;
$csrf = $_SESSION['csrf_token'] ?? '';
if (!$uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['_csrf'] ?? ($in['csrf_token'] ?? null));
if (!$tok || !hash_equals((string)$csrf, (string)$tok)) { http_response_code(400); echo json_encode(['error' => 'CSRF inválido']); exit; }

$ctx = AccountContext::fromSession();
$accountId = (int)$ctx->getAccountId();
if ($accountId <= 0) { http_response_code(403); echo json_encode(['error' => 'Sem acesso']); exit; }

$paused = !empty($in['paused']) ? 1 : 0;
$chatId = (int)($in['chat_id'] ?? 0);
$instId = (int)($in['instance_id'] ?? 0);
$jid    = trim((string)($in['remote_jid'] ?? ''));

try {
    $pdo = Database::getConnection();

    // Autoriza o CANAL alvo por grant (deny-by-default). 'send' = acao operacional de atendimento.
    // resolveForRequest resolve o canal (pedido/proprio/compartilhado), autoriza e faz 403+exit se negar.
    $ch = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $instId ?: null, 'send');
    $channelId = (int)$ch['channel_id'];

    // Localiza a conversa DENTRO do canal autorizado (nunca por escopo de conta).
    if ($chatId > 0) {
        $st = $pdo->prepare("SELECT id, instance_id, remote_jid FROM whatsapp_chats WHERE id = ? AND instance_id = ? LIMIT 1");
        $st->execute([$chatId, $channelId]);
    } elseif ($jid !== '') {
        $st = $pdo->prepare("SELECT id, instance_id, remote_jid FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? ORDER BY last_message_at DESC LIMIT 1");
        $st->execute([$channelId, $jid]);
    } else {
        http_response_code(422);
        echo json_encode(['error' => 'Informe chat_id, (instance_id + remote_jid) ou remote_jid']);
        exit;
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Conversa não encontrada no seu acesso']); exit; }

    $chatId = (int)$row['id'];
    $chJid  = (string)$row['remote_jid'];

    $upd = $pdo->prepare('UPDATE whatsapp_chats SET agent_paused = :p WHERE id = :id');
    $upd->execute(['p' => $paused, 'id' => $chatId]);

    // Ao devolver a conversa para o bot, reativa a sessao do agente (senao fica presa
    // em human_takeover/terminal e o bot nunca mais responde). Best-effort: nao pode
    // derrubar a resposta de sucesso do takeover.
    if ($paused === 0) {
        try {
            (new \App\Services\AiIntake\IntakeSessionRepository($pdo))->resumeBot($channelId, $chJid);
        } catch (\Throwable $e) {
            error_log('[agent_takeover] resumeBot falhou: ' . $e->getMessage());
        }
    }

    echo json_encode(['ok' => true, 'chat_id' => $chatId, 'agent_paused' => $paused]);
} catch (\Throwable $e) {
    error_log('[agent_takeover] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao alterar atendimento']);
}
