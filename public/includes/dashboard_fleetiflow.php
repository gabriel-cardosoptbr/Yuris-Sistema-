<?php
/**
 * Cockpit comercial da conta Fleetiflow. Incluído por public/dashboard.php
 * quando a conta tem produto 'fleetiflow' (a conta Yuris nunca chega aqui).
 *
 * Espera do dashboard.php: $ctx (AccountContext), $origin_accounts,
 * $selected_origin, $dre_receita, $dre_despesa, $dre_lucro, $dre_margem e
 * fmtBRL(). Os dados comerciais vêm de /api/dashboard_comercial.php via
 * assets/fleetiflow-dashboard.js; o financeiro (DRE) nasce renderizado aqui
 * para não piscar e é atualizado pelo mesmo JS ao trocar o período.
 */
if (!isset($ctx)) { http_response_code(403); exit; }
$activePage = 'dashboard';
$_userNome  = (string)($_SESSION['user_nome'] ?? '');
$_mesAtual  = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'][(int)date('n') - 1];

/** Card de KPI reutilizável (mesmo desenho do JS FleetiflowDash.kpi). */
function ffc_kpi(string $id, string $rotulo, string $ico, string $tom = '', string $classe = ''): string
{
    $icones = [
        'leads'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>',
        'funil'    => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
        'vendas'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'receita'  => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        'conversao'=> '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'ticket'   => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
        'despesa'  => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        'lucro'    => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
        'margem'   => '<circle cx="12" cy="12" r="10"/><path d="M12 6v12M9 9h4.5a1.5 1.5 0 0 1 0 3h-5a1.5 1.5 0 0 0 0 3H15"/>',
    ];
    $svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($icones[$ico] ?? '') . '</svg>';
    $tomCls = $tom ? ' ffc-kpi-ico--' . $tom : '';
    return '<div class="ffc-card ffc-kpi ffc-col-2 ' . $classe . '" id="' . $id . '">'
         . '<div class="ffc-kpi-topo"><span class="ffc-kpi-rotulo">' . htmlspecialchars($rotulo) . '</span><span class="ffc-kpi-ico' . $tomCls . '">' . $svg . '</span></div>'
         . '<div class="ffc-kpi-valor ffc-esq" data-campo="valor">&nbsp;</div>'
         . '<div class="ffc-kpi-pe" data-campo="pe"></div>'
         . '</div>';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Análise Comercial — Fleetiflow CRM</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/fleetiflow-favicon-32.png?v=1">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/fleetiflow-favicon-192.png?v=1">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light"||t===null)document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <link rel="stylesheet" href="/assets/fleetiflow-dashboard.css?v=3">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
</head>
<body>
<main class="w-full">
  <div class="page-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <section class="main-content ffc" id="ffc"
             data-origin="<?= htmlspecialchars($selected_origin) ?>"
             data-usuario="<?= htmlspecialchars($_userNome) ?>">

      <!-- ── Cabeçalho ──────────────────────────────────────────────────── -->
      <header class="ffc-cabecalho">
        <div>
          <h1 class="ffc-titulo">Análise comercial</h1>
          <p class="ffc-subtitulo">Acompanhe vendas, pipeline, metas e performance da operação.</p>
        </div>
        <div class="ffc-acoes">
          <select class="ffc-select y-select" id="ffcPeriodo" aria-label="Período">
            <option value="7d">Últimos 7 dias</option>
            <option value="30d" selected>Últimos 30 dias</option>
            <option value="90d">Últimos 90 dias</option>
            <option value="mes">Este mês</option>
            <option value="mes_anterior">Mês passado</option>
            <option value="ano">Este ano</option>
            <option value="custom">Personalizado</option>
          </select>
          <div class="ffc-datas" id="ffcDatas">
            <input type="date" class="ffc-input" id="ffcInicio" aria-label="Início">
            <span>até</span>
            <input type="date" class="ffc-input" id="ffcFim" aria-label="Fim">
            <button class="ffc-btn ffc-btn--marca" id="ffcAplicar" type="button">Aplicar</button>
          </div>
          <?php if (count($origin_accounts) > 1): ?>
          <select class="ffc-select y-select" id="ffcOrigem" aria-label="Unidade">
            <option value="">Todas as unidades</option>
            <option value="__matriz__" <?= $selected_origin === '__matriz__' ? 'selected' : '' ?>>Apenas matriz</option>
            <option value="__filiais__" <?= $selected_origin === '__filiais__' ? 'selected' : '' ?>>Apenas filiais</option>
            <?php foreach ($origin_accounts as $oa): if ($oa['tipo'] === 'matriz') continue; ?>
            <option value="<?= (int)$oa['id'] ?>" <?= $selected_origin === (string)$oa['id'] ? 'selected' : '' ?>><?= htmlspecialchars($oa['nome']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <select class="ffc-select y-select" id="ffcResponsavel" aria-label="Equipe">
            <option value="">Toda a equipe</option>
          </select>
          <button class="ffc-btn" id="ffcAtualizar" type="button" title="Atualizar dados">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            Atualizar
          </button>
          <div class="ffc-menu-wrap">
            <button class="ffc-btn ffc-btn--ico" id="ffcMais" type="button" aria-haspopup="true" aria-expanded="false" title="Mais ações">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
            </button>
            <div class="ffc-menu" id="ffcMenu" role="menu">
              <button type="button" data-acao="meta"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>Editar meta do mês</button>
              <button type="button" data-acao="exportar"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Exportar imagem</button>
              <button type="button" data-acao="pipeline"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="18" rx="1"/><rect x="14" y="3" width="7" height="12" rx="1"/></svg>Abrir pipeline</button>
              <button type="button" data-acao="planejamento"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="16" y1="14" x2="16" y2="18"/><path d="M16 10h.01M12 10h.01M8 10h.01M12 14h.01M8 14h.01M12 18h.01M8 18h.01"/></svg>Calcular meta</button>
            </div>
          </div>
        </div>
      </header>

      <!-- ── 1. KPIs ─────────────────────────────────────────────────────── -->
      <div class="ffc-grade" id="ffcKpis">
        <?= ffc_kpi('kpiLeads',     'Novos leads',      'leads') ?>
        <?= ffc_kpi('kpiOport',     'Oportunidades',    'funil',     'violeta') ?>
        <?= ffc_kpi('kpiVendas',    'Vendas fechadas',  'vendas',    'bom') ?>
        <?= ffc_kpi('kpiReceita',   'Receita fechada',  'receita',   '', 'ffc-kpi--destaque') ?>
        <?= ffc_kpi('kpiConversao', 'Conversão',        'conversao', 'ambar') ?>
        <?= ffc_kpi('kpiTicket',    'Ticket médio',     'ticket',    'laranja') ?>
      </div>

      <!-- ── 2. Performance (8) + Pipeline (4) ───────────────────────────── -->
      <div class="ffc-grade">
        <div class="ffc-card ffc-col-8 ffc-meio" id="cardPerformance">
          <div class="ffc-card-cab">
            <div>
              <h2 class="ffc-card-titulo">Performance comercial</h2>
              <div class="ffc-card-sub" id="perfSub">Receita realizada por dia, contra a meta proporcional</div>
            </div>
            <div class="ffc-abas" role="tablist" id="perfAbas">
              <button type="button" role="tab" data-aba="receita" aria-selected="true">Receita</button>
              <button type="button" role="tab" data-aba="vendas" aria-selected="false">Vendas</button>
              <button type="button" role="tab" data-aba="conversao" aria-selected="false">Conversão</button>
            </div>
          </div>
          <div class="ffc-grafico" id="perfArea"><canvas id="perfCanvas"></canvas></div>
          <div class="ffc-legenda" id="perfLegenda"></div>
        </div>

        <div class="ffc-card ffc-col-4 ffc-meio" id="cardPipeline">
          <div class="ffc-card-cab">
            <div>
              <h2 class="ffc-card-titulo">Distribuição do pipeline</h2>
              <div class="ffc-card-sub">Onde estão as oportunidades agora</div>
            </div>
            <a class="ffc-btn ffc-btn--mini ffc-btn--link" href="/prospeccao.php">Ver funil</a>
          </div>
          <div id="pipelineArea"></div>
        </div>
      </div>

      <!-- ── 3. Meta (4) + Atividades (8) ────────────────────────────────── -->
      <div class="ffc-grade">
        <div class="ffc-card ffc-col-4 ffc-meio" id="cardMeta">
          <div class="ffc-card-cab">
            <div>
              <h2 class="ffc-card-titulo" id="metaTitulo">Meta de <?= $_mesAtual ?></h2>
              <div class="ffc-card-sub">Fechado no mês contra a meta</div>
            </div>
            <button class="ffc-btn ffc-btn--mini" type="button" id="metaEditar">Editar</button>
          </div>
          <div id="metaArea"></div>
        </div>

        <div class="ffc-card ffc-col-8 ffc-meio" id="cardAtividades">
          <div class="ffc-card-cab">
            <div>
              <h2 class="ffc-card-titulo">Atividades recentes</h2>
              <div class="ffc-card-sub">Últimos negócios movimentados no pipeline</div>
            </div>
            <div class="ffc-card-acoes">
              <label class="ffc-busca"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="search" class="ffc-input" id="ativBusca" placeholder="Pesquisar cliente"></label>
              <select class="ffc-select y-select" id="ativEtapa" aria-label="Filtrar por etapa"><option value="">Todas as etapas</option></select>
            </div>
          </div>
          <div class="ffc-tabela-wrap" id="ativArea"></div>
          <div class="ffc-tabela-rodape" id="ativRodape"></div>
        </div>
      </div>

      <!-- ── 4. Evolução comercial (12) ──────────────────────────────────── -->
      <div class="ffc-grade">
        <div class="ffc-card ffc-col-12" id="cardEvolucao">
          <div class="ffc-card-cab">
            <div>
              <h2 class="ffc-card-titulo">Evolução comercial</h2>
              <div class="ffc-card-sub" id="evoSub">Receita realizada e meta, mês a mês</div>
            </div>
            <div class="ffc-abas" role="tablist" id="evoAbas">
              <button type="button" role="tab" data-gran="dia" aria-selected="false">Dia</button>
              <button type="button" role="tab" data-gran="semana" aria-selected="false">Semana</button>
              <button type="button" role="tab" data-gran="mes" aria-selected="true">Mês</button>
            </div>
          </div>
          <div class="ffc-grafico ffc-grafico--alto" id="evoArea"><canvas id="evoCanvas"></canvas></div>
          <div class="ffc-legenda" id="evoLegenda"></div>
        </div>
      </div>

      <!-- ── 5. Financeiro (DRE), mantido abaixo da dobra ────────────────── -->
      <div class="ffc-secao"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M9 9h4.5a1.5 1.5 0 0 1 0 3h-5a1.5 1.5 0 0 0 0 3H15"/></svg> Financeiro</div>
      <div class="ffc-grade" id="ffcDre">
        <div class="ffc-card ffc-kpi ffc-col-3" id="dreReceitaCard">
          <div class="ffc-kpi-topo"><span class="ffc-kpi-rotulo">Receita operacional</span><span class="ffc-kpi-ico ffc-kpi-ico--bom"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg></span></div>
          <div class="ffc-kpi-valor" id="dreReceita"><?= fmtBRL($dre_receita) ?></div>
          <div class="ffc-kpi-pe">vendas fechadas + receitas do DRE</div>
        </div>
        <div class="ffc-card ffc-kpi ffc-col-3">
          <div class="ffc-kpi-topo"><span class="ffc-kpi-rotulo">Custos operacionais</span><span class="ffc-kpi-ico ffc-kpi-ico--laranja"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/></svg></span></div>
          <div class="ffc-kpi-valor" id="dreDespesa"><?= fmtBRL($dre_despesa) ?></div>
          <div class="ffc-kpi-pe">despesas do período</div>
        </div>
        <div class="ffc-card ffc-kpi ffc-col-3">
          <div class="ffc-kpi-topo"><span class="ffc-kpi-rotulo">Lucro líquido</span><span class="ffc-kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg></span></div>
          <div class="ffc-kpi-valor" id="dreLucro"><?= fmtBRL($dre_lucro) ?></div>
          <div class="ffc-kpi-pe">receita menos custos</div>
        </div>
        <div class="ffc-card ffc-kpi ffc-col-3">
          <div class="ffc-kpi-topo"><span class="ffc-kpi-rotulo">Margem</span><span class="ffc-kpi-ico ffc-kpi-ico--ambar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg></span></div>
          <div class="ffc-kpi-valor" id="dreMargem"><?= $dre_margem ?>%</div>
          <div class="ffc-kpi-pe" id="dreMargemPe"><?= $dre_receita > 0 ? ($dre_margem >= 40 ? 'saudável' : ($dre_margem >= 15 ? 'atenção' : 'crítica')) : 'sem receita no período' ?></div>
        </div>
      </div>

    </section>
  </div>
</main>

<!-- Modal de meta -->
<div class="ffc-modal" id="metaModal" role="dialog" aria-modal="true" aria-labelledby="metaModalTitulo">
  <div class="ffc ffc-modal-caixa">
    <h3 id="metaModalTitulo">Meta do mês</h3>
    <p>Receita que a operação quer fechar por mês. A barra de progresso e a linha de meta dos gráficos usam este valor.</p>
    <input type="text" inputmode="decimal" class="ffc-input" id="metaInput" placeholder="R$ 0,00">
    <div class="ffc-modal-acoes">
      <button class="ffc-btn" type="button" id="metaCancelar">Cancelar</button>
      <button class="ffc-btn ffc-btn--marca" type="button" id="metaSalvar">Salvar meta</button>
    </div>
  </div>
</div>
<div class="ffc-toast" id="ffcToast" role="status" aria-live="polite"></div>

<script src="/assets/fleetiflow-dashboard.js?v=3"></script>
</body>
</html>
