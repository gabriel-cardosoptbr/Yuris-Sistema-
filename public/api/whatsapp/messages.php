<?php
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;

session_start(['read_and_close' => true]);

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();

$method    = $_SERVER['REQUEST_METHOD'];
$remoteJid = trim($_GET['jid'] ?? '');
$beforeId  = (int)($_GET['before_id'] ?? 0);
$afterId   = (int)($_GET['after_id']  ?? 0);
// Cursores cronológicos (created_at). Acompanham o id como desempate pra
// paginação keyset por (created_at, id) — ordena pela data REAL da mensagem.
$beforeAt  = trim($_GET['before_at'] ?? '');
$afterAt   = trim($_GET['after_at']  ?? '');
$limit     = min((int)($_GET['limit'] ?? 50), 100);
$search    = trim($_GET['search'] ?? '');

if ($method !== 'GET') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}
if (!$remoteJid) {
    http_response_code(400); echo json_encode(['error' => 'jid obrigatório']); exit;
}

try {
    $msgModel   = new WhatsAppMessage();
    $pdo        = \App\Core\Database::getConnection();

    // Leitura do histórico = 'view' no canal (deny-by-default). Resolve o canal
    // próprio ou, com a flag ligada, o compartilhado (filial herdando o da matriz).
    // instance_id e credenciais saem do backend (dono do canal), nunca do front.
    $ch         = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $_GET['channel_id'] ?? null, 'view');
    $instanceId = (int)$ch['channel_id'];

    if ($search !== '') {
        // Busca dentro da conversa: retorna apenas mensagens que matcham
        $msgs = $msgModel->searchInChat($instanceId, $remoteJid, $search, $limit);
    } elseif ($afterId > 0 || $afterAt !== '') {
        $msgs = $msgModel->findAfter($instanceId, $remoteJid, $afterId, $afterAt !== '' ? $afterAt : null);
    } else {
        $msgs = $msgModel->findByJid($instanceId, $remoteJid, $limit, $beforeId, $beforeAt !== '' ? $beforeAt : null);
    }

    echo json_encode([
        'ok'       => true,
        'messages' => $msgs,
        'jid'      => $remoteJid,
        'count'    => count($msgs),
    ]);
} catch (Throwable $e) {
    // P1 LGPD (2D.1): em prod esconde getMessage/file/line; em dev mantém debug
    require_once __DIR__ . '/../../../app/Core/ErrorReporter.php';
    \App\Core\ErrorReporter::handle($e);
}
