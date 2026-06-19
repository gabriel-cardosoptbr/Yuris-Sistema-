<?php
require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../app/Helpers/MonitorQuota.php';
require_once __DIR__ . '/../app/Helpers/MonitorPermission.php';
require_once __DIR__ . '/../app/Helpers/BillingGuard.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$activePage = 'escritorios';
$csrf       = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$userRole   = $_SESSION['user_role']  ?? 'user';
$accountId  = (int)($_SESSION['account_id'] ?? 0);
$accountTipo= $_SESSION['account_tipo'] ?? 'matriz';
$isAdmin    = in_array($userRole, ['owner', 'admin']) || ($_SESSION['user_perfil'] ?? '') === 'admin';

// ── Aba Monitoramentos (add-on) ─────────────────────────────────────────
// Dados server-side de cota pra render inicial (evita flash "Carregando…").
// JS faz refresh depois via /api/push/quota.php (com Cache-Control no-store).
$ctxEsc            = \App\Helpers\AccountContext::fromSession();
$isMatrizEsc       = $ctxEsc->isMatriz();
$canAllocateEsc    = \App\Helpers\MonitorPermission::canManageQuotaAllocations($ctxEsc);
$canManagePermsEsc = $isAdmin || $ctxEsc->isSuperAdmin();
$advFlagEsc        = \App\Helpers\MonitorPermission::isAdvogadoAllowedToCreate($accountId);
$monStatusEsc      = \App\Helpers\MonitorQuota::getQuotaStatus($accountId);
$monPlanBaseEsc    = \App\Helpers\BillingGuard::getBaseLimit($accountId, 'monitors.limit');
$monOverrideSumEsc = \App\Helpers\BillingGuard::getOverrideSum($accountId, 'monitors.limit');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Escritórios — Yuris</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <style>
    /* ── Tabs ── */
    .es-tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
    .es-tab  { padding:8px 18px; border-radius:8px; border:1px solid rgba(96,165,250,.18); background:transparent; color:#7a96b4; font-size:.84rem; font-weight:600; cursor:pointer; transition:all .18s; }
    .es-tab.active { background:rgba(37,99,235,.22); border-color:rgba(96,165,250,.45); color:#93c5fd; }
    .es-tab:hover:not(.active) { background:rgba(37,99,235,.1); color:#a8c5e8; }

    /* ── Cards de conteúdo ── */
    .es-card { background:linear-gradient(145deg,#0d1c30,#09131f); border:1px solid rgba(160,180,210,.14); border-radius:12px; padding:20px 22px; margin-bottom:16px; }
    .es-card-title { font-size:.88rem; font-weight:700; color:#dbeafe; margin-bottom:14px; letter-spacing:.02em; text-transform:uppercase; }

    /* ── Info grid ── */
    .es-info-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:14px; }
    .es-info-item label { display:block; font-size:.72rem; color:#7a96b4; margin-bottom:3px; }
    .es-info-item span  { font-size:.9rem; color:#d6eaff; font-weight:600; }

    /* ── Badge ── */
    .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:.72rem; font-weight:700; }
    .badge-matriz   { background:rgba(59,130,246,.2); color:#93c5fd; border:1px solid rgba(59,130,246,.35); }
    .badge-filial   { background:rgba(139,92,246,.2); color:#c4b5fd; border:1px solid rgba(139,92,246,.35); }
    /* advogado solo tem cor própria (VERDE), nunca azul/roxo — mesma paleta is-advogado de prospeccao/clientes/tarefas */
    .badge-advogado { background:rgba(16,185,129,.2); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
    .badge-active   { background:rgba(34,197,94,.15); color:#86efac; border:1px solid rgba(34,197,94,.3); }
    .badge-pending  { background:rgba(245,158,11,.15); color:#fcd34d; border:1px solid rgba(245,158,11,.3); }
    .badge-rejected { background:rgba(239,68,68,.15); color:#fca5a5; border:1px solid rgba(239,68,68,.3); }
    .badge-revoked, .badge-suspended { background:rgba(100,116,139,.15); color:#94a3b8; border:1px solid rgba(100,116,139,.3); }

    /* Badges de permissão (compartilhamentos pontuais) */
    .badge-perm-view { background:rgba(96,165,250,.18); color:#93c5fd; border:1px solid rgba(96,165,250,.32); padding:3px 9px; font-size:.72rem; }
    .badge-perm-edit { background:rgba(245,158,11,.18); color:#fcd34d; border:1px solid rgba(245,158,11,.32); padding:3px 9px; font-size:.72rem; }
    .badge-perm-full { background:rgba(34,197,94,.18);  color:#86efac; border:1px solid rgba(34,197,94,.32);  padding:3px 9px; font-size:.72rem; }

    /* Badges de recurso compartilhado */
    .badge-res-processo { background:rgba(167,139,250,.16); color:#c4b5fd; border:1px solid rgba(167,139,250,.30); padding:3px 9px; font-size:.72rem; display:inline-flex; align-items:center; gap:5px; }
    .badge-res-card     { background:rgba(34,197,94,.16);   color:#86efac; border:1px solid rgba(34,197,94,.30);   padding:3px 9px; font-size:.72rem; display:inline-flex; align-items:center; gap:5px; }
    .badge-res-contato  { background:rgba(245,158,11,.16);  color:#fcd34d; border:1px solid rgba(245,158,11,.30);  padding:3px 9px; font-size:.72rem; display:inline-flex; align-items:center; gap:5px; }

    /* Destinatário (compartilhamento) */
    .share-dest { display:flex; flex-direction:column; gap:2px; }
    .share-dest-name { font-weight:600; color:#c8ddf0; }
    .share-dest-tipo { font-size:.72rem; color:#7a96b4; }

    /* Código do destinatário (ADV-XXXXXX / codigo_vinculo) */
    .share-codigo {
      font-family:monospace; font-size:.78rem; color:#7eb8f6;
      background:rgba(5,18,39,.5); padding:3px 8px; border-radius:5px;
      border:1px solid rgba(96,165,250,.18); letter-spacing:.04em;
    }

    .share-recurso-link { text-decoration:none; }
    .share-since { color:#7a96b4; font-size:.78rem; }
    .share-actions { display:flex; gap:6px; flex-wrap:wrap; }
    .btn-share-edit {
      background:rgba(37,99,235,.18); color:#93c5fd;
      border:1px solid rgba(37,99,235,.30);
    }
    .btn-share-edit:hover { background:rgba(37,99,235,.30); }

    /* Caixa informativa do modal Editar Permissão */
    .edit-share-info {
      padding:10px 14px; border:1px solid rgba(96,165,250,.16);
      border-radius:8px; background:rgba(5,18,39,.4); margin-bottom:14px;
      color:#dbe9ff;
    }

    /* ── Código de vínculo ── */
    .codigo-box { display:flex; align-items:center; gap:10px; background:rgba(5,18,39,.8); border:1px solid rgba(96,165,250,.2); border-radius:8px; padding:10px 14px; }
    .codigo-box code { flex:1; font-family:monospace; color:#7eb8f6; font-size:.92rem; letter-spacing:.06em; }
    .copy-btn { padding:5px 12px; border-radius:6px; border:1px solid rgba(96,165,250,.3); background:transparent; color:#93c5fd; font-size:.78rem; cursor:pointer; transition:background .15s; }
    .copy-btn:hover { background:rgba(37,99,235,.2); }

    /* ── Tabela ── */
    .es-table { width:100%; border-collapse:collapse; font-size:.83rem; }
    .es-table th { color:#7a96b4; font-weight:600; text-align:left; padding:8px 12px; border-bottom:1px solid rgba(160,180,210,.1); font-size:.72rem; text-transform:uppercase; }
    .es-table td { padding:10px 12px; border-bottom:1px solid rgba(160,180,210,.06); color:#c8ddf0; vertical-align:middle; }
    .es-table tr:last-child td { border-bottom:none; }
    .es-table tr:hover td { background:rgba(37,99,235,.05); }

    /* ── Botões ── */
    .btn-sm  { padding:5px 12px; border-radius:6px; font-size:.78rem; font-weight:600; cursor:pointer; border:none; transition:all .15s; }
    .btn-primary { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; }
    .btn-primary:hover { background:linear-gradient(135deg,#3b82f6,#2563eb); }
    .btn-danger  { background:rgba(220,38,38,.2); color:#fca5a5; border:1px solid rgba(220,38,38,.3); }
    .btn-danger:hover  { background:rgba(220,38,38,.35); }
    .btn-success { background:rgba(34,197,94,.2); color:#86efac; border:1px solid rgba(34,197,94,.3); }
    .btn-success:hover { background:rgba(34,197,94,.35); }
    .btn-outline { background:transparent; color:#7eb8f6; border:1px solid rgba(96,165,250,.25); }
    .btn-outline:hover { background:rgba(37,99,235,.15); }

    /* ── Form ── */
    .es-form-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
    .es-field { display:flex; flex-direction:column; gap:5px; flex:1; min-width:180px; }
    .es-field label { font-size:.75rem; color:#7a96b4; font-weight:600; }
    .es-input { padding:8px 12px; border-radius:8px; background:rgba(5,18,39,.85); border:1px solid rgba(96,165,250,.2); color:#d6eaff; font-size:.83rem; font-family:inherit; }
    .es-input:focus { outline:none; border-color:rgba(96,165,250,.5); }
    .es-textarea { resize:vertical; min-height:70px; }

    /* ── Empty state ── */
    .es-empty { text-align:center; padding:32px 16px; color:#4a5568; font-size:.84rem; }
    .es-empty svg { width:36px; height:36px; margin:0 auto 10px; opacity:.4; display:block; }

    /* ── Pane ── */
    .es-pane { display:none; }
    .es-pane.active { display:block; }

    /* ── Modal ── */
    .es-overlay { display:none; position:fixed; inset:0; z-index:9000; background:rgba(2,6,23,.75); backdrop-filter:blur(4px); align-items:center; justify-content:center; }
    .es-overlay.open { display:flex; }
    .es-modal { background:linear-gradient(165deg,rgba(13,28,48,.98),rgba(8,18,35,.99)); border:1px solid rgba(96,165,250,.2); border-radius:14px; padding:26px 28px 22px; width:480px; max-width:95vw; box-shadow:0 24px 60px rgba(2,6,23,.7); }
    .es-modal h3 { font-size:1rem; font-weight:700; color:#dbeafe; margin-bottom:18px; }
    .es-modal-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }

    /* ──────────────────────────────────────────────────────────────────────
       TEMA CLARO — overrides para data-theme="light"
       Mantém a mesma estrutura visual mas com paleta clara legível.
       Princípio: fundo claro → texto escuro; bordas/badges suavizados.
       ────────────────────────────────────────────────────────────────────── */
    html[data-theme="light"] .es-card {
      background: #FFFFFF;
      border: 1px solid #E2E8F0;
      box-shadow: 0 1px 3px rgba(15,23,42,.04);
    }
    html[data-theme="light"] .es-card-title { color: #1E3A5F; }

    html[data-theme="light"] .es-tab {
      background: #FFFFFF;
      border-color: #D1DCE8;
      color: #4A5C72;
    }
    html[data-theme="light"] .es-tab.active {
      background: rgba(37,99,235,.10);
      border-color: rgba(37,99,235,.35);
      color: #1D4ED8;
    }
    html[data-theme="light"] .es-tab:hover:not(.active) {
      background: rgba(37,99,235,.05);
      color: #1E40AF;
    }

    html[data-theme="light"] .codigo-box {
      background: #F8FAFC;
      border-color: #D1DCE8;
      color: #1E3A5F;
    }
    html[data-theme="light"] .codigo-box code,
    html[data-theme="light"] .codigo-box span { color: #1E40AF; }

    html[data-theme="light"] .es-table th {
      color: #64748B;
      border-bottom-color: #E2E8F0;
    }
    html[data-theme="light"] .es-table td {
      color: #1E3A5F;
      border-bottom-color: #EDF2F7;
    }
    html[data-theme="light"] .es-table tr:hover td { background: #F1F5F9; }

    html[data-theme="light"] .badge-matriz {
      background: rgba(37,99,235,.10);
      color: #1D4ED8;
      border-color: rgba(37,99,235,.30);
    }
    html[data-theme="light"] .badge-filial {
      background: rgba(126,34,206,.10);
      color: #6B21A8;
      border-color: rgba(126,34,206,.30);
    }
    html[data-theme="light"] .badge-advogado {
      background: rgba(5,150,105,.10);
      color: #065F46;
      border-color: rgba(5,150,105,.30);
    }
    html[data-theme="light"] .badge-active {
      background: rgba(34,197,94,.10);
      color: #166534;
      border-color: rgba(34,197,94,.30);
    }
    html[data-theme="light"] .badge-pending {
      background: rgba(245,158,11,.10);
      color: #92400E;
      border-color: rgba(245,158,11,.30);
    }
    html[data-theme="light"] .badge-suspended,
    html[data-theme="light"] .badge-rejected {
      background: rgba(239,68,68,.10);
      color: #991B1B;
      border-color: rgba(239,68,68,.30);
    }
    html[data-theme="light"] .badge-sync-on {
      background: rgba(34,197,94,.10) !important;
      color: #166534 !important;
      border-color: rgba(34,197,94,.30) !important;
    }

    html[data-theme="light"] .es-input {
      background: #FFFFFF;
      border-color: #D1DCE8;
      color: #1E3A5F;
    }
    html[data-theme="light"] .es-input:focus {
      border-color: #2563EB;
      box-shadow: 0 0 0 3px rgba(37,99,235,.10);
    }
    html[data-theme="light"] .es-input::placeholder { color: #94A3B8; }

    html[data-theme="light"] .es-empty {
      color: #64748B;
    }

    /* Botões — manter cor de identidade, mas com contraste pro fundo branco */
    html[data-theme="light"] .btn-success {
      background: #16A34A !important;
      color: #FFFFFF !important;
      border: 1px solid #16A34A !important;
    }
    html[data-theme="light"] .btn-success:hover { background: #15803D !important; }
    html[data-theme="light"] .btn-danger {
      background: #DC2626 !important;
      color: #FFFFFF !important;
      border: 1px solid #DC2626 !important;
    }
    html[data-theme="light"] .btn-danger:hover { background: #B91C1C !important; }
    html[data-theme="light"] .btn-primary {
      background: #2563EB !important;
      color: #FFFFFF !important;
      border: 1px solid #2563EB !important;
    }
    html[data-theme="light"] .btn-primary:hover { background: #1D4ED8 !important; }
    html[data-theme="light"] .btn-outline {
      background: #FFFFFF !important;
      color: #475569 !important;
      border: 1px solid #CBD5E1 !important;
    }
    html[data-theme="light"] .btn-outline:hover { background: #F8FAFC !important; }

    /* Botão "Sincronização" usa inline style azul-translúcido — força contraste no claro */
    html[data-theme="light"] [onclick*="abrirSyncModal"] {
      background: rgba(37,99,235,.10) !important;
      color: #1D4ED8 !important;
      border: 1px solid rgba(37,99,235,.35) !important;
    }
    html[data-theme="light"] [onclick*="abrirSyncModal"]:hover {
      background: rgba(37,99,235,.18) !important;
    }

    html[data-theme="light"] .copy-btn {
      background: #FFFFFF;
      border-color: #CBD5E1;
      color: #1D4ED8;
    }
    html[data-theme="light"] .copy-btn:hover { background: #EFF6FF; }

    /* Painel "Minha Conta" — labels + valores (Nome / Tipo / Plano / Status) */
    html[data-theme="light"] .es-info-item label { color: #64748B; }
    html[data-theme="light"] .es-info-item span  { color: #0F172A; }

    /* Modal no tema claro */
    html[data-theme="light"] .es-overlay {
      background: rgba(15,23,42,.45);
    }
    html[data-theme="light"] .es-modal {
      background: #FFFFFF;
      border: 1px solid #E2E8F0;
      box-shadow: 0 24px 60px rgba(15,23,42,.18);
    }
    html[data-theme="light"] .es-modal h3 { color: #1E3A5F; }
    html[data-theme="light"] .es-modal p,
    html[data-theme="light"] .es-modal label,
    html[data-theme="light"] .es-modal span { color: #1E3A5F; }
    /* Os blocos do modal de Sync (toggle mestre + módulos) usam fundo dark — força claro */
    html[data-theme="light"] #modalSync label[style*="rgba(5,18,39"],
    html[data-theme="light"] #modalSync #syncModulesGroup {
      background: #F8FAFC !important;
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] #modalSync [style*="color:#dbe9ff"],
    html[data-theme="light"] #modalSync [style*="color:#dbeafe"] { color: #1E3A5F !important; }
    html[data-theme="light"] #modalSync [style*="color:#7a96b4"] { color: #64748B !important; }

    /* Tema claro — Compartilhamentos pontuais (linha + modal Editar) */
    html[data-theme="light"] .share-dest-name { color: #0F1F36 !important; }
    html[data-theme="light"] .share-dest-tipo { color: #64748B !important; }
    html[data-theme="light"] .share-codigo {
      background: #EFF6FF !important;
      color: #1D4ED8 !important;
      border-color: #BFDBFE !important;
    }
    html[data-theme="light"] .share-since { color: #64748B !important; }

    html[data-theme="light"] .badge-perm-view {
      background: #DBEAFE !important; color: #1D4ED8 !important;
      border-color: #93C5FD !important;
    }
    html[data-theme="light"] .badge-perm-edit {
      background: #FEF3C7 !important; color: #92400E !important;
      border-color: #FCD34D !important;
    }
    html[data-theme="light"] .badge-perm-full {
      background: #D1FAE5 !important; color: #047857 !important;
      border-color: #6EE7B7 !important;
    }
    html[data-theme="light"] .badge-res-processo {
      background: #EDE9FE !important; color: #6D28D9 !important;
      border-color: #C4B5FD !important;
    }
    html[data-theme="light"] .badge-res-card {
      background: #D1FAE5 !important; color: #047857 !important;
      border-color: #6EE7B7 !important;
    }
    html[data-theme="light"] .badge-res-contato {
      background: #FEF3C7 !important; color: #92400E !important;
      border-color: #FCD34D !important;
    }
    html[data-theme="light"] .btn-share-edit {
      background: #DBEAFE !important; color: #1D4ED8 !important;
      border-color: #93C5FD !important;
    }
    html[data-theme="light"] .btn-share-edit:hover { background: #BFDBFE !important; }

    /* Caixa info do modal Editar Permissão */
    html[data-theme="light"] .edit-share-info {
      background: #F8FAFC !important;
      border-color: #E2E8F0 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .edit-share-info strong { color: #0F1F36 !important; }
    html[data-theme="light"] .edit-share-info div[style*="color:#7a96b4"] { color: #64748B !important; }

    /* ──────────────────────────────────────────────────────────────────
       Aba MONITORAMENTOS — add-on. Tudo .mon-* pra não colidir com classes
       das outras abas. Tema escuro default + overrides claro.
       ────────────────────────────────────────────────────────────────── */
    .mon-kpi-grid {
      display: grid; gap: 12px;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }
    .mon-kpi {
      background: rgba(14,35,65,.55);
      border: 1px solid rgba(160,180,210,.14);
      border-radius: 12px;
      padding: 14px 16px;
    }
    .mon-kpi-label { font-size:.74rem; color:#7a96b4; text-transform:uppercase; letter-spacing:.05em; }
    .mon-kpi-value { font-size:1.6rem; font-weight:700; margin-top:4px; color:#dbeafe; }
    .mon-kpi.good .mon-kpi-value { color:#6EE7B7; }
    .mon-kpi.warn .mon-kpi-value { color:#FBBF24; }
    .mon-kpi.bad  .mon-kpi-value { color:#FCA5A5; }
    .mon-kpi-meta { font-size:.72rem; color:#7a96b4; margin-top:6px; }

    .mon-alert {
      padding: 12px 14px; border-radius: 10px;
      font-size: .85rem;
    }
    .mon-alert-info {
      background: rgba(37,99,235,.10);
      border: 1px solid rgba(96,165,250,.25);
      color: #bbd7ff;
    }
    .mon-alert-warn {
      background: rgba(245,158,11,.10);
      border: 1px solid rgba(245,158,11,.30);
      color: #FED7AA;
    }

    .mon-perm-row {
      display:flex; align-items:center; gap:14px;
      padding:12px 14px;
      background: rgba(14,35,65,.4);
      border: 1px solid rgba(160,180,210,.14);
      border-radius: 10px;
    }
    .mon-perm-title { font-weight:600; color:#dbeafe; font-size:.92rem; }
    .mon-perm-hint  { font-size:.78rem; color:#7a96b4; margin-top:4px; }

    .mon-switch { position:relative; display:inline-block; width:42px; height:22px; flex-shrink:0; }
    .mon-switch input { opacity:0; width:0; height:0; }
    .mon-slider {
      position:absolute; cursor:pointer; inset:0;
      background:#3B4A5E; border-radius:22px; transition:background .15s;
    }
    .mon-slider:before {
      content:""; position:absolute; height:16px; width:16px; left:3px; top:3px;
      background:#fff; border-radius:50%; transition:.15s;
    }
    .mon-switch input:checked + .mon-slider { background:#2563EB; }
    .mon-switch input:checked + .mon-slider:before { transform: translateX(20px); }

    .mon-table { width:100%; border-collapse:collapse; font-size:.85rem; }
    .mon-table th, .mon-table td {
      padding:10px 12px; text-align:left;
      border-bottom:1px solid rgba(160,180,210,.10);
      color:#dbeafe;
    }
    .mon-table th {
      font-size:.7rem; text-transform:uppercase; letter-spacing:.05em;
      color:#7a96b4; font-weight:600;
    }
    .mon-table tr:last-child td { border-bottom:none; }
    .mon-alloc-input {
      width:80px; padding:6px 8px;
      background: rgba(14,35,65,.7);
      border: 1px solid rgba(160,180,210,.22);
      border-radius: 6px;
      color: #dbeafe;
      font: inherit; font-size:.85rem;
      text-align:center;
    }
    .mon-alloc-input:focus { outline:none; border-color:#2563EB; }

    .mon-pill {
      display:inline-block; padding:2px 10px;
      border-radius:999px; font-size:.68rem; font-weight:700;
      text-transform:uppercase; letter-spacing:.04em;
    }
    .mon-pill-active   { background:rgba(74,144,120,.16); color:#6EE7B7; border:1px solid rgba(74,144,120,.30); }
    .mon-pill-revoked  { background:rgba(160,180,210,.12); color:#94a3b8; border:1px solid rgba(160,180,210,.22); }
    .mon-pill-pending  { background:rgba(245,158,11,.16); color:#FBBF24; border:1px solid rgba(245,158,11,.35); }
    .mon-pill-denied   { background:rgba(220,38,38,.16); color:#FCA5A5; border:1px solid rgba(220,38,38,.35); }

    .mon-hint { font-size:.75rem; color:#7a96b4; }

    /* TEMA CLARO (light) — overrides do add-on */
    html[data-theme="light"] .mon-kpi {
      background: #F2F5F9;
      border-color: #E2E8F0;
    }
    html[data-theme="light"] .mon-kpi-label { color: #64748B; }
    html[data-theme="light"] .mon-kpi-value { color: #0F1F36; }
    html[data-theme="light"] .mon-kpi.good .mon-kpi-value { color: #047857; }
    html[data-theme="light"] .mon-kpi.warn .mon-kpi-value { color: #B45309; }
    html[data-theme="light"] .mon-kpi.bad  .mon-kpi-value { color: #B91C1C; }
    html[data-theme="light"] .mon-kpi-meta { color: #64748B; }
    html[data-theme="light"] .mon-alert-info {
      background: rgba(37,99,235,.07);
      border-color: rgba(37,99,235,.20);
      color: #1D4ED8;
    }
    html[data-theme="light"] .mon-alert-warn {
      background: rgba(245,158,11,.08);
      border-color: rgba(245,158,11,.30);
      color: #92400E;
    }
    html[data-theme="light"] .mon-perm-row {
      background: #F8FAFC;
      border-color: #E2E8F0;
    }
    html[data-theme="light"] .mon-perm-title { color: #0F1F36; }
    html[data-theme="light"] .mon-perm-hint { color: #64748B; }
    html[data-theme="light"] .mon-slider { background: #CBD5E1; }
    html[data-theme="light"] .mon-table td { color: #0F1F36; border-bottom-color: #EDF2F7; }
    html[data-theme="light"] .mon-table th { color: #64748B; border-bottom-color: #E2E8F0; }
    html[data-theme="light"] .mon-alloc-input {
      background: #FFFFFF;
      color: #0F1F36;
      border-color: #D1DCE8;
    }
    html[data-theme="light"] .mon-pill-active  { background:rgba(22,163,74,.10); color:#15803D; border-color:rgba(22,163,74,.30); }
    html[data-theme="light"] .mon-pill-revoked { background:rgba(100,116,139,.10); color:#475569; border-color:rgba(100,116,139,.30); }
    html[data-theme="light"] .mon-pill-pending { background:rgba(245,158,11,.10); color:#92400E; border-color:rgba(245,158,11,.30); }
    html[data-theme="light"] .mon-pill-denied  { background:rgba(239,68,68,.10); color:#991B1B; border-color:rgba(239,68,68,.30); }
    html[data-theme="light"] .mon-hint { color: #64748B; }

    /* Badge de pending na tab */
    html[data-theme="light"] #reqPendingBadgeEsc { background:#DC2626; color:#FFF; }
  </style>
</head>
<body>
<main class="w-full px-6 py-6">
  <div class="page-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <section class="main-content space-y-4">

      <!-- Header -->
      <div class="card-shell page-header">
        <div class="page-header-inner">
          <div class="page-header-text">
            <h2 class="page-header-title">Escritórios</h2>
            <p class="page-header-subtitle">Gerencie vínculos Matriz–Filial, advogados associados e compartilhamento de recursos.</p>
          </div>
        </div>
      </div>

      <!-- Painel da conta atual -->
      <div class="es-card" id="painelConta">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="es-card-title" style="margin:0">Minha Conta</div>
          <?php if ($isAdmin): ?>
          <button class="btn-sm btn-outline" onclick="toggleEditConta()">Editar</button>
          <?php endif; ?>
        </div>

        <!-- Modo visualização -->
        <div id="contaView">
          <div class="es-info-grid">
            <div class="es-info-item"><label>Nome</label><span id="cNome">—</span></div>
            <div class="es-info-item"><label>Tipo</label><span id="cTipo">—</span></div>
            <div class="es-info-item"><label>Plano</label><span id="cPlano">—</span></div>
            <div class="es-info-item"><label>Status</label><span id="cStatus">—</span></div>
          </div>
          <?php if ($isAdmin): ?>
          <div style="margin-top:16px;">
            <label style="font-size:.75rem;color:#7a96b4;font-weight:600;display:block;margin-bottom:6px;">Código de vínculo <span style="color:#4a5568;font-weight:400">(compartilhe com filiais)</span></label>
            <div class="codigo-box">
              <code id="codigoVinculo">carregando...</code>
              <button class="copy-btn" onclick="copiarCodigo()">Copiar</button>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Modo edição -->
        <?php if ($isAdmin): ?>
        <div id="contaEdit" style="display:none;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div class="es-field">
              <label>Nome do escritório</label>
              <input type="text" id="editNome" class="es-input">
            </div>
            <div class="es-field">
              <label>Tipo de conta</label>
              <select id="editTipo" class="es-input">
                <option value="matriz">Matriz (escritório principal)</option>
                <option value="filial">Filial (vinculada a uma Matriz)</option>
                <option value="advogado">Advogado (solo)</option>
              </select>
            </div>
            <div class="es-field">
              <label>Plano</label>
              <select id="editPlano" class="es-input">
                <option value="basico">Básico</option>
                <option value="profissional">Profissional</option>
                <option value="enterprise">Enterprise</option>
              </select>
            </div>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn-sm btn-primary" onclick="salvarConta()">Salvar</button>
            <button class="btn-sm btn-outline" onclick="toggleEditConta()">Cancelar</button>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Tabs — data-tab garante que o nome do pane bate certo mesmo quando
           algumas tabs sao escondidas condicionalmente (ex: advogado nao ve
           "Vinculos" matriz<->filial). Antes era por posicao no array e
           quebrava: clicar em "Quem me vinculou" ativava "Compartilhamentos". -->
      <div class="es-tabs">
        <?php if ($accountTipo !== 'advogado'): ?>
        <button class="es-tab active" data-tab="vinculos"  onclick="switchTab('vinculos')">Vínculos</button>
        <button class="es-tab"         data-tab="advogados" onclick="switchTab('advogados')">Advogados Associados</button>
        <?php else: ?>
        <button class="es-tab active" data-tab="advogados" onclick="switchTab('advogados')">Quem me vinculou</button>
        <?php endif; ?>
        <button class="es-tab" data-tab="compartilhamentos" onclick="switchTab('compartilhamentos')">Compartilhamentos</button>
        <button class="es-tab" data-tab="modulos"           onclick="switchTab('modulos')">Módulos</button>
        <button class="es-tab" data-tab="monitoramentos"    onclick="switchTab('monitoramentos')">
          Monitoramentos
          <span id="reqPendingBadgeEsc" style="display:none; margin-left:6px; padding:1px 7px; border-radius:999px; background:#DC2626; color:#FFF; font-size:.7rem;">0</span>
        </button>
        <?php if ($isAdmin && $accountTipo === 'filial'): ?>
        <button class="es-tab" data-tab="solicitar"         onclick="switchTab('solicitar')">Solicitar Vínculo</button>
        <?php endif; ?>
      </div>

      <!-- ── PANE: Vínculos ── (escondido para advogado: ele só vê "Quem me vinculou") -->
      <?php if ($accountTipo !== 'advogado'): ?>
      <div class="es-pane active" id="pane-vinculos">
        <div class="es-card">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <div class="es-card-title" style="margin:0">Vínculos Matriz–Filial</div>
            <?php if ($isAdmin && $accountTipo === 'filial'): ?>
            <button class="btn-sm btn-primary" onclick="abrirSolicitarVinculo()">+ Solicitar Vínculo</button>
            <?php endif; ?>
          </div>
          <div id="vinculosList"><div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Carregando...</div></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── PANE: Advogados ── (default active para conta advogado) -->
      <div class="es-pane <?= $accountTipo === 'advogado' ? 'active' : '' ?>" id="pane-advogados">
        <!-- Vinculos de conta (advogado_vinculos) — analogo a matriz-filial -->
        <div class="es-card">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <div>
              <div class="es-card-title" style="margin:0">Vínculos de conta com advogados</div>
              <?php if ($accountTipo !== 'advogado'): ?>
              <p style="font-size:.78rem;color:#7a96b4;margin:3px 0 0">
                Vincule advogados pelo código <strong>ADV-XXXXXX</strong>. Tudo do escritório dele (Cards/Processos/Tarefas) fica acessível conforme as flags de sincronização.
              </p>
              <?php else: ?>
              <p style="font-size:.78rem;color:#7a96b4;margin:3px 0 0">
                Veja quais matrizes/filiais te vincularam e o que estão sincronizando.
              </p>
              <?php endif; ?>
            </div>
            <?php if ($isAdmin && $accountTipo !== 'advogado'): ?>
            <button class="btn-sm btn-primary" onclick="abrirModalVincularAdv()">+ Vincular Advogado</button>
            <?php endif; ?>
          </div>
          <div id="advogadosVinculosList"><div class="es-empty">Carregando...</div></div>
        </div>

        <!-- Compartilhamentos pontuais (resource_shares legado) — apenas para hosts -->
        <?php if ($accountTipo !== 'advogado'): ?>
        <div class="es-card" style="margin-top:12px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <div>
              <div class="es-card-title" style="margin:0">Compartilhamentos pontuais por processo</div>
              <p style="font-size:.78rem;color:#7a96b4;margin:3px 0 0">Compartilhe um processo específico (não a conta inteira). Útil para casos isolados.</p>
            </div>
            <?php if ($isAdmin): ?>
            <button class="btn-sm btn-outline" onclick="abrirModalConvite()">+ Novo compartilhamento</button>
            <?php endif; ?>
          </div>
          <div id="advogadosList"><div class="es-empty">Carregando...</div></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── PANE: Compartilhamentos ── -->
      <div class="es-pane" id="pane-compartilhamentos">
        <div class="es-card">
          <div class="es-card-title">Compartilhamentos Ativos</div>
          <div id="sharesList"><div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Carregando...</div></div>
        </div>
      </div>

      <!-- ── PANE: Módulos ── -->
      <div class="es-pane" id="pane-modulos">
        <div class="es-card">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <div class="es-card-title" style="margin:0">Módulos Liberados</div>
            <?php if ($isAdmin): ?>
            <button class="btn-sm btn-primary" onclick="abrirModalModulo()">+ Liberar módulo</button>
            <?php endif; ?>
          </div>
          <p style="font-size:.82rem;color:#7a96b4;margin:0 0 14px">Aqui você libera abas inteiras (ex: Dashboard, Jurídico, Processos) para outra matriz, filial ou advogado. Quem receber verá os <strong>seus dados</strong> daquela aba — diferente do vínculo de um processo específico, que libera apenas aquele processo.</p>
          <div id="modulosList"><div class="es-empty">Carregando...</div></div>
        </div>
      </div>

      <!-- ── PANE: Monitoramentos ── -->
      <div class="es-pane" id="pane-monitoramentos">

        <!-- Resumo: quantos a conta tem -->
        <div class="es-card">
          <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <div class="es-card-title" style="margin:0">Quantos monitoramentos você tem</div>
            <a href="/intimacoes.php#monitores"
               class="btn-sm btn-primary"
               title="Abre o cadastro de monitoramentos"
               style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
              + Novo monitoramento
            </a>
          </div>
          <p class="mon-hint" style="margin:6px 0 14px">
            Cada OAB ou processo monitorado é uma assinatura à parte do plano do sistema.
            Aqui você vê quantos sua conta tem disponível.
          </p>
          <?php if ($monStatusEsc['effective_limit'] <= 0): ?>
          <div class="mon-alert mon-alert-warn" style="margin-bottom:14px;">
            <strong>Você ainda não contratou nenhum monitoramento.</strong>
            Fale com o time comercial para contratar.
          </div>
          <?php endif; ?>
          <div class="mon-kpi-grid">
            <div class="mon-kpi">
              <div class="mon-kpi-label">Total</div>
              <div class="mon-kpi-value" id="monKpiContratado"><?= (int) $monStatusEsc['effective_limit'] ?></div>
              <div class="mon-kpi-meta">
                <?php if ($monStatusEsc['has_allocations'] && !$isMatrizEsc): ?>
                  recebidos da matriz
                <?php elseif (!$isMatrizEsc): ?>
                  compartilhados com a matriz
                <?php else: ?>
                  <?php $detalheTotal = []; ?>
                  <?php if ($monPlanBaseEsc > 0) $detalheTotal[] = "incluídos no plano: " . (int) $monPlanBaseEsc; ?>
                  <?php if ($monOverrideSumEsc > 0) $detalheTotal[] = "comprados à parte: " . (int) $monOverrideSumEsc; ?>
                  <?= implode(' · ', $detalheTotal) ?: 'nenhum contratado' ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="mon-kpi <?= $monStatusEsc['percent_used'] >= 100 ? 'bad' : ($monStatusEsc['percent_used'] >= 80 ? 'warn' : '') ?>" id="monKpiUsadoBox">
              <div class="mon-kpi-label">Em uso agora</div>
              <div class="mon-kpi-value" id="monKpiUsado"><?= (int) $monStatusEsc['current_usage'] ?></div>
              <div class="mon-kpi-meta" id="monKpiPercent">monitoramentos ativos</div>
            </div>
            <div class="mon-kpi <?= $monStatusEsc['available'] > 0 ? 'good' : 'bad' ?>" id="monKpiDispBox">
              <div class="mon-kpi-label">Sobrando</div>
              <div class="mon-kpi-value" id="monKpiDispo"><?= (int) $monStatusEsc['available'] ?></div>
              <div class="mon-kpi-meta">vagas livres para criar mais</div>
            </div>
          </div>
        </div>

        <?php if ($canManagePermsEsc): ?>
        <!-- Permissões -->
        <div class="es-card">
          <div class="es-card-title">Quem pode criar monitoramento</div>
          <div class="mon-perm-row">
            <label class="mon-switch">
              <input type="checkbox" id="monFlagAdvogadoCreate" <?= $advFlagEsc ? 'checked' : '' ?>>
              <span class="mon-slider"></span>
            </label>
            <div>
              <div class="mon-perm-title">Advogados podem criar monitoramento sozinhos</div>
              <div class="mon-perm-hint">
                Quando esta opção está ligada, qualquer advogado da conta consegue cadastrar
                um monitoramento direto. Quando desligada, ele precisa <strong>pedir aprovação</strong>
                pra você (admin). Donos e administradores sempre podem criar.
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($isMatrizEsc && $canAllocateEsc): ?>
        <!-- Distribuição matriz ↔ filiais -->
        <div class="es-card">
          <div class="es-card-title">Como dividir entre matriz e filiais</div>
          <div class="mon-alert mon-alert-info" style="margin-bottom:14px;">
            Por padrão, suas filiais compartilham os monitoramentos da matriz —
            quem cadastrar primeiro usa. Se quiser <strong>garantir um número
            fixo</strong> pra uma filial específica, preencha o campo "Quero deixar"
            ao lado dela e clique <strong>Salvar</strong>.
          </div>
          <div class="mon-kpi-grid" id="monDistribKpis" style="margin-bottom:18px;">
            <div class="mon-kpi">
              <div class="mon-kpi-label">Total contratado</div>
              <div class="mon-kpi-value" id="monKpiPoolTotal">—</div>
              <div class="mon-kpi-meta">soma de tudo que sua conta comprou</div>
            </div>
            <div class="mon-kpi warn">
              <div class="mon-kpi-label">Reservado pras filiais</div>
              <div class="mon-kpi-value" id="monKpiAlloc">—</div>
              <div class="mon-kpi-meta">já separado pra cada filial</div>
            </div>
            <div class="mon-kpi good">
              <div class="mon-kpi-label">Sobra para a matriz</div>
              <div class="mon-kpi-value" id="monKpiPoolLivre">—</div>
              <div class="mon-kpi-meta">o que a matriz pode usar livremente</div>
            </div>
          </div>
          <div id="monFiliaisWrap"><div class="es-empty">Carregando…</div></div>
        </div>
        <?php endif; ?>

        <!-- Solicitações / Pedidos -->
        <div class="es-card">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
            <div class="es-card-title" style="margin:0">Pedidos de monitoramento</div>
            <div style="display:flex; gap:8px; align-items:center;">
              <select id="monReqScope" class="es-input" style="height:32px; padding:4px 10px; width:auto;">
                <option value="pending">Aguardando aprovação</option>
                <option value="all">Todos</option>
                <option value="mine">Só os meus</option>
              </select>
              <button class="btn-sm btn-outline" onclick="loadMonReqs()">Atualizar</button>
            </div>
          </div>
          <div class="mon-alert mon-alert-info" style="margin-bottom:12px;">
            <?php if ($canManagePermsEsc): ?>
              Quando um advogado pede um monitoramento novo, o pedido cai aqui.
              Você pode <strong>aprovar</strong> (cria o monitoramento na hora) ou <strong>recusar</strong>.
            <?php else: ?>
              Quando você pede um monitoramento, ele aparece aqui aguardando a aprovação
              do admin. Assim que for aprovado, o monitoramento começa a rodar automaticamente.
            <?php endif; ?>
          </div>
          <div id="monReqsWrap"><div class="es-empty">Carregando…</div></div>
        </div>

        <?php if ($isMatrizEsc && $canAllocateEsc): ?>
        <!-- Histórico -->
        <div class="es-card">
          <div class="es-card-title">Histórico de reservas pra filiais</div>
          <p class="mon-hint" style="margin:0 0 12px">
            Tudo que você reservou ou desfez aparece aqui para auditoria.
          </p>
          <div id="monAllocHistWrap"><div class="es-empty">Carregando…</div></div>
        </div>
        <?php endif; ?>

      </div>

      <!-- ── PANE: Solicitar vínculo ── -->
      <div class="es-pane" id="pane-solicitar">
        <div class="es-card">
          <div class="es-card-title">Solicitar Vínculo com Matriz</div>
          <p style="font-size:.83rem;color:#7a96b4;margin-bottom:16px;">Informe o código de vínculo fornecido pelo escritório Matriz.</p>
          <div class="es-form-row">
            <div class="es-field">
              <label>Código de vínculo da Matriz</label>
              <input type="text" id="inputCodigoMatriz" class="es-input" placeholder="Ex: a1b2c3d4...">
            </div>
            <button class="btn-sm btn-primary" style="height:36px;white-space:nowrap;" onclick="solicitarVinculo()">Solicitar</button>
          </div>
          <div id="solicitarMsg" style="margin-top:10px;font-size:.82rem;"></div>
        </div>
      </div>

    </section>
  </div>
</main>

<!-- ── Modal: Compartilhar com Advogado Associado ── -->
<div class="es-overlay" id="modalConvite">
  <div class="es-modal" style="width:520px;">
    <h3>Compartilhar com Advogado Associado</h3>

    <!-- Passo 1: identificar conta -->
    <div id="adv-passo1">
      <p style="font-size:.82rem;color:#7a96b4;margin-bottom:14px;">Informe o código de vínculo do escritório do advogado. Ele encontra o código em <strong>Escritórios → Minha Conta</strong>.</p>
      <div class="es-form-row" style="align-items:flex-end;">
        <div class="es-field">
          <label>Código de vínculo do advogado *</label>
          <input type="text" id="advCodigo" class="es-input" placeholder="Ex: 4799-7d7c-9ca1-96b4" oninput="limparBusca()">
        </div>
        <button class="btn-sm btn-outline" style="height:36px;white-space:nowrap;" onclick="buscarContaAdvogado()">Buscar conta</button>
      </div>
      <div id="advContaResultado" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;font-size:.84rem;"></div>
    </div>

    <!-- Passo 2: selecionar processo (aparece após conta validada) -->
    <div id="adv-passo2" style="display:none;margin-top:18px;border-top:1px solid rgba(160,180,210,.1);padding-top:16px;">
      <div class="es-field" style="margin-bottom:10px;">
        <label>Processo *</label>
        <input type="text" id="advProcessoBusca" class="es-input" placeholder="Digite número ou nome do cliente..." oninput="buscarProcessos(this.value)">
        <div id="advProcessoLista" style="display:none;margin-top:4px;background:rgba(5,18,39,.9);border:1px solid rgba(96,165,250,.2);border-radius:8px;overflow:hidden;max-height:180px;overflow-y:auto;"></div>
        <input type="hidden" id="advProcessoId">
        <div id="advProcessoSelecionado" style="display:none;margin-top:6px;font-size:.82rem;color:#86efac;"></div>
      </div>
      <div class="es-field" style="max-width:200px;">
        <label>Permissão</label>
        <select id="advPermissao" class="es-input">
          <option value="view">Visualizar</option>
          <option value="edit">Editar</option>
        </select>
      </div>
    </div>

    <div class="es-modal-footer">
      <button class="btn-sm btn-outline" onclick="fecharModalConvite()">Cancelar</button>
      <button class="btn-sm btn-primary" id="btnConfirmarAdv" style="display:none;" onclick="confirmarCompartilhamento()">Compartilhar</button>
    </div>
  </div>
</div>

<!-- ── Modal: Liberar Módulo ── -->
<div class="es-overlay" id="modalModulo">
  <div class="es-modal" style="width:560px;">
    <h3>Liberar módulo (aba inteira)</h3>
    <p style="font-size:.82rem;color:#7a96b4;margin-bottom:14px">A pessoa/conta destino verá os <strong>seus dados</strong> nesse módulo (ex: seus processos, sua dashboard). Use o vínculo de processo específico se quiser liberar apenas 1 processo.</p>

    <div class="es-field" style="margin-bottom:12px">
      <label>Módulo *</label>
      <select id="moduleKey" class="es-input">
        <option value="processos">Processos</option>
        <option value="juridico">Jurídico (métricas/prazos)</option>
        <option value="dashboard">Dashboard</option>
        <option value="planejamento">Planejamento</option>
        <option value="prospeccao">Prospecção</option>
        <option value="financas">Finanças</option>
        <option value="tarefas">Tarefas</option>
        <option value="chat">WhatsApp</option>
        <option value="chat_interno">Chat Interno</option>
      </select>
    </div>

    <div class="es-form-row" style="align-items:flex-end;margin-bottom:0">
      <div class="es-field">
        <label>Código *</label>
        <input type="text" id="moduleCodigo" class="es-input" placeholder="Código de vínculo (conta) ou ADV-XXXXXX (advogado)">
      </div>
      <button class="btn-sm btn-outline" style="height:36px;white-space:nowrap" onclick="buscarDestinoModulo()">Buscar</button>
    </div>
    <div id="moduleResultado" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;font-size:.84rem"></div>

    <div id="modulePermSection" style="display:none;margin-top:14px">
      <div class="es-field" style="max-width:200px">
        <label>Permissão</label>
        <select id="modulePerm" class="es-input">
          <option value="view">Visualizar</option>
          <option value="edit">Editar</option>
          <option value="full">Acesso total</option>
        </select>
      </div>
    </div>

    <div class="es-modal-footer">
      <button class="btn-sm btn-outline" onclick="document.getElementById('modalModulo').classList.remove('open')">Cancelar</button>
      <button class="btn-sm btn-primary" id="moduleConfirmBtn" style="display:none" onclick="confirmarLiberarModulo()">Liberar</button>
    </div>
  </div>
</div>

<!-- ── Modal: Sincronização da Filial ──
     Permite à matriz controlar quais dados puxar de uma filial vinculada.
     - sync_enabled : toggle mestre (se desligado, a filial fica invisível)
     - sync_cards / sync_processos / sync_tarefas : flags individuais por módulo -->
<div class="es-overlay" id="modalSync">
  <div class="es-modal">
    <h3>Sincronização — <span id="syncFilialNome" style="color:#93c5fd"></span></h3>
    <p style="font-size:.83rem;color:#7a96b4;margin-bottom:14px;">
      Controle quais dados da filial são puxados para a matriz. As alterações são imediatas.
    </p>

    <!-- Toggle mestre -->
    <label style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border:1px solid rgba(96,165,250,.18);border-radius:10px;background:rgba(5,18,39,.4);margin-bottom:12px;cursor:pointer">
      <div>
        <div style="font-weight:700;color:#dbe9ff">Sincronização ativa</div>
        <div style="font-size:.76rem;color:#7a96b4;margin-top:2px">Quando desligada, esta filial fica invisível para a matriz em todos os módulos.</div>
      </div>
      <input type="checkbox" id="syncEnabled" style="width:18px;height:18px;cursor:pointer;accent-color:#2563eb">
    </label>

    <!-- Flags individuais -->
    <div id="syncModulesGroup" style="border:1px solid rgba(160,180,210,.12);border-radius:10px;padding:8px 4px;margin-bottom:14px">
      <div style="font-size:.74rem;color:#7a96b4;padding:4px 12px 8px;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Módulos a sincronizar</div>
      <label style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 14px;cursor:pointer">
        <span style="color:#dbe9ff">Cards / Leads (Prospecção)</span>
        <input type="checkbox" id="syncCards" style="width:16px;height:16px;cursor:pointer;accent-color:#2563eb">
      </label>
      <label style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 14px;cursor:pointer">
        <span style="color:#dbe9ff">Processos</span>
        <input type="checkbox" id="syncProcessos" style="width:16px;height:16px;cursor:pointer;accent-color:#2563eb">
      </label>
      <label style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 14px;cursor:pointer">
        <span style="color:#dbe9ff">Tarefas</span>
        <input type="checkbox" id="syncTarefas" style="width:16px;height:16px;cursor:pointer;accent-color:#2563eb">
      </label>
      <!-- Atalho "Tudo" -->
      <div style="padding:6px 14px 8px;border-top:1px solid rgba(160,180,210,.08);margin-top:4px;display:flex;gap:8px">
        <button type="button" class="btn-sm" style="background:transparent;border:1px solid rgba(96,165,250,.3);color:#93c5fd;font-size:.72rem" onclick="syncSetAll(true)">Marcar tudo</button>
        <button type="button" class="btn-sm" style="background:transparent;border:1px solid rgba(160,180,210,.2);color:#9ab0c9;font-size:.72rem" onclick="syncSetAll(false)">Desmarcar tudo</button>
      </div>
    </div>

    <div id="syncMsg" style="margin-top:8px;font-size:.82rem;"></div>
    <div class="es-modal-footer">
      <button class="btn-sm btn-outline" onclick="document.getElementById('modalSync').classList.remove('open')">Cancelar</button>
      <button class="btn-sm btn-primary" onclick="salvarSync()">Salvar</button>
    </div>
  </div>
</div>

<!-- ── Modal: Compartilhar WhatsApp com a Filial (a MATRIZ decide) ── -->
<div class="es-overlay" id="modalWaShare">
  <div class="es-modal">
    <h3>WhatsApp — <span id="waShareFilialNome" style="color:#93c5fd"></span></h3>
    <p style="font-size:.83rem;color:#7a96b4;margin-bottom:14px;">
      Você decide se esta filial usa o WhatsApp da sua matriz e o que ela pode fazer. Apagar mensagens e administrar o canal (conectar, QR, desconectar) continuam só com a matriz.
    </p>
    <input type="hidden" id="waShareFilialId">

    <label style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border:1px solid rgba(34,197,94,.18);border-radius:10px;background:rgba(5,18,39,.4);margin-bottom:12px;cursor:pointer">
      <div>
        <div style="font-weight:700;color:#dbe9ff">Compartilhar meu WhatsApp</div>
        <div style="font-size:.76rem;color:#7a96b4;margin-top:2px">Quando ligado, esta filial vê e atende pelo mesmo canal da matriz, sem número próprio.</div>
      </div>
      <input type="checkbox" id="waShareEnabled" onchange="waShareToggle()" style="width:18px;height:18px;cursor:pointer;accent-color:#16a34a">
    </label>

    <div id="waSharePermsGroup" style="border:1px solid rgba(160,180,210,.12);border-radius:10px;padding:8px 4px;margin-bottom:12px">
      <div style="font-size:.74rem;color:#7a96b4;padding:4px 12px 8px;font-weight:600;text-transform:uppercase;letter-spacing:.05em">O que a filial pode fazer</div>
      <label style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 14px;cursor:pointer">
        <span style="color:#dbe9ff">Ver conversas</span>
        <input type="checkbox" id="waPermView" style="width:16px;height:16px;cursor:pointer;accent-color:#16a34a">
      </label>
      <label style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 14px;cursor:pointer">
        <span style="color:#dbe9ff">Enviar mensagens</span>
        <input type="checkbox" id="waPermSend" style="width:16px;height:16px;cursor:pointer;accent-color:#16a34a">
      </label>
      <label style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 14px;cursor:pointer">
        <span style="color:#dbe9ff">Sincronizar (puxar histórico)</span>
        <input type="checkbox" id="waPermSync" style="width:16px;height:16px;cursor:pointer;accent-color:#16a34a">
      </label>
    </div>

    <div id="waShareFlagNote" style="display:none;font-size:.78rem;color:#fbbf24;background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.25);border-radius:8px;padding:8px 12px;margin-bottom:8px">
      O recurso de compartilhamento ainda não foi ativado no servidor. Sua escolha fica salva e passa a valer assim que for ativado.
    </div>

    <div id="waShareMsg" style="margin-top:4px;font-size:.82rem;"></div>
    <div class="es-modal-footer">
      <button class="btn-sm btn-outline" onclick="document.getElementById('modalWaShare').classList.remove('open')">Cancelar</button>
      <button class="btn-sm btn-primary" onclick="salvarWaShare()">Salvar</button>
    </div>
  </div>
</div>

<!-- ── Modal: Vincular Advogado Associado (vinculo de conta) ── -->
<div class="es-overlay" id="modalVincularAdv">
  <div class="es-modal">
    <h3>Vincular Advogado Associado</h3>
    <p style="font-size:.83rem;color:#7a96b4;margin-bottom:14px;">
      Informe o código do advogado solo. Aceita 3 formatos:
    </p>
    <ul style="font-size:.78rem;color:#7a96b4;margin:0 0 14px 18px;line-height:1.7;">
      <li><strong>ADV-XXXXXX</strong> — código universal do advogado (em <em>Usuários</em> dele)</li>
      <li><strong>xxxx-xxxx-xxxx-xxxx</strong> (user) — código pessoal do user advogado</li>
      <li><strong>xxxx-xxxx-xxxx-xxxx</strong> (conta) — código de vínculo da conta dele em <em>Escritórios → Minha Conta</em></li>
    </ul>
    <div class="es-field">
      <label>Código *</label>
      <input type="text" id="inputCodigoAdvogado" class="es-input" placeholder="Ex: ADV-3504EB ou d6fe-a916-08c0-fea8">
    </div>
    <div id="vincularAdvMsg" style="margin-top:8px;font-size:.82rem;"></div>
    <div class="es-modal-footer">
      <button class="btn-sm btn-outline" onclick="document.getElementById('modalVincularAdv').classList.remove('open')">Cancelar</button>
      <button class="btn-sm btn-primary" onclick="vincularAdvogado()">Vincular</button>
    </div>
  </div>
</div>

<!-- ── Modal: Sincronização do Advogado ──
     Clone do modal de filial. Permite à host (matriz/filial) controlar quais
     dados puxar do advogado vinculado.
     - sync_enabled : toggle mestre
     - sync_cards / sync_processos / sync_tarefas : flags individuais -->
<div class="es-overlay" id="modalSyncAdv">
  <div class="es-modal">
    <h3>Sincronização — <span id="syncAdvNome" style="color:#93c5fd"></span></h3>
    <p style="font-size:.83rem;color:#7a96b4;margin-bottom:14px;">
      Controle quais dados do advogado são puxados para sua conta. As alterações são imediatas.
    </p>

    <label style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border:1px solid rgba(96,165,250,.18);border-radius:10px;background:rgba(5,18,39,.4);margin-bottom:12px;cursor:pointer">
      <div>
        <div style="font-weight:700;color:#dbe9ff">Sincronização ativa</div>
        <div style="font-size:.76rem;color:#7a96b4;margin-top:2px">Quando desligada, este advogado fica invisível para sua conta em todos os módulos.</div>
      </div>
      <input type="checkbox" id="syncAdvEnabled" style="width:18px;height:18px;cursor:pointer;accent-color:#2563eb">
    </label>

    <div id="syncAdvModulesGroup" style="border:1px solid rgba(160,180,210,.12);border-radius:10px;padding:8px 4px;margin-bottom:14px">
      <div style="font-size:.74rem;color:#7a96b4;padding:4px 12px 8px;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Módulos a sincronizar</div>
      <label style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 14px;cursor:pointer">
        <span style="color:#dbe9ff">Cards / Leads (Prospecção)</span>
        <input type="checkbox" id="syncAdvCards" style="width:16px;height:16px;cursor:pointer;accent-color:#2563eb">
      </label>
      <label style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 14px;cursor:pointer">
        <span style="color:#dbe9ff">Processos</span>
        <input type="checkbox" id="syncAdvProcessos" style="width:16px;height:16px;cursor:pointer;accent-color:#2563eb">
      </label>
      <label style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 14px;cursor:pointer">
        <span style="color:#dbe9ff">Tarefas</span>
        <input type="checkbox" id="syncAdvTarefas" style="width:16px;height:16px;cursor:pointer;accent-color:#2563eb">
      </label>
      <div style="padding:6px 14px 8px;border-top:1px solid rgba(160,180,210,.08);margin-top:4px;display:flex;gap:8px">
        <button type="button" class="btn-sm" style="background:transparent;border:1px solid rgba(96,165,250,.3);color:#93c5fd;font-size:.72rem" onclick="syncAdvSetAll(true)">Marcar tudo</button>
        <button type="button" class="btn-sm" style="background:transparent;border:1px solid rgba(160,180,210,.2);color:#9ab0c9;font-size:.72rem" onclick="syncAdvSetAll(false)">Desmarcar tudo</button>
      </div>
    </div>

    <div id="syncAdvMsg" style="margin-top:8px;font-size:.82rem;"></div>
    <div class="es-modal-footer">
      <button class="btn-sm btn-outline" onclick="document.getElementById('modalSyncAdv').classList.remove('open')">Cancelar</button>
      <button class="btn-sm btn-primary" onclick="salvarSyncAdv()">Salvar</button>
    </div>
  </div>
</div>

<!-- ── Modal: Editar permissão de compartilhamento pontual ── -->
<div class="es-overlay" id="modalEditShare">
  <div class="es-modal">
    <h3>Editar permissão</h3>
    <div id="editShareInfo" class="edit-share-info"></div>

    <div class="es-field">
      <label>Nível de permissão</label>
      <select id="editSharePerm" class="es-input">
        <option value="view">Visualizar — só lê dados, não altera nada</option>
        <option value="edit">Editar — pode alterar campos do recurso</option>
        <option value="full">Acesso total — pode editar e revogar/transferir</option>
      </select>
    </div>

    <div id="editShareMsg" style="margin-top:8px;font-size:.82rem;"></div>
    <div class="es-modal-footer">
      <button class="btn-sm btn-outline" onclick="document.getElementById('modalEditShare').classList.remove('open')">Cancelar</button>
      <button class="btn-sm btn-primary" onclick="salvarEditarShare()">Salvar</button>
    </div>
  </div>
</div>

<!-- ── Modal: Solicitar Vínculo ── -->
<div class="es-overlay" id="modalSolicitar">
  <div class="es-modal">
    <h3>Solicitar Vínculo com Matriz</h3>
    <p style="font-size:.83rem;color:#7a96b4;margin-bottom:16px;">Informe o código fornecido pelo escritório Matriz.</p>
    <div class="es-field">
      <label>Código de vínculo</label>
      <input type="text" id="inputCodigoModal" class="es-input" placeholder="Ex: a1b2c3d4e5f6...">
    </div>
    <div id="solicitarModalMsg" style="margin-top:8px;font-size:.82rem;"></div>
    <div class="es-modal-footer">
      <button class="btn-sm btn-outline" onclick="document.getElementById('modalSolicitar').classList.remove('open')">Cancelar</button>
      <button class="btn-sm btn-primary" onclick="solicitarVinculoModal()">Solicitar</button>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const ACCOUNT_TIPO = <?= json_encode($accountTipo) ?>;
const ACCOUNT_ID = <?= json_encode($accountId) ?>;
// Fase 2: estado do compartilhamento de WhatsApp da matriz (preenchido em carregarVinculos)
let _waShare = { has_channel:false, sharing_enabled:false, map:{} };

const api = (url, opts = {}) => fetch(url, {
  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
  ...opts
}).then(r => r.json());

// ── Carregar conta ────────────────────────────────────────────────────────────
let _contaData = null;
async function carregarConta() {
  const r = await api('/api/accounts.php');
  const c = r.data || r;
  if (!c || !c.nome) return;
  _contaData = c;
  document.getElementById('cNome').textContent   = c.nome || '—';
  document.getElementById('cTipo').innerHTML     = `<span class="badge badge-${c.tipo}">${c.tipo}</span>`;
  document.getElementById('cPlano').textContent  = c.plano || 'basico';
  document.getElementById('cStatus').innerHTML   = `<span class="badge badge-${c.status}">${c.status}</span>`;

  if (IS_ADMIN) {
    const rc = await api('/api/accounts.php?action=codigo');
    const codigo = rc.codigo_vinculo || rc.data?.codigo_vinculo || '—';
    document.getElementById('codigoVinculo').textContent = codigo;
  }
}

function toggleEditConta() {
  const view = document.getElementById('contaView');
  const edit = document.getElementById('contaEdit');
  const isEditing = edit.style.display !== 'none';
  if (!isEditing && _contaData) {
    document.getElementById('editNome').value  = _contaData.nome  || '';
    document.getElementById('editTipo').value  = _contaData.tipo  || 'matriz';
    document.getElementById('editPlano').value = _contaData.plano || 'basico';
  }
  view.style.display = isEditing ? '' : 'none';
  edit.style.display = isEditing ? 'none' : '';
}

async function salvarConta() {
  const payload = {
    nome:        document.getElementById('editNome').value.trim(),
    tipo:        document.getElementById('editTipo').value,
    plano:       document.getElementById('editPlano').value,
    csrf_token:  CSRF,
  };
  if (!payload.nome) { toast('Nome obrigatório.', 'err'); return; }
  const r = await api('/api/accounts.php', {
    method: 'PUT', body: JSON.stringify(payload)
  });
  if (r.success || r.ok) {
    toast('Conta atualizada!', 'ok');
    toggleEditConta();
    carregarConta();
  } else {
    toast(r.error || 'Erro ao salvar.', 'err');
  }
}

function copiarCodigo() {
  const txt = document.getElementById('codigoVinculo').textContent;
  navigator.clipboard.writeText(txt).then(() => {
    const btn = document.querySelector('.copy-btn');
    btn.textContent = 'Copiado!'; setTimeout(() => btn.textContent = 'Copiar', 2000);
  });
}

// ── Tabs ─────────────────────────────────────────────────────────────────────
// Switch usa data-tab (nao posicao). Funciona mesmo quando algumas tabs sao
// escondidas via PHP (advogado nao ve "Vinculos" matriz<->filial).
function switchTab(name) {
  // Toggle visual nas tabs — match por data-tab
  document.querySelectorAll('.es-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.tab === name);
  });
  // Mostra só o pane correspondente
  document.querySelectorAll('.es-pane').forEach(p => p.classList.remove('active'));
  document.getElementById('pane-' + name)?.classList.add('active');

  // Dispara loaders só quando entra na aba (lazy)
  if (name === 'vinculos')          carregarVinculos();
  if (name === 'advogados') {
    carregarAdvogadosVinculos();
    if (ACCOUNT_TIPO !== 'advogado') carregarAdvogados(); // resource_shares legado
  }
  if (name === 'compartilhamentos') carregarShares();
  if (name === 'modulos')           carregarModulos();
  if (name === 'monitoramentos')    carregarMonitoramentos();
}

// ── Vínculos ─────────────────────────────────────────────────────────────────
async function carregarVinculos() {
  const el = document.getElementById('vinculosList');
  el.innerHTML = '<div class="es-empty">Carregando...</div>';
  const r = await api('/api/account_vinculos.php');
  const lista = r.data || [];

  // Estado do compartilhamento de WhatsApp da matriz (best-effort; nunca quebra a lista)
  _waShare = { has_channel:false, sharing_enabled:false, map:{} };
  if (IS_ADMIN) {
    try {
      const w = await api('/api/whatsapp/share.php');
      const wd = (w && w.data) || {};
      _waShare.has_channel = !!wd.has_channel;
      _waShare.sharing_enabled = !!wd.sharing_enabled;
      (wd.filiais || []).forEach(f => { _waShare.map[parseInt(f.account_id)] = f; });
    } catch (e) { /* segue sem o controle de WhatsApp */ }
  }

  if (!lista.length) {
    el.innerHTML = `<div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>Nenhum vínculo cadastrado.</div>`;
    return;
  }
  el.innerHTML = `<table class="es-table">
    <thead><tr><th>Tipo</th><th>Escritório</th><th>Status</th><th>Desde</th>${IS_ADMIN ? '<th>Ações</th>' : ''}</tr></thead>
    <tbody>${lista.map(v => {
      // Sou matriz nesta relação? compara o account_id da sessão com o lado matriz do vínculo
      const iAmMatriz = parseInt(v.matriz_account_id) === parseInt(ACCOUNT_ID);
      const outro = iAmMatriz ? (v.filial_nome || `Conta #${v.filial_account_id}`) : (v.matriz_nome || `Conta #${v.matriz_account_id}`);
      const tipoOutro = iAmMatriz ? 'Filial' : 'Matriz';
      // Resumo dos flags de sincronização (só mostrado quando sou matriz nesse vínculo)
      let syncBadge = '';
      if (iAmMatriz && v.status === 'active') {
        const syncOn = parseInt(v.sync_enabled ?? 1) === 1;
        const mods = [];
        if (parseInt(v.sync_cards ?? 1)     === 1) mods.push('Cards');
        if (parseInt(v.sync_processos ?? 1) === 1) mods.push('Processos');
        if (parseInt(v.sync_tarefas ?? 1)   === 1) mods.push('Tarefas');
        if (syncOn) {
          syncBadge = `<span class="badge badge-sync-on" title="Sincronizando: ${mods.join(', ') || 'nenhum módulo'}" style="font-size:.7rem;background:rgba(34,197,94,.18);color:#86efac;border:1px solid rgba(34,197,94,.3);padding:2px 8px;border-radius:6px;font-weight:600">Sincronizado · ${mods.length}/3</span>`;
        } else {
          syncBadge = `<span class="badge" title="Sincronização desligada — não puxa nenhum dado da filial" style="font-size:.7rem;background:rgba(160,180,210,0.1);color:#9ab0c9;border:1px solid rgba(160,180,210,0.2);padding:2px 8px;border-radius:6px;font-weight:600">Sync desligado</span>`;
        }
      }

      // Badge "WhatsApp compartilhado" (lado matriz) — só quando a matriz tem canal
      let waBadge = '';
      if (iAmMatriz && v.status === 'active' && _waShare.has_channel) {
        const stWa = _waShare.map[parseInt(v.filial_account_id)];
        if (stWa && stWa.shared) {
          waBadge = `<span class="badge" title="Seu WhatsApp está compartilhado com esta filial" style="font-size:.7rem;background:rgba(34,197,94,.18);color:#86efac;border:1px solid rgba(34,197,94,.3);padding:2px 8px;border-radius:6px;font-weight:600">WhatsApp ✓</span>`;
        }
      }

      const acoes = IS_ADMIN ? `
        ${v.status === 'pending' && iAmMatriz ? `<button class="btn-sm btn-success" onclick="aprovarVinculo(${v.id})">Aprovar</button> <button class="btn-sm btn-danger" onclick="rejeitarVinculo(${v.id})">Rejeitar</button>` : ''}
        ${v.status === 'active' && iAmMatriz ? `<button class="btn-sm" style="background:rgba(37,99,235,.18);color:#93c5fd;border:1px solid rgba(37,99,235,.3)" onclick='abrirSyncModal(${JSON.stringify(v)})'>Sincronização</button>` : ''}
        ${v.status === 'active' && iAmMatriz && _waShare.has_channel ? `<button class="btn-sm" style="background:rgba(34,197,94,.16);color:#86efac;border:1px solid rgba(34,197,94,.3)" onclick='abrirWaShareModal(${parseInt(v.filial_account_id)}, ${JSON.stringify(outro)})'>WhatsApp</button>` : ''}
        ${v.status === 'active' && iAmMatriz ? `<button class="btn-sm btn-danger" onclick="suspenderVinculo(${v.id})">Suspender</button>` : ''}
        ${v.status === 'pending' && !iAmMatriz ? '<span style="color:#7a96b4;font-size:.78rem">Aguardando aprovação da matriz</span>' : ''}
      ` : '';
      return `<tr>
        <td><span class="badge badge-${tipoOutro.toLowerCase()}">${tipoOutro}</span></td>
        <td>${outro}</td>
        <td><span class="badge badge-${v.status}">${v.status}</span>${syncBadge ? ' ' + syncBadge : ''}${waBadge ? ' ' + waBadge : ''}</td>
        <td style="color:#4a5568">${(v.created_at||'').slice(0,10)}</td>
        ${IS_ADMIN ? `<td style="display:flex;gap:6px;flex-wrap:wrap">${acoes}</td>` : ''}
      </tr>`;
    }).join('')}</tbody>
  </table>`;
}

// ── WhatsApp: a matriz compartilha o PRÓPRIO canal com a filial ──────────────
function abrirWaShareModal(filialId, filialNome) {
  if (!_waShare.has_channel) { toast('Configure o WhatsApp da matriz primeiro (Comunicação → Chat WhatsApp).', 'err'); return; }
  const st = _waShare.map[parseInt(filialId)] || { shared:false, perms:{can_view:1,can_send:1,can_sync:1} };
  const p  = st.perms || {can_view:1,can_send:1,can_sync:1};
  document.getElementById('waShareFilialId').value = filialId;
  document.getElementById('waShareFilialNome').textContent = filialNome || ('Filial #' + filialId);
  document.getElementById('waShareEnabled').checked = !!st.shared;
  document.getElementById('waPermView').checked = parseInt(p.can_view ?? 1) === 1;
  document.getElementById('waPermSend').checked = parseInt(p.can_send ?? 1) === 1;
  document.getElementById('waPermSync').checked = parseInt(p.can_sync ?? 1) === 1;
  const note = document.getElementById('waShareFlagNote');
  if (note) note.style.display = _waShare.sharing_enabled ? 'none' : 'block';
  document.getElementById('waShareMsg').textContent = '';
  waShareToggle();
  document.getElementById('modalWaShare').classList.add('open');
}
function waShareToggle() {
  const on  = document.getElementById('waShareEnabled').checked;
  const grp = document.getElementById('waSharePermsGroup');
  grp.style.opacity = on ? '1' : '.45';
  grp.style.pointerEvents = on ? 'auto' : 'none';
}
async function salvarWaShare() {
  const filialId = parseInt(document.getElementById('waShareFilialId').value);
  const on = document.getElementById('waShareEnabled').checked;
  let r;
  if (!on) {
    r = await api('/api/whatsapp/share.php', { method:'POST', body: JSON.stringify({ action:'revoke', filial_account_id: filialId, csrf_token: CSRF }) });
  } else {
    const perms = {
      can_view: document.getElementById('waPermView').checked ? 1 : 0,
      can_send: document.getElementById('waPermSend').checked ? 1 : 0,
      can_sync: document.getElementById('waPermSync').checked ? 1 : 0,
    };
    if (!perms.can_view && !perms.can_send && !perms.can_sync) {
      document.getElementById('waShareMsg').innerHTML = '<span style="color:#fca5a5">Marque ao menos uma permissão, ou desligue o compartilhamento.</span>';
      return;
    }
    r = await api('/api/whatsapp/share.php', { method:'POST', body: JSON.stringify({ action:'grant', filial_account_id: filialId, perms, csrf_token: CSRF }) });
  }
  if (r && (r.ok || r.success)) {
    toast((r.data && r.data.message) || 'Compartilhamento atualizado.', 'ok');
    document.getElementById('modalWaShare').classList.remove('open');
    carregarVinculos();
  } else {
    document.getElementById('waShareMsg').innerHTML = `<span style="color:#fca5a5">${(r && r.error) || 'Erro ao salvar.'}</span>`;
  }
}

async function aprovarVinculo(id) {
  const r = await api('/api/account_vinculos.php', {
    method: 'PATCH', body: JSON.stringify({ id, action: 'aprovar', csrf_token: CSRF })
  });
  (r.success || r.ok) ? (toast('Vínculo aprovado!', 'ok'), carregarVinculos()) : toast(r.error || 'Erro', 'err');
}
async function rejeitarVinculo(id) {
  const r = await api('/api/account_vinculos.php', {
    method: 'PATCH', body: JSON.stringify({ id, action: 'rejeitar', csrf_token: CSRF })
  });
  (r.success || r.ok) ? (toast('Vínculo rejeitado.', 'ok'), carregarVinculos()) : toast(r.error || 'Erro', 'err');
}
async function suspenderVinculo(id) {
  const motivo = await Yuris.prompt('Motivo da suspensão:', {
    title: 'Suspender vínculo',
    placeholder: 'Opcional — explique brevemente o motivo',
    okLabel: 'Suspender',
  });
  if (motivo === null) return;   // usuário cancelou
  const r = await api('/api/account_vinculos.php', {
    method: 'PATCH', body: JSON.stringify({ id, action: 'suspender', motivo, csrf_token: CSRF })
  });
  (r.success || r.ok) ? (toast('Vínculo suspenso.', 'ok'), carregarVinculos()) : toast(r.error || 'Erro', 'err');
}

// ── Sincronização granular por vínculo (Matriz↔Filial) ───────────────────────
let _syncVinculoId = null;
function abrirSyncModal(v) {
  _syncVinculoId = v.id;
  document.getElementById('syncFilialNome').textContent = v.filial_nome || `Conta #${v.filial_account_id}`;
  document.getElementById('syncEnabled').checked     = parseInt(v.sync_enabled ?? 1)   === 1;
  document.getElementById('syncCards').checked       = parseInt(v.sync_cards ?? 1)     === 1;
  document.getElementById('syncProcessos').checked   = parseInt(v.sync_processos ?? 1) === 1;
  document.getElementById('syncTarefas').checked     = parseInt(v.sync_tarefas ?? 1)   === 1;
  document.getElementById('syncMsg').textContent = '';
  _syncToggleModulesUI();
  document.getElementById('modalSync').classList.add('open');
}
function syncSetAll(value) {
  document.getElementById('syncCards').checked     = value;
  document.getElementById('syncProcessos').checked = value;
  document.getElementById('syncTarefas').checked   = value;
}
// Quando sync_enabled está OFF, desabilita visualmente os módulos individuais
function _syncToggleModulesUI() {
  const on = document.getElementById('syncEnabled').checked;
  const grp = document.getElementById('syncModulesGroup');
  grp.style.opacity      = on ? '1' : '.45';
  grp.style.pointerEvents= on ? 'auto' : 'none';
}
document.addEventListener('change', e => {
  if (e.target && e.target.id === 'syncEnabled') _syncToggleModulesUI();
});
async function salvarSync() {
  if (!_syncVinculoId) return;
  const payload = {
    id: _syncVinculoId,
    action: 'update_sync',
    sync_enabled:   document.getElementById('syncEnabled').checked   ? 1 : 0,
    sync_cards:     document.getElementById('syncCards').checked     ? 1 : 0,
    sync_processos: document.getElementById('syncProcessos').checked ? 1 : 0,
    sync_tarefas:   document.getElementById('syncTarefas').checked   ? 1 : 0,
    csrf_token: CSRF
  };
  const r = await api('/api/account_vinculos.php', {
    method: 'PATCH', body: JSON.stringify(payload)
  });
  if (r.success || r.ok) {
    toast('Sincronização atualizada.', 'ok');
    document.getElementById('modalSync').classList.remove('open');
    carregarVinculos();
  } else {
    document.getElementById('syncMsg').innerHTML = `<span style="color:#fca5a5">${r.error || 'Erro ao salvar.'}</span>`;
  }
}

function abrirSolicitarVinculo() {
  document.getElementById('modalSolicitar').classList.add('open');
}
async function solicitarVinculoModal() {
  const codigo = document.getElementById('inputCodigoModal').value.trim();
  const msg    = document.getElementById('solicitarModalMsg');
  if (!codigo) { msg.innerHTML = '<span style="color:#fca5a5">Informe o código.</span>'; return; }
  const r = await api('/api/account_vinculos.php', {
    method: 'POST', body: JSON.stringify({ codigo_vinculo: codigo, csrf_token: CSRF })
  });
  if (r.success || r.ok) {
    msg.innerHTML = '<span style="color:#86efac">Solicitação enviada! Aguarde aprovação da Matriz.</span>';
    setTimeout(() => { document.getElementById('modalSolicitar').classList.remove('open'); carregarVinculos(); }, 1800);
  } else {
    msg.innerHTML = `<span style="color:#fca5a5">${r.error || 'Erro ao solicitar.'}</span>`;
  }
}

// Versão usada pela aba "Solicitar Vínculo" (input inline, fora do modal)
async function solicitarVinculo() {
  const codigo = document.getElementById('inputCodigoMatriz').value.trim();
  const msg    = document.getElementById('solicitarMsg');
  if (!codigo) { msg.innerHTML = '<span style="color:#fca5a5">Informe o código.</span>'; return; }
  msg.innerHTML = '<span style="color:#7a96b4">Enviando...</span>';
  const r = await api('/api/account_vinculos.php', {
    method: 'POST', body: JSON.stringify({ codigo_vinculo: codigo, csrf_token: CSRF })
  });
  if (r.success || r.ok) {
    msg.innerHTML = '<span style="color:#86efac">Solicitação enviada! Aguarde aprovação da Matriz.</span>';
    document.getElementById('inputCodigoMatriz').value = '';
    setTimeout(() => { switchTab('vinculos'); }, 1500);
  } else {
    msg.innerHTML = `<span style="color:#fca5a5">${r.error || 'Erro ao solicitar.'}</span>`;
  }
}

// ════════════════════════════════════════════════════════════════════════════
// Vínculos de CONTA com advogados (advogado_vinculos) — analogo a matriz↔filial
// ════════════════════════════════════════════════════════════════════════════
async function carregarAdvogadosVinculos() {
  const el = document.getElementById('advogadosVinculosList');
  if (!el) return;
  el.innerHTML = '<div class="es-empty">Carregando...</div>';
  const r = await api('/api/advogado_vinculos.php');
  const lista = r.data || [];
  if (!lista.length) {
    if (ACCOUNT_TIPO === 'advogado') {
      el.innerHTML = `<div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Nenhuma matriz/filial te vinculou ainda.</div>`;
    } else {
      el.innerHTML = `<div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Nenhum advogado vinculado. Clique em "Vincular Advogado" para começar.</div>`;
    }
    return;
  }

  // Layout depende do papel: se sou advogado, mostro quem me vinculou (host).
  // Se sou matriz/filial, mostro os advogados vinculados.
  const iAmAdvogado = ACCOUNT_TIPO === 'advogado';

  el.innerHTML = `<table class="es-table">
    <thead><tr>
      <th>${iAmAdvogado ? 'Vinculado por' : 'Advogado'}</th>
      <th>Status</th>
      <th>Desde</th>
      ${IS_ADMIN && !iAmAdvogado ? '<th>Ações</th>' : ''}
    </tr></thead>
    <tbody>${lista.map(v => {
      const nome = iAmAdvogado
        ? (v.host_nome || `Conta #${v.host_account_id}`) + (v.host_tipo === 'filial' ? ' (Filial)' : (v.host_tipo === 'matriz' ? ' (Matriz)' : ''))
        : (v.advogado_nome || `Conta #${v.advogado_account_id}`) + (v.advogado_oab ? ' · OAB ' + v.advogado_oab : '');

      // Badge de sync — só faz sentido quando vínculo está active
      let syncBadge = '';
      if (v.status === 'active') {
        const syncOn = parseInt(v.sync_enabled ?? 1) === 1;
        const mods = [];
        if (parseInt(v.sync_cards ?? 1)     === 1) mods.push('Cards');
        if (parseInt(v.sync_processos ?? 1) === 1) mods.push('Processos');
        if (parseInt(v.sync_tarefas ?? 1)   === 1) mods.push('Tarefas');
        if (syncOn) {
          syncBadge = `<span class="badge" style="font-size:.7rem;background:rgba(34,197,94,.18);color:#86efac;border:1px solid rgba(34,197,94,.3);padding:2px 8px;border-radius:6px;font-weight:600">Sincronizado · ${mods.length}/3</span>`;
        } else {
          syncBadge = `<span class="badge" style="font-size:.7rem;background:rgba(160,180,210,0.1);color:#9ab0c9;border:1px solid rgba(160,180,210,0.2);padding:2px 8px;border-radius:6px;font-weight:600">Sync desligado</span>`;
        }
      }

      // Ações só pro host (matriz/filial), não pro advogado
      const acoes = (IS_ADMIN && !iAmAdvogado) ? `
        ${v.status === 'active' ? `<button class="btn-sm" style="background:rgba(37,99,235,.18);color:#93c5fd;border:1px solid rgba(37,99,235,.3)" onclick='abrirSyncModalAdv(${JSON.stringify(v)})'>Sincronização</button>` : ''}
        ${v.status === 'active' ? `<button class="btn-sm btn-danger" onclick="suspenderVinculoAdv(${v.id})">Suspender</button>` : ''}
        ${v.status === 'suspended' ? `<button class="btn-sm btn-success" onclick="reativarVinculoAdv(${v.id})">Reativar</button>` : ''}
      ` : '';

      return `<tr>
        <td>${nome}</td>
        <td><span class="badge badge-${v.status}">${v.status}</span>${syncBadge ? ' ' + syncBadge : ''}</td>
        <td style="color:#4a5568">${(v.created_at||'').slice(0,10)}</td>
        ${(IS_ADMIN && !iAmAdvogado) ? `<td style="display:flex;gap:6px;flex-wrap:wrap">${acoes}</td>` : ''}
      </tr>`;
    }).join('')}</tbody>
  </table>`;
}

function abrirModalVincularAdv() {
  document.getElementById('inputCodigoAdvogado').value = '';
  document.getElementById('vincularAdvMsg').textContent = '';
  document.getElementById('modalVincularAdv').classList.add('open');
}

async function vincularAdvogado() {
  const codigo = document.getElementById('inputCodigoAdvogado').value.trim();
  const msg    = document.getElementById('vincularAdvMsg');
  if (!codigo) { msg.innerHTML = '<span style="color:#fca5a5">Informe o código ADV-XXXXXX.</span>'; return; }
  msg.innerHTML = '<span style="color:#7a96b4">Vinculando...</span>';
  const r = await api('/api/advogado_vinculos.php', {
    method: 'POST', body: JSON.stringify({ codigo_advogado: codigo, csrf_token: CSRF })
  });
  if (r.success || r.ok) {
    msg.innerHTML = `<span style="color:#86efac">Vínculo criado! ${r.advogado?.nome ? '— ' + r.advogado.nome : ''}</span>`;
    setTimeout(() => {
      document.getElementById('modalVincularAdv').classList.remove('open');
      carregarAdvogadosVinculos();
    }, 1200);
  } else {
    msg.innerHTML = `<span style="color:#fca5a5">${r.error || 'Erro ao vincular.'}</span>`;
  }
}

// Modal de sincronização do advogado (clone do modal filial)
let _syncAdvVinculoId = null;
function abrirSyncModalAdv(v) {
  _syncAdvVinculoId = v.id;
  document.getElementById('syncAdvNome').textContent = v.advogado_nome || `Conta #${v.advogado_account_id}`;
  document.getElementById('syncAdvEnabled').checked   = parseInt(v.sync_enabled ?? 1)   === 1;
  document.getElementById('syncAdvCards').checked     = parseInt(v.sync_cards ?? 1)     === 1;
  document.getElementById('syncAdvProcessos').checked = parseInt(v.sync_processos ?? 1) === 1;
  document.getElementById('syncAdvTarefas').checked   = parseInt(v.sync_tarefas ?? 1)   === 1;
  document.getElementById('syncAdvMsg').textContent   = '';
  _syncAdvToggleModulesUI();
  document.getElementById('modalSyncAdv').classList.add('open');
}
function syncAdvSetAll(value) {
  document.getElementById('syncAdvCards').checked     = value;
  document.getElementById('syncAdvProcessos').checked = value;
  document.getElementById('syncAdvTarefas').checked   = value;
}
function _syncAdvToggleModulesUI() {
  const on = document.getElementById('syncAdvEnabled').checked;
  const grp = document.getElementById('syncAdvModulesGroup');
  grp.style.opacity       = on ? '1' : '.45';
  grp.style.pointerEvents = on ? 'auto' : 'none';
}
document.addEventListener('change', e => {
  if (e.target && e.target.id === 'syncAdvEnabled') _syncAdvToggleModulesUI();
});

async function salvarSyncAdv() {
  if (!_syncAdvVinculoId) return;
  const payload = {
    id: _syncAdvVinculoId,
    action: 'update_sync',
    sync_enabled:   document.getElementById('syncAdvEnabled').checked   ? 1 : 0,
    sync_cards:     document.getElementById('syncAdvCards').checked     ? 1 : 0,
    sync_processos: document.getElementById('syncAdvProcessos').checked ? 1 : 0,
    sync_tarefas:   document.getElementById('syncAdvTarefas').checked   ? 1 : 0,
    csrf_token: CSRF
  };
  const r = await api('/api/advogado_vinculos.php', {
    method: 'PATCH', body: JSON.stringify(payload)
  });
  if (r.success || r.ok) {
    toast('Sincronização atualizada.', 'ok');
    document.getElementById('modalSyncAdv').classList.remove('open');
    carregarAdvogadosVinculos();
  } else {
    document.getElementById('syncAdvMsg').innerHTML = `<span style="color:#fca5a5">${r.error || 'Erro ao salvar.'}</span>`;
  }
}

async function suspenderVinculoAdv(id) {
  const motivo = await Yuris.prompt('Motivo da suspensão:', {
    title: 'Suspender vínculo de advogado',
    placeholder: 'Opcional — explique brevemente o motivo',
    okLabel: 'Suspender',
  });
  if (motivo === null) return;
  const r = await api('/api/advogado_vinculos.php', {
    method: 'PATCH', body: JSON.stringify({ id, action: 'suspender', motivo, csrf_token: CSRF })
  });
  (r.success || r.ok) ? (toast('Vínculo suspenso.', 'ok'), carregarAdvogadosVinculos()) : toast(r.error || 'Erro', 'err');
}

async function reativarVinculoAdv(id) {
  const r = await api('/api/advogado_vinculos.php', {
    method: 'PATCH', body: JSON.stringify({ id, action: 'reativar', csrf_token: CSRF })
  });
  (r.success || r.ok) ? (toast('Vínculo reativado.', 'ok'), carregarAdvogadosVinculos()) : toast(r.error || 'Erro', 'err');
}

// ── Advogados Associados (via resource_shares — compartilhamentos pontuais) ──
// Helpers para o card "Compartilhamentos pontuais"
const PERM_LABEL = { view: 'Visualizar', edit: 'Editar', full: 'Acesso total' };
const PERM_COLOR = {
  view: { bg: 'rgba(96,165,250,.18)', fg: '#93c5fd', bd: 'rgba(96,165,250,.32)' },
  edit: { bg: 'rgba(245,158,11,.18)', fg: '#fcd34d', bd: 'rgba(245,158,11,.32)' },
  full: { bg: 'rgba(34,197,94,.18)',  fg: '#86efac', bd: 'rgba(34,197,94,.32)' },
};
const ACCOUNT_TIPO_LABEL = { matriz: 'Matriz', filial: 'Filial', advogado: 'Advogado solo' };
const RES_TIPO_LABEL = { processo: 'Processo', card: 'Card', contato: 'Contato' };
const RES_TIPO_COLOR = {
  processo: { bg: 'rgba(167,139,250,.16)', fg: '#c4b5fd', bd: 'rgba(167,139,250,.30)' },
  card    : { bg: 'rgba(34,197,94,.16)',   fg: '#86efac', bd: 'rgba(34,197,94,.30)' },
  contato : { bg: 'rgba(245,158,11,.16)',  fg: '#fcd34d', bd: 'rgba(245,158,11,.30)' },
};
const RES_TIPO_URL = {
  processo: id => '/processos.php?open=' + id,
  card    : id => '/prospeccao.php?open=' + id,
  contato : id => '/contatos.php?open=' + id,
};

async function carregarAdvogados() {
  const el = document.getElementById('advogadosList');
  if (!el) return; // pane oculto para conta tipo='advogado'
  el.innerHTML = '<div class="es-empty">Carregando...</div>';
  // ?listar_meus=1 lista shares pontuais (processo/card/contato) emitidos por mim.
  const r = await api('/api/resource_shares.php?listar_meus=1');
  const lista = (r.data || r || []).filter(s => s.status === 'active');
  if (!lista.length) {
    el.innerHTML = `<div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Nenhum compartilhamento ativo.</div>`;
    return;
  }
  el.innerHTML = `<table class="es-table">
    <thead><tr>
      <th>Destinatário</th>
      <th>Código</th>
      <th>Recurso</th>
      <th>Permissão</th>
      <th>Desde</th>
      ${IS_ADMIN ? '<th>Ações</th>' : ''}
    </tr></thead>
    <tbody>${lista.map(s => {
      // Destinatário: user (advogado solo) > conta (matriz/filial)
      const isUser = !!s.to_user_id;
      const nome   = isUser ? (s.to_user_nome || `Usuário #${s.to_user_id}`)
                            : (s.to_account_nome || `Conta #${s.to_account_id}`);
      const tipoLbl = isUser
        ? 'Advogado'
        : (ACCOUNT_TIPO_LABEL[s.to_account_tipo] || s.to_account_tipo || 'Conta');

      // Código: prefere ADV (user) > codigo_vinculo (user) > codigo_vinculo (conta)
      const codigo = s.to_user_codigo_adv
                  || s.to_user_codigo_vinculo
                  || s.to_account_codigo
                  || '—';

      // Recurso: badge colorido + link clicável
      const rt    = s.resource_type;
      const rtLbl = RES_TIPO_LABEL[rt] || rt;
      const rLabel= s.resource_label || (rt + ' #' + s.resource_id);
      const rUrl  = (RES_TIPO_URL[rt] && RES_TIPO_URL[rt](s.resource_id)) || '#';

      // Permissão (classe CSS já tematizada via .badge-perm-*)
      const pLbl = PERM_LABEL[s.permission_level] || s.permission_level;

      return `<tr>
        <td>
          <div class="share-dest">
            <span class="share-dest-name">${escapeHtml(nome)}</span>
            <span class="share-dest-tipo">${escapeHtml(tipoLbl)}</span>
          </div>
        </td>
        <td>
          <code class="share-codigo">${escapeHtml(codigo)}</code>
        </td>
        <td>
          <a href="${rUrl}" target="_blank" rel="noopener" class="share-recurso-link">
            <span class="badge badge-res-${rt}">
              ${escapeHtml(rtLbl)}: ${escapeHtml(rLabel)}
            </span>
          </a>
        </td>
        <td>
          <span class="badge badge-perm-${s.permission_level}">${pLbl}</span>
        </td>
        <td class="share-since">${(s.created_at||'').slice(0,10)}</td>
        ${IS_ADMIN ? `<td class="share-actions">
          <button class="btn-sm btn-share-edit" onclick='abrirEditarShare(${JSON.stringify(s).replace(/"/g, "&quot;")})'>Editar</button>
          <button class="btn-sm btn-danger" onclick="revogarShare(${s.id})">Revogar</button>
        </td>` : ''}
      </tr>`;
    }).join('')}</tbody>
  </table>`;
}

// Helper local — escapa HTML pra evitar XSS no nome do destinatário/recurso
function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
  }[c]));
}

// Modal Editar permissão de share pontual
let _editShareId = null;
function abrirEditarShare(s) {
  _editShareId = s.id;
  document.getElementById('editShareInfo').innerHTML = `
    <div style="font-size:.82rem;color:#dbe9ff;margin-bottom:4px">
      <strong>${escapeHtml(s.to_user_nome || s.to_account_nome || 'Destinatário')}</strong>
    </div>
    <div style="font-size:.76rem;color:#7a96b4">
      ${escapeHtml(RES_TIPO_LABEL[s.resource_type] || s.resource_type)}: ${escapeHtml(s.resource_label || '#' + s.resource_id)}
    </div>
  `;
  document.getElementById('editSharePerm').value = s.permission_level || 'view';
  document.getElementById('editShareMsg').textContent = '';
  document.getElementById('modalEditShare').classList.add('open');
}

async function salvarEditarShare() {
  if (!_editShareId) return;
  const perm = document.getElementById('editSharePerm').value;
  const msg  = document.getElementById('editShareMsg');
  msg.innerHTML = '<span style="color:#7a96b4">Salvando...</span>';
  const r = await api('/api/resource_shares.php', {
    method: 'PATCH',
    body: JSON.stringify({ id: _editShareId, permission_level: perm, csrf_token: CSRF }),
  });
  if (r.success || r.ok) {
    toast('Permissão atualizada.', 'ok');
    document.getElementById('modalEditShare').classList.remove('open');
    carregarAdvogados();
  } else {
    msg.innerHTML = `<span style="color:#fca5a5">${r.error || 'Erro ao salvar.'}</span>`;
  }
}

function abrirModalConvite() {
  _advContaDestino = null;
  document.getElementById('advCodigo').value = '';
  document.getElementById('advContaResultado').style.display = 'none';
  document.getElementById('adv-passo2').style.display = 'none';
  document.getElementById('btnConfirmarAdv').style.display = 'none';
  document.getElementById('advProcessoBusca').value = '';
  document.getElementById('advProcessoId').value = '';
  document.getElementById('advProcessoSelecionado').style.display = 'none';
  document.getElementById('modalConvite').classList.add('open');
}
function fecharModalConvite() {
  document.getElementById('modalConvite').classList.remove('open');
}

let _advContaDestino = null;
async function buscarContaAdvogado() {
  const codigo    = document.getElementById('advCodigo').value.trim();
  const resultado = document.getElementById('advContaResultado');
  if (!codigo) { toast('Informe o código de vínculo.', 'err'); return; }

  resultado.style.display = 'block';
  resultado.innerHTML = 'Buscando...';
  resultado.style.background = 'rgba(30,50,80,.5)';
  resultado.style.color = '#7a96b4';
  resultado.style.border = '1px solid rgba(96,165,250,.1)';

  const r = await fetch(`/api/accounts_lookup.php?codigo_vinculo=${encodeURIComponent(codigo)}`).then(x=>x.json()).catch(()=>({}));

  if (r.data) {
    _advContaDestino = r.data;
    const isUser = r.data.tipo_lookup === 'usuario';
    const label  = isUser
      ? `<strong style="color:#86efac">✓ Advogado encontrado:</strong> ${r.data.user_nome} <span style="color:#7a96b4;font-size:.78rem">(${r.data.user_email})</span> — escritório: <em>${r.data.account_nome}</em>`
      : `<strong style="color:#86efac">✓ Escritório encontrado:</strong> ${r.data.nome} <span class="badge badge-${r.data.tipo}" style="margin-left:6px">${r.data.tipo}</span>`;
    resultado.innerHTML = label;
    resultado.style.background = 'rgba(34,197,94,.08)';
    resultado.style.border = '1px solid rgba(34,197,94,.25)';
    document.getElementById('adv-passo2').style.display = 'block';
    document.getElementById('btnConfirmarAdv').style.display = 'inline-block';
  } else {
    _advContaDestino = null;
    resultado.innerHTML = `<span style="color:#fca5a5">✗ ${r.error || 'Código não encontrado.'}</span>`;
    resultado.style.background = 'rgba(239,68,68,.08)';
    resultado.style.border = '1px solid rgba(239,68,68,.2)';
    document.getElementById('adv-passo2').style.display = 'none';
    document.getElementById('btnConfirmarAdv').style.display = 'none';
  }
}

function limparBusca() {
  _advContaDestino = null;
  document.getElementById('advContaResultado').style.display = 'none';
  document.getElementById('adv-passo2').style.display = 'none';
  document.getElementById('btnConfirmarAdv').style.display = 'none';
}

let _buscaTimer = null;
async function buscarProcessos(q) {
  clearTimeout(_buscaTimer);
  _buscaTimer = setTimeout(async () => {
    const r = await fetch(`/api/processes_search.php?q=${encodeURIComponent(q)}`).then(x=>x.json()).catch(()=>({data:[]}));
    const lista = document.getElementById('advProcessoLista');
    if (!r.data?.length) { lista.style.display = 'none'; return; }
    lista.style.display = 'block';
    lista.innerHTML = r.data.map(p =>
      `<div onclick="selecionarProcesso(${p.id},'${(p.numero||'').replace(/'/g,'')}',' ${(p.cliente_nome||'').replace(/'/g,'')}')"
            style="padding:8px 12px;cursor:pointer;border-bottom:1px solid rgba(96,165,250,.08);font-size:.83rem;color:#c8ddf0;"
            onmouseover="this.style.background='rgba(37,99,235,.15)'" onmouseout="this.style.background=''">
        <strong>${p.numero || '—'}</strong> — ${p.cliente_nome || '—'}
      </div>`
    ).join('');
  }, 280);
}

function selecionarProcesso(id, numero, cliente) {
  document.getElementById('advProcessoId').value = id;
  document.getElementById('advProcessoBusca').value = `${numero} — ${cliente}`;
  document.getElementById('advProcessoLista').style.display = 'none';
  const sel = document.getElementById('advProcessoSelecionado');
  sel.style.display = 'block';
  // Mostra número do processo + cliente em vez de ID interno (ID é organização técnica)
  sel.textContent = `✓ Processo ${numero}${cliente ? ' — ' + cliente : ''} selecionado`;
}

async function confirmarCompartilhamento() {
  const processoId = document.getElementById('advProcessoId').value;
  const permissao  = document.getElementById('advPermissao').value;
  if (!_advContaDestino) { toast('Busque a conta do advogado primeiro.', 'err'); return; }
  if (!processoId)       { toast('Selecione um processo.', 'err'); return; }

  const payload = {
    resource_type:    'processo',
    resource_id:      parseInt(processoId),
    to_account_id:    _advContaDestino.account_id || _advContaDestino.id,
    permission_level: permissao,
    csrf_token:       CSRF,
  };
  // Se encontrado por código de usuário, inclui to_user_id para compartilhamento específico
  if (_advContaDestino.tipo_lookup === 'usuario' && _advContaDestino.user_id) {
    payload.to_user_id = _advContaDestino.user_id;
  }
  const r = await api('/api/resource_shares.php', {
    method: 'POST',
    body: JSON.stringify(payload)
  });
  if (r.success || r.ok) {
    toast(`Processo compartilhado com ${_advContaDestino.nome}!`, 'ok');
    fecharModalConvite();
    carregarAdvogados();
    carregarShares();
  } else {
    toast(r.error || 'Erro ao compartilhar.', 'err');
  }
}

// ── Compartilhamentos ─────────────────────────────────────────────────────────
async function carregarShares() {
  const el = document.getElementById('sharesList');
  if (!el) return;
  el.innerHTML = '<div class="es-empty">Carregando...</div>';
  // Mesma cilada de antes: GET sem params retorna 400.
  // - Advogado: vê o que recebeu (shared_with_me=1)
  // - Matriz/Filial: vê o que emitiu (listar_meus=1)
  const url = ACCOUNT_TIPO === 'advogado'
    ? '/api/resource_shares.php?shared_with_me=1'
    : '/api/resource_shares.php?listar_meus=1';
  const r = await api(url);
  const lista = (r.data || []).filter(s => (s.status || 'active') === 'active');
  if (!lista.length) {
    el.innerHTML = `<div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>Nenhum compartilhamento ativo.</div>`;
    return;
  }
  el.innerHTML = `<table class="es-table">
    <thead><tr><th>Recurso</th><th>Para</th><th>Permissão</th><th>Status</th>${IS_ADMIN ? '<th>Ações</th>' : ''}</tr></thead>
    <tbody>${lista.map(s => `<tr>
      <td><span class="badge badge-filial">${s.resource_type} #${s.resource_id}</span></td>
      <td>${s.to_account_nome || s.to_account_id || '—'}</td>
      <td>${s.permission_level}</td>
      <td><span class="badge badge-${s.status}">${s.status}</span></td>
      ${IS_ADMIN ? `<td>${s.status === 'active' ? `<button class="btn-sm btn-danger" onclick="revogarShare(${s.id})">Revogar</button>` : ''}</td>` : ''}
    </tr>`).join('')}</tbody>
  </table>`;
}

async function revogarShare(id) {
  if (!(await Yuris.confirm('Revogar este compartilhamento?', { danger: true, okLabel: 'Revogar' }))) return;
  const r = await api('/api/resource_shares.php', {
    method: 'DELETE', body: JSON.stringify({ id, csrf_token: CSRF })
  });
  r.ok ? (toast('Compartilhamento revogado.', 'ok'), carregarShares()) : toast(r.error || 'Erro', 'err');
}

// ── Toast ─────────────────────────────────────────────────────────────────────
// Tema-aware: lê data-theme do <html> e ajusta paleta pra manter contraste
// (fundo claro → texto escuro; fundo escuro → texto claro).
function toast(msg, type = 'ok') {
  const t = document.createElement('div');
  t.textContent = msg;
  const isLight = document.documentElement.getAttribute('data-theme') === 'light';
  let bg, border, color;
  if (type === 'ok') {
    bg     = isLight ? '#22C55E' : 'rgba(34,197,94,.2)';
    border = isLight ? '#16A34A' : 'rgba(34,197,94,.4)';
    color  = isLight ? '#FFFFFF' : '#86efac';
  } else {
    bg     = isLight ? '#DC2626' : 'rgba(239,68,68,.2)';
    border = isLight ? '#B91C1C' : 'rgba(239,68,68,.4)';
    color  = isLight ? '#FFFFFF' : '#fca5a5';
  }
  Object.assign(t.style, {
    position:'fixed', bottom:'24px', right:'24px', zIndex:9999,
    padding:'10px 18px', borderRadius:'8px', fontSize:'.84rem', fontWeight:'600',
    background: bg,
    border: `1px solid ${border}`,
    color: color,
    boxShadow: isLight ? '0 8px 24px rgba(15,23,42,.18)' : '0 8px 24px rgba(0,0,0,.3)',
    transition: 'opacity .3s',
  });
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
}

// ── Módulos liberados ─────────────────────────────────────────────────────────
const MODULOS_LABEL = {
  processos: 'Processos', juridico: 'Jurídico', dashboard: 'Dashboard',
  planejamento: 'Planejamento', prospeccao: 'Prospecção', financas: 'Finanças',
  tarefas: 'Tarefas', chat: 'WhatsApp', chat_interno: 'Chat Interno',
};

async function carregarModulos() {
  const el = document.getElementById('modulosList');
  el.innerHTML = '<div class="es-empty">Carregando...</div>';
  const r = await api('/api/resource_shares.php?resource_type=module&resource_id=0');
  // Fallback: também busca via shared_with_me para mostrar o que liberei
  const r2 = await fetch('/api/resource_shares.php?listar_modulos=1', {credentials:'same-origin'}).then(x=>x.json()).catch(()=>({data:[]}));
  const lista = (r2.data || r.data || []).filter(s => s.resource_type === 'module' && s.status === 'active');
  if (!lista.length) {
    el.innerHTML = `<div class="es-empty">Nenhum módulo liberado. Clique em "+ Liberar módulo" para começar.</div>`;
    return;
  }
  el.innerHTML = `<table class="es-table">
    <thead><tr><th>Módulo</th><th>Para</th><th>Permissão</th><th>Desde</th>${IS_ADMIN ? '<th>Ações</th>' : ''}</tr></thead>
    <tbody>${lista.map(s => {
      const alvo = s.to_user_nome ? `Advogado: <strong>${s.to_user_nome}</strong>` : (s.to_account_nome || `Conta #${s.to_account_id}`);
      return `<tr>
        <td><span class="badge badge-matriz">${MODULOS_LABEL[s.module_key] || s.module_key}</span></td>
        <td>${alvo}</td>
        <td>${s.permission_level}</td>
        <td style="color:#4a5568">${(s.created_at||'').slice(0,10)}</td>
        ${IS_ADMIN ? `<td><button class="btn-sm btn-danger" onclick="revogarShare(${s.id})">Revogar</button></td>` : ''}
      </tr>`;
    }).join('')}</tbody>
  </table>`;
}

let _moduleAlvo = null;
function abrirModalModulo() {
  _moduleAlvo = null;
  document.getElementById('moduleCodigo').value = '';
  document.getElementById('moduleResultado').style.display = 'none';
  document.getElementById('modulePermSection').style.display = 'none';
  document.getElementById('moduleConfirmBtn').style.display = 'none';
  document.getElementById('modalModulo').classList.add('open');
}

async function buscarDestinoModulo() {
  const codigo = document.getElementById('moduleCodigo').value.trim();
  const res    = document.getElementById('moduleResultado');
  if (!codigo) { toast('Cole o código primeiro.', 'err'); return; }
  res.style.display = 'block';
  res.style.background = 'rgba(30,50,80,.4)';
  res.style.border = '1px solid rgba(96,165,250,.1)';
  res.style.color = '#9ab0c9';
  res.innerHTML = 'Buscando...';
  const r = await fetch(`/api/lookup.php?codigo=${encodeURIComponent(codigo)}`, {credentials:'same-origin'}).then(x=>x.json()).catch(()=>({error:'Erro de rede'}));
  if (r.tipo === 'conta') {
    _moduleAlvo = { kind: 'conta', accountId: r.data.id, nome: r.data.nome };
    res.style.background = 'rgba(34,197,94,.08)';
    res.style.border = '1px solid rgba(34,197,94,.25)';
    res.style.color = '#86efac';
    res.innerHTML = `✓ Conta: <strong>${r.data.nome}</strong> <span style="opacity:.7">(${r.data.tipo})</span> — toda a conta receberá acesso ao módulo`;
    document.getElementById('modulePermSection').style.display = 'block';
    document.getElementById('moduleConfirmBtn').style.display = 'inline-block';
  } else if (r.tipo === 'advogado') {
    _moduleAlvo = { kind: 'advogado', userId: r.data.user_id, accountId: r.data.account_id, nome: r.data.nome };
    res.style.background = 'rgba(34,197,94,.08)';
    res.style.border = '1px solid rgba(34,197,94,.25)';
    res.style.color = '#86efac';
    res.innerHTML = `✓ Advogado: <strong>${r.data.nome}</strong> <span style="opacity:.7">(${r.data.codigo_advogado})</span> — apenas ele terá acesso ao módulo`;
    document.getElementById('modulePermSection').style.display = 'block';
    document.getElementById('moduleConfirmBtn').style.display = 'inline-block';
  } else {
    _moduleAlvo = null;
    res.style.background = 'rgba(239,68,68,.08)';
    res.style.border = '1px solid rgba(239,68,68,.3)';
    res.style.color = '#fca5a5';
    res.innerHTML = `✗ ${r.error || 'Código não encontrado'}`;
    document.getElementById('modulePermSection').style.display = 'none';
    document.getElementById('moduleConfirmBtn').style.display = 'none';
  }
}

async function confirmarLiberarModulo() {
  if (!_moduleAlvo) return;
  const moduleKey = document.getElementById('moduleKey').value;
  const perm      = document.getElementById('modulePerm').value;
  const payload = {
    resource_type:    'module',
    module_key:       moduleKey,
    permission_level: perm,
    csrf_token:       CSRF,
  };
  if (_moduleAlvo.kind === 'conta') {
    payload.to_account_id = _moduleAlvo.accountId;
  } else {
    payload.to_user_id    = _moduleAlvo.userId;
    payload.to_account_id = _moduleAlvo.accountId;
  }
  const r = await api('/api/resource_shares.php', {
    method: 'POST', body: JSON.stringify(payload),
  });
  if (r.success || r.ok) {
    toast(`Módulo "${MODULOS_LABEL[moduleKey]}" liberado para ${_moduleAlvo.nome}!`, 'ok');
    document.getElementById('modalModulo').classList.remove('open');
    carregarModulos();
  } else {
    toast(r.error || 'Erro ao liberar módulo', 'err');
  }
}

// (Removida 2a versao redundante de switchTab — a unificada em ~linha 862 ja
//  cobre vinculos/advogados/compartilhamentos/modulos via data-tab.)

// ═══════════════════════════════════════════════════════════════════════════════
// Aba MONITORAMENTOS (add-on)
// Single source: /api/push/quota.php + allocations + requests + permissions
// ═══════════════════════════════════════════════════════════════════════════════
const MON_IS_MATRIZ      = <?= $isMatrizEsc ? 'true' : 'false' ?>;
const MON_CAN_ALLOCATE   = <?= $canAllocateEsc ? 'true' : 'false' ?>;
const MON_CAN_MANAGE_PERMS = <?= $canManagePermsEsc ? 'true' : 'false' ?>;
// Nome da matriz (pra mostrar na tabela de distribuição). Resolvido da própria
// conta logada — toda matriz vê SEU nome aqui.
window.MON_MATRIZ_NOME = <?php
    try {
        $pdo = \App\Models\Database::getConnection();
        $st  = $pdo->prepare('SELECT nome FROM accounts WHERE id = :id LIMIT 1');
        $st->execute(['id' => $accountId]);
        echo json_encode((string)($st->fetchColumn() ?: 'Matriz'));
    } catch (\Throwable $e) { echo '"Matriz"'; }
?>;

let _monAllocCache = null;
let _monReqCache   = null;

async function carregarMonitoramentos() {
  // Refresca tudo paralelo. Quota já vem rendered server-side, mas refrescamos
  // pra refletir mudanças do Master sem precisar F5.
  await Promise.all([
    loadMonQuotaEsc(),
    (MON_IS_MATRIZ && MON_CAN_ALLOCATE) ? loadMonAllocs() : Promise.resolve(),
    loadMonReqs(),
  ]);
}

async function loadMonQuotaEsc() {
  try {
    const r = await fetch(`/api/push/quota.php?_t=${Date.now()}`, {
      credentials: 'same-origin',
      headers: { 'Cache-Control': 'no-cache' },
    });
    const j = await r.json();
    if (!j.ok) return;
    // ApiResponse::ok encapsula em {ok, data}; fallback pra formato antigo.
    const s = (j.data && j.data.status) || j.status || {};
    const lim = s.effective_limit || 0;
    const used = s.current_usage || 0;
    const avail = s.available || 0;
    const pct = s.percent_used || 0;
    const $ = id => document.getElementById(id);
    if ($('monKpiContratado')) $('monKpiContratado').textContent = lim;
    if ($('monKpiUsado'))      $('monKpiUsado').textContent      = used;
    if ($('monKpiDispo'))      $('monKpiDispo').textContent      = avail;
    if ($('monKpiPercent'))    $('monKpiPercent').textContent    = pct + '% utilizado';

    // Reaplica classes good/warn/bad
    const dispBox = $('monKpiDispBox');
    if (dispBox) {
      dispBox.classList.remove('good', 'bad');
      dispBox.classList.add(avail > 0 ? 'good' : 'bad');
    }
    const usadoBox = $('monKpiUsadoBox');
    if (usadoBox) {
      usadoBox.classList.remove('warn', 'bad');
      if (pct >= 100) usadoBox.classList.add('bad');
      else if (pct >= 80) usadoBox.classList.add('warn');
    }
  } catch (e) { console.warn('quota fetch', e); }
}

async function loadMonAllocs() {
  const wrap = document.getElementById('monFiliaisWrap');
  const hist = document.getElementById('monAllocHistWrap');
  try {
    const r = await fetch(`/api/push/allocations.php?_t=${Date.now()}`, {
      credentials: 'same-origin', headers: { 'Cache-Control': 'no-cache' },
    });
    const j = await r.json();
    if (!j.ok) {
      if (wrap) wrap.innerHTML = `<div class="es-empty">${escMon(j.error||'?')}</div>`;
      return;
    }
    _monAllocCache = j.data || j;
    renderMonAllocKpis();
    renderMonFiliais();
    renderMonAllocHist();
  } catch (e) {
    if (wrap) wrap.innerHTML = `<div class="es-empty">Falha de rede</div>`;
    if (hist) hist.innerHTML = '';
  }
}

function renderMonAllocKpis() {
  const p = _monAllocCache?.parent || {};
  const $ = id => document.getElementById(id);
  if ($('monKpiPoolTotal')) $('monKpiPoolTotal').textContent = p.own_limit ?? '—';
  if ($('monKpiAlloc'))     $('monKpiAlloc').textContent     = p.allocated_total ?? '—';
  if ($('monKpiPoolLivre')) $('monKpiPoolLivre').textContent = p.pool_disponivel ?? '—';
}

function renderMonFiliais() {
  const wrap = document.getElementById('monFiliaisWrap');
  if (!wrap) return;
  const p = _monAllocCache?.parent || {};
  const filiais = _monAllocCache?.filiais || [];
  const livre   = p.pool_disponivel ?? 0;
  const usoMatriz = p.matriz_usage ?? 0;
  const total   = p.own_limit ?? 0;
  const alloc   = p.allocated_total ?? 0;
  const matrizNome = (window.MON_MATRIZ_NOME || 'Matriz');

  let html = `<table class="mon-table">
    <thead><tr>
      <th>Quem usa</th><th>Está usando</th><th>Quantos tem agora</th><th>Quero deixar</th><th style="text-align:right">Ações</th>
    </tr></thead><tbody>`;

  // ── Linha da MATRIZ (read-only) ──
  // A matriz não "reserva pra si mesma" — ela usa o que sobra do pool.
  // Mostra usage atual + vagas livres pra criar mais (= sobra - uso atual).
  const livreMatriz = Math.max(0, livre - usoMatriz);
  const pillMatriz = livreMatriz > 0
    ? `<span class="mon-pill mon-pill-active">${livreMatriz} vaga${livreMatriz>1?'s':''} livre${livreMatriz>1?'s':''}</span>`
    : `<span class="mon-pill mon-pill-denied">lotado</span>`;
  html += `<tr style="background:rgba(37,99,235,.04);">
    <td><strong>${escMon(matrizNome)} (matriz, você)</strong>
        <div class="mon-hint">o que não estiver reservado fica pra você</div></td>
    <td>${usoMatriz}</td>
    <td>${pillMatriz}</td>
    <td><span class="mon-hint">automático</span></td>
    <td style="text-align:right"><span class="mon-hint">—</span></td>
  </tr>`;

  if (!filiais.length) {
    html += `<tr><td colspan="5">
      <div class="es-empty">
        Você ainda não tem filiais vinculadas para distribuir monitoramentos.
        Crie um vínculo na aba <strong>Vínculos</strong> primeiro.
      </div>
    </td></tr>`;
  } else {
    for (const f of filiais) {
      const val = f.allocated || 0;
      html += `<tr data-acc="${f.account_id}" data-id="${f.allocation_id||''}">
        <td><strong>${escMon(f.nome)}</strong><div class="mon-hint">filial #${f.account_id}</div></td>
        <td>${f.current_usage||0}</td>
        <td>${val > 0
          ? `<span class="mon-pill mon-pill-active">${val} reservado${val>1?'s':''}</span>`
          : `<span class="mon-pill mon-pill-revoked">compartilhado</span>`}</td>
        <td><input type="number" min="0" step="1" class="mon-alloc-input" value="${val}"></td>
        <td style="text-align:right;white-space:nowrap">
          <button class="btn-sm btn-primary" onclick="saveMonAlloc(${f.account_id})">Salvar</button>
          ${f.allocation_id
            ? `<button class="btn-sm btn-danger" style="margin-left:6px" onclick="revokeMonAlloc(${f.allocation_id})" title="Desfaz a reserva — essa filial volta a compartilhar com a matriz">Desfazer</button>`
            : ''}
        </td>
      </tr>`;
    }
  }
  html += `</tbody></table>
    <div class="mon-hint" style="margin-top:10px">
      Você tem <strong>${total}</strong> ao todo · reservou <strong>${alloc}</strong> pra filiais · sobram <strong>${livre}</strong> pra matriz usar livremente.
      ${filiais.length ? '<br>Deixar a filial com <strong>0</strong> e clicar Salvar volta ela a compartilhar com a matriz.' : ''}
    </div>`;
  wrap.innerHTML = html;
}

function renderMonAllocHist() {
  const wrap = document.getElementById('monAllocHistWrap');
  if (!wrap) return;
  const list = _monAllocCache?.allocations || [];
  if (!list.length) { wrap.innerHTML = `<div class="es-empty">Você ainda não reservou monitoramentos pra nenhuma filial.</div>`; return; }
  let html = `<table class="mon-table"><thead><tr>
    <th>Quando</th><th>Filial</th><th>Quantos</th><th>Situação</th><th>Quem fez</th><th>Observação</th>
  </tr></thead><tbody>`;
  for (const a of list) {
    const isAtiva = a.status === 'active';
    html += `<tr>
      <td>${monDate(a.created_at)}${a.revoked_at ? `<div class="mon-hint">desfeito ${monDate(a.revoked_at)}</div>`:''}</td>
      <td>${escMon(a.target_nome||'?')} <span class="mon-hint">#${a.target_account_id}</span></td>
      <td>${a.allocated}</td>
      <td>${isAtiva ? `<span class="mon-pill mon-pill-active">em vigor</span>` : `<span class="mon-pill mon-pill-revoked">desfeito</span>`}</td>
      <td>${escMon(a.created_by_nome||'?')}${a.revoked_by_nome ? `<div class="mon-hint">desfeito por ${escMon(a.revoked_by_nome)}</div>`:''}</td>
      <td>${escMon(a.observacoes||'')}</td>
    </tr>`;
  }
  html += `</tbody></table>`;
  wrap.innerHTML = html;
}

async function saveMonAlloc(accountId) {
  const row = document.querySelector(`#monFiliaisWrap [data-acc="${accountId}"]`);
  if (!row) return;
  const input = row.querySelector('.mon-alloc-input');
  const allocId = row.dataset.id ? parseInt(row.dataset.id, 10) : 0;
  const novo = parseInt(input.value, 10);
  if (Number.isNaN(novo) || novo < 0) { alert('Valor inválido'); return; }
  if (novo === 0 && allocId) {
    if (!confirm('Definir 0 vai revogar a alocação. Continuar?')) return;
    return revokeMonAlloc(allocId);
  }
  if (novo === 0 && !allocId) return;
  try {
    const r = await fetch(
      allocId ? `/api/push/allocations.php?id=${allocId}`
              : `/api/push/allocations.php`,
      {
        method: allocId ? 'PATCH' : 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(allocId
          ? { _csrf: CSRF, allocated: novo }
          : { _csrf: CSRF, target_account_id: accountId, allocated: novo }),
      }
    );
    const j = await r.json();
    if (!j.ok) { alert(j.error || 'Erro ao salvar'); return; }
    await loadMonAllocs();
    await loadMonQuotaEsc();
  } catch (e) { alert('Falha de rede'); }
}

async function revokeMonAlloc(id) {
  if (!confirm('Revogar alocação? A filial volta pro pool aberto da matriz.')) return;
  try {
    const r = await fetch(`/api/push/allocations.php?id=${id}`, {
      method: 'DELETE',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ _csrf: CSRF }),
    });
    const j = await r.json();
    if (!j.ok) { alert(j.error || 'Erro ao revogar'); return; }
    await loadMonAllocs();
    await loadMonQuotaEsc();
  } catch (e) { alert('Falha de rede'); }
}

// ── Solicitações ──
async function loadMonReqs() {
  const wrap = document.getElementById('monReqsWrap');
  const scope = document.getElementById('monReqScope')?.value || 'pending';
  try {
    const r = await fetch(`/api/push/requests.php?scope=${encodeURIComponent(scope)}&_t=${Date.now()}`, {
      credentials: 'same-origin', headers: { 'Cache-Control': 'no-cache' },
    });
    const j = await r.json();
    if (!j.ok) {
      if (wrap) wrap.innerHTML = `<div class="es-empty">${escMon(j.error||'?')}</div>`;
      return;
    }
    _monReqCache = j.data || j;
    updateMonReqBadge();
    renderMonReqs();
  } catch (e) {
    if (wrap) wrap.innerHTML = `<div class="es-empty">Falha de rede</div>`;
  }
}

function updateMonReqBadge() {
  const badge = document.getElementById('reqPendingBadgeEsc');
  if (!badge || !_monReqCache) return;
  const n = _monReqCache.counts?.pending || 0;
  badge.style.display = n > 0 ? 'inline-block' : 'none';
  badge.textContent = String(n);
}

function renderMonReqs() {
  const wrap = document.getElementById('monReqsWrap');
  if (!wrap || !_monReqCache) return;
  const list = _monReqCache.requests || [];
  const canApprove = _monReqCache.can?.approve;
  if (!list.length) {
    wrap.innerHTML = `<div class="es-empty">Nenhum pedido nesse filtro.</div>`;
    return;
  }
  let html = `<table class="mon-table"><thead><tr>
    <th>Quando</th><th>Quem pediu</th><th>O que quer monitorar</th><th>Motivo</th><th>Situação</th><th style="text-align:right">Ações</th>
  </tr></thead><tbody>`;
  for (const r of list) {
    const pillStatus = ({
      pending:  '<span class="mon-pill mon-pill-pending">aguardando</span>',
      approved: '<span class="mon-pill mon-pill-active">aprovado</span>',
      denied:   '<span class="mon-pill mon-pill-denied">recusado</span>',
      canceled: '<span class="mon-pill mon-pill-revoked">cancelado</span>',
    })[r.status] || r.status;
    const acoes = r.status === 'pending'
      ? (canApprove
        ? `<button class="btn-sm btn-primary" onclick="resolveMonReq(${r.id},'approve')" title="Aprovar e criar o monitoramento agora">Aprovar</button>
           <button class="btn-sm btn-danger" style="margin-left:6px" onclick="resolveMonReq(${r.id},'deny')" title="Recusar o pedido">Recusar</button>`
        : `<button class="btn-sm btn-outline" onclick="resolveMonReq(${r.id},'cancel')">Cancelar pedido</button>`)
      : (r.resulting_monitor_id ? `<span class="mon-hint">monitoramento #${r.resulting_monitor_id}</span>` : '');
    const tipoLabel = ({oab:'OAB', processo:'Processo', nome:'Nome', customizado:'Outro'})[r.tipo_monitoramento] || r.tipo_monitoramento;
    const pediu = `${escMon(tipoLabel)}: <strong>${escMon(r.valor_monitorado)}</strong>`
      + (r.uf ? ` <span class="mon-hint">/${escMon(r.uf)}</span>` : '')
      + (r.nome_complementar ? `<div class="mon-hint">${escMon(r.nome_complementar)}</div>` : '');
    html += `<tr>
      <td>${monDate(r.created_at)}${(r.approved_at || r.denied_at) ? `<div class="mon-hint">decidido ${monDate(r.approved_at||r.denied_at)}</div>`:''}</td>
      <td>${escMon(r.requesting_user_nome||'?')}</td>
      <td>${pediu}</td>
      <td><div class="mon-hint" style="max-width:240px;white-space:pre-wrap">${escMon(r.justificativa||'—')}</div>${r.motivo_recusa ? `<div class="mon-hint" style="color:#FCA5A5;margin-top:4px"><strong>Recusado:</strong> ${escMon(r.motivo_recusa)}</div>`:''}</td>
      <td>${pillStatus}</td>
      <td style="text-align:right;white-space:nowrap">${acoes}</td>
    </tr>`;
  }
  html += `</tbody></table>`;
  wrap.innerHTML = html;
}

async function resolveMonReq(id, action) {
  let motivo = null;
  if (action === 'deny') {
    motivo = prompt('Motivo da recusa (opcional):', '');
    if (motivo === null) return;
  }
  if (action === 'cancel' && !confirm('Cancelar essa solicitação?')) return;
  if (action === 'approve' && !confirm('Aprovar e criar o monitor agora?')) return;
  try {
    const r = await fetch(`/api/push/requests.php?id=${id}`, {
      method: 'PATCH',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ _csrf: CSRF, action, motivo }),
    });
    const j = await r.json();
    if (!j.ok) { alert(j.error || 'Erro'); return; }
    await loadMonReqs();
    await loadMonQuotaEsc();
  } catch (e) { alert('Falha de rede'); }
}

// Toggle flag advogado_pode_criar_monitor
document.getElementById('monFlagAdvogadoCreate')?.addEventListener('change', async (e) => {
  const cb = e.target;
  try {
    const r = await fetch('/api/push/permissions.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ _csrf: CSRF, advogado_pode_criar_monitor: !!cb.checked }),
    });
    const j = await r.json();
    if (!j.ok) { alert(j.error || 'Erro'); cb.checked = !cb.checked; }
  } catch (e) { cb.checked = !cb.checked; alert('Falha de rede'); }
});

// Filtro scope
document.getElementById('monReqScope')?.addEventListener('change', () => loadMonReqs());

// Helpers locais (escape + data) — escopados como mon* pra não colidir
function escMon(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}
function monDate(s) {
  if (!s) return '—';
  const d = new Date(String(s).replace(' ', 'T'));
  if (isNaN(d)) return s;
  return d.toLocaleDateString('pt-BR', {day:'2-digit', month:'2-digit', year:'2-digit'})
       + ' ' + d.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
}

// Preload pra contagem de pending na tab mesmo antes de abrir
loadMonReqs();

// ── Init ──────────────────────────────────────────────────────────────────────
carregarConta();

// Hash routing: #monitoramentos | #vinculos | #advogados | etc.
// Permite linkar direto pra uma aba específica (ex: badge "Gerenciar cota →"
// no modal de intimações aponta pra /escritorios.php#monitoramentos).
const _hashTab = (location.hash || '').replace('#', '').trim();
const _validHashes = ['vinculos','advogados','compartilhamentos','modulos','monitoramentos','solicitar'];
if (_validHashes.includes(_hashTab) && document.querySelector(`.es-tab[data-tab="${_hashTab}"]`)) {
  switchTab(_hashTab);
} else if (ACCOUNT_TIPO === 'advogado') {
  carregarAdvogadosVinculos();
} else {
  carregarVinculos();
}
</script>
</body>
</html>
