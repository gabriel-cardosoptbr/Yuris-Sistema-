/* ═══════════════════════════════════════════════════════════════════════════
   interface-nova-movimento.js: o movimento das telas de dentro na prévia
   (08/11/2026). Par do interface-nova-movimento.css.

   Liga html.inn-mov (fora de prefers-reduced-motion e nunca no modo embutido,
   ?embed=1, onde a tela está dentro da home) e faz a tela entrar por partes,
   como a home: os blocos sobem (isso é CSS puro, por nth-child, para não piscar
   antes do script), os ícones crescem, os números contam de zero e os itens
   (cartões, colunas, linhas) entram um a um. O que a tela desenha depois por
   JavaScript (o quadro de Tarefas, a lista do Chat) também entra assim, nos
   primeiros segundos e logo depois de um clique (trocar filtro, abrir quadro);
   fora disso, o que o servidor repõe sozinho (o Chat a cada 4 s) não se mexe.
   E leva a página embora antes de navegar pelos links do menu lateral.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var html = document.documentElement;
  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  if (html.classList.contains('yuris-embed')) return;
  html.classList.add('inn-mov');

  // ── O que é item: o que entra um a um dentro do bloco dele ──────────────
  var SEL_ITEM = '.tk-col, .tk-card, .tk-list-row, .proc-col, .proc-card, .cli-card, .kpi-card, .chat-kpi, .chat-item, '
               + '.history-item, .lead-card, .kanban-card, .int-card, .intim-card, .pub-card, .ff-card, tbody > tr';
  var inicio = performance.now(), ultimoClique = -1e9;
  document.addEventListener('pointerdown', function () { ultimoClique = performance.now(); }, true);
  function podeAnimar() { var n = performance.now(); return n - inicio < 5000 || n - ultimoClique < 1500; }

  function entrarItens(raiz) {
    if (!raiz.querySelectorAll) return;
    var itens = (raiz.matches && raiz.matches(SEL_ITEM) ? [raiz] : []).concat([].slice.call(raiz.querySelectorAll(SEL_ITEM)));
    var porPai = new Map();
    itens.forEach(function (el) {
      if (el.dataset.inn) return;
      el.dataset.inn = '1';
      // Quem já tem transform próprio (a coluna do quadro gira em rotateX para a barra
      // ficar em cima) não entra: a animação sobrescreveria o giro e o texto viraria de cabeça para baixo.
      if (getComputedStyle(el).transform !== 'none') return;
      var k = porPai.get(el.parentNode) || 0;
      porPai.set(el.parentNode, k + 1);
      if (k < 14) { el.style.setProperty('--mov-i', k); el.classList.add('inn-item'); }
    });
  }
  // Os ícones crescem: todo svg pequeno (até 56px) da tela, na ordem em que aparece.
  function crescerIcones(raiz) {
    if (!raiz.querySelectorAll) return;
    var k = 0;
    [].forEach.call(raiz.querySelectorAll('svg'), function (s) {
      if (k >= 40 || s.dataset.inn) return;
      var r = s.getBoundingClientRect();
      if (r.width < 10 || r.width > 56 || r.height > 56) return;
      s.dataset.inn = '1'; s.style.setProperty('--mov-i', k++); s.classList.add('inn-cresce');
    });
  }
  // Os números contam de zero: todo elemento folha que é só um número inteiro.
  function contar(el, dur) {
    var alvo = parseInt(el.textContent, 10);
    if (!(alvo > 0)) return;
    var t0 = null;
    function passo(t) {
      if (t0 === null) t0 = t;
      var p = Math.min(1, (t - t0) / dur); p = 1 - Math.pow(1 - p, 3);
      el.textContent = String(Math.round(alvo * p));
      if (p < 1) requestAnimationFrame(passo);
      else { el.classList.add('inn-contou'); el.addEventListener('animationend', function f() { el.classList.remove('inn-contou'); el.removeEventListener('animationend', f); }); }
    }
    el.textContent = '0';
    requestAnimationFrame(passo);
  }
  function contarNumeros(raiz) {
    if (!raiz.querySelectorAll) return;
    var k = 0, todos = [].slice.call(raiz.querySelectorAll('*'));
    if (raiz.nodeType === 1) todos.unshift(raiz);
    for (var i = 0; i < todos.length && k < 40; i++) {
      var el = todos[i];
      if (el.children.length || el.dataset.inn || /^(INPUT|TEXTAREA|SELECT|OPTION|SCRIPT|STYLE|TITLE)$/.test(el.tagName)) continue;
      // Quem ainda está vazio ou em zero não é marcado: o contador das abas e das colunas
      // é preenchido depois, trocando só o texto, e aí conta.
      if (!/^\s*\d{1,6}\s*$/.test(el.textContent) || !(parseInt(el.textContent, 10) > 0)) continue;
      if (parseFloat(getComputedStyle(el).fontSize) < 10) continue;
      el.dataset.inn = '1'; k++;
      setTimeout(contar.bind(null, el, 800), 160 + k * 40);
    }
  }

  function ligar() {
    // A tela: .main-content, ou o main ao lado do menu (Clientes, Webhooks, Chat interno).
    var main = document.querySelector('.main-content') || [].filter.call(document.querySelectorAll('main'), function (m) { return !m.querySelector('.sidebar'); })[0] || document.body;
    if (main !== document.body && !main.classList.contains('main-content')) main.classList.add('inn-tela-entra');
    entrarItens(main); crescerIcones(main); contarNumeros(main);
    new MutationObserver(function (ms) {
      if (!podeAnimar()) return;
      ms.forEach(function (m) {
        if (m.type === 'characterData') { var p = m.target.parentElement; if (p && main.contains(p)) contarNumeros(p); return; }
        [].forEach.call(m.addedNodes, function (n) {
          if (n.nodeType === 1) { entrarItens(n); contarNumeros(n); }
          else if (n.nodeType === 3 && n.parentElement) contarNumeros(n.parentElement);
        });
      });
    }).observe(main, { childList: true, subtree: true, characterData: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ligar); else ligar();

  // ── Sair pelo menu ──────────────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest('.sidebar nav a[href], .sidebar a.sb-logo-link[href], .mobile-tabbar a[href]');
    if (!a || a.target === '_blank' || a.classList.contains('is-logout') || a.hasAttribute('data-sb-recolher')) return;
    var href = a.getAttribute('href') || '';
    if (href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
    var u; try { u = new URL(a.href, location.href); } catch (x) { return; }
    if (u.origin !== location.origin) return;
    e.preventDefault();
    html.classList.add('inn-saindo');
    setTimeout(function () { location.href = u.href; }, 220);
  });
  window.addEventListener('pageshow', function () { html.classList.remove('inn-saindo'); });
})();
