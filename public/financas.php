<?php
require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/Account.php';
require_once __DIR__ . '/../app/Models/ResourceShare.php';
require_once __DIR__ . '/../app/Helpers/AccountContext.php';

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$activePage = 'dre';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));

require_once __DIR__ . '/../app/Models/DREAccount.php';
use App\Models\DREAccount;
use App\Helpers\AccountContext;

// Contexto de tenant — render server-side filtra por conta para evitar "flash" entre contas
$ctx       = AccountContext::fromSession();
$ctx->assertAccountActive(); // bloqueia conta suspensa/cancelada/inativa
$tenantIds = $ctx->getAccessibleAccountIds('financas');
if (empty($tenantIds)) $tenantIds = [0]; // guard: evita SQL "IN ()" inválido

// Monta cláusula IN(tenantIds) para reuso
$_finPh = []; $_finParams = [];
foreach ($tenantIds as $i => $aid) { $k = "finacc_{$i}"; $_finPh[] = ":{$k}"; $_finParams[$k] = (int)$aid; }
$_finIn = '(' . implode(',', $_finPh) . ')';

$summary   = DREAccount::summary(['account_ids' => $tenantIds]);
$pdo       = App\Models\Database::getConnection();
$st        = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))),0) as closed_total
                            FROM cards
                            WHERE deleted_at IS NULL
                              AND (status = 'fechado' OR (data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00'))
                              AND account_id IN $_finIn");
$st->execute($_finParams);
$row       = $st->fetch();
$closed_total       = (float)($row['closed_total'] ?? 0);
$combined_receita   = (float)($summary['receita'] ?? 0) + $closed_total;
$combined_despesa   = (float)($summary['despesa'] ?? 0);
$combined_resultado = $combined_receita - $combined_despesa;

// ── Impostos ─────────────────────────────────────────────────────────────
$taxes = [];
$total_impostos = 0.0;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS taxes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NULL,
        nome VARCHAR(100) NOT NULL DEFAULT '',
        percentual DECIMAL(7,4) NOT NULL DEFAULT 0,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $tx_st = $pdo->prepare("SELECT * FROM taxes WHERE ativo = 1 AND account_id IN $_finIn ORDER BY id ASC");
    $tx_st->execute($_finParams);
    $taxes = $tx_st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($taxes as $t) {
        $total_impostos += $combined_receita * ((float)$t['percentual'] / 100);
    }
} catch (Exception $e) {}
$pct_impostos_total  = array_sum(array_column($taxes, 'percentual'));
$combined_despesa_total = $combined_despesa + $total_impostos;
$resultado_real      = $combined_receita - $combined_despesa_total;
$margem              = $combined_receita > 0 ? round(($resultado_real / $combined_receita) * 100, 1) : 0;
$health_class        = $margem >= 40 ? 'health-ok' : ($margem >= 15 ? 'health-warn' : 'health-danger');
$health_label        = $margem >= 40 ? 'Saudável' : ($margem >= 15 ? 'Atenção' : 'Crítico');
$health_icon         = $margem >= 40 ? '▲' : ($margem >= 15 ? '●' : '▼');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Finanças — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    :root {
      --bg: #070F1C;
      --panel: #0D1C30;
      --panel-soft: #0E1F36;
      --line: rgba(160,180,210,0.08);
      --line-strong: rgba(160,180,210,0.14);
      --text: #D8E4F0;
      --muted: #7A8898;
      --primary: #244E7A;
      --ok: #1E4A3A;
      --warn: #3D3010;
      --danger: #3A1020;
      --radius: 14px;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      background-color: #070F1C;
      background-image:
        radial-gradient(ellipse 120% 80% at 15% 40%, rgba(20,50,90,0.18) 0%, transparent 55%),
        radial-gradient(ellipse 80% 60% at 85% 20%, rgba(30,60,100,0.12) 0%, transparent 50%);
      background-attachment: fixed;
      color: var(--text);
      font-family: 'Poppins', system-ui, -apple-system, sans-serif;
      min-height: 100vh;
    }
    /* ── Section panel ── */
    .dre-panel {
      background: linear-gradient(165deg, rgba(14,35,65,.94), rgba(10,23,43,.96));
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 20px;
    }
    .panel-title {
      font-size: .92rem; font-weight: 700; color: #d0e8ff;
      margin: 0 0 4px;
    }
    .panel-sub {
      font-size: .72rem; color: var(--muted); margin: 0 0 16px;
    }
    /* ── KPI cards ── */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0,1fr));
      gap: 12px;
      margin-bottom: 20px;
    }
    .kpi-card {
      background: linear-gradient(150deg, rgba(15,36,68,.96), rgba(10,22,44,.98));
      border: 1px solid rgba(96,165,250,.22);
      border-radius: 12px;
      padding: 14px 16px;
      position: relative;
      overflow: hidden;
      transition: border-color .18s, box-shadow .18s;
    }
    .kpi-card:hover { border-color: rgba(160,180,210,0.22); box-shadow: 0 8px 24px rgba(0,0,0,0.40); transform: translateY(-2px); }
    .kpi-card::before {
      content: '';
      position: absolute; top: 0; left: 0; right: 0; height: 3px;
      border-radius: 12px 12px 0 0;
    }
    .kpi-card.receita::before   { background: linear-gradient(90deg, #10b981, #34d399); }
    .kpi-card.despesa::before   { background: linear-gradient(90deg, #ef4444, #f87171); }
    .kpi-card.lucro::before     { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
    .kpi-card.margem::before    { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }
    .kpi-card.honorarios::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
    .kpi-card.resultado::before  { background: linear-gradient(90deg, #06b6d4, #22d3ee); }
    .kpi-card.imposto::before    { background: linear-gradient(90deg, #f97316, #fb923c); }
    /* ── Tax section ── */
    .tax-row { display: grid; grid-template-columns: 1fr auto auto auto; gap: 0; align-items: center; padding: 9px 12px; border-bottom: 1px solid rgba(148,163,184,.07); transition: background .12s; }
    .tax-row:hover { background: rgba(249,115,22,.04); }
    .tax-row:last-child { border-bottom: none; }
    .tax-name { font-size: .82rem; color: #d4eaff; font-weight: 500; }
    .tax-pct  { font-size: .82rem; font-weight: 700; color: #C4A040; text-align: right; min-width: 70px; }
    .tax-val  { font-size: .82rem; font-weight: 700; color: #B06070; text-align: right; min-width: 130px; }
    .tax-actions { display: flex; gap: 6px; justify-content: flex-end; min-width: 110px; padding-left: 12px; }
    .kpi-label { font-size: .65rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin-bottom: 6px; }
    .kpi-value {
      font-size: 1.15rem; font-weight: 700; color: #f0f9ff; line-height: 1.1;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .kpi-desc { font-size: .65rem; color: var(--muted); margin-top: 4px; line-height: 1.3; }
    .kpi-badge {
      display: inline-flex; align-items: center; gap: 3px;
      font-size: .63rem; font-weight: 700;
      padding: 2px 7px; border-radius: 999px; margin-top: 5px;
    }
    .badge-ok     { background: rgba(30,74,58,0.60);  color: #7ABDA0; border: 1px solid rgba(122,189,160,0.25); }
    .badge-warn   { background: rgba(61,48,16,0.60);  color: #C4A040; border: 1px solid rgba(196,160,64,0.25); }
    .badge-danger { background: rgba(58,16,32,0.60);  color: #B06070; border: 1px solid rgba(176,96,112,0.25); }
    .badge-neutral{ background: rgba(30,40,55,0.70);  color: #6B7887; border: 1px solid rgba(107,120,135,0.20); }
    /* ── Health banner ── */
    .health-bar {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 16px; border-radius: 10px; margin-bottom: 20px;
      font-size: .8rem; font-weight: 600;
    }
    .health-ok     { background: rgba(30,74,58,0.25); border: 1px solid rgba(122,189,160,0.25); color: #7ABDA0; }
    .health-warn   { background: rgba(61,48,16,0.25); border: 1px solid rgba(196,160,64,0.25);  color: #C4A040; }
    .health-danger { background: rgba(58,16,32,0.25); border: 1px solid rgba(176,96,112,0.25); color: #B06070; }
    .health-bar span.hl { font-size: 1rem; }
    /* ── Charts ── */
    .charts-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      margin-bottom: 20px;
    }
    .chart-box {
      background: linear-gradient(150deg, rgba(12,28,54,.98), rgba(8,19,40,.98));
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 12px 16px;
    }
    .chart-title { font-size: .8rem; font-weight: 600; color: #cde4ff; margin-bottom: 12px; }
    /* ── Form ── */
    .form-section-title { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--primary); margin-bottom: 12px; }
    .form-grid {
      display: grid;
      grid-template-columns: repeat(6, minmax(0,1fr));
      gap: 10px;
      align-items: end;
    }
    .form-group { display: flex; flex-direction: column; gap: 4px; }
    .form-group label { font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
    .form-group input,
    .form-group select {
      background: rgba(8,20,40,.85);
      border: 1px solid rgba(96,165,250,.25);
      border-radius: 8px;
      padding: 8px 10px;
      color: #e8f4ff;
      font-size: .8rem;
      font-family: inherit;
      transition: border-color .15s;
      width: 100%;
    }
    .form-group input::placeholder { color: rgba(154,176,201,.5); }
    .form-group input:focus,
    .form-group select:focus { outline: none; border-color: rgba(160,180,210,0.32); box-shadow: 0 0 0 2px rgba(30,58,95,0.25); }
    input[type="month"]::-webkit-calendar-picker-indicator:hover,
    input[type="date"]::-webkit-calendar-picker-indicator:hover {
      opacity: 1 !important;
    }
    .form-group select option { background: #0b1c35; color: #e8f4ff; }
    /* ── Buttons ── */
    .btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: 8px; border: none;
      font-size: .8rem; font-weight: 700; cursor: pointer;
      font-family: inherit; transition: all .18s;
    }
    .btn-primary { background: linear-gradient(135deg, #1A3A5C, #244E7A); color: #C8D4E0; border: 1px solid rgba(160,180,210,0.20); box-shadow: 0 2px 8px rgba(0,0,0,0.35); }
    .btn-primary:hover { background: linear-gradient(135deg, #244E7A, #2E6090); box-shadow: 0 4px 14px rgba(20,58,100,0.40); }
    .btn-ghost { background: rgba(160,180,210,0.06); color: #7A8898; border: 1px solid rgba(160,180,210,0.18); }
    .btn-ghost:hover { background: rgba(160,180,210,0.12); color: #A8BDD4; }
    .btn-sm { padding: 5px 10px; font-size: .72rem; border-radius: 6px; }
    .btn-edit  { background: linear-gradient(135deg, #1A3A5C, #244E7A); color: #A8BDD4; border: 1px solid rgba(160,180,210,0.18); }
    .btn-edit:hover  { background: linear-gradient(135deg, #244E7A, #2E6090); }
    .btn-del   { background: linear-gradient(135deg, #3A1020, #4A1828); color: #B06070; border: 1px solid rgba(176,96,112,0.25); }
    .btn-del:hover   { background: linear-gradient(135deg, #4A1828, #5A202E); }
    .btn-manage { background: transparent; border: 1px solid rgba(160,180,210,0.18); color: #7A8898; padding: 8px 12px; border-radius: 8px; cursor: pointer; font-size: .75rem; display: inline-flex; align-items: center; gap: 5px; transition: all .15s; }
    .btn-manage:hover { background: rgba(160,180,210,0.08); color: #A8BDD4; }
    /* ── Filters ── */
    .filters-grid {
      display: grid;
      grid-template-columns: repeat(5, minmax(0,1fr)) auto;
      gap: 10px;
      align-items: end;
    }
    /* ── Tables ── */
    .dre-table-wrap { overflow-x: auto; margin-top: 8px; }
    .dre-table { width: 100%; border-collapse: collapse; font-size: .78rem; }
    .dre-table thead th {
      padding: 8px 12px; text-align: left;
      font-size: .65rem; text-transform: uppercase; letter-spacing: .05em;
      color: var(--muted); border-bottom: 1px solid var(--line);
      white-space: nowrap;
    }
    .dre-table thead th:last-child { text-align: right; }
    .dre-table tbody tr {
      border-bottom: 1px solid rgba(148,163,184,.07);
      transition: background .12s;
    }
    .dre-table tbody tr:hover { background: rgba(59,130,246,.06); }
    .dre-table tbody td { padding: 9px 12px; color: #d4eaff; vertical-align: middle; }
    .dre-table tbody td:last-child { text-align: right; white-space: nowrap; }
    .dre-table .val-col { text-align: right; font-weight: 700; font-size: .82rem; white-space: nowrap; }
    .val-receita { color: #6ee7b7; }
    .val-despesa  { color: #fca5a5; }
    .empty-row td { color: var(--muted); font-style: italic; text-align: center; padding: 16px; }
    /* ── Badges in table ── */
    .tbadge {
      display: inline-block; padding: 2px 8px; border-radius: 999px;
      font-size: .62rem; font-weight: 700; white-space: nowrap;
    }
    .tbadge-receita  { background: rgba(30,74,58,0.60);  color: #7ABDA0; border: 1px solid rgba(122,189,160,0.25); }
    .tbadge-despesa  { background: rgba(58,16,32,0.60);  color: #B06070; border: 1px solid rgba(176,96,112,0.25); }
    .tbadge-fixa     { background: rgba(14,40,69,0.70);  color: #6898C0; border: 1px solid rgba(104,152,192,0.25); }
    .tbadge-variavel { background: rgba(61,48,16,0.60);  color: #C4A040; border: 1px solid rgba(196,160,64,0.25); }
    /* ── Executive summary ── */
    .exec-summary {
      background: linear-gradient(135deg, rgba(26,58,92,0.10), rgba(36,78,122,0.06));
      border: 1px solid rgba(160,180,210,0.10);
      border-left: 3px solid #244E7A;
      border-radius: 10px;
      padding: 14px 18px;
      font-size: .82rem;
      line-height: 1.65;
      color: #c5dff8;
      margin-bottom: 20px;
    }
    .exec-summary strong { color: #f0f9ff; }
    /* ── Modals ── */
    .modal-shell {
      position: fixed; inset: 0;
      background: rgba(2,6,23,.72);
      align-items: center; justify-content: center; z-index: 200;
    }
    .modal-panel {
      width: 620px; max-width: 96%; max-height: 90vh; overflow-y: auto;
      background: linear-gradient(160deg, rgba(11,28,55,.98), rgba(8,20,40,.99));
      border: 1px solid rgba(96,165,250,.25);
      border-radius: var(--radius);
      padding: 24px;
      box-shadow: 0 24px 60px rgba(2,6,23,.7);
    }
    .modal-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
    .modal-title { font-size: 1rem; font-weight: 700; color: #e8f4ff; }
    .modal-close { background: transparent; border: none; color: var(--muted); cursor: pointer; font-size: 1.3rem; line-height: 1; padding: 0; transition: color .15s; }
    .modal-close:hover { color: #fff; }
    .modal-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .modal-form-grid .span2 { grid-column: 1 / span 2; }
    .modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
    .error-msg { color: #fca5a5; font-size: .75rem; margin-top: 8px; display: none; }
    /* ── Divider ── */
    .section-divider { border: none; border-top: 1px solid var(--line); margin: 20px 0; }
    /* ── Responsive ── */
    @media (max-width: 1400px) { .kpi-grid { grid-template-columns: repeat(4, minmax(0,1fr)); } }
    @media (max-width: 1100px) { .kpi-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } .charts-grid { grid-template-columns: 1fr; } .form-grid { grid-template-columns: repeat(3, minmax(0,1fr)); } .filters-grid { grid-template-columns: repeat(3, minmax(0,1fr)); } }
    @media (max-width: 980px)  { .kpi-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 600px)  { .kpi-grid { grid-template-columns: 1fr 1fr; } .form-grid { grid-template-columns: 1fr; } .filters-grid { grid-template-columns: 1fr; } .modal-form-grid { grid-template-columns: 1fr; } .modal-form-grid .span2 { grid-column: auto; } .charts-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<main class="w-full px-6 py-6">
  <div class="page-layout">

    <!-- ── Sidebar ── -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- ── Main Content ── -->
    <section class="main-content space-y-4">

      <!-- Header — padrão .page-header (yuris-theme.css) -->
      <div class="dre-panel page-header">
        <div class="page-header-inner">
          <div class="page-header-text">
            <h2 class="page-header-title">Central Financeira Executiva</h2>
            <p class="page-header-subtitle">DRE — Demonstração do Resultado do Exercício · Escritório Jurídico</p>
          </div>
        </div>
      </div>

      <!-- ── KPI Cards ── -->
      <div class="kpi-grid">
        <div class="kpi-card receita">
          <div class="kpi-label">Receita operacional</div>
          <div class="kpi-value" id="sumReceita"><?=number_format($combined_receita,2,',','.')?></div>
          <div class="kpi-desc">Honorários + lançamentos de receita</div>
          <span class="kpi-badge badge-ok">↑ Receita</span>
        </div>
        <div class="kpi-card despesa">
          <div class="kpi-label">Custos operacionais</div>
          <div class="kpi-value" id="sumDespesa"><?=number_format($combined_despesa_total,2,',','.')?></div>
          <div class="kpi-desc">Despesas + impostos sobre receita</div>
          <span class="kpi-badge badge-danger">↓ Despesa</span>
        </div>
        <div class="kpi-card imposto">
          <div class="kpi-label">Total em impostos</div>
          <div class="kpi-value" id="sumImpostos"><?=number_format($total_impostos,2,',','.')?></div>
          <div class="kpi-desc" id="impostoPercDesc"><?=number_format($pct_impostos_total,2,',','.')?>% da receita operacional</div>
          <span class="kpi-badge badge-warn">Imposto</span>
        </div>
        <div class="kpi-card lucro">
          <div class="kpi-label">Lucro líquido real</div>
          <div class="kpi-value" id="sumResultado"><?=number_format($resultado_real,2,',','.')?></div>
          <div class="kpi-desc">Receita − despesas − impostos</div>
          <span class="kpi-badge <?=$resultado_real >= 0 ? 'badge-ok' : 'badge-danger'?>" id="lucrobadge"><?=$resultado_real >= 0 ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Positivo' : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Negativo'?></span>
        </div>
        <div class="kpi-card margem">
          <div class="kpi-label">Margem líquida real</div>
          <div class="kpi-value" id="sumMargem"><?=number_format($margem,1,',','.')?>%</div>
          <div class="kpi-desc">Após despesas e impostos</div>
          <span class="kpi-badge <?=$margem >= 40 ? 'badge-ok' : ($margem >= 15 ? 'badge-warn' : 'badge-danger')?>" id="margembadge"><?=$margem >= 40 ? 'Saudável' : ($margem >= 15 ? 'Atenção' : 'Crítico')?></span>
        </div>
        <div class="kpi-card honorarios">
          <div class="kpi-label">Honorários recebidos</div>
          <div class="kpi-value" id="closedOnlyVal"><?=number_format($closed_total,2,',','.')?></div>
          <div class="kpi-desc">Contratos fechados no CRM</div>
          <span class="kpi-badge badge-neutral">CRM</span>
        </div>
        <div class="kpi-card resultado">
          <div class="kpi-label">Lançamentos extras</div>
          <div class="kpi-value" id="sumLancamentos"><?=number_format((float)($summary['receita'] ?? 0),2,',','.')?></div>
          <div class="kpi-desc">Receitas via lançamentos DRE</div>
          <span class="kpi-badge badge-neutral">DRE</span>
        </div>
      </div>

      <!-- ── Health indicator ── -->
      <div class="health-bar <?=$health_class?>" id="healthBar">
        <span class="hl" id="healthIcon"><?=$health_icon?></span>
        <span>Saúde financeira: <strong id="healthLabel"><?=$health_label?></strong></span>
        <span style="margin-left:auto;opacity:.7;font-size:.72rem;font-weight:400" id="healthMeta">Margem real de <?=number_format($margem,1,',','.')?>% · Meta recomendada: ≥ 40%</span>
      </div>

      <!-- ── Executive summary ── -->
      <div class="exec-summary" id="execSummary">
        No período consolidado, o escritório registra <strong>R$ <?=number_format($combined_receita,2,',','.')?></strong> em receita operacional,
        <strong>R$ <?=number_format($combined_despesa_total,2,',','.')?></strong> em custos totais (incluindo <strong>R$ <?=number_format($total_impostos,2,',','.')?></strong> em impostos) e
        <strong>lucro líquido real de R$ <?=number_format($resultado_real,2,',','.')?></strong>,
        representando margem real de <strong><?=number_format($margem,1,',','.')?>%</strong>.
      </div>

      <!-- ── Gestão de Impostos ── -->
      <div class="dre-panel" style="margin-bottom:20px" id="taxPanel">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;flex-wrap:wrap;gap:10px">
          <div>
            <div class="panel-title">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#f97316" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:5px"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
              Impostos sobre a Receita
            </div>
            <p class="panel-sub">Configure as alíquotas — os valores são calculados sobre a receita operacional e entram nos Custos Operacionais</p>
          </div>
          <button id="addTaxBtn" class="btn btn-primary btn-sm" style="flex-shrink:0">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Novo imposto
          </button>
        </div>

        <!-- Summary strip -->
        <div id="taxSummaryStrip" style="display:none;align-items:center;gap:20px;padding:10px 14px;border-radius:10px;background:rgba(249,115,22,.08);border:1px solid rgba(249,115,22,.22);margin-bottom:16px;flex-wrap:wrap">
          <div>
            <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;color:rgba(249,115,22,.8);font-weight:700">Total em impostos</div>
            <div style="font-size:1.15rem;font-weight:700;color:#fca5a5" id="taxSummaryTotal">R$ 0,00</div>
          </div>
          <div style="width:1px;height:32px;background:rgba(249,115,22,.2)"></div>
          <div>
            <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;color:rgba(249,115,22,.8);font-weight:700">Alíquota total</div>
            <div style="font-size:1.15rem;font-weight:700;color:#fcd34d" id="taxSummaryPct">0,00%</div>
          </div>
          <div style="width:1px;height:32px;background:rgba(249,115,22,.2)"></div>
          <div>
            <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;color:rgba(249,115,22,.8);font-weight:700">Incide sobre</div>
            <div style="font-size:1.15rem;font-weight:700;color:#93c5fd" id="taxSummaryBase">R$ 0,00</div>
          </div>
        </div>

        <!-- Tax list -->
        <div id="taxListWrap">
          <div style="display:grid;grid-template-columns:1fr 100px 150px 110px;gap:0;padding:6px 12px;border-bottom:1px solid var(--line)">
            <div style="font-size:.63rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Imposto / Tributo</div>
            <div style="font-size:.63rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);text-align:right">Alíquota</div>
            <div style="font-size:.63rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);text-align:right">Valor sobre receita</div>
            <div style="font-size:.63rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);text-align:right">Ações</div>
          </div>
          <div id="taxRows">
            <div style="text-align:center;padding:20px;color:var(--muted);font-size:.78rem;font-style:italic">Nenhum imposto cadastrado — clique em "Novo imposto" para começar</div>
          </div>
        </div>
      </div>

      <!-- ── Charts ── -->
      <div class="charts-grid">
        <div class="chart-box" style="display:flex;flex-direction:column;padding:10px 16px">
          <div class="chart-title" style="margin-bottom:8px">Receita × Despesa × Lucro</div>
          <div style="flex:1">
            <canvas id="chartBarRD" height="80"></canvas>
          </div>
        </div>
        <div class="chart-box" style="display:flex;flex-direction:column;padding:10px 16px">
          <div class="chart-title" style="margin-bottom:8px">Composição da receita</div>
          <div style="display:flex;align-items:center;gap:0;flex:1">
            <div style="width:130px;height:130px;flex-shrink:0;position:relative">
              <canvas id="chartDonut"></canvas>
            </div>
            <div id="donutLegend" style="flex:1;display:flex;flex-direction:column;justify-content:center;gap:10px;padding-left:20px"></div>
          </div>
        </div>
      </div>

      <!-- ── Filters (moved above form) ── -->
      <div class="dre-panel" style="margin-bottom:16px">
        <div class="panel-title">Filtros</div>
        <p class="panel-sub">Refine os lançamentos exibidos nas tabelas abaixo</p>
        <div class="filters-grid">
          <div class="form-group">
            <label>Período (mês)</label>
            <input id="filter_month" type="month" />
          </div>
          <div class="form-group">
            <label>Tipo</label>
            <select id="filter_tipo_extra" style="background:rgba(8,20,40,.85);border:1px solid rgba(96,165,250,.25);border-radius:8px;padding:8px 10px;color:#e8f4ff;font-size:.8rem;font-family:inherit">
              <option value="all">Todos os tipos</option>
              <option value="receita">Receita</option>
              <option value="despesa">Despesa</option>
            </select>
          </div>
          <div class="form-group">
            <label>Natureza</label>
            <select id="filter_recorrencia" style="background:rgba(8,20,40,.85);border:1px solid rgba(96,165,250,.25);border-radius:8px;padding:8px 10px;color:#e8f4ff;font-size:.8rem;font-family:inherit">
              <option value="all">Todas</option>
              <option value="fixa">Fixa</option>
              <option value="variavel">Variável</option>
            </select>
          </div>
          <div class="form-group">
            <label>Código</label>
            <select id="filter_code" style="background:rgba(8,20,40,.85);border:1px solid rgba(96,165,250,.25);border-radius:8px;padding:8px 10px;color:#e8f4ff;font-size:.8rem;font-family:inherit">
              <option value="">Todos os códigos</option>
            </select>
          </div>
          <div class="form-group">
            <label>Busca rápida</label>
            <input id="filter_search_dre" type="text" placeholder="Descrição…" style="background:rgba(8,20,40,.85);border:1px solid rgba(96,165,250,.25);border-radius:8px;padding:8px 10px;color:#e8f4ff;font-size:.8rem;font-family:inherit" />
          </div>
          <div class="form-group">
            <label style="opacity:0">ação</label>
            <button id="clearFiltersBtn" class="btn btn-ghost" style="width:100%">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              Limpar filtros
            </button>
          </div>
        </div>
      </div>

      <!-- ── Form: Novo lançamento ── -->
      <div class="dre-panel" style="margin-bottom:16px">
        <div class="panel-title">Novo lançamento financeiro</div>
        <p class="panel-sub">Registre receitas e despesas fixas ou variáveis do escritório</p>

        <div class="form-grid">
          <div class="form-group" style="grid-column: span 1; position:relative;">
            <label>Código / centro de custo</label>
            <div style="display:flex;gap:6px;align-items:center">
              <select id="codigo" style="flex:1;background:rgba(8,20,40,.85);border:1px solid rgba(96,165,250,.25);border-radius:8px;padding:8px 10px;color:#e8f4ff;font-size:.8rem;font-family:inherit">
                <option value="">Selecione</option>
              </select>
              <button id="manageCodesBtn" class="btn-manage" title="Gerenciar códigos">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                Gerenciar
              </button>
            </div>
          </div>
          <div class="form-group">
            <label>Competência</label>
            <input id="data_referencia" type="month" />
          </div>
          <div class="form-group" style="grid-column: span 2">
            <label>Descrição</label>
            <input id="nome" type="text" placeholder="Ex: Aluguel do escritório, Honorário recebido…" />
          </div>
          <div class="form-group">
            <label>Tipo</label>
            <select id="tipo">
              <option value="despesa">Despesa</option>
              <option value="receita">Receita</option>
            </select>
          </div>
          <div class="form-group">
            <label>Natureza</label>
            <select id="recorrencia">
              <option value="fixa">Fixa</option>
              <option value="variavel">Variável</option>
            </select>
          </div>
          <div class="form-group">
            <label>Valor (R$)</label>
            <input id="valor_fixo" type="text" placeholder="0,00" style="text-align:right" />
          </div>
          <div class="form-group" style="justify-content:flex-end">
            <label style="opacity:0">ação</label>
            <button id="addBtn" class="btn btn-primary" style="width:100%">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Adicionar lançamento
            </button>
          </div>
        </div>
        <div id="addAccountMsg" class="error-msg"></div>
      </div>

      <!-- ── Tables ── -->
      <div class="dre-panel" style="margin-bottom:16px" id="dreListWrap">
        <div class="panel-title">Custos fixos mensais</div>
        <p class="panel-sub">Despesas e receitas com recorrência fixa</p>
        <div class="dre-table-wrap">
          <table class="dre-table" id="dreTableFixed">
            <thead>
              <tr>
                <th>Código</th>
                <th>Descrição</th>
                <th>Tipo</th>
                <th>Natureza</th>
                <th>Competência</th>
                <th style="text-align:right">Valor</th>
                <th style="text-align:right">Ações</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <div class="dre-panel" id="dreVarWrap">
        <div class="panel-title">Custos variáveis operacionais</div>
        <p class="panel-sub">Despesas e receitas com natureza variável</p>
        <div class="dre-table-wrap">
          <table class="dre-table" id="dreTableVar">
            <thead>
              <tr>
                <th>Código</th>
                <th>Descrição</th>
                <th>Tipo</th>
                <th>Natureza</th>
                <th>Competência</th>
                <th style="text-align:right">Valor</th>
                <th style="text-align:right">Ações</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

    </section>
  </div><!-- /page-layout -->

  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
</main>

<!-- ══ MODAL: Imposto ══ -->
<div id="taxModal" class="modal-shell" style="display:none">
  <div class="modal-panel" style="width:460px">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="taxModalTitle">Novo imposto</div>
        <p style="font-size:.72rem;color:var(--muted);margin:4px 0 0">O valor é calculado automaticamente sobre a receita operacional</p>
      </div>
      <button class="modal-close" onclick="document.getElementById('taxModal').style.display='none'"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <input type="hidden" id="taxId">
    <div class="modal-form-grid">
      <div class="form-group span2">
        <label>Nome do imposto / tributo</label>
        <input id="taxNome" type="text" placeholder="Ex: ISS, IRPJ, CSLL, COFINS, PIS…" autocomplete="off" />
      </div>
      <div class="form-group">
        <label>Alíquota (%)</label>
        <input id="taxPercentual" type="text" inputmode="decimal" placeholder="0,00" style="text-align:right" autocomplete="off" />
      </div>
      <div class="form-group">
        <label>Valor calculado (prévia)</label>
        <div id="taxCalcPreview" style="padding:8px 10px;background:rgba(8,20,40,.85);border:1px solid rgba(249,115,22,.25);border-radius:8px;color:#fcd34d;font-size:.88rem;font-weight:700;min-height:36px;display:flex;align-items:center">—</div>
      </div>
    </div>
    <div id="taxModalMsg" class="error-msg"></div>
    <div class="modal-footer">
      <button onclick="document.getElementById('taxModal').style.display='none'" class="btn btn-ghost">Cancelar</button>
      <button id="saveTaxBtn" class="btn btn-primary">Salvar imposto</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: Gerenciar códigos ══ -->
<div id="codesModal" class="modal-shell" style="display:none">
  <div class="modal-panel" style="width:700px">
    <div class="modal-header">
      <div>
        <div class="modal-title">Gerenciar centros de custo</div>
        <p style="font-size:.72rem;color:var(--muted);margin:4px 0 0">Cadastre e edite os códigos de classificação financeira</p>
      </div>
      <button id="closeCodesModal" class="modal-close"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 2fr auto;gap:8px;margin-bottom:16px;align-items:end">
      <div class="form-group">
        <label>Código</label>
        <input id="newCode" type="text" placeholder="Ex: 1.1.01" />
      </div>
      <div class="form-group">
        <label>Descrição</label>
        <input id="newCodeDesc" type="text" placeholder="Ex: Honorários advocatícios" />
      </div>
      <div style="display:flex;gap:6px;padding-bottom:1px">
        <button id="addCodeBtn" class="btn btn-primary btn-sm">Adicionar</button>
        <button id="cancelEditCodeBtn" class="btn btn-ghost btn-sm" style="display:none">Cancelar</button>
      </div>
    </div>
    <div style="max-height:300px;overflow:auto;border-top:1px solid var(--line);padding-top:12px">
      <table id="codesTable" style="width:100%;border-collapse:collapse;font-size:.78rem">
        <thead>
          <tr>
            <th style="text-align:left;padding:6px 10px;color:var(--muted);font-size:.65rem;text-transform:uppercase;letter-spacing:.04em">Código</th>
            <th style="text-align:left;padding:6px 10px;color:var(--muted);font-size:.65rem;text-transform:uppercase;letter-spacing:.04em">Descrição</th>
            <th style="text-align:right;padding:6px 10px;color:var(--muted);font-size:.65rem;text-transform:uppercase;letter-spacing:.04em">Ações</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ MODAL: Editar código ══ -->
<div id="codeEditModal" class="modal-shell" style="display:none">
  <div class="modal-panel" style="width:480px">
    <div class="modal-header">
      <div class="modal-title">Editar centro de custo</div>
      <button id="closeCodeEditModal" class="modal-close"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="modal-form-grid">
      <div class="form-group">
        <label>Código</label>
        <input id="codeEdit_code" type="text" />
      </div>
      <div class="form-group">
        <label>Descrição</label>
        <input id="codeEdit_desc" type="text" />
      </div>
    </div>
    <div class="modal-footer">
      <button id="cancelCodeEditBtn" class="btn btn-ghost">Cancelar</button>
      <button id="saveCodeBtn" class="btn btn-primary">Salvar alterações</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: Editar lançamento ══ -->
<div id="accountEditModal" class="modal-shell" style="display:none">
  <div class="modal-panel">
    <div class="modal-header">
      <div>
        <div class="modal-title">Editar lançamento financeiro</div>
        <p style="font-size:.72rem;color:var(--muted);margin:4px 0 0">Atualize os dados do registro selecionado</p>
      </div>
      <button id="closeAccountModal" class="modal-close"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="modal-form-grid">
      <div class="form-group">
        <label>Código / centro de custo</label>
        <select id="edit_codigo"></select>
      </div>
      <div class="form-group">
        <label>Competência</label>
        <input id="edit_data_referencia" type="month" />
      </div>
      <div class="form-group">
        <label>Tipo</label>
        <select id="edit_tipo">
          <option value="despesa">Despesa</option>
          <option value="receita">Receita</option>
        </select>
      </div>
      <div class="form-group">
        <label>Natureza</label>
        <select id="edit_recorrencia">
          <option value="fixa">Fixa</option>
          <option value="variavel">Variável</option>
        </select>
      </div>
      <div class="form-group span2">
        <label>Descrição</label>
        <input id="edit_nome" type="text" />
      </div>
      <div class="form-group">
        <label>Valor (R$)</label>
        <input id="edit_valor_fixo" type="text" placeholder="0,00" style="text-align:right" />
      </div>
    </div>
    <div id="editAccountMsg" class="error-msg"></div>
    <div class="modal-footer">
      <button onclick="document.getElementById('accountEditModal').style.display='none'" class="btn btn-ghost">Cancelar</button>
      <button id="saveAccountBtn" class="btn btn-primary">Salvar alterações</button>
    </div>
  </div>
</div>

<script>
// ── Close modals on backdrop click ──
document.addEventListener('DOMContentLoaded', function() {
  ['codesModal','codeEditModal','accountEditModal','taxModal'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', function(e) {
      if (e.target === el) el.style.display = 'none';
    });
  });
});

// ════════════════════════════════════════════════════════
//  Gestão de Impostos
// ════════════════════════════════════════════════════════
(function(){
  const TAX_API  = '/api/taxes.php';
  const CSRF     = document.querySelector('[name=csrf_token]')?.value || '';
  const fmt      = v => 'R$ ' + Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
  const escHtml  = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const setEl    = (id,v) => { const e=document.getElementById(id); if(e) e.textContent=v; };

  // Base values from PHP (updated when taxes change)
  let _receita    = <?= json_encode(round($combined_receita, 2)) ?>;
  const _despBase = <?= json_encode(round($combined_despesa, 2)) ?>;
  let _taxes      = [];

  // ── Render tax rows ──────────────────────────────────────────────────────
  function renderTaxes() {
    const wrap = document.getElementById('taxRows');
    const strip = document.getElementById('taxSummaryStrip');
    if (!wrap) return;
    if (!_taxes.length) {
      wrap.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);font-size:.78rem;font-style:italic">Nenhum imposto cadastrado — clique em "Novo imposto" para começar</div>';
      if (strip) strip.style.display = 'none';
      return;
    }
    if (strip) strip.style.display = 'flex';
    let totalImpostos = 0, totalPct = 0;
    wrap.innerHTML = _taxes.map(t => {
      const pct  = Number(t.percentual);
      const val  = _receita * (pct / 100);
      totalImpostos += val;
      totalPct      += pct;
      return `<div class="tax-row">
        <div class="tax-name">${escHtml(t.nome)}</div>
        <div class="tax-pct">${pct.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})}%</div>
        <div class="tax-val">${fmt(val)}</div>
        <div class="tax-actions">
          <button class="btn btn-sm btn-edit" onclick="window._taxEdit(${t.id})">Editar</button>
          <button class="btn btn-sm btn-del"  onclick="window._taxDel(${t.id})">Excluir</button>
        </div>
      </div>`;
    }).join('');

    // Summary strip
    setEl('taxSummaryTotal', fmt(totalImpostos));
    setEl('taxSummaryPct',   totalPct.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}) + '%');
    setEl('taxSummaryBase',  fmt(_receita));

    updateKPIsWithTaxes(totalImpostos, totalPct);
  }

  // ── Update all KPI cards / health / summary ─────────────────────────────
  function updateKPIsWithTaxes(totalImpostos, totalPct) {
    const despTotal  = _despBase + totalImpostos;
    const lucro      = _receita - despTotal;
    const margem     = _receita > 0 ? (lucro / _receita) * 100 : 0;

    const strip = v => Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
    setEl('sumDespesa',  strip(despTotal));
    setEl('sumImpostos', strip(totalImpostos));
    setEl('sumResultado',strip(lucro));
    setEl('sumMargem',   margem.toLocaleString('pt-BR',{minimumFractionDigits:1,maximumFractionDigits:1}) + '%');
    setEl('impostoPercDesc', totalPct.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}) + '% da receita operacional');

    // Badges
    const lb = document.getElementById('lucrobadge');
    if (lb) { lb.innerHTML = lucro >= 0 ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Positivo' : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Negativo'; lb.className = 'kpi-badge ' + (lucro >= 0 ? 'badge-ok' : 'badge-danger'); }
    const mb = document.getElementById('margembadge');
    if (mb) { mb.textContent = margem >= 40 ? 'Saudável' : margem >= 15 ? 'Atenção' : 'Crítico'; mb.className = 'kpi-badge ' + (margem >= 40 ? 'badge-ok' : margem >= 15 ? 'badge-warn' : 'badge-danger'); }

    // Health bar
    const hb = document.getElementById('healthBar');
    if (hb) {
      const cls = margem >= 40 ? 'health-ok' : margem >= 15 ? 'health-warn' : 'health-danger';
      hb.className = 'health-bar ' + cls;
      setEl('healthIcon',  margem >= 40 ? '▲' : margem >= 15 ? '●' : '▼');
      setEl('healthLabel', margem >= 40 ? 'Saudável' : margem >= 15 ? 'Atenção' : 'Crítico');
      setEl('healthMeta',  'Margem real de ' + margem.toLocaleString('pt-BR',{minimumFractionDigits:1,maximumFractionDigits:1}) + '% · Meta recomendada: ≥ 40%');
    }

    // Executive summary
    const es = document.getElementById('execSummary');
    if (es) {
      const impostoInfo = totalImpostos > 0
        ? ` (incluindo <strong>${fmt(totalImpostos)}</strong> em impostos — alíquota total ${totalPct.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})}%)`
        : '';
      es.innerHTML = `No período consolidado, o escritório registra <strong>${fmt(_receita)}</strong> em receita operacional, <strong>${fmt(despTotal)}</strong> em custos totais${impostoInfo} e <strong>lucro líquido real de ${fmt(lucro)}</strong>, representando margem real de <strong>${margem.toLocaleString('pt-BR',{minimumFractionDigits:1,maximumFractionDigits:1})}%</strong>.`;
    }

    // Bar chart update
    try {
      if (window.dreCharts && window.dreCharts.bar) {
        window.dreCharts.bar.data.datasets[0].data = [_receita, despTotal, Math.max(0, lucro)];
        window.dreCharts.bar.update();
      }
    } catch(e) {}
  }

  // ── Load taxes from API ──────────────────────────────────────────────────
  async function loadTaxes() {
    try {
      const res = await fetch(TAX_API, {credentials:'same-origin'});
      const j   = await res.json();
      _taxes = j.data || [];
      renderTaxes();
    } catch(e) { console.error('loadTaxes', e); }
  }

  // ── Modal helpers ────────────────────────────────────────────────────────
  function updateTaxPreview() {
    const pct = parseFloat(String(document.getElementById('taxPercentual')?.value || '0').replace(',', '.')) || 0;
    const prev = document.getElementById('taxCalcPreview');
    if (prev) prev.textContent = pct > 0 ? fmt(_receita * (pct / 100)) : '—';
  }

  function openTaxModal(tax) {
    document.getElementById('taxId').value          = tax ? tax.id : '';
    document.getElementById('taxNome').value        = tax ? tax.nome : '';
    document.getElementById('taxPercentual').value  = tax ? Number(tax.percentual).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}) : '';
    document.getElementById('taxModalTitle').textContent = tax ? 'Editar imposto' : 'Novo imposto';
    document.getElementById('taxModalMsg').style.display = 'none';
    updateTaxPreview();
    document.getElementById('taxModal').style.display = 'flex';
    setTimeout(() => document.getElementById('taxNome')?.focus(), 80);
  }

  // Exposed for inline onclick
  window._taxEdit = id => openTaxModal(_taxes.find(t => t.id == id));
  window._taxDel  = async id => {
    if (!(await Yuris.confirm('Excluir este imposto?', { danger: true, okLabel: 'Excluir' }))) return;
    try {
      await fetch(TAX_API + '?id=' + id, {method:'DELETE',credentials:'same-origin',headers:{'X-CSRF-Token':CSRF}});
      loadTaxes();
    } catch(e) { console.error(e); }
  };

  // Add button
  document.getElementById('addTaxBtn')?.addEventListener('click', () => openTaxModal(null));

  // Live preview
  document.getElementById('taxPercentual')?.addEventListener('input', updateTaxPreview);

  // Save
  document.getElementById('saveTaxBtn')?.addEventListener('click', async () => {
    const id         = document.getElementById('taxId').value;
    const nome       = document.getElementById('taxNome').value.trim();
    const pctRaw     = String(document.getElementById('taxPercentual').value).replace(',', '.');
    const percentual = parseFloat(pctRaw);
    const msg        = document.getElementById('taxModalMsg');
    if (!nome)                                           { msg.textContent='Nome é obrigatório'; msg.style.display='block'; return; }
    if (isNaN(percentual)||percentual<0||percentual>100) { msg.textContent='Alíquota inválida (0 a 100)'; msg.style.display='block'; return; }
    msg.style.display = 'none';
    try {
      const body   = { nome, percentual };
      if (id) body.id = parseInt(id);
      const method = id ? 'PUT' : 'POST';
      const res    = await fetch(TAX_API, {method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(body)});
      const j      = await res.json();
      if (j.success || j.data) {
        document.getElementById('taxModal').style.display = 'none';
        loadTaxes();
      } else { msg.textContent = j.error || 'Erro ao salvar'; msg.style.display = 'block'; }
    } catch(e) { msg.textContent = 'Erro de rede'; msg.style.display = 'block'; }
  });

  document.addEventListener('DOMContentLoaded', loadTaxes);
})();

// ── Charts (Chart.js) — instâncias expostas globalmente para atualização dinâmica ──
window.dreCharts = {};
const _fmtR = v => 'R$ ' + Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2});
const _donutColors = ['#f59e0b','#60a5fa'];
const _donutLabels = ['Honorários (CRM)','Lançamentos DRE'];

window.dreUpdateDonutLegend = function(vals) {
  const legend = document.getElementById('donutLegend');
  if (!legend) return;
  legend.innerHTML = _donutLabels.map((lbl, i) =>
    `<div style="display:flex;align-items:center;gap:8px">
      <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:${_donutColors[i]};flex-shrink:0"></span>
      <div>
        <div style="font-size:.72rem;color:#c8e0f8;font-weight:600">${lbl}</div>
        <div style="font-size:.7rem;color:#9ab0c9">${_fmtR(vals[i])}</div>
      </div>
    </div>`
  ).join('');
};

(function(){
  const receita     = <?=json_encode(round($combined_receita, 2))?>;
  const despesa     = <?=json_encode(round($combined_despesa_total, 2))?>;
  const lucro       = <?=json_encode(round($resultado_real, 2))?>;
  const honorarios  = <?=json_encode(round($closed_total, 2))?>;
  const lancamentos = <?=json_encode(round((float)($summary['receita'] ?? 0), 2))?>;

  // Tema-aware: cores de eixo e grid adaptam ao tema atual
  const _DRE_IS_LIGHT = document.documentElement.getAttribute('data-theme') === 'light';
  const DRE_TICK   = _DRE_IS_LIGHT ? '#5A6B7E'              : '#9ab0c9';
  const DRE_GRID   = _DRE_IS_LIGHT ? 'rgba(15,31,54,0.08)'  : 'rgba(148,163,184,.1)';
  const DRE_GRID_Y = _DRE_IS_LIGHT ? 'rgba(15,31,54,0.06)'  : 'rgba(148,163,184,.08)';
  const DRE_LEGEND = _DRE_IS_LIGHT ? '#0F1F36'              : '#A8BDD4';
  if (typeof Chart !== 'undefined' && Chart.defaults) {
    Chart.defaults.color = DRE_LEGEND;
    if (Chart.defaults.plugins && Chart.defaults.plugins.legend && Chart.defaults.plugins.legend.labels) {
      Chart.defaults.plugins.legend.labels.color = DRE_LEGEND;
    }
  }

  // Bar chart
  const ctxBar = document.getElementById('chartBarRD');
  if (ctxBar) {
    window.dreCharts.bar = new Chart(ctxBar, {
      type: 'bar',
      data: {
        labels: ['Receita', 'Despesa', 'Lucro líquido'],
        datasets: [{ data: [receita, despesa, Math.max(0,lucro)], backgroundColor: ['rgba(16,185,129,.55)','rgba(239,68,68,.55)','rgba(59,130,246,.55)'], borderColor: ['#10b981','#ef4444','#3b82f6'], borderWidth: 1.5, borderRadius: 6 }]
      },
      options: {
        responsive: true, maintainAspectRatio: true,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ' ' + _fmtR(ctx.raw) } } },
        scales: {
          x: { grid: { color: DRE_GRID }, ticks: { color: DRE_TICK, font: { size: 11 } } },
          y: { grid: { color: DRE_GRID_Y }, ticks: { color: DRE_TICK, font: { size: 10 }, callback: v => 'R$' + (v/1000).toFixed(0) + 'k' } }
        }
      }
    });
  }

  // Donut chart
  const ctxD = document.getElementById('chartDonut');
  if (ctxD) {
    const donutData = [Math.max(0,honorarios), Math.max(0,lancamentos)];
    window.dreCharts.donut = new Chart(ctxD, {
      type: 'doughnut',
      data: { labels: _donutLabels, datasets: [{ data: donutData, backgroundColor: ['rgba(245,158,11,.65)','rgba(96,165,250,.65)'], borderColor: _donutColors, borderWidth: 1.5, hoverOffset: 4 }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ' ' + _fmtR(ctx.raw) } } } }
    });
    window.dreUpdateDonutLegend(donutData);
  }
})();
</script>
<script src="/assets/dre.js?v=<?=filemtime(__DIR__.'/assets/dre.js')?>"></script>
<script src="/assets/fog.js"></script>
</body>
</html>

