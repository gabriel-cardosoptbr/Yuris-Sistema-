/* ui/header.js — header sticky com .scrolled. Portado do landing.js (§3). */
export function initHeader() {
  const header = document.querySelector('.lp2-header');
  if (!header) return;
  let ticking = false;
  const onScroll = () => {
    header.classList.toggle('scrolled', window.scrollY > 20);
    ticking = false;
  };
  window.addEventListener('scroll', () => {
    if (!ticking) { window.requestAnimationFrame(onScroll); ticking = true; }
  }, { passive: true });
  onScroll();
}
