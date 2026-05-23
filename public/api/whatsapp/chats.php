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

        echo json_encode([
            'ok'          => true,
            'chats'       => $msgModel->getChatList($instanceId, $search, $teamFilter),
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

                $linkData = [
                    'linked_card_id' => $payload['card_id']  ?? null,
                    'linked_user_id' => $payload['user_id']  ?? null,
                    'processo_ids'   => $processoIds,
                ];
                if ($teamId !== null) $linkData['team_id'] = $teamId;

                $msgModel->linkChat($instanceId, $jid, $linkData);
                WebhookDispatcher::fire($accountId, 'whatsapp.vinculo', WebhookDispatcher::buildPayload('whatsapp.vinculo', [
                    'entity' => 'whatsapp_chat', 'entity_id' => null,
                    'card_id' => $payload['card_id'] ?? null,
                    'data' => [
                        'remote_jid'   => $jid,
                        'card_id'      => $payload['card_id'] ?? null,
                        'user_id'      => $payload['user_id'] ?? null,
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
