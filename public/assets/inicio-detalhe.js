/* ═══════════════════════════════════════════════════════════════════════════
   inicio-detalhe.js: o pop-up de detalhe da home nova (prévia, 07/11/2026).

   "Eu estou clicando aqui e sai da página; queria que abrisse o pop-up direto
   aqui na aba principal." Clicar num cartão da home (compromisso, prazo,
   publicação, processo, conversa, pessoa) abre esta janela, sem sair da tela.
   Ctrl/Cmd/Shift+clique e o botão do meio continuam abrindo a página (o cartão
   é um link de verdade).

   Os dados vêm do próprio cartão (data-det) e, para o processo e a tarefa, dos
   endpoints que as telas já usam, com a mesma checagem de conta:
     GET   /api/processes.php?id=           o processo
     GET   /api/processo_prazos.php?processo_id=   os prazos dele
     GET   /api/tasks.php?id=               a tarefa do quadro
     PATCH /api/processo_prazos.php         concluir um prazo (CSRF no cabeçalho)
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var CFG = window.INI_DET_CFG || {};
  var MESES = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  var DIAS = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
  var ICONES = {
    evento: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M3 10h18M8 2v4M16 2v4"/></svg>',
    prazo: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    pub: '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>',
    proc: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    wa: '<svg viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
    pessoa: '<svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'
  };
  var ORIGEM = { prazo: 'Prazo do processo', tarefa_processo: 'Tarefa do processo', tarefa: 'Tarefa do quadro' };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function dataLonga(k) { if (!k) return ''; var d = new Date(k.slice(0, 10) + 'T12:00:00'); return (k.slice(0, 10) === CFG.hoje ? 'Hoje, ' : DIAS[d.getDay()] + ', ') + d.getDate() + ' de ' + MESES[d.getMonth()] + ' de ' + d.getFullYear(); }
  function dataCurta(k) { if (!k) return ''; var s = String(k).slice(0, 10); return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4); }
  function campo(rot, val, cls) { if (val == null || val === '') return ''; return '<div class="idt-campo' + (cls ? ' ' + cls : '') + '"><small>' + esc(rot) + '</small><b>' + val + '</b></div>'; }
  function num(v) { return v ? '<em class="ini-num">' + esc(v) + '</em>' : ''; }
  function botao(texto, attrs, primario) { return '<a class="idt-btn' + (primario ? ' primario' : '') + '" ' + attrs + '>' + esc(texto) + '</a>'; }
  function carregando() { return '<div class="idt-carregando"><i></i><span>Carregando…</span></div>'; }

  // ── A janela ────────────────────────────────────────────────────────────
  var fundo, janela, elIc, elTit, elSub, elCorpo, elAcoes, origemFoco = null;
  function montar() {
    if (fundo) return;
    fundo = document.createElement('div');
    fundo.className = 'idt-fundo';
    fundo.hidden = true;
    fundo.innerHTML =
      '<div class="idt-janela" role="dialog" aria-modal="true" aria-labelledby="idtTitulo">'
      + '<header class="idt-cab"><span class="ini-ic idt-ic"></span><div class="idt-cab-t"><h3 id="idtTitulo"></h3><span class="idt-sub"></span></div>'
      + '<button type="button" class="idt-fechar" aria-label="Fechar">×</button></header>'
      + '<div class="idt-corpo"></div><footer class="idt-acoes"></footer></div>';
    document.body.appendChild(fundo);
    janela = fundo.querySelector('.idt-janela');
    elIc = fundo.querySelector('.idt-ic'); elTit = fundo.querySelector('#idtTitulo'); elSub = fundo.querySelector('.idt-sub');
    elCorpo = fundo.querySelector('.idt-corpo'); elAcoes = fundo.querySelector('.idt-acoes');
    fundo.addEventListener('mousedown', function (e) { if (e.target === fundo) fechar(); });
    fundo.querySelector('.idt-fechar').addEventListener('click', fechar);
    document.addEventListener('keydown', function (e) { if (!fundo.hidden && e.key === 'Escape') fechar(); });
  }
  function abrirJanela(icone, titulo, sub) {
    montar();
    elIc.innerHTML = ICONES[icone] || ICONES.evento;
    elTit.textContent = titulo; elSub.innerHTML = sub || '';
    elCorpo.innerHTML = ''; elAcoes.innerHTML = '';
    fundo.hidden = false;
    document.documentElement.classList.add('idt-aberto');
    requestAnimationFrame(function () { fundo.classList.add('visivel'); fundo.querySelector('.idt-fechar').focus(); });
  }
  function fechar() {
    if (!fundo || fundo.hidden) return;
    fundo.classList.remove('visivel');
    document.documentElement.classList.remove('idt-aberto');
    setTimeout(function () { fundo.hidden = true; }, 160);
    if (origemFoco) { try { origemFoco.focus(); } catch (e) {} }
  }

  function getJson(url) { return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); }); }
  function avisar(msg, tipo) { if (window.Yuris && window.Yuris.toast) window.Yuris.toast(msg, tipo || 'success'); }

  // ── O processo: dados e prazos em aberto, com "Concluir" ─────────────────
  function blocoProcesso(processoId, destino, prazoEmFoco) {
    destino.innerHTML = '<div class="idt-sec"><div class="idt-sec-t">Processo</div>' + carregando() + '</div>';
    Promise.all([
      getJson('/api/processes.php?id=' + encodeURIComponent(processoId)),
      getJson('/api/processo_prazos.php?processo_id=' + encodeURIComponent(processoId)).catch(function () { return { data: [] }; })
    ]).then(function (r) {
      var p = r[0] || {}, prazos = (r[1] && r[1].data) || [];
      var abertos = prazos.filter(function (z) { return z.status !== 'concluido'; });
      var html = '<div class="idt-sec"><div class="idt-sec-t">Processo</div><div class="idt-grade">'
        + campo('Número', num(p.numero_cnj || p.numero))
        + campo('Cliente', esc(p.cliente_nome))
        + campo('Parte contrária', esc(p.parte_contraria))
        + campo('Ação', esc(p.tipo_acao))
        + campo('Vara / comarca', esc(p.vara_comarca), 'largo')
        + campo('Situação', esc(p.status ? p.status.charAt(0).toUpperCase() + p.status.slice(1) : ''))
        + campo('Próximo prazo', p.proximo_prazo ? esc(dataCurta(p.proximo_prazo)) : '')
        + campo('Setor', esc(p.setor_nome))
        + '</div>';
      if (p.observacoes) html += '<div class="idt-texto curto">' + esc(p.observacoes) + '</div>';
      html += '</div>';
      html += '<div class="idt-sec"><div class="idt-sec-t">Prazos em aberto <span class="idt-pill">' + abertos.length + '</span></div>';
      if (!abertos.length) html += '<div class="idt-vazio">Nenhum prazo em aberto neste processo.</div>';
      else html += '<div class="idt-prazos">' + abertos.map(function (z) {
        var foco = prazoEmFoco && +z.id === +prazoEmFoco;
        var venc = z.data_limite && z.data_limite.slice(0, 10) < CFG.hoje;
        return '<div class="idt-prazo' + (foco ? ' foco' : '') + '" data-prazo="' + (+z.id) + '">'
          + '<span class="idt-prazo-data' + (venc ? ' venc' : '') + '">' + esc(dataCurta(z.data_limite)) + '</span>'
          + '<span class="idt-prazo-t"><b>' + esc(z.descricao) + '</b>' + (z.responsavel ? '<small>' + esc(z.responsavel) + '</small>' : '') + '</span>'
          + '<button type="button" class="idt-mini" data-concluir="' + (+z.id) + '">Concluir</button></div>';
      }).join('') + '</div>';
      html += '</div>';
      destino.innerHTML = html;
      if (p.link_tribunal && /^https?:\/\//i.test(p.link_tribunal)) {
        var a = document.createElement('a'); a.className = 'idt-btn'; a.href = p.link_tribunal; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Abrir no tribunal';
        elAcoes.insertBefore(a, elAcoes.firstChild);
      }
    }).catch(function () {
      destino.innerHTML = '<div class="idt-sec"><div class="idt-vazio erro">Não consegui carregar o processo agora. Use "Abrir o processo".</div></div>';
    });
  }

  // Concluir um prazo, na própria janela.
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-concluir]');
    if (!b || !fundo || !fundo.contains(b)) return;
    var id = +b.getAttribute('data-concluir');
    b.disabled = true; b.textContent = 'Concluindo…';
    fetch('/api/processo_prazos.php', {
      method: 'PATCH', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf || '' },
      body: JSON.stringify({ id: id, status: 'concluido' })
    }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json().catch(function () { return {}; }); })
      .then(function () {
        var linha = b.closest('.idt-prazo'); if (linha) { linha.classList.add('feito'); b.textContent = 'Concluído'; }
        // O cartão do prazo na home sai da lista: já não está em aberto.
        document.querySelectorAll('.ini-cartao[data-det]').forEach(function (c) {
          try { var d = JSON.parse(c.getAttribute('data-det')); if (d.t === 'evento' && d.origem === 'prazo' && +d.id === id) c.classList.add('ini-feito'); } catch (x) {}
        });
        avisar('Prazo concluído.');
      })
      .catch(function () { b.disabled = false; b.textContent = 'Concluir'; avisar('Não consegui concluir o prazo. Tente de novo.', 'danger'); });
  });

  // ── Cada tipo de cartão ─────────────────────────────────────────────────
  var montadores = {
    evento: function (d) {
      var tipoRot = (CFG.tipos && CFG.tipos[d.tipo]) || 'Compromisso';
      var quando = d.data ? dataLonga(d.data) + (d.hora ? ' · ' + d.hora : '') : 'Sem data';
      abrirJanela(d.origem === 'prazo' ? 'prazo' : 'evento', d.titulo, '<i class="ini-tag ' + esc(d.tipo) + '">' + esc(tipoRot) + '</i> ' + esc(quando));
      var html = '<div class="idt-sec"><div class="idt-grade">'
        + campo('Quando', esc(quando), 'largo')
        + campo('Responsável', esc(d.resp))
        + campo('Origem', esc(ORIGEM[d.origem] || ''))
        // Com processo, número e cliente vêm na seção Processo, logo abaixo: sem repetir.
        + (d.processo_id ? '' : campo('Processo', num(d.num)) + campo('Cliente', esc(d.cliente)))
        + '</div></div><div class="idt-extra"></div>';
      elCorpo.innerHTML = html;
      var extra = elCorpo.querySelector('.idt-extra');
      if (d.processo_id) {
        blocoProcesso(d.processo_id, extra, d.origem === 'prazo' ? d.id : null);
        elAcoes.innerHTML = botao('Abrir o processo', 'href="/processos?open=' + (+d.processo_id) + '"', true);
      } else if (d.origem === 'tarefa' && d.id) {
        extra.innerHTML = '<div class="idt-sec">' + carregando() + '</div>';
        getJson('/api/tasks.php?id=' + encodeURIComponent(d.id)).then(function (r) {
          var t = (r && r.data) || {};
          var check = +t.checklist_total ? (+t.checklist_feito + ' de ' + (+t.checklist_total)) : '';
          extra.innerHTML = '<div class="idt-sec"><div class="idt-sec-t">Tarefa</div><div class="idt-grade">'
            + campo('Quadro', esc(t.board_nome)) + campo('Coluna', esc(t.coluna_nome))
            + campo('Prioridade', esc(t.prioridade ? t.prioridade.charAt(0).toUpperCase() + t.prioridade.slice(1) : ''))
            + campo('Checklist', esc(check)) + campo('Criada por', esc(t.criador_nome))
            + '</div>' + (t.descricao ? '<div class="idt-texto">' + esc(t.descricao) + '</div>' : '') + '</div>';
        }).catch(function () { extra.innerHTML = ''; });
        elAcoes.innerHTML = botao('Abrir em Tarefas', 'href="/tarefas"', true);
      } else {
        elAcoes.innerHTML = botao('Abrir em Tarefas', 'href="/tarefas"', true);
      }
    },
    proc: function (d) {
      abrirJanela('proc', d.cliente || 'Processo', num(d.num));
      elCorpo.innerHTML = '<div class="idt-sec"><div class="idt-grade">'
        + campo('Última movimentação', esc(d.mov), 'largo')
        + campo('Quando', esc(dataCurta(d.quando)))
        + campo('Situação', '<span class="ini-situ ' + esc(d.tom) + '">' + esc(d.situacao) + '</span>')
        + '</div></div><div class="idt-extra"></div>';
      blocoProcesso(d.processo_id, elCorpo.querySelector('.idt-extra'), null);
      elAcoes.innerHTML = botao('Abrir o processo', 'href="/processos?open=' + (+d.processo_id) + '"', true);
    },
    pub: function (d) {
      abrirJanela('pub', d.tipo || 'Publicação', esc(d.tribunal) + ' · ' + esc(dataLonga(d.data)));
      elCorpo.innerHTML = '<div class="idt-sec"><div class="idt-grade">'
        + campo('Tribunal', esc(d.tribunal)) + campo('Disponibilizada', esc(dataCurta(d.data)))
        + campo('Processo', num(d.num)) + campo('Cliente', esc(d.cliente))
        + campo('Órgão', esc(d.orgao), 'largo')
        + '</div></div>'
        + '<div class="idt-sec"><div class="idt-sec-t">Texto da publicação</div>'
        + (d.trecho ? '<div class="idt-texto">' + esc(d.trecho) + '</div>' : '<div class="idt-vazio">O texto completo está no Diário.</div>') + '</div>';
      elAcoes.innerHTML = (d.processo_id ? botao('Abrir o processo', 'href="/processos?open=' + (+d.processo_id) + '"', false) : '')
        + botao('Tratar no Diário', 'href="/intimacoes"', true);
    },
    wa: function (d) {
      abrirJanela('wa', d.nome, 'Esperando resposta ' + esc(d.desde));
      elCorpo.innerHTML = '<div class="idt-sec"><div class="idt-sec-t">Última mensagem</div>'
        + '<div class="idt-balao"><p>' + esc(d.texto) + '</p><small>' + esc(d.desde) + '</small></div></div>';
      elAcoes.innerHTML = botao('Responder no chat', 'href="/chat?jid=' + encodeURIComponent(d.jid) + '"', true);
    },
    pessoa: function (d) {
      var est = { livre: 'Livre hoje', ocupado: 'Com compromisso hoje', atrasado: 'Com prazo atrasado' }[d.estado] || '';
      abrirJanela('pessoa', d.nome, '<span class="ini-papel">' + esc(d.papel) + '</span>' + (est ? ' · ' + esc(est) : ''));
      elCorpo.innerHTML = '<div class="idt-sec"><div class="idt-numeros">'
        + '<div><b>' + (+d.hoje) + '</b><span>hoje</span></div>'
        + '<div><b>' + (+d.semana) + '</b><span>na semana</span></div>'
        + '<div class="' + (+d.vencidas ? 'venc' : '') + '"><b>' + (+d.vencidas) + '</b><span>vencidos</span></div>'
        + '<div><b>' + (+d.abertas) + '</b><span>tarefas abertas</span></div>'
        + '</div></div>';
      elAcoes.innerHTML = botao('Ver o desempenho', 'href="/desempenho"', true);
    }
  };

  // ── O clique no cartão ──────────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var card = e.target.closest && e.target.closest('.ini-cartao[data-det]');
    if (!card) return;
    var d; try { d = JSON.parse(card.getAttribute('data-det')); } catch (x) { return; }
    var m = montadores[d.t]; if (!m) return;
    e.preventDefault();
    origemFoco = card;
    m(d);
  });
})();
