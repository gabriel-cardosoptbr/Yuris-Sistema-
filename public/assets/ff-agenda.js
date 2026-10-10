/* ═══════════════════════════════════════════════════════════════════════
   ff-agenda.js: agenda da próxima interação com o lead (edição CRM).
   Carregado pelo sidebar.php só em conta Fleetiflow, em toda página.
   Regra de negócio em app/Prospeccao/AgendaDoLead.php; API em
   /api/crm_agenda.php.

   1. Ficha do lead (prospeccao.php): botão "Agendar próxima interação" no
      rodapé e a seção "Próxima interação", com o que já está marcado.
   2. A janela de agendar: tipo, data e hora (com atalhos), lembrete,
      responsável, quadro e, se for mensagem, o texto que sai sozinho.
   3. Ao entrar (uma vez por login e por dia): "Sua agenda de hoje".
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var CFG = window.FF_AGENDA || {};
  var API = '/api/crm_agenda.php';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var SVG = {
    calendario: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    ligacao:    '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
    reuniao:    '<path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/>',
    mensagem:   '<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z"/>',
    tarefa:     '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    fechar:     '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    ok:         '<polyline points="20 6 9 17 4 12"/>',
    sino:       '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
    enviar:     '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
    refazer:    '<polyline points="23 4 23 10 17 10"/><path d="M20.5 15a9 9 0 1 1-2.1-9.4L23 10"/>',
    seta:       '<polyline points="9 18 15 12 9 6"/>',
    relogio:    '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'
  };
  function ico(n) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (SVG[n] || '') + '</svg>';
  }

  var TIPOS = [
    { id: 'ligacao', rotulo: 'Ligação' }, { id: 'reuniao', rotulo: 'Reunião' },
    { id: 'mensagem', rotulo: 'Mensagem' }, { id: 'tarefa', rotulo: 'Tarefa' }
  ];
  var ROTULO = { ligacao: 'Ligação', reuniao: 'Reunião', mensagem: 'Mensagem', tarefa: 'Tarefa' };
  var LEMBRETES = [
    [0, 'Na hora'], [5, '5 minutos antes'], [15, '15 minutos antes'], [30, '30 minutos antes'],
    [60, '1 hora antes'], [120, '2 horas antes'], [1440, '1 dia antes'], [-1, 'Não avisar']
  ];
  var MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

  function csrf() {
    return CFG.csrf || (window.YURIS_NOTIF && window.YURIS_NOTIF.csrf) || (typeof window.csrf === 'string' ? window.csrf : '');
  }
  function pedir(metodo, qs, corpo) {
    var op = { method: metodo, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (corpo) {
      op.headers['Content-Type'] = 'application/json';
      op.headers['X-CSRF-Token'] = csrf();
      op.body = JSON.stringify(corpo);
    }
    return fetch(API + (qs || ''), op).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Resposta inválida do servidor.' }; });
    }).catch(function () { return { ok: false, error: 'Sem conexão. Tente de novo.' }; });
  }

  function dois(n) { return (n < 10 ? '0' : '') + n; }
  function isoData(d) { return d.getFullYear() + '-' + dois(d.getMonth() + 1) + '-' + dois(d.getDate()); }
  function isoHora(d) { return dois(d.getHours()) + ':' + dois(d.getMinutes()); }
  function paraData(s) { return new Date(String(s).replace(' ', 'T')); }
  function hojeIso() { return isoData(new Date()); }
  function quandoCurto(prazo) {
    var d = paraData(prazo), hoje = new Date(), amanha = new Date();
    amanha.setDate(hoje.getDate() + 1);
    if (isoData(d) === isoData(hoje)) return 'Hoje';
    if (isoData(d) === isoData(amanha)) return 'Amanhã';
    return dois(d.getDate()) + ' ' + MESES[d.getMonth()];
  }

  /* ── Janela genérica ─────────────────────────────────────────────────── */
  function janela(opts) {
    var ov = document.createElement('div');
    ov.className = 'ffag-overlay';
    ov.innerHTML =
      '<div class="ffag-painel' + (opts.largo ? ' ffag-largo' : '') + '" role="dialog" aria-modal="true" aria-labelledby="ffagTit">' +
        '<div class="ffag-cab"><span class="ffag-cab-ico">' + ico(opts.icone || 'calendario') + '</span>' +
          '<div class="ffag-cab-txt"><h3 id="ffagTit">' + esc(opts.titulo) + '</h3>' + (opts.sub ? '<p>' + esc(opts.sub) + '</p>' : '') + '</div>' +
          '<button type="button" class="ffag-x" aria-label="Fechar">' + ico('fechar') + '</button></div>' +
        '<div class="ffag-corpo"></div><div class="ffag-pe"></div>' +
      '</div>';
    document.body.appendChild(ov);
    function fechar() {
      document.removeEventListener('keydown', tecla, true);
      ov.remove();
      if (opts.aoFechar) opts.aoFechar();
    }
    function tecla(e) { if (e.key === 'Escape') { e.stopPropagation(); fechar(); } }
    document.addEventListener('keydown', tecla, true);
    ov.addEventListener('mousedown', function (e) { if (e.target === ov) fechar(); });
    ov.querySelector('.ffag-x').addEventListener('click', fechar);
    return { el: ov, corpo: ov.querySelector('.ffag-corpo'), pe: ov.querySelector('.ffag-pe'), fechar: fechar };
  }

  /* ── Agendar próxima interação ───────────────────────────────────────── */
  function abrirAgendar(cardId, nomeLead, aoSalvar) {
    var j = janela({ titulo: 'Agendar próxima interação', sub: nomeLead ? 'com ' + nomeLead : '', icone: 'calendario' });
    j.corpo.innerHTML = '<div class="ffag-vazio">Carregando…</div>';

    pedir('GET', '?card_id=' + encodeURIComponent(cardId)).then(function (r) {
      if (!r.ok) { j.corpo.innerHTML = '<div class="ffag-erro">' + esc(r.error || 'Não foi possível abrir.') + '</div>'; return; }
      r.data.nome = nomeLead || '';
      montarFormulario(j, cardId, r.data, aoSalvar);
    });
  }

  function montarFormulario(j, cardId, d, aoSalvar) {
    var amanha = new Date(); amanha.setDate(amanha.getDate() + 1); amanha.setHours(9, 0, 0, 0);
    var zap = d.whatsapp;
    var equipe = (d.equipe || []).map(function (u) {
      return '<option value="' + u.id + '"' + (u.id === d.eu ? ' selected' : '') + '>' + esc(u.nome) + (u.id === d.eu ? ' (eu)' : '') + '</option>';
    }).join('');
    var quadros = (d.quadros || []).map(function (q) {
      return '<option value="' + q.id + '"' + (q.id === d.quadro_padrao ? ' selected' : '') + '>' + esc(q.nome) + '</option>';
    }).join('');

    j.corpo.innerHTML =
      '<div class="ffag-campo"><span class="ffag-rotulo">O que vai ser</span><div class="ffag-tipos" role="group" aria-label="Tipo da interação">' +
        TIPOS.map(function (t, i) {
          return '<button type="button" class="ffag-tipo" data-tipo="' + t.id + '" aria-pressed="' + (i === 0 ? 'true' : 'false') + '">' + ico(t.id) + '<span>' + t.rotulo + '</span></button>';
        }).join('') + '</div></div>' +
      '<div class="ffag-campo"><span class="ffag-rotulo">Quando</span>' +
        '<div class="ffag-atalhos">' +
          '<button type="button" class="ffag-atalho" data-mais="h1">Daqui a 1 hora</button>' +
          '<button type="button" class="ffag-atalho" data-mais="d1">Amanhã</button>' +
          '<button type="button" class="ffag-atalho" data-mais="d2">Em 2 dias</button>' +
          '<button type="button" class="ffag-atalho" data-mais="d7">Em 1 semana</button>' +
        '</div>' +
        '<div class="ffag-linha"><input type="date" name="data" aria-label="Data" min="' + hojeIso() + '" value="' + isoData(amanha) + '">' +
        '<input type="time" name="hora" aria-label="Hora" step="300" value="09:00"></div></div>' +
      '<div class="ffag-campo"><label class="ffag-rotulo" for="ffagAssunto">Assunto <small>(opcional)</small></label>' +
        '<input type="text" id="ffagAssunto" name="assunto" maxlength="250" placeholder=""></div>' +
      '<div class="ffag-msg" hidden>' +
        (zap
          ? '<label class="ffag-chave"><input type="checkbox" name="programar" checked><span>Programar a mensagem no WhatsApp' +
              '<small>Sai sozinha na hora marcada para ' + esc(fone(zap.numero)) + ', e a tarefa é concluída.</small></span></label>' +
            '<textarea name="mensagem" maxlength="4000" placeholder="Olá! Conforme combinamos, estou retornando o contato…"></textarea>' +
            '<div class="ffag-conta"><span data-conta>0</span> / 4000</div>'
          : '<div class="ffag-sem-zap">Este lead não tem WhatsApp para a mensagem sair sozinha. Fica só o lembrete na agenda. Para programar, preencha o WhatsApp do lead ou ligue a conversa ao card.</div>') +
      '</div>' +
      '<div class="ffag-campo"><label class="ffag-rotulo" for="ffagObs">Anotação <small>(opcional)</small></label>' +
        '<textarea id="ffagObs" name="observacao" maxlength="2000" placeholder="O que foi combinado na última conversa"></textarea></div>' +
      '<div class="ffag-linha">' +
        '<div class="ffag-campo"><label class="ffag-rotulo" for="ffagLembrete">Me avisar</label><select id="ffagLembrete" name="lembrete_min">' +
          LEMBRETES.map(function (l) { return '<option value="' + l[0] + '"' + (l[0] === 15 ? ' selected' : '') + '>' + l[1] + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="ffag-campo"><label class="ffag-rotulo" for="ffagResp">Responsável</label><select id="ffagResp" name="responsavel_id">' + equipe + '</select></div>' +
      '</div>' +
      ((d.quadros || []).length > 1
        ? '<div class="ffag-campo"><label class="ffag-rotulo" for="ffagQuadro">Quadro da agenda</label><select id="ffagQuadro" name="board_id">' + quadros + '</select></div>'
        : '') +
      '<div class="ffag-erro" hidden></div>';

    j.pe.innerHTML =
      '<span class="ffag-pe-esq">' + ((d.quadros || []).length === 1 ? 'Vai para o quadro ' + esc(d.quadros[0].nome) : ((d.quadros || []).length ? '' : 'Cria o quadro Agenda comercial')) + '</span>' +
      '<button type="button" class="ffag-btn ffag-btn-leve" data-cancelar>Cancelar</button>' +
      '<button type="button" class="ffag-btn ffag-btn-principal" data-salvar>' + ico('calendario') + '<span>Agendar</span></button>';

    var c = j.corpo, tipo = 'ligacao';
    var data = c.querySelector('[name="data"]'), hora = c.querySelector('[name="hora"]');
    var assunto = c.querySelector('[name="assunto"]'), caixaMsg = c.querySelector('.ffag-msg');
    var msg = c.querySelector('[name="mensagem"]'), prog = c.querySelector('[name="programar"]');
    var erro = c.querySelector('.ffag-erro'), btn = j.pe.querySelector('[data-salvar]');

    function sugestao() {
      return { ligacao: 'Ligar para ', reuniao: 'Reunião com ', mensagem: 'Mensagem para ', tarefa: 'Retomar contato com ' }[tipo] + (d.nome || 'o lead');
    }
    function trocarTipo(t) {
      tipo = t;
      c.querySelectorAll('.ffag-tipo').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.tipo === t ? 'true' : 'false'); });
      caixaMsg.hidden = t !== 'mensagem';
      assunto.placeholder = sugestao();
      btn.querySelector('span').textContent = (t === 'mensagem' && prog && prog.checked && msg && msg.value.trim()) ? 'Programar mensagem' : 'Agendar';
    }
    trocarTipo('ligacao');

    c.querySelectorAll('.ffag-tipo').forEach(function (b) { b.addEventListener('click', function () { trocarTipo(b.dataset.tipo); }); });
    c.querySelectorAll('.ffag-atalho').forEach(function (b) {
      b.addEventListener('click', function () {
        var x = new Date();
        if (b.dataset.mais === 'h1') {
          x = new Date(Date.now() + 60 * 60000);
          x.setMinutes(Math.ceil(x.getMinutes() / 5) * 5, 0, 0);
          hora.value = isoHora(x);
        } else {
          x.setDate(x.getDate() + parseInt(b.dataset.mais.slice(1), 10));
          if (!hora.value) hora.value = '09:00';
        }
        data.value = isoData(x);
      });
    });
    if (msg) {
      var conta = c.querySelector('[data-conta]');
      var atual = function () { conta.textContent = msg.value.length; trocarTipo(tipo); };
      msg.addEventListener('input', atual);
      prog.addEventListener('change', function () { msg.disabled = !prog.checked; trocarTipo(tipo); });
    }
    j.pe.querySelector('[data-cancelar]').addEventListener('click', j.fechar);

    btn.addEventListener('click', function () {
      erro.hidden = true;
      if (!data.value || !hora.value) { mostrarErro('Informe a data e a hora.'); return; }
      var programa = tipo === 'mensagem' && prog && prog.checked && msg && msg.value.trim() !== '';
      var corpo = {
        acao: 'criar', card_id: cardId, tipo: tipo, quando: data.value + ' ' + hora.value,
        assunto: assunto.value.trim(), observacao: c.querySelector('[name="observacao"]').value.trim(),
        lembrete_min: parseInt(c.querySelector('[name="lembrete_min"]').value, 10),
        responsavel_id: parseInt(c.querySelector('[name="responsavel_id"]').value, 10) || 0,
        mensagem: programa ? msg.value.trim() : ''
      };
      var q = c.querySelector('[name="board_id"]');
      if (q) corpo.board_id = parseInt(q.value, 10);
      btn.disabled = true;
      pedir('POST', '', corpo).then(function (r) {
        btn.disabled = false;
        if (!r.ok) { mostrarErro(r.error || 'Não foi possível agendar.'); return; }
        j.fechar();
        aviso(programa ? 'Mensagem programada e agendada na agenda.' : 'Agendado. Já está na agenda de Tarefas.');
        if (aoSalvar) aoSalvar(r.data);
      });
    });
    function mostrarErro(t) { erro.textContent = t; erro.hidden = false; erro.scrollIntoView({ block: 'nearest' }); }
    setTimeout(function () { var b = c.querySelector('.ffag-tipo'); if (b) b.focus(); }, 30);
  }

  function fone(n) {
    var d = String(n || '').replace(/\D/g, '');
    if (d.length >= 12 && d.indexOf('55') === 0) d = d.slice(2);
    if (d.length === 11) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
    if (d.length === 10) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6);
    return n || '';
  }

  function aviso(texto) {
    if (typeof window.toast === 'function') { try { window.toast(texto, 'success'); return; } catch (e) {} }
    if (typeof window.showToast === 'function') { try { window.showToast(texto, 'success'); return; } catch (e) {} }
    var t = document.createElement('div');
    t.setAttribute('role', 'status');
    t.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:10060;background:#1F2937;color:#fff;' +
      'padding:10px 16px;border-radius:10px;font:600 13px Manrope,system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.2)';
    t.textContent = texto;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 3200);
  }

  /* ── Ficha do lead ───────────────────────────────────────────────────── */
  function montarNaFicha() {
    var F = window.FfFicha, shell = document.getElementById('modalEdit'), form = document.getElementById('editForm');
    if (!F || !shell || !form || !form.elements.id) return;
    var salvar = document.getElementById('saveCard');

    var bAg = document.createElement('button');
    bAg.type = 'button';
    bAg.className = 'btn soft';
    bAg.id = 'btnAgendarInteracao';
    bAg.textContent = 'Agendar próxima interação';
    if (salvar && salvar.parentNode) salvar.parentNode.insertBefore(bAg, salvar);
    F.botao(bAg, 'calendario', 'ff-btn-destaque');

    var sec = document.createElement('div');
    sec.className = 'form-section ffag-sec';
    sec.id = 'ffAgendaSection';
    sec.innerHTML = '<div class="ffag-lista"></div><div class="ffag-sec-pe"><a href="/tarefas.php">Ver agenda completa</a>' +
      '<button type="button" class="ffag-btn ffag-btn-leve" data-novo>' + ico('calendario') + '<span>Agendar</span></button></div>';
    var dir = form.querySelector('.ff-col-dir');
    if (dir) dir.insertBefore(sec, dir.firstChild);
    else { var corpo = form.querySelector('.modal-body'); if (corpo) corpo.appendChild(sec); }
    F.secao(sec, 0, 'Próxima interação', 'calendario', 'roxo');
    F.emAbas(sec, ['geral', 'comercial']);

    var lista = sec.querySelector('.ffag-lista');
    function nomeAtual() {
      return (form.cliente_nome && form.cliente_nome.value.trim()) || (form.empresa_nome && form.empresa_nome.value.trim()) || '';
    }
    function abrir() {
      var id = form.elements.id.value;
      if (!id) return;
      abrirAgendar(id, nomeAtual(), carregar);
    }
    bAg.addEventListener('click', abrir);
    sec.querySelector('[data-novo]').addEventListener('click', abrir);

    function carregar() {
      var id = form.elements.id.value;
      if (!id) { lista.innerHTML = ''; return; }
      lista.innerHTML = '<div class="ffag-vazio">Carregando…</div>';
      pedir('GET', '?card_id=' + encodeURIComponent(id)).then(function (r) {
        if (form.elements.id.value !== id) return;
        if (!r.ok) { lista.innerHTML = '<div class="ffag-vazio">' + esc(r.error || 'Não foi possível carregar.') + '</div>'; return; }
        desenhar(r.data.agendamentos || []);
      });
    }

    function desenhar(itens) {
      if (!itens.length) {
        lista.innerHTML = '<div class="ffag-vazio">Nada agendado com este lead. Agende a próxima interação para não perder o retorno.</div>';
        return;
      }
      lista.innerHTML = itens.slice(0, 6).map(function (a) {
        var ativa = a.tarefa_status === 'ativa', d = a.prazo ? paraData(a.prazo) : null;
        var pills = ['<span class="ffag-pill">' + ico(a.tipo) + esc(a.tipo_rotulo) + '</span>'];
        if (a.responsavel_nome) pills.push('<span>' + esc(a.responsavel_nome) + '</span>');
        if (a.atrasada) pills.push('<span class="ffag-pill ffag-pill-vermelho">Atrasada</span>');
        if (!ativa) pills.push('<span class="ffag-pill ffag-pill-verde">Feita</span>');
        var st = a.envio_status;
        if (st === 'pendente') pills.push('<span class="ffag-pill ffag-pill-azul">' + ico('enviar') + 'Mensagem programada</span>');
        if (st === 'enviada') pills.push('<span class="ffag-pill ffag-pill-verde">' + ico('ok') + 'Mensagem enviada</span>');
        if (st === 'enviando') pills.push('<span class="ffag-pill ffag-pill-azul">Enviando…</span>');
        if (st === 'falhou') pills.push('<span class="ffag-pill ffag-pill-ambar" title="' + esc(a.envio_erro || '') + '">Mensagem não saiu</span>');
        var acoes = '';
        if (st === 'falhou' && ativa) acoes += '<button type="button" class="ffag-mini" data-acao="reenviar" data-id="' + a.id + '" title="Mandar a mensagem de novo" aria-label="Mandar de novo">' + ico('refazer') + '</button>';
        if (ativa) acoes += '<button type="button" class="ffag-mini ffag-mini-ok" data-acao="concluir" data-id="' + a.id + '" title="Marcar como feita" aria-label="Marcar como feita">' + ico('ok') + '</button>' +
          '<button type="button" class="ffag-mini ffag-mini-x" data-acao="cancelar" data-id="' + a.id + '" title="Desmarcar" aria-label="Desmarcar">' + ico('fechar') + '</button>';
        return '<div class="ffag-item' + (ativa ? '' : ' ffag-feito') + (a.atrasada ? ' ffag-atrasada' : '') + '">' +
          '<div class="ffag-quando"><span>' + (d ? esc(quandoCurto(a.prazo)) : '') + '</span><b>' + (d ? isoHora(d) : '--') + '</b></div>' +
          '<div><div class="ffag-item-tit">' + esc(a.titulo) + '</div><div class="ffag-item-sub">' + pills.join('') + '</div>' +
            (st === 'falhou' && a.envio_erro ? '<div class="ffag-item-sub">' + esc(a.envio_erro) + '</div>' : '') + '</div>' +
          '<div class="ffag-acoes">' + acoes + '</div></div>';
      }).join('');
    }

    lista.addEventListener('click', function (e) {
      var b = e.target.closest('[data-acao]');
      if (!b) return;
      var acao = b.dataset.acao;
      if (acao === 'cancelar' && !window.confirm('Desmarcar este agendamento? A tarefa sai da agenda e a mensagem programada não é enviada.')) return;
      b.disabled = true;
      pedir('POST', '', { acao: acao, id: parseInt(b.dataset.id, 10) }).then(function (r) {
        if (!r.ok) { b.disabled = false; window.alert(r.error || 'Não foi possível concluir.'); return; }
        aviso({ cancelar: 'Agendamento desmarcado.', concluir: 'Marcado como feito.', reenviar: 'A mensagem sai no próximo minuto.' }[acao]);
        carregar();
      });
    });

    F.aoAbrir(shell, carregar);
  }

  /* ── Sua agenda de hoje (ao entrar) ──────────────────────────────────── */
  function agendaDoDia() {
    if (!CFG.sessao) return;
    var chave = 'ffag_dia_visto';
    var marca = CFG.sessao + ':' + hojeIso();
    try { if (localStorage.getItem(chave) === marca) return; } catch (e) {}

    pedir('GET', '?hoje=1').then(function (r) {
      if (!r.ok || !r.data || !r.data.length) return;
      try { localStorage.setItem(chave, marca); } catch (e) {}
      var itens = r.data;
      var atras = itens.filter(function (i) { return i.atrasada && !i.de_hoje; });
      var hoje = itens.filter(function (i) { return i.de_hoje; });
      var progs = itens.filter(function (i) { return i.mensagem_programada; }).length;
      var agora = new Date();
      var dataLonga = agora.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' });

      var j = janela({ titulo: 'Sua agenda de hoje', sub: dataLonga.charAt(0).toUpperCase() + dataLonga.slice(1), icone: 'sino', largo: true });
      var resumo = ['<span class="ffag-pill ffag-pill-azul">' + hoje.length + (hoje.length === 1 ? ' para hoje' : ' para hoje') + '</span>'];
      if (atras.length) resumo.push('<span class="ffag-pill ffag-pill-vermelho">' + atras.length + (atras.length === 1 ? ' atrasada' : ' atrasadas') + '</span>');
      if (progs) resumo.push('<span class="ffag-pill">' + ico('enviar') + progs + (progs === 1 ? ' mensagem programada' : ' mensagens programadas') + '</span>');

      function linha(i) {
        var d = paraData(i.prazo);
        var horaTxt = i.de_hoje ? i.hora : dois(d.getDate()) + '/' + dois(d.getMonth() + 1) + '<br>' + i.hora;
        var sub = [esc(i.tipo_rotulo), esc(i.quadro)];
        var extras = '';
        if (i.atrasada) extras += '<span class="ffag-pill ffag-pill-vermelho">Atrasada</span>';
        if (i.mensagem_programada) extras += '<span class="ffag-pill ffag-pill-azul">' + ico('enviar') + 'Sai sozinha</span>';
        return '<a class="ffag-dia-item' + (i.atrasada ? ' ffag-atrasada' : '') + '" href="' + esc(i.url) + '">' +
          '<span class="ffag-dia-hora">' + horaTxt + '</span>' +
          '<span><span class="ffag-dia-tit">' + esc(i.titulo) + '</span><span class="ffag-dia-sub">' + sub.join(' · ') + extras + '</span></span>' +
          '<span class="ffag-dia-seta">' + ico('seta') + '</span></a>';
      }

      j.corpo.innerHTML = '<div class="ffag-resumo">' + resumo.join('') + '</div><div class="ffag-dia">' +
        (atras.length ? '<div class="ffag-dia-grupo">Atrasadas</div>' + atras.map(linha).join('') : '') +
        (hoje.length ? '<div class="ffag-dia-grupo">Hoje</div>' + hoje.map(linha).join('') : '<div class="ffag-vazio">Nada marcado para hoje.</div>') +
        '</div>';
      j.pe.innerHTML = '<button type="button" class="ffag-btn ffag-btn-leve" data-fechar>Fechar</button>' +
        '<a class="ffag-btn ffag-btn-principal" href="/tarefas.php" style="text-decoration:none">' + ico('calendario') + '<span>Abrir agenda</span></a>';
      j.pe.querySelector('[data-fechar]').addEventListener('click', j.fechar);
    });
  }

  window.FfAgenda = { abrir: abrirAgendar };

  function iniciar() {
    montarNaFicha();
    agendaDoDia();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
