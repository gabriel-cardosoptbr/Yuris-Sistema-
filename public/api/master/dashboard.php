<?php
/**
 * Painel Master — dashboard expandido com listas segmentadas.
 *
 * Complementa overview.php fornecendo:
 *   - Listas separadas: contas em trial, vencidas, suspensas, recentes
 *   - Breakdown por plano
 *   - Contagem por tipo (matriz / filial)
 *   - Contagem de advogados ativos/inativos
 *   - Receita prevista por plano
 *
 * GET /api/master/dashboard.php
 *
 * Acesso: super_admin apenas.
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ApiResponse.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Core\Database;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') ApiResponse::methodNotAllowed();

$pdo = Database::getConnection();

// ─── KPIs principais ───────────────────────────────────────────────────────
$kpis = [
    'accounts' => [
        'total'      => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL")->fetchColumn(),
        'matriz'     => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND tipo = 'matriz'")->fetchColumn(),
        'filial'     => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND tipo = 'filial'")->fetchColumn(),
        'advogado'   => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND tipo = 'advogado'")->fetchColumn(),
        'active'     => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND status = 'active'")->fetchColumn(),
        'trial'      => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND status = 'trial'")->fetchColumn(),
        'overdue'    => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND status = 'overdue'")->fetchColumn(),
        'suspended'  => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND status = 'suspended'")->fetchColumn(),
        'cancelled'  => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND status = 'cancelled'")->fetchColumn(),
        'inactive'   => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND status = 'inactive'")->fetchColumn(),
    ],
    'users' => [
        'total'       => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn(),
        'active'      => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'active'")->fetchColumn(),
        'inactive'    => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status <> 'active'")->fetchColumn(),
        'advogados'   => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_advogado = 1")->fetchColumn(),
    ],
    'subscriptions' => [
        'trialing' => (int) $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'trialing'")->fetchColumn(),
        'active'   => (int) $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'")->fetchColumn(),
        'past_due' => (int) $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'past_due'")->fetchColumn(),
        'canceled' => (int) $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'canceled'")->fetchColumn(),
    ],
    'invoices' => [
        'open'        => (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'open'")->fetchColumn(),
        'overdue'     => (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'open' AND due_date < CURDATE()")->fetchColumn(),
        'paid_total'  => (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'paid'")->fetchColumn(),
    ],
];

// ─── Receita prevista (MRR) ────────────────────────────────────────────────
$mrrCents = (int) $pdo->query(
    "SELECT COALESCE(SUM(
        CASE WHEN s.billing_cycle = 'yearly'
             THEN ROUND(p.preco_anual_cents / 12)
             ELSE p.preco_mensal_cents
        END
     ), 0)
     FROM subscriptions s
     INNER JOIN plans p ON p.id = s.plan_id
     WHERE s.status IN ('active','past_due')"
)->fetchColumn();

// ─── Breakdown por plano ────────────────────────────────────────────────────
$byPlan = $pdo->query(
    "SELECT p.id, p.slug, p.nome, p.preco_mensal_cents,
            COUNT(s.id) AS total_subscriptions,
            SUM(CASE WHEN s.status = 'active'   THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN s.status = 'trialing' THEN 1 ELSE 0 END) AS trial_count
     FROM plans p
     LEFT JOIN subscriptions s ON s.plan_id = p.id
     GROUP BY p.id
     ORDER BY p.ordem, p.id"
)->fetchAll(\PDO::FETCH_ASSOC);

// ─── Listas segmentadas ────────────────────────────────────────────────────
$listaQuery = function (string $extraWhere, int $limit = 12) use ($pdo): array {
    $sql =
        "SELECT a.id, a.nome, a.razao_social, a.cnpj, a.tipo, a.status, a.cidade, a.estado,
                a.created_at, a.email,
                (SELECT COUNT(*) FROM users u WHERE u.account_id = a.id AND u.deleted_at IS NULL) AS users_count,
                (SELECT COUNT(*) FROM users u WHERE u.account_id = a.id AND u.deleted_at IS NULL AND u.is_advogado = 1) AS advogados_count,
                s.status AS sub_status, s.trial_ends_at, s.current_period_end,
                p.nome   AS plan_nome, p.slug AS plan_slug
         FROM accounts a
         LEFT JOIN subscriptions s ON s.id = (
             SELECT s2.id FROM subscriptions s2 WHERE s2.account_id = a.id ORDER BY s2.id DESC LIMIT 1
         )
         LEFT JOIN plans p ON p.id = s.plan_id
         WHERE a.deleted_at IS NULL AND ({$extraWhere})
         ORDER BY a.created_at DESC
         LIMIT {$limit}";
    return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
};

$listas = [
    'recentes'   => $listaQuery("1 = 1", 12),
    'trial'      => $listaQuery("a.status = 'trial' OR s.status = 'trialing'", 20),
    'vencidas'   => $listaQuery("a.status = 'overdue' OR s.status = 'past_due'", 20),
    'suspensas'  => $listaQuery("a.status = 'suspended'", 20),
    'inativas'   => $listaQuery("a.status IN ('cancelled','inactive')", 20),
];

ApiResponse::ok([
    'kpis'         => $kpis,
    'mrr_cents'    => $mrrCents,
    'mrr_brl'      => number_format($mrrCents / 100, 2, ',', '.'),
    'plans'        => $byPlan,
    'listas'       => $listas,
]);
