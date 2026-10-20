/* ═══════════════════════════════════════════════════════════════════════
   ff-contrato-lead.js: a seção "Contratos" da ficha do lead (Prospecção,
   edição CRM). Lista os contratos da negociação e abre um novo já ligado a
   ela. Carregado pelo sidebar.php em conta CRM; usa o visual de ff-agenda.css.
   A tela completa é /contratos.php.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var TOM = { rascunho: '', aguardando: ' ffag-pill-azul', parcial: ' ffag-pill-azul', concluido: ' ffag-pill-verde',
    recusado: ' ffag-pill-vermelho', expirado: ' ffag-pill-vermelho', cancelado: ' ffag-pill-vermelho' };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function montar() {
    var F = window.FfFicha, shell = document.getElementById('modalEdit'), form = document.getElementById('editForm');
    if (!F || !shell || !form || !form.elements.id) return;

    var sec = document.createElement('div');
    sec.className = 'form-section ffag-sec';
    sec.id = 'ffContratoSection';
    sec.innerHTML = '<div class="ffag-lista"></div><div class="ffag-sec-pe"><a href="/contratos">Ver todos os contratos</a>' +
      '<a class="ffag-btn ffag-btn-leve" data-novo href="#" style="text-decoration:none">+ Novo contrato</a></div>';
    var antes = document.getElementById('ffOrcamentoSection') || document.getElementById('ffAgendaSection');
    var dir = form.querySelector('.ff-col-dir');
    if (antes && antes.parentNode) antes.parentNode.insertBefore(sec, antes.nextSibling);
    else if (dir) dir.insertBefore(sec, dir.firstChild);
    else return;
    F.secao(sec, 0, 'Contratos', 'doc', 'roxo');
    F.emAbas(sec, ['geral', 'comercial']);

    var lista = sec.querySelector('.ffag-lista');
    sec.querySelector('[data-novo]').addEventListener('click', function (e) {
      e.preventDefault();
      var id = form.elements.id.value;
      if (id) location.href = '/contratos?novo=1&card_id=' + encodeURIComponent(id);
    });

    function carregar() {
      var id = form.elements.id.value;
      if (!id) { lista.innerHTML = ''; return; }
      lista.innerHTML = '<div class="ffag-vazio">Carregando…</div>';
      fetch('/api/crm_contratos.php?lista=1&card_id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (form.elements.id.value !== id) return;
          if (!r.ok) { lista.innerHTML = '<div class="ffag-vazio">' + esc(r.error || 'Não foi possível carregar.') + '</div>'; return; }
          if (!r.data.length) { lista.innerHTML = '<div class="ffag-vazio">Nenhum contrato para este lead.</div>'; return; }
          lista.innerHTML = r.data.slice(0, 5).map(function (c) {
            return '<a class="ffag-item" href="/contratos?id=' + c.id + '" style="text-decoration:none">' +
              '<div class="ffag-quando"><span>Assin.</span><b>' + c.assinaturas + '/' + c.participantes + '</b></div>' +
              '<div><div class="ffag-item-tit">' + esc(c.titulo) + '</div>' +
              '<div class="ffag-item-sub"><span class="ffag-pill' + (TOM[c.status] || '') + '">' + esc(c.status_rotulo) + '</span>' +
              (c.prazo ? '<span>prazo ' + esc(c.prazo) + '</span>' : '') + '</div></div><div class="ffag-acoes"></div></a>';
          }).join('');
        })
        .catch(function () { lista.innerHTML = '<div class="ffag-vazio">Sem conexão.</div>'; });
    }
    F.aoAbrir(shell, carregar);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', montar);
  else montar();
})();
