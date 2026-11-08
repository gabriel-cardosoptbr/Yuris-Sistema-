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
  var RE_ABA = /(^|[-_])(tab|mtab|pill|chip|seg|segment|filtro|filter|view|periodo|period|modo|opcao)(s)?(-?btn|-?item|-?opt)?$/i;
  var RE_NAO = /pane|panel|content|body|wrap|list|bar|group|container|header|grid|row|col|area|box/i;
  var RE_ON  = /^(active|ativo|ativa|selected|is-active|is-selected|current|on|sel|atual)$|-(active|ativo|ativa|selected|on)$/i;
  function ehAba(el) {
    if (el.getAttribute('role') === 'tab') return true;
    var cls = String(el.className && el.className.baseVal != null ? el.className.baseVal : el.className || '').split(/\s+/);
    return cls.some(function (c) { return RE_ABA.test(c) && !RE_NAO.test(c); });
  }
  function abaLigada(el) {
    if (el.getAttribute('aria-selected') === 'true' || el.getAttribute('aria-pressed') === 'true') return true;
    return String(el.className || '').split(/\s+/).some(function (c) { return RE_ON.test(c); });
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
    iconeNoCabecalho(); botoesDeIcone(document.body); fantasmas(main); marcarAbas(main);
    if (!vestirChartJs()) {
      // Chart.js chega depois em algumas telas: espera por ele um pouco.
      var n = 0, t = setInterval(function () { if (vestirChartJs() || ++n > 40) clearInterval(t); }, 250);
    }
    var pendente = false;
    new MutationObserver(function (ms) {
      ms.forEach(function (m) {
        if (m.type === 'attributes') { if (m.target.classList && m.target.classList.contains('inn-aba')) m.target.classList.toggle('inn-aba-on', abaLigada(m.target)); return; }
        [].forEach.call(m.addedNodes, function (no) { if (no.nodeType === 1) { botoesDeIcone(no); fantasmas(no); marcarAbas(no); } });
      });
      if (!pendente) { pendente = true; requestAnimationFrame(function () { pendente = false; conferirFantasmas(); }); }
    }).observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'aria-selected', 'aria-pressed'] });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ligar); else ligar();
  window.addEventListener('load', function () { vestirChartJs(); });
})();
