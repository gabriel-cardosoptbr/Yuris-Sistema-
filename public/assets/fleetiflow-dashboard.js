/* ═══════════════════════════════════════════════════════════════════════
   fleetiflow-dashboard.js: cockpit comercial da conta Fleetiflow.
   Lê tudo de /api/dashboard_comercial.php (uma chamada) e desenha KPIs com
   comparação de período, performance (Chart.js), rosca do pipeline (SVG),
   meta em progresso, tabela de atividades e evolução. O financeiro (DRE)
   continua em /api/dre_accounts.php, como no dashboard do Yuris.

   Componentes reutilizáveis ficam em window.FleetiflowDash (kpi, progresso,
   rosca, vazio, delta) para os próximos painéis da Fleetiflow.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  const raiz = document.getElementById('ffc');
  if (!raiz) return;

  // ── Tokens (os mesmos do fleetiflow-dashboard.css) ────────────────────────
  const COR = {
    marca: '#015DFC', marcaForte: '#013DF2', marcaSuave: '#D6E4FF', marcaMedia: '#A9C6FF', marcaClara: '#6D9DFD',
    violeta: '#835FF2', ambar: '#FDAD0D', laranja: '#F55902', bom: '#017801', ruim: '#B00000',
    texto: '#3D3D3D', texto3: '#676767', texto4: '#767676', grade: 'rgba(17,29,45,0.06)', borda: 'rgba(17,29,45,0.08)'
  };
  const PALETA = ['#015DFC', '#6D9DFD', '#835FF2', '#FDAD0D', '#F55902', '#013DF2', '#A9C6FF', '#B57600', '#017801', '#767676'];
  const FONTE = "'Manrope', system-ui, -apple-system, 'Segoe UI', sans-serif";

  const moeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 });
  const moedaCent = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  const inteiro = new Intl.NumberFormat('pt-BR');
  const fmtMoeda = v => (Math.abs(v) >= 1000 ? moeda : moedaCent).format(Number(v || 0));
  const fmtPct = v => v === null || v === undefined || isNaN(v) ? '—' : (Math.round(v * 10) / 10).toLocaleString('pt-BR') + '%';
  const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const $ = id => document.getElementById(id);

  if (window.Chart) {
    Chart.defaults.font.family = FONTE;
    Chart.defaults.font.size = 11;
    Chart.defaults.color = COR.texto4;
  }
  const tooltipPadrao = {
    backgroundColor: '#FFFFFF', borderColor: COR.borda, borderWidth: 1, titleColor: COR.texto, bodyColor: '#575757',
    titleFont: { size: 12, weight: '700' }, bodyFont: { size: 12 }, padding: 12, cornerRadius: 10, caretSize: 5, displayColors: true,
    boxWidth: 8, boxHeight: 8, boxPadding: 4, usePointStyle: true
  };

  // ── Estado ────────────────────────────────────────────────────────────────
  const estado = {
    preset: '30d', start: null, end: null, responsavel: '',
    origin: raiz.dataset.origin || '', abaPerf: 'receita', granEvo: 'mes',
    dados: null, ativLimite: 5, ativBusca: '', ativEtapa: '', corEtapa: {}
  };
  let graficoPerf = null, graficoEvo = null;

  // ── Componentes reutilizáveis ─────────────────────────────────────────────
  const ICO_SOBE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>';
  const ICO_DESCE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';

  /** Pílula ↑ X% / ↓ X%. Sem base válida (anterior 0 ou nulo) devolve ''. */
  function delta(atual, anterior, invertido) {
    if (anterior === null || anterior === undefined || !(anterior > 0) || atual === null || atual === undefined) return '';
    const v = ((atual - anterior) / anterior) * 100;
    if (!isFinite(v)) return '';
    const cls = Math.abs(v) < 0.05 ? '' : ((v > 0) !== !!invertido ? 'ffc-delta--sobe' : 'ffc-delta--desce');
    const ico = Math.abs(v) < 0.05 ? '' : (v > 0 ? ICO_SOBE : ICO_DESCE);
    return `<span class="ffc-delta ${cls}">${ico}${Math.abs(v) >= 1000 ? '>999' : (Math.round(Math.abs(v) * 10) / 10).toLocaleString('pt-BR')}%</span>`;
  }

  /** Preenche um card .ffc-kpi já existente (valor + rodapé). */
  function kpi(id, valorHtml, peHtml) {
    const el = $(id); if (!el) return;
    const v = el.querySelector('[data-campo="valor"]'), p = el.querySelector('[data-campo="pe"]');
    if (v) { v.classList.remove('ffc-esq'); v.innerHTML = valorHtml; }
    if (p) p.innerHTML = peHtml || '';
  }

  function vazio(titulo, texto, botao) {
    const ico = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M8 15l3-3 2 2 4-5"/></svg>';
    return `<div class="ffc-vazio">${ico}<b>${esc(titulo)}</b><p>${esc(texto)}</p>${botao ? `<a class="ffc-btn ffc-btn--mini" href="${botao.href}">${esc(botao.rotulo)}</a>` : ''}</div>`;
  }

  function progresso(pct, tom) {
    const largura = Math.max(0, Math.min(100, pct || 0));
    return `<div class="ffc-progresso"><i class="${tom || ''}" style="width:${largura}%"></i></div>`;
  }

  /** Rosca em SVG: fatias com respiro, total no centro. itens = [{nome, qtd, cor}]. */
  function rosca(itens, total, rotulo) {
    const TAM = 118, R = 46, ESP = 15, RESPIRO = 4, MIN = 6;
    const circ = 2 * Math.PI * R;
    const vis = itens.filter(i => i.qtd > 0);
    let arcos = [];
    if (vis.length) {
      const disp = circ - vis.length * RESPIRO;
      arcos = vis.map(i => (i.qtd / total) * disp);
      const peq = arcos.map(a => a < MIN);
      const deficit = arcos.reduce((s, a, k) => s + (peq[k] ? MIN - a : 0), 0);
      const grandes = arcos.reduce((s, a, k) => s + (peq[k] ? 0 : a), 0) || 1;
      arcos = arcos.map((a, k) => peq[k] ? MIN : Math.max(MIN, a - deficit * (a / grandes)));
    }
    let svg = `<svg class="ffc-rosca-svg" viewBox="0 0 ${TAM} ${TAM}" role="img" aria-label="${esc(rotulo)}: ${total}"><g transform="rotate(-90 ${TAM / 2} ${TAM / 2})">`;
    if (!vis.length) svg += `<circle cx="${TAM / 2}" cy="${TAM / 2}" r="${R}" fill="none" stroke="#F1F1F2" stroke-width="${ESP}"/>`;
    let ini = 0;
    vis.forEach((i, k) => {
      const traco = Math.max(0.5, arcos[k] - (vis.length > 1 ? ESP * 0.35 : 0));
      svg += `<circle cx="${TAM / 2}" cy="${TAM / 2}" r="${R}" fill="none" stroke="${i.cor}" stroke-width="${ESP}" stroke-linecap="round" stroke-dasharray="${traco} ${circ}" stroke-dashoffset="${-(ini + (vis.length > 1 ? ESP * 0.175 : 0))}"><title>${esc(i.nome)}: ${i.qtd}</title></circle>`;
      ini += arcos[k] + RESPIRO;
    });
    svg += `</g><text x="${TAM / 2}" y="${TAM / 2 - 1}" text-anchor="middle" style="fill:${COR.texto};font-size:22px;font-weight:800;letter-spacing:-0.02em">${inteiro.format(total)}</text>`;
    svg += `<text x="${TAM / 2}" y="${TAM / 2 + 13}" text-anchor="middle" style="fill:${COR.texto4};font-size:7.5px;font-weight:700;letter-spacing:.08em">${esc(rotulo).toUpperCase()}</text></svg>`;
    return svg;
  }

  window.FleetiflowDash = { kpi, delta, vazio, progresso, rosca, fmtMoeda, fmtPct };

  // ── Período ───────────────────────────────────────────────────────────────
  const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  function intervaloDoPreset(p) {
    const hoje = new Date(); hoje.setHours(0, 0, 0, 0);
    const d = n => { const x = new Date(hoje); x.setDate(x.getDate() - n); return x; };
    switch (p) {
      case '7d':  return [iso(d(6)), iso(hoje)];
      case '90d': return [iso(d(89)), iso(hoje)];
      case 'mes': return [iso(new Date(hoje.getFullYear(), hoje.getMonth(), 1)), iso(hoje)];
      case 'mes_anterior': return [iso(new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1)), iso(new Date(hoje.getFullYear(), hoje.getMonth(), 0))];
      case 'ano': return [iso(new Date(hoje.getFullYear(), 0, 1)), iso(hoje)];
      default:    return [iso(d(29)), iso(hoje)];
    }
  }
  function presetDoIntervalo(s, e) {
    for (const p of ['7d', '30d', '90d', 'mes', 'mes_anterior', 'ano']) { const [a, b] = intervaloDoPreset(p); if (a === s && b === e) return p; }
    return 'custom';
  }
  function aplicarPreset(p) {
    estado.preset = p;
    $('ffcPeriodo').value = p;
    $('ffcDatas').classList.toggle('aberto', p === 'custom');
    if (p !== 'custom') { [estado.start, estado.end] = intervaloDoPreset(p); }
    $('ffcInicio').value = estado.start || ''; $('ffcFim').value = estado.end || '';
  }
  const fmtData = s => { const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? `${m[3]}/${m[2]}` : s; };

  async function persistirPeriodo() {
    // Mesmo endpoint do dashboard do Yuris: o DRE renderizado no servidor lê a sessão.
    try {
      await fetch('/api/dashboard_settings.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ start: estado.start || '', end: estado.end || '' }) });
    } catch (e) { /* período fica só nesta visita */ }
  }

  // ── Carregamento ──────────────────────────────────────────────────────────
  async function carregar(silencioso) {
    const btn = $('ffcAtualizar'); btn.classList.add('girando'); btn.disabled = true;
    try {
      const qs = new URLSearchParams();
      if (estado.start && estado.end) { qs.set('start', estado.start); qs.set('end', estado.end); }
      if (estado.responsavel) qs.set('responsavel', estado.responsavel);
      if (estado.origin) qs.set('origin', estado.origin);
      const r = await fetch('/api/dashboard_comercial.php?' + qs.toString(), { credentials: 'same-origin' });
      const j = await r.json();
      if (!r.ok || j.error) throw new Error(j.error || 'Falha ao carregar');
      estado.dados = j;
      estado.corEtapa = {};
      j.pipeline.forEach((p, i) => { estado.corEtapa[p.slug] = PALETA[i % PALETA.length]; });
      desenharResponsaveis(j.responsaveis);
      desenharKpis(j);
      desenharPerformance(j);
      desenharPipeline(j);
      desenharMeta(j);
      desenharAtividades(j);
      desenharEvolucao(j);
      if (!silencioso) aviso('Painel atualizado');
    } catch (e) {
      console.error('cockpit', e);
      aviso('Não foi possível carregar o painel comercial', true);
    } finally { btn.classList.remove('girando'); btn.disabled = false; }
    carregarDre();
  }

  async function carregarDre() {
    try {
      const qs = new URLSearchParams();
      if (estado.start) qs.set('start', estado.start);
      if (estado.end) qs.set('end', estado.end);
      const r = await fetch('/api/dre_accounts.php?' + qs.toString(), { credentials: 'same-origin' });
      if (!r.ok) return;
      const j = await r.json(); const c = j.combined || {};
      const receita = Number(c.receita || 0), despesa = Number(c.despesa || 0), lucro = receita - despesa;
      const margem = receita > 0 ? Math.round((lucro / receita) * 1000) / 10 : 0;
      $('dreReceita').textContent = moedaCent.format(receita);
      $('dreDespesa').textContent = moedaCent.format(despesa);
      $('dreLucro').textContent = moedaCent.format(lucro);
      $('dreMargem').textContent = margem.toLocaleString('pt-BR') + '%';
      $('dreMargemPe').textContent = receita > 0 ? (margem >= 40 ? 'saudável' : margem >= 15 ? 'atenção' : 'crítica') : 'sem receita no período';
    } catch (e) { /* mantém os valores renderizados no servidor */ }
  }

  function desenharResponsaveis(lista) {
    const sel = $('ffcResponsavel');
    if (sel.options.length > 1 || !Array.isArray(lista)) return;
    lista.forEach(u => { const o = document.createElement('option'); o.value = u.id; o.textContent = u.nome; sel.appendChild(o); });
    sel.value = estado.responsavel;
    sel.style.display = lista.length > 1 ? '' : 'none';
  }

  // ── 1. KPIs ───────────────────────────────────────────────────────────────
  function desenharKpis(j) {
    const a = j.kpis.atual, b = j.kpis.anterior;
    const vs = `<span>vs. ${fmtData(j.periodo.anterior.start)}–${fmtData(j.periodo.anterior.end)}</span>`;
    const pe = (d, semBase) => d ? d + vs : `<span>${semBase}</span>`;
    kpi('kpiLeads', inteiro.format(a.leads), pe(delta(a.leads, b.leads), a.leads ? 'sem base de comparação' : 'nenhum lead no período'));
    kpi('kpiOport', inteiro.format(a.oportunidades),
        `<span>${inteiro.format(a.em_negociacao)} em negociação · ${fmtMoeda(a.valor_pipeline)} no funil</span>`);
    kpi('kpiVendas', inteiro.format(a.vendas), pe(delta(a.vendas, b.vendas), a.vendas ? 'sem base de comparação' : 'nenhuma venda no período'));
    kpi('kpiReceita', fmtMoeda(a.receita), pe(delta(a.receita, b.receita), a.receita ? 'sem base de comparação' : 'nada fechado no período'));
    kpi('kpiConversao', a.conversao === null ? '—' : fmtPct(a.conversao),
        a.conversao === null ? '<span>precisa de leads no período</span>' : pe(delta(a.conversao, b.conversao), `${a.vendas} de ${a.leads} leads`));
    kpi('kpiTicket', a.ticket === null ? '—' : fmtMoeda(a.ticket),
        a.ticket === null ? '<span>precisa de vendas no período</span>' : pe(delta(a.ticket, b.ticket), 'receita ÷ vendas'));
  }

  // ── 2. Performance comercial ──────────────────────────────────────────────
  const GRAN_NOME = { dia: 'dia', semana: 'semana', mes: 'mês' };
  function desenharPerformance(j) {
    const serie = j.serie || [], gran = GRAN_NOME[j.periodo.granularidade] || 'dia';
    const area = $('perfArea'), leg = $('perfLegenda');
    if (graficoPerf) { graficoPerf.destroy(); graficoPerf = null; }
    const aba = estado.abaPerf;
    const temReceita = serie.some(p => p.receita > 0), temVendas = serie.some(p => p.vendas > 0), temLeads = serie.some(p => p.leads > 0);
    // Estado vazio por aba: nada de eixo em R$ 0,00 / R$ 0,50 quando não há o que mostrar.
    let vazioAba = null;
    if (aba === 'receita' && !temReceita) vazioAba = ['Nenhuma receita fechada neste período', temLeads ? 'Leads entraram, mas nenhuma venda foi concluída no intervalo. A aba Vendas mostra o que entrou.' : 'A performance aparece assim que houver vendas no intervalo escolhido.'];
    else if (aba === 'vendas' && !temVendas && !temLeads) vazioAba = ['Ainda não há movimento neste período', 'Vendas e leads aparecem aqui assim que houver movimentação no intervalo.'];
    else if (aba === 'conversao' && (!temVendas || !temLeads)) vazioAba = ['Sem base para calcular a conversão', temLeads ? 'Há leads no período, mas nenhuma venda concluída ainda.' : 'A conversão precisa de leads e vendas no mesmo intervalo.'];
    if (vazioAba) {
      area.innerHTML = vazio(vazioAba[0], vazioAba[1], { href: '/prospeccao.php', rotulo: 'Ver pipeline' });
      area.style.height = 'auto'; leg.innerHTML = ''; $('perfSub').textContent = 'Sem dados para esta visão no período'; return;
    }
    area.style.height = ''; area.innerHTML = '<canvas id="perfCanvas"></canvas>';
    const labels = serie.map(p => p.label);
    let datasets, sub, legenda, escalaY;
    if (aba === 'receita') {
      const temMeta = serie.some(p => p.meta !== null);
      datasets = [{ type: 'bar', label: 'Receita', data: serie.map(p => p.receita), backgroundColor: COR.marca, hoverBackgroundColor: COR.marcaForte, borderRadius: 4, borderSkipped: false, maxBarThickness: 22, order: 2 }];
      if (temMeta) datasets.push({ type: 'line', label: 'Meta proporcional', data: serie.map(p => p.meta), borderColor: COR.texto4, borderDash: [5, 4], borderWidth: 1.5, pointRadius: 0, pointHoverRadius: 3, tension: 0, order: 1 });
      sub = `Receita realizada por ${gran}` + (temMeta ? ', contra a meta proporcional' : '');
      legenda = `<span><i style="background:${COR.marca}"></i>Receita fechada</span>` + (temMeta ? '<span><i class="tracejado"></i>Meta proporcional ao período</span>' : '');
      escalaY = { ticks: { callback: v => fmtMoeda(v), maxTicksLimit: 5 } };
    } else if (aba === 'vendas') {
      datasets = [
        { type: 'bar', label: 'Vendas', data: serie.map(p => p.vendas), backgroundColor: COR.bom, borderRadius: 4, borderSkipped: false, maxBarThickness: 22, order: 2 },
        { type: 'bar', label: 'Novos leads', data: serie.map(p => p.leads), backgroundColor: COR.marcaMedia, borderRadius: 4, borderSkipped: false, maxBarThickness: 22, order: 3 }
      ];
      sub = `Vendas fechadas e leads que entraram, por ${gran}`;
      legenda = `<span><i style="background:${COR.bom}"></i>Vendas fechadas</span><span><i style="background:${COR.marcaMedia}"></i>Novos leads</span>`;
      escalaY = { ticks: { precision: 0, maxTicksLimit: 5 } };
    } else {
      datasets = [{ type: 'line', label: 'Conversão', data: serie.map(p => p.leads > 0 ? Math.round(p.vendas / p.leads * 1000) / 10 : null), borderColor: COR.violeta, backgroundColor: 'rgba(131,95,242,0.10)', fill: true, borderWidth: 2, pointRadius: 3, pointBackgroundColor: '#fff', pointBorderColor: COR.violeta, pointBorderWidth: 2, tension: 0.3, spanGaps: true }];
      sub = `Vendas ÷ leads ${ { dia: 'do mesmo dia', semana: 'da mesma semana', mes: 'do mesmo mês' }[j.periodo.granularidade] }, em %`;
      legenda = `<span><i style="background:${COR.violeta}"></i>Conversão</span>`;
      escalaY = { ticks: { callback: v => v + '%', maxTicksLimit: 5 }, suggestedMax: 20 };
    }
    $('perfSub').textContent = sub; leg.innerHTML = legenda;
    graficoPerf = new Chart($('perfCanvas').getContext('2d'), {
      data: { labels, datasets },
      options: {
        responsive: true, maintainAspectRatio: false, animation: { duration: 500, easing: 'easeOutQuart' },
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: Object.assign({}, tooltipPadrao, { callbacks: {
          label: c => ' ' + c.dataset.label + ': ' + (aba === 'receita' ? fmtMoeda(c.parsed.y) : aba === 'conversao' ? fmtPct(c.parsed.y) : inteiro.format(c.parsed.y))
        } }) },
        scales: {
          x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 12, maxRotation: 0, autoSkip: true } },
          y: Object.assign({ beginAtZero: true, grid: { color: COR.grade }, border: { display: false, dash: [3, 3] } }, escalaY)
        }
      }
    });
  }

  // ── 3. Pipeline ───────────────────────────────────────────────────────────
  function desenharPipeline(j) {
    const area = $('pipelineArea');
    const funil = j.pipeline.filter(p => p.funil).map((p, i) => ({ nome: p.nome, qtd: p.qtd, cor: estado.corEtapa[p.slug] }));
    const fora = j.pipeline.filter(p => !p.funil);
    const total = funil.reduce((s, p) => s + p.qtd, 0);
    if (!total && !fora.some(p => p.qtd > 0)) {
      area.innerHTML = vazio('Nenhuma oportunidade no funil', 'Os leads abordados pela Vitória e os cards criados na prospecção aparecem aqui.', { href: '/prospeccao.php', rotulo: 'Abrir prospecção' });
      return;
    }
    const lista = funil.map(p => {
      const pct = total ? Math.round(p.qtd / total * 1000) / 10 : 0;
      return `<div class="ffc-rosca-item"><i style="background:${p.cor}"></i><span class="nome" title="${esc(p.nome)}">${esc(p.nome)}</span><b>${p.qtd}</b><span class="pct">${pct.toLocaleString('pt-BR')}%</span></div>`;
    }).join('');
    const chips = fora.map(p => {
      const tom = p.fechado ? 'ffc-chip--bom' : p.perdido ? 'ffc-chip--ruim' : '';
      return `<span class="ffc-chip ${tom}" title="${esc(p.nome)}">${esc(p.nome)} <b>${p.qtd}</b></span>`;
    }).join('');
    area.innerHTML = `<div class="ffc-rosca">${rosca(funil, total, 'oportunidades')}<div class="ffc-rosca-lista">${lista}</div></div>` + (chips ? `<div class="ffc-fora-funil">${chips}</div>` : '');
  }

  // ── 4. Meta ───────────────────────────────────────────────────────────────
  const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  function diasRestantes(m) {
    const mesAtual = new Date().toISOString().slice(0, 7);
    if (m.mes < mesAtual) return 'mês encerrado';
    if (m.dias_restantes === 0) return 'último dia do mês';
    return m.dias_restantes + (m.dias_restantes === 1 ? ' dia restante' : ' dias restantes');
  }
  /** Ritmo: projeção do mês no ritmo atual e quanto falta fechar por dia. */
  function ritmoHtml(m, restante) {
    const hoje = new Date(); const mesAtual = hoje.toISOString().slice(0, 7);
    if (m.mes !== mesAtual) return '';
    const diaHoje = hoje.getDate(), diasMes = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0).getDate();
    const projecao = diaHoje > 0 ? m.fechado / diaHoje * diasMes : 0;
    const porDia = m.dias_restantes > 0 ? restante / m.dias_restantes : restante;
    return `<div class="ffc-meta-dado"><span>Projeção no ritmo</span><b>${fmtMoeda(projecao)}</b></div>` +
      `<div class="ffc-meta-dado"><span>${m.dias_restantes > 0 ? 'Precisa por dia' : 'Falta hoje'}</span><b>${restante > 0 ? fmtMoeda(porDia) : 'nada'}</b></div>`;
  }
  function desenharMeta(j) {
    const m = j.meta, area = $('metaArea');
    $('metaTitulo').textContent = 'Meta de ' + MESES[parseInt(m.mes.slice(5), 10) - 1];
    if (!(m.valor > 0)) {
      area.innerHTML = vazio('Defina a meta do mês', 'Com a meta definida, a barra de progresso e a linha de meta dos gráficos passam a funcionar.')
        .replace('</div>', '<button class="ffc-btn ffc-btn--mini ffc-btn--marca" type="button" data-abrir-meta>Definir meta</button></div>');
      return;
    }
    const pct = m.progresso || 0, restante = Math.max(0, m.valor - m.fechado);
    const tom = pct >= 100 ? 'ok' : (m.dias_restantes <= 7 && pct < 70 ? 'risco' : '');
    area.innerHTML =
      `<div class="ffc-meta-valor">${fmtMoeda(m.fechado)}<small>fechados</small></div>` +
      progresso(pct, tom) +
      `<div class="ffc-progresso-legenda"><b>${fmtPct(pct)} da meta</b><span>${diasRestantes(m)}</span></div>` +
      `<div class="ffc-meta-grid"><div class="ffc-meta-dado"><span>Meta</span><b>${fmtMoeda(m.valor)}</b></div><div class="ffc-meta-dado"><span>Restante</span><b>${pct >= 100 ? 'batida' : fmtMoeda(restante)}</b></div>` +
      ritmoHtml(m, restante) + `</div>` +
      `<div class="ffc-meta-rodape"><span>${m.vendas} venda${m.vendas === 1 ? '' : 's'} fechada${m.vendas === 1 ? '' : 's'} no mês</span>${m.vendas ? `<span>ticket ${fmtMoeda(m.fechado / m.vendas)}</span>` : ''}</div>`;
  }

  // ── 5. Atividades ─────────────────────────────────────────────────────────
  const relativo = s => {
    const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/); if (!m) return '—';
    const d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
    const hoje = new Date(); const diff = Math.floor((new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate()) - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 864e5);
    const hora = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    if (diff === 0) return 'Hoje ' + hora;
    if (diff === 1) return 'Ontem ' + hora;
    if (diff < 7) return diff + ' dias atrás';
    return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + ' ' + hora;
  };
  const iniciais = n => String(n || '').trim().split(/\s+/).slice(0, 2).map(p => p[0] || '').join('').toUpperCase() || '?';

  function desenharAtividades(j) {
    const sel = $('ativEtapa');
    if (sel.options.length <= 1) j.pipeline.forEach(p => { const o = document.createElement('option'); o.value = p.slug; o.textContent = p.nome; sel.appendChild(o); });
    const area = $('ativArea'), rodape = $('ativRodape');
    const busca = estado.ativBusca.trim().toLowerCase();
    const lista = (j.atividades || []).filter(a =>
      (!estado.ativEtapa || a.etapa_slug === estado.ativEtapa) &&
      (!busca || [a.cliente, a.empresa, a.titulo, a.responsavel].some(v => String(v || '').toLowerCase().includes(busca))));
    if (!lista.length) {
      area.innerHTML = vazio(j.atividades.length ? 'Nada com esse filtro' : 'Nenhuma movimentação ainda', j.atividades.length ? 'Tente outra etapa ou outro nome.' : 'Os negócios aparecem aqui assim que houver cards no pipeline.', j.atividades.length ? null : { href: '/prospeccao.php', rotulo: 'Abrir pipeline' });
      rodape.innerHTML = ''; return;
    }
    const mostrar = lista.slice(0, estado.ativLimite);
    const linhas = mostrar.map(a => {
      const cor = estado.corEtapa[a.etapa_slug] || COR.texto4;
      return `<tr data-id="${a.id}" title="Abrir no pipeline">
        <td data-rotulo="Cliente"><div class="cliente">${esc(a.cliente || '—')}${a.empresa && a.empresa !== a.cliente ? `<small>${esc(a.empresa)}</small>` : ''}</div></td>
        <td data-rotulo="Oportunidade" class="col-oport">${esc(a.titulo || 'Sem descrição')}</td>
        <td data-rotulo="Valor"><span class="valor">${a.valor > 0 ? fmtMoeda(a.valor) : '—'}</span></td>
        <td data-rotulo="Etapa"><span class="ffc-etapa"><i style="background:${cor}"></i><span>${esc(a.etapa || 'Sem etapa')}</span></span></td>
        <td data-rotulo="Responsável" class="col-resp">${a.responsavel ? `<span class="ffc-avatar">${esc(iniciais(a.responsavel))}</span>${esc(a.responsavel.split(' ')[0])}` : '<span style="color:#767676">—</span>'}</td>
        <td data-rotulo="Atualizado" class="col-quando"><span class="quando">${esc(relativo(a.quando))}</span></td>
        <td class="acao"><a href="/prospeccao.php?open=${a.id}" aria-label="Abrir"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></a></td>
      </tr>`;
    }).join('');
    area.innerHTML = `<table class="ffc-tabela"><thead><tr><th>Cliente</th><th class="col-oport">Oportunidade</th><th>Valor</th><th>Etapa</th><th class="col-resp">Responsável</th><th class="col-quando">Atualizado</th><th></th></tr></thead><tbody>${linhas}</tbody></table>`;
    rodape.innerHTML = `<span>Mostrando ${mostrar.length} de ${lista.length}</span>` +
      (lista.length > estado.ativLimite ? `<button class="ffc-btn ffc-btn--mini ffc-btn--link" type="button" data-ver-todas>Ver todas</button>`
        : `<a class="ffc-btn ffc-btn--mini ffc-btn--link" href="/prospeccao.php">Abrir pipeline</a>`);
  }

  // ── 6. Evolução ───────────────────────────────────────────────────────────
  function desenharEvolucao(j) {
    const serie = (j.evolucao && j.evolucao[estado.granEvo]) || [];
    const area = $('evoArea'), leg = $('evoLegenda');
    const temDado = serie.some(p => p.receita > 0 || p.vendas > 0 || p.leads > 0);
    if (graficoEvo) { graficoEvo.destroy(); graficoEvo = null; }
    const gran = GRAN_NOME[estado.granEvo];
    if (!temDado) {
      area.innerHTML = vazio('Ainda não existem vendas suficientes', `A evolução por ${gran} será exibida assim que houver movimentação comercial.`, { href: '/prospeccao.php', rotulo: 'Ver pipeline' });
      area.style.height = 'auto'; leg.innerHTML = ''; $('evoSub').textContent = 'Sem dados'; return;
    }
    area.style.height = ''; area.innerHTML = '<canvas id="evoCanvas"></canvas>';
    const janela = { dia: 'Últimos 30 dias', semana: 'Últimas 16 semanas', mes: 'Últimos 12 meses' }[estado.granEvo];
    const temReceita = serie.some(p => p.receita > 0), temMeta = temReceita && serie.some(p => p.meta !== null);
    let datasets, escalas;
    if (temReceita) {
      $('evoSub').textContent = janela + ': receita realizada' + (temMeta ? ', meta' : '') + ' e novos leads';
      leg.innerHTML = `<span><i style="background:${COR.marca}"></i>Receita fechada</span>` + (temMeta ? '<span><i class="tracejado"></i>Meta</span>' : '') + `<span><i style="background:${COR.violeta}"></i>Novos leads (eixo direito)</span>`;
      datasets = [
        { type: 'bar', label: 'Receita', data: serie.map(p => p.receita), backgroundColor: COR.marca, hoverBackgroundColor: COR.marcaForte, borderRadius: 4, borderSkipped: false, maxBarThickness: 26, yAxisID: 'y', order: 3 },
        { type: 'line', label: 'Novos leads', data: serie.map(p => p.leads), borderColor: COR.violeta, borderWidth: 2, pointRadius: 2.5, pointBackgroundColor: '#fff', pointBorderColor: COR.violeta, pointBorderWidth: 2, tension: 0.3, yAxisID: 'y2', order: 1 }
      ];
      if (temMeta) datasets.splice(1, 0, { type: 'line', label: 'Meta', data: serie.map(p => p.meta), borderColor: COR.texto4, borderDash: [5, 4], borderWidth: 1.5, pointRadius: 0, pointHoverRadius: 3, tension: 0, yAxisID: 'y', order: 2 });
      escalas = {
        y: { beginAtZero: true, grid: { color: COR.grade }, border: { display: false, dash: [3, 3] }, ticks: { callback: v => fmtMoeda(v), maxTicksLimit: 5 } },
        y2: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5, color: COR.violeta } }
      };
    } else {
      // Ainda sem receita: só a entrada de leads, sem eixo em R$ zerado.
      $('evoSub').textContent = janela + ': novos leads e vendas (ainda sem receita fechada)';
      leg.innerHTML = `<span><i style="background:${COR.marcaMedia}"></i>Novos leads</span><span><i style="background:${COR.bom}"></i>Vendas fechadas</span>`;
      datasets = [
        { type: 'bar', label: 'Novos leads', data: serie.map(p => p.leads), backgroundColor: COR.marcaMedia, borderRadius: 4, borderSkipped: false, maxBarThickness: 26, yAxisID: 'y', order: 2 },
        { type: 'bar', label: 'Vendas fechadas', data: serie.map(p => p.vendas), backgroundColor: COR.bom, borderRadius: 4, borderSkipped: false, maxBarThickness: 26, yAxisID: 'y', order: 1 }
      ];
      escalas = { y: { beginAtZero: true, grid: { color: COR.grade }, border: { display: false, dash: [3, 3] }, ticks: { precision: 0, maxTicksLimit: 5 } } };
    }
    graficoEvo = new Chart($('evoCanvas').getContext('2d'), {
      data: { labels: serie.map(p => p.label), datasets },
      options: {
        responsive: true, maintainAspectRatio: false, animation: { duration: 500, easing: 'easeOutQuart' },
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: Object.assign({}, tooltipPadrao, { callbacks: {
          label: c => ' ' + c.dataset.label + ': ' + (c.dataset.label === 'Receita' || c.dataset.label === 'Meta' ? fmtMoeda(c.parsed.y) : inteiro.format(c.parsed.y))
        } }) },
        scales: Object.assign({ x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 16, maxRotation: 0, autoSkip: true } } }, escalas)
      }
    });
  }

  // ── Meta: modal ───────────────────────────────────────────────────────────
  function abrirMeta() {
    const m = estado.dados && estado.dados.meta;
    $('metaInput').value = m && m.valor > 0 ? m.valor.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) : '';
    $('metaModal').classList.add('aberto');
    setTimeout(() => $('metaInput').focus(), 30);
  }
  function fecharMeta() { $('metaModal').classList.remove('aberto'); }
  function numeroDigitado(s) {
    s = String(s || '').trim().replace(/[^\d,.\-]/g, '');
    if (s.includes('.') && s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
    else if (s.includes(',')) s = s.replace(',', '.');
    const n = Number(s); return isNaN(n) ? NaN : n;
  }
  async function salvarMeta() {
    const v = numeroDigitado($('metaInput').value);
    if (isNaN(v) || v < 0) { aviso('Informe um valor válido para a meta', true); return; }
    const btn = $('metaSalvar'); btn.disabled = true;
    try {
      const r = await fetch('/api/goals.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ valor_meta: v }) });
      const j = await r.json();
      if (!r.ok || j.error) throw new Error(j.error || 'erro');
      fecharMeta(); aviso('Meta salva'); carregar(true);
    } catch (e) { aviso('Não foi possível salvar a meta', true); }
    finally { btn.disabled = false; }
  }

  // ── Exportar imagem (html2canvas, como o dashboard do Yuris) ──────────────
  function exportar() {
    const capturar = () => {
      html2canvas(document.documentElement, { useCORS: true, scale: Math.min(2, window.devicePixelRatio || 1), backgroundColor: '#F6F7F9', logging: false })
        .then(c => c.toBlob(b => { const a = document.createElement('a'); a.download = 'analise-comercial_' + new Date().toISOString().slice(0, 10) + '.png'; a.href = URL.createObjectURL(b); document.body.appendChild(a); a.click(); a.remove(); }, 'image/png'))
        .catch(() => aviso('Não foi possível gerar a imagem', true));
    };
    if (typeof html2canvas === 'undefined') { const s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js'; s.onload = capturar; document.head.appendChild(s); }
    else capturar();
  }

  // ── Aviso ─────────────────────────────────────────────────────────────────
  let toastTimer = null;
  function aviso(msg, ruim) {
    const t = $('ffcToast'); t.textContent = msg; t.classList.toggle('ruim', !!ruim); t.classList.add('visivel');
    clearTimeout(toastTimer); toastTimer = setTimeout(() => t.classList.remove('visivel'), 2400);
  }

  // ── Eventos ───────────────────────────────────────────────────────────────
  $('ffcPeriodo').addEventListener('change', async e => {
    aplicarPreset(e.target.value);
    if (e.target.value !== 'custom') { await persistirPeriodo(); carregar(true); }
  });
  $('ffcAplicar').addEventListener('click', async () => {
    const s = $('ffcInicio').value, f = $('ffcFim').value;
    if (!s || !f) { aviso('Informe início e fim do período', true); return; }
    estado.start = s <= f ? s : f; estado.end = s <= f ? f : s; estado.preset = 'custom';
    await persistirPeriodo(); carregar(true);
  });
  $('ffcResponsavel').addEventListener('change', e => { estado.responsavel = e.target.value; carregar(true); });
  const origem = $('ffcOrigem');
  if (origem) origem.addEventListener('change', e => { const u = new URL(location.href); e.target.value ? u.searchParams.set('origin', e.target.value) : u.searchParams.delete('origin'); location.href = u.toString(); });
  $('ffcAtualizar').addEventListener('click', () => carregar(false));

  const menu = $('ffcMenu'), mais = $('ffcMais');
  mais.addEventListener('click', e => { e.stopPropagation(); const ab = menu.classList.toggle('aberto'); mais.setAttribute('aria-expanded', ab ? 'true' : 'false'); });
  document.addEventListener('click', () => { menu.classList.remove('aberto'); mais.setAttribute('aria-expanded', 'false'); });
  menu.addEventListener('click', e => {
    const b = e.target.closest('button[data-acao]'); if (!b) return;
    menu.classList.remove('aberto');
    ({ meta: abrirMeta, exportar, pipeline: () => location.href = '/prospeccao.php', planejamento: () => location.href = '/planejamento.php' })[b.dataset.acao]();
  });

  $('perfAbas').addEventListener('click', e => {
    const b = e.target.closest('button[data-aba]'); if (!b) return;
    estado.abaPerf = b.dataset.aba;
    $('perfAbas').querySelectorAll('button').forEach(x => x.setAttribute('aria-selected', x === b ? 'true' : 'false'));
    if (estado.dados) desenharPerformance(estado.dados);
  });
  $('evoAbas').addEventListener('click', e => {
    const b = e.target.closest('button[data-gran]'); if (!b) return;
    estado.granEvo = b.dataset.gran;
    $('evoAbas').querySelectorAll('button').forEach(x => x.setAttribute('aria-selected', x === b ? 'true' : 'false'));
    if (estado.dados) desenharEvolucao(estado.dados);
  });

  $('ativBusca').addEventListener('input', e => { estado.ativBusca = e.target.value; estado.ativLimite = 5; if (estado.dados) desenharAtividades(estado.dados); });
  $('ativEtapa').addEventListener('change', e => { estado.ativEtapa = e.target.value; estado.ativLimite = 5; if (estado.dados) desenharAtividades(estado.dados); });
  $('cardAtividades').addEventListener('click', e => {
    if (e.target.closest('[data-ver-todas]')) { estado.ativLimite = 25; desenharAtividades(estado.dados); return; }
    const tr = e.target.closest('tr[data-id]'); if (tr && !e.target.closest('a')) location.href = '/prospeccao.php?open=' + tr.dataset.id;
  });

  $('metaEditar').addEventListener('click', abrirMeta);
  $('cardMeta').addEventListener('click', e => { if (e.target.closest('[data-abrir-meta]')) abrirMeta(); });
  $('metaCancelar').addEventListener('click', fecharMeta);
  $('metaSalvar').addEventListener('click', salvarMeta);
  $('metaModal').addEventListener('click', e => { if (e.target === e.currentTarget) fecharMeta(); });
  $('metaInput').addEventListener('keydown', e => { if (e.key === 'Enter') salvarMeta(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { fecharMeta(); menu.classList.remove('aberto'); } });

  // ── Início: período salvo na sessão (ou últimos 30 dias) ──────────────────
  (async function () {
    let s = null, f = null;
    try {
      const r = await fetch('/api/dashboard_settings.php', { credentials: 'same-origin' });
      const j = await r.json();
      if (/^\d{4}-\d{2}-\d{2}$/.test(j.start || '') && /^\d{4}-\d{2}-\d{2}$/.test(j.end || '')) { s = j.start; f = j.end; }
    } catch (e) { /* sem sessão salva */ }
    if (s && f) { estado.start = s; estado.end = f; aplicarPreset(presetDoIntervalo(s, f)); if (estado.preset === 'custom') { estado.start = s; estado.end = f; $('ffcInicio').value = s; $('ffcFim').value = f; } }
    else { aplicarPreset('30d'); persistirPeriodo(); }
    carregar(true);
  })();
})();
