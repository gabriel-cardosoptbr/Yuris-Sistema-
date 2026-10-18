/* ff-contrato-cliente.js: o bloco "Contratos" na ficha do cliente (Clientes,
   edição CRM). Lista os contratos do cliente e abre um novo já ligado a ele.
   Carregado pelo sidebar.php em conta CRM; só age se a página tem a ficha do
   cliente (#modalCliente). A tela completa é /contratos.php. */
(function () {
  'use strict';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function montar() {
    var modal = document.getElementById('modalCliente'), form = document.getElementById('cliForm'), campoId = document.getElementById('cliId');
    if (!modal || !form || !campoId) return;
    var bloco = document.createElement('div');
    bloco.id = 'cliContratos';
    bloco.style.cssText = 'margin-top:16px;padding:14px 16px;border:1px solid rgba(17,29,45,.10);border-radius:12px;display:none';
    bloco.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px">' +
      '<b style="font-size:13.5px">Contratos</b><a data-novo href="#" style="font-size:12.5px;font-weight:700;color:var(--ff-marca,#015DFC)">+ Novo contrato</a></div><div data-lista style="font-size:13px"></div>';
    form.appendChild(bloco);
    var lista = bloco.querySelector('[data-lista]');
    bloco.querySelector('[data-novo]').addEventListener('click', function (e) {
      e.preventDefault();
      if (campoId.value) location.href = '/contratos.php?novo=1&cliente_id=' + encodeURIComponent(campoId.value);
    });
    var ultimo = null;
    function carregar() {
      var id = campoId.value;
      bloco.style.display = id ? '' : 'none';
      if (!id || id === ultimo) return;
      ultimo = id;
      lista.textContent = 'Carregando…';
      fetch('/api/crm_contratos.php?lista=1&cliente_id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (campoId.value !== id) return;
          if (!r.ok) { lista.textContent = r.error || 'Não foi possível carregar.'; return; }
          if (!r.data.length) { lista.innerHTML = '<span style="opacity:.7">Nenhum contrato para este cliente.</span>'; return; }
          lista.innerHTML = r.data.slice(0, 6).map(function (c) {
            return '<a href="/contratos.php?id=' + c.id + '" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-top:1px solid rgba(17,29,45,.06);text-decoration:none;color:inherit">' +
              '<span>' + esc(c.titulo) + '</span><span style="opacity:.75;white-space:nowrap">' + esc(c.status_rotulo) + ' · ' + c.assinaturas + '/' + c.participantes + '</span></a>';
          }).join('');
        })
        .catch(function () { lista.textContent = 'Sem conexão.'; });
    }
    new MutationObserver(function () { if (modal.offsetParent !== null || modal.classList.contains('open') || modal.classList.contains('show')) carregar(); else ultimo = null; })
      .observe(modal, { attributes: true, attributeFilter: ['class', 'style', 'aria-hidden'] });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', montar);
  else montar();
})();
