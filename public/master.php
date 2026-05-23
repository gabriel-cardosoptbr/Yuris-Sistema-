<?php
require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Models/Account.php';
require_once __DIR__ . '/../app/Models/ResourceShare.php';
require_once __DIR__ . '/../app/Helpers/AccountContext.php';

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /sistema_vendas/public/login.php'); exit; }

use App\Helpers\AccountContext;

$ctx = AccountContext::fromSession();
if (!$ctx->isSuperAdmin()) {
    http_response_code(403);
    echo '<html><body style="font-family:sans-serif;padding:40px;background:#0a1830;color:#dbe7f5">';
    echo '<h1>Acesso negado</h1><p>Apenas super administradores podem acessar o Painel Master.</p>';
    echo '<a href="/sistema_vendas/public/dashboard.php" style="color:#60a5fa">← Voltar ao Dashboard</a>';
    echo '</body></html>';
    exit;
}

$activePage = 'master';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$saLevel = $ctx->getSuperAdminLevel() ?: 'operator';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Painel Master — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/sistema_vendas/public/assets/favicon-192.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/sistema_vendas/public/assets/yuris-theme.css?v=27">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/sidebar.css?v=8">
  <style>
    body { font-family: Inter, system-ui, sans-serif; background:#070F1C; color:#D8E4F0; margin:0; }
    .mst-layout { display:grid; grid-template-columns:230px 1fr; min-height:100vh; gap:0; }
    .mst-content { padding:24px 32px; overflow-x:auto; }
    .mst-header { margin-bottom:20px; display:flex; align-items:flex-start; justify-content:space-between; gap:20px; flex-wrap:wrap; }
    .mst-title-block { flex:1; min-width:280px; }
    .mst-title { font-size:1.7rem; font-weight:800; color:#FFFFFF; letter-spacing:.01em; margin:0 0 4px; }
    .mst-sub   { font-size:.9rem; color:#9ab0c9; }
    .mst-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:.7rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
    .mst-badge-super    { background:rgba(168,85,247,.16); color:#c084fc; border:1px solid rgba(168,85,247,.40); }
    .mst-badge-operator { background:rgba(37,99,235,.16);  color:#93c5fd; border:1px solid rgba(37,99,235,.40); }
    .mst-badge-viewer   { background:rgba(124,139,160,.16); color:#A8BDD4; border:1px solid rgba(124,139,160,.40); }

    /* ── Topbar com busca global + ação principal ── */
    .mst-topbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; min-width:340px; }
    .mst-search-wrap { position:relative; flex:1; min-width:280px; }
    .mst-search { width:100%; padding:9px 12px 9px 36px; border-radius:8px; background:rgba(8,22,44,.9); border:1px solid rgba(96,165,250,.20); color:#D8E4F0; font-size:.85rem; outline:none; }
    .mst-search:focus { border-color:rgba(96,165,250,.5); box-shadow:0 0 0 3px rgba(96,165,250,.12); }
    .mst-search-ico { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#7a8898; pointer-events:none; }
    .mst-search-results { position:absolute; top:100%; left:0; right:0; margin-top:4px; max-height:360px; overflow:auto; background:rgba(8,22,44,.98); border:1px solid rgba(96,165,250,.20); border-radius:8px; box-shadow:0 8px 20px rgba(0,0,0,.30); z-index:50; display:none; }
    .mst-search-results.open { display:block; }
    .mst-search-row { padding:9px 14px; border-bottom:1px solid rgba(160,180,210,.06); cursor:pointer; }
    .mst-search-row:hover { background:rgba(37,99,235,.10); }
    .mst-search-row:last-child { border-bottom:none; }
    .mst-search-row .lbl { font-weight:600; color:#FFFFFF; font-size:.86rem; }
    .mst-search-row .sub { font-size:.72rem; color:#9ab0c9; margin-top:2px; }
    .mst-search-row .pill { float:right; }
    .mst-search-empty { padding:18px; text-align:center; color:#7a8898; font-style:italic; font-size:.82rem; }

    .mst-action-btn { padding:9px 16px; border-radius:8px; border:1px solid rgba(96,165,250,.40); background:rgba(37,99,235,.16); color:#FFFFFF; cursor:pointer; font-weight:600; font-size:.84rem; transition:all .15s; white-space:nowrap; }
    .mst-action-btn:hover { background:rgba(37,99,235,.28); border-color:rgba(96,165,250,.60); }
    .mst-action-btn-secondary { background:transparent; }

    /* ── Tabs ── */
    .mst-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:22px; border-bottom:1px solid rgba(160,180,210,0.10); padding-bottom:0; }
    .mst-tab  { padding:9px 18px; border-radius:8px 8px 0 0; border:1px solid transparent; border-bottom:none; background:transparent; color:#7a8898; cursor:pointer; font-weight:600; font-size:.86rem; transition:all .15s; }
    .mst-tab:hover { color:#A8BDD4; background:rgba(37,99,235,.08); }
    .mst-tab.active { color:#FFFFFF; background:linear-gradient(180deg,rgba(37,99,235,.18),rgba(37,99,235,.06)); border-color:rgba(96,165,250,.30); border-bottom-color:transparent; margin-bottom:-1px; }
    .mst-section { display:none; } .mst-section.active { display:block; }

    /* ── Cards & tabelas ── */
    .mst-grid-4 { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; }
    .mst-grid-5 { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:24px; }
    .mst-card { background:linear-gradient(145deg, rgba(14,35,65,.94), rgba(10,23,43,.96)); border:1px solid rgba(96,165,250,.12); border-radius:11px; padding:16px 18px; }
    .mst-kpi-label { font-size:.7rem; font-weight:700; color:#7a8898; text-transform:uppercase; letter-spacing:.07em; margin-bottom:6px; }
    .mst-kpi-value { font-size:1.5rem; font-weight:800; color:#FFFFFF; line-height:1.1; }
    .mst-kpi-foot  { font-size:.72rem; color:#9ab0c9; margin-top:6px; }

    table.mst-tbl { width:100%; border-collapse:collapse; font-size:.84rem; }
    .mst-tbl thead th { padding:9px 12px; text-align:left; font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; color:#7a8898; border-bottom:1px solid rgba(160,180,210,.10); font-weight:700; background:rgba(37,99,235,.04); }
    .mst-tbl tbody tr { border-bottom:1px solid rgba(160,180,210,.06); }
    .mst-tbl tbody tr:hover { background:rgba(37,99,235,.05); }
    .mst-tbl td { padding:11px 12px; vertical-align:middle; }
    .pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:.68rem; font-weight:700; }
    .pill-active     { background:rgba(22,163,74,.16);   color:#4ade80; border:1px solid rgba(22,163,74,.40); }
    .pill-trial      { background:rgba(124,58,237,.16);  color:#c4b5fd; border:1px solid rgba(124,58,237,.40); }
    .pill-trialing   { background:rgba(124,58,237,.16);  color:#c4b5fd; border:1px solid rgba(124,58,237,.40); }
    .pill-overdue    { background:rgba(220,38,38,.16);   color:#fca5a5; border:1px solid rgba(220,38,38,.40); }
    .pill-past_due   { background:rgba(220,38,38,.16);   color:#fca5a5; border:1px solid rgba(220,38,38,.40); }
    .pill-suspended  { background:rgba(217,119,6,.16);   color:#fbbf24; border:1px solid rgba(217,119,6,.40); }
    .pill-cancelled  { background:rgba(220,38,38,.10);   color:#fca5a5; border:1px solid rgba(220,38,38,.30); }
    .pill-canceled   { background:rgba(220,38,38,.10);   color:#fca5a5; border:1px solid rgba(220,38,38,.30); }
    .pill-inactive   { background:rgba(124,139,160,.16); color:#A8BDD4; border:1px solid rgba(124,139,160,.40); }
    .pill-paid       { background:rgba(22,163,74,.16);   color:#4ade80; border:1px solid rgba(22,163,74,.40); }
    .pill-open       { background:rgba(37,99,235,.16);   color:#93c5fd; border:1px solid rgba(37,99,235,.40); }
    .pill-void       { background:rgba(124,139,160,.16); color:#A8BDD4; border:1px solid rgba(124,139,160,.40); }
    .pill-uncollectible { background:rgba(220,38,38,.16); color:#fca5a5; border:1px solid rgba(220,38,38,.40); }
    .pill-matriz     { background:rgba(37,99,235,.16);   color:#93c5fd; border:1px solid rgba(37,99,235,.40); }
    .pill-filial     { background:rgba(168,85,247,.16);  color:#c084fc; border:1px solid rgba(168,85,247,.40); }
    .pill-advogado   { background:rgba(34,197,94,.16);   color:#86efac; border:1px solid rgba(34,197,94,.40); }
    .pill-usuario    { background:rgba(124,139,160,.16); color:#A8BDD4; border:1px solid rgba(124,139,160,.40); }

    .btn-mst { padding:6px 12px; border-radius:7px; border:1px solid rgba(160,180,210,.22); background:rgba(8,22,44,.6); color:#A8BDD4; cursor:pointer; font-size:.76rem; font-weight:600; transition:all .15s; }
    .btn-mst:hover { background:rgba(37,99,235,.16); color:#FFFFFF; border-color:rgba(96,165,250,.4); }
    .btn-mst-danger { border-color:rgba(220,38,38,.35); color:#fca5a5; }
    .btn-mst-danger:hover { background:rgba(220,38,38,.16); border-color:rgba(220,38,38,.6); color:#fff; }
    .btn-mst-success { border-color:rgba(22,163,74,.35); color:#86efac; }
    .btn-mst-success:hover { background:rgba(22,163,74,.16); border-color:rgba(22,163,74,.6); color:#fff; }
    .btn-mst-primary { border-color:rgba(96,165,250,.5); background:rgba(37,99,235,.16); color:#fff; }
    .btn-mst-primary:hover { background:rgba(37,99,235,.30); border-color:rgba(96,165,250,.7); }
    .empty { text-align:center; color:#7a8898; padding:32px; font-style:italic; }

    /* ── Modal ── */
    .mst-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.65); display:none; align-items:flex-start; justify-content:center; z-index:100; padding:40px 20px; overflow-y:auto; }
    .mst-modal-backdrop.open { display:flex; }
    .mst-modal { background:linear-gradient(145deg, #0E2341, #0A172B); border:1px solid rgba(96,165,250,.20); border-radius:13px; box-shadow:0 20px 40px rgba(0,0,0,.40); width:100%; max-width:680px; max-height:90vh; overflow-y:auto; }
    .mst-modal.lg { max-width:880px; }
    .mst-modal-header { display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:1px solid rgba(160,180,210,.10); }
    .mst-modal-title { font-size:1.05rem; font-weight:700; color:#FFFFFF; margin:0; }
    .mst-modal-close { background:transparent; border:none; color:#9ab0c9; font-size:1.5rem; cursor:pointer; line-height:1; padding:0 4px; }
    .mst-modal-close:hover { color:#fff; }
    .mst-modal-body { padding:20px 22px; }
    .mst-modal-foot { padding:14px 22px; border-top:1px solid rgba(160,180,210,.10); display:flex; gap:10px; justify-content:flex-end; }

    .mst-form-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin-bottom:12px; }
    .mst-form-row.full > * { grid-column: 1 / -1; }
    .mst-form-label { display:block; font-size:.74rem; font-weight:700; color:#9ab0c9; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
    .mst-form-input,
    .mst-form-select,
    .mst-form-textarea { width:100%; padding:8px 11px; border-radius:7px; background:rgba(5,18,39,.8); border:1px solid rgba(160,180,210,.18); color:#D8E4F0; font-size:.86rem; font-family:inherit; }
    .mst-form-input:focus,
    .mst-form-select:focus,
    .mst-form-textarea:focus { outline:none; border-color:rgba(96,165,250,.50); box-shadow:0 0 0 3px rgba(96,165,250,.12); }
    .mst-form-textarea { min-height:80px; resize:vertical; }
    .mst-form-section { font-size:.74rem; font-weight:700; color:#60a5fa; text-transform:uppercase; letter-spacing:.06em; margin:18px 0 10px; padding-bottom:6px; border-bottom:1px solid rgba(96,165,250,.15); }
    .mst-form-section:first-child { margin-top:0; }
    .mst-form-help { font-size:.72rem; color:#7a8898; margin-top:4px; }

    /* Detail rows */
    .mst-detail-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:18px; }
    .mst-detail-item { background:rgba(5,18,39,.5); border:1px solid rgba(160,180,210,.08); border-radius:8px; padding:10px 13px; }
    .mst-detail-item .label { font-size:.7rem; color:#7a8898; text-transform:uppercase; letter-spacing:.05em; font-weight:700; margin-bottom:3px; }
    .mst-detail-item .value { font-size:.92rem; color:#FFFFFF; word-break:break-word; }

    /* ── Light theme overrides ── */
    html[data-theme="light"] body { background:#F8FAFC; color:#0F1F36; }
    html[data-theme="light"] .mst-card { background:linear-gradient(145deg,#fff,#F1F5F9)!important; border-color:rgba(15,31,54,.10)!important; }
    html[data-theme="light"] .mst-kpi-label { color:#5A6B7E!important; }
    html[data-theme="light"] .mst-kpi-value { color:#0F1F36!important; }
    html[data-theme="light"] .mst-title { color:#0F1F36!important; }
    html[data-theme="light"] .mst-sub { color:#5A6B7E!important; }
    html[data-theme="light"] .mst-tbl thead th { background:rgba(37,99,235,.06)!important; color:#1E4A8A!important; }
    html[data-theme="light"] .mst-tbl tbody tr { border-color:#E2E8F0!important; }
    html[data-theme="light"] .mst-tbl tbody tr:hover { background:rgba(37,99,235,.05)!important; }
    html[data-theme="light"] .mst-tbl td { color:#0F1F36!important; }
    html[data-theme="light"] .mst-tab { color:#5A6B7E!important; }
    html[data-theme="light"] .mst-tab:hover { color:#1E4A8A!important; }
    html[data-theme="light"] .mst-tab.active { color:#1E4A8A!important; background:linear-gradient(180deg,rgba(37,99,235,.10),rgba(37,99,235,.02))!important; border-color:rgba(37,99,235,.30)!important; }
    html[data-theme="light"] .mst-search { background:#fff!important; border-color:#E2E8F0!important; color:#0F1F36!important; }
    html[data-theme="light"] .mst-search-results { background:#fff!important; border-color:#E2E8F0!important; }
    html[data-theme="light"] .mst-search-row { border-color:#F1F5F9!important; }
    html[data-theme="light"] .mst-search-row:hover { background:#F1F5F9!important; }
    html[data-theme="light"] .mst-search-row .lbl { color:#0F1F36!important; }
    html[data-theme="light"] .mst-search-row .sub { color:#5A6B7E!important; }
    html[data-theme="light"] .mst-modal { background:linear-gradient(145deg,#fff,#F8FAFC)!important; border-color:#E2E8F0!important; }
    html[data-theme="light"] .mst-modal-title { color:#0F1F36!important; }
    html[data-theme="light"] .mst-modal-close { color:#5A6B7E!important; }
    html[data-theme="light"] .mst-form-label { color:#5A6B7E!important; }
    html[data-theme="light"] .mst-form-input,
    html[data-theme="light"] .mst-form-select,
    html[data-theme="light"] .mst-form-textarea { background:#fff!important; border-color:#E2E8F0!important; color:#0F1F36!important; }
    html[data-theme="light"] .mst-form-section { color:#1E4A8A!important; border-color:rgba(37,99,235,.15)!important; }
    html[data-theme="light"] .mst-detail-item { background:#F8FAFC!important; border-color:#E2E8F0!important; }
    html[data-theme="light"] .mst-detail-item .value { color:#0F1F36!important; }
    html[data-theme="light"] .mst-detail-item .label { color:#5A6B7E!important; }
    html[data-theme="light"] .btn-mst { background:#fff!important; color:#0F1F36!important; border-color:#E2E8F0!important; }
    html[data-theme="light"] .btn-mst:hover { background:rgba(37,99,235,.08)!important; color:#1E4A8A!important; border-color:rgba(37,99,235,.30)!important; }
    html[data-theme="light"] .empty { color:#5A6B7E!important; }
  </style>
</head>
<body>
<?php $sidebarOmit = false; require __DIR__ . '/includes/sidebar.php'; ?>

<main class="mst-content" style="margin-left:230px;">
  <div class="mst-header">
    <div class="mst-title-block">
      <h1 class="mst-title">🛰️ Painel Master</h1>
      <p class="mst-sub">
        Visão global cross-tenant ·
        <span class="mst-badge mst-badge-<?=htmlspecialchars($saLevel)?>"><?=htmlspecialchars($saLevel)?></span>
      </p>
    </div>
    <div class="mst-topbar">
      <div class="mst-search-wrap">
        <svg class="mst-search-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="globalSearch" class="mst-search" placeholder="Buscar matriz, filial, advogado, e-mail, CNPJ, OAB..." autocomplete="off">
        <div id="searchResults" class="mst-search-results"></div>
      </div>
      <button class="mst-action-btn" onclick="openModalAccount()">+ Nova Conta</button>
      <button class="mst-action-btn mst-action-btn-secondary" onclick="openModalFilial()">+ Filial</button>
      <button class="mst-action-btn mst-action-btn-secondary" onclick="openModalAdvogado()">+ Advogado</button>
    </div>
  </div>

  <div class="mst-tabs">
    <button class="mst-tab active" data-mtab="overview">Visão Geral</button>
    <button class="mst-tab" data-mtab="accounts">Contas</button>
    <button class="mst-tab" data-mtab="plans">Planos</button>
    <button class="mst-tab" data-mtab="billing">Assinaturas</button>
    <button class="mst-tab" data-mtab="invoices">Faturas</button>
    <button class="mst-tab" data-mtab="payments">Pagamentos</button>
    <button class="mst-tab" data-mtab="audit">Auditoria</button>
  </div>

  <!-- ── Visão Geral ── -->
  <section class="mst-section active" id="msec-overview">
    <div class="mst-grid-5">
      <div class="mst-card"><div class="mst-kpi-label">Contas Ativas</div><div class="mst-kpi-value" id="kpiActive">—</div><div class="mst-kpi-foot" id="kpiActiveFoot">de — totais</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Em Trial</div><div class="mst-kpi-value" id="kpiTrial">—</div><div class="mst-kpi-foot">testando o sistema</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Em Atraso</div><div class="mst-kpi-value" id="kpiOverdue">—</div><div class="mst-kpi-foot">pagamento vencido</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Suspensas</div><div class="mst-kpi-value" id="kpiSuspended">—</div><div class="mst-kpi-foot">acesso bloqueado</div></div>
      <div class="mst-card"><div class="mst-kpi-label">MRR Projetado</div><div class="mst-kpi-value" id="kpiMrr">R$ —</div><div class="mst-kpi-foot">receita mensal</div></div>
    </div>

    <div class="mst-grid-5">
      <div class="mst-card"><div class="mst-kpi-label">Matrizes</div><div class="mst-kpi-value" id="kpiMatriz">—</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Filiais</div><div class="mst-kpi-value" id="kpiFilial">—</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Advogados</div><div class="mst-kpi-value" id="kpiAdv">—</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Usuários ativos</div><div class="mst-kpi-value" id="kpiUsersActive">—</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Faturas vencidas</div><div class="mst-kpi-value" id="kpiInvOverdue">—</div></div>
    </div>

    <div class="mst-card" style="padding:0; overflow:hidden; margin-bottom:18px">
      <div style="padding:14px 18px; font-weight:700; font-size:.9rem; border-bottom:1px solid rgba(160,180,210,.10)">Contas Recentes</div>
      <table class="mst-tbl">
        <thead><tr><th>Nome</th><th>Tipo</th><th>Status</th><th>Plano</th><th>Users</th><th>Adv.</th><th>Criada em</th><th></th></tr></thead>
        <tbody id="recentAccountsBody"><tr><td colspan="8" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>

    <div class="mst-grid-4" style="grid-template-columns:repeat(2,1fr);">
      <div class="mst-card" style="padding:0; overflow:hidden">
        <div style="padding:14px 18px; font-weight:700; font-size:.9rem; border-bottom:1px solid rgba(160,180,210,.10)">Em Trial</div>
        <table class="mst-tbl">
          <thead><tr><th>Nome</th><th>Termina</th><th></th></tr></thead>
          <tbody id="listTrialBody"><tr><td colspan="3" class="empty">—</td></tr></tbody>
        </table>
      </div>
      <div class="mst-card" style="padding:0; overflow:hidden">
        <div style="padding:14px 18px; font-weight:700; font-size:.9rem; border-bottom:1px solid rgba(160,180,210,.10)">Vencidas</div>
        <table class="mst-tbl">
          <thead><tr><th>Nome</th><th>Período até</th><th></th></tr></thead>
          <tbody id="listOverdueBody"><tr><td colspan="3" class="empty">—</td></tr></tbody>
        </table>
      </div>
      <div class="mst-card" style="padding:0; overflow:hidden">
        <div style="padding:14px 18px; font-weight:700; font-size:.9rem; border-bottom:1px solid rgba(160,180,210,.10)">Suspensas</div>
        <table class="mst-tbl">
          <thead><tr><th>Nome</th><th>Plano</th><th></th></tr></thead>
          <tbody id="listSuspendedBody"><tr><td colspan="3" class="empty">—</td></tr></tbody>
        </table>
      </div>
      <div class="mst-card" style="padding:0; overflow:hidden">
        <div style="padding:14px 18px; font-weight:700; font-size:.9rem; border-bottom:1px solid rgba(160,180,210,.10)">Por Plano</div>
        <table class="mst-tbl">
          <thead><tr><th>Plano</th><th>Active</th><th>Trial</th><th>Total</th></tr></thead>
          <tbody id="byPlanBody"><tr><td colspan="4" class="empty">—</td></tr></tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ── Contas ── -->
  <section class="mst-section" id="msec-accounts">
    <div class="mst-card" style="padding:0; overflow:hidden">
      <div style="display:flex; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10); flex-wrap:wrap">
        <div style="font-weight:700; margin-right:auto">Todas as Contas</div>
        <select id="filterAccStatus" class="mst-form-select" style="width:auto; padding:6px 11px; font-size:.82rem">
          <option value="">Todos status</option>
          <option value="active">Active</option>
          <option value="trial">Trial</option>
          <option value="overdue">Overdue</option>
          <option value="suspended">Suspended</option>
          <option value="cancelled">Cancelled</option>
          <option value="inactive">Inactive</option>
        </select>
        <select id="filterAccTipo" class="mst-form-select" style="width:auto; padding:6px 11px; font-size:.82rem">
          <option value="">Matriz e Filial</option>
          <option value="matriz">Só Matriz</option>
          <option value="filial">Só Filial</option>
        </select>
        <input id="filterAcc" placeholder="Buscar nome..." style="padding:6px 11px; border-radius:7px; background:rgba(5,18,39,.6); border:1px solid rgba(160,180,210,.18); color:#D8E4F0; font-size:.82rem; width:240px">
      </div>
      <table class="mst-tbl">
        <thead><tr><th>#</th><th>Nome</th><th>Tipo</th><th>Status</th><th>Plano</th><th>Cidade/UF</th><th>Users</th><th>Adv.</th><th>Assinatura</th><th>Ações</th></tr></thead>
        <tbody id="accountsBody"><tr><td colspan="10" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Planos ── -->
  <section class="mst-section" id="msec-plans">
    <div class="mst-card" style="padding:0; overflow:hidden">
      <div style="padding:14px 18px; font-weight:700; border-bottom:1px solid rgba(160,180,210,.10)">Planos Cadastrados</div>
      <table class="mst-tbl">
        <thead><tr><th>Slug</th><th>Nome</th><th>Mensal</th><th>Anual</th><th>Trial</th><th>Ativo</th><th>Assinaturas</th><th>Features</th></tr></thead>
        <tbody id="plansBody"><tr><td colspan="8" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Assinaturas ── -->
  <section class="mst-section" id="msec-billing">
    <div class="mst-card" style="padding:0; overflow:hidden">
      <div style="padding:14px 18px; font-weight:700; border-bottom:1px solid rgba(160,180,210,.10)">Assinaturas</div>
      <table class="mst-tbl">
        <thead><tr><th>Conta</th><th>Plano</th><th>Status</th><th>Ciclo</th><th>Trial até</th><th>Período até</th><th>Ações</th></tr></thead>
        <tbody id="subsBody"><tr><td colspan="7" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Faturas ── -->
  <section class="mst-section" id="msec-invoices">
    <div class="mst-card" style="padding:0; overflow:hidden">
      <div style="padding:14px 18px; font-weight:700; border-bottom:1px solid rgba(160,180,210,.10)">Faturas Recentes</div>
      <table class="mst-tbl">
        <thead><tr><th>#</th><th>Conta</th><th>Valor</th><th>Status</th><th>Vencimento</th><th>Pago em</th><th>Gateway</th></tr></thead>
        <tbody id="invoicesBody"><tr><td colspan="7" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Pagamentos (gestão manual) ── -->
  <section class="mst-section" id="msec-payments">
    <div class="mst-card" style="padding:0; overflow:hidden">
      <div style="display:flex; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10); flex-wrap:wrap">
        <div style="font-weight:700; margin-right:auto">Pagamentos / Gestão Manual</div>
        <select id="filterPayStatus" class="mst-form-select" style="width:auto; padding:6px 11px; font-size:.82rem">
          <option value="">Todos status</option>
          <option value="open">Em aberto</option>
          <option value="paid">Pagas</option>
          <option value="uncollectible">Inadimplentes</option>
          <option value="void">Anuladas</option>
        </select>
        <button class="btn-mst" onclick="payFilterOverdue()">Só vencidas</button>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>#</th><th>Conta</th><th>Plano</th><th>Valor</th><th>Status</th><th>Vencimento</th><th>Pago em</th><th>Obs.</th><th>Ações</th></tr></thead>
        <tbody id="paymentsBody"><tr><td colspan="9" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Auditoria ── -->
  <section class="mst-section" id="msec-audit">
    <div class="mst-card" style="padding:0; overflow:hidden">
      <div style="display:flex; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10); flex-wrap:wrap">
        <div style="font-weight:700; margin-right:auto">Log de Auditoria</div>
        <input id="filterAuditAcao" placeholder="Filtrar por ação (ex: account.create)..." style="padding:6px 11px; border-radius:7px; background:rgba(5,18,39,.6); border:1px solid rgba(160,180,210,.18); color:#D8E4F0; font-size:.82rem; width:280px">
      </div>
      <table class="mst-tbl">
        <thead><tr><th>Quando</th><th>Operador</th><th>Ação</th><th>Alvo</th><th>Descrição</th><th>IP</th></tr></thead>
        <tbody id="auditBody"><tr><td colspan="6" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>
</main>

<!-- ───────────────────────────────────────────────────────────────────────
     MODAIS
─────────────────────────────────────────────────────────────────────── -->

<!-- Modal: Nova Conta -->
<div class="mst-modal-backdrop" id="modalAccount" onclick="if(event.target===this)closeModal('modalAccount')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">Nova Conta (Matriz)</h3>
      <button class="mst-modal-close" onclick="closeModal('modalAccount')">×</button>
    </div>
    <form id="formAccount" onsubmit="submitAccount(event)">
      <div class="mst-modal-body">
        <div class="mst-form-section">Dados da Matriz</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Nome *</label><input name="account_nome" class="mst-form-input" required></div>
          <div><label class="mst-form-label">Razão Social</label><input name="account_razao" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">CNPJ</label><input name="account_cnpj" class="mst-form-input" placeholder="apenas dígitos"></div>
          <div><label class="mst-form-label">E-mail</label><input name="account_email" class="mst-form-input" type="email"></div>
          <div><label class="mst-form-label">Telefone</label><input name="account_tel" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Cidade</label><input name="account_cidade" class="mst-form-input"></div>
          <div><label class="mst-form-label">UF</label><input name="account_uf" class="mst-form-input" maxlength="2" style="text-transform:uppercase"></div>
          <div><label class="mst-form-label">Status inicial</label>
            <select name="account_status" class="mst-form-select">
              <option value="trial" selected>Trial (período de teste)</option>
              <option value="active">Active (já paga)</option>
            </select>
          </div>
        </div>

        <div class="mst-form-section">Administrador da Conta</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Nome *</label><input name="adm_nome" class="mst-form-input" required></div>
          <div><label class="mst-form-label">E-mail (login) *</label><input name="adm_email" class="mst-form-input" type="email" required></div>
          <div><label class="mst-form-label">Telefone</label><input name="adm_tel" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Senha (opcional)</label>
            <input name="adm_senha" class="mst-form-input" type="text" placeholder="deixe vazio pra gerar automaticamente">
            <div class="mst-form-help">Se vazio, gera senha temporária aleatória.</div>
          </div>
        </div>

        <div class="mst-form-section">Plano e Assinatura</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Plano *</label>
            <select name="sub_plan_id" class="mst-form-select" id="selPlan" required></select>
          </div>
          <div><label class="mst-form-label">Ciclo</label>
            <select name="sub_cycle" class="mst-form-select">
              <option value="monthly">Mensal</option>
              <option value="yearly">Anual</option>
            </select>
          </div>
          <div><label class="mst-form-label">Trial (dias)</label><input name="sub_trial_dias" class="mst-form-input" type="number" min="0" placeholder="usa do plano se vazio"></div>
        </div>
      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst" onclick="closeModal('modalAccount')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Criar Conta</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Nova Filial -->
<div class="mst-modal-backdrop" id="modalFilial" onclick="if(event.target===this)closeModal('modalFilial')">
  <div class="mst-modal">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">Nova Filial</h3>
      <button class="mst-modal-close" onclick="closeModal('modalFilial')">×</button>
    </div>
    <form id="formFilial" onsubmit="submitFilial(event)">
      <div class="mst-modal-body">
        <div class="mst-form-row full">
          <div>
            <label class="mst-form-label">Matriz vinculada *</label>
            <select name="matriz_id" class="mst-form-select" id="selMatriz" required></select>
          </div>
        </div>
        <div class="mst-form-section">Dados da Filial</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Nome *</label><input name="fil_nome" class="mst-form-input" required></div>
          <div><label class="mst-form-label">Razão Social</label><input name="fil_razao" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">CNPJ</label><input name="fil_cnpj" class="mst-form-input"></div>
          <div><label class="mst-form-label">E-mail</label><input name="fil_email" class="mst-form-input" type="email"></div>
          <div><label class="mst-form-label">Telefone</label><input name="fil_tel" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Cidade</label><input name="fil_cidade" class="mst-form-input"></div>
          <div><label class="mst-form-label">UF</label><input name="fil_uf" class="mst-form-input" maxlength="2" style="text-transform:uppercase"></div>
        </div>

        <div class="mst-form-section">Admin da Filial (opcional)</div>
        <div class="mst-form-help" style="margin-bottom:10px">Se preencher, será criado um usuário admin pra esta filial. Caso contrário, ela compartilha do escopo da matriz.</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Nome</label><input name="adm_nome" class="mst-form-input"></div>
          <div><label class="mst-form-label">E-mail</label><input name="adm_email" class="mst-form-input" type="email"></div>
        </div>
      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst" onclick="closeModal('modalFilial')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Criar Filial</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Novo Advogado -->
<div class="mst-modal-backdrop" id="modalAdvogado" onclick="if(event.target===this)closeModal('modalAdvogado')">
  <div class="mst-modal">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">Novo Advogado</h3>
      <button class="mst-modal-close" onclick="closeModal('modalAdvogado')">×</button>
    </div>
    <form id="formAdvogado" onsubmit="submitAdvogado(event)">
      <div class="mst-modal-body">
        <div class="mst-form-row">
          <div><label class="mst-form-label">Conta vinculada *</label>
            <select name="account_id" class="mst-form-select" id="selAdvAccount" required></select>
          </div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Nome completo *</label><input name="nome" class="mst-form-input" required></div>
          <div><label class="mst-form-label">E-mail *</label><input name="email" class="mst-form-input" type="email" required></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">OAB</label><input name="oab" class="mst-form-input"></div>
          <div><label class="mst-form-label">UF da OAB</label><input name="oab_uf" class="mst-form-input" maxlength="2" style="text-transform:uppercase"></div>
          <div><label class="mst-form-label">Telefone</label><input name="telefone" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Senha (opcional)</label>
            <input name="senha" class="mst-form-input" type="text" placeholder="deixe vazio pra gerar automaticamente">
            <div class="mst-form-help">O código ADV-XXXXXX é gerado automaticamente.</div>
          </div>
        </div>
      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst" onclick="closeModal('modalAdvogado')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Criar Advogado</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Drill-down genérico (Detalhe) -->
<div class="mst-modal-backdrop" id="modalDetalhe" onclick="if(event.target===this)closeModal('modalDetalhe')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title" id="detalheTitle">Detalhes</h3>
      <button class="mst-modal-close" onclick="closeModal('modalDetalhe')">×</button>
    </div>
    <div class="mst-modal-body" id="detalheBody">Carregando…</div>
    <div class="mst-modal-foot" id="detalheFoot"></div>
  </div>
</div>

<!-- Modal: Marcar pagamento manual -->
<div class="mst-modal-backdrop" id="modalPay" onclick="if(event.target===this)closeModal('modalPay')">
  <div class="mst-modal">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">Atualizar Pagamento Manual</h3>
      <button class="mst-modal-close" onclick="closeModal('modalPay')">×</button>
    </div>
    <form id="formPay" onsubmit="submitPay(event)">
      <input type="hidden" name="id" id="payId">
      <div class="mst-modal-body">
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Novo Status</label>
            <select name="status" class="mst-form-select">
              <option value="">(não alterar)</option>
              <option value="paid">Marcar como PAGO</option>
              <option value="uncollectible">Marcar como VENCIDA</option>
              <option value="void">Anular</option>
              <option value="open">Reabrir</option>
            </select>
          </div>
          <div>
            <label class="mst-form-label">Vencimento</label>
            <input name="due_date" class="mst-form-input" type="date">
          </div>
        </div>
        <div class="mst-form-row full">
          <div>
            <label class="mst-form-label">Observações</label>
            <textarea name="observacoes" class="mst-form-textarea" placeholder="motivo da alteração, observações de cobrança..."></textarea>
          </div>
        </div>
      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst" onclick="closeModal('modalPay')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Salvar</button>
      </div>
    </form>
  </div>
</div>

<script>
const CSRF = '<?=htmlspecialchars($csrf)?>';
const API  = '/sistema_vendas/public/api/master';
const fmtBRL = v => 'R$ ' + Number((v||0)/100).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
const fmtDate = v => v ? new Date((v+'').replace(' ','T')).toLocaleDateString('pt-BR') : '—';
const fmtDateTime = v => v ? new Date((v+'').replace(' ','T')).toLocaleString('pt-BR') : '—';
const pill = (s) => `<span class="pill pill-${s||'cancel'}">${s||'?'}</span>`;
const esc  = (s) => (s == null ? '' : String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]));

async function fj(url, opts={}) {
  const r = await fetch(url, {credentials:'same-origin', ...opts});
  let j; try { j = await r.json(); } catch(e){ j = {ok:false, error: 'JSON inválido', http: r.status}; }
  if (!r.ok && j.ok !== false) j.ok = false;
  return j;
}

function notifyErr(msg) {
  if (window.Yuris && Yuris.notify) Yuris.notify(msg, {type:'error'});
  else console.error(msg);
}
function notifyOk(msg) {
  if (window.Yuris && Yuris.notify) Yuris.notify(msg, {type:'success'});
}

// ── Hash routing ─────────────────────────────────────────────────────────
const TABS = ['overview','accounts','plans','billing','invoices','payments','audit'];
function activateTab(name) {
  if (!TABS.includes(name)) name = 'overview';
  document.querySelectorAll('.mst-tab').forEach(t => t.classList.toggle('active', t.dataset.mtab === name));
  document.querySelectorAll('.mst-section').forEach(s => s.classList.toggle('active', s.id === 'msec-'+name));
  loadTab(name);
}
function loadTab(name) {
  if (name==='overview') loadOverview();
  if (name==='accounts') loadAccounts();
  if (name==='plans')    loadPlans();
  if (name==='billing')  loadBilling();
  if (name==='invoices') loadInvoices();
  if (name==='payments') loadPayments();
  if (name==='audit')    loadAudit();
}
document.querySelectorAll('.mst-tab').forEach(b => b.addEventListener('click', () => {
  window.location.hash = b.dataset.mtab;
}));
window.addEventListener('hashchange', () => activateTab((location.hash||'').replace('#','')));

// ── Modais ───────────────────────────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

async function openModalAccount() {
  // Carrega lista de planos
  const r = await fj(`${API}/plans.php`);
  const sel = document.getElementById('selPlan');
  sel.innerHTML = '';
  if (r.ok && r.data.plans) {
    r.data.plans.filter(p => p.ativo == 1).forEach(p => {
      const o = document.createElement('option');
      o.value = p.id;
      o.textContent = `${p.nome} — ${fmtBRL(p.preco_mensal_cents)}/mês`;
      sel.appendChild(o);
    });
  }
  document.getElementById('formAccount').reset();
  openModal('modalAccount');
}

async function openModalFilial(matrizId) {
  const r = await fj(`${API}/accounts.php?tipo=matriz`);
  const sel = document.getElementById('selMatriz');
  sel.innerHTML = '';
  if (r.ok && r.data.accounts) {
    r.data.accounts.filter(a => a.tipo === 'matriz').forEach(a => {
      const o = document.createElement('option');
      o.value = a.id;
      o.textContent = `${a.nome} (#${a.id})`;
      if (matrizId && a.id == matrizId) o.selected = true;
      sel.appendChild(o);
    });
  }
  document.getElementById('formFilial').reset();
  if (matrizId) sel.value = matrizId;
  openModal('modalFilial');
}

async function openModalAdvogado(accountId) {
  const r = await fj(`${API}/accounts.php`);
  const sel = document.getElementById('selAdvAccount');
  sel.innerHTML = '';
  if (r.ok && r.data.accounts) {
    r.data.accounts.forEach(a => {
      const o = document.createElement('option');
      o.value = a.id;
      o.textContent = `[${a.tipo === 'matriz' ? 'M' : 'F'}] ${a.nome} (#${a.id})`;
      sel.appendChild(o);
    });
  }
  document.getElementById('formAdvogado').reset();
  if (accountId) sel.value = accountId;
  openModal('modalAdvogado');
}

// ── Submit handlers ──────────────────────────────────────────────────────
async function submitAccount(ev) {
  ev.preventDefault();
  const f = ev.target;
  const body = {
    csrf_token: CSRF,
    account: {
      nome:         f.account_nome.value.trim(),
      razao_social: f.account_razao.value.trim(),
      cnpj:         f.account_cnpj.value.trim(),
      email:        f.account_email.value.trim(),
      telefone:     f.account_tel.value.trim(),
      cidade:       f.account_cidade.value.trim(),
      estado:       f.account_uf.value.trim().toUpperCase(),
      status:       f.account_status.value,
    },
    admin: {
      nome:     f.adm_nome.value.trim(),
      email:    f.adm_email.value.trim(),
      senha:    f.adm_senha.value,
      telefone: f.adm_tel.value.trim(),
    },
    subscription: {
      plan_id:       parseInt(f.sub_plan_id.value, 10),
      billing_cycle: f.sub_cycle.value,
      trial_dias:    f.sub_trial_dias.value ? parseInt(f.sub_trial_dias.value, 10) : undefined,
    }
  };
  const r = await fj(`${API}/create_account.php`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': CSRF},
    body: JSON.stringify(body),
  });
  if (!r.ok) return notifyErr(r.error || 'Falha ao criar conta');
  closeModal('modalAccount');
  notifyOk('Conta criada com sucesso!');
  if (r.data.senha_gerada) {
    Yuris.notify(`Senha temporária gerada: ${r.data.senha_gerada}`, {type:'info', duration:12000});
  }
  loadTab('accounts');
  activateTab('accounts');
  window.location.hash = 'accounts';
}

async function submitFilial(ev) {
  ev.preventDefault();
  const f = ev.target;
  const body = {
    csrf_token: CSRF,
    matriz_id: parseInt(f.matriz_id.value, 10),
    filial: {
      nome:         f.fil_nome.value.trim(),
      razao_social: f.fil_razao.value.trim(),
      cnpj:         f.fil_cnpj.value.trim(),
      email:        f.fil_email.value.trim(),
      telefone:     f.fil_tel.value.trim(),
      cidade:       f.fil_cidade.value.trim(),
      estado:       f.fil_uf.value.trim().toUpperCase(),
    }
  };
  if (f.adm_nome.value.trim() && f.adm_email.value.trim()) {
    body.admin = { nome: f.adm_nome.value.trim(), email: f.adm_email.value.trim() };
  }
  const r = await fj(`${API}/create_filial.php`, {
    method:'POST',
    headers:{'Content-Type':'application/json', 'X-CSRF-Token':CSRF},
    body: JSON.stringify(body)
  });
  if (!r.ok) return notifyErr(r.error || 'Falha ao criar filial');
  closeModal('modalFilial');
  notifyOk('Filial criada com sucesso!');
  if (r.data.senha_gerada) {
    Yuris.notify(`Senha temporária do admin: ${r.data.senha_gerada}`, {type:'info', duration:12000});
  }
  loadAccounts();
}

async function submitAdvogado(ev) {
  ev.preventDefault();
  const f = ev.target;
  const body = {
    csrf_token: CSRF,
    account_id: parseInt(f.account_id.value, 10),
    nome:       f.nome.value.trim(),
    email:      f.email.value.trim(),
    oab:        f.oab.value.trim(),
    oab_uf:     f.oab_uf.value.trim().toUpperCase(),
    telefone:   f.telefone.value.trim(),
    senha:      f.senha.value,
  };
  const r = await fj(`${API}/advogados.php`, {
    method:'POST',
    headers:{'Content-Type':'application/json', 'X-CSRF-Token':CSRF},
    body: JSON.stringify(body)
  });
  if (!r.ok) return notifyErr(r.error || 'Falha ao criar advogado');
  closeModal('modalAdvogado');
  notifyOk(`Advogado criado! Código: ${r.data.codigo_advogado}`);
  if (r.data.senha_gerada) {
    Yuris.notify(`Senha temporária: ${r.data.senha_gerada}`, {type:'info', duration:12000});
  }
}

// ── Drill-down detalhes ──────────────────────────────────────────────────
async function viewAcc(id) {
  document.getElementById('detalheTitle').textContent = 'Carregando…';
  document.getElementById('detalheBody').innerHTML = 'Carregando…';
  document.getElementById('detalheFoot').innerHTML = '';
  openModal('modalDetalhe');

  const r = await fj(`${API}/accounts.php?id=${id}`);
  if (!r.ok) return notifyErr(r.error);
  const d = r.data;
  const isMatriz = d.tipo === 'matriz';

  document.getElementById('detalheTitle').innerHTML =
    `${isMatriz ? '🏢' : '🏬'} ${esc(d.nome)} <span class="pill pill-${esc(d.tipo)}" style="margin-left:8px;font-size:.6rem">${esc(d.tipo)}</span> ${pill(d.status)}`;

  let sub = d.subscription || {};
  let html = '';
  html += `<div class="mst-detail-grid">
    <div class="mst-detail-item"><div class="label">Razão Social</div><div class="value">${esc(d.razao_social||'—')}</div></div>
    <div class="mst-detail-item"><div class="label">CNPJ</div><div class="value">${esc(d.cnpj||'—')}</div></div>
    <div class="mst-detail-item"><div class="label">E-mail</div><div class="value">${esc(d.email||'—')}</div></div>
    <div class="mst-detail-item"><div class="label">Telefone</div><div class="value">${esc(d.telefone||'—')}</div></div>
    <div class="mst-detail-item"><div class="label">Cidade / UF</div><div class="value">${esc(d.cidade||'—')} ${d.estado?'/ '+esc(d.estado):''}</div></div>
    <div class="mst-detail-item"><div class="label">Código vínculo</div><div class="value" style="font-family:ui-monospace,monospace;font-size:.78rem">${esc(d.codigo_vinculo||'—')}</div></div>
    <div class="mst-detail-item"><div class="label">Plano (cache)</div><div class="value">${esc(d.plano||'—')}</div></div>
    <div class="mst-detail-item"><div class="label">Criada em</div><div class="value">${fmtDateTime(d.created_at)}</div></div>
  </div>`;

  html += `<div class="mst-form-section">Assinatura</div>`;
  if (sub && sub.id) {
    html += `<div class="mst-detail-grid">
      <div class="mst-detail-item"><div class="label">Plano</div><div class="value">${esc(sub.plan_nome||'—')} (${esc(sub.plan_slug||'')})</div></div>
      <div class="mst-detail-item"><div class="label">Status</div><div class="value">${pill(sub.status)}</div></div>
      <div class="mst-detail-item"><div class="label">Ciclo</div><div class="value">${esc(sub.billing_cycle||'—')}</div></div>
      <div class="mst-detail-item"><div class="label">Trial até</div><div class="value">${fmtDate(sub.trial_ends_at)}</div></div>
      <div class="mst-detail-item"><div class="label">Período até</div><div class="value">${fmtDate(sub.current_period_end)}</div></div>
    </div>`;
  } else {
    html += `<div class="empty">Sem assinatura ativa</div>`;
  }

  html += `<div class="mst-form-section">Volume</div>
  <div class="mst-detail-grid">
    <div class="mst-detail-item"><div class="label">Usuários</div><div class="value">${d.users_count||0}</div></div>
    <div class="mst-detail-item"><div class="label">Processos</div><div class="value">${d.processos_count||0}</div></div>
    <div class="mst-detail-item"><div class="label">Cards</div><div class="value">${d.cards_count||0}</div></div>
  </div>`;

  if (d.users && d.users.length) {
    html += `<div class="mst-form-section">Usuários (${d.users.length})</div>
    <table class="mst-tbl"><thead><tr><th>Nome</th><th>E-mail</th><th>Role</th><th>Status</th></tr></thead><tbody>`;
    d.users.forEach(u => {
      html += `<tr><td>${esc(u.nome)}</td><td>${esc(u.email)}</td><td>${esc(u.role||u.perfil)}</td><td>${pill(u.status)}</td></tr>`;
    });
    html += `</tbody></table>`;
  }

  if (d.invoices && d.invoices.length) {
    html += `<div class="mst-form-section">Faturas recentes</div>
    <table class="mst-tbl"><thead><tr><th>#</th><th>Valor</th><th>Status</th><th>Vencimento</th><th>Pago em</th></tr></thead><tbody>`;
    d.invoices.forEach(i => {
      html += `<tr><td>#${i.id}</td><td>${fmtBRL(i.amount_cents)}</td><td>${pill(i.status)}</td><td>${fmtDate(i.due_date)}</td><td>${fmtDate(i.paid_at)}</td></tr>`;
    });
    html += `</tbody></table>`;
  }

  document.getElementById('detalheBody').innerHTML = html;

  // Footer actions
  let foot = '';
  if (isMatriz) {
    foot += `<button class="btn-mst" onclick="openModalFilial(${d.id})">+ Filial</button>`;
    foot += `<button class="btn-mst" onclick="openModalAdvogado(${d.id})">+ Advogado</button>`;
  }
  if (d.status === 'active' || d.status === 'trial') {
    foot += `<button class="btn-mst btn-mst-danger" onclick="setStatus(${d.id},'suspended')">Suspender</button>`;
  }
  if (d.status === 'suspended') {
    foot += `<button class="btn-mst btn-mst-success" onclick="setStatus(${d.id},'active')">Reativar</button>`;
  }
  foot += `<button class="btn-mst" onclick="closeModal('modalDetalhe')">Fechar</button>`;
  document.getElementById('detalheFoot').innerHTML = foot;
}

async function viewAdvogado(id) {
  document.getElementById('detalheTitle').textContent = 'Carregando…';
  document.getElementById('detalheBody').innerHTML = 'Carregando…';
  document.getElementById('detalheFoot').innerHTML = '';
  openModal('modalDetalhe');

  const r = await fj(`${API}/advogados.php?id=${id}`);
  if (!r.ok) return notifyErr(r.error);
  const d = r.data;

  document.getElementById('detalheTitle').innerHTML =
    `⚖️ ${esc(d.nome)} <span class="pill pill-advogado" style="margin-left:8px;font-size:.6rem">advogado</span> ${pill(d.status)}`;

  document.getElementById('detalheBody').innerHTML = `
    <div class="mst-detail-grid">
      <div class="mst-detail-item"><div class="label">E-mail</div><div class="value">${esc(d.email)}</div></div>
      <div class="mst-detail-item"><div class="label">Telefone</div><div class="value">${esc(d.telefone||'—')}</div></div>
      <div class="mst-detail-item"><div class="label">OAB</div><div class="value">${esc(d.oab||'—')} ${d.oab_uf?'/ '+esc(d.oab_uf):''}</div></div>
      <div class="mst-detail-item"><div class="label">Código</div><div class="value" style="font-family:ui-monospace,monospace">${esc(d.codigo_advogado||'—')}</div></div>
      <div class="mst-detail-item"><div class="label">Conta vinculada</div><div class="value">${esc(d.account_nome||'—')} <span class="pill pill-${esc(d.account_tipo)}">${esc(d.account_tipo)}</span></div></div>
      ${d.matriz_nome ? `<div class="mst-detail-item"><div class="label">Matriz</div><div class="value">${esc(d.matriz_nome)}</div></div>` : ''}
      <div class="mst-detail-item"><div class="label">Criado em</div><div class="value">${fmtDateTime(d.created_at)}</div></div>
    </div>`;

  let foot = '';
  if (d.status === 'active') {
    foot += `<button class="btn-mst btn-mst-danger" onclick="setAdvogadoStatus(${d.id},'inactive')">Inativar</button>`;
  } else {
    foot += `<button class="btn-mst btn-mst-success" onclick="setAdvogadoStatus(${d.id},'active')">Ativar</button>`;
  }
  foot += `<button class="btn-mst" onclick="closeModal('modalDetalhe')">Fechar</button>`;
  document.getElementById('detalheFoot').innerHTML = foot;
}

async function setStatus(id, status) {
  if (!(await Yuris.confirm(`Mudar status pra "${status}"?`, {okLabel: 'Confirmar', danger: status === 'suspended'}))) return;
  const r = await fj(`${API}/accounts.php`, {
    method:'PATCH', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({id, status, csrf_token: CSRF})
  });
  if (!r.ok) return notifyErr(r.error);
  notifyOk('Status atualizado');
  closeModal('modalDetalhe');
  loadAccounts();
  loadOverview();
}

async function setAdvogadoStatus(id, status) {
  const r = await fj(`${API}/advogados.php`, {
    method:'PATCH', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({id, status, csrf_token: CSRF})
  });
  if (!r.ok) return notifyErr(r.error);
  notifyOk('Status atualizado');
  closeModal('modalDetalhe');
}

// ── Busca global ─────────────────────────────────────────────────────────
let _searchTimer;
document.getElementById('globalSearch').addEventListener('input', (ev) => {
  clearTimeout(_searchTimer);
  const q = ev.target.value.trim();
  if (q.length < 2) {
    document.getElementById('searchResults').classList.remove('open');
    return;
  }
  _searchTimer = setTimeout(() => doGlobalSearch(q), 250);
});
document.addEventListener('click', (ev) => {
  if (!ev.target.closest('.mst-search-wrap')) {
    document.getElementById('searchResults').classList.remove('open');
  }
});

async function doGlobalSearch(q) {
  const r = await fj(`${API}/search.php?q=${encodeURIComponent(q)}`);
  const box = document.getElementById('searchResults');
  box.classList.add('open');
  if (!r.ok) {
    box.innerHTML = `<div class="mst-search-empty">${esc(r.error||'erro na busca')}</div>`;
    return;
  }
  const rows = r.data.results || [];
  if (rows.length === 0) {
    box.innerHTML = `<div class="mst-search-empty">Nenhum resultado encontrado.</div>`;
    return;
  }
  box.innerHTML = rows.map(x => {
    const onclick = (x.type === 'matriz' || x.type === 'filial')
      ? `viewAcc(${x.id})`
      : (x.type === 'advogado' ? `viewAdvogado(${x.id})` : `viewAcc(${x.account_id})`);
    return `<div class="mst-search-row" onclick="${onclick};document.getElementById('searchResults').classList.remove('open')">
      <span class="pill pill-${esc(x.type)}">${esc(x.type)}</span>
      <div class="lbl">${esc(x.label)}</div>
      <div class="sub">${esc(x.sublabel)}</div>
    </div>`;
  }).join('');
}

// ── Loaders ──────────────────────────────────────────────────────────────
async function loadOverview() {
  const r = await fj(`${API}/dashboard.php`);
  if (!r.ok) return;
  const d = r.data;
  const k = d.kpis;

  document.getElementById('kpiActive').textContent     = k.accounts.active;
  document.getElementById('kpiActiveFoot').textContent = `de ${k.accounts.total} totais`;
  document.getElementById('kpiTrial').textContent      = k.accounts.trial + k.subscriptions.trialing;
  document.getElementById('kpiOverdue').textContent    = k.accounts.overdue + k.subscriptions.past_due;
  document.getElementById('kpiSuspended').textContent  = k.accounts.suspended;
  document.getElementById('kpiMrr').textContent        = 'R$ ' + d.mrr_brl;
  document.getElementById('kpiMatriz').textContent     = k.accounts.matriz;
  document.getElementById('kpiFilial').textContent     = k.accounts.filial;
  document.getElementById('kpiAdv').textContent        = k.users.advogados;
  document.getElementById('kpiUsersActive').textContent= k.users.active;
  document.getElementById('kpiInvOverdue').textContent = k.invoices.overdue;

  // recentes
  renderListAccounts('recentAccountsBody', d.listas.recentes, true);
  renderListSmall('listTrialBody', d.listas.trial, 'trial_ends_at');
  renderListSmall('listOverdueBody', d.listas.vencidas, 'current_period_end');
  renderListSmall('listSuspendedBody', d.listas.suspensas, 'plan_nome');

  // breakdown
  const tb = document.getElementById('byPlanBody');
  if (!d.plans || !d.plans.length) tb.innerHTML = '<tr><td colspan="4" class="empty">—</td></tr>';
  else tb.innerHTML = d.plans.map(p =>
    `<tr><td><strong>${esc(p.nome)}</strong> <small style="color:#9ab0c9">${esc(p.slug)}</small></td><td>${p.active_count||0}</td><td>${p.trial_count||0}</td><td>${p.total_subscriptions||0}</td></tr>`
  ).join('');
}

function renderListAccounts(elId, list, full) {
  const tb = document.getElementById(elId);
  if (!list || !list.length) { tb.innerHTML = `<tr><td colspan="${full?8:3}" class="empty">Nenhuma conta</td></tr>`; return; }
  tb.innerHTML = list.map(a => `
    <tr>
      <td><strong>${esc(a.nome)}</strong></td>
      <td><span class="pill pill-${esc(a.tipo)}">${esc(a.tipo)}</span></td>
      <td>${pill(a.status)}</td>
      <td>${esc(a.plan_nome || a.plan_slug || '—')}</td>
      <td>${a.users_count||0}</td>
      <td>${a.advogados_count||0}</td>
      <td>${fmtDate(a.created_at)}</td>
      <td><button class="btn-mst" onclick="viewAcc(${a.id})">Ver</button></td>
    </tr>`).join('');
}

function renderListSmall(elId, list, dateField) {
  const tb = document.getElementById(elId);
  if (!list || !list.length) { tb.innerHTML = '<tr><td colspan="3" class="empty">—</td></tr>'; return; }
  tb.innerHTML = list.slice(0,5).map(a =>
    `<tr><td>${esc(a.nome)}</td><td>${dateField === 'plan_nome' ? esc(a.plan_nome||'—') : fmtDate(a[dateField])}</td><td><button class="btn-mst" onclick="viewAcc(${a.id})">Ver</button></td></tr>`
  ).join('');
}

async function loadAccounts() {
  const params = new URLSearchParams();
  const q = document.getElementById('filterAcc').value.trim();
  const st = document.getElementById('filterAccStatus').value;
  const tp = document.getElementById('filterAccTipo').value;
  if (q)  params.set('q', q);
  if (st) params.set('status', st);
  if (tp) params.set('tipo', tp);

  const r = await fj(`${API}/accounts.php` + (params.toString() ? '?'+params.toString() : ''));
  if (!r.ok) return notifyErr(r.error);
  const tb = document.getElementById('accountsBody');
  if (!r.data.accounts.length) { tb.innerHTML='<tr><td colspan="10" class="empty">Nenhuma conta</td></tr>'; return; }
  tb.innerHTML = r.data.accounts.map(a => `
    <tr>
      <td>${a.id}</td>
      <td><strong>${esc(a.nome)}</strong></td>
      <td><span class="pill pill-${esc(a.tipo)}">${esc(a.tipo)}</span>${a.matriz_id?' <small>matriz #'+a.matriz_id+'</small>':''}</td>
      <td>${pill(a.status)}</td>
      <td>${esc(a.sub_plan || a.plano || '—')}</td>
      <td>${a.cidade ? esc(a.cidade) + (a.estado?'/'+esc(a.estado):'') : '—'}</td>
      <td>${a.users_count}</td>
      <td>${a.advogados_count || 0}</td>
      <td>${a.sub_status?pill(a.sub_status):'—'}</td>
      <td>
        <button class="btn-mst" onclick="viewAcc(${a.id})">Detalhes</button>
        ${a.status==='active' || a.status==='trial' ? `<button class="btn-mst btn-mst-danger" onclick="setStatus(${a.id},'suspended')">Suspender</button>` : ''}
        ${a.status==='suspended' ? `<button class="btn-mst btn-mst-success" onclick="setStatus(${a.id},'active')">Reativar</button>` : ''}
      </td>
    </tr>`).join('');
}
document.getElementById('filterAcc').addEventListener('input', () => clearTimeout(window._ft) || (window._ft = setTimeout(loadAccounts, 300)));
document.getElementById('filterAccStatus').addEventListener('change', loadAccounts);
document.getElementById('filterAccTipo').addEventListener('change', loadAccounts);

async function loadPlans() {
  const r = await fj(`${API}/plans.php`);
  if (!r.ok) return notifyErr(r.error);
  const tb = document.getElementById('plansBody');
  if (!r.data.plans.length) { tb.innerHTML='<tr><td colspan="8" class="empty">Sem planos</td></tr>'; return; }
  tb.innerHTML = r.data.plans.map(p => `
    <tr>
      <td><code>${esc(p.slug)}</code></td>
      <td><strong>${esc(p.nome)}</strong>${p.destaque==1?' ⭐':''}</td>
      <td>${fmtBRL(p.preco_mensal_cents)}</td>
      <td>${fmtBRL(p.preco_anual_cents)}</td>
      <td>${p.trial_dias}d</td>
      <td>${p.ativo==1?pill('active'):pill('cancelled')}</td>
      <td>${p.subscriptions_count}</td>
      <td>${p.features ? p.features.length : 0} features</td>
    </tr>`).join('');
}

async function loadBilling() {
  const r = await fj(`${API}/billing.php`);
  if (!r.ok) return notifyErr(r.error);
  const tb = document.getElementById('subsBody');
  if (!r.data.subscriptions.length) { tb.innerHTML='<tr><td colspan="7" class="empty">Nenhuma assinatura</td></tr>'; return; }
  tb.innerHTML = r.data.subscriptions.map(s => `
    <tr>
      <td>${esc(s.account_nome)}</td>
      <td>${esc(s.plan_nome)} <small style="color:#9ab0c9">(${esc(s.plan_slug)})</small></td>
      <td>${pill(s.status)}</td>
      <td>${esc(s.billing_cycle)}</td>
      <td>${fmtDate(s.trial_ends_at)}</td>
      <td>${fmtDate(s.current_period_end)}</td>
      <td>
        ${s.status==='active' || s.status==='trialing'
          ? `<button class="btn-mst btn-mst-danger" onclick="cancelSub(${s.id})">Cancelar</button>`
          : '—'}
      </td>
    </tr>`).join('');
}

async function cancelSub(id) {
  if (!(await Yuris.confirm('Cancelar essa assinatura no final do período?', { danger: true, okLabel: 'Cancelar' }))) return;
  const r = await fj(`${API}/billing.php?cancel=1`, {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({subscription_id:id, at_period_end:true, csrf_token: CSRF})
  });
  if (!r.ok) return notifyErr(r.error);
  notifyOk('Cancelamento agendado para o fim do período');
  loadBilling();
}

async function loadInvoices() {
  const r = await fj(`${API}/billing.php?invoices=1`);
  if (!r.ok) return notifyErr(r.error);
  const tb = document.getElementById('invoicesBody');
  if (!r.data.invoices.length) { tb.innerHTML='<tr><td colspan="7" class="empty">Nenhuma fatura</td></tr>'; return; }
  tb.innerHTML = r.data.invoices.map(i => `
    <tr>
      <td>${esc(i.numero||('#'+i.id))}</td>
      <td>${esc(i.account_nome)}</td>
      <td>${fmtBRL(i.amount_cents)}</td>
      <td>${pill(i.status)}</td>
      <td>${fmtDate(i.due_date)}</td>
      <td>${fmtDate(i.paid_at)}</td>
      <td>${esc(i.gateway||'—')}</td>
    </tr>`).join('');
}

// ── Payments (gestão manual) ─────────────────────────────────────────────
let _payOnlyOverdue = false;
async function loadPayments() {
  const params = new URLSearchParams();
  const st = document.getElementById('filterPayStatus').value;
  if (st) params.set('status', st);
  if (_payOnlyOverdue) params.set('vencido', '1');
  const r = await fj(`${API}/payments.php` + (params.toString() ? '?'+params.toString() : ''));
  if (!r.ok) return notifyErr(r.error);
  const tb = document.getElementById('paymentsBody');
  if (!r.data.invoices.length) { tb.innerHTML='<tr><td colspan="9" class="empty">Nenhuma fatura</td></tr>'; return; }
  tb.innerHTML = r.data.invoices.map(i => `
    <tr>
      <td>${esc(i.numero||('#'+i.id))}</td>
      <td>${esc(i.account_nome||'—')}</td>
      <td>${esc(i.plan_nome||'—')}</td>
      <td>${fmtBRL(i.amount_cents)}</td>
      <td>${pill(i.status)}</td>
      <td>${fmtDate(i.due_date)}</td>
      <td>${fmtDate(i.paid_at)}</td>
      <td>${i.observacoes_cobranca ? `<small style="color:#9ab0c9" title="${esc(i.observacoes_cobranca)}">${esc(i.observacoes_cobranca.slice(0,30))}${i.observacoes_cobranca.length>30?'…':''}</small>`: '—'}</td>
      <td><button class="btn-mst" onclick="openPayModal(${i.id})">Atualizar</button></td>
    </tr>`).join('');
}
document.getElementById('filterPayStatus').addEventListener('change', loadPayments);
function payFilterOverdue() { _payOnlyOverdue = !_payOnlyOverdue; loadPayments(); }

function openPayModal(id) {
  document.getElementById('payId').value = id;
  document.getElementById('formPay').reset();
  document.getElementById('payId').value = id;
  openModal('modalPay');
}

async function submitPay(ev) {
  ev.preventDefault();
  const f = ev.target;
  const body = {
    csrf_token: CSRF,
    id: parseInt(f.id.value, 10),
    status: f.status.value || undefined,
    due_date: f.due_date.value || undefined,
    observacoes: f.observacoes.value.trim() || undefined,
  };
  Object.keys(body).forEach(k => body[k] === undefined && delete body[k]);
  const r = await fj(`${API}/payments.php`, {
    method:'PATCH', headers:{'Content-Type':'application/json', 'X-CSRF-Token':CSRF},
    body: JSON.stringify(body)
  });
  if (!r.ok) return notifyErr(r.error);
  closeModal('modalPay');
  notifyOk('Fatura atualizada');
  loadPayments();
  loadOverview();
}

// ── Auditoria ────────────────────────────────────────────────────────────
async function loadAudit() {
  const acao = document.getElementById('filterAuditAcao').value.trim();
  const params = new URLSearchParams();
  if (acao) params.set('acao', acao);
  const r = await fj(`${API}/audit.php` + (params.toString() ? '?'+params.toString() : ''));
  if (!r.ok) return notifyErr(r.error);
  const tb = document.getElementById('auditBody');
  if (!r.data.entries.length) { tb.innerHTML='<tr><td colspan="6" class="empty">Nenhum registro</td></tr>'; return; }
  tb.innerHTML = r.data.entries.map(e => `
    <tr>
      <td>${fmtDateTime(e.created_at)}</td>
      <td>${esc(e.user_nome||'—')} <small style="color:#9ab0c9">${esc(e.sa_nivel||'')}</small></td>
      <td><code style="font-size:.78rem">${esc(e.acao)}</code></td>
      <td>${e.target_type?`${esc(e.target_type)} #${e.target_id||'—'}`:'—'}</td>
      <td>${esc(e.descricao||'—')}</td>
      <td><small>${esc(e.ip||'—')}</small></td>
    </tr>`).join('');
}
document.getElementById('filterAuditAcao').addEventListener('input', () => clearTimeout(window._fa) || (window._fa = setTimeout(loadAudit, 300)));

// ── Init ─────────────────────────────────────────────────────────────────
const initialHash = (window.location.hash || '').replace('#','') || 'overview';
activateTab(initialHash);
</script>
</body>
</html>
