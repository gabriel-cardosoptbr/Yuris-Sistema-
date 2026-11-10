/* ═══════════════════════════════════════════════════════════════════════════
   interface-nova-telas.js: arrumações de uma tela só (interface nova, prévia).
   Par da seção "Processos" de interface-nova.css. Carregado por
   includes/sidebar.php nas telas de dentro, fora do ?embed=1.

   Processos (10/11/2026): "essa Agenda Jurídica Prioritária, melhor um botão
   ali em cima: ver em formato de agenda. Clica ali e vem." O painel da agenda
   sai do fim da página e passa a abrir numa janela, pelo botão "Agenda
   jurídica" no cabeçalho. É o mesmo painel (o processos.js continua enchendo
   o #upcomingList, com Editar e Excluir): só muda de lugar.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var html = document.documentElement;
  if (html.classList.contains('yuris-embed') || /[?&]embed=1(&|$)/.test(location.search)) return;

  function agendaDeProcessos() {
    var lista = document.getElementById('upcomingList');
    var cab = document.querySelector('.proc-panel.page-header .page-header-inner');
    if (!lista || !cab || document.querySelector('.inn-agenda-btn')) return;
    var painel = lista.closest('.proc-panel'); if (!painel) return;

    var fundo = document.createElement('div');
    fundo.className = 'inn-agenda-overlay'; fundo.hidden = true;
    fundo.setAttribute('role', 'dialog'); fundo.setAttribute('aria-modal', 'true'); fundo.setAttribute('aria-label', 'Agenda jurídica prioritária');
    var janela = document.createElement('div'); janela.className = 'inn-agenda-janela';
    fundo.appendChild(janela);
    painel.classList.add('inn-agenda-painel');
    janela.appendChild(painel);
    document.body.appendChild(fundo);

    // O fechar, no canto do título do painel.
    var topo = painel.querySelector('.proc-section-title'); topo = topo && topo.closest('.flex') || painel.firstElementChild;
    var fechar = document.createElement('button');
    fechar.type = 'button'; fechar.className = 'inn-agenda-fechar'; fechar.setAttribute('aria-label', 'Fechar a agenda');
    fechar.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    if (topo) topo.appendChild(fechar);

    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'inn-agenda-btn';
    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><span>Agenda jurídica</span><b class="inn-agenda-conta" hidden></b>';
    cab.appendChild(btn);

    // Quantos prazos a agenda tem agora (o processos.js reenche a lista ao trocar o ano ou excluir).
    var conta = btn.querySelector('.inn-agenda-conta');
    function contar() {
      var n = lista.querySelectorAll('.proc-agenda-row').length;
      conta.hidden = !n; conta.textContent = n > 49 ? '50+' : String(n);
    }
    new MutationObserver(contar).observe(lista, { childList: true }); contar();

    var antes = null;
    function abrir() { antes = document.activeElement; fundo.hidden = false; document.body.style.overflow = 'hidden'; setTimeout(function () { fechar.focus(); }, 50); }
    function fecha() { fundo.hidden = true; document.body.style.overflow = ''; if (antes && antes.focus) antes.focus(); }
    btn.addEventListener('click', abrir);
    fechar.addEventListener('click', fecha);
    fundo.addEventListener('mousedown', function (e) { if (e.target === fundo) fecha(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !fundo.hidden && !document.querySelector('#modalProcess:not(.hidden)')) fecha(); });
    // Editar abre a ficha do processo por cima: a agenda fica embaixo e volta ao fechar a ficha.
  }

  function ligar() { agendaDeProcessos(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ligar); else ligar();
})();
