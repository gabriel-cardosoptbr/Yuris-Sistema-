<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/CardChecklist.php';
require_once __DIR__ . '/../../app/Models/Card.php';

use App\Models\CardChecklist;
use App\Models\Card;

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// CSRF for write operations
if (in_array($method, ['POST','PATCH','DELETE'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error'=>'Invalid CSRF token']);
        exit;
    }
}

if ($method === 'GET') {
    $card_id = isset($_GET['card_id']) ? (int)$_GET['card_id'] : null;
    if (!$card_id) { echo json_encode(['data'=>[]]); exit; }
    $items = CardChecklist::listByCard($card_id);
    echo json_encode(['data'=>$items]);
    exit;
}

if ($method === 'POST') {
    $card_id = isset($input['card_id']) ? (int)$input['card_id'] : 0;
    $descricao = trim($input['descricao'] ?? '');
    if (!$card_id || $descricao === '') { http_response_code(400); echo json_encode(['error'=>'Missing data']); exit; }
    $id = CardChecklist::create($card_id, $descricao, $_SESSION['user_id']);
    // log history entry
    $pdo = \App\Models\Database::getConnection();
    $h = $pdo->prepare('INSERT INTO card_history (card_id, usuario_id, acao, campo_alterado, valor_anterior, valor_novo, created_at) VALUES (:card_id, :usuario_id, :acao, :campo_alterado, :valor_anterior, :valor_novo, NOW())');
    $h->execute(['card_id'=>$card_id,'usuario_id'=>$_SESSION['user_id'],'acao'=>'checklist_add','campo_alterado'=>'checklist_item','valor_anterior'=>'','valor_novo'=>$descricao]);
    // recalc percent
    $pct = \App\Models\Card::recalculateChecklistPercent($card_id);
    echo json_encode(['success'=>true,'id'=>$id,'checklist_percentual'=>$pct]);
    exit;
}

if ($method === 'PATCH') {
    // reorder items inside the same checklist/card
    if (!empty($input['reorder']) && is_array($input['reorder'])) {
        $card_id = isset($input['card_id']) ? (int)$input['card_id'] : 0;
        if (!$card_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing card_id']);
            exit;
        }
        $ok = CardChecklist::updateOrders($card_id, $input['reorder']);
        if ($ok) {
            $h = \App\Models\Database::getConnection()->prepare('INSERT INTO card_history (card_id, usuario_id, acao, campo_alterado, valor_anterior, valor_novo, created_at) VALUES (:card_id, :usuario_id, :acao, :campo_alterado, :valor_anterior, :valor_novo, NOW())');
            $h->execute([
                'card_id' => $card_id,
                'usuario_id' => $_SESSION['user_id'],
                'acao' => 'checklist_reorder',
                'campo_alterado' => 'checklist_ordem',
                'valor_anterior' => '',
                'valor_novo' => 'reordered',
            ]);
        }
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    // support two PATCH operations: toggle mark or update descricao
    // if descricao provided -> update text
    if (isset($input['descricao'])) {
        $descricao = trim($input['descricao']);
        if ($descricao === '') { http_response_code(400); echo json_encode(['error'=>'Missing descricao']); exit; }
        // fetch previous value for history
        $pdo = \App\Models\Database::getConnection();
        $r = $pdo->prepare('SELECT card_id, titulo AS descricao FROM card_checklist_items WHERE id = :id LIMIT 1');
        $r->execute(['id'=>$id]);
        $row = $r->fetch();
        $card_id = $row['card_id'] ?? null;
        $prev = $row['descricao'] ?? '';
        $ok = CardChecklist::update($id, $descricao);
        if ($ok) {
            $h = $pdo->prepare('INSERT INTO card_history (card_id, usuario_id, acao, campo_alterado, valor_anterior, valor_novo, created_at) VALUES (:card_id, :usuario_id, :acao, :campo_alterado, :valor_anterior, :valor_novo, NOW())');
            $h->execute(['card_id'=>$card_id,'usuario_id'=>$_SESSION['user_id'],'acao'=>'checklist_update','campo_alterado'=>'checklist_item','valor_anterior'=>$prev,'valor_novo'=>$descricao]);
        }
        echo json_encode(['success'=>(bool)$ok]);
        exit;
    }

    $marcado = isset($input['marcado']) ? (bool)$input['marcado'] : true;
    $ok = CardChecklist::toggle($id, $marcado);
    if ($ok) {
        // fetch card_id for history
        $pdo = \App\Models\Database::getConnection();
        $r = $pdo->prepare('SELECT card_id FROM card_checklist_items WHERE id = :id LIMIT 1');
        $r->execute(['id'=>$id]);
        $row = $r->fetch();
        $card_id = $row['card_id'] ?? null;
        $h = $pdo->prepare('INSERT INTO card_history (card_id, usuario_id, acao, campo_alterado, valor_anterior, valor_novo, created_at) VALUES (:card_id, :usuario_id, :acao, :campo_alterado, :valor_anterior, :valor_novo, NOW())');
        $h->execute(['card_id'=>$card_id,'usuario_id'=>$_SESSION['user_id'],'acao'=>'checklist_toggle','campo_alterado'=>'checklist_item','valor_anterior'=>($marcado?0:1),'valor_novo'=>($marcado?1:0)]);
        // recalc percent
        $pct = \App\Models\Card::recalculateChecklistPercent($card_id);
    }
    echo json_encode(['success'=>(bool)$ok,'checklist_percentual'=>$pct ?? null]);
    exit;
}

if ($method === 'DELETE') {
    $id = isset($input['id']) ? (int)$input['id'] : ($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    // find card_id before delete
    $pdo = \App\Models\Database::getConnection();
    $r = $pdo->prepare('SELECT card_id, titulo AS descricao FROM card_checklist_items WHERE id = :id LIMIT 1');
    $r->execute(['id'=>$id]);
    $row = $r->fetch();
    $card_id = $row['card_id'] ?? null;
    $descricao = $row['descricao'] ?? '';
    $ok = CardChecklist::delete($id);
    if ($ok) {
        $h = $pdo->prepare('INSERT INTO card_history (card_id, usuario_id, acao, campo_alterado, valor_anterior, valor_novo, created_at) VALUES (:card_id, :usuario_id, :acao, :campo_alterado, :valor_anterior, :valor_novo, NOW())');
        $h->execute(['card_id'=>$card_id,'usuario_id'=>$_SESSION['user_id'],'acao'=>'checklist_delete','campo_alterado'=>'checklist_item','valor_anterior'=>$descricao,'valor_novo'=>'']);
        // recalc percent
        $pct = \App\Models\Card::recalculateChecklistPercent($card_id);
    }
    echo json_encode(['success'=>(bool)$ok,'checklist_percentual'=>$pct ?? null]);
    exit;
}

http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);
