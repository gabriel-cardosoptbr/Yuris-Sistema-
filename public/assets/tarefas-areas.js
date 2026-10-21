/* ═══════════════════════════════════════════════════════════════════════
   tarefas-areas.js: Tarefas nos ambientes da Inovaize (Software e Digital).
   Carregado por tarefas.php depois do tarefas.js, só na conta com ambientes.

   - window.TK_AREA ('software' | 'digital' | null): a tela mostra só os
     quadros daquela área (o filtro é do servidor, em task_boards.php);
   - quadro novo da área escolhe o cliente e o modelo de colunas;
   - o cartão de quadro de área ganha a aba "Entrega": tipo e os quatro
     estados (produção, aprovação, entrega, publicação), mês de competência,
     responsáveis além do principal e os materiais esperados;
   - no kanban, o cartão mostra o tipo, a situação e os materiais recebidos.
   Usa as globais do tarefas.js (currentBoard, openTaskId, loadTasks,
   renderKanban, refreshDrawer, switchTab, showToast) sem mudar o desenho dele.
   ═══════════════════════════════════════════════════════════════════════ */
window.TkAreas = (function () {
  'use strict';
  var AREA = window.TK_AREA || null;
  var API = '/api/digital.php';
  var CSRF = window.YURIS_CSRF;
  var apoio = null, clientes = null;
  var doQuadro = { entregas: {}, participantes: {} };
  var cartao = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function pedir(metodo, qs, corpo) {
    var op = { method: metodo, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (corpo) { op.headers['Content-Type'] = 'application/json'; op.headers['X-CSRF-Token'] = CSRF; op.body = JSON.stringify(corpo); }
    return fetch(API + (qs || ''), op).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão.' }; });
  }
  function aviso(msg, tipo) { if (typeof showToast === 'function') showToast(msg, { type: tipo || 'error' }); }
  function carregarApoio() { return apoio ? Promise.resolve(apoio) : pedir('GET', '?apoio=1').then(function (r) { apoio = r.ok ? r.data : null; return apoio; }); }
  function carregarClientes() { return clientes ? Promise.resolve(clientes) : pedir('GET', '?clientes=1').then(function (r) { clientes = r.ok ? r.data : []; return clientes; }); }
  function areaDoQuadro() { return (typeof currentBoard !== 'undefined' && currentBoard && currentBoard.area) || null; }

  // ── Cabeçalho da tela ────────────────────────────────────────────────
  if (AREA) {
    var h = document.querySelector('.page-header-title'), sub = document.querySelector('.page-header-subtitle');
    if (h) h.textContent = AREA === 'digital' ? 'Produção' : 'Demandas e suporte';
    document.title = h ? h.textContent : document.title;
    if (sub) sub.textContent = AREA === 'digital'
      ? 'Quadros de produção por cliente: projetos, campanhas ou episódios, com entrega, aprovação e publicação.'
      : 'Quadros de desenvolvimento e suporte por cliente ou projeto, com responsáveis e prazos.';
  }

  // ── Quadro novo: cliente e modelo ────────────────────────────────────
  var modal = document.getElementById('modalNewBoard');
  if (AREA && modal) {
    var bloco = document.createElement('div');
    bloco.className = 'tk-row-2 tka-novo';
    bloco.style.marginBottom = '12px';
    bloco.innerHTML = '<div class="tk-field"><label>Cliente' + (AREA === 'digital' ? '' : ' (opcional)') + '</label><select id="nbCliente"><option value="">Carregando…</option></select></div>' +
      '<div class="tk-field"><label>Organização das colunas</label><select id="nbModelo"></select></div>';
    var acoes = modal.querySelector('.tk-modal-actions');
    acoes.parentNode.insertBefore(bloco, acoes);
    var nbNome = document.getElementById('nbNome');
    if (nbNome) nbNome.placeholder = AREA === 'digital' ? 'Ex.: Conteúdo de novembro, Podcast temporada 2' : 'Ex.: App do cliente, Suporte';
    var tipoSel = document.getElementById('nbTipo');
    if (tipoSel) tipoSel.value = 'compartilhado';
    Promise.all([carregarApoio(), carregarClientes()]).then(function () {
      document.getElementById('nbCliente').innerHTML = '<option value="">' + (AREA === 'digital' ? 'Escolha o cliente' : 'Sem cliente (projeto interno)') + '</option>' +
        (clientes || []).map(function (c) { return '<option value="' + c.id + '">' + esc(c.nome) + '</option>'; }).join('');
      var padrao = AREA === 'digital' ? 'etapas' : 'demandas';
      document.getElementById('nbModelo').innerHTML = Object.keys((apoio && apoio.modelos) || {}).map(function (k) {
        return '<option value="' + k + '"' + (k === padrao ? ' selected' : '') + '>' + esc(apoio.modelos[k]) + '</option>';
      }).join('');
    });
  }
  // ?novo=1&cliente=N (link da ficha do cliente): abre o quadro novo já com o cliente.
  (function () {
    var p = new URLSearchParams(location.search);
    if (!AREA || !modal || p.get('novo') !== '1') return;
    var cli = p.get('cliente');
    setTimeout(function () {
      modal.classList.add('open');
      carregarClientes().then(function () { var s = document.getElementById('nbCliente'); if (s && cli) s.value = cli; });
    }, 600);
  })();
  function camposNovoQuadro() {
    if (!AREA) return {};
    var cli = document.getElementById('nbCliente'), mod = document.getElementById('nbModelo');
    if (AREA === 'digital' && cli && !cli.value) { aviso('Escolha o cliente do quadro: a produção do Digital é organizada por cliente.', 'warn'); return null; }
    return { area: AREA, cliente_id: cli && cli.value ? +cli.value : null, modelo: mod ? mod.value : null };
  }

  function semQuadros() {
    if (!AREA) return;
    var k = document.getElementById('tkKanban');
    if (!k) return;
    k.innerHTML = '<div class="tka-vazio"><b>Nenhum quadro ' + (AREA === 'digital' ? 'de produção' : 'de Software') + ' ainda.</b>' +
      '<span>' + (AREA === 'digital' ? 'Crie um quadro por cliente, projeto, campanha ou temporada.' : 'Crie um quadro por projeto, cliente ou para o suporte.') + '</span>' +
      '<button type="button" class="tk-btn-new-task" id="tkaNovoQuadro">Criar quadro</button></div>';
    k.style.display = 'flex';
    document.getElementById('tkaNovoQuadro').onclick = function () { modal.classList.add('open'); };
  }

  // ── Kanban: dados do quadro e etiquetas no cartão ────────────────────
  var SIT = { producao: ['Em produção', 'tka-azul'], aprovacao: ['Aguardando aprovação', 'tka-ambar'], concluida: ['Entregue', 'tka-verde'] };
  function decorar() {
    if (!areaDoQuadro()) return;
    document.querySelectorAll('#tkKanban .tk-card[data-task-id]').forEach(function (el) {
      var id = el.dataset.taskId, e = doQuadro.entregas[id], p = doQuadro.participantes[id] || [];
      var velho = el.querySelector('.tka-card'); if (velho) velho.remove();
      if (!e && !p.length) return;
      var html = '';
      if (e) {
        var s = SIT[e.situacao] || SIT.producao;
        html += '<span class="tka-et">' + esc(e.tipo_rotulo) + '</span><span class="tka-et ' + s[1] + '">' + s[0] + '</span>';
        if (e.publicacao === 'publicado' || e.publicacao === 'agendado') html += '<span class="tka-et tka-verde">' + esc(e.publicacao_rotulo) + '</span>';
        if (e.materiais) html += '<span class="tka-et' + (e.recebidos < e.materiais ? ' tka-ambar' : '') + '" title="Materiais recebidos">Material ' + e.recebidos + '/' + e.materiais + '</span>';
      }
      if (p.length) html += '<span class="tka-et" title="' + esc(p.map(function (x) { return x.nome; }).join(', ')) + '">+' + p.length + ' resp.</span>';
      var d = document.createElement('div');
      d.className = 'tka-card';
      d.innerHTML = html;
      var meta = el.querySelector('.tk-card-meta');
      if (meta) meta.parentNode.insertBefore(d, meta.nextSibling); else el.appendChild(d);
    });
  }
  function carregarQuadro() {
    var b = typeof currentBoard !== 'undefined' ? currentBoard : null;
    if (!b || !b.area) { doQuadro = { entregas: {}, participantes: {} }; return Promise.resolve(); }
    var id = b.id;
    return pedir('GET', '?quadro=' + id).then(function (r) {
      if (!currentBoard || currentBoard.id !== id) return;
      doQuadro = r.ok ? r.data : { entregas: {}, participantes: {} };
      decorar();
    });
  }
  if (typeof loadTasks === 'function') {
    var _lt = loadTasks;
    loadTasks = async function () { await _lt.apply(this, arguments); await carregarQuadro(); };
  }
  if (typeof renderKanban === 'function') {
    var _rk = renderKanban;
    renderKanban = function () {
      _rk.apply(this, arguments);
      if (AREA && typeof boards !== 'undefined' && !boards.length) semQuadros(); else decorar();
    };
  }

  // ── Aba "Entrega" no painel do cartão ────────────────────────────────
  var tabs = document.getElementById('tkTabs'), corpo = document.getElementById('dBody');
  var aba = null, pane = null;
  if (tabs && corpo) {
    aba = document.createElement('button');
    aba.className = 'tk-tab'; aba.dataset.tab = 'entrega'; aba.textContent = 'Entrega'; aba.style.display = 'none';
    var ck = tabs.querySelector('[data-tab="checklist"]');
    tabs.insertBefore(aba, ck || null);
    aba.addEventListener('click', function () { switchTab('entrega'); });
    pane = document.createElement('div');
    pane.className = 'tk-tab-pane'; pane.id = 'pane-entrega';
    pane.innerHTML = '<div class="tka-pane" data-amb-guarda></div>';
    corpo.appendChild(pane);
  }

  function opcoes(mapa, atual) {
    return Object.keys(mapa).map(function (k) { return '<option value="' + k + '"' + (k === atual ? ' selected' : '') + '>' + esc(mapa[k]) + '</option>'; }).join('');
  }
  function desenharCartao() {
    if (!pane || !cartao) return;
    var c = cartao, e = c.entrega, ed = c.pode_editar, ap = apoio || { tipos: {}, estados: {}, equipe: [] };
    var dis = ed ? '' : ' disabled';
    var digital = c.quadro.area === 'digital';
    var h = '';
    h += '<section class="tka-sec"><h4>Entrega</h4>' +
      (digital || e ? '' : '<p class="tka-nota">Marque o tipo se este cartão é uma entrega para o cliente. Tarefa interna fica sem tipo.</p>') +
      '<div class="tka-grade"><div><label>Tipo</label><select data-e="tipo"' + dis + '><option value="">Não é entrega</option>' + opcoes(ap.tipos, e ? e.tipo : '') + '</select></div>' +
      '<div><label>Mês de competência</label><input type="month" data-e="competencia" value="' + esc(e ? e.competencia : '') + '"' + dis + '></div></div>';
    if (e) {
      h += '<div class="tka-grade tka-estados">' + ['producao', 'aprovacao', 'entrega', 'publicacao'].map(function (k) {
        var rot = { producao: 'Produção', aprovacao: 'Aprovação do cliente', entrega: 'Entrega', publicacao: 'Publicação' }[k];
        var data = { producao: e.producao_em, aprovacao: e.aprovacao_em, entrega: e.entregue_em, publicacao: e.publicado_em }[k];
        return '<div><label>' + rot + '</label><select data-e="' + k + '"' + dis + '>' + opcoes(ap.estados[k] || {}, e[k]) + '</select>' +
          (data ? '<small class="tka-data">desde ' + esc(String(data).slice(8, 10) + '/' + String(data).slice(5, 7) + ' ' + String(data).slice(11, 16)) + '</small>' : '') + '</div>';
      }).join('') + '</div>' +
      '<p class="tka-nota">Receber o material não aprova nem entrega: cada estado muda só aqui. As mudanças ficam no Histórico do cartão.</p>' +
      // O link do post publicado: a ficha do cliente (aba Postagem) abre o post por ele.
      '<div class="tka-add"><input type="url" maxlength="500" data-link-post placeholder="Link do post publicado (Instagram, TikTok...)" value="' + esc(e.link_publicacao || '') + '"' + dis + '>' +
      (e.link_publicacao ? '<a class="tka-btn" href="' + esc(e.link_publicacao) + '" target="_blank" rel="noopener noreferrer">Abrir post</a>' : '') +
      (ed ? '<button type="button" class="tka-btn" data-salvar-link>Salvar link</button>' : '') + '</div>';
    }
    if (ed) h += '<div class="tka-acoes"><button type="button" class="tka-btn tka-principal" data-salvar-entrega>Salvar entrega</button></div>';
    h += '</section>';

    var ids = (c.participantes || []).map(function (p) { return p.id; });
    h += '<section class="tka-sec"><h4>Responsáveis além do principal</h4>' +
      '<div class="tka-pessoas">' + (ap.equipe || []).map(function (u) {
        return '<label class="tka-pessoa"><input type="checkbox" value="' + u.id + '"' + (ids.indexOf(u.id) >= 0 ? ' checked' : '') + dis + '> ' + esc(u.nome) + '</label>';
      }).join('') + '</div>' +
      (ed ? '<div class="tka-acoes"><button type="button" class="tka-btn" data-salvar-pessoas>Salvar responsáveis</button></div>' : '') + '</section>';

    var mats = c.materiais || [], rec = mats.filter(function (m) { return m.recebido; }).length;
    h += '<section class="tka-sec"><h4>Materiais esperados <span class="tka-cont">' + rec + '/' + mats.length + ' recebidos</span></h4>' +
      (mats.length ? '<ul class="tka-mats">' + mats.map(function (m) {
        return '<li><span class="tka-mat-nome"><b>' + esc(m.nome) + '</b>' + (m.recebido
          ? '<small class="tka-ok">Recebido ' + esc(String(m.recebido_em).slice(8, 10) + '/' + String(m.recebido_em).slice(5, 7)) + (m.recebido_por ? ' por ' + esc(m.recebido_por) : '') + ': ' +
            (m.arquivo_link ? '<a href="' + esc(m.arquivo_link) + '" target="_blank" rel="noopener noreferrer">' + esc(m.arquivo_nome) + '</a>' : esc(m.arquivo_nome)) + '</small>'
          : '<small class="tka-falta">Falta receber</small>') + '</span>' +
          (ed ? '<span class="tka-mat-acoes">' + (m.recebido ? '<button type="button" class="tka-btn tka-sm" data-desfazer="' + m.id + '">Desfazer</button>'
            : '<button type="button" class="tka-btn tka-sm" data-receber="' + m.id + '">Registrar recebimento</button>') +
            '<button type="button" class="tka-btn tka-sm tka-perigo" data-remover="' + m.id + '" aria-label="Remover ' + esc(m.nome) + '">Remover</button></span>' : '') + '</li>';
      }).join('') + '</ul>' : '<p class="tka-nota">Nenhum material esperado. Cadastre o que a entrega precisa (logo, fotos, roteiro) para ver o que falta.</p>') +
      (ed ? '<div class="tka-add"><input type="text" maxlength="160" data-novo-mat placeholder="Ex.: Fotos do produto em alta"><button type="button" class="tka-btn" data-add-mat>Adicionar</button></div>' : '') +
      '<div data-receber-form></div></section>';
    if (c.quadro.cliente_id) {
      var arqs = c.drive || [];
      h += '<section class="tka-sec"><h4>Arquivos do Drive</h4>' +
        (arqs.length ? '<ul class="tka-mats">' + arqs.map(function (a) {
          return '<li><span class="tka-mat-nome"><b>' + (a.link ? '<a href="' + esc(a.link) + '" target="_blank" rel="noopener noreferrer">' + esc(a.nome) + '</a>' : esc(a.nome)) + '</b><small>' + esc(a.tipo) + (a.vinculado_por ? ' · vinculado por ' + esc(a.vinculado_por) : '') + '</small></span>' +
            (ed ? '<span class="tka-mat-acoes"><button type="button" class="tka-btn tka-sm tka-perigo" data-desv="' + a.id + '">Desvincular</button></span>' : '') + '</li>';
        }).join('') + '</ul>' : '<p class="tka-nota">Nenhum arquivo da pasta do cliente vinculado a este cartão.</p>') +
        (ed && window.FfDrive ? '<div class="tka-acoes"><button type="button" class="tka-btn" data-vinc-drive>Vincular arquivo do Drive</button></div><div data-drive-box></div>' : '') + '</section>';
    }
    pane.firstChild.innerHTML = h;
    ligar();
  }

  function ligar() {
    var raiz = pane.firstChild, c = cartao;
    var bE = raiz.querySelector('[data-salvar-entrega]');
    if (bE) bE.onclick = function () {
      var corpo = { acao: 'entrega_salvar', task_id: c.task_id };
      raiz.querySelectorAll('[data-e]').forEach(function (el) { corpo[el.getAttribute('data-e')] = el.value; });
      bE.disabled = true;
      pedir('POST', '', corpo).then(function (r) {
        bE.disabled = false;
        if (!r.ok) return aviso(r.error || 'Não foi possível salvar.');
        raiz.removeAttribute('data-amb-sujo');
        cartao.entrega = r.data; doQuadro.entregas[c.task_id] = r.data; desenharCartao(); decorar();
        aviso('Entrega salva.', 'success');
      });
    };
    var bL = raiz.querySelector('[data-salvar-link]');
    if (bL) bL.onclick = function () {
      bL.disabled = true;
      pedir('POST', '', { acao: 'entrega_link', task_id: c.task_id, link: raiz.querySelector('[data-link-post]').value }).then(function (r) {
        bL.disabled = false;
        if (!r.ok) return aviso(r.error || 'Não foi possível salvar.');
        raiz.removeAttribute('data-amb-sujo');
        cartao.entrega.link_publicacao = r.link; desenharCartao();
        aviso('Link do post salvo.', 'success');
      });
    };
    var bP = raiz.querySelector('[data-salvar-pessoas]');
    if (bP) bP.onclick = function () {
      var ids = Array.prototype.map.call(raiz.querySelectorAll('.tka-pessoa input:checked'), function (i) { return +i.value; });
      bP.disabled = true;
      pedir('POST', '', { acao: 'participantes', task_id: c.task_id, user_ids: ids }).then(function (r) {
        bP.disabled = false;
        if (!r.ok) return aviso(r.error || 'Não foi possível salvar.');
        raiz.removeAttribute('data-amb-sujo');
        cartao.participantes = r.data; doQuadro.participantes[c.task_id] = r.data; desenharCartao(); decorar();
        aviso('Responsáveis salvos.', 'success');
      });
    };
    function materiais(corpo) {
      corpo.task_id = c.task_id;
      return pedir('POST', '', corpo).then(function (r) {
        if (!r.ok) { aviso(r.error || 'Não foi possível salvar.'); return; }
        cartao.materiais = r.data; desenharCartao(); carregarQuadro();
      });
    }
    var bA = raiz.querySelector('[data-add-mat]');
    if (bA) bA.onclick = function () {
      var inp = raiz.querySelector('[data-novo-mat]');
      if (!inp.value.trim()) return inp.focus();
      materiais({ acao: 'material_adicionar', nome: inp.value });
    };
    raiz.querySelectorAll('[data-desfazer]').forEach(function (b) { b.onclick = function () { materiais({ acao: 'material_desfazer', id: +b.dataset.desfazer }); }; });
    raiz.querySelectorAll('[data-remover]').forEach(function (b) { b.onclick = function () { materiais({ acao: 'material_remover', id: +b.dataset.remover }); }; });
    function drive(corpo) {
      corpo.task_id = c.task_id;
      return fetch('/api/drive.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, Accept: 'application/json' }, body: JSON.stringify(corpo) })
        .then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão.' }; })
        .then(function (r) { if (!r.ok) return aviso(r.error || 'Não foi possível salvar.'); cartao.drive = r.data; desenharCartao(); });
    }
    raiz.querySelectorAll('[data-desv]').forEach(function (b) { b.onclick = function () { drive({ acao: 'cartao_remover', id: +b.dataset.desv }); }; });
    var bv = raiz.querySelector('[data-vinc-drive]');
    if (bv) bv.onclick = function () {
      window.FfDrive.escolher(raiz.querySelector('[data-drive-box]'), c.quadro.cliente_id, function (arq) { drive({ acao: 'cartao_vincular', file_id: arq.id }); });
    };
    raiz.querySelectorAll('[data-receber]').forEach(function (b) {
      b.onclick = function () {
        var box = raiz.querySelector('[data-receber-form]'), id = +b.dataset.receber;
        box.innerHTML = '<div class="tka-receber"><label>Arquivo recebido</label>' +
          '<div data-drive-escolha></div>' +
          (window.FfDrive && c.quadro.cliente_id ? '<label>Ou envie o arquivo (vai para a pasta do cliente no Drive)</label><input type="file" data-rec-arquivo>' : '') +
          '<div class="tka-grade"><div><label>Nome do arquivo</label><input type="text" data-rec-nome maxlength="255"></div>' +
          '<div><label>Link (opcional)</label><input type="url" data-rec-link placeholder="https://drive.google.com/..."></div></div>' +
          '<div class="tka-acoes"><button type="button" class="tka-btn" data-rec-cancelar>Cancelar</button><button type="button" class="tka-btn tka-principal" data-rec-ok>Registrar</button></div></div>';
        var escolhido = { id: null };
        // Com o Drive do cliente ligado, os arquivos da pasta aparecem para escolher (ff-drive.js).
        if (window.FfDrive && c.quadro.cliente_id) {
          window.FfDrive.escolher(box.querySelector('[data-drive-escolha]'), c.quadro.cliente_id, function (arq) {
            escolhido = arq; box.querySelector('[data-rec-nome]').value = arq.nome; box.querySelector('[data-rec-link]').value = arq.link || '';
          });
        }
        box.querySelector('[data-rec-cancelar]').onclick = function () { box.innerHTML = ''; };
        box.querySelector('[data-rec-ok]').onclick = function () {
          var up = box.querySelector('[data-rec-arquivo]');
          if (up && up.files[0]) {
            // Envio: o arquivo vai para o Drive do cliente e o servidor registra o recebimento.
            var fd = new FormData();
            fd.append('acao', 'enviar'); fd.append('cliente_id', c.quadro.cliente_id); fd.append('task_id', c.task_id); fd.append('material_id', id); fd.append('arquivo', up.files[0]);
            var ok = box.querySelector('[data-rec-ok]'); ok.disabled = true; ok.textContent = 'Enviando…';
            fetch('/api/drive.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF, Accept: 'application/json' }, body: fd })
              .then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão.' }; })
              .then(function (r) {
                if (!r.ok) { ok.disabled = false; ok.textContent = 'Registrar'; return aviso(r.error || 'Não foi possível enviar.'); }
                pedir('GET', '?cartao=' + c.task_id).then(function (x) { if (x.ok) { cartao.materiais = x.data.materiais; desenharCartao(); carregarQuadro(); } });
              });
            return;
          }
          var nome = box.querySelector('[data-rec-nome]').value.trim();
          if (!nome) return box.querySelector('[data-rec-nome]').focus();
          materiais({ acao: 'material_receber', id: id, arquivo: { id: escolhido.id, nome: nome, link: box.querySelector('[data-rec-link]').value.trim() } });
        };
      };
    });
  }

  function carregarCartao() {
    if (!pane || typeof openTaskId === 'undefined' || !openTaskId) return;
    var id = openTaskId;
    aba.style.display = 'none';
    pedir('GET', '?cartao=' + id).then(function (r) {
      if (openTaskId !== id || !r.ok) return;
      cartao = r.data; cartao.task_id = id;
      if (!cartao.quadro.area && !cartao.entrega) { aba.style.display = 'none'; return; }
      aba.style.display = '';
      var drive = cartao.quadro.cliente_id
        ? fetch('/api/drive.php?cartao=' + id, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (x) { return x.json(); }).catch(function () { return { ok: false }; })
        : Promise.resolve({ ok: true, data: [] });
      Promise.all([carregarApoio(), drive]).then(function (res) { cartao.drive = res[1].ok ? res[1].data : []; desenharCartao(); });
    });
  }
  if (typeof refreshDrawer === 'function') {
    var _rd = refreshDrawer;
    refreshDrawer = async function () { await _rd.apply(this, arguments); carregarCartao(); };
  }

  return { camposNovoQuadro: camposNovoQuadro, semQuadros: semQuadros, decorar: decorar };
})();
