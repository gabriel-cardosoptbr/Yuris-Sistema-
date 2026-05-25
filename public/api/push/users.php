<?php
/**
 * push/users.php — Lista usuários do tenant pra select de responsável.
 *
 * GET /api/push/users.php
 * Resposta: { ok, users: [{id, nome, email}] }
 *
 * Multi-tenant: filtra por account_id da sessão.
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ErrorReporter.php';

use App\Helpers\AccountContext;
use App\Models\Database;

session_start(['read_and_close' => true]);

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

try {
    $ctx       = AccountContext::fromSession();
    $accountId = $ctx->getAccountId();

    $pdo  = Database::getConnection();
    $stmt = $pdo->prepare(
        'SELECT id, nome, COALESCE(email, "") AS email
           FROM users
          WHERE account_id = :acc
          ORDER BY nome ASC'
    );
    $stmt->execute(['acc' => $accountId]);
    $users = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    echo json_encode(['ok' => true, 'users' => $users, 'total' => count($users)]);
} catch (\Throwable $e) {
    \App\Helpers\ErrorReporter::handle($e);
}
