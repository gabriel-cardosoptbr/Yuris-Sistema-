/* ui/anchors.js — scroll suave de âncoras. Usa Lenis se disponível
   (window.__lp2lenis), senão scrollIntoView nativo. Portado do landing.js (§6). */
export function initAnchors() {
  document.querySelectorAll('a[href^="#"]').forEach((a) => {
    a.addEventListener('click', (e) => {
      const href = a.getAttribute('href');
      if (!href || href === '#' || href.length < 2) return;
      const target = document.querySelector(href);
      if (!target) return;
      e.preventDefault();
      const lenis = window.__lp2lenis;
      if (lenis && typeof lenis.scrollTo === 'function') {
        lenis.scrollTo(target, { offset: -80 });
      } else {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      history.pushState(null, '', href);
    });
  });
}
