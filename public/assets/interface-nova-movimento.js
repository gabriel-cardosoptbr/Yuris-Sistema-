/* ═══════════════════════════════════════════════════════════════════════════
   interface-nova-movimento.js: o movimento das telas de dentro na prévia
   (08/11/2026). Par do interface-nova-movimento.css.

   Liga a classe html.inn-mov (fora de prefers-reduced-motion) e leva a página
   embora antes de navegar pelos links do menu lateral, para a tela seguinte
   entrar subindo. Só links do menu, da mesma origem, sem modificador, e nunca
   no modo embutido (?embed=1): ali a tela está dentro da home.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var html = document.documentElement;
  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  if (html.classList.contains('yuris-embed')) return;
  html.classList.add('inn-mov');
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
