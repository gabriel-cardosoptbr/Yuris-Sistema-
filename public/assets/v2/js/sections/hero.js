/* sections/hero.js — parallax cinematográfico do #inicio.
   A ENTRADA do hero é feita por CSS (robusta pro LCP). Aqui só o movimento
   ligado ao scroll (progressive enhancement — não esconde nada). */
export function init({ gsap, ScrollTrigger }) {
  const root = document.querySelector('#inicio');
  if (!root) return;

  const ctx = gsap.context(() => {
    const aura   = root.querySelector('.lp2-hero-aura');
    const mockup = root.querySelector('.lp2-hero-mockup');
    const st = { trigger: root, start: 'top top', end: 'bottom top', scrub: true };

    if (aura)   gsap.to(aura,   { yPercent: 22, ease: 'none', scrollTrigger: st });
    if (mockup) gsap.to(mockup, { yPercent: -10, ease: 'none', scrollTrigger: st });
  }, root);

  return () => ctx.revert();
}
