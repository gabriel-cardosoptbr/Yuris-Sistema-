/**
 * ff-conversa.js — o WhatsApp do lead dentro da ficha do card (edição CRM, 07/10/2026).
 *
 * Pedido: "todo contato do WhatsApp salvo no card, com o processo em que está, quem
 * está atendendo, qual WhatsApp, número, vínculo, empresa... e a conversa", para o card
 * servir de base quando um número cair. Tudo vem de /api/whatsapp/card_whatsapp.php, que
 * só LÊ o que o Yuris já guarda (não chama a Evolution): funciona com o número
 * desconectado.
 *
 * Como encaixa: a ficha (prospeccao.php) redesenha `#chatVinculoCard` toda vez que abre
 * um card; este script observa esse elemento e, a cada redesenho, lê o id do card em
 * `#editForm input[name="id"]` e desenha o painel logo abaixo. Nada do formulário muda.
 * Só a edição CRM carrega este arquivo. O estilo usa as variáveis da ficha (--ff-*), que
 * já têm o tema escuro.
 */
(function () {
  'use strict';
  var alvo = document.getElementById('chatVinculoCard');
  if (!alvo) return;

  var esc = function (s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); };

  // ── estilo (uma vez) ───────────────────────────────────────────────────────
  var css = document.createElement('style');
  css.textContent =
    '.ffw{margin-top:14px;display:flex;flex-direction:column;gap:12px;color:var(--ff-texto,#1F2937)}' +
    '.ffw-aviso{font-size:12.5px;line-height:1.5;padding:9px 12px;border-radius:10px;border:1px solid rgba(245,158,11,.45);background:rgba(245,158,11,.12);color:var(--ff-texto-2,#3D3D3D)}' +
    '.ffw-grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1px;background:var(--ff-borda,rgba(17,29,45,.08));border:1px solid var(--ff-borda,rgba(17,29,45,.08));border-radius:12px;overflow:hidden}' +
    '.ffw-item{background:var(--ff-card,#fff);padding:9px 12px;display:flex;flex-direction:column;gap:3px;min-width:0}' +
    '.ffw-item span{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ff-texto-4,#767676)}' +
    '.ffw-item b{font-size:13.5px;font-weight:600;color:var(--ff-texto,#1F2937);overflow-wrap:anywhere;line-height:1.35}' +
    '.ffw-item small{font-size:12px;color:var(--ff-texto-4,#767676);overflow-wrap:anywhere}' +
    '.ffw-pilula{display:inline-block;align-self:flex-start;font-size:12px;font-weight:600;padding:2px 10px;border-radius:999px;border:1px solid transparent;color:var(--ff-texto,#1F2937)}' +
    '.ffw-ponto{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:middle}' +
    '.ffw-link{align-self:flex-start;font-size:12.5px;font-weight:600;color:var(--ff-marca-forte,var(--ff-marca,#015DFC));text-decoration:none}' +
    '.ffw-link:hover{text-decoration:underline}' +
    'html:not([data-theme="light"]) .ffw-link{color:var(--ff-marca-clara,#8FB4FF)}' +
    '.ffw-titulo{font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ff-texto-4,#767676);margin:2px 0 -4px}' +
    '.ffw-conversa{display:flex;flex-direction:column;gap:6px;max-height:420px;overflow-y:auto;padding:12px;border:1px solid var(--ff-borda,rgba(17,29,45,.08));border-radius:12px;background:var(--ff-fundo,#F4F6F9)}' +
    '.ffw-msg{max-width:82%;padding:7px 11px;border-radius:12px;font-size:13px;line-height:1.45;overflow-wrap:anywhere;white-space:pre-wrap;background:var(--ff-card,#fff);border:1px solid var(--ff-borda,rgba(17,29,45,.08));color:var(--ff-texto,#1F2937);align-self:flex-start}' +
    '.ffw-msg.nos{align-self:flex-end;background:rgba(var(--ff-marca-rgb,1,93,252),.12);border-color:rgba(var(--ff-marca-rgb,1,93,252),.28)}' +
    '.ffw-msg i{display:block;font-style:normal;font-size:11px;margin-top:3px;color:var(--ff-texto-4,#767676);white-space:normal}' +
    '.ffw-tipo{font-weight:600;color:var(--ff-texto-3,#575757)}' +
    '.ffw-vazio{font-size:13px;color:var(--ff-texto-3,#575757);line-height:1.5}';
  document.head.appendChild(css);

  // ── formatação ─────────────────────────────────────────────────────────────
  function fone(d) {
    d = String(d || '').replace(/\D/g, '');
    if (d.length === 13) return '+' + d.slice(0, 2) + ' (' + d.slice(2, 4) + ') ' + d.slice(4, 9) + '-' + d.slice(9);
    if (d.length === 12) return '+' + d.slice(0, 2) + ' (' + d.slice(2, 4) + ') ' + d.slice(4, 8) + '-' + d.slice(8);
    return d;
  }
  function quando(s) { // o banco grava em UTC; a tela mostra em Brasília
    if (!s) return '';
    var d = new Date(String(s).replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return String(s);
    return d.toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  }
  var TIPOS = { imageMessage: 'Imagem', image: 'Imagem', audioMessage: 'Áudio', audio: 'Áudio', pttMessage: 'Áudio', videoMessage: 'Vídeo', video: 'Vídeo',
    documentMessage: 'Documento', document: 'Documento', documentWithCaptionMessage: 'Documento', stickerMessage: 'Figurinha', sticker: 'Figurinha',
    contactMessage: 'Contato', contact: 'Contato', contactsArrayMessage: 'Contatos', locationMessage: 'Localização', location: 'Localização' };
  function corTranslucida(hex) { return /^#[0-9a-f]{6}$/i.test(hex || '') ? hex : '#6B7887'; }

  function item(rotulo, valor, extra) {
    return '<div class="ffw-item"><span>' + esc(rotulo) + '</span>' + valor + (extra || '') + '</div>';
  }
  function texto(v) { return v ? '<b>' + esc(v) + '</b>' : '<b style="font-weight:500;color:var(--ff-texto-4,#767676)">Não informado</b>'; }

  function desenhar(d) {
    var at = d.atendimento || {}, ag = at.agente, n = (d.numeros || [])[0];
    var html = '';
    if (n && n.status !== 'open') {
      html += '<div class="ffw-aviso">O número <b>' + esc(n.nome) + '</b> está desconectado. O que aparece abaixo é o histórico que o sistema já tinha salvo desta conversa.</div>';
    }
    var cor = d.etapa ? corTranslucida(d.etapa.cor) : null;
    var etapa = d.etapa
      ? '<b class="ffw-pilula" style="background:' + cor + '38;border-color:' + cor + '">' + esc(d.etapa.nome) + '</b>'
      : '<b style="font-weight:500;color:var(--ff-texto-4,#767676)">Sem etapa</b>';
    var setor = d.setor
      ? '<b><i class="ffw-ponto" style="background:' + corTranslucida(d.setor.cor) + '"></i>' + esc(d.setor.nome) + '</b>'
      : '<b style="font-weight:500;color:var(--ff-texto-4,#767676)">Sem setor</b>';
    var chip = n
      ? '<b>' + esc(n.nome) + '</b><small>' + esc(fone(n.telefone)) + ' · <i class="ffw-ponto" style="margin:0 4px 0 0;background:' + (n.status === 'open' ? '#22C55E' : '#EF4444') + '"></i>' + (n.status === 'open' ? 'conectado' : 'desconectado') + '</small>'
        + (n.dono ? '<small>Número de ' + esc(n.dono) + '</small>' : '')
      : '<b style="font-weight:500;color:var(--ff-texto-4,#767676)">Nenhuma conversa ligada</b>';
    var extraAt = [];
    if (at.responsavel && String(at.quem || '').indexOf(at.responsavel) < 0) extraAt.push('Responsável do card: ' + esc(at.responsavel));
    if (ag && ag.nome) extraAt.push(esc(ag.nome) + (ag.ligado ? (ag.pausado ? ': pausada nesta conversa' : ': ligada') : ': desligada neste número'));
    var vinculo = n
      ? '<b>Conversa ligada</b><a class="ffw-link" href="/chat.php?jid=' + encodeURIComponent(n.jid) + '">Abrir no Chat</a>'
      : '<b style="font-weight:500;color:var(--ff-texto-4,#767676)">Sem conversa ligada</b>';
    var outros = (d.numeros || []).length > 1
      ? '<small>Também falou por: ' + d.numeros.slice(1).map(function (x) { return esc(x.nome); }).join(', ') + '</small>' : '';

    html += '<div class="ffw-grade">' +
      item('Processo (etapa)', etapa) +
      item('Quem está atendendo', texto(at.quem), extraAt.length ? '<small>' + extraAt.join('<br>') + '</small>' : '') +
      item('WhatsApp nosso', chip, outros) +
      item('Telefone do lead', '<b>' + esc(fone(d.card.telefone)) + '</b>') +
      item('Vínculo', vinculo) +
      item('Setor', setor) +
      item('Empresa', texto(d.card.empresa || d.card.cliente)) +
      item('Tipo', texto(d.card.tipo)) +
      (d.card.cidade ? item('Cidade', texto(d.card.cidade)) : '') +
      '</div>';

    html += '<div class="ffw-titulo">Conversa' + (d.total_mensagens ? ' (' + d.total_mensagens + (d.total_mensagens === 1 ? ' mensagem' : ' mensagens') + (d.total_mensagens > d.mensagens.length ? ', as ' + d.mensagens.length + ' mais recentes' : '') + ')' : '') + '</div>';
    if (!d.mensagens.length) {
      html += '<div class="ffw-vazio">Ainda não há mensagens salvas desta conversa.</div>';
    } else {
      html += '<div class="ffw-conversa" id="ffwMsgs">' + d.mensagens.map(function (m) {
        var rot = TIPOS[m.tipo] ? '<span class="ffw-tipo">[' + TIPOS[m.tipo] + (m.arquivo ? ': ' + esc(m.arquivo) : '') + ']</span>' + (m.texto ? ' ' : '') : '';
        var corpo = m.texto ? esc(m.texto) : (rot ? '' : '<span class="ffw-tipo">[mensagem sem texto]</span>');
        return '<div class="ffw-msg ' + (m.de === 'nos' ? 'nos' : 'lead') + '">' + rot + corpo + '<i>' + (m.de === 'nos' ? 'Nós' : 'Lead') + ' · ' + esc(quando(m.quando)) + '</i></div>';
      }).join('') + '</div>';
    }
    return html;
  }

  // ── carga ──────────────────────────────────────────────────────────────────
  var painel = document.createElement('div');
  painel.className = 'ffw';
  painel.id = 'ffConversa';
  alvo.parentNode.insertBefore(painel, alvo.nextSibling);
  var ultimo = null, ticket = 0;

  function carregar() {
    var campo = document.querySelector('#editForm input[name="id"]');
    var id = campo ? parseInt(campo.value, 10) : 0;
    if (!id) { painel.innerHTML = ''; ultimo = null; return; }
    var meu = ++ticket;
    painel.innerHTML = '<div class="ffw-vazio">Carregando o WhatsApp deste lead…</div>';
    fetch('/api/whatsapp/card_whatsapp.php?card_id=' + id, { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (meu !== ticket) return; // outro card abriu enquanto esperava
        if (!x.ok || !x.j.ok) { painel.innerHTML = '<div class="ffw-vazio">Não foi possível carregar o WhatsApp deste lead.</div>'; return; }
        painel.innerHTML = desenhar(x.j);
        var c = document.getElementById('ffwMsgs');
        if (c) c.scrollTop = c.scrollHeight;
      })
      .catch(function () { if (meu === ticket) painel.innerHTML = '<div class="ffw-vazio">Não foi possível carregar o WhatsApp deste lead.</div>'; });
  }

  // Cada redesenho de #chatVinculoCard = um card foi aberto.
  new MutationObserver(function () { carregar(); }).observe(alvo, { childList: true });
})();
