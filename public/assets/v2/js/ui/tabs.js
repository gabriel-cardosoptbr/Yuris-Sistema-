/* ui/tabs.js — abas (prova visual + vitrine). Portado do landing.js (§4).
   Lê data-tabs no container e data-target/data-pane nos itens. */
export function initTabs() {
  const setup = (scope, btnSel, paneSel) => {
    const btns  = scope.querySelectorAll(btnSel);
    const panes = scope.querySelectorAll(paneSel);
    btns.forEach((btn) => {
      btn.addEventListener('click', () => {
        const target = btn.getAttribute('data-target');
        btns.forEach((b) => b.classList.toggle('active', b === btn));
        panes.forEach((p) => p.classList.toggle('active', p.getAttribute('data-pane') === target));
      });
    });
  };
  document.querySelectorAll('[data-tabs="mockups"]').forEach((s) => setup(s, '.lp2-tab', '.lp2-tab-pane'));
  document.querySelectorAll('[data-tabs="vitrine"]').forEach((s) => setup(s, '.lp2-vit-item', '.lp2-vit-pane'));
}
