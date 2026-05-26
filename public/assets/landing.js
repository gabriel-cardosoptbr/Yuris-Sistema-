/* =============================================================================
   landing.js — Interações da landing Yuris
   • Mouse-glow nos cards (--mouse-x/y)
   • Scroll-reveal via IntersectionObserver
   • Header sticky com .scrolled
   • Tabs da prova visual + vitrine interativa
   • Menu hambúrguer mobile (drawer)
   • Scroll suave com offset do header fixo
   ========================================================================== */
(function () {
  'use strict';

  // ── 1. Mouse-glow nos cards ────────────────────────────────────────────────
  // Captura posição relativa do mouse em cada .lp-card e expõe via CSS vars
  // (--mouse-x, --mouse-y). O CSS usa esses valores em radial-gradient pra
  // criar o efeito de luz seguindo o cursor. Sem JS de animação — tudo CSS.
  //
  // Pulado em touch devices: nesses casos o CSS já mostra um glow estático
  // (`@media (hover: none)`), e anexar mousemove só consumiria eventos sem
  // retorno visual. Detecta via matchMedia, com fallback ontouchstart.
  const isTouchDevice =
    (window.matchMedia && window.matchMedia('(hover: none)').matches) ||
    ('ontouchstart' in window);
  if (!isTouchDevice) {
    const cards = document.querySelectorAll('.lp-card');
    cards.forEach((card) => {
      card.addEventListener('mousemove', (e) => {
        const r = card.getBoundingClientRect();
        const x = e.clientX - r.left;
        const y = e.clientY - r.top;
        card.style.setProperty('--mouse-x', x + 'px');
        card.style.setProperty('--mouse-y', y + 'px');
      });
    });
  }

  // ── 2. Scroll-reveal ───────────────────────────────────────────────────────
  // Adiciona .is-visible quando o elemento entra em pelo menos 15% da viewport.
  // Respeita prefers-reduced-motion (que no CSS já desativa transition).
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -60px 0px' });

    document.querySelectorAll('.lp-reveal').forEach((el) => io.observe(el));
  } else {
    // Fallback: revela tudo de uma vez
    document.querySelectorAll('.lp-reveal').forEach((el) => el.classList.add('is-visible'));
  }

  // ── 3. Header sticky com sombra ao rolar ───────────────────────────────────
  const header = document.querySelector('.lp-header');
  if (header) {
    let ticking = false;
    const onScroll = () => {
      if (window.scrollY > 20) header.classList.add('scrolled');
      else                     header.classList.remove('scrolled');
      ticking = false;
    };
    window.addEventListener('scroll', () => {
      if (!ticking) { window.requestAnimationFrame(onScroll); ticking = true; }
    }, { passive: true });
    onScroll();
  }

  // ── 4. Tabs (prova visual + vitrine) ───────────────────────────────────────
  // Padrão: container com data-tabs="X" agrupa .lp-tab[data-target] e
  // .lp-tab-pane[data-pane]. Mesma lógica vale pra .lp-vit-item / .lp-vit-pane.
  function setupTabGroup(scope, btnSel, paneSel, dataTarget, dataPane) {
    const btns  = scope.querySelectorAll(btnSel);
    const panes = scope.querySelectorAll(paneSel);
    btns.forEach((btn) => {
      btn.addEventListener('click', () => {
        const target = btn.getAttribute(dataTarget);
        btns.forEach((b) => b.classList.toggle('active', b === btn));
        panes.forEach((p) => {
          p.classList.toggle('active', p.getAttribute(dataPane) === target);
        });
      });
    });
  }
  document.querySelectorAll('[data-tabs="mockups"]').forEach((scope) => {
    setupTabGroup(scope, '.lp-tab', '.lp-tab-pane', 'data-target', 'data-pane');
  });
  document.querySelectorAll('[data-tabs="vitrine"]').forEach((scope) => {
    setupTabGroup(scope, '.lp-vit-item', '.lp-vit-pane', 'data-target', 'data-pane');
  });

  // ── 5. Menu mobile (drawer) ────────────────────────────────────────────────
  // Abre/fecha por click do hambúrguer, backdrop, X, ou tap num link interno.
  // ESC também fecha. Quando aberto, trava o scroll do body e prende o foco
  // dentro do drawer (a11y — usuário com teclado não pula pro conteúdo atrás).
  const toggle    = document.querySelector('.lp-menu-toggle');
  const drawer    = document.querySelector('.lp-drawer');
  const backdrop  = document.querySelector('.lp-drawer-backdrop');
  const closeBtn  = document.querySelector('.lp-drawer-close');
  let lastFocused = null;

  function getFocusable(scope) {
    return Array.from(scope.querySelectorAll(
      'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter((el) => el.offsetParent !== null);
  }
  function openDrawer() {
    if (!drawer) return;
    lastFocused = document.activeElement;
    drawer.classList.add('open');
    if (backdrop) backdrop.classList.add('open');
    document.body.style.overflow = 'hidden';
    // Move foco pro primeiro elemento focável do drawer
    const focusables = getFocusable(drawer);
    if (focusables.length) focusables[0].focus();
  }
  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('open');
    if (backdrop) backdrop.classList.remove('open');
    document.body.style.overflow = '';
    // Devolve o foco pro botão que abriu
    if (lastFocused && typeof lastFocused.focus === 'function') {
      lastFocused.focus();
    }
  }
  if (toggle)   toggle.addEventListener('click', openDrawer);
  if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
  if (backdrop) backdrop.addEventListener('click', closeDrawer);
  if (drawer) {
    drawer.querySelectorAll('a').forEach((a) => a.addEventListener('click', closeDrawer));
  }
  document.addEventListener('keydown', (e) => {
    if (!drawer || !drawer.classList.contains('open')) return;
    if (e.key === 'Escape') { closeDrawer(); return; }
    // Foco trap: Tab/Shift+Tab circula só dentro do drawer
    if (e.key === 'Tab') {
      const focusables = getFocusable(drawer);
      if (!focusables.length) return;
      const first = focusables[0];
      const last  = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault(); last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault(); first.focus();
      }
    }
  });

  // ── 6. Scroll suave com offset ─────────────────────────────────────────────
  // `scroll-margin-top` no CSS já cuida do offset. Adicionamos só transição
  // suave em browsers que não respeitam `scroll-behavior:smooth` em CSS.
  document.querySelectorAll('a[href^="#"]').forEach((a) => {
    a.addEventListener('click', (e) => {
      const href = a.getAttribute('href');
      if (!href || href === '#' || href.length < 2) return;
      const target = document.querySelector(href);
      if (!target) return;
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      // Atualiza hash sem disparar jump
      history.pushState(null, '', href);
    });
  });

  // ── 7. Year no footer ──────────────────────────────────────────────────────
  const yearEl = document.querySelector('[data-year]');
  if (yearEl) yearEl.textContent = new Date().getFullYear();
})();
