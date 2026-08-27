<?php
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Usuarios/User.php';
require_once __DIR__ . '/../app/Master/Account.php';
require_once __DIR__ . '/../app/Master/ResourceShare.php';
require_once __DIR__ . '/../app/Financeiro/DREAccount.php';
require_once __DIR__ . '/../app/Core/AccountContext.php';
use App\Core\Database;
use App\Financeiro\DREAccount;
use App\Core\AccountContext;
session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$activePage = 'dashboard';

// Contexto de tenant — obrigatório para evitar vazamento entre contas.
$ctx       = AccountContext::fromSession();
$ctx->assertAccountActive(); // bloqueia conta suspensa/cancelada/inativa
$tenantIds = $ctx->getAccessibleAccountIds('dashboard');
// Guard: array vazio quebraria SQL IN (). Garante pelo menos o próprio account_id (0 = nenhum match).
if (empty($tenantIds)) $tenantIds = [0];

// Contas acessíveis com info de tipo (matriz/filial) — alimenta filtro de Origem
$origin_accounts = [];
$origin_self     = [
    'id'   => $ctx->getAccountId(),
    'tipo' => $ctx->getAccountTipo(),
    'nome' => $_SESSION['account_nome'] ?? '',
];
if ($ctx->isMatriz()) {
    try {
        // FIX (auditoria 2026-06-01): matriz_id e SEMPRE NULL — deriva as contas do
        // conjunto acessivel ($tenantIds, ja vindo de getAccessibleAccountIds via
        // account_vinculos). Sem isso o filtro de Origem do Dashboard ficava morto.
        $accIds = $tenantIds; // conjunto acessivel pre-filtro de origem
        if (count($accIds) > 1) {
            $pdo_oa = Database::getConnection();
            $ph_oa  = implode(',', array_fill(0, count($accIds), '?'));
            $stmt_oa = $pdo_oa->prepare(
                "SELECT id, nome, tipo
                 FROM accounts
                 WHERE id IN ($ph_oa) AND deleted_at IS NULL
                   AND status IN ('active','trial','overdue')
                 ORDER BY CASE WHEN tipo = 'matriz' THEN 0 ELSE 1 END, nome ASC"
            );
            $stmt_oa->execute(array_map('intval', $accIds));
            $origin_accounts = $stmt_oa->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {}
}

// Filtro de Origem (?origin=__matriz__|__filiais__|<id>) — recorta $tenantIds.
// Restringe ao conjunto ACESSÍVEL — qualquer id fora dele é ignorado (segurança).
$selected_origin = isset($_GET['origin']) ? trim((string)$_GET['origin']) : '';
if ($selected_origin !== '' && count($origin_accounts) > 1) {
    // FIX (auditoria 2026-06-01 — MÉDIA #9/#19): o YURIS tem 3 tipos de conta
    // (matriz | filial | advogado). getAccessibleAccountIds('dashboard') já inclui
    // advogados vinculados, então eles entram nos KPIs agregados — mas a
    // classificação antiga só mapeava matriz/filial, deixando o advogado fora do
    // recorte de origem. Aqui:
    //   - '__filiais__'  recorta TODAS as origens não-matriz (filiais + advogados),
    //     conforme decisão de tratar advogado como "não-matriz" no recorte;
    //   - '__advogados__' recorta só os advogados vinculados (isolamento fino).
    $matrizIds    = array_map(fn($a) => (int)$a['id'], array_filter($origin_accounts, fn($a) => $a['tipo'] === 'matriz'));
    $filiaisIds   = array_map(fn($a) => (int)$a['id'], array_filter($origin_accounts, fn($a) => $a['tipo'] === 'filial'));
    $advogadoIds  = array_map(fn($a) => (int)$a['id'], array_filter($origin_accounts, fn($a) => $a['tipo'] === 'advogado'));
    $naoMatrizIds = array_map(fn($a) => (int)$a['id'], array_filter($origin_accounts, fn($a) => $a['tipo'] !== 'matriz'));
    if ($selected_origin === '__matriz__') {
        $tenantIds = $matrizIds ?: [0];
    } elseif ($selected_origin === '__filiais__') {
        $tenantIds = $naoMatrizIds ?: [0];
    } elseif ($selected_origin === '__advogados__') {
        $tenantIds = $advogadoIds ?: [0];
    } else {
        $sid = (int)$selected_origin;
        // Só permite ids que pertencem ao conjunto acessível
        $allowed = array_map(fn($a) => (int)$a['id'], $origin_accounts);
        $tenantIds = (in_array($sid, $allowed, true) && $sid > 0) ? [$sid] : [0];
    }
}

// Popup de prazos: aparece somente na primeira visita após login.
// O logout (session_destroy) limpa o flag automaticamente.
$show_deadline_popup = empty($_SESSION['deadline_popup_shown']);
$_SESSION['deadline_popup_shown'] = true;

// ── DRE summary (server-side) ────────────────────────────────────────────────
// Renderizado direto no HTML para evitar "flash" de dados antigos durante o load do JS.
// Filtrado por account_id IN (tenantIds) — uma conta nova vê tudo zerado.
// Se houver período persistido em sessão, também aplica para não mostrar valores fora do range.
$dre_receita = $dre_despesa = $dre_lucro = $dre_margem = 0;
try {
    $pdo_dre = Database::getConnection();

    // Monta cláusula IN(tenantIds)
    $ph = []; $tenantParams = [];
    foreach ($tenantIds as $i => $aid) { $k = "dpsacc_{$i}"; $ph[] = ":{$k}"; $tenantParams[$k] = (int)$aid; }
    $inSql = '(' . implode(',', $ph) . ')';

    // Período persistido na sessão (definido pelo dashboard_settings.php)
    $ds_start = !empty($_SESSION['dashboard_start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_SESSION['dashboard_start']) ? $_SESSION['dashboard_start'] : null;
    $ds_end   = !empty($_SESSION['dashboard_end'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_SESSION['dashboard_end'])   ? $_SESSION['dashboard_end']   : null;

    // ── Cards fechados (no período se houver, senão tudo)
    $cardsWhere = "WHERE deleted_at IS NULL
                     AND (status='fechado' OR (data_fechamento IS NOT NULL AND data_fechamento>'0000-00-00'))
                     AND account_id IN $inSql";
    $cardsParams = $tenantParams;
    if ($ds_start) { $cardsWhere .= ' AND data_fechamento >= :ds_start'; $cardsParams['ds_start'] = $ds_start; }
    if ($ds_end)   { $cardsWhere .= ' AND data_fechamento <= :ds_end';   $cardsParams['ds_end']   = $ds_end; }
    $st_dre = $pdo_dre->prepare(
        "SELECT COALESCE(SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))),0) AS ct
         FROM cards $cardsWhere"
    );
    $st_dre->execute($cardsParams);
    $row_dre = $st_dre->fetch(PDO::FETCH_ASSOC);
    $closed_total = (float)($row_dre['ct'] ?? 0);

    // ── DRE accounts (lógica de recorrência: fixa = a partir de data_referencia; avulsa = só no range)
    if ($ds_start || $ds_end) {
        $dreWhere  = "WHERE ativo = 1 AND account_id IN $inSql";
        $dreParams = $tenantParams;
        if ($ds_start && $ds_end) {
            $dreWhere .= "
                AND (
                  (recorrencia = 'fixa' AND (data_referencia IS NULL OR data_referencia <= :ds_end))
                  OR
                  (recorrencia <> 'fixa' AND data_referencia BETWEEN :ds_start AND :ds_end)
                )";
            $dreParams['ds_start'] = $ds_start;
            $dreParams['ds_end']   = $ds_end;
        } elseif ($ds_end) {
            $dreWhere .= " AND (recorrencia = 'fixa' AND (data_referencia IS NULL OR data_referencia <= :ds_end))
                           OR (recorrencia <> 'fixa' AND data_referencia <= :ds_end)";
            $dreParams['ds_end'] = $ds_end;
        } else { // só ds_start
            $dreWhere .= " AND (recorrencia = 'fixa' OR (recorrencia <> 'fixa' AND data_referencia >= :ds_start))";
            $dreParams['ds_start'] = $ds_start;
        }
        $st = $pdo_dre->prepare("SELECT tipo, COALESCE(SUM(valor_fixo),0) AS total FROM dre_accounts $dreWhere GROUP BY tipo");
        $st->execute($dreParams);
        $dre_receita = $dre_despesa = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['tipo'] === 'receita') $dre_receita = (float)$r['total'];
            if ($r['tipo'] === 'despesa') $dre_despesa = (float)$r['total'];
        }
    } else {
        $dre_summary = DREAccount::summary(['account_ids' => $tenantIds]);
        $dre_receita = (float)($dre_summary['receita'] ?? 0);
        $dre_despesa = (float)($dre_summary['despesa'] ?? 0);
    }

    $dre_receita += $closed_total;
    $dre_lucro    = $dre_receita - $dre_despesa;
    $dre_margem   = $dre_receita > 0 ? round(($dre_lucro / $dre_receita) * 100, 1) : 0;
} catch(Exception $e) { /* silently use zeros */ }

function fmtBRL($n){ return 'R$ ' . number_format($n, 2, ',', '.'); }
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Dashboard Executivo — Yuris</title>
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
      --bg-main: #081526;
      --line: rgba(191,199,213,0.12);
      --line-strong: rgba(191,199,213,0.22);
      --text: #E8EDF5;
      --muted: #8A96A8;
      --primary: #2E5587;
      --ok: #2E7D5E;
      --warn: #8B6914;
      --danger: #8B2635;
      --radius: 14px;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      background-color: #081526;
      background-image: radial-gradient(ellipse at 20% 50%, rgba(30,58,95,0.15) 0%, transparent 60%), radial-gradient(ellipse at 80% 20%, rgba(46,85,135,0.10) 0%, transparent 60%);
      background-attachment: fixed;
      color: var(--text);
      font-family: Inter, 'Poppins', system-ui, -apple-system, sans-serif;
      min-height: 100vh;
    }

    /* ── Panel ── */
    .dash-panel {
      background: linear-gradient(165deg, rgba(14,35,65,.94), rgba(10,23,43,.96));
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 20px;
      box-shadow: 0 14px 40px rgba(2,6,23,.45);
    }
    .dash-panel.compact { padding: 14px 18px; }

    /* ── Section label ── */
    .section-eyebrow { font-size: .68rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 8px; }

    /* ── KPI Cards ── */
    .kpi-card {
      position: relative; overflow: hidden; border-radius: 14px;
      border: 1px solid rgba(96,165,250,.18);
      background: linear-gradient(135deg, rgba(13,31,56,.96), rgba(8,19,37,.97));
      padding: 14px 16px; min-height: 96px;
      transition: transform .2s, border-color .2s, box-shadow .2s;
    }
    .kpi-card:hover { transform: translateY(-3px); border-color: rgba(96,165,250,.4); box-shadow: 0 10px 26px rgba(37,99,235,.2); }
    .kpi-card.kpi-ok     { border-color: rgba(16,185,129,.3); }
    .kpi-card.kpi-ok:hover { border-color: rgba(16,185,129,.55); box-shadow: 0 10px 26px rgba(16,185,129,.18); }
    .kpi-card.kpi-warn   { border-color: rgba(245,158,11,.3); }
    .kpi-card.kpi-warn:hover { border-color: rgba(245,158,11,.55); box-shadow: 0 10px 26px rgba(245,158,11,.18); }
    .kpi-card.kpi-danger { border-color: rgba(239,68,68,.3); }
    .kpi-card.kpi-danger:hover { border-color: rgba(239,68,68,.55); box-shadow: 0 10px 26px rgba(239,68,68,.18); }
    .kpi-label { color: #a8c2df; font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
    .kpi-value { margin-top: 8px; color: #f0f8ff; font-size: 1.28rem; font-weight: 700; line-height: 1.15; }
    .kpi-value.sm { font-size: 1rem; }
    .kpi-foot  { margin-top: 5px; color: var(--muted); font-size: .69rem; }
    .kpi-dot   { position: absolute; top: 12px; right: 12px; width: 8px; height: 8px; border-radius: 50%; }
    .dot-ok      { background: var(--ok);     box-shadow: 0 0 6px rgba(16,185,129,.7); }
    .dot-warn    { background: var(--warn);   box-shadow: 0 0 6px rgba(245,158,11,.7); }
    .dot-danger  { background: var(--danger); box-shadow: 0 0 6px rgba(239,68,68,.7);  }
    .dot-neutral { background: rgba(148,163,184,.4); }
    .dot-primary { background: var(--primary); box-shadow: 0 0 6px rgba(59,130,246,.7); }

    /* ── Executive summary ── */
    .exec-summary {
      border-radius: 10px; border: 1px solid rgba(96,165,250,.2);
      background: rgba(8,20,40,.55); padding: 12px 16px;
      color: #d2e8ff; font-size: .87rem; line-height: 1.65;
    }
    .exec-summary strong { color: #93c5fd; }

    /* ── Alert strip ── */
    .alert-strip { display: flex; flex-wrap: wrap; gap: 8px; }
    .alert-pill {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 6px 12px; border-radius: 999px; font-size: .76rem; font-weight: 600;
      cursor: default;
    }
    .alert-danger  { background: rgba(239,68,68,.15);   color: #fca5a5; border: 1px solid rgba(239,68,68,.35); }
    .alert-warn    { background: rgba(245,158,11,.15);  color: #fcd34d; border: 1px solid rgba(245,158,11,.35); }
    .alert-ok      { background: rgba(16,185,129,.12);  color: #6ee7b7; border: 1px solid rgba(16,185,129,.3);  }
    .alert-info    { background: rgba(59,130,246,.14);  color: #93c5fd; border: 1px solid rgba(59,130,246,.32); }

    /* ── Filters / period bar ── */
    .filter-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .filter-btn {
      padding: 5px 13px; border-radius: 8px; border: 1px solid rgba(96,165,250,.25);
      background: rgba(8,22,44,.85); color: #93c5fd;
      font-family: inherit; font-size: .78rem; font-weight: 600; cursor: pointer;
      transition: background .18s, border-color .18s;
    }
    .filter-btn:hover { background: rgba(37,99,235,.2); border-color: rgba(96,165,250,.45); }
    .filter-btn.primary { background: linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; border-color:transparent; box-shadow:0 3px 10px rgba(37,99,235,.3); }
    .filter-input {
      height: 34px; padding: 0 10px;
      border-radius: 8px; border: 1px solid rgba(96,165,250,.22);
      background: rgba(255,255,255,.93); color: #0f172a;
      font-family: inherit; font-size: .78rem;
    }
    .filter-input:focus { outline: none; border-color: #3b82f6; }

    /* ── Meta widget ── */
    .meta-widget {
      display: inline-flex; align-items: center; gap: 16px; flex-wrap: wrap;
      padding: 12px 16px; border-radius: 12px;
      background: rgba(8,22,44,.7); border: 1px solid rgba(96,165,250,.18);
    }
    .meta-widget .meta-label { font-size: .72rem; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
    .meta-widget .meta-val   { font-size: 1.35rem; font-weight: 700; color: #f0f8ff; }
    .meta-widget-btn {
      padding: 7px 14px; border-radius: 8px; border: 1px solid transparent;
      font-family: inherit; font-size: .8rem; font-weight: 600; cursor: pointer;
      transition: opacity .18s, transform .12s, background .18s, color .18s, border-color .18s;
    }
    .meta-widget-btn:hover { opacity: .92; transform: translateY(-1px); }
    /* Primary (Editar) — azul forte + texto branco. Funciona em ambos os temas. */
    .meta-widget-btn.primary {
      background: linear-gradient(135deg,#2563eb,#1d4ed8);
      color:#fff;
      border-color: rgba(255,255,255,0.08);
      box-shadow:0 3px 10px rgba(37,99,235,.3);
    }
    /* Secondary (Calcular) — no dark tem fundo translúcido azul + texto azul-claro.
       Antes: color #93c5fd em background azul-claro do tema claro = invisível. */
    .meta-widget-btn.secondary {
      background: rgba(37,99,235,.22);
      color:#93c5fd;
      border-color: rgba(96,165,250,.3);
    }
    /* ── Tema claro: override de contraste real ──
       Texto azul forte sobre background azul muito leve = AAA legível. */
    html[data-theme="light"] .meta-widget-btn.secondary {
      background: rgba(29,78,216,.08) !important;
      color: #1d4ed8 !important;
      border-color: rgba(29,78,216,.40) !important;
    }
    html[data-theme="light"] .meta-widget-btn.secondary:hover {
      background: rgba(29,78,216,.14) !important;
      border-color: rgba(29,78,216,.60) !important;
    }
    /* ── Primary no claro: azul vivo, NÃO navy escuro ──
       Causa raiz: yuris-theme.css força `button.primary { background: #1E4A8A
       !important }` em tema claro. Isso vence meu gradient e cria um botão
       quase preto sobre card branco — visualmente pesado. A regra abaixo é
       mais específica (.meta-widget-btn.primary > button.primary) e força um
       azul moderno (#3b82f6 → #2563eb) que combina com card branco. */
    html[data-theme="light"] .meta-widget-btn.primary {
      background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
      color: #FFFFFF !important;
      border-color: rgba(29,78,216,.30) !important;
      box-shadow: 0 3px 10px rgba(59,130,246,.28) !important;
    }
    html[data-theme="light"] .meta-widget-btn.primary:hover {
      background: linear-gradient(135deg, #4a90f6 0%, #2c70ee 100%) !important;
      box-shadow: 0 4px 14px rgba(59,130,246,.40) !important;
    }

    /* ── Chart card ── */
    .chart-card {
      background: linear-gradient(165deg, rgba(14,35,65,.94), rgba(10,23,43,.96));
      border: 1px solid var(--line); border-radius: var(--radius);
      padding: 18px; box-shadow: 0 14px 40px rgba(2,6,23,.45);
    }
    .chart-title { font-size: .9rem; font-weight: 600; color: #dbeafe; margin-bottom: 14px; }

    /* ── Processos próximos ── */
    .prox-card { border-radius: 12px; border: 1px solid rgba(96,165,250,.12); background: rgba(8,20,40,.5); overflow: hidden; }
    .prox-card-hdr { padding: 9px 13px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(96,165,250,.1); }
    .prox-card-lbl { font-size: .74rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .prox-card-cnt { font-size: 1.05rem; font-weight: 700; color: #f0f8ff; }
    .prox-card-list { padding: 8px; display: flex; flex-direction: column; gap: 5px; max-height: 240px; overflow-y: auto; }
    .prox-row { padding: 7px 9px; border-radius: 7px; background: rgba(15,33,60,.8); border: 1px solid rgba(59,130,246,.1); font-size: .74rem; }
    .prox-row-client { color: #e2f0ff; font-weight: 600; }
    .prox-row-meta   { color: var(--muted); font-size: .7rem; margin-top: 1px; }
    .prox-empty { color: var(--muted); font-size: .76rem; padding: 10px; text-align: center; }

    /* ── Funnel / projection (keeps dashboard.js IDs) ── */
    #projectionPanel, #funnelPanel {
      background: linear-gradient(165deg, rgba(14,35,65,.94), rgba(10,23,43,.96));
      border: 1px solid var(--line); border-radius: var(--radius);
      padding: 18px; box-shadow: 0 14px 40px rgba(2,6,23,.45);
    }
    #projectionPanel h3, #funnelPanel h3 { font-size:.9rem; font-weight:600; color:#dbeafe; margin-bottom:10px; }
    #projectionPanel h4 { font-size:.82rem; font-weight:600; color:#c3d9f8; margin-bottom:8px; }
    /* Keep existing chart rules */
    canvas { position: relative; z-index: 0 !important; }
    .chartjs-render-monitor, .chart-container, .chartjs-size-monitor { z-index: 0 !important; }
    #goalModal { position: fixed !important; inset: 0 !important; z-index: 2147483647 !important; display: flex !important; align-items: center; justify-content: center; }
    #goalModal > .bg-white { position: relative !important; z-index: 2147483648 !important; }
    body.modal-open canvas, body.modal-open .chartjs-render-monitor, body.modal-open #funnelChart { visibility: hidden !important; pointer-events: none !important; }
    /* Hide text labels rendered inside funnel SVG slices */
    svg .funnel-label { display: none !important; }

    /* Override dashboard.js legacy classes to match new theme */
    .bg-white {
      background: linear-gradient(165deg, rgba(14,35,65,.94), rgba(10,23,43,.96)) !important;
      color: #e8f4ff !important;
      border: 1px solid var(--line) !important;
      box-shadow: 0 14px 40px rgba(2,6,23,.45) !important;
      border-radius: var(--radius) !important;
    }
    .bg-white input, .bg-white select, .bg-white textarea { color: #0f172a; background: #fff; }
    .bg-white input[type=month] { color: #0f172a !important; }
    button.bg-white { background: #fff !important; color: #0f172a !important; border: 1px solid rgba(10,20,30,.08) !important; box-shadow: none !important; }
    input[type="month"] { color: #0f172a !important; }

    @media (max-width: 1100px) {
      .kpi-cols-5  { grid-template-columns: repeat(3,1fr) !important; }
      .kpi-cols-4  { grid-template-columns: repeat(2,1fr) !important; }
      .charts-2col { grid-template-columns: 1fr !important; }
    }
    @media (max-width: 640px) {
      .kpi-cols-5, .kpi-cols-4 { grid-template-columns: repeat(2,1fr) !important; }
      .prox-4col { grid-template-columns: repeat(2,1fr) !important; }
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

      <!-- Header — padrão .page-header (yuris-theme.css) -->
      <div class="dash-panel page-header">
        <div class="page-header-inner">
          <div class="page-header-text">
            <h2 class="page-header-title">Dashboard Executivo</h2>
            <p class="page-header-subtitle">Visão completa — Comercial · Financeiro · Jurídico · Operacional</p>
            <?php if (count($origin_accounts) > 1):
              // Indicador visual do escopo ativo (matriz com filiais/advogados).
              // FIX (auditoria 2026-06-01 — MÉDIA #9): rotula cada conta pelo TIPO
              // real (matriz/filial/advogado), em vez de assumir "filial" para
              // tudo que não é matriz.
              $_tipoLabel  = ['matriz' => 'Matriz', 'filial' => 'Filial', 'advogado' => 'Advogado'];
              $_nFiliais   = count(array_filter($origin_accounts, fn($a) => $a['tipo'] === 'filial'));
              $_nAdvogados = count(array_filter($origin_accounts, fn($a) => $a['tipo'] === 'advogado'));
              $scope_label = '';
              if ($selected_origin === '__matriz__') $scope_label = 'Apenas Matriz';
              elseif ($selected_origin === '__filiais__') $scope_label = 'Filiais e Advogados vinculados';
              elseif ($selected_origin === '__advogados__') $scope_label = 'Apenas Advogados';
              elseif ($selected_origin !== '') {
                  $found = array_filter($origin_accounts, fn($a) => (string)$a['id'] === $selected_origin);
                  $first = $found ? reset($found) : null;
                  if ($first) $scope_label = ($_tipoLabel[$first['tipo']] ?? 'Conta') . ': ' . $first['nome'];
              } else {
                  // Resumo "Matriz + N filiais + M advogados" (omite os zerados)
                  $_parts = [];
                  if ($_nFiliais > 0)   $_parts[] = $_nFiliais . ' filial' . ($_nFiliais === 1 ? '' : 'is');
                  if ($_nAdvogados > 0) $_parts[] = $_nAdvogados . ' advogado' . ($_nAdvogados === 1 ? '' : 's');
                  $scope_label = 'Todas as origens (Matriz' . ($_parts ? ' + ' . implode(' + ', $_parts) : '') . ')';
              }
            ?>
            <div style="margin-top:6px;font-size:.74rem;color:#93c5fd;display:inline-flex;align-items:center;gap:6px">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:.7"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
              <span>Escopo: <strong style="color:#dbe9ff"><?=htmlspecialchars($scope_label)?></strong></span>
            </div>
            <?php endif; ?>
          </div>
          <div class="page-header-actions" style="display:flex;align-items:center;gap:10px">
            <?php if (count($origin_accounts) > 1): ?>
            <!-- Filtro de Origem (matriz/filial) — visível só para matriz com filiais.
                 Recarrega a página com ?origin=... porque o cálculo é server-side. -->
            <?php
              // FIX (auditoria 2026-06-01 — MÉDIA #9): separa filiais de advogados
              // em optgroups distintos. Antes "if tipo==='matriz' continue" jogava
              // qualquer não-matriz (inclusive advogado) sob o rótulo "Filial",
              // rotulando errado o tipo advogado. "__filiais__" cobre não-matriz;
              // "__advogados__" isola só os advogados.
              $_oaFiliais   = array_filter($origin_accounts, fn($a) => $a['tipo'] === 'filial');
              $_oaAdvogados = array_filter($origin_accounts, fn($a) => $a['tipo'] === 'advogado');
            ?>
            <select id="dashFilterOrigin"
                    style="height:36px;padding:0 12px;border-radius:9px;border:1px solid rgba(96,165,250,.28);background:rgba(5,18,39,.85);color:#d6eaff;font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer">
              <option value="">Todas as origens</option>
              <option value="__matriz__"  <?=$selected_origin==='__matriz__'?'selected':''?>>Apenas Matriz</option>
              <option value="__filiais__" <?=$selected_origin==='__filiais__'?'selected':''?>>Filiais e Advogados</option>
              <?php if (!empty($_oaAdvogados)): ?>
              <option value="__advogados__" <?=$selected_origin==='__advogados__'?'selected':''?>>Apenas Advogados</option>
              <?php endif; ?>
              <?php if (!empty($_oaFiliais)): ?>
              <optgroup label="Filial específica">
                <?php foreach ($_oaFiliais as $oa): ?>
                  <option value="<?=htmlspecialchars((string)$oa['id'])?>" <?=$selected_origin===(string)$oa['id']?'selected':''?>><?=htmlspecialchars($oa['nome'])?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <?php if (!empty($_oaAdvogados)): ?>
              <optgroup label="Advogado vinculado">
                <?php foreach ($_oaAdvogados as $oa): ?>
                  <option value="<?=htmlspecialchars((string)$oa['id'])?>" <?=$selected_origin===(string)$oa['id']?'selected':''?>><?=htmlspecialchars($oa['nome'])?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
            </select>
            <?php endif; ?>
            <button id="reportDashboardBtn" style="display:inline-flex;align-items:center;gap:6px;padding:0 14px;height:36px;border-radius:9px;border:1px solid rgba(96,165,250,.28);background:transparent;color:#93c5fd;font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;transition:background .18s" onmouseover="this.style.background='rgba(37,99,235,.15)'" onmouseout="this.style.background='transparent'">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              Exportar relatório
            </button>
          </div>
        </div>
      </div>

      <!-- Resumo executivo -->
      <div class="dash-panel compact">
        <div id="execSummary" class="exec-summary">Carregando visão executiva...</div>
      </div>

      <!-- Alertas executivos -->
      <div id="alertsContainer" class="alert-strip" style="display:none"></div>

      <!-- ── COMERCIAL KPIs ── -->
      <div>
        <div class="section-eyebrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><rect x="3" y="12" width="4" height="9"/><rect x="10" y="7" width="4" height="14"/><rect x="17" y="3" width="4" height="18"/></svg> Comercial</div>
        <div class="kpi-cols-5" style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px">
          <div class="kpi-card kpi-ok">
            <div class="kpi-dot dot-ok"></div>
            <div class="kpi-label">Contratos Fechados</div>
            <div class="kpi-value" id="closedValue">R$ 0,00</div>
            <div class="kpi-foot">receita confirmada</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-dot dot-primary"></div>
            <div class="kpi-label">Meta Mensal</div>
            <div class="kpi-value" id="metaTopValue">R$ 0,00</div>
            <div class="kpi-foot"><span id="metaProgresso">—</span></div>
          </div>
          <div class="kpi-card">
            <div class="kpi-dot dot-neutral"></div>
            <div class="kpi-label">Receita Projetada</div>
            <div class="kpi-value" id="projectionValue">R$ 0,00</div>
            <div class="kpi-foot">forecast do período</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-dot dot-neutral"></div>
            <div class="kpi-label">Oportunidades</div>
            <div class="kpi-value" id="opportunitiesValue">0</div>
            <div class="kpi-foot">leads no funil</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-dot dot-ok"></div>
            <div class="kpi-label">Clientes Ativos</div>
            <div class="kpi-value" id="clientsActiveValue">0</div>
            <div class="kpi-foot">contratos fechados</div>
          </div>
        </div>
      </div>

      <!-- ── FINANCEIRO KPIs (DRE — updated dynamically by filter) ── -->
      <div>
        <div class="section-eyebrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M9 9h4.5a1.5 1.5 0 0 1 0 3h-5a1.5 1.5 0 0 0 0 3H15"/></svg> Financeiro — DRE</div>
        <div class="kpi-cols-4" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
          <div class="kpi-card kpi-ok" id="dreReceitaCard">
            <div class="kpi-dot dot-ok" id="dreDotReceita"></div>
            <div class="kpi-label">Receita Operacional</div>
            <div class="kpi-value" id="dreReceita"><?= fmtBRL($dre_receita) ?></div>
            <div class="kpi-foot">honorários + DRE</div>
          </div>
          <div class="kpi-card kpi-danger" id="dreDespesaCard">
            <div class="kpi-dot dot-danger" id="dreDotDespesa"></div>
            <div class="kpi-label">Custos Operacionais</div>
            <div class="kpi-value" id="dreDespesa"><?= fmtBRL($dre_despesa) ?></div>
            <div class="kpi-foot">total de despesas</div>
          </div>
          <div class="kpi-card <?= $dre_lucro >= 0 ? 'kpi-ok' : 'kpi-danger' ?>" id="dreLucroCard">
            <div class="kpi-dot <?= $dre_lucro >= 0 ? 'dot-ok' : 'dot-danger' ?>" id="dreDotLucro"></div>
            <div class="kpi-label">Lucro Líquido</div>
            <div class="kpi-value" id="dreLucro"><?= fmtBRL($dre_lucro) ?></div>
            <div class="kpi-foot">receita menos custos</div>
          </div>
          <div class="kpi-card <?= $dre_margem >= 40 ? 'kpi-ok' : ($dre_margem >= 15 ? 'kpi-warn' : 'kpi-danger') ?>" id="dreMargemCard">
            <div class="kpi-dot <?= $dre_margem >= 40 ? 'dot-ok' : ($dre_margem >= 15 ? 'dot-warn' : 'dot-danger') ?>" id="dreDotMargem"></div>
            <div class="kpi-label">Margem de Lucro</div>
            <div class="kpi-value" id="dreMargem"><?= $dre_margem ?>%</div>
            <div class="kpi-foot" id="dreMargemFoot"><?= $dre_margem >= 40 ? 'Saudável' : ($dre_margem >= 15 ? 'Atenção' : 'Crítica') ?></div>
          </div>
        </div>
      </div>

      <!-- ── JURÍDICO KPIs (JS loads from API) ── -->
      <div>
        <div class="section-eyebrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><path d="M12 3v18"/><path d="M5 6l7-3 7 3"/><path d="M5 6 2 13h6z"/><path d="M19 6l-3 7h6z"/><line x1="3" y1="18" x2="21" y2="18"/></svg> Jurídico — Processos</div>
        <div class="kpi-cols-4" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
          <div class="kpi-card">
            <div class="kpi-dot dot-neutral" id="dotJurAtivos"></div>
            <div class="kpi-label">Processos Ativos</div>
            <div class="kpi-value" id="jurAtivos">—</div>
            <div class="kpi-foot">total cadastrado ativo</div>
          </div>
          <div class="kpi-card kpi-danger">
            <div class="kpi-dot dot-danger"></div>
            <div class="kpi-label">Prazos Hoje</div>
            <div class="kpi-value" id="jurHoje">—</div>
            <div class="kpi-foot">vencem hoje</div>
          </div>
          <div class="kpi-card kpi-warn">
            <div class="kpi-dot dot-warn"></div>
            <div class="kpi-label">Esta Semana</div>
            <div class="kpi-value" id="jurSemana">—</div>
            <div class="kpi-foot">prazos em até 7 dias</div>
          </div>
          <div class="kpi-card kpi-danger">
            <div class="kpi-dot dot-danger"></div>
            <div class="kpi-label">Urgentes</div>
            <div class="kpi-value" id="jurUrgentes">—</div>
            <div class="kpi-foot">prazo até 3 dias</div>
          </div>
        </div>
      </div>

      <!-- ── Filtros e Meta ── -->
      <div class="dash-panel compact">
        <div class="flex items-center justify-between flex-wrap gap-3">
          <!-- Period filters (dashboard.js binds to these IDs) -->
          <div class="filter-bar">
            <input id="startMonth" type="month" class="filter-input">
            <span style="color:var(--muted);font-size:.78rem">até</span>
            <input id="endMonth" type="month" class="filter-input">
            <button id="quick3"  class="filter-btn">Últimos 3</button>
            <button id="quick6"  class="filter-btn">Últimos 6</button>
            <button id="quick12" class="filter-btn">Últimos 12</button>
            <button id="clearRange" class="filter-btn">Limpar</button>
            <button id="applyRange" class="filter-btn primary">Aplicar</button>
          </div>
          <!-- Meta widget (dashboard.js binds to these IDs) -->
          <div id="metaWidget" class="meta-widget">
            <div>
              <div class="meta-label" id="metaStaticLabel">Meta mensal</div>
              <div class="meta-val" id="metaMonthValue">R$ 0,00</div>
            </div>
            <button id="editMonthMeta" class="meta-widget-btn primary">Editar</button>
            <button id="calcMetaBtn"   class="meta-widget-btn secondary" onclick="location.href='/planejamento.php'">Calcular</button>
          </div>
        </div>
      </div>

      <!-- ── GRÁFICOS COMERCIAIS ── -->
      <div class="section-eyebrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg> Análise Comercial</div>
      <div class="charts-2col" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div id="projectionPanel">
          <h3>Projeção do período</h3>
          <canvas id="projectionChart" style="max-height:220px"></canvas>
          <hr style="border-color:rgba(96,165,250,.1);margin:14px 0">
          <h4>Meta × Fechados</h4>
          <canvas id="diffChart" style="max-height:220px"></canvas>
        </div>
        <div id="funnelPanel">
          <h3>Distribuição por etapa</h3>
          <div id="funnelChart" style="width:100%;height:480px;display:block;position:relative;margin-top:6px"></div>
          <div id="funnelLegend" style="margin-top:8px;text-align:center;font-size:12px;color:#e6f6ff;padding-top:4px"></div>
        </div>
      </div>

      <!-- Receita mensal -->
      <div class="chart-card">
        <div class="chart-title">Receita Mensal — Acumulado × Meta</div>
        <canvas id="revenueChart" style="max-height:240px"></canvas>
      </div>

      <!-- ── GRÁFICOS JURÍDICOS ── -->
      <div class="section-eyebrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><path d="M12 3v18"/><path d="M5 6l7-3 7 3"/><path d="M5 6 2 13h6z"/><path d="M19 6l-3 7h6z"/><line x1="3" y1="18" x2="21" y2="18"/></svg> Análise Jurídica</div>
      <div class="charts-2col" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="chart-card">
          <div class="chart-title">Processos por Mês de Prazo</div>
          <canvas id="dashProcessesMonthChart" style="max-height:220px"></canvas>
        </div>
        <div class="chart-card">
          <div class="chart-title">Prazos — Próximos 7 dias</div>
          <canvas id="dashPrazosWeekChart" style="max-height:220px"></canvas>
        </div>
      </div>
      <div class="charts-2col" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="chart-card">
          <div class="chart-title">Processos por Status</div>
          <canvas id="dashStatusChart" style="max-height:240px"></canvas>
        </div>
        <div class="chart-card">
          <div class="chart-title">Tipos de Ação Mais Frequentes</div>
          <canvas id="dashTypesChart" style="max-height:240px"></canvas>
        </div>
      </div>

      <!-- ── PROCESSOS PRÓXIMOS ── -->
      <div>
        <div class="section-eyebrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Processos Próximos</div>
        <div class="prox-4col" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
          <div class="prox-card">
            <div class="prox-card-hdr" style="background:rgba(239,68,68,.1);border-bottom-color:rgba(239,68,68,.18)">
              <span class="prox-card-lbl" style="color:#fca5a5">Hoje</span>
              <span class="prox-card-cnt" id="dashProxHojeCnt">—</span>
            </div>
            <div class="prox-card-list" id="dashProxHoje"></div>
          </div>
          <div class="prox-card">
            <div class="prox-card-hdr" style="background:rgba(245,158,11,.08);border-bottom-color:rgba(245,158,11,.18)">
              <span class="prox-card-lbl" style="color:#fcd34d">7 dias</span>
              <span class="prox-card-cnt" id="dashProx7Cnt">—</span>
            </div>
            <div class="prox-card-list" id="dashProx7"></div>
          </div>
          <div class="prox-card">
            <div class="prox-card-hdr" style="background:rgba(59,130,246,.08);border-bottom-color:rgba(59,130,246,.18)">
              <span class="prox-card-lbl" style="color:#93c5fd">15 dias</span>
              <span class="prox-card-cnt" id="dashProx15Cnt">—</span>
            </div>
            <div class="prox-card-list" id="dashProx15"></div>
          </div>
          <div class="prox-card">
            <div class="prox-card-hdr" style="background:rgba(16,185,129,.08);border-bottom-color:rgba(16,185,129,.18)">
              <span class="prox-card-lbl" style="color:#6ee7b7">30 dias</span>
              <span class="prox-card-cnt" id="dashProx30Cnt">—</span>
            </div>
            <div class="prox-card-list" id="dashProx30"></div>
          </div>
        </div>
      </div>

    </section>
  </div>
</main>


<!-- ── Popup de Alertas de Prazos ── -->
<style>
  #alertModalDash { position:fixed;inset:0;background:rgba(2,6,23,.75);display:flex;align-items:flex-start;justify-content:center;z-index:1200;overflow-y:auto;padding:36px 12px; }
  #alertModalDash.hidden { display:none !important; }
  .adash-box { width:680px;max-width:96vw;margin:auto;background:linear-gradient(165deg,rgba(10,24,46,.99),rgba(7,18,36,.99));border:1px solid rgba(96,165,250,.25);border-radius:16px;box-shadow:0 24px 60px rgba(2,6,23,.8);overflow:hidden; }
  .adash-header { display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid rgba(96,165,250,.14);background:rgba(8,22,44,.55); }
  .adash-title { font-size:1rem;font-weight:700;color:#dbeafe; }
  .adash-close { display:inline-flex;align-items:center;gap:5px;background:transparent;border:1px solid rgba(96,165,250,.3);color:#93c5fd;border-radius:8px;padding:0 12px;height:32px;cursor:pointer;font-size:.82rem;font-weight:600; }
  .adash-close:hover { background:rgba(37,99,235,.2); }
  .adash-body { padding:18px 20px;display:flex;flex-direction:column;gap:14px; }
  .adash-block { border-radius:10px;border:1px solid rgba(96,165,250,.12);overflow:hidden; }
  .adash-block-header { padding:8px 13px;background:rgba(37,99,235,.14);display:flex;justify-content:space-between;align-items:center; }
  .adash-block-label { font-size:.78rem;font-weight:700;color:#93c5fd;text-transform:uppercase;letter-spacing:.04em; }
  .adash-block-count { font-size:.82rem;font-weight:700;color:#f0f8ff; }
  .adash-block-list { padding:10px;display:flex;flex-direction:column;gap:6px; }
  .adash-item { padding:8px 10px;border-radius:7px;background:rgba(15,33,60,.8);font-size:.78rem; }
  .adash-item-client { color:#e2f0ff;font-weight:600; }
  .adash-item-meta { color:#9ab0c9;font-size:.72rem;margin-top:2px; }
  .adash-footer { padding:14px 20px;border-top:1px solid rgba(96,165,250,.14);background:rgba(8,22,44,.4);display:flex;justify-content:flex-end;gap:10px; }
  .adash-badge-danger { background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.25);border-radius:5px;padding:1px 7px;font-size:.7rem;font-weight:700; }
  .adash-badge-warn   { background:rgba(245,158,11,.18);color:#fcd34d;border:1px solid rgba(245,158,11,.25);border-radius:5px;padding:1px 7px;font-size:.7rem;font-weight:700; }
  .adash-badge-info   { background:rgba(59,130,246,.18);color:#93c5fd;border:1px solid rgba(59,130,246,.25);border-radius:5px;padding:1px 7px;font-size:.7rem;font-weight:700; }
  .adash-badge-ok     { background:rgba(16,185,129,.18);color:#6ee7b7;border:1px solid rgba(16,185,129,.25);border-radius:5px;padding:1px 7px;font-size:.7rem;font-weight:700; }
  .adash-btn-primary  { padding:0 18px;height:36px;border-radius:8px;border:none;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-size:.82rem;font-weight:600;cursor:pointer; }

  /* ── Tema claro para o popup "Alertas de Processos com Prazo" ──
     Antes ficava com a paleta dark (fundo navy + texto azul-claro) sobre o
     fundo branco do dashboard claro. Reseta para card branco + texto navy. */
  html[data-theme="light"] #alertModalDash { background: rgba(15,31,54,0.45); }
  html[data-theme="light"] .adash-box {
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    box-shadow: 0 24px 60px rgba(15,31,54,0.18);
  }
  html[data-theme="light"] .adash-header {
    background: #F8FAFC;
    border-bottom-color: #E2E8F0;
  }
  html[data-theme="light"] .adash-title  { color: #0F172A; }
  html[data-theme="light"] .adash-close {
    background: #FFFFFF;
    border-color: #BFDBFE;
    color: #1D4ED8;
  }
  html[data-theme="light"] .adash-close:hover { background: #EFF6FF; }
  html[data-theme="light"] .adash-block       { border-color: #E2E8F0; }
  html[data-theme="light"] .adash-block-header{ background: #EFF6FF; }
  html[data-theme="light"] .adash-block-label { color: #1D4ED8; }
  html[data-theme="light"] .adash-block-count { color: #0F172A; }
  html[data-theme="light"] .adash-item        { background: #F8FAFC; border: 1px solid #E2E8F0; }
  html[data-theme="light"] .adash-item-client { color: #0F172A; }
  html[data-theme="light"] .adash-item-meta   { color: #64748B; }
  html[data-theme="light"] .adash-footer {
    background: #F8FAFC;
    border-top-color: #E2E8F0;
  }
  /* Badges no fundo claro — sólidos com texto branco (estilo PR/release badges).
     A tentativa anterior usava pasteis (red-100 + red-700) mas o contraste
     ficou fraco e os textos sumiam. Saturado + branco garante legibilidade. */
  html[data-theme="light"] .adash-badge-danger {
    background: #DC2626 !important; color: #FFFFFF !important; border-color: #B91C1C !important;
  }
  html[data-theme="light"] .adash-badge-warn {
    background: #F59E0B !important; color: #FFFFFF !important; border-color: #D97706 !important;
  }
  html[data-theme="light"] .adash-badge-info {
    background: #2563EB !important; color: #FFFFFF !important; border-color: #1D4ED8 !important;
  }
  html[data-theme="light"] .adash-badge-ok {
    background: #059669 !important; color: #FFFFFF !important; border-color: #047857 !important;
  }
  /* Botão primário (gradiente azul + texto branco) — OK no claro, sem override */
</style>

<div id="alertModalDash" class="hidden">
  <div class="adash-box">
    <div class="adash-header">
      <span class="adash-title">Alertas de Processos com Prazo</span>
      <button class="adash-close" id="adashClose"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Fechar</button>
    </div>
    <div class="adash-body" id="adashBody"></div>
    <div class="adash-footer">
      <button class="adash-close" id="adashClose2">Fechar</button>
      <button class="adash-btn-primary" id="adashVerBtn">Ver processos</button>
    </div>
  </div>
</div>

<script>
(function(){
  const KEYS = {
    hoje: 'jur_alert_hoje',
    d7:   'jur_alert_7',
    d15:  'jur_alert_15',
    d30:  'jur_alert_30',
  };
  const isOn = k => localStorage.getItem(k) !== 'false';

  function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d + 'T00:00:00');
    return dt.toLocaleDateString('pt-BR');
  }

  function diffDays(d) {
    if (!d) return 9999;
    const now = new Date(); now.setHours(0,0,0,0);
    return Math.round((new Date(d+'T00:00:00') - now) / 86400000);
  }

  function classifyProcesses(procs) {
    const hoje=[], d7=[], d15=[], d30=[];
    procs.forEach(p => {
      const diff = diffDays(p.proximo_prazo);
      if (diff === 0)           hoje.push(p);
      else if (diff > 0 && diff <= 7)  d7.push(p);
      else if (diff > 0 && diff <= 15) d15.push(p);
      else if (diff > 0 && diff <= 30) d30.push(p);
    });
    return { hoje, d7, d15, d30 };
  }

  async function checkAndShow() {
    if (!isOn(KEYS.hoje) && !isOn(KEYS.d7) && !isOn(KEYS.d15) && !isOn(KEYS.d30)) return;

    let procs = [];
    try {
      const r = await fetch('/api/processes.php', { credentials: 'same-origin' });
      const data = await r.json();
      procs = Array.isArray(data) ? data : (data.data || []);
    } catch(e) { return; }

    procs = procs.filter(p => p.proximo_prazo && p.proximo_prazo !== '0000-00-00');
    const { hoje, d7, d15, d30 } = classifyProcesses(procs);

    const blocks = [
      { key: KEYS.hoje, label: 'Prazos Hoje',      items: hoje, cls: 'adash-badge-danger' },
      { key: KEYS.d7,   label: 'Próximos 7 dias',  items: d7,   cls: 'adash-badge-warn'   },
      { key: KEYS.d15,  label: 'Próximos 15 dias', items: d15,  cls: 'adash-badge-info'   },
      { key: KEYS.d30,  label: 'Próximos 30 dias', items: d30,  cls: 'adash-badge-ok'     },
    ].filter(b => isOn(b.key) && b.items.length > 0);

    if (!blocks.length) return;

    const body = document.getElementById('adashBody');
    body.innerHTML = blocks.map(b =>
      `<div class="adash-block">
        <div class="adash-block-header">
          <span class="adash-block-label">${b.label}</span>
          <span class="adash-block-count">${b.items.length} processo${b.items.length !== 1 ? 's' : ''}</span>
        </div>
        <div class="adash-block-list">
          ${b.items.slice(0,8).map(p =>
            `<div class="adash-item">
              <div class="adash-item-client">${p.cliente_nome||'—'} <span class="${b.cls}" style="margin-left:4px">${fmtDate(p.proximo_prazo)}</span></div>
              <div class="adash-item-meta">${p.numero||'—'}${p.responsavel ? ' · '+p.responsavel : ''}</div>
            </div>`
          ).join('')}
        </div>
      </div>`
    ).join('');

    document.getElementById('alertModalDash').classList.remove('hidden');
  }

  const close = () => document.getElementById('alertModalDash').classList.add('hidden');
  document.getElementById('adashClose').addEventListener('click', close);
  document.getElementById('adashClose2').addEventListener('click', close);
  document.getElementById('adashVerBtn').addEventListener('click', () => {
    close();
    window.location.href = '/juridico.php';
  });

  // Só exibe o popup na primeira visita após login (flag controlado pela sessão PHP)
  if (<?= $show_deadline_popup ? 'true' : 'false' ?>) checkAndShow();
})();
</script>

<!-- ── Scripts ── -->
<script src="assets/dashboard.js?v=13"></script>
<script src="/assets/fog.js"></script>

<!-- ── Dashboard extended: jurídico + resumo + alertas ── -->
<script>
(function(){
  const PROC_API    = '/api/processes.php';
  const METRICS_API = '/api/juridico_metrics.php';
  const currency    = new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'});
  const TICK        = '#9ab0c9';
  const GRID        = 'rgba(148,163,184,.1)';

  // shared DRE state — initialised from PHP, updated by loadDRE()
  const _dreState = {
    receita: <?= json_encode($dre_receita) ?>,
    despesa: <?= json_encode($dre_despesa) ?>,
    lucro:   <?= json_encode($dre_lucro) ?>,
    margem:  <?= json_encode($dre_margem) ?>
  };

  function parseDate(v){
    if (!v) return null;
    const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? new Date(+m[1],+m[2]-1,+m[3]) : null;
  }
  function fmtDate(v){ const d=parseDate(v); return d ? d.toLocaleDateString('pt-BR') : '—'; }
  function setEl(id,v){ const e=document.getElementById(id); if(e) e.textContent=v; }
  function addDays(d,n){ const r=new Date(d); r.setDate(r.getDate()+n); return r; }

  // ── Render processos próximos list ──────────────────────────────────────────
  function renderProxList(listId, cntId, items){
    setEl(cntId, items ? items.length : 0);
    const el = document.getElementById(listId);
    if (!el) return;
    if (!items || !items.length){ el.innerHTML='<div class="prox-empty">Nenhum processo</div>'; return; }
    el.innerHTML = items.slice(0,6).map(p=>
      `<div class="prox-row">` +
        `<div class="prox-row-client">${p.cliente_nome||'—'}</div>` +
        `<div class="prox-row-meta">${p.numero||'—'} · ${fmtDate(p.proximo_prazo)}${p.responsavel?' · '+p.responsavel:''}</div>` +
      `</div>`
    ).join('');
  }

  // ── Compute from processes ───────────────────────────────────────────────
  function computeJurKPIs(processes){
    const now   = new Date();
    const today = new Date(now.getFullYear(),now.getMonth(),now.getDate());
    let ativos=0, hoje=0, semana=0, urgentes=0;
    processes.forEach(p=>{
      if (p.status==='arquivado'||p.status==='encerrado') return;
      ativos++;
      const d = parseDate(p.proximo_prazo);
      if (!d) return;
      const pd = new Date(d.getFullYear(),d.getMonth(),d.getDate());
      const diff = Math.floor((pd-today)/864e5);
      if (diff === 0) { hoje++; urgentes++; semana++; }
      else if (diff > 0 && diff <= 3)  { urgentes++; semana++; }
      else if (diff > 0 && diff <= 7)  { semana++; }
    });
    return { ativos, hoje, semana, urgentes };
  }

  // ── Executive summary ─────────────────────────────────────────────────────
  function updateExecSummary(jurKPIs, closedValue, metaValue){
    const el = document.getElementById('execSummary');
    if (!el) return;
    const parts = [];
    if (closedValue > 0)        parts.push(`<strong>${currency.format(closedValue)}</strong> em contratos fechados`);
    if (_dreState.lucro > 0)    parts.push(`<strong>${currency.format(_dreState.lucro)}</strong> de lucro líquido (margem ${_dreState.margem}%)`);
    if (jurKPIs.ativos > 0)    parts.push(`<strong>${jurKPIs.ativos}</strong> processo${jurKPIs.ativos!==1?'s':''} ativo${jurKPIs.ativos!==1?'s':''}`);
    if (jurKPIs.semana > 0)    parts.push(`<strong>${jurKPIs.semana}</strong> prazo${jurKPIs.semana!==1?'s':''} nesta semana`);
    if (metaValue > 0){
      const pct = Math.round(((closedValue || 0)/metaValue)*100);
      if (closedValue > 0) parts.push(`meta mensal em <strong>${pct}%</strong> de execução`);
      setEl('metaProgresso', pct + '% da meta');
    } else {
      setEl('metaProgresso', '0% da meta');
    }
    el.innerHTML = 'Hoje o escritório possui ' + (parts.length ? parts.join(', ') : 'dados sendo carregados') + '.';
  }

  // ── Alerts ────────────────────────────────────────────────────────────────
  function buildAlerts(jurKPIs, closedValue, metaValue){
    const alerts = [];
    const dreMargem  = _dreState.margem;
    const dreReceita = _dreState.receita;
    const dreDespesa = _dreState.despesa;

    const _icoAlert  = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    const _icoWarn   = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
    const _icoDown   = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>';
    const _icoChart  = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><rect x="3" y="12" width="4" height="9"/><rect x="10" y="7" width="4" height="14"/><rect x="17" y="3" width="4" height="18"/></svg>';
    const _icoCheck  = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
    const _icoDollar = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
    const _icoMoney  = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:middle;margin-right:4px"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M9 9h4.5a1.5 1.5 0 0 1 0 3h-5a1.5 1.5 0 0 0 0 3H15"/></svg>';

    if (jurKPIs.hoje > 0)
      alerts.push({cls:'alert-danger', icon:_icoAlert, msg: `${jurKPIs.hoje} prazo${jurKPIs.hoje!==1?'s':''} fatal${jurKPIs.hoje!==1?'is':''} HOJE`});
    if (jurKPIs.urgentes > 0)
      alerts.push({cls:'alert-warn', icon:_icoWarn, msg: `${jurKPIs.urgentes} processo${jurKPIs.urgentes!==1?'s':''} urgente${jurKPIs.urgentes!==1?'s':''} (até 3 dias)`});
    if (metaValue > 0 && closedValue > 0){
      const pct = (closedValue/metaValue)*100;
      if (pct < 50) alerts.push({cls:'alert-danger', icon:_icoDown, msg:`Meta mensal abaixo de 50% (${Math.round(pct)}%)`});
      else if (pct < 80) alerts.push({cls:'alert-warn', icon:_icoChart, msg:`Meta mensal em ${Math.round(pct)}% — acelere a conversão`});
      else if (pct >= 100) alerts.push({cls:'alert-ok', icon:_icoCheck, msg:`Meta mensal atingida! (${Math.round(pct)}%)`});
    }
    if (dreMargem > 0 && dreMargem < 15)
      alerts.push({cls:'alert-danger', icon:_icoDollar, msg:`Margem crítica: ${dreMargem}% — revise os custos`});
    if (dreReceita > 0 && dreDespesa / dreReceita > 0.7)
      alerts.push({cls:'alert-warn', icon:_icoMoney, msg:`Custos altos: ${Math.round((dreDespesa/dreReceita)*100)}% da receita`});
    if (!alerts.length)
      alerts.push({cls:'alert-ok', icon:_icoCheck, msg:'Escritório operando normalmente — sem alertas críticos'});

    const container = document.getElementById('alertsContainer');
    if (!container) return;
    container.style.display = 'flex';
    container.innerHTML = alerts.map(a=>
      `<div class="alert-pill ${a.cls}">${a.icon} ${a.escapeHtml ? a.msg : a.msg}</div>`
    ).join('');
  }

  // ── Jurídico mini-charts no Dashboard ────────────────────────────────────
  // Versões compactas dos gráficos de juridico.php para visão rápida no Dashboard.
  // Mesma paleta de juridico_charts.js: azul metálico institucional.
  // Cada chart guarda referência em window._dash*Chart para evitar instâncias duplicadas.
  function renderJurCharts(processes){
    if (typeof Chart === 'undefined') return;
    const currentYear = new Date().getFullYear();
    const now   = new Date();
    const today = new Date(now.getFullYear(),now.getMonth(),now.getDate());

    // Paleta unificada com juridico_charts.js — azul metálico, do mais escuro ao mais claro
    const JUR_COLORS = ['#1E5FA8','#2878C8','#1A4A8A','#3A90C4','#4AAAD8','#2050A0','#5BBCE0'];

    // ── Curva anual de processos por mês de prazo (mini line chart) ─────────
    // Alimenta o card "Processos por Mês" no Dashboard — mesmo dado de juridico.php
    const pmCtx = (document.getElementById('dashProcessesMonthChart')||{}).getContext?.('2d');
    if (pmCtx){
      const months = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
      const counts = new Array(12).fill(0);
      processes.forEach(p=>{ const d=parseDate(p.proximo_prazo); if(d&&d.getFullYear()===currentYear) counts[d.getMonth()]++; });
      if (window._dashPMChart) try{window._dashPMChart.destroy();}catch(e){}
      window._dashPMChart = new Chart(pmCtx,{
        type:'line',
        data:{labels:months,datasets:[{label:'Processos',data:counts,borderColor:'#2878C8',backgroundColor:'rgba(40,120,200,0.10)',fill:true,tension:.35,pointBackgroundColor:'#2878C8',pointRadius:4}]},
        options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{color:TICK,stepSize:1},grid:{color:GRID}},x:{ticks:{color:TICK},grid:{display:false}}}}
      });
    }

    // ── Concentração de prazos nos próximos 7 dias (mini bar) ───────────────
    // Hoje = vermelho escuro, até 3 dias = âmbar, demais = azul metálico
    const pwCtx = (document.getElementById('dashPrazosWeekChart')||{}).getContext?.('2d');
    if (pwCtx){
      const days=[],labels=[],counts=[];
      for(let i=0;i<7;i++){
        const d=new Date(today); d.setDate(today.getDate()+i);
        days.push(d.getTime());
        labels.push(i===0?'Hoje':i===1?'Amanhã':d.toLocaleDateString('pt-BR',{weekday:'short',day:'2-digit',month:'2-digit'}));
        counts.push(0);
      }
      processes.forEach(p=>{
        const d=parseDate(p.proximo_prazo); if(!d) return;
        const pd=new Date(d.getFullYear(),d.getMonth(),d.getDate());
        const idx=days.indexOf(pd.getTime()); if(idx>=0) counts[idx]++;
      });
      if (window._dashPWChart) try{window._dashPWChart.destroy();}catch(e){}
      window._dashPWChart = new Chart(pwCtx,{
        type:'bar',
        data:{labels,datasets:[{label:'Prazos',data:counts,backgroundColor:counts.map((_,i)=>i===0?'#8A3050':i<=2?'#9A7020':'#1E5FA8'),borderRadius:5,borderColor:'transparent'}]},
        options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{color:TICK,stepSize:1},grid:{color:GRID}},x:{ticks:{color:TICK},grid:{display:false}}}}
      });
    }

    // ── Distribuição por status (mini donut) ─────────────────────────────────
    // Quatro tons de azul/cinza distintos para cada status da carteira
    const sCtx = (document.getElementById('dashStatusChart')||{}).getContext?.('2d');
    if (sCtx){
      const map={ativo:0,arquivado:0,encerrado:0,outros:0};
      processes.forEach(p=>{ const s=String(p.status||'').toLowerCase(); if(map[s]!==undefined) map[s]++; else map.outros++; });
      if (window._dashSChart) try{window._dashSChart.destroy();}catch(e){}
      window._dashSChart = new Chart(sCtx,{
        type:'doughnut',
        data:{labels:['Ativo','Arquivado','Encerrado','Outros'],datasets:[{data:Object.values(map),backgroundColor:['#1E5FA8','#2E5080','#3A90C4','#445870'],borderColor:'rgba(7,20,39,.95)',borderWidth:2}]},
        options:{responsive:true,plugins:{legend:{position:'bottom',labels:{color:'#A8BDD4',font:{size:11},padding:10}}}}
      });
    }

    // ── Tipos de ação mais frequentes (mini bar) ─────────────────────────────
    // Resolve código → nome via localStorage (populado por process_codes.js)
    const tCtx = (document.getElementById('dashTypesChart')||{}).getContext?.('2d');
    if (tCtx){
      const map={};
      function resolveName(code){
        if (!code) return null;
        try{
          const raw = localStorage.getItem('process_action_types');
          if (!raw) return null;
          const list = JSON.parse(raw);
          const found = list.find(it=>String(it.code||'')===String(code)||String(it.name||'')===String(code));
          return found ? (found.name||found.code||code) : null;
        }catch(e){ return null; }
      }
      processes.forEach(p=>{ const n=resolveName(p.tipo_acao); if(n) map[n]=(map[n]||0)+1; });
      const sorted=Object.keys(map).sort((a,b)=>map[b]-map[a]).slice(0,7);
      if (sorted.length){
        if (window._dashTChart) try{window._dashTChart.destroy();}catch(e){}
        window._dashTChart = new Chart(tCtx,{
          type:'bar',
          data:{labels:sorted,datasets:[{data:sorted.map(k=>map[k]),backgroundColor:JUR_COLORS,borderRadius:5,borderColor:'transparent'}]},
          options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{color:TICK},grid:{color:GRID}},x:{ticks:{color:TICK},grid:{display:false}}}}
        });
      }
    }
  }

  // ── DRE filtered load ────────────────────────────────────────────────────
  async function loadDRE(start, end){
    try{
      let url = '/api/dre_accounts.php';
      const qs = new URLSearchParams();
      if (start) qs.set('start', start);
      if (end)   qs.set('end',   end);
      const q = qs.toString();
      if (q) url += '?' + q;
      const res = await fetch(url, {credentials:'same-origin'});
      if (!res.ok) return;
      const j = await res.json();
      const c = j.combined || {};
      const receita  = Number(c.receita  || 0);
      const despesa  = Number(c.despesa  || 0);
      const lucro    = receita - despesa;
      const margem   = receita > 0 ? Math.round((lucro / receita) * 1000) / 10 : 0;
      const fmt = v => currency.format(v);
      const setEl = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
      setEl('dreReceita', fmt(receita));
      setEl('dreDespesa', fmt(despesa));
      setEl('dreLucro',   fmt(lucro));
      setEl('dreMargem',  margem + '%');
      setEl('dreMargemFoot', margem >= 40 ? 'Saudável' : margem >= 15 ? 'Atenção' : 'Crítica');
      _dreState.receita = receita; _dreState.despesa = despesa;
      _dreState.lucro = lucro; _dreState.margem = margem;
      const lucroCard = document.getElementById('dreLucroCard');
      if (lucroCard) lucroCard.className = 'kpi-card ' + (lucro >= 0 ? 'kpi-ok' : 'kpi-danger');
      const lucroDot = document.getElementById('dreDotLucro');
      if (lucroDot) lucroDot.className = 'kpi-dot ' + (lucro >= 0 ? 'dot-ok' : 'dot-danger');
      const margemCard = document.getElementById('dreMargemCard');
      if (margemCard) margemCard.className = 'kpi-card ' + (margem >= 40 ? 'kpi-ok' : margem >= 15 ? 'kpi-warn' : 'kpi-danger');
      const margemDot = document.getElementById('dreDotMargem');
      if (margemDot) margemDot.className = 'kpi-dot ' + (margem >= 40 ? 'dot-ok' : margem >= 15 ? 'dot-warn' : 'dot-danger');
    } catch(e){ console.error('loadDRE err', e); }
  }

  // ── Main load ─────────────────────────────────────────────────────────────
  async function loadExtended(start, end){
    const [pRes, mRes] = await Promise.all([
      fetch(PROC_API,{credentials:'same-origin'}).then(r=>r.ok?r.json():null).catch(()=>null),
      fetch(METRICS_API,{credentials:'same-origin'}).then(r=>r.ok?r.json():null).catch(()=>null),
    ]);

    let processes = [];
    if (pRes){ if(Array.isArray(pRes.data)) processes=pRes.data; else if(Array.isArray(pRes)) processes=pRes; }
    const metricsData = (mRes&&mRes.success&&mRes.data) ? mRes.data : {};

    const jurKPIs = computeJurKPIs(processes);
    setEl('jurAtivos',   jurKPIs.ativos);
    setEl('jurHoje',     jurKPIs.hoje);
    setEl('jurSemana',   jurKPIs.semana);
    setEl('jurUrgentes', jurKPIs.urgentes);
    if (jurKPIs.ativos > 0){ const d=document.getElementById('dotJurAtivos'); if(d){d.className='kpi-dot dot-ok';} }

    // Processos próximos (prefer metrics with responsavel names)
    const proxHoje = (metricsData.deadlines_today||[]).length ? metricsData.deadlines_today : (() => {
      const t=new Date(); const today=new Date(t.getFullYear(),t.getMonth(),t.getDate());
      return processes.filter(p=>{ const d=parseDate(p.proximo_prazo); return d&&new Date(d.getFullYear(),d.getMonth(),d.getDate()).getTime()===today.getTime(); });
    })();
    // Faixas EXCLUSIVAS (sem sobreposição) — alinhadas com api/juridico_metrics.php:
    //   Hoje:          d == today
    //   Próximos 7d:   today < d <= today+7   (EXCLUI hoje)
    //   Próximos 15d:  today+7 < d <= today+15
    //   Próximos 30d:  today+15 < d <= today+30
    const prox7  = (metricsData.deadlines_7||[]).length ? metricsData.deadlines_7 : (() => {
      const t=new Date(); const today=new Date(t.getFullYear(),t.getMonth(),t.getDate()); const t7=addDays(today,7);
      return processes.filter(p=>{ const d=parseDate(p.proximo_prazo); if(!d) return false; const dd=new Date(d.getFullYear(),d.getMonth(),d.getDate()); return dd>today&&dd<=t7; });
    })();
    const prox15 = (metricsData.deadlines_15||[]).length ? metricsData.deadlines_15 : (() => {
      const t=new Date(); const today=new Date(t.getFullYear(),t.getMonth(),t.getDate()); const t7=addDays(today,7); const t15=addDays(today,15);
      return processes.filter(p=>{ const d=parseDate(p.proximo_prazo); if(!d) return false; const dd=new Date(d.getFullYear(),d.getMonth(),d.getDate()); return dd>t7&&dd<=t15; });
    })();
    const prox30 = (metricsData.deadlines_30||[]).length ? metricsData.deadlines_30 : (() => {
      const t=new Date(); const today=new Date(t.getFullYear(),t.getMonth(),t.getDate()); const t15=addDays(today,15); const t30=addDays(today,30);
      return processes.filter(p=>{ const d=parseDate(p.proximo_prazo); if(!d) return false; const dd=new Date(d.getFullYear(),d.getMonth(),d.getDate()); return dd>t15&&dd<=t30; });
    })();

    renderProxList('dashProxHoje','dashProxHojeCnt', proxHoje);
    renderProxList('dashProx7',   'dashProx7Cnt',    prox7);
    renderProxList('dashProx15',  'dashProx15Cnt',   prox15);
    renderProxList('dashProx30',  'dashProx30Cnt',   prox30);

    // Jurídico charts: filter by date range when provided (by proximo_prazo)
    let chartProcesses = processes;
    if (start || end) {
      chartProcesses = processes.filter(p => {
        const d = parseDate(p.proximo_prazo);
        if (!d) return true;
        const ds = d.toISOString ? d.toISOString().substr(0,10) : (d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'));
        if (start && ds < start) return false;
        if (end   && ds > end)   return false;
        return true;
      });
    }
    renderJurCharts(chartProcesses);

    // Executive summary & alerts (wait for dashboard.js to set closedValue)
    setTimeout(()=>{
      const closedEl  = document.getElementById('closedValue');
      const metaEl    = document.getElementById('metaTopValue');
      const closed    = closedEl ? parseFloat(closedEl.textContent.replace(/[^\d,]/g,'').replace(',','.')) : 0;
      const meta      = metaEl  ? parseFloat(metaEl.textContent.replace(/[^\d,]/g,'').replace(',','.'))   : 0;
      updateExecSummary(jurKPIs, closed, meta);
      buildAlerts(jurKPIs, closed, meta);
    }, 1800);
  }

  // React to filter changes dispatched by dashboard.js (also fires on initial page load)
  window.addEventListener('dashboard:rangeChanged', (e) => {
    const { start, end } = (e && e.detail) || {};
    loadDRE(start || null, end || null);
    loadExtended(start || null, end || null);
  });

  // Also update summary after dashboard.js has loaded its data
  window.addEventListener('dashboard:loaded', ()=>{
    setTimeout(()=>{
      const closedEl = document.getElementById('closedValue');
      const metaEl   = document.getElementById('metaTopValue');
      const closed   = closedEl ? parseFloat(closedEl.textContent.replace(/[^\d,]/g,'').replace(',','.')) : 0;
      const meta     = metaEl   ? parseFloat(metaEl.textContent.replace(/[^\d,]/g,'').replace(',','.'))   : 0;
      const el = document.getElementById('metaProgresso');
      if (el && meta > 0) el.textContent = Math.round((closed/meta)*100) + '% da meta';
    }, 400);
  });
})();
</script>

<!-- ── Report (html2canvas) — preservado integralmente ── -->
<script>
(function(){
  var btn = document.getElementById('reportDashboardBtn');
  if (!btn) return;
  btn.addEventListener('click', function(){
    var fogElements = Array.from(document.querySelectorAll('.bg-fog-shared, .fog-blob-shared'));
    var prevDisplay = fogElements.map(function(el){ return el.style.display || ''; });
    fogElements.forEach(function(el){ el.style.display = 'none'; });
    var styleEl = document.createElement('style');
    styleEl.id = 'captureOverrideStyles';
    styleEl.textContent = 'canvas{visibility:visible!important;opacity:1!important;}';
    document.head.appendChild(styleEl);
    var doCapture = function(){
      var element = document.documentElement;
      var width  = Math.max(document.documentElement.scrollWidth,  document.body.scrollWidth,  document.documentElement.clientWidth);
      var height = Math.max(document.documentElement.scrollHeight, document.body.scrollHeight, document.documentElement.clientHeight);
      if (typeof html2canvas === 'undefined') {
        var s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js';
        s.onload = function(){
          html2canvas(element,{useCORS:true,scale:Math.min(2,window.devicePixelRatio||1),backgroundColor:null,windowWidth:width,windowHeight:height,scrollX:-window.scrollX,scrollY:-window.scrollY,logging:false}).then(function(canvas){
            canvas.toBlob(function(blob){ var a=document.createElement('a'); a.download='dashboard_'+new Date().toISOString().slice(0,10)+'.png'; a.href=URL.createObjectURL(blob); document.body.appendChild(a); a.click(); a.remove(); },'image/png');
          }).catch(function(err){ console.error('Capture error',err); }).finally(function(){ fogElements.forEach(function(el,i){el.style.display=prevDisplay[i]||'';}); try{var s=document.getElementById('captureOverrideStyles');if(s)s.remove();}catch(e){} });
        };
        document.head.appendChild(s);
        return;
      }
      html2canvas(element,{useCORS:true,scale:Math.min(2,window.devicePixelRatio||1),backgroundColor:null,windowWidth:width,windowHeight:height,scrollX:-window.scrollX,scrollY:-window.scrollY,logging:false}).then(function(canvas){
        canvas.toBlob(function(blob){ var a=document.createElement('a'); a.download='dashboard_'+new Date().toISOString().slice(0,10)+'.png'; a.href=URL.createObjectURL(blob); document.body.appendChild(a); a.click(); a.remove(); },'image/png');
      }).catch(function(err){ console.error(err); }).finally(function(){ fogElements.forEach(function(el,i){el.style.display=prevDisplay[i]||'';}); try{var s=document.getElementById('captureOverrideStyles');if(s)s.remove();}catch(e){} });
    };
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(function(){ setTimeout(doCapture,160); }).catch(function(){ setTimeout(doCapture,300); });
    else setTimeout(doCapture,300);
  });
})();

// Filtro de Origem (Matriz / Filiais / específica) — recarrega a página
// porque o cálculo do dashboard é server-side. Preserva ?origin= no querystring.
(function(){
  var sel = document.getElementById('dashFilterOrigin');
  if (!sel) return;
  sel.addEventListener('change', function(){
    var u = new URL(window.location.href);
    if (sel.value) u.searchParams.set('origin', sel.value);
    else u.searchParams.delete('origin');
    window.location.href = u.toString();
  });
})();
</script>
</body>
</html>

