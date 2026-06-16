/* =============================================================================
   main.js — entrypoint ESM da landing YURIS v2.

   Estratégia (degradação graciosa):
   • A UI essencial (header, drawer, tabs, âncoras) liga SEMPRE — não depende
     de libs externas nem da CDN. O conteúdo é 100% visível sem JS (SSR PHP).
   • Fora de prefers-reduced-motion, carrega Lenis + GSAP sob demanda e liga as
     animações cinematográficas. Se a CDN falhar, cai no reveal estático.
   ========================================================================== */
import { prefersReducedMotion } from './core/motion-prefs.js';
import { initHeader } from './ui/header.js';
import { initDrawer } from './ui/drawer.js';
import { initTabs } from './ui/tabs.js';
import { initAnchors } from './ui/anchors.js';
import { initScrollReveal, revealAll } from './core/scroll-reveal.js';

function initYear() {
  const y = document.querySelector('[data-year]');
  if (y) y.textContent = new Date().getFullYear();
}

// ── UI essencial — sempre ──────────────────────────────────────────────────
initHeader();
initDrawer();
initTabs();
initAnchors();
initYear();

// ── Animação cinematográfica — só fora de reduced-motion ────────────────────
if (prefersReducedMotion()) {
  revealAll();
  // slider do comparativo funciona mesmo sem motion (navegação estática, sem timeline)
  import('./sections/comparativo.js').then((m) => m.init && m.init()).catch(() => {});
} else {
  initScrollReveal();           // reveal padrão das seções (IO + transição CSS)
  bootMotion();
}

async function bootMotion() {
  try {
    const [{ initLenis }, gsapMod] = await Promise.all([
      import('./core/lenis.js'),
      import('./core/gsap.js'),
    ]);
    const { gsap, ScrollTrigger } = gsapMod;

    // Sincroniza Lenis ↔ ScrollTrigger
    const lenis = initLenis();
    window.__lp2lenis = lenis;                       // usado por ui/anchors.js
    lenis.on('scroll', ScrollTrigger.update);
    gsap.ticker.add((t) => lenis.raf(t * 1000));
    gsap.ticker.lagSmoothing(0);

    // Animações por seção (vão sendo adicionadas conforme a construção avança)
    const sections = await Promise.all([
      import('./sections/hero.js'),
      import('./sections/demos.js'),
      import('./sections/origem.js'),
      import('./sections/comparativo.js'),
    ]);
    sections.forEach((m) => m.init && m.init({ gsap, ScrollTrigger }));

    // Iluminação ambiente que segue o cursor (decorativa; só mouse fino + hover).
    const { initCursorGlow } = await import('./core/cursor-glow.js');
    initCursorGlow({ gsap });

    ScrollTrigger.refresh();
  } catch (err) {
    console.warn('[lp2] animação indisponível — fallback estático:', err);
    revealAll();
  }
}
