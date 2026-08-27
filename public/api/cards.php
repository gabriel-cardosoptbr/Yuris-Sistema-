<?php
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Prospeccao/Card.php';
require_once __DIR__ . '/../../app/Prospeccao/Contato.php';
require_once __DIR__ . '/../../app/Usuarios/User.php';
require_once __DIR__ . '/../../app/Integracoes/WebhookDispatcher.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';

use App\Prospeccao\Card;
use App\Core\Database;
use App\Integracoes\WebhookDispatcher;
use App\Core\AccountContext;

// read_and_close: só lê $_SESSION (csrf_token + contexto), nunca escreve. Libera o
// lock de escrita na hora — evita serializar os AJAX do dashboard e travar o sino.
session_start(['read_and_close' => true]);
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
    // listagem — sempre filtra por tenant (inclui cards das filiais vinculadas se matriz, e cards compartilhados)
    $filters = [
        'account_ids' => $ctx->getAccessibleAccountIds('prospeccao'),
        'user_id'     => $ctx->getUserId(),
    ];
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

    // Pipeline efetivo (Opção 2): filial vinculada usa colunas da matriz.
    $pipelineAccountId = $ctx->getPipelineAccountId();

    // ── Trava: pipeline precisa ter pelo menos uma coluna pra receber o lead ──
    // (Antes era possível criar card com coluna_id NULL — o lead sumia da UI.)
    $pdoChk = Database::getConnection();
    $colCount = (int) $pdoChk->query(
        "SELECT COUNT(*) FROM pipeline_columns WHERE account_id = " . (int)$pipelineAccountId
    )->fetchColumn();
    if ($colCount === 0) {
        http_response_code(409);
        $msg = $pipelineAccountId === $accountId
            ? 'Crie pelo menos uma coluna no pipeline antes de cadastrar um lead.'
            : 'A matriz ainda não definiu colunas no pipeline. Solicite ao admin da matriz.';
        echo json_encode([
            'error'   => $msg,
            'code'    => 'no_pipeline_columns',
            'hint'    => 'Use o botão "Alterar Colunas" para criar a primeira etapa.'
        ]);
        exit;
    }

    // Se coluna_id foi enviado, valida que pertence ao PIPELINE EFETIVO (não ao próprio tenant).
    if (!empty($data['coluna_id'])) {
        $stmtCol = $pdoChk->prepare(
            "SELECT id FROM pipeline_columns WHERE id = :cid AND account_id = :acc LIMIT 1"
        );
        $stmtCol->execute(['cid' => (int)$data['coluna_id'], 'acc' => $pipelineAccountId]);
        if (!$stmtCol->fetchColumn()) {
            http_response_code(400);
            echo json_encode(['error' => 'Coluna inválida para este pipeline.', 'code' => 'invalid_column']);
            exit;
        }
    } else {
        // Fallback: usa primeira coluna do pipeline efetivo (em vez de gravar NULL)
        $firstCol = $pdoChk->prepare(
            "SELECT id FROM pipeline_columns WHERE account_id = :acc ORDER BY ordem ASC, id ASC LIMIT 1"
        );
        $firstCol->execute(['acc' => $pipelineAccountId]);
        $data['coluna_id'] = (int) $firstCol->fetchColumn();
    }

    // normalize numeric fields
    $data['valor_estimado']      = isset($data['valor_estimado'])      ? normalize_money($data['valor_estimado'])      : 0;
    $data['valor_proposta']      = isset($data['valor_proposta'])      ? normalize_money($data['valor_proposta'])      : 0;
    $data['valor_fechado_final'] = isset($data['valor_fechado_final']) ? normalize_money($data['valor_fechado_final']) : 0;
    $id = Card::create($data);
    $createdCard = Card::find($id);
    WebhookDispatcher::fire($accountId, 'card.created', WebhookDispatcher::buildPayload('card.created', [
        'entity' => 'card', 'entity_id' => $id, 'card_id' => $id, 'data' => $createdCard,
    ]));
    echo json_encode(['success'=>true,'id'=>$id]);
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
    // batch reorder
    if (!empty($input['reorder']) && is_array($input['reorder'])) {
        // ─── LGPD P0: valida tenant em CADA card do batch antes de reordenar ──
        // Antes desta correção, um admin do tenant A podia montar payload com
        // IDs de cards do tenant B e reordená-los/movê-los entre colunas.
        foreach ($input['reorder'] as $item) {
            if (!empty($item['id'])) {
                $ctx->assertCanWrite('card', (int)$item['id']);
            }
        }
        $ok = Card::bulkUpdateOrders($input['reorder'], $user_id);
        echo json_encode(['success'=>(bool)$ok]);
        exit;
    }

    if (empty($input['id'])) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    $id = (int)$input['id'];

    // ─── LGPD P0: enforcement de tenant antes de qualquer mutação no card ────
    // assertCanWrite cobre: owner do recurso (mesma conta) ou share ativo com
    // permissão de escrita. Caso contrário, 403.
    $ctx->assertCanWrite('card', $id);
    // ──────────────────────────────────────────────────────────────────────────

    if (isset($input['coluna_id']) && isset($input['ordem_na_coluna']) && ($method === 'PATCH')) {
        $prevCard = Card::find($id);
        $ok = Card::move($id, (int)$input['coluna_id'], (int)$input['ordem_na_coluna'], $user_id);
        if ($ok) {
            // Usa account_id do card afetado (não da sessão) — preserva ownership em shares
            $ownerAcc = (int)($prevCard['account_id'] ?? $accountId);
            WebhookDispatcher::fire($ownerAcc, 'card.stage_changed', WebhookDispatcher::buildPayload('card.stage_changed', [
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
        $ownerAcc = (int)($updatedCard['account_id'] ?? $prevCard['account_id'] ?? $accountId);
        WebhookDispatcher::fire($ownerAcc, $eventKey, WebhookDispatcher::buildPayload($eventKey, [
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

    // ─── LGPD P0: tenant enforcement antes de soft-delete ────────────────────
    // Antes desta correção, qualquer admin de qualquer tenant podia deletar
    // cards alheios passando o ID. Agora exige ownership ou share com write.
    $ctx->assertCanWrite('card', (int)$id);
    // ──────────────────────────────────────────────────────────────────────────

    $prevCard = Card::find((int)$id);
    $ok = Card::softDelete((int)$id, $user_id);
    if ($ok) {
        $ownerAcc = (int)($prevCard['account_id'] ?? $accountId);
        WebhookDispatcher::fire($ownerAcc, 'card.deleted', WebhookDispatcher::buildPayload('card.deleted', [
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
