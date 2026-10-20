/* ═══════════════════════════════════════════════════════════════════════
   ff-cliente-crm.js: seções da edição CRM na ficha do cliente (Clientes),
   encaixadas sem mexer em clientes.php:
     - "Financeiro": os valores combinados (mensal, anual, fixo ou único, por
       demanda), com dia de vencimento, início, fim e o total por mês.
       API /api/cliente_valores.php (App\Clientes\ValoresDoCliente).
     - "Contratos": os contratos do cliente e "Novo contrato" já ligado a ele.
   Carregado pelo sidebar.php em conta CRM; só age se a página tem a ficha.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var REAL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  var TIPOS = { mensal: 'Mensal', anual: 'Anual', fixo: 'Fixo / único', demanda: 'Por demanda' };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function dataBr(d) { return d ? d.split('-').reverse().join('/') : ''; }
  function csrf() { return (window.FF_AGENDA && window.FF_AGENDA.csrf) || (window.YURIS_NOTIF && window.YURIS_NOTIF.csrf) || ''; }

  var CSS = '' +
    '.ffcli-resumo{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}' +
    '.ffcli-tot{border:1px solid rgba(17,29,45,.10);border-radius:10px;padding:8px 12px;min-width:120px}' +
    '.ffcli-tot span{display:block;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#767676}' +
    '.ffcli-tot b{display:block;font-size:16px;font-weight:800;color:var(--ff-marca-forte,#013DF2)}' +
    '.ffcli-tot.princ{background:rgba(var(--ff-marca-rgb,1,93,252),.07);border-color:rgba(var(--ff-marca-rgb,1,93,252),.25)}' +
    '.ffcli-item{display:grid;grid-template-columns:1fr auto;gap:4px 12px;padding:9px 0;border-top:1px solid rgba(17,29,45,.07);align-items:center}' +
    '.ffcli-item.encerrado{opacity:.55}' +
    '.ffcli-item .tit{font-size:13px;font-weight:700;color:#1F2937}' +
    '.ffcli-item .val{font-size:14px;font-weight:800;color:#1F2937;text-align:right;white-space:nowrap}' +
    '.ffcli-item .sub{font-size:11.5px;color:#767676}' +
    '.ffcli-item .acoes{display:flex;gap:10px;justify-content:flex-end}' +
    '.ffcli-pill{display:inline-block;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:99px;margin-right:6px;background:rgba(var(--ff-marca-rgb,1,93,252),.10);color:var(--ff-marca-forte,#013DF2)}' +
    '.ffcli-pill.fixo{background:#FFF4D6;color:#B45309}.ffcli-pill.demanda{background:#EEF0F3;color:#575757}.ffcli-pill.anual{background:#EFEAFE;color:#6D4AFF}' +
    '.ffcli-link{background:none;border:0;padding:0;font:700 12px Manrope,system-ui,sans-serif;color:var(--ff-marca,#015DFC);cursor:pointer}' +
    '.ffcli-link.perigo{color:#B91C1C}' +
    '.ffcli-form{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-top:10px;padding:12px;border:1px dashed rgba(17,29,45,.18);border-radius:10px}' +
    '.ffcli-form label{display:block;font-size:11px;font-weight:700;color:#575757;margin-bottom:3px}' +
    '.ffcli-form input,.ffcli-form select{width:100%;padding:8px 10px;border:1px solid rgba(17,29,45,.16);border-radius:9px;font:500 13px Manrope,system-ui,sans-serif;background:#fff;color:#1F2937}' +
    '.ffcli-form .c2{grid-column:span 2}.ffcli-form .c3{grid-column:span 3}.ffcli-form .c6{grid-column:1/-1}' +
    '.ffcli-form .bts{grid-column:1/-1;display:flex;gap:8px;justify-content:flex-end}' +
    '.ffcli-bt{padding:8px 14px;border-radius:9px;border:1px solid rgba(17,29,45,.16);background:#fff;font:700 12.5px Manrope,system-ui,sans-serif;cursor:pointer;color:#1F2937}' +
    '.ffcli-bt.princ{background:var(--ff-marca,#015DFC);border-color:var(--ff-marca,#015DFC);color:var(--ff-marca-texto,#fff)}' +
    '.ffcli-erro{grid-column:1/-1;color:#B91C1C;font-size:12px;min-height:0}' +
    '.ffcli-vazio{font-size:12.5px;color:#767676;padding:4px 0}' +
    '.ffcli-pe{display:flex;justify-content:flex-end;margin-top:8px}' +
    '.ffcli-ctr{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid rgba(17,29,45,.07);text-decoration:none;color:inherit;font-size:13px}' +
    '.ffcli-ctr span:last-child{color:#767676;white-space:nowrap}' +
    '@media (max-width:700px){.ffcli-form{grid-template-columns:1fr 1fr}.ffcli-form .c2,.ffcli-form .c3{grid-column:span 1}}';

  function montar(tentativa) {
    var modal = document.getElementById('modalCliente'), campoId = document.getElementById('cliId'), F = window.FfFicha;
    if (!modal || !campoId) return;
    var esq = modal.querySelector('.ff-col-esq'), dir = modal.querySelector('.ff-col-dir');
    if (!F || !esq || !dir) { if ((tentativa || 0) < 40) setTimeout(function () { montar((tentativa || 0) + 1); }, 150); return; }
    if (document.getElementById('ffCliFinanceiro')) return;
    var st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);

    // ── Financeiro ────────────────────────────────────────────────────
    var fin = document.createElement('div');
    fin.id = 'ffCliFinanceiro';
    fin.innerHTML = '<div data-corpo></div>';
    var secFin = F.envolver([fin]);
    F.secao(secFin, 0, 'Financeiro', 'dolar', 'verde');
    F.emAbas(secFin, ['geral']);
    esq.insertBefore(secFin, esq.children[1] || null);

    // ── Contratos ─────────────────────────────────────────────────────
    var ctr = document.createElement('div');
    ctr.id = 'ffCliContratos';
    ctr.innerHTML = '<div data-lista></div><div class="ffcli-pe"><button type="button" class="ffcli-link" data-novo>+ Novo contrato</button></div>';
    var secCtr = F.envolver([ctr]);
    F.secao(secCtr, 0, 'Contratos', 'doc', 'roxo');
    F.emAbas(secCtr, ['geral']);
    var origem = Array.prototype.find.call(dir.children, function (s) { return s.getAttribute('data-ff-titulo') === 'Origem'; });
    dir.insertBefore(secCtr, origem ? origem.nextSibling : dir.firstChild);
    ctr.querySelector('[data-novo]').onclick = function () { if (campoId.value) location.href = '/contratos.php?novo=1&cliente_id=' + encodeURIComponent(campoId.value); };

    // Enter dentro destas seções não envia o formulário do cliente.
    [fin, ctr].forEach(function (el) { el.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); var b = fin.querySelector('[data-salvar]'); if (b && fin.contains(e.target)) b.click(); } }); });

    var corpo = fin.querySelector('[data-corpo]');
    var estado = { cliente: null, itens: [], resumo: null, editando: null, formAberto: false };

    function api(metodo, corpoReq, query) {
      var op = { method: metodo, credentials: 'same-origin', headers: { Accept: 'application/json' } };
      if (metodo === 'POST') { op.headers['Content-Type'] = 'application/json'; op.headers['X-CSRF-Token'] = csrf(); op.body = JSON.stringify(corpoReq); }
      return fetch('/api/cliente_valores.php' + (query || ''), op).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão.' }; });
    }

    function desenhar() {
      if (!estado.cliente) { corpo.innerHTML = '<div class="ffcli-vazio">Salve o cliente para cadastrar os valores combinados (mensal, fixo e outros).</div>'; return; }
      var r = estado.resumo || {}, h = '<div class="ffcli-resumo"><div class="ffcli-tot princ"><span>Por mês</span><b>' + REAL.format(r.por_mes || 0) + '</b></div>';
      if (r.fixo) h += '<div class="ffcli-tot"><span>Fixo / único</span><b>' + REAL.format(r.fixo) + '</b></div>';
      if (r.anual) h += '<div class="ffcli-tot"><span>Anual</span><b>' + REAL.format(r.anual) + '</b></div>';
      if (r.demanda) h += '<div class="ffcli-tot"><span>Por demanda</span><b>' + REAL.format(r.demanda) + '</b></div>';
      h += '</div>';
      if (!estado.itens.length) h += '<div class="ffcli-vazio">Nenhum valor cadastrado. Inclua a mensalidade, a taxa de implantação e o que mais foi combinado.</div>';
      h += estado.itens.map(function (i) {
        var det = [];
        if (i.dia_vencimento) det.push('vence todo dia ' + i.dia_vencimento);
        if (i.inicio && i.fim) det.push(dataBr(i.inicio) + ' a ' + dataBr(i.fim)); else if (i.inicio) det.push('desde ' + dataBr(i.inicio)); else if (i.fim) det.push('até ' + dataBr(i.fim));
        if (!i.ativo) det.push('encerrado');
        if (i.observacao) det.push(i.observacao);
        return '<div class="ffcli-item' + (i.ativo ? '' : ' encerrado') + '"><div><div class="tit"><span class="ffcli-pill ' + i.tipo + '">' + esc(i.tipo_rotulo) + '</span>' + esc(i.descricao) + '</div>' +
          (det.length ? '<div class="sub">' + esc(det.join(' · ')) + '</div>' : '') + '</div>' +
          '<div><div class="val">' + REAL.format(i.valor) + (i.tipo === 'mensal' ? '<span class="sub"> /mês</span>' : i.tipo === 'anual' ? '<span class="sub"> /ano</span>' : '') + '</div>' +
          '<div class="acoes"><button type="button" class="ffcli-link" data-editar="' + i.id + '">Editar</button>' +
          '<button type="button" class="ffcli-link" data-ativo="' + i.id + '">' + (i.ativo ? 'Encerrar' : 'Reativar') + '</button>' +
          '<button type="button" class="ffcli-link perigo" data-remover="' + i.id + '">Remover</button></div></div></div>';
      }).join('');
      if (estado.formAberto) h += formulario(estado.editando);
      else h += '<div class="ffcli-pe"><button type="button" class="ffcli-link" data-novo-valor>+ Adicionar valor</button></div>';
      corpo.innerHTML = h;
      ligar();
    }

    function formulario(i) {
      i = i || { tipo: 'mensal', ativo: true };
      var op = Object.keys(TIPOS).map(function (k) { return '<option value="' + k + '"' + (i.tipo === k ? ' selected' : '') + '>' + TIPOS[k] + '</option>'; }).join('');
      return '<div class="ffcli-form"><div class="c2"><label>Tipo</label><select data-f="tipo">' + op + '</select></div>' +
        '<div class="c2"><label>Valor (R$)</label><input data-f="valor" inputmode="decimal" placeholder="0,00" value="' + (i.valor != null ? String(i.valor).replace('.', ',') : '') + '"></div>' +
        '<div class="c2" data-dia><label>Dia de vencimento</label><input data-f="dia_vencimento" inputmode="numeric" maxlength="2" placeholder="10" value="' + (i.dia_vencimento || '') + '"></div>' +
        '<div class="c6"><label>Descrição</label><input data-f="descricao" maxlength="160" placeholder="Ex.: Mensalidade do sistema, Taxa de implantação" value="' + esc(i.descricao || '') + '"></div>' +
        '<div class="c3"><label>Início</label><input type="date" data-f="inicio" value="' + esc(i.inicio || '') + '"></div>' +
        '<div class="c3"><label>Fim (opcional)</label><input type="date" data-f="fim" value="' + esc(i.fim || '') + '"></div>' +
        '<div class="c6"><label>Observação (opcional)</label><input data-f="observacao" maxlength="300" value="' + esc(i.observacao || '') + '"></div>' +
        '<div class="ffcli-erro" data-erro></div>' +
        '<div class="bts"><button type="button" class="ffcli-bt" data-cancelar>Cancelar</button><button type="button" class="ffcli-bt princ" data-salvar>' + (i.id ? 'Salvar alteração' : 'Adicionar') + '</button></div></div>';
    }

    function ligar() {
      var q = function (s) { return corpo.querySelector(s); };
      var nv = q('[data-novo-valor]');
      if (nv) nv.onclick = function () { estado.formAberto = true; estado.editando = null; desenhar(); var v = q('[data-f="valor"]'); if (v) v.focus(); };
      var sel = q('[data-f="tipo"]');
      if (sel) { var dia = function () { q('[data-dia]').style.visibility = (sel.value === 'mensal' || sel.value === 'anual') ? '' : 'hidden'; }; sel.onchange = dia; dia(); }
      var cc = q('[data-cancelar]'); if (cc) cc.onclick = function () { estado.formAberto = false; estado.editando = null; desenhar(); };
      var sv = q('[data-salvar]');
      if (sv) sv.onclick = function () {
        var d = { acao: 'salvar', cliente_id: estado.cliente, id: estado.editando ? estado.editando.id : 0, ativo: estado.editando ? estado.editando.ativo : true };
        corpo.querySelectorAll('[data-f]').forEach(function (el) { d[el.getAttribute('data-f')] = el.value; });
        sv.disabled = true;
        api('POST', d).then(function (r) {
          sv.disabled = false;
          if (!r.ok) { q('[data-erro]').textContent = r.error || 'Não foi possível salvar.'; return; }
          estado.itens = r.data.itens; estado.resumo = r.data.resumo; estado.formAberto = false; estado.editando = null; desenhar();
        });
      };
      var porId = function (id) { return estado.itens.filter(function (i) { return i.id === +id; })[0]; };
      corpo.querySelectorAll('[data-editar]').forEach(function (b) { b.onclick = function () { estado.editando = porId(this.dataset.editar); estado.formAberto = true; desenhar(); }; });
      corpo.querySelectorAll('[data-ativo]').forEach(function (b) {
        b.onclick = function () {
          var i = porId(this.dataset.ativo);
          api('POST', Object.assign({}, i, { acao: 'salvar', cliente_id: estado.cliente, ativo: !i.ativo })).then(function (r) { if (r.ok) { estado.itens = r.data.itens; estado.resumo = r.data.resumo; desenhar(); } });
        };
      });
      corpo.querySelectorAll('[data-remover]').forEach(function (b) {
        b.onclick = function () {
          if (b.dataset.confirma !== '1') { b.dataset.confirma = '1'; b.textContent = 'Confirmar remoção'; setTimeout(function () { if (b.isConnected) { b.dataset.confirma = ''; b.textContent = 'Remover'; } }, 4000); return; }
          api('POST', { acao: 'remover', cliente_id: estado.cliente, id: +b.dataset.remover }).then(function (r) { if (r.ok) { estado.itens = r.data.itens; estado.resumo = r.data.resumo; desenhar(); } });
        };
      });
    }

    var listaCtr = ctr.querySelector('[data-lista]');
    function carregar() {
      var id = campoId.value ? +campoId.value : null;
      if (id === estado.cliente && estado.resumo) return;
      estado = { cliente: id, itens: [], resumo: null, editando: null, formAberto: false };
      if (!id) { desenhar(); listaCtr.innerHTML = '<div class="ffcli-vazio">Salve o cliente para ligar contratos a ele.</div>'; return; }
      corpo.innerHTML = '<div class="ffcli-vazio">Carregando…</div>';
      api('GET', null, '?cliente_id=' + id).then(function (r) {
        if (estado.cliente !== id) return;
        if (!r.ok) { corpo.innerHTML = '<div class="ffcli-vazio">' + esc(r.error || 'Não foi possível carregar.') + '</div>'; return; }
        estado.itens = r.data.itens; estado.resumo = r.data.resumo; desenhar();
      });
      listaCtr.innerHTML = '<div class="ffcli-vazio">Carregando…</div>';
      fetch('/api/crm_contratos.php?lista=1&cliente_id=' + id, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (estado.cliente !== id) return;
          if (!r.ok) { listaCtr.innerHTML = '<div class="ffcli-vazio">' + esc(r.error || 'Não foi possível carregar.') + '</div>'; return; }
          listaCtr.innerHTML = r.data.length ? r.data.slice(0, 8).map(function (c) {
            return '<a class="ffcli-ctr" href="/contratos.php?id=' + c.id + '"><span>' + esc(c.titulo) + '</span><span>' + esc(c.status_rotulo) + ' · ' + c.assinaturas + '/' + c.participantes + '</span></a>';
          }).join('') : '<div class="ffcli-vazio">Nenhum contrato para este cliente.</div>';
        })
        .catch(function () { listaCtr.innerHTML = '<div class="ffcli-vazio">Sem conexão.</div>'; });
    }
    var aberto = false;
    new MutationObserver(function () {
      var agora = modal.classList.contains('open');
      if (agora) carregar(); else if (aberto) estado.cliente = -1;
      aberto = agora;
    }).observe(modal, { attributes: true, attributeFilter: ['class'] });
    if (modal.classList.contains('open')) carregar();
    // Cliente novo: o id aparece no campo depois do primeiro Cadastrar, com a janela ainda aberta.
    setInterval(function () {
      if (!modal.classList.contains('open')) return;
      var id = campoId.value ? +campoId.value : null;
      if (id !== estado.cliente) carregar();
    }, 1000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(montar, 0); });
  else setTimeout(montar, 0);
})();
