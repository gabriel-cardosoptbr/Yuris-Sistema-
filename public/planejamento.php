﻿<?php
require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Models/User.php';
use App\Models\Database;
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: /sistema_vendas/public/login.php');
    exit;
}
$activePage = 'funil';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Planejamento Comercial Jurídico — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/sistema_vendas/public/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/sistema_vendas/public/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/yuris-theme.css">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/fog.css">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/sidebar.css?v=8">
  <style>
    :root {
      --bg-main: #071427;
      --panel: #0b1c35;
      --panel-soft: #0e2341;
      --line: rgba(148, 163, 184, 0.18);
      --line-strong: rgba(96, 165, 250, 0.35);
      --text: #e8f4ff;
      --muted: #99afc9;
      --ok: #22c55e;
      --warn: #f59e0b;
      --danger: #ef4444;
      --primary: #3b82f6;
      --primary-soft: rgba(59, 130, 246, 0.2);
      --radius: 14px;
    }

    body {
      color: var(--text);
      font-family: 'Inter', 'Poppins', system-ui, -apple-system, sans-serif;
      min-height: 100vh;
    }

    .card {
      background: linear-gradient(160deg, rgba(14, 35, 65, 0.92), rgba(10, 23, 43, 0.94));
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: 0 14px 40px rgba(2, 6, 23, 0.45);
      padding: 18px;
    }

    .section-title {
      font-size: 1.02rem;
      font-weight: 600;
      letter-spacing: 0.01em;
      color: #dbeafe;
    }

    .section-subtitle {
      margin-top: 4px;
      color: var(--muted);
      font-size: 0.83rem;
      line-height: 1.45;
    }

    .field-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .field-label {
      color: #d1e6ff;
      font-size: 0.82rem;
      font-weight: 500;
    }

    .field-hint {
      color: var(--muted);
      font-size: 0.73rem;
      line-height: 1.25;
    }

    .input {
      width: 100%;
      padding: 10px 12px;
      border-radius: 10px;
      border: 1px solid rgba(148, 163, 184, 0.22);
      background: rgba(15, 31, 55, 0.82);
      color: #f1f7ff;
      transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
    }

    .input::placeholder {
      color: rgba(153, 175, 201, 0.65);
    }

    .input:focus {
      outline: none;
      border-color: var(--line-strong);
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
      transform: translateY(-1px);
    }

    .input.is-dirty {
      border-color: rgba(34, 197, 94, 0.6);
      box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.16);
    }

    .kpi-card {
      position: relative;
      overflow: hidden;
      border-radius: 14px;
      border: 1px solid rgba(96, 165, 250, 0.22);
      background: linear-gradient(135deg, rgba(13, 31, 56, 0.95), rgba(8, 19, 37, 0.95));
      padding: 15px;
      min-height: 116px;
      transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
    }

    .kpi-card:hover {
      transform: translateY(-4px);
      border-color: rgba(96, 165, 250, 0.45);
      box-shadow: 0 12px 28px rgba(37, 99, 235, 0.25);
    }

    .kpi-label {
      color: #a8c2df;
      font-size: 0.79rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-weight: 600;
    }

    .kpi-value {
      margin-top: 10px;
      color: #f0f8ff;
      font-size: 1.42rem;
      font-weight: 700;
      line-height: 1.1;
    }

    .kpi-foot {
      margin-top: 8px;
      color: var(--muted);
      font-size: 0.75rem;
    }

    .goal-indicator {
      margin-top: 12px;
      border-radius: 11px;
      border: 1px solid var(--line);
      padding: 12px;
      background: rgba(7, 17, 31, 0.55);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
    }

    .goal-pill {
      border-radius: 999px;
      padding: 4px 10px;
      font-size: 0.76rem;
      font-weight: 600;
      letter-spacing: .01em;
    }

    .goal-pill.is-ok {
      background: rgba(34, 197, 94, 0.2);
      color: #7fffb3;
      border: 1px solid rgba(34, 197, 94, 0.35);
    }

    .goal-pill.is-warn {
      background: rgba(245, 158, 11, 0.2);
      color: #ffd78f;
      border: 1px solid rgba(245, 158, 11, 0.35);
    }

    .goal-pill.is-danger {
      background: rgba(239, 68, 68, 0.22);
      color: #ffc0c0;
      border: 1px solid rgba(239, 68, 68, 0.42);
    }

    .funnel-visual {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-top: 12px;
    }

    .funnel-compare {
      margin-top: 10px;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
      align-items: start;
    }

    .funnel-column {
      border: 1px solid rgba(96, 165, 250, 0.2);
      background: rgba(6, 18, 36, 0.45);
      border-radius: 12px;
      padding: 12px;
    }

    .funnel-column-title {
      font-size: 0.88rem;
      color: #cfe4ff;
      font-weight: 600;
      letter-spacing: 0.01em;
    }

    .funnel-stage {
      opacity: 0;
      transform: translateY(8px);
      transition: opacity .35s ease, transform .35s ease;
      display: block;
    }

    .funnel-stage.show-step {
      opacity: 1;
      transform: translateY(0);
    }

    .funnel-shape {
      width: 100%;
      max-width: 100%;
      clip-path: none;
      border-radius: 12px;
      border: 1px solid rgba(96, 165, 250, 0.28);
      background: linear-gradient(140deg, rgba(37, 99, 235, 0.22), rgba(15, 32, 58, 0.92));
      padding: 14px 16px;
      transition: border-color .2s ease, box-shadow .2s ease;
    }

    .funnel-stage.is-bottleneck .funnel-shape {
      border-color: rgba(245, 158, 11, 0.75);
      box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
      background: linear-gradient(145deg, rgba(245, 158, 11, 0.22), rgba(45, 24, 9, 0.9));
    }

    .funnel-head {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: flex-start;
    }

    .funnel-name {
      font-weight: 600;
      color: #e7f2ff;
    }

    .funnel-metrics {
      margin-top: 6px;
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      font-size: 0.76rem;
      color: #c2d9ef;
    }

    .funnel-metrics strong {
      color: #f3f8ff;
      font-weight: 600;
    }

    .conversions-grid {
      margin-top: 12px;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .conversion-item {
      border-radius: 12px;
      border: 1px solid rgba(148, 163, 184, 0.22);
      background: rgba(8, 20, 38, 0.75);
      padding: 12px;
    }

    .conversion-item.is-bottleneck {
      border-color: rgba(245, 158, 11, 0.7);
      background: rgba(56, 32, 11, 0.55);
    }

    .conversion-label {
      color: #a8c2df;
      font-size: 0.77rem;
      line-height: 1.35;
    }

    .conversion-value {
      margin-top: 7px;
      font-size: 1.15rem;
      font-weight: 700;
      color: #f4fbff;
    }

    .summary-box {
      border-radius: 12px;
      border: 1px solid rgba(148, 163, 184, 0.23);
      background: rgba(6, 17, 34, 0.64);
      padding: 13px;
      line-height: 1.5;
      color: #d6e8fa;
      font-size: 0.88rem;
    }

    .sim-grid {
      margin-top: 12px;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .sim-card {
      border-radius: 11px;
      border: 1px solid rgba(96, 165, 250, 0.23);
      background: rgba(10, 22, 39, 0.85);
      padding: 11px;
    }

    .sim-label {
      color: #a8c2df;
      font-size: 0.74rem;
      letter-spacing: .01em;
      text-transform: uppercase;
    }

    .sim-value {
      margin-top: 8px;
      color: #edf7ff;
      font-size: 1.08rem;
      font-weight: 700;
      line-height: 1.2;
    }

    .bottleneck-note {
      margin-top: 12px;
      border-radius: 10px;
      border: 1px dashed rgba(245, 158, 11, 0.5);
      background: rgba(73, 43, 12, 0.35);
      padding: 10px;
      color: #ffd9a1;
      font-size: 0.8rem;
    }

    .funnel-gap-full {
      grid-column: 1 / -1;
      margin-top: 2px;
      border-style: solid;
      border-color: rgba(245, 158, 11, 0.45);
      background: rgba(40, 25, 10, 0.45);
    }

    .gap-title {
      color: #ffe8bf;
      font-size: 0.88rem;
      font-weight: 600;
      margin-bottom: 10px;
    }

    .gap-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
    }

    .gap-item {
      border-radius: 10px;
      border: 1px solid rgba(251, 191, 36, 0.28);
      background: rgba(16, 14, 18, 0.38);
      padding: 10px;
    }

    .gap-item-label {
      font-size: 0.74rem;
      color: #ffdd9d;
      font-weight: 600;
      letter-spacing: .01em;
      margin-bottom: 6px;
    }

    .gap-item-value {
      color: #fff2d2;
      font-size: 1.05rem;
      font-weight: 700;
      line-height: 1.15;
    }

    .gap-item-sub {
      margin-top: 4px;
      font-size: 0.72rem;
      color: #f7d39d;
      line-height: 1.35;
    }

    .gap-footer {
      margin-top: 10px;
      padding-top: 8px;
      border-top: 1px dashed rgba(245, 158, 11, 0.35);
      font-size: 0.78rem;
      color: #ffe2b3;
    }

    .executive-panel {
      background: rgba(7, 20, 38, 0.72);
      border-color: rgba(96, 165, 250, 0.26);
    }

    .exec-summary {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .exec-title {
      color: #d7e9ff;
      font-size: 0.9rem;
      font-weight: 600;
      letter-spacing: 0.01em;
    }

    .exec-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
    }

    .exec-item {
      border-radius: 10px;
      border: 1px solid rgba(96, 165, 250, 0.28);
      background: rgba(8, 22, 44, 0.62);
      padding: 10px;
    }

    .exec-item-label {
      color: #b8d5f4;
      font-size: 0.73rem;
      letter-spacing: 0.01em;
      margin-bottom: 6px;
      text-transform: uppercase;
    }

    .exec-item-value {
      color: #f2f8ff;
      font-size: 1.02rem;
      font-weight: 700;
      line-height: 1.15;
    }

    .exec-note {
      margin-top: 2px;
      padding-top: 10px;
      border-top: 1px dashed rgba(148, 163, 184, 0.35);
      color: #d5e8ff;
      font-size: 0.83rem;
      line-height: 1.5;
    }

    @media (max-width: 1280px) {
      .simulator-card {
        position: static;
      }
    }

    @media (max-width: 1100px) {
      .conversions-grid {
        grid-template-columns: 1fr;
      }

      .sim-grid {
        grid-template-columns: 1fr;
      }

      .funnel-compare {
        grid-template-columns: 1fr;
      }

      .gap-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .exec-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 640px) {
      .gap-grid {
        grid-template-columns: 1fr;
      }

      .exec-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 900px) {
      .sidebar {
        display: none;
      }

      .mobile-tabbar {
        display: flex;
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        height: 64px;
        background: linear-gradient(180deg, rgba(6,16,30,0.98), rgba(8,20,38,0.98));
        border-top: 1px solid rgba(255,255,255,0.02);
        align-items: center;
        justify-content: space-around;
        z-index: 80;
      }

      .mobile-tabbar a {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: #cfeeff;
        text-decoration: none;
        font-size: 13px;
      }

      .mobile-tabbar .icon {
        width: 14px;
        height: 14px;
        border-radius: 3px;
        margin-bottom: 6px;
      }

      main {
        padding-bottom: 84px;
      }
    }
  </style>
</head>
<body>
  <main class="w-full px-6 pb-6">
    <div class="page-layout">

      <!-- ── Sidebar ── -->
      <?php include __DIR__ . '/includes/sidebar.php'; ?>

      <section class="main-content space-y-4">
          <div class="card-shell page-header">
            <div class="page-header-inner">
              <div class="page-header-text">
                <h2 class="page-header-title">Planejamento Comercial Jurídico</h2>
                <p class="page-header-subtitle">Simulador de funil — calcule metas, conversões e distribuição de contratos por advogado.</p>
              </div>
            </div>
          </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-4" id="topCards">
          <div class="kpi-card">
            <div class="kpi-label">Meta mensal</div>
            <div class="kpi-value" id="kpiMeta">R$ 0,00</div>
            <div class="kpi-foot">Receita alvo no período</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-label">Honorário médio</div>
            <div class="kpi-value" id="kpiHonorario">R$ 0,00</div>
            <div class="kpi-foot">Valor médio por contrato</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-label">Contratos necessários</div>
            <div class="kpi-value" id="kpiContratosNec">0</div>
            <div class="kpi-foot">Meta convertida em contratos</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-label">Leads qualificados</div>
            <div class="kpi-value" id="kpiLeadsNec">0</div>
            <div class="kpi-foot">Demanda estimada no topo</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-label">Consultas por dia</div>
            <div class="kpi-value" id="kpiConsultasDia">0</div>
            <div class="kpi-foot">Ritmo diário esperado</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-label">Contratos por responsável</div>
            <div class="kpi-value" id="kpiContratosResp">0</div>
            <div class="kpi-foot">Distribuição por pessoa</div>
          </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-12 gap-4">
          <div class="xl:col-span-8">
            <div class="card h-full">
              <h3 class="section-title">Parâmetros da meta</h3>
              <p class="section-subtitle">Atualize os dados-base comerciais. Os cálculos e o funil são atualizados automaticamente.</p>

              <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                <label class="field-group">
                  <span class="field-label">Meta mensal (R$)</span>
                  <input id="meta" class="input input-currency" type="text" value="R$ 75.000,00" placeholder="Ex.: R$ 75.000,00" inputmode="decimal">
                  <span class="field-hint">Receita desejada para o mês</span>
                </label>
                <label class="field-group">
                  <span class="field-label">Honorário médio (R$)</span>
                  <input id="ticket" class="input input-currency" type="text" value="R$ 2.140,65" placeholder="Ex.: R$ 2.140,65" inputmode="decimal">
                  <span class="field-hint">Valor médio por contrato fechado</span>
                </label>
                <label class="field-group">
                  <span class="field-label">Dias úteis no mês</span>
                  <input id="dias" class="input" type="number" step="1" min="1" value="22" placeholder="Ex.: 22">
                  <span class="field-hint">Base para ritmo operacional diário</span>
                </label>
                <label class="field-group">
                  <span class="field-label">Responsáveis comerciais</span>
                  <input id="vendedores" class="input" type="number" step="1" min="1" value="4" placeholder="Ex.: 4">
                  <span class="field-hint">Equipe de conversão e atendimento</span>
                </label>
                <label class="field-group">
                  <span class="field-label">Contratos fechados</span>
                  <input id="vendas" class="input" type="number" step="1" min="0" value="35" placeholder="Ex.: 35">
                  <span class="field-hint">Base atual no final do funil</span>
                </label>
                <label class="field-group">
                  <span class="field-label">Propostas enviadas</span>
                  <input id="propostas" class="input" type="number" step="1" min="0" value="88" placeholder="Ex.: 88">
                  <span class="field-hint">Etapa intermediária de negociação</span>
                </label>
                <label class="field-group">
                  <span class="field-label">Consultas comerciais</span>
                  <input id="visitas" class="input" type="number" step="1" min="0" value="147" placeholder="Ex.: 147">
                  <span class="field-hint">Reuniões com potencial de proposta</span>
                </label>
                <label class="field-group">
                  <span class="field-label">Leads qualificados</span>
                  <input id="oportunidades" class="input" type="number" step="1" min="0" value="445" placeholder="Ex.: 445">
                  <span class="field-hint">Topo do funil com aderência mínima</span>
                </label>
              </div>
            </div>
          </div>

          <aside class="xl:col-span-4">
            <div class="card simulator-card h-full">
              <h3 class="section-title">Simulação alternativa</h3>
              <p class="section-subtitle">Calculadora lateral para testar outro alvo de contratos e impacto em cada etapa.</p>

              <label class="field-group mt-4">
                <span class="field-label">Contratos desejados</span>
                <input id="simVendas" class="input" type="number" step="1" min="0" value="35" placeholder="Ex.: 50">
                <span class="field-hint">A atualização é automática enquanto você digita</span>
              </label>

              <div class="sim-grid">
                <div class="sim-card">
                  <div class="sim-label">Leads necessários</div>
                  <div class="sim-value" id="simLeads">0</div>
                </div>
                <div class="sim-card">
                  <div class="sim-label">Consultas necessárias</div>
                  <div class="sim-value" id="simConsultas">0</div>
                </div>
                <div class="sim-card">
                  <div class="sim-label">Propostas necessárias</div>
                  <div class="sim-value" id="simPropostas">0</div>
                </div>
                <div class="sim-card">
                  <div class="sim-label">Receita projetada</div>
                  <div class="sim-value" id="simReceita">R$ 0,00</div>
                </div>
                <div class="sim-card" style="grid-column: 1 / -1;">
                  <div class="sim-label">Distribuição por responsável</div>
                  <div class="sim-value" id="simDistribuicao">0 contratos por responsável</div>
                </div>
              </div>

            </div>
          </aside>

          <div class="xl:col-span-12">
            <div class="card">
              <h3 class="section-title">Resultado esperado</h3>
              <p class="section-subtitle">Leitura rápida da dificuldade da meta e do ritmo operacional necessário.</p>

              <div class="goal-indicator">
                <div>
                  <div class="text-sm text-blue-100 font-semibold" id="goalIndicatorTitle">Meta comercial</div>
                  <div class="section-subtitle" id="goalIndicatorText">Aguardando cálculo...</div>
                </div>
                <span class="goal-pill" id="goalIndicatorPill">Em análise</span>
              </div>

              <div class="summary-box mt-3" id="operational"></div>
            </div>
          </div>

          <div class="xl:col-span-12 space-y-4">
            <div class="card">
              <h3 class="section-title">Comparativo de funis</h3>
              <p class="section-subtitle">Visualize o funil comercial e o funil simulado lado a lado para comparar impacto por etapa.</p>
              <div class="funnel-compare">
                <div class="funnel-column">
                  <h4 class="funnel-column-title">Funil comercial</h4>
                  <div id="leftFunnel" class="funnel-visual"></div>
                </div>
                <div class="funnel-column">
                  <h4 class="funnel-column-title">Funil simulado</h4>
                  <div id="rightFunnel" class="funnel-visual"></div>
                </div>
                <div class="bottleneck-note funnel-gap-full" id="bottleneckText">Resumo de evolução entre funil comercial e simulado.</div>
              </div>
            </div>

            <div class="card">
              <h3 class="section-title">Conversões</h3>
              <p class="section-subtitle">Indicadores de eficiência por etapa, sem alterar os cálculos atuais.</p>
              <div id="calculations" class="conversions-grid"></div>
            </div>

            <div class="card">
              <h3 class="section-title">Resumo executivo</h3>
              <div class="summary-box executive-panel" id="executiveSummary"></div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </main>

  <script src="assets/dashboard.js?v=10"></script>
  <script src="/sistema_vendas/public/assets/fog.js"></script>
  <script src="/sistema_vendas/public/assets/funnel.js?v=4"></script>

</body>
</html>

