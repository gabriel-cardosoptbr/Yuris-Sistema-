/* ═══════════════════════════════════════════════════════════════════════
   ff-cliente-operacao.js: a ficha do cliente nos ambientes da Inovaize.
   Carregado pelo sidebar.php só na conta com ambientes, na tela Clientes.

   Junta à ficha (sem mexer em clientes.php):
     - aba "Produção": os quadros do cliente (Software e Digital), a
       frequência contratada (6 vídeos por mês...) e as campanhas;
     - aba "Entregas": o mês do cliente (contratadas, em produção, aguardando
       aprovação, concluídas, atrasadas), previsto x realizado por tipo e a
       lista de entregas; a contagem é de entregas, nunca de checklist;
     - "Operação" na Visão geral: em que áreas o cliente está;
     - "Google Drive" na aba Arquivos (ff-drive.js, quando ligado).
   Dados de /api/digital.php. Visual sobre ff-ficha.css e .ffcli- do
   ff-cliente-crm.js.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var CSRF = function () { return (window.FF_AGENDA && window.FF_AGENDA.csrf) || ''; };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function num(v) { return (Number(v) || 0).toLocaleString('pt-BR'); }
  function data(v) { if (!v) return ''; var s = String(v); return s.slice(8, 10) + '/' + s.slice(5, 7); }
  function pedir(qs, corpo) {
    var op = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (corpo) { op.method = 'POST'; op.headers['Content-Type'] = 'application/json'; op.headers['X-CSRF-Token'] = CSRF(); op.body = JSON.stringify(corpo); }
    return fetch('/api/digital.php' + (qs || ''), op).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão.' }; });
  }
  var AREA = { software: 'Software', digital: 'Digital / Marketing', empresa: 'Empresa', comercial: 'Comercial' };
  var CSS = '.ffop-quadros{display:flex;flex-direction:column;gap:8px}.ffop-q{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;padding:10px 12px;border-radius:10px;background:var(--ff-fundo-2,rgba(17,29,45,.04));text-decoration:none;color:inherit}' +
    '.ffop-q:hover{outline:1px solid var(--ff-marca,#015DFC)}.ffop-q b{font-size:13.5px;overflow-wrap:anywhere}.ffop-q small{display:block;font-size:12px;opacity:.75}' +
    '.ffop-nums{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;margin-bottom:12px}.ffop-n{padding:10px;border-radius:10px;background:var(--ff-fundo-2,rgba(17,29,45,.04))}.ffop-n span{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;opacity:.7}.ffop-n b{font-size:20px;font-weight:800}' +
    '.ffop-n.alerta b{color:#B91C1C}.ffop-n.atencao b{color:#B45309}.ffop-tab{width:100%;border-collapse:collapse;font-size:13px}.ffop-tab th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;opacity:.7;padding:6px 8px;border-bottom:1px solid rgba(17,29,45,.12)}' +
    '.ffop-tab td{padding:8px;border-bottom:1px solid rgba(17,29,45,.07);vertical-align:top}.ffop-tab td small{display:block;opacity:.7;font-size:11.5px}.ffop-pend{color:#B45309;font-weight:700}.ffop-mes{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}' +
    '.ffop-mes input{padding:6px 10px;border-radius:9px;border:1px solid rgba(17,29,45,.16);font:inherit}.ffop-areas{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}.ffop-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-top:10px;align-items:end}' +
    '.ffop-form label{display:flex;flex-direction:column;gap:4px;font-size:11.5px;font-weight:700;opacity:.85}.ffop-form input,.ffop-form select{padding:7px 9px;border-radius:9px;border:1px solid rgba(17,29,45,.16);font:inherit;font-size:13px;background:transparent;color:inherit}' +
    'html:not([data-theme="light"]) .ffop-q,html:not([data-theme="light"]) .ffop-n{background:rgba(255,255,255,.05)}html:not([data-theme="light"]) .ffop-n.alerta b{color:#F87171}html:not([data-theme="light"]) .ffop-n.atencao b{color:#FBBF24}html:not([data-theme="light"]) .ffop-pend{color:#FBBF24}';

  function montar(tentativa) {
    var modal = document.getElementById('modalCliente'), campoId = document.getElementById('cliId'), F = window.FfFicha;
    var esq = modal && modal.querySelector('.ff-col-esq'), dir = modal && modal.querySelector('.ff-col-dir'), nav = modal && modal.querySelector('.ff-abas');
    if (!modal || !campoId) return;
    if (!F || !esq || !dir || !nav) { if ((tentativa || 0) < 40) setTimeout(function () { montar((tentativa || 0) + 1); }, 150); return; }
    if (document.getElementById('ffOpProducao')) return;
    var st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);

    // Abas novas, antes de Arquivos.
    var antes = nav.querySelector('[data-aba="arquivos"]');
    [['producao', 'Produção', 'tarefa'], ['entregas', 'Entregas', 'calendario']].forEach(function (a) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'ff-aba'; b.setAttribute('role', 'tab'); b.dataset.aba = a[0];
      b.innerHTML = F.ico(a[2]) + '<span>' + a[1] + '</span>';
      nav.insertBefore(b, antes);
    });

    function secao(id, titulo, icone, cor, abas, coluna, primeiro) {
      var el = document.createElement('div'); el.id = id;
      var s = F.envolver([el]);
      F.secao(s, 0, titulo, icone, cor);
      F.emAbas(s, abas);
      if (primeiro) coluna.insertBefore(s, coluna.firstChild); else coluna.appendChild(s);
      return el;
    }
    var elOper = secao('ffOpResumo', 'Operação', 'maleta', 'roxo', ['geral'], dir, true);
    var elQuadros = secao('ffOpProducao', 'Quadros de produção e projetos', 'tarefa', 'verde', ['producao'], esq);
    var elFreq = secao('ffOpFrequencia', 'Frequência contratada', 'calendario', 'ambar', ['producao'], dir);
    var elCamp = secao('ffOpCampanhas', 'Campanhas de tráfego pago', 'bandeira', '', ['producao'], dir);
    var elMes = secao('ffOpMes', 'Entregas do mês', 'calendario', 'verde', ['entregas'], esq);
    var elLista = secao('ffOpLista', 'Entregas', 'tarefa', '', ['entregas'], dir);
    var elDrive = window.FfDrive ? secao('ffOpDrive', 'Google Drive do cliente', 'clipe', '', ['arquivos'], dir, true) : null;

    var estado = { id: null, mes: new Date().toISOString().slice(0, 7), dados: null, apoio: null };
    function apoio() { return estado.apoio ? Promise.resolve(estado.apoio) : pedir('?apoio=1').then(function (r) { estado.apoio = r.ok ? r.data : { tipos: {}, periodos: {} }; return estado.apoio; }); }

    function desenharResumo() {
      var d = estado.dados;
      if (!d) return;
      var areas = d.areas.length ? d.areas.map(function (a) { return '<span class="ff-pill">' + esc(AREA[a] || a) + '</span>'; }).join('') : '<span class="ff-pill ff-pill-pausa">Sem área ainda</span>';
      var r = d.resumo || {};
      var marc = d.areas_marcadas || [];
      var botoes = '<div class="ffop-areas" data-marcar>' + [['software', 'Software'], ['digital', 'Digital / Marketing']].map(function (a) {
        var on = marc.indexOf(a[0]) >= 0;
        return '<button type="button" class="ffcli-setopt' + (on ? ' on' : '') + '" data-area="' + a[0] + '" aria-pressed="' + on + '">' + a[1] + '</button>';
      }).join('') + '</div>';
      elOper.innerHTML = '<div class="ffcli-vazio" style="margin-bottom:4px">Áreas do cliente agora:</div><div class="ffop-areas">' + areas + '</div>' +
        '<div class="ffcli-vazio" style="margin:8px 0 4px">Marcar a área direto (cliente novo, ainda sem quadro, frequência ou valor):</div>' + botoes +
        '<div class="ffcli-erro" data-area-erro></div>' +
        '<div class="ffcli-vazio">' + num(d.quadros.length) + ' quadro(s) · ' + num(d.frequencias.length) + ' frequência(s) · ' + num(d.campanhas.filter(function (k) { return k.status === 'ativa'; }).length) + ' campanha(s) ativa(s)' +
        (r.contratadas || r.cadastradas ? ' · ' + num(r.concluidas) + ' de ' + num(r.contratadas || r.cadastradas) + ' entregas do mês concluídas' : '') + '</div>' +
        '<div class="ffcli-vazio" style="margin-top:4px">O cliente também entra em Software ou Digital pelo que contratou (valor com área, frequência, campanha) e pelos quadros da equipe. O cadastro é um só.</div>';
      elOper.querySelectorAll('[data-area]').forEach(function (b) {
        b.onclick = function () {
          var lista = marc.slice(), i = lista.indexOf(b.dataset.area);
          if (i >= 0) lista.splice(i, 1); else lista.push(b.dataset.area);
          b.disabled = true;
          pedir('', { acao: 'areas', cliente_id: estado.id, areas: lista }).then(function (x) {
            if (!x.ok) { b.disabled = false; elOper.querySelector('[data-area-erro]').textContent = x.error || 'Não foi possível salvar.'; return; }
            estado.dados.areas = x.areas; estado.dados.areas_marcadas = x.areas_marcadas; desenharResumo();
          });
        };
      });
    }
    function desenharQuadros() {
      var d = estado.dados;
      var h = d.quadros.length ? '<div class="ffop-quadros">' + d.quadros.map(function (b) {
        var area = b.area || '';
        return '<a class="ffop-q" href="/tarefas?' + (area === 'software' || area === 'digital' ? 'amb=' + area + '&' : '') + 'board=' + b.id + '"><span><b>' + esc(b.nome) + '</b><small>' + esc(AREA[area] || 'Tarefas') + '</small></span>' +
          '<span class="ff-pill' + (b.atrasadas ? ' ff-pill-off' : '') + '">' + num(b.abertas) + ' aberta(s)' + (b.atrasadas ? ' · ' + num(b.atrasadas) + ' atrasada(s)' : '') + (b.proximo ? ' · próximo ' + data(b.proximo) : '') + '</span></a>';
      }).join('') + '</div>' : '<div class="ffcli-vazio">Nenhum quadro deste cliente.</div>';
      if (d.pode_tarefas) h += '<div class="ffcli-pe"><a class="ffcli-link" href="/tarefas?amb=digital&novo=1&cliente=' + d.cliente.id + '">+ Quadro de Digital</a><a class="ffcli-link" href="/tarefas?amb=software&novo=1&cliente=' + d.cliente.id + '">+ Quadro de Software</a></div>';
      elQuadros.innerHTML = h;
    }
    function desenharFrequencia() {
      var d = estado.dados, ap = estado.apoio || { tipos: {}, periodos: {} };
      var h = d.frequencias.length ? '<table class="ffop-tab"><thead><tr><th>Entrega</th><th>Quantidade</th><th>Vigência</th><th></th></tr></thead><tbody>' + d.frequencias.map(function (f) {
        return '<tr><td>' + esc(f.tipo_rotulo) + '</td><td>' + num(f.quantidade) + ' ' + esc(f.periodo_rotulo) + '</td><td>desde ' + esc(data(f.inicio) + '/' + String(f.inicio).slice(0, 4)) + (f.fim ? ' até ' + esc(data(f.fim) + '/' + String(f.fim).slice(0, 4)) : '') + '</td>' +
          '<td><button type="button" class="ffcli-link" data-rem-freq="' + f.id + '">Remover</button></td></tr>';
      }).join('') + '</tbody></table>' : '<div class="ffcli-vazio">Nenhuma frequência contratada. Ex.: 6 vídeos por mês, 3 posts por semana. É o previsto do previsto x realizado.</div>';
      h += '<div class="ffop-form" data-amb-guarda><label>Entrega<select data-ff="tipo">' + Object.keys(ap.tipos).map(function (k) { return '<option value="' + k + '">' + esc(ap.tipos[k]) + '</option>'; }).join('') + '</select></label>' +
        '<label>Quantidade<input data-ff="quantidade" type="number" min="1" max="500" value="4"></label>' +
        '<label>Período<select data-ff="periodo">' + Object.keys(ap.periodos).map(function (k) { return '<option value="' + k + '">' + esc(ap.periodos[k]) + '</option>'; }).join('') + '</select></label>' +
        '<label>Começa em<input data-ff="inicio" type="date" value="' + new Date().toISOString().slice(0, 8) + '01"></label>' +
        '<label>Termina em<input data-ff="fim" type="date"></label>' +
        '<button type="button" class="ffcli-bt princ" data-add-freq>Adicionar</button></div><div class="ffcli-erro" data-freq-erro></div>';
      elFreq.innerHTML = h;
      elFreq.querySelector('[data-add-freq]').onclick = function () {
        var corpo = { acao: 'frequencia_salvar', cliente_id: estado.id };
        elFreq.querySelectorAll('[data-ff]').forEach(function (el) { corpo[el.getAttribute('data-ff')] = el.value; });
        this.disabled = true;
        var bt = this;
        pedir('', corpo).then(function (r) {
          bt.disabled = false;
          if (!r.ok) { elFreq.querySelector('[data-freq-erro]').textContent = r.error || 'Não foi possível salvar.'; return; }
          elFreq.querySelector('.ffop-form').removeAttribute('data-amb-sujo');
          carregar(true);
        });
      };
      elFreq.querySelectorAll('[data-rem-freq]').forEach(function (b) {
        b.onclick = function () { pedir('', { acao: 'frequencia_remover', cliente_id: estado.id, id: +b.dataset.remFreq }).then(function () { carregar(true); }); };
      });
    }
    function desenharCampanhas() {
      var d = estado.dados;
      elCamp.innerHTML = (d.campanhas.length ? d.campanhas.map(function (k) {
        return '<div class="ffop-q"><span><b>' + esc(k.nome) + '</b><small>' + esc(k.plataforma_rotulo) + (k.objetivo ? ' · ' + esc(k.objetivo) : '') + '</small></span><span class="ff-pill' + (k.status === 'ativa' ? ' ff-pill-ok' : '') + '">' + esc(k.status_rotulo) + '</span></div>';
      }).join('') : '<div class="ffcli-vazio">Nenhuma campanha.</div>') +
        '<div class="ffcli-pe"><a class="ffcli-link" href="/digital?ver=campanhas">Gerenciar campanhas</a></div>';
    }
    function desenharMes() {
      var d = estado.dados, r = d.resumo || {};
      var h = '<div class="ffop-mes"><label>Mês <input type="month" value="' + esc(estado.mes) + '" data-mes data-amb-ignorar></label></div>' +
        '<div class="ffop-nums">' + [['Contratadas', r.contratadas], ['Cadastradas', r.cadastradas], ['Em produção', r.producao], ['Aguardando aprovação', r.aprovacao, r.aprovacao ? 'atencao' : ''],
          ['Concluídas', r.concluidas], ['Atrasadas', r.atrasadas, r.atrasadas ? 'alerta' : ''], ['Sem cartão', r.nao_cadastradas, r.nao_cadastradas ? 'atencao' : '']].map(function (x) {
          return '<div class="ffop-n ' + (x[2] || '') + '"><span>' + x[0] + '</span><b>' + num(x[1]) + '</b></div>';
        }).join('') + '</div>';
      var tipos = Object.keys(r.por_tipo || {});
      h += tipos.length ? '<table class="ffop-tab"><thead><tr><th>Tipo</th><th>Previsto</th><th>Cadastradas</th><th>Entregues</th><th>Pendentes</th></tr></thead><tbody>' + tipos.map(function (k) {
        var x = r.por_tipo[k];
        return '<tr><td>' + esc(x.rotulo) + '</td><td>' + num(x.previsto) + '</td><td>' + num(x.cadastradas) + '</td><td>' + num(x.entregues) + '</td><td' + (x.pendentes ? ' class="ffop-pend"' : '') + '>' + num(x.pendentes) + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div class="ffcli-vazio">Sem entregas nem frequência neste mês.</div>';
      h += '<div class="ffcli-vazio" style="margin-top:8px">Conta cartões marcados como entrega (aba "Entrega" do cartão). Itens de checklist não entram.</div>';
      elMes.innerHTML = h;
      elMes.querySelector('[data-mes]').onchange = function () { if (this.value) { estado.mes = this.value; carregar(true); } };
    }
    function desenharLista() {
      elLista.innerHTML = '<div class="ffcli-vazio">Carregando…</div>';
      var id = estado.id;
      pedir('?entregas=1&cliente=' + id + '&mes=' + estado.mes).then(function (r) {
        if (estado.id !== id) return;
        if (!r.ok) { elLista.innerHTML = '<div class="ffcli-vazio">' + esc(r.error || 'Não foi possível carregar.') + '</div>'; return; }
        elLista.innerHTML = r.data.length ? '<table class="ffop-tab"><thead><tr><th>Entrega</th><th>Aprovação</th><th>Entrega</th><th>Prazo</th></tr></thead><tbody>' + r.data.map(function (e) {
          return '<tr><td><a href="/tarefas?amb=digital&board=' + e.board_id + '&tarefa=' + e.task_id + '">' + esc(e.titulo) + '</a><small>' + esc(e.tipo_rotulo) + ' · ' + esc(e.producao_rotulo) + '</small></td>' +
            '<td>' + esc(e.aprovacao_rotulo) + '</td><td>' + esc(e.entrega_rotulo) + (e.publicacao === 'publicado' ? '<small>Publicado</small>' : '') + '</td>' +
            '<td' + (e.atrasada ? ' class="ffop-pend"' : '') + '>' + esc(data(e.prazo) || '-') + (e.atrasada ? ' (atrasada)' : '') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="ffcli-vazio">Nenhuma entrega deste cliente no mês.</div>';
      });
    }

    function carregar(forcar) {
      var id = campoId.value ? +campoId.value : null;
      if (!forcar && id === estado.id && estado.dados) return;
      estado.id = id; estado.dados = null;
      if (!id) {
        [elOper, elQuadros, elFreq, elCamp, elMes, elLista].forEach(function (e) { e.innerHTML = '<div class="ffcli-vazio">Salve o cliente primeiro.</div>'; });
        if (elDrive) elDrive.innerHTML = '<div class="ffcli-vazio">Salve o cliente primeiro.</div>';
        return;
      }
      elOper.innerHTML = elQuadros.innerHTML = '<div class="ffcli-vazio">Carregando…</div>';
      Promise.all([pedir('?cliente=' + id + '&mes=' + estado.mes), apoio()]).then(function (res) {
        var r = res[0];
        if (estado.id !== id) return;
        if (!r.ok) { elOper.innerHTML = '<div class="ffcli-vazio">' + esc(r.error || 'Não foi possível carregar.') + '</div>'; return; }
        estado.dados = r.data;
        desenharResumo(); desenharQuadros(); desenharFrequencia(); desenharCampanhas(); desenharMes(); desenharLista();
      });
      if (elDrive && (forcar !== true)) window.FfDrive.painel(elDrive, id);
    }
    new MutationObserver(function () { if (modal.classList.contains('open')) carregar(); else estado.id = -1; })
      .observe(modal, { attributes: true, attributeFilter: ['class'] });
    if (modal.classList.contains('open')) carregar();
    setInterval(function () { if (modal.classList.contains('open') && (campoId.value ? +campoId.value : null) !== estado.id) carregar(); }, 1000);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { montar(0); });
  else montar(0);
})();
