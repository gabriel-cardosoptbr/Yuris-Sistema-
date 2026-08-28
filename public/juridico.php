<?php
require_once __DIR__ . '/../app/bootstrap.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$activePage = 'juridico';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Painel Jurídico — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
  <style>
    :root {
      --bg-main: #070F1C;
      --panel: #0D1C30;
      --line: rgba(160,180,210,0.08);
      --line-strong: rgba(160,180,210,0.14);
      --text: #D8E4F0;
      --muted: #7A8898;
      --primary: #244E7A;
      --ok: #1E4A3A;
      --warn: #3D3010;
      --danger: #3A1020;
      --brand: #6898C0;
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
      font-family: Inter, 'Poppins', system-ui, -apple-system, sans-serif;
      min-height: 100vh;
    }

    /* ── Sidebar ── */

    /* ── Panel ── */
    .jur-panel {
      background: linear-gradient(165deg, rgba(14,35,65,.94), rgba(10,23,43,.96));
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 20px;
      box-shadow: 0 14px 40px rgba(2,6,23,.45);
    }

    /* ── Typography ── */
    .jur-title    { font-size: 1.5rem; font-weight: 700; color: #dbeafe; line-height: 1.2; }
    .jur-subtitle { margin-top: 4px; color: var(--muted); font-size: .84rem; line-height: 1.45; }
    .jur-section-title { font-size: 1rem; font-weight: 600; color: #dbeafe; letter-spacing: .01em; }

    /* ── KPI Cards ── */
    .kpi-card {
      position: relative; overflow: hidden; border-radius: 14px;
      border: 1px solid rgba(96,165,250,.22);
      background: linear-gradient(135deg, rgba(13,31,56,.95), rgba(8,19,37,.95));
      padding: 16px; min-height: 108px;
      transition: transform .2s, border-color .2s, box-shadow .2s;
    }
    .kpi-card:hover { transform: translateY(-4px); border-color: rgba(160,180,210,0.22); box-shadow: 0 8px 24px rgba(0,0,0,0.40); }
    .kpi-card.kpi-danger { border-color: rgba(176,96,112,0.18); }
    .kpi-card.kpi-danger:hover { border-color: rgba(176,96,112,0.32); box-shadow: 0 8px 24px rgba(0,0,0,0.40); }
    .kpi-card.kpi-warn   { border-color: rgba(196,160,64,0.18); }
    .kpi-card.kpi-warn:hover   { border-color: rgba(196,160,64,0.32); box-shadow: 0 8px 24px rgba(0,0,0,0.40); }
    .kpi-card.kpi-ok     { border-color: rgba(122,189,160,0.18); }
    .kpi-label { color: #7A8898; font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
    .kpi-value { margin-top: 10px; color: #D8E4F0; font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
    .kpi-foot  { margin-top: 6px; color: var(--muted); font-size: .72rem; }
    .kpi-dot   { position: absolute; top: 14px; right: 14px; width: 9px; height: 9px; border-radius: 50%; }
    .dot-ok      { background: #4A9078; box-shadow: none; }
    .dot-warn    { background: #9A7B28; box-shadow: none; }
    .dot-danger  { background: #8A4050; box-shadow: none; }
    .dot-neutral { background: #3A4858; }

    /* ── Summary ── */
    .summary-box {
      border-radius: 10px; border: 1px solid rgba(96,165,250,.2);
      background: rgba(8,20,40,.55); padding: 13px 16px;
      color: #d2e8ff; font-size: .87rem; line-height: 1.65;
    }
    .summary-box strong { color: #93c5fd; }

    /* ── Chart card ── */
    .chart-card {
      background: linear-gradient(165deg, rgba(14,35,65,.94), rgba(10,23,43,.96));
      border: 1px solid var(--line); border-radius: var(--radius);
      padding: 18px; box-shadow: 0 14px 40px rgba(2,6,23,.45);
    }
    .chart-title { font-size: .92rem; font-weight: 600; color: #dbeafe; margin-bottom: 14px; }

    /* ── Próximos section ── */
    .prox-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    .prox-card {
      border-radius: 12px; border: 1px solid rgba(96,165,250,.14);
      background: rgba(8,20,40,.5); overflow: hidden;
    }
    .prox-card-header {
      padding: 10px 14px; display: flex; justify-content: space-between; align-items: center;
      border-bottom: 1px solid rgba(96,165,250,.1);
    }
    .prox-card-label { font-size: .8rem; font-weight: 700; color: #93c5fd; text-transform: uppercase; letter-spacing: .04em; }
    .prox-card-count { font-size: 1.1rem; font-weight: 700; color: #f0f8ff; }
    .prox-card-list  { padding: 10px; display: flex; flex-direction: column; gap: 6px; max-height: 280px; overflow-y: auto; }
    .prox-row {
      padding: 8px 10px; border-radius: 8px;
      background: rgba(15,33,60,.8); border: 1px solid rgba(59,130,246,.12);
      font-size: .76rem;
    }
    .prox-row-num    { color: #93c5fd; font-weight: 600; font-size: .7rem; }
    .prox-row-client { color: #e2f0ff; font-weight: 600; margin-top: 1px; }
    .prox-row-meta   { color: var(--muted); margin-top: 1px; font-size: .7rem; }
    .prox-empty      { color: var(--muted); font-size: .78rem; padding: 10px; text-align: center; }

    /* ── Badges ── */
    .jur-badge {
      display: inline-flex; align-items: center; padding: 2px 7px;
      border-radius: 999px; font-size: .66rem; font-weight: 700;
    }
    .badge-danger  { background: rgba(58,16,32,0.60);  color: #B06070; border: 1px solid rgba(176,96,112,0.25); }
    .badge-warn    { background: rgba(61,48,16,0.60);  color: #C4A040; border: 1px solid rgba(196,160,64,0.25); }
    .badge-ok      { background: rgba(30,74,58,0.60);  color: #7ABDA0; border: 1px solid rgba(122,189,160,0.25); }
    .badge-info    { background: rgba(14,40,69,0.70);  color: #6898C0; border: 1px solid rgba(104,152,192,0.25); }

    /* ── Distribuição por responsável (formato CARD vertical) ──
       Cada item vira mini-card: nome completo no topo + linha de baixo
       (barra de progresso + contagem + porcentagem). Evita truncar nomes. */
    .resp-row {
      display: flex; flex-direction: column; gap: 6px;
      padding: 11px 12px;
      margin-bottom: 8px;
      background: rgba(15,33,60,.55);
      border: 1px solid rgba(96,165,250,.10);
      border-radius: 9px;
      transition: border-color .15s, background .15s;
    }
    .resp-row:hover {
      border-color: rgba(96,165,250,.22);
      background: rgba(15,33,60,.75);
    }
    .resp-row:last-child { margin-bottom: 0; }
    .resp-name {
      font-size: .85rem; font-weight: 600;
      color: #d6e8fa;
      line-height: 1.3;
      /* nome completo, com quebra se necessário */
      white-space: normal;
      overflow: visible;
      text-overflow: clip;
      word-break: break-word;
    }
    .resp-stats {
      display: flex; align-items: center; gap: 10px;
    }
    .resp-bar-wrap { flex: 1; height: 6px; background: rgba(26,58,92,0.22); border-radius: 999px; overflow: hidden; }
    .resp-bar  { height: 100%; background: linear-gradient(90deg, #1A3A5C, #3D6A96); border-radius: 999px; transition: width .6s ease; }
    .resp-count { flex: 0 0 auto; min-width: 32px; text-align: right; font-size: .82rem; font-weight: 700; color: #6898C0; }
    .resp-pct   { flex: 0 0 auto; min-width: 38px; text-align: right; font-size: .73rem; color: var(--muted); }

    /* ── List rows (legacy sections) ── */
    .list-row { padding: 9px 11px; border-radius: 7px; margin-bottom: 6px; background: rgba(15,33,60,.75); border: 1px solid rgba(59,130,246,.1); font-size: .82rem; }

    /* ── Alert toggles ── */
    .alert-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid rgba(96,165,250,.08); }
    .alert-row:last-child { border-bottom: none; }
    .alert-label { font-size: .84rem; color: #d6e8fa; }
    .alert-sublabel { font-size: .72rem; color: var(--muted); margin-top: 2px; }
    .toggle-wrap { position: relative; width: 42px; height: 24px; flex-shrink: 0; }
    .toggle-wrap input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
      position: absolute; inset: 0; background: rgba(148,163,184,.3);
      border-radius: 999px; cursor: pointer; transition: background .22s;
    }
    .toggle-slider::before {
      content: ''; position: absolute; height: 18px; width: 18px;
      left: 3px; top: 3px; background: #fff; border-radius: 50%;
      transition: transform .22s;
    }
    .toggle-wrap input:checked + .toggle-slider { background: #244E7A; }
    .toggle-wrap input:checked + .toggle-slider::before { transform: translateX(18px); }

    /* ── Alert popup modal ── */
    #alertModal {
      position: fixed; inset: 0; background: rgba(2,6,23,.72);
      display: flex; align-items: flex-start; justify-content: center;
      z-index: 1100; overflow-y: auto; padding: 36px 12px;
    }
    #alertModal.hidden { display: none !important; }
    .alert-modal-box {
      width: 680px; max-width: 96vw; margin: auto;
      background: linear-gradient(165deg, rgba(10,24,46,.99), rgba(7,18,36,.99));
      border: 1px solid rgba(96,165,250,.25); border-radius: 16px;
      box-shadow: 0 24px 60px rgba(2,6,23,.75); overflow: hidden;
    }
    .alert-modal-header {
      display: flex; justify-content: space-between; align-items: center;
      padding: 16px 20px; border-bottom: 1px solid rgba(96,165,250,.14);
      background: rgba(8,22,44,.55);
    }
    .alert-modal-title { font-size: 1.02rem; font-weight: 700; color: #dbeafe; }
    .alert-modal-body  { padding: 18px 20px; display: flex; flex-direction: column; gap: 14px; max-height: 74vh; overflow-y: auto; }
    .alert-block { border-radius: 10px; border: 1px solid rgba(96,165,250,.12); overflow: hidden; }
    .alert-block-header {
      padding: 8px 13px; background: rgba(37,99,235,.14);
      display: flex; justify-content: space-between; align-items: center;
    }
    .alert-block-label { font-size: .78rem; font-weight: 700; color: #93c5fd; text-transform: uppercase; letter-spacing: .04em; }
    .alert-block-count { font-size: .82rem; font-weight: 700; color: #f0f8ff; }
    .alert-block-list  { padding: 10px; display: flex; flex-direction: column; gap: 6px; }
    .alert-item { padding: 8px 10px; border-radius: 7px; background: rgba(15,33,60,.8); font-size: .78rem; }
    .alert-item-client { color: #e2f0ff; font-weight: 600; }
    .alert-item-meta   { color: var(--muted); font-size: .72rem; margin-top: 2px; }
    .alert-modal-footer {
      padding: 14px 20px; border-top: 1px solid rgba(96,165,250,.14);
      background: rgba(8,22,44,.4); display: flex; justify-content: flex-end; gap: 10px;
    }
    .jur-btn-secondary {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 0 16px; height: 36px; border-radius: 8px;
      border: 1px solid rgba(96,165,250,.3); background: transparent;
      color: #93c5fd; font-family: inherit; font-size: .82rem; font-weight: 600; cursor: pointer;
      transition: background .18s;
    }
    .jur-btn-secondary:hover { background: rgba(37,99,235,.15); }
    .jur-btn-primary {
      padding: 0 18px; height: 36px; border-radius: 8px;
      background: linear-gradient(135deg, #1A3A5C, #244E7A);
      border: 1px solid rgba(160,180,210,0.20);
      color: #C8D4E0; font-family: inherit; font-size: .82rem; font-weight: 600; cursor: pointer;
      box-shadow: 0 2px 8px rgba(0,0,0,0.35); transition: transform .18s, box-shadow .18s;
    }
    .jur-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(20,58,100,0.40); background: linear-gradient(135deg, #244E7A, #2E6090); }

    /* ── Alert popup modal — tema claro ── */
    html[data-theme="light"] #alertModal { background: rgba(15,31,54,0.45); }
    html[data-theme="light"] .alert-modal-box {
      background: linear-gradient(165deg, #FFFFFF 0%, #F4F7FB 100%);
      border: 1px solid rgba(15,31,54,0.14);
      box-shadow: 0 24px 60px rgba(15,31,54,0.18);
    }
    html[data-theme="light"] .alert-modal-header {
      background: #F7F9FC;
      border-bottom: 1px solid rgba(15,31,54,0.10);
    }
    html[data-theme="light"] .alert-modal-title { color: #0F1F36; }
    html[data-theme="light"] .alert-block {
      border: 1px solid rgba(15,31,54,0.10);
    }
    html[data-theme="light"] .alert-block-header {
      background: rgba(37,99,235,0.08);
    }
    html[data-theme="light"] .alert-block-label { color: #1E4A8A; }
    html[data-theme="light"] .alert-block-count { color: #0F1F36; }
    html[data-theme="light"] .alert-item {
      background: #FFFFFF;
      border: 1px solid rgba(15,31,54,0.08);
    }
    html[data-theme="light"] .alert-item-client { color: #0F1F36; }
    html[data-theme="light"] .alert-item-meta   { color: #5A6B7E; }
    html[data-theme="light"] .alert-modal-footer {
      background: #F7F9FC;
      border-top: 1px solid rgba(15,31,54,0.10);
    }
    html[data-theme="light"] .jur-btn-secondary {
      background: #FFFFFF;
      border-color: rgba(15,31,54,0.18);
      color: #1E4A8A;
    }
    html[data-theme="light"] .jur-btn-secondary:hover {
      background: rgba(37,99,235,0.08);
      border-color: rgba(37,99,235,0.30);
    }
    html[data-theme="light"] .jur-btn-primary {
      background: linear-gradient(135deg, #1E4A8A, #2563EB);
      border: 1px solid rgba(30,74,138,0.40);
      color: #FFFFFF;
      box-shadow: 0 2px 8px rgba(30,74,138,0.20);
    }
    html[data-theme="light"] .jur-btn-primary:hover {
      background: linear-gradient(135deg, #2563EB, #3B82F6);
      box-shadow: 0 4px 14px rgba(37,99,235,0.30);
    }

    @media (max-width: 1100px) {
      .kpi-grid-6 { grid-template-columns: repeat(3,1fr) !important; }
      .prox-grid  { grid-template-columns: repeat(2,1fr) !important; }
      .charts-grid-3 { grid-template-columns: repeat(2,1fr) !important; }
    }
    @media (max-width: 640px) {
      .kpi-grid-6 { grid-template-columns: repeat(2,1fr) !important; }
      .prox-grid  { grid-template-columns: 1fr !important; }
      .charts-grid-3 { grid-template-columns: 1fr !important; }
    }

    /* ──────────────────────────────────────────────────────────────────────
       TEMA CLARO — overrides para a aba Jurídico.
       Princípio: fundo claro → texto escuro; mantém identidade da cor.
       ────────────────────────────────────────────────────────────────────── */
    html[data-theme="light"] .jur-panel {
      background: #FFFFFF;
      border: 1px solid #E2E8F0;
      box-shadow: 0 1px 3px rgba(15,23,42,.04);
    }
    html[data-theme="light"] .jur-title         { color: #0F172A; }
    html[data-theme="light"] .jur-subtitle      { color: #64748B; }
    html[data-theme="light"] .jur-section-title { color: #0F172A; }

    /* Distribuição por Responsável — formato card no tema claro */
    html[data-theme="light"] .resp-row {
      background: #F8FAFC;
      border-color: #E2E8F0;
    }
    html[data-theme="light"] .resp-row:hover {
      background: #F1F5F9;
      border-color: #CBD5E1;
    }
    html[data-theme="light"] .resp-name  { color: #0F172A; }
    html[data-theme="light"] .resp-bar-wrap {
      background: #E2E8F0;
    }
    html[data-theme="light"] .resp-bar {
      background: linear-gradient(90deg, #1D4ED8, #3B82F6);
    }
    html[data-theme="light"] .resp-count { color: #1D4ED8; }
    html[data-theme="light"] .resp-pct   { color: #64748B; }

    /* Prazos desta semana (list-row) */
    html[data-theme="light"] .list-row {
      background: #F8FAFC;
      border-color: #E2E8F0;
      color: #1E3A5F;
    }
    html[data-theme="light"] .list-row [style*="color:#e2f0ff"],
    html[data-theme="light"] .list-row [style*="color: #e2f0ff"] { color: #0F172A !important; }
    html[data-theme="light"] .list-row [style*="color:var(--muted)"] { color: #64748B !important; }

    /* Alertas de Prazos (toggle rows) */
    html[data-theme="light"] .alert-row {
      border-bottom-color: #EDF2F7;
    }
    html[data-theme="light"] .alert-label    { color: #0F172A; }
    html[data-theme="light"] .alert-sublabel { color: #64748B; }
  </style>
</head>
<body>
  <main class="w-full px-6 py-6 page-above-fog">
    <div class="page-layout">

      <!-- ── Sidebar ── -->
      <?php include __DIR__ . '/includes/sidebar.php'; ?>

      <!-- ── Main content ── -->
      <section class="main-content space-y-4">

        <!-- Header — padrão .page-header (yuris-theme.css) -->
        <div class="jur-panel page-header">
          <div class="page-header-inner">
            <div class="page-header-text">
              <h2 class="page-header-title">Painel Jurídico</h2>
              <p class="page-header-subtitle">Visão gerencial e estratégica — desempenho, risco operacional e distribuição da carteira</p>
            </div>
          </div>
        </div>

        <!--
          KPI estratégicos — diferenciados de processos.php (que tem visão operacional diária).
          Aqui o foco é: volume total, produtividade, risco latente, distribuição da carteira.
          IDs alimentados por juridico.js via /api/processes.php + /api/juridico_metrics.php
        -->
        <div class="kpi-grid-6" style="display:grid;grid-template-columns:repeat(6,1fr);gap:12px">

          <!-- Total de processos ativos na carteira -->
          <div class="kpi-card">
            <div class="kpi-dot dot-primary"></div>
            <div class="kpi-label">Carteira Ativa</div>
            <div class="kpi-value" id="statActiveVal">—</div>
            <div class="kpi-foot">processos em andamento</div>
          </div>

          <!-- Processos encerrados (arquivados + encerrados) — indica produtividade -->
          <div class="kpi-card kpi-ok">
            <div class="kpi-dot dot-ok"></div>
            <div class="kpi-label">Encerrados</div>
            <div class="kpi-value" id="statEncerradosVal">—</div>
            <div class="kpi-foot">arquivados e finalizados</div>
          </div>

          <!-- Novos processos abertos neste mês — indica crescimento da carteira -->
          <div class="kpi-card">
            <div class="kpi-dot dot-neutral"></div>
            <div class="kpi-label">Novos este Mês</div>
            <div class="kpi-value" id="statNovosMesVal">—</div>
            <div class="kpi-foot">abertos no mês corrente</div>
          </div>

          <!-- Processos vencidos (prazo ultrapassado, status ativo) — risco crítico -->
          <div class="kpi-card kpi-danger">
            <div class="kpi-dot dot-danger"></div>
            <div class="kpi-label">Vencidos</div>
            <div class="kpi-value" id="statVencidosVal">—</div>
            <div class="kpi-foot">prazo já ultrapassado</div>
          </div>

          <!-- Processos sem movimentação há 30+ dias — risco de abandono -->
          <div class="kpi-card kpi-warn">
            <div class="kpi-dot dot-warn"></div>
            <div class="kpi-label">Sem Movimentação</div>
            <div class="kpi-value" id="statSemMovVal">—</div>
            <div class="kpi-foot">parados há mais de 30 dias</div>
          </div>

          <!-- Advogados com processos ativos — distribuição da equipe -->
          <div class="kpi-card">
            <div class="kpi-dot dot-neutral"></div>
            <div class="kpi-label">Advogados Ativos</div>
            <div class="kpi-value" id="statAdvogadosVal">—</div>
            <div class="kpi-foot">com processos na carteira</div>
          </div>

        </div>

        <!-- Resumo executivo estratégico — gerado por juridico.js com dados reais -->
        <div class="jur-panel" style="padding:14px 18px">
          <div id="resumoJuridico" class="summary-box">Carregando análise estratégica da carteira...</div>
        </div>

        <!--
          Gráficos estratégicos linha 1 — renderizados por juridico_charts.js
          Fontes: /api/processes.php (processos) + /api/juridico_metrics.php (nomes de advogados)
          Estes gráficos são ESTRATÉGICOS: distribuição, tendências, concentração — não urgência diária.
        -->
        <div class="charts-grid-3" style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
          <!-- Distribuição por status: ativo / arquivado / encerrado -->
          <div class="chart-card">
            <div class="chart-title">Processos por Status</div>
            <canvas id="statusDonut" style="max-height:240px"></canvas>
          </div>
          <!-- Mapa de calor semanal: prazos nos próximos 7 dias por dia da semana -->
          <div class="chart-card">
            <div class="chart-title">Concentração de Prazos — 7 dias</div>
            <canvas id="deadlinesWeekChart" style="max-height:240px"></canvas>
          </div>
          <!-- Curva anual: quantos processos têm prazo por mês (visão de sazonalidade) -->
          <div class="chart-card">
            <div class="chart-title">Processos por Mês de Prazo</div>
            <canvas id="hearingsLineChart" style="max-height:240px"></canvas>
          </div>
        </div>

        <!--
          Gráficos estratégicos linha 2 — desempenho por advogado, tipos de ação, taxa de encerramento
          Fonte: /api/processes.php + by_lawyer de /api/juridico_metrics.php
        -->
        <div class="charts-grid-3" style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
          <!-- Carga processual por advogado — identifica sobrecarga da equipe -->
          <div class="chart-card">
            <div class="chart-title">Carga por Advogado</div>
            <canvas id="byLawyerBar" style="max-height:300px"></canvas>
          </div>
          <!-- Tipos de ação mais comuns — mostra especialidades e demandas frequentes -->
          <div class="chart-card">
            <div class="chart-title">Tipos de Ação Mais Frequentes</div>
            <canvas id="typesBar" style="max-height:300px"></canvas>
          </div>
          <!-- Taxa de finalização do mês corrente — eficiência operacional -->
          <div class="chart-card">
            <div class="chart-title">Taxa de Finalização (mês)</div>
            <canvas id="completionGauge" style="max-height:200px"></canvas>
            <div id="completionLabel" class="mt-2 text-sm" style="color:var(--muted)"></div>
          </div>
        </div>

        <!--
          Prazos Críticos — visão estratégica das janelas de prazo.
          Diferença de processos.php: aqui o objetivo é ver O VOLUME por janela (gerencial),
          não o controle diário individual. Alimentado por juridico.js via metricsData.
        -->
        <div class="jur-panel" id="secaoProximos">
          <div class="mb-4">
            <h3 class="jur-section-title">Prazos Críticos — Visão por Janela</h3>
            <p class="jur-subtitle">Volume de processos por janela de prazo — use a aba Processos para controle diário individual</p>
          </div>
          <div class="prox-grid">
            <!-- Hoje -->
            <div class="prox-card">
              <div class="prox-card-header" style="background:rgba(239,68,68,.12);border-bottom-color:rgba(239,68,68,.2)">
                <span class="prox-card-label" style="color:#fca5a5">Hoje</span>
                <span class="prox-card-count" id="proxHojeCount">—</span>
              </div>
              <div class="prox-card-list" id="proxHojeList"></div>
            </div>
            <!-- 7 dias -->
            <div class="prox-card">
              <div class="prox-card-header" style="background:rgba(245,158,11,.1);border-bottom-color:rgba(245,158,11,.2)">
                <span class="prox-card-label" style="color:#fcd34d">7 dias</span>
                <span class="prox-card-count" id="prox7Count">—</span>
              </div>
              <div class="prox-card-list" id="prox7List"></div>
            </div>
            <!-- 15 dias -->
            <div class="prox-card">
              <div class="prox-card-header" style="background:rgba(59,130,246,.1);border-bottom-color:rgba(59,130,246,.2)">
                <span class="prox-card-label" style="color:#93c5fd">15 dias</span>
                <span class="prox-card-count" id="prox15Count">—</span>
              </div>
              <div class="prox-card-list" id="prox15List"></div>
            </div>
            <!-- 30 dias -->
            <div class="prox-card">
              <div class="prox-card-header" style="background:rgba(16,185,129,.1);border-bottom-color:rgba(16,185,129,.2)">
                <span class="prox-card-label" style="color:#6ee7b7">30 dias</span>
                <span class="prox-card-count" id="prox30Count">—</span>
              </div>
              <div class="prox-card-list" id="prox30List"></div>
            </div>
          </div>
        </div>

        <!--
          Distribuição por Responsável — carga por advogado em formato lista com barra de progresso.
          Alimentado por juridico.js: computeByLawyer() + renderDistResponsavel().
          Prefere dados de metricsData.by_lawyer (tem nomes reais via JOIN) — fallback para IDs.
        -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="jur-panel">
            <h3 class="jur-section-title mb-3">Distribuição por Responsável</h3>
            <div id="distResponsavelList"></div>
            <!-- IDs legacy mantidos mas ocultos para não quebrar juridico.js -->
            <div id="topLawyers"   style="display:none"></div>
            <div id="byLawyerList" style="display:none"></div>
          </div>
          <div class="jur-panel">
            <h3 class="jur-section-title mb-3">Prazos desta semana</h3>
            <div id="deadlinesList"></div>
          </div>
        </div>

        <!-- Legacy audiências (mantém ID) -->
        <div id="hearingsList" style="display:none"></div>

        <!-- Alertas de prazos -->
        <div class="jur-panel">
          <div class="mb-4">
            <h3 class="jur-section-title">Alertas de Prazos</h3>
            <p class="jur-subtitle">Configure notificações popup exibidas ao entrar no sistema</p>
          </div>
          <div>
            <div class="alert-row">
              <div>
                <div class="alert-label">Notificar prazos de hoje</div>
                <div class="alert-sublabel">Exibe popup com processos que vencem no dia</div>
              </div>
              <label class="toggle-wrap">
                <input type="checkbox" id="alertToggleHoje">
                <span class="toggle-slider"></span>
              </label>
            </div>
            <div class="alert-row">
              <div>
                <div class="alert-label">Notificar próximos 7 dias</div>
                <div class="alert-sublabel">Prazos nos próximos 7 dias</div>
              </div>
              <label class="toggle-wrap">
                <input type="checkbox" id="alertToggle7">
                <span class="toggle-slider"></span>
              </label>
            </div>
            <div class="alert-row">
              <div>
                <div class="alert-label">Notificar próximos 15 dias</div>
                <div class="alert-sublabel">Prazos nos próximos 15 dias</div>
              </div>
              <label class="toggle-wrap">
                <input type="checkbox" id="alertToggle15">
                <span class="toggle-slider"></span>
              </label>
            </div>
            <div class="alert-row">
              <div>
                <div class="alert-label">Notificar próximos 30 dias</div>
                <div class="alert-sublabel">Prazos nos próximos 30 dias</div>
              </div>
              <label class="toggle-wrap">
                <input type="checkbox" id="alertToggle30">
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>
          <!-- IDs legado mantidos ocultos -->
          <div id="staleCards" style="display:none"></div>
          <div id="statNoUpdateVal" style="display:none"></div>
        </div>

      </section>
    </div>
  </main>

  <!-- ── Alert popup ── -->
  <div id="alertModal" class="hidden">
    <div class="alert-modal-box">
      <div class="alert-modal-header">
        <span class="alert-modal-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:5px"><path d="M12 3v18"/><path d="M5 6l7-3 7 3"/><path d="M5 6 2 13h6z"/><path d="M19 6l-3 7h6z"/><line x1="3" y1="18" x2="21" y2="18"/></svg> Alertas de Processos Próximos</span>
        <button id="alertModalClose" class="jur-btn-secondary" style="height:30px;padding:0 10px;font-size:.78rem"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Fechar</button>
      </div>
      <div class="alert-modal-body" id="alertModalBody"></div>
      <div class="alert-modal-footer">
        <button id="alertModalClose2" class="jur-btn-secondary">Fechar</button>
        <button id="alertModalVerBtn" class="jur-btn-primary">Ver processos</button>
      </div>
    </div>
  </div>

  <script src="/assets/juridico.js?v=<?= filemtime(__DIR__ . '/assets/juridico.js') ?>"></script>
  <script src="/assets/juridico_charts.js?v=3<?= filemtime(__DIR__ . '/assets/juridico_charts.js') ?>"></script>
  <script src="/assets/fog.js"></script>
  <script>
    // Dashboard status mirror
    try {
      var saved = localStorage.getItem('dashboard_last_update');
      if (saved) { var el = document.getElementById('dashboardStatus'); if (el) { el.textContent = saved; el.style.color = '#4ade80'; } }
      window.addEventListener('storage', function(e){
        if (!e || e.key !== 'dashboard_last_update') return;
        var el = document.getElementById('dashboardStatus'); if (el) { el.textContent = e.newValue; el.style.color = '#4ade80'; }
      });
    } catch(e) { console.warn('mirror dashboard status failed', e); }
  </script>

</body>
</html>

