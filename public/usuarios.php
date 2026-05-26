<?php
require_once __DIR__ . '/../app/Models/Database.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: /sistema_vendas/public/login.php'); exit; }
$activePage = 'usuarios';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Gestão de Usuários — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/sistema_vendas/public/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/sistema_vendas/public/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/sistema_vendas/public/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/fog.css">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/sidebar.css?v=18">
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
    .usr-panel {
      background: linear-gradient(165deg, rgba(14,35,65,.94), rgba(10,23,43,.96));
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 20px;
      box-shadow: 0 14px 40px rgba(2,6,23,.45);
    }

    /* ── KPI Cards ── */
    .kpi-card {
      position: relative; overflow: hidden; border-radius: 14px;
      border: 1px solid rgba(160,180,210,.10);
      background: linear-gradient(145deg, #0C1A2C, #081020);
      padding: 16px; min-height: 100px;
      transition: transform .2s, border-color .2s, box-shadow .2s;
    }
    .kpi-card:hover { transform: translateY(-2px); border-color: rgba(160,180,210,.22); box-shadow: 0 8px 24px rgba(0,0,0,.40); }
    .kpi-card.kpi-ok { border-color: rgba(122,189,160,.18); }
    .kpi-label { color: #7A8898; font-size: .73rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
    .kpi-value { margin-top: 10px; color: #D8E4F0; font-size: 1.55rem; font-weight: 700; line-height: 1.1; }
    .kpi-foot  { margin-top: 6px; color: var(--muted); font-size: .72rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .kpi-dot   { position: absolute; top: 14px; right: 14px; width: 9px; height: 9px; border-radius: 50%; }
    .dot-ok      { background: #4A9078; }
    .dot-neutral { background: #3A4858; }
    .dot-primary { background: #3A6090; }

    /* ── Summary box ── */
    .summary-box { border-radius: 10px; border: 1px solid rgba(160,180,210,.10); background: rgba(8,18,32,.55); padding: 12px 16px; color: #A8BDD4; font-size: .87rem; line-height: 1.6; }
    .summary-box strong { color: #C8D4E0; }

    /* ── Toolbar ── */
    .usr-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .usr-search {
      flex: 1; min-width: 180px; height: 38px; padding: 0 12px;
      border-radius: 10px; border: 1px solid rgba(160,180,210,.14);
      background: #081220; color: #D8E4F0;
      font-family: inherit; font-size: .84rem;
    }
    .usr-search::placeholder { color: #4A5568; }
    .usr-search:focus { outline: none; border-color: rgba(160,180,210,.32); box-shadow: 0 0 0 2px rgba(30,58,95,.25); }
    .usr-select {
      height: 38px; padding: 0 10px;
      border-radius: 10px; border: 1px solid rgba(160,180,210,.14);
      background: #081220; color: #D8E4F0;
      font-family: inherit; font-size: .82rem; cursor: pointer;
    }
    .usr-select:focus { outline: none; border-color: rgba(160,180,210,.32); }
    .usr-select option { background: #0B1827; color: #D8E4F0; }
    .usr-btn-primary {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 0 18px; height: 38px;
      background: linear-gradient(135deg, #1A3A5C, #244E7A);
      color: #C8D4E0; border: 1px solid rgba(160,180,210,.20); border-radius: 10px;
      font-family: inherit; font-size: .84rem; font-weight: 600; cursor: pointer;
      box-shadow: 0 2px 8px rgba(0,0,0,.35);
      transition: transform .18s, filter .18s;
    }
    .usr-btn-primary:hover { filter: brightness(1.12); transform: translateY(-1px); }
    .usr-btn-secondary {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 0 14px; height: 38px;
      border-radius: 10px; border: 1px solid rgba(160,180,210,.18);
      background: transparent; color: #A8BDD4;
      font-family: inherit; font-size: .84rem; font-weight: 600; cursor: pointer;
      transition: background .18s;
    }
    .usr-btn-secondary:hover { background: rgba(20,50,90,.30); }

    /* ── Table ── */
    .usr-table { width: 100%; border-collapse: collapse; }
    .usr-table thead tr { border-bottom: 1px solid rgba(160,180,210,.10); }
    .usr-table th { padding: 10px 14px; text-align: left; font-size: .72rem; font-weight: 700; color: #7A8898; text-transform: uppercase; letter-spacing: .05em; }
    .usr-table tbody tr {
      border-bottom: 1px solid rgba(160,180,210,.06);
      transition: background .15s;
    }
    .usr-table tbody tr:last-child { border-bottom: none; }
    .usr-table tbody tr:hover { background: rgba(20,50,90,.12); }
    .usr-table td { padding: 12px 14px; vertical-align: middle; }

    /* ── Avatar ── */
    .usr-avatar {
      width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
      background: linear-gradient(135deg, #1A3A5C, #244E7A);
      display: flex; align-items: center; justify-content: center;
      font-size: .78rem; font-weight: 700; color: #C8D4E0;
      text-transform: uppercase; letter-spacing: .01em;
    }
    .usr-name    { font-size: .86rem; font-weight: 600; color: #D8E4F0; }
    .usr-email   { font-size: .74rem; color: var(--muted); margin-top: 1px; }
    .usr-id-tag  { font-size: .7rem; color: #4A5568; font-weight: 500; }

    /* ── Profile & Status badges ── */
    .badge {
      display: inline-flex; align-items: center; padding: 3px 9px;
      border-radius: 999px; font-size: .69rem; font-weight: 700; letter-spacing: .02em;
    }
    .badge-admin    { background: rgba(30,58,95,.40);   color: #B0BCCB; border: 1px solid rgba(176,188,203,.22); }
    .badge-user     { background: rgba(30,74,58,.50);   color: #7ABDA0; border: 1px solid rgba(122,189,160,.22); }
    .badge-other    { background: rgba(40,50,65,.50);   color: #6B7887; border: 1px solid rgba(107,120,135,.20); }
    .badge-active   { background: rgba(30,74,58,.50);   color: #7ABDA0; border: 1px solid rgba(122,189,160,.22); }
    .badge-inactive { background: rgba(40,50,65,.50);   color: #6B7887; border: 1px solid rgba(107,120,135,.20); }

    /* ── Profile select (inline) ── */
    .perfil-select {
      padding: 5px 8px; border-radius: 8px;
      border: 1px solid rgba(160,180,210,.18);
      background: #081220; color: #D8E4F0;
      font-family: inherit; font-size: .8rem; cursor: pointer;
    }
    .perfil-select:focus { outline: none; border-color: rgba(160,180,210,.32); }

    /* ── Action buttons (table) ── */
    .tbl-btn {
      display: inline-flex; align-items: center;
      padding: 4px 10px; border-radius: 7px; border: 1px solid transparent;
      font-family: inherit; font-size: .74rem; font-weight: 600; cursor: pointer;
      transition: opacity .15s, transform .12s;
    }
    .tbl-btn:hover { opacity: .85; transform: translateY(-1px); }
    .btn-edit { background: rgba(26,58,92,.70); color: #A8BDD4; border-color: rgba(160,180,210,.18); }
    .btn-del  { background: rgba(58,16,32,.70);  color: #B06070; border-color: rgba(176,96,112,.22); }

    /* ── Empty/loading row ── */
    .usr-empty { text-align: center; padding: 32px; color: var(--muted); font-size: .85rem; }

    /* ── Security tips ── */
    .tip-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .tip-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border-radius: 9px; background: rgba(8,18,30,.50); border: 1px solid rgba(160,180,210,.08); }
    .tip-icon { flex-shrink: 0; width: 28px; height: 28px; border-radius: 8px; background: rgba(26,58,92,.40); display: flex; align-items: center; justify-content: center; font-size: .9rem; }
    .tip-text { font-size: .78rem; color: #A8BDD4; line-height: 1.45; }

    /* ── Modal ── */
    .usr-modal-overlay {
      position: fixed; inset: 0; background: rgba(2,6,23,.65);
      display: flex; align-items: flex-start; justify-content: center;
      z-index: 1000; overflow-y: auto; padding: 30px 12px;
    }
    .usr-modal-overlay.hidden { display: none !important; }
    .usr-modal {
      width: 560px; max-width: 96vw; margin: auto;
      background: linear-gradient(165deg, rgba(10,24,46,.99), rgba(7,18,36,.99));
      border: 1px solid rgba(96,165,250,.22);
      border-radius: 16px;
      box-shadow: 0 24px 60px rgba(2,6,23,.7);
      overflow: hidden;
    }
    .usr-modal-header {
      display: flex; justify-content: space-between; align-items: center;
      padding: 16px 20px; border-bottom: 1px solid rgba(96,165,250,.14);
      background: rgba(8,22,44,.55);
    }
    .usr-modal-title { font-size: 1rem; font-weight: 700; color: #dbeafe; }
    .usr-modal-body  { padding: 18px 20px; display: flex; flex-direction: column; gap: 14px; max-height: 76vh; overflow-y: auto; }
    .usr-modal-section { border: 1px solid rgba(96,165,250,.12); border-radius: 10px; overflow: hidden; }
    .usr-modal-section-title { padding: 8px 14px; background: rgba(37,99,235,.14); font-size: .75rem; font-weight: 700; color: #93c5fd; letter-spacing: .04em; text-transform: uppercase; }
    .usr-modal-fields { padding: 12px; display: grid; grid-template-columns: repeat(2,1fr); gap: 10px; }
    .usr-modal-fields.cols-1 { grid-template-columns: 1fr; }
    .field-group { display: flex; flex-direction: column; gap: 5px; }
    .field-label { font-size: .78rem; font-weight: 600; color: #b8d5f4; }
    .field-input {
      width: 100%; padding: 8px 10px;
      border-radius: 8px; border: 1px solid rgba(96,165,250,.25);
      background: rgba(5,18,39,.9) !important; color: #d6eaff !important;
      -webkit-text-fill-color: #d6eaff !important;
      font-family: inherit; font-size: .84rem;
      transition: border-color .18s; color-scheme: dark;
    }
    .field-input::placeholder { color: rgba(148,163,184,.45) !important; }
    .field-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.2); }
    /* Fix autofill — mantém tema escuro */
    .field-input:-webkit-autofill,
    .field-input:-webkit-autofill:hover,
    .field-input:-webkit-autofill:focus {
      -webkit-box-shadow: 0 0 0 1000px #050f22 inset !important;
      -webkit-text-fill-color: #d6eaff !important;
      caret-color: #d6eaff;
    }
    .field-input.relative-wrap { position: relative; }
    .pw-toggle {
      position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
      background: transparent; border: none; cursor: pointer; color: #7eb8f6;
      padding: 2px; display: flex;
    }
    .field-wrap-rel { position: relative; }
    .field-wrap-rel .pw-toggle { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: transparent; border: none; cursor: pointer; color: rgba(11,27,43,.65); padding: 2px; display: flex; }
    .usr-modal-footer {
      padding: 14px 20px; border-top: 1px solid rgba(96,165,250,.14);
      background: rgba(8,22,44,.4); display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap;
    }
    .modal-btn-cancel { padding: 0 16px; height: 36px; border-radius: 8px; border: 1px solid rgba(96,165,250,.3); background: transparent; color: #93c5fd; font-family: inherit; font-size: .82rem; font-weight: 600; cursor: pointer; transition: background .18s; }
    .modal-btn-cancel:hover { background: rgba(37,99,235,.15); }
    .modal-btn-save   { padding: 0 20px; height: 36px; border-radius: 8px; border: none; background: linear-gradient(135deg,#2563eb,#1d4ed8); color: #fff; font-family: inherit; font-size: .82rem; font-weight: 600; cursor: pointer; box-shadow: 0 4px 14px rgba(37,99,235,.35); transition: transform .18s,box-shadow .18s; }
    .modal-btn-save:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(37,99,235,.5); }
    .modal-btn-danger { padding: 0 16px; height: 36px; border-radius: 8px; border: none; background: rgba(220,38,38,.85); color: #fff; font-family: inherit; font-size: .82rem; font-weight: 600; cursor: pointer; transition: opacity .18s; }
    .modal-btn-danger:hover { opacity: .82; }

    /* ── Permissões checkboxes ── */
    .perm-item {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 10px; border-radius: 8px;
      border: 1px solid rgba(96,165,250,.15);
      background: rgba(30,58,95,.15);
      cursor: pointer; transition: border-color .15s, background .15s;
    }
    .perm-item:hover { border-color: rgba(96,165,250,.35); background: rgba(30,58,95,.28); }
    .perm-item input[type=checkbox] { width:15px; height:15px; accent-color:#3b82f6; cursor:pointer; flex-shrink:0; }
    .perm-item label { font-size:.8rem; font-weight:600; color:#b8d5f4; cursor:pointer; user-select:none; }
    .perm-item.checked { border-color: rgba(96,165,250,.4); background: rgba(37,99,235,.18); }

    /* ── Toast ── */
    .toast-wrap { position:fixed; left:50%; transform:translateX(-50%); bottom:24px; z-index:2000; display:flex; flex-direction:column; gap:8px; align-items:center; }
    .toast { min-width:200px; max-width:400px; padding:10px 16px; border-radius:10px; box-shadow:0 6px 18px rgba(2,6,23,.5); font-size:.84rem; font-weight:600; opacity:0; transform:translateY(10px) scale(.98); transition:opacity .2s,transform .2s; }
    .toast.show { opacity:1; transform:translateY(0) scale(1); }
    .toast.success { background:linear-gradient(90deg,#059669,#10b981); color:#fff; }
    .toast.error   { background:linear-gradient(90deg,#dc2626,#ef4444); color:#fff; }

    @media (max-width: 900px) {
      .usr-table thead { display: none; }
      .usr-table tbody tr { display: block; margin-bottom: 10px; border-radius: 12px; border: 1px solid rgba(96,165,250,.12); background: rgba(10,24,46,.8); padding: 12px; }
      .usr-table td { display: flex; align-items: center; justify-content: space-between; padding: 5px 0; border: none; }
      .usr-table td::before { content: attr(data-label); font-size: .7rem; color: var(--muted); font-weight: 600; text-transform: uppercase; margin-right: 10px; }
      .tip-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .kpi-grid-5 { grid-template-columns: repeat(2,1fr) !important; }
      .usr-modal-fields { grid-template-columns: 1fr !important; }
    }

    /* ── Tab switcher ─────────────────────────────────────── */
    .tab-bar {
      display: inline-flex; gap: 3px;
      background: rgba(5,12,25,.55);
      border: 1px solid rgba(96,165,250,.12);
      border-radius: 10px; padding: 4px;
    }
    .tab-btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 18px; border-radius: 7px; border: none;
      background: transparent; color: var(--muted);
      font-family: inherit; font-size: .83rem; font-weight: 600; cursor: pointer;
      transition: all .15s;
    }
    .tab-btn.active  { background: rgba(26,58,92,.75); color: #dbeafe; box-shadow: 0 2px 8px rgba(0,0,0,.35); }
    .tab-btn:hover:not(.active) { color: var(--text); background: rgba(20,50,90,.3); }

    /* ── Teams grid ───────────────────────────────────────── */
    .teams-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
      gap: 14px;
    }
    .team-card {
      background: linear-gradient(145deg, #0C1A2C, #081020);
      border: 1px solid rgba(160,180,210,.10);
      border-radius: 12px; padding: 16px 16px 14px;
      position: relative; overflow: hidden;
      transition: transform .18s, border-color .18s, box-shadow .18s;
    }
    .team-card:hover { transform: translateY(-2px); border-color: rgba(160,180,210,.2); box-shadow: 0 6px 20px rgba(0,0,0,.38); }
    .team-card::before {
      content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
      background: var(--team-cor, #3B82F6); border-radius: 12px 0 0 12px;
    }
    .team-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
    .team-name { font-size: .9rem; font-weight: 700; color: #D8E4F0; }
    .team-desc { font-size: .76rem; color: var(--muted); margin-top: 4px; line-height: 1.4; }
    .team-members { margin-top: 12px; display: flex; align-items: center; gap: 8px; }
    .team-avatar-stack { display: flex; }
    .team-avatar-sm {
      width: 26px; height: 26px; border-radius: 50%;
      background: linear-gradient(135deg, #1A3A5C, #244E7A);
      border: 2px solid #070F1C;
      display: flex; align-items: center; justify-content: center;
      font-size: .62rem; font-weight: 700; color: #C8D4E0;
      margin-left: -8px; flex-shrink: 0;
    }
    .team-avatar-sm:first-child { margin-left: 0; }
    .team-member-count { font-size: .75rem; color: var(--muted); }
    .team-actions { display: flex; gap: 6px; flex-shrink: 0; }

    /* ── Color swatches ───────────────────────────────────── */
    .color-swatches { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
    .color-swatch {
      width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
      border: 2px solid transparent; transition: transform .12s, border-color .12s;
    }
    .color-swatch:hover { transform: scale(1.18); }
    .color-swatch.selected { border-color: #fff; box-shadow: 0 0 0 2px rgba(255,255,255,.3); }

    /* ── Team member picker ───────────────────────────────── */
    .tm-search-wrap { position: relative; margin-bottom: 10px; }
    .tm-search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); opacity: .35; pointer-events: none; }
    .tm-search {
      width: 100%;
      background: rgba(5,18,39,.85) !important;
      border: 1px solid rgba(96,165,250,.18);
      border-radius: 8px;
      color: #d6eaff !important;
      -webkit-text-fill-color: #d6eaff !important;
      font-family: inherit; font-size: .83rem;
      padding: 8px 10px 8px 32px;
      outline: none;
      box-shadow: 0 0 0 9999px rgba(5,18,39,.85) inset !important;
      color-scheme: dark; transition: border-color .15s;
    }
    .tm-search:focus { border-color: rgba(96,165,250,.4); }
    .tm-search::placeholder { color: rgba(148,163,184,.4) !important; }

    .tm-member-list { display: flex; flex-direction: column; gap: 5px; max-height: 260px; overflow-y: auto; }
    .tm-member {
      display: flex !important; flex-direction: row !important;
      align-items: center !important; gap: 10px;
      padding: 9px 12px; border-radius: 8px; cursor: pointer;
      border: 1px solid rgba(96,165,250,.08);
      background: rgba(8,18,32,.5);
      transition: background .12s, border-color .12s;
      user-select: none;
    }
    .tm-member:hover { background: rgba(30,58,95,.28); border-color: rgba(96,165,250,.2); }
    .tm-member:has(input:checked) {
      background: rgba(37,99,235,.14);
      border-color: rgba(96,165,250,.35);
    }
    .tm-member input[type=checkbox] { display: none; }
    .tm-avatar {
      width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
      background: linear-gradient(135deg, #1A3A5C, #244E7A);
      border: 1px solid rgba(96,165,250,.15);
      display: flex; align-items: center; justify-content: center;
      font-size: .75rem; font-weight: 700; color: #C8D4E0;
      transition: background .12s;
    }
    .tm-member:has(input:checked) .tm-avatar {
      background: linear-gradient(135deg, #1d4ed8, #3b82f6);
      border-color: rgba(96,165,250,.4);
    }
    .tm-info { flex: 1; min-width: 0; }
    .tm-name  { font-size: .83rem; font-weight: 600; color: #D8E4F0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tm-email { font-size: .71rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
    .tm-chk {
      width: 18px; height: 18px; flex-shrink: 0; border-radius: 5px;
      border: 1.5px solid rgba(96,165,250,.22);
      background: transparent;
      display: flex; align-items: center; justify-content: center;
      transition: all .12s; color: transparent;
    }
    .tm-member:has(input:checked) .tm-chk {
      background: #2563eb; border-color: #2563eb; color: #fff;
    }
    .tm-empty { text-align: center; padding: 20px; color: var(--muted); font-size: .8rem; }

    /* ═════════════════════════════════════════════════════════════════════
       TEMA CLARO — Gestão de Usuários (correção 2026-05-26)
       Página não tinha NENHUM override. Tudo aparecia escuro em fundo claro.
       ═════════════════════════════════════════════════════════════════════ */
    html[data-theme="light"] body {
      background-color: #F4F7FB;
      background-image: none;
      color: #0F1F36;
    }

    /* Painéis principais */
    html[data-theme="light"] .usr-panel {
      background: #FFFFFF !important;
      border: 1px solid #E2E8F0 !important;
      box-shadow: 0 1px 3px rgba(15,31,54,0.04) !important;
    }

    /* KPI cards — fundo branco com borda */
    html[data-theme="light"] .kpi-card {
      background: #FFFFFF !important;
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .kpi-card:hover {
      border-color: #CBD5E1 !important;
      box-shadow: 0 6px 18px rgba(15,31,54,0.08) !important;
    }
    html[data-theme="light"] .kpi-label { color: #64748B !important; }
    html[data-theme="light"] .kpi-value { color: #0F1F36 !important; }
    html[data-theme="light"] .kpi-foot  { color: #64748B !important; }

    /* Resumo executivo */
    html[data-theme="light"] .summary-box {
      background: #EFF6FF !important;
      border-color: #BFDBFE !important;
      color: #1E4A8A !important;
    }
    html[data-theme="light"] .summary-box strong { color: #0F1F36 !important; }

    /* Tabs (Usuários / Setores) */
    html[data-theme="light"] .tab-bar {
      background: #F1F5F9 !important;
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .tab-btn { color: #64748B !important; background: transparent !important; }
    html[data-theme="light"] .tab-btn:hover:not(.active) {
      background: #E2E8F0 !important; color: #0F1F36 !important;
    }
    html[data-theme="light"] .tab-btn.active {
      background: #FFFFFF !important;
      color: #1D4ED8 !important;
      box-shadow: 0 1px 3px rgba(15,31,54,0.10) !important;
    }

    /* Toolbar — busca, selects, botões */
    html[data-theme="light"] .usr-search,
    html[data-theme="light"] .usr-select,
    html[data-theme="light"] .perfil-select {
      background: #FFFFFF !important;
      border-color: #CBD5E1 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .usr-search::placeholder { color: #94A3B8 !important; }
    html[data-theme="light"] .usr-search:focus,
    html[data-theme="light"] .usr-select:focus,
    html[data-theme="light"] .perfil-select:focus {
      border-color: #2563EB !important;
      box-shadow: 0 0 0 3px rgba(37,99,235,0.12) !important;
    }
    html[data-theme="light"] .usr-select option,
    html[data-theme="light"] .perfil-select option {
      background: #FFFFFF !important; color: #0F1F36 !important;
    }

    html[data-theme="light"] .usr-btn-primary {
      background: #1D4ED8 !important;
      border-color: #1D4ED8 !important;
      color: #FFFFFF !important;
      box-shadow: 0 2px 6px rgba(37,99,235,0.25) !important;
    }
    html[data-theme="light"] .usr-btn-primary:hover { filter: brightness(1.05); }
    html[data-theme="light"] .usr-btn-secondary {
      border-color: #CBD5E1 !important;
      color: #475569 !important;
      background: #FFFFFF !important;
    }
    html[data-theme="light"] .usr-btn-secondary:hover { background: #F1F5F9 !important; }

    /* Tabela — header, linhas, divisores */
    html[data-theme="light"] .usr-table thead tr { border-bottom-color: #E2E8F0 !important; }
    html[data-theme="light"] .usr-table th { color: #64748B !important; }
    html[data-theme="light"] .usr-table tbody tr { border-bottom-color: #F1F5F9 !important; }
    html[data-theme="light"] .usr-table tbody tr:hover { background: #F8FAFC !important; }

    /* Avatar, nome, email, id — TEXTOS NAVY ESCURO LEGÍVEL */
    html[data-theme="light"] .usr-avatar {
      background: linear-gradient(135deg, #DBEAFE, #BFDBFE) !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .usr-name  { color: #0F1F36 !important; font-weight: 600 !important; }
    html[data-theme="light"] .usr-email { color: #64748B !important; }
    html[data-theme="light"] .usr-id-tag { color: #94A3B8 !important; }

    /* Badge ADV-XXXX — antes era inline preto. Tema claro: fundo lavanda */
    html[data-theme="light"] .usr-table code {
      background: #EFF6FF !important;
      color: #1D4ED8 !important;
      border: 1px solid #BFDBFE !important;
    }
    html[data-theme="light"] .usr-table button[title="Copiar"] { color: #94A3B8 !important; }
    html[data-theme="light"] .usr-table button[title="Copiar"]:hover { color: #1D4ED8 !important; }

    /* Badges Perfil/Status */
    html[data-theme="light"] .badge-admin {
      background: #DBEAFE !important; color: #1D4ED8 !important;
      border-color: #93C5FD !important;
    }
    html[data-theme="light"] .badge-user {
      background: #D1FAE5 !important; color: #047857 !important;
      border-color: #6EE7B7 !important;
    }
    html[data-theme="light"] .badge-other {
      background: #F1F5F9 !important; color: #475569 !important;
      border-color: #CBD5E1 !important;
    }
    html[data-theme="light"] .badge-active {
      background: #D1FAE5 !important; color: #047857 !important;
      border-color: #6EE7B7 !important;
    }
    html[data-theme="light"] .badge-inactive {
      background: #F1F5F9 !important; color: #94A3B8 !important;
      border-color: #CBD5E1 !important;
    }

    /* Botões de ação na tabela */
    html[data-theme="light"] .btn-edit {
      background: #DBEAFE !important; color: #1D4ED8 !important;
      border-color: #93C5FD !important;
    }
    html[data-theme="light"] .btn-edit:hover { background: #BFDBFE !important; }
    html[data-theme="light"] .btn-del {
      background: #FEE2E2 !important; color: #DC2626 !important;
      border-color: #FECACA !important;
    }
    html[data-theme="light"] .btn-del:hover { background: #FECACA !important; }

    /* Boas práticas de segurança */
    html[data-theme="light"] .tip-item {
      background: #F8FAFC !important;
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .tip-icon {
      background: #DBEAFE !important; color: #1D4ED8 !important;
    }
    html[data-theme="light"] .tip-text { color: #475569 !important; }

    /* H3 dentro de painéis (Equipe, Boas práticas, Setores) */
    html[data-theme="light"] .usr-panel h3 { color: #0F1F36 !important; }
    html[data-theme="light"] .usr-panel h3 svg { color: #1E4A8A !important; }
    html[data-theme="light"] .usr-panel p { color: #64748B !important; }

    /* Setores (Times) */
    html[data-theme="light"] .team-card {
      background: #FFFFFF !important;
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .team-card:hover {
      border-color: #CBD5E1 !important;
      box-shadow: 0 6px 18px rgba(15,31,54,0.08) !important;
    }
    html[data-theme="light"] .team-name  { color: #0F1F36 !important; }
    html[data-theme="light"] .team-desc  { color: #64748B !important; }
    html[data-theme="light"] .team-member-count { color: #64748B !important; }
    html[data-theme="light"] .team-avatar-sm {
      background: linear-gradient(135deg, #DBEAFE, #BFDBFE) !important;
      color: #1D4ED8 !important;
      border-color: #FFFFFF !important;
    }

    /* Modal Novo/Editar Usuário */
    html[data-theme="light"] .usr-modal-overlay { background: rgba(15,31,54,0.45) !important; }
    html[data-theme="light"] .usr-modal {
      background: #FFFFFF !important;
      border-color: #E2E8F0 !important;
      box-shadow: 0 24px 60px rgba(15,31,54,0.18) !important;
    }
    html[data-theme="light"] .usr-modal-header {
      background: #F8FAFC !important;
      border-bottom-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .usr-modal-title { color: #0F1F36 !important; }
    html[data-theme="light"] .usr-modal-section {
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .usr-modal-section-title {
      background: #EFF6FF !important; color: #1D4ED8 !important;
    }
    html[data-theme="light"] .field-label { color: #475569 !important; }
    html[data-theme="light"] .field-input {
      background: #FFFFFF !important;
      background-color: #FFFFFF !important;
      border-color: #CBD5E1 !important;
      color: #0F1F36 !important;
      -webkit-text-fill-color: #0F1F36 !important;
      color-scheme: light;
    }
    html[data-theme="light"] .field-input::placeholder { color: #94A3B8 !important; }
    html[data-theme="light"] .field-input:focus { border-color: #2563EB !important; }
    html[data-theme="light"] .field-input:-webkit-autofill,
    html[data-theme="light"] .field-input:-webkit-autofill:hover,
    html[data-theme="light"] .field-input:-webkit-autofill:focus {
      -webkit-box-shadow: 0 0 0 1000px #FFFFFF inset !important;
      -webkit-text-fill-color: #0F1F36 !important;
    }
    html[data-theme="light"] .usr-modal-footer {
      background: #F8FAFC !important;
      border-top-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .modal-btn-cancel {
      background: #FFFFFF !important;
      border-color: #CBD5E1 !important;
      color: #475569 !important;
    }
    html[data-theme="light"] .modal-btn-cancel:hover { background: #F1F5F9 !important; }

    /* Permissões checkboxes */
    html[data-theme="light"] .perm-item {
      background: #F8FAFC !important;
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .perm-item:hover {
      background: #EFF6FF !important; border-color: #93C5FD !important;
    }
    html[data-theme="light"] .perm-item.checked {
      background: #DBEAFE !important; border-color: #2563EB !important;
    }
    html[data-theme="light"] .perm-item label { color: #1E4A8A !important; }

    /* Team member picker */
    html[data-theme="light"] .tm-avatar {
      background: linear-gradient(135deg, #DBEAFE, #BFDBFE) !important;
      color: #1D4ED8 !important;
      border-color: #BFDBFE !important;
    }
    html[data-theme="light"] .tm-name { color: #0F1F36 !important; }

    /* Mobile (cards) */
    html[data-theme="light"] .usr-table td::before { color: #64748B !important; }
    @media (max-width: 900px) {
      html[data-theme="light"] .usr-table tbody tr {
        background: #FFFFFF !important;
        border-color: #E2E8F0 !important;
      }
    }
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
        <div class="usr-panel page-header">
          <div class="page-header-inner">
            <div class="page-header-text">
              <h2 class="page-header-title">Gestão de Usuários</h2>
              <p class="page-header-subtitle">Controle de acessos, perfis e permissões do escritório</p>
            </div>
          </div>
        </div>

        <!-- Tab switcher -->
        <div style="margin-bottom:4px">
          <div class="tab-bar">
            <button class="tab-btn active" id="tabBtnUsuarios" onclick="switchTab('usuarios')">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              Usuários
            </button>
            <button class="tab-btn" id="tabBtnTimes" onclick="switchTab('times')">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              Setores
            </button>
          </div>
        </div>

        <!-- ── Seção Usuários ── -->
        <div id="sectionUsuarios">

        <!-- KPI cards -->
        <div class="kpi-grid-5" style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px">
          <div class="kpi-card">
            <div class="kpi-dot dot-primary"></div>
            <div class="kpi-label">Total de usuários</div>
            <div class="kpi-value" id="kpiTotal">—</div>
            <div class="kpi-foot">cadastrados no sistema</div>
          </div>
          <div class="kpi-card kpi-ok">
            <div class="kpi-dot dot-ok"></div>
            <div class="kpi-label">Ativos</div>
            <div class="kpi-value" id="kpiAtivos">—</div>
            <div class="kpi-foot">com acesso habilitado</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-dot dot-primary"></div>
            <div class="kpi-label">Administradores</div>
            <div class="kpi-value" id="kpiAdmins">—</div>
            <div class="kpi-foot">perfil admin</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-dot dot-neutral"></div>
            <div class="kpi-label">Usuários</div>
            <div class="kpi-value" id="kpiUsers">—</div>
            <div class="kpi-foot">perfil user</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-dot dot-neutral"></div>
            <div class="kpi-label">Último cadastro</div>
            <div class="kpi-value" id="kpiLast" style="font-size:1rem;margin-top:8px">—</div>
            <div class="kpi-foot" id="kpiLastSub">—</div>
          </div>
        </div>

        <!-- Resumo executivo -->
        <div class="usr-panel" style="padding:13px 18px">
          <div id="resumoUsuarios" class="summary-box">Carregando resumo...</div>
        </div>

        <!-- Tabela de usuários -->
        <div class="usr-panel">
          <!-- Toolbar -->
          <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
            <div>
              <h3 style="font-size:1rem;font-weight:600;color:#dbeafe">Equipe do escritório</h3>
              <p style="font-size:.8rem;color:var(--muted);margin-top:2px">Gerencie usuários, perfis e acessos</p>
            </div>
            <div class="usr-toolbar">
              <input type="text" id="searchUsers" class="usr-search" placeholder="Buscar por nome ou e-mail…">
              <select id="filterPerfil" class="usr-select">
                <option value="">Todos os perfis</option>
                <option value="admin">Admin</option>
                <option value="user">User</option>
              </select>
              <select id="filterStatus" class="usr-select">
                <option value="">Todos os status</option>
                <option value="active">Ativo</option>
                <option value="inactive">Inativo</option>
              </select>
              <button id="btnRefresh" class="usr-btn-secondary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Atualizar
              </button>
              <button id="btnNewUser" class="usr-btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Novo Usuário
              </button>
            </div>
          </div>

          <div class="overflow-auto">
            <table class="usr-table" id="usersTable">
              <thead>
                <tr>
                  <th>Usuário</th>
                  <th>ID</th>
                  <th>Código do Advogado</th>
                  <th>Perfil</th>
                  <th>Status</th>
                  <th>Alterar perfil</th>
                  <th>Ações</th>
                </tr>
              </thead>
              <tbody id="usersTbody">
                <tr><td colspan="7" class="usr-empty">Carregando usuários...</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Boas práticas de segurança -->
        <div class="usr-panel">
          <h3 style="font-size:.92rem;font-weight:600;color:#dbeafe;margin-bottom:14px"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Boas práticas de segurança</h3>
          <div class="tip-grid">
            <div class="tip-item">
              <div class="tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
              <div class="tip-text">Use perfis diferentes por função — não atribua admin para todos os colaboradores.</div>
            </div>
            <div class="tip-item">
              <div class="tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M21 2l-9.6 9.6M15.5 7.5l3 3"/></svg></div>
              <div class="tip-text">Evite compartilhar credenciais de acesso entre colaboradores do escritório.</div>
            </div>
            <div class="tip-item">
              <div class="tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>
              <div class="tip-text">Revise periodicamente usuários inativos e remova acessos desnecessários.</div>
            </div>
            <div class="tip-item">
              <div class="tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
              <div class="tip-text">Restrinja permissões administrativas ao mínimo necessário para cada função.</div>
            </div>
          </div>
        </div>

        </div><!-- /sectionUsuarios -->

        <!-- ── Seção Times ── -->
        <div id="sectionTimes" style="display:none" class="space-y-4">
          <div class="usr-panel">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
              <div>
                <h3 style="font-size:1rem;font-weight:600;color:#dbeafe">Setores do escritório</h3>
                <p style="font-size:.8rem;color:var(--muted);margin-top:2px">Organize colaboradores em setores para facilitar atribuições e filtros</p>
              </div>
              <div style="display:flex;gap:8px">
                <button class="usr-btn-primary" onclick="openCreateTeamModal()">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Novo Setor
                </button>
              </div>
            </div>
            <div id="teamsGrid" class="teams-grid">
              <div style="text-align:center;padding:40px;color:var(--muted);font-size:.85rem;grid-column:1/-1">Carregando setores…</div>
            </div>
          </div>
        </div><!-- /sectionTimes -->

      </section>
    </div>
  </main>

  <!-- ── Modal criar usuário ── -->
  <div id="modalCreateUser" class="usr-modal-overlay hidden">
    <div class="usr-modal">
      <div class="usr-modal-header">
        <span class="usr-modal-title">Novo Usuário</span>
        <button type="button" id="cancelCreateUserX" class="modal-btn-cancel" style="height:30px;padding:0 10px;font-size:.78rem"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
      </div>
      <div class="usr-modal-body">
        <form id="createUserForm">
          <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">

          <!-- Bloco 1 — Dados -->
          <div class="usr-modal-section">
            <div class="usr-modal-section-title">Dados do usuário</div>
            <div class="usr-modal-fields">
              <div class="field-group" style="grid-column:1/-1">
                <label class="field-label">Nome completo</label>
                <input name="nome" class="field-input" placeholder="Nome do colaborador" required>
              </div>
              <div class="field-group" style="grid-column:1/-1">
                <label class="field-label">E-mail</label>
                <input name="email" type="email" class="field-input" placeholder="email@escritorio.com.br" required>
              </div>
            </div>
          </div>

          <!-- Bloco 2 — Acesso -->
          <div class="usr-modal-section" style="margin-top:12px">
            <div class="usr-modal-section-title"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:6px"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M21 2l-9.6 9.6"/><path d="M15.5 7.5l3 3L22 7l-3-3"/></svg> Acesso</div>
            <div class="usr-modal-fields">
              <div class="field-group">
                <label class="field-label">Perfil</label>
                <select name="perfil" class="field-input">
                  <option value="admin">Administrador</option>
                  <option value="user" selected>Usuário</option>
                </select>
              </div>
              <div class="field-group">
                <label class="field-label">Senha inicial</label>
                <div class="field-wrap-rel">
                  <input id="create_senha" name="senha" type="password" class="field-input" placeholder="Senha de acesso" required style="padding-right:34px">
                  <button type="button" class="password-toggle pw-toggle" data-target="create_senha" aria-label="Mostrar senha">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Bloco 3 — Permissões (só para perfil user) -->
          <div class="usr-modal-section" id="createPermsSection" style="margin-top:12px;display:none">
            <div class="usr-modal-section-title"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:6px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Permissões de Acesso</div>
            <div style="padding:12px">
              <p style="font-size:.76rem;color:#93c5fd;margin:0 0 10px">Selecione os módulos que este usuário pode acessar:</p>
              <div id="createPermsGrid" style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px"></div>
            </div>
          </div>

          <div class="usr-modal-footer" style="padding:12px 0 0;border-top:none;background:transparent;margin-top:6px">
            <button type="button" id="cancelCreateUser" class="modal-btn-cancel">Cancelar</button>
            <button type="submit" class="modal-btn-save">Criar Usuário</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Modal editar usuário ── -->
  <div id="modalEditUser" class="usr-modal-overlay hidden">
    <div class="usr-modal">
      <div class="usr-modal-header">
        <span class="usr-modal-title">Editar Usuário</span>
        <button type="button" id="cancelEditUserX" class="modal-btn-cancel" style="height:30px;padding:0 10px;font-size:.78rem"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
      </div>
      <div class="usr-modal-body">
        <form id="editUserForm">
          <input type="hidden" name="id">
          <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">

          <!-- Bloco 1 — Dados -->
          <div class="usr-modal-section">
            <div class="usr-modal-section-title">Dados do usuário</div>
            <div class="usr-modal-fields">
              <div class="field-group" style="grid-column:1/-1">
                <label class="field-label">Nome completo</label>
                <input name="nome" class="field-input" placeholder="Nome do colaborador" required>
              </div>
              <div class="field-group" style="grid-column:1/-1">
                <label class="field-label">E-mail</label>
                <input name="email" type="email" class="field-input" placeholder="email@escritorio.com.br" required>
              </div>
            </div>
          </div>

          <!-- Bloco 2 — Acesso -->
          <div class="usr-modal-section" style="margin-top:12px" id="editAcessoSection">
            <div class="usr-modal-section-title"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:6px"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M21 2l-9.6 9.6"/><path d="M15.5 7.5l3 3L22 7l-3-3"/></svg> Acesso</div>
            <div class="usr-modal-fields">
              <div class="field-group" id="editPerfilGroup">
                <label class="field-label">Perfil</label>
                <select name="perfil" id="editPerfil" class="field-input">
                  <option value="admin">Administrador</option>
                  <option value="user">Usuário</option>
                </select>
              </div>
              <div class="field-group">
                <label class="field-label">Senha atual</label>
                <input id="edit_senha_atual" type="text" class="field-input" readonly
                  placeholder="Não registrada"
                  style="font-family:monospace;background:rgba(5,18,39,.5)!important;cursor:default;color:#6ee7b7!important;">
              </div>
            </div>
            <div class="usr-modal-fields" style="padding-top:0">
              <div class="field-group" style="grid-column:1/-1">
                <label class="field-label">Nova senha <span style="font-size:.72rem;color:#7a8898;font-weight:400">(deixe em branco para não alterar)</span></label>
                <input id="edit_senha" name="senha" type="text" class="field-input" placeholder="Digite para alterar a senha" style="font-family:monospace;">
              </div>
            </div>
          </div>

          <!-- Bloco 3 — Permissões (só para não-admin) -->
          <div class="usr-modal-section" id="permsSection" style="margin-top:12px;display:none">
            <div class="usr-modal-section-title"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:6px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Permissões de Acesso</div>
            <div style="padding:12px">
              <p style="font-size:.76rem;color:#93c5fd;margin:0 0 10px">Selecione os módulos que este usuário pode acessar:</p>
              <div id="permsGrid" style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px"></div>
            </div>
          </div>

          <div class="usr-modal-footer" style="padding:12px 0 0;border-top:none;background:transparent;margin-top:6px">
            <button type="button" id="deleteUser" class="modal-btn-danger">Excluir</button>
            <button type="button" id="cancelEditUser" class="modal-btn-cancel">Cancelar</button>
            <button type="submit" class="modal-btn-save">Salvar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Modal criar/editar setor ── -->
  <div id="modalTeam" class="usr-modal-overlay hidden">
    <div class="usr-modal">
      <div class="usr-modal-header">
        <span class="usr-modal-title" id="modalTeamTitle">Novo Time</span>
        <button type="button" onclick="closeModal('modalTeam')" class="modal-btn-cancel" style="height:30px;padding:0 10px;font-size:.78rem">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="usr-modal-body">
        <input type="hidden" id="teamId">

        <!-- Identificação -->
        <div class="usr-modal-section">
          <div class="usr-modal-section-title">Identificação</div>
          <div class="usr-modal-fields cols-1">
            <div class="field-group">
              <label class="field-label">Nome do setor</label>
              <input id="teamNome" class="field-input" placeholder="Ex: Setor Trabalhista">
            </div>
            <div class="field-group">
              <label class="field-label">Cor de identificação</label>
              <div style="display:flex;align-items:center;gap:10px;padding:7px 10px;background:rgba(5,18,39,.9);border:1px solid rgba(96,165,250,.25);border-radius:8px;transition:border-color .18s" onclick="document.getElementById('teamCor').click()" style="cursor:pointer">
                <input type="color" id="teamCor" value="#3B82F6"
                  style="width:30px;height:26px;padding:1px;border:1px solid rgba(96,165,250,.2);border-radius:4px;background:transparent;cursor:pointer;flex-shrink:0"
                  oninput="document.getElementById('teamCorLabel').textContent=this.value">
                <span id="teamCorLabel" style="font-size:.83rem;color:#d6eaff;font-family:monospace;letter-spacing:.04em">#3B82F6</span>
                <span style="margin-left:auto;font-size:.75rem;color:var(--muted)">Clique para escolher</span>
              </div>
            </div>
            <div class="field-group">
              <label class="field-label">Descrição <span style="font-size:.72rem;color:#7a8898;font-weight:400">(opcional)</span></label>
              <input id="teamDesc" class="field-input" placeholder="Breve descrição do setor">
            </div>
          </div>
        </div>

        <!-- Membros -->
        <div class="usr-modal-section" style="margin-top:12px">
          <div class="usr-modal-section-title">Membros</div>
          <div style="padding:12px">
            <div class="tm-search-wrap">
              <svg class="tm-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" id="teamMemberSearch" class="tm-search" placeholder="Buscar membro por nome ou e-mail…" oninput="filterTeamMembers(this.value)">
            </div>
            <div id="teamMembersGrid" class="tm-member-list"></div>
          </div>
        </div>
      </div>
      <div class="usr-modal-footer">
        <button id="deleteTeamBtn" class="modal-btn-danger" style="display:none" onclick="deleteTeamFromModal()">Excluir</button>
        <button class="modal-btn-cancel" onclick="closeModal('modalTeam')">Cancelar</button>
        <button class="modal-btn-save" onclick="saveTeam()">Salvar</button>
      </div>
    </div>
  </div>

  <div class="toast-wrap" id="toastWrap" aria-live="polite" aria-atomic="true"></div>


  <script>
    const apiUsers  = '/sistema_vendas/public/api/users.php';
    const csrf      = '<?=htmlspecialchars($csrf)?>';
    const authUserId= <?= json_encode($_SESSION['user_id'] ?? null) ?>;

    const ALL_PAGES = [
      { key:'dashboard',    label:'Dashboard' },
      { key:'planejamento', label:'Planejamento' },
      { key:'prospeccao',   label:'Prospecção' },
      { key:'financas',     label:'Finanças' },
      { key:'processos',    label:'Processos' },
      { key:'juridico',     label:'Jurídico' },
      { key:'usuarios',     label:'Usuários' },
      { key:'agente',       label:'Agente' },
      { key:'chat',         label:'WhatsApp' },
      { key:'chat_interno', label:'Chat Interno' },
      { key:'configuracoes',label:'Configurações' },
    ];

    // ── Helpers ──────────────────────────────────────────────────────────────
    function escapeHtml(s){ return s ? s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') : ''; }

    function initials(nome){
      if (!nome) return '?';
      const parts = nome.trim().split(/\s+/);
      if (parts.length >= 2) return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
      return nome.substring(0,2).toUpperCase();
    }

    function perfilBadge(perfil){
      if (perfil === 'admin') return '<span class="badge badge-admin">Admin</span>';
      if (perfil === 'user')  return '<span class="badge badge-user">Usuário</span>';
      return '<span class="badge badge-other">' + escapeHtml(perfil||'—') + '</span>';
    }

    function statusBadge(status){
      const s = String(status||'active').toLowerCase();
      if (s === 'active' || s === 'ativo' || s === '1') return '<span class="badge badge-active">Ativo</span>';
      return '<span class="badge badge-inactive">Inativo</span>';
    }

    // ── KPIs & Resumo ─────────────────────────────────────────────────────────
    function updateKPIs(users){
      const total  = users.length;
      const admins = users.filter(u => u.perfil === 'admin').length;
      const regular= users.filter(u => u.perfil !== 'admin').length;
      const ativos = users.filter(u => { const s=String(u.status||'active').toLowerCase(); return s==='active'||s==='ativo'||s==='1'; }).length;
      const last   = users.length ? users[users.length-1] : null;

      const set = (id,v) => { const el=document.getElementById(id); if(el) el.textContent=v; };
      set('kpiTotal',  total);
      set('kpiAtivos', ativos);
      set('kpiAdmins', admins);
      set('kpiUsers',  regular);
      if (last){
        const el = document.getElementById('kpiLast');
        const sub= document.getElementById('kpiLastSub');
        if (el)  el.textContent = last.nome ? last.nome.split(' ')[0] : 'ID '+last.id;
        if (sub) sub.textContent= perfilLabel(last.perfil);
      }

      const rEl = document.getElementById('resumoUsuarios');
      if (rEl){
        const parts = [`O sistema possui <strong>${total}</strong> usuário${total!==1?'s':''} cadastrado${total!==1?'s':''}`];
        if (admins > 0) parts.push(`sendo <strong>${admins}</strong> administrador${admins!==1?'es':''}`);
        if (ativos > 0) parts.push(`<strong>${ativos}</strong> ativo${ativos!==1?'s':''}`);
        parts.push('Revise as permissões periodicamente para manter a segurança do escritório');
        rEl.innerHTML = parts.join(', ') + '.';
      }
    }

    function perfilLabel(perfil){
      if (perfil==='admin') return 'Administrador';
      if (perfil==='user')  return 'Usuário';
      return perfil||'—';
    }

    // ── Stored users for filtering ────────────────────────────────────────────
    let _allUsers = [];

    function renderUsers(users){
      const tbody = document.getElementById('usersTbody');
      if (!users.length){
        tbody.innerHTML = '<tr><td colspan="7" class="usr-empty">Nenhum usuário encontrado</td></tr>';
        return;
      }
      tbody.innerHTML = '';
      users.forEach(u => {
        const perfil  = u.perfil || 'user';
        const status  = u.status || 'active';
        const ini     = initials(u.nome||'');
        const codigo  = u.codigo_vinculo || '—';
        const tr      = document.createElement('tr');
        tr.innerHTML =
          `<td data-label="Usuário">` +
            `<div style="display:flex;align-items:center;gap:10px">` +
              `<div class="usr-avatar">${ini}</div>` +
              `<div>` +
                `<div class="usr-name">${escapeHtml(u.nome||'—')}</div>` +
                `<div class="usr-email">${escapeHtml(u.email||'—')}</div>` +
              `</div>` +
            `</div>` +
          `</td>` +
          `<td data-label="ID"><span class="usr-id-tag">#${u.display_id ?? u.id}</span></td>` +
          `<td data-label="Código do Advogado">` +
            `<div style="display:flex;align-items:center;gap:6px;">` +
              `<code style="font-family:monospace;font-size:.78rem;color:#7eb8f6;background:rgba(5,18,39,.7);padding:3px 8px;border-radius:5px;border:1px solid rgba(96,165,250,.15);letter-spacing:.04em">${escapeHtml(codigo)}</code>` +
              `<button onclick="navigator.clipboard.writeText('${escapeHtml(codigo)}').then(()=>{this.textContent='✓';setTimeout(()=>this.textContent='⧉',1500)})" title="Copiar" style="background:none;border:none;color:#4a6070;cursor:pointer;font-size:.85rem;padding:2px 4px">⧉</button>` +
            `</div>` +
          `</td>` +
          `<td data-label="Perfil">${perfilBadge(perfil)}</td>` +
          `<td data-label="Status">${statusBadge(status)}</td>` +
          `<td data-label="Alterar perfil">` +
            `<select class="perfil-select" data-id="${u.id}">` +
              `<option value="admin"${perfil==='admin'?' selected':''}>Admin</option>` +
              `<option value="user"${perfil==='user'?' selected':''}>User</option>` +
            `</select>` +
          `</td>` +
          `<td data-label="Ações">` +
            `<div style="display:flex;gap:6px">` +
              `<button class="tbl-btn btn-edit edit-user" data-id="${u.id}">Editar</button>` +
              `<button class="tbl-btn btn-del delete-user" data-id="${u.id}">Excluir</button>` +
            `</div>` +
          `</td>`;
        tbody.appendChild(tr);
      });
    }

    function applyFilters(){
      const q      = (document.getElementById('searchUsers')?.value || '').toLowerCase().trim();
      const perfil = document.getElementById('filterPerfil')?.value || '';
      const status = document.getElementById('filterStatus')?.value || '';
      let filtered = _allUsers;
      if (q)      filtered = filtered.filter(u => (u.nome||'').toLowerCase().includes(q) || (u.email||'').toLowerCase().includes(q));
      if (perfil) filtered = filtered.filter(u => (u.perfil||'user') === perfil);
      if (status) filtered = filtered.filter(u => {
        const s = String(u.status||'active').toLowerCase();
        const isActive = s==='active'||s==='ativo'||s==='1';
        return status === 'active' ? isActive : !isActive;
      });
      renderUsers(filtered);
    }

    // ── Load users ────────────────────────────────────────────────────────────
    async function loadUsers(){
      const tbody = document.getElementById('usersTbody');
      tbody.innerHTML = '<tr><td colspan="7" class="usr-empty">Carregando...</td></tr>';
      try{
        const res = await fetch(apiUsers);
        const j   = await res.json();
        _allUsers = j.data || [];
        applyFilters();
        updateKPIs(_allUsers);
      } catch(e){
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="7" class="usr-empty" style="color:#f87171">Erro ao carregar usuários</td></tr>';
      }
    }

    // ── Filter events ─────────────────────────────────────────────────────────
    document.getElementById('searchUsers')?.addEventListener('input', applyFilters);
    document.getElementById('filterPerfil')?.addEventListener('change', applyFilters);
    document.getElementById('filterStatus')?.addEventListener('change', applyFilters);

    // ── Modal helpers ─────────────────────────────────────────────────────────
    const openModal  = id => document.getElementById(id)?.classList.remove('hidden');
    const closeModal = id => document.getElementById(id)?.classList.add('hidden');

    document.getElementById('btnRefresh').addEventListener('click', loadUsers);
    document.getElementById('btnNewUser').addEventListener('click', () => openModal('modalCreateUser'));
    document.getElementById('cancelCreateUser').addEventListener('click',  () => closeModal('modalCreateUser'));
    document.getElementById('cancelCreateUserX').addEventListener('click', () => closeModal('modalCreateUser'));
    document.getElementById('cancelEditUser').addEventListener('click',  () => closeModal('modalEditUser'));
    document.getElementById('cancelEditUserX').addEventListener('click', () => closeModal('modalEditUser'));

    // ── Inline perfil change ───────────────────────────────────────────────────
    document.getElementById('usersTbody').addEventListener('change', async function(e){
      const sel = e.target.closest('select.perfil-select');
      if (!sel) return;
      const id = sel.dataset.id, perfil = sel.value;
      if (!id) return;
      if (String(id) === String(authUserId) && perfil !== 'admin'){
        if (!(await Yuris.confirm('Você está prestes a remover seu próprio acesso de administrador. Continuar?', { danger: true, okLabel: 'Continuar' }))){ sel.value='admin'; return; }
      }
      try{
        const res = await fetch(apiUsers, {method:'PUT', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body:JSON.stringify({id,perfil,csrf_token:csrf})});
        const j   = await res.json();
        if (j && j.success) { showToast('Perfil atualizado'); loadUsers(); }
        else { showToast('Erro ao atualizar perfil: '+(j&&(j.error||j.message)?j.error||j.message:'erro'),'error'); loadUsers(); }
      }catch(err){ console.error(err); showToast('Erro de rede ao atualizar perfil','error'); loadUsers(); }
    });

    // ── Create user — permissões ──────────────────────────────────────────────
    function renderCreatePermsGrid() {
      const grid = document.getElementById('createPermsGrid');
      grid.innerHTML = '';
      ALL_PAGES.forEach(p => {
        const item = document.createElement('label');
        item.className = 'perm-item';
        item.innerHTML = `<input type="checkbox" value="${p.key}"><span>${p.label}</span>`;
        item.querySelector('input').addEventListener('change', () => item.classList.toggle('checked', item.querySelector('input').checked));
        grid.appendChild(item);
      });
    }
    renderCreatePermsGrid();

    document.querySelector('[name=perfil]', document.getElementById('createUserForm'))?.addEventListener?.('change', function(){
      document.getElementById('createPermsSection').style.display = this.value === 'user' ? 'block' : 'none';
    });
    // trigger on select inside the form
    document.getElementById('createUserForm').querySelector('select[name=perfil]').addEventListener('change', function(){
      document.getElementById('createPermsSection').style.display = this.value !== 'admin' ? 'block' : 'none';
    });

    // ── Create user ───────────────────────────────────────────────────────────
    document.getElementById('createUserForm').addEventListener('submit', async function(e){
      e.preventDefault();
      const payload = Object.fromEntries(new FormData(e.target).entries());
      // inclui permissões se seção visível
      if (document.getElementById('createPermsSection').style.display !== 'none') {
        payload.permissions = Array.from(document.querySelectorAll('#createPermsGrid input[type=checkbox]:checked')).map(cb => cb.value);
      }
      const res = await fetch(apiUsers, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body:JSON.stringify(payload)});
      const j   = await res.json();
      if (j && j.success){ closeModal('modalCreateUser'); e.target.reset(); loadUsers(); showToast('Usuário criado com sucesso'); }
      else showToast('Erro ao criar usuário: '+(j&&j.error?j.error:''),'error');
    });

    // toggle permissions section when perfil changes
    document.getElementById('editPerfil').addEventListener('change', function(){
      const uid = document.getElementById('editUserForm').querySelector('[name=id]').value;
      togglePermsSection(this.value, uid);
    });

    // ── Edit user ─────────────────────────────────────────────────────────────
    document.getElementById('editUserForm').addEventListener('submit', async function(e){
      e.preventDefault();
      const fd      = new FormData(e.target);
      const payload = Object.fromEntries(fd.entries());
      // include permissions if section is visible
      if (document.getElementById('permsSection').style.display !== 'none') {
        payload.permissions = getCheckedPerms();
      }
      const res = await fetch(apiUsers, {method:'PUT', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body:JSON.stringify(payload)});
      const j   = await res.json();
      if (j && j.success){ closeModal('modalEditUser'); loadUsers(); showToast('Usuário salvo'); }
      else showToast('Erro ao salvar: '+(j&&j.error?j.error:''),'error');
    });

    // ── Permissions section helpers ───────────────────────────────────────────
    function renderPermsGrid(checkedPages) {
      const grid = document.getElementById('permsGrid');
      grid.innerHTML = '';
      ALL_PAGES.forEach(p => {
        const isChecked = checkedPages.includes(p.key);
        const item = document.createElement('label');
        item.className = 'perm-item' + (isChecked ? ' checked' : '');
        item.innerHTML = `<input type="checkbox" name="perm_${p.key}" value="${p.key}"${isChecked?' checked':''}><span>${p.label}</span>`;
        item.querySelector('input').addEventListener('change', function(){
          item.classList.toggle('checked', this.checked);
        });
        grid.appendChild(item);
      });
    }

    function getCheckedPerms() {
      return Array.from(document.querySelectorAll('#permsGrid input[type=checkbox]:checked')).map(cb => cb.value);
    }

    function togglePermsSection(perfil, userId) {
      const sec = document.getElementById('permsSection');
      const isRoot = String(userId) === '1';
      // show permissions panel only for non-admin, non-root users
      sec.style.display = (perfil !== 'admin' && !isRoot) ? 'block' : 'none';
    }

    // ── Table row actions ─────────────────────────────────────────────────────
    document.getElementById('usersTbody').addEventListener('click', async function(e){
      const btnEdit = e.target.closest('button.edit-user');
      if (btnEdit){
        const id = btnEdit.dataset.id;
        const res= await fetch(apiUsers+'?id='+id);
        const j  = await res.json();
        const u  = (j.data && j.data[0]) || j.data || null;
        if (!u) return;
        const form    = document.getElementById('editUserForm');
        const isRoot  = String(u.id) === '1';
        form.querySelector('[name=id]').value    = u.id;
        form.querySelector('[name=nome]').value  = u.nome  || '';
        form.querySelector('[name=email]').value = u.email || '';

        // pre-fill password if returned (admin or self)
        // campo leitura — senha atual
        const senhaAtualEl = document.getElementById('edit_senha_atual');
        if (senhaAtualEl) {
          senhaAtualEl.value = u.senha_plain || '';
          senhaAtualEl.placeholder = u.senha_plain ? '' : 'Não registrada — salve uma nova senha';
          senhaAtualEl.style.borderColor = u.senha_plain ? 'rgba(16,185,129,.3)' : 'rgba(245,158,11,.3)';
        }
        // campo nova senha — sempre limpo
        const senhaInput = form.querySelector('[name=senha]');
        senhaInput.value = '';
        senhaInput.placeholder = 'Digite para alterar a senha';

        // perfil: lock only for root user; name/email/senha always editable
        const perfilSel = document.getElementById('editPerfil');
        perfilSel.value    = u.perfil || 'user';
        perfilSel.disabled = isRoot;

        // delete button: hide for root
        const delBtn = document.getElementById('deleteUser');
        delBtn.style.display = isRoot ? 'none' : '';

        // permissions
        renderPermsGrid(u.permissions || []);
        togglePermsSection(u.perfil, u.id);

        openModal('modalEditUser');
        return;
      }
      const btnDel = e.target.closest('button.delete-user');
      if (btnDel){
        const id = btnDel.dataset.id;
        if (!(await Yuris.confirm('Excluir este usuário permanentemente?', { danger: true, okLabel: 'Excluir' }))) return;
        const res= await fetch(apiUsers, {method:'DELETE', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body:JSON.stringify({id,csrf_token:csrf})});
        const j  = await res.json();
        if (j && j.success){ loadUsers(); showToast('Usuário excluído'); }
        else showToast('Erro ao excluir','error');
      }
    });

    // ── Delete from edit modal ────────────────────────────────────────────────
    document.getElementById('deleteUser').addEventListener('click', async function(){
      const id = document.getElementById('editUserForm').id.value;
      if (!(await Yuris.confirm('Excluir este usuário?', { danger: true, okLabel: 'Excluir' }))) return;
      const res= await fetch(apiUsers, {method:'DELETE', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body:JSON.stringify({id,csrf_token:csrf})});
      const j  = await res.json();
      if (j && j.success){ closeModal('modalEditUser'); loadUsers(); showToast('Usuário excluído'); }
      else showToast('Erro ao excluir','error');
    });

    // ── Password toggle ───────────────────────────────────────────────────────
    (function(){
      const eye    = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
      const eyeOff = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a21.67 21.67 0 0 1 5.06-6.94"/><path d="M1 1l22 22"/><path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"/></svg>';
      document.addEventListener('click', function(e){
        const btn = e.target.closest('button.password-toggle');
        if (!btn) return;
        const inp = document.getElementById(btn.dataset.target);
        if (!inp) return;
        if (inp.type === 'password'){ inp.type='text';     btn.innerHTML=eyeOff; }
        else                        { inp.type='password'; btn.innerHTML=eye;    }
      });
    })();

    // ── Toast ─────────────────────────────────────────────────────────────────
    function showToast(message, type='success', ms=3000){
      const wrap = document.getElementById('toastWrap');
      if (!wrap) return;
      const t = document.createElement('div');
      t.className = 'toast ' + (type==='error'?'error':'success');
      t.textContent = message;
      wrap.appendChild(t);
      requestAnimationFrame(()=> setTimeout(()=> t.classList.add('show'), 10));
      const hide = ()=>{ t.classList.remove('show'); setTimeout(()=>t.remove(),300); };
      const to = setTimeout(hide, ms);
      t.addEventListener('click', ()=>{ clearTimeout(to); hide(); });
    }

    // ── Dashboard status mirror ───────────────────────────────────────────────
    try{
      const saved = localStorage.getItem('dashboard_last_update');
      if (saved){ const el=document.getElementById('dashboardStatus'); if(el){ el.textContent=saved; el.style.color='#4ade80'; } }
      window.addEventListener('storage', function(e){ if(!e||e.key!=='dashboard_last_update') return; const el=document.getElementById('dashboardStatus'); if(el){ el.textContent=e.newValue; el.style.color='#4ade80'; } });
    }catch(e){}

    // ── Teams ──────────────────────────────────────────────────────────────────
    const apiTeams   = '/sistema_vendas/public/api/teams.php';
    const TEAM_COLORS = ['#3B82F6','#10B981','#8B5CF6','#F59E0B','#EF4444','#14B8A6','#F97316','#EC4899'];

    function switchTab(tab) {
      const isUsers = tab === 'usuarios';
      document.getElementById('sectionUsuarios').style.display = isUsers ? '' : 'none';
      document.getElementById('sectionTimes').style.display    = isUsers ? 'none' : '';
      document.getElementById('tabBtnUsuarios').classList.toggle('active',  isUsers);
      document.getElementById('tabBtnTimes').classList.toggle('active',    !isUsers);
      if (!isUsers) loadTeams();
    }

    async function loadTeams() {
      document.getElementById('teamsGrid').innerHTML =
        '<div style="text-align:center;padding:40px;color:var(--muted);font-size:.85rem;grid-column:1/-1">Carregando…</div>';
      try {
        const res = await fetch(apiTeams);
        const j   = await res.json();
        renderTeams(j.data || []);
      } catch(e) {
        document.getElementById('teamsGrid').innerHTML =
          '<div style="text-align:center;padding:40px;color:#f87171;font-size:.85rem;grid-column:1/-1">Erro ao carregar setores</div>';
      }
    }

    function renderTeams(teams) {
      const grid = document.getElementById('teamsGrid');
      if (!teams.length) {
        grid.innerHTML = `
          <div style="text-align:center;padding:48px 24px;color:var(--muted);font-size:.85rem;grid-column:1/-1">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="opacity:.3;margin:0 auto 12px;display:block"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <p>Nenhum setor cadastrado ainda.</p>
            <p style="margin-top:4px">Clique em <strong style="color:#93c5fd">+ Novo Setor</strong> para começar.</p>
          </div>`;
        return;
      }
      grid.innerHTML = teams.map(t => {
        const ids     = Array.isArray(t.membro_ids) ? t.membro_ids : [];
        const count   = ids.length;
        const visible = ids.slice(0, 4);
        const avatars = visible.map(uid => {
          const u   = _allUsers.find(x => String(x.id) === String(uid));
          const ini = u ? initials(u.nome) : '?';
          const tip = u ? escapeHtml(u.nome) : '';
          return `<div class="team-avatar-sm" title="${tip}">${ini}</div>`;
        }).join('');
        const extra  = count > 4 ? `<div class="team-avatar-sm" title="${count-4} mais">+${count-4}</div>` : '';
        const cor    = escapeHtml(t.cor || '#3B82F6');
        const label  = count === 0 ? 'Sem membros' : count === 1 ? '1 membro' : `${count} membros`;
        return `
        <div class="team-card" style="--team-cor:${cor}">
          <div class="team-card-top">
            <div style="min-width:0">
              <div class="team-name">${escapeHtml(t.nome)}</div>
              ${t.descricao ? `<div class="team-desc">${escapeHtml(t.descricao)}</div>` : ''}
            </div>
            <div class="team-actions">
              <button class="tbl-btn btn-edit" onclick="openEditTeamModal(${t.id})">Editar</button>
              <button class="tbl-btn btn-del"  onclick="confirmDeleteTeam(${t.id},'${escapeHtml(t.nome).replace(/'/g,"\\'")}')">Excluir</button>
            </div>
          </div>
          <div class="team-members">
            <div class="team-avatar-stack">${avatars}${extra}</div>
            <span class="team-member-count">${label}</span>
          </div>
        </div>`;
      }).join('');
    }

    function setTeamColor(cor) {
      const v = cor || '#3B82F6';
      const inp = document.getElementById('teamCor');
      const lbl = document.getElementById('teamCorLabel');
      if (inp) inp.value = v;
      if (lbl) lbl.textContent = v;
    }

    let _teamSelectedIds = [];

    function renderTeamMembersGrid(selectedIds, query) {
      _teamSelectedIds = (selectedIds || []).map(String);
      const grid = document.getElementById('teamMembersGrid');
      if (!_allUsers.length) {
        grid.innerHTML = '<div class="tm-empty">Nenhum usuário disponível</div>';
        return;
      }
      const q = (query || '').toLowerCase().trim();
      const filtered = q
        ? _allUsers.filter(u => (u.nome||'').toLowerCase().includes(q) || (u.email||'').toLowerCase().includes(q))
        : _allUsers;

      if (!filtered.length) {
        grid.innerHTML = '<div class="tm-empty">Nenhum membro encontrado</div>';
        return;
      }
      grid.innerHTML = filtered.map(u => {
        const checked = _teamSelectedIds.includes(String(u.id));
        const ini     = initials(u.nome);
        return `<label class="tm-member">
          <input type="checkbox" value="${u.id}" name="team_member"${checked ? ' checked' : ''}>
          <div class="tm-avatar">${ini}</div>
          <div class="tm-info">
            <div class="tm-name">${escapeHtml(u.nome)}</div>
            <div class="tm-email">${escapeHtml(u.email||'')}</div>
          </div>
          <div class="tm-chk">
            <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="1.5 5 4 7.5 8.5 2"/>
            </svg>
          </div>
        </label>`;
      }).join('');
    }

    function filterTeamMembers(q) {
      const checked = [...document.querySelectorAll('input[name=team_member]:checked')].map(i => i.value);
      renderTeamMembersGrid(checked, q);
    }

    function openCreateTeamModal() {
      document.getElementById('teamId').value    = '';
      document.getElementById('teamNome').value  = '';
      document.getElementById('teamDesc').value  = '';
      document.getElementById('teamCor').value   = '#3B82F6';
      document.getElementById('modalTeamTitle').textContent = 'Novo Setor';
      document.getElementById('deleteTeamBtn').style.display = 'none';
      setTeamColor('#3B82F6');
      document.getElementById('teamMemberSearch').value = '';
      renderTeamMembersGrid([]);
      openModal('modalTeam');
    }

    async function openEditTeamModal(id) {
      const res = await fetch(apiTeams + '?id=' + id);
      const j   = await res.json();
      const t   = j.data;
      if (!t) return;
      document.getElementById('teamId').value    = t.id;
      document.getElementById('teamNome').value  = t.nome;
      document.getElementById('teamDesc').value  = t.descricao || '';
      document.getElementById('teamCor').value   = t.cor || '#3B82F6';
      document.getElementById('modalTeamTitle').textContent = 'Editar Setor';
      document.getElementById('deleteTeamBtn').style.display = '';
      setTeamColor(t.cor || '#3B82F6');
      document.getElementById('teamMemberSearch').value = '';
      renderTeamMembersGrid(Array.isArray(t.membro_ids) ? t.membro_ids : []);
      openModal('modalTeam');
    }

    async function saveTeam() {
      const id      = document.getElementById('teamId').value;
      const nome    = document.getElementById('teamNome').value.trim();
      const cor     = document.getElementById('teamCor').value;
      const desc    = document.getElementById('teamDesc').value.trim();
      const members = [...document.querySelectorAll('input[name=team_member]:checked')].map(i => parseInt(i.value));

      if (!nome) { showToast('Nome do setor é obrigatório', 'error'); return; }

      const payload = { nome, cor, descricao: desc, members, csrf_token: csrf };
      if (id) payload.id = parseInt(id);

      const res = await fetch(apiTeams, {
        method: id ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(payload),
      });
      const j = await res.json();
      if (j && j.success) {
        closeModal('modalTeam');
        loadTeams();
        showToast(id ? 'Setor atualizado' : 'Setor criado com sucesso');
      } else {
        showToast('Erro: ' + (j && j.error ? j.error : 'falha ao salvar'), 'error');
      }
    }

    async function confirmDeleteTeam(id, nome) {
      if (!(await Yuris.confirm(`Excluir o setor "${nome}"?\n\nOs usuários não serão removidos do sistema.`, { danger: true, okLabel: 'Excluir setor' }))) return;
      const res = await fetch(apiTeams + '?id=' + id, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ id, csrf_token: csrf }),
      });
      const j = await res.json();
      if (j && j.success) { loadTeams(); showToast('Setor excluído'); }
      else showToast('Erro ao excluir setor', 'error');
    }

    function deleteTeamFromModal() {
      const id   = document.getElementById('teamId').value;
      const nome = document.getElementById('teamNome').value;
      if (!id) return;
      closeModal('modalTeam');
      confirmDeleteTeam(parseInt(id), nome);
    }
    // ── End Teams ───────────────────────────────────────────────────────────────

    loadUsers();
  </script>
  <script src="/sistema_vendas/public/assets/fog.js"></script>
</body>
</html>

