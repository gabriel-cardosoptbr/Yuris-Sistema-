/* core/lenis.js — smooth scroll premium (carregado só fora de reduced-motion). */
import Lenis from 'lenis';

export function initLenis() {
  const lenis = new Lenis({
    duration: 1.1,
    easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
    smoothWheel: true,
    wheelMultiplier: 1,
  });
  return lenis;
}
