<?php
require_once __DIR__ . '/../app/Core/Database.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$activePage = 'agente';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Agente IA — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <style>
    :root {
      --bg-main: #071427;
      --line: rgba(148,163,184,.18);
      --line-strong: rgba(96,165,250,.35);
      --text: #e8f4ff;
      --muted: #9ab0c9;
      --primary: #3b82f6;
      --ok: #10b981;
      --warn: #f59e0b;
      --danger: #ef4444;
      --radius: 14px;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      background: radial-gradient(circle at top right, #0f2a49 0%, var(--bg-main) 46%, #050f1f 100%);
      color: var(--text);
      font-family: 'Poppins', system-ui, -apple-system, sans-serif;
      min-height: 100vh;
    }

    /* ── Sidebar ── */

    /* ── Panel ── */
    .agt-panel {
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
    .kpi-card:hover { transform: translateY(-3px); border-color: rgba(96,165,250,.4); box-shadow: 0 10px 26px rgba(37,99,235,.22); }
    .kpi-card.kpi-ok     { border-color: rgba(16,185,129,.35); }
    .kpi-card.kpi-warn   { border-color: rgba(245,158,11,.35); }
    .kpi-card.kpi-danger { border-color: rgba(239,68,68,.35); }
    .kpi-label { color: #a8c2df; font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
    .kpi-value { margin-top: 8px; color: #f0f8ff; font-size: 1.15rem; font-weight: 700; line-height: 1.2; }
    .kpi-foot  { margin-top: 5px; color: var(--muted); font-size: .7rem; }
    .kpi-dot   { position: absolute; top: 13px; right: 13px; width: 9px; height: 9px; border-radius: 50%; }
    .dot-ok      { background: var(--ok);     box-shadow: 0 0 7px rgba(16,185,129,.7); }
    .dot-warn    { background: var(--warn);   box-shadow: 0 0 7px rgba(245,158,11,.7); }
    .dot-danger  { background: var(--danger); box-shadow: 0 0 7px rgba(239,68,68,.7);  }
    .dot-neutral { background: rgba(148,163,184,.45); }

    /* ── Section title ── */
    .agt-section-title  { font-size: .78rem; font-weight: 700; color: #93c5fd; letter-spacing: .04em; text-transform: uppercase; padding: 8px 14px; background: rgba(37,99,235,.14); border-bottom: 1px solid rgba(96,165,250,.1); }
    .agt-section-body   { padding: 16px; display: flex; flex-direction: column; gap: 14px; }

    /* ── Form section card ── */
    .agt-section { border: 1px solid rgba(96,165,250,.12); border-radius: 12px; overflow: hidden; }

    /* ── Field ── */
    .field-group { display: flex; flex-direction: column; gap: 5px; }
    .field-label { font-size: .8rem; font-weight: 600; color: #b8d5f4; }
    .field-hint  { font-size: .72rem; color: var(--muted); line-height: 1.3; }
    .field-input {
      width: 100%; padding: 9px 11px;
      border-radius: 9px; border: 1px solid rgba(96,165,250,.22) !important;
      background: rgba(8,20,44,.85) !important; color: #d0e8ff !important;
      -webkit-text-fill-color: #d0e8ff !important;
      font-family: inherit; font-size: .84rem;
      transition: border-color .18s, box-shadow .18s;
    }
    .field-input::placeholder { color: rgba(153,175,201,.55); }
    .field-input:focus { outline: none; border-color: #3b82f6 !important; box-shadow: 0 0 0 3px rgba(59,130,246,.2); }
    .field-input:-webkit-autofill,
    .field-input:-webkit-autofill:hover,
    .field-input:-webkit-autofill:focus,
    .field-input:-webkit-autofill:active {
      -webkit-box-shadow: 0 0 0 200px rgba(8,20,44,.95) inset !important;
      -webkit-text-fill-color: #d0e8ff !important;
      caret-color: #d0e8ff !important;
    }
    .field-input.dark {
      background: rgba(8,20,44,.85); color: #d0e8ff;
      border-color: rgba(96,165,250,.25);
    }
    .field-input.dark::placeholder { color: rgba(153,175,201,.55); }
    .field-input.dark:focus { border-color: var(--line-strong); }
    .field-wrap-rel { position: relative; }
    .field-wrap-rel .field-input { padding-right: 72px; }
    .field-icon-btn {
      position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
      display: flex; align-items: center; gap: 4px;
      background: transparent; border: none; cursor: pointer;
      color: rgba(11,27,43,.6); padding: 4px; border-radius: 5px;
    }
    .field-icon-btn:hover { background: rgba(0,0,0,.07); }
    .field-icon-btn svg { width: 16px; height: 16px; display: block; }
    .field-icon-btn.dark-btn { color: rgba(153,175,201,.7); }
    .field-icon-btn.dark-btn:hover { background: rgba(255,255,255,.08); }

    /* ── Toggle switch (replaces checkbox) ── */
    .toggle-row { display: flex; align-items: center; gap: 14px; }
    .toggle-wrap { position: relative; width: 48px; height: 26px; flex-shrink: 0; }
    .toggle-wrap input { opacity: 0; width: 0; height: 0; position: absolute; }
    .toggle-slider {
      position: absolute; inset: 0; background: rgba(148,163,184,.3);
      border-radius: 999px; cursor: pointer; transition: background .22s;
    }
    .toggle-slider::before {
      content: ''; position: absolute; height: 20px; width: 20px;
      left: 3px; top: 3px; background: #fff; border-radius: 50%;
      transition: transform .22s; box-shadow: 0 1px 4px rgba(0,0,0,.3);
    }
    .toggle-wrap input:checked + .toggle-slider { background: var(--ok); }
    .toggle-wrap input:checked + .toggle-slider::before { transform: translateX(22px); }
    .toggle-label { font-size: .88rem; font-weight: 600; color: #d6e8fa; }
    .toggle-sublabel { font-size: .74rem; color: var(--muted); }

    /* ── Credential warning ── */
    .credential-warn {
      display: flex; align-items: flex-start; gap: 10px;
      padding: 10px 13px; border-radius: 9px;
      background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.3);
      font-size: .78rem; color: #fcd34d; line-height: 1.4;
    }
    .credential-warn svg { flex-shrink: 0; width: 16px; height: 16px; margin-top: 1px; }

    /* ── Char counter ── */
    .char-counter { text-align: right; font-size: .72rem; color: var(--muted); margin-top: 3px; }
    .char-counter.near { color: var(--warn); }
    .char-counter.over  { color: var(--danger); font-weight: 700; }

    /* ── Save button ── */
    .agt-btn-primary {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 0 24px; height: 42px;
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      color: #fff; border: none; border-radius: 10px;
      font-family: inherit; font-size: .9rem; font-weight: 600; cursor: pointer;
      box-shadow: 0 4px 16px rgba(37,99,235,.4);
      transition: transform .18s, box-shadow .18s, opacity .18s;
    }
    .agt-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(37,99,235,.55); }
    .agt-btn-primary:disabled { opacity: .6; cursor: not-allowed; transform: none; }
    .agt-btn-secondary {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 0 16px; height: 38px;
      border-radius: 9px; border: 1px solid rgba(96,165,250,.3);
      background: transparent; color: #93c5fd;
      font-family: inherit; font-size: .82rem; font-weight: 600; cursor: pointer;
      transition: background .18s;
    }
    .agt-btn-secondary:hover { background: rgba(37,99,235,.15); }
    .agt-btn-secondary:disabled { opacity: .5; cursor: not-allowed; }

    /* ── Test area ── */
    .test-bubble {
      padding: 12px 14px; border-radius: 12px;
      background: rgba(8,22,44,.8); border: 1px solid rgba(96,165,250,.15);
      font-size: .84rem; color: #d2e8ff; line-height: 1.55;
      white-space: pre-wrap; word-break: break-word;
      min-height: 60px;
    }
    .test-label { font-size: .74rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }

    /* ── Mensagem de resultado de "Testar conexão" ── */
    /* Pill-style: fundo opaco + borda + ícone via ::before. Funciona em ambos os temas. */
    .test-conn-msg {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 8px 12px; border-radius: 8px;
      font-size: .8rem; font-weight: 600; line-height: 1.3;
    }
    .test-conn-msg::before {
      content: ''; flex-shrink: 0; width: 14px; height: 14px;
      background-repeat: no-repeat; background-position: center; background-size: contain;
    }
    .test-conn-warn {
      background: rgba(245,158,11,.18);
      color: #fcd34d;
      border: 1px solid rgba(245,158,11,.45);
    }
    .test-conn-warn::before {
      background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23fcd34d' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'><path d='M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z'/><line x1='12' y1='9' x2='12' y2='13'/><line x1='12' y1='17' x2='12.01' y2='17'/></svg>");
    }
    .test-conn-ok {
      background: rgba(16,185,129,.18);
      color: #6ee7b7;
      border: 1px solid rgba(16,185,129,.45);
    }
    .test-conn-ok::before {
      background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236ee7b7' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'><polyline points='20 6 9 17 4 12'/></svg>");
    }

    /* ── Tips panel ── */
    .tip-row { display: flex; align-items: flex-start; gap: 10px; padding: 9px 0; border-bottom: 1px solid rgba(96,165,250,.07); }
    .tip-row:last-child { border-bottom: none; }
    .tip-icon { flex-shrink: 0; width: 26px; height: 26px; border-radius: 7px; background: rgba(37,99,235,.2); display: flex; align-items: center; justify-content: center; font-size: .85rem; }
    .tip-text { font-size: .78rem; color: #b8d5f4; line-height: 1.45; }

    /* ── Toast ── */
    .toast-wrap { position:fixed; top:1rem; right:1rem; z-index:1100; display:flex; flex-direction:column; gap:6px; }
    .toast { padding:.65rem 1rem; border-radius:9px; color:#fff; box-shadow:0 6px 18px rgba(2,6,23,.6); font-size:.84rem; font-weight:600; opacity:0; transform:translateY(-6px); transition:all .25s ease; max-width:360px; }
    .toast.show { opacity:1; transform:translateY(0); }
    .toast.success { background:linear-gradient(90deg,#059669,#10b981); }
    .toast.error   { background:linear-gradient(90deg,#dc2626,#ef4444); }
    .toast.info    { background:linear-gradient(90deg,#2563eb,#3b82f6); }

    @media (max-width: 768px)  { .two-col { grid-template-columns: 1fr !important; } }
    @media (max-width: 640px)  { .kpi-grid { grid-template-columns: repeat(2,1fr) !important; } }

    /* ═══════════════════════════════════════════════════════════════════════
       LIGHT THEME OVERRIDES — Página tinha ZERO overrides; tudo tava com texto
       azul-claro sobre fundo branco (ilegível). Cobrindo: KPI cards, painéis,
       seções, inputs, labels, hints, toggle, credential warn, tip rows,
       diagnóstico, botões secundários, teste rápido.
       ═══════════════════════════════════════════════════════════════════════ */
    html[data-theme="light"] body {
      background: linear-gradient(135deg, #eef2f9 0%, #e2e9f4 50%, #d8e1ee 100%) !important;
      color: #0b1a33 !important;
    }

    /* Panel container */
    html[data-theme="light"] .agt-panel {
      background: #ffffff !important;
      border: 1px solid #d6deeb !important;
      box-shadow: 0 4px 16px rgba(15,32,60,.06) !important;
      color: #0b1a33 !important;
    }

    /* KPI cards */
    html[data-theme="light"] .kpi-card {
      background: #ffffff !important;
      border: 1px solid #d6deeb !important;
      box-shadow: 0 2px 8px rgba(15,32,60,.05) !important;
    }
    html[data-theme="light"] .kpi-card:hover {
      border-color: #93c5fd !important;
      box-shadow: 0 6px 20px rgba(37,99,235,.14) !important;
    }
    html[data-theme="light"] .kpi-card.kpi-ok     { border-color: rgba(16,185,129,.45) !important; }
    html[data-theme="light"] .kpi-card.kpi-warn   { border-color: rgba(245,158,11,.55) !important; }
    html[data-theme="light"] .kpi-card.kpi-danger { border-color: rgba(239,68,68,.55) !important; }
    html[data-theme="light"] .kpi-label { color: #4b5d76 !important; }
    html[data-theme="light"] .kpi-value { color: #0b1a33 !important; }
    html[data-theme="light"] .kpi-foot  { color: #5b6b85 !important; }

    /* Section card */
    html[data-theme="light"] .agt-section {
      border: 1px solid #d6deeb !important;
      background: #ffffff !important;
    }
    html[data-theme="light"] .agt-section-title {
      color: #1d4ed8 !important;
      background: #eaf1fb !important;
      border-bottom: 1px solid #d0deef !important;
    }
    html[data-theme="light"] .agt-section-body {
      background: #ffffff !important;
    }

    /* Form fields */
    html[data-theme="light"] .field-label { color: #1f3358 !important; font-weight: 600 !important; }
    html[data-theme="light"] .field-hint  { color: #5b6b85 !important; }
    html[data-theme="light"] .field-input {
      background: #ffffff !important;
      border: 1px solid #cbd5e3 !important;
      color: #0b1a33 !important;
      -webkit-text-fill-color: #0b1a33 !important;
    }
    html[data-theme="light"] .field-input::placeholder { color: #94a3b8 !important; -webkit-text-fill-color: #94a3b8 !important; }
    html[data-theme="light"] .field-input:focus {
      border-color: #2563eb !important;
      box-shadow: 0 0 0 3px rgba(37,99,235,.15) !important;
    }
    html[data-theme="light"] .field-input:-webkit-autofill,
    html[data-theme="light"] .field-input:-webkit-autofill:hover,
    html[data-theme="light"] .field-input:-webkit-autofill:focus,
    html[data-theme="light"] .field-input:-webkit-autofill:active {
      -webkit-box-shadow: 0 0 0 200px #ffffff inset !important;
      -webkit-text-fill-color: #0b1a33 !important;
      caret-color: #0b1a33 !important;
    }
    html[data-theme="light"] textarea.field-input { background: #ffffff !important; }
    html[data-theme="light"] .field-icon-btn { color: #5b6b85 !important; }
    html[data-theme="light"] .field-icon-btn:hover { background: rgba(15,32,60,.06) !important; }

    /* Toggle */
    html[data-theme="light"] .toggle-slider { background: #cbd5e3 !important; }
    html[data-theme="light"] .toggle-wrap input:checked + .toggle-slider { background: #10b981 !important; }
    html[data-theme="light"] .toggle-label    { color: #0b1a33 !important; }
    html[data-theme="light"] .toggle-sublabel { color: #5b6b85 !important; }

    /* Credential warn */
    html[data-theme="light"] .credential-warn {
      background: #fff7e1 !important;
      border: 1px solid #f5c46a !important;
      color: #8a5a07 !important;
    }
    html[data-theme="light"] .credential-warn svg { stroke: #c08407 !important; }

    /* Char counter */
    html[data-theme="light"] .char-counter { color: #5b6b85 !important; }
    html[data-theme="light"] .char-counter.near { color: #c08407 !important; }
    html[data-theme="light"] .char-counter.over { color: #b91c1c !important; }

    /* Buttons */
    html[data-theme="light"] .agt-btn-secondary {
      background: #f4f7fc !important;
      border: 1px solid #c7d3e6 !important;
      color: #1d4ed8 !important;
    }
    html[data-theme="light"] .agt-btn-secondary:hover { background: #e7eefb !important; border-color: #93c5fd !important; }
    html[data-theme="light"] .agt-btn-primary {
      background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
      color: #fff !important;
      box-shadow: 0 4px 14px rgba(37,99,235,.30) !important;
    }
    html[data-theme="light"] .agt-btn-primary:hover {
      box-shadow: 0 6px 18px rgba(37,99,235,.40) !important;
    }

    /* Test bubble */
    html[data-theme="light"] .test-bubble {
      background: #f4f7fc !important;
      border: 1px solid #d6deeb !important;
      color: #0b1a33 !important;
    }
    html[data-theme="light"] .test-label { color: #5b6b85 !important; }

    /* Tip rows */
    html[data-theme="light"] .tip-row { border-bottom: 1px solid #e5ebf5 !important; }
    html[data-theme="light"] .tip-icon {
      background: #e7eefb !important;
      color: #1d4ed8 !important;
    }
    html[data-theme="light"] .tip-icon svg { stroke: #1d4ed8 !important; }
    html[data-theme="light"] .tip-text { color: #1f3358 !important; }

    /* Side panel headers (h3) */
    html[data-theme="light"] .agt-panel h3 { color: #0b1a33 !important; }
    html[data-theme="light"] .agt-panel h3 svg { stroke: #1d4ed8 !important; }
    html[data-theme="light"] .agt-panel p { color: #5b6b85 !important; }

    /* Diagnóstico rápido */
    html[data-theme="light"] .diag-row { border-bottom: 1px solid #e5ebf5 !important; }
    html[data-theme="light"] .diag-row span:first-child { color: #4b5d76 !important; }
    html[data-theme="light"] .diag-ok      { background:#dcfce7 !important; color:#15803d !important; border:1px solid #86efac !important; }
    html[data-theme="light"] .diag-warn    { background:#fef3c7 !important; color:#92400e !important; border:1px solid #fcd34d !important; }
    html[data-theme="light"] .diag-neutral { background:#f1f5fb !important; color:#4b5d76 !important; border:1px solid #cbd5e3 !important; }

    /* KPI dot when neutral */
    html[data-theme="light"] .dot-neutral { background:#cbd5e3 !important; }

    /* Section title SVG icons */
    html[data-theme="light"] .agt-section-title svg { stroke: #1d4ed8 !important; }

    /* Save / Test connection button icons */
    html[data-theme="light"] .agt-btn-secondary svg { stroke: currentColor !important; }

    /* Mensagem "Testar conexão" no tema claro */
    /* Cores amber-700 / emerald-700 — alto contraste em fundo claro */
    html[data-theme="light"] .test-conn-warn {
      background: #fff3d4 !important;
      color: #92400e !important;
      border: 1px solid #f5c46a !important;
    }
    html[data-theme="light"] .test-conn-warn::before {
      background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2392400e' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'><path d='M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z'/><line x1='12' y1='9' x2='12' y2='13'/><line x1='12' y1='17' x2='12.01' y2='17'/></svg>") !important;
    }
    html[data-theme="light"] .test-conn-ok {
      background: #dcfce7 !important;
      color: #166534 !important;
      border: 1px solid #86efac !important;
    }
    html[data-theme="light"] .test-conn-ok::before {
      background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23166534' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'><polyline points='20 6 9 17 4 12'/></svg>") !important;
    }

    /* ── Canal WhatsApp (read-only; gerido em Comunicação) ── */
    .channel-box { border:1px solid rgba(96,165,250,.22); border-radius:10px; background:rgba(8,20,44,.6); padding:12px 14px; }
    .channel-box.channel-loading { color:var(--muted); font-size:.82rem; }
    .channel-select { width:100%; margin-bottom:10px; }
    .ch-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .ch-name { font-weight:700; color:#e8f4ff; font-size:.92rem; }
    .ch-meta { display:flex; gap:14px; flex-wrap:wrap; margin-top:8px; font-size:.78rem; color:var(--muted); }
    .ch-meta b { color:#cfe4ff; font-weight:600; }
    .ch-badge { font-size:.7rem; font-weight:700; padding:2px 9px; border-radius:999px; white-space:nowrap; }
    .ch-badge.ok   { background:rgba(16,185,129,.2);  color:#6ee7b7; border:1px solid rgba(16,185,129,.4); }
    .ch-badge.off  { background:rgba(239,68,68,.16);  color:#fca5a5; border:1px solid rgba(239,68,68,.4); }
    .ch-badge.wait { background:rgba(245,158,11,.16); color:#fcd34d; border:1px solid rgba(245,158,11,.4); }
    .ch-warn { margin-top:10px; padding:9px 12px; border-radius:8px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#fca5a5; font-size:.78rem; line-height:1.45; }
    .ch-empty { text-align:center; padding:8px 6px; }
    .ch-empty-title { font-weight:700; color:#fca5a5; font-size:.9rem; margin-bottom:6px; }
    .ch-empty-sub { color:var(--muted); font-size:.78rem; margin-bottom:12px; line-height:1.45; }
    .ch-link { color:#93c5fd; text-decoration:underline; font-weight:600; cursor:pointer; }
    html[data-theme="light"] .channel-box { background:#f4f7fc !important; border:1px solid #d6deeb !important; }
    html[data-theme="light"] .ch-name { color:#0b1a33 !important; }
    html[data-theme="light"] .ch-meta { color:#5b6b85 !important; }
    html[data-theme="light"] .ch-meta b { color:#1f3358 !important; }
    html[data-theme="light"] .ch-link { color:#1d4ed8 !important; }
    html[data-theme="light"] .ch-warn { background:#fee2e2 !important; border-color:#fca5a5 !important; color:#b91c1c !important; }
    html[data-theme="light"] .ch-empty-title { color:#b91c1c !important; }
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
        <div class="agt-panel page-header">
          <div class="page-header-inner">
            <div class="page-header-text">
              <h2 class="page-header-title">Agente de IA — WhatsApp</h2>
              <p class="page-header-subtitle">Configure o comportamento e as credenciais do agente que atende via WhatsApp</p>
            </div>
          </div>
        </div>

        <!-- KPI cards -->
        <div class="kpi-grid" style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px">
          <div class="kpi-card" id="cardStatus">
            <div class="kpi-dot dot-neutral" id="dotStatus"></div>
            <div class="kpi-label">Status do agente</div>
            <div class="kpi-value" id="kpiStatus">—</div>
            <div class="kpi-foot">configuração atual</div>
          </div>
          <div class="kpi-card" id="cardWhats">
            <div class="kpi-dot dot-neutral" id="dotWhats"></div>
            <div class="kpi-label">WhatsApp</div>
            <div class="kpi-value" id="kpiWhats">—</div>
            <div class="kpi-foot" id="kpiWhatsFoot">número configurado</div>
          </div>
          <div class="kpi-card" id="cardProvider">
            <div class="kpi-dot dot-neutral" id="dotProvider"></div>
            <div class="kpi-label">Provedor</div>
            <div class="kpi-value" id="kpiProvider">—</div>
            <div class="kpi-foot">API de IA ativa</div>
          </div>
          <div class="kpi-card" id="cardPrompt">
            <div class="kpi-dot dot-neutral" id="dotPrompt"></div>
            <div class="kpi-label">Prompt</div>
            <div class="kpi-value" id="kpiPrompt">—</div>
            <div class="kpi-foot" id="kpiPromptFoot">instrução do agente</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-dot dot-neutral"></div>
            <div class="kpi-label">Última atualização</div>
            <div class="kpi-value" id="kpiLastUpdate" style="font-size:.92rem;margin-top:8px">—</div>
            <div class="kpi-foot">configuração salva</div>
          </div>
        </div>

        <!-- Form + Tips layout -->
        <div class="two-col" style="display:grid;grid-template-columns:1fr 320px;gap:14px;align-items:start">

          <!-- Left: form -->
          <div class="space-y-4">
            <form id="agentForm">
              <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">

              <!-- Bloco 1 — Identidade -->
              <div class="agt-section">
                <div class="agt-section-title"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:6px"><circle cx="12" cy="8" r="4"/><path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/></svg> Identidade do agente</div>
                <div class="agt-section-body">
                  <div class="field-group">
                    <label class="field-label">Nome do agente</label>
                    <input name="name" class="field-input" placeholder="Ex: Assistente Jurídico — Yuris" autocomplete="off">
                    <span class="field-hint">Nome que aparece nas conversas do WhatsApp</span>
                  </div>
                  <input type="hidden" name="whatsapp_instance_id" id="whatsappInstanceId" value="">
                  <div class="field-group">
                    <label class="field-label">Canal WhatsApp</label>
                    <div id="channelBox" class="channel-box channel-loading">Carregando canais…</div>
                    <span class="field-hint">O canal é conectado e gerido em Comunicação, Chat WhatsApp. Aqui você só escolhe em qual canal o agente atende, não dá para digitar número nem criar conexão.</span>
                  </div>
                  <div>
                    <div class="toggle-row">
                      <label class="toggle-wrap">
                        <input type="checkbox" name="enabled" id="toggleEnabled">
                        <span class="toggle-slider"></span>
                      </label>
                      <div>
                        <div class="toggle-label" id="toggleLabel">Agente inativo</div>
                        <div class="toggle-sublabel">Ative para iniciar o atendimento automático</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Bloco 2 — Integração (OCULTO: provedor e chave da IA são GLOBAIS, definidos no Painel Master) -->
              <div class="agt-section" style="margin-top:14px; display:none">
                <div class="agt-section-title"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:6px"><path d="M9 2v6"/><path d="M15 2v6"/><path d="M6 8h12v4a6 6 0 1 1-12 0V8z"/><path d="M12 18v4"/></svg> Integração com API</div>
                <div class="agt-section-body">
                  <div class="field-group">
                    <label class="field-label">Provedor / API</label>
                    <input name="provider" class="field-input" placeholder="openai, anthropic, gemini…" autocomplete="off">
                    <span class="field-hint">Nome do provedor de IA que será utilizado</span>
                  </div>
                  <div class="field-group">
                    <label class="field-label">Chave de API</label>
                    <div class="credential-warn" style="margin-bottom:6px">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                      <span>Credencial sensível — não compartilhe nem publique esta chave</span>
                    </div>
                    <div class="field-wrap-rel">
                      <input id="apiKeyInput" name="api_key" type="password" class="field-input" placeholder="sk-…" autocomplete="new-password">
                      <div style="position:absolute;right:6px;top:50%;transform:translateY(-50%);display:flex;gap:3px">
                        <button type="button" id="toggleApiKey" class="field-icon-btn" title="Mostrar/ocultar">
                          <svg id="apiKeyEyeIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                        <button type="button" id="copyApiKey" class="field-icon-btn" title="Copiar chave">
                          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        </button>
                      </div>
                    </div>
                  </div>
                  <!-- (removido) "Testar conexão" simulado: a conexão WhatsApp é validada
                       em Comunicação -> Chat WhatsApp; aqui só há a credencial do LLM. -->
                  <span class="field-hint">A chave acima é do provedor de IA (LLM), não da Evolution. A conexão do WhatsApp é feita em Comunicação, Chat WhatsApp.</span>
                </div>
              </div>

              <!-- Bloco 3 — Comportamento (OCULTO: o prompt/comportamento é GLOBAL, definido no Painel Master) -->
              <div class="agt-section" style="margin-top:14px; display:none">
                <div class="agt-section-title"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:6px"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Comportamento do agente</div>
                <div class="agt-section-body">
                  <div class="field-group">
                    <label class="field-label">Prompt padrão</label>
                    <textarea name="prompt" id="promptField" class="field-input" rows="7"
                      placeholder="Você é um assistente jurídico do escritório [Nome]. Responda de forma profissional e objetiva. Quando não souber a resposta, diga que irá verificar com um advogado..."></textarea>
                    <div class="char-counter" id="promptCounter">0 caracteres</div>
                    <span class="field-hint">Instruções que definem como o agente responde. Seja claro e objetivo.</span>
                  </div>
                </div>
              </div>

              <!-- Bloco 4 — Pre-atendimento juridico (config do assistente) -->
              <div class="agt-section" style="margin-top:14px">
                <div class="agt-section-title"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:6px"><path d="M12 2a3 3 0 0 0-3 3v1H7a2 2 0 0 0-2 2v3a7 7 0 0 0 14 0V8a2 2 0 0 0-2-2h-2V5a3 3 0 0 0-3-3z"/></svg> Pre-atendimento juridico</div>
                <div class="agt-section-body">
                  <span class="field-hint" style="margin-bottom:10px;display:block">Esta tela NAO configura conexao de WhatsApp (numero, QR e token ficam em Comunicacao, Chat WhatsApp). Aqui voce define como o assistente recepciona, faz a triagem e encaminha os contatos.</span>

                  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
                    <div class="field-group"><label class="field-label">Nome do escritorio</label><input id="cfgOfficeName" class="field-input" placeholder="Ex: Silva e Advogados"></div>
                    <!-- OCULTO: maximo de perguntas e modelo de IA sao GLOBAIS (Painel Master). Mantidos no DOM com default global p/ nao quebrar o salvar. -->
                    <div class="field-group" style="display:none"><label class="field-label">Maximo de perguntas (3 a 8)</label><input id="cfgMaxQuestions" type="number" min="3" max="8" class="field-input" value="6"></div>
                    <div class="field-group" style="display:none"><label class="field-label">Modelo de IA</label><select id="cfgModel" class="field-input"><option value="gpt-4o-mini">gpt-4o-mini (economico)</option><option value="gpt-5.4-mini">gpt-5.4-mini</option><option value="gpt-5.4-nano">gpt-5.4-nano</option></select></div>
                  </div>

                  <div class="field-group" style="margin-top:10px"><label class="field-label">Descricao do escritorio</label><input id="cfgOfficeDesc" class="field-input" placeholder="Atendemos nas areas de... em..."></div>

                  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-top:4px">
                    <div class="field-group"><label class="field-label">Cidade</label><input id="cfgCidade" class="field-input"></div>
                    <div class="field-group"><label class="field-label">Horario</label><input id="cfgHorario" class="field-input" placeholder="Seg a Sex, 9h-18h"></div>
                    <div class="field-group"><label class="field-label">Telefone</label><input id="cfgTelefone" class="field-input"></div>
                    <div class="field-group"><label class="field-label">E-mail</label><input id="cfgEmail" class="field-input"></div>
                    <div class="field-group"><label class="field-label">Site</label><input id="cfgSite" class="field-input"></div>
                    <div class="field-group"><label class="field-label">Endereco</label><input id="cfgEndereco" class="field-input"></div>
                  </div>

                  <div class="field-group" style="margin-top:10px"><label class="field-label">Mensagem inicial (saudacao)</label><input id="cfgInitialMsg" class="field-input" placeholder="Ola! Sou o assistente virtual do escritorio..."></div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="field-group"><label class="field-label">Mensagem de encaminhamento</label><input id="cfgHandoffMsg" class="field-input" placeholder="Vou encaminhar para a nossa equipe..."></div>
                    <div class="field-group"><label class="field-label">Mensagem de urgencia</label><input id="cfgUrgencyMsg" class="field-input" placeholder="Entendi a urgencia, vou priorizar..."></div>
                  </div>
                  <div class="field-group"><label class="field-label">Mensagem de encerramento</label><input id="cfgClosingMsg" class="field-input"></div>

                  <div class="field-group" style="margin-top:8px">
                    <label class="field-label">Perguntas opcionais da triagem</label>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:.82rem;color:var(--muted)">
                      <label><input type="checkbox" id="cfgAskCidade"> Perguntar cidade</label>
                      <label><input type="checkbox" id="cfgAskDocumentos" checked> Perguntar documentos</label>
                      <label><input type="checkbox" id="cfgAskProcesso" checked> Perguntar se ha processo</label>
                      <label><input type="checkbox" id="cfgAskPrazo" checked> Perguntar prazo</label>
                    </div>
                  </div>

                  <div class="field-group" style="margin-top:10px">
                    <label class="field-label">Areas atendidas</label>
                    <span class="field-hint">Marque as areas que o escritorio atende. O assistente faz so a triagem (nao da parecer) e encaminha; temas fora das areas marcadas saem como "fora de escopo".</span>
                    <div id="areasBox" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:4px 14px;margin-top:8px;max-height:220px;overflow:auto;border:1px solid var(--border,#1e293b);border-radius:8px;padding:10px"></div>
                  </div>

                  <!-- OCULTO (etapa futura, paga a parte): criar card/tarefa, board, responsavel e limites de token/custo. Mantido no DOM (card desligado) p/ nao quebrar o salvar; sera reativado depois. -->
                  <div class="agt-section-title" style="margin-top:14px;font-size:.8rem; display:none">Encaminhamento e limites</div>
                  <div style="display:none;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
                    <div class="field-group"><label class="field-label" style="font-weight:400"><input type="checkbox" id="cfgCreateCard"> Criar card em Prospeccao</label></div>
                    <div class="field-group"><label class="field-label" style="font-weight:400"><input type="checkbox" id="cfgCreateTask"> Criar tarefa</label></div>
                    <div class="field-group"><label class="field-label">Quadro de tarefas (board_id)</label><input id="cfgBoardId" type="number" class="field-input" placeholder="opcional"></div>
                    <div class="field-group"><label class="field-label">Responsavel padrao (user_id)</label><input id="cfgDefaultUser" type="number" class="field-input" placeholder="opcional"></div>
                    <div class="field-group"><label class="field-label">Limite de tokens (saida)</label><input id="cfgMaxTokens" type="number" class="field-input" value="800"></div>
                    <div class="field-group"><label class="field-label">Limite de custo mensal (USD)</label><input id="cfgMonthlyLimit" type="number" step="0.01" class="field-input" placeholder="0 = sem limite"></div>
                  </div>
                </div>
              </div>

              <!-- Save button -->
              <div style="display:flex;justify-content:flex-end;margin-top:16px">
                <button type="submit" id="btnSave" class="agt-btn-primary">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                  Salvar configuração
                </button>
              </div>
            </form>

            <!-- Teste rápido -->
            <div class="agt-panel" style="padding:18px">
              <h3 style="font-size:.92rem;font-weight:600;color:#dbeafe;margin-bottom:4px">Teste rápido do agente</h3>
              <p style="font-size:.78rem;color:var(--muted);margin-bottom:14px">Simule como o agente vai contextualizar uma mensagem usando o prompt atual</p>
              <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                <div class="field-group" style="flex:1;min-width:200px">
                  <label class="field-label">Mensagem de teste</label>
                  <input id="testMessage" class="field-input" placeholder="Ex: Quero saber sobre divórcio consensual">
                </div>
                <button type="button" id="btnTestAgent" class="agt-btn-secondary" style="height:38px;margin-bottom:0">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                  Testar
                </button>
              </div>
              <div id="testResultArea" style="margin-top:12px;display:none">
                <div class="test-label">Prévia do contexto enviado ao agente:</div>
                <div class="test-bubble" id="testResultBubble"></div>
              </div>
            </div>
          </div>

          <!-- Right: tips panel -->
          <div class="space-y-4" style="position:sticky;top:1.5rem">
            <div class="agt-panel" style="padding:18px">
              <h3 style="font-size:.88rem;font-weight:700;color:#dbeafe;margin-bottom:14px"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Boas práticas para o agente</h3>
              <div>
                <div class="tip-row">
                  <div class="tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></div>
                  <div class="tip-text">Use um nome claro e profissional que identifique o escritório.</div>
                </div>
                <div class="tip-row">
                  <div class="tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div>
                  <div class="tip-text">Escreva um prompt objetivo — inclua o papel do agente, tom e limitações.</div>
                </div>
                <div class="tip-row">
                  <div class="tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
                  <div class="tip-text">Nunca compartilhe a chave de API — ela dá acesso pleno à sua conta.</div>
                </div>
                <div class="tip-row">
                  <div class="tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3h6v11l3.5 5.5A1 1 0 0 1 17.65 21H6.35a1 1 0 0 1-.85-1.5L9 14V3z"/><line x1="9" y1="3" x2="15" y2="3"/></svg></div>
                  <div class="tip-text">Teste antes de ativar — use o campo de teste para validar o comportamento.</div>
                </div>
                <div class="tip-row">
                  <div class="tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg></div>
                  <div class="tip-text">Revise o atendimento periodicamente e ajuste o prompt conforme necessário.</div>
                </div>
                <div class="tip-row">
                  <div class="tip-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
                  <div class="tip-text">Evite incluir dados sensíveis de clientes no prompt padrão.</div>
                </div>
              </div>
            </div>

            <!-- Status visual -->
            <div class="agt-panel" style="padding:16px">
              <h3 style="font-size:.82rem;font-weight:700;color:#dbeafe;margin-bottom:12px"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><rect x="3" y="12" width="4" height="9"/><rect x="10" y="7" width="4" height="14"/><rect x="17" y="3" width="4" height="18"/></svg> Diagnóstico rápido</h3>
              <div style="display:flex;flex-direction:column;gap:8px" id="diagList">
                <div class="diag-row" id="diagName">
                  <span style="font-size:.76rem;color:var(--muted)">Nome do agente</span>
                  <span class="diag-badge" id="diagNameBadge">—</span>
                </div>
                <div class="diag-row" id="diagWa">
                  <span style="font-size:.76rem;color:var(--muted)">WhatsApp</span>
                  <span class="diag-badge" id="diagWaBadge">—</span>
                </div>
                <div class="diag-row" id="diagProv">
                  <span style="font-size:.76rem;color:var(--muted)">Provedor</span>
                  <span class="diag-badge" id="diagProvBadge">—</span>
                </div>
                <div class="diag-row" id="diagKey">
                  <span style="font-size:.76rem;color:var(--muted)">Chave API</span>
                  <span class="diag-badge" id="diagKeyBadge">—</span>
                </div>
                <div class="diag-row" id="diagPrm">
                  <span style="font-size:.76rem;color:var(--muted)">Prompt</span>
                  <span class="diag-badge" id="diagPrmBadge">—</span>
                </div>
              </div>
            </div>
          </div>
        </div>

      </section>
    </div>
  </main>

  <div class="toast-wrap" id="toastContainer" aria-live="polite" aria-atomic="true"></div>


  <style>
    .diag-row { display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px solid rgba(96,165,250,.07); }
    .diag-row:last-child { border-bottom:none; }
    .diag-badge { font-size:.7rem; font-weight:700; padding:2px 8px; border-radius:999px; }
    .diag-ok      { background:rgba(16,185,129,.2);  color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
    .diag-warn    { background:rgba(245,158,11,.18); color:#fcd34d; border:1px solid rgba(245,158,11,.35); }
    .diag-neutral { background:rgba(148,163,184,.13);color:#94a3b8; border:1px solid rgba(148,163,184,.28); }
  </style>

  <script src="/assets/agent.js?v=<?= filemtime(__DIR__ . '/assets/agent.js') ?>"></script>
  <script src="/assets/fog.js"></script>
  <script>
    // Dashboard status mirror
    try{
      const saved = localStorage.getItem('dashboard_last_update');
      if (saved){ const el=document.getElementById('dashboardStatus'); if(el){ el.textContent=saved; el.style.color='#4ade80'; } }
      window.addEventListener('storage', function(e){ if(!e||e.key!=='dashboard_last_update') return; const el=document.getElementById('dashboardStatus'); if(el){ el.textContent=e.newValue; el.style.color='#4ade80'; } });
    }catch(e){}
  </script>
</body>
</html>

