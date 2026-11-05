/* =============================================================================
   site.js — comportamento do site público do Yuris. Vanilla, sem dependências,
   sem etapa de build. Tudo é melhoria progressiva: sem JS o conteúdo continua
   inteiro e legível (abas viram blocos empilhados, FAQ é <details>).

   Módulos: header (sombra ao rolar), drawer mobile, abas, entrada de conteúdo
   (IntersectionObserver), cenas de produto, vitrine do CTA, ano, e o movimento
   (escalonamento, letras do YURIS, saída de página, números, funil, escopo).
   Quem anima é o CSS (site-motion.css); aqui só se ligam classes e se medem
   posições. Respeita prefers-reduced-motion: nada anima, tudo já nasce visível.
   ========================================================================== */
(function () {
  'use strict';

  var doc = document;
  var body = doc.body;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- Header: borda e sombra depois que a página rola ---- */
  var header = doc.querySelector('.ys-header');
  if (header) {
    var onScroll = function () { header.classList.toggle('is-scrolled', window.scrollY > 8); };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* ---- Drawer mobile ---- */
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

  /* ---- Cenas de produto: entram em sequência quando aparecem; replay ao trocar de aba ---- */
  function playScene(scene) {
    scene.classList.add('is-playing');
    tocaCena(scene);
  }
  function armScenes(scope, replay) {
    (scope || doc).querySelectorAll('.lp2-demo').forEach(function (scene) {
      if (scene.__ysArmed) {
        if (replay && !reduce) {
          scene.classList.remove('is-playing'); scene.__ysTocou = false;
          void scene.offsetWidth;
          playScene(scene);
        }
        return;
      }
      scene.__ysArmed = true;
      scene.querySelectorAll('.dm-el').forEach(function (el, i) { el.style.setProperty('--i', i); });
      scene.querySelectorAll('.dm-bar').forEach(function (b, i) { b.style.setProperty('--i', i); });
      if (reduce || !('IntersectionObserver' in window)) { scene.classList.add('is-playing'); return; }
      scene.classList.add('is-armed');
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) { playScene(scene); io.disconnect(); }
        });
      }, { threshold: 0.25 });
      io.observe(scene);
    });
  }

  /* ---- Abas: [data-tabs] > [data-target] (botões) + [data-pane] (painéis) ---- */
  doc.querySelectorAll('[data-tabs]').forEach(function (root) {
    var btns = Array.prototype.slice.call(root.querySelectorAll('[data-target]'));
    var panes = Array.prototype.slice.call(root.querySelectorAll('[data-pane]'));
    if (!btns.length || !panes.length) return;

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
        var was = p.classList.contains('is-active');
        p.classList.toggle('is-active', on);
        if (on) armScenes(p, !was);
      });
    }
    btns.forEach(function (b, i) {
      b.setAttribute('role', 'tab');
      b.addEventListener('click', function () { activate(b.getAttribute('data-target'), false); });
      b.addEventListener('keydown', function (e) {
        var vertical = root.hasAttribute('data-tabs-vertical');
        var next = vertical ? 'ArrowDown' : 'ArrowRight', prev = vertical ? 'ArrowUp' : 'ArrowLeft';
        var to = -1;
        if (e.key === next) to = (i + 1) % btns.length;
        else if (e.key === prev) to = (i - 1 + btns.length) % btns.length;
        else if (e.key === 'Home') to = 0;
        else if (e.key === 'End') to = btns.length - 1;
        if (to > -1) { e.preventDefault(); activate(btns[to].getAttribute('data-target'), true); }
      });
    });
    panes.forEach(function (p) { p.setAttribute('role', 'tabpanel'); });
    var list = root.querySelector('[data-tablist]');
    if (list) list.setAttribute('role', 'tablist');
    var first = root.querySelector('[data-target].is-active') || btns[0];
    activate(first.getAttribute('data-target'), false);
  });

  /* ---- Vitrine do CTA final: troca de tela a cada 3,2 s, só enquanto visível ---- */
  doc.querySelectorAll('.dm-show').forEach(function (show) {
    var frames = show.querySelectorAll('.dm-frame');
    var label = show.querySelector('.dm-show-label');
    var dots = show.querySelectorAll('.dm-show-dot');
    if (!frames.length) return;
    var at = 0, timer = null;
    function go(i) {
      at = i;
      frames.forEach(function (f, j) { f.classList.toggle('is-on', j === i); });
      dots.forEach(function (d, j) { d.classList.toggle('on', j === i); });
      if (label) label.textContent = frames[i].getAttribute('data-label') || '';
    }
    go(0);
    if (reduce || !('IntersectionObserver' in window)) return;
    new IntersectionObserver(function (entries) {
      var vis = entries[0].isIntersecting;
      if (vis && !timer) timer = setInterval(function () { go((at + 1) % frames.length); }, 3200);
      if (!vis && timer) { clearInterval(timer); timer = null; }
    }, { threshold: 0.2 }).observe(show);
  });

  /* ---- Escalonamento: cada filho ganha um atraso e entra em sequência ---- */
  var grupos = ['.ys-mosaic', '.ys-ruled', '.ys-pilares', '.ys-integra', '.ys-quem-list', '.ys-etimo', '.ys-pontas-grid', '.ys-cmp', '.ys-minis', '.ys-orbit-col', '.ys-ruler', '.ys-related-grid', '.ys-panel-chips', '.ys-checks', '.ys-rows', '.pl-planos', '.pl-base-item', '.pl-compl-grade', '.ys-faq'];
  doc.querySelectorAll(grupos.join(',')).forEach(function (g) {
    Array.prototype.forEach.call(g.children, function (el, i) {
      el.style.setProperty('--d', (Math.min(i, 12) * 0.07).toFixed(2) + 's');
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
      var s = doc.createElement('span'); s.textContent = ch; s.style.setProperty('--d', (i * 0.09) + 's'); word.appendChild(s);
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
          if (en.isIntersecting) { en.target.classList.add('is-in'); rio.unobserve(en.target); }
        });
      }, { threshold: 0.1, rootMargin: '0px 0px -5% 0px' });
      items.forEach(function (el) { rio.observe(el); });
      // Rede de segurança: nada fica escondido se o observador não disparar.
      setTimeout(function () { items.forEach(function (el) { if (!el.classList.contains('is-in') && el.getBoundingClientRect().top < window.innerHeight) el.classList.add('is-in'); }); }, 1500);
    }
  }

  /* ---- Ano do rodapé ---- */
  doc.querySelectorAll('[data-year]').forEach(function (y) { y.textContent = new Date().getFullYear(); });

  /* ---- Cenas: números que contam, linha que se desenha, funil e escopo ---- */
  function conta(el) {
    var alvo = parseInt(el.getAttribute('data-c') || el.getAttribute('data-k') || el.textContent, 10);
    if (isNaN(alvo)) return;
    if (reduce) { el.textContent = alvo; return; }
    var ini = performance.now(), dur = 1300;
    (function tick(t) {
      var p = Math.min(1, (t - ini) / dur), e = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(alvo * e);
      if (p < 1) requestAnimationFrame(tick);
    })(ini);
  }
  function tocaCena(scene) {
    if (scene.__ysTocou) return;
    scene.__ysTocou = true;
    scene.querySelectorAll('[data-c], [data-k]').forEach(conta);
    var path = scene.querySelector('.dm-line-path');
    if (path && path.getTotalLength && !reduce) {
      var L = path.getTotalLength();
      path.style.strokeDasharray = L; path.style.strokeDashoffset = L;
      requestAnimationFrame(function () { requestAnimationFrame(function () { path.style.strokeDashoffset = 0; }); });
    }
    if (!scene.__ysLoop) {
      scene.__ysLoop = true;
      if (scene.classList.contains('dm-funnel')) funil(scene);
      if (scene.classList.contains('dm-scope') && !reduce) escopo(scene);
    }
  }

  /* Funil: o lead vai de coluna em coluna e, na última, vira cliente. */
  function funil(scene) {
    var cols = scene.querySelectorAll('.dm-col'), lead = scene.querySelector('.dm-lead');
    if (cols.length < 2 || !lead) return;
    var i = 0, n = cols.length;
    function vai() {
      var r0 = cols[0].getBoundingClientRect(), r = cols[i].getBoundingClientRect();
      lead.style.transform = 'translateX(' + Math.round(r.left - r0.left) + 'px)';
      lead.style.borderColor = i === n - 1 ? 'var(--ys-ok)' : '';
      i = (i + 1) % n;
    }
    vai();
    if (!reduce) setInterval(vai, 1750);
  }

  /* Escopo (Gestão): matriz, filial e advogado se alternam, e os números mudam. */
  function escopo(scene) {
    var segs = scene.querySelectorAll('.dm-seg-i'), thumb = scene.querySelector('.dm-seg-thumb');
    var k = { proc: scene.querySelector('[data-k="proc"]'), prazo: scene.querySelector('[data-k="prazo"]'), adv: scene.querySelector('[data-k="adv"]') };
    var dados = [{ proc: 248, prazo: 31, adv: 12 }, { proc: 96, prazo: 12, adv: 5 }, { proc: 34, prazo: 4, adv: 1 }];
    var at = 0;
    function mostra(i) {
      segs.forEach(function (s, j) { s.classList.toggle('is-on', j === i); });
      if (thumb) thumb.style.transform = 'translateX(' + (i * 100) + '%)';
      Object.keys(k).forEach(function (c) { if (k[c]) { k[c].setAttribute('data-k', dados[i][c]); conta(k[c]); } });
    }
    setInterval(function () { at = (at + 1) % dados.length; mostra(at); }, 2600);
  }

  armScenes(doc, false);

  /* ---- Saída de página onde não há View Transitions ---- */
  if (!reduce && !('startViewTransition' in doc)) {
    doc.addEventListener('click', function (e) {
      var a = e.target.closest('a[href]');
      if (!a || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('onclick') || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
      var url;
      try { url = new URL(a.href, location.href); } catch (err) { return; }
      if (url.origin !== location.origin || url.protocol === 'javascript:' || (url.pathname === location.pathname && url.hash)) return;
      e.preventDefault();
      body.classList.add('is-leaving');
      setTimeout(function () { location.href = url.href; }, 220);
    });
    window.addEventListener('pageshow', function () { body.classList.remove('is-leaving'); });
  }
})();
