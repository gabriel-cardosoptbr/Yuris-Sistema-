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
     conversa              a conversa no Chat de verdade (pop-up do módulo, abaixo)
     cliente               o nome abre a ficha de verdade de Clientes (?open=ID)
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
  // O nome do cliente, quando o cadastro existe, abre a ficha de verdade de Clientes.
  function cliente(nome, id) {
    if (!nome) return '';
    return +id ? '<button type="button" class="idt-link" data-acao="abrir-cliente" data-id="' + (+id) + '" title="Abrir a ficha do cliente">' + esc(nome) + '</button>' : esc(nome);
  }
  function campo(rot, val, cls) { if (val == null || val === '') return ''; return '<div class="idt-campo' + (cls ? ' ' + cls : '') + '"><small>' + esc(rot) + '</small><b>' + val + '</b></div>'; }
  function num(v) { return v ? '<em class="ini-num">' + esc(v) + '</em>' : ''; }
  function link(texto, href, primario) { return '<a class="idt-btn' + (primario ? ' primario' : '') + '" href="' + esc(href) + '">' + esc(texto) + '</a>'; }
  function acao(texto, nome, primario, extra) { return '<button type="button" class="idt-btn' + (primario ? ' primario' : '') + '" data-acao="' + esc(nome) + '"' + (extra || '') + '>' + esc(texto) + '</button>'; }
  // Enquanto carrega, a forma do que vem em ossos cinza: campos e linhas de prazo.
  function ossos(campos, linhas) {
    var o = function (est) { return '<i class="idt-osso" style="' + est + '"></i>'; }, h = '';
    if (campos) { h += '<div class="idt-grade">'; for (var i = 0; i < campos; i++) h += '<div class="idt-campo osso">' + o('width:' + (28 + (i * 17) % 34) + '%;height:9px') + o('width:' + (50 + (i * 23) % 40) + '%;height:13px') + '</div>'; h += '</div>'; }
    if (linhas) { h += '<div class="idt-prazos">'; for (var k = 0; k < linhas; k++) h += '<div class="idt-prazo osso">' + o('width:54px;height:30px') + '<span class="idt-prazo-t">' + o('width:' + (55 + k * 15) + '%;height:12px') + o('width:30%;height:9px') + '</span>' + o('width:72px;height:26px') + '</div>'; h += '</div>'; }
    return h;
  }
  // O conteúdo de verdade chegou no lugar dos ossos: sai do cinza e ganha cor.
  function ganhaVida(el) { el.classList.remove('idt-vida'); void el.offsetWidth; el.classList.add('idt-vida'); }
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
      .replace(/\b(OAB\s*:?\s*(?:\d[\d.]{1,8}[A-Z]?\s*\/?\s*[A-Z]{2}|\/?\s*[A-Z]{2}\s*:?\s*\d[\d.]{1,8}))\b/g, '<strong class="idt-oab">$1</strong>')
      // Endereço do tribunal no texto vira link (só https, em outra aba). O ponto final fica fora.
      .replace(/\bhttps:\/\/[^\s<"]+?(?=[.,;)]?(?:\s|<|$))/g, function (u) { return '<a class="idt-pub-link-txt" href="' + u + '" target="_blank" rel="noopener noreferrer">' + u + '</a>'; });
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
    var jaVisivel = !fundo.hidden && fundo.classList.contains('visivel');
    fundo.hidden = false; fundo.classList.remove('fechando');
    document.documentElement.classList.add('idt-aberto');
    // A janela nasce do cartão clicado ("que se transforme o card na abertura"): começa
    // com o tamanho e a posição dele e a transição do inicio-movimento.css a leva ao lugar.
    var jan = fundo.querySelector('.idt-janela'), de = jaVisivel ? null : origemRect();
    if (!de) { void fundo.offsetWidth; fundo.classList.add('visivel'); return; } // reflow em vez de rAF: aba em segundo plano não pausa a entrada
    if (origemFoco && origemFoco.classList) { origemFoco.classList.remove('ini-abrindo'); void origemFoco.offsetWidth; origemFoco.classList.add('ini-abrindo'); }
    // Quem chamou ainda vai encher o corpo nesta mesma volta; a medida da janela só vale
    // depois disso, por isso a largada fica para a microtarefa seguinte.
    jan.style.opacity = '0';
    Promise.resolve().then(function () {
      if (fundo.hidden) return;
      var j = jan.getBoundingClientRect();
      jan.style.transition = 'none'; jan.style.transformOrigin = '0 0';
      jan.style.transform = 'translate(' + (de.left - j.left) + 'px,' + (de.top - j.top) + 'px) scale(' + Math.max(de.width / j.width, .2) + ',' + Math.max(de.height / j.height, .08) + ')';
      jan.style.opacity = '.4';
      void fundo.offsetWidth;
      fundo.classList.add('visivel');
      jan.style.transition = ''; jan.style.transform = ''; jan.style.opacity = '';
    });
  }
  function origemRect() {
    if (!origemFoco || !origemFoco.getBoundingClientRect || !document.documentElement.classList.contains('ini-mov')) return null;
    var r = origemFoco.getBoundingClientRect();
    return r.width > 0 && r.height > 0 ? r : null;
  }
  function fechar() {
    if (!fundo || fundo.hidden) return;
    // Volta para o cartão de onde veio.
    var jan = fundo.querySelector('.idt-janela'), para = origemRect(), espera = 160;
    if (para) {
      var j = jan.getBoundingClientRect();
      fundo.classList.add('fechando'); jan.style.transformOrigin = '0 0';
      jan.style.transform = 'translate(' + (para.left - j.left) + 'px,' + (para.top - j.top) + 'px) scale(' + Math.max(para.width / j.width, .2) + ',' + Math.max(para.height / j.height, .08) + ')';
      jan.style.opacity = '0'; espera = 230;
    }
    fundo.classList.remove('visivel');
    document.documentElement.classList.remove('idt-aberto');
    setTimeout(function () { fundo.hidden = true; fundo.classList.remove('fechando'); jan.style.transform = ''; jan.style.opacity = ''; jan.style.transformOrigin = ''; }, espera);
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
    }
  };

  // ── O processo: dados e prazos em aberto ────────────────────────────────
  function blocoProcesso(processoId, destino, prazoEmFoco) {
    destino.innerHTML = '<div class="idt-sec"><div class="idt-sec-t">Processo</div>' + ossos(6) + '</div><div class="idt-sec"><div class="idt-sec-t">Prazos em aberto</div>' + ossos(0, 2) + '</div>';
    Promise.all([
      getJson('/api/processes.php?id=' + encodeURIComponent(processoId)),
      getJson('/api/processo_prazos.php?processo_id=' + encodeURIComponent(processoId)).catch(function () { return { data: [] }; })
    ]).then(function (r) {
      var p = r[0] || {}, prazos = (r[1] && r[1].data) || [];
      var abertos = prazos.filter(function (z) { return z.status !== 'concluido'; });
      var html = '<div class="idt-sec"><div class="idt-sec-t">Processo</div><div class="idt-grade">'
        + campo('Número', num(p.numero_cnj || p.numero))
        + campo('Cliente', cliente(p.cliente_nome, p.cliente_id))
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
      ganhaVida(destino);
      if (p.link_tribunal && /^https?:\/\//i.test(p.link_tribunal)) {
        var a = document.createElement('a'); a.className = 'idt-btn'; a.href = p.link_tribunal; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Abrir no tribunal';
        elAcoes.insertBefore(a, elAcoes.firstChild);
      }
    }).catch(function () {
      destino.innerHTML = '<div class="idt-sec"><div class="idt-vazio erro">Não consegui carregar o processo agora. Use "Abrir o processo".</div></div>';
    });
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
      // A ficha do processo abre já descendo até o prazo ou a tarefa (o ?foco= da tela de Processos).
      var foco = d.origem === 'prazo' ? 'prazo:' + (+d.id) : d.origem === 'tarefa_processo' ? 'tarefa:' + (+d.id) : '';
      elAcoes.innerHTML = (d.processo_id ? acao('Abrir o processo', 'abrir-processo', false, ' data-id="' + (+d.processo_id) + '"' + (foco ? ' data-foco="' + foco + '"' : ''))
        : d.origem === 'tarefa' && d.id ? acao('Abrir a tarefa', 'abrir-tarefa', false, ' data-id="' + (+d.id) + '"') : link('Abrir em Tarefas', '/tarefas', false)) + concluir;
      if (d.processo_id) blocoProcesso(d.processo_id, extra, d.origem === 'prazo' ? d.id : null);
      if (d.origem === 'tarefa' && d.id) {
        var alvo = document.createElement('div'); extra.appendChild(alvo);
        alvo.innerHTML = '<div class="idt-sec"><div class="idt-sec-t">Tarefa</div>' + ossos(4) + '</div>';
        getJson('/api/tasks.php?id=' + encodeURIComponent(d.id)).then(function (r) {
          var t = (r && r.data) || {};
          alvo.innerHTML = '<div class="idt-sec"><div class="idt-sec-t">Tarefa</div><div class="idt-grade">'
            + campo('Quadro', esc(t.board_nome)) + campo('Coluna', esc(t.coluna_nome))
            + campo('Prioridade', esc(t.prioridade ? t.prioridade.charAt(0).toUpperCase() + t.prioridade.slice(1) : ''))
            + campo('Criada por', esc(t.criador_nome))
            + '</div>' + (t.descricao ? '<div class="idt-texto">' + esc(t.descricao) + '</div>' : '') + '</div>';
          ganhaVida(alvo);
        }).catch(function () { alvo.innerHTML = ''; });
      }
    },
    // O cartão de processo abre direto a ficha de verdade da tela de Processos.
    proc: function (d) {
      abrirModulo('/processos?open=' + (+d.processo_id));
    },
    // A publicação em tópicos, separada no servidor por App\Processos\PublicacaoEstrutura:
    // cabeçalho, timbre, partes, advogados um por linha com a OAB, cada seção do teor
    // com os parágrafos e a assinatura à parte, e os intimados. Sem estrutura (texto
    // que não deu para separar), cai na limpeza do Diário.
    pub: function (d) {
      abrirJanela('pub', d.tipo || 'Publicação', esc(d.tribunal) + ' · ' + esc(dataLonga(d.data)));
      var e = d.estrutura || {};
      var nomes = function (lista) { return lista.map(function (n) { return '<span class="idt-pub-nome">' + esc(n) + '</span>'; }).join(''); };
      var cab = (e.cabecalho || []).filter(function (c) { return !(c.rotulo === 'Processo' && d.num); });
      // Em pares; se sobrar um, ele ocupa a linha inteira (sem vão na grade).
      var pares = [['Tribunal', esc(d.tribunal)], ['Disponibilizada', esc(dataCurta(d.data))], ['Processo', num(d.num)], ['Cliente', cliente(d.cliente, d.cliente_id)]]
        .concat(cab.map(function (c) { return [c.rotulo, /^Processo/.test(c.rotulo) ? num(c.valor) : esc(c.valor)]; }))
        .filter(function (p) { return p[1]; });
      var html = '<div class="idt-sec"><div class="idt-grade">'
        + pares.map(function (p, i) { return campo(p[0], p[1], i === pares.length - 1 && pares.length % 2 ? 'largo' : ''); }).join('')
        + campo('Órgão', esc(d.orgao), 'largo')
        + '</div>' + (e.preambulo ? '<div class="idt-pub-timbre">' + realcar(esc(e.preambulo)) + '</div>' : '') + '</div>';
      if (e.partes && e.partes.length) {
        html += '<div class="idt-sec"><div class="idt-sec-t">Partes</div><div class="idt-pub-lista">'
          + e.partes.map(function (g) { return '<div class="idt-pub-grupo">' + (g.rotulo === 'Partes' ? '' : '<span class="idt-pub-rot">' + esc(g.rotulo) + '</span>') + '<div>' + nomes(g.nomes) + '</div></div>'; /* sem rótulo, o título da seção já diz Partes */ }).join('')
          + '</div></div>';
      }
      if (e.advogados && e.advogados.length) {
        html += '<div class="idt-sec"><div class="idt-sec-t">Advogados</div><div class="idt-pub-lista">'
          + e.advogados.map(function (a) { return '<div class="idt-pub-adv"><span class="idt-pub-nome">' + esc(a.nome) + '</span>' + (a.oab ? '<strong class="idt-oab">OAB ' + esc(a.oab) + (a.uf ? '/' + esc(a.uf) : '') + '</strong>' : '') + '</div>'; }).join('')
          + '</div></div>';
      }
      var teor = '';
      if (e.blocos && e.blocos.length) {
        teor = e.blocos.map(function (b) {
          var a = b.assinatura;
          return '<div class="idt-pub-bloco">' + (b.titulo ? '<div class="idt-pub-secao">' + esc(b.titulo) + '</div>' : '')
            + b.paragrafos.map(function (p) { return '<p>' + realcar(esc(p)) + '</p>'; }).join('')
            + (a ? '<div class="idt-pub-assin">' + (a.local ? '<span>' + esc(a.local) + '</span>' : '') + (a.nome ? '<b>' + esc(a.nome) + '</b>' : '') + (a.cargo ? '<span>' + esc(a.cargo) + '</span>' : '') + '</div>' : '')
            + '</div>';
        }).join('');
        teor = '<div class="idt-pub-teor">' + teor + '</div>';
      } else {
        var texto = formatarJuridico(limparTexto(d.texto || ''));
        if (texto) teor = '<div class="idt-texto juridico">' + realcar(esc(texto)) + '</div>';
      }
      html += '<div class="idt-sec"><div class="idt-sec-t">Teor da publicação</div>' + (teor || '<div class="idt-vazio">O texto completo está no Diário.</div>') + '</div>';
      if (e.intimados && e.intimados.length) html += '<div class="idt-sec"><div class="idt-sec-t">Intimados</div><div class="idt-pub-lista"><div class="idt-pub-grupo"><div>' + nomes(e.intimados) + '</div></div></div></div>';
      elCorpo.innerHTML = html
        + (e.link ? '<div class="idt-sec"><a class="idt-pub-link" href="' + esc(e.link) + '" target="_blank" rel="noopener noreferrer">Ver a certidão no tribunal</a></div>' : '')
        + '<div class="idt-sec"><div class="idt-sec-t">Tratamento</div>'
        + '<label class="idt-rotulo" for="idtNota">Nota (opcional)</label>'
        + '<textarea id="idtNota" class="idt-entrada" rows="2" placeholder="O que foi feito ou decidido sobre esta publicação"></textarea></div>';
      elAcoes.innerHTML = (d.processo_id ? acao('Abrir o processo', 'abrir-processo', false, ' data-id="' + (+d.processo_id) + '"') : '')
        + acao('Sem providência', 'tratar-pub', false, ' data-id="' + (+d.id) + '" data-status="sem_providencia"')
        + acao('Marcar como tratada', 'tratar-pub', true, ' data-id="' + (+d.id) + '" data-status="tratada"');
    },
    // A conversa abre no Chat de verdade: a mesma tela, só a conversa, por cima da home.
    wa: function (d) {
      abrirModulo('/chat?jid=' + encodeURIComponent(d.jid));
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

  // ── O pop-up de verdade do módulo, dentro da home ───────────────────────
  // "Tem que abrir exatamente o pop-up lá do campo, do jurídico, de operação:
  // o campo de verdade." A tela do módulo abre num iframe com ?embed=1 e mostra
  // só o pop-up dela (assets/embed.js), o mesmo de sempre, com tudo o que tem.
  // Quando ele fecha, o iframe sai e a home se atualiza.
  var EMB = null;
  function abrirModulo(url) {
    if (EMB) return;
    if (fundo && !fundo.hidden) { fundo.classList.remove('visivel'); fundo.hidden = true; }
    var caixa = document.createElement('div');
    caixa.className = 'idt-embed';
    // Enquanto o módulo carrega, a forma do pop-up que vem, em ossos cinza ("a tela
    // fantasma"); quando ele chega, a forma se move até onde o pop-up de verdade está
    // (o embed.js manda a medida) e o pop-up aparece no lugar dela.
    var tela = (/^\/([a-z_]+)/.exec(url) || [])[1] || '';
    var fan = document.createElement('div');
    fan.className = 'idt-fantasma' + (tela === 'chat' ? ' chat' : '');
    fan.innerHTML = fantasma(tela);
    caixa.appendChild(fan);
    var f = document.createElement('iframe');
    f.className = 'idt-embed-quadro'; f.title = 'Pop-up do módulo';
    f.src = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'embed=1';
    caixa.appendChild(f);
    // "Se eu ficar clicando fora, que seja cancelado": enquanto carrega, o clique fora
    // (o quadro ainda não pega clique) desiste de abrir.
    caixa.addEventListener('mousedown', function () { if (EMB && EMB.caixa === caixa && !caixa.classList.contains('pronto')) fecharModulo(); });
    document.body.appendChild(caixa);
    document.documentElement.classList.add('idt-aberto');
    EMB = { caixa: caixa, quadro: f, url: url };
    void caixa.offsetWidth; caixa.classList.add('visivel');
  }
  // Fechar só fecha: a home fica exatamente como estava. Nada recarrega e nenhum cartão
  // é trocado ("se eu sair, é só para sair; se eu recarregar a página, aí recarrega tudo").
  function fecharModulo() {
    if (!EMB) return;
    var c = EMB.caixa; EMB = null;
    c.classList.remove('visivel');
    document.documentElement.classList.remove('idt-aberto');
    setTimeout(function () { c.remove(); }, 160);
  }
  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || !EMB || !e.data || !e.data.yuris) return;
    if (e.data.yuris === 'embed-pronto') morfar(e.data.caixa);
    if (e.data.yuris === 'embed-fechar') fecharModulo();
    // Não abriu: fica na home e avisa. Nunca leva para a tela do módulo.
    if (e.data.yuris === 'embed-sem-popup') {
      fecharModulo();
      avisar(e.data.tela === 'chat' ? 'A conversa não abriu. Confira se o WhatsApp está conectado e tente de novo.' : 'Não consegui abrir agora. Tente de novo.', 'danger');
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && EMB && !EMB.caixa.classList.contains('pronto')) fecharModulo(); });
  // A tela fantasma: a forma do pop-up do módulo, em ossos. Ficha (processo, cliente,
  // tarefa) ou conversa (chat).
  function fantasma(tela) {
    var o = function (cls, est) { return '<i class="idt-osso' + (cls ? ' ' + cls : '') + '"' + (est ? ' style="' + est + '"' : '') + '></i>'; };
    var cab = '<div class="idt-fan-cab">' + o('quad') + '<span>' + o('', 'width:42%;height:16px') + o('', 'width:26%;height:11px;margin-top:7px') + '</span>' + o('quad peq') + '</div>';
    if (tela === 'chat') {
      var bolhas = [58, 40, 66, 35, 52, 44].map(function (w, i) { return o('bolha' + (i % 2 ? ' dir' : ''), 'width:' + w + '%'); }).join('');
      return cab + '<div class="idt-fan-corpo chat">' + bolhas + '</div><div class="idt-fan-pe chat">' + o('quad peq') + o('', 'flex:1;height:40px') + o('quad peq') + '</div>';
    }
    var campos = '';
    for (var i = 0; i < 6; i++) campos += '<span class="idt-fan-campo">' + o('', 'width:' + (30 + (i * 13) % 30) + '%;height:10px') + o('', 'height:38px') + '</span>';
    var abas = tela === 'tarefas' ? '<div class="idt-fan-abas">' + [70, 80, 60, 64, 58].map(function (w) { return o('', 'width:' + w + 'px;height:12px'); }).join('') + '</div>' : '';
    return cab + '<div class="idt-fan-corpo">' + abas + '<div class="idt-fan-grade">' + campos + '</div>' + o('', 'height:92px;margin-top:12px') + '</div>'
      + '<div class="idt-fan-pe">' + o('', 'width:120px;height:38px') + o('', 'width:150px;height:38px') + '</div>';
  }
  // O pop-up chegou: a forma vai até a medida dele e some por baixo do pop-up de verdade.
  function morfar(c) {
    if (!EMB) return;
    var caixa = EMB.caixa, fan = caixa.querySelector('.idt-fantasma');
    if (!fan || !c || !c.width || !document.documentElement.classList.contains('ini-mov')) { caixa.classList.add('pronto'); return; }
    // Pop-up mais alto que a janela (a ficha do cliente rola por dentro): a forma para na borda.
    c = { left: Math.max(0, c.left), top: Math.max(0, c.top), width: Math.min(c.width, window.innerWidth - Math.max(0, c.left)), height: Math.min(c.height, window.innerHeight - Math.max(0, c.top)) };
    var r = fan.getBoundingClientRect();
    fan.style.transition = 'none'; fan.style.transform = 'none';
    fan.style.left = r.left + 'px'; fan.style.top = r.top + 'px'; fan.style.width = r.width + 'px'; fan.style.height = r.height + 'px';
    void fan.offsetWidth;
    fan.style.transition = ''; fan.classList.add('morfa');
    fan.style.left = c.left + 'px'; fan.style.top = c.top + 'px'; fan.style.width = c.width + 'px'; fan.style.height = c.height + 'px';
    setTimeout(function () { if (EMB && EMB.caixa === caixa) caixa.classList.add('pronto'); }, 400);
  }
  ACOES['abrir-processo'] = function (b) {
    var foco = b.getAttribute('data-foco') || '';
    abrirModulo('/processos?open=' + (+b.getAttribute('data-id')) + (/^(prazo|tarefa):\d+$/.test(foco) ? '&foco=' + foco : ''));
  };
  ACOES['abrir-tarefa'] = function (b) { abrirModulo('/tarefas?tarefa=' + (+b.getAttribute('data-id'))); };
  ACOES['abrir-cliente'] = function (b) { abrirModulo('/clientes?open=' + (+b.getAttribute('data-id'))); };
  // ── Os botões da faixa de boas-vindas ───────────────────────────────────
  var novos = {
    processo: function () {
      abrirModulo('/processos?criar=1');
    },
    tarefa: function () {
      abrirModulo('/tarefas?nova=1');
    },
    publicacoes: function () {
      var cards = [].slice.call(document.querySelectorAll('.ini-pub.ini-cartao[data-det]:not(.ini-feito)'));
      abrirJanela('pub', 'Tratar publicações', cards.length + (cards.length === 1 ? ' pendente na home' : ' pendentes na home'));
      elCorpo.innerHTML = '<div class="idt-sec"><div class="idt-sec-t">Escolha uma para ler e tratar</div><div class="idt-prazos">'
        + (cards.length ? cards.map(function (c, i) { var d = JSON.parse(c.getAttribute('data-det'));
            return '<button type="button" class="idt-prazo idt-escolha" data-escolha="' + i + '"><span class="idt-prazo-data">' + esc(d.tribunal) + '</span><span class="idt-prazo-t"><b>' + esc(d.tipo) + (d.cliente ? ' · ' + esc(d.cliente) : '') + '</b><small>' + esc(dataCurta(d.data)) + (d.num ? ' · ' + esc(d.num) : '') + '</small></span><span class="idt-mini">Ler e tratar</span></button>'; }).join('')
          : '<div class="idt-vazio">Nenhuma publicação pendente na home.</div>')
        + '</div></div>';
      elCorpo.querySelectorAll('[data-escolha]').forEach(function (b) { b.addEventListener('click', function () { var c = cards[+b.getAttribute('data-escolha')]; cartaoAtual = c; ESTADO.ultimoDet = JSON.parse(c.getAttribute('data-det')); montadores.pub(ESTADO.ultimoDet); }); });
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
    origemFoco = card; cartaoAtual = card; ESTADO.ultimoDet = d;
    m(d);
  });
})();
