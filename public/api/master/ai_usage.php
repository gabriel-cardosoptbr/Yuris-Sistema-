<?php
/**
 * /api/master/ai_usage.php — Painel Master: visão de CONTAS x AGENTE de IA.
 *
 * Mostra, por conta: se o agente está LIGADO (agent_configs.enabled), em qual canal,
 * quantas áreas de triagem, e o CONSUMO de tokens/custo (hoje, mês, total) lido de
 * ai_usage_log (já gravado pelo motor a cada resposta do bot). Pura leitura/relatório.
 *
 * Acesso: super_admin em sessão master_mode (somente leitura — sem CSRF/escrita).
 *
 *   GET -> { ok, data: { summary:{...}, accounts:[ {...} ] } }
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$pdo = \App\Models\Database::getConnection();

$monthStart = date('Y-m-01 00:00:00');
$todayStart = date('Y-m-d 00:00:00');

/* ── 1) Configs do agente por conta (quem tem agente, ligado/desligado, canal) ── */
$cfgRows = $pdo->query("
    SELECT ac.account_id,
           a.nome   AS account_nome,
           a.tipo   AS tipo,
           a.status AS account_status,
           MAX(ac.enabled) AS any_enabled,
           COUNT(*) AS channels,
           GROUP_CONCAT(
               CONCAT(COALESCE(wi.instance_name,'(sem canal)'), '|', COALESCE(wi.status,''), '|', ac.enabled)
               ORDER BY ac.id SEPARATOR ';;'
           ) AS channels_detail
      FROM agent_configs ac
      JOIN accounts a            ON a.id  = ac.account_id
      LEFT JOIN whatsapp_instances wi ON wi.id = ac.whatsapp_instance_id
     GROUP BY ac.account_id, a.nome, a.tipo, a.status
")->fetchAll(\PDO::FETCH_ASSOC);

/* Áreas de triagem habilitadas por conta (best-effort; tabela pode estar vazia). */
$areasByAcc = [];
try {
    foreach ($pdo->query("SELECT account_id, COUNT(*) c FROM ai_intake_areas WHERE enabled = 1 GROUP BY account_id") as $r) {
        $areasByAcc[(int)$r['account_id']] = (int)$r['c'];
    }
} catch (\Throwable $_) { /* coluna/tabela ausente: ignora */ }

/* ── 2) Consumo (tokens/custo) por conta, lido de ai_usage_log ── */
$useStmt = $pdo->prepare("
    SELECT ul.account_id,
           a.nome AS account_nome,
           a.tipo AS tipo,
           COUNT(*)                       AS calls,
           COALESCE(SUM(ul.total_tokens),0)   AS tok_total,
           COALESCE(SUM(ul.estimated_cost),0) AS cost_total,
           COALESCE(SUM(CASE WHEN ul.created_at >= :ms1 THEN ul.total_tokens   ELSE 0 END),0) AS tok_month,
           COALESCE(SUM(CASE WHEN ul.created_at >= :ms2 THEN ul.estimated_cost ELSE 0 END),0) AS cost_month,
           COALESCE(SUM(CASE WHEN ul.created_at >= :ts1 THEN ul.total_tokens   ELSE 0 END),0) AS tok_today,
           MAX(ul.created_at) AS last_at
      FROM ai_usage_log ul
      LEFT JOIN accounts a ON a.id = ul.account_id
     GROUP BY ul.account_id, a.nome, a.tipo
");
$useStmt->execute([':ms1'=>$monthStart, ':ms2'=>$monthStart, ':ts1'=>$todayStart]);
$useRows = $useStmt->fetchAll(\PDO::FETCH_ASSOC);

/* ── 3) Merge por account_id ── */
$acc = []; // account_id => linha
$mk = function(int $id, string $nome = '', string $tipo = '') {
    return [
        'account_id'   => $id,
        'account_nome' => $nome !== '' ? $nome : ('Conta #' . $id),
        'tipo'         => $tipo,
        'account_status' => null,
        'enabled'      => 0,
        'channels'     => 0,
        'channels_detail' => [],
        'areas'        => 0,
        'calls'        => 0,
        'tok_today'    => 0, 'tok_month' => 0, 'tok_total' => 0,
        'cost_month'   => 0.0, 'cost_total' => 0.0,
        'last_at'      => null,
    ];
};

foreach ($cfgRows as $r) {
    $id = (int)$r['account_id'];
    $row = $mk($id, (string)($r['account_nome'] ?? ''), (string)($r['tipo'] ?? ''));
    $row['account_status'] = $r['account_status'] ?? null;
    $row['enabled']  = (int)$r['any_enabled'];
    $row['channels'] = (int)$r['channels'];
    $row['areas']    = $areasByAcc[$id] ?? 0;
    $det = [];
    foreach (explode(';;', (string)($r['channels_detail'] ?? '')) as $piece) {
        if ($piece === '') continue;
        $p = explode('|', $piece);
        $det[] = ['name'=>$p[0] ?? '', 'status'=>$p[1] ?? '', 'enabled'=>(int)($p[2] ?? 0)];
    }
    $row['channels_detail'] = $det;
    $acc[$id] = $row;
}

foreach ($useRows as $r) {
    $id = (int)$r['account_id'];
    if (!isset($acc[$id])) $acc[$id] = $mk($id, (string)($r['account_nome'] ?? ''), (string)($r['tipo'] ?? ''));
    $acc[$id]['calls']      = (int)$r['calls'];
    $acc[$id]['tok_today']  = (int)$r['tok_today'];
    $acc[$id]['tok_month']  = (int)$r['tok_month'];
    $acc[$id]['tok_total']  = (int)$r['tok_total'];
    $acc[$id]['cost_month'] = (float)$r['cost_month'];
    $acc[$id]['cost_total'] = (float)$r['cost_total'];
    $acc[$id]['last_at']    = $r['last_at'];
}

$accounts = array_values($acc);
/* Ordena: ligados primeiro, depois por consumo do mês desc. */
usort($accounts, function ($a, $b) {
    if ($a['enabled'] !== $b['enabled']) return $b['enabled'] <=> $a['enabled'];
    return $b['tok_month'] <=> $a['tok_month'];
});

$summary = [
    'contas_com_agente' => count($cfgRows),
    'contas_ligadas'    => count(array_filter($accounts, fn($x) => $x['enabled'] === 1)),
    'tokens_today'      => array_sum(array_column($accounts, 'tok_today')),
    'tokens_month'      => array_sum(array_column($accounts, 'tok_month')),
    'tokens_total'      => array_sum(array_column($accounts, 'tok_total')),
    'cost_month'        => array_sum(array_column($accounts, 'cost_month')),
    'cost_total'        => array_sum(array_column($accounts, 'cost_total')),
    'month_label'       => date('m/Y'),
];

ApiResponse::ok(['summary' => $summary, 'accounts' => $accounts]);
