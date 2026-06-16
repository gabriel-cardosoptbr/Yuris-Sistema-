/* core/scroll-reveal.js
   Reveal padrão das seções (.lp2-reveal → .is-visible, transição via CSS).
   Independe da CDN: usa IntersectionObserver nativo. Portado do landing.js (§2). */

export function initScrollReveal() {
  const els = document.querySelectorAll('.lp2-reveal');
  if (!els.length) return;
  if (!('IntersectionObserver' in window)) { revealAll(); return; }
  // Esconde SÓ agora (com o JS comprovadamente rodando). Se este módulo não
  // executar, .lp2-hidden nunca é aplicado e o conteúdo permanece visível.
  els.forEach((el) => el.classList.add('lp2-hidden'));
  const io = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        entry.target.classList.remove('lp2-hidden');
        io.unobserve(entry.target);
      }
    });
  }, { threshold: 0.1, rootMargin: '0px 0px -8% 0px' });
  els.forEach((el) => io.observe(el));
}

/* Mostra tudo de uma vez (fallback no-IO / reduced-motion / falha de libs). */
export function revealAll() {
  document.querySelectorAll('.lp2-reveal').forEach((el) => {
    el.classList.add('is-visible');
    el.classList.remove('lp2-hidden');
  });
}
