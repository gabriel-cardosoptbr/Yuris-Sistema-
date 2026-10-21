/* ═══════════════════════════════════════════════════════════════════════
   ambientes-visao.js: as Visões Gerais dos ambientes da Inovaize (Empresa,
   Software, Digital) e as telas Entregas e Campanhas do Digital. Página em
   app/Ambientes/Paginas/visao.php; números de /api/ambientes.php e
   /api/digital.php. Visual sobre o .ctr- de ff-contratos.css e o .amb- de
   ambientes.css. Sem diálogo do navegador: confirmação em modal próprio.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var CFG = window.AMB || {};
  var app = document.getElementById('ambApp'), acoes = document.getElementById('ambAcoes');
  if (!app) return;
  var mes = CFG.mes;
  var apoio = null, clientes = null;

  // ── Utilidades ─────────────────────────────────────────────────────────
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function brl(v) { return (Number(v) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
  function num(v) { return (Number(v) || 0).toLocaleString('pt-BR'); }
  function data(v) { if (!v) return ''; var s = String(v); return s.slice(8, 10) + '/' + s.slice(5, 7) + (s.length > 10 && s.slice(11, 16) !== '00:00' ? ' ' + s.slice(11, 16) : ''); }
  function mesRotulo(m) { var n = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro']; return n[+m.slice(5, 7) - 1] + ' de ' + m.slice(0, 4); }
  function pedir(url, corpo) {
    var op = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (corpo) { op.method = 'POST'; op.headers['Content-Type'] = 'application/json'; op.headers['X-CSRF-Token'] = CFG.csrf; op.body = JSON.stringify(corpo); }
    return fetch(url, op).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão. Tente de novo.' }; });
  }
  function kpi(rot, valor, sub, href, tom) {
    var tag = href ? 'a' : 'div';
    return '<' + tag + ' class="ctr-num amb-kpi' + (tom ? ' amb-' + tom : '') + '"' + (href ? ' href="' + esc(href) + '"' : '') + '><span>' + esc(rot) + '</span><b>' + valor + '</b>' + (sub ? '<small>' + sub + '</small>' : '') + '</' + tag + '>';
  }
  function cartao(titulo, corpo, extra) { return '<section class="ctr-cartao amb-cartao"><h3>' + esc(titulo) + (extra || '') + '</h3>' + corpo + '</section>'; }
  function vazio(txt) { return '<p class="amb-vazio">' + esc(txt) + '</p>'; }
  function erro(txt) { app.innerHTML = '<div class="ctr-aviso vermelho" role="alert">' + esc(txt) + ' <button type="button" class="ctr-btn ctr-btn-sm" id="ambTentar">Tentar de novo</button></div>'; document.getElementById('ambTentar').onclick = abrir; }
  function toast(msg, tipo) {
    var t = document.createElement('div');
    t.className = 'amb-toast' + (tipo === 'erro' ? ' amb-toast-erro' : '');
    t.setAttribute('role', 'status'); t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 4200);
  }
  function confirmar(titulo, texto, rotuloOk) {
    return new Promise(function (ok) {
      var f = document.createElement('div');
      f.className = 'amb-guarda-fundo';
      f.innerHTML = '<div class="amb-guarda" role="alertdialog" aria-modal="true" aria-labelledby="ambCfT"><h3 id="ambCfT">' + esc(titulo) + '</h3><p>' + esc(texto) + '</p>' +
        '<div class="amb-guarda-acoes"><button type="button" data-n>Cancelar</button><button type="button" class="principal" data-s>' + esc(rotuloOk) + '</button></div></div>';
      document.body.appendChild(f);
      f.querySelector('[data-n]').focus();
      function fim(v) { f.remove(); ok(v); }
      f.querySelector('[data-n]').onclick = function () { fim(false); };
      f.querySelector('[data-s]').onclick = function () { fim(true); };
      f.addEventListener('keydown', function (e) { if (e.key === 'Escape') fim(false); });
    });
  }
  function seletorMes() {
    if (!acoes) return;
    acoes.innerHTML = '<label class="amb-mes"><span>Mês</span><input type="month" id="ambMes" value="' + esc(mes) + '" data-amb-ignorar></label>';
    document.getElementById('ambMes').onchange = function () {
      if (!this.value) return;
      mes = this.value;
      var u = new URL(location.href); u.searchParams.set('mes', mes); history.replaceState(null, '', u.toString());
      abrir();
    };
  }
  function linkTarefa(area, t) { return '/tarefas?amb=' + area + '&board=' + t.board_id + '&tarefa=' + t.id; }
  function proximos(lista, area) {
    if (!lista.length) return vazio('Nenhum prazo nos próximos 14 dias.');
    return '<ul class="amb-lista">' + lista.map(function (t) {
      return '<li><a href="' + linkTarefa(area, t) + '"><b>' + esc(t.titulo) + '</b><small>' + esc(t.quadro || '') + (t.responsavel ? ' · ' + esc(t.responsavel) : ' · sem responsável') + '</small></a>' +
        '<span class="amb-pill' + (t.atrasada ? ' amb-pill-verm' : '') + '">' + (t.atrasada ? 'Atrasada · ' : '') + esc(data(t.prazo)) + '</span></li>';
    }).join('') + '</ul>';
  }
  function responsaveis(lista) {
    if (!lista.length) return vazio('Nenhuma tarefa aberta com responsável.');
    return '<table class="amb-tab"><thead><tr><th>Pessoa</th><th>Abertas</th><th>Atrasadas</th><th>Próximo prazo</th></tr></thead><tbody>' + lista.map(function (r) {
      return '<tr><td>' + esc(r.nome) + '</td><td>' + num(r.abertas) + '</td><td' + (r.atrasadas ? ' class="amb-verm"' : '') + '>' + num(r.atrasadas) + '</td><td>' + esc(data(r.proximo) || '-') + '</td></tr>';
    }).join('') + '</tbody></table>';
  }

  // ── Empresa ────────────────────────────────────────────────────────────
  // A visão de tudo, em gráficos: cada área com evolução, distribuição e os
  // números do mês. Chart.js (o mesmo do painel comercial), nas cores da marca.
  var PM = (window.MARCA && window.MARCA.paleta) || {};
  // A cor do ambiente (ambientes.css, html[data-ambiente]) vai na frente das séries; sem ela, a da marca.
  var estiloRaiz = getComputedStyle(document.documentElement);
  var ambC = (estiloRaiz.getPropertyValue('--amb-c') || '').trim(), ambC2 = (estiloRaiz.getPropertyValue('--amb-c2') || '').trim();
  // Tons claros derivados da cor do ambiente (mistura com branco), para as séries secundárias acompanharem.
  function clarear(hex, p) {
    var m = /^#?([0-9a-f]{6})$/i.exec(hex || ''); if (!m) return null;
    var n = parseInt(m[1], 16), r = n >> 16, g = (n >> 8) & 255, bl = n & 255, f = function (c) { return Math.round(c + (255 - c) * p).toString(16).padStart(2, '0'); };
    return '#' + f(r) + f(g) + f(bl);
  }
  var COR = { marca: ambC || PM.marca || '#015DFC', clara: clarear(ambC, .35) || PM.clara || '#6D9DFD', media: clarear(ambC, .62) || PM.media || '#A9C6FF', forte: ambC2 || PM.forte || '#013DF2',
    violeta: '#835FF2', ambar: '#FDAD0D', laranja: '#F55902', verde: '#1A9B4B', vermelho: '#D13C3C', cinza: '#9AA4B2' };
  if (ambC2 && ambC2 !== COR.violeta) COR.violeta = ambC2;
  var PALETA = [COR.marca, COR.violeta, COR.ambar, COR.verde, COR.laranja, COR.clara, COR.forte, COR.cinza];
  var escuro = function () { return document.documentElement.getAttribute('data-theme') !== 'light'; };
  var graficos = [];
  function gid() { return 'g' + Math.random().toString(36).slice(2, 9); }
  function canvas(id, altura) { return '<div class="amb-graf" style="height:' + (altura || 240) + 'px"><canvas id="' + id + '"></canvas></div>'; }
  // A navegação rápida acende o link da seção que está na tela.
  function ligarNav() {
    var nav = document.querySelector('.amb-nav');
    if (!nav || nav._ligado || !window.IntersectionObserver) return;
    nav._ligado = true;
    var links = {};
    nav.querySelectorAll('a[href^="#"]').forEach(function (a) { links[a.getAttribute('href').slice(1)] = a; });
    var vistos = {};
    function marcar() {
      var atual = null;
      Object.keys(links).forEach(function (id) { if (vistos[id] && (!atual || vistos[id] < vistos[atual])) atual = id; });
      Object.keys(links).forEach(function (id) { links[id].classList.toggle('ativo', id === atual); });
    }
    var obs = new IntersectionObserver(function (ents) {
      ents.forEach(function (e) { if (e.isIntersecting) vistos[e.target.id] = e.boundingClientRect.top; else delete vistos[e.target.id]; });
      marcar();
    }, { rootMargin: '-56px 0px -55% 0px', threshold: 0 });
    Object.keys(links).forEach(function (id) { var s = document.getElementById(id); if (s) obs.observe(s); });
  }
  function desenharGraficos(lista) {
    ligarNav();
    if (!window.Chart) return;
    graficos.forEach(function (g) { try { g.destroy(); } catch (e) { /* já saiu da tela */ } });
    graficos = [];
    var texto = escuro() ? '#AEB7C4' : '#767676', grade = escuro() ? 'rgba(255,255,255,.08)' : 'rgba(17,29,45,.07)';
    Chart.defaults.font.family = "'Manrope', system-ui, sans-serif"; Chart.defaults.font.size = 11; Chart.defaults.color = texto;
    lista.forEach(function (item) {
      var el = document.getElementById(item.id);
      if (!el) return;
      var cfg = item.cfg;
      cfg.options = cfg.options || {};
      cfg.options.responsive = true; cfg.options.maintainAspectRatio = false;
      cfg.options.plugins = cfg.options.plugins || {};
      cfg.options.plugins.legend = Object.assign({ position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } }, cfg.options.plugins.legend || {});
      cfg.options.plugins.tooltip = Object.assign({ backgroundColor: escuro() ? '#1B2A44' : '#FFFFFF', titleColor: escuro() ? '#E7EBF1' : '#1F2937', bodyColor: escuro() ? '#D2D8E1' : '#575757',
        borderColor: grade, borderWidth: 1, padding: 10, cornerRadius: 10, usePointStyle: true, boxWidth: 8 }, cfg.options.plugins.tooltip || {});
      if (cfg.options.scales) Object.keys(cfg.options.scales).forEach(function (k) {
        cfg.options.scales[k].grid = Object.assign({ color: grade, drawBorder: false }, cfg.options.scales[k].grid || {});
        cfg.options.scales[k].ticks = Object.assign({ color: texto }, cfg.options.scales[k].ticks || {});
      });
      graficos.push(new Chart(el, cfg));
    });
  }
  var brlCurto = function (v) { return Math.abs(v) >= 1000 ? 'R$ ' + (v / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mil' : brl(v); };
  function eixoMoeda() { return { beginAtZero: true, ticks: { callback: function (v) { return brlCurto(v); } } }; }
  function tipMoeda() { return { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + brl(c.parsed.y != null ? c.parsed.y : c.parsed); } } }; }
  function rosca(id, rotulos, valores, cores, centro) {
    return { id: id, cfg: { type: 'doughnut', data: { labels: rotulos, datasets: [{ data: valores, backgroundColor: cores || PALETA, borderWidth: 0, hoverOffset: 6 }] },
      options: { cutout: '68%', plugins: { legend: { position: 'right' }, tooltip: { callbacks: { label: function (c) { var t = c.dataset.data.reduce(function (a, b) { return a + b; }, 0); return ' ' + c.label + ': ' + num(c.parsed) + (t ? ' (' + Math.round(c.parsed / t * 100) + '%)' : ''); } } } } },
      plugins: centro ? [{ id: 'centro', afterDraw: function (ch) { var x = ch.chartArea.left + ch.chartArea.width / 2, y = ch.chartArea.top + ch.chartArea.height / 2, ctx = ch.ctx; ctx.save(); ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.font = "800 22px 'Manrope', system-ui, sans-serif"; ctx.fillStyle = escuro() ? '#E7EBF1' : '#1F2937'; ctx.fillText(centro[0], x, y - 8); ctx.font = "600 11px 'Manrope', system-ui, sans-serif"; ctx.fillStyle = escuro() ? '#AEB7C4' : '#767676'; ctx.fillText(centro[1], x, y + 12); ctx.restore(); } }] : [] } };
  }
  function tile(rot, valor, sub, tom) { return '<div class="amb-tile' + (tom ? ' amb-' + tom : '') + '"><span>' + esc(rot) + '</span><b>' + valor + '</b>' + (sub ? '<small>' + sub + '</small>' : '') + '</div>'; }
  function tiles(l) { return '<div class="amb-tiles">' + l.filter(Boolean).join('') + '</div>'; }
  function grafCartao(titulo, id, altura, extra, classe) { return '<div class="ctr-cartao amb-cartao' + (classe ? ' ' + classe : '') + '"><h3>' + esc(titulo) + (extra ? '<small class="amb-cinza">' + esc(extra) + '</small>' : '') + '</h3>' + canvas(id, altura) + '</div>'; }
  function secao(id, titulo, link, rotuloLink, corpo) {
    return '<section class="amb-sec" id="' + id + '"><div class="amb-sec-cab"><h2>' + esc(titulo) + '</h2>' +
      (link ? '<a class="ctr-btn ctr-btn-sm" href="' + esc(link) + '">' + esc(rotuloLink) + '</a>' : '') + '</div>' + corpo + '</section>';
  }
  function pct(v) { return v == null ? '-' : String(v).replace('.', ',') + '%'; }
  var SIT_PROJ = { desenvolvimento: 'Em desenvolvimento', producao: 'Em produção', pausado: 'Pausado' };

  function empresa() {
    seletorMes();
    var ini = mes + '-01', ult = new Date(+mes.slice(0, 4), +mes.slice(5, 7), 0).getDate();
    Promise.all([
      pedir('/api/ambientes.php?visao=empresa&mes=' + mes),
      pedir('/api/dashboard_comercial.php?start=' + ini + '&end=' + mes + '-' + ('0' + ult).slice(-2)).then(function (r) { return r && r.kpis ? r : null; })
    ]).then(function (res) {
      var r = res[0], com = res[1];
      if (!r.ok) return erro(r.error || 'Não foi possível carregar a Visão Geral.');
      var d = r.data, S = d.series, f = d.financeiro, cl = d.clientes_detalhe, mk = d.marketing, mt = mk.total, sw = d.software, eq = d.equipe, G = [];
      var nav = [['empComercial', 'Comercial'], ['empFinanceiro', 'Financeiro'], ['empClientes', 'Clientes'], ['empMarketing', 'Marketing'], ['empSoftware', 'Software']];
      if (d.contratos) nav.push(['empContratos', 'Contratos']);
      nav.push(['empEquipe', 'Equipe'], ['empAtencao', 'Atrasos e pendências']);
      var h = '<nav class="amb-nav" aria-label="Seções da Visão Geral">' + nav.map(function (n) { return '<a href="#' + n[0] + '">' + n[1] + '</a>'; }).join('') + '</nav>';

      // Resumo do mês: uma faixa de números.
      var recebPct = f.previsto ? Math.round(f.recebido / f.previsto * 100) : null;
      h += tiles([
        tile('Receita recorrente', brl(f.mrr), 'Software ' + brlCurto(f.mrr_por_area.software) + ' · Digital ' + brlCurto(f.mrr_por_area.digital)),
        tile('Recebido no mês', brl(f.recebido), recebPct != null ? recebPct + '% do previsto (' + brlCurto(f.previsto) + ')' : 'nada previsto', f.cobrancas_atrasadas ? 'alerta' : ''),
        tile('Receita fechada no funil', com ? brl(com.kpis.atual.receita) : '-', com ? num(com.kpis.atual.vendas) + ' venda(s) · conversão ' + pct(com.kpis.atual.conversao) : ''),
        tile('Clientes ativos', num(cl.ativos), num(cl.novos_mes) + ' novo(s) no mês · ' + num(cl.inadimplentes) + ' inadimplente(s)', cl.inadimplentes ? 'alerta' : ''),
        tile('Entregas de marketing', num(mt.concluidas) + '<em>/' + num(mt.contratadas) + '</em>', num(mt.publicadas) + ' postada(s) · ' + num(mt.atrasadas) + ' atrasada(s)', mt.atrasadas ? 'alerta' : ''),
        tile('Projetos de software', num(sw.carteira.length), num(sw.tarefas.abertas) + ' tarefa(s) aberta(s) · ' + num(sw.tarefas.atrasadas) + ' atrasada(s)', sw.tarefas.atrasadas ? 'alerta' : ''),
        tile('OTIF da equipe', pct(eq.otif && eq.otif.otif_pct), eq.otif && eq.otif.compromissos ? num(eq.otif.compromissos) + ' compromisso(s) no mês' : 'sem compromissos no mês')
      ]);

      // ── Comercial ──
      var cmh = '';
      if (com) {
        var a = com.kpis.atual, ev = com.evolucao.mes || [], ids = { evo: gid(), funil: gid(), area: gid() };
        var meta = com.meta && com.meta.valor ? tile('Meta do mês', brlCurto(com.meta.fechado || 0) + '<em>/' + brlCurto(com.meta.valor) + '</em>', pct(com.meta.progresso) + ' atingido · ' + num(com.meta.dias_restantes) + ' dia(s) restante(s)', (com.meta.progresso || 0) < 50 ? 'atencao' : '') : '';
        cmh += tiles([tile('Novos leads', num(a.leads)), tile('Oportunidades', num(a.oportunidades), num(a.em_negociacao) + ' em negociação'), tile('No funil', brlCurto(a.valor_pipeline), 'valor em aberto'),
          tile('Vendas', num(a.vendas)), tile('Receita fechada', brl(a.receita)), tile('Conversão', pct(a.conversao)), tile('Ticket médio', a.ticket != null ? brlCurto(a.ticket) : '-'), meta]);
        cmh += '<div class="amb-grade-3"><div class="amb-span2">' + grafCartao('Receita fechada e leads, 12 meses', ids.evo, 260, 'barras: receita · linha: leads novos') + '</div>' + grafCartao('Vendas por área do serviço no mês', ids.area, 260) + '</div>';
        G.push({ id: ids.evo, cfg: { type: 'bar', data: { labels: ev.map(function (x) { return x.label; }), datasets: [
          { type: 'bar', label: 'Receita fechada', data: ev.map(function (x) { return x.receita; }), backgroundColor: COR.marca, borderRadius: 6, yAxisID: 'y', order: 2 },
          { type: 'line', label: 'Meta', data: ev.map(function (x) { return x.meta; }), borderColor: COR.ambar, borderDash: [5, 4], pointRadius: 0, borderWidth: 2, yAxisID: 'y', order: 1, spanGaps: true },
          { type: 'line', label: 'Leads novos', data: ev.map(function (x) { return x.leads; }), borderColor: COR.violeta, backgroundColor: COR.violeta, tension: .35, pointRadius: 3, borderWidth: 2, yAxisID: 'y2', order: 0 } ] },
          options: { interaction: { mode: 'index', intersect: false }, scales: { y: eixoMoeda(), y2: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { precision: 0 } } },
            plugins: { tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + (c.dataset.yAxisID === 'y2' ? num(c.parsed.y) : brl(c.parsed.y)); } } } } } } });
        var ROT = { software: 'Software', digital: 'Digital / Marketing', ambos: 'Os dois', sem_area: 'Sem área marcada' }, ar = d.comercial_areas;
        var chaves = ['software', 'digital', 'ambos', 'sem_area'].filter(function (k) { return (ar.fechados[k] && ar.fechados[k].qtd) || (ar.abertos[k] && ar.abertos[k].qtd); });
        G.push({ id: ids.area, cfg: { type: 'bar', data: { labels: chaves.map(function (k) { return ROT[k]; }), datasets: [
          { label: 'Fechadas no mês', data: chaves.map(function (k) { return (ar.fechados[k] || {}).qtd || 0; }), backgroundColor: COR.marca, borderRadius: 6 },
          { label: 'Em aberto', data: chaves.map(function (k) { return (ar.abertos[k] || {}).qtd || 0; }), backgroundColor: COR.media, borderRadius: 6 } ] },
          options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: {} } } } });
        var funil = (com.pipeline || []).filter(function (p) { return p.qtd > 0; });
        cmh += '<div class="amb-grade-3"><div class="amb-span2">' + grafCartao('Funil agora: leads por etapa', ids.funil, Math.max(200, 34 * funil.length + 60)) + '</div>' +
          '<div class="ctr-cartao amb-cartao"><h3>Prospecção do mês</h3>' + (com.prospeccao ? tiles([
            tile('Entraram', num(com.prospeccao.entraram), com.prospeccao.entraram_anterior != null ? num(com.prospeccao.entraram_anterior) + ' no mês anterior' : ''),
            tile('Descartados', num(com.prospeccao.descartados), com.prospeccao.entraram ? Math.round(com.prospeccao.descartados / com.prospeccao.entraram * 100) + '% dos que entraram' : '', com.prospeccao.descartados ? 'atencao' : '')
          ]) : '') + '</div></div>';
        G.push({ id: ids.funil, cfg: { type: 'bar', data: { labels: funil.map(function (p) { return p.nome; }), datasets: [{ label: 'Leads', data: funil.map(function (p) { return p.qtd; }), backgroundColor: funil.map(function (p, i) { return p.fechado ? COR.verde : p.perdido ? COR.cinza : PALETA[i % PALETA.length]; }), borderRadius: 6 }] },
          options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { var p = funil[c.dataIndex]; return ' ' + num(p.qtd) + ' lead(s) · ' + brl(p.valor) + ' em aberto'; } } } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: {} } } } });
      } else cmh = '<div class="ctr-aviso">O painel comercial não respondeu agora.</div>';
      h += secao('empComercial', 'Comercial', '/dashboard?amb=comercial', 'Abrir painel comercial', cmh);

      // ── Financeiro ──
      var fi = { evo: gid(), area: gid() }, AREA = { software: 'Software', digital: 'Digital', outro: 'Outro', sem_area: 'Sem área' };
      var fh = tiles([tile('Previsto no mês', brl(f.previsto), 'cobranças vencendo no mês'), tile('Recebido', brl(f.recebido), recebPct != null ? recebPct + '% do previsto' : ''),
        tile('A receber', brl(f.a_receber), 'ainda em aberto no mês'), tile('Em atraso', brl(f.atrasado), num(f.cobrancas_atrasadas) + ' cobrança(s)', f.cobrancas_atrasadas ? 'alerta' : ''),
        tile('Próximos 7 dias', brl(f.proximos_7_dias.valor), num(f.proximos_7_dias.qtd) + ' a vencer'),
        (f.dre.receita || f.dre.despesa) ? tile('Resultado fixo (DRE)', brl(f.dre.resultado), 'receita ' + brlCurto(f.dre.receita) + ' · despesa ' + brlCurto(f.dre.despesa), f.dre.resultado < 0 ? 'alerta' : '') : '']);
      fh += '<div class="amb-grade-3"><div class="amb-span2">' + grafCartao('Dinheiro, 12 meses', fi.evo, 260, 'barras: previsto e recebido · linha: receita recorrente') + '</div>' + grafCartao('Recebido no mês por área', fi.area, 260) + '</div>';
      G.push({ id: fi.evo, cfg: { type: 'bar', data: { labels: S.meses, datasets: [
        { label: 'Previsto', data: S.dinheiro.previsto, backgroundColor: COR.media, borderRadius: 6 },
        { label: 'Recebido', data: S.dinheiro.recebido, backgroundColor: COR.marca, borderRadius: 6 },
        { type: 'line', label: 'Receita recorrente', data: S.dinheiro.recorrente, borderColor: COR.verde, backgroundColor: COR.verde, tension: .35, pointRadius: 3, borderWidth: 2 } ] },
        options: { interaction: { mode: 'index', intersect: false }, scales: { y: eixoMoeda(), x: {} }, plugins: { tooltip: tipMoeda() } } } });
      var fa = Object.keys(f.por_area).filter(function (k) { return f.por_area[k].recebido > 0; });
      G.push(fa.length ? rosca(fi.area, fa.map(function (k) { return AREA[k] || k; }), fa.map(function (k) { return f.por_area[k].recebido; }), null, [brlCurto(f.recebido), 'recebido'])
        : rosca(fi.area, ['Nada recebido'], [1], [escuro() ? '#2A3A55' : '#E5E9F0'], ['R$ 0', 'recebido']));
      h += secao('empFinanceiro', 'Financeiro', CFG.pode.financas ? '/financas?amb=empresa' : '', 'Abrir Finanças', fh);

      // ── Clientes ──
      var ci = { evo: gid(), area: gid(), sit: gid() };
      var ch = tiles([tile('Ativos', num(cl.ativos)), tile('Novos no mês', num(cl.novos_mes)), tile('Software', num(cl.software)), tile('Digital', num(cl.digital)), tile('Nas duas', num(cl.nas_duas)),
        tile('Sem área', num(cl.sem_area), '', cl.sem_area ? 'atencao' : ''), tile('Inadimplentes', num(cl.inadimplentes), 'com cobrança vencida', cl.inadimplentes ? 'alerta' : ''), tile('Sem responsável', num(cl.sem_responsavel), '', cl.sem_responsavel ? 'atencao' : '')]);
      ch += '<div class="amb-grade-3"><div class="amb-span2">' + grafCartao('Base de clientes, 12 meses', ci.evo, 240, 'barras: novos no mês · linha: ativos acumulados') + '</div>' + grafCartao('Por área', ci.area, 240) + '</div>';
      G.push({ id: ci.evo, cfg: { type: 'bar', data: { labels: S.meses, datasets: [
        { label: 'Novos', data: S.clientes.novos, backgroundColor: COR.marca, borderRadius: 6, yAxisID: 'y' },
        { type: 'line', label: 'Ativos acumulados', data: S.clientes.base, borderColor: COR.violeta, backgroundColor: COR.violeta, tension: .35, pointRadius: 3, borderWidth: 2, yAxisID: 'y2' } ] },
        options: { interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, y2: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { precision: 0 } } } } } });
      var soSw = cl.software - cl.nas_duas, soDg = cl.digital - cl.nas_duas;
      G.push(rosca(ci.area, ['Só Software', 'Só Digital', 'As duas', 'Sem área'], [soSw, soDg, cl.nas_duas, cl.sem_area], [COR.marca, COR.violeta, COR.verde, COR.cinza], [num(cl.ativos), 'ativos']));
      h += secao('empClientes', 'Clientes', '/clientes?amb=empresa', 'Abrir Clientes', ch);

      // ── Marketing ──
      var mi = { evo: gid(), cli: gid(), sit: gid(), post: gid() };
      var mh = tiles([tile('Contratadas', num(mt.contratadas), num(mt.esperado_ate_hoje) + ' esperadas até hoje'), tile('Concluídas', num(mt.concluidas), mt.contratadas ? Math.round(mt.concluidas / mt.contratadas * 100) + '% do contratado' : ''),
        tile('Postadas', num(mt.publicadas), num(mt.agendadas) + ' agendada(s) · ' + num(mt.a_publicar) + ' a postar', mt.a_publicar ? 'atencao' : ''), tile('Em produção', num(mt.producao)),
        tile('Aguardando aprovação', num(mt.aprovacao), '', mt.aprovacao ? 'atencao' : ''), tile('Atrasadas', num(mt.atrasadas), '', mt.atrasadas ? 'alerta' : ''),
        tile('Sem cartão', num(mt.nao_cadastradas), 'contratadas e não cadastradas', mt.nao_cadastradas ? 'atencao' : ''), tile('Campanhas ativas', num(mt.campanhas_ativas), brlCurto(mt.verba_ativa) + '/mês de verba'), tile('Materiais faltando', num(mt.materiais_faltando))]);
      mh += '<div class="amb-grade-3"><div class="amb-span2">' + grafCartao('Entregas por mês, 6 meses', mi.evo, 240, 'contratadas, concluídas e postadas') + '</div>' + grafCartao('Situação das entregas do mês', mi.sit, 240) + '</div>';
      G.push({ id: mi.evo, cfg: { type: 'bar', data: { labels: S.meses6, datasets: [
        { label: 'Contratadas', data: S.entregas.contratadas, backgroundColor: COR.media, borderRadius: 6 },
        { label: 'Concluídas', data: S.entregas.concluidas, backgroundColor: COR.marca, borderRadius: 6 },
        { label: 'Postadas', data: S.entregas.publicadas, backgroundColor: COR.verde, borderRadius: 6 } ] },
        options: { interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: {} } } } });
      G.push(rosca(mi.sit, ['Concluídas', 'Aguardando aprovação', 'Em produção', 'Sem cartão'], [mt.concluidas, mt.aprovacao, mt.producao, mt.nao_cadastradas], [COR.verde, COR.ambar, COR.marca, COR.cinza], [num(mt.contratadas || mt.cadastradas), 'no mês']));
      var cls = mk.clientes.slice().sort(function (a, b) { return (b.contratadas || b.cadastradas) - (a.contratadas || a.cadastradas); });
      if (cls.length) {
        mh += '<div class="amb-grade-3"><div class="amb-span2">' + grafCartao('Por cliente: contratado, concluído e postado', mi.cli, Math.max(220, 34 * cls.length + 70)) + '</div>' +
          '<div class="ctr-cartao amb-cartao"><h3>Ritmo do mês por cliente</h3><ul class="amb-lista">' + cls.map(function (c) {
            var ritmo = c.ritmo === 'atrasado' ? '<span class="amb-pill amb-pill-verm">' + num(c.concluidas) + ' de ' + num(c.esperado_ate_hoje) + ' esperadas</span>' : c.ritmo === 'em_dia' ? '<span class="amb-pill amb-pill-ok">Em dia</span>' : '<span class="amb-pill">Sem frequência</span>';
            return '<li><a href="/clientes?amb=digital&open=' + c.id + '"><b>' + esc(c.nome) + '</b><small>' + (c.contratadas && !c.publicadas && !c.concluidas ? '<span class="amb-verm">Nada postado no mês</span>' : num(c.publicadas) + ' postada(s) · ' + num(c.aprovacao) + ' em aprovação') + '</small></a>' + ritmo + '</li>';
          }).join('') + '</ul></div></div>';
        G.push({ id: mi.cli, cfg: { type: 'bar', data: { labels: cls.map(function (c) { return c.nome; }), datasets: [
          { label: 'Contratadas', data: cls.map(function (c) { return c.contratadas; }), backgroundColor: COR.media, borderRadius: 6 },
          { label: 'Concluídas', data: cls.map(function (c) { return c.concluidas; }), backgroundColor: COR.marca, borderRadius: 6 },
          { label: 'Postadas', data: cls.map(function (c) { return c.publicadas; }), backgroundColor: COR.verde, borderRadius: 6 },
          { label: 'Atrasadas', data: cls.map(function (c) { return c.atrasadas; }), backgroundColor: COR.vermelho, borderRadius: 6 } ] },
          options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: {} } } } });
      } else mh += '<div class="ctr-cartao amb-cartao">' + vazio('Nenhum cliente de Digital ainda. Marque a área na ficha do cliente ou cadastre frequência, campanha ou quadro de produção.') + '</div>';
      h += secao('empMarketing', 'Marketing (Digital)', '/digital', 'Abrir Digital', mh);

      // ── Software ──
      var si = { sit: gid(), resp: gid(), sem: gid() }, t = sw.tarefas, porSit = {}, resp = {};
      sw.carteira.forEach(function (p) { porSit[p.status] = (porSit[p.status] || 0) + 1; var rr = p.responsavel || 'Sem responsável'; resp[rr] = (resp[rr] || 0) + 1; });
      var respK = Object.keys(resp).sort(function (a, b) { return resp[b] - resp[a]; }).slice(0, 10);
      var sh = tiles([tile('Projetos em andamento', num(sw.carteira.length)), tile('Quadros', num(sw.quadros.length), 'demandas e suporte'), tile('Tarefas abertas', num(t.abertas), num(t.sem_responsavel) + ' sem responsável'),
        tile('Atrasadas', num(t.atrasadas), '', t.atrasadas ? 'alerta' : ''), tile('Suporte em aberto', num(t.suporte_abertos)), tile('Concluídas (8 semanas)', num(S.tarefas_concluidas.software.reduce(function (a, b) { return a + b; }, 0)))]);
      sh += '<div class="amb-grade-3">' + grafCartao('Projetos por situação', si.sit, 240) + grafCartao('Projetos por responsável', si.resp, 240) + grafCartao('Tarefas concluídas por semana', si.sem, 240, 'todos os quadros, por área') + '</div>';
      var sitK = Object.keys(porSit);
      G.push(sitK.length ? rosca(si.sit, sitK.map(function (k) { return SIT_PROJ[k] || k; }), sitK.map(function (k) { return porSit[k]; }), [COR.marca, COR.verde, COR.ambar], [num(sw.carteira.length), 'projetos'])
        : rosca(si.sit, ['Nenhum projeto'], [1], [escuro() ? '#2A3A55' : '#E5E9F0'], ['0', 'projetos']));
      G.push({ id: si.resp, cfg: { type: 'bar', data: { labels: respK, datasets: [{ label: 'Projetos', data: respK.map(function (k) { return resp[k]; }), backgroundColor: COR.marca, borderRadius: 6 }] },
        options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: {} } } } });
      G.push({ id: si.sem, cfg: { type: 'bar', data: { labels: S.semanas, datasets: [
        { label: 'Software', data: S.tarefas_concluidas.software, backgroundColor: COR.marca, borderRadius: 4 },
        { label: 'Digital', data: S.tarefas_concluidas.digital, backgroundColor: COR.violeta, borderRadius: 4 },
        { label: 'Outros quadros', data: S.tarefas_concluidas.outros, backgroundColor: COR.cinza, borderRadius: 4 } ] },
        options: { interaction: { mode: 'index', intersect: false }, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } } } });
      if (sw.proximos.length) sh += cartao('Próximos prazos de Software (14 dias)', proximos(sw.proximos.slice(0, 6), 'software'));
      h += secao('empSoftware', 'Software', '/software', 'Abrir Software', sh);

      // ── Contratos ──
      if (d.contratos) {
        var k = d.contratos, ki = { sit: gid(), evo: gid() };
        var kh = tiles([tile('Aguardando assinatura', num(k.aguardando), '', k.aguardando ? 'atencao' : ''), tile('Assinados no mês', num(k.concluidos_mes), num(k.concluidos) + ' no total'), tile('Rascunhos', num(k.rascunhos)), tile('Expirados ou recusados', num(k.perdidos), '', k.perdidos ? 'alerta' : '')]);
        kh += '<div class="amb-grade-3">' + grafCartao('Contratos por situação', ki.sit, 240) + '<div class="amb-span2">' + grafCartao('Enviados e assinados por mês', ki.evo, 240) + '</div></div>';
        var kt = k.aguardando + k.concluidos + k.rascunhos + k.perdidos;
        G.push(kt ? rosca(ki.sit, ['Assinados', 'Aguardando', 'Rascunhos', 'Expirados ou recusados'], [k.concluidos, k.aguardando, k.rascunhos, k.perdidos], [COR.verde, COR.ambar, COR.cinza, COR.vermelho], [num(kt), 'contratos'])
          : rosca(ki.sit, ['Nenhum contrato'], [1], [escuro() ? '#2A3A55' : '#E5E9F0'], ['0', 'contratos']));
        G.push({ id: ki.evo, cfg: { type: 'bar', data: { labels: S.meses6, datasets: [
          { label: 'Enviados para assinatura', data: S.contratos.enviados, backgroundColor: COR.media, borderRadius: 6 },
          { label: 'Assinados', data: S.contratos.assinados, backgroundColor: COR.verde, borderRadius: 6 } ] },
          options: { interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: {} } } } });
        h += secao('empContratos', 'Contratos', '/contratos?amb=empresa', 'Abrir Contratos', kh);
      }

      // ── Equipe ──
      var ei = { pessoas: gid(), otif: gid() }, o = eq.otif || {}, pes = eq.pessoas.slice(0, 12);
      var eh = tiles([tile('OTIF no mês', pct(o.otif_pct), o.compromissos ? num(o.otif) + ' de ' + num(o.compromissos) + ' no prazo e completas' : 'sem compromissos com prazo'),
        tile('No prazo', pct(o.ot_pct)), tile('Completas', pct(o.if_pct)), tile('Tarefas abertas', num(eq.abertas), num(eq.sem_responsavel) + ' sem responsável'), tile('Atrasadas', num(eq.atrasadas), '', eq.atrasadas ? 'alerta' : '')]);
      eh += '<div class="amb-grade-3"><div class="amb-span2">' + grafCartao('Tarefas por pessoa', ei.pessoas, Math.max(220, 32 * pes.length + 70), 'abertas e atrasadas agora') + '</div>' + grafCartao('OTIF da equipe, 6 meses', ei.otif, 240) + '</div>';
      G.push({ id: ei.pessoas, cfg: { type: 'bar', data: { labels: pes.map(function (p) { return p.nome; }), datasets: [
        { label: 'Abertas', data: pes.map(function (p) { return p.abertas; }), backgroundColor: COR.marca, borderRadius: 6 },
        { label: 'Atrasadas', data: pes.map(function (p) { return p.atrasadas; }), backgroundColor: COR.vermelho, borderRadius: 6 } ] },
        options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: {} },
          plugins: { tooltip: { callbacks: { afterBody: function (c) { var p = pes[c[0].dataIndex]; return p.otif_pct != null ? 'OTIF no mês: ' + pct(p.otif_pct) : ''; } } } } } } });
      var evo = eq.evolucao || { rotulos: [], otif: [], ot: [], if: [] };
      G.push({ id: ei.otif, cfg: { type: 'line', data: { labels: evo.rotulos, datasets: [
        { label: 'OTIF', data: evo.otif, borderColor: COR.marca, backgroundColor: COR.marca, tension: .35, pointRadius: 3, borderWidth: 2, spanGaps: true },
        { label: 'No prazo', data: evo.ot, borderColor: COR.violeta, borderDash: [4, 3], tension: .35, pointRadius: 2, borderWidth: 1.5, spanGaps: true },
        { label: 'Completas', data: evo['if'], borderColor: COR.verde, borderDash: [4, 3], tension: .35, pointRadius: 2, borderWidth: 1.5, spanGaps: true } ] },
        options: { scales: { y: { min: 0, max: 100, ticks: { callback: function (v) { return v + '%'; } } }, x: {} }, plugins: { tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + (c.parsed.y == null ? 'sem dados' : pct(c.parsed.y)); } } } } } } });
      h += secao('empEquipe', 'Equipe', CFG.pode.tarefas ? '/desempenho' : '', 'Abrir Desempenho', eh);

      // ── Atrasos e pendências ──
      var a2 = d.atrasos, p = d.pendencias;
      var atrasos = [['Tarefas de Software atrasadas', a2.tarefas_software, '/software'], ['Tarefas de Digital atrasadas', a2.tarefas_digital, '/digital'],
        ['Entregas atrasadas', a2.entregas, '/digital?ver=entregas&mes=' + d.mes], ['Cobranças vencidas', a2.cobrancas, CFG.pode.financas ? '/financas?amb=empresa' : '', a2.cobrancas ? brl(a2.cobrancas_valor) : '']];
      var pend = [['Entregas aguardando aprovação do cliente', p.aprovacoes, '/digital?ver=entregas&mes=' + d.mes], ['Materiais esperados ainda não recebidos', p.materiais, '/digital'],
        ['Entregas a postar', mt.a_publicar, '/digital?ver=entregas&mes=' + d.mes], ['Clientes de marketing atrasados no ritmo do mês', mk.clientes.filter(function (c) { return c.ritmo === 'atrasado'; }).length, '/digital'],
        ['Tarefas sem responsável', p.sem_responsavel, '/software']];
      if (p.contratos !== null) pend.splice(2, 0, ['Contratos aguardando assinatura', p.contratos, '/contratos?amb=empresa']);
      function linhas(l) {
        return '<ul class="amb-lista amb-lista-num">' + l.map(function (x) {
          var dentro = '<b>' + esc(x[0]) + '</b>' + (x[3] ? '<small>' + esc(x[3]) + '</small>' : '');
          return '<li>' + (x[2] ? '<a href="' + esc(x[2]) + '">' + dentro + '</a>' : '<span>' + dentro + '</span>') + '<span class="amb-pill' + (x[1] ? ' amb-pill-verm' : ' amb-pill-ok') + '">' + num(x[1]) + '</span></li>';
        }).join('') + '</ul>';
      }
      h += secao('empAtencao', 'Atrasos e pendências', '', '', '<div class="amb-duas">' + cartao('Atrasos', linhas(atrasos)) + cartao('Pendências', linhas(pend)) + '</div>');
      if (CFG.admin) h += '<div id="ambDrive"></div>';
      h += cartao('Como os números são contados',
        '<p class="amb-nota">O Comercial usa os mesmos números do painel comercial, no mês escolhido. Cada cliente conta uma vez no total, mesmo quando contrata Software e Digital. ' +
        'A receita recorrente soma cada linha de valor do cliente uma vez, na área da linha; "previsto" são as cobranças que vencem no mês e "recebido" as pagas no mês. As vendas seguem a regra do painel comercial. ' +
        'Entregas contam cartões marcados como entrega; item de checklist nunca conta. "Postada" é a entrega com publicação marcada como publicada. O ritmo compara as concluídas com o contratado proporcional aos dias do mês. OTIF é a parte das tarefas com prazo entregues no prazo e completas.</p>');
      app.innerHTML = h;
      desenharGraficos(G);
      if (CFG.admin) driveCartao(document.getElementById('ambDrive'));
    });
  }

  // Conexão da conta Google (só administrador): o Drive de cada cliente depende dela.
  function driveCartao(el) {
    if (!el) return;
    pedir('/api/drive.php?estado=1').then(function (r) {
      if (!r || !r.ok) return;
      var d = r.data, h = '';
      if (d.aviso) h += '<div class="ctr-aviso ' + (d.aviso.ok ? 'verde' : 'vermelho') + '" role="status">' + esc(d.aviso.msg) + '</div>';
      if (!d.configurado) h += '<p class="amb-nota">O servidor ainda não tem as credenciais do Google (GOOGLE_DRIVE_CLIENT_ID e GOOGLE_DRIVE_CLIENT_SECRET). Depois de configuradas, conecte aqui a conta corporativa.</p>';
      else if (!d.conectado) h += '<p class="amb-nota">Nenhuma conta Google conectada' + (d.dominio ? ' (só @' + esc(d.dominio) + ')' : '') + '. Conecte a conta corporativa para ligar a pasta de cada cliente.</p>' +
        '<a class="ctr-btn ctr-btn-principal" href="/api/drive_oauth.php?iniciar=1&volta=%2Fempresa">Conectar conta Google</a>';
      else h += '<p class="amb-nota">Conectado como <b>' + esc(d.email) + '</b> desde ' + esc(data(d.conectado_em)) + '. ' +
        (d.ultima_sync ? 'Última sincronização automática: ' + esc(data(d.ultima_sync)) + '. ' : 'A sincronização automática ainda não rodou. ') +
        (d.erro ? '<span class="amb-verm">Último erro: ' + esc(d.erro) + '</span>' : '') + '</p><button type="button" class="ctr-btn ctr-btn-perigo" id="ambDriveSair">Desconectar</button>';
      el.innerHTML = cartao('Google Drive', h);
      var sair = document.getElementById('ambDriveSair');
      if (sair) sair.onclick = function () {
        confirmar('Desconectar o Google Drive', 'As pastas ligadas aos clientes continuam registradas, mas ninguém verá os arquivos até conectar de novo.', 'Desconectar').then(function (sim) {
          if (!sim) return;
          pedir('/api/drive.php', { acao: 'desconectar' }).then(function (x) { if (!x.ok) return toast(x.error || 'Não foi possível desconectar.', 'erro'); driveCartao(el); });
        });
      };
    });
  }

  // ── Software ───────────────────────────────────────────────────────────
  var MODELO = { demandas: 'Demandas', suporte: 'Suporte', etapas: 'Etapas', episodios: 'Episódios', campanhas: 'Campanhas', livre: 'Livre' };
  function software() {
    if (acoes) acoes.innerHTML = CFG.pode.tarefas ? '<a class="ctr-btn ctr-btn-principal" href="/tarefas?amb=software">Abrir quadros</a>' : '';
    pedir('/api/ambientes.php?visao=software').then(function (r) {
      if (!r.ok) return erro(r.error || 'Não foi possível carregar a Visão Geral.');
      var d = r.data, t = d.tarefas, S = d.series, G = [], ids = { sit: gid(), resp: gid(), fluxo: gid(), quadros: gid(), prio: gid(), pessoas: gid() };
      var porSit = {}, resp = {};
      d.carteira.forEach(function (p) { porSit[p.status] = (porSit[p.status] || 0) + 1; var rr = p.responsavel || 'Sem responsável'; resp[rr] = (resp[rr] || 0) + 1; });
      var concl8 = S.concluidas.reduce(function (a, b) { return a + b; }, 0), cri8 = S.criadas.reduce(function (a, b) { return a + b; }, 0);
      var h = tiles([
        tile('Projetos em andamento', num(d.carteira.length), Object.keys(porSit).map(function (k) { return num(porSit[k]) + ' ' + (SIT_PROJ[k] || k).toLowerCase(); }).join(' · ')),
        tile('Quadros', num(d.quadros.length), 'demandas e suporte'),
        tile('Tarefas abertas', num(t.abertas), num(t.sem_responsavel) + ' sem responsável · ' + num(d.sem_prazo) + ' sem prazo'),
        tile('Atrasadas', num(t.atrasadas), t.abertas ? Math.round(t.atrasadas / t.abertas * 100) + '% das abertas' : '', t.atrasadas ? 'alerta' : ''),
        tile('Suporte em aberto', num(t.suporte_abertos), 'chamados nos quadros de suporte'),
        tile('Concluídas em 8 semanas', num(concl8), num(cri8) + ' criadas no período', cri8 > concl8 ? 'atencao' : '')
      ]);
      h += '<div class="amb-grade-3"><div class="amb-span2">' + grafCartao('Fluxo de tarefas por semana', ids.fluxo, 250, 'criadas x concluídas nos quadros de Software') + '</div>' + grafCartao('Tarefas abertas por prioridade', ids.prio, 250) + '</div>';
      G.push({ id: ids.fluxo, cfg: { type: 'bar', data: { labels: S.semanas, datasets: [
        { label: 'Criadas', data: S.criadas, backgroundColor: COR.media, borderRadius: 6 },
        { label: 'Concluídas', data: S.concluidas, backgroundColor: COR.marca, borderRadius: 6 } ] },
        options: { interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: {} } } } });
      var pr = d.por_prioridade, PRIO = { urgente: 'Urgente', alta: 'Alta', media: 'Média', baixa: 'Baixa' }, pk = Object.keys(PRIO);
      G.push(t.abertas ? rosca(ids.prio, pk.map(function (k) { return PRIO[k]; }), pk.map(function (k) { return pr[k] || 0; }), [COR.vermelho, COR.laranja, COR.marca, COR.cinza], [num(t.abertas), 'abertas'])
        : rosca(ids.prio, ['Nenhuma aberta'], [1], [escuro() ? '#2A3A55' : '#E5E9F0'], ['0', 'abertas']));
      var qs = d.quadros.slice().sort(function (a, b) { return b.abertas - a.abertas; }).slice(0, 12);
      var respK = Object.keys(resp).sort(function (a, b) { return resp[b] - resp[a]; }).slice(0, 10), sitK = Object.keys(porSit);
      h += '<div class="amb-grade-3">' + grafCartao('Projetos por situação', ids.sit, 240) + grafCartao('Projetos por responsável', ids.resp, 240) + grafCartao('Tarefas abertas por quadro', ids.quadros, 240, 'abertas e atrasadas') + '</div>';
      G.push(sitK.length ? rosca(ids.sit, sitK.map(function (k) { return SIT_PROJ[k] || k; }), sitK.map(function (k) { return porSit[k]; }), [COR.marca, COR.verde, COR.ambar], [num(d.carteira.length), 'projetos'])
        : rosca(ids.sit, ['Nenhum projeto'], [1], [escuro() ? '#2A3A55' : '#E5E9F0'], ['0', 'projetos']));
      G.push({ id: ids.resp, cfg: { type: 'bar', data: { labels: respK, datasets: [{ label: 'Projetos', data: respK.map(function (k) { return resp[k]; }), backgroundColor: COR.marca, borderRadius: 6 }] },
        options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: {} } } } });
      G.push({ id: ids.quadros, cfg: { type: 'bar', data: { labels: qs.map(function (q) { return (q.cliente ? q.cliente + ' · ' : '') + q.nome; }), datasets: [
        { label: 'Abertas', data: qs.map(function (q) { return q.abertas; }), backgroundColor: COR.marca, borderRadius: 6 },
        { label: 'Atrasadas', data: qs.map(function (q) { return q.atrasadas; }), backgroundColor: COR.vermelho, borderRadius: 6 } ] },
        options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: {} } } } });
      var pes = d.responsaveis.slice(0, 12);
      h += '<div class="amb-grade-3"><div class="amb-span2">' + grafCartao('Tarefas por pessoa', ids.pessoas, Math.max(220, 32 * pes.length + 70), 'abertas e atrasadas agora') + '</div>' + cartao('Próximos prazos (14 dias)', proximos(d.proximos, 'software')) + '</div>';
      G.push({ id: ids.pessoas, cfg: { type: 'bar', data: { labels: pes.map(function (p) { return p.nome; }), datasets: [
        { label: 'Abertas', data: pes.map(function (p) { return p.abertas; }), backgroundColor: COR.marca, borderRadius: 6 },
        { label: 'Atrasadas', data: pes.map(function (p) { return p.atrasadas; }), backgroundColor: COR.vermelho, borderRadius: 6 } ] },
        options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: {} } } } });
      var carteira = d.carteira.length ? '<table class="amb-tab"><thead><tr><th>Projeto</th><th>Cliente</th><th>Situação</th><th>Responsável</th></tr></thead><tbody>' +
        d.carteira.map(function (p) {
          return '<tr><td><a href="/projetos?amb=software&p=' + encodeURIComponent(p.slug) + '">' + esc(p.nome) + '</a></td><td>' + esc(p.cliente || '-') + '</td><td><span class="amb-pill">' + esc(SIT_PROJ[p.status] || p.status) + '</span></td><td>' + esc(p.responsavel || 'Sem responsável') + '</td></tr>';
        }).join('') + '</tbody></table>' : vazio('Nenhum projeto de Software em andamento na carteira.');
      h += cartao('Projetos em andamento', carteira);
      app.innerHTML = h;
      desenharGraficos(G);
    });
  }

  // ── Digital ────────────────────────────────────────────────────────────
  function previsto(c) {
    var tipos = Object.keys(c.por_tipo || {});
    if (!tipos.length) return '<span class="amb-cinza">Sem frequência contratada</span>';
    return tipos.map(function (k) {
      var x = c.por_tipo[k];
      return '<span class="amb-prev"><b>' + esc(x.rotulo) + '</b> ' + num(x.entregues) + (x.previsto ? '/' + num(x.previsto) : '') + (x.pendentes ? ' <em>' + num(x.pendentes) + ' pendente' + (x.pendentes > 1 ? 's' : '') + '</em>' : '') + '</span>';
    }).join('');
  }
  function digital() {
    seletorMes();
    pedir('/api/ambientes.php?visao=digital&mes=' + mes).then(function (r) {
      if (!r.ok) return erro(r.error || 'Não foi possível carregar a Visão Geral.');
      var d = r.data, t = d.total, S = d.series, G = [], ids = { evo: gid(), sit: gid(), cli: gid(), tipo: gid(), post: gid(), fluxo: gid(), pessoas: gid() };
      var h = tiles([
        tile('Contratadas', num(t.contratadas), num(t.esperado_ate_hoje) + ' esperadas até hoje'),
        tile('Concluídas', num(t.concluidas), t.contratadas ? Math.round(t.concluidas / t.contratadas * 100) + '% do contratado' : num(t.cadastradas) + ' cadastradas'),
        tile('Postadas', num(t.publicadas), num(t.agendadas) + ' agendada(s) · ' + num(t.a_publicar) + ' a postar', t.a_publicar ? 'atencao' : ''),
        tile('Em produção', num(t.producao)), tile('Aguardando aprovação', num(t.aprovacao), 'do cliente', t.aprovacao ? 'atencao' : ''),
        tile('Atrasadas', num(t.atrasadas), 'prazo vencido sem entregar', t.atrasadas ? 'alerta' : ''),
        tile('Sem cartão', num(t.nao_cadastradas), 'contratadas e não cadastradas', t.nao_cadastradas ? 'atencao' : ''),
        tile('Campanhas ativas', num(t.campanhas_ativas)), tile('Materiais faltando', num(t.materiais_faltando))
      ]);
      h += '<div class="amb-grade-3"><div class="amb-span2">' + grafCartao('Entregas por mês, 6 meses', ids.evo, 250, 'contratadas, concluídas e postadas') + '</div>' + grafCartao('Situação das entregas de ' + mesRotulo(d.mes), ids.sit, 250) + '</div>';
      G.push({ id: ids.evo, cfg: { type: 'bar', data: { labels: S.meses6, datasets: [
        { label: 'Contratadas', data: S.contratadas, backgroundColor: COR.media, borderRadius: 6 },
        { label: 'Concluídas', data: S.concluidas, backgroundColor: COR.marca, borderRadius: 6 },
        { label: 'Postadas', data: S.publicadas, backgroundColor: COR.verde, borderRadius: 6 } ] },
        options: { interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: {} } } } });
      G.push(rosca(ids.sit, ['Concluídas', 'Aguardando aprovação', 'Em produção', 'Sem cartão'], [t.concluidas, t.aprovacao, t.producao, t.nao_cadastradas], [COR.verde, COR.ambar, COR.marca, COR.cinza], [num(t.contratadas || t.cadastradas), 'no mês']));
      var cls = d.clientes.slice().sort(function (a, b) { return (b.contratadas || b.cadastradas) - (a.contratadas || a.cadastradas); });
      var tipos = d.por_tipo || [];
      h += '<div class="amb-grade-3"><div class="amb-span2">' + grafCartao('Por cliente: contratado, concluído e postado', ids.cli, Math.max(220, 34 * cls.length + 70)) + '</div>' + grafCartao('Por tipo de entrega', ids.tipo, Math.max(220, 34 * tipos.length + 70), 'previsto x entregue x postado') + '</div>';
      G.push({ id: ids.cli, cfg: { type: 'bar', data: { labels: cls.map(function (c) { return c.nome; }), datasets: [
        { label: 'Contratadas', data: cls.map(function (c) { return c.contratadas; }), backgroundColor: COR.media, borderRadius: 6 },
        { label: 'Concluídas', data: cls.map(function (c) { return c.concluidas; }), backgroundColor: COR.marca, borderRadius: 6 },
        { label: 'Postadas', data: cls.map(function (c) { return c.publicadas; }), backgroundColor: COR.verde, borderRadius: 6 },
        { label: 'Atrasadas', data: cls.map(function (c) { return c.atrasadas; }), backgroundColor: COR.vermelho, borderRadius: 6 } ] },
        options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: {} } } } });
      G.push({ id: ids.tipo, cfg: { type: 'bar', data: { labels: tipos.map(function (x) { return x.rotulo; }), datasets: [
        { label: 'Previsto', data: tipos.map(function (x) { return x.previsto; }), backgroundColor: COR.media, borderRadius: 6 },
        { label: 'Entregues', data: tipos.map(function (x) { return x.entregues; }), backgroundColor: COR.marca, borderRadius: 6 },
        { label: 'Postados', data: tipos.map(function (x) { return x.publicados; }), backgroundColor: COR.verde, borderRadius: 6 } ] },
        options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: {} } } } });
      var pes = d.responsaveis.slice(0, 12), F = S.fluxo;
      h += '<div class="amb-grade-3">' + grafCartao('Fluxo de cartões por semana', ids.fluxo, 240, 'criados x concluídos nos quadros de Digital') + grafCartao('Cartões por pessoa', ids.pessoas, 240, 'abertos e atrasados agora') + cartao('Próximos prazos (14 dias)', proximos(d.proximos, 'digital')) + '</div>';
      G.push({ id: ids.fluxo, cfg: { type: 'bar', data: { labels: F.semanas, datasets: [
        { label: 'Criados', data: F.criadas, backgroundColor: COR.media, borderRadius: 6 },
        { label: 'Concluídos', data: F.concluidas, backgroundColor: COR.marca, borderRadius: 6 } ] },
        options: { interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: {} } } } });
      G.push({ id: ids.pessoas, cfg: { type: 'bar', data: { labels: pes.map(function (p) { return p.nome; }), datasets: [
        { label: 'Abertos', data: pes.map(function (p) { return p.abertas; }), backgroundColor: COR.marca, borderRadius: 6 },
        { label: 'Atrasados', data: pes.map(function (p) { return p.atrasadas; }), backgroundColor: COR.vermelho, borderRadius: 6 } ] },
        options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: {} } } } });
      var linhas = cls.length ? '<table class="amb-tab amb-tab-cli"><thead><tr><th>Cliente</th><th>Postagem no mês</th><th>Previsto x realizado</th><th>Ritmo</th><th>Aprovação</th><th>Atrasadas</th><th>Quadros</th></tr></thead><tbody>' +
        cls.map(function (c) {
          var ritmo = c.ritmo === 'atrasado' ? '<span class="amb-pill amb-pill-verm">Atrasado: ' + num(c.concluidas) + ' de ' + num(c.esperado_ate_hoje) + ' esperadas</span>' : c.ritmo === 'em_dia' ? '<span class="amb-pill amb-pill-ok">Em dia</span>' : '<span class="amb-pill">Sem frequência</span>';
          return '<tr><td><a href="/clientes?amb=digital&open=' + c.id + '">' + esc(c.nome) + '</a>' + (c.campanhas_ativas ? '<small class="amb-cinza">' + num(c.campanhas_ativas) + ' campanha(s) ativa(s)</small>' : '') + (c.contratadas && !c.publicadas && !c.concluidas ? '<small class="amb-verm">Nada postado no mês</small>' : '') + '</td>' +
            '<td><b>' + num(c.publicadas) + '</b> postada(s)<small class="amb-cinza">' + num(c.agendadas) + ' agendada(s) · ' + num(c.a_publicar) + ' a postar</small></td>' +
            '<td>' + previsto(c) + '</td><td>' + ritmo + '</td><td' + (c.aprovacao ? ' class="amb-amb"' : '') + '>' + num(c.aprovacao) + '</td>' +
            '<td' + (c.atrasadas ? ' class="amb-verm"' : '') + '>' + num(c.atrasadas) + '</td>' +
            '<td>' + (c.quadros.length ? c.quadros.map(function (q) { return '<a class="amb-pill" href="/tarefas?amb=digital&board=' + q.id + '">' + esc(q.nome) + '</a>'; }).join(' ') : '<a class="amb-cinza" href="/tarefas?amb=digital">Criar quadro</a>') + '</td></tr>';
        }).join('') + '</tbody></table>'
        : vazio('Nenhum cliente de Digital ainda. Marque a área na ficha do cliente ou cadastre frequência, campanha ou quadro de produção.');
      h += cartao('Clientes em ' + mesRotulo(d.mes), linhas);
      app.innerHTML = h;
      desenharGraficos(G);
    });
  }

  // ── Entregas ───────────────────────────────────────────────────────────
  function entregas() {
    seletorMes();
    pedir('/api/digital.php?entregas=1&mes=' + mes).then(function (r) {
      if (!r.ok) return erro(r.error || 'Não foi possível carregar as entregas.');
      var l = r.data, filtro = 'todas';
      var cont = { todas: l.length, producao: 0, aprovacao: 0, concluida: 0, atrasada: 0 };
      l.forEach(function (e) { cont[e.situacao]++; if (e.atrasada) cont.atrasada++; });
      function desenhar() {
        var vis = l.filter(function (e) { return filtro === 'todas' || (filtro === 'atrasada' ? e.atrasada : e.situacao === filtro); });
        var chips = [['todas', 'Todas'], ['producao', 'Em produção'], ['aprovacao', 'Aguardando aprovação'], ['concluida', 'Concluídas'], ['atrasada', 'Atrasadas']].map(function (c) {
          return '<button type="button" class="ctr-aba' + (filtro === c[0] ? ' ativa' : '') + '" data-f="' + c[0] + '">' + c[1] + ' (' + num(cont[c[0]]) + ')</button>';
        }).join('');
        var tab = vis.length ? '<table class="amb-tab"><thead><tr><th>Cliente</th><th>Entrega</th><th>Produção</th><th>Aprovação</th><th>Entrega</th><th>Publicação</th><th>Prazo</th><th>Responsável</th></tr></thead><tbody>' +
          vis.map(function (e) {
            var cls = { producao: '', aprovacao: ' amb-pill-amb', concluida: ' amb-pill-ok' };
            return '<tr><td>' + esc(e.cliente || '-') + '</td><td><a href="/tarefas?amb=digital&board=' + e.board_id + '&tarefa=' + e.task_id + '">' + esc(e.titulo) + '</a><small class="amb-cinza">' + esc(e.tipo_rotulo) + ' · ' + esc(e.quadro) + '</small></td>' +
              '<td>' + esc(e.producao_rotulo) + '</td><td><span class="amb-pill' + (e.aprovacao === 'aguardando' ? ' amb-pill-amb' : e.aprovacao === 'aprovado' ? ' amb-pill-ok' : e.aprovacao === 'ajustes' ? ' amb-pill-verm' : '') + '">' + esc(e.aprovacao_rotulo) + '</span></td>' +
              '<td><span class="amb-pill' + (cls[e.situacao] || '') + '">' + esc(e.entrega_rotulo) + '</span></td><td>' + esc(e.publicacao_rotulo) + '</td>' +
              '<td' + (e.atrasada ? ' class="amb-verm"' : '') + '>' + esc(data(e.prazo) || '-') + (e.atrasada ? ' (atrasada)' : '') + '</td><td>' + esc(e.responsavel || '-') + '</td></tr>';
          }).join('') + '</tbody></table>' : vazio(l.length ? 'Nenhuma entrega neste filtro.' : 'Nenhuma entrega com competência em ' + mesRotulo(mes) + '. Marque o tipo de entrega na aba "Entrega" do cartão.');
        app.innerHTML = '<div class="ctr-filtros">' + chips + '</div>' + cartao('Entregas de ' + mesRotulo(mes), tab);
        app.querySelectorAll('[data-f]').forEach(function (b) { b.onclick = function () { filtro = b.dataset.f; desenhar(); }; });
      }
      desenhar();
    });
  }

  // ── Campanhas ──────────────────────────────────────────────────────────
  function carregarApoio() {
    return Promise.all([
      apoio ? Promise.resolve() : pedir('/api/digital.php?apoio=1').then(function (r) { apoio = r.ok ? r.data : { plataformas: {}, status_campanha: {}, equipe: [] }; }),
      clientes ? Promise.resolve() : pedir('/api/digital.php?clientes=1').then(function (r) { clientes = r.ok ? r.data : []; })
    ]);
  }
  function opts(mapa, atual) { return Object.keys(mapa).map(function (k) { return '<option value="' + k + '"' + (k === atual ? ' selected' : '') + '>' + esc(mapa[k]) + '</option>'; }).join(''); }
  function formCampanha(k, aoSalvar) {
    k = k || { status: 'planejada', plataforma: 'meta' };
    var f = document.createElement('div');
    f.className = 'amb-guarda-fundo ctr-overlay';
    f.innerHTML = '<form class="amb-guarda amb-form" data-amb-guarda novalidate><h3>' + (k.id ? 'Editar campanha' : 'Nova campanha') + '</h3>' +
      '<div class="amb-form-grade">' +
      '<label class="amb-c2">Cliente<select name="cliente_id" required><option value="">Escolha</option>' + clientes.map(function (c) { return '<option value="' + c.id + '"' + (c.id === k.cliente_id ? ' selected' : '') + '>' + esc(c.nome) + '</option>'; }).join('') + '</select></label>' +
      '<label class="amb-c2">Nome<input name="nome" maxlength="160" required value="' + esc(k.nome || '') + '" placeholder="Ex.: Black Friday 2026"></label>' +
      '<label>Plataforma<select name="plataforma">' + opts(apoio.plataformas, k.plataforma) + '</select></label>' +
      '<label>Situação<select name="status">' + opts(apoio.status_campanha, k.status) + '</select></label>' +
      '<label>Verba mensal (R$)<input name="verba_mensal" inputmode="decimal" value="' + (k.verba_mensal != null ? String(k.verba_mensal).replace('.', ',') : '') + '"></label>' +
      '<label>Responsável<select name="responsavel_id"><option value="">Sem responsável</option>' + apoio.equipe.map(function (u) { return '<option value="' + u.id + '"' + (u.id === k.responsavel_id ? ' selected' : '') + '>' + esc(u.nome) + '</option>'; }).join('') + '</select></label>' +
      '<label>Início<input type="date" name="inicio" value="' + esc(k.inicio || '') + '"></label>' +
      '<label>Fim<input type="date" name="fim" value="' + esc(k.fim || '') + '"></label>' +
      '<label class="amb-c2">Objetivo<input name="objetivo" maxlength="160" value="' + esc(k.objetivo || '') + '" placeholder="Ex.: leads para a loja"></label>' +
      '<label class="amb-c2">Link do gerenciador<input name="link" value="' + esc(k.link || '') + '" placeholder="https://..."></label>' +
      '<label class="amb-c2">Observação<input name="observacao" maxlength="500" value="' + esc(k.observacao || '') + '"></label>' +
      '</div><div class="ctr-erro" data-erro role="alert"></div>' +
      '<div class="amb-guarda-acoes"><button type="button" data-cancelar>Cancelar</button><button type="submit" class="principal">Salvar</button></div></form>';
    document.body.appendChild(f);
    var form = f.querySelector('form');
    form.querySelector('[name="cliente_id"]').focus();
    function fechar() { f.remove(); }
    f.querySelector('[data-cancelar]').onclick = fechar;
    form.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });
    form.onsubmit = function (e) {
      e.preventDefault();
      var corpo = { acao: 'campanha_salvar', id: k.id || 0 };
      new FormData(form).forEach(function (v, n) { corpo[n] = v; });
      corpo.cliente_id = +corpo.cliente_id || 0; corpo.responsavel_id = +corpo.responsavel_id || null;
      var b = form.querySelector('[type="submit"]'); b.disabled = true;
      pedir('/api/digital.php', corpo).then(function (r) {
        b.disabled = false;
        if (!r.ok) { form.querySelector('[data-erro]').textContent = r.error || 'Não foi possível salvar.'; return; }
        fechar(); toast('Campanha salva.'); aoSalvar();
      });
    };
  }
  function campanhas() {
    if (acoes) acoes.innerHTML = '<button type="button" class="ctr-btn ctr-btn-principal" id="ambNovaCampanha">Nova campanha</button>';
    var filtro = '';
    function carregar() {
      pedir('/api/digital.php?campanhas=1' + (filtro ? '&status=' + filtro : '')).then(function (r) {
        if (!r.ok) return erro(r.error || 'Não foi possível carregar as campanhas.');
        carregarApoio().then(function () {
          var l = r.data;
          var chips = [['', 'Todas']].concat(Object.keys(apoio.status_campanha).map(function (k) { return [k, apoio.status_campanha[k]]; })).map(function (c) {
            return '<button type="button" class="ctr-aba' + (filtro === c[0] ? ' ativa' : '') + '" data-f="' + c[0] + '">' + esc(c[1]) + '</button>';
          }).join('');
          var tab = l.length ? '<table class="amb-tab"><thead><tr><th>Campanha</th><th>Cliente</th><th>Plataforma</th><th>Verba mensal</th><th>Período</th><th>Situação</th><th>Responsável</th><th></th></tr></thead><tbody>' +
            l.map(function (k) {
              return '<tr><td><b>' + esc(k.nome) + '</b>' + (k.objetivo ? '<small class="amb-cinza">' + esc(k.objetivo) + '</small>' : '') + '</td><td>' + esc(k.cliente) + '</td><td>' + esc(k.plataforma_rotulo) + '</td>' +
                '<td>' + (k.verba_mensal != null ? brl(k.verba_mensal) : '-') + '</td><td>' + esc((data(k.inicio) || '?') + (k.fim ? ' a ' + data(k.fim) : '')) + '</td>' +
                '<td><span class="amb-pill' + (k.status === 'ativa' ? ' amb-pill-ok' : k.status === 'pausada' ? ' amb-pill-amb' : '') + '">' + esc(k.status_rotulo) + '</span></td><td>' + esc(k.responsavel || '-') + '</td>' +
                '<td class="amb-acoes-linha">' + (k.link ? '<a class="ctr-btn ctr-btn-sm" href="' + esc(k.link) + '" target="_blank" rel="noopener noreferrer">Gerenciador</a>' : '') +
                '<button type="button" class="ctr-btn ctr-btn-sm" data-editar="' + k.id + '">Editar</button><button type="button" class="ctr-btn ctr-btn-sm ctr-btn-perigo" data-remover="' + k.id + '">Remover</button></td></tr>';
            }).join('') + '</tbody></table>' : vazio('Nenhuma campanha' + (filtro ? ' nesta situação.' : '. Cadastre a primeira com "Nova campanha".'));
          app.innerHTML = '<div class="ctr-filtros">' + chips + '</div>' + cartao('Campanhas de tráfego pago', tab);
          app.querySelectorAll('[data-f]').forEach(function (b) { b.onclick = function () { filtro = b.dataset.f; carregar(); }; });
          app.querySelectorAll('[data-editar]').forEach(function (b) { b.onclick = function () { formCampanha(l.filter(function (k) { return k.id === +b.dataset.editar; })[0], carregar); }; });
          app.querySelectorAll('[data-remover]').forEach(function (b) {
            b.onclick = function () {
              var k = l.filter(function (x) { return x.id === +b.dataset.remover; })[0];
              confirmar('Remover campanha', 'Remover "' + k.nome + '"? O cadastro sai do sistema; nada muda na plataforma de anúncios.', 'Remover').then(function (sim) {
                if (!sim) return;
                pedir('/api/digital.php', { acao: 'campanha_remover', id: k.id }).then(function (x) { if (!x.ok) return toast(x.error || 'Não foi possível remover.', 'erro'); toast('Campanha removida.'); carregar(); });
              });
            };
          });
        });
      });
    }
    var bn = document.getElementById('ambNovaCampanha');
    if (bn) bn.onclick = function () { carregarApoio().then(function () { formCampanha(null, carregar); }); };
    carregar();
  }

  function abrir() {
    if (CFG.ambiente === 'digital' && CFG.ver === 'entregas') return entregas();
    if (CFG.ambiente === 'digital' && CFG.ver === 'campanhas') return campanhas();
    if (CFG.ambiente === 'empresa') return empresa();
    if (CFG.ambiente === 'software') return software();
    if (CFG.ambiente === 'digital') return digital();
  }
  abrir();
})();
