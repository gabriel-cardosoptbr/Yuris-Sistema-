<?php
/**
 * poll.php — cursor de eventos do chat (Onda 4 / 4B). Endpoint BARATO por design:
 * o chat.js pergunta "mudou algo no canal?" a cada 2s e so refaz as queries pesadas
 * (lista + conversa aberta) quando events_seq muda. Devolve tambem o status da conexao.
 *
 * Por que existir: em prefork+mod_php nao da pra manter SSE/WebSocket (cada conexao
 * segura 1 processo). Entao mantemos poll, mas tornamos o poll quase de graca: 1 SELECT
 * por PK (events_seq/status), e o trabalho pesado so quando de fato houve novidade.
 *
 * Autorizacao por GRANT (view) via WhatsAppChannelAccessService — deny-by-default, igual
 * aos demais endpoints data-plane. Sem channel_id, resolve o canal padrao do tenant.
 *
 * NB: a autorizacao roda a cada poll (algumas queries indexadas). Ainda assim o poll fica
 * muito mais leve que o getChatList antigo (subqueries por linha). Um cache curto da
 * decisao (APCu/sessao) pode entrar depois se o profiling mostrar que a authz esquenta.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\WhatsAppAgente\WhatsAppChannelAccessService;

session_start(['read_and_close' => true]); // le a sessao e libera o lock (poll concorrente)
$uid = $_SESSION['user_id'] ?? null;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!$uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

try {
    $pdo       = \App\Core\Database::getConnection();
    $ctx       = AccountContext::fromSession();
    $accountId = $ctx->getAccountId();
    $reqChannel = (isset($_GET['channel_id']) && (int)$_GET['channel_id'] > 0) ? (int)$_GET['channel_id'] : null;

    // Deny-by-default: resolve o canal autorizado (view). Sem grant -> lanca -> 403.
    // Credencial/instancia sao SEMPRE resolvidas no backend (nunca confia no front).
    $ch        = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $reqChannel, 'view');
    $channelId = (int)$ch['channel_id'];

    $st = $pdo->prepare('SELECT events_seq, status FROM whatsapp_instances WHERE id = ? LIMIT 1');
    $st->execute([$channelId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'channel_id' => $channelId,
        'seq'        => (int)($row['events_seq'] ?? 0),
        'status'     => (string)($row['status'] ?? 'close'),
    ]);
} catch (\Throwable $e) {
    // resolveForRequest lanca em acesso negado / canal inexistente. O front trata
    // qualquer falha do poll como "sem cursor" e cai no polling antigo (fail-safe).
    error_log('[whatsapp/poll] ' . $e->getMessage());
    http_response_code(403);
    echo json_encode(['error' => 'Sem acesso ao canal']);
}
