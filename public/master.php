<?php
require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Models/Account.php';
require_once __DIR__ . '/../app/Models/ResourceShare.php';
require_once __DIR__ . '/../app/Helpers/AccountContext.php';

session_start();
// Não logado → manda pro portal dedicado (não pro login regular)
if (empty($_SESSION['user_id'])) {
    header('Location: /master_login.php');
    exit;
}

// ISOLAMENTO TOTAL: o Painel Master só é acessível via portal master_login.
// Mesmo super_admin que logou pelo /login.php normal NÃO entra direto aqui —
// precisa fazer login explícito em /master_login.php pra ter master_mode=true.
// Isso evita que uma sessão sequestrada do app abra o painel master.
if (empty($_SESSION['master_mode'])) {
    header('Location: /master_login.php');
    exit;
}

use App\Helpers\AccountContext;

$ctx = AccountContext::fromSession();
if (!$ctx->isSuperAdmin()) {
    // Não é super_admin → derruba sessão master (foi promovida indevidamente?) e bloqueia
    unset($_SESSION['master_mode']);
    http_response_code(403);
    echo '<html><body style="font-family:sans-serif;padding:40px;background:#0a0418;color:#dbe7f5">';
    echo '<h1>Acesso negado</h1><p>Apenas super administradores podem acessar o Painel Master.</p>';
    echo '<a href="/master_login.php" style="color:#c084fc">← Voltar ao portal master</a>';
    echo '</body></html>';
    exit;
}

$activePage = 'master';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$saLevel = $ctx->getSuperAdminLevel() ?: 'operator';

// LGPD P0 (1.9): banner pede pra configurar 2FA se ainda não habilitado.
// Modo opt-in — não bloqueia login, apenas chama atenção.
$mfaEnabled = false;
try {
    $pdoMfa = \App\Models\Database::getConnection();
    $sMfa = $pdoMfa->prepare('SELECT mfa_enabled FROM super_admins WHERE user_id = :uid AND ativo = 1 LIMIT 1');
    $sMfa->execute(['uid' => $ctx->getUserId()]);
    $mfaEnabled = (int)$sMfa->fetchColumn() === 1;
} catch (\Throwable $_) { /* tabela pré-migration 047 — silencioso */ }

// Botão "Sair" sempre faz logout do master e volta pro portal isolado.
// (chegou até aqui = master_mode obrigatoriamente true, asserted acima)
$exitHref  = '/master_logout.php';
$exitLabel = '← Sair (logout)';
$exitTitle = 'Encerrar sessão e voltar ao portal master';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Painel Master — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=27">
  <!-- Yuris UI lib (Yuris.notify/confirm/prompt — polyfill window.alert).
       Carregado direto aqui porque o Painel Master roda sem sidebar. -->
  <script src="/assets/yuris-ui.js"></script>
  <!-- Chart.js pra gráficos da aba Dashboard -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    html, body { margin:0; padding:0; }
    body { font-family: Inter, system-ui, sans-serif; background:#070F1C; color:#D8E4F0; min-height:100vh; overflow-x:hidden; }
    .mst-content { padding:8px 32px 24px; max-width:100%; box-sizing:border-box; }
    .mst-topbar-exit { display:flex; justify-content:flex-end; align-items:center; padding:10px 32px 0; border-bottom:1px solid rgba(96,165,250,.08); background:rgba(5,15,30,.4); }
    .mst-exit { padding:6px 13px; border-radius:7px; border:1px solid rgba(160,180,210,.20); background:rgba(8,22,44,.6); color:#A8BDD4; text-decoration:none; font-size:.76rem; font-weight:600; transition:all .15s; margin-bottom:8px; }
    .mst-exit:hover { background:rgba(37,99,235,.16); color:#FFFFFF; border-color:rgba(96,165,250,.4); }
    html[data-theme="light"] body { background:#F8FAFC; color:#0F1F36; }
    html[data-theme="light"] .mst-topbar-exit { background:#fff; border-color:#E2E8F0; }
    html[data-theme="light"] .mst-exit { background:#F1F5F9; color:#0F1F36; border-color:#E2E8F0; }
    html[data-theme="light"] .mst-exit:hover { background:rgba(37,99,235,.10); border-color:rgba(37,99,235,.30); color:#1E4A8A; }
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

    /* ── Light overrides ADICIONAIS (contraste — auditoria 2026-05-28) ─────────
       Elementos que estavam ilegíveis no tema claro: badges de nível, subtexto
       dos KPIs, pills de status, banner 2FA, ícones e botões coloridos. */
    html[data-theme="light"] .mst-badge-super    { background:rgba(168,85,247,.10)!important; color:#6B21A8!important; border-color:rgba(168,85,247,.30)!important; }
    html[data-theme="light"] .mst-badge-operator { background:rgba(37,99,235,.10)!important;  color:#1E40AF!important; border-color:rgba(37,99,235,.30)!important; }
    html[data-theme="light"] .mst-badge-viewer   { background:rgba(100,116,139,.12)!important; color:#475569!important; border-color:rgba(100,116,139,.30)!important; }

    html[data-theme="light"] .mst-kpi-foot { color:#5A6B7E!important; }

    html[data-theme="light"] .pill-active,
    html[data-theme="light"] .pill-paid          { background:rgba(22,163,74,.12)!important;  color:#15803D!important; border-color:rgba(22,163,74,.30)!important; }
    html[data-theme="light"] .pill-trial,
    html[data-theme="light"] .pill-trialing      { background:rgba(124,58,237,.12)!important; color:#5B21B6!important; border-color:rgba(124,58,237,.30)!important; }
    html[data-theme="light"] .pill-overdue,
    html[data-theme="light"] .pill-past_due,
    html[data-theme="light"] .pill-uncollectible { background:rgba(220,38,38,.12)!important;  color:#B91C1C!important; border-color:rgba(220,38,38,.30)!important; }
    html[data-theme="light"] .pill-suspended     { background:rgba(217,119,6,.12)!important;  color:#B45309!important; border-color:rgba(217,119,6,.30)!important; }
    html[data-theme="light"] .pill-cancelled,
    html[data-theme="light"] .pill-canceled      { background:rgba(220,38,38,.08)!important;  color:#B91C1C!important; border-color:rgba(220,38,38,.25)!important; }
    html[data-theme="light"] .pill-inactive,
    html[data-theme="light"] .pill-void,
    html[data-theme="light"] .pill-usuario       { background:rgba(100,116,139,.12)!important; color:#475569!important; border-color:rgba(100,116,139,.30)!important; }
    html[data-theme="light"] .pill-open          { background:rgba(37,99,235,.12)!important;  color:#1E40AF!important; border-color:rgba(37,99,235,.30)!important; }
    html[data-theme="light"] .pill-matriz        { background:rgba(37,99,235,.12)!important;  color:#1E40AF!important; border-color:rgba(37,99,235,.30)!important; }
    html[data-theme="light"] .pill-filial        { background:rgba(168,85,247,.12)!important; color:#6B21A8!important; border-color:rgba(168,85,247,.30)!important; }
    html[data-theme="light"] .pill-advogado      { background:rgba(34,197,94,.12)!important;  color:#15803D!important; border-color:rgba(34,197,94,.30)!important; }

    html[data-theme="light"] .mst-search-ico   { color:#5A6B7E!important; }
    html[data-theme="light"] .mst-search-empty { color:#5A6B7E!important; }
    html[data-theme="light"] .mst-form-help    { color:#5A6B7E!important; }
    html[data-theme="light"] .btn-mst-danger   { color:#B91C1C!important; border-color:rgba(220,38,38,.30)!important; }
    html[data-theme="light"] .btn-mst-success  { color:#15803D!important; border-color:rgba(22,163,74,.30)!important; }

    /* Banner 2FA (era amarelo claro #fde68a, sumia no fundo claro) */
    html[data-theme="light"] .mst-mfa-banner { background:linear-gradient(90deg,rgba(217,119,6,.14),rgba(217,119,6,.05))!important; border-bottom-color:rgba(217,119,6,.45)!important; color:#92400E!important; }

    /* ── Botão de alternar tema (claro/escuro) no header ── */
    .mst-theme-toggle { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:7px; border:1px solid rgba(160,180,210,.20); background:rgba(8,22,44,.6); color:#A8BDD4; cursor:pointer; font-size:.76rem; font-weight:600; transition:all .15s; margin-bottom:8px; margin-right:8px; font-family:inherit; }
    .mst-theme-toggle:hover { background:rgba(37,99,235,.16); color:#FFFFFF; border-color:rgba(96,165,250,.4); }
    .mst-theme-toggle svg { width:15px; height:15px; flex-shrink:0; }
    html[data-theme="light"] .mst-theme-toggle { background:#F1F5F9; color:#0F1F36; border-color:#E2E8F0; }
    html[data-theme="light"] .mst-theme-toggle:hover { background:rgba(37,99,235,.10); border-color:rgba(37,99,235,.30); color:#1E4A8A; }
  </style>
</head>
<body>
<div class="mst-topbar-exit">
  <button type="button" id="mstThemeToggle" class="mst-theme-toggle" title="Alternar entre tema claro e escuro">
    <span class="mst-theme-ico" aria-hidden="true"></span>
    <span id="mstThemeLabel">Tema claro</span>
  </button>
  <a href="<?= htmlspecialchars($exitHref) ?>" class="mst-exit" title="<?= htmlspecialchars($exitTitle) ?>"><?= htmlspecialchars($exitLabel) ?></a>
</div>
<script>
/* Alternador de tema do Painel Master — escreve em localStorage.yuris_theme,
   mesmo mecanismo do boot script (linha ~66) e de configuracoes.php. O botão
   mostra a AÇÃO: no escuro oferece "Tema claro" (sol); no claro oferece
   "Tema escuro" (lua). */
(function(){
  var btn = document.getElementById('mstThemeToggle');
  if (!btn) return;
  var root = document.documentElement;
  var ico  = btn.querySelector('.mst-theme-ico');
  var lbl  = document.getElementById('mstThemeLabel');
  var sun  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';
  var moon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
  function render(){
    var isLight = root.getAttribute('data-theme') === 'light';
    ico.innerHTML  = isLight ? moon : sun;
    lbl.textContent = isLight ? 'Tema escuro' : 'Tema claro';
  }
  btn.addEventListener('click', function(){
    var isLight = root.getAttribute('data-theme') === 'light';
    if (isLight) { root.removeAttribute('data-theme'); try{ localStorage.setItem('yuris_theme','dark'); }catch(e){} }
    else         { root.setAttribute('data-theme','light'); try{ localStorage.setItem('yuris_theme','light'); }catch(e){} }
    render();
  });
  render();
})();
</script>

<?php if (!$mfaEnabled): ?>
<div class="mst-mfa-banner" style="background:linear-gradient(90deg,rgba(245,158,11,.18),rgba(245,158,11,.06));border-bottom:1px solid rgba(245,158,11,.40);padding:10px 32px;display:flex;align-items:center;justify-content:space-between;gap:14px;color:#fde68a;font-size:.86rem">
  <div style="display:flex;align-items:center;gap:10px">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <strong>2FA não configurado.</strong>
    <span>O Painel Master tem acesso cross-tenant total — proteja com autenticação em dois fatores.</span>
  </div>
  <a href="/master_mfa_setup.php"
     style="padding:6px 14px;background:linear-gradient(135deg,#a855f7,#7c3aed);color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:.82rem;white-space:nowrap;">
    Configurar agora →
  </a>
</div>
<?php endif; ?>

<main class="mst-content">
  <div class="mst-header">
    <div class="mst-title-block">
      <h1 class="mst-title" style="display:flex;align-items:center;gap:10px">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color:#c084fc"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
        <span>Painel Master</span>
      </h1>
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
      <button class="mst-action-btn" onclick="openModalAccount('matriz')">+ Matriz</button>
      <button class="mst-action-btn mst-action-btn-secondary" onclick="openModalFilial()">+ Filial</button>
      <button class="mst-action-btn mst-action-btn-secondary" onclick="openModalAccount('advogado')">+ Advogado</button>
    </div>
  </div>

  <?php
    // Helper PHP: SVG inline de cada tab, mesmo padrão (size 13, stroke 2.2)
    // — mantém consistência visual: TODAS as tabs têm ícone, sem exceção.
    $_tabIco = function (string $key): string {
        $base = 'width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-2px;margin-right:5px"';
        $paths = [
            'overview'  => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
            'dashboard' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            'accounts'  => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'plans'     => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
            'billing'   => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
            'invoices'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
            'payments'  => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
            'expenses'  => '<path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>',
            'audit'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
            'lgpd'      => '<path d="M9 12l2 2 4-4"/><path d="M12 22s8-4 8-10V6l-8-3-8 3v6c0 6 8 10 8 10z"/>',
            'retencao'  => '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
            'incidents' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
            'operators' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/>',
            'reviews'   => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        ];
        return '<svg ' . $base . '>' . ($paths[$key] ?? '') . '</svg>';
    };
  ?>
  <div class="mst-tabs">
    <button class="mst-tab active" data-mtab="overview"><?= $_tabIco('overview') ?>Visão Geral</button>
    <button class="mst-tab" data-mtab="dashboard"><?= $_tabIco('dashboard') ?>Dashboard</button>
    <button class="mst-tab" data-mtab="accounts"><?= $_tabIco('accounts') ?>Contas</button>
    <button class="mst-tab" data-mtab="plans"><?= $_tabIco('plans') ?>Planos</button>
    <button class="mst-tab" data-mtab="billing"><?= $_tabIco('billing') ?>Assinaturas</button>
    <button class="mst-tab" data-mtab="invoices"><?= $_tabIco('invoices') ?>Faturas</button>
    <button class="mst-tab" data-mtab="payments"><?= $_tabIco('payments') ?>Pagamentos</button>
    <button class="mst-tab" data-mtab="expenses"><?= $_tabIco('expenses') ?>Despesas</button>
    <button class="mst-tab" data-mtab="audit"><?= $_tabIco('audit') ?>Auditoria</button>
    <button class="mst-tab" data-mtab="lgpd"><?= $_tabIco('lgpd') ?>LGPD <span id="lgpdBadge" style="display:none;background:#ef4444;color:#fff;font-size:.7rem;padding:1px 7px;border-radius:999px;margin-left:5px;font-weight:700"></span></button>
    <button class="mst-tab" data-mtab="retencao"><?= $_tabIco('retencao') ?>Retenção</button>
    <button class="mst-tab" data-mtab="incidents"><?= $_tabIco('incidents') ?>Incidentes <span id="incidentBadge" style="display:none;background:#ef4444;color:#fff;font-size:.7rem;padding:1px 7px;border-radius:999px;margin-left:5px;font-weight:700"></span></button>
    <button class="mst-tab" data-mtab="operators"><?= $_tabIco('operators') ?>Operadores <span id="operatorsBadge" style="display:none;background:#f59e0b;color:#fff;font-size:.7rem;padding:1px 7px;border-radius:999px;margin-left:5px;font-weight:700"></span></button>
    <button class="mst-tab" data-mtab="reviews"><?= $_tabIco('reviews') ?>Revisões <span id="reviewsBadge" style="display:none;background:#dc2626;color:#fff;font-size:.7rem;padding:1px 7px;border-radius:999px;margin-left:5px;font-weight:700"></span></button>
    <button class="mst-tab" data-mtab="whatsapp"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>WhatsApp</button>
  </div>

  <!-- ── Visão Geral ── -->
  <section class="mst-section active" id="msec-overview">
    <div class="mst-grid-5">
      <div class="mst-card"><div class="mst-kpi-label">Contas Ativas</div><div class="mst-kpi-value" id="kpiActive">—</div><div class="mst-kpi-foot" id="kpiActiveFoot">de — totais</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Em teste</div><div class="mst-kpi-value" id="kpiTrial">—</div><div class="mst-kpi-foot">testando o sistema</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Em Atraso</div><div class="mst-kpi-value" id="kpiOverdue">—</div><div class="mst-kpi-foot">pagamento vencido</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Suspensas</div><div class="mst-kpi-value" id="kpiSuspended">—</div><div class="mst-kpi-foot">acesso bloqueado</div></div>
      <div class="mst-card"><div class="mst-kpi-label">MRR Projetado</div><div class="mst-kpi-value" id="kpiMrr">R$ —</div><div class="mst-kpi-foot">receita mensal</div></div>
    </div>

    <div class="mst-grid-5">
      <div class="mst-card"><div class="mst-kpi-label">Matrizes</div><div class="mst-kpi-value" id="kpiMatriz">—</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Filiais</div><div class="mst-kpi-value" id="kpiFilial">—</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Adv. Solo (contas)</div><div class="mst-kpi-value" id="kpiAccAdv">—</div><div class="mst-kpi-foot">tenants tipo advogado</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Advogados (users)</div><div class="mst-kpi-value" id="kpiAdv">—</div><div class="mst-kpi-foot">users com OAB</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Faturas vencidas</div><div class="mst-kpi-value" id="kpiInvOverdue">—</div></div>
    </div>
    <div class="mst-grid-5" style="grid-template-columns:repeat(2,1fr)">
      <div class="mst-card"><div class="mst-kpi-label">Usuários ativos</div><div class="mst-kpi-value" id="kpiUsersActive">—</div></div>
      <div class="mst-card"><div class="mst-kpi-label">Usuários inativos</div><div class="mst-kpi-value" id="kpiUsersInactive">—</div></div>
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
          <thead><tr><th>Plano</th><th>Ativas</th><th>Em teste</th><th>Total</th></tr></thead>
          <tbody id="byPlanBody"><tr><td colspan="4" class="empty">—</td></tr></tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ── Dashboard (Gráficos) ── -->
  <section class="mst-section" id="msec-dashboard">
    <!-- Linha 1: Caixa REAL (o que efetivamente entrou e saiu) -->
    <div class="mst-grid-5" style="grid-template-columns:repeat(3,1fr); margin-bottom:14px">
      <div class="mst-card" style="border-left:3px solid #4ade80">
        <div class="mst-kpi-label">Receita Realizada (mês)</div>
        <div class="mst-kpi-value" id="finReceitaReal" style="color:#4ade80">R$ —</div>
        <div class="mst-kpi-foot">faturas pagas neste mês (caixa real)</div>
      </div>
      <div class="mst-card" style="border-left:3px solid #fca5a5">
        <div class="mst-kpi-label">Despesas (mês)</div>
        <div class="mst-kpi-value" id="finDespesa" style="color:#fca5a5">R$ —</div>
        <div class="mst-kpi-foot" id="finMesRef">—</div>
      </div>
      <div class="mst-card" style="border-left:3px solid #c084fc">
        <div class="mst-kpi-label">Lucro Real (caixa)</div>
        <div class="mst-kpi-value" id="finLucroReal" style="color:#c084fc">R$ —</div>
        <div class="mst-kpi-foot">Receita Realizada − Despesas</div>
      </div>
    </div>
    <!-- Linha 2: Projeção (potencial baseado em MRR) -->
    <div class="mst-grid-5" style="grid-template-columns:repeat(3,1fr); margin-bottom:18px">
      <div class="mst-card" style="border-left:3px solid rgba(74,222,128,.5); opacity:.92">
        <div class="mst-kpi-label">MRR Projetado</div>
        <div class="mst-kpi-value" id="finMrrProj" style="color:#86efac">R$ —</div>
        <div class="mst-kpi-foot">run rate · inclui trial · active · past_due</div>
      </div>
      <div class="mst-card" style="border-left:3px solid rgba(74,222,128,.5); opacity:.92">
        <div class="mst-kpi-label">MRR Realizado</div>
        <div class="mst-kpi-value" id="finMrrReal" style="color:#86efac">R$ —</div>
        <div class="mst-kpi-foot">só pagantes · active + past_due</div>
      </div>
      <div class="mst-card" style="border-left:3px solid rgba(192,132,252,.5); opacity:.92">
        <div class="mst-kpi-label">Lucro Projetado (mês)</div>
        <div class="mst-kpi-value" id="finLucroProj" style="color:#c4b5fd">R$ —</div>
        <div class="mst-kpi-foot">MRR Projetado − Despesas</div>
      </div>
    </div>

    <div class="mst-grid-5" style="grid-template-columns:repeat(2,1fr); gap:14px; margin-bottom:18px">
      <div class="mst-card" style="padding:14px 18px">
        <div style="font-weight:700; font-size:.9rem; margin-bottom:14px">MRR · últimos 12 meses</div>
        <div style="position:relative; height:240px"><canvas id="chartMrr"></canvas></div>
      </div>
      <div class="mst-card" style="padding:14px 18px">
        <div style="font-weight:700; font-size:.9rem; margin-bottom:14px">Receita vs Despesas · últimos 12 meses</div>
        <div style="position:relative; height:240px"><canvas id="chartRecDesp"></canvas></div>
      </div>
    </div>

    <div class="mst-grid-5" style="grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:18px">
      <div class="mst-card" style="padding:14px 18px">
        <div style="font-weight:700; font-size:.9rem; margin-bottom:14px">Contas por Tipo</div>
        <div style="position:relative; height:220px"><canvas id="chartTipo"></canvas></div>
      </div>
      <div class="mst-card" style="padding:14px 18px">
        <div style="font-weight:700; font-size:.9rem; margin-bottom:14px">Contas por Status</div>
        <div style="position:relative; height:220px"><canvas id="chartStatus"></canvas></div>
      </div>
      <div class="mst-card" style="padding:14px 18px">
        <div style="font-weight:700; font-size:.9rem; margin-bottom:14px">Despesas por Categoria (mês)</div>
        <div style="position:relative; height:220px"><canvas id="chartDespCat"></canvas></div>
      </div>
    </div>

    <div class="mst-grid-5" style="grid-template-columns:repeat(2,1fr); gap:14px">
      <div class="mst-card" style="padding:14px 18px">
        <div style="font-weight:700; font-size:.9rem; margin-bottom:14px">Receita por Plano</div>
        <div style="position:relative; height:240px"><canvas id="chartPlanos"></canvas></div>
      </div>
      <div class="mst-card" style="padding:14px 18px">
        <div style="font-weight:700; font-size:.9rem; margin-bottom:14px">Crescimento de Contas · últimos 12 meses</div>
        <div style="position:relative; height:240px"><canvas id="chartCrescimento"></canvas></div>
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
          <option value="active">Ativa</option>
          <option value="trial">Em teste</option>
          <option value="overdue">Em atraso</option>
          <option value="suspended">Suspensa</option>
          <option value="cancelled">Cancelada</option>
          <option value="inactive">Inativa</option>
        </select>
        <select id="filterAccTipo" class="mst-form-select" style="width:auto; padding:6px 11px; font-size:.82rem">
          <option value="">Todos tipos</option>
          <option value="matriz">Só Matriz</option>
          <option value="filial">Só Filial</option>
          <option value="advogado">Só Advogado</option>
        </select>
        <input id="filterAcc" placeholder="Buscar nome..." style="padding:6px 11px; border-radius:7px; background:rgba(5,18,39,.6); border:1px solid rgba(160,180,210,.18); color:#D8E4F0; font-size:.82rem; width:240px">
      </div>
      <table class="mst-tbl">
        <thead><tr><th>#</th><th>Nome</th><th>Tipo</th><th>Status</th><th>Plano</th><th>Cidade/UF</th><th>Users</th><th>Adv.</th><th title="Monitoramentos usados / contratados">Monitors</th><th>Assinatura</th><th>Ações</th></tr></thead>
        <tbody id="accountsBody"><tr><td colspan="11" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Planos ── -->
  <section class="mst-section" id="msec-plans">
    <div class="mst-card" style="padding:0; overflow:hidden">
      <div style="display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10)">
        <div style="font-weight:700">Planos Cadastrados</div>
        <button class="btn-mst btn-mst-primary" onclick="openPlanModal()">+ Novo Plano</button>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>Slug</th><th>Nome</th><th>Mensal</th><th>Anual</th><th>Dias de teste</th><th>Ativo</th><th>Assinaturas</th><th>Features</th><th>Ações</th></tr></thead>
        <tbody id="plansBody"><tr><td colspan="9" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Assinaturas ── -->
  <section class="mst-section" id="msec-billing">
    <div class="mst-card" style="padding:0; overflow:hidden">
      <div style="display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10)">
        <div>
          <div style="font-weight:700">Assinaturas</div>
          <div style="font-size:.74rem; color:#9ab0c9; margin-top:3px">
            Plano do sistema (cobrança via gateway) + Monitoramento (add-on cobrado por unidade)
          </div>
        </div>
        <button class="btn-mst btn-mst-primary" onclick="openNovaMonitorSubModal()">+ Nova assinatura de monitor</button>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>Tipo</th><th>Conta</th><th>Produtos / Assinaturas</th><th>Ações</th></tr></thead>
        <tbody id="subsBody"><tr><td colspan="4" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Faturas ── -->
  <section class="mst-section" id="msec-invoices">
    <div class="mst-card" style="padding:0; overflow:hidden">
      <div style="display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10)">
        <div>
          <div style="font-weight:700">Faturas Recentes</div>
          <div style="font-size:.74rem; color:#9ab0c9; margin-top:3px">
            Geradas automaticamente quando o gateway processa cobranças · OU criadas manualmente abaixo
          </div>
        </div>
        <button class="btn-mst btn-mst-primary" onclick="openNewInvoiceModal()">+ Nova Cobrança</button>
      </div>
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
        <div style="margin-right:auto">
          <div style="font-weight:700">Pagamentos / Gestão Manual</div>
          <div style="font-size:.74rem; color:#9ab0c9; margin-top:3px">
            Mesma fonte que Faturas · aqui você marca pago, ajusta vencimento, adiciona observações de cobrança
          </div>
        </div>
        <select id="filterPayStatus" class="mst-form-select" style="width:auto; padding:6px 11px; font-size:.82rem">
          <option value="">Todos status</option>
          <option value="open">Em aberto</option>
          <option value="paid">Pagas</option>
          <option value="uncollectible">Inadimplentes</option>
          <option value="void">Anuladas</option>
        </select>
        <button class="btn-mst" onclick="payFilterOverdue()">Só vencidas</button>
        <button class="btn-mst btn-mst-primary" onclick="openNewInvoiceModal()">+ Nova Cobrança</button>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>#</th><th>Conta</th><th>Plano</th><th>Valor</th><th>Status</th><th>Vencimento</th><th>Pago em</th><th>Obs.</th><th>Ações</th></tr></thead>
        <tbody id="paymentsBody"><tr><td colspan="9" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Despesas (CRUD) ── -->
  <section class="mst-section" id="msec-expenses">
    <div class="mst-grid-5" style="grid-template-columns:repeat(3,1fr); margin-bottom:18px">
      <div class="mst-card">
        <div class="mst-kpi-label">Total do Mês</div>
        <div class="mst-kpi-value" id="expTotalMes">R$ —</div>
        <div class="mst-kpi-foot" id="expCountMes">— despesas</div>
      </div>
      <div class="mst-card">
        <div class="mst-kpi-label">Pendentes</div>
        <div class="mst-kpi-value" id="expPendentes">—</div>
        <div class="mst-kpi-foot">aguardando pagamento</div>
      </div>
      <div class="mst-card">
        <div class="mst-kpi-label">Vencidas</div>
        <div class="mst-kpi-value" id="expVencidas" style="color:#fca5a5">—</div>
        <div class="mst-kpi-foot">prazo expirado</div>
      </div>
    </div>

    <div class="mst-card" style="padding:0; overflow:hidden">
      <div style="display:flex; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10); flex-wrap:wrap">
        <div style="font-weight:700; margin-right:auto">Despesas Operacionais</div>
        <select id="filterExpMonth" class="mst-form-select" style="width:auto; padding:6px 11px; font-size:.82rem">
          <option value="">Todos os meses</option>
        </select>
        <select id="filterExpCategoria" class="mst-form-select" style="width:auto; padding:6px 11px; font-size:.82rem">
          <option value="">Todas categorias</option>
          <option value="servidor">Servidor</option>
          <option value="pessoas">Pessoas / Salários</option>
          <option value="apis">APIs</option>
          <option value="marketing">Marketing</option>
          <option value="infraestrutura">Infraestrutura</option>
          <option value="impostos">Impostos</option>
          <option value="software">Software</option>
          <option value="juridico">Jurídico</option>
          <option value="outros">Outros</option>
        </select>
        <select id="filterExpStatus" class="mst-form-select" style="width:auto; padding:6px 11px; font-size:.82rem">
          <option value="">Todos status</option>
          <option value="pendente">Pendente</option>
          <option value="pago">Pago</option>
          <option value="atrasado">Atrasado</option>
          <option value="cancelado">Cancelado</option>
        </select>
        <button class="btn-mst btn-mst-primary" onclick="openExpenseModal()">+ Nova Despesa</button>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>Categoria</th><th>Descrição</th><th>Fornecedor</th><th>Valor</th><th>Competência</th><th>Vencimento</th><th>Status</th><th>Recorrência</th><th>Ações</th></tr></thead>
        <tbody id="expensesBody"><tr><td colspan="9" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Auditoria ── -->
  <section class="mst-section" id="msec-audit">
    <div class="mst-card" style="padding:0; overflow:hidden">
      <div style="display:flex; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10); flex-wrap:wrap">
        <div style="margin-right:auto">
          <div style="font-weight:700">Log de Auditoria</div>
          <div style="font-size:.74rem; color:#9ab0c9; margin-top:3px">
            Preenchido <strong>automaticamente</strong> quando super_admin executa ações no painel · não é editável (write-only, compliance)
          </div>
        </div>
        <input id="filterAuditAcao" placeholder="Filtrar por ação (ex: account.create)..." style="padding:6px 11px; border-radius:7px; background:rgba(5,18,39,.6); border:1px solid rgba(160,180,210,.18); color:#D8E4F0; font-size:.82rem; width:280px">
      </div>
      <table class="mst-tbl">
        <thead><tr><th>Quando</th><th>Operador</th><th>Ação</th><th>Alvo</th><th>Descrição</th><th>IP</th></tr></thead>
        <tbody id="auditBody"><tr><td colspan="6" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── LGPD (solicitações de titulares — Art. 18) ── -->
  <section class="mst-section" id="msec-lgpd">
    <div class="mst-card" style="padding:0; overflow:hidden; margin-bottom:14px">
      <div style="display:flex; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10); flex-wrap:wrap">
        <div style="margin-right:auto">
          <div style="font-weight:700">Central LGPD — Solicitações de Titulares</div>
          <div style="font-size:.74rem; color:#9ab0c9; margin-top:3px">
            Pedidos chegam via <a href="/lgpd/solicitar.php" target="_blank" style="color:#7eb8f7">/lgpd/solicitar.php</a> · Prazo de resposta: <strong>15 dias</strong> (LGPD Art. 19)
          </div>
        </div>
        <select id="filterLgpdStatus" class="mst-form-select" style="width:auto;padding:6px 11px;font-size:.82rem">
          <option value="">Todos status</option>
          <option value="aberto" selected>Aberto</option>
          <option value="em_analise">Em análise</option>
          <option value="aguardando_titular">Aguardando titular</option>
          <option value="concluido">Concluído</option>
          <option value="rejeitado">Rejeitado</option>
          <option value="expirado">Expirado</option>
        </select>
        <select id="filterLgpdTipo" class="mst-form-select" style="width:auto;padding:6px 11px;font-size:.82rem">
          <option value="">Todos tipos</option>
          <option value="confirmacao_existencia">Confirmação</option>
          <option value="acesso">Acesso</option>
          <option value="correcao">Correção</option>
          <option value="anonimizacao">Anonimização</option>
          <option value="bloqueio">Bloqueio</option>
          <option value="eliminacao">Eliminação</option>
          <option value="portabilidade">Portabilidade</option>
          <option value="info_compartilhamento">Compartilhamento</option>
          <option value="revogacao_consentimento">Revogação consentimento</option>
          <option value="revisao_decisao_automatizada">Revisão decisão</option>
        </select>
        <label style="font-size:.82rem;color:#9ab0c9;display:flex;align-items:center;gap:5px">
          <input type="checkbox" id="filterLgpdAtrasada"> Apenas atrasadas
        </label>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>#</th><th>Titular</th><th>Tipo</th><th>Recebido</th><th>Prazo</th><th>Status</th><th>Ações</th></tr></thead>
        <tbody id="lgpdBody"><tr><td colspan="7" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>

    <!-- ── Aceites de Termos (LGPD Art. 8º — registro de consentimento) ── -->
    <div class="mst-card" style="padding:0; overflow:hidden; margin-bottom:14px">
      <div style="display:flex; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10); flex-wrap:wrap">
        <div style="margin-right:auto">
          <div style="font-weight:700">Aceites de Termos <span id="consentsBadge" style="display:none;background:#10b981;color:#fff;font-size:.72rem;padding:1px 8px;border-radius:999px;margin-left:6px;font-weight:700"></span></div>
          <div style="font-size:.74rem; color:#9ab0c9; margin-top:3px">
            Prova de consentimento aos Termos de Uso/Privacidade no login (LGPD Art. 8º §1º — registro de IP, data e versão)
          </div>
        </div>
        <input type="search" id="filterConsentQ" class="mst-form-select" placeholder="Buscar e-mail / nome…" style="width:auto;min-width:200px;padding:6px 11px;font-size:.82rem">
        <select id="filterConsentStatus" class="mst-form-select" style="width:auto;padding:6px 11px;font-size:.82rem">
          <option value="ativo" selected>Ativos</option>
          <option value="revogado">Revogados</option>
          <option value="">Todos</option>
        </select>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>#</th><th>Titular</th><th>Conta</th><th>Base legal</th><th>Concedido</th><th>IP</th><th>Status</th></tr></thead>
        <tbody id="consentsBody"><tr><td colspan="7" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Retenção LGPD (Art. 16 + 18 IV) ── -->
  <section class="mst-section" id="msec-retencao">
    <div class="mst-card" style="padding:0; overflow:hidden; margin-bottom:14px">
      <div style="display:flex; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10); flex-wrap:wrap">
        <div style="margin-right:auto">
          <div style="font-weight:700">Políticas de Retenção</div>
          <div style="font-size:.74rem; color:#9ab0c9; margin-top:3px">
            Cron diário aplica essas regras. Última anonimização: <strong id="retencaoLastAnon">—</strong>
          </div>
        </div>
        <button class="btn-mst" type="button" onclick="runRetention(true)">Dry Run (simular)</button>
        <button class="btn-mst btn-mst-primary" type="button" onclick="runRetention(false)">Executar agora</button>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>Entidade</th><th>Ação</th><th>Prazo (dias)</th><th>Base legal</th><th>Último run</th><th>Status</th><th>Ativo</th></tr></thead>
        <tbody id="retencaoBody"><tr><td colspan="7" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>

    <div class="mst-card" style="padding:0; overflow:hidden">
      <div style="padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10)">
        <div style="font-weight:700">Log de Anonimizações (Art. 12)</div>
        <div style="font-size:.74rem; color:#9ab0c9; margin-top:3px">Operações de anonimização irreversíveis — apenas as 50 mais recentes.</div>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>Quando</th><th>Entidade</th><th>ID</th><th>Motivo</th><th>Executor</th><th>Solicitação</th></tr></thead>
        <tbody id="anonLogBody"><tr><td colspan="6" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Incidentes de Segurança (LGPD Art. 48) ── -->
  <section class="mst-section" id="msec-incidents">
    <div class="mst-card" style="padding:0; overflow:hidden; margin-bottom:14px">
      <div style="display:flex; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10); flex-wrap:wrap">
        <div style="margin-right:auto">
          <div style="font-weight:700">Incidentes de Segurança</div>
          <div style="font-size:.74rem; color:#9ab0c9; margin-top:3px">
            Registro de incidentes envolvendo dados pessoais. <strong>LGPD Art. 48</strong> exige notificação à ANPD e aos titulares afetados em prazo razoável.
          </div>
        </div>
        <select id="filterIncStatus" class="mst-form-select" style="width:auto;padding:6px 11px;font-size:.82rem">
          <option value="">Todos status</option>
          <option value="detectado">Detectado</option>
          <option value="em_analise">Em análise</option>
          <option value="contido">Contido</option>
          <option value="mitigado">Mitigado</option>
          <option value="notificado_anpd">Notificado ANPD</option>
          <option value="notificado_titulares">Notificado Titulares</option>
          <option value="encerrado">Encerrado</option>
          <option value="falso_positivo">Falso positivo</option>
        </select>
        <select id="filterIncSeveridade" class="mst-form-select" style="width:auto;padding:6px 11px;font-size:.82rem">
          <option value="">Todas severidades</option>
          <option value="critica">Crítica</option>
          <option value="alta">Alta</option>
          <option value="media">Média</option>
          <option value="baixa">Baixa</option>
        </select>
        <select id="filterIncTipo" class="mst-form-select" style="width:auto;padding:6px 11px;font-size:.82rem">
          <option value="">Todos tipos</option>
          <option value="vazamento_dados">Vazamento</option>
          <option value="acesso_indevido">Acesso indevido</option>
          <option value="ransomware">Ransomware</option>
          <option value="phishing">Phishing</option>
          <option value="dos_ddos">DoS/DDoS</option>
          <option value="exposicao_credenciais">Exposição credenciais</option>
          <option value="perda_dispositivo">Perda de dispositivo</option>
          <option value="engenharia_social">Engenharia social</option>
          <option value="config_indevida">Config. indevida</option>
          <option value="outro">Outro</option>
        </select>
        <label style="font-size:.82rem;color:#9ab0c9;display:flex;align-items:center;gap:5px">
          <input type="checkbox" id="filterIncAbertos" checked> Apenas abertos
        </label>
        <button class="btn-mst btn-mst-primary" type="button" onclick="openNewIncident()">+ Novo Incidente</button>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>#</th><th>Título</th><th>Tipo</th><th>Sev.</th><th>Conta</th><th>Detectado</th><th>Status</th><th>Notificações</th><th>Ações</th></tr></thead>
        <tbody id="incidentsBody"><tr><td colspan="9" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Operadores / DPA (LGPD Art. 33 + 39) ── -->
  <section class="mst-section" id="msec-operators">
    <div class="mst-card" style="padding:0; overflow:hidden; margin-bottom:14px">
      <div style="display:flex; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10); flex-wrap:wrap">
        <div style="margin-right:auto">
          <div style="font-weight:700">Inventário de Operadores (Terceiros)</div>
          <div style="font-size:.74rem; color:#9ab0c9; margin-top:3px">
            Todos os terceiros que tratam dados pessoais em nome da Yuris. <strong>Art. 33</strong> (transferência internacional) + <strong>Art. 39</strong> (DPA / contrato com operador).
          </div>
        </div>
        <select id="filterOpCategoria" class="mst-form-select" style="width:auto;padding:6px 11px;font-size:.82rem">
          <option value="">Todas categorias</option>
          <option value="api_externa">API externa</option>
          <option value="hospedagem">Hospedagem</option>
          <option value="cdn">CDN</option>
          <option value="gateway_pagamento">Gateway pagamento</option>
          <option value="smtp">SMTP</option>
          <option value="llm_ia">LLM / IA</option>
          <option value="monitoramento">Monitoramento</option>
          <option value="suporte">Suporte</option>
          <option value="analytics">Analytics</option>
          <option value="backup">Backup</option>
          <option value="outro">Outro</option>
        </select>
        <select id="filterOpDpa" class="mst-form-select" style="width:auto;padding:6px 11px;font-size:.82rem">
          <option value="">Todos DPA</option>
          <option value="pendente">Pendente</option>
          <option value="em_negociacao">Em negociação</option>
          <option value="assinado">Assinado</option>
          <option value="vencido">Vencido</option>
          <option value="dispensado">Dispensado</option>
          <option value="rejeitado">Rejeitado</option>
        </select>
        <label style="font-size:.82rem;color:#9ab0c9;display:flex;align-items:center;gap:5px">
          <input type="checkbox" id="filterOpIntl"> Transf. internacional
        </label>
        <label style="font-size:.82rem;color:#9ab0c9;display:flex;align-items:center;gap:5px">
          <input type="checkbox" id="filterOpAtivos" checked> Apenas ativos
        </label>
        <button class="btn-mst" type="button" onclick="exportInventory()">Exportar Inventário</button>
        <button class="btn-mst btn-mst-primary" type="button" onclick="openNewOperator()">+ Novo Operador</button>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>#</th><th>Nome</th><th>Categoria</th><th>País</th><th>Intl?</th><th>DPA</th><th>Assinado</th><th>Validade</th><th>Ações</th></tr></thead>
        <tbody id="operatorsBody"><tr><td colspan="9" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>

  <!-- ── Revisões (uso INTERNO — pendências antes do go-live) ── -->
  <section class="mst-section" id="msec-whatsapp">
    <div class="mst-card" style="padding:18px; margin-bottom:14px">
      <div style="font-weight:700; margin-bottom:4px">Configuração Global da Evolution</div>
      <div style="font-size:.74rem; color:#9ab0c9; margin-bottom:12px">URL base + admin key do servidor Evolution (AUTHENTICATION_API_KEY). Usadas para CRIAR instâncias automaticamente. A admin key fica cifrada e nunca volta em claro.</div>
      <div style="display:grid; gap:12px; max-width:640px">
        <div><label class="mst-form-label">URL base da Evolution</label><input id="gevBaseUrl" class="mst-form-input" placeholder="https://evolution.inovaize.com"></div>
        <div><label class="mst-form-label">Admin key (global)</label><input id="gevAdminKey" class="mst-form-input" type="text" autocomplete="new-password" placeholder="deixe em branco para manter a atual"><span id="gevKeyHint" style="font-size:.72rem;color:#9ab0c9;display:block;margin-top:4px"></span></div>
        <div><label class="mst-form-label">Webhook canônico</label><input id="gevWebhook" class="mst-form-input" placeholder="https://yuris.com.br/api/whatsapp/webhook.php"></div>
        <div><button onclick="saveGlobalEvolution()" style="padding:9px 18px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer">Salvar config global</button></div>
      </div>
    </div>
    <div class="mst-card" style="padding:0; overflow:hidden; margin-bottom:14px">
      <div style="padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10)">
        <div style="font-weight:700">Instâncias por conta</div>
        <div style="font-size:.74rem; color:#9ab0c9; margin-top:3px">
          Conexão Evolution (URL, API Key, instância, webhook) de cada conta. Editável <strong>apenas aqui</strong>, pelo super admin. No painel da conta o cliente só conecta, gera QR e vê o status, nunca a chave.
        </div>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>Conta</th><th>Tipo</th><th>Instância</th><th>API Key</th><th></th></tr></thead>
        <tbody id="waCfgRows"><tr><td colspan="5" style="padding:14px;color:#9ab0c9">Carregando…</td></tr></tbody>
      </table>
    </div>
    <div class="mst-card" id="waCfgForm" style="display:none; padding:18px; margin-bottom:14px">
      <div style="font-weight:700; margin-bottom:12px" id="waCfgFormTitle">Conexão Evolution</div>
      <input type="hidden" id="waCfgAccountId">
      <div style="display:grid; gap:12px; max-width:640px">
        <div><label class="mst-form-label">URL da Evolution API</label><input id="waCfgBaseUrl" class="mst-form-input" placeholder="http://evolution:8080"></div>
        <div><label class="mst-form-label">API Key</label><input id="waCfgApiKey" class="mst-form-input" type="text" autocomplete="new-password" placeholder="deixe em branco para manter a atual"><span id="waCfgKeyHint" style="font-size:.72rem;color:#9ab0c9;display:block;margin-top:4px"></span></div>
        <div><label class="mst-form-label">Nome da instância</label><input id="waCfgInstance" class="mst-form-input" placeholder="ex: yuris-crm"></div>
        <div><label class="mst-form-label">URL do webhook</label><input id="waCfgWebhook" class="mst-form-input" placeholder="https://yuris.com.br/api/whatsapp/webhook.php"></div>
        <div style="display:flex; gap:10px; margin-top:4px">
          <button onclick="saveWhatsappConfig()" style="padding:9px 18px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer">Salvar conexão</button>
          <button onclick="document.getElementById('waCfgForm').style.display='none'" style="padding:9px 16px;background:transparent;border:1px solid rgba(160,180,210,.3);color:#9ab0c9;border-radius:8px;cursor:pointer">Cancelar</button>
        </div>
      </div>
    </div>
  </section>

  <section class="mst-section" id="msec-reviews">
    <div class="mst-card" style="padding:0; overflow:hidden; margin-bottom:14px">
      <div style="display:flex; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid rgba(160,180,210,.10); flex-wrap:wrap">
        <div style="margin-right:auto">
          <div style="font-weight:700">Revisões Internas (DPO / Diretoria)</div>
          <div style="font-size:.74rem; color:#9ab0c9; margin-top:3px">
            Pendências antes do go-live em produção. <strong>Visível apenas aqui</strong> — clientes nunca veem estes status. Itens com <span style="color:#dc2626;font-weight:700">bloqueador</span> impedem produção.
          </div>
        </div>
        <select id="filterRevStatus" class="mst-form-select" style="width:auto;padding:6px 11px;font-size:.82rem">
          <option value="">Todos status</option>
          <option value="pendente" selected>Pendente</option>
          <option value="em_revisao">Em revisão</option>
          <option value="bloqueado">Bloqueado</option>
          <option value="concluido">Concluído</option>
          <option value="dispensado">Dispensado</option>
        </select>
        <select id="filterRevCategoria" class="mst-form-select" style="width:auto;padding:6px 11px;font-size:.82rem">
          <option value="">Todas categorias</option>
          <option value="documento_legal">Documentos legais</option>
          <option value="operador_dpa">Operador/DPA</option>
          <option value="configuracao_env">Configuração .env</option>
          <option value="seguranca_tecnica">Segurança técnica</option>
          <option value="treinamento">Treinamento</option>
          <option value="auditoria_externa">Auditoria externa</option>
          <option value="designacao_dpo">Designação DPO</option>
          <option value="outro">Outro</option>
        </select>
        <select id="filterRevPrioridade" class="mst-form-select" style="width:auto;padding:6px 11px;font-size:.82rem">
          <option value="">Todas prioridades</option>
          <option value="critica">Crítica</option>
          <option value="alta">Alta</option>
          <option value="media">Média</option>
          <option value="baixa">Baixa</option>
        </select>
        <label style="font-size:.82rem;color:#9ab0c9;display:flex;align-items:center;gap:5px">
          <input type="checkbox" id="filterRevBloqueia"> Apenas bloqueadores
        </label>
      </div>
      <table class="mst-tbl">
        <thead><tr><th>#</th><th>Item</th><th>Categoria</th><th>Responsável</th><th>Prio.</th><th>Status</th><th>Bloq.</th><th>Ações</th></tr></thead>
        <tbody id="reviewsBody"><tr><td colspan="8" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </section>
</main>

<!-- ───────────────────────────────────────────────────────────────────────
     MODAIS
─────────────────────────────────────────────────────────────────────── -->

<!-- Modal: Nova Conta (matriz ou advogado-solo) -->
<div class="mst-modal-backdrop" id="modalAccount" onclick="if(event.target===this)closeModal('modalAccount')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title" id="accountModalTitle">Nova Matriz</h3>
      <button class="mst-modal-close" onclick="closeModal('modalAccount')">×</button>
    </div>
    <form id="formAccount" onsubmit="submitAccount(event)">
      <input type="hidden" name="tipo" id="accountTipo" value="matriz">
      <div class="mst-modal-body">
        <div class="mst-form-section" id="accountDataSection">Dados da Matriz</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Nome *</label><input name="account_nome" class="mst-form-input" required></div>
          <div><label class="mst-form-label" id="accountRazaoLabel">Razão Social</label><input name="account_razao" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label" id="accountCnpjLabel">CNPJ</label><input name="account_cnpj" class="mst-form-input" placeholder="apenas dígitos"></div>
          <div><label class="mst-form-label">E-mail</label><input name="account_email" class="mst-form-input" type="email"></div>
          <div><label class="mst-form-label">Telefone</label><input name="account_tel" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Cidade</label><input name="account_cidade" class="mst-form-input"></div>
          <div><label class="mst-form-label">UF</label><input name="account_uf" class="mst-form-input" maxlength="2" style="text-transform:uppercase"></div>
          <div><label class="mst-form-label">Status inicial</label>
            <select name="account_status" class="mst-form-select">
              <option value="trial" selected>Em teste (período de avaliação)</option>
              <option value="active">Ativa (já paga)</option>
            </select>
          </div>
        </div>

        <div class="mst-form-section" id="adminSection">Administrador da Conta</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label" id="admNomeLabel">Nome *</label><input name="adm_nome" class="mst-form-input" required></div>
          <div><label class="mst-form-label">E-mail (login) *</label><input name="adm_email" class="mst-form-input" type="email" required></div>
          <div><label class="mst-form-label">Telefone</label><input name="adm_tel" class="mst-form-input"></div>
        </div>
        <!-- OAB: aparece apenas quando tipo='advogado' -->
        <div class="mst-form-row" id="oabRow" style="display:none">
          <div><label class="mst-form-label">OAB *</label><input name="adm_oab" class="mst-form-input" placeholder="ex: 123456"></div>
          <div><label class="mst-form-label">UF da OAB *</label><input name="adm_oab_uf" class="mst-form-input" maxlength="2" style="text-transform:uppercase" placeholder="ex: SP"></div>
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
              <option value="quarterly">Trimestral</option>
              <option value="yearly">Anual</option>
            </select>
          </div>
          <div><label class="mst-form-label">Trial (dias)</label><input name="sub_trial_dias" class="mst-form-input" type="number" min="0" placeholder="usa do plano se vazio"></div>
        </div>

        <!-- ── Monitoramento (add-on opcional) ──────────────────────────
             Permite contratar monitoramento JÁ na criação da conta.
             Mesma operação backend do "+ Nova assinatura de monitor".
             Se a checkbox ficar desmarcada, nada é cobrado/registrado.
        -->
        <div class="mst-form-section" style="display:flex; align-items:center; gap:10px">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font:inherit; color:inherit">
            <input type="checkbox" name="mon_enable" id="monEnable" onchange="document.getElementById('monFields').style.display=this.checked?'':'none'" style="width:16px;height:16px;cursor:pointer">
            Monitoramento (add-on opcional)
          </label>
        </div>
        <div id="monFields" style="display:none">
          <div style="background:rgba(168,85,247,.08); border-left:3px solid #c084fc; padding:10px 12px; border-radius:6px; font-size:.82rem; color:#A8BDD4; margin-bottom:12px">
            Registra contrato comercial de monitoramento. <strong>Não dispara cobrança automática</strong> — gateway ainda não plugado. Os campos de preço/ciclo são metadados que viram MRR.
          </div>
          <div class="mst-form-row">
            <div>
              <label class="mst-form-label">Quantidade de monitors</label>
              <input name="mon_qtd" type="number" min="1" max="1000" class="mst-form-input" placeholder="ex: 10">
            </div>
            <div>
              <label class="mst-form-label">Preço unitário (R$)</label>
              <input name="mon_price" type="number" step="0.01" min="0" class="mst-form-input" placeholder="ex: 49.90">
              <div class="mst-form-help">Opcional — vira KPI de MRR</div>
            </div>
            <div>
              <label class="mst-form-label">Ciclo</label>
              <select name="mon_cycle" class="mst-form-select">
                <option value="monthly">Mensal</option>
                <option value="quarterly">Trimestral</option>
                <option value="yearly">Anual</option>
              </select>
            </div>
          </div>
          <div class="mst-form-row">
            <div>
              <label class="mst-form-label">Nº contrato / proposta</label>
              <input name="mon_contract" type="text" maxlength="120" class="mst-form-input" placeholder="ex: CONT-2026-001">
            </div>
            <div style="grid-column: span 2">
              <label class="mst-form-label">Observação interna</label>
              <input name="mon_obs" type="text" maxlength="200" class="mst-form-input" placeholder="ex: Pacote anual fechado via PIX">
            </div>
          </div>
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

        <div class="mst-form-section">WhatsApp da Filial</div>
        <div class="mst-form-help" style="margin-bottom:10px">Como esta filial vai usar o WhatsApp. O compartilhamento do canal da matriz só passa a valer quando o recurso estiver ativado no servidor.</div>
        <div class="mst-form-row full">
          <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;margin-bottom:8px">
            <input type="radio" name="whatsapp_mode" value="matriz" checked style="margin-top:3px">
            <span><strong>Usar o WhatsApp da matriz</strong> (padrão, recomendado)<br><span class="mst-form-help">A filial enxerga e atende pelo mesmo canal da matriz, sem precisar de um número próprio.</span></span>
          </label>
          <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;margin-bottom:8px">
            <input type="radio" name="whatsapp_mode" value="propria" style="margin-top:3px">
            <span><strong>WhatsApp próprio</strong><br><span class="mst-form-help">Cria uma instância nova na Evolution para esta filial (número separado).</span></span>
          </label>
          <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer">
            <input type="radio" name="whatsapp_mode" value="depois" style="margin-top:3px">
            <span><strong>Configurar depois</strong><br><span class="mst-form-help">A filial nasce sem WhatsApp; você define mais tarde.</span></span>
          </label>
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

<!-- Modal: Criar Nova Cobrança (invoice manual) -->
<div class="mst-modal-backdrop" id="modalNewInvoice" onclick="if(event.target===this)closeModal('modalNewInvoice')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">Nova Cobrança</h3>
      <button class="mst-modal-close" onclick="closeModal('modalNewInvoice')">×</button>
    </div>
    <form id="formNewInvoice" onsubmit="submitNewInvoice(event)">
      <div class="mst-modal-body">
        <div class="mst-form-section">Destinatário</div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Conta *</label>
            <select name="account_id" id="newInvAccount" class="mst-form-select" required></select>
          </div>
          <div>
            <label class="mst-form-label">Assinatura (opcional)</label>
            <select name="subscription_id" id="newInvSub" class="mst-form-select"><option value="">— vincular a uma assinatura —</option></select>
            <div class="mst-form-help">Se vincular, a fatura fica associada à subscription.</div>
          </div>
        </div>

        <div class="mst-form-section">Valor & Vencimento</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Valor (R$) *</label><input name="valor" id="newInvValor" class="mst-form-input" type="number" step="0.01" min="0.01" required></div>
          <div><label class="mst-form-label">Vencimento *</label><input name="due_date" id="newInvDue" class="mst-form-input" type="date" required></div>
          <div><label class="mst-form-label">Número (opcional)</label><input name="numero" id="newInvNumero" class="mst-form-input" placeholder="ex: FAT-001"></div>
        </div>

        <div class="mst-form-section">Descrição</div>
        <div class="mst-form-row full">
          <div><label class="mst-form-label">Descrição</label><input name="descricao" id="newInvDescricao" class="mst-form-input" placeholder="ex: Plano Básico — mensalidade junho/2026"></div>
        </div>
        <div class="mst-form-row full">
          <div><label class="mst-form-label">Observações</label><textarea name="observacoes" id="newInvObs" class="mst-form-textarea" placeholder="anotações internas de cobrança..."></textarea></div>
        </div>
      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst" onclick="closeModal('modalNewInvoice')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Criar Cobrança</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Marcar pagamento manual (edit invoice existing) -->
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

<!-- Modal: Editar/Criar Plano -->
<div class="mst-modal-backdrop" id="modalPlan" onclick="if(event.target===this)closeModal('modalPlan')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title" id="planModalTitle">Editar Plano</h3>
      <button class="mst-modal-close" onclick="closeModal('modalPlan')">×</button>
    </div>
    <form id="formPlan" onsubmit="submitPlan(event)">
      <input type="hidden" name="id" id="planId">
      <div class="mst-modal-body">
        <div class="mst-form-section">Identificação</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Slug *</label><input name="slug" id="planSlug" class="mst-form-input" required placeholder="ex: basico, profissional"></div>
          <div><label class="mst-form-label">Nome *</label><input name="nome" id="planNome" class="mst-form-input" required></div>
        </div>
        <div class="mst-form-row full">
          <div><label class="mst-form-label">Descrição</label><textarea name="descricao" id="planDesc" class="mst-form-textarea"></textarea></div>
        </div>

        <div class="mst-form-section">Preços & Período</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Mensal (R$)</label><input name="preco_mensal" id="planPM" class="mst-form-input" type="number" step="0.01" min="0" placeholder="149.00"></div>
          <div><label class="mst-form-label">Anual (R$)</label><input name="preco_anual" id="planPA" class="mst-form-input" type="number" step="0.01" min="0" placeholder="1490.00"></div>
          <div><label class="mst-form-label">Trial (dias)</label><input name="trial_dias" id="planTrial" class="mst-form-input" type="number" min="0"></div>
          <div><label class="mst-form-label">Ordem</label><input name="ordem" id="planOrdem" class="mst-form-input" type="number" min="0"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Status</label>
            <select name="ativo" id="planAtivo" class="mst-form-select">
              <option value="1">Ativo</option>
              <option value="0">Inativo</option>
            </select>
          </div>
          <div><label class="mst-form-label">Destaque</label>
            <select name="destaque" id="planDestaque" class="mst-form-select">
              <option value="0">Não</option>
              <option value="1">Sim (estrela)</option>
            </select>
          </div>
        </div>

        <div class="mst-form-section">Limites (deixe vazio = ilimitado · 0 = desabilitado)</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Max usuários</label><input id="feat_max_users" class="mst-form-input" type="number" min="0"></div>
          <div><label class="mst-form-label">Max processos</label><input id="feat_max_processos" class="mst-form-input" type="number" min="0"></div>
          <div><label class="mst-form-label">Max cards (CRM)</label><input id="feat_max_cards" class="mst-form-input" type="number" min="0"></div>
          <div><label class="mst-form-label">Max filiais</label><input id="feat_max_filiais" class="mst-form-input" type="number" min="0"></div>
        </div>

        <div class="mst-form-section">Módulos liberados</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">WhatsApp</label>
            <select id="feat_whatsapp_enabled" class="mst-form-select"><option value="1">Sim</option><option value="0">Não</option></select>
          </div>
          <div><label class="mst-form-label">Chat interno</label>
            <select id="feat_chat_interno" class="mst-form-select"><option value="1">Sim</option><option value="0">Não</option></select>
          </div>
          <div><label class="mst-form-label">Webhooks</label>
            <select id="feat_webhooks" class="mst-form-select"><option value="1">Sim</option><option value="0">Não</option></select>
          </div>
          <div><label class="mst-form-label">API externa</label>
            <select id="feat_integracoes_api" class="mst-form-select"><option value="1">Sim</option><option value="0">Não</option></select>
          </div>
        </div>
      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst" onclick="closeModal('modalPlan')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Salvar Plano</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Editar Assinatura -->
<div class="mst-modal-backdrop" id="modalSub" onclick="if(event.target===this)closeModal('modalSub')">
  <div class="mst-modal">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">Editar Assinatura</h3>
      <button class="mst-modal-close" onclick="closeModal('modalSub')">×</button>
    </div>
    <form id="formSub" onsubmit="submitSub(event)">
      <input type="hidden" name="subscription_id" id="subId">
      <div class="mst-modal-body">
        <div class="mst-detail-item" id="subAccountInfo" style="margin-bottom:14px"><div class="label">Conta</div><div class="value">—</div></div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Plano</label>
            <select name="plan_id" id="subPlanId" class="mst-form-select"></select>
          </div>
          <div><label class="mst-form-label">Status</label>
            <select name="status" id="subStatus" class="mst-form-select">
              <option value="trialing">Em teste</option>
              <option value="active">Ativa</option>
              <option value="past_due">Atrasada</option>
              <option value="canceled">Cancelada</option>
              <option value="unpaid">Não paga</option>
              <option value="incomplete">Incompleta</option>
            </select>
          </div>
          <div><label class="mst-form-label">Ciclo</label>
            <select name="billing_cycle" id="subCycle" class="mst-form-select">
              <option value="monthly">Mensal</option>
              <option value="quarterly">Trimestral</option>
              <option value="yearly">Anual</option>
            </select>
          </div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Teste grátis até</label><input name="trial_ends_at" id="subTrialEnd" class="mst-form-input" type="date"></div>
          <div><label class="mst-form-label">Fim do período atual</label><input name="current_period_end" id="subPeriodEnd" class="mst-form-input" type="date"></div>
        </div>
      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst" onclick="closeModal('modalSub')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Editar Conta (matriz/filial/advogado) -->
<div class="mst-modal-backdrop" id="modalEditAccount" onclick="if(event.target===this)closeModal('modalEditAccount')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">Editar Conta</h3>
      <button class="mst-modal-close" onclick="closeModal('modalEditAccount')">×</button>
    </div>
    <form id="formEditAccount" onsubmit="submitEditAccount(event)">
      <input type="hidden" name="id" id="editAccId">
      <div class="mst-modal-body">
        <div class="mst-form-section">Identificação</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Nome *</label><input name="nome" id="editAccNome" class="mst-form-input" required></div>
          <div><label class="mst-form-label">Razão Social</label><input name="razao_social" id="editAccRazao" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">CNPJ / CPF</label><input name="cnpj" id="editAccCnpj" class="mst-form-input"></div>
          <div><label class="mst-form-label">E-mail</label><input name="email" id="editAccEmail" class="mst-form-input" type="email"></div>
          <div><label class="mst-form-label">Telefone</label><input name="telefone" id="editAccTel" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Cidade</label><input name="cidade" id="editAccCidade" class="mst-form-input"></div>
          <div><label class="mst-form-label">UF</label><input name="estado" id="editAccUf" class="mst-form-input" maxlength="2" style="text-transform:uppercase"></div>
        </div>

        <div class="mst-form-section">Status & Plano</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Tipo</label>
            <select name="tipo" id="editAccTipo" class="mst-form-select">
              <option value="matriz">Matriz</option>
              <option value="filial">Filial</option>
              <option value="advogado">Advogado (Solo)</option>
            </select>
            <div class="mst-form-help" style="color:#fbbf24">Atenção — mudar tipo é arriscado. Use só se sabe o que está fazendo.</div>
          </div>
          <div><label class="mst-form-label">Status da conta</label>
            <select name="status" id="editAccStatus" class="mst-form-select">
              <option value="active">Ativa</option>
              <option value="trial">Em teste</option>
              <option value="overdue">Em atraso</option>
              <option value="suspended">Suspensa</option>
              <option value="cancelled">Cancelada</option>
              <option value="inactive">Inativa</option>
            </select>
            <div class="mst-form-help">Controla acesso ao sistema. O status financeiro fica no bloco Assinatura abaixo.</div>
          </div>
          <div><label class="mst-form-label">Plano (slug cache)</label>
            <select name="plano" id="editAccPlano" class="mst-form-select">
              <option value="">— Carregando…</option>
            </select>
            <div class="mst-form-help">String legada. Plano real é via Assinaturas.</div>
          </div>
        </div>

        <!-- Assinatura — só aparece se a conta tiver uma assinatura ativa -->
        <div id="editAccSubBlock" style="display:none">
          <div class="mst-form-section">Assinatura</div>
          <input type="hidden" id="editAccSubId">
          <div class="mst-form-row">
            <div><label class="mst-form-label">Ciclo de cobrança</label>
              <select id="editAccSubCycle" class="mst-form-select">
                <option value="monthly">Mensal</option>
                <option value="quarterly">Trimestral</option>
                <option value="yearly">Anual</option>
              </select>
            </div>
            <div><label class="mst-form-label">Teste grátis até</label>
              <input id="editAccSubTrial" type="date" class="mst-form-input">
              <div class="mst-form-help">Data limite do período de avaliação gratuita (depois precisa pagar pra continuar usando).</div>
            </div>
            <div><label class="mst-form-label">Período até</label>
              <input id="editAccSubPeriod" type="date" class="mst-form-input">
              <div class="mst-form-help">Data até quando a conta fica ativa (pós-pagamento ou pós-trial).</div>
            </div>
          </div>
        </div>

        <!-- ── Monitoramentos (add-on) ─────────────────────────────────
             Inline edit: input direto pra ajustar o total contratado.
             "+ Registrar compra" continua disponível pra contratos
             comerciais com metadados (preço/ciclo/nº contrato).
        -->
        <div class="mst-form-section">Monitoramentos (add-on)</div>
        <div id="editAccMonitorSummary" style="background:rgba(91,155,213,.06); border:1px solid rgba(91,155,213,.18); border-radius:8px; padding:12px 14px; margin-bottom:10px; display:flex; gap:18px; align-items:center; flex-wrap:wrap">
          <div style="flex:0 0 auto">
            <div class="label" style="font-size:.7rem; color:#9ab0c9; margin-bottom:4px">CONTRATADOS</div>
            <div style="display:flex; gap:6px; align-items:center">
              <input type="number" id="editAccMonLimit" min="0" max="9999" value="0"
                     class="mst-form-input" style="width:80px; font-size:1.15rem; font-weight:700; text-align:center">
              <button type="button" class="btn-mst btn-mst-primary" onclick="saveMonitorLimit()" style="padding:7px 14px">Salvar</button>
            </div>
          </div>
          <div>
            <div class="label" style="font-size:.7rem; color:#9ab0c9">EM USO</div>
            <div style="font-size:1.25rem; font-weight:700" id="editAccMonUsed">—</div>
          </div>
          <div>
            <div class="label" style="font-size:.7rem; color:#9ab0c9">DISPONÍVEL</div>
            <div style="font-size:1.25rem; font-weight:700" id="editAccMonAvail">—</div>
          </div>
          <div style="margin-left:auto">
            <button type="button" class="btn-mst" onclick="openPurchaseMonitorModal()" title="Registrar contrato comercial com nº de contrato, preço, ciclo">+ Registrar compra/contrato</button>
          </div>
        </div>
        <div style="font-size:.74rem; color:#9ab0c9; margin-top:-4px; margin-bottom:10px">
          ↑ Edite o número e clique <strong>Salvar</strong> pra adicionar ou remover monitoramentos.
          Use <strong>+ Registrar compra</strong> só pra contratos comerciais com nº de contrato e preço.
        </div>
        <div id="editAccMonOverrides" style="font-size:.85rem"></div>
        <!-- ────────────────────────────────────────────────────────── -->

      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst btn-mst-danger" onclick="deleteAccount()" style="margin-right:auto">Excluir conta…</button>
        <button type="button" class="btn-mst" onclick="closeModal('modalEditAccount')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Nova/Editar Despesa -->
<div class="mst-modal-backdrop" id="modalExpense" onclick="if(event.target===this)closeModal('modalExpense')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title" id="expenseModalTitle">Nova Despesa</h3>
      <button class="mst-modal-close" onclick="closeModal('modalExpense')">×</button>
    </div>
    <form id="formExpense" onsubmit="submitExpense(event)">
      <input type="hidden" name="id" id="expId">
      <div class="mst-modal-body">
        <div class="mst-form-section">Identificação</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Categoria *</label>
            <select name="categoria" id="expCategoria" class="mst-form-select" required>
              <option value="servidor">Servidor / Hosting</option>
              <option value="pessoas">Pessoas / Salários</option>
              <option value="apis">APIs externas</option>
              <option value="marketing">Marketing / Anúncios</option>
              <option value="infraestrutura">Infraestrutura</option>
              <option value="impostos">Impostos</option>
              <option value="software">Software / Licenças</option>
              <option value="juridico">Jurídico / Contábil</option>
              <option value="outros">Outros</option>
            </select>
          </div>
          <div><label class="mst-form-label">Fornecedor</label><input name="fornecedor" id="expFornecedor" class="mst-form-input" placeholder="ex: DigitalOcean, Stripe, etc."></div>
        </div>
        <div class="mst-form-row full">
          <div><label class="mst-form-label">Descrição *</label><input name="descricao" id="expDescricao" class="mst-form-input" required placeholder="ex: Hospedagem VPS Produção"></div>
        </div>

        <div class="mst-form-section">Valor & Datas</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Valor (R$) *</label><input name="valor" id="expValor" class="mst-form-input" type="number" step="0.01" min="0.01" required></div>
          <div><label class="mst-form-label">Competência *</label><input name="data_competencia" id="expCompetencia" class="mst-form-input" type="date" required>
            <div class="mst-form-help">Mês a que pertence</div>
          </div>
          <div><label class="mst-form-label">Vencimento</label><input name="vencimento" id="expVencimento" class="mst-form-input" type="date"></div>
          <div><label class="mst-form-label">Data Pagamento</label><input name="data_pagamento" id="expPagamento" class="mst-form-input" type="date"></div>
        </div>

        <div class="mst-form-section">Status & Recorrência</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Status</label>
            <select name="status" id="expStatus" class="mst-form-select">
              <option value="pendente">Pendente</option>
              <option value="pago">Pago</option>
              <option value="atrasado">Atrasado</option>
              <option value="cancelado">Cancelado</option>
            </select>
          </div>
          <div><label class="mst-form-label">Recorrência</label>
            <select name="recorrencia" id="expRecorrencia" class="mst-form-select">
              <option value="nenhuma">Avulsa (não recorre)</option>
              <option value="mensal">Mensal</option>
              <option value="anual">Anual</option>
            </select>
          </div>
          <div><label class="mst-form-label">Método de Pagamento</label>
            <select name="metodo_pagamento" id="expMetodo" class="mst-form-select">
              <option value="">—</option>
              <option value="cartao">Cartão de crédito</option>
              <option value="pix">PIX</option>
              <option value="boleto">Boleto</option>
              <option value="debito">Débito</option>
              <option value="transferencia">Transferência</option>
              <option value="outro">Outro</option>
            </select>
          </div>
        </div>
        <div class="mst-form-row full">
          <div><label class="mst-form-label">Observações</label><textarea name="observacoes" id="expObs" class="mst-form-textarea" placeholder="anotações sobre o gasto, justificativa, link de nota..."></textarea></div>
        </div>
      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst btn-mst-danger" onclick="deleteExpense()" style="margin-right:auto" id="expDeleteBtn">Excluir</button>
        <button type="button" class="btn-mst" onclick="closeModal('modalExpense')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Editar Usuário -->
<!-- ────────────────────────────────────────────────────────────────────
     Modal: Liberar grant gratuito de monitor (cortesia/promo)
     Etapa 6 add-on Monitoramentos
──────────────────────────────────────────────────────────────────── -->
<div class="mst-modal-backdrop" id="modalGrantMonitor" onclick="if(event.target===this)closeModal('modalGrantMonitor')">
  <div class="mst-modal">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">+ Liberar grant gratuito</h3>
      <button class="mst-modal-close" onclick="closeModal('modalGrantMonitor')">×</button>
    </div>
    <form onsubmit="submitGrantMonitor(event)">
      <input type="hidden" name="account_id" id="grantMonAccountId">
      <div class="mst-modal-body">
        <div style="background:rgba(168,207,238,.08); border-left:3px solid #5b9bd5; padding:10px 12px; border-radius:6px; font-size:.82rem; color:#A8BDD4; margin-bottom:14px">
          Use pra cortesia, promoção, trial estendido ou compensar bug/incidente.
          Cliente vai ver a quantidade liberada IMEDIATAMENTE.
        </div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Quantidade de monitors *</label>
            <input name="qtd" type="number" min="1" max="500" required class="mst-form-input" placeholder="1">
            <div class="mst-form-help">Quantos monitoramentos extras essa conta vai ter.</div>
          </div>
          <div>
            <label class="mst-form-label">Expira em (opcional)</label>
            <input name="expires" type="date" class="mst-form-input">
            <div class="mst-form-help">Vazio = nunca expira. Útil pra trial temporário.</div>
          </div>
        </div>
        <div>
          <label class="mst-form-label">Observação (interna)</label>
          <textarea name="obs" rows="2" class="mst-form-input" placeholder="Ex: Cortesia lançamento Q2-2026"></textarea>
        </div>
      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst" onclick="closeModal('modalGrantMonitor')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Liberar agora</button>
      </div>
    </form>
  </div>
</div>

<!-- ────────────────────────────────────────────────────────────────────
     Modal: Registrar compra/contrato comercial de monitors
     Preparado pra gateway futuro mas SEM cobrança automática agora.
──────────────────────────────────────────────────────────────────── -->
<div class="mst-modal-backdrop" id="modalPurchaseMonitor" onclick="if(event.target===this)closeModal('modalPurchaseMonitor')">
  <div class="mst-modal">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">+ Registrar compra / contrato</h3>
      <button class="mst-modal-close" onclick="closeModal('modalPurchaseMonitor')">×</button>
    </div>
    <form onsubmit="submitPurchaseMonitor(event)">
      <input type="hidden" name="account_id" id="purchMonAccountId">
      <div class="mst-modal-body">
        <div style="background:rgba(91,155,213,.08); border-left:3px solid #5b9bd5; padding:10px 12px; border-radius:6px; font-size:.82rem; color:#A8BDD4; margin-bottom:14px">
          Registra contrato comercial. Cliente recebe os monitors imediatamente.
          <strong>Não dispara cobrança automática</strong> — gateway ainda não está
          implementado. Os campos de preço/ciclo são metadados.
        </div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Quantidade contratada *</label>
            <input name="qtd" type="number" min="1" max="1000" required class="mst-form-input" placeholder="10">
          </div>
          <div>
            <label class="mst-form-label">Preço unitário (R$ — opcional)</label>
            <input name="price" type="number" step="0.01" min="0" class="mst-form-input" placeholder="49.90">
            <div class="mst-form-help">Metadado. Vira KPI de MRR no Master.</div>
          </div>
          <div>
            <label class="mst-form-label">Ciclo</label>
            <select name="cycle" class="mst-form-select">
              <option value="">— escolha —</option>
              <option value="monthly">Mensal</option>
              <option value="quarterly">Trimestral</option>
              <option value="yearly">Anual</option>
              <option value="one_off">Pagamento único</option>
            </select>
          </div>
        </div>
        <div>
          <label class="mst-form-label">Nº de contrato / proposta</label>
          <input name="contract" type="text" maxlength="120" class="mst-form-input" placeholder="Ex: CONT-2026-001">
        </div>
        <div>
          <label class="mst-form-label">Observação (interna)</label>
          <textarea name="obs" rows="2" class="mst-form-input" placeholder="Ex: Contrato anual — pago via PIX"></textarea>
        </div>
      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst" onclick="closeModal('modalPurchaseMonitor')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Registrar compra</button>
      </div>
    </form>
  </div>
</div>

<!-- ────────────────────────────────────────────────────────────────────
     Modal: Nova assinatura de monitor (atalho da aba Assinaturas)
     Permite Master registrar compra de monitor sem ir em Contas →
     Editar conta → Monitoramentos. Mesma operação backend, UX mais direta.
──────────────────────────────────────────────────────────────────── -->
<div class="mst-modal-backdrop" id="modalNovaMonitorSub" onclick="if(event.target===this)closeModal('modalNovaMonitorSub')">
  <div class="mst-modal">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">+ Nova assinatura de monitor</h3>
      <button class="mst-modal-close" onclick="closeModal('modalNovaMonitorSub')">×</button>
    </div>
    <form onsubmit="submitNovaMonitorSub(event)">
      <div class="mst-modal-body">
        <div style="background:rgba(168,85,247,.08); border-left:3px solid #c084fc; padding:10px 12px; border-radius:6px; font-size:.82rem; color:#A8BDD4; margin-bottom:14px">
          Registra contrato comercial de monitoramento (add-on do plano).
          Vai aparecer nesta tela como assinatura ativa. <strong>Não dispara
          cobrança automática</strong> — gateway será integrado em etapa futura.
        </div>
        <div>
          <label class="mst-form-label">Conta *</label>
          <select name="account_id" id="novaMonSubAccount" class="mst-form-select" required>
            <option value="">— escolha a conta —</option>
          </select>
          <div class="mst-form-help">Carregada da aba Contas.</div>
        </div>
        <div class="mst-form-row" style="margin-top:10px">
          <div>
            <label class="mst-form-label">Quantidade de monitors *</label>
            <input name="qtd" type="number" min="1" max="1000" required class="mst-form-input" placeholder="10">
          </div>
          <div>
            <label class="mst-form-label">Preço unitário (R$ — opcional)</label>
            <input name="price" type="number" step="0.01" min="0" class="mst-form-input" placeholder="49.90">
          </div>
          <div>
            <label class="mst-form-label">Ciclo *</label>
            <select name="cycle" class="mst-form-select" required>
              <option value="monthly">Mensal</option>
              <option value="quarterly">Trimestral</option>
              <option value="yearly">Anual</option>
            </select>
          </div>
        </div>
        <div style="margin-top:10px">
          <label class="mst-form-label">Nº de contrato / proposta</label>
          <input name="contract" type="text" maxlength="120" class="mst-form-input" placeholder="Ex: CONT-2026-001">
        </div>
        <div style="margin-top:10px">
          <label class="mst-form-label">Observação (interna)</label>
          <textarea name="obs" rows="2" class="mst-form-input" placeholder="Ex: Contrato trimestral pago via PIX"></textarea>
        </div>
      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst" onclick="closeModal('modalNovaMonitorSub')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Criar assinatura</button>
      </div>
    </form>
  </div>
</div>

<div class="mst-modal-backdrop" id="modalEditUser" onclick="if(event.target===this)closeModal('modalEditUser')">
  <div class="mst-modal">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">Editar Usuário</h3>
      <button class="mst-modal-close" onclick="closeModal('modalEditUser')">×</button>
    </div>
    <form id="formEditUser" onsubmit="submitEditUser(event)">
      <input type="hidden" name="id" id="editUserId">
      <div class="mst-modal-body">
        <div class="mst-detail-item" style="margin-bottom:14px"><div class="label">Conta</div><div class="value" id="editUserAccountInfo">—</div></div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Nome *</label><input name="nome" id="editUserNome" class="mst-form-input" required></div>
          <div><label class="mst-form-label">E-mail (login) *</label><input name="email" id="editUserEmail" class="mst-form-input" type="email" required></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Telefone</label><input name="telefone" id="editUserTel" class="mst-form-input"></div>
          <div><label class="mst-form-label">Role</label>
            <select name="role" id="editUserRole" class="mst-form-select">
              <option value="owner">Proprietário</option>
              <option value="admin">Administrador</option>
              <option value="manager">Gerente</option>
              <option value="user">Usuário</option>
              <option value="viewer">Leitor</option>
            </select>
          </div>
          <div><label class="mst-form-label">Status</label>
            <select name="status" id="editUserStatus" class="mst-form-select">
              <option value="active">Ativo</option>
              <option value="inactive">Inativo</option>
            </select>
          </div>
        </div>
        <div class="mst-form-row" id="editUserAdvRow" style="display:none">
          <div><label class="mst-form-label">OAB</label><input name="oab" id="editUserOab" class="mst-form-input"></div>
          <div><label class="mst-form-label">UF da OAB</label><input name="oab_uf" id="editUserOabUf" class="mst-form-input" maxlength="2" style="text-transform:uppercase"></div>
          <div><label class="mst-form-label">Código</label><input id="editUserCodAdv" class="mst-form-input" readonly></div>
        </div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Nova senha (opcional)</label>
            <input name="nova_senha" id="editUserNewPass" class="mst-form-input" type="text" placeholder="deixe vazio pra não alterar">
            <div class="mst-form-help">Ou use o botão "Reset senha" pra gerar uma automaticamente.</div>
          </div>
        </div>
      </div>
      <div class="mst-modal-foot">
        <button type="button" class="btn-mst btn-mst-danger" onclick="deleteUser()" style="margin-right:auto">Excluir user</button>
        <button type="button" class="btn-mst" onclick="resetUserPassword()">Reset senha</button>
        <button type="button" class="btn-mst" onclick="closeModal('modalEditUser')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: LGPD — detalhes da solicitação -->
<div class="mst-modal-backdrop" id="modalLgpd" onclick="if(event.target===this)closeModal('modalLgpd')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title" id="lgpdDrawerTitle">Solicitação LGPD</h3>
      <button class="mst-modal-close" onclick="closeModal('modalLgpd')">×</button>
    </div>
    <div class="mst-modal-body" id="lgpdDrawerBody" style="max-height:75vh;overflow-y:auto"></div>
  </div>
</div>

<!-- Modal: Incidente — detalhes -->
<div class="mst-modal-backdrop" id="modalIncident" onclick="if(event.target===this)closeModal('modalIncident')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title" id="incidentTitle">Incidente de Segurança</h3>
      <button class="mst-modal-close" onclick="closeModal('modalIncident')">×</button>
    </div>
    <div class="mst-modal-body" id="incidentBody" style="max-height:78vh;overflow-y:auto"></div>
  </div>
</div>

<!-- Modal: Revisão — detalhes -->
<div class="mst-modal-backdrop" id="modalReview" onclick="if(event.target===this)closeModal('modalReview')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title" id="reviewTitle">Item de Revisão</h3>
      <button class="mst-modal-close" onclick="closeModal('modalReview')">×</button>
    </div>
    <div class="mst-modal-body" id="reviewBody" style="max-height:78vh;overflow-y:auto"></div>
  </div>
</div>

<!-- Modal: Operador — detalhes -->
<div class="mst-modal-backdrop" id="modalOperator" onclick="if(event.target===this)closeModal('modalOperator')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title" id="operatorTitle">Operador</h3>
      <button class="mst-modal-close" onclick="closeModal('modalOperator')">×</button>
    </div>
    <div class="mst-modal-body" id="operatorBody" style="max-height:78vh;overflow-y:auto"></div>
  </div>
</div>

<!-- Modal: Novo Operador -->
<div class="mst-modal-backdrop" id="modalNewOperator" onclick="if(event.target===this)closeModal('modalNewOperator')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">Adicionar Operador</h3>
      <button class="mst-modal-close" onclick="closeModal('modalNewOperator')">×</button>
    </div>
    <form id="formNewOperator" onsubmit="submitNewOperator(event)">
      <div class="mst-modal-body">
        <div class="mst-form-section">Identificação</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Nome *</label><input name="nome" class="mst-form-input" required maxlength="150"></div>
          <div>
            <label class="mst-form-label">Categoria *</label>
            <select name="categoria" class="mst-form-select" required>
              <option value="">Selecione...</option>
              <option value="api_externa">API externa</option>
              <option value="hospedagem">Hospedagem</option>
              <option value="cdn">CDN</option>
              <option value="gateway_pagamento">Gateway pagamento</option>
              <option value="smtp">SMTP</option>
              <option value="llm_ia">LLM / IA</option>
              <option value="monitoramento">Monitoramento</option>
              <option value="suporte">Suporte</option>
              <option value="analytics">Analytics</option>
              <option value="backup">Backup</option>
              <option value="outro">Outro</option>
            </select>
          </div>
          <div>
            <label class="mst-form-label">Papel</label>
            <select name="papel" class="mst-form-select">
              <option value="operador" selected>Operador</option>
              <option value="suboperador">Suboperador</option>
              <option value="controlador_conjunto">Controlador conjunto</option>
            </select>
          </div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">CNPJ / ID estrangeiro</label><input name="cnpj_ou_id" class="mst-form-input" maxlength="100"></div>
          <div><label class="mst-form-label">País (ISO 3166-1 alpha-2)</label><input name="pais" class="mst-form-input" maxlength="2" placeholder="BR, US, DE..." style="text-transform:uppercase"></div>
          <div><label class="mst-form-label">Contato DPO terceiro (e-mail)</label><input name="contato_dpo_terceiro" type="email" class="mst-form-input"></div>
        </div>

        <div class="mst-form-section">Tratamento de dados</div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Categorias de dados tratados</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;font-size:.82rem;color:#cbd5e1">
              <label><input type="checkbox" name="cat_pii_basica"> PII básica</label>
              <label><input type="checkbox" name="cat_documentos"> Documentos</label>
              <label><input type="checkbox" name="cat_financeiro"> Financeiro</label>
              <label><input type="checkbox" name="cat_juridico"> Jurídico</label>
              <label><input type="checkbox" name="cat_autenticacao"> Autenticação</label>
              <label><input type="checkbox" name="cat_comunicacoes"> Comunicações</label>
              <label><input type="checkbox" name="cat_sensiveis"> Sensíveis</label>
            </div>
          </div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Finalidade *</label><textarea name="finalidade" rows="2" class="mst-form-input" required placeholder="Para qual finalidade os dados são compartilhados?"></textarea></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Retenção pelo terceiro</label><input name="retencao_terceiro" class="mst-form-input" placeholder="Ex: 30 dias após término do contrato"></div>
        </div>

        <div class="mst-form-section">Transferência internacional (Art. 33)</div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Há transferência internacional?</label>
            <select name="transferencia_internacional" class="mst-form-select" id="opIntlSel" onchange="toggleBaseLegal()">
              <option value="0" selected>Não</option>
              <option value="1">Sim</option>
            </select>
          </div>
          <div id="opBaseLegalWrap" style="display:none;flex:2">
            <label class="mst-form-label">Base legal da transferência *</label>
            <select name="base_legal_transferencia" class="mst-form-select">
              <option value="">Selecione...</option>
              <option value="clausulas_contratuais_padrao">Cláusulas contratuais padrão</option>
              <option value="regras_corporativas_globais">Regras corporativas globais</option>
              <option value="decisao_anpd_adequacao">Decisão ANPD (adequação)</option>
              <option value="autorizacao_anpd_especifica">Autorização ANPD específica</option>
              <option value="cooperacao_juridica_internacional">Cooperação jurídica internacional</option>
              <option value="protecao_vida">Proteção da vida</option>
              <option value="cumprimento_obrigacao_legal">Cumprimento obrigação legal</option>
              <option value="execucao_contrato_titular">Execução de contrato com titular</option>
              <option value="consentimento_especifico">Consentimento específico</option>
              <option value="garantias_outras">Outras garantias</option>
            </select>
          </div>
        </div>

        <div class="mst-form-section">DPA (Art. 39)</div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Status DPA</label>
            <select name="dpa_status" class="mst-form-select">
              <option value="pendente" selected>Pendente</option>
              <option value="em_negociacao">Em negociação</option>
              <option value="assinado">Assinado</option>
              <option value="dispensado">Dispensado (justificado)</option>
            </select>
          </div>
          <div><label class="mst-form-label">Assinado em</label><input name="dpa_assinado_em" type="date" class="mst-form-input"></div>
          <div><label class="mst-form-label">Validade</label><input name="dpa_validade" type="date" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">URL/Path do PDF</label><input name="dpa_url" class="mst-form-input" placeholder="storage/dpa/..."></div>
          <div><label class="mst-form-label">URL da Política de Privacidade do terceiro</label><input name="url_politica_privacidade" class="mst-form-input" placeholder="https://..."></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Certificações</label><input name="certificacoes" class="mst-form-input" placeholder="ISO 27001, SOC 2 Type II..."></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Notas</label><textarea name="notas" rows="2" class="mst-form-input"></textarea></div>
        </div>
      </div>
      <div class="mst-modal-footer">
        <button type="button" class="btn-mst" onclick="closeModal('modalNewOperator')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Adicionar Operador</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Editar Operador (dados completos) -->
<div class="mst-modal-backdrop" id="modalEditOperator" onclick="if(event.target===this)closeModal('modalEditOperator')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title" id="editOpTitle">Editar Operador</h3>
      <button class="mst-modal-close" onclick="closeModal('modalEditOperator')">×</button>
    </div>
    <form id="formEditOperator" onsubmit="submitEditOperator(event)">
      <input type="hidden" name="id" id="editOpId">
      <div class="mst-modal-body">
        <div class="mst-form-section">Identificação</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Nome *</label><input name="nome" id="editOpNome" class="mst-form-input" required maxlength="150"></div>
          <div>
            <label class="mst-form-label">Categoria *</label>
            <select name="categoria" id="editOpCategoria" class="mst-form-select" required>
              <option value="api_externa">API externa</option>
              <option value="hospedagem">Hospedagem</option>
              <option value="cdn">CDN</option>
              <option value="gateway_pagamento">Gateway pagamento</option>
              <option value="smtp">SMTP</option>
              <option value="llm_ia">LLM / IA</option>
              <option value="monitoramento">Monitoramento</option>
              <option value="suporte">Suporte</option>
              <option value="analytics">Analytics</option>
              <option value="backup">Backup</option>
              <option value="outro">Outro</option>
            </select>
          </div>
          <div>
            <label class="mst-form-label">Papel</label>
            <select name="papel" id="editOpPapel" class="mst-form-select">
              <option value="operador">Operador</option>
              <option value="suboperador">Suboperador</option>
              <option value="controlador_conjunto">Controlador conjunto</option>
            </select>
          </div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">CNPJ / ID estrangeiro</label><input name="cnpj_ou_id" id="editOpCnpj" class="mst-form-input" maxlength="100"></div>
          <div><label class="mst-form-label">País (ISO 3166-1 alpha-2)</label><input name="pais" id="editOpPais" class="mst-form-input" maxlength="2" placeholder="BR, US, DE..." style="text-transform:uppercase"></div>
          <div><label class="mst-form-label">Contato DPO terceiro (e-mail)</label><input name="contato_dpo_terceiro" id="editOpDpoMail" type="email" class="mst-form-input"></div>
        </div>

        <div class="mst-form-section">Tratamento de dados</div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Categorias de dados tratados</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;font-size:.82rem;color:#cbd5e1">
              <label><input type="checkbox" name="cat_pii_basica" id="editOpCatPii"> PII básica</label>
              <label><input type="checkbox" name="cat_documentos" id="editOpCatDoc"> Documentos</label>
              <label><input type="checkbox" name="cat_financeiro" id="editOpCatFin"> Financeiro</label>
              <label><input type="checkbox" name="cat_juridico"   id="editOpCatJur"> Jurídico</label>
              <label><input type="checkbox" name="cat_autenticacao" id="editOpCatAuth"> Autenticação</label>
              <label><input type="checkbox" name="cat_comunicacoes" id="editOpCatCom"> Comunicações</label>
              <label><input type="checkbox" name="cat_sensiveis"  id="editOpCatSens"> Sensíveis</label>
            </div>
          </div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Finalidade *</label><textarea name="finalidade" id="editOpFinalidade" rows="2" class="mst-form-input" required></textarea></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Retenção pelo terceiro</label><input name="retencao_terceiro" id="editOpRetencao" class="mst-form-input"></div>
        </div>

        <div class="mst-form-section">Transferência internacional (Art. 33)</div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Há transferência internacional?</label>
            <select name="transferencia_internacional" class="mst-form-select" id="editOpIntlSel" onchange="toggleBaseLegalEdit()">
              <option value="0">Não</option>
              <option value="1">Sim</option>
            </select>
          </div>
          <div id="editOpBaseLegalWrap" style="display:none;flex:2">
            <label class="mst-form-label">Base legal da transferência *</label>
            <select name="base_legal_transferencia" id="editOpBaseLegal" class="mst-form-select">
              <option value="">Selecione...</option>
              <option value="clausulas_contratuais_padrao">Cláusulas contratuais padrão</option>
              <option value="regras_corporativas_globais">Regras corporativas globais</option>
              <option value="decisao_anpd_adequacao">Decisão ANPD (adequação)</option>
              <option value="autorizacao_anpd_especifica">Autorização ANPD específica</option>
              <option value="cooperacao_juridica_internacional">Cooperação jurídica internacional</option>
              <option value="protecao_vida">Proteção da vida</option>
              <option value="cumprimento_obrigacao_legal">Cumprimento obrigação legal</option>
              <option value="execucao_contrato_titular">Execução de contrato com titular</option>
              <option value="consentimento_especifico">Consentimento específico</option>
              <option value="garantias_outras">Outras garantias</option>
            </select>
          </div>
        </div>

        <div class="mst-form-section">DPA (Art. 39)</div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Status DPA</label>
            <select name="dpa_status" id="editOpDpaStatus" class="mst-form-select">
              <option value="pendente">Pendente</option>
              <option value="em_negociacao">Em negociação</option>
              <option value="assinado">Assinado</option>
              <option value="dispensado">Dispensado</option>
              <option value="vencido">Vencido</option>
              <option value="rejeitado">Rejeitado</option>
            </select>
          </div>
          <div><label class="mst-form-label">Assinado em</label><input name="dpa_assinado_em" id="editOpDpaAss" type="date" class="mst-form-input"></div>
          <div><label class="mst-form-label">Validade</label><input name="dpa_validade" id="editOpDpaVal" type="date" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">URL/Path do PDF</label><input name="dpa_url" id="editOpDpaUrl" class="mst-form-input"></div>
          <div><label class="mst-form-label">URL Política Privacidade</label><input name="url_politica_privacidade" id="editOpUrlPriv" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Certificações</label><input name="certificacoes" id="editOpCert" class="mst-form-input"></div>
        </div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Notas</label><textarea name="notas" id="editOpNotas" rows="2" class="mst-form-input"></textarea></div>
        </div>
      </div>
      <div class="mst-modal-footer">
        <button type="button" class="btn-mst" onclick="closeModal('modalEditOperator')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Salvar alterações</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Novo Incidente -->
<div class="mst-modal-backdrop" id="modalNewIncident" onclick="if(event.target===this)closeModal('modalNewIncident')">
  <div class="mst-modal lg">
    <div class="mst-modal-header">
      <h3 class="mst-modal-title">Registrar Novo Incidente</h3>
      <button class="mst-modal-close" onclick="closeModal('modalNewIncident')">×</button>
    </div>
    <form id="formNewIncident" onsubmit="submitNewIncident(event)">
      <div class="mst-modal-body">
        <div class="mst-form-section">Classificação</div>
        <div class="mst-form-row">
          <div><label class="mst-form-label">Título *</label><input name="titulo" class="mst-form-input" required maxlength="200" placeholder="Ex.: Acesso indevido ao painel master via brute-force"></div>
        </div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Tipo *</label>
            <select name="tipo" class="mst-form-select" required>
              <option value="">Selecione...</option>
              <option value="vazamento_dados">Vazamento de dados</option>
              <option value="acesso_indevido">Acesso indevido</option>
              <option value="ransomware">Ransomware</option>
              <option value="phishing">Phishing</option>
              <option value="dos_ddos">DoS / DDoS</option>
              <option value="exposicao_credenciais">Exposição de credenciais</option>
              <option value="perda_dispositivo">Perda de dispositivo</option>
              <option value="engenharia_social">Engenharia social</option>
              <option value="config_indevida">Configuração indevida</option>
              <option value="outro">Outro</option>
            </select>
          </div>
          <div>
            <label class="mst-form-label">Severidade *</label>
            <select name="severidade" class="mst-form-select" required>
              <option value="baixa">Baixa</option>
              <option value="media" selected>Média</option>
              <option value="alta">Alta</option>
              <option value="critica">Crítica</option>
            </select>
          </div>
          <div>
            <label class="mst-form-label">Conta afetada (ID)</label>
            <input name="account_id" type="number" class="mst-form-input" placeholder="vazio = plataforma">
          </div>
        </div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Detectado em *</label>
            <input name="detectado_em" type="datetime-local" class="mst-form-input" required>
          </div>
          <div>
            <label class="mst-form-label">Ocorrido em (estimado)</label>
            <input name="ocorrido_em" type="datetime-local" class="mst-form-input">
          </div>
        </div>

        <div class="mst-form-section">Impacto</div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Dados afetados (categorias)</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;font-size:.82rem;color:#cbd5e1">
              <label><input type="checkbox" name="cat_pii_basica"> Dados básicos (nome, e-mail, tel.)</label>
              <label><input type="checkbox" name="cat_documentos"> Documentos (CPF, RG, OAB)</label>
              <label><input type="checkbox" name="cat_financeiro"> Financeiro</label>
              <label><input type="checkbox" name="cat_juridico"> Jurídico (processos)</label>
              <label><input type="checkbox" name="cat_autenticacao"> Autenticação (senhas, MFA)</label>
              <label><input type="checkbox" name="cat_comunicacoes"> Comunicações (chat, WhatsApp)</label>
              <label><input type="checkbox" name="cat_dados_sensiveis"> Sensíveis (Art. 5 II)</label>
            </div>
          </div>
        </div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Titulares estimados</label>
            <input name="titulares_estimados" type="number" min="0" class="mst-form-input" placeholder="0">
          </div>
          <div>
            <label class="mst-form-label">Registros afetados</label>
            <input name="registros" type="number" min="0" class="mst-form-input" placeholder="0">
          </div>
        </div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Impacto avaliado</label>
            <textarea name="impacto" rows="2" class="mst-form-input" placeholder="Ex.: Possível acesso a dados básicos de 12 titulares; sem acesso a credenciais."></textarea>
          </div>
        </div>

        <div class="mst-form-section">Descrição</div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Descrição interna (DPO)</label>
            <textarea name="descricao_interna" rows="3" class="mst-form-input" placeholder="Detalhes técnicos para a equipe..."></textarea>
          </div>
        </div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Descrição pública (sanitizada)</label>
            <textarea name="descricao_publica" rows="3" class="mst-form-input" placeholder="Versão para titulares / ANPD — sem detalhes técnicos sensíveis."></textarea>
          </div>
        </div>
        <div class="mst-form-row">
          <div>
            <label class="mst-form-label">Medidas imediatas (contenção)</label>
            <textarea name="medidas_imediatas" rows="2" class="mst-form-input" placeholder="Ex.: Sessão revogada, MFA forçado, IP bloqueado."></textarea>
          </div>
        </div>
      </div>
      <div class="mst-modal-footer">
        <button type="button" class="btn-mst" onclick="closeModal('modalNewIncident')">Cancelar</button>
        <button type="submit" class="btn-mst btn-mst-primary">Registrar Incidente</button>
      </div>
    </form>
  </div>
</div>

<script>
const CSRF = '<?=htmlspecialchars($csrf)?>';
const API  = '/api/master';
const fmtBRL = v => 'R$ ' + Number((v||0)/100).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
const fmtDate = v => v ? new Date((v+'').replace(' ','T')).toLocaleDateString('pt-BR') : '—';
const fmtDateTime = v => v ? new Date((v+'').replace(' ','T')).toLocaleString('pt-BR') : '—';
// Traduz badges/valores tecnicos pra portugues. Mantem fallback no valor original
// se nao mapeado. Class CSS continua usando o valor cru (preserva cores).
function i18nBadge(v) {
  if (v === null || v === undefined || v === '') return '—';
  const map = {
    // status conta (accounts.status)
    'active':     'Ativa',
    'trial':      'Em teste',
    'overdue':    'Em atraso',
    'suspended':  'Suspensa',
    'cancelled':  'Cancelada',
    'inactive':   'Inativa',
    // status assinatura (subscriptions.status)
    'trialing':   'Em teste',
    'past_due':   'Atrasada',
    'canceled':   'Cancelada',
    'unpaid':     'Não paga',
    'incomplete': 'Incompleta',
    // ciclo (billing_cycle)
    'monthly':    'Mensal',
    'yearly':     'Anual',
    'weekly':     'Semanal',
    'quarterly':  'Trimestral',
    // role (users.role)
    'owner':      'Proprietário',
    'admin':      'Administrador',
    'manager':    'Gerente',
    'user':       'Usuário',
    'viewer':     'Leitor',
    // tipo conta
    'matriz':     'Matriz',
    'filial':     'Filial',
    'advogado':   'Advogado',
    // status fatura/pagamento
    'paid':       'Paga',
    'pending':    'Pendente',
    'refunded':   'Reembolsada',
    'failed':     'Falhou',
  };
  return map[String(v).toLowerCase()] || v;
}
const pill = (s) => `<span class="pill pill-${s||'cancel'}">${i18nBadge(s)}</span>`;
const esc  = (s) => (s == null ? '' : String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]));

// ── Biblioteca de ícones SVG (Lucide-like) ───────────────────────────────
// Sem emoji em nenhum lugar do Painel Master — padrão SaaS limpo.
const _ICO = {
  building:   '<path d="M3 21h18"/><path d="M5 21V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v14"/><path d="M9 9h.01"/><path d="M9 13h.01"/><path d="M9 17h.01"/><path d="M15 9h.01"/><path d="M15 13h.01"/><path d="M15 17h.01"/>',
  store:      '<path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.41.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/>',
  scale:      '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/>',
  server:     '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>',
  users:      '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  plug:       '<path d="M9 2v6"/><path d="M15 2v6"/><path d="M6 8h12v3a6 6 0 0 1-12 0Z"/><path d="M12 14v8"/>',
  megaphone:  '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
  hardhat:    '<rect x="2" y="18" width="20" height="4" rx="1"/><path d="M5 18v-4a7 7 0 0 1 14 0v4"/><path d="M10 8V5h4v3"/>',
  receipt:    '<path d="M4 2v20l2-2 2 2 2-2 2 2 2-2 2 2 2-2 2 2V2l-2 2-2-2-2 2-2-2-2 2-2-2-2 2Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17V7"/>',
  package:    '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
  pin:        '<line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/>',
  refresh:    '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
  calendar:   '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
  alert:      '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
};
function ico(key, opts = {}) {
  const path = _ICO[key];
  if (!path) return '';
  const size = opts.size || 14;
  const color = opts.color || 'currentColor';
  const style = opts.style || 'display:inline;vertical-align:-2px';
  return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="${style}">${path}</svg>`;
}
// Mapa: chave categoria → ícone
const _EXP_CAT_ICO = {
  servidor:'server', pessoas:'users', apis:'plug', marketing:'megaphone',
  infraestrutura:'hardhat', impostos:'receipt', software:'package',
  juridico:'scale', outros:'pin'
};
const _EXP_CAT_LBL = {
  servidor:'Servidor', pessoas:'Pessoas', apis:'APIs', marketing:'Marketing',
  infraestrutura:'Infra', impostos:'Impostos', software:'Software',
  juridico:'Jurídico', outros:'Outros'
};

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
// Etapa 8 (LGPD): array atualizado com lgpd, retencao, incidents — antes faltavam
// e o hash routing caía no fallback de 'overview' ao clicar nessas abas.
const TABS = ['overview','dashboard','accounts','plans','billing','invoices','payments','expenses','audit','lgpd','retencao','incidents','operators','reviews','whatsapp'];
function activateTab(name) {
  if (!TABS.includes(name)) name = 'overview';
  document.querySelectorAll('.mst-tab').forEach(t => t.classList.toggle('active', t.dataset.mtab === name));
  document.querySelectorAll('.mst-section').forEach(s => s.classList.toggle('active', s.id === 'msec-'+name));
  loadTab(name);
}
function loadTab(name) {
  if (name==='overview')  loadOverview();
  if (name==='dashboard') loadDashboard();
  if (name==='accounts')  loadAccounts();
  if (name==='plans')     loadPlans();
  if (name==='billing')   loadBilling();
  if (name==='invoices')  loadInvoices();
  if (name==='payments')  loadPayments();
  if (name==='expenses')  loadExpenses();
  if (name==='audit')     loadAudit();
  if (name==='lgpd')    { loadLgpdRequests(); loadConsents(); }
  if (name==='retencao')  loadRetention();
  if (name==='incidents') loadIncidents();
  if (name==='operators') loadOperators();
  if (name==='reviews')   loadReviews();
  if (name==='whatsapp') { loadGlobalEvolution(); loadWhatsappConfig(); }
}

// ── WhatsApp / Evolution (infra por conta — só super_admin) ────────────────
async function loadWhatsappConfig() {
  const tb = document.getElementById('waCfgRows');
  const form = document.getElementById('waCfgForm');
  if (form) form.style.display = 'none';
  if (!tb) return;
  const _e = s => String(s==null?'':s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  tb.innerHTML = '<tr><td colspan="5" style="padding:14px;color:#9ab0c9">Carregando…</td></tr>';
  const r = await fj(`${API}/whatsapp_config.php?list=1`);
  if (!r.ok) { tb.innerHTML = '<tr><td colspan="5" style="padding:14px;color:#dc2626">Erro ao carregar</td></tr>'; return; }
  const rows = (r.data && r.data.accounts) || [];
  window.__waCfgList = rows;
  if (!rows.length) { tb.innerHTML = '<tr><td colspan="5" style="padding:14px;color:#9ab0c9">Nenhuma conta com WhatsApp configurado ainda.</td></tr>'; return; }
  tb.innerHTML = rows.map(a => `<tr>
    <td>${_e(a.account_nome)} <span style="color:#7a8aa0">#${a.account_id}</span></td>
    <td>${_e(a.account_tipo)}</td>
    <td>${_e(a.evolution_instance) || '—'}</td>
    <td>${a.has_key ? '<span style="color:#10b981;font-weight:600">configurada</span>' : '<span style="color:#f59e0b;font-weight:600">faltando</span>'}</td>
    <td style="text-align:right;white-space:nowrap">
      <button onclick="provisionInstance(${a.account_id}, this)" style="padding:5px 12px;font-size:.78rem;border:1px solid rgba(16,185,129,.5);background:transparent;color:#34D399;border-radius:7px;cursor:pointer;margin-right:6px">Criar instância</button>
      <button onclick="editWhatsappConfig(${a.account_id})" style="padding:5px 12px;font-size:.78rem;border:1px solid rgba(96,165,250,.4);background:transparent;color:#7EB8F7;border-radius:7px;cursor:pointer">Editar</button>
      ${a.evolution_instance ? `<button onclick="deleteInstance(${a.account_id}, this)" style="padding:5px 12px;font-size:.78rem;border:1px solid rgba(239,68,68,.5);background:transparent;color:#F87171;border-radius:7px;cursor:pointer;margin-left:6px">Excluir</button>` : ''}
    </td>
  </tr>`).join('');
}
async function editWhatsappConfig(accountId) {
  const r = await fj(`${API}/whatsapp_config.php?account_id=${accountId}`);
  if (!r.ok) { notifyErr('Erro ao carregar a conexão'); return; }
  const s = (r.data && r.data.settings) || {};
  const acc = (r.data && r.data.account) || {};
  document.getElementById('waCfgAccountId').value = accountId;
  document.getElementById('waCfgFormTitle').textContent = 'Conexão Evolution — ' + (acc.nome || ('conta #'+accountId));
  document.getElementById('waCfgBaseUrl').value  = s.evolution_base_url || '';
  document.getElementById('waCfgInstance').value = s.evolution_instance || '';
  document.getElementById('waCfgWebhook').value  = s.webhook_url || '';
  const ak = document.getElementById('waCfgApiKey');
  if (s.evolution_api_key_masked) {
    ak.value = s.evolution_api_key_masked;
    ak.dataset.masked = s.evolution_api_key_masked;
    ak.dataset.pristine = '1';
    ak.onfocus = function(){ if (this.dataset.pristine === '1') this.value = ''; };
    ak.onblur  = function(){ if (this.dataset.pristine === '1' && this.value === '') this.value = this.dataset.masked || ''; };
    ak.oninput = function(){ this.dataset.pristine = '0'; };
  } else {
    ak.value = ''; ak.dataset.masked = ''; ak.dataset.pristine = '0';
    ak.onfocus = null; ak.onblur = null; ak.oninput = null;
  }
  document.getElementById('waCfgKeyHint').textContent = s.evolution_api_key_masked
    ? 'Chave salva (mostrada mascarada). Clique e digite para trocar; deixe como está para manter.'
    : 'Nenhuma chave salva ainda';
  const form = document.getElementById('waCfgForm');
  form.style.display = 'block';
  form.scrollIntoView({behavior:'smooth', block:'center'});
}
async function saveWhatsappConfig() {
  const accountId = parseInt(document.getElementById('waCfgAccountId').value, 10);
  if (!accountId) return;
  const body = { csrf_token: CSRF, account_id: accountId,
    evolution_base_url: document.getElementById('waCfgBaseUrl').value.trim(),
    evolution_instance: document.getElementById('waCfgInstance').value.trim(),
    webhook_url:        document.getElementById('waCfgWebhook').value.trim() };
  // Só envia a chave se o usuário digitou uma nova (não reenvia a máscara).
  const akEl = document.getElementById('waCfgApiKey');
  const akVal = (akEl.value || '').trim();
  if (akEl.dataset.pristine !== '1' && akVal !== '' && akVal !== (akEl.dataset.masked || '')) {
    body.evolution_api_key = akVal;
  }
  try {
    const res = await fetch(`${API}/whatsapp_config.php`, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify(body) });
    const j = await res.json();
    if (res.ok && j.ok !== false) { notifyOk('Conexão Evolution salva'); document.getElementById('waCfgForm').style.display='none'; loadWhatsappConfig(); }
    else notifyErr(j.error || (j.data && j.data.error) || 'Erro ao salvar');
  } catch(e) { notifyErr('Erro de rede ao salvar'); }
}
async function deleteInstance(accountId, btn) {
  const row = (window.__waCfgList || []).find(a => String(a.account_id) === String(accountId)) || {};
  const nm  = row.evolution_instance || '(instância)';
  const acc = row.account_nome || ('conta #' + accountId);
  if (!confirm(
    'Resetar a conexão WhatsApp de ' + acc + ' (instância atual: ' + nm + ')?\n\n' +
    'Isso vai:\n' +
    '• apagar TODAS as instâncias dessa conta na Evolution (o número desconecta)\n' +
    '• apagar as conversas/mensagens/contatos dessa conta no Yuris\n' +
    '• desvincular o agente de IA do canal\n\n' +
    'NÃO dá pra desfazer. Depois você pode clicar em "Criar instância" pra começar do zero.'
  )) return;
  _btnLoading(btn, 'Excluindo…');
  try {
    const res = await fetch(`${API}/whatsapp_config.php`, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify({ csrf_token: CSRF, action:'delete_instance', account_id: accountId }) });
    const j = await res.json();
    if (res.ok && j.ok !== false) { notifyOk((j.data && j.data.message) || 'Instância excluída'); loadWhatsappConfig(); /* re-render recria o botão */ }
    else { notifyErr(j.error || (j.data && j.data.error) || 'Erro ao excluir'); _btnRestore(btn); }
  } catch(e) { notifyErr('Erro de rede ao excluir'); _btnRestore(btn); }
}

// ── Config GLOBAL Evolution + provisionamento ────────────────────────────────
async function loadGlobalEvolution() {
  const r = await fj(`${API}/whatsapp_config.php?global=1`);
  if (!r.ok) return;
  const d = r.data || {};
  const set = (id,v)=>{ const el=document.getElementById(id); if(el) el.value = v||''; };
  set('gevBaseUrl', d.evolution_base_url);
  set('gevWebhook', d.webhook_url);
  const k = document.getElementById('gevAdminKey');
  if (k) {
    if (d.has_admin_key) {
      // Mostra a chave salva mascarada NO PRÓPRIO CAMPO (asteriscos + final),
      // pra confirmar visualmente que está lá. Trava: enquanto "pristine", o save
      // nunca reenvia a máscara (mantém a atual). Ao focar limpa pra digitar nova;
      // se sair sem digitar, restaura a máscara.
      const masked = d.admin_key_masked || '••••••••••••';
      k.value = masked;
      k.dataset.masked = masked;
      k.dataset.pristine = '1';
      k.onfocus = function(){ if (this.dataset.pristine === '1') this.value = ''; };
      k.onblur  = function(){ if (this.dataset.pristine === '1' && this.value === '') this.value = this.dataset.masked || ''; };
      k.oninput = function(){ this.dataset.pristine = '0'; };
    } else {
      k.value = ''; k.dataset.masked = ''; k.dataset.pristine = '0';
      k.onfocus = null; k.onblur = null; k.oninput = null;
    }
  }
  const hint = document.getElementById('gevKeyHint');
  if (hint) hint.textContent = d.has_admin_key
    ? 'Chave salva (mostrada mascarada acima). Clique no campo e digite para trocar; deixe como está para manter.'
    : 'Nenhuma admin key salva ainda';
}
async function saveGlobalEvolution() {
  const body = { csrf_token: CSRF, action:'save_global',
    evolution_base_url: document.getElementById('gevBaseUrl').value.trim(),
    webhook_url:        document.getElementById('gevWebhook').value.trim() };
  // Só envia a chave se o usuário REALMENTE digitou uma nova. Se o campo continua
  // "pristine" (mostrando a máscara) ou igual à máscara, mantém a atual (não grava por cima).
  const kEl = document.getElementById('gevAdminKey');
  const kVal = (kEl.value || '').trim();
  if (kEl.dataset.pristine !== '1' && kVal !== '' && kVal !== (kEl.dataset.masked || '')) {
    body.evolution_admin_key = kVal;
  }
  try {
    const res = await fetch(`${API}/whatsapp_config.php`, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify(body) });
    const j = await res.json();
    if (res.ok && j.ok !== false) { notifyOk('Config global salva'); loadGlobalEvolution(); }
    else notifyErr(j.error || (j.data&&j.data.error) || 'Erro ao salvar');
  } catch(e) { notifyErr('Erro de rede'); }
}
function _btnLoading(btn, txt) {
  if (!btn) return null;
  const orig = btn.textContent;
  btn.disabled = true; btn.dataset.orig = orig;
  btn.textContent = txt; btn.style.opacity = '.6'; btn.style.cursor = 'wait';
  return orig;
}
function _btnRestore(btn) {
  if (!btn) return;
  btn.disabled = false; btn.textContent = btn.dataset.orig || btn.textContent;
  btn.style.opacity = ''; btn.style.cursor = '';
}
async function provisionInstance(accountId, btn) {
  if (!confirm('Criar uma instância WhatsApp nova na Evolution para esta conta? Isso conecta de verdade no servidor.')) return;
  _btnLoading(btn, 'Criando…');
  try {
    const res = await fetch(`${API}/whatsapp_config.php`, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify({ csrf_token: CSRF, action:'provision', account_id: accountId }) });
    const j = await res.json();
    if (res.ok && j.ok !== false) { notifyOk((j.data && j.data.message) || 'Instância criada'); loadWhatsappConfig(); /* re-render recria o botão */ }
    else { notifyErr(j.error || (j.data&&j.data.error) || 'Erro ao criar instância'); _btnRestore(btn); }
  } catch(e) { notifyErr('Erro de rede ao criar instância'); _btnRestore(btn); }
}
document.querySelectorAll('.mst-tab').forEach(b => b.addEventListener('click', () => {
  window.location.hash = b.dataset.mtab;
}));
window.addEventListener('hashchange', () => activateTab((location.hash||'').replace('#','')));

// ── Modais ───────────────────────────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

async function openModalAccount(tipo) {
  tipo = tipo || 'matriz';
  // Carrega lista de planos
  const r = await fj(`${API}/plans.php`);
  const sel = document.getElementById('selPlan');
  sel.innerHTML = '';
  if (r.ok && r.data.plans) {
    r.data.plans.filter(p => p.ativo == 1).forEach(p => {
      const o = document.createElement('option');
      o.value = p.id;
      o.textContent = `${p.nome} — ${fmtBRL(p.preco_mensal_cents)}/mês`;
      // Sugere "Teste Grátis" como default pra advogado
      if (tipo === 'advogado' && p.slug === 'teste_gratis') o.selected = true;
      sel.appendChild(o);
    });
  }
  document.getElementById('formAccount').reset();
  document.getElementById('accountTipo').value = tipo;

  const isAdv = (tipo === 'advogado');
  document.getElementById('accountModalTitle').textContent = isAdv ? 'Novo Advogado (Conta Própria)' : 'Nova Matriz';
  document.getElementById('accountDataSection').textContent = isAdv ? 'Dados do Escritório / Advogado Solo' : 'Dados da Matriz';
  document.getElementById('accountRazaoLabel').textContent = isAdv ? 'Razão Social / Nome Completo' : 'Razão Social';
  document.getElementById('accountCnpjLabel').textContent = isAdv ? 'CNPJ / CPF' : 'CNPJ';
  document.getElementById('adminSection').textContent = isAdv ? 'Dados do Advogado' : 'Administrador da Conta';
  document.getElementById('admNomeLabel').textContent = isAdv ? 'Nome do Advogado *' : 'Nome *';
  document.getElementById('oabRow').style.display = isAdv ? '' : 'none';
  // tipo dirige obrigatoriedade dos campos OAB no DOM
  document.querySelector('[name="adm_oab"]').required    = isAdv;
  document.querySelector('[name="adm_oab_uf"]').required = isAdv;

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
      // Rótulo cobrindo os 3 tipos. O ternário binário antigo (matriz?'M':'F')
      // marcava advogado-solo e qualquer não-matriz como [F] (Filial) — errado.
      const tag = a.tipo === 'matriz' ? 'M' : a.tipo === 'filial' ? 'F' : 'A';
      o.textContent = `[${tag}] ${a.nome} (#${a.id})`;
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
  const tipo = f.tipo.value || 'matriz';
  const body = {
    csrf_token: CSRF,
    tipo,
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
  // OAB obrigatório quando é advogado-solo
  if (tipo === 'advogado') {
    body.admin.oab    = f.adm_oab.value.trim();
    body.admin.oab_uf = f.adm_oab_uf.value.trim().toUpperCase();
  }
  // Monitoramento (add-on opcional). Só envia se checkbox marcada E qtd > 0.
  // Backend (create_account.php) faz o INSERT em account_quota_overrides
  // dentro da mesma transação — falha aqui = rollback de tudo.
  if (f.mon_enable && f.mon_enable.checked) {
    const monQtd = parseInt(f.mon_qtd.value, 10);
    if (monQtd > 0) {
      body.monitor = {
        qtd:          monQtd,
        billing_cycle: f.mon_cycle.value || 'monthly',
        contract_ref: f.mon_contract.value.trim() || null,
        observacoes:  f.mon_obs.value.trim() || null,
      };
      const monPrice = parseFloat(f.mon_price.value);
      if (!isNaN(monPrice) && monPrice > 0) {
        body.monitor.unit_price_cents = Math.round(monPrice * 100);
      }
    }
  }
  const r = await fj(`${API}/create_account.php`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': CSRF},
    body: JSON.stringify(body),
  });
  if (!r.ok) return notifyErr(r.error || 'Falha ao criar conta');
  closeModal('modalAccount');
  notifyOk(tipo === 'advogado' ? 'Advogado solo criado!' : 'Matriz criada com sucesso!');
  if (r.data.senha_gerada) {
    Yuris.notify(`Senha temporária gerada: ${r.data.senha_gerada}`, {type:'info', duration:12000});
  }
  if (r.data.codigo_advogado) {
    Yuris.notify(`Código do advogado: ${r.data.codigo_advogado}`, {type:'info', duration:10000});
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
    // WhatsApp da filial: matriz (herdar, padrão) | propria | depois. O backend
    // (create_filial.php) valida e só concede acesso ao canal da matriz via grant
    // explícito; o uso em runtime depende da feature flag.
    whatsapp_mode: (f.whatsapp_mode && f.whatsapp_mode.value) || 'matriz',
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

  // Ícone/cor por tipo cobrindo os TRÊS tipos (matriz/filial/advogado).
  // Antes era binário (matriz vs 'store') e o advogado-solo caía no ramo de
  // filial. Advogado tem ícone/cor próprios (balança verde, igual a viewAdvogado).
  const _accIcon  = d.tipo === 'matriz' ? 'building' : d.tipo === 'advogado' ? 'scale' : 'store';
  const _accColor = d.tipo === 'matriz' ? '#60a5fa'  : d.tipo === 'advogado' ? '#86efac' : '#c084fc';

  // Header mostra DOIS pills quando o status da assinatura diverge do status
  // da conta — antes só mostrava o da conta e o user via "Em teste" no bloco
  // Assinatura abaixo sem entender que eram coisas distintas (conta=acesso,
  // assinatura=ciclo financeiro). Hint visual "Assin:" deixa explícito.
  const _sub = d.subscription || {};
  const _hasSub = _sub && _sub.id && _sub.status;
  const _statusDiverge = _hasSub && String(_sub.status) !== String(d.status);
  const _subPill = _statusDiverge
    ? ` <span style="opacity:.7;font-size:.7rem;margin-left:6px">Assin:</span> ${pill(_sub.status)}`
    : '';
  document.getElementById('detalheTitle').innerHTML =
    `${ico(_accIcon, {size:18, style:'display:inline;vertical-align:-3px;margin-right:6px;color:'+_accColor})}${esc(d.nome)} <span class="pill pill-${esc(d.tipo)}" style="margin-left:8px;font-size:.6rem">${esc(d.tipo)}</span> ${pill(d.status)}${_subPill}`;

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
      <div class="mst-detail-item"><div class="label">Ciclo</div><div class="value">${i18nBadge(sub.billing_cycle)}</div></div>
      <div class="mst-detail-item"><div class="label">Teste grátis até</div><div class="value">${fmtDate(sub.trial_ends_at)}</div></div>
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

  // ── Monitoramentos (Etapa 6 add-on) ──────────────────────────────────
  // Add-on à parte do plano. Cliente começa com 0. Master libera grant
  // gratuito OU registra compra/contrato (sem implementar gateway ainda).
  const mq = d.monitor_quota || {};
  const limMq = mq.effective_limit || 0;
  const usedMq = mq.current_usage || 0;
  const availMq = mq.available || 0;
  const mqColor = limMq === 0 ? '#6b7280'
    : usedMq > limMq ? '#ef4444'
    : usedMq >= limMq * 0.8 ? '#f59e0b'
    : '#22c55e';
  html += `<div class="mst-form-section">Monitoramentos (add-on)</div>
  <div class="mst-detail-grid">
    <div class="mst-detail-item"><div class="label">Contratados</div><div class="value" style="color:${mqColor}">${limMq}</div></div>
    <div class="mst-detail-item"><div class="label">Em uso</div><div class="value">${usedMq}</div></div>
    <div class="mst-detail-item"><div class="label">Disponível</div><div class="value" style="color:${availMq>0?'#22c55e':'#9ab0c9'}">${availMq}</div></div>
  </div>`;
  if (mq.overrides && mq.overrides.length) {
    html += `<table class="mst-tbl" style="margin-top:8px"><thead><tr>
      <th>Origem</th><th>Qtd</th><th>Contrato</th><th>Preço unit.</th><th>Expira</th><th>Criado</th><th>Observação</th>
    </tr></thead><tbody>`;
    mq.overrides.forEach(o => {
      const priceFmt = o.unit_price_cents ? fmtBRL(o.unit_price_cents) : '—';
      html += `<tr>
        <td><span class="pill pill-${o.source==='purchase'?'active':'trial'}">${esc(o.source)}</span></td>
        <td><strong>+${o.limit_value}</strong></td>
        <td>${esc(o.contract_ref||'—')}</td>
        <td>${priceFmt}</td>
        <td>${o.expires_at?fmtDate(o.expires_at):'Sem expirar'}</td>
        <td><span title="${esc(o.criado_por_nome||'?')}">${fmtDate(o.created_at)}</span></td>
        <td><small>${esc(o.observacoes||'—')}</small></td>
      </tr>`;
    });
    html += `</tbody></table>`;
  } else {
    html += `<div class="empty" style="margin-top:6px">Sem monitoramentos contratados</div>`;
  }
  // ─────────────────────────────────────────────────────────────────────

  if (d.users && d.users.length) {
    html += `<div class="mst-form-section">Usuários (${d.users.length})</div>
    <table class="mst-tbl"><thead><tr><th>Nome</th><th>E-mail</th><th>Role</th><th>Status</th><th>Ações</th></tr></thead><tbody>`;
    d.users.forEach(u => {
      html += `<tr>
        <td>${esc(u.nome)}</td>
        <td>${esc(u.email)}</td>
        <td>${i18nBadge(u.role||u.perfil)}</td>
        <td>${pill(u.status)}</td>
        <td>
          <button class="btn-mst" onclick="openEditUser(${u.id})">Editar</button>
          <button class="btn-mst" onclick="quickResetPassword(${u.id})">Reset senha</button>
        </td>
      </tr>`;
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
  foot += `<button class="btn-mst btn-mst-primary" onclick="openEditAccount(${d.id})">Editar dados</button>`;
  if (sub && sub.id) {
    // openSubModal lê de _subsCache (populada só ao abrir a aba Assinaturas).
    // Ao editar a assinatura direto pelo detalhe da conta, a aba pode nunca
    // ter sido carregada — então sincronizamos o cache com a subscription
    // deste detalhe (acrescentando account_nome, que vem da própria conta).
    cacheSubscription({ ...sub, account_id: sub.account_id || d.id, account_nome: d.nome });
    foot += `<button class="btn-mst btn-mst-primary" onclick="openSubModal(${sub.id})">Editar assinatura</button>`;
  }
  if (isMatriz) {
    foot += `<button class="btn-mst" onclick="openModalFilial(${d.id})">+ Filial</button>`;
    foot += `<button class="btn-mst" onclick="openModalAdvogado(${d.id})">+ Advogado nesta conta</button>`;
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
    `${ico('scale', {size:18, style:'display:inline;vertical-align:-3px;margin-right:6px;color:#86efac'})}${esc(d.nome)} <span class="pill pill-advogado" style="margin-left:8px;font-size:.6rem">advogado</span> ${pill(d.status)}`;

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
  document.getElementById('kpiAccAdv').textContent     = k.accounts.advogado || 0;
  document.getElementById('kpiAdv').textContent        = k.users.advogados;
  document.getElementById('kpiUsersActive').textContent= k.users.active;
  const kpiUsersInactive = document.getElementById('kpiUsersInactive');
  if (kpiUsersInactive) kpiUsersInactive.textContent = k.users.inactive;
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
  if (!r.data.accounts.length) { tb.innerHTML='<tr><td colspan="11" class="empty">Nenhuma conta</td></tr>'; return; }
  tb.innerHTML = r.data.accounts.map(a => `
    <tr>
      <td>${a.id}</td>
      <td><strong>${esc(a.nome)}</strong></td>
      <td><span class="pill pill-${esc(a.tipo)}">${esc(a.tipo)}</span>${a.matriz_nome?' <small>← '+esc(a.matriz_nome)+'</small>':''}</td>
      <td>${pill(a.status)}</td>
      <td>${esc(a.sub_plan || a.plano || '—')}</td>
      <td>${a.cidade ? esc(a.cidade) + (a.estado?'/'+esc(a.estado):'') : '—'}</td>
      <td>${a.users_count}</td>
      <td>${a.advogados_count || 0}</td>
      <td>${renderMonitorsCell(a.monitors_used||0, a.monitors_limit||0)}</td>
      <td>${a.sub_status?pill(a.sub_status):'—'}</td>
      <td>
        <button class="btn-mst" onclick="viewAcc(${a.id})">Detalhes</button>
        <button class="btn-mst" onclick="openEditAccount(${a.id})">Editar</button>
        ${a.status==='active' || a.status==='trial' ? `<button class="btn-mst btn-mst-danger" onclick="setStatus(${a.id},'suspended')">Suspender</button>` : ''}
        ${a.status==='suspended' ? `<button class="btn-mst btn-mst-success" onclick="setStatus(${a.id},'active')">Reativar</button>` : ''}
      </td>
    </tr>`).join('');
}

/**
 * Renderiza célula "Monitors" da lista de contas — formato "used/limit"
 * colorido conforme % de uso:
 *   - sem cota (limit=0):              cinza, "—"
 *   - usado > limit (estouro/legado):  vermelho
 *   - usado >= 80% do limit:           laranja
 *   - default:                         verde
 */
function renderMonitorsCell(used, limit) {
  if (!limit) {
    return `<span style="color:#6b7280; font-size:.78rem" title="Sem cota contratada">—</span>`;
  }
  let color = '#22c55e'; // verde (default)
  if (used > limit) color = '#ef4444';      // vermelho (estouro)
  else if (used >= limit * 0.8) color = '#f59e0b'; // laranja (perto do limite)
  return `<span style="color:${color}; font-weight:600; font-size:.85rem" title="Usado / Contratado">${used}/${limit}</span>`;
}
document.getElementById('filterAcc').addEventListener('input', () => clearTimeout(window._ft) || (window._ft = setTimeout(loadAccounts, 300)));
document.getElementById('filterAccStatus').addEventListener('change', loadAccounts);
document.getElementById('filterAccTipo').addEventListener('change', loadAccounts);

let _plansCache = [];
async function loadPlans() {
  const r = await fj(`${API}/plans.php`);
  if (!r.ok) return notifyErr(r.error);
  _plansCache = r.data.plans || [];
  const tb = document.getElementById('plansBody');
  if (!_plansCache.length) { tb.innerHTML='<tr><td colspan="9" class="empty">Sem planos</td></tr>'; return; }
  tb.innerHTML = _plansCache.map(p => `
    <tr>
      <td><code>${esc(p.slug)}</code></td>
      <td><strong>${esc(p.nome)}</strong>${p.destaque==1?' ⭐':''}</td>
      <td>${fmtBRL(p.preco_mensal_cents)}</td>
      <td>${fmtBRL(p.preco_anual_cents)}</td>
      <td>${p.trial_dias}d</td>
      <td>${p.ativo==1?pill('active'):pill('cancelled')}</td>
      <td>${p.subscriptions_count}</td>
      <td>${p.features ? p.features.length : 0} features</td>
      <td><button class="btn-mst" onclick="openPlanModal(${p.id})">Editar</button></td>
    </tr>`).join('');
}

/**
 * Popula o <select id="editAccPlano"> do modal Editar Conta com a lista
 * de planos vinda de plans.php. Usa _plansCache se já estiver populado
 * (ex: usuário entrou na aba Planos antes); senão fetcha sob demanda.
 *
 * Reabilita o sentido de "lista suspensa" — antes era input livre, fácil
 * de gerar typo no slug. selectedSlug pré-seleciona o atual; se o slug
 * legado não bater com nenhum plano ativo, adiciona uma opção avulsa
 * preservando o valor (evita perder o cache em contas antigas).
 */
async function fillEditAccPlanos(selectedSlug = '') {
  const sel = document.getElementById('editAccPlano');
  if (!sel) return;
  let planos = _plansCache;
  if (!planos || !planos.length) {
    try {
      const r = await fj(`${API}/plans.php`);
      if (r.ok) {
        planos = (r.data && r.data.plans) || [];
        _plansCache = planos;
      } else {
        planos = [];
      }
    } catch (_e) { planos = []; }
  }
  let html = '<option value="">— Sem plano —</option>';
  let foundCurrent = false;
  planos.forEach(p => {
    const isSel = String(p.slug) === String(selectedSlug);
    if (isSel) foundCurrent = true;
    const ativo = p.ativo == 1 ? '' : ' (inativo)';
    html += `<option value="${esc(p.slug)}"${isSel?' selected':''}>${esc(p.nome)} (${esc(p.slug)})${ativo}</option>`;
  });
  // Slug legado que não existe mais em plans — mantém visível pra não corromper o cache
  if (selectedSlug && !foundCurrent) {
    html += `<option value="${esc(selectedSlug)}" selected>${esc(selectedSlug)} (legado)</option>`;
  }
  sel.innerHTML = html;
}

// ── Plan modal (criar / editar) ──────────────────────────────────────────
const _FEATURE_KEYS = ['max_users','max_processos','max_cards','max_filiais','whatsapp_enabled','chat_interno','webhooks','integracoes_api'];

function openPlanModal(id) {
  const form = document.getElementById('formPlan');
  form.reset();
  document.getElementById('planId').value = id || '';
  document.getElementById('planModalTitle').textContent = id ? 'Editar Plano' : 'Novo Plano';

  if (id) {
    const p = _plansCache.find(x => x.id == id);
    if (!p) return notifyErr('Plano não encontrado em cache — recarregue a página');
    document.getElementById('planSlug').value     = p.slug || '';
    document.getElementById('planNome').value     = p.nome || '';
    document.getElementById('planDesc').value     = p.descricao || '';
    document.getElementById('planPM').value       = (Number(p.preco_mensal_cents||0)/100).toFixed(2);
    document.getElementById('planPA').value       = (Number(p.preco_anual_cents||0)/100).toFixed(2);
    document.getElementById('planTrial').value    = p.trial_dias || 0;
    document.getElementById('planOrdem').value    = p.ordem || 0;
    document.getElementById('planAtivo').value    = p.ativo == 1 ? '1' : '0';
    document.getElementById('planDestaque').value = p.destaque == 1 ? '1' : '0';

    // Pré-carrega features
    const featMap = {};
    (p.features || []).forEach(f => { featMap[f.feature_key] = f; });
    _FEATURE_KEYS.forEach(k => {
      const el = document.getElementById('feat_' + k);
      if (!el) return;
      const f = featMap[k];
      if (k === 'whatsapp_enabled' || k === 'chat_interno' || k === 'webhooks' || k === 'integracoes_api') {
        el.value = (f && f.is_enabled == 1) ? '1' : '0';
      } else {
        el.value = (f && f.limit_value !== null && f.limit_value !== undefined) ? f.limit_value : '';
      }
    });
  } else {
    // Defaults pra novo plano
    document.getElementById('planAtivo').value = '1';
    document.getElementById('planDestaque').value = '0';
    document.getElementById('planTrial').value = 14;
    document.getElementById('planOrdem').value = 99;
    _FEATURE_KEYS.forEach(k => {
      const el = document.getElementById('feat_' + k);
      if (el) el.value = (k === 'whatsapp_enabled' || k === 'chat_interno') ? '1' : (k === 'webhooks' || k === 'integracoes_api') ? '0' : '';
    });
  }
  openModal('modalPlan');
}

async function submitPlan(ev) {
  ev.preventDefault();
  const id   = document.getElementById('planId').value;
  const isEdit = !!id;
  const pm = Math.round(parseFloat(document.getElementById('planPM').value || 0) * 100);
  const pa = Math.round(parseFloat(document.getElementById('planPA').value || 0) * 100);

  // Monta lista de features
  const features = _FEATURE_KEYS.map(k => {
    const el = document.getElementById('feat_' + k);
    const isBool = (k === 'whatsapp_enabled' || k === 'chat_interno' || k === 'webhooks' || k === 'integracoes_api');
    return {
      feature_key: k,
      limit_value: isBool ? null : (el.value === '' ? null : parseInt(el.value, 10)),
      is_enabled:  isBool ? (el.value == '1') : true,
    };
  });

  const body = {
    csrf_token: CSRF,
    slug:               document.getElementById('planSlug').value.trim(),
    nome:               document.getElementById('planNome').value.trim(),
    descricao:          document.getElementById('planDesc').value.trim(),
    preco_mensal_cents: pm,
    preco_anual_cents:  pa,
    trial_dias:         parseInt(document.getElementById('planTrial').value || 0, 10),
    ordem:              parseInt(document.getElementById('planOrdem').value || 99, 10),
    ativo:              parseInt(document.getElementById('planAtivo').value, 10),
    destaque:           parseInt(document.getElementById('planDestaque').value, 10),
    features,
  };
  if (isEdit) body.id = parseInt(id, 10);

  const r = await fj(`${API}/plans.php`, {
    method: isEdit ? 'PATCH' : 'POST',
    headers: {'Content-Type':'application/json', 'X-CSRF-Token': CSRF},
    body: JSON.stringify(body),
  });
  if (!r.ok) return notifyErr(r.error || 'Falha ao salvar plano');
  closeModal('modalPlan');
  notifyOk(isEdit ? 'Plano atualizado' : 'Plano criado');
  loadPlans();
}

// ── Subscription modal (editar) ──────────────────────────────────────────
let _subsCache = [];
// Faz upsert de uma assinatura em _subsCache (por id). Usado para abrir o
// modal de edição a partir do detalhe da conta sem depender de loadBilling().
function cacheSubscription(sub) {
  if (!sub || !sub.id) return;
  const i = _subsCache.findIndex(x => x.id == sub.id);
  if (i >= 0) _subsCache[i] = { ..._subsCache[i], ...sub };
  else _subsCache.push(sub);
}
async function openSubModal(id) {
  const s = _subsCache.find(x => x.id == id);
  if (!s) return notifyErr('Assinatura não encontrada');

  // Popular select de planos
  const sel = document.getElementById('subPlanId');
  sel.innerHTML = '';
  if (!_plansCache.length) await loadPlans();
  _plansCache.forEach(p => {
    const o = document.createElement('option');
    o.value = p.id; o.textContent = `${p.nome} (${p.slug})`;
    if (p.id == s.plan_id) o.selected = true;
    sel.appendChild(o);
  });

  document.getElementById('subId').value = s.id;
  document.getElementById('subAccountInfo').querySelector('.value').textContent = s.account_nome + ' (#' + s.account_id + ')';
  document.getElementById('subStatus').value = s.status;
  document.getElementById('subCycle').value  = s.billing_cycle || 'monthly';
  document.getElementById('subTrialEnd').value  = s.trial_ends_at ? s.trial_ends_at.substring(0,10) : '';
  document.getElementById('subPeriodEnd').value = s.current_period_end ? s.current_period_end.substring(0,10) : '';
  openModal('modalSub');
}

async function submitSub(ev) {
  ev.preventDefault();
  const body = {
    csrf_token: CSRF,
    subscription_id: parseInt(document.getElementById('subId').value, 10),
    plan_id:         parseInt(document.getElementById('subPlanId').value, 10),
    status:          document.getElementById('subStatus').value,
    billing_cycle:   document.getElementById('subCycle').value,
    trial_ends_at:   document.getElementById('subTrialEnd').value || null,
    current_period_end: document.getElementById('subPeriodEnd').value || null,
  };
  const r = await fj(`${API}/billing.php`, {
    method: 'PATCH',
    headers: {'Content-Type':'application/json', 'X-CSRF-Token': CSRF},
    body: JSON.stringify(body),
  });
  if (!r.ok) return notifyErr(r.error || 'Falha ao salvar assinatura');
  closeModal('modalSub');
  notifyOk('Assinatura atualizada');
  loadBilling();
}

async function loadBilling() {
  const r = await fj(`${API}/billing.php`);
  if (!r.ok) return notifyErr(r.error);
  _subsCache = r.data.subscriptions || [];
  const monitorSubs = r.data.monitor_subscriptions || [];
  const tb = document.getElementById('subsBody');

  // Agrupa por account_id — 1 row por conta com tags + lista empilhada.
  // Mais organizado que 1 row por assinatura quando uma conta tem plano +
  // múltiplos monitors. Pedido do usuário 2026-05-26.
  const byAccount = {};
  function ensure(aid, nome) {
    if (!byAccount[aid]) byAccount[aid] = { account_id: aid, account_nome: nome, plano: null, monitors: [] };
    return byAccount[aid];
  }
  _subsCache.forEach(s => { ensure(s.account_id, s.account_nome).plano = s; });
  monitorSubs.forEach(m => { ensure(m.account_id, m.account_nome).monitors.push(m); });

  const grupos = Object.values(byAccount);
  if (!grupos.length) {
    tb.innerHTML = '<tr><td colspan="4" class="empty">Nenhuma assinatura</td></tr>';
    return;
  }

  // CSS inline pra "linha-item" dentro da célula Produtos
  const itemStyle = 'padding:7px 0; line-height:1.5';
  const itemSep   = 'border-top:1px solid rgba(160,180,210,.08)';

  tb.innerHTML = grupos.map(g => {
    // Tags empilhadas na coluna Tipo (mostra só os tipos que essa conta tem)
    const tags = [];
    if (g.plano)              tags.push(`<span class="pill" style="background:rgba(96,165,250,.15); color:#60a5fa; border:1px solid rgba(96,165,250,.3); display:inline-block; margin-bottom:3px">Plano</span>`);
    if (g.monitors.length)    tags.push(`<span class="pill" style="background:rgba(168,85,247,.15); color:#c084fc; border:1px solid rgba(168,85,247,.3); display:inline-block">Monitor</span>`);

    // Linhas-item dentro da célula Produtos
    const itens = [];

    if (g.plano) {
      let valor = '<small style="color:#9ab0c9">sem preço cadastrado</small>';
      if (g.plano.billing_cycle === 'monthly')      valor = `<strong style="color:#e2e8f0">${fmtBRL(g.plano.preco_mensal_cents)}</strong> <small style="color:#9ab0c9">/mês</small>`;
      else if (g.plano.billing_cycle === 'yearly')  valor = `<strong style="color:#e2e8f0">${fmtBRL(g.plano.preco_anual_cents)}</strong> <small style="color:#9ab0c9">/ano</small>`;
      else if (g.plano.billing_cycle === 'quarterly') valor = '<small style="color:#9ab0c9">trimestral — preço não configurado no plano</small>';
      itens.push(`<div style="${itemStyle}">
        <strong style="color:#e2e8f0">📦 ${esc(g.plano.plan_nome)}</strong>
        <small style="color:#9ab0c9">(${esc(g.plano.plan_slug)})</small>
        · ${pill(g.plano.status)}
        · <span style="color:#cbd5e1">${i18nBadge(g.plano.billing_cycle)}</span>
        · ${valor}
        ${g.plano.trial_ends_at ? `<br><small style="color:#9ab0c9">teste até ${fmtDate(g.plano.trial_ends_at)} · período até ${fmtDate(g.plano.current_period_end)}</small>` : `<br><small style="color:#9ab0c9">período até ${fmtDate(g.plano.current_period_end)}</small>`}
      </div>`);
    }

    g.monitors.forEach(m => {
      let valor = '<small style="color:#9ab0c9">sem preço</small>';
      if (m.unit_price_cents && m.qtd) {
        const total = m.unit_price_cents * m.qtd;
        const sufixo = m.billing_cycle === 'monthly' ? '/mês' :
                       m.billing_cycle === 'quarterly' ? '/trim' :
                       m.billing_cycle === 'yearly' ? '/ano' : '';
        valor = `<strong style="color:#e2e8f0">${fmtBRL(total)}</strong> <small style="color:#9ab0c9">${sufixo} · ${m.qtd}×${fmtBRL(m.unit_price_cents)}</small>`;
      }
      const status = m.expires_at && new Date(m.expires_at) < new Date() ? 'canceled' : 'active';
      itens.push(`<div style="${itemStyle}; ${itens.length ? itemSep : ''}">
        <strong style="color:#e2e8f0">🛰️ ${m.qtd}× Monitoramento OAB</strong>
        ${m.contract_ref ? `<small style="color:#9ab0c9">(${esc(m.contract_ref)})</small>` : ''}
        · ${pill(status)}
        · <span style="color:#cbd5e1">${i18nBadge(m.billing_cycle)}</span>
        · ${valor}
        ${m.expires_at ? `<br><small style="color:#9ab0c9">expira ${fmtDate(m.expires_at)}</small>` : ''}
      </div>`);
    });

    // Ações empilhadas: Editar conta sempre + Cancelar por monitor.
    // Tudo alinhado na coluna Ações pra não ter botão flutuando dentro
    // de items na coluna Produtos.
    const acoes = [];
    acoes.push(`<button class="btn-mst" onclick="openEditAccount(${g.account_id})" style="display:block; width:100%; margin-bottom:6px">Editar conta</button>`);
    g.monitors.forEach(m => {
      const label = m.contract_ref ? esc(m.contract_ref) : `monitor #${m.id}`;
      acoes.push(`<button class="btn-mst btn-mst-danger" onclick="revokeMonitorOverride(${m.id})" style="display:block; width:100%; padding:4px 10px; font-size:.72rem; margin-bottom:4px" title="Revogar assinatura de monitor">Cancelar ${label}</button>`);
    });

    return `
      <tr>
        <td style="vertical-align:top">${tags.join('')}</td>
        <td style="vertical-align:top"><strong>${esc(g.account_nome)}</strong></td>
        <td>${itens.join('')}</td>
        <td style="vertical-align:top; min-width:160px">${acoes.join('')}</td>
      </tr>`;
  }).join('');
}

/**
 * Atalho: do row da assinatura de monitor, abre o modal Editar Conta
 * já na seção Monitoramentos. Reusa o fluxo existente.
 */
function openMonitorSubFromBilling(accountId) {
  openEditAccount(accountId);
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
  if (!r.data.invoices.length) {
    tb.innerHTML = `<tr><td colspan="7" class="empty">
      Nenhuma fatura ainda. As faturas aparecem aqui quando você cria uma
      cobrança no botão acima, ou quando um gateway de pagamento (Stripe / Mercado Pago)
      processa uma transação real (ainda não plugado).
    </td></tr>`;
    return;
  }
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
  if (!r.data.invoices.length) {
    tb.innerHTML = `<tr><td colspan="9" class="empty">
      Nenhuma cobrança encontrada com esses filtros. Clique em "+ Nova Cobrança"
      pra criar manualmente, ou ajuste os filtros acima.
    </td></tr>`;
    return;
  }
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

// ── Nova Cobrança (criar invoice manualmente) ────────────────────────────
async function openNewInvoiceModal() {
  // Carrega contas no select
  const r = await fj(`${API}/accounts.php`);
  const selA = document.getElementById('newInvAccount');
  selA.innerHTML = '';
  if (r.ok && r.data.accounts) {
    r.data.accounts.forEach(a => {
      const o = document.createElement('option');
      o.value = a.id;
      o.textContent = `${a.nome} (${a.tipo} · #${a.id})`;
      selA.appendChild(o);
    });
  }
  // Reset form
  document.getElementById('formNewInvoice').reset();
  // Pré-popula vencimento = 30 dias
  const d = new Date(); d.setDate(d.getDate() + 30);
  document.getElementById('newInvDue').value = d.toISOString().slice(0,10);
  // Quando muda conta, recarrega subscriptions disponíveis
  selA.onchange = loadNewInvoiceSubscriptions;
  if (selA.value) loadNewInvoiceSubscriptions();
  openModal('modalNewInvoice');
}

async function loadNewInvoiceSubscriptions() {
  const accId = document.getElementById('newInvAccount').value;
  const selS  = document.getElementById('newInvSub');
  selS.innerHTML = '<option value="">— vincular a uma assinatura —</option>';
  if (!accId) return;
  const r = await fj(`${API}/billing.php`);
  if (!r.ok) return;
  (r.data.subscriptions || []).filter(s => s.account_id == accId).forEach(s => {
    const o = document.createElement('option');
    o.value = s.id;
    o.textContent = `#${s.id} · ${s.plan_nome} (${s.status})`;
    selS.appendChild(o);
  });
}

async function submitNewInvoice(ev) {
  ev.preventDefault();
  const f = ev.target;
  const body = {
    csrf_token: CSRF,
    account_id:      parseInt(f.account_id.value, 10),
    subscription_id: f.subscription_id.value ? parseInt(f.subscription_id.value, 10) : undefined,
    amount_cents:    Math.round(parseFloat(f.valor.value || 0) * 100),
    due_date:        f.due_date.value,
    numero:          f.numero.value.trim(),
    descricao:       f.descricao.value.trim(),
    observacoes:     f.observacoes.value.trim(),
  };
  Object.keys(body).forEach(k => body[k] === undefined && delete body[k]);

  const r = await fj(`${API}/payments.php`, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify(body),
  });
  if (!r.ok) return notifyErr(r.error || 'Falha ao criar cobrança');
  closeModal('modalNewInvoice');
  notifyOk('Cobrança criada com sucesso');
  // Recarrega a tab atual (faturas ou pagamentos)
  loadInvoices();
  loadPayments();
  loadOverview();
  loadDashboard();
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
  if (!r.data.entries.length) {
    tb.innerHTML = `<tr><td colspan="6" class="empty">
      Nenhum registro ainda. O log é preenchido automaticamente quando você
      executa ações no Painel Master (criar/editar conta, alterar plano,
      cancelar assinatura, etc.). Faça uma ação e ela aparece aqui em tempo real.
    </td></tr>`;
    return;
  }
  tb.innerHTML = r.data.entries.map(e => `
    <tr>
      <td>${fmtDateTime(e.created_at)}</td>
      <td>${esc(e.user_nome||'—')} <small style="color:#9ab0c9">${esc(e.sa_nivel||'')}</small></td>
      <td><code style="font-size:.78rem">${esc((window.Yuris && Yuris.translateAuditAcao) ? Yuris.translateAuditAcao(e.acao) : (e.acao || ''))}</code></td> <!-- i18n acao via Yuris.translateAuditAcao -->
      <td>${e.target_type?`${esc(e.target_type)} #${e.target_id||'—'}`:'—'}</td>
      <td>${esc(e.descricao||'—')}</td>
      <td><small>${esc(e.ip||'—')}</small></td>
    </tr>`).join('');
}
document.getElementById('filterAuditAcao').addEventListener('input', () => clearTimeout(window._fa) || (window._fa = setTimeout(loadAudit, 300)));

// ── Dashboard (Chart.js) ─────────────────────────────────────────────────
let _charts = {};
function _isLight() { return document.documentElement.getAttribute('data-theme') === 'light'; }
function _chartColors() {
  const light = _isLight();
  return {
    grid:  light ? 'rgba(15,31,54,.08)'  : 'rgba(160,180,210,.10)',
    tick:  light ? '#5A6B7E'             : '#9ab0c9',
    title: light ? '#0F1F36'             : '#FFFFFF',
  };
}
function _destroyChart(key) {
  if (_charts[key]) { _charts[key].destroy(); delete _charts[key]; }
}

async function loadDashboard() {
  const r = await fj(`${API}/finance.php`);
  if (!r.ok) return notifyErr(r.error);
  const d = r.data;
  const cols = _chartColors();

  // ── Linha 1: caixa REAL ──
  document.getElementById('finReceitaReal').textContent = 'R$ ' + d.receita_real_mes_brl;
  document.getElementById('finDespesa').textContent     = 'R$ ' + d.despesa_mes_brl;
  document.getElementById('finLucroReal').textContent   = 'R$ ' + d.lucro_real_brl;
  document.getElementById('finMesRef').textContent      = 'referência ' + d.mes_referencia;
  // Cor do lucro real: verde se positivo, vermelho se negativo
  document.getElementById('finLucroReal').style.color =
    d.lucro_real_cents >= 0 ? '#4ade80' : '#fca5a5';

  // ── Linha 2: projeção ──
  document.getElementById('finMrrProj').textContent  = 'R$ ' + d.mrr_projetado_brl;
  document.getElementById('finMrrReal').textContent  = 'R$ ' + d.mrr_realizado_brl;
  document.getElementById('finLucroProj').textContent = 'R$ ' + d.lucro_projetado_brl;
  document.getElementById('finLucroProj').style.color =
    d.lucro_projetado_cents >= 0 ? '#86efac' : '#fca5a5';

  const baseOpts = (extra = {}) => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { labels: { color: cols.tick, font: {size: 11} } },
      tooltip: { callbacks: extra.tooltipCb || {} }
    },
    scales: extra.scales || {
      x: { ticks: { color: cols.tick, font:{size:10} }, grid: { color: cols.grid } },
      y: { ticks: { color: cols.tick, font:{size:10}, callback: v => 'R$ ' + (v/100).toLocaleString('pt-BR') }, grid: { color: cols.grid } }
    }
  });

  // 1. MRR últimos 12 meses (line)
  _destroyChart('mrr');
  _charts.mrr = new Chart(document.getElementById('chartMrr'), {
    type: 'line',
    data: {
      labels: d.mrr_serie_12m.map(x => x.mes),
      datasets: [{
        label: 'MRR (R$)',
        data: d.mrr_serie_12m.map(x => x.value),
        borderColor: '#4ade80',
        backgroundColor: 'rgba(74,222,128,.18)',
        tension: 0.32,
        fill: true,
        pointRadius: 3,
      }]
    },
    options: baseOpts({
      tooltipCb: { label: c => 'R$ ' + (c.parsed.y/100).toLocaleString('pt-BR', {minimumFractionDigits:2}) }
    })
  });

  // 2. Receita vs Despesas (bar)
  _destroyChart('recDesp');
  _charts.recDesp = new Chart(document.getElementById('chartRecDesp'), {
    type: 'bar',
    data: {
      labels: d.mrr_serie_12m.map(x => x.mes),
      datasets: [
        { label: 'Receita', data: d.mrr_serie_12m.map(x => x.value),    backgroundColor: 'rgba(74,222,128,.65)' },
        { label: 'Despesa', data: d.despesas_serie_12m.map(x => x.value), backgroundColor: 'rgba(252,165,165,.65)' },
      ]
    },
    options: baseOpts({
      tooltipCb: { label: c => c.dataset.label + ': R$ ' + (c.parsed.y/100).toLocaleString('pt-BR') }
    })
  });

  // 3. Contas por Tipo (donut)
  _destroyChart('tipo');
  const tipoData = d.accounts_por_tipo;
  _charts.tipo = new Chart(document.getElementById('chartTipo'), {
    type: 'doughnut',
    data: {
      labels: ['Matriz', 'Filial', 'Advogado'],
      datasets: [{
        data: [tipoData.matriz, tipoData.filial, tipoData.advogado],
        backgroundColor: ['#60a5fa','#c084fc','#86efac'],
        borderColor: cols.grid, borderWidth: 2,
      }]
    },
    options: { responsive:true, maintainAspectRatio:false,
               plugins:{ legend:{ position:'bottom', labels:{ color: cols.tick, font:{size:11}, padding:10 } } } }
  });

  // 4. Contas por Status (donut)
  _destroyChart('status');
  const statusData = d.accounts_por_status;
  _charts.status = new Chart(document.getElementById('chartStatus'), {
    type: 'doughnut',
    data: {
      labels: ['Active','Trial','Overdue','Suspended','Cancelled','Inactive'],
      datasets: [{
        data: [statusData.active, statusData.trial, statusData.overdue, statusData.suspended, statusData.cancelled, statusData.inactive],
        backgroundColor: ['#4ade80','#c4b5fd','#fca5a5','#fbbf24','#fca5a5','#A8BDD4'],
        borderColor: cols.grid, borderWidth: 2,
      }]
    },
    options: { responsive:true, maintainAspectRatio:false,
               plugins:{ legend:{ position:'bottom', labels:{ color: cols.tick, font:{size:11}, padding:10 } } } }
  });

  // 5. Despesas por Categoria (donut)
  _destroyChart('despCat');
  const catLabels = d.despesas_por_categoria.map(c => c.categoria);
  const catValues = d.despesas_por_categoria.map(c => Number(c.total_cents));
  const catColors = ['#60a5fa','#c084fc','#86efac','#fbbf24','#fca5a5','#94a3b8','#a78bfa','#7dd3fc','#fbbf24'];
  _charts.despCat = new Chart(document.getElementById('chartDespCat'), {
    type: 'doughnut',
    data: {
      labels: catLabels.length ? catLabels : ['(sem despesas neste mês)'],
      datasets: [{
        data: catValues.length ? catValues : [1],
        backgroundColor: catLabels.length ? catColors.slice(0, catLabels.length) : ['rgba(160,180,210,.20)'],
        borderColor: cols.grid, borderWidth: 2,
      }]
    },
    options: {
      responsive:true, maintainAspectRatio:false,
      plugins:{
        legend:{ position:'bottom', labels:{ color: cols.tick, font:{size:11}, padding:8 } },
        tooltip:{ callbacks:{ label: c => c.label + ': R$ ' + (c.parsed/100).toLocaleString('pt-BR', {minimumFractionDigits:2}) } }
      }
    }
  });

  // 6. Receita por Plano (bar horizontal)
  _destroyChart('planos');
  const planos = d.receita_por_plano;
  _charts.planos = new Chart(document.getElementById('chartPlanos'), {
    type: 'bar',
    data: {
      labels: planos.map(p => p.plano),
      datasets: [{
        label: 'MRR (R$)',
        data: planos.map(p => Number(p.mrr_cents)),
        backgroundColor: 'rgba(96,165,250,.65)',
      }]
    },
    options: { indexAxis:'y', ...baseOpts({
      scales: {
        x: { ticks: { color: cols.tick, callback: v => 'R$ ' + (v/100).toLocaleString('pt-BR') }, grid: { color: cols.grid } },
        y: { ticks: { color: cols.tick }, grid: { color: cols.grid } }
      },
      tooltipCb: { label: c => 'R$ ' + (c.parsed.x/100).toLocaleString('pt-BR') }
    })}
  });

  // 7. Crescimento de Contas (line)
  _destroyChart('cresc');
  _charts.cresc = new Chart(document.getElementById('chartCrescimento'), {
    type: 'line',
    data: {
      labels: d.contas_serie_12m.map(x => x.mes),
      datasets: [{
        label: 'Contas (acumulado)',
        data: d.contas_serie_12m.map(x => x.value),
        borderColor: '#a78bfa',
        backgroundColor: 'rgba(167,139,250,.20)',
        tension: 0.32, fill: true, pointRadius: 3,
      }]
    },
    options: {
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ labels:{ color: cols.tick, font:{size:11} } } },
      scales: {
        x: { ticks: { color: cols.tick, font:{size:10} }, grid: { color: cols.grid } },
        y: { ticks: { color: cols.tick, font:{size:10}, precision:0 }, grid: { color: cols.grid }, beginAtZero: true }
      }
    }
  });
}

// ── Despesas ─────────────────────────────────────────────────────────────
let _expensesCache = [];
async function loadExpenses() {
  const params = new URLSearchParams();
  const mm  = document.getElementById('filterExpMonth').value;
  const cat = document.getElementById('filterExpCategoria').value;
  const st  = document.getElementById('filterExpStatus').value;
  if (mm)  params.set('month', mm);
  if (cat) params.set('categoria', cat);
  if (st)  params.set('status', st);

  const r = await fj(`${API}/expenses.php` + (params.toString() ? '?'+params.toString() : ''));
  if (!r.ok) return notifyErr(r.error);
  _expensesCache = r.data.expenses || [];

  // KPIs
  document.getElementById('expTotalMes').textContent = 'R$ ' + r.data.total_brl;
  document.getElementById('expCountMes').textContent = _expensesCache.length + ' despesa(s)';
  document.getElementById('expPendentes').textContent = _expensesCache.filter(e => e.status === 'pendente').length;
  const hoje = new Date().toISOString().slice(0,10);
  document.getElementById('expVencidas').textContent = _expensesCache.filter(e =>
    e.status === 'pendente' && e.vencimento && e.vencimento < hoje
  ).length;

  // Popular select de meses (últimos 12) — uma vez só
  const selMes = document.getElementById('filterExpMonth');
  if (selMes.options.length <= 1) {
    for (let i = 0; i < 12; i++) {
      const dt = new Date(); dt.setMonth(dt.getMonth() - i);
      const mes = dt.toISOString().slice(0,7);
      const o = document.createElement('option'); o.value = mes; o.textContent = mes;
      selMes.appendChild(o);
    }
  }

  const tb = document.getElementById('expensesBody');
  if (!_expensesCache.length) { tb.innerHTML = '<tr><td colspan="9" class="empty">Nenhuma despesa</td></tr>'; return; }
  tb.innerHTML = _expensesCache.map(e => {
    const catIcoKey = _EXP_CAT_ICO[e.categoria] || 'pin';
    const catLbl    = _EXP_CAT_LBL[e.categoria] || e.categoria;
    const recIco    = e.recorrencia === 'mensal'
        ? ico('refresh',  {size:13, color:'#86efac', style:'display:inline;vertical-align:-2px'}) + ' <small style="color:#86efac">mensal</small>'
        : (e.recorrencia === 'anual'
            ? ico('calendar', {size:13, color:'#93c5fd', style:'display:inline;vertical-align:-2px'}) + ' <small style="color:#93c5fd">anual</small>'
            : '<small style="color:#7a8898">—</small>');
    const vencidoFlag = (e.status === 'pendente' && e.vencimento && e.vencimento < hoje);
    const vencidoBadge = vencidoFlag
        ? ' ' + ico('alert', {size:12, color:'#fca5a5', style:'display:inline;vertical-align:-2px;margin-left:4px'})
        : '';
    return `<tr ${vencidoFlag?'style="background:rgba(220,38,38,.05)"':''}>
      <td><span style="display:inline-flex;align-items:center;gap:6px">${ico(catIcoKey, {size:13, style:'flex-shrink:0;color:#9ab0c9'})}<small>${esc(catLbl)}</small></span></td>
      <td><strong>${esc(e.descricao)}</strong></td>
      <td>${esc(e.fornecedor||'—')}</td>
      <td>R$ ${(Number(e.valor_cents)/100).toLocaleString('pt-BR',{minimumFractionDigits:2})}</td>
      <td>${fmtDate(e.data_competencia)}</td>
      <td>${e.vencimento ? fmtDate(e.vencimento) + vencidoBadge : '—'}</td>
      <td>${pill(e.status)}</td>
      <td>${recIco}</td>
      <td>
        <button class="btn-mst" onclick="openExpenseModal(${e.id})">Editar</button>
        ${e.status !== 'pago' ? `<button class="btn-mst btn-mst-success" onclick="markExpensePaid(${e.id})">Marcar pago</button>` : ''}
      </td>
    </tr>`;
  }).join('');
}
document.getElementById('filterExpMonth').addEventListener('change', loadExpenses);
document.getElementById('filterExpCategoria').addEventListener('change', loadExpenses);
document.getElementById('filterExpStatus').addEventListener('change', loadExpenses);

function openExpenseModal(id) {
  const f = document.getElementById('formExpense');
  f.reset();
  document.getElementById('expId').value = id || '';
  document.getElementById('expenseModalTitle').textContent = id ? 'Editar Despesa' : 'Nova Despesa';
  document.getElementById('expDeleteBtn').style.display = id ? '' : 'none';

  if (id) {
    const e = _expensesCache.find(x => x.id == id);
    if (!e) return notifyErr('Despesa não encontrada');
    document.getElementById('expCategoria').value   = e.categoria;
    document.getElementById('expDescricao').value   = e.descricao || '';
    document.getElementById('expFornecedor').value  = e.fornecedor || '';
    document.getElementById('expValor').value       = (Number(e.valor_cents)/100).toFixed(2);
    document.getElementById('expCompetencia').value = e.data_competencia ? e.data_competencia.substring(0,10) : '';
    document.getElementById('expVencimento').value  = e.vencimento ? e.vencimento.substring(0,10) : '';
    document.getElementById('expPagamento').value   = e.data_pagamento ? e.data_pagamento.substring(0,10) : '';
    document.getElementById('expStatus').value      = e.status;
    document.getElementById('expRecorrencia').value = e.recorrencia;
    document.getElementById('expMetodo').value      = e.metodo_pagamento || '';
    document.getElementById('expObs').value         = e.observacoes || '';
  } else {
    // Defaults pra nova: competência = hoje
    document.getElementById('expCompetencia').value = new Date().toISOString().slice(0,10);
  }
  openModal('modalExpense');
}

async function submitExpense(ev) {
  ev.preventDefault();
  const f = ev.target;
  const id = f.id.value;
  const isEdit = !!id;
  const body = {
    csrf_token: CSRF,
    categoria:        f.categoria.value,
    descricao:        f.descricao.value.trim(),
    fornecedor:       f.fornecedor.value.trim(),
    valor_cents:      Math.round(parseFloat(f.valor.value || 0) * 100),
    data_competencia: f.data_competencia.value,
    vencimento:       f.vencimento.value || null,
    data_pagamento:   f.data_pagamento.value || null,
    status:           f.status.value,
    recorrencia:      f.recorrencia.value,
    metodo_pagamento: f.metodo_pagamento.value,
    observacoes:      f.observacoes.value.trim(),
  };
  if (isEdit) body.id = parseInt(id, 10);

  const r = await fj(`${API}/expenses.php`, {
    method: isEdit ? 'PATCH' : 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify(body),
  });
  if (!r.ok) return notifyErr(r.error);
  closeModal('modalExpense');
  notifyOk(isEdit ? 'Despesa atualizada' : 'Despesa criada');
  loadExpenses();
}

async function deleteExpense() {
  const id = parseInt(document.getElementById('expId').value, 10);
  if (!id) return;
  const desc = document.getElementById('expDescricao').value;
  if (!(await Yuris.confirm(`Excluir a despesa "${desc}"?`, {danger:true, okLabel:'Excluir'}))) return;
  const r = await fj(`${API}/expenses.php?id=${id}`, {
    method:'DELETE',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({csrf_token: CSRF, id}),
  });
  if (!r.ok) return notifyErr(r.error);
  closeModal('modalExpense');
  notifyOk('Despesa removida');
  loadExpenses();
}

async function markExpensePaid(id) {
  if (!(await Yuris.confirm('Marcar esta despesa como PAGA?', {okLabel:'Marcar pago'}))) return;
  const r = await fj(`${API}/expenses.php`, {
    method:'PATCH',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({csrf_token: CSRF, id, status: 'pago'}),
  });
  if (!r.ok) return notifyErr(r.error);
  notifyOk('Despesa marcada como paga');
  loadExpenses();
}

// ── Editar Conta (modal) ─────────────────────────────────────────────────
async function openEditAccount(id) {
  const r = await fj(`${API}/accounts.php?id=${id}`);
  if (!r.ok) return notifyErr(r.error);
  const d = r.data;
  document.getElementById('editAccId').value     = d.id;
  document.getElementById('editAccNome').value   = d.nome || '';
  document.getElementById('editAccRazao').value  = d.razao_social || '';
  document.getElementById('editAccCnpj').value   = d.cnpj || '';
  document.getElementById('editAccEmail').value  = d.email || '';
  document.getElementById('editAccTel').value    = d.telefone || '';
  document.getElementById('editAccCidade').value = d.cidade || '';
  document.getElementById('editAccUf').value     = d.estado || '';
  document.getElementById('editAccTipo').value   = d.tipo || 'matriz';
  document.getElementById('editAccStatus').value = d.status || 'active';

  // Popula o select de planos com a lista de plans.php ANTES de aplicar o
  // value salvo. Se a opção não existir (slug legado deletado), o select
  // mostra placeholder e fica visível pro user escolher um novo.
  await fillEditAccPlanos(d.plano || '');

  // Bloco Assinatura: só aparece se a conta tem subscription
  const sub = d.subscription || null;
  const subBlock = document.getElementById('editAccSubBlock');
  if (sub && sub.id) {
    subBlock.style.display = '';
    document.getElementById('editAccSubId').value     = sub.id;
    document.getElementById('editAccSubCycle').value  = sub.billing_cycle || 'monthly';
    document.getElementById('editAccSubTrial').value  = sub.trial_ends_at ? sub.trial_ends_at.substring(0,10) : '';
    document.getElementById('editAccSubPeriod').value = sub.current_period_end ? sub.current_period_end.substring(0,10) : '';
  } else {
    subBlock.style.display = 'none';
    document.getElementById('editAccSubId').value = '';
  }

  // Carrega cota de monitoramentos (Etapa 6 add-on).
  // IMPORTANTE: setamos window._editAccCurrentId AQUI (sincrono) pra
  // garantir que botões/inputs funcionam mesmo se o user clicar antes
  // da chamada AJAX a /quotas.php completar (race condition fix).
  window._editAccCurrentId = id;
  loadEditAccMonitorQuota(id);

  openModal('modalEditAccount');
}

/**
 * Carrega cota de monitoramentos da conta + lista de overrides ativos
 * pra exibir no modal Editar. Chamado quando abre o modal e após cada
 * grant/purchase/revoke. Endpoint: /api/master/quotas.php?account_id=X.
 */
async function loadEditAccMonitorQuota(accountId) {
  // window._editAccCurrentId já foi setado sincronamente em openEditAccount()
  // — não precisa esperar AJAX.
  const limitInput = document.getElementById('editAccMonLimit');
  limitInput.value = '…';
  limitInput.disabled = true;
  document.getElementById('editAccMonUsed').textContent  = '…';
  document.getElementById('editAccMonAvail').textContent = '…';
  document.getElementById('editAccMonOverrides').innerHTML = '<div class="empty">Carregando…</div>';

  const r = await fj(`${API}/quotas.php?account_id=${accountId}`);
  if (!r.ok) {
    document.getElementById('editAccMonOverrides').innerHTML = `<div class="empty">Erro: ${esc(r.error||'desconhecido')}</div>`;
    limitInput.disabled = false;
    return;
  }
  const q = r.data;

  limitInput.value = q.effective_limit || 0;
  limitInput.disabled = false;
  limitInput.dataset.original = q.effective_limit || 0; // pra comparar em saveMonitorLimit
  document.getElementById('editAccMonUsed').textContent  = q.current_usage  || 0;
  const avail = q.available || 0;
  const availEl = document.getElementById('editAccMonAvail');
  availEl.textContent = avail;
  availEl.style.color = avail > 0 ? '#22c55e' : (q.current_usage > q.effective_limit ? '#ef4444' : '#9ab0c9');

  // Tabela de overrides ativos
  const cont = document.getElementById('editAccMonOverrides');
  if (!q.overrides || !q.overrides.length) {
    cont.innerHTML = `<div class="empty" style="margin-top:8px">Sem monitoramentos contratados. Use os botões acima pra liberar grant ou registrar compra.</div>`;
    return;
  }
  let html = `<table class="mst-tbl"><thead><tr>
    <th>Origem</th><th>Qtd</th><th>Contrato</th><th>Preço unit.</th><th>Expira</th><th>Criado</th><th>Observação</th><th>Ações</th>
  </tr></thead><tbody>`;
  q.overrides.forEach(o => {
    const priceFmt = o.unit_price_cents ? fmtBRL(o.unit_price_cents) : '—';
    html += `<tr>
      <td><span class="pill pill-${o.source==='purchase'?'active':'trial'}">${esc(o.source)}</span></td>
      <td><strong>+${o.limit_value}</strong></td>
      <td>${esc(o.contract_ref||'—')}</td>
      <td>${priceFmt}</td>
      <td>${o.expires_at?fmtDate(o.expires_at):'Sem expirar'}</td>
      <td>${fmtDate(o.created_at)}</td>
      <td><small>${esc(o.observacoes||'—')}</small></td>
      <td><button type="button" class="btn-mst btn-mst-danger" onclick="revokeMonitorOverride(${o.id})">Revogar</button></td>
    </tr>`;
  });
  html += `</tbody></table>`;
  cont.innerHTML = html;
}

/**
 * Salva o novo total contratado (inline edit).
 * Backend (/api/master/quotas.php POST com set_total) calcula delta:
 *   - delta > 0: cria grant master_grant com a diferença
 *   - delta < 0: revoga overrides ativos (FIFO) até cobrir a redução
 *   - delta == 0: no-op
 */
async function saveMonitorLimit() {
  const accId = window._editAccCurrentId;
  if (!accId) return notifyErr('Abra primeiro o modal Editar Conta');

  const limitInput = document.getElementById('editAccMonLimit');
  const novo  = parseInt(limitInput.value, 10);
  const orig  = parseInt(limitInput.dataset.original || '0', 10);
  if (isNaN(novo) || novo < 0) return notifyErr('Informe um número válido (0 ou mais)');
  if (novo === orig) return notifyOk('Sem mudanças.');

  // Se reduzir, confirma — pode afetar overrides existentes
  if (novo < orig) {
    const diff = orig - novo;
    const used = parseInt(document.getElementById('editAccMonUsed').textContent, 10) || 0;
    let warn = `Reduzir de ${orig} para ${novo} monitoramentos (-${diff})?`;
    if (used > novo) {
      warn += `\n\n⚠️ Cliente já usa ${used} — vai ficar acima do novo limite. Os monitors existentes NÃO serão removidos, mas novos cadastros serão bloqueados.`;
    }
    if (!(await Yuris.confirm(warn, {danger:true, okLabel:'Reduzir'}))) {
      limitInput.value = orig;
      return;
    }
  }

  // Disable durante request pra evitar duplo clique
  limitInput.disabled = true;
  const r = await fj(`${API}/quotas.php`, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({csrf_token: CSRF, account_id: accId, set_total: novo}),
  });
  limitInput.disabled = false;
  if (!r.ok) return notifyErr(r.error);

  notifyOk(r.data.message || 'Cota atualizada');
  loadEditAccMonitorQuota(accId);
  loadAccounts();
}

/**
 * Abre modal pra liberar GRANT gratuito (cortesia/promo).
 * Usa window._editAccCurrentId que openEditAccount setou.
 */
function openGrantMonitorModal() {
  const accId = window._editAccCurrentId;
  if (!accId) return notifyErr('Abra primeiro o modal Editar Conta');
  document.getElementById('grantMonAccountId').value = accId;
  document.getElementById('grantMonQtd').value = 1;
  document.getElementById('grantMonExpires').value = '';
  document.getElementById('grantMonObs').value = '';
  openModal('modalGrantMonitor');
}

/**
 * Abre modal pra REGISTRAR compra (contrato comercial — sem gateway agora).
 */
function openPurchaseMonitorModal() {
  const accId = window._editAccCurrentId;
  if (!accId) return notifyErr('Abra primeiro o modal Editar Conta');
  document.getElementById('purchMonAccountId').value = accId;
  document.getElementById('purchMonQtd').value = 1;
  document.getElementById('purchMonPrice').value = '';
  document.getElementById('purchMonCycle').value = '';
  document.getElementById('purchMonContract').value = '';
  document.getElementById('purchMonObs').value = '';
  openModal('modalPurchaseMonitor');
}

async function submitGrantMonitor(ev) {
  ev.preventDefault();
  const f = ev.target;
  const body = {
    csrf_token:  CSRF,
    account_id:  parseInt(f.account_id.value, 10),
    limit_value: parseInt(f.qtd.value, 10),
    source:      'master_grant',
    observacoes: f.obs.value.trim() || null,
  };
  const expires = f.expires.value;
  if (expires) body.expires_at = expires + ' 23:59:59';
  const r = await fj(`${API}/quotas.php`, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify(body),
  });
  if (!r.ok) return notifyErr(r.error);
  closeModal('modalGrantMonitor');
  notifyOk(`Liberado: +${body.limit_value} monitor(s)`);
  // Atualiza UI
  loadEditAccMonitorQuota(body.account_id);
  loadAccounts(); // refresh da lista de fundo
}

async function submitPurchaseMonitor(ev) {
  ev.preventDefault();
  const f = ev.target;
  const body = {
    csrf_token:    CSRF,
    account_id:    parseInt(f.account_id.value, 10),
    limit_value:   parseInt(f.qtd.value, 10),
    source:        'purchase',
    observacoes:   f.obs.value.trim() || null,
    contract_ref:  f.contract.value.trim() || null,
  };
  const price = parseFloat(f.price.value);
  if (!isNaN(price) && price > 0) body.unit_price_cents = Math.round(price * 100);
  if (f.cycle.value) body.billing_cycle = f.cycle.value;

  const r = await fj(`${API}/quotas.php`, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify(body),
  });
  if (!r.ok) return notifyErr(r.error);
  closeModal('modalPurchaseMonitor');
  notifyOk(`Compra registrada: +${body.limit_value} monitor(s)`);
  loadEditAccMonitorQuota(body.account_id);
  loadAccounts();
}

async function revokeMonitorOverride(overrideId) {
  if (!(await Yuris.confirm('Revogar este override de monitoramento? A cota será reduzida.', {danger:true, okLabel:'Revogar'}))) return;
  const r = await fj(`${API}/quotas.php?id=${overrideId}`, {
    method: 'DELETE',
    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({csrf_token: CSRF}),
  });
  if (!r.ok) return notifyErr(r.error);
  notifyOk('Override revogado');
  // Atualiza onde quer que esteja sendo exibido
  if (window._editAccCurrentId) loadEditAccMonitorQuota(window._editAccCurrentId);
  loadAccounts();
  loadBilling(); // refresh tabela Assinaturas (linha do monitor some)
}

/**
 * Abre o modal de "Nova assinatura de monitor" — atalho da aba Assinaturas.
 * Popula select com contas ativas (cache _accountsListMin) e pré-seleciona
 * a Silvana ou primeira da lista se _accountsCacheMin tem dados.
 */
async function openNovaMonitorSubModal() {
  // Carrega lista de contas (sem filtro — Master vê todas)
  const sel = document.getElementById('novaMonSubAccount');
  sel.innerHTML = '<option value="">— carregando contas… —</option>';
  sel.disabled = true;

  const r = await fj(`${API}/accounts.php`);
  if (!r.ok) {
    sel.innerHTML = '<option value="">— erro ao carregar contas —</option>';
    return notifyErr(r.error);
  }
  const accs = (r.data.accounts || []).filter(a => a.status !== 'deleted');
  sel.innerHTML = '<option value="">— escolha a conta —</option>'
    + accs.map(a => `<option value="${a.id}">#${a.id} · ${esc(a.nome)} (${esc(a.tipo)})</option>`).join('');
  sel.disabled = false;

  // Reset form
  const form = sel.closest('form');
  form.querySelector('[name=qtd]').value      = 10;
  form.querySelector('[name=price]').value    = '';
  form.querySelector('[name=cycle]').value    = 'monthly';
  form.querySelector('[name=contract]').value = '';
  form.querySelector('[name=obs]').value      = '';

  openModal('modalNovaMonitorSub');
}

async function submitNovaMonitorSub(ev) {
  ev.preventDefault();
  const f = ev.target;
  const accId = parseInt(f.account_id.value, 10);
  if (!accId) return notifyErr('Escolha uma conta');

  const body = {
    csrf_token:   CSRF,
    account_id:   accId,
    limit_value:  parseInt(f.qtd.value, 10),
    source:       'purchase',
    billing_cycle: f.cycle.value,           // monthly | quarterly | yearly
    contract_ref: f.contract.value.trim() || null,
    observacoes:  f.obs.value.trim() || null,
  };
  const price = parseFloat(f.price.value);
  if (!isNaN(price) && price > 0) body.unit_price_cents = Math.round(price * 100);

  const r = await fj(`${API}/quotas.php`, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify(body),
  });
  if (!r.ok) return notifyErr(r.error);

  closeModal('modalNovaMonitorSub');
  notifyOk(`Assinatura criada: +${body.limit_value} monitor(s)`);
  loadBilling();  // recarrega aba Assinaturas — linha nova aparece
  loadAccounts(); // refresh contagem da coluna Monitors em Contas
}

async function submitEditAccount(ev) {
  ev.preventDefault();
  const f = ev.target;
  const id = parseInt(f.id.value, 10);
  const body = {
    csrf_token: CSRF, id,
    nome:         f.nome.value.trim(),
    razao_social: f.razao_social.value.trim(),
    cnpj:         f.cnpj.value.trim(),
    email:        f.email.value.trim(),
    telefone:     f.telefone.value.trim(),
    cidade:       f.cidade.value.trim(),
    estado:       f.estado.value.trim().toUpperCase(),
    tipo:         f.tipo.value,
    status:       f.status.value,
    plano:        f.plano.value.trim(),
  };
  const r = await fj(`${API}/accounts.php`, {
    method: 'PATCH',
    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify(body),
  });
  if (!r.ok) return notifyErr(r.error || 'Falha ao salvar conta');

  // Se houver assinatura, faz PATCH separado em billing.php com ciclo + datas
  const subId = parseInt(document.getElementById('editAccSubId').value || '0', 10);
  if (subId > 0) {
    const subBody = {
      csrf_token: CSRF,
      subscription_id:    subId,
      billing_cycle:      document.getElementById('editAccSubCycle').value,
      trial_ends_at:      document.getElementById('editAccSubTrial').value || null,
      current_period_end: document.getElementById('editAccSubPeriod').value || null,
    };
    const r2 = await fj(`${API}/billing.php`, {
      method: 'PATCH',
      headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify(subBody),
    });
    if (!r2.ok) return notifyErr(r2.error || 'Conta salva, mas falha ao salvar assinatura');
  }

  closeModal('modalEditAccount');
  notifyOk('Conta atualizada');
  loadAccounts();
  loadOverview();
}

async function deleteAccount() {
  const id = parseInt(document.getElementById('editAccId').value, 10);
  if (!id) return;
  const nome = document.getElementById('editAccNome').value;
  if (!(await Yuris.confirm(`Excluir a conta "${nome}"? Isso é um soft-delete (status vira "cancelled" e deleted_at preenchido). Não apaga dados de processos/cards.`, {danger:true, okLabel:'Excluir conta'}))) return;
  const r = await fj(`${API}/accounts.php?id=${id}`, {
    method:'DELETE',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({csrf_token: CSRF, id}),
  });
  if (!r.ok) return notifyErr(r.error);
  closeModal('modalEditAccount');
  notifyOk('Conta excluída (soft-delete)');
  loadAccounts();
}

// ── Editar Usuário (modal) ───────────────────────────────────────────────
async function openEditUser(id) {
  const r = await fj(`${API}/users.php?id=${id}`);
  if (!r.ok) return notifyErr(r.error);
  const u = r.data;
  document.getElementById('editUserId').value    = u.id;
  document.getElementById('editUserNome').value  = u.nome || '';
  document.getElementById('editUserEmail').value = u.email || '';
  document.getElementById('editUserTel').value   = u.telefone || '';
  document.getElementById('editUserRole').value  = u.role || 'user';
  document.getElementById('editUserStatus').value= u.status || 'active';
  document.getElementById('editUserNewPass').value = '';
  document.getElementById('editUserAccountInfo').textContent =
    (u.account_nome || '—') + ' · ' + (u.account_tipo || '—') + ' (#' + u.account_id + ')';

  // Adv fields aparecem só se for advogado
  const isAdv = !!u.is_advogado;
  document.getElementById('editUserAdvRow').style.display = isAdv ? '' : 'none';
  if (isAdv) {
    document.getElementById('editUserOab').value    = u.oab || '';
    document.getElementById('editUserOabUf').value  = u.oab_uf || '';
    document.getElementById('editUserCodAdv').value = u.codigo_advogado || '';
  }
  openModal('modalEditUser');
}

async function submitEditUser(ev) {
  ev.preventDefault();
  const f = ev.target;
  const id = parseInt(f.id.value, 10);
  const body = {
    csrf_token: CSRF, id,
    nome:     f.nome.value.trim(),
    email:    f.email.value.trim(),
    telefone: f.telefone.value.trim(),
    role:     f.role.value,
    status:   f.status.value,
  };
  if (f.nova_senha.value) body.nova_senha = f.nova_senha.value;
  const oab    = document.getElementById('editUserOab').value;
  const oab_uf = document.getElementById('editUserOabUf').value;
  if (oab    || oab_uf) { body.oab    = oab.trim();    body.oab_uf = oab_uf.trim().toUpperCase(); }

  const r = await fj(`${API}/users.php`, {
    method: 'PATCH',
    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify(body),
  });
  if (!r.ok) return notifyErr(r.error);
  closeModal('modalEditUser');
  notifyOk('Usuário atualizado');
}

async function resetUserPassword() {
  const id = parseInt(document.getElementById('editUserId').value, 10);
  if (!id) return;
  if (!(await Yuris.confirm('Gerar nova senha temporária pra este usuário?', {okLabel:'Resetar'}))) return;
  const r = await fj(`${API}/users.php?reset_password=1`, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({csrf_token: CSRF, id}),
  });
  if (!r.ok) return notifyErr(r.error);
  Yuris.notify(`Nova senha: ${r.data.senha_gerada}`, {type:'success', duration:18000});
}

async function quickResetPassword(id) {
  if (!(await Yuris.confirm('Resetar a senha deste usuário? Vai gerar uma nova senha temporária.', {okLabel:'Resetar'}))) return;
  const r = await fj(`${API}/users.php?reset_password=1`, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({csrf_token: CSRF, id}),
  });
  if (!r.ok) return notifyErr(r.error);
  Yuris.notify(`Nova senha: ${r.data.senha_gerada}`, {type:'success', duration:18000});
}

async function deleteUser() {
  const id = parseInt(document.getElementById('editUserId').value, 10);
  if (!id) return;
  const nome = document.getElementById('editUserNome').value;
  if (!(await Yuris.confirm(`Excluir o usuário "${nome}"? Soft-delete: a sessão dele expira na próxima navegação.`, {danger:true, okLabel:'Excluir'}))) return;
  const r = await fj(`${API}/users.php?id=${id}`, {
    method: 'DELETE',
    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({csrf_token: CSRF, id}),
  });
  if (!r.ok) return notifyErr(r.error);
  closeModal('modalEditUser');
  notifyOk('Usuário removido');
}

// ═══════════════════════════════════════════════════════════════════════════
// LGPD — Central de solicitações (Art. 18)
// ═══════════════════════════════════════════════════════════════════════════
async function loadLgpdRequests() {
  const status   = document.getElementById('filterLgpdStatus').value;
  const tipo     = document.getElementById('filterLgpdTipo').value;
  const atrasada = document.getElementById('filterLgpdAtrasada').checked ? '1' : '';
  const qs = new URLSearchParams();
  if (status)   qs.set('status', status);
  if (tipo)     qs.set('tipo', tipo);
  if (atrasada) qs.set('atrasada', '1');

  const r = await fj(`${API}/lgpd_requests.php?${qs.toString()}`);
  const tbody = document.getElementById('lgpdBody');
  if (!r.ok || !Array.isArray(r.data)) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty">Erro ao carregar.</td></tr>';
    return;
  }
  if (r.data.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty">Nenhuma solicitação encontrada com esses filtros.</td></tr>';
    return;
  }

  const tipoLabels = {
    confirmacao_existencia: 'Confirmação',
    acesso: 'Acesso',
    correcao: 'Correção',
    anonimizacao: 'Anonimização',
    bloqueio: 'Bloqueio',
    eliminacao: 'Eliminação',
    portabilidade: 'Portabilidade',
    info_compartilhamento: 'Compartilhamento',
    revogacao_consentimento: 'Revogar consent.',
    revisao_decisao_automatizada: 'Revisão decisão',
  };
  const statusColors = {
    aberto: '#f59e0b', em_analise: '#3b82f6', aguardando_titular: '#a855f7',
    concluido: '#10b981', rejeitado: '#ef4444', expirado: '#94a3b8',
  };

  tbody.innerHTML = r.data.map(row => {
    const prazoDate  = new Date(row.prazo_resposta);
    const atrasada   = prazoDate < new Date() && ['aberto','em_analise','aguardando_titular'].includes(row.status);
    const atrasoTag  = atrasada ? ' <span style="color:#ef4444;font-weight:700">ATRASADA</span>' : '';
    return `<tr>
      <td>#${row.id}</td>
      <td>
        <div style="font-weight:600">${escL(row.titular_nome)}</div>
        <div style="font-size:.75rem;color:#9ab0c9">${escL(row.titular_email)}</div>
      </td>
      <td>${escL(tipoLabels[row.tipo] || row.tipo)}</td>
      <td style="font-size:.78rem">${fmtDateTime(row.recebido_em)}</td>
      <td style="font-size:.78rem">${fmtDateTime(row.prazo_resposta)}${atrasoTag}</td>
      <td><span style="padding:3px 9px;border-radius:999px;background:${statusColors[row.status]||'#94a3b8'}1f;border:1px solid ${statusColors[row.status]||'#94a3b8'}40;color:${statusColors[row.status]||'#94a3b8'};font-size:.74rem;font-weight:600">${escL(row.status)}</span></td>
      <td><button class="btn-mst" onclick="openLgpdDrawer(${row.id})">Abrir</button></td>
    </tr>`;
  }).join('');
}
// helper escape isolado (escL = escape LGPD) pra não conflitar com helpers globais
function escL(s) { return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }

// ── Aceites de Termos (consome /api/master/consents.php) ───────────────────
// Lê os registros de consentimento (finalidade=termos_uso_login) — prova de
// aceite aos Termos no login. Somente leitura; mutação/revogação é feita via
// fluxo de Solicitações LGPD (revogacao_consentimento). Endpoint exige
// master_mode no servidor.
let _consentsTimer;
async function loadConsents() {
  const q      = document.getElementById('filterConsentQ').value.trim();
  const status = document.getElementById('filterConsentStatus').value;
  const qs = new URLSearchParams({ finalidade: 'termos_uso_login' });
  if (q)      qs.set('q', q);
  if (status) qs.set('status', status);

  const tbody = document.getElementById('consentsBody');
  const r = await fj(`${API}/consents.php?${qs.toString()}`);
  if (!r.ok || !r.data || !Array.isArray(r.data.items)) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty">Erro ao carregar aceites.</td></tr>';
    return;
  }
  const items = r.data.items;
  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty">Nenhum aceite encontrado com esses filtros.</td></tr>';
    return;
  }
  tbody.innerHTML = items.map(c => {
    const ativo = c.status === 'ativo';
    const stColor = ativo ? '#10b981' : '#ef4444';
    const stTxt = ativo ? 'ativo' : escL(c.status);
    const stExtra = (!ativo && c.revogado_em) ? `<div style="font-size:.7rem;color:#9ab0c9">rev. ${fmtDate(c.revogado_em)}</div>` : '';
    return `<tr>
      <td>#${c.id}</td>
      <td>
        <div style="font-weight:600">${escL(c.user_nome || c.email || '—')}</div>
        ${c.user_nome && c.email ? `<div style="font-size:.75rem;color:#9ab0c9">${escL(c.email)}</div>` : ''}
      </td>
      <td>${escL(c.account_nome || '—')}</td>
      <td style="font-size:.78rem">${escL(c.base_legal || '—')}</td>
      <td style="font-size:.78rem">${fmtDateTime(c.concedido_em)}</td>
      <td style="font-size:.74rem;font-family:ui-monospace,monospace;color:#9ab0c9" title="${escL(c.user_agent || '')}">${escL(c.ip || '—')}</td>
      <td><span style="padding:3px 9px;border-radius:999px;background:${stColor}1f;border:1px solid ${stColor}40;color:${stColor};font-size:.74rem;font-weight:600">${stTxt}</span>${stExtra}</td>
    </tr>`;
  }).join('');

  refreshConsentsBadge();
}

async function refreshConsentsBadge() {
  try {
    const r = await fj(`${API}/consents.php?counts=1`);
    if (!r.ok || !r.data) return;
    const badge = document.getElementById('consentsBadge');
    const total = r.data.total_ativos || 0;
    if (total > 0) {
      badge.style.display = 'inline-block';
      badge.textContent = total;
      badge.title = `${total} aceites ativos · ${r.data.hoje || 0} hoje · ${r.data.ultimos_7d || 0} nos últimos 7 dias`;
    } else {
      badge.style.display = 'none';
    }
  } catch (_) {}
}

document.getElementById('filterConsentStatus').addEventListener('change', loadConsents);
document.getElementById('filterConsentQ').addEventListener('input', () => {
  clearTimeout(_consentsTimer);
  _consentsTimer = setTimeout(loadConsents, 300);
});

// ═════ Central LGPD v2: drawer com abas (Migration 057 + APIs F4) ═════════
// Guarda estado da solicitacao atual aberta (alimentado pelo fullDetail).
window._lgpdData = null;

async function openLgpdDrawer(id) {
  const r = await fj(`${API}/lgpd_requests.php?id=${id}`);
  if (!r.ok) return notifyErr(r.error || 'Erro');
  // fullDetail v2 retorna { request, eventos, modules, findings,
  // attachments, retentions, counts, prazo_efetivo }
  window._lgpdData = r.data;
  const req = r.data.request;
  window._lgpdCurrentEmail = req.titular_email;
  window._lgpdCurrentReqId = req.id;

  // Header (cabeçalho fixo + abas)
  const prazoBase = req.prazo_prorrogado_em || req.prazo_resposta;
  const atrasada = (new Date(prazoBase)) < new Date()
                 && ['aberto','em_analise','aguardando_titular'].includes(req.status);
  const titularTipoBadge = req.titular_tipo
    ? `<span style="padding:2px 8px;border-radius:999px;background:rgba(96,165,250,.10);color:#7eb8f7;font-size:.7rem;font-weight:600;text-transform:uppercase">${escL(req.titular_tipo)}</span>`
    : `<span style="padding:2px 8px;border-radius:999px;background:rgba(245,158,11,.10);color:#f59e0b;font-size:.7rem;font-weight:600">tipo nao classificado</span>`;

  document.getElementById('lgpdDrawerTitle').textContent =
    `Solicitação #${req.id} — ${escL(req.titular_nome)}`;

  document.getElementById('lgpdDrawerBody').innerHTML = `
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
      ${titularTipoBadge}
      <span style="padding:2px 9px;border-radius:999px;background:rgba(96,165,250,.10);color:#7eb8f7;font-size:.72rem;font-weight:600">${escL(req.tipo)}</span>
      <span style="padding:2px 9px;border-radius:999px;background:rgba(160,180,210,.10);color:#9ab0c9;font-size:.72rem">${escL(req.canal_origem||'-')}</span>
      <span style="padding:2px 9px;border-radius:999px;background:rgba(245,158,11,.12);color:#f59e0b;font-size:.72rem;font-weight:600;text-transform:uppercase">prio: ${escL(req.prioridade||'media')}</span>
      ${atrasada ? '<span style="padding:2px 9px;border-radius:999px;background:#ef44441f;color:#ef4444;font-size:.72rem;font-weight:700">ATRASADA</span>' : ''}
      ${req.prazo_prorrogado_em ? `<span style="padding:2px 9px;border-radius:999px;background:rgba(168,85,247,.12);color:#a855f7;font-size:.72rem">prorrogada até ${fmtDate(req.prazo_prorrogado_em)}</span>` : ''}
      ${req.atendimento_parcial == 1 ? '<span style="padding:2px 9px;border-radius:999px;background:rgba(96,165,250,.10);color:#7eb8f7;font-size:.72rem">atendimento parcial</span>' : ''}
    </div>

    <!-- Tabs -->
    <div id="lgpdTabsNav" style="display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid rgba(160,180,210,.15);margin-bottom:14px">
      ${['dados','modulos','findings','anexos','retencoes','historico'].map(t => `
        <button type="button" data-lgpdtab="${t}" onclick="showLgpdTab('${t}')"
                style="background:transparent;border:none;color:#9ab0c9;padding:8px 14px;cursor:pointer;font-size:.85rem;font-weight:600;border-bottom:2px solid transparent">${escL(_lgpdTabLabel(t))}</button>
      `).join('')}
    </div>

    <div id="lgpdTabContent"></div>

    <!-- Footer fixo: acoes globais -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px;padding-top:14px;border-top:1px solid rgba(160,180,210,.10);justify-content:flex-end">
      <button class="btn-mst" onclick="runLgpdSearch()" style="background:rgba(96,165,250,.12);color:#7eb8f7">Buscar dados deste titular</button>
      <button class="btn-mst" onclick="prorrogarLgpd()">Solicitar prorrogação</button>
      <button class="btn-mst" onclick="updateLgpdStatus('em_analise')">Em análise</button>
      <button class="btn-mst" onclick="updateLgpdStatus('aguardando_titular')">Aguardando titular</button>
      <button class="btn-mst" style="background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;border:none" onclick="updateLgpdStatus('rejeitado')">Rejeitar</button>
      <button class="btn-mst btn-mst-primary" onclick="updateLgpdStatus('concluido')">Concluir</button>
    </div>

    <input type="hidden" id="lgpdReqId" value="${req.id}">
  `;
  showLgpdTab('dados');
  openModal('modalLgpd');
}

function _lgpdTabLabel(t) {
  const labels = {dados:'Dados', modulos:'Módulos pesquisados', findings:'Dados encontrados',
                  anexos:'Anexos', retencoes:'Justificativas retenção', historico:'Histórico'};
  return labels[t] || t;
}

function showLgpdTab(name) {
  // marca botão ativo
  document.querySelectorAll('#lgpdTabsNav [data-lgpdtab]').forEach(b => {
    const active = b.dataset.lgpdtab === name;
    b.style.color = active ? '#7eb8f7' : '#9ab0c9';
    b.style.borderBottomColor = active ? '#7eb8f7' : 'transparent';
  });
  const el = document.getElementById('lgpdTabContent');
  if (!el) return;
  switch (name) {
    case 'dados':     el.innerHTML = _renderLgpdDados();      break;
    case 'modulos':   el.innerHTML = _renderLgpdModulos();    break;
    case 'findings':  el.innerHTML = _renderLgpdFindings();   break;
    case 'anexos':    el.innerHTML = _renderLgpdAnexos();     break;
    case 'retencoes': el.innerHTML = _renderLgpdRetencoes();  break;
    case 'historico': el.innerHTML = _renderLgpdHistorico();  break;
  }
}

function _renderLgpdDados() {
  const d = window._lgpdData;
  const req = d.request;
  return `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;font-size:.82rem;color:#9ab0c9;margin-bottom:16px">
      <div>E-mail: <strong style="color:#fff">${escL(req.titular_email)}</strong></div>
      <div>CPF: <strong style="color:#fff">${escL(req.titular_cpf || '—')}</strong></div>
      <div>Telefone: <strong style="color:#fff">${escL(req.titular_telefone || '—')}</strong></div>
      <div>Tipo: <strong style="color:#fff">${escL(req.tipo)}</strong></div>
      <div>Recebido: <strong style="color:#fff">${fmtDateTime(req.recebido_em)}</strong></div>
      <div>Prazo: <strong style="color:#fff">${fmtDate(req.prazo_prorrogado_em || req.prazo_resposta)}</strong></div>
      ${req.titular_referencia_entidade ? `<div>Referencia: <strong style="color:#fff">${escL(req.titular_referencia_entidade)}#${escL(req.titular_referencia_id||'-')}</strong></div>` : ''}
      ${req.account_id ? `<div>Tenant: <strong style="color:#fff">#${escL(req.account_id)}</strong></div>` : ''}
    </div>
    ${req.descricao ? `<h4 style="color:#fff;margin:14px 0 4px">Descrição do titular</h4>
      <div style="background:rgba(96,165,250,.05);padding:10px;border-radius:6px;white-space:pre-wrap;color:#cbd5e1">${escL(req.descricao)}</div>` : ''}

    <h4 style="color:#fff;margin:18px 0 6px">Responder / Atualizar</h4>
    <textarea id="lgpdResposta" rows="5" placeholder="Digite a resposta ao titular..." style="width:100%;padding:10px;border:1px solid rgba(160,180,210,.18);border-radius:6px;background:rgba(5,18,39,.6);color:#fff;font:inherit;resize:vertical">${escL(req.resposta || '')}</textarea>
    <textarea id="lgpdMotivoRejeicao" rows="2" placeholder="Motivo de rejeição (se aplicável)..." style="width:100%;margin-top:8px;padding:10px;border:1px solid rgba(239,68,68,.20);border-radius:6px;background:rgba(5,18,39,.6);color:#fca5a5;font:inherit;resize:vertical">${escL(req.motivo_rejeicao || '')}</textarea>
  `;
}

function _renderLgpdModulos() {
  const mods = window._lgpdData.modules || [];
  if (mods.length === 0) {
    return `<div style="padding:14px;text-align:center;color:#9ab0c9;background:rgba(8,12,24,.4);border-radius:8px">
      Nenhum módulo pesquisado ainda.<br>
      Clique em <strong style="color:#7eb8f7">"Buscar dados deste titular"</strong> abaixo para popular automaticamente.
    </div>`;
  }
  const total = mods.reduce((s, m) => s + (parseInt(m.total_registros, 10) || 0), 0);
  return `
    <div style="color:#9ab0c9;font-size:.82rem;margin-bottom:10px">
      ${mods.length} módulo(s) pesquisado(s) · <strong style="color:#fff">${total}</strong> registro(s) totais encontrados
    </div>
    <table class="mst-tbl">
      <thead><tr><th>Módulo</th><th>Registros</th><th>Resumo</th><th>Pesquisado em</th><th>Por</th></tr></thead>
      <tbody>${mods.map(m => `<tr>
        <td><strong>${escL(m.modulo)}</strong></td>
        <td>${(parseInt(m.total_registros,10)||0) > 0
              ? `<span style="color:#10b981;font-weight:600">${escL(m.total_registros)}</span>`
              : '<span style="color:#9ab0c9">0</span>'}</td>
        <td style="font-size:.82rem;color:#cbd5e1">${escL(m.resumo || '-')}</td>
        <td style="font-size:.78rem">${fmtDateTime(m.pesquisado_em)}</td>
        <td style="font-size:.78rem">${escL(m.pesquisado_por || '-')}</td>
      </tr>`).join('')}</tbody>
    </table>
  `;
}

function _renderLgpdFindings() {
  const f = window._lgpdData.findings || [];
  const counts = (window._lgpdData.counts && window._lgpdData.counts.findings) || {};
  if (f.length === 0) {
    return `<div style="padding:14px;text-align:center;color:#9ab0c9;background:rgba(8,12,24,.4);border-radius:8px">
      Nenhum dado encontrado ainda. Use "Buscar dados deste titular" para popular.
    </div>`;
  }
  return `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-bottom:14px;font-size:.78rem">
      <div style="background:rgba(96,165,250,.08);padding:8px;border-radius:6px"><strong style="color:#fff;font-size:1.1rem">${counts.total||0}</strong><br><span style="color:#9ab0c9">total</span></div>
      <div style="background:rgba(16,185,129,.08);padding:8px;border-radius:6px"><strong style="color:#10b981;font-size:1.1rem">${counts.revisados||0}</strong><br><span style="color:#9ab0c9">revisados</span></div>
      <div style="background:rgba(245,158,11,.08);padding:8px;border-radius:6px"><strong style="color:#f59e0b;font-size:1.1rem">${counts.pendentes_revisao||0}</strong><br><span style="color:#9ab0c9">pendentes</span></div>
      <div style="background:rgba(96,165,250,.08);padding:8px;border-radius:6px"><strong style="color:#7eb8f7;font-size:1.1rem">${counts.no_export||0}</strong><br><span style="color:#9ab0c9">no export</span></div>
      <div style="background:rgba(239,68,68,.08);padding:8px;border-radius:6px"><strong style="color:#ef4444;font-size:1.1rem">${counts.retencao_marcada||0}</strong><br><span style="color:#9ab0c9">retidos</span></div>
    </div>
    <table class="mst-tbl">
      <thead><tr><th>Módulo</th><th>Entidade</th><th>Campo</th><th>Valor (mascarado)</th><th>Tipo</th><th>No export?</th><th>Ações</th></tr></thead>
      <tbody>${f.map(x => `<tr>
        <td style="font-size:.78rem">${escL(x.modulo)}</td>
        <td style="font-size:.78rem">${escL(x.entidade)}#${escL(x.entidade_id)}</td>
        <td style="font-size:.78rem">${escL(x.campo || '-')}</td>
        <td><code style="background:rgba(8,12,24,.5);padding:2px 6px;border-radius:4px;color:#cbd5e1;font-size:.78rem">${escL(x.valor_mascarado || '-')}</code></td>
        <td><span style="padding:2px 7px;border-radius:6px;background:rgba(96,165,250,.1);color:#7eb8f7;font-size:.7rem">${escL(x.tipo_dado)}</span></td>
        <td><input type="checkbox" ${x.incluido_no_export==1?'checked':''} onchange="toggleLgpdFindingExport(${x.id}, this.checked)"></td>
        <td>
          ${x.pode_excluir == null
            ? `<button class="btn-mst" style="padding:3px 8px;font-size:.72rem" onclick="reviewLgpdFinding(${x.id})">Revisar</button>`
            : (x.pode_excluir == 1
                ? '<span style="color:#10b981;font-size:.72rem;font-weight:600">✓ pode excluir</span>'
                : `<span style="color:#ef4444;font-size:.72rem;font-weight:600" title="${escL(x.motivo_retencao||'')}">✗ retido</span>`)}
          <button class="btn-mst" style="padding:3px 8px;font-size:.72rem;margin-left:4px" onclick="openRegisterRetention(${x.id}, '${escL(x.entidade)}', ${escL(x.entidade_id)})">Reter</button>
        </td>
      </tr>`).join('')}</tbody>
    </table>
  `;
}

function _renderLgpdAnexos() {
  const a = window._lgpdData.attachments || [];
  return `
    <div style="margin-bottom:14px;padding:12px;background:rgba(96,165,250,.05);border-radius:8px">
      <h4 style="color:#fff;margin:0 0 8px;font-size:.92rem">Adicionar anexo</h4>
      <form id="lgpdUploadForm" onsubmit="uploadLgpdAttachment(event)" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <select name="categoria" required style="padding:6px 10px;border-radius:6px;background:rgba(5,18,39,.6);border:1px solid rgba(160,180,210,.18);color:#fff;font:inherit;font-size:.82rem">
          <option value="">Categoria...</option>
          <option value="documento_titular">Documento do titular (RG/CPF)</option>
          <option value="procuracao">Procuração</option>
          <option value="export_dados">Export de dados</option>
          <option value="comprovante_envio">Comprovante de envio</option>
          <option value="evidencia_analise">Evidência de análise</option>
          <option value="justificativa_juridica">Justificativa jurídica</option>
          <option value="outro">Outro</option>
        </select>
        <select name="visibilidade" style="padding:6px 10px;border-radius:6px;background:rgba(5,18,39,.6);border:1px solid rgba(160,180,210,.18);color:#fff;font:inherit;font-size:.82rem">
          <option value="interno">Interno (só DPO)</option>
          <option value="entregue_titular">Entregue ao titular</option>
        </select>
        <input name="descricao" placeholder="Descrição (opcional)" style="flex:1;min-width:160px;padding:6px 10px;border-radius:6px;background:rgba(5,18,39,.6);border:1px solid rgba(160,180,210,.18);color:#fff;font:inherit;font-size:.82rem">
        <input name="file" type="file" required style="font-size:.78rem;color:#cbd5e1">
        <button class="btn-mst btn-mst-primary" type="submit" style="font-size:.82rem">Enviar</button>
      </form>
      <div style="font-size:.72rem;color:#9ab0c9;margin-top:6px">Máx. 25 MB. Aceita PDF, ZIP, imagens, Office, texto.</div>
    </div>
    ${a.length === 0
      ? '<div style="padding:14px;text-align:center;color:#9ab0c9;background:rgba(8,12,24,.4);border-radius:8px">Nenhum anexo ainda.</div>'
      : `<table class="mst-tbl">
          <thead><tr><th>Arquivo</th><th>Categoria</th><th>Visibilidade</th><th>Tamanho</th><th>Enviado em</th><th>Por</th><th>Ações</th></tr></thead>
          <tbody>${a.map(x => `<tr>
            <td><strong style="font-size:.82rem">${escL(x.nome_arquivo)}</strong><div style="font-size:.7rem;color:#9ab0c9">${escL(x.tipo_arquivo)}</div></td>
            <td style="font-size:.78rem">${escL(x.categoria)}</td>
            <td><span style="padding:2px 7px;border-radius:6px;background:${x.visibilidade==='entregue_titular'?'rgba(16,185,129,.12);color:#10b981':'rgba(96,165,250,.10);color:#7eb8f7'};font-size:.7rem;font-weight:600">${escL(x.visibilidade)}</span></td>
            <td style="font-size:.78rem">${escL(Math.round(x.tamanho/1024))} KB</td>
            <td style="font-size:.78rem">${fmtDateTime(x.created_at)}</td>
            <td style="font-size:.78rem">${escL(x.uploaded_by_nome||'-')}</td>
            <td>
              <a href="${API}/lgpd_request_attachments.php?download=${x.id}" target="_blank" rel="noopener" class="btn-mst" style="padding:3px 8px;font-size:.72rem;text-decoration:none">Baixar</a>
              ${x.visibilidade==='interno' ? `<button class="btn-mst" style="padding:3px 8px;font-size:.72rem;margin-left:4px" onclick="releaseAttachment(${x.id})">Liberar</button>` : ''}
            </td>
          </tr>`).join('')}</tbody>
        </table>`}
  `;
}

function _renderLgpdRetencoes() {
  const r = window._lgpdData.retentions || [];
  if (r.length === 0) {
    return '<div style="padding:14px;text-align:center;color:#9ab0c9;background:rgba(8,12,24,.4);border-radius:8px">Nenhuma justificativa de retenção registrada.</div>';
  }
  return `
    <table class="mst-tbl">
      <thead><tr><th>Entidade</th><th>Base Legal (Art. 7/16)</th><th>Justificativa</th><th>Prazo</th><th>Responsável</th><th>Aprovação 4-eyes</th></tr></thead>
      <tbody>${r.map(x => `<tr>
        <td style="font-size:.78rem">${escL(x.entidade)}#${escL(x.entidade_id)}</td>
        <td><span style="padding:2px 7px;border-radius:6px;background:rgba(168,85,247,.10);color:#a855f7;font-size:.7rem;font-weight:600">${escL(x.base_legal_retencao)}</span></td>
        <td style="font-size:.78rem;max-width:280px">${escL(x.justificativa)}${x.fundamentacao_juridica ? `<div style="font-size:.7rem;color:#9ab0c9;margin-top:3px"><em>${escL(x.fundamentacao_juridica)}</em></div>` : ''}</td>
        <td style="font-size:.78rem">${x.prazo_retencao_ate ? fmtDate(x.prazo_retencao_ate) : 'indeterminado'}</td>
        <td style="font-size:.78rem">${escL(x.responsavel_nome || '-')}</td>
        <td style="font-size:.78rem">${x.aprovado_em
            ? `<span style="color:#10b981;font-weight:600">✓ ${escL(x.aprovado_por_nome||'')}</span>`
            : `<button class="btn-mst" style="padding:3px 8px;font-size:.72rem" onclick="approveRetention(${x.id})">Aprovar</button>`}</td>
      </tr>`).join('')}</tbody>
    </table>
  `;
}

function _renderLgpdHistorico() {
  const ev = window._lgpdData.eventos || [];
  if (ev.length === 0) return '<div style="padding:14px;color:#9ab0c9">Sem eventos.</div>';
  return `
    <div style="background:rgba(8,12,24,.4);border:1px solid rgba(160,180,210,.10);border-radius:6px;padding:6px 14px;max-height:380px;overflow-y:auto">
      ${ev.map(e => `<div style="padding:8px 0;border-bottom:1px solid rgba(160,180,210,.10)">
        <div style="font-size:.85rem;color:#fff;font-weight:600">${escL((window.Yuris && Yuris.translateAuditAcao) ? Yuris.translateAuditAcao(e.evento) : (e.evento || ''))}${e.tipo_acao ? ` <span style="font-size:.7rem;color:#7eb8f7">[${escL((window.Yuris && Yuris.translateAuditAcao) ? Yuris.translateAuditAcao(e.tipo_acao) : (e.tipo_acao || ''))}]</span>` : ''}</div> <!-- i18n acao via Yuris.translateAuditAcao -->
        ${(e.status_anterior && e.status_novo) ? `<div style="font-size:.78rem;color:#a855f7;margin-top:2px">${escL(e.status_anterior)} → ${escL(e.status_novo)}</div>` : ''}
        ${e.observacao ? `<div style="font-size:.8rem;color:#cbd5e1;margin-top:2px">${escL(e.observacao)}</div>` : ''}
        <div style="font-size:.72rem;color:#9ab0c9;margin-top:2px">
          ${escL(fmtDateTime(e.created_at))}${e.user_nome ? ' · ' + escL(e.user_nome) : ' · sistema'}
        </div>
      </div>`).join('')}
    </div>
  `;
}

// ─── Ações novas ─────────────────────────────────────────────────────────────

async function runLgpdSearch() {
  if (!confirm('Buscar dados deste titular em TODOS os módulos do sistema?\n\nPode demorar alguns segundos e popula a aba "Módulos" e "Dados encontrados".')) return;
  const id = window._lgpdCurrentReqId;
  const r = await fj(`${API}/lgpd_request_search.php`, {
    method:'POST', body: JSON.stringify({ csrf_token: CSRF, id })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk(`Busca concluída: ${r.data.total_geral} achado(s) em ${r.data.modulos_pesquisados.length} módulo(s)`);
  openLgpdDrawer(id); // recarrega
}

async function prorrogarLgpd() {
  const id = window._lgpdCurrentReqId;
  const dias = parseInt(prompt('Prorrogar prazo por quantos dias? (1-30)', '15'), 10);
  if (!dias || dias < 1 || dias > 30) return;
  const motivo = prompt('Motivo da prorrogação (obrigatório, LGPD Art. 19 §3):', '');
  if (!motivo || motivo.trim() === '') return notifyErr('Motivo obrigatório.');
  const r = await fj(`${API}/lgpd_requests.php?action=prorrogar`, {
    method:'POST', body: JSON.stringify({ csrf_token: CSRF, id, dias, motivo })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Prazo prorrogado');
  openLgpdDrawer(id);
}

async function toggleLgpdFindingExport(findingId, included) {
  const r = await fj(`${API}/lgpd_finding_review.php`, {
    method: 'PATCH', body: JSON.stringify({
      csrf_token: CSRF, finding_id: findingId, incluido_no_export: included
    })
  });
  if (!r.ok) {
    // Reverte checkbox visualmente em caso de erro
    const cb = event && event.target;
    if (cb) cb.checked = !included;
    return notifyErr(r.error || 'Erro ao salvar');
  }
  notifyOk(`Finding ${included ? 'INCLUIDO no' : 'REMOVIDO do'} export`);
}

async function reviewLgpdFinding(findingId) {
  const decide = confirm('Este dado PODE ser excluido/anonimizado em resposta a solicitacao?\n\n[OK] = pode excluir  |  [Cancelar] = deve reter');
  // Se for reter, exige motivo
  let body = { csrf_token: CSRF, finding_id: findingId, pode_excluir: decide };
  if (!decide) {
    const motivo = prompt('Motivo da retencao (resumo curto — para justificativa formal completa use "Reter"):', '');
    if (motivo === null || motivo.trim() === '') return notifyErr('Motivo obrigatorio para reter.');
    body.motivo_retencao = motivo;
  }
  const r = await fj(`${API}/lgpd_finding_review.php`, {
    method: 'PATCH', body: JSON.stringify(body)
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk(decide ? 'Marcado: pode excluir' : 'Marcado: deve reter');
  openLgpdDrawer(window._lgpdCurrentReqId);
}

function openRegisterRetention(findingId, entidade, entidadeId) {
  const base = prompt(
    'Base legal de retenção (LGPD Art. 7/16):\n\n' +
    'obrigacao_legal | exercicio_direitos_processo | cumprimento_contrato | ' +
    'dados_terceiros | sigilo_profissional | seguranca_sistema | ' +
    'auditoria_obrigatoria | anonimizacao_inviavel | outro',
    'exercicio_direitos_processo'
  );
  if (!base) return;
  const just = prompt('Justificativa detalhada (obrigatória):', '');
  if (!just || just.trim() === '') return notifyErr('Justificativa obrigatória.');
  const fund = prompt('Fundamentação jurídica (artigos, jurisprudência — opcional):', '') || null;
  const prazo = prompt('Prazo de retenção (YYYY-MM-DD, vazio = indeterminado):', '') || null;

  fj(`${API}/lgpd_anonymize.php?action=register_retention`, {
    method:'POST', body: JSON.stringify({
      csrf_token: CSRF,
      lgpd_request_id: window._lgpdCurrentReqId,
      entidade, entidade_id: entidadeId,
      base_legal_retencao: base, justificativa: just,
      fundamentacao_juridica: fund, prazo_retencao_ate: prazo,
      finding_id: findingId,
    })
  }).then(r => {
    if (!r.ok) return notifyErr(r.error || 'Erro');
    notifyOk('Retenção registrada');
    openLgpdDrawer(window._lgpdCurrentReqId);
  });
}

async function approveRetention(justId) {
  if (!confirm('Aprovar esta retencao como 2º revisor (4-eyes)?\n\nVoce NAO pode aprovar uma justificativa que VOCE MESMO criou — o sistema bloqueia auto-aprovacao.')) return;
  const r = await fj(`${API}/lgpd_retention_approve.php`, {
    method: 'POST', body: JSON.stringify({
      csrf_token: CSRF, justification_id: justId
    })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Retencao aprovada (4-eyes)');
  openLgpdDrawer(window._lgpdCurrentReqId);
}

async function uploadLgpdAttachment(ev) {
  ev.preventDefault();
  const form = ev.target;
  const fd = new FormData(form);
  fd.append('csrf_token', CSRF);
  fd.append('request_id', window._lgpdCurrentReqId);
  const r = await fetch(`${API}/lgpd_request_attachments.php`, {
    method:'POST', body: fd, headers: { 'X-CSRF-Token': CSRF }, credentials:'same-origin'
  });
  let j; try { j = await r.json(); } catch(_) { j = { ok: false, error: 'Resposta inválida' }; }
  if (!j.ok) return notifyErr(j.error || 'Erro no upload');
  notifyOk('Anexo enviado');
  form.reset();
  openLgpdDrawer(window._lgpdCurrentReqId);
}

async function releaseAttachment(attId) {
  if (!confirm('Marcar este anexo como "entregue ao titular"? Esta acao indica que o arquivo foi compartilhado externamente.')) return;
  const r = await fj(`${API}/lgpd_request_attachments.php`, {
    method:'PATCH', body: JSON.stringify({
      csrf_token: CSRF, id: attId, visibilidade: 'entregue_titular'
    })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Visibilidade atualizada');
  openLgpdDrawer(window._lgpdCurrentReqId);
}

async function updateLgpdStatus(status) {
  const id = parseInt(document.getElementById('lgpdReqId').value, 10);
  // v2: aba "dados" pode nao estar visivel — usa valor do estado se nao tiver no DOM
  const respEl = document.getElementById('lgpdResposta');
  const motEl  = document.getElementById('lgpdMotivoRejeicao');
  const resposta = respEl ? respEl.value : (window._lgpdData?.request?.resposta || '');
  const motivo   = motEl  ? motEl.value  : (window._lgpdData?.request?.motivo_rejeicao || '');
  if (status === 'rejeitado' && !motivo.trim()) {
    showLgpdTab('dados');
    return notifyErr('Informe o motivo da rejeição (aba Dados).');
  }
  const body = { csrf_token: CSRF, id, status, resposta };
  if (status === 'rejeitado') body.motivo_rejeicao = motivo;
  const r = await fj(`${API}/lgpd_requests.php`, {
    method: 'PATCH', body: JSON.stringify(body)
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Solicitação atualizada');
  closeModal('modalLgpd');
  loadLgpdRequests();
  refreshLgpdBadge();
}

async function lgpdAnonimizar() {
  const entidade   = document.getElementById('lgpdAnonEntidade').value;
  const entidadeId = parseInt(document.getElementById('lgpdAnonId').value, 10);
  if (!entidadeId) return notifyErr('Informe o ID da entidade');
  if (!confirm(`Anonimizar ${entidade} #${entidadeId}? Esta operação é IRREVERSÍVEL.`)) return;
  const r = await fj(`${API}/lgpd_anonymize.php`, {
    method: 'POST', body: JSON.stringify({
      csrf_token: CSRF, lgpd_request_id: window._lgpdCurrentReqId,
      entidade, entidade_id: entidadeId,
    })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk(`${entidade} #${entidadeId} anonimizado`);
  openLgpdDrawer(window._lgpdCurrentReqId); // recarrega drawer (atualiza eventos)
}

async function lgpdExport() {
  if (!window._lgpdCurrentEmail) return notifyErr('Email do titular não disponível');
  if (!confirm(`Gerar export de portabilidade para ${window._lgpdCurrentEmail}?`)) return;
  const out = document.getElementById('lgpdExportLink');
  out.textContent = ' Gerando...';
  const r = await fj(`${API}/lgpd_anonymize.php?action=export`, {
    method: 'POST', body: JSON.stringify({
      csrf_token: CSRF, lgpd_request_id: window._lgpdCurrentReqId,
      email: window._lgpdCurrentEmail,
    })
  });
  if (!r.ok) { out.textContent = ''; return notifyErr(r.error || 'Erro'); }
  out.innerHTML = ` <a href="${r.data.download_url}" target="_blank" style="color:#7eb8f7">Baixar ${r.data.file}</a>`;
  notifyOk('Export gerado');
}

async function refreshLgpdBadge() {
  try {
    const r = await fj(`${API}/lgpd_requests.php?counts=1`);
    if (!r.ok) return;
    const badge = document.getElementById('lgpdBadge');
    const pendentes = (r.data && r.data.pendentes) || 0;
    const atrasadas = (r.data && r.data.atrasadas) || 0;
    if (pendentes > 0) {
      badge.style.display = 'inline-block';
      badge.textContent = pendentes;
      badge.style.background = atrasadas > 0 ? '#ef4444' : '#f59e0b';
      badge.title = `${pendentes} pendentes (${atrasadas} atrasadas)`;
    } else {
      badge.style.display = 'none';
    }
  } catch (_) {}
}

// Auto-atualiza badge a cada 60s
refreshLgpdBadge();
setInterval(refreshLgpdBadge, 60000);

document.getElementById('filterLgpdStatus').addEventListener('change', loadLgpdRequests);
document.getElementById('filterLgpdTipo').addEventListener('change', loadLgpdRequests);
document.getElementById('filterLgpdAtrasada').addEventListener('change', loadLgpdRequests);

// ═══════════════════════════════════════════════════════════════════════════
// Retenção (Etapa 7)
// ═══════════════════════════════════════════════════════════════════════════
async function loadRetention() {
  const r = await fj(`${API}/retention.php`);
  const tbody = document.getElementById('retencaoBody');
  if (!r.ok) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty">Erro ao carregar.</td></tr>';
    return;
  }
  const pols = r.data.policies || [];
  if (pols.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty">Sem políticas configuradas.</td></tr>';
  } else {
    tbody.innerHTML = pols.map(p => {
      const statusColor = p.ultimo_status === 'success' ? '#10b981'
                        : p.ultimo_status === 'error'   ? '#ef4444' : '#9ab0c9';
      return `<tr>
        <td><strong>${escL(p.entidade)}</strong></td>
        <td><span style="padding:2px 9px;border-radius:999px;background:rgba(96,165,250,.10);color:#7eb8f7;font-size:.72rem;font-weight:600">${escL(p.acao_apos)}</span></td>
        <td><input type="number" min="1" max="36500" value="${parseInt(p.retencao_dias,10)}" data-id="${p.id}" style="width:80px;padding:4px 8px;border-radius:6px;background:rgba(5,18,39,.6);border:1px solid rgba(160,180,210,.18);color:#fff;font:inherit" onchange="savePolicyDias(${p.id}, this.value)"></td>
        <td style="font-size:.78rem;max-width:280px;color:#9ab0c9">${escL((p.base_legal||'').substring(0,120))}${(p.base_legal||'').length>120?'…':''}</td>
        <td style="font-size:.78rem">${p.ultimo_run ? fmtDateTime(p.ultimo_run) : '—'} <br><small style="color:#9ab0c9">${p.ultimo_purge_count} linhas</small></td>
        <td><span style="color:${statusColor};font-size:.78rem;font-weight:600">${escL(p.ultimo_status||'—')}</span></td>
        <td><input type="checkbox" ${p.ativo == 1 ? 'checked' : ''} onchange="savePolicyAtivo(${p.id}, this.checked)"></td>
      </tr>`;
    }).join('');
  }
  document.getElementById('retencaoLastAnon').textContent = r.data.total_anonimizacoes + ' anonimizações executadas no histórico';
  // Carrega log
  loadAnonLog();
}

async function loadAnonLog() {
  const r = await fj(`${API}/retention.php?logs=1`);
  const tbody = document.getElementById('anonLogBody');
  if (!r.ok || !Array.isArray(r.data) || r.data.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="empty">Sem anonimizações registradas.</td></tr>';
    return;
  }
  tbody.innerHTML = r.data.map(row => `<tr>
    <td style="font-size:.78rem">${fmtDateTime(row.executado_em)}</td>
    <td><strong>${escL(row.entidade)}</strong></td>
    <td>#${parseInt(row.entidade_id,10)}</td>
    <td style="font-size:.82rem">${escL(row.motivo || '—')}</td>
    <td style="font-size:.82rem">${escL(row.executor_nome || (row.executado_por_user_id ? '#'+row.executado_por_user_id : 'cron'))}</td>
    <td>${row.lgpd_request_id ? '#'+parseInt(row.lgpd_request_id,10) : '—'}</td>
  </tr>`).join('');
}

async function savePolicyDias(id, val) {
  const dias = parseInt(val, 10);
  if (!dias || dias < 1) return notifyErr('Prazo inválido');
  const r = await fj(`${API}/retention.php`, {
    method: 'PATCH', body: JSON.stringify({ csrf_token: CSRF, id, retencao_dias: dias })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Política atualizada');
}

async function savePolicyAtivo(id, ativo) {
  const r = await fj(`${API}/retention.php`, {
    method: 'PATCH', body: JSON.stringify({ csrf_token: CSRF, id, ativo: ativo ? 1 : 0 })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk(ativo ? 'Política ativada' : 'Política desativada');
}

async function runRetention(dry) {
  const msg = dry ? 'Executar simulação (sem alterações reais)?' : 'EXECUTAR retenção AGORA? Esta ação aplicará purges/anonimizações nos dados.';
  if (!confirm(msg)) return;
  const r = await fj(`${API}/retention.php?action=run`, {
    method: 'POST', body: JSON.stringify({ csrf_token: CSRF, dry_run: dry })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  alert((dry ? 'Simulação concluída.' : 'Executado!') + '\n\n' + (r.data.log || []).join('\n'));
  loadRetention();
}

// ═══════════════════════════════════════════════════════════════════════════
// Incidentes de Segurança (Etapa 8 — LGPD Art. 48)
// ═══════════════════════════════════════════════════════════════════════════
const INC_TIPO_LABEL = {
  vazamento_dados:'Vazamento', acesso_indevido:'Acesso indevido', ransomware:'Ransomware',
  phishing:'Phishing', dos_ddos:'DoS/DDoS', exposicao_credenciais:'Exposição cred.',
  perda_dispositivo:'Perda disp.', engenharia_social:'Eng. social',
  config_indevida:'Config. indevida', outro:'Outro',
};
const INC_STATUS_COLOR = {
  detectado:'#f59e0b', em_analise:'#3b82f6', contido:'#a855f7',
  mitigado:'#06b6d4', notificado_anpd:'#22d3ee', notificado_titulares:'#22d3ee',
  encerrado:'#10b981', falso_positivo:'#94a3b8',
};
const INC_SEV_COLOR = {
  critica:'#dc2626', alta:'#ef4444', media:'#f59e0b', baixa:'#94a3b8',
};

async function loadIncidents() {
  const status     = document.getElementById('filterIncStatus').value;
  const severidade = document.getElementById('filterIncSeveridade').value;
  const tipo       = document.getElementById('filterIncTipo').value;
  const abertos    = document.getElementById('filterIncAbertos').checked ? '1' : '';
  const qs = new URLSearchParams();
  if (status)     qs.set('status', status);
  if (severidade) qs.set('severidade', severidade);
  if (tipo)       qs.set('tipo', tipo);
  if (abertos)    qs.set('abertos', '1');

  const r = await fj(`${API}/incidents.php?${qs.toString()}`);
  const tbody = document.getElementById('incidentsBody');
  if (!r.ok || !Array.isArray(r.data)) {
    tbody.innerHTML = '<tr><td colspan="9" class="empty">Erro ao carregar.</td></tr>';
    return;
  }
  if (r.data.length === 0) {
    tbody.innerHTML = '<tr><td colspan="9" class="empty">Nenhum incidente registrado com esses filtros.</td></tr>';
    return;
  }
  tbody.innerHTML = r.data.map(row => {
    const sevColor = INC_SEV_COLOR[row.severidade] || '#94a3b8';
    const stColor  = INC_STATUS_COLOR[row.status]  || '#94a3b8';
    const notifAnpd = row.notificacao_anpd_em
      ? `<span style="color:#10b981;font-size:.72rem" title="ANPD: ${fmtDateTime(row.notificacao_anpd_em)}">ANPD ✓</span>` : '';
    const notifTit  = row.notificacao_titulares_em
      ? `<span style="color:#10b981;font-size:.72rem" title="Titulares: ${fmtDateTime(row.notificacao_titulares_em)}">Tit. ✓</span>` : '';
    return `<tr>
      <td>#${row.id}</td>
      <td><div style="font-weight:600">${escL(row.titulo)}</div></td>
      <td style="font-size:.78rem">${escL(INC_TIPO_LABEL[row.tipo] || row.tipo)}</td>
      <td><span style="padding:2px 9px;border-radius:999px;background:${sevColor}1f;border:1px solid ${sevColor}40;color:${sevColor};font-size:.72rem;font-weight:700;text-transform:uppercase">${escL(row.severidade)}</span></td>
      <td style="font-size:.78rem">${row.account_id ? '#'+row.account_id+' '+escL(row.account_nome||'') : '<em style="color:#9ab0c9">Plataforma</em>'}</td>
      <td style="font-size:.78rem">${fmtDateTime(row.detectado_em)}</td>
      <td><span style="padding:3px 9px;border-radius:999px;background:${stColor}1f;border:1px solid ${stColor}40;color:${stColor};font-size:.72rem;font-weight:600">${escL(row.status)}</span></td>
      <td style="display:flex;gap:6px">${notifAnpd}${notifTit}</td>
      <td><button class="btn-mst" onclick="openIncidentDrawer(${row.id})">Abrir</button></td>
    </tr>`;
  }).join('');
}

function openNewIncident() {
  // Pré-preenche detectado_em com agora (formato datetime-local)
  const now = new Date();
  const pad = n => String(n).padStart(2, '0');
  const local = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
  document.querySelector('#formNewIncident [name="detectado_em"]').value = local;
  document.getElementById('formNewIncident').reset();
  document.querySelector('#formNewIncident [name="detectado_em"]').value = local;
  document.querySelector('#formNewIncident [name="severidade"]').value = 'media';
  openModal('modalNewIncident');
}

async function submitNewIncident(ev) {
  ev.preventDefault();
  const f = ev.target;
  const fd = new FormData(f);
  // Coleta categorias selecionadas no checkbox-group
  const categorias = [];
  ['pii_basica','documentos','financeiro','juridico','autenticacao','comunicacoes','dados_sensiveis']
    .forEach(c => { if (fd.get('cat_'+c)) categorias.push(c); });
  const dadosAfetados = {
    categorias,
    titulares_estimados: parseInt(fd.get('titulares_estimados')||'0', 10),
    registros: parseInt(fd.get('registros')||'0', 10),
  };
  const body = {
    csrf_token: CSRF,
    titulo: fd.get('titulo'),
    tipo: fd.get('tipo'),
    severidade: fd.get('severidade'),
    account_id: fd.get('account_id') ? parseInt(fd.get('account_id'),10) : null,
    detectado_em: (fd.get('detectado_em')||'').replace('T',' ') + ':00',
    ocorrido_em: fd.get('ocorrido_em') ? fd.get('ocorrido_em').replace('T',' ')+':00' : null,
    impacto: fd.get('impacto'),
    descricao_interna: fd.get('descricao_interna'),
    descricao_publica: fd.get('descricao_publica'),
    medidas_imediatas: fd.get('medidas_imediatas'),
    dados_afetados: dadosAfetados,
  };
  const r = await fj(`${API}/incidents.php`, {
    method:'POST', body: JSON.stringify(body)
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Incidente #' + r.data.id + ' registrado');
  closeModal('modalNewIncident');
  loadIncidents();
  refreshIncidentBadge();
}

async function openIncidentDrawer(id) {
  const r = await fj(`${API}/incidents.php?id=${id}`);
  if (!r.ok) return notifyErr(r.error || 'Erro');
  const { incident: inc, eventos, public_report } = r.data;

  const eventosHtml = (eventos || []).map(e => {
    const stChange = (e.status_anterior && e.status_novo)
      ? ` <span style="color:#9ab0c9">[${escL(e.status_anterior)} → ${escL(e.status_novo)}]</span>` : '';
    return `<div style="padding:8px 0;border-bottom:1px solid rgba(160,180,210,.10)">
      <div style="font-size:.85rem;color:#fff;font-weight:600">${escL(e.tipo_evento)}${stChange}</div>
      ${e.descricao ? `<div style="font-size:.8rem;color:#cbd5e1;margin-top:2px">${escL(e.descricao)}</div>` : ''}
      <div style="font-size:.72rem;color:#9ab0c9;margin-top:2px">
        ${escL(fmtDateTime(e.created_at))}${e.user_nome ? ' · ' + escL(e.user_nome) : ' · sistema'}
        ${e.request_id ? ' · req:' + escL(e.request_id) : ''}
      </div>
    </div>`;
  }).join('') || '<div style="padding:10px;color:#9ab0c9">Sem eventos registrados.</div>';

  const sevColor = INC_SEV_COLOR[inc.severidade] || '#94a3b8';
  const stColor  = INC_STATUS_COLOR[inc.status]  || '#94a3b8';
  const categoriasArr = (inc.dados_afetados && Array.isArray(inc.dados_afetados.categorias))
    ? inc.dados_afetados.categorias : [];
  const tit = (inc.dados_afetados && inc.dados_afetados.titulares_estimados) || '?';
  const reg = (inc.dados_afetados && inc.dados_afetados.registros) || '?';

  document.getElementById('incidentTitle').textContent =
    `Incidente #${inc.id} — ${escL(inc.titulo)}`;

  document.getElementById('incidentBody').innerHTML = `
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
      <span style="padding:3px 11px;border-radius:999px;background:${sevColor}1f;border:1px solid ${sevColor}40;color:${sevColor};font-size:.78rem;font-weight:700;text-transform:uppercase">SEV: ${escL(inc.severidade)}</span>
      <span style="padding:3px 11px;border-radius:999px;background:${stColor}1f;border:1px solid ${stColor}40;color:${stColor};font-size:.78rem;font-weight:600">${escL(inc.status)}</span>
      <span style="font-size:.78rem;color:#9ab0c9">${escL(INC_TIPO_LABEL[inc.tipo] || inc.tipo)}</span>
      <span style="font-size:.78rem;color:#9ab0c9">·</span>
      <span style="font-size:.78rem;color:#9ab0c9">${inc.account_id ? 'Conta #'+inc.account_id : 'Plataforma'}</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;font-size:.82rem;color:#9ab0c9;margin-bottom:16px">
      <div>Detectado: <strong style="color:#fff">${fmtDateTime(inc.detectado_em)}</strong></div>
      <div>Ocorrido em: <strong style="color:#fff">${inc.ocorrido_em ? fmtDateTime(inc.ocorrido_em) : '—'}</strong></div>
      <div>Contido em: <strong style="color:#fff">${inc.contido_em ? fmtDateTime(inc.contido_em) : '—'}</strong></div>
      <div>Mitigado em: <strong style="color:#fff">${inc.mitigado_em ? fmtDateTime(inc.mitigado_em) : '—'}</strong></div>
      <div>Encerrado em: <strong style="color:#fff">${inc.encerrado_em ? fmtDateTime(inc.encerrado_em) : '—'}</strong></div>
      <div>Reportado por: <strong style="color:#fff">${inc.reportado_por_user_id ? '#'+inc.reportado_por_user_id : '—'}</strong></div>
    </div>

    <h4 style="color:#fff;margin:14px 0 4px">Impacto avaliado</h4>
    <div style="background:rgba(96,165,250,.05);padding:10px;border-radius:6px;color:#cbd5e1;font-size:.85rem">
      <div><strong>Categorias:</strong> ${categoriasArr.length ? escL(categoriasArr.join(', ')) : '—'}</div>
      <div><strong>Titulares estimados:</strong> ${escL(String(tit))} · <strong>Registros:</strong> ${escL(String(reg))}</div>
      ${inc.impacto ? `<div style="margin-top:6px"><strong>Avaliação:</strong> ${escL(inc.impacto)}</div>` : ''}
    </div>

    ${inc.descricao_publica ? `<h4 style="color:#fff;margin:14px 0 4px">Descrição pública (titular/ANPD)</h4>
      <div style="background:rgba(96,165,250,.05);padding:10px;border-radius:6px;white-space:pre-wrap;color:#cbd5e1">${escL(inc.descricao_publica)}</div>` : ''}

    ${inc.descricao_interna ? `<h4 style="color:#fff;margin:14px 0 4px">Descrição interna (DPO)</h4>
      <div style="background:rgba(239,68,68,.06);padding:10px;border-radius:6px;white-space:pre-wrap;color:#fca5a5;font-size:.85rem">${escL(inc.descricao_interna)}</div>` : ''}

    ${inc.medidas_imediatas ? `<h4 style="color:#fff;margin:14px 0 4px">Medidas imediatas</h4>
      <div style="background:rgba(96,165,250,.05);padding:10px;border-radius:6px;white-space:pre-wrap;color:#cbd5e1">${escL(inc.medidas_imediatas)}</div>` : ''}

    ${inc.causa_raiz ? `<h4 style="color:#fff;margin:14px 0 4px">Causa raiz</h4>
      <div style="background:rgba(96,165,250,.05);padding:10px;border-radius:6px;white-space:pre-wrap;color:#cbd5e1">${escL(inc.causa_raiz)}</div>` : ''}

    ${inc.medidas_corretivas ? `<h4 style="color:#fff;margin:14px 0 4px">Medidas corretivas / lições aprendidas</h4>
      <div style="background:rgba(96,165,250,.05);padding:10px;border-radius:6px;white-space:pre-wrap;color:#cbd5e1">${escL(inc.medidas_corretivas)}</div>` : ''}

    <h4 style="color:#fff;margin:18px 0 8px">Timeline (eventos imutáveis)</h4>
    <div style="background:rgba(8,12,24,.4);border:1px solid rgba(160,180,210,.10);border-radius:6px;padding:6px 14px;max-height:230px;overflow-y:auto">${eventosHtml}</div>

    <h4 style="color:#fff;margin:18px 0 6px">Notificações (Art. 48)</h4>
    <div style="background:rgba(96,165,250,.05);padding:10px;border-radius:6px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <div style="flex:1;min-width:280px">
        <div style="font-size:.78rem;color:#9ab0c9">ANPD: ${inc.notificacao_anpd_em
          ? '<strong style="color:#10b981">notificada em '+fmtDateTime(inc.notificacao_anpd_em)+'</strong>'
            + (inc.notificacao_anpd_protocolo ? ' · protocolo '+escL(inc.notificacao_anpd_protocolo) : '')
          : '<strong style="color:#f59e0b">não notificada</strong>'}</div>
        <div style="font-size:.78rem;color:#9ab0c9;margin-top:3px">Titulares: ${inc.notificacao_titulares_em
          ? '<strong style="color:#10b981">notificados em '+fmtDateTime(inc.notificacao_titulares_em)+'</strong>'
            + (inc.notificacao_titulares_canal ? ' via '+escL(inc.notificacao_titulares_canal) : '')
          : '<strong style="color:#f59e0b">não notificados</strong>'}</div>
      </div>
      ${!inc.notificacao_anpd_em ? `<button class="btn-mst" onclick="notifyAnpd(${inc.id})">Marcar Notificação ANPD</button>` : ''}
      ${!inc.notificacao_titulares_em ? `<button class="btn-mst" onclick="notifyHolders(${inc.id})">Marcar Notificação Titulares</button>` : ''}
    </div>

    <h4 style="color:#fff;margin:18px 0 6px">Atualizar status</h4>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
      <button class="btn-mst" onclick="updateIncidentStatus(${inc.id},'em_analise')">Em análise</button>
      <button class="btn-mst" onclick="updateIncidentStatus(${inc.id},'contido')">Contido</button>
      <button class="btn-mst" onclick="updateIncidentStatus(${inc.id},'mitigado')">Mitigado</button>
      <button class="btn-mst" style="background:linear-gradient(135deg,#94a3b8,#64748b);color:#fff;border:none" onclick="updateIncidentStatus(${inc.id},'falso_positivo')">Falso positivo</button>
      <button class="btn-mst btn-mst-primary" onclick="updateIncidentStatus(${inc.id},'encerrado')">Encerrar</button>
    </div>

    <h4 style="color:#fff;margin:18px 0 6px">Adicionar comentário ao timeline</h4>
    <div style="display:flex;gap:6px">
      <input id="incCommentTxt" type="text" placeholder="Comentário..." style="flex:1;padding:6px 10px;border-radius:6px;background:rgba(5,18,39,.6);border:1px solid rgba(160,180,210,.18);color:#fff;font:inherit">
      <button class="btn-mst" onclick="addIncidentEvent(${inc.id})">Adicionar</button>
    </div>

    <details style="margin-top:18px">
      <summary style="cursor:pointer;color:#7eb8f7;font-size:.85rem">Ver relatório público gerado (JSON)</summary>
      <pre style="background:rgba(8,12,24,.5);padding:10px;border-radius:6px;color:#cbd5e1;font-size:.78rem;overflow-x:auto;margin-top:8px">${escL(JSON.stringify(public_report, null, 2))}</pre>
    </details>
  `;
  openModal('modalIncident');
}

async function updateIncidentStatus(id, status) {
  if (!confirm(`Alterar status do incidente #${id} para "${status}"?`)) return;
  const r = await fj(`${API}/incidents.php`, {
    method:'PATCH', body: JSON.stringify({ csrf_token: CSRF, id, status })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Status atualizado');
  openIncidentDrawer(id);
  loadIncidents();
  refreshIncidentBadge();
}

async function notifyAnpd(id) {
  const protocolo = prompt('Protocolo da notificação à ANPD (opcional):', '');
  if (protocolo === null) return;
  const r = await fj(`${API}/incidents.php?action=notify_anpd`, {
    method:'POST', body: JSON.stringify({ csrf_token: CSRF, id, protocolo })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Notificação ANPD registrada');
  openIncidentDrawer(id);
  refreshIncidentBadge();
}

async function notifyHolders(id) {
  const canal = prompt('Canal usado para notificar titulares (email | telefone | aviso_publico | in_app):', 'email');
  if (canal === null) return;
  const r = await fj(`${API}/incidents.php?action=notify_holders`, {
    method:'POST', body: JSON.stringify({ csrf_token: CSRF, id, canal })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Notificação aos titulares registrada');
  openIncidentDrawer(id);
  refreshIncidentBadge();
}

async function addIncidentEvent(id) {
  const txt = document.getElementById('incCommentTxt').value.trim();
  if (!txt) return notifyErr('Digite um comentário');
  const r = await fj(`${API}/incidents.php?action=add_event`, {
    method:'POST', body: JSON.stringify({
      csrf_token: CSRF, id, tipo_evento: 'comentario', descricao: txt
    })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Comentário adicionado');
  openIncidentDrawer(id);
}

async function refreshIncidentBadge() {
  try {
    const r = await fj(`${API}/incidents.php?counts=1`);
    if (!r.ok) return;
    const badge = document.getElementById('incidentBadge');
    const abertos  = (r.data && r.data.abertos) || 0;
    const criticos = (r.data && r.data.criticos_abertos) || 0;
    const notifPend = (r.data && r.data.notificacao_pendente) || 0;
    if (abertos > 0) {
      badge.style.display = 'inline-block';
      badge.textContent = abertos;
      badge.style.background = (criticos > 0 || notifPend > 0) ? '#dc2626' : '#f59e0b';
      badge.title = `${abertos} abertos · ${criticos} críticos · ${notifPend} pend. notificação`;
    } else {
      badge.style.display = 'none';
    }
  } catch (_) {}
}

// Auto-atualiza badge a cada 60s
refreshIncidentBadge();
setInterval(refreshIncidentBadge, 60000);

document.getElementById('filterIncStatus').addEventListener('change', loadIncidents);
document.getElementById('filterIncSeveridade').addEventListener('change', loadIncidents);
document.getElementById('filterIncTipo').addEventListener('change', loadIncidents);
document.getElementById('filterIncAbertos').addEventListener('change', loadIncidents);

// ═══════════════════════════════════════════════════════════════════════════
// Operadores / DPA (Etapa 9 — LGPD Art. 33 + 39)
// ═══════════════════════════════════════════════════════════════════════════
const OP_CATEGORIA_LABEL = {
  api_externa:'API externa', hospedagem:'Hospedagem', cdn:'CDN',
  gateway_pagamento:'Gateway', smtp:'SMTP', llm_ia:'LLM/IA',
  monitoramento:'Monitoramento', suporte:'Suporte', analytics:'Analytics',
  backup:'Backup', outro:'Outro',
};
const OP_DPA_COLOR = {
  assinado:'#10b981', dispensado:'#22d3ee',
  em_negociacao:'#3b82f6', pendente:'#f59e0b',
  vencido:'#ef4444', rejeitado:'#dc2626',
};
const OP_BL_LABEL = {
  clausulas_contratuais_padrao:'Cláusulas contratuais padrão',
  regras_corporativas_globais:'Regras corporativas globais',
  decisao_anpd_adequacao:'Decisão ANPD (adequação)',
  autorizacao_anpd_especifica:'Autorização ANPD',
  cooperacao_juridica_internacional:'Cooperação jurídica',
  protecao_vida:'Proteção da vida',
  cumprimento_obrigacao_legal:'Obrigação legal',
  execucao_contrato_titular:'Execução de contrato',
  consentimento_especifico:'Consentimento específico',
  garantias_outras:'Outras garantias',
  nao_aplicavel:'N/A',
};

async function loadOperators() {
  const categoria = document.getElementById('filterOpCategoria').value;
  const dpa       = document.getElementById('filterOpDpa').value;
  const intl      = document.getElementById('filterOpIntl').checked ? '1' : '';
  const ativos    = document.getElementById('filterOpAtivos').checked ? '1' : '';
  const qs = new URLSearchParams();
  if (categoria) qs.set('categoria', categoria);
  if (dpa)       qs.set('dpa_status', dpa);
  if (intl)      qs.set('transferencia_internacional', '1');
  if (ativos)    qs.set('ativo', '1');

  const r = await fj(`${API}/processors.php?${qs.toString()}`);
  const tbody = document.getElementById('operatorsBody');
  if (!r.ok || !Array.isArray(r.data)) {
    tbody.innerHTML = '<tr><td colspan="9" class="empty">Erro ao carregar.</td></tr>';
    return;
  }
  if (r.data.length === 0) {
    tbody.innerHTML = '<tr><td colspan="9" class="empty">Nenhum operador encontrado.</td></tr>';
    return;
  }
  const hoje = new Date(); hoje.setHours(0,0,0,0);
  tbody.innerHTML = r.data.map(row => {
    const dpaCol = OP_DPA_COLOR[row.dpa_status] || '#94a3b8';
    let validadeStr = '—';
    let validadeStyle = '';
    if (row.dpa_validade) {
      const dv = new Date(row.dpa_validade);
      const diffDays = Math.floor((dv - hoje) / 86400000);
      validadeStr = fmtDate(row.dpa_validade);
      if (diffDays < 0)        { validadeStyle = 'color:#ef4444;font-weight:700'; validadeStr += ' (vencido)'; }
      else if (diffDays <= 30) { validadeStyle = 'color:#f59e0b;font-weight:600'; validadeStr += ` (${diffDays}d)`; }
    }
    const inativo = row.ativo == 0 ? ' <span style="font-size:.7rem;color:#94a3b8">[inativo]</span>' : '';
    return `<tr>
      <td>#${row.id}</td>
      <td><div style="font-weight:600">${escL(row.nome)}${inativo}</div>
          <div style="font-size:.72rem;color:#9ab0c9">${escL(row.papel)}</div></td>
      <td style="font-size:.78rem">${escL(OP_CATEGORIA_LABEL[row.categoria] || row.categoria)}</td>
      <td>${row.pais ? `<span style="font-size:.78rem;background:rgba(96,165,250,.10);padding:2px 7px;border-radius:6px">${escL(row.pais)}</span>` : '—'}</td>
      <td>${row.transferencia_internacional == 1
            ? '<span style="color:#f59e0b;font-weight:700" title="Transferência internacional">SIM</span>'
            : '<span style="color:#9ab0c9">—</span>'}</td>
      <td><span style="padding:3px 9px;border-radius:999px;background:${dpaCol}1f;border:1px solid ${dpaCol}40;color:${dpaCol};font-size:.72rem;font-weight:600">${escL(row.dpa_status)}</span></td>
      <td style="font-size:.78rem">${row.dpa_assinado_em ? fmtDate(row.dpa_assinado_em) : '—'}</td>
      <td style="font-size:.78rem;${validadeStyle}">${validadeStr}</td>
      <td><button class="btn-mst" onclick="openOperatorDrawer(${row.id})">Abrir</button></td>
    </tr>`;
  }).join('');
}

function openNewOperator() {
  document.getElementById('formNewOperator').reset();
  document.getElementById('opBaseLegalWrap').style.display = 'none';
  openModal('modalNewOperator');
}

function toggleBaseLegal() {
  const v = document.getElementById('opIntlSel').value;
  document.getElementById('opBaseLegalWrap').style.display = (v === '1') ? 'block' : 'none';
}

async function submitNewOperator(ev) {
  ev.preventDefault();
  const f = ev.target;
  const fd = new FormData(f);
  const cats = [];
  ['pii_basica','documentos','financeiro','juridico','autenticacao','comunicacoes','sensiveis']
    .forEach(c => { if (fd.get('cat_'+c)) cats.push(c); });

  const body = {
    csrf_token: CSRF,
    nome: fd.get('nome'),
    categoria: fd.get('categoria'),
    papel: fd.get('papel') || 'operador',
    cnpj_ou_id: fd.get('cnpj_ou_id') || null,
    pais: (fd.get('pais')||'').toUpperCase() || null,
    contato_dpo_terceiro: fd.get('contato_dpo_terceiro') || null,
    dados_tratados: { categorias: cats },
    finalidade: fd.get('finalidade'),
    retencao_terceiro: fd.get('retencao_terceiro') || null,
    transferencia_internacional: fd.get('transferencia_internacional') === '1',
    base_legal_transferencia: fd.get('transferencia_internacional') === '1'
      ? (fd.get('base_legal_transferencia') || null)
      : 'nao_aplicavel',
    dpa_status: fd.get('dpa_status') || 'pendente',
    dpa_assinado_em: fd.get('dpa_assinado_em') || null,
    dpa_validade: fd.get('dpa_validade') || null,
    dpa_url: fd.get('dpa_url') || null,
    url_politica_privacidade: fd.get('url_politica_privacidade') || null,
    certificacoes: fd.get('certificacoes') || null,
    notas: fd.get('notas') || null,
  };
  const r = await fj(`${API}/processors.php`, {
    method:'POST', body: JSON.stringify(body)
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Operador #' + r.data.id + ' adicionado');
  closeModal('modalNewOperator');
  loadOperators();
  refreshOperatorsBadge();
}

// ── Editar operador existente ──────────────────────────────────────────────
function toggleBaseLegalEdit() {
  const v = document.getElementById('editOpIntlSel').value;
  document.getElementById('editOpBaseLegalWrap').style.display = (v === '1') ? 'block' : 'none';
}

async function openEditOperator(id) {
  const r = await fj(`${API}/processors.php?id=${id}`);
  if (!r.ok) return notifyErr(r.error || 'Erro ao carregar operador');
  const p = r.data.processor;

  // Pre-preenche o form com dados atuais
  document.getElementById('editOpId').value          = p.id;
  document.getElementById('editOpTitle').textContent = `Editar Operador #${p.id} — ${p.nome}`;
  document.getElementById('editOpNome').value        = p.nome || '';
  document.getElementById('editOpCategoria').value   = p.categoria || 'outro';
  document.getElementById('editOpPapel').value       = p.papel || 'operador';
  document.getElementById('editOpCnpj').value        = p.cnpj_ou_id || '';
  document.getElementById('editOpPais').value        = p.pais || '';
  document.getElementById('editOpDpoMail').value     = p.contato_dpo_terceiro || '';
  document.getElementById('editOpFinalidade').value  = p.finalidade || '';
  document.getElementById('editOpRetencao').value    = p.retencao_terceiro || '';
  document.getElementById('editOpIntlSel').value     = (p.transferencia_internacional == 1) ? '1' : '0';
  document.getElementById('editOpBaseLegal').value   = p.base_legal_transferencia || '';
  document.getElementById('editOpDpaStatus').value   = p.dpa_status || 'pendente';
  document.getElementById('editOpDpaAss').value      = p.dpa_assinado_em || '';
  document.getElementById('editOpDpaVal').value      = p.dpa_validade || '';
  document.getElementById('editOpDpaUrl').value      = p.dpa_url || '';
  document.getElementById('editOpUrlPriv').value     = p.url_politica_privacidade || '';
  document.getElementById('editOpCert').value        = p.certificacoes || '';
  document.getElementById('editOpNotas').value       = p.notas || '';

  // Categorias de dados (JSON ja decodificado pelo Model)
  const cats = (p.dados_tratados && Array.isArray(p.dados_tratados.categorias))
             ? p.dados_tratados.categorias : [];
  const catMap = {
    pii_basica:'editOpCatPii', documentos:'editOpCatDoc', financeiro:'editOpCatFin',
    juridico:'editOpCatJur', autenticacao:'editOpCatAuth',
    comunicacoes:'editOpCatCom', sensiveis:'editOpCatSens',
  };
  Object.keys(catMap).forEach(k => {
    const el = document.getElementById(catMap[k]);
    if (el) el.checked = cats.includes(k);
  });

  toggleBaseLegalEdit();
  openModal('modalEditOperator');
}

async function submitEditOperator(ev) {
  ev.preventDefault();
  const f = ev.target;
  const fd = new FormData(f);
  const id = parseInt(fd.get('id'), 10);
  if (!id) return notifyErr('id ausente');

  // Coleta categorias marcadas (mesma logica do submitNewOperator)
  const cats = [];
  ['pii_basica','documentos','financeiro','juridico','autenticacao','comunicacoes','sensiveis']
    .forEach(c => { if (fd.get('cat_'+c)) cats.push(c); });

  const intl = fd.get('transferencia_internacional') === '1';
  const body = {
    csrf_token: CSRF,
    id,
    nome: fd.get('nome'),
    categoria: fd.get('categoria'),
    papel: fd.get('papel') || 'operador',
    cnpj_ou_id: fd.get('cnpj_ou_id') || null,
    pais: (fd.get('pais')||'').toUpperCase() || null,
    contato_dpo_terceiro: fd.get('contato_dpo_terceiro') || null,
    dados_tratados: { categorias: cats },
    finalidade: fd.get('finalidade'),
    retencao_terceiro: fd.get('retencao_terceiro') || null,
    transferencia_internacional: intl,
    base_legal_transferencia: intl
      ? (fd.get('base_legal_transferencia') || null)
      : 'nao_aplicavel',
    dpa_status: fd.get('dpa_status'),
    dpa_assinado_em: fd.get('dpa_assinado_em') || null,
    dpa_validade: fd.get('dpa_validade') || null,
    dpa_url: fd.get('dpa_url') || null,
    url_politica_privacidade: fd.get('url_politica_privacidade') || null,
    certificacoes: fd.get('certificacoes') || null,
    notas: fd.get('notas') || null,
  };

  const r = await fj(`${API}/processors.php`, {
    method:'PATCH', body: JSON.stringify(body)
  });
  if (!r.ok) return notifyErr(r.error || 'Erro ao salvar');
  notifyOk('Operador atualizado');
  closeModal('modalEditOperator');
  openOperatorDrawer(id);   // refresh drawer
  loadOperators();          // refresh tabela
  refreshOperatorsBadge();
}

async function openOperatorDrawer(id) {
  const r = await fj(`${API}/processors.php?id=${id}`);
  if (!r.ok) return notifyErr(r.error || 'Erro');
  const { processor: p, history } = r.data;

  const histHtml = (history || []).map(h => `
    <div style="padding:8px 0;border-bottom:1px solid rgba(160,180,210,.10)">
      <div style="font-size:.85rem;color:#fff;font-weight:600">${escL((window.Yuris && Yuris.translateAuditAcao) ? Yuris.translateAuditAcao(h.acao) : (h.acao || ''))}</div> <!-- i18n acao via Yuris.translateAuditAcao -->
      ${h.descricao ? `<div style="font-size:.8rem;color:#cbd5e1;margin-top:2px">${escL(h.descricao)}</div>` : ''}
      <div style="font-size:.72rem;color:#9ab0c9;margin-top:2px">
        ${escL(fmtDateTime(h.created_at))}${h.user_nome ? ' · ' + escL(h.user_nome) : ' · sistema'}
        ${h.request_id ? ' · req:' + escL(h.request_id) : ''}
      </div>
    </div>`).join('') || '<div style="padding:10px;color:#9ab0c9">Sem histórico.</div>';

  const dpaCol = OP_DPA_COLOR[p.dpa_status] || '#94a3b8';
  const cats = (p.dados_tratados && p.dados_tratados.categorias) || [];

  document.getElementById('operatorTitle').textContent =
    `Operador #${p.id} — ${escL(p.nome)}`;

  document.getElementById('operatorBody').innerHTML = `
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
      <span style="padding:3px 11px;border-radius:999px;background:${dpaCol}1f;border:1px solid ${dpaCol}40;color:${dpaCol};font-size:.78rem;font-weight:600">DPA: ${escL(p.dpa_status)}</span>
      <span style="font-size:.78rem;color:#9ab0c9">${escL(OP_CATEGORIA_LABEL[p.categoria] || p.categoria)} · ${escL(p.papel)}</span>
      ${p.transferencia_internacional == 1
        ? `<span style="padding:3px 9px;border-radius:999px;background:#f59e0b1f;border:1px solid #f59e0b40;color:#f59e0b;font-size:.72rem;font-weight:700">TRANSF. INTL → ${escL(p.pais||'?')}</span>` : ''}
      ${p.ativo == 0 ? '<span style="padding:3px 9px;border-radius:999px;background:rgba(148,163,184,.15);color:#94a3b8;font-size:.72rem;font-weight:600">INATIVO</span>' : ''}
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;font-size:.82rem;color:#9ab0c9;margin-bottom:16px">
      <div>CNPJ/ID: <strong style="color:#fff">${escL(p.cnpj_ou_id || '—')}</strong></div>
      <div>País: <strong style="color:#fff">${escL(p.pais || '—')}</strong></div>
      <div>Contato DPO: <strong style="color:#fff">${escL(p.contato_dpo_terceiro || '—')}</strong></div>
      <div>DPA assinado em: <strong style="color:#fff">${p.dpa_assinado_em ? fmtDate(p.dpa_assinado_em) : '—'}</strong></div>
      <div>DPA válido até: <strong style="color:#fff">${p.dpa_validade ? fmtDate(p.dpa_validade) : '—'}</strong></div>
      <div>Base legal transf.: <strong style="color:#fff">${escL(OP_BL_LABEL[p.base_legal_transferencia] || p.base_legal_transferencia || '—')}</strong></div>
    </div>

    <h4 style="color:#fff;margin:14px 0 4px">Tratamento de dados</h4>
    <div style="background:rgba(96,165,250,.05);padding:10px;border-radius:6px;color:#cbd5e1;font-size:.85rem">
      <div><strong>Categorias:</strong> ${cats.length ? escL(cats.join(', ')) : '—'}</div>
      ${p.finalidade ? `<div style="margin-top:6px"><strong>Finalidade:</strong> ${escL(p.finalidade)}</div>` : ''}
      ${p.retencao_terceiro ? `<div style="margin-top:6px"><strong>Retenção do terceiro:</strong> ${escL(p.retencao_terceiro)}</div>` : ''}
    </div>

    ${p.certificacoes ? `<h4 style="color:#fff;margin:14px 0 4px">Certificações</h4>
      <div style="background:rgba(96,165,250,.05);padding:10px;border-radius:6px;color:#cbd5e1">${escL(p.certificacoes)}</div>` : ''}

    ${p.url_politica_privacidade ? `<h4 style="color:#fff;margin:14px 0 4px">Política de privacidade do terceiro</h4>
      <div><a href="${escL(p.url_politica_privacidade)}" target="_blank" rel="noopener" style="color:#7eb8f7">${escL(p.url_politica_privacidade)}</a></div>` : ''}

    ${p.dpa_url ? `<h4 style="color:#fff;margin:14px 0 4px">PDF do DPA</h4>
      <div><a href="${escL(p.dpa_url)}" target="_blank" rel="noopener" style="color:#7eb8f7">${escL(p.dpa_url)}</a></div>` : ''}

    ${p.notas ? `<h4 style="color:#fff;margin:14px 0 4px">Notas</h4>
      <div style="background:rgba(96,165,250,.05);padding:10px;border-radius:6px;white-space:pre-wrap;color:#cbd5e1">${escL(p.notas)}</div>` : ''}

    <h4 style="color:#fff;margin:18px 0 8px">Histórico (imutável)</h4>
    <div style="background:rgba(8,12,24,.4);border:1px solid rgba(160,180,210,.10);border-radius:6px;padding:6px 14px;max-height:230px;overflow-y:auto">${histHtml}</div>

    <h4 style="color:#fff;margin:18px 0 6px">Ações</h4>
    <div style="background:rgba(96,165,250,.05);padding:10px;border-radius:6px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <button class="btn-mst" style="background:rgba(96,165,250,.18);color:#7eb8f7;border-color:rgba(96,165,250,.40)" onclick="openEditOperator(${p.id})">Editar dados</button>
      ${p.dpa_status !== 'assinado' ? `<button class="btn-mst btn-mst-primary" onclick="signDpa(${p.id})">Marcar DPA Assinado</button>` : ''}
      ${p.dpa_status === 'pendente' ? `<button class="btn-mst" onclick="updateOperatorStatus(${p.id},'em_negociacao')">Em negociação</button>` : ''}
      <button class="btn-mst" onclick="updateOperatorStatus(${p.id},'dispensado','DPA dispensado por dispensa legal (ex.: open-source self-hosted, dispensa contratual)')">Dispensar</button>
      ${p.ativo == 1
        ? `<button class="btn-mst" style="background:linear-gradient(135deg,#94a3b8,#64748b);color:#fff;border:none" onclick="deactivateOperator(${p.id})">Desativar</button>`
        : `<button class="btn-mst" onclick="reactivateOperator(${p.id})">Reativar</button>`}
    </div>

    <h4 style="color:#fff;margin:18px 0 6px">Adicionar comentário ao histórico</h4>
    <div style="display:flex;gap:6px">
      <input id="opCommentTxt" type="text" placeholder="Comentário..." style="flex:1;padding:6px 10px;border-radius:6px;background:rgba(5,18,39,.6);border:1px solid rgba(160,180,210,.18);color:#fff;font:inherit">
      <button class="btn-mst" onclick="addOperatorEvent(${p.id})">Adicionar</button>
    </div>
  `;
  openModal('modalOperator');
}

async function signDpa(id) {
  const assinado = prompt('Data de assinatura do DPA (YYYY-MM-DD):',
    new Date().toISOString().slice(0,10));
  if (!assinado) return;
  const validade = prompt('Validade do DPA (YYYY-MM-DD, vazio = sem prazo):', '') || null;
  const url = prompt('URL/Path do PDF (opcional):', '') || null;
  const r = await fj(`${API}/processors.php?action=sign_dpa`, {
    method:'POST', body: JSON.stringify({ csrf_token: CSRF, id, assinado_em: assinado, validade, url })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('DPA marcado como assinado');
  openOperatorDrawer(id);
  loadOperators();
  refreshOperatorsBadge();
}

async function updateOperatorStatus(id, dpa_status, motivo) {
  if (!confirm(`Alterar status DPA do operador #${id} para "${dpa_status}"?`)) return;
  const body = { csrf_token: CSRF, id, dpa_status };
  if (motivo) body.notas = motivo;
  const r = await fj(`${API}/processors.php`, {
    method:'PATCH', body: JSON.stringify(body)
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Status atualizado');
  openOperatorDrawer(id);
  loadOperators();
  refreshOperatorsBadge();
}

async function deactivateOperator(id) {
  const motivo = prompt('Motivo da desativação:', '');
  if (motivo === null) return;
  const r = await fj(`${API}/processors.php?action=deactivate`, {
    method:'POST', body: JSON.stringify({ csrf_token: CSRF, id, motivo })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Operador desativado');
  openOperatorDrawer(id);
  loadOperators();
}

async function reactivateOperator(id) {
  if (!confirm('Reativar operador #' + id + '?')) return;
  const r = await fj(`${API}/processors.php`, {
    method:'PATCH', body: JSON.stringify({ csrf_token: CSRF, id, ativo: 1 })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Operador reativado');
  openOperatorDrawer(id);
  loadOperators();
}

async function addOperatorEvent(id) {
  const txt = document.getElementById('opCommentTxt').value.trim();
  if (!txt) return notifyErr('Digite um comentário');
  const r = await fj(`${API}/processors.php?action=add_event`, {
    method:'POST', body: JSON.stringify({ csrf_token: CSRF, id, descricao: txt })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Comentário adicionado');
  openOperatorDrawer(id);
}

async function exportInventory() {
  const r = await fj(`${API}/processors.php?action=export_inventory`);
  if (!r.ok) return notifyErr(r.error || 'Erro');
  // Baixa como JSON
  const blob = new Blob([JSON.stringify(r.data, null, 2)], {type:'application/json'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `inventario-operadores-${new Date().toISOString().slice(0,10)}.json`;
  document.body.appendChild(a); a.click(); a.remove();
  URL.revokeObjectURL(url);
  notifyOk('Inventário exportado');
}

async function refreshOperatorsBadge() {
  try {
    const r = await fj(`${API}/processors.php?counts=1`);
    if (!r.ok) return;
    const badge = document.getElementById('operatorsBadge');
    const c = r.data || {};
    const pendentes = (c.dpa_pendente || 0) + (c.dpa_vencido || 0) + (c.vencendo_30d || 0);
    if (pendentes > 0) {
      badge.style.display = 'inline-block';
      badge.textContent = pendentes;
      badge.style.background = (c.dpa_vencido > 0) ? '#ef4444' : '#f59e0b';
      badge.title = `${c.dpa_pendente} DPA pendente · ${c.dpa_vencido} vencido · ${c.vencendo_30d} vencendo em 30d`;
    } else {
      badge.style.display = 'none';
    }
  } catch (_) {}
}

refreshOperatorsBadge();
setInterval(refreshOperatorsBadge, 60000);

document.getElementById('filterOpCategoria').addEventListener('change', loadOperators);
document.getElementById('filterOpDpa').addEventListener('change', loadOperators);
document.getElementById('filterOpIntl').addEventListener('change', loadOperators);
document.getElementById('filterOpAtivos').addEventListener('change', loadOperators);

// ═══════════════════════════════════════════════════════════════════════════
// Revisões Internas — pendências antes do go-live (uso DPO/Diretoria)
// Migration 056 + tabela pending_reviews
// ═══════════════════════════════════════════════════════════════════════════
const REV_STATUS_COLOR = {
  pendente:'#f59e0b', em_revisao:'#3b82f6', concluido:'#10b981',
  dispensado:'#94a3b8', bloqueado:'#dc2626',
};
const REV_PRIO_COLOR = {
  critica:'#dc2626', alta:'#ef4444', media:'#f59e0b', baixa:'#94a3b8',
};
const REV_CAT_LABEL = {
  documento_legal:'Documento legal', operador_dpa:'Operador/DPA',
  configuracao_env:'Config. .env', seguranca_tecnica:'Segurança técnica',
  treinamento:'Treinamento', auditoria_externa:'Auditoria externa',
  designacao_dpo:'Designação DPO', outro:'Outro',
};

async function loadReviews() {
  const status     = document.getElementById('filterRevStatus').value;
  const categoria  = document.getElementById('filterRevCategoria').value;
  const prioridade = document.getElementById('filterRevPrioridade').value;
  const bloqueia   = document.getElementById('filterRevBloqueia').checked ? '1' : '';
  const qs = new URLSearchParams();
  if (status)     qs.set('status', status);
  if (categoria)  qs.set('categoria', categoria);
  if (prioridade) qs.set('prioridade', prioridade);
  if (bloqueia)   qs.set('bloqueia', '1');

  const r = await fj(`${API}/reviews.php?${qs.toString()}`);
  const tbody = document.getElementById('reviewsBody');
  if (!r.ok || !Array.isArray(r.data)) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty">Erro ao carregar.</td></tr>';
    return;
  }
  if (r.data.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty">Nenhum item encontrado.</td></tr>';
    return;
  }
  tbody.innerHTML = r.data.map(row => {
    const stCol  = REV_STATUS_COLOR[row.status] || '#94a3b8';
    const prCol  = REV_PRIO_COLOR[row.prioridade] || '#94a3b8';
    const bloqIco = row.bloqueia_producao == 1
      ? '<span style="color:#dc2626;font-weight:700" title="Bloqueia go-live">BLOQ</span>'
      : '<span style="color:#9ab0c9">—</span>';
    return `<tr>
      <td>#${row.id}</td>
      <td>
        <div style="font-weight:600">${escL(row.titulo)}</div>
        ${row.link_referencia ? `<a href="${escL(row.link_referencia)}" target="_blank" rel="noopener" style="font-size:.7rem;color:#7eb8f7">${escL(row.link_referencia)}</a>` : ''}
      </td>
      <td style="font-size:.78rem">${escL(REV_CAT_LABEL[row.categoria] || row.categoria)}</td>
      <td style="font-size:.78rem">${escL(row.responsavel || '—')}</td>
      <td><span style="padding:2px 9px;border-radius:999px;background:${prCol}1f;border:1px solid ${prCol}40;color:${prCol};font-size:.7rem;font-weight:700;text-transform:uppercase">${escL(row.prioridade)}</span></td>
      <td><span style="padding:3px 9px;border-radius:999px;background:${stCol}1f;border:1px solid ${stCol}40;color:${stCol};font-size:.72rem;font-weight:600">${escL(row.status)}</span></td>
      <td>${bloqIco}</td>
      <td><button class="btn-mst" onclick="openReviewDrawer(${row.id})">Abrir</button></td>
    </tr>`;
  }).join('');
}

async function openReviewDrawer(id) {
  const r = await fj(`${API}/reviews.php?id=${id}`);
  if (!r.ok) return notifyErr(r.error || 'Erro');
  const it = r.data;

  const stCol = REV_STATUS_COLOR[it.status] || '#94a3b8';
  const prCol = REV_PRIO_COLOR[it.prioridade] || '#94a3b8';

  document.getElementById('reviewTitle').textContent =
    `Revisão #${it.id} — ${escL(it.titulo)}`;

  document.getElementById('reviewBody').innerHTML = `
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
      <span style="padding:3px 11px;border-radius:999px;background:${stCol}1f;border:1px solid ${stCol}40;color:${stCol};font-size:.78rem;font-weight:600">${escL(it.status)}</span>
      <span style="padding:3px 11px;border-radius:999px;background:${prCol}1f;border:1px solid ${prCol}40;color:${prCol};font-size:.72rem;font-weight:700;text-transform:uppercase">prio: ${escL(it.prioridade)}</span>
      ${it.bloqueia_producao == 1 ? '<span style="padding:3px 9px;border-radius:999px;background:#dc26261f;border:1px solid #dc262640;color:#dc2626;font-size:.72rem;font-weight:700">BLOQUEIA GO-LIVE</span>' : ''}
      <span style="font-size:.78rem;color:#9ab0c9">${escL(REV_CAT_LABEL[it.categoria] || it.categoria)}</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;font-size:.82rem;color:#9ab0c9;margin-bottom:16px">
      <div>Responsável: <strong style="color:#fff">${escL(it.responsavel || '—')}</strong></div>
      <div>Prazo: <strong style="color:#fff">${it.prazo ? fmtDate(it.prazo) : '—'}</strong></div>
      <div>Criado em: <strong style="color:#fff">${fmtDateTime(it.created_at)}</strong></div>
      ${it.concluido_em ? `<div>Concluído em: <strong style="color:#10b981">${fmtDateTime(it.concluido_em)}</strong></div>` : ''}
      ${it.concluido_por_nome ? `<div>Concluído por: <strong style="color:#fff">${escL(it.concluido_por_nome)}</strong></div>` : ''}
    </div>

    ${it.descricao ? `<h4 style="color:#fff;margin:14px 0 4px">Descrição</h4>
      <div style="background:rgba(96,165,250,.05);padding:12px;border-radius:6px;white-space:pre-wrap;color:#cbd5e1;font-size:.88rem;line-height:1.55">${escL(it.descricao)}</div>` : ''}

    ${it.link_referencia ? `<h4 style="color:#fff;margin:14px 0 4px">Link de referência</h4>
      <a href="${escL(it.link_referencia)}" target="_blank" rel="noopener" style="color:#7eb8f7;word-break:break-all">${escL(it.link_referencia)}</a>` : ''}

    <h4 style="color:#fff;margin:18px 0 6px">Notas internas (DPO/Diretoria)</h4>
    <textarea id="revNotas" rows="4" placeholder="Notas livres sobre o andamento, contato com fornecedor, data prevista, etc..." style="width:100%;padding:10px;border:1px solid rgba(160,180,210,.18);border-radius:6px;background:rgba(5,18,39,.6);color:#fff;font:inherit;resize:vertical">${escL(it.notas_internas || '')}</textarea>

    <h4 style="color:#fff;margin:18px 0 6px">Mudar status</h4>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
      <button class="btn-mst" onclick="updateReview(${it.id},'pendente')">Reabrir (pendente)</button>
      <button class="btn-mst" onclick="updateReview(${it.id},'em_revisao')">Em revisão</button>
      <button class="btn-mst" style="background:linear-gradient(135deg,#94a3b8,#64748b);color:#fff;border:none" onclick="updateReview(${it.id},'dispensado')">Dispensar</button>
      <button class="btn-mst btn-mst-primary" onclick="updateReview(${it.id},'concluido')">Marcar Concluído</button>
    </div>
  `;
  openModal('modalReview');
}

async function updateReview(id, status) {
  const notas = document.getElementById('revNotas').value;
  if (!confirm(`Marcar item #${id} como "${status}"?`)) return;
  const r = await fj(`${API}/reviews.php`, {
    method: 'PATCH',
    body: JSON.stringify({ csrf_token: CSRF, id, status, notas_internas: notas })
  });
  if (!r.ok) return notifyErr(r.error || 'Erro');
  notifyOk('Item atualizado');
  closeModal('modalReview');
  loadReviews();
  refreshReviewsBadge();
}

async function refreshReviewsBadge() {
  try {
    const r = await fj(`${API}/reviews.php?counts=1`);
    if (!r.ok) return;
    const badge = document.getElementById('reviewsBadge');
    const c = r.data || {};
    const blocker = c.bloqueadores_golive || 0;
    const crit    = c.criticos_abertos || 0;
    const pend    = (c.pendentes || 0) + (c.em_revisao || 0);
    if (pend > 0) {
      badge.style.display = 'inline-block';
      badge.textContent = pend;
      badge.style.background = (blocker > 0 || crit > 0) ? '#dc2626' : '#f59e0b';
      badge.title = `${pend} pendentes · ${blocker} bloqueadores go-live · ${crit} críticos`;
    } else {
      badge.style.display = 'none';
    }
  } catch (_) {}
}

refreshReviewsBadge();
setInterval(refreshReviewsBadge, 60000);

document.getElementById('filterRevStatus').addEventListener('change', loadReviews);
document.getElementById('filterRevCategoria').addEventListener('change', loadReviews);
document.getElementById('filterRevPrioridade').addEventListener('change', loadReviews);
document.getElementById('filterRevBloqueia').addEventListener('change', loadReviews);

// ── Init ─────────────────────────────────────────────────────────────────
const initialHash = (window.location.hash || '').replace('#','') || 'overview';
activateTab(initialHash);
</script>
</body>
</html>
