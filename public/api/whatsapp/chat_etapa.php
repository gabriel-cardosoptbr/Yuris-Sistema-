<?php
/**
 * GET/POST /api/whatsapp/chat_etapa.php
 *
 * Etapa do funil dentro do Chat (só conta Fleetiflow). A etapa É a coluna do
 * card ligado à conversa: mudar aqui move o card na Prospecção, e arrastar lá
 * muda o que aparece aqui. Não existe um segundo estado para ficar fora de sincronia.
 *
 *   GET  ?channel_id=ID                               -> { ok, colunas:[{id,nome,cor}] }
 *   POST { remote_jid, coluna_id, channel_id?, _csrf } -> { ok, card_id, coluna_id, nome, cor }
 *        Conversa sem card: o card é criado (telefone da conversa) e já vai para a coluna.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\WhatsAppAgente\SdrFleetiflow;
use App\WhatsAppAgente\WhatsAppChannelAccessService;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid  = $_SESSION['user_id']    ?? null;
$csrf = $_SESSION['csrf_token'] ?? '';
if (!$uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$ctx = AccountContext::fromSession();
$accountId = (int)$ctx->getAccountId();
$pdo = Database::getConnection();

try {
    $in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $ch = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, ($in['channel_id'] ?? $_GET['channel_id'] ?? null), 'view');
    $instanceId = (int)$ch['channel_id'];
    $dono = (int)$ch['owner_account_id'];
    if (!SdrFleetiflow::contaUsa($dono)) {
        http_response_code(404); echo json_encode(['error' => 'Indisponível para esta conta']); exit;
    }

    // O funil é o da conta dona do canal; quem está no Chat precisa enxergar a prospecção dela.
    $acessiveis = $ctx->getAccessibleAccountIds('prospeccao') ?: [$accountId];
    if (!in_array($dono, array_map('intval', $acessiveis), true)) {
        http_response_code(403); echo json_encode(['error' => 'Sem acesso à prospecção']); exit;
    }

    $st = $pdo->prepare('SELECT id, nome, cor FROM pipeline_columns WHERE account_id = ? ORDER BY ordem, id');
    $st->execute([$dono]);
    $colunas = $st->fetchAll(\PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['ok' => true, 'colunas' => $colunas]); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
    }

    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['_csrf'] ?? null);
    if (!$tok || !hash_equals((string)$csrf, (string)$tok)) {
        http_response_code(400); echo json_encode(['error' => 'CSRF inválido']); exit;
    }
    $jid = trim((string)($in['remote_jid'] ?? ''));
    $colunaId = (int)($in['coluna_id'] ?? 0);
    $coluna = null;
    foreach ($colunas as $c) { if ((int)$c['id'] === $colunaId) { $coluna = $c; break; } }
    if ($jid === '' || !$coluna) {
        http_response_code(422); echo json_encode(['error' => 'Conversa ou etapa inválida']); exit;
    }

    $st = $pdo->prepare(
        'SELECT wc.linked_card_id FROM whatsapp_chats wc
           JOIN cards c ON c.id = wc.linked_card_id AND c.deleted_at IS NULL AND c.account_id = ?
          WHERE wc.instance_id = ? AND wc.remote_jid = ? LIMIT 1'
    );
    $st->execute([$dono, $instanceId, $jid]);
    $cardId = (int)($st->fetchColumn() ?: 0) ?: null;

    if (!$cardId) {
        // Telefone da conversa: o próprio JID, ou o que a identidade sabe de um @lid.
        $fone = str_ends_with($jid, '@s.whatsapp.net') ? explode('@', $jid)[0] : '';
        if ($fone === '') {
            $st = $pdo->prepare("SELECT phone FROM whatsapp_identidades WHERE instance_id = ? AND (lid = ? OR jid = ?) AND phone REGEXP '^[0-9]{10,13}$' LIMIT 1");
            $st->execute([$instanceId, $jid, $jid]);
            $fone = (string)($st->fetchColumn() ?: '');
        }
        if ($fone === '') {
            http_response_code(422); echo json_encode(['error' => 'Esta conversa não tem telefone para criar o card']); exit;
        }
        $cardId = SdrFleetiflow::garantirCard($dono, $instanceId, $jid, $fone);
        if (!$cardId) {
            http_response_code(422); echo json_encode(['error' => 'Não foi possível criar o card (o número já é cliente?)']); exit;
        }
    }

    $atual = $pdo->prepare('SELECT coluna_id FROM cards WHERE id = ?');
    $atual->execute([$cardId]);
    if ((int)$atual->fetchColumn() !== $colunaId) {
        \App\Prospeccao\Card::move($cardId, $colunaId, 0, (int)$uid);
    }

    echo json_encode(['ok' => true, 'card_id' => $cardId, 'coluna_id' => $colunaId,
                      'nome' => $coluna['nome'], 'cor' => $coluna['cor']]);
} catch (\Throwable $e) {
    error_log('[chat_etapa] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao mudar a etapa']);
}
