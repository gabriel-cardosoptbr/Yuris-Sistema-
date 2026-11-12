/* ═══════════════════════════════════════════════════════════════════════════
   interface-nova-contraste.js: nenhum texto abaixo do mínimo de leitura
   (11/11/2026, "escuro com escuro, não dá para ler").

   As telas de dentro trazem dezenas de cores fixas da própria folha (o contador
   da coluna de Tarefas em #7A8898, a data do prazo em #5A6878, o "Quente" da
   Prospecção em vinho, os rótulos do formulário...). A camada troca o fundo para
   o grafite ou a prata da casa, e o texto que combinava com o fundo antigo passa
   a ler 2,5 a 4:1. Corrigir uma a uma não acaba, e tela nova volta a quebrar.

   A REGRA: todo texto visível é medido contra o fundo que está de fato atrás dele
   (as camadas de fundo somadas até a primeira opaca). Abaixo de 4,5:1 (3:1 para
   texto grande: 18,5 px, ou 14 px em negrito), a cor troca pela cor de texto da
   casa do mesmo tom, a primeira que passar:

     cinza ....... texto3, texto2, texto
     azul ........ azul-texto, texto
     vermelho .... erro-texto, erro-forte, texto
     âmbar ....... aviso, aviso-forte, texto
     verde ....... ok, ok-forte, texto

   A troca é uma classe (inn-ct-<variável>), então ela segue o tema; trocar o
   tema refaz a conta. Seleção, item ativo e pop-up de fora não entram. Só nas
   telas de dentro: a home tem as variáveis próprias já no mínimo (inicio.css).
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var html = document.documentElement;
  var CANDIDATOS = {
    cinza:    ['texto3', 'texto2', 'texto'],
    azul:     ['azul-texto', 'texto'],
    vermelho: ['erro-texto', 'erro-forte', 'texto'],
    ambar:    ['aviso', 'aviso-forte', 'texto'],
    verde:    ['ok', 'ok-forte', 'texto']
  };
  var TODAS = ['texto3', 'texto2', 'texto', 'azul-texto', 'erro-texto', 'erro-forte', 'aviso', 'aviso-forte', 'ok', 'ok-forte', 'branco', 'preto'];
  // Último recurso: fundo que a tela deixou escuro no tema claro (a pílula do "0s" no Chat, o aviso
  // de desconexão) ou claro no escuro. Nenhuma cor da casa passa; vale o branco ou o quase preto.
  var FIXAS = { branco: [255, 255, 255, 1], preto: [17, 20, 26, 1] };
  var FORA = '#yuris-cookie-banner, #yuris-cookie-prefs, script, style, noscript, [hidden], svg, canvas, .sidebar, .mobile-tabbar, .yuris-notif-badge';
  // O que está selecionado ou ativo tem fundo cheio e texto claro de propósito.
  var SELECIONADO = '.active, .ativo, .sel, .selected, .on, [aria-selected="true"], [aria-pressed="true"], .inn-aba-ligada';

  function rgba(c) {
    var m = String(c || '').match(/rgba?\(([^)]+)\)/); if (!m) return null;
    var p = m[1].split(/[,\s/]+/).filter(Boolean).map(Number);
    return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1];
  }
  function hexOuRgb(v) {
    v = String(v || '').trim();
    if (v.charAt(0) === '#') {
      var h = v.slice(1); if (h.length === 3) h = h.replace(/./g, '$&$&');
      return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16), 1];
    }
    return rgba(v);
  }
  function lum(c) {
    var a = c.slice(0, 3).map(function (v) { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); });
    return .2126 * a[0] + .7152 * a[1] + .0722 * a[2];
  }
  function mistura(t, b) { var a = t[3]; return [t[0] * a + b[0] * (1 - a), t[1] * a + b[1] * (1 - a), t[2] * a + b[2] * (1 - a), 1]; }
  function razao(fg, bg) { var a = lum(mistura(fg, bg)), b = lum(bg); return (Math.max(a, b) + .05) / (Math.min(a, b) + .05); }
  function claro() { return html.getAttribute('data-theme') === 'light'; }

  // O fundo de verdade: as camadas somadas de dentro para fora até a primeira opaca.
  function fundo(el) {
    var pilha = [], e = el;
    while (e && e.nodeType === 1) {
      var cs = getComputedStyle(e), c = rgba(cs.backgroundColor);
      var img = cs.backgroundImage;
      if (img && img.indexOf('url(') !== -1) return null;   // foto atrás: não dá para medir
      if (img && img.indexOf('gradient') !== -1) { var m = img.match(/rgba?\([^)]+\)/g); if (m) c = rgba(m[m.length - 1]); }
      if (c && c[3] > 0) { pilha.push(c); if (c[3] >= .99) break; }
      e = e.parentElement;
    }
    var b = claro() ? [230, 232, 235, 1] : [16, 19, 23, 1];
    for (var i = pilha.length - 1; i >= 0; i--) b = mistura(pilha[i], b);
    return b;
  }

  function tom(c) {
    var r = c[0] / 255, g = c[1] / 255, b = c[2] / 255, mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn;
    var l = (mx + mn) / 2, s = d === 0 ? 0 : d / (1 - Math.abs(2 * l - 1));
    if (s < .22 || d < .08) return 'cinza';
    var h = mx === r ? ((g - b) / d) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4;
    h = (h * 60 + 360) % 360;
    if (h < 22 || h >= 330) return 'vermelho';
    if (h < 65) return 'ambar';
    if (h < 170) return 'verde';
    if (h < 290) return 'azul';
    return 'vermelho';   // vinho e rosa
  }

  var cores = {};
  function lerCores() {
    var cs = getComputedStyle(html);
    TODAS.forEach(function (n) { cores[n] = FIXAS[n] || hexOuRgb(cs.getPropertyValue('--inn-t-' + n)); });
  }

  function textoProprio(el) {
    for (var n = el.firstChild; n; n = n.nextSibling) if (n.nodeType === 3 && n.nodeValue.trim()) return true;
    return false;
  }
  function limpar(el) {
    TODAS.forEach(function (n) { el.classList.remove('inn-ct-' + n); });
    // Cor que a guarda gravou no elemento: volta a que estava.
    if (el.dataset.innCtAntes != null) {
      if (el.dataset.innCtAntes) el.style.setProperty('color', el.dataset.innCtAntes, 'important'); else el.style.removeProperty('color');
      delete el.dataset.innCtAntes;
    }
    delete el.dataset.innCt; delete el.dataset.innCtSig;
  }
  // A camada (porCimaDoId) e algumas telas gravam a cor no próprio elemento com !important, que
  // nenhuma folha vence: aí a guarda grava também, guardando a de antes para devolver.
  function aplicar(el, nome, noElemento) {
    if (noElemento) {
      if (el.dataset.innCtAntes == null) el.dataset.innCtAntes = el.style.getPropertyValue('color') || '';
      var f = FIXAS[nome];
      el.style.setProperty('color', f ? 'rgb(' + f.slice(0, 3).join(',') + ')' : 'var(--inn-t-' + nome + ')', 'important');
    } else el.classList.add('inn-ct-' + nome);
    el.dataset.innCt = nome;
  }

  // A medição vale para a cor e o fundo daquele momento: a camada pinta depois, a tela troca
  // o estado. Mudou um dos dois, mede de novo (o "✕" de Tarefas era medido antes de vestir).
  function conferir(el) {
    if (!textoProprio(el)) return;
    if (el.closest(FORA) || el.closest(SELECIONADO)) return;
    var r = el.getBoundingClientRect(); if (!r.width || !r.height) return;
    var cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || +cs.opacity < .5) return;
    var noElemento = el.style.getPropertyPriority('color') === 'important';
    var fg = rgba(cs.color), bg = fundo(el); if (!fg || !bg) return;
    var sig = cs.color + '|' + bg.map(Math.round).join(',');
    if (el.dataset.innCtSig === sig) return;
    el.dataset.innCtSig = sig;
    var grande = parseFloat(cs.fontSize) >= 18.5 || (parseFloat(cs.fontSize) >= 14 && +cs.fontWeight >= 700);
    var minimo = grande ? 3 : 4.5;
    if (razao(fg, bg) >= minimo) { if (!el.dataset.innCt) el.dataset.innCt = '1'; return; }
    limpar(el);
    var lista = CANDIDATOS[tom(fg)];
    for (var i = 0; i < lista.length; i++) {
      var c = cores[lista[i]];
      if (c && razao(c, bg) >= minimo) { aplicar(el, lista[i], noElemento); break; }
    }
    if (i === lista.length) aplicar(el, razao(FIXAS.branco, bg) >= razao(FIXAS.preto, bg) ? 'branco' : 'preto', noElemento);
    el.dataset.innCtSig = getComputedStyle(el).color + '|' + bg.map(Math.round).join(',');
  }

  // Mede a cor FINAL: no meio de uma transição (botão com "color .15s", a camada acabando de
  // pintar) a cor lida é a de passagem. Sem transição por um instante, dentro do mesmo quadro
  // (nada é pintado nesse meio), e ela volta.
  var semTr = document.createElement('style');
  // :is() com um id dá força de id: vence as transições !important da camada (aba, botão), que
  // são por classe. Mexer no style de cada elemento acordaria a camada, que observa o atributo.
  semTr.textContent = ':is(#inn-ct-medindo, *), :is(#inn-ct-medindo, *)::before, :is(#inn-ct-medindo, *)::after{ transition:none !important; }';
  function medindo(fn) {
    document.head.appendChild(semTr); void html.offsetWidth;
    try { fn(); } finally { semTr.remove(); }
  }

  // ── Passadas: o que está na tela primeiro, o resto em tempo ocioso ─────────
  var ocioso = window.requestIdleCallback || function (f) { return setTimeout(function () { f({ timeRemaining: function () { return 8; } }); }, 50); };
  var fila = [], pedida = false;
  function varrer() {
    lerCores();
    var raiz = document.querySelector('.inn-tela') || document.querySelector('main') || document.body;
    var todos = [].slice.call(raiz.querySelectorAll('*'));
    // Também o que mora fora da tela: pop-ups e peças soltas no corpo da página (o contador de
    // sincronização do Chat flutua direto no <body>). O menu fica de fora.
    [].forEach.call(document.body.children, function (p) {
      if (p === raiz || p.contains(raiz) || raiz.contains(p) || /^(SCRIPT|STYLE|NOSCRIPT|LINK|TEMPLATE)$/.test(p.tagName) || p.matches('.sidebar, .mobile-tabbar, #yuris-cookie-banner, #yuris-cookie-prefs')) return;
      todos.push(p); [].push.apply(todos, p.querySelectorAll('*'));
    });
    [].forEach.call(document.querySelectorAll('[role="dialog"], [class*="modal"], [id*="modal"], [id*="Modal"], [class*="drawer"]'), function (p) { if (!raiz.contains(p) && !p.closest('.sidebar')) [].push.apply(todos, p.querySelectorAll('*')); });
    todos = Array.from(new Set(todos));
    var dentro = [], fora = [];
    todos.forEach(function (el) {
      if (!textoProprio(el)) return;
      var r = el.getBoundingClientRect();
      (r.bottom > -40 && r.top < innerHeight + 40 ? dentro : fora).push(el);
    });
    medindo(function () { dentro.forEach(conferir); });
    fila = fora;
    if (!pedida && fila.length) { pedida = true; ocioso(trabalhar); }
  }
  function trabalhar(prazo) {
    pedida = false;
    var feitos = 0;
    medindo(function () {
      while (fila.length && (prazo.timeRemaining() > 2 || feitos < 60)) { var el = fila.shift(); feitos++; if (el.isConnected) conferir(el); }
    });
    if (fila.length) { pedida = true; ocioso(trabalhar); }
  }
  var timer = null;
  function agendar(ms) { if (timer) return; timer = setTimeout(function () { timer = null; varrer(); }, ms); }

  // A camada das telas veste as cores primeiro; a conta vem depois dela.
  document.addEventListener('inn:revelada', function () { agendar(250); });
  agendar(1500);
  // Conteúdo novo (lista que chega, pop-up que abre, rolagem que revela o resto) e peça que a TELA
  // mostra trocando a classe (o aviso de desconexão do Chat ganha "visible"). Classe que só mudou nas
  // marcas inn-* é da camada ou desta guarda: não conta, senão uma passada acordaria a outra.
  function semMarcas(c) { return String(c || '').split(/\s+/).filter(function (x) { return x && x.indexOf('inn-') !== 0; }).sort().join(' '); }
  try {
    new MutationObserver(function (ms) {
      for (var i = 0; i < ms.length; i++) {
        var m = ms[i];
        if (m.type === 'childList' && m.addedNodes.length) { agendar(400); return; }
        if (m.type === 'attributes' && m.attributeName === 'hidden') { agendar(400); return; }
        if (m.type === 'attributes' && m.attributeName === 'class' && semMarcas(m.oldValue) !== semMarcas(m.target.getAttribute('class'))) { agendar(400); return; }
      }
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeOldValue: true, attributeFilter: ['class', 'hidden'] });
  } catch (e) {}
  addEventListener('scroll', function () { agendar(300); }, { passive: true, capture: true });
  document.addEventListener('click', function () { agendar(500); }, true);
  // Trocou o tema: as contas são outras.
  try {
    new MutationObserver(function () {
      [].forEach.call(document.querySelectorAll('[data-inn-ct]'), limpar);
      agendar(150);
    }).observe(html, { attributes: true, attributeFilter: ['data-theme'] });
  } catch (e) {}

  window.InnContraste = { varrer: varrer, conferir: conferir, razao: razao, tom: tom, fundo: fundo };
})();
