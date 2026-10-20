/* ═══════════════════════════════════════════════════════════════════════
   recebimentos.js: painel "Recebimentos de clientes" da tela Finanças
   (edição CRM). Lê /api/recebimentos_clientes.php: os números do mês e a
   lista de cobranças (a receber, atrasadas, recebidas), com "Marcar como
   paga", "Desfazer baixa" e "Cancelar". As cobranças nascem dos valores
   combinados na ficha do cliente (App\Clientes\CobrancaDoCliente).
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var painel = document.getElementById('recebPanel');
  if (!painel) return;
  var REAL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  var FORMAS = {};
  var filtro = 'abertas', itens = [], pagando = null;
  var corpo = document.querySelector('#recebTabela tbody');
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function dataBr(d) { return d ? String(d).slice(0, 10).split('-').reverse().join('/') : ''; }
  function hojeIso() { var d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); }
  function csrf() { var el = document.querySelector('[name=csrf_token]'); return el ? el.value : ''; }

  var css = document.createElement('style');
  css.textContent = '' +
    '.receb-cab{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}' +
    '.receb-resumo{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px}' +
    '.receb-num{border:1px solid rgba(148,163,184,.18);border-radius:10px;padding:10px 12px;background:rgba(8,20,40,.45)}' +
    '.receb-num span{display:block;font-size:.6rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;margin-bottom:4px}' +
    '.receb-num b{font-size:1.1rem;font-weight:700;color:#e8f4ff;overflow-wrap:anywhere}' +
    '.receb-num small{display:block;color:var(--muted);font-size:.68rem;margin-top:2px}' +
    '.receb-num-atraso b{color:#fca5a5}.receb-num-ok b{color:#6ee7b7}' +
    '.receb-filtros{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px}' +
    '.receb-tabela td{white-space:normal;overflow-wrap:anywhere}' +
    '.receb-tabela td small{display:block;color:var(--muted);font-size:.68rem;margin-top:2px}' +
    '.receb-tabela td:last-child{white-space:nowrap}' +
    '.receb-tabela a{color:inherit;text-decoration:none}.receb-tabela a:hover{text-decoration:underline}' +
    '.receb-sit{display:inline-block;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:2px 7px;border-radius:999px;white-space:nowrap}' +
    '.receb-sit-aberta{background:rgba(148,163,184,.18);color:#cbd5e1}.receb-sit-atrasada{background:rgba(185,28,28,.25);color:#fca5a5}.receb-sit-paga{background:rgba(4,120,87,.25);color:#6ee7b7}' +
    '.receb-acoes{display:inline-flex;gap:6px;justify-content:flex-end}' +
    '.receb-baixa td{background:rgba(59,130,246,.06)}' +
    '.receb-baixa-form{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;justify-content:flex-end}' +
    '.receb-baixa-form label{display:flex;flex-direction:column;gap:3px;font-size:.65rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}' +
    '.receb-baixa-form input,.receb-baixa-form select{padding:6px 8px;border-radius:6px;font:inherit;font-size:.78rem;background:rgba(8,20,40,.85);border:1px solid rgba(96,165,250,.25);color:#e8f4ff}' +
    '.receb-erro{color:#fca5a5;font-size:.72rem;width:100%;text-align:right}' +
    'html[data-theme="light"] .receb-num{background:#FFFFFF;border-color:rgba(17,29,45,.10)}' +
    'html[data-theme="light"] .receb-num b{color:#0F1F36}html[data-theme="light"] .receb-num-atraso b{color:#B91C1C}html[data-theme="light"] .receb-num-ok b{color:#15803D}' +
    'html[data-theme="light"] .receb-sit-aberta{background:#EEF0F3;color:#374151}html[data-theme="light"] .receb-sit-atrasada{background:#FEE2E2;color:#B91C1C}html[data-theme="light"] .receb-sit-paga{background:#D1FAE5;color:#047857}' +
    'html[data-theme="light"] .receb-baixa-form input,html[data-theme="light"] .receb-baixa-form select{background:#FFFFFF;border-color:rgba(17,29,45,.18);color:#0F1F36}' +
    'html[data-theme="light"] .receb-erro{color:#B91C1C}' +
    '@media (max-width:900px){.receb-resumo{grid-template-columns:1fr 1fr}}';
  document.head.appendChild(css);

  function api(metodo, corpoReq) {
    var op = { method: metodo, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (metodo === 'POST') { op.headers['Content-Type'] = 'application/json'; op.headers['X-CSRF-Token'] = csrf(); op.body = JSON.stringify(corpoReq); }
    return fetch('/api/recebimentos_clientes.php' + (metodo === 'GET' ? '?filtro=' + filtro : ''), op)
      .then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão.' }; });
  }

  function carregar() {
    api('GET').then(function (r) {
      if (!r.ok) { corpo.innerHTML = '<tr class="empty-row"><td colspan="6">' + esc(r.error || 'Não foi possível carregar.') + '</td></tr>'; return; }
      FORMAS = r.formas || FORMAS;
      var k = r.data.kpi;
      document.getElementById('recebMes').textContent = REAL.format(k.a_receber_mes || 0);
      document.getElementById('recebAtrasado').textContent = REAL.format(k.atrasado || 0);
      document.getElementById('recebAtrasadas').textContent = k.atrasadas ? k.atrasadas + ' cobrança' + (k.atrasadas == 1 ? '' : 's') : '';
      document.getElementById('recebRecebido').textContent = REAL.format(k.recebido_mes || 0);
      document.getElementById('recebProximo').textContent = REAL.format(k.previsto_proximo || 0);
      document.getElementById('recebMrr').textContent = REAL.format(k.mensalidades_ativas || 0);
      itens = r.data.itens || [];
      desenhar();
    });
  }

  function desenhar() {
    if (!itens.length) {
      var vazio = { abertas: 'Nada a receber até o fim do mês que vem. Cadastre mensalidades e serviços na ficha do cliente (Clientes, seção Financeiro).', atrasadas: 'Nenhuma cobrança atrasada.', pagas: 'Nenhum recebimento nos últimos 90 dias.' }[filtro];
      corpo.innerHTML = '<tr class="empty-row"><td colspan="6">' + vazio + '</td></tr>';
      return;
    }
    corpo.innerHTML = itens.map(function (c) {
      var sit = c.status === 'paga' ? 'paga' : (c.atrasada ? 'atrasada' : 'aberta');
      var rot = { paga: 'Recebida', atrasada: 'Atrasada', aberta: 'A receber' }[sit];
      var detalhe = [c.tipo_rotulo];
      if (c.tipo === 'mensal') detalhe.push('referente a ' + dataBr(c.competencia).slice(3));
      if (c.contrato) detalhe.push('contrato: ' + c.contrato);
      var pago = c.status === 'paga' ? '<small>em ' + dataBr(c.pago_em) + (c.forma_pagamento ? ' por ' + esc(c.forma_pagamento) : '') + (c.valor_pago != null && c.valor_pago !== c.valor ? ', ' + REAL.format(c.valor_pago) : '') + '</small>' : '';
      var acoes = c.status === 'aberta'
        ? '<button type="button" class="btn btn-primary btn-sm" data-pagar="' + c.id + '">Marcar como paga</button><button type="button" class="btn btn-ghost btn-sm" data-cancelar="' + c.id + '">Cancelar</button>'
        : '<button type="button" class="btn btn-ghost btn-sm" data-desfazer="' + c.id + '">Desfazer baixa</button>';
      var h = '<tr><td><a href="/clientes?open=' + c.cliente_id + '"><b>' + esc(c.cliente) + '</b></a></td>' +
        '<td>' + esc(c.descricao) + '<small>' + esc(detalhe.join(' · ')) + '</small></td>' +
        '<td>' + dataBr(c.vencimento) + '</td>' +
        '<td style="text-align:right;white-space:nowrap"><b>' + REAL.format(c.valor) + '</b></td>' +
        '<td><span class="receb-sit receb-sit-' + sit + '">' + rot + '</span>' + pago + '</td>' +
        '<td style="text-align:right"><div class="receb-acoes">' + acoes + '</div></td></tr>';
      if (pagando === c.id) {
        var op = Object.keys(FORMAS).map(function (k) { return '<option value="' + k + '">' + esc(FORMAS[k]) + '</option>'; }).join('');
        h += '<tr class="receb-baixa"><td colspan="6"><div class="receb-baixa-form">' +
          '<label>Pago em<input type="date" data-b="pago_em" value="' + hojeIso() + '"></label>' +
          '<label>Valor recebido (R$)<input data-b="valor_pago" inputmode="decimal" value="' + c.valor.toFixed(2).replace('.', ',') + '"></label>' +
          '<label>Forma<select data-b="forma_pagamento"><option value="">Não informar</option>' + op + '</select></label>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-voltar>Voltar</button>' +
          '<button type="button" class="btn btn-primary btn-sm" data-confirmar>Confirmar pagamento</button>' +
          '<div class="receb-erro" data-erro></div></div></td></tr>';
      }
      return h;
    }).join('');
  }

  function acao(corpoReq, botao) {
    if (botao) botao.disabled = true;
    return api('POST', corpoReq).then(function (r) {
      if (botao) botao.disabled = false;
      if (!r.ok) { var e = corpo.querySelector('[data-erro]'); if (e) e.textContent = r.error || 'Não foi possível.'; else if (botao) botao.textContent = r.error || 'Não foi possível.'; return; }
      pagando = null; carregar();
    });
  }

  corpo.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.pagar) { pagando = +b.dataset.pagar; desenhar(); var v = corpo.querySelector('[data-b="valor_pago"]'); if (v) v.focus(); return; }
    if (b.hasAttribute('data-voltar')) { pagando = null; desenhar(); return; }
    if (b.hasAttribute('data-confirmar')) {
      var d = { acao: 'pagar', id: pagando };
      corpo.querySelectorAll('[data-b]').forEach(function (el) { d[el.getAttribute('data-b')] = el.value; });
      acao(d, b); return;
    }
    if (b.dataset.desfazer) { acao({ acao: 'desfazer', id: +b.dataset.desfazer }, b); return; }
    if (b.dataset.cancelar) {
      // Dois cliques, sem diálogo do navegador.
      if (b.dataset.confirma !== '1') { b.dataset.confirma = '1'; b.textContent = 'Confirmar cancelamento'; setTimeout(function () { if (b.isConnected) { b.dataset.confirma = ''; b.textContent = 'Cancelar'; } }, 4000); return; }
      acao({ acao: 'cancelar', id: +b.dataset.cancelar }, b);
    }
  });
  corpo.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.matches('[data-b]')) { e.preventDefault(); var c = corpo.querySelector('[data-confirmar]'); if (c) c.click(); } });

  painel.querySelectorAll('[data-receb-filtro]').forEach(function (b) {
    b.addEventListener('click', function () {
      filtro = b.dataset.recebFiltro; pagando = null;
      painel.querySelectorAll('[data-receb-filtro]').forEach(function (x) { x.classList.toggle('btn-primary', x === b); x.classList.toggle('btn-ghost', x !== b); });
      carregar();
    });
  });
  carregar();
})();
