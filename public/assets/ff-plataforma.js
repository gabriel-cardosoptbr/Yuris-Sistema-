/* ═══════════════════════════════════════════════════════════════════════
   ff-plataforma.js: a tela Plataforma do CRM (edição Fleetiflow), par de
   /plataforma.php. Liga o CRM à plataforma com o código de conexão e mostra
   a carteira: cada conta ligada a um lead, com plano, situação, quanto gera
   e os pedidos em aberto. Visual .orc- de ff-orcamentos.css.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var CFG = window.FF_PLA || {};
  var app = document.getElementById('plaApp');
  if (!app) return;
  var REAL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  var SITUACAO = { ativa: 'Ativa', teste: 'Em teste', inadimplente: 'Inadimplente', suspensa: 'Suspensa', cancelada: 'Cancelada' };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function real(v) { return v == null ? '—' : REAL.format(v); }
  function dataBr(s) {
    if (!s) return '—';
    var d = new Date(String(s).replace(' ', 'T') + 'Z');
    return isNaN(d) ? s : d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
  }
  function get(q) {
    return fetch(CFG.api + '?' + q, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); });
  }
  function post(corpo) {
    return fetch(CFG.api, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CFG.csrf },
      body: JSON.stringify(corpo)
    }).then(function (r) { return r.json(); });
  }

  function carregar() {
    app.innerHTML = '<div class="orc-cartao orc-vazio">Carregando…</div>';
    get('conexao=1').then(function (r) {
      if (!r.ok) { app.innerHTML = '<div class="orc-erro">' + esc(r.error) + '</div>'; return; }
      var c = r.data;
      if (!c.ligada) { desenharLigar(c); return; }
      get('carteira=1').then(function (k) { desenharCarteira(c, k.ok ? k.data : [], k.ok ? null : k.error); });
    }).catch(function () { app.innerHTML = '<div class="orc-erro">Sem conexão.</div>'; });
  }

  function desenharLigar(c) {
    if (!c.pode_configurar) {
      app.innerHTML = '<div class="orc-cartao orc-vazio">O CRM ainda não está ligado à plataforma. Peça ao dono ou a um administrador.</div>';
      return;
    }
    app.innerHTML =
      '<div class="orc-cartao" style="max-width:720px">' +
      '<h3 style="margin:0;font-size:16px;font-weight:800;color:var(--o-texto)">Ligar à plataforma</h3>' +
      '<p style="margin:6px 0 12px;font-size:13px;color:var(--o-texto-3);line-height:1.5">No Painel Master da plataforma, em Integrações › Sistema comercial, gere um código de conexão e cole aqui. ' +
      'É uma vez só: depois disso o CRM lê as contas e recebe os avisos (pedidos, recargas, troca de plano) sozinho.</p>' +
      '<div class="orc-erro" data-erro hidden></div>' +
      '<textarea class="pla-codigo" data-codigo placeholder="FFC1.…" spellcheck="false"></textarea>' +
      '<div style="margin-top:12px"><button type="button" class="orc-btn orc-btn-principal" data-ligar>Ligar</button></div></div>';
    var botao = app.querySelector('[data-ligar]');
    botao.addEventListener('click', function () {
      var erro = app.querySelector('[data-erro]');
      erro.hidden = true;
      botao.disabled = true;
      botao.textContent = 'Ligando…';
      post({ acao: 'conectar', codigo: app.querySelector('[data-codigo]').value }).then(function (r) {
        if (!r.ok) { erro.textContent = r.error; erro.hidden = false; botao.disabled = false; botao.textContent = 'Ligar'; return; }
        carregar();
      }).catch(function () { erro.textContent = 'Sem conexão.'; erro.hidden = false; botao.disabled = false; botao.textContent = 'Ligar'; });
    });
  }

  function desenharCarteira(c, linhas, erro) {
    var mrr = 0, mes = 0, pedidos = 0;
    linhas.forEach(function (l) { mrr += l.mensalidade || 0; mes += l.gerado_mes || 0; pedidos += l.pedidos_abertos || 0; });
    var cx = c.conexao;

    var h = '<div class="orc-cartao" style="margin-bottom:16px"><div class="orc-ficha-topo">' +
      '<h3>Ligado à plataforma</h3><span class="orc-pill orc-pill-CONVERTIDO">' + esc(cx.nome || 'Conectado') + '</span>' +
      (cx.pode_mudar ? '<span class="orc-pill orc-pill-APROVADO">pode mudar plano e módulo</span>' : '<span class="orc-pill">só leitura</span>') +
      '<span class="orc-aba-sep"></span>' +
      (c.pode_configurar ? '<button type="button" class="orc-btn orc-btn-sm orc-btn-perigo" data-desligar>Desligar</button>' : '') +
      '</div><div class="pla-meta"><span>API <b>' + esc(cx.api_url) + '</b></span><span>desde <b>' + dataBr(cx.conectado_em) + '</b></span>' +
      '<span>último aviso <b>' + dataBr(cx.ultimo_evento_em) + '</b></span></div></div>';

    h += '<div class="pla-resumo">' +
      tile('Contas ligadas', linhas.length) + tile('Mensalidades', real(mrr)) + tile('Gerado no mês', real(mes)) + tile('Pedidos em aberto', pedidos) + '</div>';
    if (erro) h += '<div class="orc-erro">' + esc(erro) + '</div>';

    if (!linhas.length) {
      h += '<div class="orc-cartao orc-vazio">Nenhum lead ligado ainda. Abra um lead em Prospecção e, na seção "Conta na plataforma", cole o código da conta (FLT-XXXX-XXXX).</div>';
    } else {
      h += '<div class="orc-tabela-wrap"><table class="orc-tabela"><thead><tr>' +
        '<th>Conta</th><th>Responsável</th><th>Plano</th><th>Situação</th><th>Mensalidade</th><th>Saldo</th><th>Gerou no mês</th><th>Gerou no total</th><th>Consultas</th><th>Gasto com consultas</th><th>Módulos</th><th>Pedidos</th><th>Oferecer</th>' +
        '</tr></thead><tbody>' + linhas.map(function (l) {
          return '<tr data-card="' + (l.card_id || '') + '">' +
            '<td><div class="orc-forte">' + esc(l.nome || l.codigo) + '</div><div class="orc-mono" style="color:var(--o-texto-4)">' + esc(l.codigo) + '</div></td>' +
            '<td>' + esc(l.responsavel || '—') + '</td>' +
            '<td>' + esc(l.plano || '—') + '</td>' +
            '<td>' + (l.situacao ? '<span class="orc-pill pla-pill-' + esc(l.situacao) + '">' + esc(SITUACAO[l.situacao] || l.situacao) + '</span>' : '—') + '</td>' +
            '<td class="orc-num">' + real(l.mensalidade) + '</td>' +
            '<td class="orc-num">' + real(l.saldo) + '</td>' +
            '<td class="orc-num">' + real(l.gerado_mes) + '</td>' +
            '<td class="orc-num orc-forte">' + real(l.gerado_total) + '</td>' +
            '<td class="orc-num">' + (l.consultas_total == null ? '—' : l.consultas_total.toLocaleString('pt-BR') + (l.consultas_mes ? ' <span style="color:var(--o-texto-4)">(' + l.consultas_mes + ' no mês)</span>' : '')) + '</td>' +
            '<td class="orc-num">' + real(l.gasto_consultas) + '</td>' +
            '<td class="orc-num">' + l.modulos_ativos + ' de ' + l.modulos_total + '</td>' +
            '<td>' + (l.pedidos_abertos ? '<span class="orc-pill orc-pill-REJEITADO">' + l.pedidos_abertos + ' em aberto</span>' : '—') + '</td>' +
            '<td class="orc-num">' + (l.oportunidades || '—') + '</td></tr>';
        }).join('') + '</tbody></table></div>' +
        '<p style="margin:10px 2px 0;font-size:12px;color:var(--o-texto-4)">Os números são da última vez que o lead foi aberto ou que a plataforma avisou de uma mudança.</p>';
    }
    app.innerHTML = h;

    Array.prototype.forEach.call(app.querySelectorAll('tr[data-card]'), function (tr) {
      tr.addEventListener('click', function () {
        var id = tr.getAttribute('data-card');
        if (id) location.href = '/prospeccao.php?open=' + encodeURIComponent(id);
      });
    });
    var desligar = app.querySelector('[data-desligar]');
    if (desligar) desligar.addEventListener('click', function () {
      if (!window.confirm('Desligar o CRM da plataforma? Os leads continuam ligados às contas, mas nada novo chega até ligar de novo com outro código.')) return;
      post({ acao: 'desconectar' }).then(carregar);
    });
  }

  function tile(rotulo, valor) {
    return '<div class="orc-cartao"><span>' + esc(rotulo) + '</span><b>' + esc(valor) + '</b></div>';
  }

  carregar();
})();
