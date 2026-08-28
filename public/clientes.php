<?php
/**
 * clientes.php — Módulo Clientes (Operações).
 *
 * Base real de clientes do escritório (separada da Prospecção/leads).
 * Padrão visual e UX espelham a Prospecção:
 *   - Faixa de origem no card (top=matriz, bottom=filial)
 *   - Filtro "Origem" (Matriz / Filial X / Todos) — só matriz vê
 *   - Kanban com setores como colunas + coluna "Outros" pra cards de filial
 *     com setor sem correspondência por nome no kanban da matriz
 *   - Setores são por tenant (matriz cria os dela, filial cria os dela)
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Clientes\ClienteSetor;
use App\Core\AccountContext;
use App\Core\UserOptions;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

// Permission gate
$_isAdmin = strtolower((string)($_SESSION['user_perfil'] ?? '')) === 'admin';
$_perms   = (array)($_SESSION['user_permissions'] ?? []);
if (!$_isAdmin && !in_array('*', $_perms, true) && !in_array('clientes', $_perms, true)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>403</title>';
    echo '<style>body{font-family:system-ui;background:#0a0f1e;color:#cbd5e1;display:grid;place-items:center;height:100vh;margin:0}</style>';
    echo '<div><h1 style="margin:0 0 8px">Acesso negado</h1>';
    echo '<p>Você não tem permissão para acessar Clientes. Solicite ao administrador.</p></div>';
    exit;
}

$activePage = 'clientes';
$csrf       = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$accountId  = $ctx->getAccountId();
$isMatriz   = $ctx->isMatriz();

// Setores do tenant (cada conta tem os seus — sem herança)
$setores = ClienteSetor::listAll(['account_ids' => [$accountId]]);

// Usuários acessíveis (matriz + filiais vinculadas se matriz)
$users = [];
try { $users = $ctx->getAccessibleUsers(); } catch (\Throwable $e) {}

// Origem dropdown — matriz com filiais vinculadas (via account_vinculos).
// FIX: query antiga usava matriz_id direto na tabela accounts, mas o vínculo
// real no YURIS é via account_vinculos. Usamos getAccessibleAccountIds que
// já sabe ler dos dois lugares (canônico).
$origin_accounts = [];
if ($isMatriz) {
    try {
        $accessibleIds = $ctx->getAccessibleAccountIds('clientes');
        if (count($accessibleIds) > 1) {
            $pdo = Database::getConnection();
            $placeholders = implode(',', array_fill(0, count($accessibleIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, nome, tipo
                   FROM accounts
                  WHERE id IN ($placeholders)
                    AND deleted_at IS NULL
                    AND status IN ('active','trial','overdue')
               ORDER BY CASE WHEN tipo = 'matriz' THEN 0 ELSE 1 END, nome ASC"
            );
            $stmt->execute(array_map('intval', $accessibleIds));
            $origin_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (\Throwable $e) {}
}
$showOrigemFilter = $isMatriz && count($origin_accounts) > 1;
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Clientes — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32"  href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.14.0/Sortable.min.js"></script>
  <style>
    :root {
      --bg-main: #070F1C;
      --panel: #0D1C30;
      --panel-2: #112742;
      --text: #E5EEF8;
      --muted: #94a3b8;
      --primary: #3b82f6;
      --ok: #22c55e;
      --warn: #f59e0b;
      --danger: #ef4444;
      --border: rgba(96,165,250,0.18);
    }
    body { background: var(--bg-main); color: var(--text); margin: 0; font-family: 'Inter', system-ui, sans-serif; }
    html[data-theme="light"] body { background: #f6f8fc; color: #0f172a; }

    /* Layout matches Prospecção/Tarefas: padding externo de 24px (= Tailwind px-6 py-6)
       envolvendo sidebar+main, com gap entre eles. Padrão canônico do app. */
    .layout {
      display: flex;
      min-height: 100vh;
      padding: 24px;
      gap: 24px;
      box-sizing: border-box;
    }
    .main {
      flex: 1;
      padding: 0 0 60px;         /* padding interno zerado — layout já dá 24px externo */
      max-width: 100%;
      overflow-x: hidden;
      min-width: 0;
    }

    .page-header { margin-bottom: 18px; }
    .page-header h1 { margin: 0 0 6px; font-size: 1.6rem; font-weight: 700; letter-spacing: -.01em; }
    .page-header p  { margin: 0; color: var(--muted); font-size: .92rem; }

    .card-shell {
      background: linear-gradient(180deg, var(--panel) 0%, var(--panel-2) 100%);
      border: 1px solid var(--border); border-radius: 14px;
      padding: 14px 16px; margin-bottom: 14px;
    }
    html[data-theme="light"] .card-shell {
      background: #ffffff; border-color: #e2e8f0;
      box-shadow: 0 1px 2px rgba(15,23,42,.04);
    }

    /* ── Toolbar ──────────────────────────────────────────────────── */
    /* Padrão espelhado da Prospecção: botões à esquerda (compactos) +
       filtros em grid auto-fit à direita, com rótulos acima. */
    .toolbar-grid {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 14px;
      align-items: end;
    }
    .action-buttons {
      display: flex; flex-wrap: wrap; gap: 8px; align-self: end;
    }
    .action-buttons .btn { white-space: nowrap; }

    .filters-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
      gap: 10px;
    }
    .field-group {
      display: flex; flex-direction: column; gap: 5px;
      min-width: 0;   /* permite shrink dentro do grid */
    }
    .field-label {
      color: #cadff9; font-size: .74rem; font-weight: 500;
      letter-spacing: .01em;
    }
    html[data-theme="light"] .field-label { color: #475569; }

    .toolbar-foot {
      margin-top: 12px; padding-top: 10px;
      border-top: 1px dashed var(--border);
      display: flex; justify-content: flex-end;
    }

    @media (max-width: 1100px) {
      .toolbar-grid { grid-template-columns: 1fr; }   /* botões em cima, filtros embaixo */
    }
    @media (max-width: 560px) {
      .filters-grid { grid-template-columns: 1fr; }   /* mobile: 1 filtro por linha */
    }

    .btn {
      background: linear-gradient(180deg, #3b82f6, #2563eb);
      color: #fff; border: 0; border-radius: 10px;
      padding: 9px 14px; font-weight: 600; font-size: .88rem;
      cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
      transition: filter .12s;
    }
    .btn:hover { filter: brightness(1.08); }
    .btn:disabled { opacity: .5; cursor: not-allowed; }
    .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text); }
    html[data-theme="light"] .btn-ghost { border-color: #cbd5e1; color: #0f172a; }
    .btn-ghost:hover { background: rgba(96,165,250,.06); }
    .btn-danger { background: linear-gradient(180deg, #ef4444, #dc2626); }

    .form-input, .form-select {
      background: rgba(15,30,55,.55); border: 1px solid var(--border);
      border-radius: 9px; padding: 8px 11px; color: var(--text);
      font-size: .88rem; min-width: 0;
    }
    html[data-theme="light"] .form-input, html[data-theme="light"] .form-select {
      background: #fff; border-color: #cbd5e1; color: #0f172a;
    }
    .form-input:focus, .form-select:focus { outline: 2px solid rgba(59,130,246,.45); outline-offset: 1px; }
    .form-input::placeholder { color: var(--muted); }
    .search-wrap { position: relative; width: 100%; }
    .search-wrap .form-input { width: 100%; padding-left: 32px; }
    .search-wrap svg { position: absolute; left: 9px; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none; }

    /* Tamanho consistente de form controls em filtros (alinhamento vertical) */
    .filters-grid .form-input,
    .filters-grid .form-select { width: 100%; min-height: 38px; }

    .meta-line { margin-left: auto; color: var(--muted); font-size: .8rem; }

    /* ── Kanban ───────────────────────────────────────────────────── */
    .board {
      display: flex; gap: 14px; overflow-x: auto;
      padding: 4px 2px 16px;
      scroll-snap-type: x proximity;
    }
    .setor-col {
      min-width: 320px; max-width: 320px; flex-shrink: 0;
      background: rgba(15,30,55,.55);
      border: 1px solid var(--border); border-radius: 14px;
      padding: 12px; display: flex; flex-direction: column;
      scroll-snap-align: start;
      max-height: calc(100vh - 240px);
    }
    .setor-col.outros {
      background: rgba(245,158,11,.06);
      border-color: rgba(245,158,11,.35);
    }
    html[data-theme="light"] .setor-col { background: #f8fafc; border-color: #e2e8f0; }
    html[data-theme="light"] .setor-col.outros { background: #fffbeb; border-color: #fcd34d; }

    .setor-head {
      display: flex; align-items: center; gap: 8px;
      padding: 4px 6px 10px; border-bottom: 1px dashed var(--border); margin-bottom: 10px;
      flex-shrink: 0;
    }
    .setor-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .setor-name { font-weight: 700; font-size: .92rem; }
    .setor-count {
      margin-left: auto; font-size: .72rem; color: var(--muted);
      background: rgba(148,163,184,.12); padding: 2px 8px; border-radius: 999px;
    }
    .setor-body {
      flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 8px;
      min-height: 80px; padding-right: 2px;
    }
    .setor-empty {
      text-align: center; color: var(--muted); font-size: .78rem;
      padding: 18px 8px; border: 1px dashed var(--border); border-radius: 10px;
    }
    html[data-theme="light"] .setor-empty { border-color: #e2e8f0; color: #94a3b8; }

    /* ── Card ─────────────────────────────────────────────────────── */
    .cli-card {
      position: relative;
      background: linear-gradient(180deg, #122a4a 0%, #0f2238 100%);
      border: 1px solid var(--border); border-radius: 11px;
      padding: 10px 12px 10px 12px;
      cursor: grab; transition: transform .1s, box-shadow .1s, border-color .15s;
      overflow: hidden;
    }
    html[data-theme="light"] .cli-card {
      background: #ffffff; border-color: #e2e8f0;
      box-shadow: 0 1px 2px rgba(15,23,42,.05);
    }
    .cli-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,.18); border-color: rgba(96,165,250,.35); }
    .cli-card.is-readonly { cursor: pointer; opacity: .92; }
    .cli-card.is-readonly:hover { box-shadow: 0 4px 10px rgba(245,158,11,.18); border-color: rgba(245,158,11,.4); }
    .cli-card.sortable-ghost { opacity: .35; }

    /* Faixa de Origem — SEMPRE TOP, espelha o padrão da Prospecção.
       Mostra MATRIZ/FILIAL à esquerda + nome da conta à direita. */
    .cli-card[data-origin-tipo] { padding-top: 28px; }
    .cli-card .origin-strip {
      position: absolute; left: 0; right: 0; top: 0; height: 22px;
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 10px; font-size: .68rem; font-weight: 700;
      letter-spacing: .06em; text-transform: uppercase;
      border-top-left-radius: 10px; border-top-right-radius: 10px;
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    .cli-card .origin-strip.is-matriz {
      background: linear-gradient(90deg, rgba(37,99,235,.32), rgba(37,99,235,.16));
      color: #93c5fd;
    }
    .cli-card .origin-strip.is-filial {
      background: linear-gradient(90deg, rgba(168,85,247,.32), rgba(168,85,247,.14));
      color: #d8b4fe;
    }
    .cli-card .origin-strip.is-advogado {
      background: linear-gradient(90deg, rgba(16,185,129,.32), rgba(16,185,129,.14));
      color: #6ee7b7;
    }
    .cli-card .origin-strip .org-name {
      font-weight: 600; text-transform: none; letter-spacing: 0; opacity: .9;
      max-width: 60%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    html[data-theme="light"] .cli-card .origin-strip.is-matriz {
      background: linear-gradient(90deg, rgba(37,99,235,.22), rgba(37,99,235,.10));
      color: #1e40af;
    }
    html[data-theme="light"] .cli-card .origin-strip.is-filial {
      background: linear-gradient(90deg, rgba(126,34,206,.22), rgba(126,34,206,.10));
      color: #6b21a8;
    }
    html[data-theme="light"] .cli-card .origin-strip.is-advogado {
      background: linear-gradient(90deg, rgba(5,150,105,.22), rgba(5,150,105,.10));
      color: #065f46;
    }

    .cli-name { font-weight: 700; font-size: .92rem; line-height: 1.25; margin: 2px 0 4px; padding-right: 4px; word-break: break-word; }
    .cli-meta { display: flex; flex-wrap: wrap; gap: 6px 10px; font-size: .74rem; color: var(--muted); }
    .cli-meta span { display: inline-flex; align-items: center; gap: 4px; }
    .cli-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
    .chip {
      font-size: .68rem; font-weight: 600;
      padding: 2px 8px; border-radius: 999px;
      display: inline-flex; align-items: center; gap: 4px;
    }
    .chip-setor { background: rgba(99,102,241,.18); color: #a5b4fc; }
    .chip-origin-matriz { background: rgba(59,130,246,.18); color: #93c5fd; }
    .chip-origin-filial { background: rgba(245,158,11,.18); color: #fcd34d; }
    .chip-status-ativo     { background: rgba(34,197,94,.18); color: #86efac; }
    .chip-status-pausado   { background: rgba(245,158,11,.18); color: #fcd34d; }
    .chip-status-arquivado { background: rgba(148,163,184,.18); color: #cbd5e1; }
    html[data-theme="light"] .chip-setor { background: #eef2ff; color: #4f46e5; }
    html[data-theme="light"] .chip-origin-matriz { background: #dbeafe; color: #1e40af; }
    html[data-theme="light"] .chip-origin-filial { background: #fef3c7; color: #92400e; }
    html[data-theme="light"] .chip-status-ativo     { background: #dcfce7; color: #166534; }
    html[data-theme="light"] .chip-status-pausado   { background: #fef3c7; color: #92400e; }
    html[data-theme="light"] .chip-status-arquivado { background: #f1f5f9; color: #475569; }

    /* ── Modal ────────────────────────────────────────────────────── */
    .modal-shell {
      display: none; position: fixed; inset: 0; z-index: 1000;
      background: rgba(7,15,28,.78); backdrop-filter: blur(4px);
      align-items: flex-start; justify-content: center;
      padding: 4vh 16px;
      overflow-y: auto;
    }
    .modal-shell.open { display: flex; }
    .modal-panel {
      width: 100%; max-width: 720px;
      background: linear-gradient(180deg, var(--panel) 0%, var(--panel-2) 100%);
      border: 1px solid var(--border); border-radius: 16px;
      padding: 22px 24px; box-shadow: 0 20px 40px rgba(0,0,0,.4);
    }
    .modal-panel.wide { max-width: 880px; }
    html[data-theme="light"] .modal-panel { background: #fff; border-color: #e2e8f0; box-shadow: 0 12px 30px rgba(15,23,42,.18); }
    .modal-head {
      display: flex; align-items: center; gap: 10px;
      margin-bottom: 18px; padding-bottom: 14px;
      border-bottom: 1px solid var(--border);
    }
    .modal-head h2 { margin: 0; font-size: 1.15rem; font-weight: 700; }
    .modal-head .close {
      margin-left: auto; background: transparent; border: 0; color: var(--muted);
      width: 32px; height: 32px; border-radius: 8px; cursor: pointer;
      display: grid; place-items: center;
    }
    .modal-head .close:hover { background: rgba(148,163,184,.12); color: var(--text); }

    .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px 14px; }
    .form-grid .full { grid-column: 1 / -1; }
    .field label { display: block; font-size: .78rem; font-weight: 600; color: var(--muted); margin-bottom: 4px; letter-spacing: .02em; text-transform: uppercase; }
    .field .form-input, .field .form-select { width: 100%; }
    .field textarea.form-input { resize: vertical; min-height: 70px; font-family: inherit; }

    .modal-foot {
      display: flex; gap: 10px; justify-content: flex-end;
      margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border);
    }

    /* Setores manager list */
    .setor-row {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px;
      background: rgba(15,30,55,.4); margin-bottom: 8px;
      cursor: grab;
    }
    html[data-theme="light"] .setor-row { background: #f8fafc; border-color: #e2e8f0; }
    .setor-row.sortable-ghost { opacity: .4; }
    .setor-row .drag-handle { color: var(--muted); cursor: grab; }
    .setor-row .color-picker {
      width: 28px; height: 28px; border-radius: 8px; cursor: pointer; flex-shrink: 0;
      border: 1px solid var(--border); padding: 0;
    }
    .setor-row .nome-input {
      flex: 1; background: transparent; border: 0; color: var(--text);
      font-weight: 600; font-size: .92rem; padding: 4px 6px; border-radius: 6px;
      min-width: 0;
    }
    html[data-theme="light"] .setor-row .nome-input { color: #0f172a; }
    .setor-row .nome-input:focus { outline: 2px solid rgba(59,130,246,.4); background: rgba(255,255,255,.06); }
    html[data-theme="light"] .setor-row .nome-input:focus { background: #fff; }
    .setor-row .count-badge {
      font-size: .72rem; color: var(--muted);
      background: rgba(148,163,184,.12); padding: 2px 8px; border-radius: 999px;
    }
    .setor-row .archive-btn {
      background: transparent; border: 0; color: var(--muted); padding: 4px 6px;
      cursor: pointer; border-radius: 6px;
    }
    .setor-row .archive-btn:hover { background: rgba(239,68,68,.12); color: var(--danger); }

    /* History */
    .history-list { max-height: 220px; overflow-y: auto; padding-right: 6px; }
    .history-item {
      padding: 8px 10px; border-left: 2px solid var(--border);
      font-size: .82rem; color: var(--muted); margin-bottom: 6px;
    }
    .history-item .acao { color: var(--text); font-weight: 600; }
    .history-item time { display: block; font-size: .7rem; opacity: .7; margin-top: 2px; }

    /* Build-banner é só placeholder de Fase 1, removido na 3 */

    @media (max-width: 768px) {
      .layout { padding: 12px; gap: 12px; }
      .main { padding: 0 0 60px; }
      .form-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body class="theme-yuris">

<div class="layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <header class="page-header">
      <h1>Clientes</h1>
      <p>Organize os clientes do escritório por setor operacional.</p>
    </header>

    <section class="card-shell">
      <div class="toolbar-grid">
        <div class="action-buttons">
          <button type="button" class="btn" id="btnNovoCliente">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Novo Cliente
          </button>
          <button type="button" class="btn btn-ghost" id="btnGerenciarSetores" title="Gerenciar setores">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="14" y2="18"/></svg>
            Gerenciar Setores
          </button>
        </div>

        <div class="filters-grid">
          <label class="field-group">
            <span class="field-label">Busca rápida</span>
            <div class="search-wrap">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="search" id="searchInput" class="form-input" placeholder="Nome, telefone, CPF/CNPJ ou e-mail…">
            </div>
          </label>

          <label class="field-group">
            <span class="field-label">Responsável</span>
            <select id="filterResponsavel" class="form-select">
              <?= UserOptions::renderGrouped($users, null, 'Todos responsáveis') ?>
            </select>
          </label>

          <label class="field-group">
            <span class="field-label">Status</span>
            <select id="filterStatus" class="form-select">
              <option value="">Todos status</option>
              <option value="ativo">Ativo</option>
              <option value="pausado">Pausado</option>
              <option value="arquivado">Arquivado</option>
            </select>
          </label>

          <?php if ($showOrigemFilter): ?>
          <label class="field-group">
            <span class="field-label">Origem</span>
            <?php
              // FIX (auditoria 2026-06-01 — BAIXA #32 / MÉDIA #19): o YURIS tem 3
              // tipos de conta (matriz | filial | advogado). A versão antiga só
              // montava o optgroup com tipo==='filial', deixando advogados
              // vinculados de fora do filtro. Separa filiais de advogados em
              // optgroups distintos (rótulo correto por tipo) e expõe
              // "__advogados__" para isolar só os advogados. "__filiais__" passa
              // a cobrir todas as origens não-matriz (ver applyFilters no JS).
              $filiaisOnly   = array_filter($origin_accounts, fn($a) => $a['tipo'] === 'filial');
              $advogadosOnly = array_filter($origin_accounts, fn($a) => $a['tipo'] === 'advogado');
            ?>
            <select id="filterOrigem" class="form-select">
              <option value="">Todas as origens</option>
              <option value="__matriz__">Apenas Matriz</option>
              <option value="__filiais__">Filiais e Advogados</option>
              <?php if (!empty($advogadosOnly)): ?>
              <option value="__advogados__">Apenas Advogados</option>
              <?php endif; ?>
              <?php if (!empty($filiaisOnly)): ?>
              <optgroup label="Filial específica">
                <?php foreach ($filiaisOnly as $a): ?>
                  <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars((string)$a['nome']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <?php if (!empty($advogadosOnly)): ?>
              <optgroup label="Advogado vinculado">
                <?php foreach ($advogadosOnly as $a): ?>
                  <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars((string)$a['nome']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
            </select>
          </label>
          <?php endif; ?>
        </div>
      </div>

      <div class="toolbar-foot">
        <span class="meta-line" id="metaLine">—</span>
      </div>
    </section>

    <?php if (empty($setores)): ?>
      <div class="card-shell" style="text-align:center; padding:36px;">
        <p style="color:var(--muted); margin:0 0 12px;">
          Nenhum setor configurado ainda. Crie o primeiro setor pra começar.
        </p>
        <button type="button" class="btn" onclick="Clientes.openSetoresModal()">Gerenciar Setores</button>
      </div>
    <?php else: ?>
      <div class="board" id="board"><!-- preenchido por JS --></div>
    <?php endif; ?>
  </main>
</div>

<!-- ─────────────────────────────────────────────────────────────────
     MODAL: Novo / Editar Cliente
─────────────────────────────────────────────────────────────────── -->
<div class="modal-shell" id="modalCliente" role="dialog" aria-modal="true" aria-labelledby="mcliTitle">
  <div class="modal-panel wide">
    <div class="modal-head">
      <h2 id="mcliTitle">Novo Cliente</h2>
      <button type="button" class="close" onclick="Clientes.closeClienteModal()" aria-label="Fechar">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <form id="cliForm" onsubmit="return Clientes.submitCliente(event)">
      <input type="hidden" name="id" id="cliId">

      <div class="form-grid">
        <div class="field full">
          <label for="cliNome">Nome do cliente *</label>
          <input type="text" id="cliNome" name="nome" class="form-input" required maxlength="190" autocomplete="off">
        </div>

        <div class="field">
          <label for="cliTipo">Tipo</label>
          <select id="cliTipo" name="tipo_cliente" class="form-select">
            <option value="PF">Pessoa Física</option>
            <option value="PJ">Pessoa Jurídica</option>
          </select>
        </div>

        <div class="field">
          <label for="cliCpfCnpj">CPF / CNPJ</label>
          <input type="text" id="cliCpfCnpj" name="cpf_cnpj" class="form-input" placeholder="000.000.000-00" maxlength="20">
        </div>

        <div class="field">
          <label for="cliRg">RG</label>
          <input type="text" id="cliRg" name="rg" class="form-input" placeholder="00.000.000-0" maxlength="30">
        </div>

        <div class="field full">
          <label for="cliNomeMae">Nome da mãe</label>
          <input type="text" id="cliNomeMae" name="nome_mae" class="form-input" maxlength="190" autocomplete="off">
        </div>

        <div class="field">
          <label for="cliTelefone">Telefone</label>
          <input type="text" id="cliTelefone" name="telefone" class="form-input" placeholder="(00) 00000-0000" maxlength="30">
        </div>

        <div class="field">
          <label for="cliWhatsapp">WhatsApp</label>
          <input type="text" id="cliWhatsapp" name="whatsapp" class="form-input" placeholder="(00) 00000-0000" maxlength="30">
        </div>

        <div class="field full">
          <label for="cliEmail">E-mail</label>
          <input type="email" id="cliEmail" name="email" class="form-input" placeholder="contato@cliente.com" maxlength="190">
        </div>

        <div class="field">
          <label for="cliOrigem" style="display:flex; align-items:center; gap:6px;">
            <span>Origem do cadastro</span>
            <button type="button" class="gear-btn" title="Gerenciar origens"
                    onclick="Clientes.openOrigensModal()" tabindex="-1"
                    style="background:transparent; border:0; color:var(--muted); cursor:pointer; padding:0; display:inline-flex;">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06A2 2 0 1 1 2.27 17.8l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09c.7 0 1.27-.43 1.51-1a1.65 1.65 0 0 0-.33-1.82l-.06-.06A2 2 0 1 1 6.3 2.27l.06.06c.5.5 1.2.75 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09c0 .7.43 1.27 1 1.51a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c.32.56.32 1.28.32 1.82V12a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </button>
          </label>
          <select id="cliOrigem" name="origem" class="form-select">
            <!-- populado por JS via /api/clientes_origens.php -->
          </select>
        </div>

        <div class="field">
          <label for="cliStatus">Status</label>
          <select id="cliStatus" name="status" class="form-select">
            <option value="ativo">Ativo</option>
            <option value="pausado">Pausado</option>
            <option value="arquivado">Arquivado</option>
          </select>
        </div>

        <div class="field">
          <label for="cliSetor">Setor *</label>
          <select id="cliSetor" name="setor_id" class="form-select" required>
            <?php foreach ($setores as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars((string)$s['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label for="cliResp">Responsável interno</label>
          <select id="cliResp" name="responsavel_id" class="form-select">
            <?= UserOptions::renderGrouped($users, null, '— sem responsável —') ?>
          </select>
        </div>

        <div class="field full">
          <label style="font-size:.78rem; font-weight:600; color:var(--muted); margin: 6px 0 -4px; padding-top:8px; border-top:1px dashed var(--border); text-transform:uppercase; letter-spacing:.04em;">Endereço</label>
        </div>

        <div class="field">
          <label for="cliCep">CEP</label>
          <input type="text" id="cliCep" name="cep" class="form-input" placeholder="00000-000" maxlength="10"
            onblur="Clientes.viaCepLookup(this.value, 'cli')">
          <small id="cliCepStatus" style="display:block; font-size:.7rem; color:var(--muted); margin-top:3px; min-height:1em;"></small>
        </div>

        <div class="field">
          <label for="cliUf">UF</label>
          <select id="cliUf" name="uf" class="form-select">
            <option value="">—</option>
            <?php foreach (['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'] as $uf): ?>
              <option value="<?= $uf ?>"><?= $uf ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field full">
          <label for="cliLogradouro">Logradouro (rua/av./travessa)</label>
          <input type="text" id="cliLogradouro" name="logradouro" class="form-input" maxlength="190" autocomplete="off">
        </div>

        <div class="field">
          <label for="cliNumero">Número</label>
          <input type="text" id="cliNumero" name="numero" class="form-input" placeholder="100 ou S/N" maxlength="20">
        </div>

        <div class="field">
          <label for="cliComplemento">Complemento</label>
          <input type="text" id="cliComplemento" name="complemento" class="form-input" placeholder="Apto 42, Bloco B…" maxlength="100">
        </div>

        <div class="field">
          <label for="cliBairro">Bairro</label>
          <input type="text" id="cliBairro" name="bairro" class="form-input" maxlength="100">
        </div>

        <div class="field">
          <label for="cliCidade">Cidade</label>
          <input type="text" id="cliCidade" name="cidade" class="form-input" maxlength="100">
        </div>

        <div class="field full" style="margin-top:6px; padding-top:8px; border-top:1px dashed var(--border);">
          <label for="cliObs">Observações</label>
          <textarea id="cliObs" name="observacoes" class="form-input" rows="3" maxlength="2000"></textarea>
        </div>
      </div>

      <!-- Processos vinculados (só no modo edição) — reverse lookup do cliente_id -->
      <div id="cliProcessosBlock" style="display:none; margin-top:16px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin:0 0 8px;">
          <h3 style="font-size:.84rem; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); margin:0;">Processos vinculados</h3>
          <div style="display:flex; gap:14px; align-items:center;">
            <a id="cliVincularProcesso" href="#" style="font-size:.78rem; color:#60a5fa; text-decoration:none;">+ Vincular existente</a>
            <a id="cliNovoProcesso" href="#" style="font-size:.78rem; color:#60a5fa; text-decoration:none;">+ Novo processo</a>
          </div>
        </div>
        <div id="cliProcessos" style="display:flex; flex-direction:column; gap:6px;"></div>
      </div>

      <!-- Histórico (só no modo edição) -->
      <div id="cliHistoryBlock" style="display:none; margin-top:16px;">
        <h3 style="font-size:.84rem; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); margin:0 0 8px;">Histórico</h3>
        <div class="history-list" id="cliHistory"></div>
      </div>

      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" id="btnArquivarCliente" style="display:none;" onclick="Clientes.archiveCliente()">Arquivar</button>
        <button type="button" class="btn btn-ghost" onclick="Clientes.closeClienteModal()">Cancelar</button>
        <button type="submit" class="btn" id="btnSalvarCliente">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────
     MODAL: Gerenciar Setores
─────────────────────────────────────────────────────────────────── -->
<div class="modal-shell" id="modalSetores" role="dialog" aria-modal="true" aria-labelledby="msetTitle">
  <div class="modal-panel">
    <div class="modal-head">
      <h2 id="msetTitle">Gerenciar Setores</h2>
      <button type="button" class="close" onclick="Clientes.closeSetoresModal()" aria-label="Fechar">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <p style="color:var(--muted); font-size:.84rem; margin:0 0 14px;">
      Arraste pra reordenar · Clique no nome pra renomear · Clique na cor pra mudar · Lixeira arquiva (não exclui)
    </p>

    <div id="setoresList"><!-- preenchido por JS --></div>

    <div style="display:flex; gap:8px; margin-top:12px;">
      <input type="text" id="novoSetorNome" class="form-input" placeholder="Nome do novo setor" maxlength="190" style="flex:1;">
      <input type="color" id="novoSetorCor" class="form-input" value="#6366f1" style="width:50px; padding:4px;">
      <button type="button" class="btn" onclick="Clientes.createSetor()">Adicionar</button>
    </div>

    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" onclick="Clientes.closeSetoresModal()">Fechar</button>
    </div>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────
     MODAL: Gerenciar Origens
─────────────────────────────────────────────────────────────────── -->
<div class="modal-shell" id="modalOrigens" role="dialog" aria-modal="true" aria-labelledby="morgTitle">
  <div class="modal-panel">
    <div class="modal-head">
      <h2 id="morgTitle">Gerenciar Origens do Cadastro</h2>
      <button type="button" class="close" onclick="Clientes.closeOrigensModal()" aria-label="Fechar">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <p style="color:var(--muted); font-size:.84rem; margin:0 0 14px;">
      Arraste pra reordenar · Clique no nome pra renomear · Lixeira arquiva (recusa se houver clientes usando)
    </p>

    <div id="origensList"><!-- preenchido por JS --></div>

    <div style="display:flex; gap:8px; margin-top:12px;">
      <input type="text" id="novaOrigemNome" class="form-input" placeholder="Nome da nova origem (ex.: LinkedIn, Outdoor)" maxlength="80" style="flex:1;">
      <button type="button" class="btn" onclick="Clientes.createOrigem()">Adicionar</button>
    </div>

    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" onclick="Clientes.closeOrigensModal()">Fechar</button>
    </div>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────
     MODAL: Vincular processo EXISTENTE ao cliente
─────────────────────────────────────────────────────────────────── -->
<div class="modal-shell" id="modalVincularProcesso" role="dialog" aria-modal="true" aria-labelledby="mvpTitle">
  <div class="modal-panel">
    <div class="modal-head">
      <h2 id="mvpTitle">Vincular processo existente</h2>
      <button type="button" class="close" onclick="document.getElementById('modalVincularProcesso').classList.remove('open')" aria-label="Fechar">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <p style="color:var(--muted); font-size:.84rem; margin:0 0 12px;">
      Busque por número, cliente ou parte contrária. Processos da matriz e das filiais vinculadas aparecem agrupados.
    </p>
    <input type="text" id="vincProcSearch" class="form-input" placeholder="Buscar processo…" autocomplete="off" style="width:100%; margin:0 0 10px;">
    <div id="vincProcLista" style="max-height:48vh; overflow-y:auto;"><!-- preenchido por JS --></div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('modalVincularProcesso').classList.remove('open')">Fechar</button>
    </div>
  </div>
</div>

<script>
window.YURIS_CTX = {
    csrf:        <?= json_encode($csrf) ?>,
    accountId:   <?= (int)$accountId ?>,
    isMatriz:    <?= $isMatriz ? 'true' : 'false' ?>,
    showOrigem:  <?= $showOrigemFilter ? 'true' : 'false' ?>
};

window.Clientes = (function () {
    'use strict';

    // ── Estado ────────────────────────────────────────────────────
    const state = {
        setores: [],       // setores do tenant (colunas do kanban)
        origens: [],       // origens do cadastro (lista editável)
        clientes: [],      // clientes (matriz + filiais se matriz)
        usersMap: {},      // id -> nome
        sortables: {},     // colId -> Sortable instance
        editingId: null,   // cliente sendo editado (null = novo)
        currentCliente: null,
    };

    // Mapa de usuários (preenchido a partir do DOM do <select>)
    document.querySelectorAll('#filterResponsavel option').forEach(o => {
        if (o.value) state.usersMap[o.value] = o.textContent;
    });

    // ── Helpers ───────────────────────────────────────────────────
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }
    function csrfHeaders(extra) {
        return Object.assign({
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': window.YURIS_CTX.csrf
        }, extra || {});
    }
    function fmtPhone(s) {
        if (!s) return '';
        const d = String(s).replace(/\D/g, '');
        if (d.length === 11) return `(${d.slice(0,2)}) ${d.slice(2,7)}-${d.slice(7)}`;
        if (d.length === 10) return `(${d.slice(0,2)}) ${d.slice(2,6)}-${d.slice(6)}`;
        if (d.length === 13 && d.startsWith('55')) return `+${d.slice(0,2)} (${d.slice(2,4)}) ${d.slice(4,9)}-${d.slice(9)}`;
        return s;
    }
    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(s.replace(' ', 'T'));
        if (isNaN(d)) return s;
        return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
    }
    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
    }
    function notify(msg, type) {
        if (window.Yuris && typeof window.Yuris.notify === 'function') {
            window.Yuris.notify(msg, type || 'info');
        } else {
            console.log('[NOTIFY]', type, msg);
        }
    }
    function slugify(s) {
        return String(s || '').toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    // ── Carga inicial ─────────────────────────────────────────────
    async function loadAll() {
        try {
            const [resSet, resCli, resOrg] = await Promise.all([
                fetch('/api/clientes_setores.php', { credentials: 'same-origin' }),
                fetch('/api/clientes.php',         { credentials: 'same-origin' }),
                fetch('/api/clientes_origens.php', { credentials: 'same-origin' })
            ]);
            const setData = await resSet.json();
            const cliData = await resCli.json();
            const orgData = await resOrg.json();

            state.setores  = Array.isArray(setData.data) ? setData.data : [];
            state.clientes = Array.isArray(cliData.data) ? cliData.data : [];
            state.origens  = Array.isArray(orgData.data) ? orgData.data : [];

            populateOrigemSelect();
            renderBoard();
            updateMetaLine();
        } catch (e) {
            console.error('[Clientes] loadAll error', e);
            notify('Falha ao carregar dados', 'error');
        }
    }

    /**
     * Popula o <select id="cliOrigem"> com state.origens.
     * @param {string} [preserveValue] slug a manter selecionado (opcional, default = valor atual)
     */
    function populateOrigemSelect(preserveValue) {
        const sel = $('#cliOrigem');
        if (!sel) return;
        const target = (preserveValue !== undefined) ? preserveValue : sel.value;
        const opts = ['<option value="">— Selecionar —</option>'];
        state.origens.forEach(o => {
            opts.push(`<option value="${escapeHtml(o.slug)}">${escapeHtml(o.nome)}</option>`);
        });
        // Se há valor a preservar mas não está mais na lista (origem renomeada/arquivada
        // ou slug legado pré-migration), adiciona "fantasma" pra não perder o vínculo.
        if (target && !state.origens.some(o => o.slug === target)) {
            opts.push(`<option value="${escapeHtml(target)}">${escapeHtml(target)} (origem inativa)</option>`);
        }
        sel.innerHTML = opts.join('');
        if (target) sel.value = target;
    }

    // ── Render do kanban ──────────────────────────────────────────
    function renderBoard() {
        const board = $('#board');
        if (!board) return;

        const filtered = applyFilters(state.clientes);

        // Agrupa clientes por coluna efetiva (setor da matriz por nome-match; "Outros" pra resto)
        const byCol = {};
        const outros = [];
        const matrizSetorByName = {};
        state.setores.forEach(s => {
            byCol[s.id] = [];
            matrizSetorByName[String(s.nome).trim().toLowerCase()] = s.id;
        });

        filtered.forEach(c => {
            const isOwn = (parseInt(c.account_id, 10) === window.YURIS_CTX.accountId);
            if (isOwn) {
                if (byCol[c.setor_id]) byCol[c.setor_id].push(c);
                else outros.push(c);
            } else {
                // Filial: tenta encontrar coluna da matriz pelo NOME do setor original
                const key = String(c.setor_nome || '').trim().toLowerCase();
                const matchId = matrizSetorByName[key];
                if (matchId) byCol[matchId].push(c);
                else outros.push(c);
            }
        });

        // Limpa instâncias Sortable antigas
        Object.values(state.sortables).forEach(s => { try { s.destroy(); } catch (e) {} });
        state.sortables = {};

        // Renderiza colunas dos setores
        const html = [];
        state.setores.forEach(s => {
            const items = byCol[s.id] || [];
            html.push(renderColumn(s, items, false));
        });
        // Coluna especial "Outros" — só aparece quando há cards
        if (outros.length > 0) {
            html.push(renderColumn({
                id: '__outros__', nome: 'Outros',
                cor: '#f59e0b'
            }, outros, true));
        }
        board.innerHTML = html.join('');

        // Re-instancia Sortable
        state.setores.forEach(s => {
            const body = $(`.setor-col[data-setor-id="${s.id}"] .setor-body`);
            if (!body) return;
            state.sortables[s.id] = new Sortable(body, {
                group: 'clientes-kanban',
                animation: 140,
                ghostClass: 'sortable-ghost',
                filter: '.is-readonly',                  // não arrasta cards de filial
                preventOnFilter: false,
                onEnd: onCardDrop,
            });
        });
        // "Outros" não é Sortable (read-only)
    }

    function renderColumn(setor, items, isOutros) {
        const cor = escapeHtml(setor.cor || '#6366f1');
        return `
            <section class="setor-col${isOutros ? ' outros' : ''}" data-setor-id="${setor.id}">
              <header class="setor-head">
                <span class="setor-dot" style="background:${cor}"></span>
                <span class="setor-name">${escapeHtml(setor.nome)}</span>
                <span class="setor-count">${items.length}</span>
              </header>
              <div class="setor-body" data-col="${setor.id}">
                ${items.length === 0
                    ? `<div class="setor-empty">${isOutros
                        ? 'Cards de filial sem setor equivalente na matriz aparecem aqui.'
                        : 'Sem clientes neste setor.'}</div>`
                    : items.map(c => renderCard(c)).join('')}
              </div>
            </section>`;
    }

    function renderCard(c) {
        const isOwn      = (parseInt(c.account_id, 10) === window.YURIS_CTX.accountId);
        const origemTipo = String(c.origin_account_tipo || '').toLowerCase();  // 'matriz' | 'filial' | ''
        const origemNome = String(c.origin_account_nome || '');

        // Faixa de origem: SEMPRE no topo (matriz=azul, filial=roxo), com label + nome
        const stripCls    = origemTipo === 'matriz' ? 'is-matriz'
                          : origemTipo === 'advogado' ? 'is-advogado'
                          : 'is-filial';
        // Label reflete o TIPO real da conta — conta solo é 'advogado', nunca 'filial'.
        const stripLabel  = origemTipo === 'matriz'   ? 'MATRIZ'
                          : origemTipo === 'advogado' ? 'ADVOGADO'
                          : 'FILIAL';
        const stripHtml   = origemTipo ? `
            <div class="origin-strip ${stripCls}">
              <span class="org-label">${stripLabel}</span>
              <span class="org-name" title="${escapeHtml(origemNome)}">${escapeHtml(origemNome)}</span>
            </div>` : '';

        // Setor original (só mostra se vier de outra conta e não houver match por nome)
        const matrizSetorByName = {};
        state.setores.forEach(s => matrizSetorByName[String(s.nome).trim().toLowerCase()] = s.id);
        const setorMatch        = matrizSetorByName[String(c.setor_nome || '').trim().toLowerCase()];
        const showSetorOriginal = !isOwn && !setorMatch && c.setor_nome;

        const status     = (c.status || 'ativo').toLowerCase();
        const statusChip = `<span class="chip chip-status-${escapeHtml(status)}">${escapeHtml(status)}</span>`;

        return `
            <article class="cli-card${isOwn ? '' : ' is-readonly'}"
                     data-cli-id="${c.id}" data-account="${c.account_id}"
                     ${origemTipo ? `data-origin-tipo="${origemTipo}"` : ''}
                     onclick="Clientes.openEditModal(${c.id})">
              ${stripHtml}
              <div class="cli-name">${escapeHtml(c.nome || '—')}</div>
              <div class="cli-meta">
                ${c.whatsapp ? `<span>📱 ${escapeHtml(fmtPhone(c.whatsapp))}</span>` : (c.telefone ? `<span>☎ ${escapeHtml(fmtPhone(c.telefone))}</span>` : '')}
                ${c.cpf_cnpj ? `<span>${escapeHtml(c.cpf_cnpj)}</span>` : ''}
                ${c.responsavel_nome ? `<span>👤 ${escapeHtml(c.responsavel_nome)}</span>` : ''}
              </div>
              <div class="cli-chips">
                ${statusChip}
                ${showSetorOriginal ? `<span class="chip chip-setor">Setor: ${escapeHtml(c.setor_nome)}</span>` : ''}
              </div>
            </article>`;
    }

    function updateMetaLine() {
        const meta = $('#metaLine');
        if (!meta) return;
        const total = state.clientes.length;
        const filteredCount = applyFilters(state.clientes).length;
        meta.textContent = `${state.setores.length} setor${state.setores.length === 1 ? '' : 'es'} · ${filteredCount} de ${total} cliente${total === 1 ? '' : 's'}`;
    }

    // ── Filtros ───────────────────────────────────────────────────
    function applyFilters(list) {
        const q       = ($('#searchInput')?.value || '').trim().toLowerCase();
        const resp    = parseInt($('#filterResponsavel')?.value || '0', 10);
        const status  = ($('#filterStatus')?.value || '').trim();
        const origem  = ($('#filterOrigem')?.value || '').trim();  // pode ser '__matriz__' / '__filiais__' / accId / ''

        return list.filter(c => {
            if (q) {
                const hay = [c.nome, c.telefone, c.whatsapp, c.cpf_cnpj, c.email].map(v => String(v || '').toLowerCase()).join(' ');
                if (!hay.includes(q)) return false;
            }
            if (resp > 0 && parseInt(c.responsavel_id, 10) !== resp) return false;
            if (status   && String(c.status || '').toLowerCase() !== status.toLowerCase()) return false;

            // Origem: aceita pseudo-valores __matriz__ / __filiais__ / __advogados__
            // ou account_id específico.
            // FIX (auditoria 2026-06-01 — MÉDIA #19 / BAIXA #32): "__filiais__"
            // antes exigia tipo==='filial', escondendo clientes de contas advogado
            // vinculadas. Agora cobre toda origem não-matriz (filial + advogado);
            // "__advogados__" isola só os advogados.
            if (origem) {
                const tipo = String(c.origin_account_tipo || '').toLowerCase();
                const accId = String(c.account_id || '');
                if (origem === '__matriz__'    && tipo !== 'matriz')    return false;
                if (origem === '__filiais__'   && tipo === 'matriz')    return false;
                if (origem === '__advogados__' && tipo !== 'advogado')  return false;
                if (origem !== '__matriz__' && origem !== '__filiais__' && origem !== '__advogados__' && accId !== origem) return false;
            }
            return true;
        });
    }

    // ── Drag-and-drop ─────────────────────────────────────────────
    async function onCardDrop(evt) {
        const cardEl = evt.item;
        const cliId  = parseInt(cardEl.dataset.cliId, 10);
        const account = parseInt(cardEl.dataset.account, 10);
        if (account !== window.YURIS_CTX.accountId) {
            // segurança extra (filter já bloqueia, mas double-check)
            await loadAll();
            notify('Apenas clientes próprios podem ser movidos.', 'warning');
            return;
        }
        const toCol = evt.to.closest('.setor-col');
        const newSetorId = parseInt(toCol?.dataset.setorId, 10);
        if (!newSetorId || isNaN(newSetorId)) { await loadAll(); return; }

        // Recalcula ordens da coluna destino
        const orderedIds = $$('.cli-card', evt.to).map(el => parseInt(el.dataset.cliId, 10));
        const reorder = orderedIds.map((id, idx) => ({
            id, setor_id: newSetorId, ordem_no_setor: idx
        }));

        try {
            const res = await fetch('/api/clientes.php', {
                method: 'PATCH',
                headers: csrfHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ reorder, csrf_token: window.YURIS_CTX.csrf })
            });
            const j = await res.json();
            if (!res.ok || !j.success) throw new Error(j.error || 'reorder falhou');
            // Atualiza estado local
            const lookup = {};
            reorder.forEach(r => lookup[r.id] = r);
            state.clientes.forEach(c => {
                if (lookup[c.id]) {
                    c.setor_id       = lookup[c.id].setor_id;
                    c.ordem_no_setor = lookup[c.id].ordem_no_setor;
                }
            });
            updateMetaLine();
        } catch (e) {
            console.error('[Clientes] drop error', e);
            notify('Falha ao mover cliente', 'error');
            await loadAll();
        }
    }

    // ── Modal: Cliente (Novo / Editar) ────────────────────────────
    function openCreateModal() {
        state.editingId     = null;
        state.currentCliente = null;
        $('#mcliTitle').textContent = 'Novo Cliente';
        $('#cliForm').reset();
        $('#cliId').value = '';
        $('#cliHistoryBlock').style.display = 'none';
        $('#btnArquivarCliente').style.display = 'none';
        $('#btnSalvarCliente').textContent = 'Cadastrar';
        // setor default = primeiro
        if (state.setores.length > 0) $('#cliSetor').value = state.setores[0].id;
        showModal('modalCliente');
        setTimeout(() => $('#cliNome').focus(), 100);
    }

    async function openEditModal(id) {
        try {
            const res = await fetch(`/api/clientes.php?id=${id}`, { credentials: 'same-origin' });
            const j = await res.json();
            if (!j.data) throw new Error(j.error || 'Cliente não encontrado');
            const c = j.data;
            state.editingId      = parseInt(c.id, 10);
            state.currentCliente = c;
            $('#mcliTitle').textContent = c.nome || 'Editar Cliente';
            $('#cliId').value       = c.id;
            $('#cliNome').value     = c.nome || '';
            $('#cliTipo').value     = c.tipo_cliente || 'PF';
            $('#cliCpfCnpj').value  = c.cpf_cnpj || '';
            $('#cliRg').value       = c.rg || '';
            $('#cliNomeMae').value  = c.nome_mae || '';
            $('#cliTelefone').value = c.telefone || '';
            $('#cliWhatsapp').value = c.whatsapp || '';
            $('#cliEmail').value    = c.email || '';
            $('#cliCep').value      = c.cep || '';
            $('#cliLogradouro').value = c.logradouro || '';
            $('#cliNumero').value   = c.numero || '';
            $('#cliComplemento').value = c.complemento || '';
            $('#cliBairro').value   = c.bairro || '';
            $('#cliCidade').value   = c.cidade || '';
            $('#cliUf').value       = c.uf || '';
            // Origem: re-popula o select garantindo que o slug salvo apareça
            // (mesmo se for origem antiga/arquivada — vira opção "fantasma")
            populateOrigemSelect(c.origem || 'cadastro_manual');
            $('#cliStatus').value   = c.status || 'ativo';
            $('#cliSetor').value    = c.setor_id || '';
            $('#cliResp').value     = c.responsavel_id || '';
            $('#cliObs').value      = c.observacoes || '';
            $('#cliCepStatus').textContent = '';
            $('#btnSalvarCliente').textContent = 'Salvar';

            // Histórico
            const histBlock = $('#cliHistoryBlock');
            const histList  = $('#cliHistory');
            if (Array.isArray(c.history) && c.history.length > 0) {
                histList.innerHTML = c.history.map(h => {
                    const who = h.user_nome || h.user_login || 'sistema';
                    const acaoLabel = (window.Yuris && Yuris.translateAuditAcao)
                        ? Yuris.translateAuditAcao(h.acao)
                        : (h.acao || '');
                    return `<div class="history-item">
                        <span class="acao">${escapeHtml(acaoLabel)}</span> por <strong>${escapeHtml(who)}</strong>
                        <time>${escapeHtml(fmtDate(h.created_at))}</time>
                    </div>`;
                }).join('');
                histBlock.style.display = '';
            } else {
                histList.innerHTML = '<div class="history-item">Sem histórico ainda.</div>';
                histBlock.style.display = '';
            }

            // Botão arquivar: só pra clientes próprios e não-arquivados
            const isOwn = (parseInt(c.account_id, 10) === window.YURIS_CTX.accountId);
            $('#btnArquivarCliente').style.display = (isOwn && !c.deleted_at) ? '' : 'none';
            // Form fields: read-only se for de filial (matriz vendo cliente de filial)
            const disabled = !isOwn;
            $$('#cliForm input, #cliForm select, #cliForm textarea').forEach(el => el.disabled = disabled);
            $('#btnSalvarCliente').style.display = disabled ? 'none' : '';

            // Processos vinculados (reverse lookup do cliente_id, migration 093) +
            // atalho "Novo processo para este cliente" (auditoria 2026-06-01).
            loadProcessosCliente(c.id);

            showModal('modalCliente');
        } catch (e) {
            console.error('[Clientes] openEditModal', e);
            notify('Falha ao abrir cliente: ' + e.message, 'error');
        }
    }

    function closeClienteModal() { hideModal('modalCliente'); }

    async function submitCliente(ev) {
        ev.preventDefault();
        const form = $('#cliForm');
        const data = {};
        new FormData(form).forEach((v, k) => { data[k] = v; });
        data.csrf_token = window.YURIS_CTX.csrf;

        const isEdit = state.editingId !== null;
        const url    = '/api/clientes.php';
        const method = isEdit ? 'PUT' : 'POST';
        if (isEdit) data.id = state.editingId;

        try {
            const res = await fetch(url, {
                method, headers: csrfHeaders(), credentials: 'same-origin',
                body: JSON.stringify(data)
            });
            const j = await res.json();
            if (!res.ok || !j.success) throw new Error(j.error || 'Falha na requisição');
            notify(isEdit ? 'Cliente atualizado' : 'Cliente cadastrado', 'success');
            closeClienteModal();
            await loadAll();
        } catch (e) {
            console.error('[Clientes] submit error', e);
            notify('Erro: ' + e.message, 'error');
        }
        return false;
    }

    async function archiveCliente() {
        if (!state.editingId) return;
        if (!confirm('Arquivar este cliente? Pode ser restaurado depois.')) return;
        try {
            const res = await fetch(`/api/clientes.php?id=${state.editingId}`, {
                method: 'DELETE',
                headers: csrfHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ csrf_token: window.YURIS_CTX.csrf })
            });
            const j = await res.json();
            if (!j.success) throw new Error(j.error || 'Falha ao arquivar');
            notify('Cliente arquivado', 'success');
            closeClienteModal();
            await loadAll();
        } catch (e) {
            console.error('[Clientes] archive error', e);
            notify('Erro: ' + e.message, 'error');
        }
    }

    // ── Modal: Setores ────────────────────────────────────────────
    async function openSetoresModal() {
        await refreshSetoresList();
        showModal('modalSetores');
    }
    function closeSetoresModal() { hideModal('modalSetores'); loadAll(); /* reflete mudanças */ }

    async function refreshSetoresList() {
        try {
            const res = await fetch('/api/clientes_setores.php?include_inactive=0', { credentials: 'same-origin' });
            const j = await res.json();
            const list = Array.isArray(j.data) ? j.data : [];
            const wrap = $('#setoresList');
            if (list.length === 0) {
                wrap.innerHTML = '<p style="color:var(--muted); text-align:center; padding:24px 0;">Nenhum setor ainda. Adicione abaixo.</p>';
                return;
            }
            wrap.innerHTML = list.map(s => `
                <div class="setor-row" data-id="${s.id}">
                  <span class="drag-handle" title="Arraste pra reordenar">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                  </span>
                  <input type="color" class="color-picker" value="${escapeHtml(s.cor || '#6366f1')}" onchange="Clientes.updateSetor(${s.id}, {cor: this.value})">
                  <input type="text" class="nome-input" value="${escapeHtml(s.nome)}" maxlength="190"
                    onblur="if(this.value.trim() && this.value !== this.defaultValue) { Clientes.updateSetor(${s.id}, {nome: this.value.trim()}); this.defaultValue = this.value.trim(); }">
                  <span class="count-badge">${parseInt(s.clientes_count || 0, 10)} cliente${parseInt(s.clientes_count || 0, 10) === 1 ? '' : 's'}</span>
                  <button type="button" class="archive-btn" title="Arquivar setor" onclick="Clientes.archiveSetor(${s.id})">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                  </button>
                </div>
            `).join('');

            // Sortable na lista
            const listEl = $('#setoresList');
            if (listEl._sortable) { try { listEl._sortable.destroy(); } catch (e) {} }
            listEl._sortable = new Sortable(listEl, {
                animation: 140, ghostClass: 'sortable-ghost', handle: '.drag-handle',
                onEnd: async () => {
                    const ids = $$('.setor-row', listEl).map((el, idx) => ({ id: parseInt(el.dataset.id, 10), ordem: idx }));
                    try {
                        await fetch('/api/clientes_setores.php', {
                            method: 'PATCH', headers: csrfHeaders(), credentials: 'same-origin',
                            body: JSON.stringify({ reorder: ids, csrf_token: window.YURIS_CTX.csrf })
                        });
                    } catch (e) {
                        notify('Falha ao reordenar', 'error');
                    }
                }
            });
        } catch (e) {
            console.error('[Clientes] refreshSetoresList', e);
            notify('Falha ao listar setores', 'error');
        }
    }

    async function createSetor() {
        const nome = $('#novoSetorNome').value.trim();
        const cor  = $('#novoSetorCor').value || '#6366f1';
        if (!nome) { notify('Nome obrigatório', 'warning'); return; }
        try {
            const res = await fetch('/api/clientes_setores.php', {
                method: 'POST', headers: csrfHeaders(), credentials: 'same-origin',
                body: JSON.stringify({ nome, cor, csrf_token: window.YURIS_CTX.csrf })
            });
            const j = await res.json();
            if (!j.success) throw new Error(j.error || 'Falha');
            $('#novoSetorNome').value = '';
            await refreshSetoresList();
        } catch (e) {
            notify('Erro: ' + e.message, 'error');
        }
    }

    async function updateSetor(id, patch) {
        try {
            const body = Object.assign({ id, csrf_token: window.YURIS_CTX.csrf }, patch);
            const res = await fetch('/api/clientes_setores.php', {
                method: 'PATCH', headers: csrfHeaders(), credentials: 'same-origin',
                body: JSON.stringify(body)
            });
            const j = await res.json();
            if (!j.success) throw new Error(j.error || 'Falha');
        } catch (e) {
            notify('Erro: ' + e.message, 'error');
            await refreshSetoresList();
        }
    }

    async function archiveSetor(id) {
        if (!confirm('Arquivar este setor? Ele some do kanban mas continua no DB.')) return;
        try {
            const res = await fetch(`/api/clientes_setores.php?id=${id}`, {
                method: 'DELETE', headers: csrfHeaders(), credentials: 'same-origin',
                body: JSON.stringify({ csrf_token: window.YURIS_CTX.csrf })
            });
            const j = await res.json();
            if (!j.success) {
                if (j.code === 'has_clients') {
                    notify(`Setor tem ${j.count} cliente(s) ativo(s). Mova-os primeiro.`, 'warning');
                } else {
                    throw new Error(j.error || 'Falha');
                }
            } else {
                notify('Setor arquivado', 'success');
                await refreshSetoresList();
            }
        } catch (e) {
            notify('Erro: ' + e.message, 'error');
        }
    }

    // ── Modal helpers ─────────────────────────────────────────────
    function showModal(id) { const m = $('#' + id); if (m) m.classList.add('open'); }
    function hideModal(id) { const m = $('#' + id); if (m) m.classList.remove('open'); }

    // ── Wire-up ──────────────────────────────────────────────────
    // Carrega processos vinculados ao cliente (GET /api/processes.php?cliente_id=)
    // e configura o atalho "Novo processo para este cliente".
    async function loadProcessosCliente(clienteId) {
        const block = $('#cliProcessosBlock');
        const list  = $('#cliProcessos');
        const novo  = $('#cliNovoProcesso');
        if (!block || !list) return;
        if (novo) novo.href = '/processos.php?new_cliente_id=' + encodeURIComponent(clienteId);
        block.style.display = '';
        list.innerHTML = '<div style="color:var(--muted); font-size:.8rem;">Carregando…</div>';
        try {
            const r = await fetch('/api/processes.php?cliente_id=' + encodeURIComponent(clienteId), { credentials: 'same-origin' });
            const j = await r.json();
            const procs = Array.isArray(j) ? j : (j.data || []);
            if (!procs.length) {
                list.innerHTML = '<div style="color:var(--muted); font-size:.8rem;">Nenhum processo vinculado a este cliente ainda.</div>';
                return;
            }
            list.innerHTML = procs.map(p => {
                const num = escapeHtml(p.numero || ('Processo #' + p.id));
                const st  = escapeHtml(p.status || '');
                return `<a href="/processos.php?open=${p.id}" style="display:flex; justify-content:space-between; gap:8px; padding:8px 10px; border-radius:8px; background:rgba(96,165,250,.08); border:1px solid rgba(96,165,250,.15); text-decoration:none; color:inherit;">
                    <span>${num}</span><span style="color:var(--muted); font-size:.76rem;">${st} →</span>
                </a>`;
            }).join('');
        } catch (e) {
            list.innerHTML = '<div style="color:var(--muted); font-size:.8rem;">Falha ao carregar processos.</div>';
        }
    }

    // ── Vincular processo EXISTENTE ao cliente ────────────────────────
    // Espelha o picker de cliente que já existe no detalhe do Processo:
    // lista os processos acessíveis (matriz + filiais vinculadas) agrupados
    // por conta de origem, com busca, e ao escolher seta processos.cliente_id
    // = cliente atual via PUT /api/processes.php (update parcial + tenant-check).
    let _procPickerData = null; // cache da lista de processos do picker

    async function openVincularProcessoModal() {
        if (!state.editingId) { notify('Abra um cliente primeiro', 'error'); return; }
        const input = document.getElementById('vincProcSearch');
        const lista = document.getElementById('vincProcLista');
        if (input) input.value = '';
        if (lista) lista.innerHTML = '<div style="color:var(--muted); font-size:.83rem; padding:16px 8px; text-align:center">Carregando…</div>';
        showModal('modalVincularProcesso');
        setTimeout(() => input?.focus(), 60);
        try {
            if (!_procPickerData) {
                const r = await fetch('/api/processes.php', { credentials: 'same-origin' });
                const j = await r.json();
                _procPickerData = Array.isArray(j) ? j : (j.data || []);
            }
            _renderProcPicker('');
        } catch (e) {
            if (lista) lista.innerHTML = '<div style="color:var(--muted); font-size:.83rem; padding:16px 8px; text-align:center">Falha ao carregar processos.</div>';
        }
    }

    function _renderProcPicker(filtro) {
        const lista = document.getElementById('vincProcLista');
        if (!lista) return;
        const termo = String(filtro || '').toLowerCase().trim();
        const arr = (_procPickerData || []).filter(p => {
            if (!termo) return true;
            return String(p.numero || '').toLowerCase().includes(termo)
                || String(p.cliente_nome || '').toLowerCase().includes(termo)
                || String(p.parte_contraria || '').toLowerCase().includes(termo);
        });
        if (!arr.length) {
            lista.innerHTML = '<div style="color:var(--muted); font-size:.83rem; padding:16px 8px; text-align:center">Nenhum processo encontrado</div>';
            return;
        }
        // Agrupa por conta de origem (matriz primeiro, depois filiais/advogados por nome)
        const groups = {};
        arr.forEach(p => {
            const key = String(p.origin_account_id || '0');
            if (!groups[key]) groups[key] = { tipo: (p.origin_account_tipo || ''), nome: (p.origin_account_nome || ''), items: [] };
            groups[key].items.push(p);
        });
        const order = Object.values(groups).sort((a, b) => {
            const am = a.tipo === 'matriz' ? 0 : 1, bm = b.tipo === 'matriz' ? 0 : 1;
            if (am !== bm) return am - bm;
            return String(a.nome || '').localeCompare(String(b.nome || ''));
        });
        const tipoLabel = t => t === 'matriz' ? 'MATRIZ' : (t === 'filial' ? 'FILIAL' : (t === 'advogado' ? 'ADVOGADO' : ''));
        let html = '';
        order.forEach(g => {
            const title = [tipoLabel(g.tipo), g.nome].filter(Boolean).join(' · ') || 'Conta';
            html += `<div style="font-size:.7rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--muted); padding:10px 8px 4px;">${escapeHtml(title)}</div>`;
            html += g.items.map(p => {
                const num = escapeHtml(p.numero || ('Processo #' + p.id));
                const subParts = [];
                if (p.cliente_nome)     subParts.push(escapeHtml(p.cliente_nome));
                if (p.parte_contraria)  subParts.push('× ' + escapeHtml(p.parte_contraria));
                const sub = subParts.join(' · ');
                const jaNeste = String(p.cliente_id || '') === String(state.editingId || '') && p.cliente_id;
                const noutro  = !jaNeste && p.cliente_id;
                const badge = jaNeste
                    ? '<span style="color:#34d399; font-size:.72rem; font-weight:600; white-space:nowrap">✓ já vinculado</span>'
                    : (noutro ? '<span style="color:#fbbf24; font-size:.72rem; font-weight:600; white-space:nowrap">vinculado a outro</span>' : '');
                const clickAttr = jaNeste ? '' : `onclick="window.__vincProc(${p.id})"`;
                const cur = jaNeste ? 'default; opacity:.55' : 'pointer';
                return `<div ${clickAttr} style="display:flex; justify-content:space-between; gap:10px; align-items:center; padding:9px 10px; border-radius:8px; cursor:${cur}; border:1px solid var(--border); margin:4px 0;">
                    <div style="min-width:0;">
                        <div style="font-weight:600; font-size:.86rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${num}</div>
                        ${sub ? `<div style="color:var(--muted); font-size:.76rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${sub}</div>` : ''}
                    </div>
                    ${badge}
                </div>`;
            }).join('');
        });
        lista.innerHTML = html;
    }

    // Chamado ao clicar num processo do modal — grava o vínculo (cliente_id)
    window.__vincProc = async (procId) => {
        if (!state.editingId) { notify('Abra um cliente primeiro', 'error'); return; }
        try {
            const r = await fetch('/api/processes.php', {
                method: 'PUT', headers: csrfHeaders(), credentials: 'same-origin',
                body: JSON.stringify({ id: procId, cliente_id: state.editingId, csrf_token: window.YURIS_CTX.csrf })
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok || j.success === false) throw new Error(j.error || ('HTTP ' + r.status));
            notify('Processo vinculado ao cliente', 'success');
            hideModal('modalVincularProcesso');
            _procPickerData = null;            // cliente_id mudou — invalida o cache
            loadProcessosCliente(state.editingId);
        } catch (e) {
            notify('Falha ao vincular processo: ' + (e.message || 'erro'), 'error');
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('cliVincularProcesso')?.addEventListener('click', (e) => { e.preventDefault(); openVincularProcessoModal(); });
        document.getElementById('vincProcSearch')?.addEventListener('input', function () { _renderProcPicker(this.value); });
    });

    document.addEventListener('DOMContentLoaded', () => {
        $('#btnNovoCliente')?.addEventListener('click', openCreateModal);
        $('#btnGerenciarSetores')?.addEventListener('click', openSetoresModal);

        // Deep-link ?open=ID — abre o cliente direto (ex: vindo do vínculo de
        // processo "Ver ficha na aba Clientes"). Auditoria 2026-06-01.
        try {
            const openId = new URLSearchParams(location.search).get('open');
            if (openId) {
                setTimeout(() => { try { openEditModal(parseInt(openId, 10)); } catch(e){} }, 400);
                history.replaceState({}, '', location.pathname);
            }
        } catch (e) {}

        // Filtros: debounced re-render
        let t;
        const onFilter = () => { clearTimeout(t); t = setTimeout(() => { renderBoard(); updateMetaLine(); }, 150); };
        $('#searchInput')?.addEventListener('input', onFilter);
        $('#filterResponsavel')?.addEventListener('change', onFilter);
        $('#filterStatus')?.addEventListener('change', onFilter);
        $('#filterOrigem')?.addEventListener('change', onFilter);

        // ESC fecha modal aberto
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                $$('.modal-shell.open').forEach(m => m.classList.remove('open'));
            }
        });

        // Click no backdrop fecha modal
        $$('.modal-shell').forEach(shell => {
            shell.addEventListener('click', (e) => {
                if (e.target === shell) shell.classList.remove('open');
            });
        });

        loadAll();
    });

    // ── Modal: Origens ────────────────────────────────────────────
    async function openOrigensModal() {
        await refreshOrigensList();
        showModal('modalOrigens');
    }
    function closeOrigensModal() {
        hideModal('modalOrigens');
        // Recarrega lista pra refletir mudanças no dropdown da modal de cliente
        loadAll();
    }

    async function refreshOrigensList() {
        try {
            const res = await fetch('/api/clientes_origens.php?include_inactive=0', { credentials: 'same-origin' });
            const j = await res.json();
            const list = Array.isArray(j.data) ? j.data : [];
            const wrap = $('#origensList');
            if (list.length === 0) {
                wrap.innerHTML = '<p style="color:var(--muted); text-align:center; padding:24px 0;">Nenhuma origem ainda. Adicione abaixo.</p>';
                return;
            }
            wrap.innerHTML = list.map(o => `
                <div class="setor-row" data-id="${o.id}">
                  <span class="drag-handle" title="Arraste pra reordenar">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                  </span>
                  <input type="text" class="nome-input" value="${escapeHtml(o.nome)}" maxlength="80"
                    onblur="if(this.value.trim() && this.value !== this.defaultValue) { Clientes.updateOrigem(${o.id}, {nome: this.value.trim()}); this.defaultValue = this.value.trim(); }">
                  <span class="count-badge">${parseInt(o.clientes_count || 0, 10)} cliente${parseInt(o.clientes_count || 0, 10) === 1 ? '' : 's'}</span>
                  <button type="button" class="archive-btn" title="Arquivar origem" onclick="Clientes.archiveOrigem(${o.id})">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                  </button>
                </div>
            `).join('');

            // Sortable
            const listEl = $('#origensList');
            if (listEl._sortable) { try { listEl._sortable.destroy(); } catch (e) {} }
            listEl._sortable = new Sortable(listEl, {
                animation: 140, ghostClass: 'sortable-ghost', handle: '.drag-handle',
                onEnd: async () => {
                    const ids = $$('.setor-row', listEl).map((el, idx) => ({ id: parseInt(el.dataset.id, 10), ordem: idx }));
                    try {
                        await fetch('/api/clientes_origens.php', {
                            method: 'PATCH', headers: csrfHeaders(), credentials: 'same-origin',
                            body: JSON.stringify({ reorder: ids, csrf_token: window.YURIS_CTX.csrf })
                        });
                    } catch (e) {
                        notify('Falha ao reordenar origens', 'error');
                    }
                }
            });
        } catch (e) {
            console.error('[Clientes] refreshOrigensList', e);
            notify('Falha ao listar origens', 'error');
        }
    }

    async function createOrigem() {
        const nome = $('#novaOrigemNome').value.trim();
        if (!nome) { notify('Nome obrigatório', 'warning'); return; }
        try {
            const res = await fetch('/api/clientes_origens.php', {
                method: 'POST', headers: csrfHeaders(), credentials: 'same-origin',
                body: JSON.stringify({ nome, csrf_token: window.YURIS_CTX.csrf })
            });
            const j = await res.json();
            if (!j.success) throw new Error(j.error || 'Falha');
            $('#novaOrigemNome').value = '';
            await refreshOrigensList();
        } catch (e) {
            notify('Erro: ' + e.message, 'error');
        }
    }

    async function updateOrigem(id, patch) {
        try {
            const body = Object.assign({ id, csrf_token: window.YURIS_CTX.csrf }, patch);
            const res = await fetch('/api/clientes_origens.php', {
                method: 'PATCH', headers: csrfHeaders(), credentials: 'same-origin',
                body: JSON.stringify(body)
            });
            const j = await res.json();
            if (!j.success) throw new Error(j.error || 'Falha');
        } catch (e) {
            notify('Erro: ' + e.message, 'error');
            await refreshOrigensList();
        }
    }

    async function archiveOrigem(id) {
        if (!confirm('Arquivar esta origem? Pode reativar depois (clientes mantêm o vínculo histórico).')) return;
        try {
            const res = await fetch(`/api/clientes_origens.php?id=${id}`, {
                method: 'DELETE', headers: csrfHeaders(), credentials: 'same-origin',
                body: JSON.stringify({ csrf_token: window.YURIS_CTX.csrf })
            });
            const j = await res.json();
            if (!j.success) {
                if (j.code === 'has_clients') {
                    notify(`Origem em uso por ${j.count} cliente(s). Reclassifique primeiro.`, 'warning');
                } else {
                    throw new Error(j.error || 'Falha');
                }
            } else {
                notify('Origem arquivada', 'success');
                await refreshOrigensList();
            }
        } catch (e) {
            notify('Erro: ' + e.message, 'error');
        }
    }

    // ── ViaCEP autofill ───────────────────────────────────────────
    // Usado em onblur dos campos CEP (clientes.php usa prefix 'cli';
    // se quiser usar em outro form, passe prefix correspondente).
    let _lastCep = null;
    async function viaCepLookup(raw, prefix) {
        prefix = prefix || 'cli';
        const cep = String(raw || '').replace(/\D/g, '');
        const status = $(`#${prefix}CepStatus`);
        if (cep.length !== 8) {
            if (status) status.textContent = cep.length === 0 ? '' : 'CEP precisa de 8 dígitos';
            return;
        }
        if (cep === _lastCep) return;  // evita re-lookup desnecessário
        _lastCep = cep;
        if (status) { status.textContent = 'Buscando…'; status.style.color = 'var(--muted)'; }
        try {
            const res = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const j = await res.json();
            if (j.erro) {
                if (status) { status.textContent = 'CEP não encontrado'; status.style.color = 'var(--warn)'; }
                return;
            }
            // Preenche só campos vazios (não sobrescreve edição manual)
            const setIfEmpty = (id, val) => { const el = $('#' + id); if (el && !el.value && val) el.value = val; };
            setIfEmpty(prefix + 'Logradouro', j.logradouro);
            setIfEmpty(prefix + 'Bairro',     j.bairro);
            setIfEmpty(prefix + 'Cidade',     j.localidade);
            const ufEl = $('#' + prefix + 'Uf');
            if (ufEl && !ufEl.value && j.uf) ufEl.value = j.uf;
            if (status) { status.textContent = `${j.localidade}/${j.uf}`; status.style.color = 'var(--ok)'; }
        } catch (e) {
            console.warn('[ViaCEP]', e);
            if (status) { status.textContent = 'Falha ao consultar CEP'; status.style.color = 'var(--warn)'; }
        }
    }

    return {
        loadAll, openCreateModal, openEditModal, closeClienteModal, submitCliente, archiveCliente,
        openSetoresModal, closeSetoresModal,
        createSetor, updateSetor, archiveSetor,
        openOrigensModal, closeOrigensModal,
        createOrigem, updateOrigem, archiveOrigem,
        viaCepLookup,
    };
})();
</script>

</body>
</html>
