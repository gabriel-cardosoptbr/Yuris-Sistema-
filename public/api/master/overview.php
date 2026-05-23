<?php
/**
 * Painel Master — overview global de todas as contas/assinaturas/faturas.
 * Acesso: apenas super_admin.
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

$pdo = Database::getConnection();

$accountsTotal     = (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL")->fetchColumn();
$accountsActive    = (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND status='active'")->fetchColumn();
$accountsSuspended = (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND status='suspended'")->fetchColumn();
$accountsCanceled  = (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND status='cancelled'")->fetchColumn();
$usersTotal        = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
$processosTotal    = (int) $pdo->query("SELECT COUNT(*) FROM processos WHERE deleted_at IS NULL")->fetchColumn();
$cardsTotal        = (int) $pdo->query("SELECT COUNT(*) FROM cards WHERE deleted_at IS NULL")->fetchColumn();

$subsTrialing = (int) $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='trialing'")->fetchColumn();
$subsActive   = (int) $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='active'")->fetchColumn();
$subsPastDue  = (int) $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='past_due'")->fetchColumn();
$subsCanceled = (int) $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='canceled'")->fetchColumn();

$mrrCents = (int) $pdo->query(
    "SELECT COALESCE(SUM(CASE WHEN s.billing_cycle='monthly' THEN p.preco_mensal_cents ELSE ROUND(p.preco_anual_cents/12) END),0)
     FROM subscriptions s
     INNER JOIN plans p ON p.id=s.plan_id
     WHERE s.status IN ('active','past_due')"
)->fetchColumn();

$invoicesOpen = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='open'")->fetchColumn();
$invoicesPaid = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='paid'")->fetchColumn();

$recentAccounts = $pdo->query(
    "SELECT a.id, a.nome, a.tipo, a.status, a.plano, a.created_at,
            (SELECT COUNT(*) FROM users u WHERE u.account_id=a.id AND u.deleted_at IS NULL) AS users_count,
            (SELECT s.status FROM subscriptions s WHERE s.account_id=a.id ORDER BY s.id DESC LIMIT 1) AS sub_status
     FROM accounts a
     WHERE a.deleted_at IS NULL
     ORDER BY a.created_at DESC LIMIT 20"
)->fetchAll(\PDO::FETCH_ASSOC);

ApiResponse::ok([
    'accounts'  => [
        'total'     => $accountsTotal,
        'active'    => $accountsActive,
        'suspended' => $accountsSuspended,
        'canceled'  => $accountsCanceled,
    ],
    'subscriptions' => [
        'trialing' => $subsTrialing,
        'active'   => $subsActive,
        'past_due' => $subsPastDue,
        'canceled' => $subsCanceled,
    ],
    'billing' => [
        'mrr_cents'      => $mrrCents,
        'mrr_brl'        => number_format($mrrCents / 100, 2, ',', '.'),
        'invoices_open'  => $invoicesOpen,
        'invoices_paid'  => $invoicesPaid,
    ],
    'totals' => [
        'users'     => $usersTotal,
        'processos' => $processosTotal,
        'cards'     => $cardsTotal,
    ],
    'recent_accounts' => $recentAccounts,
]);
