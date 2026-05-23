<?php
/**
 * Painel Master — gestão de subscriptions e invoices.
 *
 * GET    /api/master/billing.php              → lista subscriptions + filtros
 * GET    /api/master/billing.php?invoices=1   → lista invoices
 * PATCH  /api/master/billing.php              → muda plano da assinatura
 * POST   /api/master/billing.php?cancel=1     → cancela assinatura
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Models\Database;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) ApiResponse::badRequest('CSRF inválido');
}

if ($method === 'GET') {
    if (!empty($_GET['invoices'])) {
        $rows = $pdo->query(
            "SELECT i.*, a.nome AS account_nome
             FROM invoices i INNER JOIN accounts a ON a.id = i.account_id
             ORDER BY i.id DESC LIMIT 100"
        )->fetchAll(\PDO::FETCH_ASSOC);
        ApiResponse::ok(['invoices' => $rows]);
    }
    $rows = $pdo->query(
        "SELECT s.*, a.nome AS account_nome, p.nome AS plan_nome, p.slug AS plan_slug,
                p.preco_mensal_cents, p.preco_anual_cents
         FROM subscriptions s
         INNER JOIN accounts a ON a.id = s.account_id
         INNER JOIN plans    p ON p.id = s.plan_id
         WHERE a.deleted_at IS NULL
         ORDER BY s.id DESC"
    )->fetchAll(\PDO::FETCH_ASSOC);
    ApiResponse::ok(['subscriptions' => $rows]);
}

if ($method === 'PATCH') {
    $subId    = (int)($input['subscription_id'] ?? 0);
    $newPlan  = (int)($input['plan_id'] ?? 0);
    if (!$subId || !$newPlan) ApiResponse::badRequest('subscription_id e plan_id obrigatórios');
    $pdo->prepare('UPDATE subscriptions SET plan_id = :pid WHERE id = :sid')
        ->execute(['pid' => $newPlan, 'sid' => $subId]);
    ApiResponse::ok(['updated' => true]);
}

if ($method === 'POST' && !empty($_GET['cancel'])) {
    $subId = (int)($input['subscription_id'] ?? 0);
    $atEnd = !empty($input['at_period_end']);
    if (!$subId) ApiResponse::badRequest('subscription_id obrigatório');
    if ($atEnd) {
        $pdo->prepare('UPDATE subscriptions SET cancel_at_period_end = 1 WHERE id = :id')
            ->execute(['id' => $subId]);
    } else {
        $pdo->prepare('UPDATE subscriptions SET status="canceled", canceled_at=NOW() WHERE id = :id')
            ->execute(['id' => $subId]);
    }
    ApiResponse::ok(['canceled' => true, 'at_period_end' => $atEnd]);
}

ApiResponse::methodNotAllowed();
