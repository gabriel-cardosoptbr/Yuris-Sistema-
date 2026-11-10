/* ═══════════════════════════════════════════════════════════════════════════
   interface-nova-cartoes.js: todo cartão de indicador faz alguma coisa
   (interface nova, prévia, 10/11/2026).

   "Todo card tem que ser interativo. Eu tenho que clicar e ir para a aba; se
   já for dentro da aba, tem que ser, por exemplo, um filtro." Nenhum dos cerca
   de 60 cartões das telas fazia nada ao clique. Aqui, por tela, o rótulo do
   cartão diz o que ele faz:
     - ir: leva para a tela do assunto (a mesma saída do menu, com a tela nova
       pedida no aperto: um <a class="inn-cartao-link"> cobre o cartão, e o
       menu-fixo.js e a regra speculationrules do sidebar.php cuidam dele);
       com ?kpi=..., a tela de destino aplica o filtro daquele número;
     - filtro: aplica o filtro da própria tela (o mesmo campo que a pessoa
       usaria, com os mesmos eventos); clicar de novo desfaz;
     - secao: rola até a parte da tela que explica aquele número e a destaca.
   O cartão que está filtrando fica marcado (inn-cartao-on).
   Carregado por includes/sidebar.php nas telas de dentro, fora do ?embed=1.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var html = document.documentElement;
  if (html.classList.contains('yuris-embed') || /[?&]embed=1(&|$)/.test(location.search)) return;
  var busca = new URLSearchParams(location.search);
  var tela = location.pathname.replace(/^\/+/, '').replace(/\.php$/, '').split('/')[0] || 'dashboard';
  if (tela === 'dashboard' && busca.get('gestao') === '1') tela = 'gestao';

  function norm(t) { return String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim(); }
  function $(s) { return document.querySelector(s); }
  // Muda o campo como a pessoa mudaria: valor, depois input e change (as telas ouvem um ou outro).
  function muda(sel, v) {
    var el = typeof sel === 'string' ? $(sel) : sel; if (!el) return false;
    el.value = v;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }
  function dia(desloc) {
    var x = new Date(); x.setDate(x.getDate() + (desloc || 0));
    return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0');
  }
  function clica(sel) { var b = $(sel); if (b) b.click(); return !!b; }

  // O painel em volta de um elemento (fundo próprio, moldura e cantos), para rolar até ele e destacar.
  function painelDe(el) {
    for (var n = el; n && n !== document.body; n = n.parentElement) {
      var cs = getComputedStyle(n);
      if (parseFloat(cs.borderTopWidth) > 0 && parseFloat(cs.borderTopLeftRadius) >= 8 && n.getBoundingClientRect().width > 300) return n;
    }
    return el;
  }
  function destacar(el) {
    el.classList.remove('inn-destaque'); void el.offsetWidth; el.classList.add('inn-destaque');
    el.addEventListener('animationend', function f(e) { if (e.target === el && e.animationName === 'inn-destaque') { el.classList.remove('inn-destaque'); el.removeEventListener('animationend', f); } });
  }
  // Leva até o painel e o destaca; se ele já está inteiro à vista, só destaca.
  function mostrar(el) {
    if (!el) return false;
    var p = painelDe(el), r = p.getBoundingClientRect();
    if (r.top < 0 || r.top > innerHeight * .6) p.scrollIntoView({ behavior: 'smooth', block: 'start' });
    destacar(p);
    return true;
  }
  // O título da seção, pelo começo do texto (sem acento e sem caixa).
  function titulo(t) {
    var alvo = norm(t), achado = null;
    [].some.call(document.querySelectorAll('h1, h2, h3, h4, .section-eyebrow, [class*="title"], [class*="titulo"], [class*="-t"]'), function (h) {
      if (h.closest('.sidebar, [data-inn-kpi], .kpi-card') || !h.getBoundingClientRect().height) return false;
      if (norm(h.textContent).indexOf(alvo) === 0) { achado = h; return true; }
      return false;
    });
    return achado;
  }
  function secao(t) { return function () { return mostrar(titulo(t)); }; }
  // O valor de destaque do cartão (o nome do "Último cadastro").
  function valorDoCartao(c) {
    var v = c.querySelector('.kpi-value, [class*="valor"], [class*="value"], strong, b');
    return v ? v.textContent.trim() : '';
  }

  function limparUsuarios() { muda('#filterPerfil', ''); muda('#filterStatus', ''); muda('#searchUsers', ''); }

  // ── O que cada cartão faz, por tela (a chave é o começo do rótulo) ──────
  var ACOES = {
    gestao: {
      'contratos fechados': { ir: '/financas?kpi=honorarios recebidos' },
      'meta mensal': { ir: '/planejamento?kpi=meta mensal' },
      'receita projetada': { ir: '/planejamento?kpi=contratos necessarios' },
      'oportunidades': { ir: '/prospeccao' },
      'clientes ativos': { ir: '/clientes' },
      'receita operacional': { ir: '/financas?kpi=receita operacional' },
      'custos operacionais': { ir: '/financas?kpi=custos operacionais' },
      'lucro liquido': { ir: '/financas?kpi=lucro liquido real' },
      'margem de lucro': { ir: '/financas?kpi=margem liquida real' },
      'processos ativos': { ir: '/processos?status=ativos' },
      'prazos hoje': { ir: '/processos?prazo=hoje' },
      'esta semana': { ir: '/processos?prazo=semana' },
      'urgentes': { ir: '/processos?prazo=urgentes' }
    },
    juridico: {
      'carteira ativa': { ir: '/processos?status=ativos' },
      'encerrados': { ir: '/processos?kpi=encerrados' },
      'novos este mes': { ir: '/processos' },
      'vencidos': { ir: '/processos?kpi=vencidos' },
      'sem movimentacao': { ir: '/processos' },
      'advogados ativos': { ir: '/usuarios' }
    },
    processos: {
      // Os de prazo e o de ativos usam o filtro da própria tela pelo endereço (a mesma conta do
      // cartão, todos os anos); com ele ligado, o cartão volta para a tela sem filtro.
      'processos ativos': { ir: '/processos?status=ativos', ligado: busca.get('status') === 'ativos' },
      'vencem hoje': { ir: '/processos?prazo=hoje', ligado: busca.get('prazo') === 'hoje' },
      'esta semana': { ir: '/processos?prazo=semana', ligado: busca.get('prazo') === 'semana' },
      'urgentes': { ir: '/processos?prazo=urgentes', ligado: busca.get('prazo') === 'urgentes' },
      'vencidos': { filtro: function () { muda('#procFilterStatus', 'ativo'); muda('#procFilterDateFrom', ''); muda('#procFilterDateTo', dia(-1)); },
                    desfaz: function () { muda('#procFilterStatus', ''); muda('#procFilterDateTo', ''); } },
      'encerrados': { filtro: function () { muda('#procFilterDateFrom', ''); muda('#procFilterDateTo', ''); muda('#procFilterStatus', 'encerrado'); },
                      desfaz: function () { muda('#procFilterStatus', ''); } }
    },
    usuarios: {
      'total de usuarios': { filtro: limparUsuarios, sempre: true, mostra: 'equipe do escritorio' },
      'ativos': { filtro: function () { limparUsuarios(); muda('#filterStatus', 'active'); }, desfaz: limparUsuarios, mostra: 'equipe do escritorio' },
      'administradores': { filtro: function () { limparUsuarios(); muda('#filterPerfil', 'admin'); }, desfaz: limparUsuarios, mostra: 'equipe do escritorio' },
      'usuarios': { filtro: function () { limparUsuarios(); muda('#filterPerfil', 'user'); }, desfaz: limparUsuarios, mostra: 'equipe do escritorio' },
      'ultimo cadastro': { filtro: function (c) { limparUsuarios(); muda('#searchUsers', valorDoCartao(c)); }, desfaz: limparUsuarios, mostra: 'equipe do escritorio' }
    },
    financas: {
      'receita operacional': { filtro: function () { muda('#filter_tipo_extra', 'receita'); }, desfaz: function () { muda('#filter_tipo_extra', 'all'); }, mostra: 'filtros' },
      'custos operacionais': { filtro: function () { muda('#filter_tipo_extra', 'despesa'); }, desfaz: function () { muda('#filter_tipo_extra', 'all'); }, mostra: 'filtros' },
      'total em impostos': { secao: 'impostos sobre a receita' },
      'lucro liquido real': { secao: 'receita × despesa × lucro' },
      'margem liquida real': { secao: 'dre' },
      'honorarios recebidos': { secao: 'composicao da receita' },
      'lancamentos extras': { filtro: function () { muda('#filter_tipo_extra', 'all'); }, sempre: true, mostra: 'filtros' }
    },
    planejamento: {
      'meta mensal': { foco: '#meta' },
      'honorario medio': { foco: '#ticket' },
      'contratos necessarios': { secao: 'resultado esperado' },
      'leads qualificados': { secao: 'comparativo de funis' },
      'consultas por dia': { secao: 'resultado esperado' },
      'contratos por responsavel': { secao: 'resultado esperado' }
    },
    desempenho: {
      'otif': { secao: 'entregas no periodo' },
      'no prazo': { secao: 'pontos de atencao' },
      'completas': { secao: 'entregas no periodo' },
      'compromissos': { secao: 'tarefas abertas agora' }
    },
    atendimento: {
      'tempo medio de resposta': { secao: 'por operador' },
      'respondido em ate': { secao: 'dia a dia' },
      'primeira resposta': { secao: 'quanto o lead esperou' },
      'conversas': { ir: '/chat' },
      'esperando agora': { secao: 'quem esta esperando agora' }
    },
    configuracoes: {
      'usuarios': { ir: '/usuarios' },
      'agente ia': { ir: '/agente' },
      'alertas juridicos': { aba: '.cfg-tab[data-tab="juridico"]' },
      'notificacoes': { aba: '.cfg-tab[data-tab="notificacoes"]' },
      'ultima alteracao': { aba: '.cfg-tab[data-tab="seguranca"]' }
    },
    chat: {
      'conversas': { filtro: function () { clica('[onclick*="setFilter(\'all\')"]'); }, sempre: true },
      'nao lidas': { filtro: function () { clica('[onclick*="setFilter(\'unread\')"]'); }, desfaz: function () { clica('[onclick*="setFilter(\'all\')"]'); } },
      'conexao': { faz: function () { if (window.ChatApp && ChatApp.openSettings) ChatApp.openSettings(); } },
      'numero': { faz: function () { if (window.ChatApp && ChatApp.openSettings) ChatApp.openSettings(); } }
    },
    agente: {
      'status do agente': { secao: 'diagnostico rapido' },
      'whatsapp': { foco: '#channelSelect' },
      'provedor': { secao: 'identidade do agente' },
      'prompt': { secao: 'pre-atendimento juridico' },
      'ultima atualizacao': { secao: 'identidade do agente' }
    }
  };
  var mapa = ACOES[tela];
  if (!mapa) return;
  var chaves = Object.keys(mapa).sort(function (a, b) { return b.length - a.length; });
  function chaveDe(card) {
    var t = norm(card.innerText || card.textContent);
    for (var i = 0; i < chaves.length; i++) if (t.indexOf(chaves[i]) === 0) return chaves[i];
    return null;
  }

  // A tela de destino só vale se ela está no menu da pessoa (o menu só mostra o que a permissão
  // deixa abrir): sem isso o cartão levava para o "acesso negado". Devolve o endereço ou null.
  function noMenu(url) {
    var u = new URL(url, location.href), caminho = u.pathname.replace(/\.php$/, '');
    var item = [].filter.call(document.querySelectorAll('.sidebar nav a[href], .mobile-tabbar a[href]'), function (a) { var x = new URL(a.href, location.href); return x.pathname.replace(/\.php$/, '') === caminho; })[0];
    return item ? u.pathname + u.search : null;
  }
  // O destino do cartão: o que a própria tela já deu a ele (o Dashboard Executivo põe data-href,
  // conferido pela permissão no servidor), ou o do mapa, se a tela estiver no menu da pessoa.
  function destinoDe(c, a) {
    if (c.dataset.href) return c.dataset.href;
    if (a && a.ir && !a.ligado) return noMenu(a.ir);
    return null;
  }
  var SEL_CARTAO = '[data-inn-kpi], .kpi-card, .chat-kpi';
  // O cartão é o menor elemento marcado cujo texto começa com um rótulo conhecido: a camada marca
  // como indicador também o pedaço de dentro (o rodapé de Finanças, sem rótulo) e a fileira inteira
  // (no Dashboard Executivo, que começa com o rótulo do primeiro cartão). Nem o de dentro nem o de
  // fora serve sempre; o menor que tem rótulo, sim.
  function cartoes() {
    var comRotulo = [].filter.call(document.querySelectorAll(SEL_CARTAO), function (c) {
      return !c.closest('.sidebar, [class*="modal"], [id*="Modal"]') && (c.dataset.innAcao || chaveDe(c));
    });
    return comRotulo.filter(function (c) { return !comRotulo.some(function (o) { return o !== c && c.contains(o); }); });
  }
  // Marca os cartões que têm o que fazer: cursor, foco pelo teclado e, nos que levam a outra tela,
  // um link de verdade por cima (o navegador pede a tela no aperto, como no menu).
  function marcar() {
    cartoes().forEach(function (c) {
      if (c.dataset.innAcao) return;
      var k = chaveDe(c); if (!k) return;
      var a = mapa[k];
      c.dataset.innAcao = k; c.classList.add('inn-cartao-acao');
      var rotulo = (c.innerText || '').trim().split('\n')[0];
      var destino = destinoDe(c, a);
      if (a.ir && !a.ligado && !destino) { c.classList.remove('inn-cartao-acao'); return; }   // sem permissão para a tela: fica como está
      if (destino) {
        var l = document.createElement('a');
        l.className = 'inn-cartao-link'; l.href = destino; l.setAttribute('aria-label', 'Abrir: ' + rotulo);
        c.appendChild(l);
      } else {
        c.setAttribute('role', 'button'); c.setAttribute('tabindex', '0'); c.setAttribute('aria-label', rotulo);
      }
      if (a.ligado) c.classList.add('inn-cartao-on');
    });
  }
  var ligado = null;
  function fazer(c, k) {
    var a = mapa[k]; if (!a) return;
    if (a.ligado) { location.href = location.pathname.replace(/\.php$/, ''); return; }
    var destino = destinoDe(c, a);
    if (destino) { location.href = destino; return; }
    if (a.aba) { clica(a.aba); var b = $(a.aba); if (b) mostrar(b); return; }
    if (a.foco) { var f = $(a.foco); if (f) { mostrar(f); setTimeout(function () { try { f.focus({ preventScroll: true }); } catch (e) {} }, 350); } return; }
    if (a.faz) { a.faz(c); return; }
    if (a.secao) { secao(a.secao)(); return; }
    if (a.filtro) {
      // Clicar de novo no cartão ligado desfaz o filtro (o de "todos" fica sempre ligável).
      if (ligado === c && !a.sempre) {
        if (a.desfaz) a.desfaz(c);
        c.classList.remove('inn-cartao-on'); ligado = null; return;
      }
      if (ligado && ligado !== c) { ligado.classList.remove('inn-cartao-on'); var ak = mapa[ligado.dataset.innAcao]; if (ak && ak.desfaz) ak.desfaz(ligado); }
      a.filtro(c);
      c.classList.add('inn-cartao-on'); ligado = c;
      if (a.mostra) mostrar(titulo(a.mostra));
    }
  }
  // Clique no cartão (o link por cima é do menu-fixo.js, que faz a saída e troca de tela).
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || !e.target.closest) return;
    if (e.target.closest('.inn-cartao-link')) return;
    var c = e.target.closest('.inn-cartao-acao'); if (!c) return;
    if (e.target.closest('a[href], button, input, select, textarea, label')) return;   // o que já é clicável no cartão fica com o dele
    fazer(c, c.dataset.innAcao);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var c = e.target.classList && e.target.classList.contains('inn-cartao-acao') ? e.target : null;
    if (!c) return;
    e.preventDefault(); fazer(c, c.dataset.innAcao);
  });

  // Os cartões chegam com a tela (alguns por script): marca quando ela aparece e quando muda.
  var pedido = null;
  function pedir() { clearTimeout(pedido); pedido = setTimeout(marcar, 200); }
  function comecar() {
    marcar();
    new MutationObserver(pedir).observe(document.querySelector('.main-content') || document.body, { childList: true, subtree: true });
    // Veio de outra tela com ?kpi=...: aplica o que aquele cartão faz aqui, uma vez.
    var kpi = norm(busca.get('kpi') || '');
    if (kpi && mapa[kpi] && !mapa[kpi].ir) {
      setTimeout(function () {
        marcar();
        var c = cartoes().filter(function (x) { return x.dataset.innAcao === kpi; })[0];
        if (c) fazer(c, kpi); else if (mapa[kpi].secao) secao(mapa[kpi].secao)();
        try { busca.delete('kpi'); history.replaceState(history.state, '', location.pathname + (busca.toString() ? '?' + busca : '') + location.hash); } catch (e) {}
      }, 450);
    }
  }
  if (html.classList.contains('inn-vestindo')) document.addEventListener('inn:revelada', function f() { document.removeEventListener('inn:revelada', f); comecar(); });
  else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', comecar);
  else comecar();
})();
