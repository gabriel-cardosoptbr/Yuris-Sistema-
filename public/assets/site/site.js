/* =============================================================================
   site.js — comportamento do site público do Yuris. Vanilla, sem dependências,
   sem etapa de build. Tudo é melhoria progressiva: sem JS o conteúdo continua
   inteiro e legível (as abas viram blocos empilhados, a FAQ é <details>).

   Módulos: cabeçalho (sombra ao rolar), menu do celular, abas, números das
   ilustrações, entrada de conteúdo, escalonamento das listas, letras do YURIS e
   o ano do rodapé. Quem anima é o CSS (site-motion.css); aqui só se ligam
   classes. Com prefers-reduced-motion, tudo já nasce visível e parado.
   ========================================================================== */
(function () {
  'use strict';

  var doc = document;
  var body = doc.body;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- Cabeçalho: borda e sombra depois que a página rola ---- */
  var header = doc.querySelector('.ys-header');
  if (header) {
    var onScroll = function () { header.classList.toggle('is-scrolled', window.scrollY > 8); };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* ---- Menu do celular ---- */
  var toggle = doc.querySelector('.ys-menu-toggle');
  var drawer = doc.querySelector('.ys-drawer');
  function setDrawer(open) {
    body.classList.toggle('is-drawer-open', open);
    if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    body.style.overflow = open ? 'hidden' : '';
    if (open && drawer) { var f = drawer.querySelector('a, button'); if (f) f.focus({ preventScroll: true }); }
    if (!open && toggle) toggle.focus({ preventScroll: true });
  }
  if (toggle && drawer) {
    toggle.addEventListener('click', function () { setDrawer(true); });
    doc.querySelector('.ys-drawer-close').addEventListener('click', function () { setDrawer(false); });
    doc.querySelector('.ys-drawer-backdrop').addEventListener('click', function () { setDrawer(false); });
    drawer.addEventListener('click', function (e) { if (e.target.closest('a')) setDrawer(false); });
    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && body.classList.contains('is-drawer-open')) setDrawer(false); });
  }

  /* ---- Abas: [data-tabs] > [data-target] (botões) + [data-pane] (painéis) ----
     O painel inativo fica fora da leitura e do foco (inert), mas continua no HTML:
     o texto de todas as abas é lido por buscador e por IA. */
  doc.querySelectorAll('[data-tabs]').forEach(function (root) {
    var btns = Array.prototype.slice.call(root.querySelectorAll('[data-target]'));
    var panes = Array.prototype.slice.call(root.querySelectorAll('[data-pane]'));
    var list = root.querySelector('[data-tablist]');
    if (!btns.length) return;

    function activate(id, focus) {
      btns.forEach(function (b) {
        var on = b.getAttribute('data-target') === id;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.tabIndex = on ? 0 : -1;
        if (on && focus) b.focus();
      });
      panes.forEach(function (p) {
        var on = p.getAttribute('data-pane') === id;
        p.classList.toggle('is-active', on);
        if (on) p.removeAttribute('inert'); else p.setAttribute('inert', '');
      });
    }
    btns.forEach(function (b, i) {
      b.setAttribute('role', 'tab');
      b.addEventListener('click', function () { activate(b.getAttribute('data-target'), false); });
      b.addEventListener('keydown', function (e) {
        var to = -1;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') to = (i + 1) % btns.length;
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') to = (i - 1 + btns.length) % btns.length;
        else if (e.key === 'Home') to = 0;
        else if (e.key === 'End') to = btns.length - 1;
        if (to > -1) { e.preventDefault(); activate(btns[to].getAttribute('data-target'), true); }
      });
    });
    panes.forEach(function (p) { p.setAttribute('role', 'tabpanel'); });
    if (list) list.setAttribute('role', 'tablist');
    var first = root.querySelector('[data-target].is-active') || btns[0];
    activate(first.getAttribute('data-target'), false);
  });

  /* ---- Números das ilustrações: contam até o valor quando a cena aparece ----
     O número certo já está no HTML (sem JS ou sem movimento, ele fica parado). */
  function conta(el) {
    var alvo = parseInt(el.getAttribute('data-conta'), 10);
    var sufixo = el.getAttribute('data-sufixo') || '';
    if (isNaN(alvo)) return;
    var ini = null, dur = 900;
    (function passo(t) {
      if (ini === null) ini = t;
      var p = Math.min(1, (t - ini) / dur), e = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(alvo * e) + (p === 1 ? sufixo : (sufixo ? sufixo.replace(/\d/g, '0') : ''));
      if (p < 1) requestAnimationFrame(passo);
    })(performance.now());
  }

  /* ---- Escalonamento: cada filho ganha um atraso e entra em sequência ---- */
  var grupos = ['.ys-ruled', '.ys-liga', '.ys-quem-list', '.ys-etimo', '.ys-pontas-grid', '.ys-cmp', '.ys-related-grid', '.ys-checks', '.ys-rows', '.pl-planos', '.pl-base-item', '.pl-compl-grade', '.ys-faq'];
  doc.querySelectorAll(grupos.join(',')).forEach(function (g) {
    Array.prototype.forEach.call(g.children, function (el, i) {
      el.style.setProperty('--d', (Math.min(i, 10) * 0.06).toFixed(2) + 's');
      el.classList.add('ys-reveal');
    });
    g.classList.remove('ys-reveal');
  });

  /* ---- YURIS gigante: uma letra por vez ---- */
  var word = doc.querySelector('.ys-origem-word');
  if (word && !word.querySelector('span')) {
    var txt = word.textContent.trim();
    word.textContent = '';
    txt.split('').forEach(function (ch, i) {
      var s = doc.createElement('span'); s.textContent = ch; s.style.setProperty('--d', (i * 0.08) + 's'); word.appendChild(s);
    });
    word.classList.add('ys-reveal');
  }

  /* ---- Entrada de conteúdo ---- */
  var items = doc.querySelectorAll('.ys-reveal');
  if (items.length) {
    if (reduce || !('IntersectionObserver' in window)) {
      items.forEach(function (el) { el.classList.add('is-in'); });
    } else {
      var rio = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) return;
          en.target.classList.add('is-in'); rio.unobserve(en.target);
          if (en.target.classList.contains('ys-cena')) setTimeout(function () { en.target.querySelectorAll('[data-conta]').forEach(conta); }, 300);
        });
      }, { threshold: 0.08, rootMargin: '0px 0px -4% 0px' });
      items.forEach(function (el) { rio.observe(el); });
      // Rede de segurança: nada fica escondido se o observador não disparar.
      setTimeout(function () { items.forEach(function (el) { if (!el.classList.contains('is-in') && el.getBoundingClientRect().top < window.innerHeight) el.classList.add('is-in'); }); }, 1500);
    }
  }

  /* ---- Ano do rodapé ---- */
  doc.querySelectorAll('[data-year]').forEach(function (y) { y.textContent = new Date().getFullYear(); });
})();
