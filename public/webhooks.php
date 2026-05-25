<?php
require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Services/WebhookDispatcher.php';

use App\Services\WebhookDispatcher;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /sistema_vendas/public/login.php'); exit; }
if ($_SESSION['user_perfil'] !== 'admin') { header('Location: /sistema_vendas/public/dashboard.php'); exit; }

$activePage = 'webhooks';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$catalog = WebhookDispatcher::catalog();
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Webhooks — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/sistema_vendas/public/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/sistema_vendas/public/assets/favicon-32.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/sistema_vendas/public/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/sidebar.css?v=10">
  <style>
    :root {
      --bg: #070F1C; --panel: rgba(14,35,65,.94); --line: rgba(160,180,210,0.08);
      --line-md: rgba(160,180,210,0.14); --text: #D8E4F0; --muted: #7A8898;
      --primary: #244E7A; --accent: #3b82f6; --radius: 14px;
      --ok: #059669; --warn: #d97706; --danger: #dc2626;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body { margin:0; background:#070F1C; color:var(--text); font-family:'Poppins',system-ui,sans-serif; min-height:100vh; }

    /* ── Layout ── */
    .wh-main { flex:1; min-width:0; display:flex; flex-direction:column; gap:20px; }

    /* ── KPI ── */
    .kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
    .kpi-card { background:linear-gradient(145deg,#0C1A2C,#081020); border:1px solid rgba(160,180,210,.10); border-radius:14px; padding:16px; position:relative; overflow:hidden; }
    .kpi-card:hover { border-color:rgba(160,180,210,.22); transform:translateY(-1px); transition:.2s; }
    .kpi-dot { position:absolute; top:14px; right:14px; width:9px; height:9px; border-radius:50%; }
    .dot-blue { background:#3A6090; } .dot-ok { background:#4A9078; } .dot-warn { background:#8A7030; } .dot-neutral { background:#3A4858; }
    .kpi-label { color:#7A8898; font-size:.73rem; text-transform:uppercase; letter-spacing:.06em; font-weight:600; }
    .kpi-value { margin-top:8px; color:#D8E4F0; font-size:1.5rem; font-weight:700; line-height:1.1; }
    .kpi-foot  { margin-top:4px; color:var(--muted); font-size:.72rem; }

    /* ── Panel ── */
    .panel { background:var(--panel); border:1px solid var(--line); border-radius:var(--radius); padding:20px; }

    /* ── Toolbar ── */
    .toolbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .btn { display:inline-flex; align-items:center; gap:6px; padding:0 16px; height:38px; border-radius:10px; font-family:inherit; font-size:.84rem; font-weight:600; cursor:pointer; border:none; transition:filter .15s, transform .15s; }
    .btn-primary { background:linear-gradient(135deg,#1A3A5C,#244E7A); color:#C8D4E0; border:1px solid rgba(160,180,210,.20); }
    .btn-primary:hover { filter:brightness(1.12); transform:translateY(-1px); }
    .btn-secondary { background:transparent; color:#A8BDD4; border:1px solid rgba(160,180,210,.18); }
    .btn-secondary:hover { background:rgba(20,50,90,.30); }
    .btn-danger { background:rgba(220,38,38,.85); color:#fff; }
    .btn-danger:hover { opacity:.85; }
    .btn-sm { height:30px; padding:0 10px; font-size:.78rem; }
    .btn-ok { background:rgba(5,150,105,.25); color:#34d399; border:1px solid rgba(5,150,105,.35); }
    .btn-warn { background:rgba(217,119,6,.2); color:#fbbf24; border:1px solid rgba(217,119,6,.3); }

    /* ── Webhook Cards list ── */
    .wh-list { display:flex; flex-direction:column; gap:10px; }
    .wh-card {
      background:linear-gradient(145deg,rgba(12,26,44,.96),rgba(8,16,32,.98));
      border:1px solid var(--line-md); border-radius:12px; padding:16px 20px;
      display:flex; align-items:center; gap:16px; transition:border-color .2s;
    }
    .wh-card:hover { border-color:rgba(96,165,250,.25); }
    .wh-card-status { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
    .wh-card-info { flex:1; min-width:0; }
    .wh-card-name { font-size:.92rem; font-weight:700; color:#D8E4F0; }
    .wh-card-url  { font-size:.74rem; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:340px; margin-top:2px; }
    .wh-card-meta { display:flex; align-items:center; gap:12px; margin-top:6px; flex-wrap:wrap; }
    .wh-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:.68rem; font-weight:600; }
    .wh-badge-ok   { background:rgba(5,150,105,.2);  color:#34d399; border:1px solid rgba(5,150,105,.3); }
    .wh-badge-off  { background:rgba(107,114,128,.15); color:#9ca3af; border:1px solid rgba(107,114,128,.25); }
    .wh-badge-blue { background:rgba(59,130,246,.15); color:#93c5fd; border:1px solid rgba(59,130,246,.25); }
    .wh-badge-warn { background:rgba(217,119,6,.15);  color:#fbbf24; border:1px solid rgba(217,119,6,.25); }
    .wh-card-actions { display:flex; gap:6px; flex-shrink:0; }
    .wh-empty { text-align:center; padding:48px; color:var(--muted); font-size:.88rem; }

    /* ── Modal ── */
    .modal-overlay { position:fixed; inset:0; background:rgba(2,6,23,.7); display:flex; align-items:flex-start; justify-content:center; z-index:1000; overflow-y:auto; padding:24px 12px; }
    .modal-overlay.hidden { display:none !important; }
    .modal { width:680px; max-width:96vw; margin:auto; background:linear-gradient(165deg,rgba(10,24,46,.99),rgba(7,18,36,.99)); border:1px solid rgba(96,165,250,.22); border-radius:16px; box-shadow:0 24px 60px rgba(2,6,23,.7); overflow:hidden; }
    .modal-header { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid rgba(96,165,250,.14); background:rgba(8,22,44,.55); flex-shrink:0; }
    .modal-title  { font-size:1rem; font-weight:700; color:#dbeafe; }
    .modal-body   { padding:20px; display:flex; flex-direction:column; gap:16px; max-height:75vh; overflow-y:auto; overflow-x:hidden; }
    .modal-footer { padding:14px 20px; border-top:1px solid rgba(96,165,250,.14); background:rgba(8,22,44,.4); display:flex; justify-content:flex-end; gap:8px; flex-shrink:0; }

    /* ── Form fields ── */
    .field-group { display:flex; flex-direction:column; gap:5px; }
    .field-label { font-size:.78rem; font-weight:600; color:#b8d5f4; }
    .field-input {
      width:100%; padding:9px 12px; border-radius:8px; border:1px solid rgba(96,165,250,.25);
      background:rgba(8,20,40,.8) !important; color:#D8E4F0 !important;
      -webkit-text-fill-color:#D8E4F0 !important; font-family:inherit; font-size:.84rem; transition:border-color .18s;
    }
    .field-input:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.2); }
    .field-input::placeholder { color:rgba(160,180,210,.35); }
    .field-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

    /* ── Event selector ── */
    .ev-section { border:1px solid rgba(96,165,250,.12); border-radius:10px; overflow:hidden; }
    .ev-section-header { display:flex; align-items:center; gap:10px; padding:10px 14px; background:rgba(37,99,235,.12); cursor:pointer; user-select:none; }
    .ev-section-title { font-size:.78rem; font-weight:700; color:#93c5fd; letter-spacing:.04em; text-transform:uppercase; flex:1; }
    .ev-section-count { font-size:.7rem; color:#64748b; }
    .ev-toggle-all { font-size:.7rem; color:#60a5fa; cursor:pointer; text-decoration:underline; white-space:nowrap; }
    .ev-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:6px; padding:10px 12px; }
    .ev-item { display:flex; align-items:center; gap:8px; padding:7px 10px; border-radius:7px; border:1px solid rgba(96,165,250,.12); background:rgba(30,58,95,.1); cursor:pointer; transition:all .15s; }
    .ev-item:hover { border-color:rgba(96,165,250,.3); background:rgba(30,58,95,.25); }
    .ev-item.checked { border-color:rgba(59,130,246,.4); background:rgba(37,99,235,.2); }
    .ev-item input { width:14px; height:14px; accent-color:#3b82f6; cursor:pointer; flex-shrink:0; }
    .ev-item-info { min-width:0; }
    .ev-item-key  { font-size:.65rem; color:#64748b; font-family:monospace; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .ev-item-desc { font-size:.76rem; color:#b8d5f4; line-height:1.3; }
    .ev-master { display:flex; align-items:center; gap:8px; padding:10px 14px; background:rgba(37,99,235,.08); border-bottom:1px solid rgba(96,165,250,.1); }
    .ev-master label { font-size:.82rem; font-weight:600; color:#93c5fd; cursor:pointer; }

    /* ── Logs table ── */
    .log-table { width:100%; border-collapse:collapse; font-size:.78rem; }
    .log-table th { padding:8px 12px; text-align:left; color:var(--muted); font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid var(--line); }
    .log-table td { padding:8px 12px; border-bottom:1px solid var(--line); vertical-align:middle; }
    .log-table tr:last-child td { border-bottom:none; }
    .log-status { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:.67rem; font-weight:700; }
    .log-ok   { background:rgba(5,150,105,.2);  color:#34d399; }
    .log-fail { background:rgba(220,38,38,.2);   color:#f87171; }
    .log-key  { font-family:monospace; color:#93c5fd; font-size:.73rem; }

    /* ── Payload preview ── */
    .payload-preview { background:rgba(2,6,23,.8); border:1px solid rgba(96,165,250,.15); border-radius:8px; padding:12px; font-family:monospace; font-size:.74rem; color:#a5b4fc; white-space:pre-wrap; overflow-x:auto; max-height:300px; overflow-y:auto; }

    /* ── Toast ── */
    .toast-wrap { position:fixed; left:50%; transform:translateX(-50%); bottom:24px; z-index:2000; display:flex; flex-direction:column; gap:8px; align-items:center; }
    .toast { min-width:200px; max-width:400px; padding:10px 16px; border-radius:10px; box-shadow:0 6px 18px rgba(2,6,23,.5); font-size:.84rem; font-weight:600; opacity:0; transform:translateY(10px); transition:opacity .2s,transform .2s; }
    .toast.show { opacity:1; transform:translateY(0); }
    .toast.success { background:linear-gradient(90deg,#059669,#10b981); color:#fff; }
    .toast.error   { background:linear-gradient(90deg,#dc2626,#ef4444); color:#fff; }
    .toast.info    { background:linear-gradient(90deg,#1d4ed8,#3b82f6); color:#fff; }

    /* ── Doc tabs ── */
    .doc-tab { background:rgba(30,58,95,.2); color:#7A8898; }
    .doc-tab:hover { background:rgba(37,99,235,.2); color:#93c5fd; border-color:rgba(59,130,246,.4); }
    .doc-tab-active { background:rgba(37,99,235,.35) !important; color:#dbeafe !important; border-color:rgba(96,165,250,.5) !important; }

    /* ══════════════════════════════════════════════════════════════════
       TEMA CLARO — Webhooks
       Body claro, painéis brancos, modais translucidos invertidos,
       overrides explícitos pros inline styles dos modais Catálogo + Docs
       (attribute selectors `[style*="..."]` ganham especificidade sobre
       inline graças ao `!important`).
       ══════════════════════════════════════════════════════════════════ */
    /* body bg vem do yuris-theme.css (cinza #DDE3EC + gradientes radiais —
       padrão de todas as páginas). Não sobrescrever aqui pra não destoar. */
    html[data-theme="light"] .panel,
    html[data-theme="light"] .kpi-card,
    html[data-theme="light"] .wh-card {
      background: #FFFFFF !important;
      border: 1px solid rgba(15,31,54,0.10) !important;
      box-shadow: 0 1px 3px rgba(15,31,54,0.04) !important;
    }
    html[data-theme="light"] .kpi-card:hover,
    html[data-theme="light"] .wh-card:hover {
      border-color: rgba(37,99,235,0.30) !important;
      box-shadow: 0 4px 12px rgba(15,31,54,0.08) !important;
    }
    html[data-theme="light"] .kpi-label { color: #5A6B7E !important; }
    html[data-theme="light"] .kpi-value { color: #0F1F36 !important; }
    html[data-theme="light"] .kpi-foot  { color: #5A6B7E !important; }
    html[data-theme="light"] .wh-card-name { color: #0F1F36 !important; }
    html[data-theme="light"] .wh-card-url  { color: #5A6B7E !important; }
    html[data-theme="light"] .wh-empty     { color: #5A6B7E !important; }

    /* Page header */
    html[data-theme="light"] .page-header h2 { color: #0F1F36 !important; }
    html[data-theme="light"] .page-header p  { color: #5A6B7E !important; }

    /* Buttons */
    html[data-theme="light"] .btn-secondary {
      background: #FFFFFF !important;
      color: #1E4A8A !important;
      border: 1px solid rgba(15,31,54,0.18) !important;
    }
    html[data-theme="light"] .btn-secondary:hover {
      background: rgba(37,99,235,0.08) !important;
      border-color: rgba(37,99,235,0.30) !important;
    }
    html[data-theme="light"] .btn-primary {
      background: linear-gradient(135deg, #1E4A8A, #2563EB) !important;
      color: #FFFFFF !important;
      border: 1px solid rgba(30,74,138,0.40) !important;
    }
    html[data-theme="light"] .btn-primary:hover {
      background: linear-gradient(135deg, #2563EB, #3B82F6) !important;
    }

    /* Modais */
    html[data-theme="light"] .modal-overlay { background: rgba(15,31,54,0.45) !important; }
    html[data-theme="light"] .modal {
      background: linear-gradient(165deg, #FFFFFF 0%, #F7F9FC 100%) !important;
      border: 1px solid rgba(15,31,54,0.14) !important;
      box-shadow: 0 24px 60px rgba(15,31,54,0.18) !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .modal-header {
      background: #F7F9FC !important;
      border-bottom: 1px solid rgba(15,31,54,0.10) !important;
    }
    html[data-theme="light"] .modal-footer {
      background: #F7F9FC !important;
      border-top: 1px solid rgba(15,31,54,0.10) !important;
    }
    html[data-theme="light"] .modal-title { color: #0F1F36 !important; }

    /* Form fields */
    html[data-theme="light"] .field-label { color: #1E4A8A !important; }
    html[data-theme="light"] .field-input {
      background: #FFFFFF !important;
      -webkit-text-fill-color: #0F1F36 !important;
      color: #0F1F36 !important;
      border: 1px solid rgba(15,31,54,0.18) !important;
    }
    html[data-theme="light"] .field-input::placeholder { color: #94A3B8 !important; }
    html[data-theme="light"] .field-input:focus {
      border-color: #2563EB !important;
      box-shadow: 0 0 0 3px rgba(37,99,235,0.15) !important;
    }

    /* Event selector */
    html[data-theme="light"] .ev-section {
      border: 1px solid rgba(15,31,54,0.10) !important;
      background: #FFFFFF !important;
    }
    html[data-theme="light"] .ev-section-header { background: rgba(37,99,235,0.08) !important; }
    html[data-theme="light"] .ev-section-title  { color: #1E4A8A !important; }
    html[data-theme="light"] .ev-section-count  { color: #5A6B7E !important; }
    html[data-theme="light"] .ev-toggle-all     { color: #2563EB !important; }
    html[data-theme="light"] .ev-item {
      background: #FFFFFF !important;
      border: 1px solid rgba(15,31,54,0.10) !important;
    }
    html[data-theme="light"] .ev-item:hover {
      background: rgba(37,99,235,0.06) !important;
      border-color: rgba(37,99,235,0.25) !important;
    }
    html[data-theme="light"] .ev-item.checked {
      background: rgba(37,99,235,0.10) !important;
      border-color: rgba(37,99,235,0.40) !important;
    }
    html[data-theme="light"] .ev-item-key  { color: #5A6B7E !important; }
    html[data-theme="light"] .ev-item-desc { color: #0F1F36 !important; }
    html[data-theme="light"] .ev-master {
      background: rgba(37,99,235,0.06) !important;
      border-bottom: 1px solid rgba(15,31,54,0.08) !important;
    }
    html[data-theme="light"] .ev-master label { color: #1E4A8A !important; }

    /* Logs table */
    html[data-theme="light"] .log-table th {
      color: #5A6B7E !important;
      border-bottom: 1px solid rgba(15,31,54,0.10) !important;
    }
    html[data-theme="light"] .log-table td {
      color: #0F1F36 !important;
      border-bottom: 1px solid rgba(15,31,54,0.08) !important;
    }
    html[data-theme="light"] .log-key { color: #1E4A8A !important; }

    /* Payload preview (JSON viewer) */
    html[data-theme="light"] .payload-preview {
      background: #F7F9FC !important;
      border: 1px solid rgba(15,31,54,0.10) !important;
      color: #1E40AF !important;
    }

    /* Doc tabs (pílulas Visão Geral / Payload / Campos / ...) */
    html[data-theme="light"] .doc-tab {
      background: #FFFFFF !important;
      color: #5A6B7E !important;
      border-color: rgba(15,31,54,0.18) !important;
    }
    html[data-theme="light"] .doc-tab:hover {
      background: rgba(37,99,235,0.08) !important;
      color: #1E4A8A !important;
      border-color: rgba(37,99,235,0.30) !important;
    }
    html[data-theme="light"] .doc-tab-active {
      background: linear-gradient(135deg, #1E4A8A, #2563EB) !important;
      color: #FFFFFF !important;
      border-color: rgba(30,74,138,0.40) !important;
    }

    /* ─── Inline styles do Catálogo + Docs (attribute selectors) ─── */
    /* Headers de seção (PROSPECÇÃO — CLIENTES etc.) */
    html[data-theme="light"] #catalogBody [style*="background:rgba(37,99,235,.14)"],
    html[data-theme="light"] #modalDocs [style*="background:rgba(37,99,235,.14)"] {
      background: rgba(37,99,235,0.10) !important;
      color: #1E4A8A !important;
    }
    /* Mini-cards (Tempo real / Seguro / Padronizado) */
    html[data-theme="light"] #modalDocs [style*="background:rgba(30,58,95,.2)"] {
      background: #F7F9FC !important;
      border-color: rgba(15,31,54,0.10) !important;
    }
    /* Container das abas (Visão Geral...) */
    html[data-theme="light"] #modalDocs [style*="background:rgba(8,22,44,.4)"] {
      background: #F7F9FC !important;
      border-bottom-color: rgba(15,31,54,0.10) !important;
    }
    /* Sub-cards azul-escuro dentro do docs */
    html[data-theme="light"] #modalDocs [style*="background:rgba(37,99,235,.2)"] {
      background: rgba(37,99,235,0.12) !important;
      border-color: rgba(37,99,235,0.25) !important;
    }
    /* Borders */
    html[data-theme="light"] #modalDocs [style*="border:1px solid rgba(96,165,250,.15)"],
    html[data-theme="light"] #modalDocs [style*="border:1px solid rgba(96,165,250,.12)"],
    html[data-theme="light"] #modalDocs [style*="border:1px solid rgba(59,130,246,.2)"] {
      border-color: rgba(15,31,54,0.10) !important;
    }
    html[data-theme="light"] #catalogBody [style*="border-bottom:1px solid rgba(96,165,250,.1)"] {
      border-bottom-color: rgba(15,31,54,0.08) !important;
    }
    html[data-theme="light"] #catalogBody [style*="border-top:1px solid rgba(96,165,250,.06)"] {
      border-top-color: rgba(15,31,54,0.06) !important;
    }

    /* Texto colorido inline → tons escuros pra contraste no fundo claro */
    html[data-theme="light"] #catalogBody [style*="color:#dbeafe"],
    html[data-theme="light"] #modalDocs [style*="color:#dbeafe"] { color: #0F1F36 !important; }
    html[data-theme="light"] #catalogBody [style*="color:#93c5fd"],
    html[data-theme="light"] #modalDocs [style*="color:#93c5fd"]   { color: #1E4A8A !important; }
    html[data-theme="light"] #catalogBody [style*="color:#b8d5f4"],
    html[data-theme="light"] #modalDocs [style*="color:#b8d5f4"]   { color: #0F1F36 !important; }
    html[data-theme="light"] #catalogBody [style*="color:#c8dff4"] { color: #0F1F36 !important; }
    html[data-theme="light"] #catalogBody [style*="color:#4b6380"] { color: #5A6B7E !important; }
    html[data-theme="light"] #modalDocs [style*="color:#6b7280"]   { color: #5A6B7E !important; }
    html[data-theme="light"] #modalDocs [style*="color:#a5b4fc"]   { color: #1E40AF !important; }

    /* docsBody — texto base */
    html[data-theme="light"] #docsBody { color: #0F1F36 !important; }

    /* Code inline (<code style="background:rgba(0,0,0,.3)..."> POST</code>) */
    html[data-theme="light"] #modalDocs code[style*="background:rgba(0,0,0,.3)"] {
      background: rgba(37,99,235,0.10) !important;
      color: #1E40AF !important;
    }
    /* Pill "disponível" no catálogo */
    html[data-theme="light"] #catalogBody span[style*="background:rgba(5,150,105,.2)"] {
      background: rgba(5,150,105,0.12) !important;
      color: #047857 !important;
      border-color: rgba(5,150,105,0.35) !important;
    }

    /* ─── Aba EVENTOS do modal Docs (filtros sticky + cards de evento) ─── */
    /* Container sticky com gradiente dark hardcoded → fundo claro com fade */
    html[data-theme="light"] #modalDocs [data-section="eventos"] > div[style*="position:sticky"] {
      background: linear-gradient(180deg, #FFFFFF 85%, transparent) !important;
    }
    /* Pílula "Todos" (cinza neutro) */
    html[data-theme="light"] .ev-mod-btn[data-mod=""] {
      background: rgba(15,31,54,0.06) !important;
      color: #5A6B7E !important;
      border-color: rgba(15,31,54,0.20) !important;
    }
    html[data-theme="light"] .ev-mod-btn[data-mod=""].ev-mod-active {
      background: #1E4A8A !important;
      color: #FFFFFF !important;
      border-color: #1E4A8A !important;
    }
    /* Contador "80 eventos documentados" */
    html[data-theme="light"] #evCount { color: #5A6B7E !important; }

    /* Card de evento (.ev-doc-card) — header dark hardcoded vira claro */
    html[data-theme="light"] .ev-doc-card {
      background: #FFFFFF !important;
      border: 1px solid rgba(15,31,54,0.10) !important;
    }
    html[data-theme="light"] .ev-doc-card > div[style*="background:rgba(8,20,40,.7)"] {
      background: #F7F9FC !important;
      border-bottom: 1px solid rgba(15,31,54,0.06) !important;
    }
    html[data-theme="light"] .ev-doc-card > div[style*="border-top:1px solid rgba(96,165,250,.08)"] {
      border-top-color: rgba(15,31,54,0.06) !important;
    }
    /* Labels "QUANDO DISPARA" / "CAMPOS DO OBJETO" (#64748b muito claro) */
    html[data-theme="light"] .ev-doc-card [style*="color:#64748b"] { color: #5A6B7E !important; }
    /* Chevron do collapse (#4b5563 fica sumido no claro) */
    html[data-theme="light"] .ev-doc-card svg[stroke="#4b5563"] { stroke: #5A6B7E !important; }

    /* Tabela de campos (key/desc) dentro do card de evento */
    html[data-theme="light"] .ev-doc-card table { color: #0F1F36 !important; }
    html[data-theme="light"] .ev-doc-card table td { border-color: rgba(15,31,54,0.06) !important; }
    html[data-theme="light"] .ev-doc-card code { color: #1E40AF !important; }

    /* Bloco "EXEMPLO DE PAYLOAD COMPLETO" — usa .payload-preview, já coberto */

    /* ─── Aba EXEMPLOS do modal Docs ─── */
    html[data-theme="light"] #modalDocs [data-section="exemplos"] {
      background: #FFFFFF !important;
      border-color: rgba(15,31,54,0.10) !important;
    }
    /* Header "EXEMPLOS DE AUTOMAÇÃO" */
    html[data-theme="light"] #modalDocs [data-section="exemplos"] > div[style*="background:rgba(37,99,235,.14)"] {
      background: #EEF4FF !important;
      color: #1E40AF !important;
    }
    /* Cards de integração (Make / n8n / Zapier / Servidor) */
    html[data-theme="light"] #modalDocs [data-section="exemplos"] div[style*="background:rgba(30,58,95,.15)"] {
      background: #F7F9FC !important;
      border-color: rgba(15,31,54,0.08) !important;
    }
    /* Título dos cards de integração */
    html[data-theme="light"] #modalDocs [data-section="exemplos"] div[style*="color:#dbeafe"] {
      color: #0F1F36 !important;
    }
    /* Descrição dos cards + lista "Casos de uso" (todos com color:#94a3b8) */
    html[data-theme="light"] #modalDocs [data-section="exemplos"] div[style*="color:#94a3b8"],
    html[data-theme="light"] #modalDocs [data-section="exemplos"] ul[style*="color:#94a3b8"] {
      color: #5A6B7E !important;
    }
    /* Bloco verde "Casos de uso prontos" */
    html[data-theme="light"] #modalDocs [data-section="exemplos"] div[style*="background:rgba(5,150,105,.08)"] {
      background: #ECFDF5 !important;
      border-color: rgba(5,150,105,0.25) !important;
    }
    /* Título "Casos de uso prontos" + ícone */
    html[data-theme="light"] #modalDocs [data-section="exemplos"] div[style*="color:#34d399"] {
      color: #047857 !important;
    }
    html[data-theme="light"] #modalDocs [data-section="exemplos"] svg[stroke="#34d399"] {
      stroke: #047857 !important;
    }
    /* <code> dos eventos nos casos de uso (lavanda claro -> azul escuro com fundo) */
    html[data-theme="light"] #modalDocs [data-section="exemplos"] code {
      color: #1E40AF !important;
      background: rgba(30,64,175,0.08) !important;
      padding: 1px 5px;
      border-radius: 3px;
    }

    @media (max-width:900px) {
      .kpi-grid { grid-template-columns:repeat(2,1fr); }
      .field-row { grid-template-columns:1fr; }
      .ev-grid   { grid-template-columns:1fr; }
    }
  </style>
</head>
<body>
  <main style="width:100%;padding:24px;box-sizing:border-box;min-height:100vh">
    <div class="page-layout">
      <?php include __DIR__ . '/includes/sidebar.php'; ?>

      <div class="wh-main">

        <!-- Header -->
        <div class="panel page-header" style="padding:16px 20px">
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
            <div>
              <h2 style="margin:0;font-size:1.25rem;font-weight:700;color:#dbeafe">Webhooks</h2>
              <p style="margin:4px 0 0;font-size:.82rem;color:var(--muted)">Automatize integrações disparando eventos em tempo real para sistemas externos</p>
            </div>
            <div class="toolbar">
              <button class="btn btn-secondary btn-sm" id="btnDocs">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Documentação
              </button>
              <button class="btn btn-secondary btn-sm" id="btnCatalog">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Catálogo de Eventos
              </button>
              <button class="btn btn-primary" id="btnNewWebhook">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Novo Webhook
              </button>
            </div>
          </div>
        </div>

        <!-- KPIs -->
        <div class="kpi-grid">
          <div class="kpi-card"><div class="kpi-dot dot-blue"></div><div class="kpi-label">Total de Webhooks</div><div class="kpi-value" id="kpiTotal">—</div><div class="kpi-foot">endpoints cadastrados</div></div>
          <div class="kpi-card"><div class="kpi-dot dot-ok"></div><div class="kpi-label">Ativos</div><div class="kpi-value" id="kpiAtivos">—</div><div class="kpi-foot">recebendo eventos</div></div>
          <div class="kpi-card"><div class="kpi-dot dot-warn"></div><div class="kpi-label">Entregas hoje</div><div class="kpi-value" id="kpiEntregas">—</div><div class="kpi-foot">disparos nas últimas 24h</div></div>
          <div class="kpi-card"><div class="kpi-dot dot-ok"></div><div class="kpi-label">Taxa de sucesso</div><div class="kpi-value" id="kpiSucesso">—</div><div class="kpi-foot">respostas 2xx</div></div>
        </div>

        <!-- Webhook list -->
        <div class="panel">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <h3 style="margin:0;font-size:.95rem;font-weight:600;color:#dbeafe">Endpoints configurados</h3>
            <button class="btn btn-secondary btn-sm" id="btnRefresh">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
              Atualizar
            </button>
          </div>
          <div class="wh-list" id="whList">
            <div class="wh-empty">Carregando...</div>
          </div>
        </div>

        <!-- Recent logs -->
        <div class="panel">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <h3 style="margin:0;font-size:.95rem;font-weight:600;color:#dbeafe">Log de Entregas Recentes</h3>
            <span id="logsSubtitle" style="font-size:.75rem;color:var(--muted)">últimas 50 entregas</span>
          </div>
          <div style="overflow-x:auto">
            <table class="log-table">
              <thead><tr><th>Evento</th><th>Webhook</th><th>HTTP</th><th>Duração</th><th>Status</th><th>Tent.</th><th>Data</th><th></th></tr></thead>
              <tbody id="logsTbody"><tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)">Carregando...</td></tr></tbody>
            </table>
          </div>
        </div>

      </div><!-- /wh-main -->
    </div>
  </main>

  <!-- ── Modal: Criar/Editar Webhook ── -->
  <div id="modalWebhook" class="modal-overlay hidden">
    <div class="modal">
      <div class="modal-header">
        <span class="modal-title" id="modalWebhookTitle">Novo Webhook</span>
        <button type="button" id="modalWebhookClose" class="btn btn-secondary btn-sm">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <!-- Configuração — FORA da área rolável, sempre visível -->
      <div style="padding:16px 20px;border-bottom:1px solid rgba(96,165,250,.1);display:flex;flex-direction:column;gap:12px">
        <input type="hidden" id="whId">
        <div class="field-group">
          <label class="field-label">Nome do Webhook <span style="color:#f87171">*</span></label>
          <input id="whNome" class="field-input" placeholder="Ex: Notificar advogado no WhatsApp">
        </div>
        <div class="field-group">
          <label class="field-label">URL de destino <span style="color:#f87171">*</span></label>
          <input id="whUrl" class="field-input" placeholder="https://hook.make.com/...">
        </div>
        <div class="field-row">
          <div class="field-group">
            <label class="field-label">Secret HMAC-SHA256 <span style="color:var(--muted);font-weight:400">(opcional)</span></label>
            <input id="whSecret" class="field-input" placeholder="Chave para validar assinatura">
          </div>
          <div class="field-group">
            <label class="field-label">Status</label>
            <select id="whAtivo" class="field-input">
              <option value="1">Ativo</option>
              <option value="0">Inativo</option>
            </select>
          </div>
        </div>
        <details style="margin-top:4px">
          <summary style="cursor:pointer;font-size:.8rem;color:#93c5fd;user-select:none;padding:4px 0">Configurações avançadas (LGPD, retry, escopo)</summary>
          <div style="margin-top:10px;padding-top:10px;border-top:1px dashed rgba(96,165,250,.15);display:flex;flex-direction:column;gap:12px">
            <div class="field-row">
              <div class="field-group">
                <label class="field-label">Modo de payload (LGPD)</label>
                <select id="whPayloadMode" class="field-input">
                  <option value="masked">Mascarado (recomendado)</option>
                  <option value="minimal">Mínimo (só id/type/status)</option>
                  <option value="full">Completo (sem mascaramento)</option>
                </select>
              </div>
              <div class="field-group">
                <label class="field-label">Escopo</label>
                <select id="whEscopo" class="field-input">
                  <option value="tenant_only">Apenas este escritório</option>
                  <option value="matriz_e_filiais">Matriz + filiais vinculadas</option>
                  <option value="filial_only">Apenas filial</option>
                </select>
              </div>
            </div>
            <div class="field-row">
              <div class="field-group">
                <label class="field-label">Timeout (segundos)</label>
                <input id="whTimeout" type="number" min="1" max="60" value="10" class="field-input">
              </div>
              <div class="field-group">
                <label class="field-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:6px">
                  <input type="checkbox" id="whRetryEnabled" style="accent-color:#3b82f6" checked>
                  Retry automático (máx. tentativas)
                </label>
                <input id="whMaxRetries" type="number" min="1" max="10" value="3" class="field-input" placeholder="3">
              </div>
            </div>
            <div class="field-group">
              <label class="field-label">Headers customizados <span style="color:var(--muted);font-weight:400">(JSON, opcional)</span></label>
              <textarea id="whHeadersCustom" class="field-input" rows="2" placeholder='{"Authorization":"Bearer xxx"}' style="font-family:monospace;font-size:.78rem"></textarea>
            </div>
            <div id="whRotateSecretBlock" style="display:none;padding:10px;background:rgba(245,158,11,.06);border-left:3px solid #f59e0b;border-radius:4px">
              <button type="button" id="whRotateSecret" class="btn btn-warn btn-sm">Rotacionar Secret</button>
              <span style="font-size:.75rem;color:#fbbf24;margin-left:8px">Gera novo secret. Mostrado uma única vez.</span>
            </div>
          </div>
        </details>
      </div>

      <!-- Eventos — área rolável separada -->
      <div class="modal-body" id="modalWebhookBody" style="padding-top:12px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <span style="font-size:.82rem;font-weight:600;color:#b8d5f4">Eventos a escutar</span>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.78rem;color:#93c5fd">
            <input type="checkbox" id="chkAllEvents" style="accent-color:#3b82f6"> Todos os eventos
          </label>
        </div>
        <div id="evCatalogContainer" style="display:flex;flex-direction:column;gap:8px"></div>
      </div>
      <div class="modal-footer">
        <button id="whDelete" class="btn btn-danger btn-sm" style="display:none;margin-right:auto">Excluir</button>
        <button id="whTest" class="btn btn-warn btn-sm" style="display:none">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          Testar
        </button>
        <button id="whCancel" class="btn btn-secondary btn-sm">Cancelar</button>
        <button id="whSave" class="btn btn-primary btn-sm">Salvar</button>
      </div>
    </div>
  </div>

  <!-- ── Modal: Documentação ── -->
  <div id="modalDocs" class="modal-overlay hidden">
    <div class="modal" style="width:820px">
      <div class="modal-header">
        <span class="modal-title" style="display:flex;align-items:center;gap:8px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>Documentação dos Webhooks — Yuris</span>
        <button type="button" id="modalDocsClose" class="btn btn-secondary btn-sm">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <!-- Abas de navegação (fora do scroll) -->
      <div style="display:flex;gap:6px;flex-wrap:wrap;padding:12px 20px;border-bottom:1px solid rgba(96,165,250,.1);background:rgba(8,22,44,.4)">
        <?php foreach([
          ['overview','Visão Geral'],
          ['payload','Payload'],
          ['campos','Campos'],
          ['signature','Assinatura'],
          ['headers','Headers'],
          ['eventos','Eventos'],
          ['exemplos','Exemplos'],
        ] as [$key,$label]): ?>
        <button class="doc-tab<?= $key==='overview'?' doc-tab-active':'' ?>" data-tab="<?=$key?>"
          style="padding:5px 14px;border-radius:999px;font-family:inherit;font-size:.76rem;font-weight:600;cursor:pointer;border:1px solid rgba(59,130,246,.3);transition:all .15s">
          <?=$label?>
        </button>
        <?php endforeach; ?>
      </div>

      <div class="modal-body" id="docsBody" style="gap:20px;font-size:.84rem;line-height:1.7;color:#b8d5f4">

        <!-- Visão Geral -->
        <div data-section="overview" style="border:1px solid rgba(96,165,250,.15);border-radius:10px;overflow:hidden">
          <div style="padding:10px 16px;background:rgba(37,99,235,.14);font-size:.75rem;font-weight:700;color:#93c5fd;text-transform:uppercase;letter-spacing:.06em">Visão Geral</div>
          <div style="padding:14px 16px">
            <p style="margin:0 0 10px">Os <strong style="color:#dbeafe">Webhooks do Yuris</strong> permitem que sistemas externos recebam notificações em tempo real sempre que algo acontecer no sistema - prazo criado, um processo atualizado, um cliente convertido, etc.</p>
            <p style="margin:0 0 10px">Cada webhook é um <strong>endpoint HTTP(S) seu</strong> (Make, n8n, Zapier, seu próprio servidor) que o Yuris chama via <code style="background:rgba(0,0,0,.3);padding:1px 5px;border-radius:4px;color:#a5b4fc">POST</code> com um payload JSON padronizado.</p>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px">
              <?php
              $whFeatures = [
                ['<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>','Tempo real','Disparo imediato no momento do evento'],
                ['<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>','Seguro','Assinatura HMAC-SHA256 em cada requisição'],
                ['<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>','Padronizado','Mesmo formato JSON para todos os eventos'],
              ];
              foreach($whFeatures as [$ic,$t,$d]): ?>
              <div style="background:rgba(30,58,95,.2);border:1px solid rgba(96,165,250,.12);border-radius:8px;padding:12px">
                <div style="margin-bottom:8px;display:flex;align-items:center;justify-content:center;width:34px;height:34px;background:rgba(37,99,235,.2);border-radius:8px;border:1px solid rgba(59,130,246,.2)"><?=$ic?></div>
                <div style="font-weight:700;color:#dbeafe;font-size:.82rem"><?=$t?></div>
                <div style="font-size:.75rem;color:var(--muted);margin-top:2px"><?=$d?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Estrutura do Payload -->
        <div data-section="payload" style="border:1px solid rgba(96,165,250,.15);border-radius:10px;overflow:hidden;display:none">
          <div style="padding:10px 16px;background:rgba(37,99,235,.14);font-size:.75rem;font-weight:700;color:#93c5fd;text-transform:uppercase;letter-spacing:.06em">Estrutura do Payload</div>
          <div style="padding:14px 16px">
            <p style="margin:0 0 10px">Todo evento enviado pelo Yuris segue <strong>exatamente</strong> este formato JSON:</p>
            <pre class="payload-preview">{
  "event":         "processo.prazo_created",   <span style="color:#6b7280">// chave única do evento</span>
  "module":        "processo",                  <span style="color:#6b7280">// módulo de origem</span>
  "entity":        "prazo",                     <span style="color:#6b7280">// tipo da entidade afetada</span>
  "entity_id":     123,                         <span style="color:#6b7280">// ID da entidade</span>
  "processo_id":   55,                          <span style="color:#6b7280">// ID do processo (se aplicável)</span>
  "cliente_id":    18,                          <span style="color:#6b7280">// ID do cliente (se aplicável)</span>
  "card_id":       null,                        <span style="color:#6b7280">// ID do card (se aplicável)</span>
  "action":        "created",                   <span style="color:#6b7280">// ação: created | updated | deleted | etc.</span>
  "user_id":       4,                           <span style="color:#6b7280">// usuário que disparou a ação</span>
  "timestamp":     "2026-05-03 18:30:00",       <span style="color:#6b7280">// data/hora UTC</span>
  "data":          {                            <span style="color:#6b7280">// dados atuais da entidade</span>
    "titulo":      "Prazo para contestação",
    "data_prazo":  "2026-05-10",
    "status":      "pendente"
  },
  "previous_data": null                         <span style="color:#6b7280">// dados anteriores (em updates)</span>
}</pre>
          </div>
        </div>

        <!-- Campos detalhados -->
        <div data-section="campos" style="border:1px solid rgba(96,165,250,.15);border-radius:10px;overflow:hidden;display:none">
          <div style="padding:10px 16px;background:rgba(37,99,235,.14);font-size:.75rem;font-weight:700;color:#93c5fd;text-transform:uppercase;letter-spacing:.06em">Descrição dos Campos</div>
          <table style="width:100%;border-collapse:collapse">
            <thead><tr style="border-bottom:1px solid rgba(96,165,250,.1)">
              <th style="padding:8px 16px;text-align:left;font-size:.72rem;color:var(--muted);text-transform:uppercase">Campo</th>
              <th style="padding:8px 16px;text-align:left;font-size:.72rem;color:var(--muted);text-transform:uppercase">Tipo</th>
              <th style="padding:8px 16px;text-align:left;font-size:.72rem;color:var(--muted);text-transform:uppercase">Descrição</th>
            </tr></thead>
            <tbody>
            <?php foreach([
              ['event','string','Chave única do evento. Ex: processo.prazo_created'],
              ['module','string','Módulo de origem: processo, card, cliente, financeiro, usuario, sistema'],
              ['entity','string|null','Tipo da entidade afetada: prazo, tarefa, documento, audiencia, etc.'],
              ['entity_id','int|null','ID primário da entidade afetada'],
              ['processo_id','int|null','ID do processo relacionado (null se não aplicável)'],
              ['cliente_id','int|null','ID do cliente relacionado (null se não aplicável)'],
              ['card_id','int|null','ID do card de prospecção (null se não aplicável)'],
              ['action','string','Ação executada: created, updated, deleted, changed, completed, paid'],
              ['user_id','int|null','ID do usuário do Yuris que executou a ação'],
              ['timestamp','string','Data/hora UTC no formato Y-m-d H:i:s'],
              ['data','object|null','Dados atuais completos da entidade no momento do evento'],
              ['previous_data','object|null','Dados anteriores (preenchido apenas em eventos de update)'],
            ] as [$campo,$tipo,$desc]): ?>
            <tr style="border-bottom:1px solid rgba(96,165,250,.06)">
              <td style="padding:8px 16px"><code style="color:#a5b4fc;font-size:.78rem"><?=$campo?></code></td>
              <td style="padding:8px 16px;font-size:.74rem;color:#64748b;white-space:nowrap"><?=$tipo?></td>
              <td style="padding:8px 16px;font-size:.78rem"><?=$desc?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Assinatura -->
        <div data-section="signature" style="border:1px solid rgba(96,165,250,.15);border-radius:10px;overflow:hidden;display:none">
          <div style="padding:10px 16px;background:rgba(37,99,235,.14);font-size:.75rem;font-weight:700;color:#93c5fd;text-transform:uppercase;letter-spacing:.06em">Verificação de Assinatura (HMAC-SHA256)</div>
          <div style="padding:14px 16px">
            <p style="margin:0 0 10px">Cada requisição inclui o header <code style="background:rgba(0,0,0,.3);padding:1px 5px;border-radius:4px;color:#a5b4fc">X-Yuris-Signature</code> com assinatura HMAC do body. Use o <strong>Secret</strong> configurado no webhook para verificar:</p>
            <pre class="payload-preview"><span style="color:#6b7280">// PHP</span>
$secret    = 'seu_secret_aqui';
$body      = file_get_contents('php://input');
$signature = 'sha256=' . hash_hmac('sha256', $body, $secret);
$received  = $_SERVER['HTTP_X_YURIS_SIGNATURE'] ?? '';

if (!hash_equals($signature, $received)) {
    http_response_code(401);
    die('Assinatura inválida');
}

<span style="color:#6b7280">// Node.js</span>
const crypto = require('crypto');
const sig = 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex');
if (!crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(received))) {
    return res.status(401).send('Invalid signature');
}</pre>
            <p style="margin:10px 0 0;font-size:.77rem;color:var(--muted);display:flex;align-items:center;gap:6px"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Se não configurar um Secret, a assinatura ainda é enviada mas com chave vazia — ideal para testes iniciais.</p>
          </div>
        </div>

        <!-- Headers enviados -->
        <div data-section="headers" style="border:1px solid rgba(96,165,250,.15);border-radius:10px;overflow:hidden;display:none">
          <div style="padding:10px 16px;background:rgba(37,99,235,.14);font-size:.75rem;font-weight:700;color:#93c5fd;text-transform:uppercase;letter-spacing:.06em">Headers HTTP enviados</div>
          <div style="padding:14px 16px">
            <pre class="payload-preview">POST https://seu-endpoint.com/webhook HTTP/1.1
Content-Type: application/json
X-Yuris-Event: processo.prazo_created      <span style="color:#6b7280">// nome do evento</span>
X-Yuris-Signature: sha256=abc123...        <span style="color:#6b7280">// assinatura HMAC</span>
User-Agent: Yuris-Webhook/1.0</pre>
            <p style="margin:10px 0 0;font-size:.77rem;color:var(--muted)">Seu endpoint deve responder com status <strong style="color:#34d399">2xx</strong> em até 10 segundos. Qualquer outro código é registrado como falha no log.</p>
          </div>
        </div>

        <!-- Exemplos de integração -->
        <div data-section="exemplos" style="border:1px solid rgba(96,165,250,.15);border-radius:10px;overflow:hidden;display:none">
          <div style="padding:10px 16px;background:rgba(37,99,235,.14);font-size:.75rem;font-weight:700;color:#93c5fd;text-transform:uppercase;letter-spacing:.06em">Exemplos de Automação</div>
          <div style="padding:14px 16px;display:flex;flex-direction:column;gap:12px">

            <?php
            $integracoes = [
              ['<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>','Make (Integromat)','Use o módulo <strong>Webhooks → Custom webhook</strong>. Cole a URL gerada no campo URL do Yuris. O Make detecta automaticamente a estrutura do payload na primeira entrega.'],
              ['<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>','n8n','Crie um nó <strong>Webhook</strong> no n8n, copie a URL e cole no Yuris. Use o campo <code>event</code> para rotear para fluxos diferentes com um nó Switch.'],
              ['<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>','Zapier','Use o Zap trigger <strong>Webhooks by Zapier → Catch Hook</strong>. Cole a URL no Yuris e use o botão <strong>Testar</strong> para enviar um evento de exemplo.'],
              ['<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>','Servidor próprio','Crie um endpoint POST que leia o body JSON, verifique a assinatura HMAC e processe o campo <code>event</code> para decidir a ação.'],
            ];
            foreach($integracoes as [$ico,$titulo,$desc]): ?>
            <div style="background:rgba(30,58,95,.15);border:1px solid rgba(96,165,250,.1);border-radius:8px;padding:12px">
              <div style="font-weight:700;color:#dbeafe;margin-bottom:4px;display:flex;align-items:center;gap:7px"><span style="opacity:.7"><?=$ico?></span><?=$titulo?></div>
              <div style="font-size:.78rem;color:#94a3b8"><?=$desc?></div>
            </div>
            <?php endforeach; ?>

            <div style="background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.2);border-radius:8px;padding:12px">
              <div style="font-weight:700;color:#34d399;margin-bottom:6px"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Casos de uso prontos</div>
              <ul style="margin:0;padding-left:18px;color:#94a3b8;font-size:.78rem;display:flex;flex-direction:column;gap:4px">
                <li><code style="color:#a5b4fc">processo.prazo_created</code> → Avisar advogado responsável via WhatsApp</li>
                <li><code style="color:#a5b4fc">card.stage_changed</code> → Quando entrar em "Contrato Enviado", gerar PDF da proposta</li>
                <li><code style="color:#a5b4fc">cliente.converted_to_processo</code> → Criar pasta no Google Drive automaticamente</li>
                <li><code style="color:#a5b4fc">processo.tarefa_atrasada</code> → Notificar gestor por e-mail</li>
                <li><code style="color:#a5b4fc">financeiro.overdue</code> → Enviar lembrete de cobrança ao cliente</li>
                <li><code style="color:#a5b4fc">usuario.mentioned</code> → Notificar usuário mencionado no chat interno</li>
              </ul>
            </div>
          </div>
        </div>

        <!-- ── Aba: Eventos ── -->
        <?php
        $evDocs = [
          /* ─ PROSPECÇÃO: Clientes ─ */
          'cliente.created' => ['mod'=>'Prospecção — Clientes','quando'=>'Disparado quando um novo cliente é cadastrado no CRM.','data'=>['id'=>'ID do cliente','nome'=>'Nome completo','email'=>'E-mail','telefone'=>'Telefone','origem'=>'Canal de origem (site, indicação…)','responsavel_id'=>'ID do usuário responsável'],'action'=>'created'],
          'cliente.updated' => ['mod'=>'Prospecção — Clientes','quando'=>'Disparado quando dados cadastrais do cliente são alterados.','data'=>['id'=>'ID do cliente','campos_alterados'=>'Array com os campos que mudaram'],'action'=>'updated'],
          'cliente.deleted' => ['mod'=>'Prospecção — Clientes','quando'=>'Disparado quando o cliente é excluído (soft delete).','data'=>['id'=>'ID do cliente','nome'=>'Nome do cliente excluído'],'action'=>'deleted'],
          'cliente.converted_to_processo' => ['mod'=>'Prospecção — Clientes','quando'=>'Disparado quando um card/cliente é convertido em processo jurídico.','data'=>['cliente_id'=>'ID do cliente','processo_id'=>'ID do processo criado','responsavel_id'=>'Advogado responsável'],'action'=>'converted'],
          /* ─ PROSPECÇÃO: Cards ─ */
          'card.created' => ['mod'=>'Prospecção — Cards','quando'=>'Novo card de lead ou oportunidade criado no funil.','data'=>['id'=>'ID do card','titulo'=>'Título / nome do lead','etapa'=>'Etapa atual do funil','responsavel_id'=>'Responsável','valor'=>'Valor estimado da oportunidade'],'action'=>'created'],
          'card.updated' => ['mod'=>'Prospecção — Cards','quando'=>'Qualquer campo do card é atualizado.','data'=>['id'=>'ID do card','campos_alterados'=>'Campos que mudaram'],'action'=>'updated'],
          'card.deleted' => ['mod'=>'Prospecção — Cards','quando'=>'Card excluído do funil.','data'=>['id'=>'ID do card','titulo'=>'Título do card'],'action'=>'deleted'],
          'card.stage_changed' => ['mod'=>'Prospecção — Cards','quando'=>'Card é movido de uma etapa do funil para outra.','data'=>['id'=>'ID do card','etapa_anterior'=>'Nome da etapa anterior','etapa_nova'=>'Nome da nova etapa','responsavel_id'=>'Responsável pelo card'],'action'=>'stage_changed'],
          'card.responsavel_changed' => ['mod'=>'Prospecção — Cards','quando'=>'Responsável pelo card é alterado.','data'=>['id'=>'ID do card','responsavel_anterior_id'=>'ID anterior','responsavel_novo_id'=>'Novo ID'],'action'=>'changed'],
          'card.tag_added' => ['mod'=>'Prospecção — Cards','quando'=>'Nova tag é adicionada ao card.','data'=>['card_id'=>'ID do card','tag'=>'Nome da tag adicionada'],'action'=>'tag_added'],
          'card.comment_added' => ['mod'=>'Prospecção — Cards','quando'=>'Comentário adicionado ao card.','data'=>['card_id'=>'ID do card','comentario_id'=>'ID do comentário','texto'=>'Conteúdo do comentário','autor_id'=>'ID do autor'],'action'=>'created'],
          'card.file_uploaded' => ['mod'=>'Prospecção — Cards','quando'=>'Arquivo enviado ao card.','data'=>['card_id'=>'ID do card','arquivo_nome'=>'Nome do arquivo','arquivo_url'=>'URL de download','tamanho_bytes'=>'Tamanho em bytes'],'action'=>'uploaded'],
          'card.followup_created' => ['mod'=>'Prospecção — Cards','quando'=>'Follow-up agendado no card.','data'=>['card_id'=>'ID do card','data_followup'=>'Data do follow-up','descricao'=>'Descrição','responsavel_id'=>'Responsável'],'action'=>'created'],
          'card.followup_completed' => ['mod'=>'Prospecção — Cards','quando'=>'Follow-up marcado como concluído.','data'=>['card_id'=>'ID do card','followup_id'=>'ID do follow-up','concluido_por_id'=>'Quem concluiu'],'action'=>'completed'],
          'card.dados_pessoais.updated' => ['mod'=>'Abas do Card','quando'=>'Aba Dados Pessoais do card foi salva.','data'=>['card_id'=>'ID do card','aba'=>'"dados_pessoais"','campos'=>'Objeto com os dados atualizados'],'action'=>'updated'],
          'card.atendimento.updated' => ['mod'=>'Abas do Card','quando'=>'Aba Atendimento salva.','data'=>['card_id'=>'ID do card','aba'=>'"atendimento"','campos'=>'Dados atualizados'],'action'=>'updated'],
          'card.qualificacao.updated' => ['mod'=>'Abas do Card','quando'=>'Aba Qualificação salva.','data'=>['card_id'=>'ID do card','aba'=>'"qualificacao"','campos'=>'Dados atualizados'],'action'=>'updated'],
          'card.documentos.updated' => ['mod'=>'Abas do Card','quando'=>'Aba Documentos do card atualizada.','data'=>['card_id'=>'ID do card','aba'=>'"documentos"','arquivos_count'=>'Total de arquivos'],'action'=>'updated'],
          'card.observacoes.updated' => ['mod'=>'Abas do Card','quando'=>'Aba Observações salva.','data'=>['card_id'=>'ID do card','aba'=>'"observacoes"','texto'=>'Texto das observações'],'action'=>'updated'],
          'card.historico.updated' => ['mod'=>'Abas do Card','quando'=>'Registro inserido no histórico do card.','data'=>['card_id'=>'ID do card','entrada'=>'Descrição do evento registrado','tipo'=>'Tipo do registro'],'action'=>'updated'],
          'card.financeiro.updated' => ['mod'=>'Abas do Card','quando'=>'Aba Financeiro do card salva.','data'=>['card_id'=>'ID do card','valor_total'=>'Valor total','status_pagamento'=>'Status'],'action'=>'updated'],
          'card.contrato.updated' => ['mod'=>'Abas do Card','quando'=>'Aba Contrato do card atualizada (status, assinatura, etc.).','data'=>['card_id'=>'ID do card','status_contrato'=>'Status do contrato','data_assinatura'=>'Data de assinatura (se assinado)'],'action'=>'updated'],
          /* ─ PROCESSOS ─ */
          'processo.created' => ['mod'=>'Processos / Jurídico','quando'=>'Novo processo jurídico aberto no sistema.','data'=>['id'=>'ID do processo','numero'=>'Número processual','tipo'=>'Tipo/área do direito','status'=>'Status inicial','responsavel_id'=>'Advogado responsável','cliente_id'=>'ID do cliente','vara'=>'Vara ou tribunal'],'action'=>'created'],
          'processo.updated' => ['mod'=>'Processos / Jurídico','quando'=>'Dados gerais do processo atualizados.','data'=>['id'=>'ID do processo','campos_alterados'=>'Campos que mudaram'],'action'=>'updated'],
          'processo.deleted' => ['mod'=>'Processos / Jurídico','quando'=>'Processo excluído.','data'=>['id'=>'ID do processo','numero'=>'Número processual'],'action'=>'deleted'],
          'processo.status_changed' => ['mod'=>'Processos / Jurídico','quando'=>'Status do processo alterado (ativo, arquivado, encerrado…).','data'=>['id'=>'ID do processo','status_anterior'=>'Status antes','status_novo'=>'Novo status'],'action'=>'changed'],
          'processo.responsavel_changed' => ['mod'=>'Processos / Jurídico','quando'=>'Advogado responsável alterado.','data'=>['id'=>'ID do processo','responsavel_anterior_id'=>'ID anterior','responsavel_novo_id'=>'Novo ID'],'action'=>'changed'],
          'processo.etapa_changed' => ['mod'=>'Processos / Jurídico','quando'=>'Etapa processual alterada (conhecimento, recurso, execução…).','data'=>['id'=>'ID do processo','etapa_anterior'=>'Etapa anterior','etapa_nova'=>'Nova etapa'],'action'=>'changed'],
          'processo.prazo_created' => ['mod'=>'Processos / Jurídico','quando'=>'Novo prazo cadastrado no processo.','data'=>['prazo_id'=>'ID do prazo','titulo'=>'Título do prazo','data_prazo'=>'Data limite','tipo'=>'Tipo (fatal, regular)','responsavel_id'=>'Responsável','processo_id'=>'ID do processo'],'action'=>'created'],
          'processo.prazo_updated' => ['mod'=>'Processos / Jurídico','quando'=>'Prazo editado (data, título ou responsável).','data'=>['prazo_id'=>'ID','titulo'=>'Título','data_prazo'=>'Nova data','responsavel_id'=>'Responsável'],'action'=>'updated'],
          'processo.prazo_completed' => ['mod'=>'Processos / Jurídico','quando'=>'Prazo marcado como cumprido.','data'=>['prazo_id'=>'ID do prazo','titulo'=>'Título','concluido_em'=>'Data de conclusão','concluido_por_id'=>'Quem concluiu'],'action'=>'completed'],
          'processo.prazo_vencendo' => ['mod'=>'Processos / Jurídico','quando'=>'Prazo faltando ≤ 3 dias para vencer (disparado por scheduler/cron).','data'=>['prazo_id'=>'ID','titulo'=>'Título','data_prazo'=>'Data limite','dias_restantes'=>'Dias até vencer','responsavel_id'=>'Responsável'],'action'=>'alert'],
          'processo.tarefa_created' => ['mod'=>'Processos / Jurídico','quando'=>'Nova tarefa criada no processo.','data'=>['tarefa_id'=>'ID da tarefa','titulo'=>'Título','data_limite'=>'Data limite','responsavel_id'=>'Responsável','prioridade'=>'alta | media | baixa'],'action'=>'created'],
          'processo.tarefa_completed' => ['mod'=>'Processos / Jurídico','quando'=>'Tarefa concluída.','data'=>['tarefa_id'=>'ID','titulo'=>'Título','concluido_em'=>'Data','concluido_por_id'=>'Quem concluiu'],'action'=>'completed'],
          'processo.tarefa_atrasada' => ['mod'=>'Processos / Jurídico','quando'=>'Tarefa com prazo vencido sem conclusão (disparada por cron).','data'=>['tarefa_id'=>'ID','titulo'=>'Título','data_limite'=>'Data que passou','dias_atraso'=>'Dias em atraso','responsavel_id'=>'Responsável'],'action'=>'overdue'],
          'processo.andamento_added' => ['mod'=>'Processos / Jurídico','quando'=>'Andamento processual registrado.','data'=>['andamento_id'=>'ID','descricao'=>'Texto do andamento','data_andamento'=>'Data do evento','tipo'=>'Tipo do andamento','autor_id'=>'Quem registrou'],'action'=>'created'],
          'processo.documento_uploaded' => ['mod'=>'Processos / Jurídico','quando'=>'Documento enviado ao processo.','data'=>['arquivo_nome'=>'Nome do arquivo','arquivo_url'=>'URL','tipo_documento'=>'Categoria do documento','tamanho_bytes'=>'Tamanho','enviado_por_id'=>'Usuário'],'action'=>'uploaded'],
          'processo.audiencia_created' => ['mod'=>'Processos / Jurídico','quando'=>'Audiência cadastrada no processo.','data'=>['audiencia_id'=>'ID','tipo'=>'Tipo da audiência','data_audiencia'=>'Data e hora','local'=>'Local/link','responsavel_id'=>'Responsável'],'action'=>'created'],
          'processo.audiencia_updated' => ['mod'=>'Processos / Jurídico','quando'=>'Dados da audiência alterados.','data'=>['audiencia_id'=>'ID','campos_alterados'=>'O que mudou'],'action'=>'updated'],
          'processo.audiencia_realizada' => ['mod'=>'Processos / Jurídico','quando'=>'Audiência marcada como realizada.','data'=>['audiencia_id'=>'ID','realizada_em'=>'Data de realização','resultado'=>'Resultado registrado'],'action'=>'completed'],
          /* Abas do processo */
          'processo.dados_gerais.updated' => ['mod'=>'Abas do Processo','quando'=>'Aba Dados Gerais do processo salva.','data'=>['processo_id'=>'ID','aba'=>'"dados_gerais"','campos'=>'Dados atualizados'],'action'=>'updated'],
          'processo.partes.updated' => ['mod'=>'Abas do Processo','quando'=>'Aba Partes atualizada (autor, réu, advogados).','data'=>['processo_id'=>'ID','partes'=>'Array com as partes'],'action'=>'updated'],
          'processo.andamento_processual.updated' => ['mod'=>'Abas do Processo','quando'=>'Novo registro na aba Andamento Processual.','data'=>['processo_id'=>'ID','andamento_id'=>'ID do andamento','descricao'=>'Texto'],'action'=>'updated'],
          'processo.prazos.updated' => ['mod'=>'Abas do Processo','quando'=>'Qualquer alteração na aba Prazos.','data'=>['processo_id'=>'ID','prazo_id'=>'ID do prazo afetado','acao'=>'created | updated | deleted'],'action'=>'updated'],
          'processo.tarefas.updated' => ['mod'=>'Abas do Processo','quando'=>'Qualquer alteração na aba Tarefas.','data'=>['processo_id'=>'ID','tarefa_id'=>'ID da tarefa','acao'=>'created | updated | completed'],'action'=>'updated'],
          'processo.documentos.updated' => ['mod'=>'Abas do Processo','quando'=>'Aba Documentos atualizada.','data'=>['processo_id'=>'ID','arquivo_nome'=>'Arquivo','acao'=>'uploaded | deleted'],'action'=>'updated'],
          'processo.audiencias.updated' => ['mod'=>'Abas do Processo','quando'=>'Aba Audiências atualizada.','data'=>['processo_id'=>'ID','audiencia_id'=>'ID','acao'=>'created | updated | realizada'],'action'=>'updated'],
          'processo.financeiro.updated' => ['mod'=>'Abas do Processo','quando'=>'Aba Financeiro do processo atualizada.','data'=>['processo_id'=>'ID','lancamento_id'=>'ID do lançamento','tipo'=>'receita | despesa','valor'=>'Valor'],'action'=>'updated'],
          'processo.honorarios.updated' => ['mod'=>'Abas do Processo','quando'=>'Aba Honorários salva.','data'=>['processo_id'=>'ID','tipo_honorario'=>'fixo | percentual | êxito','valor'=>'Valor acordado'],'action'=>'updated'],
          'processo.observacoes.updated' => ['mod'=>'Abas do Processo','quando'=>'Aba Observações do processo salva.','data'=>['processo_id'=>'ID','texto'=>'Conteúdo das observações'],'action'=>'updated'],
          'processo.historico.updated' => ['mod'=>'Abas do Processo','quando'=>'Registro inserido no histórico do processo.','data'=>['processo_id'=>'ID','entrada'=>'Descrição','tipo'=>'Tipo do registro','autor_id'=>'Quem registrou'],'action'=>'updated'],
          /* ─ FINANCEIRO ─ */
          'financeiro.receita_created' => ['mod'=>'Financeiro','quando'=>'Nova receita lançada no financeiro.','data'=>['id'=>'ID do lançamento','descricao'=>'Descrição','valor'=>'Valor em R$','data_vencimento'=>'Vencimento','processo_id'=>'Processo vinculado (se houver)','cliente_id'=>'Cliente vinculado'],'action'=>'created'],
          'financeiro.despesa_created' => ['mod'=>'Financeiro','quando'=>'Nova despesa lançada.','data'=>['id'=>'ID','descricao'=>'Descrição','valor'=>'Valor','data_vencimento'=>'Vencimento','categoria'=>'Categoria da despesa'],'action'=>'created'],
          'financeiro.updated' => ['mod'=>'Financeiro','quando'=>'Lançamento financeiro editado.','data'=>['id'=>'ID','campos_alterados'=>'Campos alterados'],'action'=>'updated'],
          'financeiro.status_changed' => ['mod'=>'Financeiro','quando'=>'Status do lançamento alterado (pendente → pago, etc.).','data'=>['id'=>'ID','status_anterior'=>'Status antes','status_novo'=>'Novo status'],'action'=>'changed'],
          'financeiro.paid' => ['mod'=>'Financeiro','quando'=>'Lançamento marcado como pago/recebido.','data'=>['id'=>'ID','valor'=>'Valor pago','data_pagamento'=>'Data efetiva do pagamento','forma_pagamento'=>'PIX, boleto, TED…'],'action'=>'paid'],
          'financeiro.overdue' => ['mod'=>'Financeiro','quando'=>'Lançamento passou da data de vencimento sem pagamento (cron diário).','data'=>['id'=>'ID','descricao'=>'Descrição','valor'=>'Valor','data_vencimento'=>'Vencimento','dias_atraso'=>'Dias em atraso'],'action'=>'overdue'],
          'financeiro.deleted' => ['mod'=>'Financeiro','quando'=>'Lançamento excluído.','data'=>['id'=>'ID','descricao'=>'Descrição do lançamento'],'action'=>'deleted'],
          'financeiro.parcela_created' => ['mod'=>'Financeiro','quando'=>'Parcela criada em parcelamento.','data'=>['parcela_id'=>'ID','lancamento_pai_id'=>'ID do lançamento principal','numero'=>'Número da parcela','valor'=>'Valor','data_vencimento'=>'Vencimento'],'action'=>'created'],
          'financeiro.parcela_paid' => ['mod'=>'Financeiro','quando'=>'Parcela paga.','data'=>['parcela_id'=>'ID','numero'=>'Nº da parcela','valor'=>'Valor pago','data_pagamento'=>'Data'],'action'=>'paid'],
          'financeiro.relatorio_dre' => ['mod'=>'Financeiro','quando'=>'DRE gerado/exportado.','data'=>['periodo_inicio'=>'Início do período','periodo_fim'=>'Fim do período','receita_total'=>'Total de receitas','despesa_total'=>'Total de despesas','lucro'=>'Resultado'],'action'=>'generated'],
          /* ─ USUÁRIOS ─ */
          'usuario.created' => ['mod'=>'Usuários / Equipe','quando'=>'Novo usuário cadastrado no sistema.','data'=>['id'=>'ID do usuário','nome'=>'Nome','email'=>'E-mail','perfil'=>'admin | user | manager'],'action'=>'created'],
          'usuario.updated' => ['mod'=>'Usuários / Equipe','quando'=>'Dados do usuário atualizados.','data'=>['id'=>'ID','campos_alterados'=>'Campos alterados'],'action'=>'updated'],
          'usuario.deleted' => ['mod'=>'Usuários / Equipe','quando'=>'Usuário excluído (soft delete).','data'=>['id'=>'ID','nome'=>'Nome do usuário'],'action'=>'deleted'],
          'usuario.permission_changed' => ['mod'=>'Usuários / Equipe','quando'=>'Permissões de acesso às páginas foram alteradas.','data'=>['id'=>'ID','permissoes_novas'=>'Array de pages permitidas','alterado_por_id'=>'Admin que fez a mudança'],'action'=>'changed'],
          'usuario.mentioned' => ['mod'=>'Usuários / Equipe','quando'=>'Usuário mencionado em mensagem do chat interno (@nome).','data'=>['usuario_id'=>'ID do mencionado','mencionado_por_id'=>'ID de quem mencionou','conversa_id'=>'ID da conversa','mensagem_id'=>'ID da mensagem','contexto'=>'Texto ao redor da menção'],'action'=>'mentioned'],
          'usuario.login' => ['mod'=>'Usuários / Equipe','quando'=>'Usuário faz login no sistema.','data'=>['id'=>'ID do usuário','nome'=>'Nome','ip'=>'IP de origem','user_agent'=>'Navegador/dispositivo'],'action'=>'login'],
          'usuario.senha_changed' => ['mod'=>'Usuários / Equipe','quando'=>'Senha do usuário alterada.','data'=>['id'=>'ID do usuário','alterado_por_id'=>'Quem alterou (pode ser o próprio)'],'action'=>'updated'],
          'usuario.activated'         => ['mod'=>'Usuários / Equipe','quando'=>'Usuário (re)ativado após estar inativo.','data'=>['id'=>'ID do usuário','ativado_por_id'=>'Admin que ativou'],'action'=>'activated'],
          'usuario.deactivated'       => ['mod'=>'Usuários / Equipe','quando'=>'Usuário desativado (perde acesso, dados preservados).','data'=>['id'=>'ID do usuário','desativado_por_id'=>'Admin','motivo'=>'Motivo (se informado)'],'action'=>'deactivated'],
          'usuario.role_changed'      => ['mod'=>'Usuários / Equipe','quando'=>'Papel (role) do usuário alterado (owner | admin | manager | user | viewer).','data'=>['id'=>'ID do usuário','role_anterior'=>'Role antes','role_novo'=>'Nova role','alterado_por_id'=>'Quem alterou'],'action'=>'role_changed'],
          'usuario.password_changed'  => ['mod'=>'Usuários / Equipe','quando'=>'Senha alterada (versão padronizada — sem dados sensíveis).','data'=>['id'=>'ID do usuário','alterado_por_id'=>'Quem alterou','self_service'=>'true se foi o próprio'],'action'=>'password_changed'],
          'usuario.login_success'     => ['mod'=>'Usuários / Equipe','quando'=>'Login bem-sucedido (substitui usuario.login a longo prazo).','data'=>['id'=>'ID do usuário','ip'=>'IP de origem','user_agent'=>'Navegador','mfa_used'=>'true se 2FA validado'],'action'=>'login_success'],
          'usuario.login_failed'      => ['mod'=>'Usuários / Equipe','quando'=>'Tentativa de login com credenciais inválidas (rate-limit dispara após 5 falhas em 15min).','data'=>['login_tentado'=>'Login digitado (mascarado)','ip'=>'IP de origem','motivo'=>'invalid_password | user_not_found | inactive_account'],'action'=>'login_failed'],
          'usuario.logout'            => ['mod'=>'Usuários / Equipe','quando'=>'Usuário fez logout (sessão encerrada).','data'=>['id'=>'ID do usuário','ip'=>'IP'],'action'=>'logout'],
          'usuario.2fa_enabled'       => ['mod'=>'Usuários / Equipe','quando'=>'2FA TOTP ativado para o usuário.','data'=>['id'=>'ID do usuário'],'action'=>'2fa_enabled'],
          'usuario.2fa_disabled'      => ['mod'=>'Usuários / Equipe','quando'=>'2FA desativado pelo próprio usuário ou admin.','data'=>['id'=>'ID do usuário','desativado_por_id'=>'Quem desativou'],'action'=>'2fa_disabled'],
          'usuario.2fa_required'      => ['mod'=>'Usuários / Equipe','quando'=>'Tentativa de acesso sem 2FA quando exigido pelo papel do usuário (ex: super_admin).','data'=>['id'=>'ID do usuário','ip'=>'IP'],'action'=>'2fa_required'],
          /* ─ ADVOGADOS ─ */
          'advogado.created'          => ['mod'=>'Advogados','quando'=>'Novo advogado cadastrado no sistema.','data'=>['id'=>'ID do advogado','nome'=>'Nome completo','oab'=>'Número OAB','uf_oab'=>'UF da OAB','email'=>'E-mail'],'action'=>'created'],
          'advogado.updated'          => ['mod'=>'Advogados','quando'=>'Dados do advogado atualizados.','data'=>['id'=>'ID','campos_alterados'=>'Array com campos que mudaram'],'action'=>'updated'],
          'advogado.activated'        => ['mod'=>'Advogados','quando'=>'Advogado ativado (volta a aparecer em listas/atribuições).','data'=>['id'=>'ID','ativado_por_id'=>'Admin'],'action'=>'activated'],
          'advogado.deactivated'      => ['mod'=>'Advogados','quando'=>'Advogado desativado.','data'=>['id'=>'ID','desativado_por_id'=>'Admin','motivo'=>'Motivo (se informado)'],'action'=>'deactivated'],
          'advogado.deleted'          => ['mod'=>'Advogados','quando'=>'Advogado excluído (soft delete).','data'=>['id'=>'ID','nome'=>'Nome'],'action'=>'deleted'],
          'advogado.linked_to_matriz' => ['mod'=>'Advogados','quando'=>'Advogado vinculado à matriz.','data'=>['advogado_id'=>'ID do advogado','matriz_account_id'=>'ID da matriz','vinculado_por_id'=>'Quem fez o vínculo'],'action'=>'linked'],
          'advogado.linked_to_filial' => ['mod'=>'Advogados','quando'=>'Advogado vinculado a uma filial específica.','data'=>['advogado_id'=>'ID do advogado','filial_account_id'=>'ID da filial','vinculado_por_id'=>'Quem fez'],'action'=>'linked'],
          'advogado.unlinked'         => ['mod'=>'Advogados','quando'=>'Vínculo do advogado com matriz ou filial removido.','data'=>['advogado_id'=>'ID','account_id_anterior'=>'Conta da qual saiu','removido_por_id'=>'Quem removeu'],'action'=>'unlinked'],
          'advogado.oab_updated'      => ['mod'=>'Advogados','quando'=>'OAB do advogado atualizada (número ou UF).','data'=>['id'=>'ID','oab_anterior'=>'OAB antiga','oab_nova'=>'Nova OAB','uf_anterior'=>'UF antiga','uf_nova'=>'Nova UF'],'action'=>'oab_updated'],
          /* ─ LEADS ─ */
          'lead.created'        => ['mod'=>'Leads','quando'=>'Novo lead capturado (formulário, WhatsApp, importação, manual).','data'=>['id'=>'ID do lead','nome'=>'Nome','email'=>'E-mail','telefone'=>'Telefone','origem'=>'Canal de origem','responsavel_id'=>'Responsável'],'action'=>'created'],
          'lead.updated'        => ['mod'=>'Leads','quando'=>'Dados do lead atualizados.','data'=>['id'=>'ID','campos_alterados'=>'Campos que mudaram'],'action'=>'updated'],
          'lead.converted'      => ['mod'=>'Leads','quando'=>'Lead convertido em cliente (cliente_id é gerado).','data'=>['lead_id'=>'ID do lead','cliente_id'=>'ID do cliente criado','convertido_por_id'=>'Usuário que converteu'],'action'=>'converted'],
          'lead.deleted'        => ['mod'=>'Leads','quando'=>'Lead excluído.','data'=>['id'=>'ID','nome'=>'Nome do lead excluído'],'action'=>'deleted'],
          'lead.status_changed' => ['mod'=>'Leads','quando'=>'Status do lead alterado (novo, contatado, qualificado, perdido…).','data'=>['id'=>'ID','status_anterior'=>'Status antes','status_novo'=>'Novo status','alterado_por_id'=>'Quem alterou'],'action'=>'status_changed'],
          /* ─ CONTATOS ─ */
          'contato.created' => ['mod'=>'Contatos','quando'=>'Novo contato criado (independente de lead/cliente).','data'=>['id'=>'ID do contato','nome'=>'Nome','telefone'=>'Telefone','email'=>'E-mail','tipo'=>'pessoa | empresa'],'action'=>'created'],
          'contato.updated' => ['mod'=>'Contatos','quando'=>'Dados do contato atualizados.','data'=>['id'=>'ID','campos_alterados'=>'Campos que mudaram'],'action'=>'updated'],
          'contato.deleted' => ['mod'=>'Contatos','quando'=>'Contato excluído.','data'=>['id'=>'ID','nome'=>'Nome'],'action'=>'deleted'],
          /* ─ PIPELINE / FUNIL ─ */
          'pipeline.created'       => ['mod'=>'Funil / Pipeline','quando'=>'Novo funil de vendas criado.','data'=>['id'=>'ID do funil','nome'=>'Nome','criado_por_id'=>'Usuário'],'action'=>'created'],
          'pipeline.updated'       => ['mod'=>'Funil / Pipeline','quando'=>'Funil renomeado ou reconfigurado.','data'=>['id'=>'ID','campos_alterados'=>'Campos alterados'],'action'=>'updated'],
          'pipeline.stage_created' => ['mod'=>'Funil / Pipeline','quando'=>'Nova etapa adicionada ao funil.','data'=>['pipeline_id'=>'ID do funil','stage_id'=>'ID da etapa','nome'=>'Nome da etapa','ordem'=>'Posição no funil'],'action'=>'stage_created'],
          'pipeline.stage_updated' => ['mod'=>'Funil / Pipeline','quando'=>'Etapa renomeada ou movida de posição.','data'=>['pipeline_id'=>'ID','stage_id'=>'ID da etapa','campos_alterados'=>'O que mudou'],'action'=>'stage_updated'],
          'pipeline.stage_deleted' => ['mod'=>'Funil / Pipeline','quando'=>'Etapa excluída do funil (cards associados são realocados).','data'=>['pipeline_id'=>'ID','stage_id'=>'ID excluído','cards_realocados'=>'Quantos cards foram movidos'],'action'=>'stage_deleted'],
          /* ─ WHATSAPP / CONVERSAS ─ */
          'whatsapp.message_received'        => ['mod'=>'WhatsApp / Conversas','quando'=>'Mensagem inbound recebida via WhatsApp (Evolution API).','data'=>['conversa_id'=>'ID da conversa','contato_id'=>'ID do contato','telefone'=>'Telefone (mascarado)','direcao'=>'"inbound"','tipo_midia'=>'text | audio | image | document','instancia'=>'Nome da instância Evolution'],'action'=>'message_received'],
          'whatsapp.message_sent'            => ['mod'=>'WhatsApp / Conversas','quando'=>'Mensagem outbound enviada via WhatsApp.','data'=>['conversa_id'=>'ID','contato_id'=>'ID','telefone'=>'Telefone (mascarado)','direcao'=>'"outbound"','enviado_por_id'=>'Usuário ou bot','tipo_midia'=>'text | audio | image | document'],'action'=>'message_sent'],
          'whatsapp.conversation_started'    => ['mod'=>'WhatsApp / Conversas','quando'=>'Nova conversa iniciada (primeira mensagem trocada).','data'=>['conversa_id'=>'ID','contato_id'=>'ID','telefone'=>'Telefone','canal'=>'"whatsapp"','iniciada_por'=>'inbound | outbound'],'action'=>'conversation_started'],
          'whatsapp.conversation_closed'     => ['mod'=>'WhatsApp / Conversas','quando'=>'Conversa marcada como encerrada.','data'=>['conversa_id'=>'ID','contato_id'=>'ID','encerrada_por_id'=>'Usuário','motivo'=>'resolvido | abandonada | spam'],'action'=>'conversation_closed'],
          'whatsapp.conversation_assigned'   => ['mod'=>'WhatsApp / Conversas','quando'=>'Conversa atribuída a um atendente.','data'=>['conversa_id'=>'ID','contato_id'=>'ID','responsavel_id'=>'Usuário atribuído','atribuido_por_id'=>'Quem atribuiu'],'action'=>'conversation_assigned'],
          'whatsapp.conversation_unassigned' => ['mod'=>'WhatsApp / Conversas','quando'=>'Atribuição removida (volta pra fila).','data'=>['conversa_id'=>'ID','responsavel_anterior_id'=>'ID anterior','removido_por_id'=>'Quem removeu'],'action'=>'conversation_unassigned'],
          'whatsapp.handoff_requested'       => ['mod'=>'WhatsApp / Conversas','quando'=>'Bot/agente IA solicita transferência pra humano.','data'=>['conversa_id'=>'ID','contato_id'=>'ID','motivo'=>'Motivo do handoff','agente_origem'=>'Nome do bot/agente'],'action'=>'handoff_requested'],
          'whatsapp.handoff_completed'       => ['mod'=>'WhatsApp / Conversas','quando'=>'Transferência bot→humano efetivada (humano assumiu).','data'=>['conversa_id'=>'ID','assumido_por_id'=>'Usuário que assumiu','tempo_espera_segundos'=>'Tempo entre handoff_requested e completed'],'action'=>'handoff_completed'],
          /* ─ LGPD / PRIVACIDADE ─ */
          'lgpd.request_created'            => ['mod'=>'LGPD / Privacidade','quando'=>'Titular abriu nova solicitação LGPD (acesso, exclusão, correção, etc.).','data'=>['id'=>'ID da solicitação','tipo'=>'acesso | correcao | anonimizacao | bloqueio | eliminacao | portabilidade | revogacao_consentimento | etc.','status'=>'"aberto"','titular_email'=>'E-mail do titular (mascarado conforme payload_mode)'],'action'=>'created'],
          'lgpd.request_updated'            => ['mod'=>'LGPD / Privacidade','quando'=>'Solicitação LGPD teve mudança de status ou anotação.','data'=>['id'=>'ID','status_anterior'=>'Status antes','status_novo'=>'Novo status','atualizado_por_id'=>'DPO/operador'],'action'=>'updated'],
          'lgpd.request_completed'          => ['mod'=>'LGPD / Privacidade','quando'=>'Solicitação atendida e encerrada com resposta ao titular.','data'=>['id'=>'ID','tipo'=>'Tipo da solicitação','prazo_dias'=>'Dias até resolução'],'action'=>'completed'],
          'lgpd.request_denied'             => ['mod'=>'LGPD / Privacidade','quando'=>'Solicitação rejeitada (com justificativa legal).','data'=>['id'=>'ID','tipo'=>'Tipo','motivo_juridico'=>'Base legal da negativa'],'action'=>'denied'],
          'lgpd.consent_given'              => ['mod'=>'LGPD / Privacidade','quando'=>'Consentimento ativo registrado em lgpd_consents (finalidade != termos_uso_login).','data'=>['id'=>'ID do consent','finalidade'=>'Finalidade do tratamento','base_legal'=>'consentimento | legitimo_interesse | etc.','fonte'=>'Origem do consentimento','titular_email'=>'E-mail (mascarado)'],'action'=>'given'],
          'lgpd.consent_revoked'            => ['mod'=>'LGPD / Privacidade','quando'=>'Titular revogou consentimento previamente concedido.','data'=>['id'=>'ID do consent','finalidade'=>'Finalidade revogada','revogado_em'=>'Data/hora','titular_email'=>'E-mail (mascarado)'],'action'=>'revoked'],
          'lgpd.data_exported'              => ['mod'=>'LGPD / Privacidade','quando'=>'Pacote de dados do titular exportado (DSAR — direito de acesso/portabilidade).','data'=>['request_id'=>'ID da solicitação LGPD','formato'=>'json | csv','arquivos'=>'Quantidade de arquivos','tamanho_total_bytes'=>'Tamanho do pacote'],'action'=>'exported'],
          'lgpd.data_deleted'               => ['mod'=>'LGPD / Privacidade','quando'=>'Dados do titular excluídos (DSAR — direito ao esquecimento).','data'=>['request_id'=>'ID','registros_excluidos'=>'Total de rows removidas','tabelas_afetadas'=>'Lista de tabelas tocadas'],'action'=>'deleted'],
          'lgpd.data_anonymized'            => ['mod'=>'LGPD / Privacidade','quando'=>'Dados anonimizados (mantém registros pra auditoria, remove PII).','data'=>['request_id'=>'ID','registros_anonimizados'=>'Total','tabelas_afetadas'=>'Lista'],'action'=>'anonymized'],
          'lgpd.privacy_document_published' => ['mod'=>'LGPD / Privacidade','quando'=>'Nova versão de Política de Privacidade ou Termos publicada.','data'=>['documento_id'=>'ID','tipo'=>'politica_privacidade | termos_uso | cookies','versao'=>'Número da versão','vigencia_inicio'=>'Data de início','publicado_por_id'=>'Quem publicou'],'action'=>'published'],
          'lgpd.terms_accepted'             => ['mod'=>'LGPD / Privacidade','quando'=>'Termos de uso aceitos (login, signup, banner).','data'=>['id'=>'ID do consent','versao_termo'=>'Versão aceita','fonte'=>'login_form | signup | banner','titular_email'=>'E-mail (mascarado)'],'action'=>'accepted'],
          'lgpd.cookies_accepted'           => ['mod'=>'LGPD / Privacidade','quando'=>'Usuário aceitou política de cookies via banner.','data'=>['categorias_aceitas'=>'Array (necessarios, analytics, marketing)','versao_politica'=>'Versão','ip'=>'IP'],'action'=>'accepted'],
          /* ─ SEGURANÇA ─ */
          'security.incident_created'     => ['mod'=>'Segurança','quando'=>'Novo incidente de segurança registrado (vazamento, acesso indevido, ransomware, etc.).','data'=>['id'=>'ID do incidente','tipo'=>'vazamento_dados | acesso_indevido | ransomware | phishing | etc.','severidade'=>'baixa | media | alta | critica','status'=>'"detectado"','titulo'=>'Título do incidente'],'action'=>'created'],
          'security.incident_updated'     => ['mod'=>'Segurança','quando'=>'Status, severidade ou descrição do incidente alterada.','data'=>['id'=>'ID','status_anterior'=>'Status antes','status_novo'=>'Novo status','atualizado_por_id'=>'DPO/security'],'action'=>'updated'],
          'security.incident_resolved'    => ['mod'=>'Segurança','quando'=>'Incidente marcado como encerrado/mitigado.','data'=>['id'=>'ID','tempo_resolucao_horas'=>'Tempo total','medidas_corretivas'=>'Resumo das ações'],'action'=>'resolved'],
          'security.incident_reported'    => ['mod'=>'Segurança','quando'=>'Incidente reportado externamente (ANPD, titulares afetados).','data'=>['id'=>'ID','reportado_para'=>'anpd | titulares | ambos','data_comunicacao'=>'Data do report'],'action'=>'reported'],
          'security.suspicious_login'     => ['mod'=>'Segurança','quando'=>'Login detectado de localização/dispositivo incomum.','data'=>['user_id'=>'ID do usuário','ip'=>'IP','geo'=>'Localização aproximada','dispositivo'=>'User-Agent','motivo_alerta'=>'novo_geo | novo_dispositivo | horario_atipico'],'action'=>'suspicious_login'],
          'security.access_denied'        => ['mod'=>'Segurança','quando'=>'Acesso negado a recurso por política (não 401, mas 403).','data'=>['user_id'=>'ID','recurso'=>'URL/endpoint','motivo'=>'sem_permissao | tenant_diferente | conta_suspensa'],'action'=>'access_denied'],
          'security.permission_violation' => ['mod'=>'Segurança','quando'=>'Tentativa de uso de permissão que o usuário não tem (IDOR, escalation).','data'=>['user_id'=>'ID','permissao_tentada'=>'Permissão alvo','recurso'=>'URL','ip'=>'IP'],'action'=>'permission_violation'],
          /* ─ AUDITORIA / SISTEMA ─ */
          'audit.log_created'             => ['mod'=>'Auditoria / Sistema','quando'=>'Novo registro inserido em master_audit_log.','data'=>['id'=>'ID do log','acao'=>'Ação registrada','entidade'=>'Entidade afetada','entidade_id'=>'ID da entidade','autor_id'=>'Usuário responsável'],'action'=>'log_created'],
          'system.error'                  => ['mod'=>'Auditoria / Sistema','quando'=>'Erro fatal capturado (exception não tratada).','data'=>['mensagem'=>'Mensagem do erro','arquivo'=>'Arquivo:linha','request_id'=>'ID da request','user_id'=>'Usuário (se logado)'],'action'=>'error'],
          'system.warning'                => ['mod'=>'Auditoria / Sistema','quando'=>'Aviso de sistema (deprecation, config faltando, etc.).','data'=>['mensagem'=>'Texto do aviso','contexto'=>'Onde aconteceu'],'action'=>'warning'],
          'integration.webhook_failed'    => ['mod'=>'Auditoria / Sistema','quando'=>'Webhook outbound falhou após esgotar retries.','data'=>['webhook_endpoint_id'=>'ID do endpoint','delivery_id'=>'ID da delivery','event_code'=>'Evento que falhou','tentativas'=>'Total de tentativas'],'action'=>'webhook_failed'],
          'integration.webhook_recovered' => ['mod'=>'Auditoria / Sistema','quando'=>'Webhook que estava falhando voltou a entregar com sucesso.','data'=>['webhook_endpoint_id'=>'ID','delivery_id'=>'ID atual','tentativas_ate_recuperar'=>'Quantas tentativas'],'action'=>'webhook_recovered'],
          'integration.api_error'         => ['mod'=>'Auditoria / Sistema','quando'=>'Erro em chamada a API externa (DJEN, AASP, Evolution, gateway).','data'=>['integracao'=>'Nome (djen | aasp | evolution | etc.)','endpoint'=>'URL chamada','status_code'=>'HTTP status retornado','mensagem'=>'Mensagem do erro'],'action'=>'api_error'],
          /* ─ SISTEMA ─ */
          'arquivo.uploaded' => ['mod'=>'Sistema','quando'=>'Arquivo enviado a qualquer entidade do sistema.','data'=>['arquivo_nome'=>'Nome','arquivo_url'=>'URL','tamanho_bytes'=>'Tamanho','entidade_tipo'=>'processo | card | usuario','entidade_id'=>'ID da entidade'],'action'=>'uploaded'],
          'comentario.created' => ['mod'=>'Sistema','quando'=>'Comentário criado em qualquer entidade.','data'=>['id'=>'ID do comentário','texto'=>'Conteúdo','autor_id'=>'Autor','entidade_tipo'=>'Entidade pai','entidade_id'=>'ID da entidade pai'],'action'=>'created'],
          'comentario.updated' => ['mod'=>'Sistema','quando'=>'Comentário editado.','data'=>['id'=>'ID','texto_novo'=>'Novo conteúdo','editado_em'=>'Timestamp'],'action'=>'updated'],
          'comentario.deleted' => ['mod'=>'Sistema','quando'=>'Comentário excluído.','data'=>['id'=>'ID','autor_id'=>'Autor original'],'action'=>'deleted'],
          'notificacao.created' => ['mod'=>'Sistema','quando'=>'Notificação interna gerada pelo sistema.','data'=>['id'=>'ID','tipo'=>'Tipo da notificação','mensagem'=>'Texto','destinatario_id'=>'Usuário destinatário'],'action'=>'created'],
          'relatorio.generated' => ['mod'=>'Sistema','quando'=>'Relatório gerado/exportado.','data'=>['tipo'=>'Tipo do relatório','formato'=>'PDF | Excel | CSV','gerado_por_id'=>'Usuário','url'=>'URL de download'],'action'=>'generated'],
          'login.created' => ['mod'=>'Sistema','quando'=>'Acesso ao sistema realizado (equivalente a usuario.login).','data'=>['usuario_id'=>'ID','nome'=>'Nome','ip'=>'IP','timestamp'=>'Data/hora'],'action'=>'login'],
          'agente.resposta' => ['mod'=>'Sistema','quando'=>'Agente IA gerou uma resposta.','data'=>['pergunta'=>'Texto da pergunta','resposta'=>'Resposta gerada','tokens_usados'=>'Tokens consumidos','usuario_id'=>'Quem perguntou'],'action'=>'responded'],
          'whatsapp.mensagem' => ['mod'=>'Sistema','quando'=>'Mensagem recebida via WhatsApp.','data'=>['jid'=>'ID do contato WhatsApp','nome_contato'=>'Nome','mensagem'=>'Conteúdo','tipo'=>'text | audio | image | document','instancia'=>'Instância Evolution API'],'action'=>'received'],
          'whatsapp.vinculo' => ['mod'=>'Sistema','quando'=>'Chat WhatsApp vinculado a processo ou card.','data'=>['jid'=>'ID do chat','processo_id'=>'Processo vinculado','card_id'=>'Card vinculado','usuario_id'=>'ID do usuário vinculado'],'action'=>'linked'],
          'chat.mensagem' => ['mod'=>'Sistema','quando'=>'Mensagem enviada no chat interno do Yuris.','data'=>['mensagem_id'=>'ID','conversa_id'=>'ID da conversa','autor_id'=>'Remetente','texto'=>'Conteúdo (pode ter @menções)','mencoes'=>'Array de IDs mencionados'],'action'=>'created'],
          'webhook.test' => ['mod'=>'Sistema','quando'=>'Disparado manualmente pelo botão Testar no painel de Webhooks.','data'=>['mensagem'=>'Texto de teste','webhook_nome'=>'Nome do webhook testado'],'action'=>'test'],
        ];
        $modColors = [
            'Prospecção — Clientes' => '#60a5fa',
            'Prospecção — Cards'    => '#a78bfa',
            'Abas do Card'          => '#c4b5fd',
            'Processos / Jurídico'  => '#34d399',
            'Abas do Processo'      => '#22d3ee',
            'Financeiro'            => '#fbbf24',
            'Usuários / Equipe'     => '#f472b6',
            'Advogados'             => '#2dd4bf',
            'Leads'                 => '#fb923c',
            'Contatos'              => '#facc15',
            'Funil / Pipeline'      => '#8b5cf6',
            'WhatsApp / Conversas'  => '#25d366',
            'LGPD / Privacidade'    => '#fb7185',
            'Segurança'             => '#ef4444',
            'Auditoria / Sistema'   => '#71717a',
            'Sistema'               => '#94a3b8',
        ];
        ?>
        <div data-section="eventos" style="display:none">
          <!-- Filtros sticky -->
          <div style="position:sticky;top:0;background:linear-gradient(180deg,rgba(7,15,28,1) 85%,transparent);padding-bottom:8px;z-index:10;display:flex;flex-direction:column;gap:8px">
            <!-- Filtros por módulo -->
            <div id="evModFilters" style="display:flex;gap:6px;flex-wrap:wrap">
              <button class="ev-mod-btn ev-mod-active" data-mod="" style="padding:4px 12px;border-radius:999px;font-family:inherit;font-size:.72rem;font-weight:600;cursor:pointer;border:1px solid rgba(148,163,184,.3);background:rgba(148,163,184,.15);color:#94a3b8;transition:all .15s">Todos</button>
              <?php foreach($modColors as $mod => $cor): ?>
              <button class="ev-mod-btn" data-mod="<?=htmlspecialchars($mod)?>"
                style="padding:4px 12px;border-radius:999px;font-family:inherit;font-size:.72rem;font-weight:600;cursor:pointer;border:1px solid <?=$cor?>44;background:<?=$cor?>18;color:<?=$cor?>;transition:all .15s">
                <?=htmlspecialchars($mod)?>
              </button>
              <?php endforeach; ?>
            </div>
            <!-- Busca -->
            <div style="position:relative">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);pointer-events:none">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
              </svg>
              <input id="evSearch" class="field-input" placeholder="Buscar evento (ex: prazo, card, financeiro…)" style="width:100%;padding-left:34px">
            </div>
            <div id="evCount" style="font-size:.72rem;color:var(--muted);padding-left:2px"></div>
          </div>
          <!-- Lista de eventos -->
          <div id="evDocList" style="display:flex;flex-direction:column;gap:10px">
          <?php foreach($evDocs as $key => $ev):
            $mod = $ev['mod'];
            $cor = $modColors[$mod] ?? '#475569';
          ?>
          <div class="ev-doc-card" data-key="<?=htmlspecialchars($key)?>" data-mod="<?=htmlspecialchars($mod)?>"
            style="border:1px solid rgba(96,165,250,.12);border-radius:10px;overflow:hidden">
            <!-- Header do evento -->
            <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:rgba(8,20,40,.7);cursor:pointer" onclick="this.nextElementSibling.classList.toggle('hidden')">
              <div style="flex:1;min-width:0">
                <code style="font-size:.84rem;font-weight:600;color:#dbeafe;display:block;margin-bottom:3px"><?=htmlspecialchars($key)?></code>
                <span style="font-size:.68rem;font-weight:600;padding:1px 7px;border-radius:999px;background:<?=$cor?>22;color:<?=$cor?>;border:1px solid <?=$cor?>44;white-space:nowrap"><?=$mod?></span>
              </div>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <!-- Corpo colapsável -->
            <div class="hidden" style="padding:14px;display:flex;flex-direction:column;gap:12px;border-top:1px solid rgba(96,165,250,.08)">
              <div>
                <div style="font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Quando dispara</div>
                <div style="font-size:.8rem;color:#b8d5f4"><?=htmlspecialchars($ev['quando'])?></div>
              </div>
              <div>
                <div style="font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Campos do objeto <code style="color:#a5b4fc">data</code></div>
                <table style="width:100%;border-collapse:collapse;font-size:.76rem">
                  <?php foreach($ev['data'] as $campo => $desc): ?>
                  <tr style="border-bottom:1px solid rgba(96,165,250,.06)">
                    <td style="padding:5px 10px 5px 0;width:38%"><code style="color:#a5b4fc"><?=htmlspecialchars($campo)?></code></td>
                    <td style="padding:5px 0;color:#94a3b8"><?=htmlspecialchars($desc)?></td>
                  </tr>
                  <?php endforeach; ?>
                </table>
              </div>
              <div>
                <div style="font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Exemplo de payload completo</div>
                <pre class="payload-preview" style="font-size:.72rem">{
  "event":       "<?=htmlspecialchars($key)?>",
  "module":      "<?=htmlspecialchars(explode('.',$key)[0])?>",
  "entity":      "<?=htmlspecialchars($ev['data'] ? array_key_first($ev['data']) : 'null')?>",
  "entity_id":   1,
  "processo_id": <?=str_contains($key,'processo')?'55':'null'?>,
  "cliente_id":  <?=str_contains($key,'cliente')||str_contains($key,'processo')?'18':'null'?>,
  "card_id":     <?=str_contains($key,'card')?'77':'null'?>,
  "action":      "<?=htmlspecialchars($ev['action'])?>",
  "user_id":     4,
  "timestamp":   "<?=date('Y-m-d H:i:s')?>",
  "data": {
<?php $i=0; foreach($ev['data'] as $c=>$d): $i++;
  echo '    "'.htmlspecialchars($c).'": "<'.htmlspecialchars($d).'>"'.($i<count($ev['data'])?',':'')."\n";
endforeach; ?>  },
  "previous_data": null
}</pre>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          </div>
        </div>

      </div><!-- /modal-body -->
    </div>
  </div>

  <!-- ── Modal: Catálogo de Eventos ── -->
  <div id="modalCatalog" class="modal-overlay hidden">
    <div class="modal" style="width:760px">
      <div class="modal-header">
        <span class="modal-title">Catálogo completo de eventos</span>
        <button type="button" id="modalCatalogClose" class="btn btn-secondary btn-sm">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="modal-body" id="catalogBody" style="gap:0;padding:0">
        <?php foreach($catalog as $group): ?>
        <div style="border-bottom:1px solid rgba(96,165,250,.1)">
          <div style="padding:10px 16px;background:rgba(37,99,235,.14);font-size:.74rem;font-weight:700;color:#93c5fd;letter-spacing:.06em;text-transform:uppercase"><?=htmlspecialchars($group['label'])?></div>
          <?php foreach($group['events'] as $key => $desc): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-top:1px solid rgba(96,165,250,.06);gap:12px">
            <div style="min-width:0">
              <div style="font-size:.8rem;color:#c8dff4;margin-bottom:2px"><?=htmlspecialchars($desc)?></div>
              <code style="font-size:.69rem;color:#4b6380"><?=htmlspecialchars($key)?></code>
            </div>
            <span style="font-size:.65rem;padding:2px 8px;border-radius:999px;background:rgba(5,150,105,.2);color:#34d399;border:1px solid rgba(5,150,105,.3);white-space:nowrap;flex-shrink:0">disponível</span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="toast-wrap" id="toastWrap"></div>

  <script>
  const API   = '/sistema_vendas/public/api/webhooks.php';
  const CSRF  = '<?= htmlspecialchars($csrf) ?>';
  const CATALOG = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE) ?>;

  // ── Helpers ──────────────────────────────────────────────────────────────────
  function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function headers(){ return {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF}; }

  function showToast(msg, type='success'){
    const w=document.getElementById('toastWrap');
    const d=document.createElement('div');
    d.className=`toast ${type}`;d.textContent=msg;w.appendChild(d);
    requestAnimationFrame(()=>{ requestAnimationFrame(()=>d.classList.add('show')); });
    setTimeout(()=>{d.classList.remove('show');setTimeout(()=>d.remove(),300)},3500);
  }

  const openModal  = id => document.getElementById(id)?.classList.remove('hidden');
  const closeModal = id => document.getElementById(id)?.classList.add('hidden');

  // ── KPIs ─────────────────────────────────────────────────────────────────────
  async function loadKPIs(){
    try {
      const [wRes, lRes] = await Promise.all([
        fetch(API).then(r=>r.json()),
        fetch(API+'?action=logs&limit=200').then(r=>r.json())
      ]);
      const hooks = wRes.data||[];
      const logs  = lRes.data||[];
      const now   = Date.now();
      const logs24= logs.filter(l => (now - new Date(l.created_at).getTime()) < 86400000);
      const ok24  = logs24.filter(l => l.success==1).length;

      document.getElementById('kpiTotal').textContent    = hooks.length;
      document.getElementById('kpiAtivos').textContent   = hooks.filter(h=>h.ativo==1).length;
      document.getElementById('kpiEntregas').textContent = logs24.length;
      document.getElementById('kpiSucesso').textContent  = logs24.length ? Math.round(ok24/logs24.length*100)+'%' : '—';
    } catch(e){ console.error(e); }
  }

  // ── Webhook list ──────────────────────────────────────────────────────────────
  async function loadWebhooks(){
    const list = document.getElementById('whList');
    list.innerHTML = '<div class="wh-empty">Carregando...</div>';
    try {
      const j = await fetch(API).then(r=>r.json());
      const hooks = j.data||[];
      if (!hooks.length){ list.innerHTML='<div class="wh-empty">Nenhum webhook configurado.<br>Clique em <strong>Novo Webhook</strong> para começar.</div>'; return; }
      list.innerHTML = hooks.map(h=>{
        const ativo    = h.ativo==1;
        const evCount  = (h.event_count||0);
        const rate     = h.success_rate !== null ? h.success_rate+'%' : '—';
        const last     = h.last_delivery ? new Date(h.last_delivery+'Z').toLocaleString('pt-BR') : 'Nunca';
        const rateClass= h.success_rate === null ? 'wh-badge-blue' : (h.success_rate>=90?'wh-badge-ok':'wh-badge-warn');
        return `
        <div class="wh-card">
          <div class="wh-card-status" style="background:${ativo?'#10b981':'#6b7280'}"></div>
          <div class="wh-card-info">
            <div class="wh-card-name">${esc(h.nome)}</div>
            <div class="wh-card-url">${esc(h.url)}</div>
            <div class="wh-card-meta">
              <span class="wh-badge ${ativo?'wh-badge-ok':'wh-badge-off'}">${ativo?'Ativo':'Inativo'}</span>
              <span class="wh-badge wh-badge-blue">${evCount} evento${evCount!==1?'s':''}</span>
              <span class="wh-badge ${rateClass}">${rate} sucesso</span>
              <span style="font-size:.7rem;color:var(--muted)">Última entrega: ${last}</span>
            </div>
          </div>
          <div class="wh-card-actions">
            <button class="btn btn-secondary btn-sm wh-logs-btn" data-id="${h.id}" title="Ver logs">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
              Logs
            </button>
            <button class="btn btn-primary btn-sm wh-edit-btn" data-id="${h.id}">Editar</button>
          </div>
        </div>`;
      }).join('');
    } catch(e){ list.innerHTML='<div class="wh-empty" style="color:#f87171">Erro ao carregar</div>'; }
  }

  // ── Logs table ────────────────────────────────────────────────────────────────
  async function loadLogs(webhookId){
    const tbody = document.getElementById('logsTbody');
    const sub   = document.getElementById('logsSubtitle');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)">Carregando...</td></tr>';
    const url = API+'?action=logs' + (webhookId ? '&id='+webhookId : '');
    sub.textContent  = webhookId ? 'filtrando por webhook' : 'últimas 50 entregas';
    try {
      const j    = await fetch(url).then(r=>r.json());
      const logs = j.data||[];
      if (!logs.length){ tbody.innerHTML='<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)">Nenhuma entrega registrada</td></tr>'; return; }
      const STATUS_MAP = {
        success:  {label:'Sucesso',  color:'#34d399', cls:'log-ok'},
        failed:   {label:'Falhou',   color:'#f87171', cls:'log-fail'},
        retrying: {label:'Retry',    color:'#fbbf24', cls:''},
        pending:  {label:'Pendente', color:'#93c5fd', cls:''},
        canceled: {label:'Cancelada',color:'#a3a3a3', cls:''},
      };
      tbody.innerHTML = logs.map(l=>{
        const st = STATUS_MAP[l.status] || STATUS_MAP.failed;
        const dt = l.created_at ? new Date(l.created_at+'Z').toLocaleString('pt-BR') : '—';
        const terminal = ['success','failed','canceled'].includes(l.status);
        const acoes = terminal
          ? `<button class="btn btn-secondary btn-sm wh-resend-btn" data-delivery="${l.id}" title="Reenviar"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg></button>`
          : `<span style="font-size:.7rem;color:var(--muted)">aguardando</span>`;
        return `<tr>
          <td><code class="log-key">${esc(l.event_key)}</code></td>
          <td style="color:var(--muted);font-size:.75rem">${esc(l.webhook_nome||'#'+l.webhook_id)}</td>
          <td><span style="font-size:.76rem;font-family:monospace;color:${st.color}">${l.response_status||'—'}</span></td>
          <td style="font-size:.75rem;color:var(--muted)">${l.duration_ms!=null?l.duration_ms+'ms':'—'}</td>
          <td><span class="log-status ${st.cls}" style="color:${st.color}">${st.label}</span></td>
          <td style="font-size:.74rem;color:var(--muted);text-align:center">${l.tentativa||1}</td>
          <td style="font-size:.74rem;color:var(--muted)">${dt}</td>
          <td>${acoes}</td>
        </tr>`;
      }).join('');
    } catch(e){ tbody.innerHTML='<tr><td colspan="8" style="text-align:center;color:#f87171">Erro ao carregar logs</td></tr>'; }
  }

  // ── Reenviar delivery (Etapa 9) ───────────────────────────────────────────────
  document.getElementById('logsTbody').addEventListener('click', async function(e){
    const btn = e.target.closest('.wh-resend-btn');
    if (!btn) return;
    const did = btn.dataset.delivery;
    btn.disabled = true;
    showToast('Reenviando...', 'info');
    const j = await fetch(API, {method:'POST',headers:headers(),body:JSON.stringify({action:'resend',delivery_id:did,csrf_token:CSRF})}).then(r=>r.json());
    if (j.success){ showToast('Delivery reenviada','success'); setTimeout(()=>loadLogs(), 800); }
    else { btn.disabled = false; showToast('Erro: '+(j.error||''),'error'); }
  });

  // ── Event catalog UI ──────────────────────────────────────────────────────────
  function buildEvCatalog(checkedKeys){
    const container = document.getElementById('evCatalogContainer');
    container.innerHTML = '';
    Object.entries(CATALOG).forEach(([groupKey, group]) => {
      const groupEvents = Object.entries(group.events);
      const section = document.createElement('div');
      section.className = 'ev-section';
      const checkedInGroup = groupEvents.filter(([k])=>checkedKeys.includes(k)).length;
      section.innerHTML = `
        <div class="ev-section-header">
          <span class="ev-section-title">${group.label}</span>
          <span class="ev-section-count">${checkedInGroup}/${groupEvents.length}</span>
          <span class="ev-toggle-all" data-group="${groupKey}">Todos</span>
        </div>
        <div class="ev-grid">
          ${groupEvents.map(([key,desc])=>{
            const chk = checkedKeys.includes(key);
            return `<label class="ev-item${chk?' checked':''}" data-group="${groupKey}">
              <input type="checkbox" value="${key}"${chk?' checked':''}">
              <div class="ev-item-info">
                <div class="ev-item-desc">${desc}</div>
                <div class="ev-item-key">${key}</div>
              </div>
            </label>`;
          }).join('')}
        </div>`;
      container.appendChild(section);
    });

    // toggle individual
    container.querySelectorAll('.ev-item input').forEach(cb=>{
      cb.addEventListener('change', function(){
        this.closest('label').classList.toggle('checked',this.checked);
        updateGroupCount(this.closest('.ev-section'));
        syncMasterCheckbox();
      });
    });

    // toggle group
    container.querySelectorAll('.ev-toggle-all').forEach(btn=>{
      btn.addEventListener('click', function(e){
        e.stopPropagation();
        const sec = this.closest('.ev-section');
        const cbs = sec.querySelectorAll('input[type=checkbox]');
        const allChecked = Array.from(cbs).every(c=>c.checked);
        cbs.forEach(c=>{ c.checked=!allChecked; c.closest('label').classList.toggle('checked',!allChecked); });
        updateGroupCount(sec);
        syncMasterCheckbox();
      });
    });

    function updateGroupCount(sec){
      const total   = sec.querySelectorAll('input').length;
      const checked = sec.querySelectorAll('input:checked').length;
      sec.querySelector('.ev-section-count').textContent = checked+'/'+total;
    }
    function syncMasterCheckbox(){
      const all = container.querySelectorAll('input[type=checkbox]');
      document.getElementById('chkAllEvents').checked = Array.from(all).every(c=>c.checked);
    }
  }

  function getCheckedEvents(){
    return Array.from(document.querySelectorAll('#evCatalogContainer input:checked')).map(c=>c.value);
  }

  // master checkbox
  document.getElementById('chkAllEvents').addEventListener('change', function(){
    const all = document.querySelectorAll('#evCatalogContainer input[type=checkbox]');
    all.forEach(c=>{ c.checked=this.checked; c.closest('label').classList.toggle('checked',this.checked); });
    document.querySelectorAll('.ev-section-count').forEach(s=>{
      const sec   = s.closest('.ev-section');
      const total = sec.querySelectorAll('input').length;
      s.textContent = (this.checked?total:0)+'/'+total;
    });
  });

  // ── Open create modal ─────────────────────────────────────────────────────────
  document.getElementById('btnNewWebhook').addEventListener('click', ()=>{
    document.getElementById('whId').value = '';
    document.getElementById('whNome').value  = '';
    document.getElementById('whUrl').value   = '';
    document.getElementById('whSecret').value= '';
    document.getElementById('whAtivo').value = '1';
    // Etapa 8: defaults dos campos avançados
    document.getElementById('whPayloadMode').value  = 'masked';
    document.getElementById('whEscopo').value       = 'tenant_only';
    document.getElementById('whTimeout').value      = '10';
    document.getElementById('whRetryEnabled').checked = true;
    document.getElementById('whMaxRetries').value   = '3';
    document.getElementById('whHeadersCustom').value = '';
    document.getElementById('whRotateSecretBlock').style.display = 'none';
    document.getElementById('chkAllEvents').checked = false;
    document.getElementById('whDelete').style.display = 'none';
    document.getElementById('whTest').style.display   = 'none';
    document.getElementById('modalWebhookTitle').textContent = 'Novo Webhook';
    buildEvCatalog([]);
    openModal('modalWebhook');
    document.getElementById('modalWebhookBody').scrollTop = 0;
  });

  // ── Open edit modal ───────────────────────────────────────────────────────────
  document.getElementById('whList').addEventListener('click', async function(e){
    const editBtn = e.target.closest('.wh-edit-btn');
    if (editBtn){
      const id = editBtn.dataset.id;
      const j  = await fetch(API+'?id='+id).then(r=>r.json());
      const h  = j.data;
      if (!h) return;
      document.getElementById('whId').value     = h.id;
      document.getElementById('whNome').value   = h.nome||'';
      document.getElementById('whUrl').value    = h.url||'';
      document.getElementById('whSecret').value = h.secret||'';
      document.getElementById('whAtivo').value  = String(h.ativo??1);
      // Etapa 8: carrega campos avançados
      document.getElementById('whPayloadMode').value  = h.payload_mode || 'masked';
      document.getElementById('whEscopo').value       = h.escopo || 'tenant_only';
      document.getElementById('whTimeout').value      = h.timeout_segundos || 10;
      document.getElementById('whRetryEnabled').checked = (h.retry_enabled ?? 1) == 1;
      document.getElementById('whMaxRetries').value   = h.max_retries || 3;
      document.getElementById('whHeadersCustom').value = h.headers_customizados
        ? (typeof h.headers_customizados === 'string' ? h.headers_customizados : JSON.stringify(h.headers_customizados, null, 2))
        : '';
      document.getElementById('whRotateSecretBlock').style.display = '';
      document.getElementById('chkAllEvents').checked = false;
      document.getElementById('whDelete').style.display = '';
      document.getElementById('whTest').style.display   = '';
      document.getElementById('modalWebhookTitle').textContent = 'Editar Webhook';
      buildEvCatalog(h.eventos||[]);
      openModal('modalWebhook');
      document.getElementById('modalWebhookBody').scrollTop = 0;
      return;
    }
    const logsBtn = e.target.closest('.wh-logs-btn');
    if (logsBtn){ loadLogs(logsBtn.dataset.id); logsBtn.closest('.wh-card').scrollIntoView({behavior:'smooth',block:'nearest'}); }
  });

  // ── Save ─────────────────────────────────────────────────────────────────────
  document.getElementById('whSave').addEventListener('click', async ()=>{
    const id     = document.getElementById('whId').value;
    const nome   = document.getElementById('whNome').value.trim();
    const url    = document.getElementById('whUrl').value.trim();
    const secret = document.getElementById('whSecret').value.trim();
    const ativo  = parseInt(document.getElementById('whAtivo').value);
    const eventos= getCheckedEvents();
    if (!nome||!url){ showToast('Nome e URL são obrigatórios','error'); return; }
    // Etapa 8: campos avançados
    const payload_mode      = document.getElementById('whPayloadMode').value;
    const escopo            = document.getElementById('whEscopo').value;
    const timeout_segundos  = parseInt(document.getElementById('whTimeout').value) || 10;
    const retry_enabled     = document.getElementById('whRetryEnabled').checked ? 1 : 0;
    const max_retries       = parseInt(document.getElementById('whMaxRetries').value) || 3;
    let   headers_customizados = null;
    const hRaw = document.getElementById('whHeadersCustom').value.trim();
    if (hRaw) {
      try { headers_customizados = JSON.parse(hRaw); }
      catch(e){ showToast('Headers customizados: JSON inválido','error'); return; }
    }
    const body = { nome, url, secret, ativo, eventos, csrf_token:CSRF,
                   payload_mode, escopo, timeout_segundos, retry_enabled, max_retries, headers_customizados };
    const res  = id
      ? await fetch(API, {method:'PUT',  headers:headers(), body:JSON.stringify({...body,id})})
      : await fetch(API, {method:'POST', headers:headers(), body:JSON.stringify(body)});
    const j = await res.json();
    if (j.success){ closeModal('modalWebhook'); loadWebhooks(); loadKPIs(); showToast(id?'Webhook atualizado':'Webhook criado'); }
    else showToast('Erro: '+(j.error||''),'error');
  });

  // ── Rotacionar Secret (Etapa 9) ───────────────────────────────────────────────
  document.getElementById('whRotateSecret').addEventListener('click', async ()=>{
    const id = document.getElementById('whId').value;
    if (!id) return;
    if (!(await Yuris.confirm('Gerar novo secret? O secret atual deixa de funcionar imediatamente. O novo será mostrado uma única vez.', { danger: true, okLabel: 'Rotacionar' }))) return;
    const j = await fetch(API, {method:'POST',headers:headers(),body:JSON.stringify({action:'rotate_secret',id,csrf_token:CSRF})}).then(r=>r.json());
    if (j.success) {
      document.getElementById('whSecret').value = j.secret;
      showToast('Secret rotacionado — anote agora.', 'success');
    } else {
      showToast('Erro: '+(j.error||''),'error');
    }
  });

  // ── Delete ────────────────────────────────────────────────────────────────────
  document.getElementById('whDelete').addEventListener('click', async ()=>{
    const id = document.getElementById('whId').value;
    if (!id) return;
    if (!(await Yuris.confirm('Excluir este webhook?', { danger: true, okLabel: 'Excluir' }))) return;
    const j = await fetch(API, {method:'DELETE',headers:headers(),body:JSON.stringify({id,csrf_token:CSRF})}).then(r=>r.json());
    if (j.success){ closeModal('modalWebhook'); loadWebhooks(); loadKPIs(); showToast('Webhook excluído'); }
    else showToast('Erro ao excluir','error');
  });

  // ── Test ──────────────────────────────────────────────────────────────────────
  document.getElementById('whTest').addEventListener('click', async ()=>{
    const id = document.getElementById('whId').value;
    if (!id) return;
    showToast('Enviando evento de teste...','info');
    const j = await fetch(API, {method:'POST',headers:headers(),body:JSON.stringify({action:'test',id,csrf_token:CSRF})}).then(r=>r.json());
    showToast(j.success?'Teste enviado! Verifique os logs.':'Erro ao testar: '+(j.error||''), j.success?'success':'error');
    setTimeout(()=>loadLogs(id), 1500);
  });

  // ── Docs modal ───────────────────────────────────────────────────────────────
  function switchDocTab(key) {
    document.querySelectorAll('#modalDocs [data-section]').forEach(s => s.style.display = 'none');
    document.querySelectorAll('.doc-tab').forEach(t => t.classList.remove('doc-tab-active'));
    const sec = document.querySelector(`#modalDocs [data-section="${key}"]`);
    const tab = document.querySelector(`.doc-tab[data-tab="${key}"]`);
    if (sec) sec.style.display = 'block';
    if (tab) tab.classList.add('doc-tab-active');
    document.getElementById('docsBody').scrollTop = 0;
    if (key === 'eventos') {
      _evModAtivo = '';
      const s = document.getElementById('evSearch'); if (s) s.value = '';
      document.querySelectorAll('.ev-mod-btn').forEach((b,i)=>{ b.style.opacity='1'; b.style.fontWeight= i===0?'700':'600'; });
      document.querySelectorAll('.ev-doc-card').forEach(c=>c.style.display='');
      const total = document.querySelectorAll('.ev-doc-card').length;
      const cnt = document.getElementById('evCount');
      if (cnt) cnt.textContent = `${total} eventos documentados`;
    }
  }

  // Busca + filtro por módulo
  let _evModAtivo = '';

  function filterEvDocs() {
    const q   = (document.getElementById('evSearch')?.value || '').toLowerCase().trim();
    let vis   = 0;
    document.querySelectorAll('.ev-doc-card').forEach(card => {
      const modMatch = !_evModAtivo || card.dataset.mod === _evModAtivo;
      const txtMatch = !q || card.dataset.key.includes(q) || card.dataset.mod.toLowerCase().includes(q) || card.textContent.toLowerCase().includes(q);
      const show = modMatch && txtMatch;
      card.style.display = show ? '' : 'none';
      if (show) vis++;
    });
    const total = document.querySelectorAll('.ev-doc-card').length;
    const cnt   = document.getElementById('evCount');
    if (cnt) cnt.textContent = (q || _evModAtivo) ? `${vis} de ${total} eventos` : `${total} eventos documentados`;
  }

  document.getElementById('evSearch')?.addEventListener('input', filterEvDocs);

  document.getElementById('evModFilters')?.addEventListener('click', e => {
    const btn = e.target.closest('.ev-mod-btn');
    if (!btn) return;
    _evModAtivo = btn.dataset.mod;
    document.querySelectorAll('.ev-mod-btn').forEach(b => {
      const isActive = b === btn;
      b.classList.toggle('ev-mod-active', isActive);
      // highlight ativo com borda mais forte
      b.style.opacity = isActive ? '1' : '0.55';
      b.style.fontWeight = isActive ? '700' : '600';
    });
    filterEvDocs();
  });

  document.getElementById('btnDocs').addEventListener('click', ()=>{
    openModal('modalDocs');
    switchDocTab('overview');
  });
  document.getElementById('modalDocsClose').addEventListener('click', ()=>closeModal('modalDocs'));

  document.querySelectorAll('.doc-tab').forEach(btn=>{
    btn.addEventListener('click', ()=> switchDocTab(btn.dataset.tab));
  });

  // ── Catalog modal ─────────────────────────────────────────────────────────────
  document.getElementById('btnCatalog').addEventListener('click', ()=>{
    openModal('modalCatalog');
    document.getElementById('catalogBody').scrollTop = 0;
  });

  // ── Close modals ──────────────────────────────────────────────────────────────
  ['modalWebhookClose','whCancel'].forEach(id=>document.getElementById(id)?.addEventListener('click',()=>closeModal('modalWebhook')));
  document.getElementById('modalCatalogClose').addEventListener('click',()=>closeModal('modalCatalog'));
  document.querySelectorAll('.modal-overlay').forEach(el=>el.addEventListener('click',e=>{ if(e.target===el) el.classList.add('hidden'); }));

  // ── Refresh ───────────────────────────────────────────────────────────────────
  document.getElementById('btnRefresh').addEventListener('click', ()=>{ loadWebhooks(); loadKPIs(); loadLogs(null); });

  // ── Init ──────────────────────────────────────────────────────────────────────
  loadWebhooks();
  loadKPIs();
  loadLogs(null);
  </script>
</body>
</html>

