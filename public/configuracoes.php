<?php
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Prospeccao/PipelineColumn.php';
use App\Prospeccao\PipelineColumn;
session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$activePage = 'configuracoes';
$csrf    = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$columns = PipelineColumn::listAll();
$nCols   = count($columns);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Configurações — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <style>
    :root {
      --bg-main: #070F1C;
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

    /* ── Sidebar ── */

    /* ── Panel ── */
    .cfg-panel {
      background: linear-gradient(165deg, rgba(14,35,65,.94), rgba(10,23,43,.96));
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 20px;
      box-shadow: 0 14px 40px rgba(2,6,23,.45);
    }

    /* ── KPI Cards ── */
    .kpi-card {
      position: relative; overflow: hidden; border-radius: 14px;
      border: 1px solid rgba(96,165,250,.2);
      background: linear-gradient(135deg, rgba(13,31,56,.95), rgba(8,19,37,.95));
      padding: 15px; min-height: 96px;
      transition: transform .2s, border-color .2s, box-shadow .2s;
    }
    .kpi-card:hover { transform: translateY(-3px); border-color: rgba(160,180,210,0.22); box-shadow: 0 8px 24px rgba(0,0,0,0.40); }
    .kpi-card.kpi-ok { border-color: rgba(122,189,160,0.18); }
    .kpi-label { color: #7A8898; font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
    .kpi-value { margin-top: 8px; color: #D8E4F0; font-size: 1.45rem; font-weight: 700; line-height: 1.1; }
    .kpi-foot  { margin-top: 5px; color: var(--muted); font-size: .7rem; }
    .kpi-dot   { position: absolute; top: 12px; right: 12px; width: 8px; height: 8px; border-radius: 50%; }
    .dot-ok      { background: #4A9078; box-shadow: none; }
    .dot-neutral { background: #3A4858; }
    .dot-primary { background: #3A6090; box-shadow: none; }

    /* ── Summary ── */
    .summary-box {
      border-radius: 10px; border: 1px solid rgba(96,165,250,.2);
      background: rgba(8,20,40,.55); padding: 12px 16px;
      color: #d2e8ff; font-size: .87rem; line-height: 1.65;
    }
    .summary-box strong { color: #93c5fd; }

    /* ── Nav tabs ── */
    .cfg-tabs { display: flex; gap: 4px; flex-wrap: wrap; }
    .cfg-tab {
      padding: 7px 16px; border-radius: 8px; border: 1px solid rgba(160,180,210,0.12);
      background: transparent; color: var(--muted);
      font-family: inherit; font-size: .8rem; font-weight: 600; cursor: pointer;
      transition: all .18s;
    }
    .cfg-tab:hover { background: rgba(26,58,92,0.18); color: #A8BDD4; border-color: rgba(160,180,210,0.25); }
    .cfg-tab.active { background: rgba(26,58,92,0.30); color: #D8E4F0; border-color: rgba(160,180,210,0.35); }

    /* ── Section ── */
    .cfg-section { display: none; }
    .cfg-section.active { display: block; }
    .cfg-section-title  { font-size: .78rem; font-weight: 700; color: #7A8898; letter-spacing: .05em; text-transform: uppercase; padding: 8px 13px; background: rgba(20,40,70,0.18); border-bottom: 1px solid rgba(160,180,210,0.08); }
    .cfg-section-body   { padding: 16px; }
    .cfg-block { border: 1px solid rgba(96,165,250,.1); border-radius: 11px; overflow: hidden; }

    /* ── Column table ── */
    .cols-table { width: 100%; border-collapse: collapse; }
    .cols-table thead tr { border-bottom: 1px solid rgba(96,165,250,.14); }
    .cols-table th { padding: 9px 12px; text-align: left; font-size: .7rem; font-weight: 700; color: #a8c2df; text-transform: uppercase; letter-spacing: .05em; }
    .cols-table tbody tr { border-bottom: 1px solid rgba(96,165,250,.06); transition: background .14s; }
    .cols-table tbody tr:last-child { border-bottom: none; }
    .cols-table tbody tr:hover { background: rgba(37,99,235,.07); }
    .cols-table td { padding: 11px 12px; vertical-align: middle; font-size: .83rem; }
    .col-color-swatch { display: inline-block; width: 26px; height: 16px; border-radius: 5px; vertical-align: middle; border: 1px solid rgba(255,255,255,.1); }
    .col-badge {
      display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px;
      font-size: .68rem; font-weight: 700;
    }
    .badge-sim  { background: rgba(30,74,58,0.60);  color: #7ABDA0; border: 1px solid rgba(122,189,160,0.25); }
    .badge-nao  { background: rgba(30,40,55,0.70);  color: #6B7887; border: 1px solid rgba(107,120,135,0.20); }

    /* ── Buttons ── */
    .cfg-btn-primary {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 0 16px; height: 36px;
      background: linear-gradient(135deg, #1A3A5C, #244E7A);
      color: #C8D4E0; border: 1px solid rgba(160,180,210,0.20); border-radius: 9px;
      font-family: inherit; font-size: .8rem; font-weight: 600; cursor: pointer;
      box-shadow: 0 2px 8px rgba(0,0,0,0.35); transition: transform .18s, box-shadow .18s;
    }
    .cfg-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(20,58,100,0.40); background: linear-gradient(135deg, #244E7A, #2E6090); }
    .cfg-btn-secondary {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 0 12px; height: 32px;
      border-radius: 7px; border: 1px solid rgba(160,180,210,0.18);
      background: transparent; color: #7A8898;
      font-family: inherit; font-size: .76rem; font-weight: 600; cursor: pointer;
      transition: background .18s;
    }
    .cfg-btn-secondary:hover { background: rgba(160,180,210,0.10); color: #A8BDD4; }
    .cfg-btn-danger {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 0 10px; height: 30px;
      border-radius: 7px; border: 1px solid rgba(176,96,112,0.25);
      background: linear-gradient(135deg, #3A1020, #4A1828); color: #B06070;
      font-family: inherit; font-size: .74rem; font-weight: 600; cursor: pointer;
      transition: opacity .15s;
    }
    .cfg-btn-danger:hover { opacity: .85; }

    /* ── Field ── */
    .field-group { display: flex; flex-direction: column; gap: 5px; }
    .field-label { font-size: .79rem; font-weight: 600; color: #b8d5f4; }
    .field-hint  { font-size: .71rem; color: var(--muted); }
    .field-input {
      width: 100%; padding: 8px 10px;
      border-radius: 8px; border: 1px solid rgba(96,165,250,.22);
      background: rgba(255,255,255,.95); color: #0f172a;
      font-family: inherit; font-size: .83rem;
      transition: border-color .18s;
    }
    .field-input::placeholder { color: rgba(15,23,42,.42); }
    .field-input:focus { outline: none; border-color: rgba(160,180,210,0.32); box-shadow: 0 0 0 2px rgba(30,58,95,0.25); }

    /* ── Toggle ── */
    .toggle-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid rgba(160,180,210,0.07); }
    .toggle-row:last-child { border-bottom: none; }
    .toggle-label { font-size: .83rem; color: #A8BDD4; }
    .toggle-sub   { font-size: .71rem; color: var(--muted); margin-top: 2px; }
    .toggle-wrap  { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
    .toggle-wrap input { opacity: 0; width: 0; height: 0; position: absolute; }
    .toggle-slider { position: absolute; inset: 0; background: rgba(148,163,184,.3); border-radius: 999px; cursor: pointer; transition: background .22s; }
    .toggle-slider::before { content:''; position:absolute; height:18px; width:18px; left:3px; top:3px; background:#fff; border-radius:50%; transition:transform .22s; box-shadow:0 1px 4px rgba(0,0,0,.3); }
    .toggle-wrap input:checked + .toggle-slider { background: #244E7A; }
    .toggle-wrap input:checked + .toggle-slider::before { transform: translateX(20px); }

    /* ── Link card ── */
    .link-card {
      display: flex; align-items: center; justify-content: space-between;
      padding: 12px 14px; border-radius: 10px;
      background: rgba(8,20,40,.55); border: 1px solid rgba(96,165,250,.1);
      text-decoration: none; color: #d6e8fa;
      transition: border-color .18s, background .18s;
    }
    .link-card:hover { border-color: rgba(96,165,250,.32); background: rgba(37,99,235,.1); }
    .link-card-title { font-size: .85rem; font-weight: 600; }
    .link-card-sub   { font-size: .73rem; color: var(--muted); margin-top: 2px; }

    /* Tema claro: aba Atalhos — cards brancos legíveis */
    html[data-theme="light"] .link-card {
      background: #FFFFFF !important;
      border: 1px solid rgba(15,31,54,0.10) !important;
      color: #0F1F36 !important;
      box-shadow: 0 1px 3px rgba(15,31,54,0.04) !important;
    }
    html[data-theme="light"] .link-card:hover {
      background: rgba(37,99,235,0.06) !important;
      border-color: rgba(37,99,235,0.30) !important;
      box-shadow: 0 4px 12px rgba(15,31,54,0.08) !important;
    }
    html[data-theme="light"] .link-card-title { color: #0F1F36 !important; }
    html[data-theme="light"] .link-card-sub   { color: #5A6B7E !important; }
    html[data-theme="light"] .link-card svg   { stroke: #1E4A8A !important; }

    /* ── Modal ── */
    #modal {
      position: fixed; inset: 0; background: rgba(2,6,23,.65);
      display: flex; align-items: flex-start; justify-content: center;
      z-index: 1000; overflow-y: auto; padding: 30px 12px;
    }
    #modal.hidden { display: none !important; }
    .modal-box {
      width: 480px; max-width: 96vw; margin: auto;
      background: linear-gradient(165deg, rgba(10,24,46,.99), rgba(7,18,36,.99));
      border: 1px solid rgba(96,165,250,.22);
      border-radius: 16px; overflow: hidden;
      box-shadow: 0 24px 60px rgba(2,6,23,.7);
    }
    .modal-header {
      display: flex; justify-content: space-between; align-items: center;
      padding: 15px 20px; border-bottom: 1px solid rgba(96,165,250,.14);
      background: rgba(8,22,44,.55);
    }
    .modal-title-text { font-size: 1rem; font-weight: 700; color: #dbeafe; }
    .modal-body   { padding: 18px 20px; display: flex; flex-direction: column; gap: 12px; }
    .modal-footer {
      padding: 13px 20px; border-top: 1px solid rgba(96,165,250,.14);
      background: rgba(8,22,44,.4); display: flex; justify-content: flex-end; gap: 8px;
    }
    .modal-content input, .modal-content select { color: #0f172a; background: rgba(255,255,255,.95); }

    /* ── Toast ── */
    .toast-wrap { position:fixed; top:1rem; right:1rem; z-index:1100; display:flex; flex-direction:column; gap:6px; }
    .toast { padding:.6rem 1rem; border-radius:9px; color:#fff; font-size:.83rem; font-weight:600; opacity:0; transform:translateY(-6px); transition:all .25s ease; }
    .toast.show { opacity:1; transform:translateY(0); }
    .toast.success { background:linear-gradient(90deg,#059669,#10b981); }
    .toast.error   { background:linear-gradient(90deg,#dc2626,#ef4444); }

    @media (max-width: 640px) { .kpi-grid-6 { grid-template-columns: repeat(2,1fr) !important; } }

    /* ── Cards de seleção de tema (aba Aparência) ── */
    .theme-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 14px;
    }
    .theme-card {
      position: relative;
      display: flex; flex-direction: column;
      padding: 12px;
      background: rgba(8,22,44,.45);
      border: 2px solid rgba(96,165,250,.14);
      border-radius: 12px;
      cursor: pointer;
      transition: all .2s ease;
      text-align: left;
      color: inherit;
    }
    .theme-card:hover {
      border-color: rgba(96,165,250,.40);
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(37,99,235,.12);
    }
    .theme-card[aria-pressed="true"] {
      border-color: #2563EB;
      box-shadow: 0 0 0 3px rgba(37,99,235,.18), 0 8px 24px rgba(37,99,235,.18);
    }
    .theme-card[aria-pressed="true"] .theme-card-check { opacity: 1; transform: scale(1); }

    .theme-preview {
      border-radius: 8px;
      height: 130px;
      display: flex;
      overflow: hidden;
      border: 1px solid rgba(160,180,210,.12);
      margin-bottom: 10px;
    }
    .theme-preview .tp-sidebar { width: 22%; background: #060E18; border-right: 1px solid rgba(160,180,210,.10); }
    .theme-preview .tp-content { flex: 1; padding: 10px; display: flex; flex-direction: column; gap: 6px; background: #070F1C; }
    .theme-preview .tp-header  { height: 14px; background: rgba(20,50,90,.50); border-radius: 3px; width: 60%; }
    .theme-preview .tp-card    { height: 22px; background: #0D1C30; border-radius: 4px; border: 1px solid rgba(160,180,210,.08); }
    .theme-preview .tp-card-2  { background: linear-gradient(90deg, #1A3A5C 0%, #244E7A 100%); width: 70%; }
    .theme-preview .tp-row     { height: 8px; background: rgba(160,180,210,.14); border-radius: 2px; }
    .theme-preview .tp-row.short { width: 50%; }

    /* Preview do tema CLARO — mostra como vai ficar */
    .theme-preview-light .tp-sidebar { background: #EAEEF4; border-right-color: rgba(15,31,54,.10); }
    .theme-preview-light .tp-content { background: #DDE3EC; }
    .theme-preview-light .tp-header  { background: rgba(37,99,235,.18); }
    .theme-preview-light .tp-card    { background: #FFFFFF; border-color: rgba(15,31,54,.10); }
    .theme-preview-light .tp-card-2  { background: linear-gradient(90deg, #1E4A8A 0%, #2563EB 100%); }
    .theme-preview-light .tp-row     { background: rgba(15,31,54,.18); }

    .theme-card-meta { flex: 1; }
    .theme-card-title { font-size: .92rem; font-weight: 700; color: #D8E4F0; display: flex; align-items: center; gap: 8px; }
    .theme-card-sub   { font-size: .76rem; color: #7A8898; margin-top: 2px; }
    .theme-badge      { padding: 2px 7px; border-radius: 10px; font-size: .65rem; font-weight: 700; background: rgba(96,165,250,.18); color: #93C5FD; text-transform: uppercase; letter-spacing: .04em; }
    .theme-badge-new  { background: rgba(34,197,94,.16); color: #4ADE80; }

    .theme-card-check {
      position: absolute; top: 10px; right: 10px;
      width: 28px; height: 28px; border-radius: 50%;
      background: #2563EB; color: #FFFFFF;
      display: flex; align-items: center; justify-content: center;
      opacity: 0; transform: scale(.6);
      transition: all .2s ease;
      box-shadow: 0 4px 12px rgba(37,99,235,.40);
    }

    /* Tema claro overrides para esta tela específica (modal, cards) */
    html[data-theme="light"] .modal-content input,
    html[data-theme="light"] .modal-content select { color: #0F1F36 !important; background: #FFFFFF !important; }
    html[data-theme="light"] .modal-title-text { color: #0F1F36 !important; }
    html[data-theme="light"] .modal-header { background: rgba(37,99,235,0.04) !important; border-bottom-color: rgba(15,31,54,.12) !important; }
    html[data-theme="light"] .modal-footer { background: rgba(37,99,235,0.02) !important; border-top-color: rgba(15,31,54,.10) !important; }
    html[data-theme="light"] .theme-card { background: #FFFFFF !important; border-color: rgba(15,31,54,.12) !important; }
    html[data-theme="light"] .theme-card-title { color: #0F1F36 !important; }
    html[data-theme="light"] .theme-card-sub   { color: #5A6B7E !important; }
    html[data-theme="light"] .theme-badge      { background: rgba(37,99,235,.12); color: #1E4A8A; }
    html[data-theme="light"] .theme-badge-new  { background: rgba(22,163,74,.14); color: #15803D; }
    html[data-theme="light"] .cfg-tab {
      background: #FFFFFF !important;
      border: 1px solid rgba(15,31,54,.18) !important;
      color: #1E4A8A !important;
      font-weight: 600 !important;
    }
    html[data-theme="light"] .cfg-tab svg { stroke: currentColor !important; }
    html[data-theme="light"] .cfg-tab:hover  {
      background: rgba(37,99,235,.10) !important;
      color: #0F1F36 !important;
      border-color: rgba(37,99,235,.40) !important;
    }
    html[data-theme="light"] .cfg-tab.active {
      background: #1E4A8A !important;
      color: #FFFFFF !important;
      border-color: #1E4A8A !important;
      font-weight: 700 !important;
      box-shadow: 0 2px 8px rgba(30,74,138,.25) !important;
    }
    html[data-theme="light"] .cfg-tab.active svg { stroke: #FFFFFF !important; }
    html[data-theme="light"] .cfg-section-title {
      background: rgba(37,99,235,.08) !important;
      color: #1E4A8A !important;
      border-bottom-color: rgba(15,31,54,.12) !important;
      font-weight: 700 !important;
    }
    /* Botões da página de configurações */
    html[data-theme="light"] .cfg-btn-primary { background: linear-gradient(135deg, #1E4A8A, #2563EB) !important; color: #FFFFFF !important; border-color: #1E4A8A !important; }
    html[data-theme="light"] .cfg-btn-secondary { background: #FFFFFF !important; color: #1E4A8A !important; border: 1px solid rgba(15,31,54,.18) !important; }
    html[data-theme="light"] .cfg-btn-secondary:hover { background: rgba(37,99,235,.08) !important; }
    html[data-theme="light"] .cfg-btn-danger { background: linear-gradient(135deg, #B91C1C, #DC2626) !important; color: #FFFFFF !important; border-color: #B91C1C !important; }
    /* Textos genéricos dentro da página configurações */
    html[data-theme="light"] .cfg-panel { color: #0F1F36 !important; }
    html[data-theme="light"] .cfg-panel h3,
    html[data-theme="light"] .cfg-panel h2 { color: #0F1F36 !important; }
    /* Summary box em fundo claro */
    html[data-theme="light"] .summary-box { background: rgba(247,249,252,.95) !important; border: 1px solid rgba(15,31,54,.10) !important; color: #0F1F36 !important; }
    html[data-theme="light"] .summary-box strong { color: #1E4A8A !important; }
    /* Badges sim/não */
    html[data-theme="light"] .badge-sim { background: rgba(22,163,74,.14) !important; color: #15803D !important; border: 1px solid rgba(22,163,74,.30) !important; }
    html[data-theme="light"] .badge-nao { background: rgba(124,139,160,.14) !important; color: #5A6B7E !important; border: 1px solid rgba(124,139,160,.30) !important; }
    /* Tabela de colunas */
    html[data-theme="light"] .cols-table thead tr { border-bottom-color: rgba(15,31,54,.14) !important; }
    html[data-theme="light"] .cols-table th { color: #1E4A8A !important; }
    html[data-theme="light"] .cols-table tbody tr { border-bottom-color: rgba(15,31,54,.08) !important; }
    html[data-theme="light"] .cols-table tbody tr:hover { background: rgba(37,99,235,.06) !important; }
    html[data-theme="light"] .cols-table td { color: #0F1F36 !important; }
    /* Modal interno */
    html[data-theme="light"] .modal-header { background: rgba(37,99,235,.06) !important; border-bottom-color: rgba(15,31,54,.12) !important; }
    html[data-theme="light"] .modal-title-text { color: #0F1F36 !important; }
    html[data-theme="light"] .modal-body { color: #0F1F36 !important; }
    html[data-theme="light"] .modal-footer { background: rgba(37,99,235,.04) !important; border-top-color: rgba(15,31,54,.10) !important; }
  </style>
</head>
<body>
<main class="w-full px-6 py-6">
  <div class="page-layout">

    <!-- ── Sidebar ── -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- ── Main ── -->
    <section class="main-content space-y-4">

      <!-- Header — padrão .page-header (yuris-theme.css) -->
      <div class="cfg-panel page-header">
        <div class="page-header-inner">
          <div class="page-header-text">
            <h2 class="page-header-title">Configurações do Sistema</h2>
            <p class="page-header-subtitle">Central administrativa do CRM — Funil · Notificações · Segurança · Integrações</p>
          </div>
        </div>
      </div>

      <!-- KPI cards -->
      <div class="kpi-grid-6" style="display:grid;grid-template-columns:repeat(5,1fr);gap:11px">
        <div class="kpi-card">
          <div class="kpi-dot dot-neutral" id="dotUsers"></div>
          <div class="kpi-label">Usuários</div>
          <div class="kpi-value" id="kpiUsers">—</div>
          <div class="kpi-foot">cadastrados no sistema</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-dot dot-neutral" id="dotAgent"></div>
          <div class="kpi-label">Agente IA</div>
          <div class="kpi-value" id="kpiAgent">—</div>
          <div class="kpi-foot">status da integração</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-dot dot-neutral" id="dotAlertas"></div>
          <div class="kpi-label">Alertas jurídicos</div>
          <div class="kpi-value" id="kpiAlertas">—</div>
          <div class="kpi-foot">notificações ativas</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-dot dot-neutral"></div>
          <div class="kpi-label">Notificações</div>
          <div class="kpi-value" id="kpiNotifs">—</div>
          <div class="kpi-foot">canais habilitados</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-dot dot-neutral"></div>
          <div class="kpi-label">Última alteração</div>
          <div class="kpi-value" id="kpiLastChange" style="font-size:.85rem;margin-top:7px">—</div>
          <div class="kpi-foot">via painel admin</div>
        </div>
      </div>

      <!-- Resumo executivo -->
      <div class="cfg-panel" style="padding:13px 18px">
        <div id="cfgSummary" class="summary-box">Carregando resumo administrativo...</div>
      </div>

      <!-- Nav tabs -->
      <div class="cfg-panel" style="padding:14px 18px">
        <div class="cfg-tabs" id="cfgTabs">
          <button class="cfg-tab active" data-tab="juridico"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><path d="M12 3v18"/><path d="M5 6l7-3 7 3"/><path d="M5 6 2 13h6z"/><path d="M19 6l-3 7h6z"/><line x1="3" y1="18" x2="21" y2="18"/></svg> Jurídico</button>
          <button class="cfg-tab" data-tab="notificacoes"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg> Notificações</button>
          <button class="cfg-tab" data-tab="aparencia"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg> Aparência</button>
          <button class="cfg-tab" data-tab="links"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Atalhos</button>
          <button class="cfg-tab" data-tab="seguranca"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Segurança</button>
        </div>
      </div>

      <!-- ── SECTION: Jurídico ── (default) -->
      <div class="cfg-section active" id="sec-juridico">
        <div class="cfg-panel space-y-4">
          <div>
            <h3 style="font-size:.95rem;font-weight:600;color:#dbeafe;margin-bottom:4px">Alertas de Prazos Jurídicos</h3>
            <p style="font-size:.78rem;color:var(--muted)">Configure as janelas de alerta — sincronizado com o Painel Jurídico</p>
          </div>
          <div class="cfg-block">
            <div class="cfg-section-title">Notificações popup de processos</div>
            <div class="cfg-section-body">
              <div class="toggle-row">
                <div><div class="toggle-label">Notificar prazos de hoje</div><div class="toggle-sub">Popup ao entrar no sistema com processos que vencem hoje</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="jAlertHoje"><span class="toggle-slider"></span></label>
              </div>
              <div class="toggle-row">
                <div><div class="toggle-label">Notificar próximos 7 dias</div><div class="toggle-sub">Processos com prazo nos próximos 7 dias</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="jAlert7"><span class="toggle-slider"></span></label>
              </div>
              <div class="toggle-row">
                <div><div class="toggle-label">Notificar próximos 15 dias</div><div class="toggle-sub">Processos com prazo nos próximos 15 dias</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="jAlert15"><span class="toggle-slider"></span></label>
              </div>
              <div class="toggle-row">
                <div><div class="toggle-label">Notificar próximos 30 dias</div><div class="toggle-sub">Processos com prazo nos próximos 30 dias</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="jAlert30"><span class="toggle-slider"></span></label>
              </div>
            </div>
          </div>
          <div class="cfg-block">
            <div class="cfg-section-title">Critérios de urgência processual</div>
            <div class="cfg-section-body" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
              <div class="field-group">
                <label class="field-label">Prazo urgente (dias)</label>
                <input type="number" id="jUrgDias" class="field-input" value="3" min="1" max="30">
                <span class="field-hint">Processos com prazo até este número de dias são marcados como Urgente</span>
              </div>
              <div class="field-group">
                <label class="field-label">Prazo de atenção (dias)</label>
                <input type="number" id="jAtencaoDias" class="field-input" value="7" min="1" max="60">
                <span class="field-hint">Processos com prazo até este número de dias recebem badge "Esta semana"</span>
              </div>
            </div>
          </div>
          <div style="display:flex;justify-content:flex-end">
            <button id="btnSaveJuridico" class="cfg-btn-primary">Salvar configurações jurídicas</button>
          </div>
        </div>
      </div>

      <!-- ── SECTION: Notificações ── -->
      <div class="cfg-section" id="sec-notificacoes">
        <div class="cfg-panel space-y-4">
          <div>
            <h3 style="font-size:.95rem;font-weight:600;color:#dbeafe;margin-bottom:4px">Notificações e Alertas</h3>
            <p style="font-size:.78rem;color:var(--muted)">Configure os canais e tipos de alertas do sistema</p>
          </div>
          <div class="cfg-block">
            <div class="cfg-section-title">📣 Canais de notificação</div>
            <div class="cfg-section-body">
              <div class="toggle-row">
                <div><div class="toggle-label">Popup interno do sistema</div><div class="toggle-sub">Notificações dentro do CRM ao entrar</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="nPopup" checked><span class="toggle-slider"></span></label>
              </div>
              <div class="toggle-row">
                <div><div class="toggle-label">WhatsApp (via Agente)</div><div class="toggle-sub">Enviar alertas pelo número configurado no Agente</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="nWhats"><span class="toggle-slider"></span></label>
              </div>
              <div class="toggle-row">
                <div><div class="toggle-label">E-mail</div><div class="toggle-sub">Alertas por e-mail (requer configuração de SMTP)</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="nEmail"><span class="toggle-slider"></span></label>
              </div>
            </div>
          </div>
          <div class="cfg-block">
            <div class="cfg-section-title">Tipos de alerta</div>
            <div class="cfg-section-body">
              <div class="toggle-row">
                <div><div class="toggle-label">Alertas jurídicos (prazos)</div><div class="toggle-sub">Processos próximos do vencimento</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="nJur" checked><span class="toggle-slider"></span></label>
              </div>
              <div class="toggle-row">
                <div><div class="toggle-label">Alertas comerciais (meta)</div><div class="toggle-sub">Meta mensal abaixo do esperado</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="nComercial" checked><span class="toggle-slider"></span></label>
              </div>
              <div class="toggle-row">
                <div><div class="toggle-label">Alertas financeiros (DRE)</div><div class="toggle-sub">Despesas altas, margem crítica</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="nFinanc" checked><span class="toggle-slider"></span></label>
              </div>
              <div class="toggle-row">
                <div><div class="toggle-label">Usuário sem movimentação</div><div class="toggle-sub">Alertar quando responsável ficar sem atividade</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="nInativo"><span class="toggle-slider"></span></label>
              </div>
            </div>
          </div>
          <div style="display:flex;justify-content:flex-end">
            <button id="btnSaveNotifs" class="cfg-btn-primary">Salvar notificações</button>
          </div>
        </div>
      </div>

      <!-- ── SECTION: Aparência ── -->
      <div class="cfg-section" id="sec-aparencia">
        <div class="cfg-panel">
          <div class="cfg-section-title">🎨 Tema visual</div>
          <div class="cfg-section-body">
            <p style="font-size:.82rem;color:#9ab0c9;line-height:1.5;margin-bottom:16px">
              Escolha entre o tema escuro institucional (padrão) ou o tema claro <strong>Yuris Day</strong> —
              fundo prata derivado da logo, acentos em azul corporativo.
              A preferência é salva no seu navegador e aplicada em todas as abas.
            </p>

            <div class="theme-grid" id="themeGrid">
              <!-- Card: Tema Escuro -->
              <button type="button" class="theme-card" data-theme-pick="dark" aria-pressed="true">
                <div class="theme-preview theme-preview-dark">
                  <div class="tp-sidebar"></div>
                  <div class="tp-content">
                    <div class="tp-header"></div>
                    <div class="tp-card"></div>
                    <div class="tp-card tp-card-2"></div>
                    <div class="tp-row"></div>
                    <div class="tp-row short"></div>
                  </div>
                </div>
                <div class="theme-card-meta">
                  <div class="theme-card-title">Yuris Night <span class="theme-badge">Padrão</span></div>
                  <div class="theme-card-sub">Tema escuro institucional · navy + prata</div>
                </div>
                <div class="theme-card-check">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
              </button>

              <!-- Card: Tema Claro -->
              <button type="button" class="theme-card" data-theme-pick="light" aria-pressed="false">
                <div class="theme-preview theme-preview-light">
                  <div class="tp-sidebar"></div>
                  <div class="tp-content">
                    <div class="tp-header"></div>
                    <div class="tp-card"></div>
                    <div class="tp-card tp-card-2"></div>
                    <div class="tp-row"></div>
                    <div class="tp-row short"></div>
                  </div>
                </div>
                <div class="theme-card-meta">
                  <div class="theme-card-title">Yuris Day <span class="theme-badge theme-badge-new">Novo</span></div>
                  <div class="theme-card-sub">Tema claro premium · prata + azul</div>
                </div>
                <div class="theme-card-check">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
              </button>
            </div>

            <div style="margin-top:18px;padding:12px 14px;background:rgba(37,99,235,0.08);border:1px solid rgba(37,99,235,0.18);border-radius:8px;font-size:.78rem;color:#9ab0c9;line-height:1.5">
              <strong style="color:#1E4A8A;display:inline-flex;align-items:center;gap:6px"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21h6"/><path d="M12 17v4"/><path d="M12 3a6 6 0 0 0-4 10.5c.5.5 1 1.2 1 2v.5h6v-.5c0-.8.5-1.5 1-2A6 6 0 0 0 12 3z"/></svg> Dica:</strong>
              A troca é instantânea — não precisa recarregar a página. A preferência é por dispositivo (cada navegador guarda a sua).
            </div>
          </div>
        </div>
      </div>

      <!-- ── SECTION: Atalhos ── -->
      <div class="cfg-section" id="sec-links">
        <div class="cfg-panel">
          <h3 style="font-size:.95rem;font-weight:600;color:#dbeafe;margin-bottom:14px">Atalhos para configurações avançadas</h3>
          <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px">
            <a href="agente.php" class="link-card">
              <div><div class="link-card-title"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><rect x="4" y="8" width="16" height="12" rx="2"/><path d="M8 8V6a2 2 0 0 1 4 0v2"/><path d="M12 6V4"/><circle cx="9" cy="14" r="1" fill="currentColor"/><circle cx="15" cy="14" r="1" fill="currentColor"/><path d="M8 17h8"/></svg> Agente de IA</div><div class="link-card-sub">Configurar provedor, chave de API e prompt do agente WhatsApp</div></div>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <a href="usuarios.php" class="link-card">
              <div><div class="link-card-title"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Gestão de Usuários</div><div class="link-card-sub">Criar, editar e controlar perfis e acessos</div></div>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <a href="funil.php" class="link-card">
              <div><div class="link-card-title"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><rect x="3" y="12" width="4" height="9"/><rect x="10" y="7" width="4" height="14"/><rect x="17" y="3" width="4" height="18"/></svg> Planejamento Comercial</div><div class="link-card-sub">Meta mensal, honorário médio, simulações de funil</div></div>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <a href="juridico.php" class="link-card">
              <div><div class="link-card-title"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><path d="M12 3v18"/><path d="M5 6l7-3 7 3"/><path d="M5 6 2 13h6z"/><path d="M19 6l-3 7h6z"/><line x1="3" y1="18" x2="21" y2="18"/></svg> Painel Jurídico</div><div class="link-card-sub">Alertas de prazo, diagnóstico processual, gráficos</div></div>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <a href="financas.php" class="link-card">
              <div><div class="link-card-title"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M9 9h4.5a1.5 1.5 0 0 1 0 3h-5a1.5 1.5 0 0 0 0 3H15"/></svg> Central Financeira (DRE)</div><div class="link-card-sub">Categorias, centros de custo, lançamentos, metas financeiras</div></div>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <a href="processos.php" class="link-card">
              <div><div class="link-card-title"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg> Gestão Processual</div><div class="link-card-sub">Tipos de ação, responsáveis, prazos e calendário</div></div>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
          </div>
        </div>
      </div>

      <!-- ── SECTION: Segurança ── -->
      <div class="cfg-section" id="sec-seguranca">
        <div class="cfg-panel space-y-4">
          <div>
            <h3 style="font-size:.95rem;font-weight:600;color:#dbeafe;margin-bottom:4px">Segurança e Boas práticas</h3>
            <p style="font-size:.78rem;color:var(--muted)">Orientações para manter o sistema seguro</p>
          </div>
          <div class="cfg-block">
            <div class="cfg-section-title"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Políticas de segurança</div>
            <div class="cfg-section-body">
              <div class="toggle-row">
                <div><div class="toggle-label">Bloqueio por inatividade de sessão</div><div class="toggle-sub">Encerra a sessão após período sem uso</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="secInact" checked><span class="toggle-slider"></span></label>
              </div>
              <div class="toggle-row">
                <div><div class="toggle-label">Restringir acesso à área administrativa</div><div class="toggle-sub">Somente perfil admin acessa Configurações e Usuários</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="secAdmin" checked><span class="toggle-slider"></span></label>
              </div>
              <div class="toggle-row">
                <div><div class="toggle-label">Log de alterações administrativas</div><div class="toggle-sub">Registrar quem alterou configurações e quando</div></div>
                <label class="toggle-wrap"><input type="checkbox" id="secLog" checked><span class="toggle-slider"></span></label>
              </div>
            </div>
          </div>
          <div class="cfg-block">
            <div class="cfg-section-title"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Checklist de boas práticas</div>
            <div class="cfg-section-body" style="display:flex;flex-direction:column;gap:8px">
              <?php
              $checks = [
                ['<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M21 2l-9.6 9.6M15.5 7.5l3 3"/></svg>','Use senhas diferentes para cada colaborador','Evite o compartilhamento de credenciais'],
                ['<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>','Atribua perfis adequados por função','Não dê admin a todos — use o perfil user onde possível'],
                ['<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>','Revise usuários inativos periodicamente','Remova acessos de ex-colaboradores imediatamente'],
                ['<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>','Mantenha o prompt do Agente atualizado','Revise mensalmente o comportamento do agente IA'],
                ['<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>','Faça backup das configurações regularmente','Exporte suas configurações antes de alterações grandes'],
                ['<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>','Monitore o log de alterações','Audite periodicamente quem fez o quê no sistema'],
              ];
              foreach($checks as $ch): ?>
              <div style="display:flex;align-items:flex-start;gap:10px;padding:9px 10px;border-radius:8px;background:rgba(8,20,40,.5);border:1px solid rgba(96,165,250,.08)">
                <span style="font-size:.9rem;flex-shrink:0;margin-top:1px"><?= $ch[0] ?></span>
                <div>
                  <div style="font-size:.8rem;font-weight:600;color:#d6e8fa"><?= $ch[1] ?></div>
                  <div style="font-size:.72rem;color:var(--muted);margin-top:1px"><?= $ch[2] ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div style="display:flex;justify-content:flex-end">
            <button id="btnSaveSec" class="cfg-btn-primary">Salvar preferências</button>
          </div>
        </div>
      </div>

    </section>
  </div>
</main>

<div class="toast-wrap" id="toastWrap" aria-live="polite"></div>


<script>
  // ── Toast ──────────────────────────────────────────────────────────────────
  function showToast(msg, type='success', ms=3500){
    const w=document.getElementById('toastWrap'); if(!w) return;
    const t=document.createElement('div'); t.className='toast '+(type==='error'?'error':'success'); t.textContent=msg; w.appendChild(t);
    void t.offsetWidth; t.classList.add('show');
    const h=()=>{t.classList.remove('show');setTimeout(()=>t.remove(),300);}
    setTimeout(h,ms); t.addEventListener('click',h);
  }

  // ── Tabs ───────────────────────────────────────────────────────────────────
  document.querySelectorAll('.cfg-tab').forEach(btn=>{
    btn.addEventListener('click',()=>{
      document.querySelectorAll('.cfg-tab').forEach(b=>b.classList.remove('active'));
      document.querySelectorAll('.cfg-section').forEach(s=>s.classList.remove('active'));
      btn.classList.add('active');
      const sec=document.getElementById('sec-'+btn.dataset.tab);
      if(sec) sec.classList.add('active');
    });
  });

  // ── Theme switcher (aba Aparência) ─────────────────────────────────────────
  (function initThemeSwitcher(){
    const root  = document.documentElement;
    const cards = document.querySelectorAll('.theme-card[data-theme-pick]');
    if (!cards.length) return;

    // Reflete o estado atual nos cards (lido do localStorage / atributo do <html>)
    const current = root.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    cards.forEach(c => c.setAttribute('aria-pressed', c.dataset.themePick === current ? 'true' : 'false'));

    cards.forEach(c => {
      c.addEventListener('click', () => {
        const pick = c.dataset.themePick;
        if (pick === 'light') {
          root.setAttribute('data-theme', 'light');
          try { localStorage.setItem('yuris_theme', 'light'); } catch(e){}
        } else {
          root.removeAttribute('data-theme');
          try { localStorage.setItem('yuris_theme', 'dark'); } catch(e){}
        }
        cards.forEach(x => x.setAttribute('aria-pressed', x.dataset.themePick === pick ? 'true' : 'false'));
        // Toast de confirmação (reaproveita o sistema existente da página)
        if (typeof showToast === 'function') {
          showToast(pick === 'light' ? 'Tema claro ativado · Yuris Day' : 'Tema escuro ativado · Yuris Night', 'success');
        }
      });
    });
  })();

  // Base de APIs usada por loadKPIs() abaixo (Column CRUD removido junto com a aba Funil de Vendas)
  const API_BASE = '/api/';

  // Updates only localStorage-derived KPI cells — no network calls.
  function updateLocalKPIs(){
    const alertKeys =['jur_alert_hoje','jur_alert_7','jur_alert_15','jur_alert_30'];
    const notifKeys =['cfg_notif_popup','cfg_notif_whats','cfg_notif_email','cfg_notif_jur','cfg_notif_comercial','cfg_notif_financ'];
    const activeAlertas = alertKeys.filter(k=>localStorage.getItem(k)!=='false').length;
    const activeNotifs  = notifKeys.filter(k=>localStorage.getItem(k)!=='false').length;
    const elA=document.getElementById('kpiAlertas');  if(elA) elA.textContent=activeAlertas+' / 4';
    const dA=document.getElementById('dotAlertas');   if(dA)  dA.className='kpi-dot '+(activeAlertas>0?'dot-ok':'dot-neutral');
    const elN=document.getElementById('kpiNotifs');   if(elN) elN.textContent=activeNotifs+' ativos';
    const last=localStorage.getItem('cfg_last_change');
    const elL=document.getElementById('kpiLastChange'); if(elL&&last) elL.textContent=last;
    updateSummary();
  }

  // Fetches remote KPIs (users + agent) in parallel, then refreshes local KPIs.
  async function loadKPIs(){
    const [usersRes, agentRes] = await Promise.allSettled([
      fetch(API_BASE+'users.php',         {credentials:'same-origin'}),
      fetch(API_BASE+'agent_settings.php',{credentials:'same-origin'}),
    ]);
    try{
      if(usersRes.status==='fulfilled'&&usersRes.value.ok){
        const j=await usersRes.value.json();
        const cnt=(j.data||[]).length;
        const el=document.getElementById('kpiUsers'); if(el) el.textContent=cnt;
        const d=document.getElementById('dotUsers');  if(d)  d.className='kpi-dot dot-ok';
      }
    }catch(e){}
    try{
      if(agentRes.status==='fulfilled'&&agentRes.value.ok){
        const j=await agentRes.value.json();
        const el=document.getElementById('kpiAgent'); const d=document.getElementById('dotAgent');
        if(j.enabled){ if(el)el.textContent='Ativo';   if(d) d.className='kpi-dot dot-ok'; }
        else           { if(el)el.textContent='Inativo'; }
      }
    }catch(e){}
    updateLocalKPIs();
  }

  function updateSummary(nCols){
    const el=document.getElementById('cfgSummary'); if(!el) return;
    const usersEl=document.getElementById('kpiUsers');
    const agentEl=document.getElementById('kpiAgent');
    const users=usersEl&&usersEl.textContent!=='—'?usersEl.textContent:'?';
    const agentStatus=agentEl?agentEl.textContent:'—';
    const alertas=document.getElementById('kpiAlertas')?.textContent||'—';
    el.innerHTML=
      `O sistema possui <strong>${users}</strong> usuário${users!=='1'?'s':''} cadastrado${users!=='1'?'s':''}, `+
      `agente IA <strong>${agentStatus.toLowerCase()}</strong> e <strong>${alertas}</strong> alertas jurídicos habilitados. `+
      `Revise as configurações periodicamente para manter o escritório operando com eficiência.`;
  }

  // ── Shared toggle wiring ──────────────────────────────────────────────────
  function wireToggles(map){
    map.forEach(({id,key,def})=>{
      const el=document.getElementById(id); if(!el) return;
      const stored=localStorage.getItem(key);
      el.checked = stored===null ? (def!==false) : stored!=='false';
      el.addEventListener('change',()=>localStorage.setItem(key,el.checked?'true':'false'));
    });
  }
  function wireSaveBtn(btnId, toastMsg){
    document.getElementById(btnId)?.addEventListener('click',()=>{
      try{ localStorage.setItem('cfg_last_change',new Date().toLocaleString('pt-BR')); }catch(e){}
      showToast(toastMsg);
      updateLocalKPIs();
    });
  }

  wireToggles([
    {id:'jAlertHoje', key:'jur_alert_hoje',    def:true},
    {id:'jAlert7',    key:'jur_alert_7',        def:true},
    {id:'jAlert15',   key:'jur_alert_15',       def:true},
    {id:'jAlert30',   key:'jur_alert_30',       def:true},
  ]);
  wireSaveBtn('btnSaveJuridico','Configurações jurídicas salvas');

  wireToggles([
    {id:'nPopup',    key:'cfg_notif_popup',    def:true},
    {id:'nWhats',    key:'cfg_notif_whats',    def:false},
    {id:'nEmail',    key:'cfg_notif_email',    def:false},
    {id:'nJur',      key:'cfg_notif_jur',      def:true},
    {id:'nComercial',key:'cfg_notif_comercial',def:true},
    {id:'nFinanc',   key:'cfg_notif_financ',   def:true},
    {id:'nInativo',  key:'cfg_notif_inativo',  def:false},
  ]);
  wireSaveBtn('btnSaveNotifs','Notificações salvas');

  wireToggles([
    {id:'secInact', key:'cfg_sec_secInact', def:true},
    {id:'secAdmin', key:'cfg_sec_secAdmin', def:true},
    {id:'secLog',   key:'cfg_sec_secLog',   def:true},
  ]);
  wireSaveBtn('btnSaveSec','Preferências de segurança salvas');

  // ── Dashboard status mirror ───────────────────────────────────────────────
  try{
    const saved=localStorage.getItem('dashboard_last_update');
    if(saved){ const el=document.getElementById('dashboardStatus'); if(el){ el.textContent=saved; el.style.color='#4ade80'; } }
    window.addEventListener('storage',function(e){ if(!e||e.key!=='dashboard_last_update') return; const el=document.getElementById('dashboardStatus'); if(el){ el.textContent=e.newValue; el.style.color='#4ade80'; } });
  }catch(e){}

  loadKPIs();
</script>
<script src="/assets/fog.js"></script>
</body>
</html>

