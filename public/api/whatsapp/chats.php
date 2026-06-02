<?php
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppMessage.php';
require_once __DIR__ . '/../../../app/Models/Team.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Services/WebhookDispatcher.php';

use App\Models\Team;
use App\Helpers\AccountContext;
use App\Services\WebhookDispatcher;

session_start(['read_and_close' => true]);
$_uid  = $_SESSION['user_id']    ?? null;
$_csrf = $_SESSION['csrf_token'] ?? '';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
if (!$_uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

// Context de tenant para validar que o team_id pertence à conta correta
$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();

try {
    $instModel  = new WhatsAppInstance();
    $msgModel   = new WhatsAppMessage();
    $method     = $_SERVER['REQUEST_METHOD'];
    $cfg        = $instModel->getSettings($accountId);
    $instName   = $cfg['evolution_instance'] ?? 'yuris-crm';
    $row        = $instModel->findOrCreate($instName, '', $accountId);
    $instanceId = (int)$row['id'];

    // Validação extra: garante isolamento por tenant
    if ((int)($row['account_id'] ?? 0) !== $accountId) {
        http_response_code(403);
        echo json_encode(['error' => 'Instância WhatsApp não pertence a esta conta']);
        exit;
    }

    if ($method === 'GET') {
        $search = trim($_GET['search'] ?? '');

        // Se pediu processos de um JID específico
        if (!empty($_GET['action']) && $_GET['action'] === 'get_processos') {
            $jid = trim($_GET['jid'] ?? '');
            if (!$jid) { echo json_encode(['ok' => false, 'error' => 'jid obrigatório']); exit; }
            echo json_encode([
                'ok'          => true,
                'processo_ids'=> $msgModel->getLinkedProcessos($instanceId, $jid),
            ]);
            exit;
        }

        // Filtro por setor:
        //   ?team_id=N  → conversas do setor N
        //   ?team_id=0  → conversas sem setor
        //   (ausente)   → todas as conversas
        $teamFilter = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;
        // Filtro por responsável (linked_user_id):
        //   ?user_id=N  → conversas atribuídas ao user N
        //   ?user_id=0  → conversas sem responsável
        $userFilter = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
        // Filtro arquivadas: ?archived=1 lista apenas arquivadas; default = só não-arquivadas
        $archived = !empty($_GET['archived']) && $_GET['archived'] !== '0';

        echo json_encode([
            'ok'          => true,
            'chats'       => $msgModel->getChatList($instanceId, $search, $teamFilter, $userFilter, $archived),
            'total_unread'=> $msgModel->getTotalUnread($instanceId),
        ]);
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($payload['_csrf']) || $payload['_csrf'] !== $_csrf) {
            http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit;
        }
        $action = $payload['action'] ?? '';
        $jid    = $payload['remote_jid'] ?? '';

        if ($action === 'mark_read') {
            if ($jid) $msgModel->markChatRead($instanceId, $jid);
            echo json_encode(['ok' => true]); exit;
        }
        // Marca chat como NÃO lido (volta unread_count >= 1). Inverso de mark_read.
        if ($action === 'mark_unread') {
            if ($jid) $msgModel->markChatUnread($instanceId, $jid);
            echo json_encode(['ok' => true]); exit;
        }
        // Arquiva / desarquiva conversa
        if ($action === 'toggle_archive') {
            if ($jid) $msgModel->toggleArchive($instanceId, $jid);
            echo json_encode(['ok' => true]); exit;
        }
        if ($action === 'toggle_pin') {
            if ($jid) $msgModel->togglePin($instanceId, $jid);
            echo json_encode(['ok' => true]); exit;
        }
        if ($action === 'link') {
            if ($jid) {
                $processoIds = $payload['processo_ids'] ?? [];
                if (!is_array($processoIds)) $processoIds = [];

                // team_id pode vir junto com o link do card
                $teamId = isset($payload['team_id']) ? (int)$payload['team_id'] : null;
                if ($teamId !== null) {
                    // Garante que o team pertence à conta do usuário logado
                    if (!Team::findById($teamId, $accountId)) $teamId = null;
                }

                // SEGURANCA (auditoria 2026-06-01): valida tenant do card_id/user_id
                // antes de gravar o vinculo (IDOR de escrita — antes aceitava ID de
                // outro tenant). Se nao pertencer aos tenants acessiveis, descarta.
                $linkCardId = isset($payload['card_id']) && $payload['card_id'] ? (int)$payload['card_id'] : null;
                $linkUserId = isset($payload['user_id']) && $payload['user_id'] ? (int)$payload['user_id'] : null;
                if ($linkCardId || $linkUserId) {
                    $pdoChk = \App\Models\Database::getConnection();
                    $accIds = $ctx->getAccessibleAccountIds('prospeccao');
                    if (empty($accIds)) $accIds = [$accountId];
                    $ph = implode(',', array_fill(0, count($accIds), '?'));
                    if ($linkCardId) {
                        $st = $pdoChk->prepare("SELECT 1 FROM cards WHERE id=? AND account_id IN ($ph) AND deleted_at IS NULL LIMIT 1");
                        $st->execute(array_merge([$linkCardId], $accIds));
                        if (!$st->fetchColumn()) $linkCardId = null; // descarta card de outro tenant
                    }
                    if ($linkUserId) {
                        $accIdsU = $ctx->getAccessibleAccountIds('chat'); if (empty($accIdsU)) $accIdsU = [$accountId];
                        $phU = implode(',', array_fill(0, count($accIdsU), '?'));
                        $st = $pdoChk->prepare("SELECT 1 FROM users WHERE id=? AND account_id IN ($phU) AND deleted_at IS NULL LIMIT 1");
                        $st->execute(array_merge([$linkUserId], $accIdsU));
                        if (!$st->fetchColumn()) $linkUserId = null; // descarta user de outro tenant
                    }
                }
                $linkData = [
                    'linked_card_id' => $linkCardId,
                    'linked_user_id' => $linkUserId,
                    'processo_ids'   => $processoIds,
                ];
                if ($teamId !== null) $linkData['team_id'] = $teamId;

                $msgModel->linkChat($instanceId, $jid, $linkData);
                WebhookDispatcher::fire($accountId, 'whatsapp.vinculo', WebhookDispatcher::buildPayload('whatsapp.vinculo', [
                    'entity' => 'whatsapp_chat', 'entity_id' => null,
                    'card_id' => $linkCardId,
                    'data' => [
                        'remote_jid'   => $jid,
                        'card_id'      => $linkCardId,
                        'user_id'      => $linkUserId,
                        'processo_ids' => $processoIds,
                        'team_id'      => $teamId,
                    ],
                ]));
            }
            echo json_encode(['ok' => true]); exit;
        }

        // Atribuir/remover setor de uma conversa (ação independente do link)
        // Body: { action: "set_team", remote_jid: "...", team_id: N }
        // team_id = 0 ou null = remove o setor
        if ($action === 'set_team') {
            if (!$jid) { echo json_encode(['ok' => false, 'error' => 'remote_jid obrigatório']); exit; }

            $teamId = isset($payload['team_id']) && $payload['team_id'] ? (int)$payload['team_id'] : null;

            // Valida que o team pertence à conta do usuário logado (se estiver atribuindo)
            if ($teamId !== null && !Team::findById($teamId, $accountId)) {
                echo json_encode(['ok' => false, 'error' => 'Setor não encontrado']); exit;
            }

            $ok = $msgModel->setTeam($instanceId, $jid, $teamId);
            echo json_encode(['ok' => $ok]); exit;
        }

        // Excluir conversa (mensagens + registro do chat) do banco local
        // Body: { action: "delete", remote_jid: "..." }
        if ($action === 'delete') {
            if (!$jid) { echo json_encode(['ok' => false, 'error' => 'remote_jid obrigatório']); exit; }

            // SEGURANCA (auditoria 2026-06-01 BAIXA #26): checagem de tenant EXPLÍCITA
            // antes do delete. WhatsAppMessage::deleteChat() faz um DELETE cego por
            // (instance_id, remote_jid) sem filtrar account_id; aqui confirmamos que
            // o chat alvo pertence a uma instância de conta acessível (matriz+filiais)
            // — não apenas confiando no instanceId resolvido acima. Sem isso, um
            // instanceId/jid forjado poderia apagar conversa de outro tenant.
            $pdoDel = \App\Models\Database::getConnection();
            $accDel = $ctx->getAccessibleAccountIds('whatsapp');
            if (empty($accDel)) $accDel = [$accountId];
            $phDel  = implode(',', array_fill(0, count($accDel), '?'));
            $stDel  = $pdoDel->prepare(
                "SELECT 1
                   FROM whatsapp_chats wc
                   JOIN whatsapp_instances wi ON wi.id = wc.instance_id
                  WHERE wc.instance_id = ? AND wc.remote_jid = ?
                    AND wi.account_id IN ($phDel)
                  LIMIT 1"
            );
            $stDel->execute(array_merge([$instanceId, $jid], array_map('intval', $accDel)));
            if (!$stDel->fetchColumn()) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Conversa não pertence a esta conta']);
                exit;
            }

            $ok = $msgModel->deleteChat($instanceId, $jid);
            echo json_encode(['ok' => $ok]); exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Ação desconhecida']); exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Throwable $e) {
    // P1 LGPD (2D.1): em prod esconde getMessage/file/line
    require_once __DIR__ . '/../../../app/Helpers/ErrorReporter.php';
    \App\Helpers\ErrorReporter::handle($e);
}
