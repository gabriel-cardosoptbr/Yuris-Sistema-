/* ═══════════════════════════════════════════════════════════════════════════
   inicio-detalhe.js: o pop-up da home nova (prévia, 07/11/2026).

   "Eu clico aqui e sai da página; queria que abrisse o pop-up direto aqui."
   E, em seguida: "não pode ter só o preview; eu clico no botão e tenho que
   conseguir concluir, responder, mandar mensagem, tudo prático."

   Cada cartão da home abre esta janela com o detalhe e a AÇÃO dele, sem sair
   da tela:
     compromisso / prazo   Concluir (prazo, tarefa do processo, tarefa do quadro)
     processo              os prazos em aberto, cada um com Concluir
     publicação            o texto formatado como no Diário; Tratada / Sem providência
     conversa              as últimas mensagens e a resposta, enviada daqui
     pessoa                os números de hoje e da semana
   E os botões da faixa de boas-vindas abrem o formulário aqui mesmo:
     Novo processo, Nova tarefa, Tratar publicações.

   Ctrl/Cmd/Shift+clique ainda abre a página completa (o cartão é link).

   Endpoints: os mesmos que as telas usam, com a mesma checagem de conta.
     GET   /api/processes.php?id=          POST /api/processes.php
     GET   /api/processo_prazos.php?processo_id=   PATCH (concluir prazo)
     PATCH /api/processo_tarefas.php       (concluir tarefa do processo)
     GET   /api/tasks.php?id=              POST /api/tasks.php (criar) e ?action=complete
     GET   /api/task_boards.php            GET /api/task_users.php
     POST  /api/push/persist.php           action 'tratar' (tratamento da publicação)
     GET   /api/whatsapp/messages.php?jid= POST /api/whatsapp/send.php
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
    pessoa: '<svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    novo: '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>',
    tarefa: '<svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>'
  };
  var ORIGEM = { prazo: 'Prazo do processo', tarefa_processo: 'Tarefa do processo', tarefa: 'Tarefa do quadro' };

  // ── Utilidades ──────────────────────────────────────────────────────────
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function dataLonga(k) { if (!k) return ''; var d = new Date(String(k).slice(0, 10) + 'T12:00:00'); return (String(k).slice(0, 10) === CFG.hoje ? 'Hoje, ' : DIAS[d.getDay()] + ', ') + d.getDate() + ' de ' + MESES[d.getMonth()] + ' de ' + d.getFullYear(); }
  function dataCurta(k) { if (!k) return ''; var s = String(k).slice(0, 10); return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4); }
  function hora(k) { var s = String(k || ''); return s.length >= 16 ? s.slice(11, 16) : ''; }
  function campo(rot, val, cls) { if (val == null || val === '') return ''; return '<div class="idt-campo' + (cls ? ' ' + cls : '') + '"><small>' + esc(rot) + '</small><b>' + val + '</b></div>'; }
  function num(v) { return v ? '<em class="ini-num">' + esc(v) + '</em>' : ''; }
  function link(texto, href, primario) { return '<a class="idt-btn' + (primario ? ' primario' : '') + '" href="' + esc(href) + '">' + esc(texto) + '</a>'; }
  function acao(texto, nome, primario, extra) { return '<button type="button" class="idt-btn' + (primario ? ' primario' : '') + '" data-acao="' + esc(nome) + '"' + (extra || '') + '>' + esc(texto) + '</button>'; }
  function carregando() { return '<div class="idt-carregando"><i></i><span>Carregando…</span></div>'; }
  function avisar(msg, tipo) { if (window.Yuris && window.Yuris.toast) window.Yuris.toast(msg, tipo || 'success'); }
  function getJson(url) { return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); }); }
  function enviar(url, metodo, corpo) {
    return fetch(url, { method: metodo, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CFG.csrf || '' },
      body: JSON.stringify(corpo) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok || j.ok === false || j.success === false || j.error) throw new Error(j.error || ('HTTP ' + r.status)); return j; }); });
  }
  // Botão ocupado enquanto a ação roda; volta ao normal se falhar.
  function ocupado(b, texto) { b.disabled = true; b.dataset.rotulo = b.textContent; b.textContent = texto; }
  function liberado(b) { b.disabled = false; if (b.dataset.rotulo) b.textContent = b.dataset.rotulo; }

  // ── O texto da publicação, tratado como no Diário (intimacoes.js:
  //    cleanText + _formatLegal). Mesma regra, para ler igual nas duas telas. ──
  function limparTexto(s) {
    if (!s) return '';
    var t = String(s);
    t = t.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, ' ').replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, ' ');
    t = t.replace(/<!DOCTYPE[^>]*>/gi, ' ');
    t = t.replace(/<(html|head|body|meta|title|link|style|script)\b[^>]*>/gi, ' ').replace(/<\/(html|head|body|meta|title|link|style|script)>/gi, ' ');
    t = t.replace(/[@#.]?[a-zA-Z_][\w-]*\s*\{[^{}]*\}/g, ' ');
    t = t.replace(/\b[a-zA-Z-]+\s*:\s*[^;{}]+;/g, ' ');
    t = t.replace(/<\s*br\s*\/?>/gi, '\n').replace(/<\/(p|div|tr|li)>/gi, '\n').replace(/<\/td>/gi, ' ');
    t = t.replace(/<[^>]+>/g, '');
    var dec = document.createElement('textarea'); dec.innerHTML = t; t = dec.value;
    t = t.replace(/[ \t ]/g, ' ').replace(/ {2,}/g, ' ').replace(/\n{3,}/g, '\n\n');
    return t.trim();
  }
  function formatarJuridico(s) {
    if (!s) return '';
    var t = String(s);
    [/\bPODER JUDICIÁRIO\b/g, /\bRECLAMANTE\s*:/g, /\bRECLAMADO\s*:/g, /\bEXEQUENTE\s*:/g, /\bEXECUTAD[OA]\s*:/g,
     /\bAUTOR\(?A?\)?\s*:/g, /\bRÉU\(?S?\)?\s*:/g, /\bINTIMAÇÃO\b(?!\s*(?:de|do|da|por|com))/g, /\bDECISÃO\b(?!\s+ID)/g,
     /\bDESPACHO\b(?!\s+ID)/g, /\bSENTENÇA\b(?!\s+ID)/g, /\bACÓRDÃO\b(?!\s+ID)/g, /\bCONCLUSÃO\b/g,
     /\bVistos\b\s*(?:etc)?\./g, /\bFica V\. Sa\./g, /\bFica\s+intimad[oa]\b/g, /\bIntimado\(s\)\s*\/?\s*Citado\(s\)/g,
     /\bPara\s+maiores\s+informações/g].forEach(function (re) { t = t.replace(re, '\n\n$&'); });
    t = t.replace(/\s(\d+)-\s/g, '\n$1- ');
    t = t.replace(/\s+(-\s*ADV\s*:)/gi, '\n$1').replace(/\s+(Adv\s+-)/g, '\n$1');
    t = t.replace(/([a-záéíóúçãõ])(Intimado\(s\))/g, '$1\n\n$2');
    t = t.replace(/\s+-\s+([A-Z][A-Z\.\s\&]{3,}(LTDA|S\.A\.|EIRELI|ME|EPP))/g, '\n - $1');
    t = t.replace(/\s+(Em\s+\d{2}\/\d{2}\/\d{4})/g, '\n$1');
    return t.replace(/\n{3,}/g, '\n\n').replace(/^\n+/, '');
  }
  // Números de processo e OABs em azul, como os detalhes do resto da home.
  function realcar(htmlEsc) {
    return htmlEsc
      .replace(/\b(\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4})\b/g, '<em class="ini-num">$1</em>')
      .replace(/\b(OAB\s*\d{2,7}\s*\/?\s*[A-Z]{2})\b/g, '<strong class="idt-oab">$1</strong>');
  }

  // ── A janela ────────────────────────────────────────────────────────────
  var fundo, elIc, elTit, elSub, elCorpo, elAcoes, origemFoco = null, cartaoAtual = null;
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
    elIc = fundo.querySelector('.idt-ic'); elTit = fundo.querySelector('#idtTitulo'); elSub = fundo.querySelector('.idt-sub');
    elCorpo = fundo.querySelector('.idt-corpo'); elAcoes = fundo.querySelector('.idt-acoes');
    fundo.addEventListener('mousedown', function (e) { if (e.target === fundo) fechar(); });
    fundo.querySelector('.idt-fechar').addEventListener('click', fechar);
    document.addEventListener('keydown', function (e) { if (!fundo.hidden && e.key === 'Escape') fechar(); });
    fundo.addEventListener('click', function (e) {
      var b = e.target.closest('[data-acao]'); if (!b || b.disabled) return;
      var f = ACOES[b.getAttribute('data-acao')]; if (f) { e.preventDefault(); f(b); }
    });
  }
  function abrirJanela(icone, titulo, sub) {
    montar();
    elIc.innerHTML = ICONES[icone] || ICONES.evento;
    elTit.textContent = titulo; elSub.innerHTML = sub || '';
    elCorpo.innerHTML = ''; elAcoes.innerHTML = '';
    fundo.hidden = false;
    document.documentElement.classList.add('idt-aberto');
    requestAnimationFrame(function () { fundo.classList.add('visivel'); });
  }
  function fechar() {
    if (!fundo || fundo.hidden) return;
    fundo.classList.remove('visivel');
    document.documentElement.classList.remove('idt-aberto');
    setTimeout(function () { fundo.hidden = true; }, 160);
    if (origemFoco) { try { origemFoco.focus({ preventScroll: true }); } catch (e) {} }
  }
  // O cartão da home que gerou a janela some da lista quando a ação o resolve.
  function resolverCartao(card) { if (card) card.classList.add('ini-feito'); }

  // ── Ações dos botões da janela ──────────────────────────────────────────
  var ESTADO = {};
  var ACOES = {
    'concluir-prazo': function (b) {
      var id = +b.getAttribute('data-id');
      ocupado(b, 'Concluindo…');
      enviar('/api/processo_prazos.php', 'PATCH', { id: id, status: 'concluido' }).then(function () {
        b.textContent = 'Concluído'; b.classList.add('feito');
        var linha = elCorpo.querySelector('.idt-prazo[data-prazo="' + id + '"]'); if (linha) linha.classList.add('feito');
        document.querySelectorAll('.ini-cartao[data-det]').forEach(function (c) { try { var d = JSON.parse(c.getAttribute('data-det')); if (d.t === 'evento' && d.origem === 'prazo' && +d.id === id) resolverCartao(c); } catch (x) {} });
        avisar('Prazo concluído.');
      }).catch(function (e) { liberado(b); avisar('Não consegui concluir o prazo: ' + e.message, 'danger'); });
    },
    'concluir-tarefa-processo': function (b) {
      var id = +b.getAttribute('data-id');
      ocupado(b, 'Concluindo…');
      enviar('/api/processo_tarefas.php', 'PATCH', { id: id, concluido: 1 }).then(function () {
        b.textContent = 'Concluída'; b.classList.add('feito'); resolverCartao(cartaoAtual); avisar('Tarefa concluída.');
      }).catch(function (e) { liberado(b); avisar('Não consegui concluir a tarefa: ' + e.message, 'danger'); });
    },
    'concluir-tarefa': function (b) {
      var id = +b.getAttribute('data-id');
      ocupado(b, 'Concluindo…');
      enviar('/api/tasks.php?action=complete', 'POST', { id: id }).then(function () {
        b.textContent = 'Concluída'; b.classList.add('feito'); resolverCartao(cartaoAtual); avisar('Tarefa concluída.');
      }).catch(function (e) { liberado(b); avisar('Não consegui concluir a tarefa: ' + e.message, 'danger'); });
    },
    'tratar-pub': function (b) {
      var id = +b.getAttribute('data-id'), status = b.getAttribute('data-status');
      var nota = (elCorpo.querySelector('#idtNota') || {}).value || '';
      elAcoes.querySelectorAll('[data-acao="tratar-pub"]').forEach(function (x) { x.disabled = true; });
      ocupado(b, 'Salvando…');
      enviar('/api/push/persist.php', 'POST', { _csrf: CFG.csrf, event_id: id, action: 'tratar', extra: { status: status, nota: nota } }).then(function () {
        b.textContent = status === 'tratada' ? 'Tratada' : 'Sem providência'; b.classList.add('feito');
        resolverCartao(cartaoAtual);
        avisar(status === 'tratada' ? 'Publicação marcada como tratada.' : 'Publicação marcada sem providência.');
      }).catch(function (e) {
        elAcoes.querySelectorAll('[data-acao="tratar-pub"]').forEach(function (x) { x.disabled = false; });
        liberado(b); avisar('Não consegui registrar: ' + e.message, 'danger');
      });
    },
    'enviar-wa': function (b) {
      var campoTxt = elCorpo.querySelector('#idtResposta'); var texto = (campoTxt.value || '').trim();
      if (!texto) { campoTxt.focus(); return; }
      ocupado(b, 'Enviando…'); campoTxt.disabled = true;
      enviar('/api/whatsapp/send.php', 'POST', { _csrf: CFG.csrf, remote_jid: ESTADO.jid, type: 'text', text: texto }).then(function () {
        var fio = elCorpo.querySelector('.idt-fio');
        if (fio) { fio.insertAdjacentHTML('beforeend', bolha({ direction: 'outbound', message_content: texto, created_at: '' }, true)); fio.scrollTop = fio.scrollHeight; }
        campoTxt.value = ''; campoTxt.disabled = false; liberado(b); campoTxt.focus();
        resolverCartao(cartaoAtual); avisar('Mensagem enviada.');
      }).catch(function (e) { campoTxt.disabled = false; liberado(b); avisar('Não consegui enviar: ' + e.message, 'danger'); });
    },
    'salvar-processo': function (b) {
      var f = elCorpo.querySelector('form'); if (!f.reportValidity()) return;
      var dados = {}; new FormData(f).forEach(function (v, k) { if (String(v).trim() !== '') dados[k] = String(v).trim(); });
      dados.status = 'ativo';
      ocupado(b, 'Cadastrando…');
      enviar('/api/processes.php', 'POST', dados).then(function (r) {
        avisar('Processo cadastrado.');
        var id = r && (r.id || (r.data && r.data.id));
        b.textContent = 'Cadastrado';
        if (id) elAcoes.insertAdjacentHTML('afterbegin', link('Abrir o processo', '/processos?open=' + id, false));
        setTimeout(function () { location.reload(); }, 1600);
      }).catch(function (e) { liberado(b); avisar('Não consegui cadastrar: ' + e.message, 'danger'); });
    },
    'salvar-tarefa': function (b) {
      var f = elCorpo.querySelector('form'); if (!f.reportValidity()) return;
      var d = {}; new FormData(f).forEach(function (v, k) { if (String(v).trim() !== '') d[k] = String(v).trim(); });
      var corpo = { board_id: +d.board_id, titulo: d.titulo, prioridade: d.prioridade || 'media', descricao: d.descricao || null,
        responsavel_id: d.responsavel_id ? +d.responsavel_id : null,
        prazo: d.data ? d.data + ' ' + (d.hora || '00:00') + ':00' : null };
      ocupado(b, 'Criando…');
      enviar('/api/tasks.php', 'POST', corpo).then(function () {
        avisar('Tarefa criada.'); b.textContent = 'Criada';
        setTimeout(function () { location.reload(); }, 1200);
      }).catch(function (e) { liberado(b); avisar('Não consegui criar a tarefa: ' + e.message, 'danger'); });
    }
  };

  // ── O processo: dados e prazos em aberto ────────────────────────────────
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
      html += '</div><div class="idt-sec"><div class="idt-sec-t">Prazos em aberto <span class="idt-pill">' + abertos.length + '</span></div>';
      if (!abertos.length) html += '<div class="idt-vazio">Nenhum prazo em aberto neste processo.</div>';
      else html += '<div class="idt-prazos">' + abertos.map(function (z) {
        var venc = z.data_limite && String(z.data_limite).slice(0, 10) < CFG.hoje;
        return '<div class="idt-prazo' + (prazoEmFoco && +z.id === +prazoEmFoco ? ' foco' : '') + '" data-prazo="' + (+z.id) + '">'
          + '<span class="idt-prazo-data' + (venc ? ' venc' : '') + '">' + esc(dataCurta(z.data_limite)) + '</span>'
          + '<span class="idt-prazo-t"><b>' + esc(z.descricao) + '</b>' + (z.responsavel ? '<small>' + esc(z.responsavel) + '</small>' : '') + '</span>'
          + '<button type="button" class="idt-mini" data-acao="concluir-prazo" data-id="' + (+z.id) + '">Concluir</button></div>';
      }).join('') + '</div>';
      destino.innerHTML = html + '</div>';
      if (p.link_tribunal && /^https?:\/\//i.test(p.link_tribunal)) {
        var a = document.createElement('a'); a.className = 'idt-btn'; a.href = p.link_tribunal; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Abrir no tribunal';
        elAcoes.insertBefore(a, elAcoes.firstChild);
      }
    }).catch(function () {
      destino.innerHTML = '<div class="idt-sec"><div class="idt-vazio erro">Não consegui carregar o processo agora. Use "Abrir o processo".</div></div>';
    });
  }

  // ── Uma mensagem do WhatsApp ────────────────────────────────────────────
  function textoMsg(m) {
    var t = m.message_content || m.caption || '';
    if (t) return t;
    var tp = String(m.message_type || '');
    if (/image/.test(tp)) return 'Imagem'; if (/audio|ptt/.test(tp)) return 'Áudio'; if (/video/.test(tp)) return 'Vídeo'; if (/document/.test(tp)) return 'Documento';
    return 'Mensagem';
  }
  function bolha(m, agora) {
    var saida = m.direction === 'outbound' || m.direction === 'out' || m.from_me == 1;
    return '<div class="idt-bolha ' + (saida ? 'saida' : 'entrada') + '"><p>' + esc(textoMsg(m)) + '</p><small>' + (agora ? 'agora' : esc((m.created_at ? dataCurta(m.created_at) + ' ' + hora(m.created_at) : ''))) + '</small></div>';
  }

  // ── Cada tipo de cartão ─────────────────────────────────────────────────
  var montadores = {
    evento: function (d) {
      var tipoRot = (CFG.tipos && CFG.tipos[d.tipo]) || 'Compromisso';
      var quando = d.data ? dataLonga(d.data) + (d.hora ? ' · ' + d.hora : '') : 'Sem data';
      abrirJanela(d.origem === 'prazo' ? 'prazo' : 'evento', d.titulo, '<i class="ini-tag ' + esc(d.tipo) + '">' + esc(tipoRot) + '</i> ' + esc(quando));
      elCorpo.innerHTML = '<div class="idt-sec"><div class="idt-grade">'
        + campo('Quando', esc(quando), 'largo')
        + campo('Responsável', esc(d.resp))
        + campo('Origem', esc(ORIGEM[d.origem] || ''))
        + (d.processo_id ? '' : campo('Processo', num(d.num)) + campo('Cliente', esc(d.cliente)))
        + '</div></div><div class="idt-extra"></div>';
      var extra = elCorpo.querySelector('.idt-extra');
      // A ação principal é concluir o próprio item; abrir a página fica ao lado.
      var concluir = d.origem === 'prazo' ? acao('Concluir prazo', 'concluir-prazo', true, ' data-id="' + (+d.id) + '"')
        : d.origem === 'tarefa_processo' ? acao('Concluir tarefa', 'concluir-tarefa-processo', true, ' data-id="' + (+d.id) + '"')
        : d.origem === 'tarefa' ? acao('Concluir tarefa', 'concluir-tarefa', true, ' data-id="' + (+d.id) + '"') : '';
      elAcoes.innerHTML = (d.processo_id ? link('Abrir o processo', '/processos?open=' + (+d.processo_id), false) : link('Abrir em Tarefas', '/tarefas', false)) + concluir;
      if (d.processo_id) blocoProcesso(d.processo_id, extra, d.origem === 'prazo' ? d.id : null);
      if (d.origem === 'tarefa' && d.id) {
        var alvo = document.createElement('div'); extra.appendChild(alvo);
        alvo.innerHTML = '<div class="idt-sec">' + carregando() + '</div>';
        getJson('/api/tasks.php?id=' + encodeURIComponent(d.id)).then(function (r) {
          var t = (r && r.data) || {};
          alvo.innerHTML = '<div class="idt-sec"><div class="idt-sec-t">Tarefa</div><div class="idt-grade">'
            + campo('Quadro', esc(t.board_nome)) + campo('Coluna', esc(t.coluna_nome))
            + campo('Prioridade', esc(t.prioridade ? t.prioridade.charAt(0).toUpperCase() + t.prioridade.slice(1) : ''))
            + campo('Criada por', esc(t.criador_nome))
            + '</div>' + (t.descricao ? '<div class="idt-texto">' + esc(t.descricao) + '</div>' : '') + '</div>';
        }).catch(function () { alvo.innerHTML = ''; });
      }
    },
    proc: function (d) {
      abrirJanela('proc', d.cliente || 'Processo', num(d.num));
      elCorpo.innerHTML = '<div class="idt-sec"><div class="idt-grade">'
        + campo('Última movimentação', esc(d.mov), 'largo')
        + campo('Quando', esc(dataCurta(d.quando)))
        + campo('Situação', '<span class="ini-situ ' + esc(d.tom) + '">' + esc(d.situacao) + '</span>')
        + '</div></div><div class="idt-extra"></div>';
      elAcoes.innerHTML = link('Abrir o processo', '/processos?open=' + (+d.processo_id), true);
      blocoProcesso(d.processo_id, elCorpo.querySelector('.idt-extra'), null);
    },
    pub: function (d) {
      abrirJanela('pub', d.tipo || 'Publicação', esc(d.tribunal) + ' · ' + esc(dataLonga(d.data)));
      var texto = formatarJuridico(limparTexto(d.texto || ''));
      elCorpo.innerHTML = '<div class="idt-sec"><div class="idt-grade">'
        + campo('Tribunal', esc(d.tribunal)) + campo('Disponibilizada', esc(dataCurta(d.data)))
        + campo('Processo', num(d.num)) + campo('Cliente', esc(d.cliente))
        + campo('Órgão', esc(d.orgao), 'largo')
        + '</div></div>'
        + '<div class="idt-sec"><div class="idt-sec-t">Texto da publicação</div>'
        + (texto ? '<div class="idt-texto juridico">' + realcar(esc(texto)) + '</div>' : '<div class="idt-vazio">O texto completo está no Diário.</div>') + '</div>'
        + '<div class="idt-sec"><div class="idt-sec-t">Tratamento</div>'
        + '<label class="idt-rotulo" for="idtNota">Nota (opcional)</label>'
        + '<textarea id="idtNota" class="idt-entrada" rows="2" placeholder="O que foi feito ou decidido sobre esta publicação"></textarea></div>';
      elAcoes.innerHTML = (d.processo_id ? link('Abrir o processo', '/processos?open=' + (+d.processo_id), false) : '')
        + acao('Sem providência', 'tratar-pub', false, ' data-id="' + (+d.id) + '" data-status="sem_providencia"')
        + acao('Marcar como tratada', 'tratar-pub', true, ' data-id="' + (+d.id) + '" data-status="tratada"');
    },
    wa: function (d) {
      ESTADO.jid = d.jid;
      abrirJanela('wa', d.nome, 'Esperando resposta ' + esc(d.desde));
      elCorpo.innerHTML = '<div class="idt-sec"><div class="idt-sec-t">Conversa</div><div class="idt-fio">' + carregando() + '</div></div>'
        + '<div class="idt-sec"><label class="idt-rotulo" for="idtResposta">Sua resposta</label>'
        + '<textarea id="idtResposta" class="idt-entrada" rows="3" placeholder="Escreva e aperte Enter para enviar (Shift+Enter quebra a linha)"></textarea></div>';
      elAcoes.innerHTML = link('Abrir o chat completo', '/chat?jid=' + encodeURIComponent(d.jid), false) + acao('Enviar', 'enviar-wa', true);
      var campoTxt = elCorpo.querySelector('#idtResposta');
      campoTxt.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); var b = elAcoes.querySelector('[data-acao="enviar-wa"]'); if (b && !b.disabled) ACOES['enviar-wa'](b); } });
      setTimeout(function () { campoTxt.focus(); }, 60);
      getJson('/api/whatsapp/messages.php?jid=' + encodeURIComponent(d.jid) + '&limit=15').then(function (r) {
        var msgs = (r && r.messages) || [];
        msgs.sort(function (a, b) { return String(a.created_at).localeCompare(String(b.created_at)) || (a.id - b.id); });
        var fio = elCorpo.querySelector('.idt-fio');
        fio.innerHTML = msgs.length ? msgs.map(function (m) { return bolha(m); }).join('') : bolha({ direction: 'inbound', message_content: d.texto, created_at: '' });
        fio.scrollTop = fio.scrollHeight;
      }).catch(function () {
        elCorpo.querySelector('.idt-fio').innerHTML = bolha({ direction: 'inbound', message_content: d.texto, created_at: '' });
      });
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
      elAcoes.innerHTML = link('Ver o desempenho', '/desempenho', true);
    }
  };

  // ── Os botões da faixa de boas-vindas ───────────────────────────────────
  function opcoes(lista, rot, val, vazio) { return (vazio ? '<option value="">' + esc(vazio) + '</option>' : '') + lista.map(function (x) { return '<option value="' + esc(x[val]) + '">' + esc(x[rot]) + '</option>'; }).join(''); }
  var novos = {
    processo: function () {
      abrirJanela('novo', 'Novo processo', 'Cadastro rápido. O resto se completa na tela do processo.');
      elCorpo.innerHTML = '<form class="idt-form" onsubmit="return false">'
        + '<label class="idt-c largo"><span>Número do processo</span><input class="idt-entrada" name="numero" placeholder="0000000-00.0000.0.00.0000" required></label>'
        + '<label class="idt-c"><span>Cliente</span><input class="idt-entrada" name="cliente_nome" required></label>'
        + '<label class="idt-c"><span>Parte contrária</span><input class="idt-entrada" name="parte_contraria"></label>'
        + '<label class="idt-c largo"><span>Vara / comarca</span><input class="idt-entrada" name="vara_comarca"></label>'
        + '<label class="idt-c"><span>Responsável</span><select class="idt-entrada" name="responsavel_user_id"><option value="">Carregando…</option></select></label>'
        + '<label class="idt-c"><span>Próximo prazo</span><input class="idt-entrada" type="date" name="proximo_prazo"></label>'
        + '<label class="idt-c largo"><span>Link do tribunal</span><input class="idt-entrada" type="url" name="link_tribunal" placeholder="https://"></label>'
        + '<label class="idt-c largo"><span>Observações</span><textarea class="idt-entrada" name="observacoes" rows="3"></textarea></label>'
        + '</form>';
      elAcoes.innerHTML = link('Cadastro completo', '/processos', false) + acao('Cadastrar processo', 'salvar-processo', true);
      getJson('/api/task_users.php').then(function (r) { elCorpo.querySelector('[name="responsavel_user_id"]').innerHTML = opcoes((r && r.data) || [], 'nome', 'id', 'Sem responsável'); })
        .catch(function () { elCorpo.querySelector('[name="responsavel_user_id"]').innerHTML = '<option value="">Sem responsável</option>'; });
      setTimeout(function () { elCorpo.querySelector('[name="numero"]').focus(); }, 60);
    },
    tarefa: function () {
      abrirJanela('tarefa', 'Nova tarefa', 'Entra no quadro escolhido e na agenda do dia.');
      elCorpo.innerHTML = '<form class="idt-form" onsubmit="return false">'
        + '<label class="idt-c largo"><span>O que precisa ser feito</span><input class="idt-entrada" name="titulo" required></label>'
        + '<label class="idt-c"><span>Data</span><input class="idt-entrada" type="date" name="data" value="' + esc(CFG.hoje) + '"></label>'
        + '<label class="idt-c"><span>Hora</span><input class="idt-entrada" type="time" name="hora"></label>'
        + '<label class="idt-c"><span>Responsável</span><select class="idt-entrada" name="responsavel_id"><option value="">Carregando…</option></select></label>'
        + '<label class="idt-c"><span>Prioridade</span><select class="idt-entrada" name="prioridade"><option value="baixa">Baixa</option><option value="media" selected>Média</option><option value="alta">Alta</option><option value="urgente">Urgente</option></select></label>'
        + '<label class="idt-c largo"><span>Quadro</span><select class="idt-entrada" name="board_id" required><option value="">Carregando…</option></select></label>'
        + '<label class="idt-c largo"><span>Detalhes</span><textarea class="idt-entrada" name="descricao" rows="3"></textarea></label>'
        + '</form>';
      elAcoes.innerHTML = link('Abrir em Tarefas', '/tarefas', false) + acao('Criar tarefa', 'salvar-tarefa', true);
      getJson('/api/task_users.php').then(function (r) { elCorpo.querySelector('[name="responsavel_id"]').innerHTML = opcoes((r && r.data) || [], 'nome', 'id', 'Sem responsável'); })
        .catch(function () { elCorpo.querySelector('[name="responsavel_id"]').innerHTML = '<option value="">Sem responsável</option>'; });
      getJson('/api/task_boards.php').then(function (r) {
        var lista = ((r && r.data) || []).filter(function (b) { return b.ativo === undefined || +b.ativo === 1; });
        elCorpo.querySelector('[name="board_id"]').innerHTML = lista.length ? opcoes(lista, 'nome', 'id') : '<option value="">Nenhum quadro disponível</option>';
      }).catch(function () { elCorpo.querySelector('[name="board_id"]').innerHTML = '<option value="">Não consegui carregar os quadros</option>'; });
      setTimeout(function () { elCorpo.querySelector('[name="titulo"]').focus(); }, 60);
    },
    publicacoes: function () {
      var cards = [].slice.call(document.querySelectorAll('.ini-pub.ini-cartao[data-det]:not(.ini-feito)'));
      abrirJanela('pub', 'Tratar publicações', cards.length + (cards.length === 1 ? ' pendente na home' : ' pendentes na home'));
      elCorpo.innerHTML = '<div class="idt-sec"><div class="idt-sec-t">Escolha uma para ler e tratar</div><div class="idt-prazos">'
        + (cards.length ? cards.map(function (c, i) { var d = JSON.parse(c.getAttribute('data-det'));
            return '<button type="button" class="idt-prazo idt-escolha" data-escolha="' + i + '"><span class="idt-prazo-data">' + esc(d.tribunal) + '</span><span class="idt-prazo-t"><b>' + esc(d.tipo) + (d.cliente ? ' · ' + esc(d.cliente) : '') + '</b><small>' + esc(dataCurta(d.data)) + (d.num ? ' · ' + esc(d.num) : '') + '</small></span><span class="idt-mini">Ler e tratar</span></button>'; }).join('')
          : '<div class="idt-vazio">Nenhuma publicação pendente na home.</div>')
        + '</div></div>';
      elCorpo.querySelectorAll('[data-escolha]').forEach(function (b) { b.addEventListener('click', function () { var c = cards[+b.getAttribute('data-escolha')]; cartaoAtual = c; montadores.pub(JSON.parse(c.getAttribute('data-det'))); }); });
      elAcoes.innerHTML = link('Abrir o Diário completo', '/intimacoes', true);
    }
  };

  // ── Os cliques ──────────────────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var gatilho = e.target.closest && e.target.closest('[data-novo]');
    if (gatilho && novos[gatilho.getAttribute('data-novo')]) { e.preventDefault(); origemFoco = gatilho; cartaoAtual = null; novos[gatilho.getAttribute('data-novo')](); return; }
    var card = e.target.closest && e.target.closest('.ini-cartao[data-det]');
    if (!card) return;
    var d; try { d = JSON.parse(card.getAttribute('data-det')); } catch (x) { return; }
    var m = montadores[d.t]; if (!m) return;
    e.preventDefault();
    origemFoco = card; cartaoAtual = card;
    m(d);
  });
})();
