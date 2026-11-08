/* ═══════════════════════════════════════════════════════════════════════════
   interface-nova-sistema.js: o desenho da home em todas as telas (prévia,
   08/11/2026). Par do interface-nova-sistema.css.

     - o ícone da tela no cabeçalho: copia o svg do item ativo do menu para uma
       caixa afundada ao lado do título;
     - botões só de ícone ganham a caixa afundada (.inn-ic-btn);
     - "Carregando…" vira a forma cinza que brilha (.inn-carregando);
     - a onda do toque: um quadro fixo do tamanho do elemento, por cima dele,
       com o anel azul nascendo do ponto do clique (o elemento não muda);
     - gráficos (Chart.js): a paleta do Yuris (azul e prata; vermelho, verde e
       âmbar só quando já eram), a letra Inter, as linhas apagadas, a dica no
       painel prata e a entrada que cresce em sequência. Vale para os gráficos
       que já existem e para os que nascerem depois.
   Carregado pelo menu junto com a interface-nova-telas.css (fora da home).
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var html = document.documentElement;
  var semMovimento = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  function vars() {
    var c = getComputedStyle(html);
    var v = function (n, d) { return (c.getPropertyValue(n) || '').trim() || d; };
    return { azul: v('--inn-t-azul', '#3A63C9'), azul2: v('--inn-t-azul2', '#4F78DB'), texto: v('--inn-t-texto', '#1E2630'), texto3: v('--inn-t-texto3', '#7A8494'),
             linha: v('--inn-t-linha', 'rgba(31,38,50,.10)'), painel: v('--inn-t-painel', '#F5F6F8'), linha2: v('--inn-t-linha2', 'rgba(31,38,50,.18)') };
  }

  // A tela: o container ao lado do menu, seja qual for o nome (.main-content, main.main, main).
  function acharTela() {
    var t = document.querySelector('.inn-tela');
    if (t) return t;
    t = [].filter.call(document.querySelectorAll('.main-content, main'), function (m) { return !m.querySelector('.sidebar') && !m.closest('.sidebar'); })[0];
    // Webhooks: o <main> embrulha o menu também; a tela é o irmão do menu, o maior deles.
    if (!t) {
      var sb = document.querySelector('.sidebar'), pai = sb && sb.parentElement;
      if (pai) t = [].filter.call(pai.children, function (c) { return c !== sb && !/^(SCRIPT|STYLE|LINK|ASIDE)$/.test(c.tagName) && !c.contains(sb); })
        .sort(function (a, b) { return b.getBoundingClientRect().height * b.getBoundingClientRect().width - a.getBoundingClientRect().height * a.getBoundingClientRect().width; })[0];
    }
    if (t) t.classList.add('inn-tela');
    return t || null;
  }

  // ── 0. O tema dos gráficos do dashboard.js (lido antes de ele carregar) ────────
  // O funil de distribuição de Indicadores é um SVG desenhado pelo dashboard.js com a paleta
  // azul-marinho antiga (#244E7A...). O arquivo aceita window.YURIS_CHART_THEME: a prévia põe
  // ali o azul do Yuris e a prata. Só preenche o que não foi definido (conta de marca tem o seu).
  (function () {
    var escuro = document.documentElement.getAttribute('data-theme') !== 'light';
    var meu = {
      funil: ['#2E55B8', '#3D65CB', '#5A7ED6', '#7A8494', '#9AA3B0', '#23448F', '#4F78DB', '#CBD0D8'],
      funilLegenda: escuro ? '#B4BAC5' : '#4E586A',
      fechados: '#2E55B8', fechadosBorda: '#2E55B8', projecao: '#9AA3B0', projecaoBorda: '#9AA3B0', meta: '#9AA3B0',
      receita: '#3D65CB', receitaArea: 'rgba(61,101,203,.10)', metaLinha: '#9AA3B0',
      roscaTexto: escuro ? '#E8EAEE' : '#1E2630', roscaTextoFraco: escuro ? '#7F8794' : '#7A8494',
      tooltipFundo: escuro ? 'rgba(38,43,51,.98)' : 'rgba(245,246,248,.98)', tooltipBorda: 'rgba(61,101,203,.25)',
      tooltipTitulo: escuro ? '#E8EAEE' : '#1E2630', tooltipCorpo: escuro ? '#B4BAC5' : '#4E586A',
      legendaLinha: escuro ? '#B4BAC5' : '#4E586A', fonte: 'Inter, system-ui, sans-serif'
    };
    window.YURIS_CHART_THEME = Object.assign(meu, window.YURIS_CHART_THEME || {});
    // Esta camada carrega com defer e roda depois do dashboard.js, que já montou o CT com a
    // paleta antiga. CT é const de topo (escopo léxico global): troca-se nele; o funil é
    // desenhado depois, quando os dados chegam, e já sai com a paleta nova.
    try {
      if (typeof CT !== 'undefined' && CT && CT.funil) Object.keys(meu).forEach(function (k) { CT[k] = meu[k]; });
    } catch (e) {}
  })();

  // ── 1. O ícone da tela no cabeçalho ─────────────────────────────────────
  function iconeNoCabecalho() {
    var main = acharTela();
    if (!main || main.querySelector('.inn-cab-ic')) return;
    var ativo = document.querySelector('.sidebar nav a.active .icon svg') || document.querySelector('.sidebar .sidebar-group.has-active .sidebar-group-icon svg');
    // Tela fora do menu desta edição: o link do menu para o mesmo endereço, se houver.
    if (!ativo) {
      var aqui = location.pathname.replace(/\.php$/, '').replace(/^\//, '');
      var link = [].filter.call(document.querySelectorAll('.sidebar nav a[href]'), function (a) { return (a.getAttribute('href') || '').replace(/^\//, '').replace(/\.php.*$/, '').replace(/\?.*$/, '') === aqui; })[0];
      ativo = link && link.querySelector('.icon svg');
    }
    var titulo = main.querySelector('.page-header .page-header-title') || main.querySelector('.page-header h1, .page-header h2, h1, header h2, h2');
    if (!ativo || !titulo) return;
    var caixa = document.createElement('span');
    caixa.className = 'inn-cab-ic';
    caixa.setAttribute('aria-hidden', 'true');
    caixa.appendChild(ativo.cloneNode(true));
    // Título e ícone na mesma linha: o título vira flex só se ainda não for.
    titulo.classList.add('inn-cab-titulo');
    var bloco = titulo.parentNode;
    if (bloco && bloco.classList && bloco.classList.contains('page-header-text')) {
      var linha = document.createElement('div');
      linha.className = 'inn-cab-linha';
      bloco.parentNode.insertBefore(linha, bloco);
      linha.appendChild(caixa); linha.appendChild(bloco);
    } else {
      titulo.classList.add('inn-cab-linha');
      titulo.insertBefore(caixa, titulo.firstChild);
    }
  }

  // ── 2. Botões só de ícone ───────────────────────────────────────────────
  function botoesDeIcone(raiz) {
    if (!raiz.querySelectorAll) return;
    [].forEach.call(raiz.querySelectorAll('button, a[class*="btn"], .icon-btn'), function (b) {
      if (b.dataset.innIc || b.closest('.sidebar, .tk-tabs, [role="tablist"], .inn-cab-ic')) return;
      // Caixa de marcar não é botão de ícone: acender em azul no hover pareceria marcada.
      if (/check|toggle|switch/i.test(b.className) || b.getAttribute('role') === 'checkbox' || b.hasAttribute('aria-pressed') || b.hasAttribute('aria-checked')) return;
      var svg = b.querySelector('svg');
      if (!svg || (b.textContent || '').trim().length > 1) return;
      var r = b.getBoundingClientRect();
      if (r.width < 22 || r.width > 48 || r.height > 48) return;
      b.dataset.innIc = '1'; b.classList.add('inn-ic-btn');
    });
  }

  // ── 3. "Carregando…" vira a forma cinza ─────────────────────────────────
  var RE_CARREGANDO = /^\s*(carregando|buscando|atualizando)\b[\s\S]{0,30}$/i;
  function fantasmas(raiz) {
    if (!raiz.querySelectorAll) return;
    var todos = [].slice.call(raiz.querySelectorAll('div, span, p, td, li, small'));
    if (raiz.nodeType === 1) todos.unshift(raiz);
    todos.forEach(function (el) {
      if (el.children.length > 1 || el.classList.contains('inn-carregando')) return;
      if (el.children.length === 1 && !/^(I|SVG|SPAN)$/i.test(el.children[0].tagName)) return;
      if (!RE_CARREGANDO.test(el.textContent || '')) return;
      if (el.closest('button, select, option, .sidebar')) return;
      el.classList.add('inn-carregando');
    });
  }
  // Quando o texto muda (o módulo trocou "Carregando…" pelo conteúdo), a forma sai.
  function conferirFantasmas() {
    [].forEach.call(document.querySelectorAll('.inn-carregando'), function (el) {
      if (!RE_CARREGANDO.test(el.textContent || '')) el.classList.remove('inn-carregando');
    });
  }

  // ── 3b. Abas, guias e filtros reconhecidos pelo nome ────────────────────
  // Cada tela chama a aba de um jeito (int-tab, lp-tab, mst-tab, ci-mtab, doc-tab,
  // mon-pill, chat-filter-btn...) e o estado de um jeito (active, ativa, ativo,
  // selected, doc-tab-active, mon-pill-active...). Marca as duas coisas e deixa o CSS
  // pôr a pílula azul. Painel de aba (pane, panel, content) não é aba.
  var RE_ABA = /(^|[-_])(tab|mtab|aba|pill|pilula|chip|seg|segment|filtro|filter|view|periodo|period|modo|opcao)(s)?(-?btn|-?item|-?opt)?$/i;
  var RE_NAO = /pane|panel|content|body|wrap|list|bar|group|container|header|grid|row|col|area|box/i;
  var RE_ON  = /^(active|ativo|ativa|selected|is-active|is-selected|current|on|sel|atual)$|-(active|ativo|ativa|selected|on)$/i;
  function ehAba(el) {
    if (el.getAttribute('role') === 'tab') return true;
    var cls = String(el.className && el.className.baseVal != null ? el.className.baseVal : el.className || '').split(/\s+/);
    return cls.some(function (c) { return RE_ABA.test(c) && !RE_NAO.test(c); });
  }
  function abaLigada(el) {
    if (el.getAttribute('aria-selected') === 'true' || el.getAttribute('aria-pressed') === 'true') return true;
    // As marcas da própria camada (inn-aba-on termina em "-on") não contam: senão a aba que
    // ligou uma vez fica ligada para sempre, e trocar de aba deixa as duas azuis (08/11/2026).
    return String(el.className || '').split(/\s+/).some(function (c) { return c.indexOf('inn-') !== 0 && RE_ON.test(c); });
  }
  function marcarAbas(raiz) {
    if (!raiz || !raiz.querySelectorAll) return;
    var cands = [].slice.call(raiz.querySelectorAll('button, a, li, span, div, label'));
    if (raiz.nodeType === 1) cands.unshift(raiz);
    cands.forEach(function (el) {
      if (el.classList.contains('inn-aba')) { el.classList.toggle('inn-aba-on', abaLigada(el)); return; }
      if (!ehAba(el) || el.closest('.sidebar')) return;
      var r = el.getBoundingClientRect();
      if (r.height > 56 || r.width > 320) return;                 // aba é coisa pequena
      if (el.tagName !== 'BUTTON' && el.tagName !== 'A' && el.getAttribute('role') !== 'tab' && !el.onclick && getComputedStyle(el).cursor !== 'pointer') return;
      el.classList.add('inn-aba');
      el.classList.toggle('inn-aba-on', abaLigada(el));
    });
  }

  // Tira o "sem transição" depois que a cor nova já foi pintada (lê o estilo para
  // fixar a cor antes de soltar; setTimeout e não rAF, que para em aba escondida).
  function soltarTransicao(els) {
    if (!els.length) return;
    els.forEach(function (el) { void getComputedStyle(el).borderTopColor; });
    setTimeout(function () { els.forEach(function (el) { el.classList.remove('inn-c-agora'); }); }, 60);
  }

  // ── 3c. Selos e etiquetas pela cor que já tinham ────────────────────────
  // Cada tela pinta o selo à sua moda (badge-hot rosa, status-pago verde-limão,
  // tag-pendente pastel...). O sentido fica, o desenho passa a ser o da etiqueta
  // da home: vermelho, âmbar e verde só quando já eram estado; azul e roxo viram o
  // azul do Yuris; cinza fica na prata. A classe original fica guardada: se a tela
  // trocar o estado do selo, ele é lido de novo.
  // Também o selo que só diz o estado no nome (o-bom, o-atencao, o-ruim do Desempenho).
  var RE_SELO = /badge|(^|[-_])(tag|selo|etiqueta|status)([-_]|$)|^(pill|chip)$|-pill$|(^|[-_])(bom|ruim|atencao|ok|warn|danger|success|erro|alerta|urgente|pendente|ativo|inativo)$/i;
  var TONS = ['inn-selo-erro', 'inn-selo-aviso', 'inn-selo-ok', 'inn-selo-azul', 'inn-selo-neutro'];
  function baseSelo(el) { return String(el.className || '').split(/\s+/).filter(function (c) { return c && c.indexOf('inn-') !== 0; }).join(' '); }
  function tomDoSelo(el) {
    var cs = getComputedStyle(el), melhor = null;
    [cs.backgroundColor, cs.color, cs.borderTopColor].forEach(function (c, i) {
      var v = rgb(c); if (!v || v[3] < (i === 0 ? .04 : .3)) return;
      var hsl = matiz(v[0], v[1], v[2]);
      if (hsl.l < .08 || hsl.l > .96) return;
      if (!melhor || hsl.s > melhor.s) melhor = hsl;
    });
    if (!melhor || melhor.s < .2) return 'inn-selo-neutro';
    var h = melhor.h;
    if (h < 16 || h >= 290) return 'inn-selo-erro';
    if (h < 58) return 'inn-selo-aviso';
    if (h < 172) return 'inn-selo-ok';
    return 'inn-selo-azul';
  }
  function vestirSelo(el) {
    TONS.forEach(function (t) { el.classList.remove(t); });
    el.classList.remove('inn-selo');
    var tom = tomDoSelo(el);                   // lido sem as classes da prévia
    el.dataset.innSelo = baseSelo(el);
    el.classList.add('inn-selo', tom);
  }
  function ehSelo(el) {
    if (el.dataset.innSelo != null || el.classList.contains('inn-aba')) return false;
    var cls = String(el.className && el.className.baseVal != null ? '' : el.className || '').split(/\s+/);
    if (!cls.some(function (c) { return RE_SELO.test(c); })) return false;
    if (el.closest('.sidebar, button, .inn-aba, th, select, label, .inn-cab-ic') && !el.matches('button')) return false;
    if (el.matches('button, input, select, textarea')) return false;
    var t = (el.textContent || '').trim();
    if (!t || t.length > 40 || el.querySelector('div, p, ul, table, input, button')) return false;
    // Embrulho de selos (.badges) não é selo: os de dentro é que são.
    if ([].some.call(el.children, function (f) { return RE_SELO.test(String(f.className || '')); })) return false;
    var r = el.getBoundingClientRect();
    return r.height > 0 && r.height <= 30 && r.width <= 240;
  }
  function selos(raiz) {
    if (!raiz || !raiz.querySelectorAll || !document.querySelector('.inn-tela')) return;
    var cands = [].slice.call(raiz.querySelectorAll('span, small, em, b, strong, div, a, li'));
    if (raiz.nodeType === 1) cands.unshift(raiz);
    var achados = cands.filter(function (el) { return el.closest && el.closest('.inn-tela, [class*="modal"]') && ehSelo(el); });
    // Lê todos os tons antes de pôr classe (pôr classe no meio força recálculo a cada selo).
    var tons = achados.map(tomDoSelo);
    achados.forEach(function (el, i) { el.dataset.innSelo = baseSelo(el); el.classList.add('inn-c-agora', 'inn-selo', tons[i]); });
    soltarTransicao(achados);
  }

  // ── 3d. O que ficou com a cor antiga ────────────────────────────────────
  // Lido pela cor de verdade, não pelo nome (cada tela dá um nome):
  //   - fundo branco no tema claro: painel vira a prata do cartão, campo vira o
  //     fundo afundado, botão e aba viram o botão secundário da home;
  //   - botão vermelho (Excluir, Remover) vira o secundário com texto vermelho;
  //     botão de outra cor que não o azul vira o secundário;
  //   - borda colorida por estado num bloco (cartão atrasado, linha urgente) vira
  //     a linha neutra: o estado fica no texto, como na home;
  //   - faixa lateral colorida sai;
  //   - caixa de aviso (fundo vermelho, âmbar ou verde) vira o aviso da home.
  // Fica de fora o que é cor de dado escolhida pela pessoa (bolinha de setor, de
  // coluna) e o que precisa ser branco (QR, assinatura, foto).
  var RE_BRANCO_NAO = /qr|knob|thumb|switch|toggle|avatar|assinatura|signature|preview|logo|foto|photo|img|canvas|swatch|cor-|color/i;
  var RE_CAIXA = /card|kpi|panel|painel|tile|stat|widget|alert|aviso|banner|strip|faixa|summary|resumo|health|saude/i;
  // Título e cabeçalho de seção, coluna ou cartão: só texto, sem faixa (fundo tingido, borda ou
  // linha embaixo). "Não é para ter listra nenhuma" (08/11/2026). O cabeçalho da página é o
  // painel do topo e fica de fora.
  var RE_TITULO = /(^|[-_\s])(title|titulo|header|head|hdr|cabec|cabecalho|heading|footer|rodape)([-_\s]|$)/i;
  var RE_TITULO_NAO = /page-header|inn-cab|thead|avatar|-ic(\s|$)|icon|foto|logo/i;
  function temFaixa(cs) {
    var bg = rgb(cs.backgroundColor);
    if ((bg && bg[3] > .03) || cs.backgroundImage.indexOf('gradient') >= 0) return true;
    return ['Top', 'Bottom', 'Left', 'Right'].some(function (s) {
      if (parseFloat(cs['border' + s + 'Width']) <= 0 || cs['border' + s + 'Style'] === 'none') return false;
      var c = rgb(cs['border' + s + 'Color']); return !!c && c[3] > .04;
    });
  }
  function tomDeCor(c, minA, minL) {
    var v = rgb(c); if (!v || v[3] < (minA || .25)) return null;
    var h = matiz(v[0], v[1], v[2]);
    if (h.s < .3 || h.l < (minL || .12) || h.l > .95) return null;
    if (h.h >= 200 && h.h < 240) return 'azul';
    if (h.h < 16 || h.h >= 330) return 'erro';
    if (h.h < 58) return 'aviso';
    if (h.h < 172) return 'ok';
    return 'outro';
  }
  function tomDe(cs, prop, minA, minL) { return tomDeCor(cs[prop], minA, minL); }
  // O fundo pode ser cor ou degradê (o aviso do Chat é um degradê vinho escuro): lê os dois.
  function tomFundo(cs) {
    var t = tomDe(cs, 'backgroundColor', .05, .04); if (t) return t;
    if (cs.backgroundImage.indexOf('gradient') < 0) return null;
    var paradas = cs.backgroundImage.match(/rgba?\([^)]+\)/g) || [];
    for (var i = 0; i < paradas.length; i++) { t = tomDeCor(paradas[i], .25, .04); if (t) return t; }
    return null;
  }
  function emTransicao(el) {
    try { return el.getAnimations().some(function (a) { return typeof CSSTransition !== 'undefined' && a instanceof CSSTransition; }); } catch (e) { return false; }
  }
  function branco(cs) {
    var v = rgb(cs.backgroundColor);
    if (v && v[3] > .9) return v[0] >= 252 && v[1] >= 252 && v[2] >= 252;
    // Degradê quase branco (branco a #F4F7FB, o cartão do tema claro antigo): também é branco.
    if ((!v || v[3] < .1) && cs.backgroundImage.indexOf('gradient') >= 0) {
      var paradas = (cs.backgroundImage.match(/rgba?\([^)]+\)/g) || []).map(rgb).filter(Boolean);
      return paradas.length > 0 && paradas.every(function (p) { return p[3] > .9 && p[0] >= 240 && p[1] >= 240 && p[2] >= 240; });
    }
    return false;
  }
  // A cor de fundo que se vê: a cor, ou a primeira parada do degradê.
  function corFundo(cs) {
    var v = rgb(cs.backgroundColor); if (v && v[3] >= .5) return v;
    if (cs.backgroundImage.indexOf('gradient') < 0) return null;
    var paradas = (cs.backgroundImage.match(/rgba?\([^)]+\)/g) || []).map(rgb).filter(Boolean);
    for (var i = 0; i < paradas.length; i++) if (paradas[i][3] >= .5) return paradas[i];
    return null;
  }
  function claro() { return document.documentElement.getAttribute('data-theme') === 'light'; }
  // Pop-up de qualquer módulo (modal, diálogo, gaveta, painel lateral).
  var SEL_POPUP = '[class*="modal"], [id*="modal"], [id*="Modal"], [role="dialog"], dialog, [class*="drawer"], [class*="popup"], [class*="popover"], [class*="dialog"], [class*="overlay"]';
  function noPopup(el) { return !!(el.parentElement && el.parentElement.closest(SEL_POPUP)); }
  var TIPOS_SEM_FUNDO = /^(checkbox|radio|range|color|file|hidden|button|submit|reset|image)$/i;
  // Contraste do texto com o fundo que se vê atrás dele (WCAG). O fundo de cada elemento é
  // guardado durante a passada, para não subir a árvore inteira de novo a cada texto.
  function luz(v) {
    var f = function (c) { c /= 255; return c <= .03928 ? c / 12.92 : Math.pow((c + .055) / 1.055, 2.4); };
    return .2126 * f(v[0]) + .7152 * f(v[1]) + .0722 * f(v[2]);
  }
  function fundoVisto(el, cache) {
    var cadeia = [], achado = null, n = el;
    while (n && n.nodeType === 1) {
      if (cache.has(n)) { achado = cache.get(n); break; }
      cadeia.push(n);
      var v = corFundo(getComputedStyle(n));
      if (v) { achado = v; break; }
      n = n.parentElement;
    }
    if (!achado) achado = claro() ? [230, 232, 235, 1] : [23, 26, 31, 1];
    cadeia.forEach(function (c) { cache.set(c, achado); });
    return achado;
  }
  function temTextoProprio(el) {
    for (var i = 0; i < el.childNodes.length; i++) { var c = el.childNodes[i]; if (c.nodeType === 3 && c.textContent.trim().length > 1) return true; }
    return false;
  }
  function vestirCores(raiz) {
    if (!raiz || !raiz.querySelectorAll || !document.querySelector('.inn-tela')) return;
    var cacheFundo = new WeakMap();
    var cands = [].slice.call(raiz.querySelectorAll('div, section, article, aside, header, footer, li, a, button, input, select, textarea, span, label, td, tr, h1, h2, h3, h4, h5, h6'));
    if (raiz.nodeType === 1) cands.unshift(raiz);
    var fila = [];
    cands.forEach(function (el) {
      // Vale na tela, nos pop-ups e também no que fica fora (aviso fixo no topo, toast): só o menu fica de fora.
      if (el.dataset.innCor || el.closest('.sidebar, .inn-selo, .inn-cab-ic, svg')) return;
      var cls = String(el.className || ''); if (typeof el.className !== 'string') return;
      if (/(^|\s)inn-(selo|ic-btn|aba-on)/.test(cls) && !/inn-aba(\s|$)/.test(cls)) return;
      // No meio de uma transição a cor lida é a do caminho, não a de chegada (e o aviso do Chat
      // ainda está crescendo de altura zero): lê de novo no fim.
      if (emTransicao(el)) {
        if (!el.dataset.innEspera) { el.dataset.innEspera = '1'; el.addEventListener('transitionend', function () { delete el.dataset.innEspera; if (el.dataset.innCor == null) vestirCores(el); }, { once: true }); }
        return;
      }
      var r = el.getBoundingClientRect(); if (r.width < 8 || r.height < 8) return;
      var cs = getComputedStyle(el), marca = [];
      var ehCampo = /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName);
      var ehBotao = !ehCampo && (el.tagName === 'BUTTON' || /(^|[-_\s])btn|button|botao/i.test(cls));
      // Faixa de título: vira só o texto; nada mais nela é lido (nem como aviso tingido).
      if (!ehCampo && !ehBotao && RE_TITULO.test(cls) && !RE_TITULO_NAO.test(cls) && r.height <= 64 && !el.closest('table') && (el.textContent || '').trim() && temFaixa(cs)) {
        fila.push([el, ['inn-c-titulo']]); el.dataset.innBase = baseSelo(el); return;
      }
      // Campo dentro de pop-up: branco, para ler e preencher ("os campos podem ser brancos",
      // 08/11/2026). Texto, lista e área iguais; a caixa de marcar fica como está.
      var campoPop = ehCampo && !TIPOS_SEM_FUNDO.test(el.type || '') && noPopup(el);
      if (campoPop) marca.push('inn-c-campo-pop');
      // Fundo branco (claro): painel, campo ou botão.
      else if (claro() && branco(cs) && !RE_BRANCO_NAO.test(cls) && !(r.width < 60 && r.height < 60 && !ehCampo && !ehBotao)) {
        if (ehCampo) marca.push('inn-c-campo');
        else if (ehBotao || el.classList.contains('inn-aba')) marca.push('inn-c-botao');
        else if (!el.querySelector(':scope > img:only-child, :scope > canvas:only-child')) marca.push('inn-c-painel');
      }
      // Botões coloridos que não são o azul.
      if (ehBotao && !el.classList.contains('inn-aba')) {
        var tb = tomDe(cs, 'backgroundColor'), tt = tomDe(cs, 'color'), tbd = parseFloat(cs.borderTopWidth) > 0 ? tomDe(cs, 'borderTopColor') : null;
        // Cheio de outra cor (verde "Ativar", roxo "Salvar"): é o principal, vira o azul.
        // Só o texto ou a borda coloridos: é o secundário. Vermelho é sempre perigo.
        var vbt = rgb(cs.backgroundColor), cheio = tb && vbt && vbt[3] > .6;
        var tom = (tb && tb !== 'azul') ? tb : (!tb && tt && tt !== 'azul') ? tt : (!tb && tbd && tbd !== 'azul') ? tbd : null;
        if (tom === 'erro') marca.push('inn-c-perigo');
        else if (tom && cheio && r.height >= 30) marca.push('inn-c-primario');   // o miúdo dentro de cartão não ganha o brilho
        else if (tom) marca.push('inn-c-botao');
      } else if (!ehCampo && r.width > 120 && r.height > 26) {
        // Caixa tingida (fundo de estado, mesmo bem claro, com texto): a baixa vira o aviso
        // da home; a alta (um bloco inteiro pintado de pêssego) vira o painel afundado.
        // Bloco escuro no tema claro (o "Valor calculado" do imposto era azul-marinho quase
        // preto com texto amarelo): sobra do tema escuro, vira a prata com o texto escuro.
        var vEsc = rgb(cs.backgroundColor);
        if (claro() && vEsc && vEsc[3] > .5 && matiz(vEsc[0], vEsc[1], vEsc[2]).l < .22 && !RE_BRANCO_NAO.test(cls) && !/overlay|backdrop|mask|scrim|shell|tooltip/i.test(cls + ' ' + el.id) && !(r.width >= innerWidth * .9 && r.height >= innerHeight * .9) && !el.classList.contains('inn-aba')) {
          marca.push('inn-c-escuro'); fila.push([el, marca]); el.dataset.innBase = baseSelo(el); return;
        }
        var fb = tomFundo(cs);
        var tingida = fb && fb !== 'azul' && fb !== 'outro' && (el.textContent || '').trim().length > 3;
        // Faixa de resumo (três ou mais blocos de rótulo e valor, como "Total em impostos /
        // Alíquota / Incide sobre" de Finanças) não é aviso: vira o painel afundado, e os
        // números âmbar e verdes de dentro voltam ao texto escuro (só o vermelho fica).
        var blocos = tingida ? [].filter.call(el.children, function (c) { return (c.textContent || '').trim().length > 1; }).length : 0;
        if (tingida) marca.push((r.height > 90 || blocos >= 3) ? 'inn-c-neutro' : 'inn-c-alerta-' + fb);
        // Borda colorida por estado: vira a linha neutra.
        var bd = parseFloat(cs.borderTopWidth) > 0 ? tomDe(cs, 'borderTopColor') : null;
        if (bd && bd !== 'azul' && !tingida) marca.push('inn-c-borda');
        // Filete decorativo (::before/::after fino e colorido no topo ou na lateral de um
        // cartão): some. Só em cartão, painel e afins, que é onde ele mora.
        if (RE_CAIXA.test(cls)) ['::before', '::after'].forEach(function (ps) {
          var pc = getComputedStyle(el, ps);
          if (pc.content === 'none' || pc.content === 'normal') return;
          var ph = parseFloat(pc.height), pw = parseFloat(pc.width);
          if (!((ph <= 8 && pw >= 40) || (pw <= 8 && ph >= 30))) return;
          var pb = rgb(pc.backgroundColor);
          if (pc.backgroundImage.indexOf('gradient') >= 0 || (pb && pb[3] > .2 && matiz(pb[0], pb[1], pb[2]).s > .2)) marca.push(ps === '::before' ? 'inn-c-sem-filete-antes' : 'inn-c-sem-filete-depois');
        });
      }
      // Toast (aviso flutuante, de qualquer módulo): o painel da home, nunca bloco escuro.
      var pai = el.parentElement;
      if (/toast|snack/i.test(cls + ' ' + el.id) || (pai && /toast|snack/i.test(pai.id + ' ' + String(pai.className || '')) && !/toast-(icon|content|title|body|close)/i.test(cls) && r.width > 120)) {
        if (!/toast-(icon|content|title|body|close|host)/i.test(cls + ' ' + el.id) && r.width > 120) marca.push('inn-toast');
      }
      // Faixa lateral colorida.
      if (!ehCampo && parseFloat(cs.borderLeftWidth) >= 2 && parseFloat(cs.borderLeftWidth) > parseFloat(cs.borderTopWidth) && tomDe(cs, 'borderLeftColor')) marca.push('inn-c-sem-faixa');
      // Texto que some no fundo (o subtítulo azul-claro em cima da prata): menos de 3 para 1
      // vira o cinza do texto; se era estado (vermelho, âmbar, verde) fica o tom forte dele;
      // azul vivo (link, número do processo) vira o azul do Yuris; azul desbotado vira cinza.
      // Dentro de aba a cor é da regra da aba (muda quando ela liga e desliga); ler aqui
      // pintava o contador "8 não lidas" de cinza antes de a aba ficar azul.
      if (!ehCampo && temTextoProprio(el) && !el.closest('.inn-aba')) {
        var tc = rgb(cs.color);
        if (tc && tc[3] > .3) {
          var fv = fundoVisto(el, cacheFundo), l1 = luz(tc), l2 = luz(fv);
          var contraste = (Math.max(l1, l2) + .05) / (Math.min(l1, l2) + .05);
          if (contraste < 3) {
            var tt2 = tomDeCor(cs.color, .3, .05);
            marca.push(tt2 === 'erro' || tt2 === 'aviso' || tt2 === 'ok' ? 'inn-c-texto-' + tt2 : ((tt2 === 'azul' || tt2 === 'outro') && (el.tagName === 'A' || matiz(tc[0], tc[1], tc[2]).s >= .6)) ? 'inn-c-texto-azul' : 'inn-c-texto-cinza');
          }
        }
      }
      fila.push([el, marca]);
      el.dataset.innBase = baseSelo(el);
    });
    // Lê tudo antes de escrever: trocar classe no meio da leitura recalcula o estilo a cada passo.
    // A troca é seca, sem transição: senão o cartão abre verde e desbota para o cinza.
    var trocados = [];
    fila.forEach(function (p) {
      p[0].dataset.innCor = p[1].join(' ') || '-';
      if (p[1].length) { p[0].classList.add('inn-c-agora'); trocados.push(p[0]); }
      p[1].forEach(function (m) { p[0].classList.add(m); });
      if (p[0].id && p[1].length) porCimaDoId(p[0], p[1]);
    });
    soltarTransicao(trocados);
    // Dentro do resumo afundado, texto âmbar ou verde vira o texto escuro.
    fila.forEach(function (p) {
      if (p[1].indexOf('inn-c-neutro') < 0) return;
      [].forEach.call(p[0].querySelectorAll('*'), function (f) {
        if (f.closest('.inn-selo, button, a, svg')) return;
        var t = tomDeCor(getComputedStyle(f).color, .3, .05);
        if (t === 'aviso' || t === 'ok') f.classList.add('inn-c-texto-forte');
      });
    });
    afundarCaixas(raiz);
  }
  // Caixa da mesma cor dentro de caixa (o resumo dentro do painel, o bloco dentro da seção, o
  // código dentro do cartão): a de dentro afunda, como as caixas dos ícones da home, sem linha;
  // dentro de uma afundada, a terceira fica plana. Foi o "cheio de linha, cheio de contorno
  // quadrado" do funil do Planejamento (08/11/2026). Lista não entra: cartão numa raia, ou item
  // repetido ao lado de outro igual, é para ficar em relevo.
  var RE_RAIA = /(^|[^a-z])(col|cols|column|coluna|list|lista|cardslist|kanban|lane|grid|cards|items|itens|rows|linhas|board|quadro)([^a-z]|$)/;
  function caixaDe(el) {
    var cs = getComputedStyle(el);
    if (cs.borderTopStyle === 'none' || parseFloat(cs.borderTopWidth) <= 0 || parseFloat(cs.borderBottomWidth) <= 0 || parseFloat(cs.borderLeftWidth) <= 0) return null;
    var bc = rgb(cs.borderTopColor); if (!bc || bc[3] < .06) return null;
    return corFundo(cs);
  }
  function afundarCaixas(raiz) {
    if (!raiz || !raiz.querySelectorAll) return;
    var cands = [].slice.call(raiz.querySelectorAll('div, section, article, aside, li, p'));
    if (raiz.nodeType === 1) {
      // Os pais primeiro (o resumo nasce vazio e só enche depois): de fora para dentro.
      var acima = [], pa = raiz, k = 0;
      while (pa && pa !== document.body && k++ < 5 && !pa.classList.contains('inn-tela')) { acima.unshift(pa); pa = pa.parentElement; }
      cands = acima.concat(cands);
    }
    cands.forEach(function (el) {
      if (el.dataset.innCaixa || typeof el.className !== 'string') return;
      var cls = el.className;
      if (/(^|\s)inn-(selo|aba|ic-btn|toast)/.test(cls) || RE_BRANCO_NAO.test(cls) || /(^|[-_\s])btn|button|botao|field|input|select|campo|dropdown/i.test(cls)) return;   // campo e botão continuam com cara de campo e botão
      if (el.closest('.sidebar, table, button, svg, .inn-selo, .inn-aba')) return;
      if (el.offsetWidth < 160 || el.offsetHeight < 36) return;
      var bg = caixaDe(el); if (!bg) return;
      var pai = el.parentElement, caixaPai = null, n = 0;
      while (pai && pai !== document.body && n++ < 3) {
        if (pai.classList.contains('inn-tela')) break;
        if (pai.dataset.innCaixa || caixaDe(pai)) { caixaPai = pai; break; }   // a afundada perdeu a borda, mas continua sendo a caixa de fora
        pai = pai.parentElement;
      }
      if (!caixaPai) return;
      var p = el.parentElement, pcls = ((p.className || '') + ' ' + (p.id || '')).toLowerCase();
      if (RE_RAIA.test(pcls)) return;
      var c1 = cls.split(/\s+/)[0];
      if (c1 && [].some.call(p.children, function (s) { return s !== el && s.classList.contains(c1); })) return;
      var marca = null;
      if (caixaPai.classList.contains('inn-c-afundado') || caixaPai.classList.contains('inn-c-plano')) marca = 'inn-c-plano';
      else {
        var pbg = corFundo(getComputedStyle(caixaPai));
        if (pbg && Math.abs(pbg[0] - bg[0]) < 14 && Math.abs(pbg[1] - bg[1]) < 14 && Math.abs(pbg[2] - bg[2]) < 14) marca = 'inn-c-afundado';
      }
      if (!marca) return;
      el.dataset.innCaixa = marca;
      el.classList.add('inn-c-agora'); el.classList.add(marca);
      if (el.id) porCimaDoId(el, [marca]);
      soltarTransicao([el]);
    });
  }
  // Regra por #id da tela (o aviso fixo do Chat é #disconnectAlert.visible) ganha de qualquer
  // classe: nesses, a cor nova vai em linha, com !important.
  var INLINE = {
    'inn-c-painel': { 'background': 'var(--inn-t-painel)' },
    'inn-c-campo': { 'background': 'var(--inn-t-fundo)' },
    'inn-c-neutro': { 'background': 'var(--inn-t-fundo)', 'border-color': 'var(--inn-t-linha)' },
    'inn-c-borda': { 'border-color': 'var(--inn-t-linha)' },
    'inn-c-sem-faixa': { 'border-left-color': 'var(--inn-t-linha)' },
    'inn-c-alerta-erro': { 'background': 'linear-gradient(var(--inn-t-erro-bg), var(--inn-t-erro-bg)) var(--inn-t-painel)', 'color': 'var(--inn-t-erro)', 'border-color': 'transparent' },
    'inn-c-alerta-aviso': { 'background': 'linear-gradient(var(--inn-t-aviso-bg), var(--inn-t-aviso-bg)) var(--inn-t-painel)', 'color': 'var(--inn-t-aviso)', 'border-color': 'transparent' },
    'inn-c-alerta-ok': { 'background': 'linear-gradient(var(--inn-t-ok-bg), var(--inn-t-ok-bg)) var(--inn-t-painel)', 'color': 'var(--inn-t-ok)', 'border-color': 'transparent' },
    'inn-toast': { 'background': 'var(--inn-t-painel)', 'color': 'var(--inn-t-texto)', 'border-color': 'var(--inn-t-linha2)' },
    'inn-c-afundado': { 'background': 'var(--inn-t-fundo)', 'border-color': 'transparent', 'box-shadow': 'var(--inn-t-fundo-sombra)' },
    'inn-c-plano': { 'background': 'transparent', 'border-color': 'transparent', 'box-shadow': 'none' },
    'inn-c-titulo': { 'background': 'transparent', 'border-color': 'transparent', 'box-shadow': 'none' },
    'inn-c-campo-pop': { 'background-color': 'var(--inn-t-campo-pop)', 'border-color': 'var(--inn-t-linha2)', 'color': 'var(--inn-t-texto)', 'box-shadow': 'none' },
    'inn-c-texto-cinza': { 'color': 'var(--inn-t-texto2)' },
    'inn-c-escuro': { 'background': 'var(--inn-t-painel2)', 'color': 'var(--inn-t-texto)', 'border-color': 'var(--inn-t-linha2)', 'box-shadow': 'none' },
    'inn-c-texto-azul': { 'color': 'var(--inn-t-azul2)' },
    'inn-c-texto-erro': { 'color': 'var(--inn-t-erro)' },
    'inn-c-texto-aviso': { 'color': 'var(--inn-t-aviso)' },
    'inn-c-texto-ok': { 'color': 'var(--inn-t-ok)' }
  };
  function porCimaDoId(el, marcas) {
    marcas.forEach(function (m) {
      var props = INLINE[m]; if (!props) return;
      Object.keys(props).forEach(function (k) { el.style.setProperty(k, props[k], 'important'); });
      el.dataset.innInline = (el.dataset.innInline ? el.dataset.innInline + ' ' : '') + Object.keys(props).join(' ');
    });
  }
  function revestirCores(el) {
    (el.dataset.innInline || '').split(' ').forEach(function (k) { if (k) el.style.removeProperty(k); });
    delete el.dataset.innInline;
    (el.dataset.innCor || '').split(' ').forEach(function (m) { if (m && m !== '-') el.classList.remove(m); });
    delete el.dataset.innCor; delete el.dataset.innBase;
    if (el.dataset.innCaixa) { el.classList.remove(el.dataset.innCaixa); delete el.dataset.innCaixa; }
    vestirCores(el);
  }
  // Trocar o tema relê as cores (o branco só existe no claro).
  new MutationObserver(function () {
    [].forEach.call(document.querySelectorAll('[data-inn-cor]'), function (el) {
      (el.dataset.innCor || '').split(' ').forEach(function (m) { if (m && m !== '-') el.classList.remove(m); });
      delete el.dataset.innCor; delete el.dataset.innBase;
    });
    [].forEach.call(document.querySelectorAll('[data-inn-caixa]'), function (el) { el.classList.remove(el.dataset.innCaixa); delete el.dataset.innCaixa; });
    vestirCores(document.body);
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  // ── 3b. Clicar fora fecha o pop-up ──────────────────────────────────────
  // "Em absolutamente todos os pop-ups: se eu clicar fora, ele se fecha" (08/11/2026).
  // Cada módulo fecha o seu de um jeito, então o clique fora aperta o próprio botão de
  // fechar do pop-up (X, "Fechar" ou "Cancelar", nessa ordem): o módulo faz a limpeza de
  // sempre. Fora é o fundo escurecido: um elemento que cobre quase a tela inteira, fixo,
  // e que tem dentro uma peça menor (o painel). O botão do mouse tem de descer e subir no
  // fundo: arrastar uma seleção de dentro do campo até fora não fecha nada.
  var RE_FECHAR_ATTR = /(^|[-_\s])(close|fechar|dismiss|btn-x|x-btn)([-_\s]|$)|modal-close|close-btn|closeBtn/i;
  function visivel(el) {
    if (!el.offsetWidth && !el.offsetHeight) return false;
    var cs = getComputedStyle(el); return cs.visibility !== 'hidden' && cs.display !== 'none' && parseFloat(cs.opacity) > .05;
  }
  function ehFundoDePopup(el) {
    if (!el || el.nodeType !== 1 || el === document.body || el === document.documentElement) return false;
    if (el.closest('.sidebar, .inn-tela') && !el.closest(SEL_POPUP)) return false;
    var r = el.getBoundingClientRect();
    if (r.width < innerWidth * .9 || r.height < innerHeight * .9) return false;
    var fixo = false, n = el;
    for (var i = 0; i < 3 && n && n !== document.body; i++, n = n.parentElement) if (getComputedStyle(n).position === 'fixed') { fixo = true; break; }
    if (!fixo) return false;
    // Tem dentro o painel: uma peça visível bem menor que o fundo.
    return [].some.call(el.querySelectorAll('*'), function (f) {
      if (f.children.length === 0 && !/^(INPUT|TEXTAREA|SELECT|BUTTON)$/.test(f.tagName)) return false;
      var fr = f.getBoundingClientRect();
      return fr.width > 160 && fr.height > 80 && (fr.width < r.width * .95 || fr.height < r.height * .95) && visivel(f);
    });
  }
  function botaoDeFechar(raiz) {
    var cands = [].filter.call(raiz.querySelectorAll('button, a, [role="button"], span, i, div'), function (b) {
      if (!visivel(b) || b.offsetWidth > 260 || b.offsetHeight > 70) return false;
      // Só o que está no pop-up de cima, não num pop-up aberto por baixo dele.
      return true;
    });
    var nota = function (b) {
      var txt = (b.textContent || '').trim(), attr = (b.getAttribute('aria-label') || '') + ' ' + (b.getAttribute('title') || '') + ' ' + String(b.className || '') + ' ' + (b.id || '');
      if (/^[×✕✖✗xX]$/.test(txt) || RE_FECHAR_ATTR.test(attr) || /\bfechar\b|\bclose\b/i.test(b.getAttribute('aria-label') || '')) return 3;
      if (/^fechar$/i.test(txt) || /^[×✕]\s*fechar$/i.test(txt)) return 2;
      if (/^(cancelar|voltar)$/i.test(txt)) return 1;
      return 0;
    };
    var melhor = null, nm = 0;
    cands.forEach(function (b) {
      var v = nota(b);
      // O de dentro ganha do de fora (o <span>× dentro do <button> fica com o botão).
      if (v > nm || (v === nm && v > 0 && melhor && melhor.contains(b) && /^(BUTTON|A)$/.test(b.tagName))) { melhor = b; nm = v; }
    });
    if (melhor && !/^(BUTTON|A)$/.test(melhor.tagName)) { var bt = melhor.closest('button, a, [role="button"]'); if (bt && raiz.contains(bt)) melhor = bt; }
    return nm > 0 ? melhor : null;
  }
  var fundoApertado = null;
  document.addEventListener('mousedown', function (e) {
    var t = e.target, fora = false;
    if (t && t.tagName === 'DIALOG') { var dr = t.getBoundingClientRect(); fora = e.clientX < dr.left || e.clientX > dr.right || e.clientY < dr.top || e.clientY > dr.bottom; }
    fundoApertado = (e.button === 0 && (fora || ehFundoDePopup(t))) ? t : null;
  }, true);
  document.addEventListener('click', function (e) {
    var fundo = fundoApertado; fundoApertado = null;
    if (!fundo || e.target !== fundo || e.button > 0) return;
    // <dialog>: o fundo é o próprio diálogo, e "fora" é o clique fora do retângulo dele.
    var b = botaoDeFechar(fundo);
    if (b) { e.stopPropagation(); e.preventDefault(); b.click(); return; }
    if (fundo.tagName === 'DIALOG' && fundo.open && typeof fundo.close === 'function') { e.stopPropagation(); fundo.close(); }
    // Sem botão de fechar reconhecível: deixa o clique seguir para o módulo, que pode ter o seu.
  }, true);

  // ── 3c. Um desenho só de pop-up: o da home ────────────────────────────
  // "O design não se refletiu em processos, em publicação, em nenhuma guia" (08/11/2026).
  // Cada módulo tem o seu pop-up (painel quase branco, seção azulada, rótulo azul, cabeçalho
  // tingido), e várias regras antigas vêm por #id. Então o desenho vai em linha, com
  // !important, pela ESTRUTURA do pop-up, não pelo nome:
  //   painel  = o cartão da home (.ini-card): prata, borda fina, sombra, 14px;
  //   cabeçalho = sem faixa, com o ícone da tela na caixa afundada (.ini-ic), título escuro;
  //   seção   = o bloco interno da home (.ini-cal / .ini-cartao): prata um tom abaixo, 12px;
  //   título de seção = o rótulo de grupo da home ("PRÓXIMOS DIAS"): pequeno, azul, maiúsculo;
  //   rótulo  = cinza do texto; campo = branco (regra 3, inn-c-campo-pop).
  var RE_CAB_POP = /(^|[-_\s])(header|head|cab|cabecalho|top|topo|titlebar)([-_\s]|$)/i;
  var RE_SEC_POP = /(^|[-_\s])(section|secao|sec|bloco|block|group|grupo|fieldset|card|box|area)([-_\s]|$)/i;
  var RE_TIT_POP = /(^|[-_\s])(title|titulo|legend|heading)([-_\s]|$)/i;
  var POP = {
    painel: { 'background': 'var(--inn-t-painel)', 'border-color': 'var(--inn-t-linha)', 'box-shadow': 'var(--inn-t-sombra)', 'border-radius': '14px', 'color': 'var(--inn-t-texto)' },
    cab: { 'background': 'transparent', 'border-color': 'transparent', 'box-shadow': 'none' },
    tit: { 'color': 'var(--inn-t-texto)', 'font-weight': '700', 'font-size': '17px', 'letter-spacing': '-.2px', 'text-transform': 'none' },
    sub: { 'color': 'var(--inn-t-texto3)', 'font-weight': '500' },
    secao: { 'background': 'var(--inn-t-painel2)', 'border-color': 'var(--inn-t-linha)', 'box-shadow': '0 1px 0 var(--inn-t-hi) inset', 'border-radius': '12px', 'color': 'var(--inn-t-texto)' },
    secTit: { 'color': 'var(--inn-t-azul2)', 'font-size': '11px', 'font-weight': '800', 'text-transform': 'uppercase', 'letter-spacing': '.08em', 'background': 'transparent', 'border-color': 'transparent', 'box-shadow': 'none' },
    rotulo: { 'color': 'var(--inn-t-texto2)', 'font-weight': '600' }
  };
  function fixar(el, nome) {
    if (el.dataset.innPop === nome) return;
    var props = POP[nome];
    Object.keys(props).forEach(function (k) { el.style.setProperty(k, props[k], 'important'); });
    el.dataset.innPop = nome;
  }
  function textoProprio(el) {
    var t = ''; for (var i = 0; i < el.childNodes.length; i++) if (el.childNodes[i].nodeType === 3) t += el.childNodes[i].textContent;
    return t.trim();
  }
  function painelDo(fundo) {
    if (fundo.tagName === 'DIALOG') return fundo;
    var melhor = null, area = 0, fr = fundo.getBoundingClientRect();
    [].forEach.call(fundo.querySelectorAll('*'), function (el) {
      var r = el.getBoundingClientRect();
      if (r.width < 240 || r.height < 120 || (r.width >= fr.width * .95 && r.height >= fr.height * .95)) return;
      // O painel é o mais alto da árvore entre os grandes: não desce para dentro dele.
      if (melhor && melhor.contains(el)) return;
      var a = r.width * r.height; if (a > area && visivel(el)) { melhor = el; area = a; }
    });
    return melhor;
  }
  function vestirPopup(fundo) {
    var painel = painelDo(fundo); if (!painel) return;
    var pr = painel.getBoundingClientRect();
    if (fundo.tagName !== 'DIALOG') fixar(painel, 'painel'); else fixar(fundo, 'painel');
    // Cabeçalho: perto do topo do painel, quase da largura dele, baixo, com texto.
    var cab = [].filter.call(painel.querySelectorAll('div, header, section'), function (el) {
      var r = el.getBoundingClientRect(), cls = String(el.className || '');
      return visivel(el) && r.top - pr.top < 40 && r.width >= pr.width * .7 && r.height < 140 && r.height > 20 && (RE_CAB_POP.test(cls) || el.tagName === 'HEADER') && (el.textContent || '').trim();
    })[0];
    if (!cab) {
      // Sem bloco de cabeçalho (Monitoramento de Publicações: H2 e P soltos no painel): o
      // título solto no alto do painel faz o papel dele.
      var solto = [].filter.call(painel.querySelectorAll('h1, h2, h3, [class*="title"], [class*="titulo"]'), function (e) {
        if (!/^H[1-3]$/.test(e.tagName) && !/title|titulo/i.test(String(e.className || '').replace(/\binn-[\w-]+/g, ''))) return false;
        return visivel(e) && e.getBoundingClientRect().top - pr.top < 80 && !/sub/i.test(String(e.className)) && !e.closest('[data-inn-pop="secao"]');
      })[0];
      if (solto) {
        fixar(solto, 'tit');
        var sub2 = solto.nextElementSibling;
        if (sub2 && visivel(sub2) && /^(P|SPAN|DIV)$/.test(sub2.tagName) && !sub2.querySelector('input, select, textarea, button') && (sub2.textContent || '').trim().length < 260) fixar(sub2, 'sub');
        iconeNoPopup(solto.parentElement, solto);
      }
    }
    if (cab) {
      fixar(cab, 'cab');
      // O título é o texto, nunca a linha que também tem botões (a gaveta da tarefa tem o
      // título editável ao lado de Concluir e Arquivar): o campo de título só ganha o ícone.
      var tit = [].filter.call(cab.querySelectorAll('h1, h2, h3, h4, [class*="title"], [class*="titulo"]'), function (e) {
        if (!/^H[1-4]$/.test(e.tagName) && !/title|titulo/i.test(String(e.className || '').replace(/\binn-[\w-]+/g, ''))) return false;
        return visivel(e) && !/sub/i.test(String(e.className)) && (/^(INPUT|TEXTAREA)$/.test(e.tagName) || !e.querySelector('button, input, textarea, select'));
      })[0];
      if (tit && /^(INPUT|TEXTAREA)$/.test(tit.tagName)) iconeNoPopup(cab, tit);
      else if (tit) {
        fixar(tit, 'tit');
        var sub = [].filter.call(cab.querySelectorAll('[class*="sub"], [class*="desc"], p'), function (e) { return visivel(e) && e !== tit && !e.contains(tit); })[0];
        if (sub) fixar(sub, 'sub');
        iconeNoPopup(cab, tit);
      }
    }
    // Seções: blocos grandes com borda (ou fundo próprio), dentro do painel, fora do cabeçalho.
    var cw = pr.width;
    [].forEach.call(painel.querySelectorAll('div, section, fieldset'), function (el) {
      if (el === cab || (cab && (cab.contains(el) || el.contains(cab)))) return;
      if (el.closest('[data-inn-pop="secao"]') && !el.matches('[data-inn-pop="secao"]')) return;
      var cls = String(el.className || '');
      if (!(RE_SEC_POP.test(cls) || el.tagName === 'FIELDSET')) return;
      if (/(^|\s)inn-(aba|selo|toast|ic-btn)|btn|button|botao|tab|aba|pill|chip|badge|tag|selo|toast|campo|field|input|select|grid|row|linha|col(\s|$|-)/i.test(cls.replace(/\binn-c-[\w-]+/g, '').replace(/(section|secao|bloco|block|group|grupo)/ig, ''))) return;
      var forte = /(^|[-_\s])(section|secao|sec|bloco|block)([-_\s]|$)/i.test(cls) || el.tagName === 'FIELDSET';
      if (!visivel(el)) return;
      var r = el.getBoundingClientRect(); if (r.width < cw * .5 || r.height < 50) return;
      var cs = getComputedStyle(el), bc = rgb(cs.borderTopColor), bg = corFundo(cs);
      var temBorda = parseFloat(cs.borderTopWidth) > 0 && cs.borderTopStyle !== 'none' && bc && bc[3] > .04;
      if (!temBorda && !bg && !forte) return;
      fixar(el, 'secao');
      // Título da seção: o primeiro texto curto e forte no alto dela, antes dos campos.
      var t = [].filter.call(el.querySelectorAll('h2, h3, h4, h5, legend, div, span, p'), function (e) {
        if (!visivel(e) || e.querySelector('input, select, textarea, button') || e.closest('button, label')) return false;
        var er = e.getBoundingClientRect(), tx = (e.textContent || '').trim();
        if (er.top - r.top > 46 || !tx || tx.length > 60 || e.children.length > 2) return false;
        var ecs = getComputedStyle(e);
        return RE_TIT_POP.test(String(e.className || '')) || /^(H[2-5]|LEGEND)$/.test(e.tagName) || parseInt(ecs.fontWeight, 10) >= 600;
      })[0];
      if (t) fixar(t, 'secTit');
    });
    // Rótulos dos campos (não o de caixa de marcar, que é o próprio texto da opção).
    [].forEach.call(painel.querySelectorAll('label, [class*="label"], [class*="rotulo"]'), function (l) {
      if (l.dataset.innPop || !visivel(l) || /(^|\s)inn-/.test(String(l.className)) || l.querySelector('input[type="checkbox"], input[type="radio"]')) return;
      var tx = textoProprio(l) || (l.children.length <= 1 ? (l.textContent || '').trim() : '');
      if (!tx || tx.length > 60 || l.closest('button, [data-inn-pop="secTit"], [data-inn-pop="tit"]')) return;
      if (l.tagName === 'LABEL' && !textoProprio(l)) { var sp = l.querySelector('span, div'); if (sp && !sp.querySelector('input, select, textarea') && (sp.textContent || '').trim().length <= 60) fixar(sp, 'rotulo'); return; }
      fixar(l, 'rotulo');
    });
  }
  // O ícone da tela, na caixa afundada da home, antes do título do pop-up.
  function iconeNoPopup(cab, tit) {
    if (cab.querySelector('.inn-cab-ic')) return;
    var ativo = document.querySelector('.sidebar nav a.active .icon svg') || document.querySelector('.sidebar .sidebar-group.has-active .sidebar-group-icon svg');
    if (!ativo) return;
    var caixa = document.createElement('span');
    caixa.className = 'inn-cab-ic inn-pop-ic';
    caixa.setAttribute('aria-hidden', 'true');
    caixa.appendChild(ativo.cloneNode(true));
    // O bloco do título (título + subtítulo) é o filho do cabeçalho que contém o título.
    if (tit.previousElementSibling && tit.previousElementSibling.classList.contains('inn-pop-linha')) return;
    var bloco = tit; while (bloco.parentElement && bloco.parentElement !== cab) bloco = bloco.parentElement;
    if (bloco.parentElement !== cab || bloco.closest('.inn-pop-linha')) return;
    var linha = document.createElement('div');
    linha.className = 'inn-pop-linha';
    linha.style.cssText = 'display:flex;align-items:center;gap:12px;min-width:0;flex:1 1 auto;';
    cab.insertBefore(linha, bloco);
    linha.appendChild(caixa); linha.appendChild(bloco);
  }
  var popTimer = null;
  function vestirPopups() {
    clearTimeout(popTimer);
    popTimer = setTimeout(function () {
      var vistos = [];
      [].forEach.call(document.querySelectorAll(SEL_POPUP + ', dialog[open]'), function (el) {
        if (!visivel(el) || vistos.some(function (v) { return v.contains(el); })) return;
        if (el.tagName === 'DIALOG' ? el.open : ehFundoDePopup(el)) { vistos.push(el); vestirPopup(el); }
      });
    }, 90);
  }
  new MutationObserver(function (ms) {
    for (var i = 0; i < ms.length; i++) {
      var t = ms[i].target;
      if (t.nodeType === 1 && (t.matches(SEL_POPUP + ', dialog') || t.closest(SEL_POPUP))) { vestirPopups(); return; }
    }
  }).observe(document.body, { attributes: true, attributeFilter: ['class', 'style', 'hidden', 'open'], childList: true, subtree: true });
  vestirPopups();

  // ── 3d. As telas com a estrutura da home ──────────────────────────────
  // "Passe nas telas" (08/11/2026), depois de os pop-ups ganharem o desenho da home. Medido
  // na home: indicador (.ini-kpi) = ícone na caixa afundada à esquerda, rótulo cinza 12px sem
  // maiúsculas, número 26px 800, rodapé 11,5px; cartão (.ini-card-h) = ícone afundado ao lado
  // do título escuro 15px 700. Nas telas o indicador tinha rótulo em maiúsculas e um ponto
  // azul no canto, e o cartão tinha uma "sobrancelha" azul em maiúsculas ou um título grande.
  // O ícone de cada peça sai do assunto do título; sem assunto conhecido, o ícone da tela.
  var SVG_IC = {
    alerta: '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
    relogio: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    agenda: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    pasta: '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
    pessoas: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    dinheiro: '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    conversa: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    documento: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
    busca: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
    grafico: '<path d="M18 20V10M12 20V4M6 20v-6"/>',
    sino: '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/>',
    escudo: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    tarefa: '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    robo: '<rect x="4" y="8" width="16" height="12" rx="2"/><path d="M12 4v4M9 13h.01M15 13h.01"/>'
  };
  var ASSUNTOS = [
    ['alerta', /vencid|atrasad|urgent|cr[ií]tic|risco|gargalo|sem moviment/i],
    ['relogio', /prazo|vence|hoje|semana|amanh|tempo|resposta|[uú]ltima (altera|atualiza)/i],
    ['agenda', /calend|agenda|data|m[eê]s|period/i],
    ['dinheiro', /receita|custo|lucro|valor|honor|imposto|financ|margem|r\$|mensal|faturamento|cobran|despesa|lan[çc]amento|ticket/i],
    ['pessoas', /client|lead|usu[aá]ri|equipe|advogad|respons|pessoa|admin|escrit[oó]rio|v[ií]ncul|associad/i],
    ['conversa', /convers|chat|whats|mensag|atendim|n[aã]o lidas|n[uú]mero|canal/i],
    ['documento', /public|intima|di[aá]rio|djen|document|contrato|relat|termo|perfil de busca|monitora/i],
    ['robo', /agente|prompt|provedor|\bia\b|ia ativa/i],
    ['sino', /notifica|alerta/i],
    ['escudo', /segur|acesso|permiss|senha/i],
    ['tarefa', /tarefa|checklist|conclu|recorr/i],
    ['pasta', /processo|carteira|ativo|encerr|arquiv|jur[ií]dic|setor/i],
    ['grafico', /distribui|carga|gr[aá]fic|indicador|taxa|desempenho|status|composi|convers[aã]o|funil|meta|resumo|simula|total/i],
    ['busca', /buscar|filtro|busca/i]
  ];
  function iconeDoAssunto(texto) {
    for (var i = 0; i < ASSUNTOS.length; i++) if (ASSUNTOS[i][1].test(texto)) {
      var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 24 24'); svg.innerHTML = SVG_IC[ASSUNTOS[i][0]];
      return svg;
    }
    var ativo = document.querySelector('.sidebar nav a.active .icon svg') || document.querySelector('.sidebar .sidebar-group.has-active .sidebar-group-icon svg');
    return ativo ? ativo.cloneNode(true) : null;
  }
  function emLinha(el, props) { Object.keys(props).forEach(function (k) { el.style.setProperty(k, props[k], 'important'); }); }
  var RE_KPI = /(^|[-_\s])(kpi|kpi-card|stat|stat-card|metric|metrica|indicador|resumo-card|o-kpi|chat-kpi)([-_\s]|$)|kpi-card|-kpi(\s|$)/i;
  // Mede contra a borda de dentro do cartão (o próprio número cresce junto com o texto e
  // nunca parece transbordar), e de novo quando ele termina de contar (os números entram
  // contando de 0, e o texto final só existe no fim).
  function caber(valor) {
    var k = valor.closest('[data-inn-kpi]') || valor.parentElement; if (!k) return;
    var kr = k.getBoundingClientRect(), kcs = getComputedStyle(k);
    var limite = kr.right - parseFloat(kcs.paddingRight) - parseFloat(kcs.borderRightWidth) - 2;
    var tamanhos = [26, 24, 22, 20, 18, 16, 14];
    valor.style.setProperty('min-width', '0', 'important');
    for (var i = 0; i < tamanhos.length; i++) {
      valor.style.setProperty('font-size', tamanhos[i] + 'px', 'important');
      if (fimDoTexto(valor) <= limite) return;
    }
  }
  // Onde o texto termina de verdade (a caixa do número vai até a borda do cartão).
  function fimDoTexto(el) {
    var r = document.createRange(); r.selectNodeContents(el);
    return r.getBoundingClientRect().right;
  }
  function vigiarNumero(valor) {
    var t = null;
    new MutationObserver(function () { clearTimeout(t); t = setTimeout(function () { caber(valor); }, 120); }).observe(valor, { characterData: true, childList: true, subtree: true });
    setTimeout(function () { caber(valor); }, 1500);
    setTimeout(function () { caber(valor); }, 3200);
  }
  function vestirIndicador(k) {
    if (k.dataset.innKpi) return;
    k.dataset.innKpi = '1';
    // Já tem ícone (Chat): só o texto entra na medida da home.
    var jaTemIcone = !!k.querySelector('svg, img');
    var filhos = [].filter.call(k.children, function (c) { return visivel(c) && !c.classList.contains('inn-onda-quadro'); });
    if (filhos.length === 1 && filhos[0].children.length >= 2 && !/^(B|STRONG)$/.test(filhos[0].tagName)) filhos = [].filter.call(filhos[0].children, visivel);
    var ponto = filhos.filter(function (c) { return c.offsetWidth <= 12 && c.offsetHeight <= 12 && !(c.textContent || '').trim(); });
    ponto.forEach(function (c) { emLinha(c, { 'display': 'none' }); });
    filhos = filhos.filter(function (c) { return ponto.indexOf(c) < 0; });
    var valor = null, maior = 0;
    filhos.forEach(function (c) { var fs = parseFloat(getComputedStyle(c).fontSize); if ((c.textContent || '').trim() && fs > maior) { maior = fs; valor = c; } });
    // Valor pequeno ("—" em Última alteração) ainda é indicador quando a peça é um cartão.
    if (!valor || (maior < 18 && !corFundo(getComputedStyle(k)))) return;
    var i = filhos.indexOf(valor), rotulo = filhos.slice(0, i).filter(function (c) { return (c.textContent || '').trim(); })[0];
    if (rotulo) emLinha(rotulo, { 'font-size': '12px', 'font-weight': '600', 'text-transform': 'none', 'letter-spacing': 'normal', 'color': 'var(--inn-t-texto3)' });
    var cv = tomDeCor(getComputedStyle(valor).color, .3, .05);
    // 26px como na home; se o número não cabe inteiro na linha (R$ 128.660,00 em Indicadores,
    // o WhatsApp do Agente), desce até caber. Nunca cortado, nunca quebrado no meio.
    emLinha(valor, { 'font-weight': '800', 'letter-spacing': '-.6px', 'line-height': '1.1', 'white-space': 'nowrap' });
    valor.dataset.innKpiValor = '1'; caber(valor); vigiarNumero(valor);
    if (!cv || cv === 'azul' || cv === 'outro') emLinha(valor, { 'color': 'var(--inn-t-texto)' });
    filhos.slice(i + 1).forEach(function (c) { if (!c.querySelector('.inn-selo, [class*="badge"], [class*="tag"]')) emLinha(c, { 'font-size': '11.5px', 'color': 'var(--inn-t-texto3)' }); });
    if (jaTemIcone || !rotulo) return;
    var svg = iconeDoAssunto((rotulo.textContent || '') + ' ' + (k.textContent || '')); if (!svg) return;
    var caixa = document.createElement('span');
    caixa.className = 'inn-kpi-ic'; caixa.setAttribute('aria-hidden', 'true'); caixa.appendChild(svg);
    var pai = rotulo.parentElement;
    pai.insertBefore(caixa, pai.firstChild);
    // A grade da home: ícone à esquerda, ocupando as linhas do texto.
    var linhas = [].filter.call(pai.children, function (c) { return c !== caixa && visivel(c); }).length || 1;
    emLinha(pai, { 'display': 'grid', 'grid-template-columns': 'auto minmax(0, 1fr)', 'column-gap': '14px', 'row-gap': '0', 'align-items': 'center', 'align-content': 'center' });
    emLinha(caixa, { 'grid-column': '1', 'grid-row': '1 / span ' + linhas });
    [].forEach.call(pai.children, function (c) { if (c !== caixa) emLinha(c, { 'grid-column': '2' }); });
  }
  function vestirCartaoDaTela(p) {
    if (p.dataset.innCartao) return;
    var pr = p.getBoundingClientRect();
    var t = [].filter.call(p.querySelectorAll('h1, h2, h3, h4, [class*="title"], [class*="titulo"], [class*="eyebrow"], [class*="heading"]'), function (e) {
      // A marca inn-c-titulo também contém "titulo": só vale o nome que a tela deu.
      if (!/^H[1-4]$/.test(e.tagName) && !/title|titulo|eyebrow|heading/i.test(String(e.className || '').replace(/\binn-[\w-]+/g, ''))) return false;
      if (!visivel(e) || e.closest('.page-header, .inn-cab-ic, button, label, table, [data-inn-cartao], .inn-kpi-ic') || e.querySelector('input, select, textarea, button, table')) return false;
      var r = e.getBoundingClientRect(), tx = (e.textContent || '').trim();
      return r.top - pr.top < 52 && r.left - pr.left < 60 && tx && tx.length <= 70 && e.children.length <= 3;
    })[0];
    p.dataset.innCartao = '1';
    if (!t) return;
    // Título com ícone próprio (Impostos sobre a Receita) fica como está, só na medida.
    var temIcone = t.querySelector('svg') || (t.previousElementSibling && t.previousElementSibling.querySelector && (t.previousElementSibling.tagName === 'svg' || t.previousElementSibling.querySelector('svg')) && t.previousElementSibling.offsetWidth < 48);
    emLinha(t, { 'font-size': '15px', 'font-weight': '700', 'letter-spacing': '-.2px', 'text-transform': 'none', 'color': 'var(--inn-t-texto)', 'line-height': '1.25' });
    var sub = t.nextElementSibling;
    if (sub && visivel(sub) && /^(P|SPAN|SMALL|DIV)$/.test(sub.tagName) && !sub.querySelector('input, select, textarea, button, table') && (sub.textContent || '').trim().length < 200 && sub.getBoundingClientRect().height < 48) emLinha(sub, { 'font-size': '12px', 'font-weight': '600', 'color': 'var(--inn-t-texto3)', 'text-transform': 'none', 'letter-spacing': 'normal' });
    if (temIcone || t.closest('.inn-card-linha')) return;
    var svg = iconeDoAssunto((t.textContent || '') + ' ' + (sub ? sub.textContent : '')); if (!svg) return;
    var caixa = document.createElement('span');
    caixa.className = 'inn-card-ic'; caixa.setAttribute('aria-hidden', 'true'); caixa.appendChild(svg);
    // Ícone ao lado do bloco título + subtítulo, como na home.
    var linha = document.createElement('div');
    linha.className = 'inn-card-linha';
    linha.style.cssText = 'display:flex;align-items:center;gap:12px;min-width:0;';
    var temSub = sub && sub.style.getPropertyValue('font-size') === '12px';
    t.parentElement.insertBefore(linha, t);
    var bloco = document.createElement('div');
    bloco.style.cssText = 'display:flex;flex-direction:column;gap:2px;min-width:0;';
    linha.appendChild(caixa); linha.appendChild(bloco);
    bloco.appendChild(t); if (temSub) bloco.appendChild(sub);
    emLinha(t, { 'margin': '0' }); if (temSub) emLinha(sub, { 'margin': '0' });
  }
  var telaTimer = null;
  function vestirTela() {
    clearTimeout(telaTimer);
    telaTimer = setTimeout(function () {
      var tela = document.querySelector('.inn-tela'); if (!tela) return;
      [].forEach.call(tela.querySelectorAll('div, section, article, a'), function (el) {
        if (el.closest(SEL_POPUP) || el.closest('.sidebar, .page-header')) return;
        var cls = String(el.className || ''); if (typeof el.className !== 'string') return;
        // A grade que embrulha os indicadores (kpi-grid-6) não é indicador.
        if (RE_KPI.test(cls) && !/(label|value|valor|foot|dot|ic|icon)$/i.test(cls.split(/\s+/)[0]) && !/(^|[-_\s])(grid|row|wrap|lista|list|kpis|linha|area)([-_\s\d]|$)/i.test(cls) && visivel(el)) { vestirIndicador(el); return; }
      });
      // Cartões: os painéis de primeiro nível da tela (fundo próprio e borda), sem os de dentro.
      var paineis = [].filter.call(tela.querySelectorAll('div, section, article, aside, form'), function (el) {
        if (!visivel(el) || el.closest(SEL_POPUP) || el.closest('.page-header') || el.dataset.innKpi || el.closest('[data-inn-kpi]')) return false;
        var r = el.getBoundingClientRect(); if (r.width < 260 || r.height < 70) return false;
        var cs = getComputedStyle(el), bg = corFundo(cs), bc = rgb(cs.borderTopColor);
        return !!bg && parseFloat(cs.borderTopWidth) > 0 && bc && bc[3] > .04 && parseFloat(cs.borderTopLeftRadius) >= 8 && !/col(\s|$|-)|coluna|lane|card-mini|cli-card|proc-card|tk-card|setor-col|kanban|item|row|linha/i.test(String(el.className));
      });
      paineis.forEach(function (p) { if (!paineis.some(function (o) { return o !== p && o.contains(p); })) vestirCartaoDaTela(p); });
    }, 120);
  }
  new MutationObserver(function (ms) {
    for (var i = 0; i < ms.length; i++) if (ms[i].addedNodes.length) { var t = ms[i].target; if (t.nodeType === 1 && t.closest && t.closest('.inn-tela') && !t.closest(SEL_POPUP)) { vestirTela(); return; } }
  }).observe(document.body, { childList: true, subtree: true });
  vestirTela();
  var caberTimer = null;
  addEventListener('resize', function () { clearTimeout(caberTimer); caberTimer = setTimeout(function () { [].forEach.call(document.querySelectorAll('[data-inn-kpi-valor]'), caber); }, 150); });

  // ── 4. A onda do toque ──────────────────────────────────────────────────
  var SEL_TOQUE = 'button, .btn, a[class*="btn"], [role="tab"], .inn-aba, .tk-card, .cli-card, .proc-card, .lead-card, .kanban-card, .kpi-card, .o-card[onclick], .chat-item, .int-card, .tk-periodo, .chat-filter-btn';
  document.addEventListener('pointerdown', function (e) {
    if (semMovimento || e.button > 0 || !e.target.closest) return;
    var el = e.target.closest(SEL_TOQUE);
    if (!el || el.disabled || el.closest('.sidebar, .ini')) return;
    var r = el.getBoundingClientRect();
    if (r.width < 8 || r.height < 8) return;
    var cs = getComputedStyle(el);
    var q = document.createElement('div');
    q.className = 'inn-onda-quadro';
    q.style.cssText = 'left:' + r.left + 'px;top:' + r.top + 'px;width:' + r.width + 'px;height:' + r.height + 'px;border-radius:' + cs.borderRadius;
    var i = document.createElement('i');
    var raio = Math.ceil(Math.hypot(Math.max(e.clientX - r.left, r.right - e.clientX), Math.max(e.clientY - r.top, r.bottom - e.clientY)));
    i.style.cssText = 'left:' + (e.clientX - r.left) + 'px;top:' + (e.clientY - r.top) + 'px;width:' + (raio * 2) + 'px;height:' + (raio * 2) + 'px';
    q.appendChild(i);
    document.body.appendChild(q);
    setTimeout(function () { q.remove(); }, 600);
  }, true);

  // ── 5. Gráficos ─────────────────────────────────────────────────────────
  // Paleta: azul e prata do logo. Vermelho, verde e âmbar ficam quando já eram (são estado).
  var PALETA = ['#3A63C9', '#9AA3B0', '#23448F', '#6C90E3', '#5B6575', '#B4BAC5', '#2D4FA8', '#C9CFD9'];
  function rgb(c) {
    if (typeof c !== 'string') return null;
    var m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(c.trim());
    if (m) { var h = m[1].length === 3 ? m[1].replace(/./g, '$&$&') : m[1]; return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16), 1]; }
    m = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+%?))?\s*\)$/i.exec(c.trim());
    if (m) return [+m[1], +m[2], +m[3], m[4] == null ? 1 : (String(m[4]).indexOf('%') > 0 ? parseFloat(m[4]) / 100 : +m[4])];
    return null;
  }
  function matiz(r, g, b) {
    var mx = Math.max(r, g, b) / 255, mn = Math.min(r, g, b) / 255, d = mx - mn, h = 0;
    if (d === 0) return { h: 0, s: 0, l: (mx + mn) / 2 };
    var l = (mx + mn) / 2, s = d / (1 - Math.abs(2 * l - 1));
    r /= 255; g /= 255; b /= 255;
    if (mx === r) h = 60 * (((g - b) / d) % 6); else if (mx === g) h = 60 * ((b - r) / d + 2); else h = 60 * ((r - g) / d + 4);
    return { h: (h + 360) % 360, s: s, l: l };
  }
  function semantica(hsl) {
    if (hsl.s < .25) return null;
    if (hsl.h < 18 || hsl.h >= 340) return '#C2554F';       // vermelho, apagado
    if (hsl.h >= 28 && hsl.h < 55) return '#C08A2E';        // âmbar, apagado
    if (hsl.h >= 85 && hsl.h < 165) return '#3F8F6A';       // verde, apagado
    return null;
  }
  function recolor(c, k) {
    if (Array.isArray(c)) return c.map(function (x, j) { return recolor(x, j); });
    var v = rgb(c); if (!v) return c;
    var hsl = matiz(v[0], v[1], v[2]);
    var alvo = semantica(hsl) || (hsl.s < .12 ? '#9AA3B0' : PALETA[k % PALETA.length]);
    var a = rgb(alvo);
    return 'rgba(' + a[0] + ',' + a[1] + ',' + a[2] + ',' + v[3] + ')';
  }
  function vestirGrafico(ch) {
    if (!ch || !ch.config || ch.$innVestido) return;
    ch.$innVestido = true;
    var data = ch.config.data || {};
    (data.datasets || []).forEach(function (ds, k) {
      ['backgroundColor', 'borderColor', 'pointBackgroundColor', 'hoverBackgroundColor', 'hoverBorderColor', 'pointBorderColor'].forEach(function (p) {
        if (ds[p] != null && typeof ds[p] !== 'function' && typeof ds[p] !== 'object' || Array.isArray(ds[p])) ds[p] = recolor(ds[p], k);
      });
      var tipo = ds.type || ch.config.type;
      if (tipo === 'bar' && ds.borderRadius == null) ds.borderRadius = 6;
    });
    // As opções do Chart.js são proxies: nada de copiar objeto inteiro (recursão); só se
    // troca propriedade por propriedade, no que já existe, como a documentação manda.
    var v = vars(), o = ch.options;
    try {
      var tt = o && o.plugins && o.plugins.tooltip;
      if (tt) { tt.backgroundColor = v.painel; tt.titleColor = v.texto; tt.bodyColor = v.texto; tt.borderColor = v.linha2; tt.borderWidth = 1; tt.cornerRadius = 8; tt.padding = 10; tt.usePointStyle = true; }
      var lg = o && o.plugins && o.plugins.legend && o.plugins.legend.labels;
      if (lg) { lg.color = v.texto3; lg.usePointStyle = true; }
      var esc = (o && o.scales) || {};
      Object.keys(esc).forEach(function (k) {
        var s = esc[k]; if (!s) return;
        if (s.grid && s.grid.display !== false) s.grid.color = v.linha;
        if (s.ticks) s.ticks.color = v.texto3;
        if (s.border) s.border.color = v.linha;
      });
      if (!semMovimento && o && o.animation) {
        o.animation.duration = 900;
        o.animation.easing = 'easeOutQuart';
        o.animation.delay = function (ctx) { return ctx.type === 'data' && ctx.mode === 'default' ? ctx.dataIndex * 45 + ctx.datasetIndex * 90 : 0; };
      }
    } catch (e) {}
    try { ch.update(); } catch (e) {}
  }
  function vestirChartJs() {
    var C = window.Chart;
    if (!C || C.$innLigado) return !!C;
    C.$innLigado = true;
    var v = vars();
    try {
      C.defaults.font.family = 'Inter, system-ui, -apple-system, "Segoe UI", sans-serif';
      C.defaults.color = v.texto3;
      C.defaults.borderColor = v.linha;
    } catch (e) {}
    try { C.register({ id: 'innYuris', beforeInit: function (ch) { setTimeout(function () { vestirGrafico(ch); }, 0); } }); } catch (e) {}
    var lista = C.instances ? (Array.isArray(C.instances) ? C.instances : Object.keys(C.instances).map(function (k) { return C.instances[k]; })) : [];
    lista.forEach(vestirGrafico);
    return true;
  }

  // ── Ligar ───────────────────────────────────────────────────────────────
  function ligar() {
    var main = acharTela() || document.body;
    iconeNoCabecalho(); botoesDeIcone(document.body); fantasmas(main); marcarAbas(main); selos(document.body); vestirCores(document.body);
    if (!vestirChartJs()) {
      // Chart.js chega depois em algumas telas: espera por ele um pouco.
      var n = 0, t = setInterval(function () { if (vestirChartJs() || ++n > 40) clearInterval(t); }, 250);
    }
    var pendente = false;
    new MutationObserver(function (ms) {
      ms.forEach(function (m) {
        if (m.type === 'attributes') {
          var alvo = m.target;
          if (alvo.classList && alvo.classList.contains('inn-aba')) alvo.classList.toggle('inn-aba-on', abaLigada(alvo));
          // Selo que trocou de estado (a tela mudou a classe dele): lê a cor de novo.
          if (alvo.dataset && alvo.dataset.innSelo != null && m.attributeName === 'class' && baseSelo(alvo) !== alvo.dataset.innSelo) vestirSelo(alvo);
          if (alvo.dataset && alvo.dataset.innCor != null && m.attributeName === 'class' && baseSelo(alvo) !== alvo.dataset.innBase) revestirCores(alvo);
          // O que estava escondido e apareceu (display:none que virou flex) ainda não foi lido.
          // O que estava escondido e apareceu (display:none que virou flex, classe "visible" que chegou) ainda não foi lido.
          if ((m.attributeName === 'style' || m.attributeName === 'hidden' || m.attributeName === 'class') && alvo.dataset && alvo.dataset.innCor == null && alvo.dataset.innSelo == null && !alvo.classList.contains('inn-aba')) vestirCores(alvo);
          return;
        }
        [].forEach.call(m.addedNodes, function (no) { if (no.nodeType === 1) { botoesDeIcone(no); fantasmas(no); marcarAbas(no); selos(no); vestirCores(no); } });
      });
      if (!pendente) { pendente = true; requestAnimationFrame(function () { pendente = false; conferirFantasmas(); }); }
    }).observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'aria-selected', 'aria-pressed', 'style', 'hidden'] });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ligar); else ligar();
  window.addEventListener('load', function () { vestirChartJs(); });
})();
