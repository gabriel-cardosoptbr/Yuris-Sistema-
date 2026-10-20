/* ═══════════════════════════════════════════════════════════════════════
   ff-orcamento-lead.js: a seção "Orçamentos" da ficha do lead (Prospecção,
   edição CRM). Lista os orçamentos do lead e abre um novo já com ele.
   Carregado pelo sidebar.php em conta Fleetiflow; usa o visual de
   ff-agenda.css. A tela completa é /orcamentos.php.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var REAL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  var ROTULO = { RASCUNHO: 'Rascunho', APROVADO: 'Aprovado', CONVERTIDO: 'Negócio fechado', REJEITADO: 'Rejeitado' };
  var TOM = { RASCUNHO: '', APROVADO: ' ffag-pill-azul', CONVERTIDO: ' ffag-pill-verde', REJEITADO: ' ffag-pill-vermelho' };

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
    sec.id = 'ffOrcamentoSection';
    sec.innerHTML = '<div class="ffag-lista"></div><div class="ffag-sec-pe"><a href="/orcamentos.php">Ver todos os orçamentos</a>' +
      '<a class="ffag-btn ffag-btn-leve" data-novo href="#" style="text-decoration:none">+ Novo orçamento</a></div>';
    var agenda = document.getElementById('ffAgendaSection');
    var dir = form.querySelector('.ff-col-dir');
    if (agenda && agenda.parentNode) agenda.parentNode.insertBefore(sec, agenda.nextSibling);
    else if (dir) dir.insertBefore(sec, dir.firstChild);
    else return;
    F.secao(sec, 0, 'Orçamentos', 'dolar', 'verde');
    F.emAbas(sec, ['geral', 'comercial']);

    var lista = sec.querySelector('.ffag-lista');
    sec.querySelector('[data-novo]').addEventListener('click', function (e) {
      e.preventDefault();
      var id = form.elements.id.value;
      if (id) location.href = '/orcamentos.php?novo=1&card=' + encodeURIComponent(id);
    });

    function carregar() {
      var id = form.elements.id.value;
      if (!id) { lista.innerHTML = ''; return; }
      lista.innerHTML = '<div class="ffag-vazio">Carregando…</div>';
      fetch('/api/crm_orcamentos.php?card_id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (form.elements.id.value !== id) return;
          if (!r.ok) { lista.innerHTML = '<div class="ffag-vazio">' + esc(r.error || 'Não foi possível carregar.') + '</div>'; return; }
          if (!r.data.length) {
            lista.innerHTML = '<div class="ffag-vazio">Nenhum orçamento para este lead ainda.</div>';
            return;
          }
          lista.innerHTML = r.data.slice(0, 5).map(function (o) {
            return '<a class="ffag-item" href="/orcamentos.php?id=' + o.id + '" style="text-decoration:none">' +
              '<div class="ffag-quando"><span>Nº</span><b>' + o.numero + '</b></div>' +
              '<div><div class="ffag-item-tit">' + REAL.format(o.total) + (o.placa ? ' · ' + esc(o.placa) : '') + '</div>' +
              '<div class="ffag-item-sub"><span class="ffag-pill' + TOM[o.status] + '">' + ROTULO[o.status] + '</span><span>' + o.itens + (o.itens === 1 ? ' item' : ' itens') + '</span></div></div>' +
              '<div class="ffag-acoes"></div></a>';
          }).join('');
        })
        .catch(function () { lista.innerHTML = '<div class="ffag-vazio">Sem conexão.</div>'; });
    }
    F.aoAbrir(shell, carregar);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', montar);
  else montar();
})();
