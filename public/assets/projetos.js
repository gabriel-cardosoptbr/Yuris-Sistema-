/**
 * Pasta Projetos (edição CRM, 14/10/2026).
 *
 * Duas telas no mesmo arquivo, escolhidas pelo data-slug de #projPagina:
 *   - sem slug: Visão geral da carteira (indicadores, gráficos, um cartão por
 *     projeto-mãe);
 *   - com slug: a página do projeto (números do projeto + subprojetos, gráficos e
 *     as abas Visão geral, Subprojetos, Contas, Custos e receitas, Acessos e
 *     Ficha e pendências).
 *
 * Fala com /api/projetos.php; toda conta de dinheiro (rateio de servidor e por
 * conta, arrecadado, evolução, agregado da mãe, pendências) chega pronta de
 * App\Projetos. Aqui só se desenha e se edita. Gráficos com Chart.js.
 */
(function () {
  'use strict';
  const API = '/api/projetos.php';
  const $ = id => document.getElementById(id);
  const raiz = $('projPagina');
  if (!raiz) return;

  const SLUG = raiz.dataset.slug || '';
  const CSRF = document.querySelector('[name=csrf_token]')?.value || '';
  const brl = v => v === null || v === undefined ? null : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const brl0 = v => 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 });
  const moeda = (v, m) => (m === 'USD' ? 'US$ ' : 'R$ ') + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const num = v => Number(v || 0).toLocaleString('pt-BR');
  const data = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : '';
  const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const falta = (t = 'a preencher') => `<span class="proj-falta">${t}</span>`;
  const toast = (m, t) => { if (window.Yuris && Yuris.toast) Yuris.toast(m, t || 'info'); };
  const sinal = v => v > 0 ? 'proj-pos' : (v < 0 ? 'proj-neg' : '');
  const pct = v => v === null || v === undefined ? '' : String(v).replace('.', ',') + '%';
  const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
  const rotMes = ym => MESES[Number(ym.slice(5, 7)) - 1] + '/' + ym.slice(2, 4);
  const ico = d => `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">${d}</svg>`;
  const I = {
    mais: ico('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>'),
    lapis: ico('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>'),
    lixo: ico('<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>'),
    link: ico('<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>'),
    copiar: ico('<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>'),
    olho: ico('<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'),
    srv: ico('<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/>'),
    chave: ico('<circle cx="7.5" cy="15.5" r="4.5"/><path d="M21 2l-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>'),
  };

  let P = { projetos: [], servidores: [], resumo: {}, opcoes: {}, cotacao_usd: 5.5 };
  let porId = {}, porSlug = {}, filhosDe = {};
  let aba = (location.hash || '').replace('#', '') || 'visao';
  let filtro = 'ativos', busca = '';
  const CH = {};

  /* ── Comunicação ── */
  async function chamar(corpo) {
    const r = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(corpo) });
    let j = {};
    try { j = await r.json(); } catch (e) { /* resposta sem JSON */ }
    if (!r.ok || !j.success) throw new Error(j.error || 'Não foi possível salvar. Tente de novo.');
    if (j.projetos) receber(j);
    return j;
  }

  async function carregar() {
    try {
      const r = await fetch(API, { credentials: 'same-origin' });
      const j = await r.json();
      if (!r.ok) throw new Error(j.error || 'erro');
      receber(j);
    } catch (e) {
      $('projConteudo').innerHTML = 'Não foi possível carregar os projetos. Recarregue a página.';
    }
  }

  function receber(j) {
    P = j;
    porId = {}; porSlug = {}; filhosDe = {};
    P.projetos.forEach(p => { porId[p.id] = p; porSlug[p.slug] = p; });
    P.projetos.forEach(p => { if (p.parent_id) (filhosDe[p.parent_id] = filhosDe[p.parent_id] || []).push(p); });
    desenharServidores();
    preencherFornecedores();
    if (SLUG && !porSlug[SLUG]) { location.href = '/projetos.php'; return; }
    desenhar();
  }

  const nomeStatus = st => P.opcoes.status?.[st] || st;
  const selo = st => `<span class="proj-selo st-${esc(st)}">${esc(nomeStatus(st))}</span>`;
  const nomeCat = k => k === 'contas' ? 'Consumo das contas' : (P.opcoes.categorias?.custo?.[k] || P.opcoes.categorias?.receita?.[k] || k);
  const urlProj = p => '/projetos.php?p=' + encodeURIComponent(p.slug);
  const link = u => u ? (/^https?:\/\//i.test(u) ? `<a href="${esc(u)}" target="_blank" rel="noopener">${esc(u.replace(/^https?:\/\//, ''))}</a>` : esc(u)) : falta();

  function barraComp(c, n) {
    const cls = c >= 80 ? '' : (c >= 50 ? 'meio' : 'baixo');
    return `<div class="proj-comp"><div class="proj-barra-comp"><i class="${cls}" style="width:${c}%"></i></div>
            <small>${c}% · ${n ? n + (n === 1 ? ' pendência' : ' pendências') : 'completo'}</small></div>`;
  }

  function kpi(classe, rotulo, valor, desc, extra) {
    return `<div class="kpi-card ${classe}"><div class="kpi-label">${rotulo}</div><div class="kpi-value ${extra || ''}">${valor}</div><div class="kpi-desc">${desc || '&nbsp;'}</div></div>`;
  }

  /* ── Gráficos ── */
  function cores() {
    const claro = document.documentElement.getAttribute('data-theme') === 'light';
    const marca = getComputedStyle(document.documentElement).getPropertyValue('--ff-marca').trim() || '#3b82f6';
    return { texto: claro ? '#575757' : '#a3adba', grade: claro ? 'rgba(17,29,45,0.08)' : 'rgba(255,255,255,0.08)', marca,
             receita: '#10b981', custo: '#f87171', paleta: [marca, '#f59e0b', '#8b5cf6', '#06b6d4', '#ef4444', '#10b981', '#ec4899', '#64748b', '#84cc16', '#f97316'] };
  }

  function grafico(id, cfg) {
    if (CH[id]) { CH[id].destroy(); delete CH[id]; }
    const el = $(id);
    if (!el || !window.Chart) return;
    CH[id] = new Chart(el, cfg);
  }

  function opcoesBase(c, extra) {
    return Object.assign({
      responsive: true, maintainAspectRatio: false, animation: { duration: 350 },
      plugins: { legend: { labels: { color: c.texto, boxWidth: 10, font: { size: 11 } } },
                 tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label || ctx.label}: ${brl(ctx.parsed.y ?? ctx.parsed.x ?? ctx.parsed)}` } } },
    }, extra || {});
  }

  function graficoEvolucao(id, evo) {
    const c = cores();
    grafico(id, { type: 'line', data: { labels: evo.map(e => rotMes(e.mes)), datasets: [
      { label: 'Receita', data: evo.map(e => e.receita), borderColor: c.receita, backgroundColor: c.receita + '22', fill: true, tension: .35, pointRadius: 2 },
      { label: 'Custo', data: evo.map(e => e.custo), borderColor: c.custo, backgroundColor: c.custo + '18', fill: true, tension: .35, pointRadius: 2 },
    ] }, options: opcoesBase(c, { scales: {
      x: { ticks: { color: c.texto, font: { size: 10 } }, grid: { display: false } },
      y: { ticks: { color: c.texto, font: { size: 10 }, callback: v => brl0(v) }, grid: { color: c.grade }, beginAtZero: true } } }) });
  }

  function graficoCategorias(id, porCat) {
    const c = cores();
    const linhas = Object.entries(porCat || {}).filter(([, v]) => v > 0).sort((a, b) => b[1] - a[1]);
    if (!linhas.length) { const el = $(id); if (el) el.parentElement.innerHTML = '<div class="proj-vazio">Nenhum custo com valor preenchido ainda.</div>'; return; }
    grafico(id, { type: 'doughnut', data: { labels: linhas.map(l => nomeCat(l[0])), datasets: [{ data: linhas.map(l => l[1]), backgroundColor: c.paleta, borderWidth: 0 }] },
      options: opcoesBase(c, { cutout: '62%', plugins: { legend: { position: 'right', labels: { color: c.texto, boxWidth: 10, font: { size: 11 } } },
        tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${brl(ctx.parsed)}` } } } }) });
  }

  function graficoBarras(id, linhas, horizontal) {
    const c = cores();
    if (!linhas.length) { const el = $(id); if (el) el.parentElement.innerHTML = '<div class="proj-vazio">Lance receitas e custos para comparar.</div>'; return; }
    const eixoV = { ticks: { color: c.texto, font: { size: 10 }, callback: v => brl0(v) }, grid: { color: c.grade }, beginAtZero: true };
    const eixoN = { ticks: { color: c.texto, font: { size: 11 }, callback: function (v) { const t = this.getLabelForValue(v); return t.length > 22 ? t.slice(0, 21) + '…' : t; } }, grid: { display: false } };
    grafico(id, { type: 'bar', data: { labels: linhas.map(l => l.nome), datasets: [
      { label: 'Receita/mês', data: linhas.map(l => l.receita), backgroundColor: c.receita, borderRadius: 4, maxBarThickness: 18 },
      { label: 'Custo/mês', data: linhas.map(l => l.custo), backgroundColor: c.custo, borderRadius: 4, maxBarThickness: 18 },
    ] }, options: opcoesBase(c, { indexAxis: horizontal ? 'y' : 'x', scales: horizontal ? { x: eixoV, y: eixoN } : { x: eixoN, y: eixoV },
      plugins: { legend: { labels: { color: c.texto, boxWidth: 10, font: { size: 11 } } },
                 tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${brl(horizontal ? ctx.parsed.x : ctx.parsed.y)}` } } } }) });
  }

  function sparkline(id, evo) {
    const c = cores();
    grafico(id, { type: 'line', data: { labels: evo.map(e => e.mes), datasets: [
      { data: evo.map(e => e.receita), borderColor: c.receita, borderWidth: 1.6, pointRadius: 0, tension: .35, fill: false },
      { data: evo.map(e => e.custo), borderColor: c.custo, borderWidth: 1.6, pointRadius: 0, tension: .35, fill: false },
    ] }, options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { display: false }, tooltip: { enabled: false } },
      scales: { x: { display: false }, y: { display: false, beginAtZero: true } } } });
  }

  function desenhar() {
    Object.keys(CH).forEach(k => { CH[k].destroy(); delete CH[k]; });
    if (SLUG) desenharProjeto(porSlug[SLUG]); else desenharVisaoGeral();
  }

  /* ════════════════════════════ VISÃO GERAL ════════════════════════════ */
  function desenharVisaoGeral() {
    const s = P.resumo || {};
    const maes = P.projetos.filter(p => !p.parent_id);
    const ativas = maes.filter(p => p.status !== 'encerrado');
    const evo = [];
    ativas.forEach(p => p.agregado.evolucao.forEach((e, i) => {
      evo[i] = evo[i] || { mes: e.mes, receita: 0, custo: 0 }; evo[i].receita += e.receita; evo[i].custo += e.custo;
    }));
    // Servidores sem projeto ligado não aparecem em nenhum projeto: a evolução da carteira soma só o rateado.
    const porCat = {};
    ativas.forEach(p => Object.entries(p.agregado.custo_por_categoria || {}).forEach(([k, v]) => { if (k !== 'servidor') porCat[k] = (porCat[k] || 0) + v; }));
    const totSrv = (P.servidores || []).reduce((t, x) => t + (x.valor_mensal_brl || 0), 0);
    if (totSrv > 0) porCat.servidor = totSrv;

    $('projConteudo').outerHTML = `<div id="projConteudo">
      <div class="dre-panel page-header"><div class="page-header-inner proj-cab">
        <div class="page-header-text"><h2 class="page-header-title">Projetos</h2>
          <p class="page-header-subtitle">Tudo o que a empresa roda: o que cada projeto consome, quanto arrecada, as contas de cada um e o que falta preencher</p></div>
        <div class="proj-cab-botoes">
          <button type="button" class="btn btn-ghost btn-sm" data-acao="servidores">${I.srv} Servidores</button>
          <button type="button" class="btn btn-primary btn-sm" data-acao="novo-projeto">${I.mais} Novo projeto</button>
        </div></div></div>

      <div class="kpi-grid proj-kpis">
        ${kpi('lucro', 'Projetos', num(ativas.length), `${num(s.projetos_ativos)} em produção · ${num(s.projetos_total)} com subprojetos`)}
        ${kpi('receita', 'Receita por mês', brl(s.receita_mensal), s.sem_receita ? `${s.sem_receita} projeto(s) que cobram sem receita lançada` : 'recorrente')}
        ${kpi('despesa', 'Custo por mês', brl(s.custo_mensal), 'servidores, IA, APIs, licenças, contas')}
        ${kpi('margem', 'Margem por mês', brl(s.margem_mensal), s.margem_pct === null ? 'sem receita para comparar' : pct(s.margem_pct) + ' da receita', sinal(s.margem_mensal))}
        ${kpi('resultado', 'Contas e usuários', `${num(s.contas)} <small class="proj-kpi-sub">contas</small>`, `${num(s.usuarios)} usuários nos projetos`)}
        ${kpi('imposto', 'Dossiê a completar', num(s.pendencias), s.servidores_sem_valor ? `inclui ${s.servidores_sem_valor} servidor(es) sem custo` : 'informações faltando')}
      </div>

      <div class="proj-grid2">
        <div class="dre-panel"><div class="panel-title">Evolução da carteira</div><p class="panel-sub">Receita e custo por mês nos últimos 12 meses, pelo cadastro dos projetos</p>
          <div class="proj-canvas"><canvas id="chEvo"></canvas></div></div>
        <div class="dre-panel"><div class="panel-title">Para onde vai o custo</div><p class="panel-sub">Custo mensal da carteira por tipo de consumo</p>
          <div class="proj-canvas"><canvas id="chCat"></canvas></div></div>
      </div>
      <div class="dre-panel"><div class="panel-title">Quem paga a conta</div><p class="panel-sub">Receita e custo mensal de cada projeto, somando os subprojetos</p>
        <div class="proj-canvas proj-canvas-alto" id="chProjWrap"><canvas id="chProj"></canvas></div></div>

      <div class="proj-barra">
        <div class="proj-filtros" id="projFiltros">
          ${[['ativos', 'Ativos'], ['producao', 'Em produção'], ['desenvolvimento', 'Em desenvolvimento'], ['pausado', 'Pausados'], ['encerrado', 'Encerrados'], ['pendentes', 'Com pendência']]
            .map(([k, t]) => `<button type="button" class="btn btn-sm ${filtro === k ? 'btn-primary' : 'btn-ghost'}" data-filtro="${k}">${t}</button>`).join('')}
        </div>
        <div class="proj-barra-dir">
          <input type="search" id="projBusca" class="proj-busca" placeholder="Buscar projeto, cliente, conta" value="${esc(busca)}" autocomplete="off">
          <button type="button" class="btn btn-ghost btn-sm" data-acao="cotacao" title="Cotação usada para converter custos em dólar">US$ 1 = R$ ${Number(P.cotacao_usd).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 4 })}</button>
        </div>
      </div>
      <div class="proj-cards" id="projCards"></div>
    </div>`;

    graficoEvolucao('chEvo', evo);
    graficoCategorias('chCat', porCat);
    const ranking = ativas.filter(p => p.agregado.receita_mensal > 0 || p.agregado.custo_mensal > 0)
      .sort((a, b) => (b.agregado.receita_mensal + b.agregado.custo_mensal) - (a.agregado.receita_mensal + a.agregado.custo_mensal))
      .map(p => ({ nome: p.nome, receita: p.agregado.receita_mensal, custo: p.agregado.custo_mensal }));
    $('chProjWrap').style.height = Math.max(160, ranking.length * 34 + 60) + 'px';
    graficoBarras('chProj', ranking, true);
    desenharCartoes();
  }

  function passaFiltro(p) {
    const grupo = [p, ...(filhosDe[p.id] || [])];
    if (filtro === 'ativos' && p.status === 'encerrado') return false;
    if (['producao', 'desenvolvimento', 'pausado', 'encerrado'].includes(filtro) && p.status !== filtro) return false;
    if (filtro === 'pendentes' && !p.agregado.pendencias) return false;
    if (!busca) return true;
    const alvo = grupo.map(g => [g.nome, g.cliente, g.stack, g.url, ...g.contas.map(c => c.nome), ...g.itens.map(i => i.fornecedor + ' ' + i.descricao)].join(' ')).join(' ').toLowerCase();
    return alvo.includes(busca);
  }

  function desenharCartoes() {
    const lista = P.projetos.filter(p => !p.parent_id).filter(passaFiltro);
    const el = $('projCards');
    if (!lista.length) { el.innerHTML = `<div class="dre-panel proj-vazio">${P.projetos.length ? 'Nenhum projeto neste filtro.' : 'Nenhum projeto cadastrado ainda. Use "Novo projeto".'}</div>`; return; }
    el.innerHTML = lista.map(p => {
      const a = p.agregado;
      const subs = filhosDe[p.id] || [];
      const sub = [p.cliente, P.opcoes.tipos?.[p.tipo]].filter(Boolean).join(' · ');
      return `<a class="proj-card" href="${urlProj(p)}">
        <div class="proj-card-topo"><div class="proj-card-nome"><b>${esc(p.nome)}</b><small>${esc(sub)}</small></div>${selo(p.status)}</div>
        <div class="proj-card-spark"><canvas id="sp-${p.id}"></canvas></div>
        <div class="proj-card-nums">
          <div><span>Receita/mês</span><b>${a.receita_mensal > 0 ? brl(a.receita_mensal) : '<i class="proj-falta">–</i>'}</b></div>
          <div><span>Custo/mês</span><b>${brl(a.custo_mensal)}</b></div>
          <div><span>Margem</span><b class="${sinal(a.margem_mensal)}">${brl(a.margem_mensal)}</b></div>
        </div>
        <div class="proj-card-chips">
          ${subs.length ? `<span>${subs.length} subprojeto${subs.length > 1 ? 's' : ''}</span>` : ''}
          ${a.qtd_contas ? `<span>${a.qtd_contas} conta${a.qtd_contas > 1 ? 's' : ''}</span>` : ''}
          ${a.usuarios ? `<span>${num(a.usuarios)} usuários</span>` : ''}
          ${a.custo_por_usuario ? `<span>${brl(a.custo_por_usuario)}/usuário</span>` : ''}
        </div>
        ${barraComp(a.completude, a.pendencias)}
      </a>`;
    }).join('');
    lista.forEach(p => sparkline('sp-' + p.id, p.agregado.evolucao));
  }

  /* ════════════════════════════ PÁGINA DO PROJETO ════════════════════════════ */
  function desenharProjeto(p) {
    const mae = p.parent_id ? porId[p.parent_id] : null;
    const filhos = filhosDe[p.id] || [];
    const grupo = [p, ...filhos];
    const a = p.agregado;
    const contas = grupo.flatMap(g => g.contas.map(c => Object.assign({ _proj: g }, c)));
    const acessos = grupo.flatMap(g => g.acessos.map(x => Object.assign({ _proj: g }, x)));
    const abas = [['visao', 'Visão geral'], ...(mae ? [] : [['subprojetos', `Subprojetos <em>${filhos.length}</em>`]]),
                  ['contas', `Contas <em>${contas.length}</em>`], ['custos', 'Custos e receitas'], ['acessos', `Acessos <em>${acessos.length}</em>`],
                  ['ficha', `Ficha e pendências <em>${a.pendencias}</em>`]];
    if (!abas.some(x => x[0] === aba)) aba = 'visao';
    const sub = [p.cliente ? 'Cliente: ' + esc(p.cliente) : '', esc(P.opcoes.tipos?.[p.tipo] || p.tipo), p.url ? link(p.url) : ''].filter(Boolean).join(' &nbsp;·&nbsp; ');

    $('projConteudo').outerHTML = `<div id="projConteudo">
      <nav class="proj-trilha"><a href="/projetos.php">Projetos</a>${mae ? ` › <a href="${urlProj(mae)}">${esc(mae.nome)}</a>` : ''} › <b>${esc(p.nome)}</b></nav>
      <div class="dre-panel page-header"><div class="page-header-inner proj-cab">
        <div class="page-header-text"><h2 class="page-header-title">${esc(p.nome)} ${selo(p.status)}</h2>
          <p class="page-header-subtitle">${sub}</p>
          ${p.descricao ? `<p class="proj-desc">${esc(p.descricao)}</p>` : ''}</div>
        <div class="proj-cab-botoes">
          <button type="button" class="btn btn-ghost btn-sm" data-acao="editar-projeto" data-id="${p.id}">${I.lapis} Editar</button>
          ${mae ? '' : `<button type="button" class="btn btn-ghost btn-sm" data-acao="novo-subprojeto" data-id="${p.id}">${I.mais} Subprojeto</button>`}
          <button type="button" class="btn btn-ghost btn-sm proj-perigo" data-acao="excluir-projeto" data-id="${p.id}">${I.lixo} Excluir</button>
        </div></div></div>

      <div class="kpi-grid proj-kpis">
        ${kpi('receita', 'Receita por mês', a.receita_mensal > 0 ? brl(a.receita_mensal) : falta(), filhos.length ? `somando ${filhos.length} subprojeto(s)` : (p.calc.receita_unica > 0 ? 'mais ' + brl(p.calc.receita_unica) + ' únicos' : 'recorrente'))}
        ${kpi('despesa', 'Custo por mês', brl(a.custo_mensal), p.calc.custo_servidor === null ? 'servidor a preencher' : 'servidor, consumo, licenças')}
        ${kpi('margem', 'Margem por mês', brl(a.margem_mensal), a.margem_pct === null ? 'sem receita para comparar' : pct(a.margem_pct) + ' da receita', sinal(a.margem_mensal))}
        ${kpi('lucro', 'Contas e usuários', `${num(a.qtd_contas)} <small class="proj-kpi-sub">contas</small>`, a.usuarios ? `${num(a.usuarios)} usuários` : 'usuários a preencher')}
        ${kpi('resultado', 'Custo por usuário', a.custo_por_usuario === null ? falta() : brl(a.custo_por_usuario), 'custo do mês ÷ usuários')}
        ${kpi('imposto', 'Arrecadado até hoje', brl(a.arrecadado), `dossiê ${a.completude}% completo`)}
      </div>

      <div class="proj-abas" role="tablist">${abas.map(([k, t]) => `<button type="button" role="tab" class="proj-aba${k === aba ? ' ativa' : ''}" data-aba="${k}">${t}</button>`).join('')}</div>
      <div id="projAba"></div>
    </div>`;
    desenharAba(p, grupo, contas, acessos);
  }

  function desenharAba(p, grupo, contas, acessos) {
    const el = $('projAba');
    Object.keys(CH).forEach(k => { CH[k].destroy(); delete CH[k]; });
    const filhos = filhosDe[p.id] || [];
    const a = p.agregado;

    if (aba === 'visao') {
      const temContas = contas.length > 0;
      el.innerHTML = `
        <div class="proj-grid2">
          <div class="dre-panel"><div class="panel-title">Evolução</div><p class="panel-sub">Receita e custo por mês nos últimos 12 meses, pelo cadastro${filhos.length ? ' (com os subprojetos)' : ''}</p>
            <div class="proj-canvas"><canvas id="chEvo"></canvas></div></div>
          <div class="dre-panel"><div class="panel-title">Para onde vai o custo</div><p class="panel-sub">Custo mensal por tipo de consumo</p>
            <div class="proj-canvas"><canvas id="chCat"></canvas></div></div>
        </div>
        ${filhos.length ? `<div class="dre-panel"><div class="panel-title">Subprojetos</div><p class="panel-sub">Receita e custo mensal de cada parte</p>
            <div class="proj-canvas proj-canvas-alto" style="height:${Math.max(160, grupo.length * 34 + 60)}px"><canvas id="chSub"></canvas></div></div>` : ''}
        ${temContas ? `<div class="dre-panel"><div class="panel-title">Consumo por conta</div><p class="panel-sub">Quanto cada conta paga e quanto custa por mês (rateio do custo do projeto + o que só ela consome)</p>
            <div class="proj-canvas proj-canvas-alto" style="height:${Math.max(160, Math.min(contas.length, 20) * 30 + 60)}px"><canvas id="chContas"></canvas></div></div>` : ''}
        <div class="proj-grid2">
          <div class="dre-panel"><div class="panel-title">Acessos rápidos</div><p class="panel-sub">Links do projeto; logins e senhas na aba Acessos</p>${listaAcessosCurta(acessos)}</div>
          <div class="dre-panel"><div class="panel-title">Onde roda</div><p class="panel-sub">Servidores do projeto e a parte do custo de cada um</p>${listaServidoresDoGrupo(grupo)}</div>
        </div>`;
      graficoEvolucao('chEvo', a.evolucao);
      graficoCategorias('chCat', a.custo_por_categoria);
      if (filhos.length) graficoBarras('chSub', grupo.filter(g => g.status !== 'encerrado' || p.status === 'encerrado').map(g => ({ nome: g.id === p.id ? g.nome + ' (geral)' : g.nome, receita: g.calc.receita_mensal, custo: g.calc.custo_mensal })), true);
      if (temContas) graficoBarras('chContas', contas.filter(c => c.calc).sort((x, y) => (y.calc.custo + (y.calc.receita || 0)) - (x.calc.custo + (x.calc.receita || 0))).slice(0, 20)
        .map(c => ({ nome: c.nome, receita: c.calc.receita || 0, custo: c.calc.custo })), true);
      return;
    }

    if (aba === 'subprojetos') {
      el.innerHTML = `<div class="dre-panel">
        <div class="proj-secao-cab"><div><div class="panel-title">Subprojetos</div><p class="panel-sub">Cada parte de ${esc(p.nome)}, com os números dela</p></div>
          <button type="button" class="btn btn-primary btn-sm" data-acao="novo-subprojeto" data-id="${p.id}">${I.mais} Novo subprojeto</button></div>
        <div class="dre-table-wrap"><table class="dre-table proj-tabela"><thead><tr><th>Subprojeto</th><th>Situação</th><th>Onde roda</th><th style="text-align:right">Contas</th>
          <th style="text-align:right">Receita/mês</th><th style="text-align:right">Custo/mês</th><th style="text-align:right">Margem</th><th>Dossiê</th><th style="text-align:right">Ações</th></tr></thead>
        <tbody>${filhos.length ? filhos.map(f => `<tr data-ir="${urlProj(f)}">
          <td class="proj-nome"><b>${esc(f.nome)}</b><small>${esc([f.cliente, P.opcoes.tipos?.[f.tipo]].filter(Boolean).join(' · '))}</small></td>
          <td>${selo(f.status)}</td><td>${f.servidor_nome ? esc(f.servidor_nome) : falta('não informado')}</td>
          <td class="num">${num(f.calc.qtd_contas)}</td>
          <td class="num">${f.calc.receita_mensal > 0 ? brl(f.calc.receita_mensal) : '–'}</td><td class="num">${brl(f.calc.custo_mensal)}</td>
          <td class="num ${sinal(f.calc.margem_mensal)}">${brl(f.calc.margem_mensal)}</td>
          <td>${barraComp(f.calc.completude, f.calc.pendencias.length)}</td>
          <td><span class="proj-acoes"><button type="button" class="btn btn-ghost btn-sm" data-acao="editar-projeto" data-id="${f.id}" title="Editar">${I.lapis}</button><button type="button" class="btn btn-ghost btn-sm proj-perigo" data-acao="excluir-projeto" data-id="${f.id}" title="Excluir">${I.lixo}</button></span></td>
        </tr>`).join('') : '<tr class="empty-row"><td colspan="9">Nenhum subprojeto. Use "Novo subprojeto" para separar as partes deste projeto (site, app, painel...).</td></tr>'}</tbody></table></div></div>`;
      return;
    }

    if (aba === 'contas') {
      const mostraProj = filhos.length > 0;
      const compart = grupo.reduce((t, g) => t + (g.calc.qtd_contas ? g.calc.custo_compartilhado : 0), 0);
      const sync = contas.find(c => c.sincronizado_em);
      el.innerHTML = `<div class="dre-panel">
        <div class="proj-secao-cab"><div><div class="panel-title">Contas do projeto</div>
          <p class="panel-sub">Quem usa o projeto, quanto paga e quanto consome. O custo do projeto${compart ? ` (${brl(compart)}/mês)` : ''} é dividido entre as contas pelo peso de consumo, mais o que só cada uma gasta.${sync ? ` Medição automática em ${data(sync.sincronizado_em.slice(0, 10))}.` : ''}</p></div>
          <button type="button" class="btn btn-primary btn-sm" data-acao="nova-conta" data-id="${p.id}">${I.mais} Nova conta</button></div>
        <div class="dre-table-wrap"><table class="dre-table proj-itens"><thead><tr><th>Conta</th>${mostraProj ? '<th>Subprojeto</th>' : ''}<th>Situação</th>
          <th style="text-align:right">Usuários</th><th style="text-align:right">Paga/mês</th><th style="text-align:right">Custa/mês</th><th style="text-align:right">Por usuário</th>
          <th style="text-align:right">Margem</th><th>Consumo medido</th><th style="text-align:right">Ações</th></tr></thead>
        <tbody>${contas.length ? contas.map(c => {
          const k = c.calc || {}; const m = c.metricas || {};
          const med = [m.mensagens_30d !== undefined ? num(m.mensagens_30d) + ' msgs/30d' : '', m.ia_chamadas_30d ? num(m.ia_chamadas_30d) + ' IA/30d' : '',
                       m.clientes !== undefined ? num(m.clientes) + ' clientes' : '', m.processos ? num(m.processos) + ' processos' : '',
                       m.preco_tabela ? 'tabela ' + brl(m.preco_tabela) : ''].filter(Boolean).join(' · ');
          return `<tr class="${c.status === 'cancelada' ? 'inativo' : ''}">
            <td><b>${esc(c.nome)}</b><small>${esc([c.identificador, c.plano].filter(Boolean).join(' · '))}</small></td>
            ${mostraProj ? `<td>${esc(c._proj.nome)}</td>` : ''}
            <td><span class="proj-selo sc-${esc(c.status)}">${esc(P.opcoes.status_conta?.[c.status] || c.status)}</span></td>
            <td class="num">${c.usuarios === null ? '–' : num(c.usuarios)}</td>
            <td class="num">${['ativa', 'inadimplente'].includes(c.status) ? (c.receita_mensal === null ? falta() : moeda(c.receita_mensal, c.moeda)) : '–'}</td>
            <td class="num">${k.custo !== undefined ? brl(k.custo) : '–'}${k.custo_direto ? `<small>${brl(k.custo_direto)} só dela</small>` : ''}</td>
            <td class="num">${k.custo_por_usuario === null || k.custo_por_usuario === undefined ? '–' : brl(k.custo_por_usuario)}</td>
            <td class="num ${sinal(k.margem)}">${k.margem === null || k.margem === undefined ? '–' : brl(k.margem)}</td>
            <td><small class="proj-medido">${med || '–'}</small></td>
            <td><span class="proj-acoes"><button type="button" class="btn btn-ghost btn-sm" data-acao="editar-conta" data-id="${c.id}" title="Editar">${I.lapis}</button><button type="button" class="btn btn-ghost btn-sm proj-perigo" data-acao="excluir-conta" data-id="${c.id}" title="Excluir">${I.lixo}</button></span></td>
          </tr>`; }).join('') : `<tr class="empty-row"><td colspan="${mostraProj ? 10 : 9}">Nenhuma conta. Cadastre os clientes que usam este projeto para ver quanto cada um paga e consome.</td></tr>`}</tbody></table></div></div>`;
      return;
    }

    if (aba === 'custos') {
      el.innerHTML = grupo.map(g => blocoItens(g, grupo.length > 1)).join('');
      return;
    }

    if (aba === 'acessos') {
      const cripto = P.opcoes.cripto;
      el.innerHTML = `<div class="dre-panel">
        <div class="proj-secao-cab"><div><div class="panel-title">Acessos</div><p class="panel-sub">Links, logins e painel master. As senhas ficam cifradas e só aparecem ao clicar em Mostrar.${cripto ? '' : ' <b class="proj-falta">A chave de criptografia do sistema não está configurada: dá para guardar link e usuário, mas não senha.</b>'}</p></div>
          <button type="button" class="btn btn-primary btn-sm" data-acao="novo-acesso" data-id="${p.id}">${I.mais} Novo acesso</button></div>
        <div class="proj-acessos">${acessos.length ? acessos.map(x => `<div class="proj-acesso">
          <div class="proj-acesso-topo"><div><b>${I.chave} ${esc(x.rotulo)}</b>${grupo.length > 1 ? `<small>${esc(x._proj.nome)}</small>` : ''}</div>
            <span class="proj-acoes"><button type="button" class="btn btn-ghost btn-sm" data-acao="editar-acesso" data-id="${x.id}" title="Editar">${I.lapis}</button><button type="button" class="btn btn-ghost btn-sm proj-perigo" data-acao="excluir-acesso" data-id="${x.id}" title="Excluir">${I.lixo}</button></span></div>
          ${x.url ? `<div class="proj-acesso-linha"><span>Endereço</span><a href="${esc(x.url)}" target="_blank" rel="noopener">${esc(x.url)} ${I.link}</a></div>` : ''}
          <div class="proj-acesso-linha"><span>Usuário</span>${x.usuario ? `<code>${esc(x.usuario)}</code><button type="button" class="proj-mini" data-copiar="${esc(x.usuario)}" title="Copiar">${I.copiar}</button>` : falta()}</div>
          <div class="proj-acesso-linha"><span>Senha</span>${x.tem_senha ? `<code id="senha-${x.id}">••••••••</code><button type="button" class="proj-mini" data-acao="revelar" data-id="${x.id}" title="Mostrar">${I.olho}</button>` : falta('não guardada')}</div>
          ${x.observacao ? `<p class="proj-acesso-obs">${esc(x.observacao)}</p>` : ''}
        </div>`).join('') : '<div class="proj-vazio">Nenhum acesso cadastrado. Guarde aqui o painel master, o login do cliente, o servidor...</div>'}</div></div>`;
      return;
    }

    if (aba === 'ficha') {
      el.innerHTML = grupo.map(g => {
        const c = g.calc;
        const campo = (rot, html, largo) => `<div${largo ? ' class="largo"' : ''}><dt>${rot}</dt><dd>${html}</dd></div>`;
        return `<div class="dre-panel">
          <div class="proj-secao-cab"><div><div class="panel-title">${esc(g.nome)} ${grupo.length > 1 ? selo(g.status) : ''}</div><p class="panel-sub">Dossiê ${c.completude}% completo</p></div>
            <button type="button" class="btn btn-ghost btn-sm" data-acao="editar-projeto" data-id="${g.id}">${I.lapis} Editar dados</button></div>
          <div class="proj-pend${c.pendencias.length ? '' : ' ok'}">${c.pendencias.length
            ? `<b>Falta preencher:</b><ul>${c.pendencias.map(t => `<li>${esc(t)}</li>`).join('')}</ul>` : '<b>Dossiê completo.</b>'}</div>
          <dl class="proj-ficha">
            ${campo('Cliente', g.cliente ? esc(g.cliente) : '–')}${campo('Endereço de produção', link(g.url))}${campo('Onde roda', g.servidor_nome ? `${esc(g.servidor_nome)} (peso ${g.servidor_peso})` : falta('não informado'))}
            ${campo('Stack', g.stack ? esc(g.stack) : falta())}${campo('Repositório', link(g.repositorio))}${campo('Responsável', g.responsavel ? esc(g.responsavel) : falta())}
            ${campo('Usuários', c.usuarios === null ? falta() : num(c.usuarios))}${campo('Início', g.inicio ? data(g.inicio) : '–')}${campo('Atualizado em', g.atualizado_em ? data(g.atualizado_em.slice(0, 10)) : '–')}
            ${campo('Descrição', g.descricao ? esc(g.descricao) : falta(), true)}${g.observacao ? campo('Observações', esc(g.observacao), true) : ''}
          </dl></div>`;
      }).join('');
    }
  }

  function listaAcessosCurta(acessos) {
    const comLink = acessos.filter(x => x.url).slice(0, 8);
    if (!comLink.length) return '<div class="proj-vazio">Nenhum link cadastrado ainda.</div>';
    return `<ul class="proj-links">${comLink.map(x => `<li><a href="${esc(x.url)}" target="_blank" rel="noopener">${I.link} ${esc(x.rotulo)}</a><small>${esc(x.url.replace(/^https?:\/\//, ''))}</small></li>`).join('')}</ul>`;
  }

  function listaServidoresDoGrupo(grupo) {
    const linhas = grupo.filter(g => g.servidor_id).map(g => `<li><b>${esc(g.servidor_nome)}</b><small>${esc(g.nome)} · ${g.calc.custo_servidor === null ? 'custo a preencher' : brl(g.calc.custo_servidor) + '/mês'}</small></li>`);
    return linhas.length ? `<ul class="proj-links">${linhas.join('')}</ul><button type="button" class="btn btn-ghost btn-sm" data-acao="servidores">${I.srv} Ver servidores</button>`
      : '<div class="proj-vazio">Nenhum servidor ligado. Edite o projeto para dizer onde ele roda.</div>';
  }

  function blocoItens(g, varios) {
    const c = g.calc;
    const linhaItem = it => {
      const valor = it.valor_unitario === null ? falta() :
        moeda(it.valor_unitario, it.moeda) + (Number(it.quantidade) !== 1 ? ` × ${String(it.quantidade).replace('.', ',')}` : '') +
        (it.moeda === 'USD' && it.total_brl !== null ? `<small>${brl(it.total_brl)}</small>` : '');
      const rec = esc(P.opcoes.recorrencias?.[it.recorrencia] || it.recorrencia) +
        (it.inicio || it.fim ? `<small>${it.inicio ? 'desde ' + data(it.inicio) : ''}${it.fim ? ' até ' + data(it.fim) : ''}</small>` : '');
      return `<tr class="${it.vigente ? '' : 'inativo'}">
        <td>${esc(it.descricao)}<small>${esc(nomeCat(it.categoria))}${it.observacao ? ' · ' + esc(it.observacao) : ''}</small></td>
        <td>${it.fornecedor ? esc(it.fornecedor) : '–'}</td><td class="num">${valor}</td><td>${rec}</td>
        <td class="num">${it.mensal_brl === null ? falta() : (it.recorrencia === 'unico' ? '–' : brl(it.mensal_brl))}</td>
        <td><span class="proj-acoes"><button type="button" class="btn btn-ghost btn-sm" data-acao="editar-item" data-id="${it.id}" title="Editar">${I.lapis}</button><button type="button" class="btn btn-ghost btn-sm proj-perigo" data-acao="excluir-item" data-id="${it.id}" title="Excluir">${I.lixo}</button></span></td>
      </tr>`;
    };
    const custos = g.itens.filter(i => i.natureza === 'custo');
    const receitas = g.itens.filter(i => i.natureza === 'receita');
    const linhaSrv = g.servidor_id ? `<tr><td>Servidor: ${esc(g.servidor_nome)}<small>rateio pelo peso ${g.servidor_peso} entre os projetos do servidor</small></td><td>–</td>
        <td class="num">${c.custo_servidor === null ? falta() : brl(c.custo_servidor)}</td><td>Mensal</td>
        <td class="num">${c.custo_servidor === null ? falta() : brl(c.custo_servidor)}</td>
        <td><button type="button" class="btn btn-ghost btn-sm" data-acao="servidores">${I.srv}</button></td></tr>` : '';
    const linhaContas = c.qtd_contas && (c.custo_por_categoria.contas || 0) > 0 ? `<tr><td>Consumo das contas<small>o que cada conta gasta só para ela (aba Contas)</small></td><td>–</td><td class="num">–</td><td>Mensal</td><td class="num">${brl(c.custo_por_categoria.contas)}</td><td></td></tr>` : '';
    const receitaContas = c.qtd_contas ? g.contas.filter(x => ['ativa', 'inadimplente'].includes(x.status) && x.receita_mensal !== null).reduce((t, x) => t + (x.calc?.receita || 0), 0) : 0;
    const linhaRecContas = c.qtd_contas ? `<tr><td>Contas que pagam<small>soma do que cada conta paga (aba Contas)</small></td><td>–</td><td class="num">–</td><td>Mensal</td><td class="num">${receitaContas ? brl(receitaContas) : falta()}</td><td></td></tr>` : '';
    const cab = `<thead><tr><th>Item</th><th>%F%</th><th style="text-align:right">Valor</th><th>Recorrência</th><th style="text-align:right">Por mês</th><th style="text-align:right">Ações</th></tr></thead>`;
    return `<div class="dre-panel">
      ${varios ? `<div class="proj-secao-cab"><div><div class="panel-title">${esc(g.nome)}</div><p class="panel-sub">Receita ${brl(c.receita_mensal)} · custo ${brl(c.custo_mensal)} por mês</p></div>
        ${g.id !== Number(porSlug[SLUG].id) ? `<a class="btn btn-ghost btn-sm" href="${urlProj(g)}">Abrir</a>` : ''}</div>` : ''}
      <div class="proj-secao-cab"><h3>O que consome</h3><button type="button" class="btn btn-ghost btn-sm" data-acao="novo-item" data-natureza="custo" data-id="${g.id}">${I.mais} Custo</button></div>
      <div class="dre-table-wrap"><table class="dre-table proj-itens">${cab.replace('%F%', 'Fornecedor')}<tbody>${linhaSrv + linhaContas + custos.map(linhaItem).join('') ||
        '<tr class="empty-row"><td colspan="6">Nenhum custo lançado. Servidor, IA, WhatsApp, domínio, licenças...</td></tr>'}</tbody></table></div>
      <div class="proj-secao-cab"><h3>O que arrecada</h3><button type="button" class="btn btn-ghost btn-sm" data-acao="novo-item" data-natureza="receita" data-id="${g.id}">${I.mais} Receita</button></div>
      <div class="dre-table-wrap"><table class="dre-table proj-itens">${cab.replace('%F%', 'Quem paga')}<tbody>${linhaRecContas + receitas.map(linhaItem).join('') ||
        '<tr class="empty-row"><td colspan="6">Nenhuma receita lançada.</td></tr>'}</tbody></table></div>
    </div>`;
  }

  /* ════════════════════════════ MODAIS ════════════════════════════ */
  function abrir(id) { $(id).style.display = 'flex'; }
  function fechar(id) { $(id).style.display = 'none'; }
  function msg(id, texto) { const el = $(id); el.textContent = texto || ''; el.style.display = texto ? 'block' : 'none'; }
  const opcoes = (obj, sel) => Object.entries(obj || {}).map(([k, v]) => `<option value="${esc(k)}"${k === sel ? ' selected' : ''}>${esc(v)}</option>`).join('');
  async function confirmar(texto) {
    if (window.Yuris && Yuris.confirm) return Yuris.confirm(texto, { okLabel: 'Excluir', danger: true });
    return false;
  }
  function lerNumero(txt) {
    let s = String(txt || '').trim().replace(/^(R\$|US\$|\$)\s*/, '');
    if (!s) return null;
    if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
    return /^\d+(\.\d{1,2})?$/.test(s) ? parseFloat(s) : NaN;
  }
  const valorCampo = v => v === null || v === undefined ? '' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2 });

  /* ── Projeto ── */
  function abrirProjeto(p, paiPadrao) {
    $('pmTitulo').textContent = p ? 'Editar projeto' : (paiPadrao ? 'Novo subprojeto' : 'Novo projeto');
    $('pmId').value = p ? p.id : '';
    $('pmNome').value = p?.nome || '';
    $('pmTipo').innerHTML = opcoes(P.opcoes.tipos, p?.tipo || 'cliente');
    $('pmStatus').innerHTML = opcoes(P.opcoes.status, p?.status || 'producao');
    $('pmServidor').innerHTML = '<option value="">Não informado</option>' + P.servidores.map(s => `<option value="${s.id}"${p && p.servidor_id === s.id ? ' selected' : ''}>${esc(s.nome)}</option>`).join('');
    const temFilhos = p && (filhosDe[p.id] || []).length > 0;
    const pai = p ? p.parent_id : (paiPadrao || null);
    $('pmPai').innerHTML = '<option value="">Nenhum (projeto principal)</option>' + P.projetos.filter(x => !x.parent_id && (!p || x.id !== p.id))
      .map(x => `<option value="${x.id}"${pai === x.id ? ' selected' : ''}>${esc(x.nome)}</option>`).join('');
    $('pmPai').disabled = !!temFilhos;
    $('pmPai').title = temFilhos ? 'Este projeto tem subprojetos, então continua como projeto principal.' : '';
    $('pmPeso').value = p?.servidor_peso || 1;
    const campos = { pmCliente: 'cliente', pmResponsavel: 'responsavel', pmUrl: 'url', pmRepo: 'repositorio', pmStack: 'stack', pmInicio: 'inicio', pmDescricao: 'descricao', pmObs: 'observacao' };
    Object.entries(campos).forEach(([el, c]) => { $(el).value = p?.[c] || ''; });
    if (!p && paiPadrao) $('pmCliente').value = porId[paiPadrao]?.cliente || '';
    $('pmUsuarios').value = p && p.usuarios_ativos !== null ? p.usuarios_ativos : '';
    msg('pmMsg', '');
    abrir('projModal');
    setTimeout(() => $('pmNome').focus(), 30);
  }

  async function salvarProjeto() {
    const corpo = {
      acao: 'projeto_salvar', id: Number($('pmId').value) || 0, nome: $('pmNome').value, tipo: $('pmTipo').value,
      status: $('pmStatus').value, cliente: $('pmCliente').value, responsavel: $('pmResponsavel').value, url: $('pmUrl').value,
      repositorio: $('pmRepo').value, servidor_id: Number($('pmServidor').value) || 0, servidor_peso: Number($('pmPeso').value) || 1,
      stack: $('pmStack').value, usuarios_ativos: $('pmUsuarios').value, inicio: $('pmInicio').value,
      descricao: $('pmDescricao').value, observacao: $('pmObs').value, parent_id: Number($('pmPai').value) || 0,
    };
    $('pmSalvar').disabled = true;
    try {
      const j = await chamar(corpo);
      fechar('projModal');
      toast(corpo.id ? 'Projeto atualizado.' : 'Projeto criado.', 'success');
      // Projeto novo: abre a página dele (o menu ganha o item ao recarregar).
      if (!corpo.id && j.id && porId[j.id]) location.href = urlProj(porId[j.id]);
    } catch (e) { msg('pmMsg', e.message); }
    finally { $('pmSalvar').disabled = false; }
  }

  async function excluirProjeto(id) {
    const p = porId[id]; if (!p) return;
    const nf = (filhosDe[id] || []).length;
    const aviso = nf ? ` Os ${nf} subprojeto(s) dele não são apagados: viram projetos principais.` : '';
    if (!(await confirmar(`Excluir o projeto "${p.nome}" com os custos, receitas, contas e acessos dele?${aviso}`))) return;
    try {
      const destino = p.slug === SLUG ? (p.parent_id && porId[p.parent_id] ? urlProj(porId[p.parent_id]) : '/projetos.php') : null;
      await chamar({ acao: 'projeto_excluir', id });
      toast('Projeto excluído.', 'success');
      if (destino || !SLUG) location.href = destino || location.href;
    } catch (e) { toast(e.message, 'danger'); }
  }

  /* ── Item (receita / custo) ── */
  function abrirItem(projetoId, natureza, it) {
    const p = porId[projetoId]; if (!p) return;
    const nat = it ? it.natureza : natureza;
    $('imTitulo').textContent = it ? (nat === 'custo' ? 'Editar custo' : 'Editar receita') : (nat === 'custo' ? 'Novo custo' : 'Nova receita');
    $('imSub').textContent = p.nome;
    $('imFornecedorRot').textContent = nat === 'custo' ? 'Fornecedor' : 'Quem paga';
    $('imId').value = it ? it.id : ''; $('imNatureza').value = nat; $('imProjeto').value = projetoId;
    $('imCategoria').innerHTML = opcoes(P.opcoes.categorias?.[nat], it?.categoria || (nat === 'custo' ? 'servidor' : 'mensalidade'));
    $('imRecorrencia').innerHTML = opcoes(P.opcoes.recorrencias, it?.recorrencia || 'mensal');
    $('imDescricao').value = it?.descricao || '';
    $('imFornecedor').value = it?.fornecedor || (nat === 'receita' ? (p.cliente || '') : '');
    $('imValor').value = it ? valorCampo(it.valor_unitario) : '';
    $('imMoeda').value = it?.moeda || 'BRL';
    $('imQtd').value = it ? String(it.quantidade).replace('.', ',') : '1';
    $('imInicio').value = it?.inicio || ''; $('imFim').value = it?.fim || ''; $('imObs').value = it?.observacao || '';
    msg('imMsg', ''); previa(); abrir('itemModal');
    setTimeout(() => $('imDescricao').focus(), 30);
  }

  function previa() {
    const v = lerNumero($('imValor').value), q = lerNumero($('imQtd').value) ?? 1;
    const el = $('imPrevia');
    if (v === null) { el.textContent = 'Sem valor: o item fica como "a preencher" e aparece nas pendências do dossiê.'; return; }
    if (Number.isNaN(v) || Number.isNaN(q)) { el.textContent = 'Valor ou quantidade inválido. Use o formato 1.234,56.'; return; }
    const usd = $('imMoeda').value === 'USD';
    const total = v * q, emReal = usd ? total * P.cotacao_usd : total;
    const rec = $('imRecorrencia').value;
    let t = (usd ? moeda(total, 'USD') + ' ≈ ' : '') + brl(emReal);
    t += rec === 'anual' ? ` por ano, ${brl(emReal / 12)} por mês` : (rec === 'mensal' ? ' por mês' : ' uma vez');
    el.textContent = t;
  }

  async function salvarItem() {
    const corpo = {
      acao: 'item_salvar', id: Number($('imId').value) || 0, projeto_id: Number($('imProjeto').value), natureza: $('imNatureza').value,
      categoria: $('imCategoria').value, descricao: $('imDescricao').value, fornecedor: $('imFornecedor').value,
      valor_unitario: $('imValor').value, moeda: $('imMoeda').value, quantidade: $('imQtd').value,
      recorrencia: $('imRecorrencia').value, inicio: $('imInicio').value, fim: $('imFim').value, observacao: $('imObs').value,
    };
    $('imSalvar').disabled = true;
    try { await chamar(corpo); fechar('itemModal'); toast('Item salvo.', 'success'); }
    catch (e) { msg('imMsg', e.message); }
    finally { $('imSalvar').disabled = false; }
  }

  /* ── Conta ── */
  function acharConta(id) { for (const p of P.projetos) { const c = p.contas.find(x => x.id === id); if (c) return [p, c]; } return [null, null]; }
  function abrirConta(projetoId, c) {
    const p = porId[projetoId]; if (!p) return;
    $('cmTitulo').textContent = c ? 'Editar conta' : 'Nova conta';
    $('cmSub').textContent = p.nome;
    $('cmId').value = c ? c.id : ''; $('cmProjeto').value = projetoId;
    $('cmNome').value = c?.nome || ''; $('cmIdent').value = c?.identificador || ''; $('cmPlano').value = c?.plano || '';
    $('cmStatus').innerHTML = opcoes(P.opcoes.status_conta, c?.status || 'ativa');
    $('cmReceita').value = c ? valorCampo(c.receita_mensal) : ''; $('cmMoeda').value = c?.moeda || 'BRL';
    $('cmUsuarios').value = c && c.usuarios !== null ? c.usuarios : '';
    $('cmPeso').value = c ? String(c.peso_consumo).replace('.', ',') : '1';
    $('cmDireto').value = c ? valorCampo(c.custo_direto) : '';
    $('cmInicio').value = c?.inicio || ''; $('cmObs').value = c?.observacao || '';
    const sy = $('cmSync');
    if (c && c.origem === 'sync') { sy.style.display = 'block'; sy.textContent = 'Esta conta é medida automaticamente (usuários, custo direto e situação são atualizados na próxima medição). Quanto paga, peso e observação ficam como você deixar.'; }
    else sy.style.display = 'none';
    msg('cmMsg', ''); abrir('contaModal');
    setTimeout(() => $('cmNome').focus(), 30);
  }

  async function salvarConta() {
    const corpo = { acao: 'conta_salvar', id: Number($('cmId').value) || 0, projeto_id: Number($('cmProjeto').value), nome: $('cmNome').value,
      identificador: $('cmIdent').value, plano: $('cmPlano').value, status: $('cmStatus').value, receita_mensal: $('cmReceita').value,
      moeda: $('cmMoeda').value, usuarios: $('cmUsuarios').value, peso_consumo: $('cmPeso').value, custo_direto: $('cmDireto').value,
      inicio: $('cmInicio').value, observacao: $('cmObs').value };
    $('cmSalvar').disabled = true;
    try { await chamar(corpo); fechar('contaModal'); toast('Conta salva.', 'success'); }
    catch (e) { msg('cmMsg', e.message); }
    finally { $('cmSalvar').disabled = false; }
  }

  /* ── Acesso ── */
  function acharAcesso(id) { for (const p of P.projetos) { const a = p.acessos.find(x => x.id === id); if (a) return [p, a]; } return [null, null]; }
  function abrirAcesso(projetoId, a) {
    const p = porId[projetoId]; if (!p) return;
    $('amTitulo').textContent = a ? 'Editar acesso' : 'Novo acesso';
    $('amSub').textContent = p.nome;
    $('amId').value = a ? a.id : ''; $('amProjeto').value = projetoId;
    $('amRotulo').value = a?.rotulo || ''; $('amUrl').value = a?.url || ''; $('amUsuario').value = a?.usuario || '';
    $('amSenha').value = ''; $('amSenha').placeholder = a?.tem_senha ? 'em branco = mantém a guardada' : 'guardada cifrada';
    $('amLimpar').checked = false; $('amLimpar').parentElement.style.display = a?.tem_senha ? '' : 'none';
    $('amObs').value = a?.observacao || '';
    msg('amMsg', ''); abrir('acessoModal');
    setTimeout(() => $('amRotulo').focus(), 30);
  }

  async function salvarAcesso() {
    const corpo = { acao: 'acesso_salvar', id: Number($('amId').value) || 0, projeto_id: Number($('amProjeto').value), rotulo: $('amRotulo').value,
      url: $('amUrl').value, usuario: $('amUsuario').value, senha: $('amSenha').value, limpar_senha: $('amLimpar').checked, observacao: $('amObs').value };
    $('amSalvar').disabled = true;
    try { await chamar(corpo); $('amSenha').value = ''; fechar('acessoModal'); toast('Acesso salvo.', 'success'); }
    catch (e) { msg('amMsg', e.message); }
    finally { $('amSalvar').disabled = false; }
  }

  async function revelar(id, botao) {
    const alvo = $('senha-' + id); if (!alvo) return;
    if (alvo.dataset.aberta) { alvo.textContent = '••••••••'; delete alvo.dataset.aberta; return; }
    try {
      const j = await chamar({ acao: 'acesso_revelar', id });
      alvo.textContent = j.senha; alvo.dataset.aberta = '1';
      if (botao) {
        const copia = document.createElement('button');
        copia.type = 'button'; copia.className = 'proj-mini'; copia.title = 'Copiar'; copia.innerHTML = I.copiar;
        copia.addEventListener('click', () => copiar(j.senha));
        botao.after(copia);
        setTimeout(() => { alvo.textContent = '••••••••'; delete alvo.dataset.aberta; copia.remove(); }, 30000);
      }
    } catch (e) { toast(e.message, 'danger'); }
  }

  function copiar(texto) {
    if (navigator.clipboard) navigator.clipboard.writeText(texto).then(() => toast('Copiado.', 'success'), () => toast('Não foi possível copiar.', 'warn'));
  }

  /* ── Servidores ── */
  function desenharServidores() {
    const tb = $('srvLinhas');
    if (!P.servidores.length) { tb.innerHTML = '<tr class="empty-row"><td colspan="4">Nenhum servidor cadastrado.</td></tr>'; return; }
    tb.innerHTML = P.servidores.map(s => `<tr>
      <td><b>${esc(s.nome)}</b><div class="proj-srv-projs">${esc([s.provedor, s.endereco].filter(Boolean).join(' · '))}</div></td>
      <td><div class="proj-srv-projs">${s.projetos.length ? s.projetos.map(x => esc(x.nome) + (x.rateio !== null ? ' (' + brl(x.rateio) + ')' : '')).join(', ') : 'nenhum projeto ligado'}</div></td>
      <td class="num" style="text-align:right">${s.valor_mensal === null ? falta() : moeda(s.valor_mensal, s.moeda)}</td>
      <td style="text-align:right;white-space:nowrap"><span class="proj-acoes"><button type="button" class="btn btn-ghost btn-sm" data-acao="editar-srv" data-id="${s.id}">${I.lapis}</button><button type="button" class="btn btn-ghost btn-sm proj-perigo" data-acao="excluir-srv" data-id="${s.id}">${I.lixo}</button></span></td>
    </tr>`).join('');
  }

  function formServidor(s) {
    $('srvFormTitulo').textContent = s ? 'Editar ' + s.nome : 'Novo servidor';
    $('srvId').value = s ? s.id : ''; $('srvNome').value = s?.nome || ''; $('srvProvedor').value = s?.provedor || '';
    $('srvEndereco').value = s?.endereco || ''; $('srvValor').value = s ? valorCampo(s.valor_mensal) : '';
    $('srvMoeda').value = s?.moeda || 'BRL'; $('srvObs').value = s?.observacao || '';
    msg('srvMsg', '');
  }

  async function salvarServidor() {
    const corpo = { acao: 'servidor_salvar', id: Number($('srvId').value) || 0, nome: $('srvNome').value, provedor: $('srvProvedor').value,
                    endereco: $('srvEndereco').value, valor_mensal: $('srvValor').value, moeda: $('srvMoeda').value, observacao: $('srvObs').value };
    $('srvSalvar').disabled = true;
    try { await chamar(corpo); formServidor(null); toast('Servidor salvo.', 'success'); }
    catch (e) { msg('srvMsg', e.message); }
    finally { $('srvSalvar').disabled = false; }
  }

  function preencherFornecedores() {
    const nomes = new Set();
    P.projetos.forEach(p => p.itens.forEach(i => { if (i.fornecedor) nomes.add(i.fornecedor); }));
    $('projFornecedores').innerHTML = [...nomes].sort().map(n => `<option value="${esc(n)}"></option>`).join('');
  }

  async function excluirSimples(acao, id, texto, ok) {
    if (!(await confirmar(texto))) return;
    try { await chamar({ acao, id }); toast(ok, 'success'); if (acao === 'servidor_excluir') formServidor(null); }
    catch (e) { toast(e.message, 'danger'); }
  }

  /* ════════════════════════════ EVENTOS ════════════════════════════ */
  document.addEventListener('click', async e => {
    const f = e.target.closest('[data-fechar]');
    if (f) { fechar(f.dataset.fechar); return; }
    const cp = e.target.closest('[data-copiar]');
    if (cp) { e.preventDefault(); copiar(cp.dataset.copiar); return; }
    const ab = e.target.closest('[data-aba]');
    if (ab) {
      aba = ab.dataset.aba; history.replaceState(null, '', '#' + aba);
      document.querySelectorAll('.proj-aba').forEach(x => x.classList.toggle('ativa', x === ab));
      const p = porSlug[SLUG]; const grupo = [p, ...(filhosDe[p.id] || [])];
      desenharAba(p, grupo, grupo.flatMap(g => g.contas.map(c => Object.assign({ _proj: g }, c))), grupo.flatMap(g => g.acessos.map(x => Object.assign({ _proj: g }, x))));
      return;
    }
    const fl = e.target.closest('[data-filtro]');
    if (fl) {
      filtro = fl.dataset.filtro;
      document.querySelectorAll('#projFiltros [data-filtro]').forEach(x => { x.classList.toggle('btn-primary', x === fl); x.classList.toggle('btn-ghost', x !== fl); });
      desenharCartoes(); return;
    }
    const tr = e.target.closest('tr[data-ir]');
    if (tr && !e.target.closest('button')) { location.href = tr.dataset.ir; return; }

    const b = e.target.closest('[data-acao]');
    if (!b) return;
    const id = Number(b.dataset.id) || 0;
    switch (b.dataset.acao) {
      case 'novo-projeto': abrirProjeto(null, null); break;
      case 'novo-subprojeto': abrirProjeto(null, id); break;
      case 'editar-projeto': abrirProjeto(porId[id], null); break;
      case 'excluir-projeto': excluirProjeto(id); break;
      case 'novo-item': abrirItem(id, b.dataset.natureza, null); break;
      case 'editar-item': { const p = P.projetos.find(x => x.itens.some(i => i.id === id)); if (p) abrirItem(p.id, null, p.itens.find(i => i.id === id)); break; }
      case 'excluir-item': excluirSimples('item_excluir', id, 'Excluir este item?', 'Item excluído.'); break;
      case 'nova-conta': abrirConta(id, null); break;
      case 'editar-conta': { const [p, c] = acharConta(id); if (p) abrirConta(p.id, c); break; }
      case 'excluir-conta': excluirSimples('conta_excluir', id, 'Excluir esta conta do projeto?', 'Conta excluída.'); break;
      case 'novo-acesso': abrirAcesso(id, null); break;
      case 'editar-acesso': { const [p, a] = acharAcesso(id); if (p) abrirAcesso(p.id, a); break; }
      case 'excluir-acesso': excluirSimples('acesso_excluir', id, 'Excluir este acesso? A senha guardada também é apagada.', 'Acesso excluído.'); break;
      case 'revelar': revelar(id, b); break;
      case 'servidores': formServidor(null); abrir('srvModal'); break;
      case 'editar-srv': formServidor(P.servidores.find(s => s.id === id)); $('srvNome').focus(); break;
      case 'excluir-srv': excluirSimples('servidor_excluir', id, 'Excluir este servidor? Os projetos ligados a ele ficam sem servidor.', 'Servidor excluído.'); break;
      case 'cotacao': {
        if (!(window.Yuris && Yuris.prompt)) break;
        const v = await Yuris.prompt('Cotação do dólar usada para converter os custos em US$ (R$ por US$ 1):',
          { defaultValue: String(P.cotacao_usd).replace('.', ','), okLabel: 'Salvar cotação' });
        if (v === null || v === undefined || String(v).trim() === '') break;
        try { await chamar({ acao: 'cotacao_salvar', valor: v }); toast('Cotação atualizada.', 'success'); } catch (err) { toast(err.message, 'danger'); }
        break;
      }
    }
  });
  document.addEventListener('input', e => {
    if (e.target.id === 'projBusca') { busca = e.target.value.trim().toLowerCase(); desenharCartoes(); }
    if (e.target.id === 'imValor' || e.target.id === 'imQtd') previa();
  });
  document.addEventListener('change', e => { if (e.target.id === 'imMoeda' || e.target.id === 'imRecorrencia') previa(); });
  $('pmSalvar').addEventListener('click', salvarProjeto);
  $('imSalvar').addEventListener('click', salvarItem);
  $('cmSalvar').addEventListener('click', salvarConta);
  $('amSalvar').addEventListener('click', salvarAcesso);
  $('srvSalvar').addEventListener('click', salvarServidor);
  $('srvLimpar').addEventListener('click', () => formServidor(null));
  document.querySelectorAll('.modal-shell').forEach(m => m.addEventListener('mousedown', e => { if (e.target === m) fechar(m.id); }));
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    const aberto = ['itemModal', 'contaModal', 'acessoModal', 'projModal', 'srvModal'].find(id => $(id).style.display === 'flex');
    if (aberto) fechar(aberto);
  });

  carregar();
})();
