/* ui/drawer.js — menu mobile com focus-trap + ESC. Portado do landing.js (§5). */
export function initDrawer() {
  const toggle   = document.querySelector('.lp2-menu-toggle');
  const drawer   = document.querySelector('.lp2-drawer');
  const backdrop = document.querySelector('.lp2-drawer-backdrop');
  const closeBtn = document.querySelector('.lp2-drawer-close');
  if (!drawer) return;
  let lastFocused = null;

  const getFocusable = (scope) => Array.from(scope.querySelectorAll(
    'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
  )).filter((el) => el.offsetParent !== null);

  function open() {
    lastFocused = document.activeElement;
    drawer.classList.add('open');
    if (backdrop) backdrop.classList.add('open');
    document.body.style.overflow = 'hidden';
    const f = getFocusable(drawer);
    if (f.length) f[0].focus();
  }
  function close() {
    drawer.classList.remove('open');
    if (backdrop) backdrop.classList.remove('open');
    document.body.style.overflow = '';
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  }

  if (toggle)   toggle.addEventListener('click', open);
  if (closeBtn) closeBtn.addEventListener('click', close);
  if (backdrop) backdrop.addEventListener('click', close);
  drawer.querySelectorAll('a').forEach((a) => a.addEventListener('click', close));

  document.addEventListener('keydown', (e) => {
    if (!drawer.classList.contains('open')) return;
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'Tab') {
      const f = getFocusable(drawer);
      if (!f.length) return;
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });
}
