<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Processo.php';
require_once __DIR__ . '/../../app/Models/Contato.php';
require_once __DIR__ . '/../../app/Services/WebhookDispatcher.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Services\WebhookDispatcher;
use App\Models\Processo;
use App\Models\Database;
use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Carrega contexto de tenant — aborta com 401 se sessão inválida
$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();  // NUNCA lido do request

// Garante que a coluna card_id existe na tabela processos
try {
    $pdo = Database::getConnection();
    $pdo->exec("ALTER TABLE processos ADD COLUMN IF NOT EXISTS card_id INT DEFAULT NULL");
} catch (\Throwable $e) { /* ignora se já existe ou DB não suporta IF NOT EXISTS */ }

$method = $_SERVER['REQUEST_METHOD'];
try {
    if ($method === 'GET') {
        if (!empty($_GET['schema'])) {
            $pdo = Database::getConnection();
            $stmt = $pdo->query("DESCRIBE processos");
            $cols = $stmt->fetchAll();
            echo json_encode(['schema' => $cols]);
            exit;
        }
        if (!empty($_GET['id'])) {
            $p = Processo::find((int)$_GET['id']);
            echo json_encode($p ?: []);
            exit;
        }
        $filters = ['account_id' => $accountId];  // tenant filter OBRIGATÓRIO
        if (!empty($_GET['responsavel_user_id'])) $filters['responsavel_user_id'] = (int)$_GET['responsavel_user_id'];
        if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
        if (!empty($_GET['from']) && !empty($_GET['to'])) { $filters['from'] = $_GET['from']; $filters['to'] = $_GET['to']; }
        if (isset($_GET['card_id']) && $_GET['card_id'] !== '') $filters['card_id'] = (int)$_GET['card_id'];
        $list = Processo::list($filters);
        echo json_encode(['data'=>$list]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $input['account_id'] = $accountId;  // injeta tenant server-side, nunca do body
        $id = Processo::create($input);
        $p = Processo::find($id);
        WebhookDispatcher::fire('processo.created', WebhookDispatcher::buildPayload('processo.created', [
            'entity' => 'processo', 'entity_id' => $id, 'processo_id' => $id,
            'cliente_id' => $p['contato_id'] ?? null, 'data' => $p,
        ]));
        echo json_encode(['success'=>true,'id'=>$id,'data'=>$p]);
        exit;
    }

    if ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = $input['id'] ?? null;
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
        $prev = Processo::find((int)$id);
        $ok = Processo::update((int)$id, $input);
        if ($ok && $prev) {
            $updated = Processo::find((int)$id);
            $eventKey = 'processo.updated';
            if (isset($input['status']) && ($prev['status'] ?? null) !== $input['status']) {
                $eventKey = 'processo.status_changed';
            } elseif (isset($input['responsavel_user_id']) && ($prev['responsavel_user_id'] ?? null) != $input['responsavel_user_id']) {
                $eventKey = 'processo.responsavel_changed';
            } elseif (isset($input['fase']) && ($prev['fase'] ?? null) !== $input['fase']) {
                $eventKey = 'processo.etapa_changed';
            } elseif (isset($input['etapa']) && ($prev['etapa'] ?? null) !== $input['etapa']) {
                $eventKey = 'processo.etapa_changed';
            }
            WebhookDispatcher::fire($eventKey, WebhookDispatcher::buildPayload($eventKey, [
                'entity' => 'processo', 'entity_id' => (int)$id, 'processo_id' => (int)$id,
                'cliente_id' => $updated['contato_id'] ?? null,
                'data' => $updated, 'previous_data' => $prev,
            ]));
        }
        echo json_encode(['success'=>$ok]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = $input['id'] ?? null;
        }
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
        $prev = Processo::find((int)$id);
        $ok = Processo::softDelete((int)$id);
        if ($ok && $prev) {
            WebhookDispatcher::fire('processo.deleted', WebhookDispatcher::buildPayload('processo.deleted', [
                'entity' => 'processo', 'entity_id' => (int)$id, 'processo_id' => (int)$id,
                'cliente_id' => $prev['contato_id'] ?? null, 'data' => $prev,
            ]));
        }
        echo json_encode(['success'=>$ok]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error'=>'Method not allowed']);
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
