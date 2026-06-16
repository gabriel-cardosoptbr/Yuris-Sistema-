/* core/motion-prefs.js — preferência de movimento reduzido do usuário. */
export function prefersReducedMotion() {
  return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
}
