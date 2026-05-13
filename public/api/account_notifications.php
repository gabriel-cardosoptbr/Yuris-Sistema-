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

require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/AccountNotification.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Models\AccountNotification;
use App\Helpers\AccountContext;

session_start();
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
    if (!empty($input['all'])) {
        $count = AccountNotification::marcarTodasLidas($ctx->getUserId(), $ctx->getAccountId());
        echo json_encode(['success' => true, 'marcadas' => $count]);
        exit;
    }

    $id = (int) ($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id obrigatório']); exit; }
    $ok = AccountNotification::marcarLida($id, $ctx->getUserId());
    echo json_encode(['success' => $ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
