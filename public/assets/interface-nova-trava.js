/* ═══════════════════════════════════════════════════════════════════════════
   interface-nova-trava.js: com um pop-up aberto, o fundo não rola (11/11/2026).

   "Quando abertos, não posso ter interação com o fundo; abri e consegui rolar o
   fundo para cima e para baixo." Cada pop-up do sistema nasceu de um jeito (o de
   Tarefas, o de Processos, os da home, o embutido de outro módulo, o de cookies)
   e só dois travavam a rolagem. Esta guarda vale para todos, inclusive os que
   vierem depois, sem nada em cada tela.

   A REGRA: pop-up aberto é uma camada fixa, visível e que recebe clique, cobrindo
   a tela (85% da largura e da altura ou mais): o fundo escuro de todo modal. Com
   uma delas aberta, a rolada (roda do mouse, dedo, teclado) só passa se houver,
   DENTRO da camada mais de cima, uma área que ainda role naquela direção. Senão é
   cancelada: o fundo não se mexe e a rolagem não "escorre" para a página.

   Por que pelo evento e não por overflow:hidden no <body>: nas telas de dentro
   quem rola nem sempre é a página (é a área principal), e esconder a barra da
   página empurra o layout. Carrega também dentro do pop-up embutido (?embed=1):
   lá, a rolada que não tem onde ir escorreria para a página de fora.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.InnTrava) return;
  var CAND = '[class*="overlay"], [class*="Overlay"], [class*="modal"], [class*="Modal"], [class*="backdrop"], [class*="drawer"], [class*="popup"], '
           + '[id*="overlay"], [id*="Overlay"], [id*="modal"], [id*="Modal"], [role="dialog"], dialog[open], .inn-emb, .idt-fundo, .idt-embed, #yuris-cookie-prefs';
  var FORA = '.sidebar, .mobile-tabbar, #yuris-cookie-banner';

  function cobre(el) {
    if (el.closest(FORA)) return false;
    var cs = getComputedStyle(el);
    if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden' || cs.pointerEvents === 'none' || +cs.opacity < .05) return false;
    var r = el.getBoundingClientRect();
    return r.width >= innerWidth * .85 && r.height >= innerHeight * .85 && r.left <= innerWidth * .1 && r.top <= innerHeight * .1;
  }
  // A camada aberta mais de cima (maior z-index; empate, a última no documento).
  var cache = null, sujo = true;
  function aberta() {
    if (!sujo) return cache;
    sujo = false; cache = null;
    var melhor = -Infinity;
    [].forEach.call(document.querySelectorAll(CAND), function (el) {
      if (!cobre(el)) return;
      var z = parseInt(getComputedStyle(el).zIndex, 10); z = isNaN(z) ? 0 : z;
      if (z >= melhor) { melhor = z; cache = el; }
    });
    return cache;
  }
  try {
    new MutationObserver(function () { sujo = true; })
      .observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'style', 'open', 'hidden'] });
  } catch (e) {}
  addEventListener('resize', function () { sujo = true; });

  // Alguém entre o alvo e a camada ainda rola nessa direção (dy > 0 desce, dx > 0 vai à direita)?
  function rolaDentro(alvo, camada, dx, dy) {
    var el = alvo && alvo.nodeType === 1 ? alvo : (alvo && alvo.parentElement);
    while (el && el !== document.body && el !== document.documentElement) {
      var cs = getComputedStyle(el);
      if (dy && /(auto|scroll|overlay)/.test(cs.overflowY) && el.scrollHeight > el.clientHeight + 1) {
        if (dy > 0 ? el.scrollTop + el.clientHeight < el.scrollHeight - 1 : el.scrollTop > 0) return true;
      }
      if (dx && /(auto|scroll|overlay)/.test(cs.overflowX) && el.scrollWidth > el.clientWidth + 1) {
        if (dx > 0 ? el.scrollLeft + el.clientWidth < el.scrollWidth - 1 : el.scrollLeft > 0) return true;
      }
      if (el === camada) break;
      el = el.parentElement;
    }
    return false;
  }
  function segurar(e, dx, dy) {
    var camada = aberta(); if (!camada) return;
    var alvo = e.target;
    // Campo de texto com várias linhas rola por si; o resto passa pela regra.
    if (!camada.contains(alvo) || !rolaDentro(alvo, camada, dx, dy)) { if (e.cancelable) e.preventDefault(); }
  }

  document.addEventListener('wheel', function (e) {
    if (e.ctrlKey) return;   // zoom da página
    segurar(e, e.shiftKey && !e.deltaX ? e.deltaY : e.deltaX, e.shiftKey && !e.deltaX ? 0 : e.deltaY);
  }, { passive: false, capture: true });

  var toque = null;
  document.addEventListener('touchstart', function (e) { var t = e.touches[0]; toque = t ? { x: t.clientX, y: t.clientY } : null; }, { passive: true, capture: true });
  document.addEventListener('touchmove', function (e) {
    var t = e.touches[0]; if (!t || !toque) return;
    var dx = toque.x - t.clientX, dy = toque.y - t.clientY;
    toque = { x: t.clientX, y: t.clientY };
    segurar(e, Math.abs(dx) > Math.abs(dy) ? dx : 0, Math.abs(dy) >= Math.abs(dx) ? dy : 0);
  }, { passive: false, capture: true });

  var TECLAS = { ArrowDown: 1, PageDown: 1, End: 1, ' ': 1, ArrowUp: -1, PageUp: -1, Home: -1 };
  document.addEventListener('keydown', function (e) {
    var dir = TECLAS[e.key]; if (!dir) return;
    var a = document.activeElement;
    // Quem escreve (campo, área de texto, seletor, editor) usa as teclas para si.
    if (a && (/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) || a.isContentEditable)) return;
    if (e.key === ' ' && a && /^(BUTTON|A)$/.test(a.tagName)) return;
    if (e.key === ' ' && e.shiftKey) dir = -1;
    segurar(e, 0, dir);
  }, { capture: true });

  window.InnTrava = { aberta: function () { sujo = true; return aberta(); } };
})();
