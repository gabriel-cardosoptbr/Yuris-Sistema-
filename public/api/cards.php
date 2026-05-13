<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Card.php';
require_once __DIR__ . '/../../app/Models/Contato.php';
require_once __DIR__ . '/../../app/Models/User.php';
require_once __DIR__ . '/../../app/Services/WebhookDispatcher.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Models\Card;
use App\Models\Database;
use App\Services\WebhookDispatcher;
use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

// Carrega contexto de tenant — aborta com 401 se sessão inválida
$ctx       = AccountContext::fromSession();
$user_id   = $ctx->getUserId();
$accountId = $ctx->getAccountId();   // NUNCA lido do request — sempre da sessão

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

auto_json_response:
if ($method === 'GET') {
    // busca card individual — valida acesso ao tenant
    if (isset($_GET['id'])) {
        $card = Card::find((int)$_GET['id']);
        if ($card) {
            $ctx->assertCanRead('card', (int)$_GET['id']);
            $card['checklist'] = Card::getChecklist($card['id']);
            $card['history']   = Card::getHistory($card['id']);
        }
        echo json_encode(['data' => $card]);
        exit;
    }
    // listagem — sempre filtra por tenant (inclui cards compartilhados)
    $filters = ['account_id' => $accountId];  // OBRIGATÓRIO
    if (isset($_GET['coluna_id']))           $filters['coluna_id']            = (int)$_GET['coluna_id'];
    if (isset($_GET['responsavel_user_id'])) $filters['responsavel_user_id']  = (int)$_GET['responsavel_user_id'];
    if (isset($_GET['status']))              $filters['status']               = $_GET['status'];
    $cards = Card::list($filters);
    echo json_encode(['data' => $cards]);
    exit;
}

// For state changing requests, basic CSRF check using session token
if (in_array($method, ['POST','PUT','DELETE','PATCH'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error'=>'Invalid CSRF token']);
        exit;
    }
}

if ($method === 'POST') {
    // create — injeta account_id da sessão (NUNCA do body)
    $data = $input;
    $data['account_id'] = $accountId;  // tenant ownership definido server-side
    // normalize numeric fields
    $data['valor_estimado']      = isset($data['valor_estimado'])      ? normalize_money($data['valor_estimado'])      : 0;
    $data['valor_proposta']      = isset($data['valor_proposta'])      ? normalize_money($data['valor_proposta'])      : 0;
    $data['valor_fechado_final'] = isset($data['valor_fechado_final']) ? normalize_money($data['valor_fechado_final']) : 0;
    $id = Card::create($data);
    $createdCard = Card::find($id);
    WebhookDispatcher::fire('card.created', WebhookDispatcher::buildPayload('card.created', [
        'entity' => 'card', 'entity_id' => $id, 'card_id' => $id, 'data' => $createdCard,
    ]));
    echo json_encode(['success'=>true,'id'=>$id]);
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
    // batch reorder
    if (!empty($input['reorder']) && is_array($input['reorder'])) {
        $ok = Card::bulkUpdateOrders($input['reorder'], $user_id);
        echo json_encode(['success'=>(bool)$ok]);
        exit;
    }

    if (empty($input['id'])) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    $id = (int)$input['id'];
    if (isset($input['coluna_id']) && isset($input['ordem_na_coluna']) && ($method === 'PATCH')) {
        $prevCard = Card::find($id);
        $ok = Card::move($id, (int)$input['coluna_id'], (int)$input['ordem_na_coluna'], $user_id);
        if ($ok) {
            WebhookDispatcher::fire('card.stage_changed', WebhookDispatcher::buildPayload('card.stage_changed', [
                'entity' => 'card', 'entity_id' => $id, 'card_id' => $id,
                'data' => Card::find($id), 'previous_data' => $prevCard,
            ]));
        }
        echo json_encode(['success'=> (bool)$ok]);
        exit;
    }
    // update
    $prevCard = Card::find($id);
    if (isset($input['valor_estimado'])) $input['valor_estimado'] = normalize_money($input['valor_estimado']);
    if (isset($input['valor_proposta'])) $input['valor_proposta'] = normalize_money($input['valor_proposta']);
    if (isset($input['valor_fechado_final'])) $input['valor_fechado_final'] = normalize_money($input['valor_fechado_final']);
    $ok = Card::update($id, $input);
    if ($ok) {
        $updatedCard = Card::find($id);
        $eventKey = 'card.updated';
        if (isset($input['coluna_id']) && ($prevCard['coluna_id'] ?? null) != $input['coluna_id']) {
            $eventKey = 'card.stage_changed';
        } elseif (isset($input['responsavel_user_id']) && ($prevCard['responsavel_user_id'] ?? null) != $input['responsavel_user_id']) {
            $eventKey = 'card.responsavel_changed';
        }
        WebhookDispatcher::fire($eventKey, WebhookDispatcher::buildPayload($eventKey, [
            'entity' => 'card', 'entity_id' => $id, 'card_id' => $id,
            'data' => $updatedCard, 'previous_data' => $prevCard,
        ]));
    }
    echo json_encode(['success'=> (bool)$ok]);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? ($input['id'] ?? null);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    $prevCard = Card::find((int)$id);
    $ok = Card::softDelete((int)$id, $user_id);
    if ($ok) {
        WebhookDispatcher::fire('card.deleted', WebhookDispatcher::buildPayload('card.deleted', [
            'entity' => 'card', 'entity_id' => (int)$id, 'card_id' => (int)$id, 'data' => $prevCard,
        ]));
    }
    echo json_encode(['success'=> (bool)$ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);

function normalize_money($v)
{
    // Robust normalization accepting e.g. 'R$ 1.000,00', '1.000,00', '1000.00', '1000,00'
    if (is_numeric($v)) return number_format((float)$v,2,'.','');
    $s = trim((string)$v);
    if ($s === '') return '0.00';
    // remove currency symbols and spaces
    $s = preg_replace('/[^0-9,\.\-]/u', '', $s);
    // if contains both dot and comma, decide which is decimal separator
    $hasDot = strpos($s, '.') !== false;
    $hasComma = strpos($s, ',') !== false;
    if ($hasDot && $hasComma) {
        // assume dot is thousands and comma is decimal: remove dots, replace comma with dot
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif ($hasComma && !$hasDot) {
        // comma as decimal
        $s = str_replace(',', '.', $s);
    }
    // now remove any remaining non-numeric except dot and minus
    $s = preg_replace('/[^0-9\.\-]/', '', $s);
    if (!is_numeric($s)) return '0.00';
    return number_format((float)$s,2,'.','');
}
