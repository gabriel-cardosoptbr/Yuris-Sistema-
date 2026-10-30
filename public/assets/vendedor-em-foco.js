/* vendedor-em-foco.js: o seletor "Vendedor" do menu (sidebar.php). Grava a escolha
   em /api/vendedor_em_foco.php e recarrega: cada tela lê o vendedor no servidor
   (App\Core\VendedorEmFoco).

   Os filtros "Responsável" que as telas já tinham (painel, funil, clientes,
   tarefas, relatórios) viram espelho dele: começam no vendedor escolhido e,
   trocados, trocam o vendedor de todas as telas. */
(function () {
  var caixa = document.getElementById('sbVend');
  if (!caixa) return;
  var btn = document.getElementById('sbVendBtn');
  var lista = document.getElementById('sbVendLista');
  var ESPELHOS = ['ffcResponsavel', 'filterResponsible', 'filterResponsavel', 'fltResponsavel', 'f-resp'];

  async function definir(id) {
    try {
      var r = await fetch('/api/vendedor_em_foco.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': caixa.dataset.csrf || '' },
        body: JSON.stringify({ vendedor_id: Number(id) || null })
      });
      var j = await r.json().catch(function () { return {}; });
      if (j.ok) {
        // Responsável fixado no endereço (relatórios, desempenho, atendimento) ganharia da escolha nova.
        var u = new URL(location.href);
        ['responsavel_id', 'responsavel', 'colaborador', 'operador'].forEach(function (k) { u.searchParams.delete(k); });
        if (u.href === location.href) location.reload(); else location.href = u.href;
        return true;
      }
      if (window.Yuris && Yuris.toast) Yuris.toast(j.error || 'Não foi possível trocar o vendedor.', 'error');
    } catch (_) {
      if (window.Yuris && Yuris.toast) Yuris.toast('Falha de conexão.', 'error');
    }
    return false;
  }

  function fechar() { lista.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    var abrir = lista.hidden;
    lista.hidden = !abrir;
    btn.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    if (abrir) { var a = lista.querySelector('button.atual') || lista.querySelector('button'); if (a) a.focus(); }
  });
  document.addEventListener('click', function (e) { if (!caixa.contains(e.target)) fechar(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !lista.hidden) { fechar(); btn.focus(); } });

  lista.addEventListener('click', async function (e) {
    var b = e.target.closest('button[data-vendedor]');
    if (!b || b.classList.contains('atual')) { fechar(); return; }
    lista.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
    if (!(await definir(b.dataset.vendedor))) lista.querySelectorAll('button').forEach(function (x) { x.disabled = false; });
  });

  // Captura: roda antes do listener da própria tela, que deixa de filtrar só ali.
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || ESPELHOS.indexOf(el.id) < 0) return;
    e.stopImmediatePropagation();
    el.disabled = true;
    definir(el.value).then(function (ok) { if (!ok) el.disabled = false; });
  }, true);
})();
