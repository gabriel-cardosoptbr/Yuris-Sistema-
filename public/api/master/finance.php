<?php
/**
 * Painel Master — agregados financeiros pra alimentar os gráficos do Dashboard.
 *
 * GET /api/master/finance.php
 *
 * Retorna:
 *   • mrr_atual_cents             — MRR projetado das subscriptions active+past_due
 *   • despesa_mes_atual_cents     — total de master_expenses do mês corrente
 *   • lucro_mes_atual_cents       — diferença
 *   • mrr_serie_12m               — array [{mes:'2026-01', value:cents}, ...] últimos 12 meses
 *   • despesas_serie_12m          — idem pra despesas
 *   • accounts_por_tipo           — { matriz:N, filial:N, advogado:N }
 *   • accounts_por_status         — { active:N, trial:N, ... }
 *   • receita_por_plano           — [{plano, slug, mrr_cents}]
 *   • despesas_por_categoria      — [{categoria, total_cents}]
 *   • contas_serie_12m            — crescimento mensal acumulado
 *
 * Acesso: super_admin apenas.
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') ApiResponse::methodNotAllowed();

$pdo = Database::getConnection();

// ─── MRR PROJETADO (todas as subs vivas: trial + active + past_due) ─────────
// Representa o RUN RATE — quanto entra se todos os trials converterem.
$mrrProjCents = (int) $pdo->query(
    "SELECT COALESCE(SUM(
        CASE WHEN s.billing_cycle = 'yearly'
             THEN ROUND(p.preco_anual_cents / 12)
             ELSE p.preco_mensal_cents
        END
     ), 0)
     FROM subscriptions s
     INNER JOIN plans p ON p.id = s.plan_id
     WHERE s.status IN ('trialing','active','past_due')"
)->fetchColumn();

// ─── MRR REALIZADO (subs efetivamente pagando agora: active + past_due) ─────
$mrrRealCents = (int) $pdo->query(
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

// ─── Mês corrente ────────────────────────────────────────────────────────────
$mesAtual = date('Y-m');

// ─── Receita Realizada do mês = invoices PAGAS este mês ─────────────────────
$stRecMes = $pdo->prepare(
    "SELECT COALESCE(SUM(amount_cents), 0)
     FROM invoices
     WHERE status = 'paid'
       AND DATE_FORMAT(paid_at, '%Y-%m') = :mm"
);
$stRecMes->execute(['mm' => $mesAtual]);
$receitaRealMesCents = (int) $stRecMes->fetchColumn();

// ─── Despesa mês corrente ────────────────────────────────────────────────────
$stDesp = $pdo->prepare(
    "SELECT COALESCE(SUM(valor_cents), 0)
     FROM master_expenses
     WHERE deleted_at IS NULL
       AND DATE_FORMAT(data_competencia, '%Y-%m') = :mm"
);
$stDesp->execute(['mm' => $mesAtual]);
$despMesCents = (int) $stDesp->fetchColumn();

// Dois lucros: REAL (caixa) e PROJETADO (potencial)
$lucroRealCents = $receitaRealMesCents - $despMesCents;
$lucroProjCents = $mrrProjCents - $despMesCents;

// ─── Série MRR últimos 12 meses (somatório de subscriptions ativas em cada mês) ──
// Como subscriptions tem created_at, calculamos quantas estavam ativas em cada mês
// e somamos preço. Versão simplificada: usa o MRR atual replicado, ajustado pelos
// criados/cancelados ao longo do tempo. Pra MVP, retornamos uma série razoável
// baseada em created_at + canceled_at.
$mrrSerie = [];
$despSerie = [];
$contasSerie = [];
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime("-{$i} months");
    $mes  = date('Y-m', $ts);
    $ini  = date('Y-m-01', $ts);
    $fim  = date('Y-m-t', $ts);

    // MRR vigente NO FIM daquele mês
    $stMRR = $pdo->prepare(
        "SELECT COALESCE(SUM(
            CASE WHEN s.billing_cycle = 'yearly'
                 THEN ROUND(p.preco_anual_cents / 12)
                 ELSE p.preco_mensal_cents
            END
         ), 0)
         FROM subscriptions s
         INNER JOIN plans p ON p.id = s.plan_id
         WHERE s.created_at <= :fim
           AND (s.canceled_at IS NULL OR s.canceled_at > :fim2)"
    );
    $stMRR->execute(['fim' => $fim . ' 23:59:59', 'fim2' => $fim . ' 23:59:59']);
    $mrrSerie[] = ['mes' => $mes, 'value' => (int) $stMRR->fetchColumn()];

    // Despesa no mês
    $stD = $pdo->prepare(
        "SELECT COALESCE(SUM(valor_cents), 0) FROM master_expenses
         WHERE deleted_at IS NULL
           AND DATE_FORMAT(data_competencia, '%Y-%m') = :mm"
    );
    $stD->execute(['mm' => $mes]);
    $despSerie[] = ['mes' => $mes, 'value' => (int) $stD->fetchColumn()];

    // Crescimento de contas (acumulado até o fim do mês)
    $stC = $pdo->prepare(
        "SELECT COUNT(*) FROM accounts
         WHERE deleted_at IS NULL AND created_at <= :fim"
    );
    $stC->execute(['fim' => $fim . ' 23:59:59']);
    $contasSerie[] = ['mes' => $mes, 'value' => (int) $stC->fetchColumn()];
}

// ─── Contas por tipo ────────────────────────────────────────────────────────
$accountsPorTipo = [
    'matriz'   => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND tipo='matriz'")->fetchColumn(),
    'filial'   => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND tipo='filial'")->fetchColumn(),
    'advogado' => (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND tipo='advogado'")->fetchColumn(),
];

// ─── Contas por status ──────────────────────────────────────────────────────
$accountsPorStatus = [];
foreach (['active','trial','overdue','suspended','cancelled','inactive'] as $st) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND status = :s");
    $stmt->execute(['s' => $st]);
    $accountsPorStatus[$st] = (int) $stmt->fetchColumn();
}

// ─── Receita por plano (MRR breakdown) ──────────────────────────────────────
$receitaPorPlano = $pdo->query(
    "SELECT p.id, p.slug, p.nome AS plano,
            COALESCE(SUM(
              CASE WHEN s.billing_cycle = 'yearly'
                   THEN ROUND(p.preco_anual_cents / 12)
                   ELSE p.preco_mensal_cents
              END
            ), 0) AS mrr_cents,
            COUNT(s.id) AS subs_count
     FROM plans p
     LEFT JOIN subscriptions s
       ON s.plan_id = p.id AND s.status IN ('active','past_due','trialing')
     GROUP BY p.id
     ORDER BY mrr_cents DESC"
)->fetchAll(\PDO::FETCH_ASSOC);

// ─── Despesas por categoria (mês atual) ──────────────────────────────────────
$stCat = $pdo->prepare(
    "SELECT categoria, COALESCE(SUM(valor_cents), 0) AS total_cents
     FROM master_expenses
     WHERE deleted_at IS NULL
       AND DATE_FORMAT(data_competencia, '%Y-%m') = :mm
     GROUP BY categoria
     ORDER BY total_cents DESC"
);
$stCat->execute(['mm' => $mesAtual]);
$despesasPorCategoria = $stCat->fetchAll(\PDO::FETCH_ASSOC);

$fmt = fn(int $c) => number_format($c / 100, 2, ',', '.');

ApiResponse::ok([
    // Visão DUPLA: projetada (potencial) vs realizada (caixa)
    'mrr_projetado_cents'    => $mrrProjCents,
    'mrr_projetado_brl'      => $fmt($mrrProjCents),
    'mrr_realizado_cents'    => $mrrRealCents,
    'mrr_realizado_brl'      => $fmt($mrrRealCents),
    'receita_real_mes_cents' => $receitaRealMesCents,
    'receita_real_mes_brl'   => $fmt($receitaRealMesCents),
    'despesa_mes_cents'      => $despMesCents,
    'despesa_mes_brl'        => $fmt($despMesCents),
    'lucro_real_cents'       => $lucroRealCents,
    'lucro_real_brl'         => $fmt($lucroRealCents),
    'lucro_projetado_cents'  => $lucroProjCents,
    'lucro_projetado_brl'    => $fmt($lucroProjCents),

    // Aliases de compatibilidade (não quebra código antigo que lê 'mrr_atual_*')
    'mrr_atual_cents'        => $mrrProjCents,
    'mrr_atual_brl'          => $fmt($mrrProjCents),
    'lucro_mes_cents'        => $lucroProjCents,
    'lucro_mes_brl'          => $fmt($lucroProjCents),

    'mrr_serie_12m'          => $mrrSerie,
    'despesas_serie_12m'     => $despSerie,
    'contas_serie_12m'       => $contasSerie,
    'accounts_por_tipo'      => $accountsPorTipo,
    'accounts_por_status'    => $accountsPorStatus,
    'receita_por_plano'      => $receitaPorPlano,
    'despesas_por_categoria' => $despesasPorCategoria,
    'mes_referencia'         => $mesAtual,
]);
