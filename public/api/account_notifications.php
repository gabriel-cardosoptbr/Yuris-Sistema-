<?php
/**
 * API: /api/account_notifications.php
 * Central de notificações do tenant logado.
 *
 * GET    /api/account_notifications.php              → lista notificações do usuário
 * GET    /api/account_notifications.php?count=1      → apenas contagem de não lidas
 * PATCH  /api/account_notifications.php              → marca como lida
 *          Body: { id: 5 } ou { all: true }
 */

require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Master/AccountNotification.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';

use App\Master\AccountNotification;
use App\Core\AccountContext;

// read_and_close: o endpoint só LÊ a sessão (AccountContext + csrf), nunca escreve.
// Sem isto, o session_start() segurava o lock de escrita e o fetch da lista podia
// pendurar sob contenção (bug do sino "Carregando..." eterno). Padrão da casa.
session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');

$ctx    = AccountContext::fromSession();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET') {
    if (!empty($_GET['count'])) {
        $count = AccountNotification::countNaoLidas($ctx->getUserId(), $ctx->getAccountId());
        echo json_encode(['count' => $count]);
        exit;
    }

    $soNaoLidas = !empty($_GET['unread']);
    $data = AccountNotification::listForUser($ctx->getUserId(), $ctx->getAccountId(), $soNaoLidas);
    echo json_encode(['data' => $data]);
    exit;
}

if ($method === 'PATCH') {
    // CSRF obrigatório em mutações (regra canônica): header X-CSRF-Token ou body.
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? ($_POST['csrf_token'] ?? null));
    if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $csrf)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido']);
        exit;
    }

    if (!empty($input['all'])) {
        $count = AccountNotification::marcarTodasLidas($ctx->getUserId(), $ctx->getAccountId());
        echo json_encode(['success' => true, 'marcadas' => $count]);
        exit;
    }

    $id = (int) ($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id obrigatório']); exit; }
    $ok = AccountNotification::marcarLida($id, $ctx->getUserId(), $ctx->getAccountId());
    echo json_encode(['success' => $ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
