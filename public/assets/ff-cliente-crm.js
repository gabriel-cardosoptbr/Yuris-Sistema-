/* ═══════════════════════════════════════════════════════════════════════
   ff-cliente-crm.js: seções da edição CRM na ficha do cliente (Clientes),
   encaixadas sem mexer em clientes.php:
     - "Financeiro": os valores combinados (mensal, anual, serviço avulso, taxa
       fixa, por demanda), cada um com a data em que começa e, se quiser, o
       contrato a que se refere; embaixo, as cobranças que saem deles (em
       aberto, atrasada, paga) com "Marcar como paga".
       API /api/cliente_valores.php (App\Clientes\ValoresDoCliente e
       App\Clientes\CobrancaDoCliente).
     - "Contratos": os contratos do cliente, "Novo contrato" já ligado a ele e
       "Vincular contrato existente" (os que ainda estão sem cliente).
     - "Setores": a que setores da conta o cliente pertence (mais de um).
   No quadro de Clientes, o miolo de cada card é trocado pelo card da edição
   CRM (dinheiro, situação das cobranças, setores, contratos, contato,
   responsável, WhatsApp; GET /api/cliente_valores.php?quadro=1), e o quadro
   pode ser visto por situação (as colunas de sempre) ou por setor (o cliente
   em cada setor dele), sem mexer no renderCard.
   Carregado pelo sidebar.php em conta CRM; só age se a página tem a ficha.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var REAL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  var TIPOS = { mensal: 'Mensal', anual: 'Anual', servico: 'Serviço avulso', fixo: 'Taxa fixa / única', demanda: 'Por demanda' };
  var FORMAS = { pix: 'Pix', boleto: 'Boleto', cartao: 'Cartão', transferencia: 'Transferência', dinheiro: 'Dinheiro', outro: 'Outro' };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function dataBr(d) { return d ? String(d).slice(0, 10).split('-').reverse().join('/') : ''; }
  function hojeIso() { var d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); }
  function csrf() { return (window.FF_AGENDA && window.FF_AGENDA.csrf) || (window.YURIS_NOTIF && window.YURIS_NOTIF.csrf) || ''; }

  var CSS = '' +
    '.ffcli-resumo{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}' +
    '.ffcli-tot{border:1px solid rgba(17,29,45,.10);border-radius:10px;padding:8px 12px;min-width:120px}' +
    '.ffcli-tot span{display:block;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#767676}' +
    '.ffcli-tot b{display:block;font-size:16px;font-weight:800;color:var(--ff-marca-forte,#013DF2)}' +
    '.ffcli-tot.princ{background:rgba(var(--ff-marca-rgb,1,93,252),.07);border-color:rgba(var(--ff-marca-rgb,1,93,252),.25)}' +
    '.ffcli-tot.alerta b{color:#B91C1C}.ffcli-tot.ok b{color:#047857}' +
    '.ffcli-item{display:grid;grid-template-columns:1fr auto;gap:4px 12px;padding:9px 0;border-top:1px solid rgba(17,29,45,.07);align-items:center}' +
    '.ffcli-item.encerrado{opacity:.55}' +
    '.ffcli-item .tit{font-size:13px;font-weight:700;color:#1F2937}' +
    '.ffcli-item .val{font-size:14px;font-weight:800;color:#1F2937;text-align:right;white-space:nowrap}' +
    '.ffcli-item .sub{font-size:11.5px;color:#767676}' +
    '.ffcli-item .acoes{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}' +
    '.ffcli-pill{display:inline-block;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:99px;margin-right:6px;background:rgba(var(--ff-marca-rgb,1,93,252),.10);color:var(--ff-marca-forte,#013DF2)}' +
    '.ffcli-pill.fixo{background:#FFF4D6;color:#B45309}.ffcli-pill.demanda{background:#EEF0F3;color:#575757}.ffcli-pill.anual{background:#EFEAFE;color:#6D4AFF}.ffcli-pill.servico{background:#E0F2FE;color:#0369A1}' +
    '.ffcli-pill.aberta{background:#EEF0F3;color:#374151}.ffcli-pill.atrasada{background:#FEE2E2;color:#B91C1C}.ffcli-pill.paga{background:#D1FAE5;color:#047857}' +
    '.ffcli-link{background:none;border:0;padding:0;font:700 12px Manrope,system-ui,sans-serif;color:var(--ff-marca,#015DFC);cursor:pointer}' +
    '.ffcli-link.perigo{color:#B91C1C}' +
    '.ffcli-form{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-top:10px;padding:12px;border:1px dashed rgba(17,29,45,.18);border-radius:10px}' +
    '.ffcli-form label{display:block;font-size:11px;font-weight:700;color:#575757;margin-bottom:3px}' +
    '.ffcli-form input,.ffcli-form select{width:100%;padding:8px 10px;border:1px solid rgba(17,29,45,.16);border-radius:9px;font:500 13px Manrope,system-ui,sans-serif;background:#fff;color:#1F2937}' +
    '.ffcli-form .c2{grid-column:span 2}.ffcli-form .c3{grid-column:span 3}.ffcli-form .c6{grid-column:1/-1}' +
    '.ffcli-form .bts{grid-column:1/-1;display:flex;gap:8px;justify-content:flex-end}' +
    '.ffcli-form .dica{font-size:11px;color:#767676;margin-top:3px}' +
    '.ffcli-bt{padding:8px 14px;border-radius:9px;border:1px solid rgba(17,29,45,.16);background:#fff;font:700 12.5px Manrope,system-ui,sans-serif;cursor:pointer;color:#1F2937}' +
    '.ffcli-bt.princ{background:var(--ff-marca,#015DFC);border-color:var(--ff-marca,#015DFC);color:var(--ff-marca-texto,#fff)}' +
    '.ffcli-erro{grid-column:1/-1;color:#B91C1C;font-size:12px;min-height:0}' +
    '.ffcli-vazio{font-size:12.5px;color:#767676;padding:4px 0}' +
    '.ffcli-pe{display:flex;justify-content:flex-end;gap:14px;margin-top:8px;flex-wrap:wrap}' +
    '.ffcli-sub{display:flex;justify-content:space-between;align-items:baseline;margin:16px 0 4px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#575757}' +
    '.ffcli-ctr{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid rgba(17,29,45,.07);text-decoration:none;color:inherit;font-size:13px}' +
    '.ffcli-ctr span:last-child{color:#767676;white-space:nowrap}' +
    '.ffcli-vinc{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}' +
    '.ffcli-vinc select{flex:1;min-width:180px;padding:8px 10px;border:1px solid rgba(17,29,45,.16);border-radius:9px;font:500 13px Manrope,system-ui,sans-serif;background:#fff;color:#1F2937}' +
    '.ffcli-pill.cancelada{background:#F3F4F6;color:#6B7280}' +
    '.ffcli-item.cancelada .tit,.ffcli-item.cancelada .val{color:#9CA3AF;text-decoration:line-through}' +
    '.ffcli-setores{display:flex;flex-wrap:wrap;gap:6px}' +
    '.ffcli-setopt{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:99px;border:1px solid rgba(17,29,45,.16);background:#fff;font:600 12.5px Manrope,system-ui,sans-serif;color:#374151;cursor:pointer}' +
    '.ffcli-setopt i{width:8px;height:8px;border-radius:50%;display:inline-block}' +
    '.ffcli-setopt.on{background:rgba(var(--ff-marca-rgb,1,93,252),.10);border-color:rgba(var(--ff-marca-rgb,1,93,252),.45);color:var(--ff-marca-forte,#013DF2)}' +
    '@media (max-width:700px){.ffcli-form{grid-template-columns:1fr 1fr}.ffcli-form .c2,.ffcli-form .c3{grid-column:span 1}}';

  // ── Quadro de Clientes: o card redesenhado e a visão por setor ───────
  // O renderCard de clientes.php continua montando o <article> (arrastar, abrir a
  // ficha); aqui o miolo é trocado pelo card da edição CRM, com os dados de
  // GET /api/cliente_valores.php?quadro=1 (App\Clientes\CardDoCliente).
  var QUADRO = null, SETORES_CONTA = [], pedindoQuadro = null, VISTA = 'situacao';
  try { VISTA = localStorage.getItem('ffcli_vista') === 'setor' ? 'setor' : 'situacao'; } catch (e) {}
  var CSS_QUADRO = '' +
    '.cli-card.ffc-card{padding:0 !important;overflow:hidden;border-radius:14px !important}' +
    '.ffc{padding:13px 14px 12px;display:flex;flex-direction:column;gap:10px;font-family:Manrope,system-ui,sans-serif}' +
    '.ffc-topo{display:flex;align-items:center;gap:10px}' +
    '.ffc-av{width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13.5px;color:#fff;flex-shrink:0;letter-spacing:.02em}' +
    '.ffc-id{min-width:0;flex:1}' +
    '.ffc-nome{font-size:14.5px;font-weight:800;color:var(--ffc-tx,#111827);line-height:1.25;overflow-wrap:anywhere}' +
    '.ffc-sub{font-size:11.5px;color:var(--ffc-mu,#6B7280);margin-top:2px;line-height:1.35}' +
    '.ffc-zap{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:#E8F8EE;color:#16A34A;flex-shrink:0;text-decoration:none}' +
    '.ffc-zap:hover{background:#D1F2DE}' +
    '.ffc-zap svg{width:16px;height:16px}' +
    '.ffc-tags{display:flex;flex-wrap:wrap;gap:5px}' +
    '.ffc-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:99px;background:var(--ffc-bg2,#F3F4F6);color:var(--ffc-tx2,#374151)}' +
    '.ffc-tag i{width:7px;height:7px;border-radius:50%}' +
    '.ffc-tag.sit{background:transparent;border:1px solid var(--ffc-bd,#E5E7EB)}' +
    '.ffc-tag.alerta{background:#FEF2F2;color:#B91C1C}' +
    '.ffc-din{border-radius:11px;padding:10px 11px;background:var(--ffc-bg3,#F8FAFC);border:1px solid var(--ffc-bd,#EEF0F3)}' +
    '.ffc-valor{font-size:17px;font-weight:800;color:var(--ffc-tx,#111827);letter-spacing:-.01em}' +
    '.ffc-valor small{font-size:11.5px;font-weight:600;color:var(--ffc-mu,#6B7280);margin-left:2px}' +
    '.ffc-extra{font-size:11.5px;color:var(--ffc-mu,#6B7280);margin-top:1px}' +
    '.ffc-sit{display:flex;align-items:center;gap:6px;margin-top:7px;font-size:11.5px;font-weight:700}' +
    '.ffc-sit i{width:7px;height:7px;border-radius:50%;flex-shrink:0}' +
    '.ffc-sit.atraso{color:#B91C1C}.ffc-sit.atraso i{background:#EF4444}' +
    '.ffc-sit.prox{color:var(--ffc-tx2,#374151)}.ffc-sit.prox i{background:#F59E0B}' +
    '.ffc-sit.ok{color:#047857}.ffc-sit.ok i{background:#10B981}' +
    '.ffc-din.vazio{font-size:12px;color:var(--ffc-mu,#6B7280);background:transparent;border-style:dashed}' +
    '.ffc-pe{display:flex;flex-wrap:wrap;gap:6px 12px;padding-top:9px;border-top:1px solid var(--ffc-bd,#F0F1F3)}' +
    '.ffc-meta{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;color:var(--ffc-mu,#6B7280);font-weight:600}' +
    '.ffc-meta svg{width:13px;height:13px;flex-shrink:0;opacity:.8}' +
    '.ffc-meta.forte{color:var(--ffc-tx2,#374151)}' +
    'html[data-theme="dark"] .ffc{--ffc-tx:#F3F4F6;--ffc-tx2:#E5E7EB;--ffc-mu:#9CA3AF;--ffc-bg2:rgba(255,255,255,.08);--ffc-bg3:rgba(255,255,255,.04);--ffc-bd:rgba(255,255,255,.10)}' +
    '.ffq-vista{display:flex;align-items:center;gap:6px;margin:0 0 10px;font:600 12.5px Manrope,system-ui,sans-serif;color:#6B7280;flex-wrap:wrap}' +
    '.ffq-vista button{padding:6px 13px;border-radius:99px;border:1px solid rgba(17,29,45,.14);background:#fff;font:700 12.5px Manrope,system-ui,sans-serif;color:#374151;cursor:pointer}' +
    '.ffq-vista button.on{background:var(--ff-marca,#015DFC);border-color:var(--ff-marca,#015DFC);color:var(--ff-marca-texto,#fff)}' +
    '.ffq-vista small{font-weight:500;color:#9CA3AF}';

  var ICO = {
    zap: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.3-.5.1-1 .1-1.7-.1-.4-.1-.9-.3-1.5-.6-2.6-1.1-4.3-3.7-4.4-3.9-.1-.2-1-1.4-1-2.6s.6-1.9.9-2.1c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 1.9c.1.1.1.3 0 .5l-.3.5-.4.4c-.1.1-.3.3-.1.6.2.3.7 1.2 1.6 2 1.1 1 2 1.3 2.3 1.4.3.1.5.1.6-.1l.8-1c.2-.3.4-.2.6-.1l1.8.9c.3.1.5.2.5.3.1.2.1.7-.1 1.3Z"/></svg>',
    resp: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>',
    doc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>',
    fala: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12Z"/></svg>'
  };
  var PALETA = ['#2563EB', '#7C3AED', '#DB2777', '#EA580C', '#0D9488', '#16A34A', '#0891B2', '#9333EA', '#C2410C', '#4F46E5'];
  function corSegura(c) { return /^#[0-9a-fA-F]{3,8}$/.test(c || '') ? c : '#3B82F6'; }
  function corDoNome(n) { var h = 0; for (var i = 0; i < n.length; i++) h = (h * 31 + n.charCodeAt(i)) >>> 0; return PALETA[h % PALETA.length]; }
  function iniciais(n) { var p = String(n || '?').trim().split(/\s+/); return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : (p[0][1] || ''))).toUpperCase(); }
  function haQuanto(dias) {
    if (dias == null) return '';
    if (dias < 1) return 'desde hoje';
    if (dias < 30) return 'há ' + dias + (dias === 1 ? ' dia' : ' dias');
    var m = Math.floor(dias / 30);
    if (m < 12) return 'há ' + m + (m === 1 ? ' mês' : ' meses');
    var a = Math.floor(m / 12);
    return 'há ' + a + (a === 1 ? ' ano' : ' anos');
  }
  function diasDesde(iso) { if (!iso) return null; var d = new Date(String(iso).replace(' ', 'T')); return isNaN(d) ? null : Math.max(0, Math.floor((Date.now() - d.getTime()) / 86400000)); }
  function dataCurta(iso) { var p = String(iso || '').slice(0, 10).split('-'); return p.length === 3 ? p[2] + '/' + p[1] : ''; }

  function htmlCard(d, origem) {
    var sub = [];
    if (d.tipo) sub.push(d.tipo === 'PF' ? 'Pessoa física' : d.tipo === 'PJ' ? 'Empresa' : d.tipo);
    if (d.cidade) sub.push(d.cidade);
    if (d.dias_cliente != null) sub.push('cliente ' + haQuanto(d.dias_cliente));
    var h = '<div class="ffc"><div class="ffc-topo"><span class="ffc-av" style="background:' + corDoNome(d.nome || '') + '">' + esc(iniciais(d.nome)) + '</span>' +
      '<div class="ffc-id"><div class="ffc-nome">' + esc(d.nome) + '</div>' + (sub.length ? '<div class="ffc-sub">' + esc(sub.join(' · ')) + '</div>' : '') + '</div>' +
      (d.whatsapp ? '<a class="ffc-zap" data-ffc-zap href="/chat.php?jid=' + encodeURIComponent((d.whatsapp.length <= 11 ? '55' + d.whatsapp : d.whatsapp) + '@s.whatsapp.net') + '" title="Abrir conversa no WhatsApp" aria-label="Abrir conversa no WhatsApp">' + ICO.zap + '</a>' : '') +
      '</div>';
    var tags = (d.setores || []).map(function (s) { return '<span class="ffc-tag"><i style="background:' + corSegura(s.cor) + '"></i>' + esc(s.nome) + '</span>'; });
    if (d.status && d.status !== 'ativo') tags.push('<span class="ffc-tag alerta">' + esc(d.status) + '</span>');
    if (origem) tags.push('<span class="ffc-tag sit">' + esc(origem) + '</span>');
    if (tags.length) h += '<div class="ffc-tags" data-ffc-tags>' + tags.join('') + '</div>';
    if (d.por_mes || d.avulso || d.atrasado || d.proxima) {
      h += '<div class="ffc-din">' +
        (d.por_mes ? '<div class="ffc-valor">' + REAL.format(d.por_mes) + '<small>/mês</small></div>' : (d.avulso ? '<div class="ffc-valor">' + REAL.format(d.avulso) + '<small>em serviços</small></div>' : '')) +
        (d.por_mes && d.avulso ? '<div class="ffc-extra">+ ' + REAL.format(d.avulso) + ' em serviços e taxas</div>' : '');
      if (d.atrasado) h += '<div class="ffc-sit atraso"><i></i>' + REAL.format(d.atrasado) + ' em atraso' + (d.atrasadas > 1 ? ' (' + d.atrasadas + ' cobranças)' : '') + '</div>';
      else if (d.proxima) h += '<div class="ffc-sit prox"><i></i>Próxima cobrança ' + dataCurta(d.proxima.vencimento) + ' · ' + REAL.format(d.proxima.valor) + '</div>';
      else h += '<div class="ffc-sit ok"><i></i>Nada em aberto' + (d.recebido ? ' · ' + REAL.format(d.recebido) + ' recebidos' : '') + '</div>';
      h += '</div>';
    } else {
      h += '<div class="ffc-din vazio">Sem valor combinado. Abra o cliente para cadastrar a mensalidade ou um serviço.</div>';
    }
    var pe = [];
    pe.push('<span class="ffc-meta' + (d.responsavel ? ' forte' : '') + '" title="Responsável">' + ICO.resp + esc(d.responsavel || 'Sem responsável') + '</span>');
    var k = d.contratos || {};
    if (k.total) pe.push('<span class="ffc-meta forte" title="Contratos">' + ICO.doc + k.total + (k.total === 1 ? ' contrato' : ' contratos') +
      (k.aguardando ? ' · ' + k.aguardando + ' aguardando assinatura' : (k.assinados ? ' · ' + k.assinados + (k.assinados === 1 ? ' assinado' : ' assinados') : '')) + '</span>');
    var dc = diasDesde(d.ultimo_contato);
    pe.push('<span class="ffc-meta" title="Último contato registrado">' + ICO.fala + (dc == null ? 'Sem contato registrado' : 'Contato ' + (dc === 0 ? 'hoje' : haQuanto(dc))) + '</span>');
    h += '<div class="ffc-pe">' + pe.join('') + '</div></div>';
    return h;
  }

  function decorarCard(card) {
    var id = +card.getAttribute('data-cli-id'), d = QUADRO && QUADRO[id];
    if (!d) return;
    // Cliente de outra conta (filial): guarda o nome da origem que vinha na faixa.
    var org = card.querySelector('.org-name');
    var origem = d.outra_conta && org ? org.textContent.trim() : '';
    card.innerHTML = htmlCard(d, origem);
    card.classList.add('ffc-card');
    card.setAttribute('data-ffc', '1');
    var z = card.querySelector('[data-ffc-zap]');
    if (z) z.addEventListener('click', function (e) { e.stopPropagation(); });
  }
  function decorarQuadro(forcar) {
    document.querySelectorAll('#board .cli-card[data-cli-id]').forEach(function (c) { if (forcar || c.getAttribute('data-ffc') !== '1') decorarCard(c); });
    agendarVistaSetor();
  }

  // ── Visão por setor: uma coluna por setor, o cliente em cada setor dele ──
  var vistaTimer = null;
  function agendarVistaSetor() { clearTimeout(vistaTimer); vistaTimer = setTimeout(desenharVistaSetor, 60); }
  function desenharVistaSetor() {
    var board = document.getElementById('board');
    if (!board) return;
    var alvo = document.getElementById('ffBoardSetor');
    if (VISTA !== 'setor' || !QUADRO) { board.style.display = ''; if (alvo) alvo.style.display = 'none'; return; }
    if (!alvo) { alvo = document.createElement('div'); alvo.id = 'ffBoardSetor'; alvo.className = 'board'; board.parentNode.insertBefore(alvo, board.nextSibling); }
    var colunas = SETORES_CONTA.map(function (s) { return { id: s.id, nome: s.nome, cor: s.cor, cards: [] }; });
    var sem = { id: 0, nome: 'Sem setor', cor: '#9CA3AF', cards: [] };
    board.querySelectorAll('.cli-card[data-cli-id]').forEach(function (card) {
      var d = QUADRO[+card.getAttribute('data-cli-id')];
      var col = card.closest('.setor-col');
      var situacao = col ? (col.querySelector('.setor-name') || {}).textContent : '';
      var ids = d ? (d.setores || []).map(function (s) { return s.id; }) : [];
      var destinos = colunas.filter(function (c) { return ids.indexOf(c.id) >= 0; });
      if (!destinos.length) destinos = [sem];
      destinos.forEach(function (c) {
        var clone = card.cloneNode(true);
        // Nesta visão a coluna é o setor; a situação (coluna do quadro) vai no card.
        clone.querySelectorAll('.ffc-tag').forEach(function (t) { if (t.querySelector('i') && ids.length) t.remove(); });
        if (situacao) {
          var tags = clone.querySelector('[data-ffc-tags]');
          if (!tags) { tags = document.createElement('div'); tags.className = 'ffc-tags'; tags.setAttribute('data-ffc-tags', ''); var topo = clone.querySelector('.ffc-topo'); if (topo) topo.after(tags); }
          var t = document.createElement('span'); t.className = 'ffc-tag sit'; t.textContent = situacao.trim(); tags.insertBefore(t, tags.firstChild);
        }
        var z = clone.querySelector('[data-ffc-zap]');
        if (z) z.addEventListener('click', function (e) { e.stopPropagation(); });
        c.cards.push(clone);
      });
    });
    if (sem.cards.length) colunas.push(sem);
    alvo.innerHTML = '';
    colunas.forEach(function (c) {
      var sec = document.createElement('section');
      sec.className = 'setor-col';
      sec.innerHTML = '<header class="setor-head"><span class="setor-dot" style="background:' + corSegura(c.cor) + '"></span><span class="setor-name">' + esc(c.nome) + '</span><span class="setor-count">' + c.cards.length + '</span></header><div class="setor-body"></div>';
      var body = sec.querySelector('.setor-body');
      if (!c.cards.length) body.innerHTML = '<div class="setor-empty">Nenhum cliente neste setor.</div>';
      c.cards.forEach(function (k) { body.appendChild(k); });
      alvo.appendChild(sec);
    });
    if (!colunas.length) alvo.innerHTML = '<div class="setor-empty" style="padding:24px">A conta ainda não tem setores. Crie em Configurações, Setores, e marque os setores na ficha de cada cliente.</div>';
    board.style.display = 'none';
    alvo.style.display = '';
  }
  function ligarVista() {
    var board = document.getElementById('board');
    if (!board || document.getElementById('ffQuadroVista')) return;
    var v = document.createElement('div');
    v.id = 'ffQuadroVista';
    v.className = 'ffq-vista';
    v.innerHTML = '<span>Ver quadro por</span><button type="button" data-vista="situacao">Situação</button><button type="button" data-vista="setor">Setor</button>' +
      '<small data-vista-dica></small>';
    board.parentNode.insertBefore(v, board);
    function marcar() {
      v.querySelectorAll('[data-vista]').forEach(function (b) { b.classList.toggle('on', b.dataset.vista === VISTA); });
      v.querySelector('[data-vista-dica]').textContent = VISTA === 'setor' ? 'O cliente aparece em cada setor dele. Para mudar a situação, arraste na visão por situação.' : '';
    }
    v.querySelectorAll('[data-vista]').forEach(function (b) {
      b.onclick = function () { VISTA = b.dataset.vista; try { localStorage.setItem('ffcli_vista', VISTA); } catch (e) {} marcar(); desenharVistaSetor(); };
    });
    marcar();
  }

  function carregarQuadro() {
    if (pedindoQuadro) return pedindoQuadro;
    pedindoQuadro = fetch('/api/cliente_valores.php?quadro=1', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (r) { if (r.ok) { QUADRO = r.clientes || {}; SETORES_CONTA = r.setores || []; decorarQuadro(true); } })
      .catch(function () {})
      .then(function () { pedindoQuadro = null; });
    return pedindoQuadro;
  }
  function ligarQuadro() {
    if (!document.getElementById('board')) return;
    if (!document.getElementById('ffCliCss')) { var s = document.createElement('style'); s.id = 'ffCliCss'; s.textContent = CSS; document.head.appendChild(s); }
    if (!document.getElementById('ffQuadroCss')) { var s2 = document.createElement('style'); s2.id = 'ffQuadroCss'; s2.textContent = CSS_QUADRO; document.head.appendChild(s2); }
    ligarVista();
    carregarQuadro();
    // O quadro é redesenhado inteiro (renderBoard, filtros, arrastar): refaz os cards novos.
    new MutationObserver(function () { if (QUADRO) decorarQuadro(false); })
      .observe(document.getElementById('board'), { childList: true, subtree: true });
  }

  function montar(tentativa) {
    var modal = document.getElementById('modalCliente'), campoId = document.getElementById('cliId'), F = window.FfFicha;
    if (!modal || !campoId) return;
    var esq = modal.querySelector('.ff-col-esq'), dir = modal.querySelector('.ff-col-dir');
    if (!F || !esq || !dir) { if ((tentativa || 0) < 40) setTimeout(function () { montar((tentativa || 0) + 1); }, 150); return; }
    if (document.getElementById('ffCliFinanceiro')) return;
    if (!document.getElementById('ffCliCss')) { var st = document.createElement('style'); st.id = 'ffCliCss'; st.textContent = CSS; document.head.appendChild(st); }

    // ── Financeiro ────────────────────────────────────────────────────
    var fin = document.createElement('div');
    fin.id = 'ffCliFinanceiro';
    fin.innerHTML = '<div data-corpo></div>';
    var secFin = F.envolver([fin]);
    F.secao(secFin, 0, 'Financeiro', 'dolar', 'verde');
    F.emAbas(secFin, ['geral']);
    esq.insertBefore(secFin, esq.children[1] || null);

    // ── Setores ───────────────────────────────────────────────────────
    var setEl = document.createElement('div');
    setEl.id = 'ffCliSetores';
    setEl.innerHTML = '<div data-setores></div><div class="ffcli-erro" data-set-erro></div>';
    var secSet = F.envolver([setEl]);
    F.secao(secSet, 0, 'Setores', 'grade', 'ambar');
    F.emAbas(secSet, ['geral']);

    // ── Contratos ─────────────────────────────────────────────────────
    var ctr = document.createElement('div');
    ctr.id = 'ffCliContratos';
    ctr.innerHTML = '<div data-lista></div><div data-vinc></div><div class="ffcli-pe"><button type="button" class="ffcli-link" data-vincular>Vincular contrato existente</button><button type="button" class="ffcli-link" data-novo>+ Novo contrato</button></div>';
    var secCtr = F.envolver([ctr]);
    F.secao(secCtr, 0, 'Contratos', 'doc', 'roxo');
    F.emAbas(secCtr, ['geral']);
    var origem = Array.prototype.find.call(dir.children, function (s) { return s.getAttribute('data-ff-titulo') === 'Origem'; });
    dir.insertBefore(secCtr, origem ? origem.nextSibling : dir.firstChild);
    dir.insertBefore(secSet, secCtr);
    ctr.querySelector('[data-novo]').onclick = function () { if (campoId.value) location.href = '/contratos.php?novo=1&cliente_id=' + encodeURIComponent(campoId.value); };

    // Enter dentro destas seções não envia o formulário do cliente.
    [fin, ctr].forEach(function (el) { el.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); var b = fin.querySelector('[data-salvar]'); if (b && fin.contains(e.target)) b.click(); } }); });

    var corpo = fin.querySelector('[data-corpo]');
    var estado = novoEstado(null);
    function novoEstado(id) { return { cliente: id, itens: [], resumo: null, cobrancas: { itens: [], totais: {} }, contratos: [], podeBaixar: false, editando: null, formAberto: false, pagando: null, verPagas: false, verCanceladas: false, setores: [], setoresConta: [] }; }

    function api(metodo, corpoReq, query) {
      var op = { method: metodo, credentials: 'same-origin', headers: { Accept: 'application/json' } };
      if (metodo === 'POST') { op.headers['Content-Type'] = 'application/json'; op.headers['X-CSRF-Token'] = csrf(); op.body = JSON.stringify(corpoReq); }
      return fetch('/api/cliente_valores.php' + (query || ''), op).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão.' }; });
    }
    function aplicar(d) {
      estado.itens = d.itens || []; estado.resumo = d.resumo || {};
      estado.cobrancas = d.cobrancas || { itens: [], totais: {} }; estado.contratos = d.contratos || [];
      estado.setores = d.setores || []; estado.setoresConta = d.setores_conta || [];
      desenharSetores();
    }

    var caixaSet = setEl.querySelector('[data-setores]');
    function desenharSetores() {
      if (!estado.cliente) { caixaSet.innerHTML = '<div class="ffcli-vazio">Salve o cliente para escolher os setores.</div>'; return; }
      if (!estado.setoresConta.length) { caixaSet.innerHTML = '<div class="ffcli-vazio">A conta ainda não tem setores. Crie em Configurações, Setores.</div>'; return; }
      caixaSet.innerHTML = '<div class="ffcli-setores">' + estado.setoresConta.map(function (s) {
        var on = estado.setores.indexOf(s.id) >= 0;
        return '<button type="button" class="ffcli-setopt' + (on ? ' on' : '') + '" data-set="' + s.id + '" aria-pressed="' + on + '"><i style="background:' + corSegura(s.cor) + '"></i>' + esc(s.nome) + '</button>';
      }).join('') + '</div><div class="ffcli-vazio" style="margin-top:6px">Toque para marcar ou desmarcar. Pode ser mais de um.</div>';
      caixaSet.querySelectorAll('[data-set]').forEach(function (b) {
        b.onclick = function () {
          var id = +b.dataset.set, lista = estado.setores.slice(), i = lista.indexOf(id);
          if (i >= 0) lista.splice(i, 1); else lista.push(id);
          b.disabled = true;
          api('POST', { acao: 'setores', cliente_id: estado.cliente, setores: lista }).then(function (r) {
            b.disabled = false;
            setEl.querySelector('[data-set-erro]').textContent = r.ok ? '' : (r.error || 'Não foi possível salvar.');
            if (r.ok) { aplicar(r.data); carregarQuadro(); }
          });
        };
      });
    }

    function desenhar() {
      if (!estado.cliente) { corpo.innerHTML = '<div class="ffcli-vazio">Salve o cliente para cadastrar os valores combinados (mensal, serviço, taxa e outros).</div>'; return; }
      var r = estado.resumo || {}, t = estado.cobrancas.totais || {};
      var h = '<div class="ffcli-resumo"><div class="ffcli-tot princ"><span>Por mês</span><b>' + REAL.format(r.por_mes || 0) + '</b></div>';
      if (r.servico) h += '<div class="ffcli-tot"><span>Serviços</span><b>' + REAL.format(r.servico) + '</b></div>';
      if (r.fixo) h += '<div class="ffcli-tot"><span>Taxas</span><b>' + REAL.format(r.fixo) + '</b></div>';
      if (r.anual) h += '<div class="ffcli-tot"><span>Anual</span><b>' + REAL.format(r.anual) + '</b></div>';
      if (r.demanda) h += '<div class="ffcli-tot"><span>Por demanda</span><b>' + REAL.format(r.demanda) + '</b></div>';
      if (t.aberto) h += '<div class="ffcli-tot"><span>Em aberto</span><b>' + REAL.format(t.aberto) + '</b></div>';
      if (t.atrasado) h += '<div class="ffcli-tot alerta"><span>Atrasado</span><b>' + REAL.format(t.atrasado) + '</b></div>';
      if (t.recebido_ano) h += '<div class="ffcli-tot ok"><span>Recebido no ano</span><b>' + REAL.format(t.recebido_ano) + '</b></div>';
      h += '</div>';

      h += '<div class="ffcli-sub"><span>Valores combinados</span></div>';
      if (!estado.itens.length) h += '<div class="ffcli-vazio">Nenhum valor cadastrado. Inclua a mensalidade, o serviço feito (um site, um projeto) e o que mais foi combinado.</div>';
      h += estado.itens.map(function (i) {
        var det = [];
        if (i.tipo === 'mensal' || i.tipo === 'anual') {
          if (i.inicio) det.push('começa em ' + dataBr(i.inicio));
          if (i.dia_vencimento) det.push('vence todo dia ' + i.dia_vencimento);
          if (i.fim) det.push('até ' + dataBr(i.fim));
        } else if (i.tipo === 'demanda') {
          if (i.inicio) det.push('desde ' + dataBr(i.inicio));
          if (i.fim) det.push('até ' + dataBr(i.fim));
        } else if (i.inicio) det.push('cobrança em ' + dataBr(i.inicio));
        if (i.contrato) det.push('contrato: ' + i.contrato);
        if (!i.ativo) det.push('encerrado');
        if (i.observacao) det.push(i.observacao);
        return '<div class="ffcli-item' + (i.ativo ? '' : ' encerrado') + '"><div><div class="tit"><span class="ffcli-pill ' + i.tipo + '">' + esc(i.tipo_rotulo) + '</span>' + esc(i.descricao) + '</div>' +
          (det.length ? '<div class="sub">' + esc(det.join(' · ')) + '</div>' : '') + '</div>' +
          '<div><div class="val">' + REAL.format(i.valor) + (i.tipo === 'mensal' ? '<span class="sub"> /mês</span>' : i.tipo === 'anual' ? '<span class="sub"> /ano</span>' : '') + '</div>' +
          '<div class="acoes"><button type="button" class="ffcli-link" data-editar="' + i.id + '">Editar</button>' +
          (i.tipo === 'mensal' || i.tipo === 'anual' || i.tipo === 'demanda' ? '<button type="button" class="ffcli-link" data-ativo="' + i.id + '">' + (i.ativo ? 'Encerrar' : 'Reativar') + '</button>' : '') +
          '<button type="button" class="ffcli-link perigo" data-remover="' + i.id + '">Remover</button></div></div></div>';
      }).join('');
      if (estado.formAberto) h += formulario(estado.editando);
      else h += '<div class="ffcli-pe"><button type="button" class="ffcli-link" data-novo-valor>+ Adicionar valor ou serviço</button></div>';

      h += cobrancasHtml();
      corpo.innerHTML = h;
      ligar();
    }

    function cobrancasHtml() {
      var todas = estado.cobrancas.itens || [];
      if (!todas.length) return '';
      var abertas = todas.filter(function (c) { return c.status === 'aberta'; });
      var pagas = todas.filter(function (c) { return c.status === 'paga'; });
      var canceladas = todas.filter(function (c) { return c.status === 'cancelada'; });
      var h = '<div class="ffcli-sub"><span>Cobranças</span><span>' + abertas.length + ' em aberto · ' + pagas.length + ' paga' + (pagas.length === 1 ? '' : 's') + '</span></div>';
      if (!abertas.length) h += '<div class="ffcli-vazio">Nada em aberto.</div>';
      h += abertas.map(linhaCobranca).join('');
      if (pagas.length) {
        if (estado.verPagas) h += pagas.slice().reverse().map(linhaCobranca).join('');
        h += '<div class="ffcli-pe"><button type="button" class="ffcli-link" data-ver-pagas>' + (estado.verPagas ? 'Esconder as pagas' : 'Ver as pagas (' + pagas.length + ')') + '</button></div>';
      }
      if (canceladas.length) {
        if (estado.verCanceladas) h += canceladas.map(linhaCobranca).join('');
        h += '<div class="ffcli-pe"><button type="button" class="ffcli-link" data-ver-canceladas>' + (estado.verCanceladas ? 'Esconder as canceladas' : 'Ver as canceladas (' + canceladas.length + ')') + '</button></div>';
      }
      return h;
    }

    function linhaCobranca(c) {
      var sit = c.status === 'paga' ? 'paga' : (c.status === 'cancelada' ? 'cancelada' : (c.atrasada ? 'atrasada' : 'aberta'));
      var rot = { paga: 'Paga', cancelada: 'Cancelada', atrasada: 'Atrasada', aberta: 'Em aberto' }[sit];
      var det = ['vence ' + dataBr(c.vencimento)];
      if (c.tipo === 'mensal') det.push('referente a ' + dataBr(c.competencia).slice(3));
      if (c.status === 'paga') det.push('paga em ' + dataBr(c.pago_em) + (c.forma_pagamento ? ' por ' + c.forma_pagamento : '') + (c.valor_pago != null && c.valor_pago !== c.valor ? ', ' + REAL.format(c.valor_pago) : ''));
      if (c.contrato) det.push('contrato: ' + c.contrato);
      if (c.status === 'cancelada' && c.observacao) det.push(c.observacao.toLowerCase());
      var acoes = '';
      if (estado.podeBaixar) {
        if (c.status === 'aberta') acoes = '<button type="button" class="ffcli-link" data-pagar="' + c.id + '">Marcar como paga</button><button type="button" class="ffcli-link perigo" data-cancelar-cob="' + c.id + '">Cancelar</button>';
        else if (c.status === 'paga') acoes = '<button type="button" class="ffcli-link" data-desfazer="' + c.id + '">Desfazer baixa</button>';
        else if (c.status === 'cancelada') acoes = '<button type="button" class="ffcli-link" data-reabrir="' + c.id + '">Reabrir</button>';
      }
      var h = '<div class="ffcli-item' + (sit === 'cancelada' ? ' cancelada' : '') + '"><div><div class="tit"><span class="ffcli-pill ' + sit + '">' + rot + '</span>' + esc(c.descricao) + '</div><div class="sub">' + esc(c.tipo_rotulo + ' · ' + det.join(' · ')) + '</div></div>' +
        '<div><div class="val">' + REAL.format(c.valor) + '</div>' + (acoes ? '<div class="acoes">' + acoes + '</div>' : '') + '</div></div>';
      if (estado.pagando === c.id) {
        var op = Object.keys(FORMAS).map(function (k) { return '<option value="' + k + '">' + FORMAS[k] + '</option>'; }).join('');
        h += '<div class="ffcli-form" data-baixa="' + c.id + '"><div class="c2"><label>Pago em</label><input type="date" data-b="pago_em" value="' + hojeIso() + '"></div>' +
          '<div class="c2"><label>Valor recebido (R$)</label><input data-b="valor_pago" inputmode="decimal" value="' + String(c.valor.toFixed(2)).replace('.', ',') + '"></div>' +
          '<div class="c2"><label>Forma</label><select data-b="forma_pagamento"><option value="">Não informar</option>' + op + '</select></div>' +
          '<div class="ffcli-erro" data-erro-baixa></div>' +
          '<div class="bts"><button type="button" class="ffcli-bt" data-baixa-cancelar>Voltar</button><button type="button" class="ffcli-bt princ" data-baixa-ok>Confirmar pagamento</button></div></div>';
      }
      return h;
    }

    function formulario(i) {
      i = i || { tipo: 'mensal', ativo: true };
      var op = Object.keys(TIPOS).map(function (k) { return '<option value="' + k + '"' + (i.tipo === k ? ' selected' : '') + '>' + TIPOS[k] + '</option>'; }).join('');
      var ops = '<option value="">Sem contrato</option>' + estado.contratos.map(function (c) { return '<option value="' + c.id + '"' + (i.contrato_id === c.id ? ' selected' : '') + '>' + esc(c.titulo) + '</option>'; }).join('');
      return '<div class="ffcli-form"><div class="c2"><label>Tipo</label><select data-f="tipo">' + op + '</select></div>' +
        '<div class="c2"><label>Valor (R$)</label><input data-f="valor" inputmode="decimal" placeholder="0,00" value="' + (i.valor != null ? String(i.valor).replace('.', ',') : '') + '"></div>' +
        '<div class="c2" data-dia><label>Dia de vencimento</label><input data-f="dia_vencimento" inputmode="numeric" maxlength="2" placeholder="10" value="' + (i.dia_vencimento || '') + '"></div>' +
        '<div class="c6"><label>Descrição</label><input data-f="descricao" maxlength="160" placeholder="Ex.: Mensalidade do sistema, Criação do site, Taxa de implantação" value="' + esc(i.descricao || '') + '"></div>' +
        '<div class="c3"><label data-rot-inicio>Começa em</label><input type="date" data-f="inicio" value="' + esc(i.inicio || '') + '"><div class="dica" data-dica-inicio></div></div>' +
        '<div class="c3" data-fim><label>Termina em (opcional)</label><input type="date" data-f="fim" value="' + esc(i.fim || '') + '"></div>' +
        '<div class="c6"><label>Contrato (opcional)</label><select data-f="contrato_id">' + ops + '</select></div>' +
        '<div class="c6"><label>Observação (opcional)</label><input data-f="observacao" maxlength="300" value="' + esc(i.observacao || '') + '"></div>' +
        '<div class="ffcli-erro" data-erro></div>' +
        '<div class="bts"><button type="button" class="ffcli-bt" data-cancelar>Cancelar</button><button type="button" class="ffcli-bt princ" data-salvar>' + (i.id ? 'Salvar alteração' : 'Adicionar') + '</button></div></div>';
    }

    function ligar() {
      var q = function (s) { return corpo.querySelector(s); };
      var nv = q('[data-novo-valor]');
      if (nv) nv.onclick = function () { estado.formAberto = true; estado.editando = null; desenhar(); var v = q('[data-f="valor"]'); if (v) v.focus(); };
      var sel = q('[data-f="tipo"]');
      if (sel) {
        var ajustar = function () {
          var t = sel.value, rec = t === 'mensal' || t === 'anual';
          q('[data-dia]').style.visibility = rec ? '' : 'hidden';
          q('[data-fim]').style.visibility = (rec || t === 'demanda') ? '' : 'hidden';
          q('[data-rot-inicio]').textContent = rec ? 'Começa em' : (t === 'demanda' ? 'Desde (opcional)' : 'Data da cobrança');
          q('[data-dica-inicio]').textContent = t === 'mensal' ? 'A primeira mensalidade vence a partir desta data.' : t === 'anual' ? 'A primeira anuidade vence a partir desta data.' : (t === 'demanda' ? '' : 'Gera uma cobrança única nesta data.');
        };
        sel.onchange = ajustar; ajustar();
      }
      var cc = q('[data-cancelar]'); if (cc) cc.onclick = function () { estado.formAberto = false; estado.editando = null; desenhar(); };
      var sv = q('[data-salvar]');
      if (sv) sv.onclick = function () {
        var d = { acao: 'salvar', cliente_id: estado.cliente, id: estado.editando ? estado.editando.id : 0, ativo: estado.editando ? estado.editando.ativo : true };
        corpo.querySelectorAll('[data-f]').forEach(function (el) { d[el.getAttribute('data-f')] = el.value; });
        sv.disabled = true;
        api('POST', d).then(function (r) {
          sv.disabled = false;
          if (!r.ok) { q('[data-erro]').textContent = r.error || 'Não foi possível salvar.'; return; }
          aplicar(r.data); estado.formAberto = false; estado.editando = null; desenhar(); carregarQuadro();
        });
      };
      var porId = function (id) { return estado.itens.filter(function (i) { return i.id === +id; })[0]; };
      corpo.querySelectorAll('[data-editar]').forEach(function (b) { b.onclick = function () { estado.editando = porId(this.dataset.editar); estado.formAberto = true; desenhar(); }; });
      corpo.querySelectorAll('[data-ativo]').forEach(function (b) {
        b.onclick = function () {
          var i = porId(this.dataset.ativo);
          api('POST', Object.assign({}, i, { acao: 'salvar', cliente_id: estado.cliente, ativo: !i.ativo })).then(function (r) { if (r.ok) { aplicar(r.data); desenhar(); } });
        };
      });
      confirmarDuplo('[data-remover]', 'Remover', 'Confirmar remoção', function (b) {
        return api('POST', { acao: 'remover', cliente_id: estado.cliente, id: +b.dataset.remover });
      });

      // Cobranças
      var vp = q('[data-ver-pagas]'); if (vp) vp.onclick = function () { estado.verPagas = !estado.verPagas; desenhar(); };
      var vc = q('[data-ver-canceladas]'); if (vc) vc.onclick = function () { estado.verCanceladas = !estado.verCanceladas; desenhar(); };
      corpo.querySelectorAll('[data-reabrir]').forEach(function (b) {
        b.onclick = function () { api('POST', { acao: 'cobranca_reabrir', cliente_id: estado.cliente, id: +b.dataset.reabrir }).then(function (r) { if (r.ok) { aplicar(r.data); desenhar(); } }); };
      });
      corpo.querySelectorAll('[data-pagar]').forEach(function (b) { b.onclick = function () { estado.pagando = +b.dataset.pagar; desenhar(); }; });
      var bv = q('[data-baixa-cancelar]'); if (bv) bv.onclick = function () { estado.pagando = null; desenhar(); };
      var bo = q('[data-baixa-ok]');
      if (bo) bo.onclick = function () {
        var d = { acao: 'cobranca_pagar', cliente_id: estado.cliente, id: estado.pagando };
        corpo.querySelectorAll('[data-b]').forEach(function (el) { d[el.getAttribute('data-b')] = el.value; });
        bo.disabled = true;
        api('POST', d).then(function (r) {
          bo.disabled = false;
          if (!r.ok) { q('[data-erro-baixa]').textContent = r.error || 'Não foi possível dar baixa.'; return; }
          aplicar(r.data); estado.pagando = null; desenhar();
        });
      };
      confirmarDuplo('[data-cancelar-cob]', 'Cancelar', 'Confirmar cancelamento', function (b) {
        return api('POST', { acao: 'cobranca_cancelar', cliente_id: estado.cliente, id: +b.dataset.cancelarCob });
      });
      corpo.querySelectorAll('[data-desfazer]').forEach(function (b) {
        b.onclick = function () { api('POST', { acao: 'cobranca_desfazer', cliente_id: estado.cliente, id: +b.dataset.desfazer }).then(function (r) { if (r.ok) { aplicar(r.data); desenhar(); } }); };
      });
    }

    // Ação destrutiva em dois cliques (sem diálogo do navegador).
    function confirmarDuplo(sel, rotulo, rotuloConfirma, acao) {
      corpo.querySelectorAll(sel).forEach(function (b) {
        b.onclick = function () {
          if (b.dataset.confirma !== '1') { b.dataset.confirma = '1'; b.textContent = rotuloConfirma; setTimeout(function () { if (b.isConnected) { b.dataset.confirma = ''; b.textContent = rotulo; } }, 4000); return; }
          acao(b).then(function (r) {
            if (r.ok) { aplicar(r.data); desenhar(); return; }
            b.textContent = r.error || 'Não foi possível.'; b.dataset.confirma = '';
          });
        };
      });
    }

    // ── Lista e vínculo de contratos ──────────────────────────────────
    var listaCtr = ctr.querySelector('[data-lista]'), vincCtr = ctr.querySelector('[data-vinc]');
    function getJson(url) { return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão.' }; }); }
    function carregarContratos(id) {
      listaCtr.innerHTML = '<div class="ffcli-vazio">Carregando…</div>';
      getJson('/api/crm_contratos.php?lista=1&cliente_id=' + id).then(function (r) {
        if (estado.cliente !== id) return;
        if (!r.ok) { listaCtr.innerHTML = '<div class="ffcli-vazio">' + esc(r.error || 'Não foi possível carregar.') + '</div>'; return; }
        listaCtr.innerHTML = r.data.length ? r.data.map(function (c) {
          return '<a class="ffcli-ctr" href="/contratos.php?id=' + c.id + '"><span>' + esc(c.titulo) + '</span><span>' + esc(c.status_rotulo) + ' · ' + c.assinaturas + '/' + c.participantes + '</span></a>';
        }).join('') : '<div class="ffcli-vazio">Nenhum contrato para este cliente.</div>';
      });
    }
    ctr.querySelector('[data-vincular]').onclick = function () {
      var id = estado.cliente;
      if (!id) return;
      if (vincCtr.innerHTML) { vincCtr.innerHTML = ''; return; }
      vincCtr.innerHTML = '<div class="ffcli-vazio">Buscando contratos sem cliente…</div>';
      getJson('/api/crm_contratos.php?lista=1&sem_cliente=1').then(function (r) {
        if (estado.cliente !== id) return;
        if (!r.ok) { vincCtr.innerHTML = '<div class="ffcli-vazio">' + esc(r.error || 'Não foi possível carregar.') + '</div>'; return; }
        if (!r.data.length) { vincCtr.innerHTML = '<div class="ffcli-vazio">Não há contrato sem cliente para vincular.</div>'; return; }
        vincCtr.innerHTML = '<div class="ffcli-vinc"><select data-vsel>' + r.data.map(function (c) { return '<option value="' + c.id + '">' + esc(c.titulo) + ' (' + esc(c.status_rotulo) + ')</option>'; }).join('') + '</select>' +
          '<button type="button" class="ffcli-bt princ" data-vok>Vincular</button></div><div class="ffcli-erro" data-verro></div>';
        vincCtr.querySelector('[data-vok]').onclick = function () {
          var bt = this; bt.disabled = true;
          fetch('/api/crm_contratos.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify({ acao: 'vincular_cliente', id: +vincCtr.querySelector('[data-vsel]').value, cliente_id: id }) })
            .then(function (x) { return x.json(); }).catch(function () { return { ok: false, error: 'Sem conexão.' }; })
            .then(function (x) {
              bt.disabled = false;
              if (!x.ok) { vincCtr.querySelector('[data-verro]').textContent = x.error || 'Não foi possível vincular.'; return; }
              vincCtr.innerHTML = ''; carregarContratos(id);
              api('GET', null, '?cliente_id=' + id).then(function (y) { if (y.ok && estado.cliente === id) { aplicar(y.data); desenhar(); } });
            });
        };
      });
    };

    function carregar() {
      var id = campoId.value ? +campoId.value : null;
      if (id === estado.cliente && estado.resumo) return;
      estado = novoEstado(id);
      vincCtr.innerHTML = '';
      if (!id) { desenhar(); desenharSetores(); listaCtr.innerHTML = '<div class="ffcli-vazio">Salve o cliente para ligar contratos a ele.</div>'; return; }
      caixaSet.innerHTML = '<div class="ffcli-vazio">Carregando…</div>';
      corpo.innerHTML = '<div class="ffcli-vazio">Carregando…</div>';
      api('GET', null, '?cliente_id=' + id).then(function (r) {
        if (estado.cliente !== id) return;
        if (!r.ok) { corpo.innerHTML = '<div class="ffcli-vazio">' + esc(r.error || 'Não foi possível carregar.') + '</div>'; return; }
        if (r.tipos) TIPOS = r.tipos;
        if (r.formas) FORMAS = r.formas;
        estado.podeBaixar = !!r.pode_baixar;
        aplicar(r.data); desenhar();
      });
      carregarContratos(id);
    }
    var aberto = false;
    new MutationObserver(function () {
      var agora = modal.classList.contains('open');
      // Ao fechar a ficha, o card do quadro pega o que mudou (baixa, setor, valor).
      if (agora) carregar(); else if (aberto) { estado.cliente = -1; carregarQuadro(); }
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
  function iniciar() { montar(); ligarQuadro(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(iniciar, 0); });
  else setTimeout(iniciar, 0);
})();
