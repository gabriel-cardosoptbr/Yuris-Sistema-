<?php
require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Models/Processo.php';
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: /sistema_vendas/public/login.php');
    exit;
}
$activePage = 'processos';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));

// Carrega usuários no servidor para disponibilizar imediatamente no JS (sem fetch async)
$system_users = [];
try {
    $pdo_u = App\Models\Database::getConnection();
    $system_users = $pdo_u->query(
        "SELECT id, nome FROM users WHERE deleted_at IS NULL ORDER BY nome ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Gestão Processual — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/sistema_vendas/public/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/sistema_vendas/public/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/yuris-theme.css">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/fog.css">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/sidebar.css?v=8">
  <style>
    /*
      Variáveis locais alinhadas com yuris-theme.css.
      yuris-theme.css usa !important nos componentes principais (kpi-card, dot-*, badges),
      portanto estas variáveis afetam principalmente: body background, textos inline,
      e componentes específicos de processos que não têm override no tema global.
    */
    :root {
      --bg-main:    #070F1C;   /* igual yuris-theme.css --yuris-bg-deep */
      --panel:      #0D1C30;   /* igual yuris-theme.css --yuris-bg-card */
      --panel-soft: #0E1F36;
      --line:       rgba(160,180,210,0.08);   /* igual yuris-theme.css --yuris-border */
      --line-strong:rgba(160,180,210,0.14);   /* igual yuris-theme.css --yuris-border-md */
      --text:       #D8E4F0;   /* igual yuris-theme.css --yuris-text */
      --muted:      #7A8898;   /* igual yuris-theme.css --yuris-text-dim */
      --primary:    #244E7A;   /* igual yuris-theme.css --yuris-accent */
      --ok:         #1E4A3A;   /* igual yuris-theme.css --yuris-success */
      --warn:       #3D3010;   /* igual yuris-theme.css --yuris-warning */
      --danger:     #3A1020;   /* igual yuris-theme.css --yuris-danger */
      --radius:     14px;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      background-color: #070F1C;
      background-image: radial-gradient(ellipse at 20% 50%, rgba(20,50,90,0.18) 0%, transparent 55%), radial-gradient(ellipse at 80% 20%, rgba(30,60,100,0.12) 0%, transparent 50%);
      background-attachment: fixed;
      color: var(--text);
      font-family: Inter, 'Poppins', system-ui, -apple-system, sans-serif;
      min-height: 100vh;
    }

    /* ── Panel ── */
    .proc-panel {
      background: linear-gradient(165deg, rgba(14,35,65,.94), rgba(10,23,43,.96));
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 20px;
      box-shadow: 0 14px 40px rgba(2,6,23,.45);
    }

    /* ── Typography ── */
    .proc-title    { font-size: 1.5rem; font-weight: 700; color: #dbeafe; line-height: 1.2; }
    .proc-subtitle { margin-top: 4px; color: var(--muted); font-size: .84rem; line-height: 1.45; }
    .proc-section-title { font-size: 1rem; font-weight: 600; color: #dbeafe; letter-spacing: .01em; }

    /* ── KPI Cards ── */
    .kpi-card {
      position: relative; overflow: hidden;
      border-radius: 14px;
      border: 1px solid rgba(96,165,250,.22);
      background: linear-gradient(135deg, rgba(13,31,56,.95), rgba(8,19,37,.95));
      padding: 16px;
      min-height: 108px;
      transition: transform .2s, border-color .2s, box-shadow .2s;
    }
    .kpi-card:hover { transform: translateY(-4px); border-color: rgba(96,165,250,.45); box-shadow: 0 12px 28px rgba(37,99,235,.25); }
    .kpi-card.kpi-danger { border-color: rgba(239,68,68,.35); }
    .kpi-card.kpi-danger:hover { border-color: rgba(239,68,68,.6); box-shadow: 0 12px 28px rgba(239,68,68,.2); }
    .kpi-card.kpi-warn  { border-color: rgba(245,158,11,.35); }
    .kpi-card.kpi-warn:hover  { border-color: rgba(245,158,11,.6); box-shadow: 0 12px 28px rgba(245,158,11,.2); }
    .kpi-card.kpi-ok    { border-color: rgba(16,185,129,.35); }
    .kpi-label { color: #a8c2df; font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
    .kpi-value { margin-top: 10px; color: #f0f8ff; font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
    .kpi-foot  { margin-top: 6px; color: var(--muted); font-size: .72rem; }
    .kpi-dot   { position: absolute; top: 14px; right: 14px; width: 9px; height: 9px; border-radius: 50%; }
    .dot-ok      { background: var(--ok);     box-shadow: 0 0 7px rgba(16,185,129,.6); }
    .dot-warn    { background: var(--warn);   box-shadow: 0 0 7px rgba(245,158,11,.6); }
    .dot-danger  { background: var(--danger); box-shadow: 0 0 7px rgba(239,68,68,.6);  }
    .dot-neutral { background: rgba(148,163,184,.45); }

    /* ── Summary ── */
    .summary-box {
      border-radius: 10px;
      border: 1px solid rgba(96,165,250,.2);
      background: rgba(8,20,40,.55);
      padding: 13px 16px;
      color: #d2e8ff; font-size: .87rem; line-height: 1.65;
    }
    .summary-box strong { color: #93c5fd; }

    /* ── Top bar (year + button) ── */
    .proc-topbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .proc-select {
      height: 38px; padding: 0 12px;
      border-radius: 10px; border: 1px solid rgba(96,165,250,.3);
      background: rgba(8,22,44,.9); color: #d0e8ff;
      font-family: inherit; font-size: .84rem; cursor: pointer;
      transition: border-color .18s;
    }
    .proc-select:focus { outline: none; border-color: var(--line-strong); box-shadow: 0 0 0 3px rgba(59,130,246,.18); }
    .proc-select option { background: #0b1c35; color: #e8f4ff; }
    .proc-btn-primary {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 0 18px; height: 38px;
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      color: #fff; border: none; border-radius: 10px;
      font-family: inherit; font-size: .84rem; font-weight: 600;
      cursor: pointer; transition: transform .18s, box-shadow .18s;
      box-shadow: 0 4px 14px rgba(37,99,235,.35);
    }
    .proc-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(37,99,235,.5); }

    /* ── Board ── */
    .proc-board {
      display: flex; gap: 12px;
      flex-wrap: nowrap; align-items: flex-end;
      overflow-x: auto; padding-top: 16px; padding-bottom: 4px;
      -webkit-overflow-scrolling: touch;
      transform: rotateX(180deg);
      -webkit-transform: rotateX(180deg);
    }
    .proc-col {
      flex: 0 0 290px; width: 290px; max-width: 320px; min-width: 210px;
      border-radius: 12px;
      background: rgba(8,20,40,.5);
      border: 1px solid rgba(96,165,250,.1);
      padding: 12px;
      transform: rotateX(180deg);
      -webkit-transform: rotateX(180deg);
    }
    .proc-col-header {
      display: flex; justify-content: space-between; align-items: center;
      margin-bottom: 10px; padding-bottom: 8px;
      border-bottom: 1px solid rgba(96,165,250,.1);
    }
    .proc-col-month { font-weight: 600; font-size: .86rem; color: #c3d9f8; }
    .proc-col-count {
      font-size: .72rem; padding: 2px 8px; border-radius: 999px;
      background: rgba(37,99,235,.25); color: #93c5fd; font-weight: 600;
    }
    .proc-cards-list { min-height: 180px; display: flex; flex-direction: column; gap: 8px; }
    .proc-empty {
      display: flex; align-items: center; justify-content: center;
      min-height: 90px;
      border: 2px dashed rgba(96,165,250,.1);
      border-radius: 8px;
      color: rgba(148,163,184,.45); font-size: .76rem; text-align: center; padding: 12px;
    }

    /* ── Process card (board) ── */
    .proc-card {
      background: linear-gradient(165deg, rgba(20,42,78,.92), rgba(13,28,52,.94));
      border: 1px solid rgba(96,165,250,.16);
      border-radius: 10px; padding: 11px 12px;
      transition: border-color .18s, box-shadow .18s, transform .15s;
      cursor: pointer;
    }
    .proc-card:hover { transform: translateY(-2px); border-color: rgba(96,165,250,.36); box-shadow: 0 6px 18px rgba(2,6,23,.45); }
    .proc-card.is-urgent { border-left: 3px solid var(--danger); }
    .proc-card.is-warn   { border-left: 3px solid var(--warn); }
    .proc-card.is-ok     { border-left: 3px solid var(--ok); }
    .proc-card-num    { font-size: .75rem; font-weight: 700; color: #93c5fd; letter-spacing: .01em; }
    .proc-card-client { font-size: .83rem; font-weight: 600; color: #e2f0ff; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .proc-card-meta   { font-size: .72rem; color: var(--muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .proc-card-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; }

    /* ── Progress bar de tarefas no card ── */
    .proc-task-bar-wrap {
      position: relative; margin-top: 10px;
      cursor: default;
    }
    .proc-task-bar-track {
      height: 6px; border-radius: 99px;
      background: rgba(59,130,246,.35); overflow: hidden;
      box-shadow: inset 0 0 0 1px rgba(59,130,246,.25);
    }
    .proc-task-bar-fill {
      height: 100%; border-radius: 99px;
      transition: width .4s ease;
      box-shadow: 0 0 6px rgba(96,165,250,.5);
    }
    .proc-task-bar-wrap::after {
      content: attr(data-tip);
      position: absolute;
      bottom: calc(100% + 8px); left: 50%;
      transform: translateX(-50%);
      background: #0B1929;
      border: 1px solid rgba(96,165,250,.22);
      color: #d6eaff; font-size: .72rem;
      padding: 5px 10px; border-radius: 7px;
      white-space: nowrap; z-index: 300;
      box-shadow: 0 4px 16px rgba(0,0,0,.5);
      pointer-events: none;
      opacity: 0; transition: opacity .15s;
    }
    .proc-task-bar-wrap:hover::after { opacity: 1; }
    .proc-card-date   { font-size: .72rem; color: #a8c2df; }
    .proc-card-actions { display: flex; gap: 5px; }

    /* ── Badges ── */
    .proc-badge {
      display: inline-flex; align-items: center;
      padding: 2px 7px; border-radius: 999px;
      font-size: .66rem; font-weight: 700; letter-spacing: .01em; white-space: nowrap;
    }
    .badge-danger  { background: rgba(239,68,68,.2);    color: #fca5a5; border: 1px solid rgba(239,68,68,.4); }
    .badge-warn    { background: rgba(245,158,11,.18);  color: #fcd34d; border: 1px solid rgba(245,158,11,.38); }
    .badge-ok      { background: rgba(16,185,129,.18);  color: #6ee7b7; border: 1px solid rgba(16,185,129,.35); }
    .badge-info    { background: rgba(59,130,246,.18);  color: #93c5fd; border: 1px solid rgba(59,130,246,.36); }
    .badge-neutral { background: rgba(148,163,184,.13); color: #94a3b8; border: 1px solid rgba(148,163,184,.26); }

    /* ── Action buttons ── */
    .proc-btn-sm {
      display: inline-flex; align-items: center;
      padding: 3px 9px; border-radius: 6px; border: none;
      font-family: inherit; font-size: .7rem; font-weight: 600;
      cursor: pointer; transition: opacity .15s, transform .15s;
    }
    .proc-btn-sm:hover { opacity: .82; transform: translateY(-1px); }
    .btn-edit { background: rgba(37,99,235,.9);  color: #fff; }
    .btn-del  { background: rgba(220,38,38,.82); color: #fff; }

    /* ── Agenda row ── */
    .proc-agenda-list { display: flex; flex-direction: column; gap: 8px; }
    .proc-agenda-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: 13px 15px; gap: 12px;
      background: linear-gradient(165deg, rgba(15,33,60,.94), rgba(10,24,46,.96));
      border: 1px solid rgba(59,130,246,.16);
      border-radius: 10px;
      transition: border-color .18s, transform .15s;
    }
    .proc-agenda-row:hover { transform: translateY(-1px); border-color: rgba(96,165,250,.32); }
    .proc-agenda-row.row-urgent { border-left: 3px solid var(--danger); }
    .proc-agenda-row.row-warn   { border-left: 3px solid var(--warn); }
    .proc-agenda-left { flex: 1; min-width: 0; }
    .proc-agenda-num    { font-size: .73rem; color: #93c5fd; font-weight: 600; }
    .proc-agenda-client { font-size: .87rem; font-weight: 600; color: #e2f0ff; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .proc-agenda-tipo   { font-size: .74rem; color: var(--muted); margin-top: 1px; }
    .proc-agenda-right  { text-align: right; flex-shrink: 0; }
    .proc-agenda-date   { font-size: .84rem; font-weight: 600; color: #d1e8ff; }
    .proc-agenda-actions { display: flex; gap: 5px; justify-content: flex-end; margin-top: 6px; }

    /* ── Modal ── */
    #modalProcess {
      position: fixed; inset: 0;
      background: rgba(2,6,23,.65);
      display: flex; align-items: flex-start; justify-content: center;
      z-index: 1000; overflow-y: auto; padding: 30px 12px;
    }
    #modalProcess.hidden { display: none !important; }
    .proc-modal {
      width: 760px; max-width: 96vw;
      background: linear-gradient(165deg, rgba(10,24,46,.99), rgba(7,18,36,.99));
      border: 1px solid rgba(96,165,250,.22);
      border-radius: 16px;
      box-shadow: 0 24px 60px rgba(2,6,23,.7);
      overflow: hidden;
      margin: auto;
    }
    .proc-modal-header {
      display: flex; justify-content: space-between; align-items: center;
      padding: 18px 22px;
      border-bottom: 1px solid rgba(96,165,250,.14);
      background: rgba(8,22,44,.55);
    }
    .proc-modal-title { font-size: 1.05rem; font-weight: 700; color: #dbeafe; }
    .proc-modal-body  { padding: 20px 22px; display: flex; flex-direction: column; gap: 16px; max-height: 78vh; overflow-y: auto; }
    .proc-modal-section { border: 1px solid rgba(96,165,250,.12); border-radius: 10px; overflow: hidden; }
    .proc-modal-section-title {
      padding: 9px 14px;
      background: rgba(37,99,235,.14);
      font-size: .77rem; font-weight: 700; color: #93c5fd;
      letter-spacing: .04em; text-transform: uppercase;
    }
    .proc-modal-fields { padding: 14px; display: grid; grid-template-columns: repeat(2,1fr); gap: 12px; }
    .proc-modal-fields.cols-1 { grid-template-columns: 1fr; }
    .field-group { display: flex; flex-direction: column; gap: 5px; }
    .field-label { font-size: .79rem; font-weight: 600; color: #b8d5f4; }
    .field-input {
      width: 100%; padding: 9px 11px;
      border-radius: 8px; border: 1px solid rgba(96,165,250,.25);
      background: rgba(255,255,255,.95);
      color: #0f172a; font-family: inherit; font-size: .84rem;
      transition: border-color .18s, box-shadow .18s;
    }
    .field-input::placeholder { color: rgba(15,23,42,.42); }
    .field-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.2); }
    .proc-modal-footer {
      display: flex; justify-content: flex-end; gap: 10px;
      padding: 14px 22px;
      border-top: 1px solid rgba(96,165,250,.14);
      background: rgba(8,22,44,.4);
    }
    .proc-btn-cancel {
      padding: 0 18px; height: 38px;
      border-radius: 8px; border: 1px solid rgba(96,165,250,.3);
      background: transparent; color: #93c5fd;
      font-family: inherit; font-size: .84rem; font-weight: 600; cursor: pointer;
      transition: background .18s;
    }
    .proc-btn-cancel:hover { background: rgba(37,99,235,.15); }
    .proc-btn-save {
      padding: 0 22px; height: 38px;
      border-radius: 8px; border: none;
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      color: #fff; font-family: inherit; font-size: .84rem; font-weight: 600; cursor: pointer;
      box-shadow: 0 4px 14px rgba(37,99,235,.35);
      transition: transform .18s, box-shadow .18s;
    }
    .proc-btn-save:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(37,99,235,.5); }

    /* ── code-select-wrap ── */
    .code-select-wrap { position: relative; display: block; width: 100%; }
    .code-select-wrap select { padding-right: 3rem; width: 100%; box-sizing: border-box; height: 38px; appearance: none; -webkit-appearance: none; }
    .code-select-wrap::after { content:''; position:absolute; right:3rem; top:50%; transform:translateY(-50%); width:12px; height:12px; background-repeat:no-repeat; pointer-events:none; z-index:2; background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%237EB8F6' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>"); background-size:12px 12px; }
    .code-select-wrap .manage-btn { position:absolute; right:6px; top:50%; transform:translateY(-50%); border-radius:.5rem; padding:.25rem .5rem; background:transparent; color:#7EB8F6; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:.35rem; }
    .code-select-wrap .manage-btn svg { width:18px; height:18px; display:block; }
    .code-select-wrap .manage-btn:hover { background:rgba(126,184,246,.1); }

    /* ── Toasts ── */
    .toast-container { position:fixed; top:1rem; right:1rem; z-index:1100; display:flex; flex-direction:column; gap:.5rem; }
    .toast { padding:.6rem 1rem; border-radius:.5rem; color:#fff; box-shadow:0 6px 18px rgba(2,6,23,.6); font-size:14px; opacity:0; transform:translateY(-6px); transition:all .28s ease; }
    .toast.show { opacity:1; transform:translateY(0); }
    .toast.success { background:linear-gradient(90deg,#059669,#10b981); }
    .toast.error   { background:linear-gradient(90deg,#dc2626,#ef4444); }
    .toast.info    { background:linear-gradient(90deg,#2563eb,#3b82f6); }

    /* ── Flash animation ── */
    @keyframes processFlash {
      0%   { box-shadow:0 0 0 0 rgba(96,165,250,0); }
      50%  { box-shadow:0 8px 20px 4px rgba(96,165,250,.14); transform:translateY(-2px); }
      100% { box-shadow:0 0 0 0 rgba(96,165,250,0); transform:translateY(0); }
    }
    .process-flash { animation: processFlash .5s ease-in-out 0s 2; }

    /* ── Novos blocos processuais ── */
    .proc-section { background:rgba(14,28,50,.6); border:1px solid rgba(96,165,250,.1); border-radius:10px; padding:16px; margin-bottom:14px; }
    .proc-section-title { font-size:.72rem; font-weight:700; color:#60a5fa; text-transform:uppercase; letter-spacing:.08em; margin-bottom:12px; }
    .prazo-card { background:rgba(8,20,40,.8); border:1px solid rgba(96,165,250,.12); border-radius:8px; padding:12px; display:flex; flex-direction:column; gap:8px; }
    .prazo-card-header { display:flex; justify-content:space-between; align-items:center; }
    .prazo-badge-pendente { background:rgba(245,158,11,.15); color:#fcd34d; border:1px solid rgba(245,158,11,.25); border-radius:4px; padding:2px 8px; font-size:.7rem; font-weight:700; }
    .prazo-badge-concluido { background:rgba(16,185,129,.15); color:#6ee7b7; border:1px solid rgba(16,185,129,.25); border-radius:4px; padding:2px 8px; font-size:.7rem; font-weight:700; }
    .prazo-badge-vencido { background:rgba(239,68,68,.15); color:#fca5a5; border:1px solid rgba(239,68,68,.25); border-radius:4px; padding:2px 8px; font-size:.7rem; font-weight:700; }
    .tarefa-item {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px;
      background: rgba(5,18,39,.6);
      border-radius: 8px;
      border: 1px solid rgba(96,165,250,.1);
      transition: border-color .15s, background .15s;
      cursor: default;
    }
    .tarefa-item:hover { background: rgba(10,26,56,.65); border-color: rgba(96,165,250,.2); }
    .tarefa-item.done-item { opacity: .7; }

    /* Custom checkbox */
    .tarefa-check { display: none; }
    .tarefa-chk-box {
      width: 18px; height: 18px; flex-shrink: 0; border-radius: 5px;
      border: 1.5px solid rgba(96,165,250,.3);
      background: transparent; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      transition: all .15s; color: transparent;
    }
    .tarefa-item.done-item .tarefa-chk-box {
      background: #2563eb; border-color: #2563eb; color: #fff;
    }
    .tarefa-chk-box:hover { border-color: rgba(96,165,250,.6); }

    .tarefa-body { flex: 1; min-width: 0; }
    .tarefa-texto { font-size: .83rem; font-weight: 500; color: #dbeafe; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tarefa-texto.done { text-decoration: line-through; color: #6b7fa0; }
    .tarefa-resp {
      display: inline-flex; align-items: center; gap: 4px;
      margin-top: 3px; font-size: .71rem; color: #7a9abf;
    }

    .tarefa-btn-edit {
      background: transparent; border: 1px solid rgba(96,165,250,.18);
      color: #7eb8f6; border-radius: 6px;
      padding: 3px 10px; font-size: .73rem; font-weight: 500;
      cursor: pointer; white-space: nowrap; font-family: inherit;
      transition: all .15s; flex-shrink: 0;
    }
    .tarefa-btn-edit:hover { background: rgba(37,99,235,.18); border-color: rgba(96,165,250,.4); }
    .tarefa-del {
      width: 26px; height: 26px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      background: rgba(220,38,38,.12); border: 1px solid rgba(239,68,68,.2);
      border-radius: 6px; color: #f87171; cursor: pointer;
      transition: all .15s; font-size: .8rem;
    }
    .tarefa-del:hover { background: rgba(220,38,38,.28); border-color: rgba(239,68,68,.45); }
    .timeline-entry { display:flex; gap:10px; padding:8px 0; border-bottom:1px solid rgba(96,165,250,.08); }
    .timeline-entry:last-child { border-bottom:none; }
    .timeline-dot { width:8px; height:8px; border-radius:50%; background:#3b82f6; margin-top:5px; flex-shrink:0; }
    .timeline-content { flex:1; }
    .timeline-action { font-size:.8rem; font-weight:600; color:#e2f0ff; }
    .timeline-meta { font-size:.72rem; color:#9ab0c9; margin-top:2px; }

    /* Fix autofill do browser sobrescrevendo fundo dos inputs */
    #modalAddPrazo input:-webkit-autofill,
    #modalAddPrazo input:-webkit-autofill:hover,
    #modalAddPrazo input:-webkit-autofill:focus,
    #modalProcess input:-webkit-autofill,
    #modalProcess input:-webkit-autofill:hover,
    #modalProcess input:-webkit-autofill:focus {
      -webkit-box-shadow: 0 0 0px 1000px #050f22 inset !important;
      -webkit-text-fill-color: #d6eaff !important;
      caret-color: #d6eaff;
    }
    #modalAddPrazo input,
    #modalAddPrazo select,
    #modalAddPrazo textarea {
      background: #050f22 !important;
      color: #d6eaff !important;
      color-scheme: dark;
    }
    #modalAddPrazo input::placeholder { color: rgba(148,163,184,.5) !important; }

    @media (max-width: 1100px) {
      .kpi-grid-6 { grid-template-columns: repeat(3,1fr) !important; }
    }
    @media (max-width: 640px) {
      .kpi-grid-6 { grid-template-columns: repeat(2,1fr) !important; }
      .proc-modal-fields { grid-template-columns: 1fr !important; }
    }
  </style>
</head>
<body>
  <main class="w-full px-6 py-6">
    <div class="page-layout">

      <!-- ── Sidebar ── -->
      <?php include __DIR__ . '/includes/sidebar.php'; ?>

      <!-- ── Main content ── -->
      <section class="main-content space-y-4">

        <!--
          Aba Processos — FOCO OPERACIONAL
          Diferente de juridico.php (estratégico): aqui o objetivo é controle diário —
          prazos de hoje/amanhã/semana, Kanban mensal, tarefas individuais, histórico por processo.
          Dados: /api/processes.php (CRUD completo) + /api/juridico_metrics.php (nomes de advogados).
          JS responsável: processos.js
        -->
        <!-- Header — padrão .page-header (yuris-theme.css) -->
        <div class="proc-panel page-header">
          <div class="page-header-inner">
            <div class="page-header-text">
              <h2 class="page-header-title">Gestão Processual</h2>
              <p class="page-header-subtitle">Controle operacional — prazos, tarefas, histórico e movimentações diárias</p>
            </div>
          </div>
        </div>

        <!-- Filtros e ações — busca por texto, status, responsável, data e cadastro rápido -->
        <div class="proc-panel">
          <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
            <!-- Busca por texto -->
            <div style="position:relative;flex:1;min-width:200px">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input id="procSearchText" type="text" placeholder="Buscar por número, cliente ou setor..."
                     style="width:100%;padding:9px 12px 9px 32px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.84rem;box-sizing:border-box">
            </div>
            <!-- Filtro por status -->
            <select id="procFilterStatus" class="proc-select" style="min-width:140px">
              <option value="">Todos os status</option>
              <option value="ativo">Ativo</option>
              <option value="arquivado">Arquivado</option>
              <option value="encerrado">Encerrado</option>
            </select>
            <!-- Filtro por responsável -->
            <select id="procFilterResp" class="proc-select" style="min-width:160px">
              <option value="">Todos os responsáveis</option>
            </select>
            <!-- Filtro por data (prazo) -->
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
              <span style="font-size:.78rem;color:#9ab0c9;white-space:nowrap">Prazo entre</span>
              <input id="procFilterDateFrom" type="date" class="proc-select" style="padding:0 8px;min-width:130px">
              <span style="font-size:.78rem;color:#9ab0c9">e</span>
              <input id="procFilterDateTo" type="date" class="proc-select" style="padding:0 8px;min-width:130px">
            </div>
            <!-- Limpar -->
            <button id="procFilterClear" style="display:inline-flex;align-items:center;gap:5px;padding:8px 14px;border-radius:8px;background:transparent;border:1px solid rgba(96,165,250,.2);color:#9ab0c9;cursor:pointer;font-size:.82rem;white-space:nowrap"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Limpar</button>
            <!-- Separador -->
            <div style="flex:1;min-width:8px"></div>
            <!-- Ano + Cadastrar (movidos para cá) -->
            <div class="proc-topbar">
              <label class="text-sm font-semibold" style="color:#93c5fd">Ano</label>
              <select id="yearFilter" class="proc-select"></select>
              <button id="btnNewProcess" class="proc-btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Cadastrar Processo
              </button>
            </div>
            <!-- Contador -->
            <span id="procFilterCount" style="font-size:.78rem;color:#9ab0c9"></span>
          </div>
        </div>

        <!--
          KPI operacionais — focados em urgência do dia a dia.
          Diferentes dos KPIs de juridico.php (que mostram totais estratégicos e risco latente).
          Alimentados por processos.js via /api/processes.php (cálculo client-side).
        -->
        <div class="kpi-grid-6" style="display:grid;grid-template-columns:repeat(6,1fr);gap:12px">
          <div class="kpi-card">
            <div class="kpi-dot dot-neutral"></div>
            <div class="kpi-label">Processos Ativos</div>
            <div class="kpi-value" id="kpiAtivos">—</div>
            <div class="kpi-foot">no período selecionado</div>
          </div>
          <div class="kpi-card kpi-danger">
            <div class="kpi-dot dot-danger"></div>
            <div class="kpi-label">Vencem Hoje</div>
            <div class="kpi-value" id="kpiHoje">—</div>
            <div class="kpi-foot">prazo fatal hoje</div>
          </div>
          <div class="kpi-card kpi-warn">
            <div class="kpi-dot dot-warn"></div>
            <div class="kpi-label">Esta Semana</div>
            <div class="kpi-value" id="kpiSemana">—</div>
            <div class="kpi-foot">prazos em até 7 dias</div>
          </div>
          <div class="kpi-card kpi-danger">
            <div class="kpi-dot dot-danger"></div>
            <div class="kpi-label">Urgentes</div>
            <div class="kpi-value" id="kpiUrgentes">—</div>
            <div class="kpi-foot">prazo em até 3 dias</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-dot dot-danger"></div>
            <div class="kpi-label">Vencidos</div>
            <div class="kpi-value" id="kpiVencidos">—</div>
            <div class="kpi-foot">prazo ultrapassado</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-dot dot-neutral"></div>
            <div class="kpi-label">Encerrados</div>
            <div class="kpi-value" id="kpiArquivados">—</div>
            <div class="kpi-foot">arquivados / encerrados</div>
          </div>
        </div>

        <!-- Resumo operacional automático — gerado por processos.js com foco em prazos urgentes -->
        <div class="proc-panel" style="padding:14px 18px">
          <div id="resumoJuridico" class="summary-box">
            Carregando resumo operacional dos processos...
          </div>
        </div>

        <!-- Calendário Processual — Kanban por mês de prazo, scroll horizontal -->
        <div class="proc-panel">
          <div class="flex items-center justify-between mb-3">
            <div>
              <h3 class="proc-section-title">Calendário Processual</h3>
              <p class="proc-subtitle">Processos organizados por mês de prazo — role horizontalmente para navegar</p>
            </div>
          </div>
          <div id="columnsWrapper" class="mt-2"></div>
        </div>

        <!-- Agenda Jurídica Prioritária -->
        <div class="proc-panel">
          <div class="flex items-center justify-between mb-3">
            <div>
              <h3 class="proc-section-title">Agenda Jurídica Prioritária</h3>
              <p class="proc-subtitle">Próximos prazos ordenados por urgência — os mais críticos aparecem primeiro</p>
            </div>
          </div>
          <div id="upcomingList" class="proc-agenda-list mt-2"></div>
        </div>

      </section>
    </div>
  </main>

  <!-- ── Modal ── -->
  <div id="modalProcess" class="hidden">
    <div class="proc-modal">
      <div class="proc-modal-header">
        <span class="proc-modal-title" id="modalTitle">Cadastrar Processo</span>
        <button type="button" id="modalCloseX" class="proc-btn-cancel" style="display:inline-flex;align-items:center;gap:5px;height:32px;padding:0 12px;font-size:.8rem"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Fechar</button>
      </div>
      <div class="proc-modal-body">
        <form id="processForm">
          <input type="hidden" name="id">

          <!-- Bloco 1 — Identificação -->
          <div class="proc-modal-section">
            <div class="proc-modal-section-title">Identificação do Processo</div>
            <div class="proc-modal-fields">
              <div class="field-group">
                <label class="field-label">Número do processo</label>
                <input name="numero" class="field-input" placeholder="0000000-00.0000.0.00.0000">
              </div>
              <div class="field-group">
                <label class="field-label">Cliente</label>
                <input name="cliente_nome" id="clienteNomeInput" class="field-input" placeholder="Nome completo do cliente">
                <div id="clienteNomeHint" style="display:none;font-size:.7rem;color:#60a5fa;margin-top:3px">Nome preenchido pelo vínculo com cliente</div>
              </div>
              <div class="field-group">
                <label class="field-label">Parte contrária</label>
                <input name="parte_contraria" class="field-input" placeholder="Nome da parte adversa">
                <input name="cpf_cnpj_parte_contraria" class="field-input" placeholder="CPF ou CNPJ da parte contrária"
                       style="margin-top:6px"
                       oninput="this.value=this.value.replace(/[^0-9.\/\-]/g,'')">
              </div>
              <div class="field-group">
                <label class="field-label">Vara / Comarca</label>
                <input name="vara_comarca" class="field-input" placeholder="Ex: 3ª Vara Cível — São Paulo/SP">
              </div>
              <div class="field-group" style="grid-column:1/-1">
                <label class="field-label">Setor</label>
                <select name="setor_id" id="setor_id" class="field-input">
                  <option value="">— Selecionar setor —</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Bloco 2 — Controle Interno -->
          <div class="proc-modal-section" style="margin-top:14px">
            <div class="proc-modal-section-title">Controle Interno</div>
            <div class="proc-modal-fields">
              <div class="field-group">
                <label class="field-label">Responsável</label>
                <select name="responsavel_user_id" id="selectResponsavelProcesso" class="field-input">
                  <option value="">— Selecionar responsável —</option>
                </select>
              </div>
              <div class="field-group">
                <label class="field-label">Status processual</label>
                <select name="status" class="field-input">
                  <option value="ativo">Ativo</option>
                  <option value="arquivado">Arquivado</option>
                  <option value="encerrado">Encerrado</option>
                </select>
              </div>
              <div class="field-group">
                <label class="field-label">Data de início</label>
                <input type="date" name="data_inicio" class="field-input">
              </div>
              <div class="field-group">
                <label class="field-label">Próximo prazo</label>
                <input type="date" name="proximo_prazo" id="proximoPrazoInput" class="field-input">
                <div id="proximoPrazoHint" style="font-size:.72rem;color:#9ab0c9;margin-top:3px"></div>
              </div>
              <div class="field-group" style="grid-column:1/-1">
                <label class="field-label">Observações estratégicas</label>
                <textarea name="observacoes" class="field-input" rows="3" placeholder="Notas internas, estratégia processual, pontos de atenção..."></textarea>
              </div>
            </div>
          </div>

          <!-- Bloco — Vínculo com Cliente -->
          <div class="proc-section" style="margin-top:14px">
            <div class="proc-section-title">Vínculo com Cliente</div>
            <div class="proc-section-body">
              <div>
                <label style="font-size:.8rem;color:#9ab0c9;display:block;margin-bottom:4px">Cliente vinculado (Lead/Prospecção)</label>
                <div style="display:flex;gap:8px;align-items:center">
                  <div id="proc_card_selected_name" style="flex:1;padding:10px 12px;border-radius:8px;background:rgba(5,18,39,.6);border:1px solid rgba(96,165,250,.15);color:#9ab0c9;font-size:.85rem;min-height:40px;display:flex;align-items:center">
                    <span id="proc_card_selected_label" style="color:#9ab0c9">Nenhum cliente vinculado</span>
                  </div>
                  <button type="button" id="btnAbrirModalCliente" style="padding:10px 16px;border-radius:8px;background:rgba(37,99,235,.25);border:1px solid rgba(96,165,250,.3);color:#93c5fd;cursor:pointer;font-size:.82rem;white-space:nowrap">Buscar</button>
                  <button type="button" id="btnLimparCliente" style="display:none;padding:10px 10px;border-radius:8px;background:transparent;border:1px solid rgba(239,68,68,.3);color:#fca5a5;cursor:pointer;font-size:.82rem"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                </div>
                <input type="hidden" name="card_id" id="proc_card_id">
              </div>

              <!-- Modal de seleção de cliente -->
              <div id="modalSelecionarCliente" style="display:none;position:fixed;inset:0;background:rgba(2,6,23,.8);z-index:3000;align-items:flex-start;justify-content:center;padding:40px 16px">
                <div style="background:linear-gradient(165deg,rgba(10,24,46,.99),rgba(7,18,36,.99));border:1px solid rgba(96,165,250,.25);border-radius:14px;width:560px;max-width:98vw;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,.8)">
                  <div style="padding:18px 20px;border-bottom:1px solid rgba(96,165,250,.12);display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
                    <span style="font-size:1rem;font-weight:700;color:#dbeafe">Selecionar Cliente</span>
                    <button type="button" id="btnFecharModalCliente" style="background:transparent;border:1px solid rgba(96,165,250,.3);color:#93c5fd;border-radius:8px;padding:4px 12px;cursor:pointer;font-size:.82rem;display:flex;align-items:center;gap:5px"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Fechar</button>
                  </div>
                  <div style="padding:14px 20px;flex-shrink:0">
                    <input id="clienteSearchInput" type="text" placeholder="Buscar pelo nome do cliente..." autocomplete="off"
                           style="width:100%;padding:10px 12px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.85rem;box-sizing:border-box">
                  </div>
                  <div id="clienteListaModal" style="overflow-y:auto;flex:1;padding:0 12px 12px"></div>
                </div>
              </div>
              <div id="proc_card_link" style="display:none;margin-top:8px">
                <a id="proc_card_link_btn" href="/sistema_vendas/public/prospeccao.php" style="font-size:.82rem;color:#60a5fa">Ver ficha do cliente na Prospecção →</a>
              </div>
            </div>
          </div>

          <!-- Bloco — Prazos Processuais -->
          <div class="proc-section">
            <div class="proc-section-title">Prazos Processuais</div>
            <div class="proc-section-body">
              <div id="prazosContainer" style="display:flex;flex-direction:column;gap:10px;margin-bottom:10px"><div style="color:#9ab0c9;font-size:.8rem">Salve o processo primeiro para adicionar prazos</div></div>
              <button type="button" id="btnAddPrazo" style="width:100%;padding:9px;border-radius:8px;background:transparent;border:1px dashed rgba(96,165,250,.3);color:#60a5fa;cursor:pointer;font-size:.82rem">+ Adicionar Prazo</button>
            </div>
          </div>

          <!-- Bloco — Tarefas Processuais -->
          <div class="proc-section">
            <div class="proc-section-title">Tarefas Processuais</div>
            <div class="proc-section-body">
              <div style="display:flex;gap:8px;margin-bottom:6px;flex-wrap:wrap">
                <input id="novaTarefa" placeholder="Nova tarefa..." style="flex:1;min-width:140px;padding:9px 12px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.82rem;color-scheme:dark">
                <input type="date" id="novaTarefaData" style="flex:0 0 140px;padding:9px 10px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.82rem;color-scheme:dark;font-family:inherit">
                <select id="novaTarefaResp" style="flex:0 0 160px;padding:9px 10px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.82rem">
                  <option value="">— Responsável —</option>
                </select>
                <button type="button" id="btnAddTarefa" style="padding:9px 16px;border-radius:8px;background:rgba(37,99,235,.25);border:1px solid rgba(96,165,250,.3);color:#93c5fd;cursor:pointer;font-size:.82rem">Adicionar</button>
              </div>
              <div id="tarefasProgress" style="font-size:.78rem;color:#9ab0c9;margin-bottom:4px">0% concluído (0/0)</div>
              <div style="height:4px;background:rgba(255,255,255,.08);border-radius:4px;margin-bottom:10px">
                <div id="tarefasProgressBar" style="height:100%;background:#3b82f6;border-radius:4px;width:0%;transition:width .3s"></div>
              </div>
              <div id="tarefasContainer" style="display:flex;flex-direction:column;gap:6px"></div>
            </div>
          </div>

          <!-- Bloco — Histórico Processual -->
          <div class="proc-section">
            <div class="proc-section-title">Histórico Processual</div>
            <div id="processoHistoryList" style="max-height:220px;overflow-y:auto;display:flex;flex-direction:column"></div>
          </div>

          <div class="proc-modal-footer" style="padding:14px 0 0;border-top:none;background:transparent;margin-top:4px">
            <button type="button" id="cancelProcess" class="proc-btn-cancel">Cancelar</button>
            <button type="submit" class="proc-btn-save">Salvar Processo</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal para adicionar prazo (substitui prompt nativo) -->
  <div id="modalAddPrazo" style="display:none;position:fixed;inset:0;background:rgba(2,6,23,.75);z-index:2000;align-items:center;justify-content:center">
    <div style="background:linear-gradient(165deg,rgba(10,24,46,.99),rgba(7,18,36,.99));border:1px solid rgba(96,165,250,.25);border-radius:14px;padding:24px;width:460px;max-width:95vw;box-shadow:0 20px 50px rgba(0,0,0,.7)">
      <div style="font-size:1rem;font-weight:700;color:#dbeafe;margin-bottom:18px">Novo Prazo Processual</div>
      <div style="display:flex;flex-direction:column;gap:12px">
        <div>
          <label style="font-size:.78rem;color:#9ab0c9;display:block;margin-bottom:4px">Descrição *</label>
          <input id="addPrazoDesc" placeholder="Ex: Audiência de instrução, Prazo recursal..." style="width:100%;padding:10px 12px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.85rem;box-sizing:border-box">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label style="font-size:.78rem;color:#9ab0c9;display:block;margin-bottom:4px">Data limite *</label>
            <input id="addPrazoData" type="date" style="width:100%;padding:10px 12px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.85rem;box-sizing:border-box">
          </div>
          <div>
            <label style="font-size:.78rem;color:#9ab0c9;display:block;margin-bottom:4px">Prioridade</label>
            <select id="addPrazoPrio" style="width:100%;padding:10px 12px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.85rem;box-sizing:border-box">
              <option value="baixa">Baixa</option>
              <option value="media" selected>Média</option>
              <option value="alta">Alta</option>
            </select>
          </div>
        </div>
        <div>
          <label style="font-size:.78rem;color:#9ab0c9;display:block;margin-bottom:4px">Responsável</label>
          <select id="addPrazoResp" style="width:100%;padding:10px 12px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.85rem;box-sizing:border-box">
            <option value="">— Selecionar responsável —</option>
          </select>
        </div>
        <div>
          <label style="font-size:.78rem;color:#9ab0c9;display:block;margin-bottom:4px">Observação</label>
          <input id="addPrazoObs" placeholder="Observação interna (opcional)" style="width:100%;padding:10px 12px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.85rem;box-sizing:border-box">
        </div>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px">
        <button id="addPrazoCancel" style="padding:0 18px;height:38px;border-radius:8px;border:1px solid rgba(96,165,250,.3);background:transparent;color:#93c5fd;cursor:pointer;font-size:.85rem">Cancelar</button>
        <button id="addPrazoConfirm" style="padding:0 20px;height:38px;border-radius:8px;border:none;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-weight:700;cursor:pointer;font-size:.85rem">Salvar Prazo</button>
      </div>
    </div>
  </div>

  <script>
    // Usuários pré-carregados via PHP — disponível imediatamente sem fetch async
    window._SYSTEM_USERS = <?= json_encode($system_users, JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="/sistema_vendas/public/assets/processos.js?v=<?= filemtime(__DIR__ . '/assets/processos.js') ?>"></script>
  <script src="/sistema_vendas/public/assets/process_codes.js"></script>
  <script src="/sistema_vendas/public/assets/fog.js"></script>
  <script>
    // Auto-abre processo se URL tiver ?open=ID (vindo de outra aba do sistema)
    // Também suporta ?new_card_id=ID para criar novo processo já vinculado a um cliente
    document.addEventListener('DOMContentLoaded', () => {
      const params   = new URLSearchParams(location.search);
      const openId   = params.get('open');
      const newCardId = params.get('new_card_id');
      setTimeout(() => { // aguarda processos.js inicializar
        if (openId) {
          fetch(`/sistema_vendas/public/api/processes.php?id=${openId}`, {credentials:'same-origin'})
            .then(r => r.json())
            .then(data => { const proc = data.data || data; if (proc && proc.id) showModal(proc); })
            .catch(() => {});
        } else if (newCardId) {
          // Abre modal de novo processo com o cliente pré-selecionado
          showModal(null);
          setTimeout(() => {
            const hidden = document.getElementById('proc_card_id');
            if (hidden) hidden.value = newCardId;
            // Carrega o nome do cliente e pré-seleciona no campo de busca
            if (window._cardsData && window._cardsData.length) {
              const card = window._cardsData.find(c => String(c.id) === String(newCardId));
              if (card) {
                window._applyCardSelection(card);
              }
            } else {
              fetch('/sistema_vendas/public/api/cards.php?id=' + newCardId, {credentials:'same-origin'})
                .then(r => r.json())
                .then(d => {
                  const card = d.data || d;
                  if (card && card.id) {
                    if (!window._cardsData) window._cardsData = [];
                    window._cardsData.push(card);
                    window._applyCardSelection(card);
                  }
                }).catch(() => {});
            }
          }, 100);
        }
      }, 800);
    });
    // Modal X close button
    document.addEventListener('DOMContentLoaded', function(){
      var x = document.getElementById('modalCloseX');
      if (x) x.addEventListener('click', function(){
        var m = document.getElementById('modalProcess');
        if (m) m.classList.add('hidden');
      });
    });
    // Dashboard status mirror
    try {
      var saved = localStorage.getItem('dashboard_last_update');
      if (saved) {
        var el = document.getElementById('dashboardStatus');
        if (el) { el.textContent = saved; el.style.color = '#4ade80'; }
      }
      window.addEventListener('storage', function(e){
        if (!e || e.key !== 'dashboard_last_update') return;
        var el = document.getElementById('dashboardStatus');
        if (el) { el.textContent = e.newValue; el.style.color = '#4ade80'; }
      });
    } catch(e) { console.warn('mirror dashboard status failed', e); }
  </script>

  <div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>

  <!-- ── Modal editar tarefa processual ── -->
  <div id="modalEditTarefa" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(2,6,23,.72);backdrop-filter:blur(4px);align-items:center;justify-content:center">
    <div style="background:linear-gradient(165deg,rgba(10,24,46,.99),rgba(7,18,36,.99));border:1px solid rgba(96,165,250,.22);border-radius:14px;padding:22px 24px;width:380px;max-width:95vw;box-shadow:0 24px 60px rgba(2,6,23,.7)">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
        <span style="font-size:.95rem;font-weight:700;color:#dbeafe">Editar Tarefa</span>
        <button onclick="document.getElementById('modalEditTarefa').style.display='none'" style="background:transparent;border:none;color:#7a8898;cursor:pointer;font-size:1.1rem;line-height:1;padding:2px 6px">✕</button>
      </div>
      <input type="hidden" id="etId">
      <div style="display:flex;flex-direction:column;gap:12px">
        <div class="field-group">
          <label class="field-label">Descrição</label>
          <textarea id="etTitulo" class="field-input" placeholder="Descreva a tarefa..." rows="3" style="resize:vertical;min-height:80px;line-height:1.5"></textarea>
        </div>
        <div class="field-group">
          <label class="field-label">Data</label>
          <input type="date" id="etData" class="field-input" style="color-scheme:dark">
        </div>
        <div class="field-group">
          <label class="field-label">Responsável</label>
          <select id="etResp" class="field-input">
            <option value="">— Sem responsável —</option>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
        <button onclick="document.getElementById('modalEditTarefa').style.display='none'"
          style="padding:0 18px;height:36px;border-radius:8px;border:1px solid rgba(96,165,250,.25);background:transparent;color:#93c5fd;font-family:inherit;font-size:.83rem;font-weight:600;cursor:pointer;transition:background .15s"
          onmouseover="this.style.background='rgba(37,99,235,.15)'" onmouseout="this.style.background='transparent'">Cancelar</button>
        <button onclick="window._salvarEdicaoTarefa()"
          style="padding:0 20px;height:36px;border-radius:8px;border:none;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-family:inherit;font-size:.83rem;font-weight:600;cursor:pointer;box-shadow:0 4px 14px rgba(37,99,235,.35);transition:transform .15s,box-shadow .15s"
          onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 6px 18px rgba(37,99,235,.5)'" onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(37,99,235,.35)'">Salvar</button>
      </div>
    </div>
  </div>

</body>
</html>

