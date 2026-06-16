<?php
/**
 * POST /api/whatsapp/agent_takeover.php
 *
 * "Assumir conversa": liga/desliga a pausa do agente de IA em UMA conversa.
 * Quando agent_paused=1, o webhook não dispara a IA naquela conversa (humano
 * assumiu). Ação operacional do atendimento, então qualquer usuário logado do
 * tenant pode usar (não é config de infra).
 *
 * Body JSON: { paused: 0|1, chat_id?: int, instance_id?: int, remote_jid?: string, _csrf }
 * Identifica a conversa por chat_id OU por (instance_id + remote_jid).
 * Escopo: a conversa precisa pertencer a uma conta acessível (matriz/filial).
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';

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
if (!$tok || $tok !== $csrf) { http_response_code(400); echo json_encode(['error' => 'CSRF inválido']); exit; }

$ctx = AccountContext::fromSession();

// Escopo de contas acessíveis (matriz vê filiais; filial vê própria + matriz).
$scope = $ctx->getAccessibleAccountIds();
$mid = $ctx->getPipelineAccountId();
if ($mid > 0 && !in_array($mid, $scope, true)) $scope[] = $mid;
$scope = array_values(array_unique(array_filter(array_map('intval', $scope), fn($v) => $v > 0)));
if (empty($scope)) { http_response_code(403); echo json_encode(['error' => 'Sem acesso']); exit; }

$paused   = !empty($in['paused']) ? 1 : 0;
$chatId   = (int)($in['chat_id'] ?? 0);
$instId   = (int)($in['instance_id'] ?? 0);
$jid      = trim((string)($in['remote_jid'] ?? ''));

try {
    $pdo = Database::getConnection();
    $ph = []; $params = [];
    foreach ($scope as $i => $aid) { $k = "a{$i}"; $ph[] = ":{$k}"; $params[$k] = $aid; }
    $inAcc = implode(',', $ph);

    // Localiza a conversa dentro do escopo. Prioridade: chat_id > instance+jid > jid.
    if ($chatId > 0) {
        $st = $pdo->prepare("SELECT id FROM whatsapp_chats WHERE id = :id AND account_id IN ($inAcc) LIMIT 1");
        $st->execute($params + ['id' => $chatId]);
    } elseif ($instId > 0 && $jid !== '') {
        $st = $pdo->prepare("SELECT id FROM whatsapp_chats WHERE instance_id = :iid AND remote_jid = :jid AND account_id IN ($inAcc) LIMIT 1");
        $st->execute($params + ['iid' => $instId, 'jid' => $jid]);
    } elseif ($jid !== '') {
        // Caso comum (1 instância por conta): resolve só pelo jid dentro do escopo.
        $st = $pdo->prepare("SELECT id FROM whatsapp_chats WHERE remote_jid = :jid AND account_id IN ($inAcc) ORDER BY last_message_at DESC LIMIT 1");
        $st->execute($params + ['jid' => $jid]);
    } else {
        http_response_code(422);
        echo json_encode(['error' => 'Informe chat_id, (instance_id + remote_jid) ou remote_jid']);
        exit;
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Conversa não encontrada no seu acesso']); exit; }

    $upd = $pdo->prepare('UPDATE whatsapp_chats SET agent_paused = :p WHERE id = :id');
    $upd->execute(['p' => $paused, 'id' => (int)$row['id']]);

    echo json_encode(['ok' => true, 'chat_id' => (int)$row['id'], 'agent_paused' => $paused]);
} catch (\Throwable $e) {
    error_log('[agent_takeover] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao alterar atendimento']);
}
