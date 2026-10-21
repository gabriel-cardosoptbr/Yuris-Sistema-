/* ═══════════════════════════════════════════════════════════════════════
   ff-cliente-operacao.js: a ficha do cliente nos ambientes da Inovaize.
   Carregado pelo sidebar.php só na conta com ambientes, na tela Clientes.

   Cada ambiente abre o cliente como um sistema à parte (21/10/2026). O
   cadastro é um só; o ambiente do menu (aside[data-ambiente]) decide as abas
   e as seções:
     - Digital: Visão geral (resumo do mês, redes e marca), Produção,
       Entregas, Postagem (grade com a arte do Drive, situação e link do
       post), Arquivos (Drive), WhatsApp e Histórico. Sem financeiro,
       contrato, dados pessoais, endereço ou origem.
     - Software: Visão geral (resumo, perfil técnico), Projeto e demandas
       (quadros de Software, demandas abertas, projetos da pasta Projetos),
       Arquivos, WhatsApp e Histórico. Sem financeiro e sem Digital.
     - Comercial: a venda: dados, origem, negociações, financeiro, contratos.
       Sem produção.
     - Empresa: tudo.
   No quadro de Clientes, window.FfCardArea dá o miolo do card de cada
   ambiente (ff-cliente-crm.js chama): entregas e postagem no Digital,
   demandas no Software, dinheiro e operação na Empresa.
   Dados de /api/digital.php (?cliente=, ?ficha=, ?quadro_clientes=).
   Visual sobre ff-ficha.css e .ffcli- do ff-cliente-crm.js.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var aside = document.querySelector('aside.sidebar[data-ambiente]');
  var AMB = aside ? aside.getAttribute('data-ambiente') : 'empresa';
  if (['empresa', 'comercial', 'software', 'digital'].indexOf(AMB) < 0) AMB = 'empresa';
  var CHAVE = { empresa: 'e', comercial: 'c', software: 's', digital: 'd' }[AMB];

  var CSRF = function () { return (window.FF_AGENDA && window.FF_AGENDA.csrf) || ''; };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function num(v) { return (Number(v) || 0).toLocaleString('pt-BR'); }
  function pl(n, um, varios) { return num(n) + ' ' + (Number(n) === 1 ? um : varios); }
  function brl(v) { return (Number(v) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
  function data(v) { if (!v) return ''; var s = String(v); return s.slice(8, 10) + '/' + s.slice(5, 7); }
  function dataAno(v) { if (!v) return ''; var s = String(v); return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4); }
  function host(u) { try { return new URL(u).host.replace(/^www\./, ''); } catch (e) { return u || ''; } }
  function linkExt(u, txt) { return u ? '<a class="ffcli-link" href="' + esc(u) + '" target="_blank" rel="noopener noreferrer">' + esc(txt || host(u)) + '</a>' : ''; }
  function pedir(qs, corpo) {
    var op = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (corpo) { op.method = 'POST'; op.headers['Content-Type'] = 'application/json'; op.headers['X-CSRF-Token'] = CSRF(); op.body = JSON.stringify(corpo); }
    return fetch('/api/digital.php' + (qs || ''), op).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão.' }; });
  }
  function aviso(msg, tipo) { if (window.Yuris && window.Yuris.toast) window.Yuris.toast(msg, tipo || 'error'); }
  var AREA = { software: 'Software', digital: 'Digital / Marketing', empresa: 'Empresa', comercial: 'Comercial' };
  var PRIO = { urgente: 'Urgente', alta: 'Alta', media: 'Média', baixa: 'Baixa' };
  var PUB = { publicado: 'Publicado', agendado: 'Agendado', pendente: 'A publicar', nao_se_aplica: 'Não vai ao ar' };

  var CSS = '.ffop-quadros{display:flex;flex-direction:column;gap:8px}.ffop-q{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;padding:10px 12px;border-radius:10px;background:var(--ff-fundo-2,rgba(17,29,45,.04));text-decoration:none;color:inherit}' +
    '.ffop-q:hover{outline:1px solid var(--ff-marca,#015DFC)}.ffop-q b{font-size:13.5px;overflow-wrap:anywhere}.ffop-q small{display:block;font-size:12px;opacity:.75}' +
    '.ffop-nums{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;margin-bottom:12px}.ffop-n{padding:10px;border-radius:10px;background:var(--ff-fundo-2,rgba(17,29,45,.04))}.ffop-n span{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;opacity:.7}.ffop-n b{font-size:20px;font-weight:800}' +
    '.ffop-n.alerta b{color:#B91C1C}.ffop-n.atencao b{color:#B45309}.ffop-n.ok b{color:#047857}.ffop-tab{width:100%;border-collapse:collapse;font-size:13px}.ffop-tab th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;opacity:.7;padding:6px 8px;border-bottom:1px solid rgba(17,29,45,.12)}' +
    '.ffop-tab td{padding:8px;border-bottom:1px solid rgba(17,29,45,.07);vertical-align:top;overflow-wrap:anywhere}.ffop-tab td small{display:block;opacity:.7;font-size:11.5px}.ffop-pend{color:#B45309;font-weight:700}.ffop-mes{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}' +
    '.ffop-mes input{padding:6px 10px;border-radius:9px;border:1px solid rgba(17,29,45,.16);font:inherit}.ffop-areas{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}.ffop-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-top:10px;align-items:end}' +
    '.ffop-form label{display:flex;flex-direction:column;gap:4px;font-size:11.5px;font-weight:700;opacity:.85}.ffop-form input,.ffop-form select,.ffop-form textarea{padding:7px 9px;border-radius:9px;border:1px solid rgba(17,29,45,.16);font:inherit;font-size:13px;background:transparent;color:inherit}' +
    '.ffop-form .larga{grid-column:1/-1}.ffop-form textarea{min-height:90px;resize:vertical}' +
    '.ffop-fora{display:none!important}' +
    '.ffop-barra{height:8px;border-radius:99px;background:rgba(17,29,45,.08);overflow:hidden;margin:4px 0 10px}.ffop-barra i{display:block;height:100%;background:var(--ff-marca,#015DFC);border-radius:99px}' +
    '.ffop-dl{display:grid;grid-template-columns:minmax(110px,auto) 1fr;gap:6px 12px;font-size:13px}.ffop-dl dt{font-weight:700;opacity:.7}.ffop-dl dd{margin:0;overflow-wrap:anywhere}.ffop-briefing{white-space:pre-wrap;font-size:13px;line-height:1.5;margin-top:10px;padding:10px 12px;border-radius:10px;background:var(--ff-fundo-2,rgba(17,29,45,.04))}' +
    '.ffop-lista{display:flex;flex-direction:column}.ffop-li{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid rgba(17,29,45,.07);font-size:13px;flex-wrap:wrap}.ffop-li:first-child{border-top:0}.ffop-li b{overflow-wrap:anywhere}.ffop-li small{display:block;opacity:.7;font-size:11.5px}' +
    '.ffop-post-grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px}' +
    '.ffop-post{border:1px solid rgba(17,29,45,.10);border-radius:12px;overflow:hidden;display:flex;flex-direction:column;min-width:0;background:var(--ff-card,#fff)}' +
    '.ffop-post-mini{position:relative;aspect-ratio:4/5;background:rgba(17,29,45,.05);display:flex;align-items:center;justify-content:center;overflow:hidden;border:0;padding:0;cursor:default;width:100%;color:inherit}' +
    '.ffop-post-mini.ver{cursor:pointer}.ffop-post-mini img{width:100%;height:100%;object-fit:cover;display:block}.ffop-post-mini .ico{display:flex;flex-direction:column;align-items:center;gap:6px;font-size:11.5px;font-weight:700;opacity:.65;padding:10px;text-align:center}' +
    '.ffop-post-mini .ico svg{width:30px;height:30px}.ffop-post-sit{position:absolute;left:8px;top:8px}' +
    '.ffop-post-txt{padding:9px 10px;display:flex;flex-direction:column;gap:3px;min-width:0}.ffop-post-txt b{font-size:12.5px;line-height:1.3;overflow-wrap:anywhere}.ffop-post-txt small{font-size:11px;opacity:.75}' +
    '.ffop-post-acoes{display:flex;flex-wrap:wrap;gap:4px 10px;padding:0 10px 10px}.ffop-post-link{display:flex;gap:6px;padding:0 10px 10px}.ffop-post-link input{flex:1;min-width:0;padding:6px 8px;border-radius:8px;border:1px solid rgba(17,29,45,.16);font:inherit;font-size:12px;background:transparent;color:inherit}' +
    '.ffop-pill{display:inline-flex;align-items:center;height:22px;padding:0 9px;border-radius:99px;font-size:11px;font-weight:700;background:#EEF0F3;color:#374151;white-space:nowrap}' +
    '.ffop-pill.publicado,.ffop-pill.ok{background:#D1FAE5;color:#047857}.ffop-pill.agendado{background:#E0F2FE;color:#0369A1}.ffop-pill.pendente,.ffop-pill.atencao{background:#FFF4D6;color:#B45309}.ffop-pill.alerta{background:#FEE2E2;color:#B91C1C}' +
    '.ffop-amb{font-size:12px;font-weight:700;color:var(--ff-marca,#015DFC);margin-top:2px}' +
    '.ffop-cab{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px}' +
    // Card do quadro de Clientes
    '.ffc-op .ffc-op-tit{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--ffc-mu,#6B7280);margin-bottom:4px}' +
    '.ffc-op .ffc-barra{height:6px;border-radius:99px;background:var(--ffc-bd,#E5E7EB);overflow:hidden;margin:6px 0 2px}.ffc-op .ffc-barra i{display:block;height:100%;background:var(--ff-marca,#015DFC)}' +
    '.ffc-op-linha{font-size:11.5px;color:var(--ffc-mu,#6B7280);margin-top:2px}.ffc-op + .ffc-op{margin-top:-4px}' +
    'html:not([data-theme="light"]) .ffop-q,html:not([data-theme="light"]) .ffop-n,html:not([data-theme="light"]) .ffop-briefing{background:rgba(255,255,255,.05)}html:not([data-theme="light"]) .ffop-n.alerta b{color:#F87171}html:not([data-theme="light"]) .ffop-n.atencao b{color:#FBBF24}html:not([data-theme="light"]) .ffop-n.ok b{color:#34D399}html:not([data-theme="light"]) .ffop-pend{color:#FBBF24}' +
    'html:not([data-theme="light"]) .ffop-post{background:#13213A;border-color:rgba(255,255,255,.10)}html:not([data-theme="light"]) .ffop-post-mini{background:rgba(255,255,255,.05)}html:not([data-theme="light"]) .ffop-barra{background:rgba(255,255,255,.10)}';
  function estilo() { if (document.getElementById('ffOpCss')) return; var st = document.createElement('style'); st.id = 'ffOpCss'; st.textContent = CSS; document.head.appendChild(st); }

  // ── Card do quadro de Clientes ─────────────────────────────────────────
  var ICO_INSTA = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r=".6" fill="currentColor"/></svg>';
  var ICO_PRAZO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>';
  var CA = {
    modo: AMB === 'comercial' ? 'dinheiro' : AMB,
    dados: null,
    bloco: function (id, modo) {
      var x = CA.dados && CA.dados[id];
      if (!x) return modo === 'empresa' ? '' : '<div class="ffc-din vazio">Carregando a operação…</div>';
      var dg = x.digital, sw = x.software, temDg = x.areas.indexOf('digital') >= 0, temSw = x.areas.indexOf('software') >= 0;
      function blocoDigital(curto) {
        var meta = dg.contratadas || dg.cadastradas;
        if (!meta) return curto ? '' : '<div class="ffc-din vazio">Sem entregas neste mês. Abra o cliente para ver a frequência e os quadros.</div>';
        var pct = Math.min(100, Math.round(100 * dg.concluidas / meta));
        var sit = dg.ritmo === 'atrasado' ? '<div class="ffc-sit atraso"><i></i>Atrasado: ' + num(dg.concluidas) + ' de ' + num(dg.esperado_ate_hoje) + ' esperadas até hoje</div>'
          : dg.ritmo === 'em_dia' ? '<div class="ffc-sit ok"><i></i>Em dia com o contratado</div>' : '<div class="ffc-sit prox"><i></i>Sem frequência contratada</div>';
        if (curto) return '<div class="ffc-op-linha"><b>Digital:</b> ' + num(dg.concluidas) + ' de ' + pl(meta, 'entrega', 'entregas') + ' · ' + pl(dg.publicadas, 'postada', 'postadas') + (dg.ritmo === 'atrasado' ? ' · atrasado' : '') + '</div>';
        return '<div class="ffc-din ffc-op"><div class="ffc-op-tit">Entregas do mês</div><div class="ffc-valor">' + num(dg.concluidas) + '<small>de ' + num(meta) + ' concluídas</small></div>' +
          '<div class="ffc-barra"><i style="width:' + pct + '%"></i></div>' +
          '<div class="ffc-op-linha">' + pl(dg.publicadas, 'postada', 'postadas') + ' · ' + pl(dg.agendadas, 'agendada', 'agendadas') + (dg.aprovacao ? ' · ' + num(dg.aprovacao) + ' com o cliente' : '') + (dg.atrasadas ? ' · ' + pl(dg.atrasadas, 'atrasada', 'atrasadas') : '') + '</div>' + sit + '</div>';
      }
      function blocoSoftware(curto) {
        if (!sw.quadros) return curto ? '' : '<div class="ffc-din vazio">Sem quadro de Software. Abra o cliente para criar o projeto.</div>';
        if (curto) return '<div class="ffc-op-linha"><b>Software:</b> ' + pl(sw.abertas, 'demanda aberta', 'demandas abertas') + (sw.atrasadas ? ' · ' + pl(sw.atrasadas, 'atrasada', 'atrasadas') : '') + '</div>';
        return '<div class="ffc-din ffc-op"><div class="ffc-op-tit">Demandas</div><div class="ffc-valor">' + num(sw.abertas) + '<small>abertas</small></div>' +
          (sw.atrasadas ? '<div class="ffc-sit atraso"><i></i>' + pl(sw.atrasadas, 'atrasada', 'atrasadas') + '</div>'
            : sw.proximo ? '<div class="ffc-sit prox"><i></i>Próximo prazo ' + data(sw.proximo) + '</div>' : '<div class="ffc-sit ok"><i></i>Nada atrasado</div>') +
          '<div class="ffc-op-linha">' + pl(sw.quadros, 'quadro', 'quadros') + ' de Software</div></div>';
      }
      if (modo === 'digital') return blocoDigital(false);
      if (modo === 'software') return blocoSoftware(false);
      var linhas = (temDg ? blocoDigital(true) : '') + (temSw ? blocoSoftware(true) : '');
      return linhas ? '<div class="ffc-op">' + linhas + '</div>' : '';
    },
    rodape: function (id, modo) {
      var x = CA.dados && CA.dados[id], out = [];
      if (!x || modo === 'dinheiro') return out;
      if (x.instagram && modo !== 'software') out.push('<span class="ffc-meta" title="Instagram">' + ICO_INSTA + '@' + esc(x.instagram) + '</span>');
      if (modo === 'digital' && x.digital.proxima) out.push('<span class="ffc-meta forte" title="Próxima entrega">' + ICO_PRAZO + 'Próxima entrega ' + data(x.digital.proxima) + '</span>');
      return out;
    }
  };
  window.FfCardArea = CA;
  function carregarCards() {
    if (CA.modo === 'dinheiro' || !document.getElementById('board')) return;
    pedir('?quadro_clientes=1').then(function (r) {
      if (!r.ok) return;
      CA.dados = r.data;
      estilo();
      if (window.FfCliCrm) window.FfCliCrm.redesenhar();
    });
  }

  // ── Em que abas cada seção aparece, por ambiente ───────────────────────
  // e: Empresa, c: Comercial, s: Software, d: Digital. [] = fora da ficha deste ambiente.
  var G = ['geral'], N = [];
  function todos(a) { return { e: a, c: a, s: a, d: a }; }
  var MAPA = {
    'Dados principais': todos(G), 'Observações': todos(G), 'Setores': todos(G), 'Operação': todos(G), 'Histórico': todos(['historico']),
    'Dados pessoais': { e: G, c: G, s: N, d: N }, 'Endereço': { e: G, c: G, s: N, d: N }, 'Origem': { e: G, c: G, s: N, d: N },
    'Financeiro': { e: G, c: G, s: N, d: N }, 'Contratos': { e: G, c: G, s: N, d: N }, 'Negociações': { e: G, c: G, s: N, d: N },
    'Conversas de WhatsApp': { e: ['geral', 'whatsapp'], c: ['geral', 'whatsapp'], s: ['whatsapp'], d: ['whatsapp'] },
    'Tarefas': { e: G, c: G, s: ['software'], d: ['producao'] },
    'Etiquetas, campos e documentos': { e: ['geral', 'arquivos'], c: ['geral', 'arquivos'], s: ['arquivos'], d: ['arquivos'] },
    'Quadros de produção e projetos': { e: ['producao'], c: N, s: N, d: ['producao'] },
    'Frequência contratada': { e: ['producao'], c: N, s: N, d: ['producao'] },
    'Campanhas de tráfego pago': { e: ['producao'], c: N, s: N, d: ['producao'] },
    'Entregas do mês': { e: ['entregas'], c: N, s: N, d: ['entregas'] },
    'Lista de entregas': { e: ['entregas'], c: N, s: N, d: ['entregas'] },
    'Google Drive do cliente': { e: ['arquivos'], c: N, s: ['arquivos'], d: ['arquivos'] },
    'Resumo do Digital': { e: G, c: N, s: N, d: G },
    'Redes e marca': { e: ['postagem'], c: N, s: N, d: G },
    'Postagem do mês': { e: ['postagem'], c: N, s: N, d: ['postagem'] },
    'Resumo do Software': { e: G, c: N, s: G, d: N },
    'Perfil técnico': { e: ['software'], c: N, s: G, d: N },
    'Quadros de Software': { e: ['software'], c: N, s: ['software'], d: N },
    'Demandas abertas': { e: ['software'], c: N, s: ['software'], d: N },
    'Projetos ligados': { e: ['software'], c: N, s: ['software'], d: N }
  };
  var ABAS = {
    empresa: [['geral', 'Visão geral', 'grade'], ['whatsapp', 'WhatsApp', 'zap'], ['producao', 'Produção', 'tarefa'], ['entregas', 'Entregas', 'calendario'],
      ['postagem', 'Postagem', 'imagem'], ['software', 'Software', 'codigo'], ['arquivos', 'Arquivos', 'clipe'], ['historico', 'Histórico', 'relogio']],
    comercial: [['geral', 'Visão geral', 'grade'], ['whatsapp', 'WhatsApp', 'zap'], ['arquivos', 'Arquivos', 'clipe'], ['historico', 'Histórico', 'relogio']],
    software: [['geral', 'Visão geral', 'grade'], ['software', 'Projeto e demandas', 'codigo'], ['arquivos', 'Arquivos', 'clipe'], ['whatsapp', 'WhatsApp', 'zap'], ['historico', 'Histórico', 'relogio']],
    digital: [['geral', 'Visão geral', 'grade'], ['producao', 'Produção', 'tarefa'], ['entregas', 'Entregas', 'calendario'], ['postagem', 'Postagem', 'imagem'],
      ['arquivos', 'Arquivos', 'clipe'], ['whatsapp', 'WhatsApp', 'zap'], ['historico', 'Histórico', 'relogio']]
  };
  var SUBTITULO = {
    empresa: 'Ficha de Empresa: tudo do cliente, do comercial ao financeiro, à produção e ao software.',
    comercial: 'Ficha de Comercial: origem, negociações, valores e contratos.',
    software: 'Ficha de Software: o sistema do cliente, projetos e demandas.',
    digital: 'Ficha de Digital / Marketing: projeto, entregas, postagem e arquivos.'
  };

  function montar(tentativa) {
    var modal = document.getElementById('modalCliente'), campoId = document.getElementById('cliId'), F = window.FfFicha;
    var esq = modal && modal.querySelector('.ff-col-esq'), dir = modal && modal.querySelector('.ff-col-dir'), nav = modal && modal.querySelector('.ff-abas');
    if (!modal || !campoId) return;
    if (!F || !esq || !dir || !nav) { if ((tentativa || 0) < 40) setTimeout(function () { montar((tentativa || 0) + 1); }, 150); return; }
    if (document.getElementById('ffOpProducao')) return;
    estilo();

    // Abas do ambiente, na ordem dele (as que já existem são reaproveitadas).
    var existentes = {};
    nav.querySelectorAll('.ff-aba').forEach(function (b) { existentes[b.getAttribute('data-aba')] = b; b.remove(); });
    ABAS[AMB].forEach(function (a) {
      var b = existentes[a[0]];
      if (!b) { b = document.createElement('button'); b.type = 'button'; b.className = 'ff-aba'; b.setAttribute('role', 'tab'); b.dataset.aba = a[0]; }
      b.innerHTML = F.ico(a[2]) + '<span>' + esc(a[1]) + '</span>';
      nav.appendChild(b);
    });
    // Qual ficha é esta, abaixo do título.
    var tit = modal.querySelector('.ff-titulo');
    if (tit && tit.parentNode) { var amb = document.createElement('div'); amb.className = 'ffop-amb'; amb.textContent = SUBTITULO[AMB]; tit.parentNode.appendChild(amb); }

    function secao(id, titulo, icone, cor, coluna, posicao) {
      var el = document.createElement('div'); el.id = id;
      var s = F.envolver([el]);
      F.secao(s, 0, titulo, icone, cor);
      s.setAttribute('data-ff-abas', '');
      if (posicao === 'topo') coluna.insertBefore(s, coluna.firstChild);
      else if (posicao && posicao.parentNode === coluna) coluna.insertBefore(s, posicao.nextSibling);
      else coluna.appendChild(s);
      return el;
    }
    var dadosPrinc = Array.prototype.find.call(esq.children, function (s) { return s.getAttribute('data-ff-titulo') === 'Dados principais'; });
    var elOper = secao('ffOpResumo', 'Operação', 'maleta', 'roxo', dir, 'topo');
    // No Digital e no Software o resumo abre a ficha; na Empresa vem depois do cadastro.
    var posResumo = AMB === 'digital' || AMB === 'software' ? 'topo' : dadosPrinc;
    var origem = Array.prototype.find.call(dir.children, function (s) { return s.getAttribute('data-ff-titulo') === 'Origem'; });
    var elResDg = secao('ffOpResDigital', 'Resumo do Digital', 'alvo', 'verde', esq, posResumo);
    var elResSw = secao('ffOpResSoftware', 'Resumo do Software', 'codigo', 'roxo', esq, posResumo);
    var elRedes = secao('ffOpRedes', 'Redes e marca', 'imagem', 'roxo', dir, 'topo');
    var elTec = secao('ffOpTecnico', 'Perfil técnico', 'codigo', '', dir, 'topo');
    var elNeg = secao('ffOpNegociacoes', 'Negociações', 'bandeira', 'ambar', dir, origem);
    var elQuadros = secao('ffOpProducao', 'Quadros de produção e projetos', 'tarefa', 'verde', esq);
    var elFreq = secao('ffOpFrequencia', 'Frequência contratada', 'calendario', 'ambar', dir);
    var elCamp = secao('ffOpCampanhas', 'Campanhas de tráfego pago', 'bandeira', '', dir);
    var elMes = secao('ffOpMes', 'Entregas do mês', 'calendario', 'verde', esq);
    var elLista = secao('ffOpLista', 'Lista de entregas', 'tarefa', '', dir);
    var elPost = secao('ffOpPostagem', 'Postagem do mês', 'imagem', 'verde', esq);
    var elQSw = secao('ffOpQuadrosSw', 'Quadros de Software', 'tarefa', 'verde', esq);
    var elDem = secao('ffOpDemandas', 'Demandas abertas', 'tarefa', 'ambar', esq);
    var elProj = secao('ffOpProjetos', 'Projetos ligados', 'maleta', '', dir);
    var elDrive = window.FfDrive ? secao('ffOpDrive', 'Google Drive do cliente', 'clipe', '', dir, 'topo') : null;
    function sec(el) { return el && el.closest('.form-section'); }

    // A faixa do topo: no Digital o Instagram e no Software o sistema, no lugar da origem.
    var faixa = modal.querySelector('.ff-resumo'), celAmb = null;
    if (faixa && (AMB === 'digital' || AMB === 'software')) {
      var celOrigem = faixa.querySelector('[data-cel="origem"]');
      if (celOrigem) celOrigem.classList.add('ffop-fora');
      celAmb = document.createElement('div');
      celAmb.className = 'ff-cel'; celAmb.setAttribute('data-cel', 'ambiente');
      celAmb.innerHTML = '<span class="ff-cel-rotulo">' + (AMB === 'digital' ? 'Instagram' : 'Sistema') + '</span><div class="ff-cel-valor"></div>';
      faixa.appendChild(celAmb);
    }

    // Distribui as seções pelas abas do ambiente e tira o número (as que ficam
    // fora deixariam buraco na contagem). Seção que outro script põe depois
    // (Financeiro, Contratos, Setores) entra pelo observador.
    function aplicar() {
      [esq, dir].forEach(function (col) {
        Array.prototype.forEach.call(col.children, function (s) {
          var t = s.getAttribute('data-ff-titulo');
          if (!t) return;
          var m = MAPA[t];
          if (m) s.setAttribute('data-ff-abas', m[CHAVE].join(' '));
          if (s.getAttribute('data-ff-numero') === '1') {
            s.setAttribute('data-ff-numero', '');
            var alvo = s.querySelector('.form-section-title span:last-child');
            if (alvo) alvo.textContent = t;
          }
        });
      });
      var at = nav.querySelector('.ff-aba.ativa') || nav.querySelector('[data-aba="geral"]');
      var painel = modal.querySelector('[data-ff-aba]');
      if (at && painel) {
        var chave = at.getAttribute('data-aba');
        painel.querySelectorAll('[data-ff-abas]').forEach(function (s) { s.classList.toggle('ff-oculta', (s.getAttribute('data-ff-abas') || '').split(/\s+/).indexOf(chave) === -1); });
      }
    }
    var agendado = null;
    function aplicarDepois() { clearTimeout(agendado); agendado = setTimeout(aplicar, 30); }
    new MutationObserver(aplicarDepois).observe(esq, { childList: true });
    new MutationObserver(aplicarDepois).observe(dir, { childList: true });
    aplicar();

    var estado = { id: null, mes: new Date().toISOString().slice(0, 7), dados: null, ficha: null, apoio: null, editando: {} };
    function apoio() { return estado.apoio ? Promise.resolve(estado.apoio) : pedir('?apoio=1').then(function (r) { estado.apoio = r.ok ? r.data : { tipos: {}, periodos: {} }; return estado.apoio; }); }
    function temArea(a) { return estado.dados && estado.dados.areas.indexOf(a) >= 0; }
    function tile(rot, v, cls) { return '<div class="ffop-n ' + (cls || '') + '"><span>' + rot + '</span><b>' + v + '</b></div>'; }

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
            estado.dados.areas = x.areas; estado.dados.areas_marcadas = x.areas_marcadas; desenharResumo(); desenharFora();
          });
        };
      });
    }
    // Na Empresa, o resumo de uma área só aparece se o cliente está nela.
    function desenharFora() {
      if (AMB !== 'empresa') return;
      sec(elResDg).classList.toggle('ffop-fora', !temArea('digital'));
      sec(elResSw).classList.toggle('ffop-fora', !temArea('software'));
    }

    // ── Digital ──────────────────────────────────────────────────────────
    function desenharResumoDigital() {
      var r = (estado.dados && estado.dados.resumo) || {}, p = estado.ficha && estado.ficha.postagem;
      var meta = r.contratadas || r.cadastradas || 0, pct = meta ? Math.min(100, Math.round(100 * (r.concluidas || 0) / meta)) : 0;
      var ritmo = r.ritmo === 'atrasado' ? '<span class="ffop-pill alerta">Atrasado: ' + num(r.concluidas) + ' de ' + num(r.esperado_ate_hoje) + ' esperadas até hoje</span>'
        : r.ritmo === 'em_dia' ? '<span class="ffop-pill ok">Em dia com o contratado</span>' : '<span class="ffop-pill">Sem frequência contratada</span>';
      var h = '<div class="ffop-cab"><b>' + esc(estado.mes.slice(5, 7) + '/' + estado.mes.slice(0, 4)) + '</b>' + ritmo + '</div>' +
        '<div class="ffcli-vazio">' + num(r.concluidas) + ' de ' + num(meta) + ' entregas concluídas</div><div class="ffop-barra"><i style="width:' + pct + '%"></i></div>' +
        '<div class="ffop-nums">' + tile('Contratadas', num(r.contratadas)) + tile('Concluídas', num(r.concluidas), 'ok') + tile('Postadas', num(r.publicadas)) +
        tile('Agendadas', num(r.agendadas)) + tile('Com o cliente', num(r.aprovacao), r.aprovacao ? 'atencao' : '') + tile('Atrasadas', num(r.atrasadas), r.atrasadas ? 'alerta' : '') + '</div>';
      var prox = p ? p.itens.filter(function (e) { return e.situacao !== 'concluida' && e.prazo; }).slice(0, 5) : [];
      h += prox.length ? '<div class="ffcli-sub">Próximas entregas</div><div class="ffop-lista">' + prox.map(function (e) {
        return '<div class="ffop-li"><span><b><a href="/tarefas?amb=digital&board=' + e.board_id + '&tarefa=' + e.task_id + '">' + esc(e.titulo) + '</a></b><small>' + esc(e.tipo_rotulo) + ' · ' + esc(e.producao_rotulo) + '</small></span>' +
          '<span' + (e.atrasada ? ' class="ffop-pend"' : '') + '>' + esc(data(e.prazo)) + (e.atrasada ? ' (atrasada)' : '') + '</span></div>';
      }).join('') + '</div>' : '';
      elResDg.innerHTML = h;
    }
    function formPerfil(el, area, campos, perfil, aoSalvar) {
      el.innerHTML = '<div class="ffop-form" data-amb-guarda>' + campos.map(function (c) {
        var v = perfil[c[0]] || '';
        if (c[2] === 'texto') return '<label class="larga">' + c[1] + '<textarea data-pf="' + c[0] + '" maxlength="5000" placeholder="' + esc(c[3] || '') + '">' + esc(v) + '</textarea></label>';
        return '<label' + (c[2] === 'larga' ? ' class="larga"' : '') + '>' + c[1] + '<input data-pf="' + c[0] + '" value="' + esc(v) + '" placeholder="' + esc(c[3] || '') + '"></label>';
      }).join('') + '<div class="ffcli-pe larga"><button type="button" class="ffcli-bt" data-cancelar>Cancelar</button><button type="button" class="ffcli-bt princ" data-salvar>Salvar</button></div></div><div class="ffcli-erro" data-erro></div>';
      el.querySelector('[data-cancelar]').onclick = function () { estado.editando[area] = false; aoSalvar(); };
      el.querySelector('[data-salvar]').onclick = function () {
        var b = this, corpo = { acao: 'perfil_salvar', cliente_id: estado.id, area: area, campos: {} };
        el.querySelectorAll('[data-pf]').forEach(function (i) { corpo.campos[i.getAttribute('data-pf')] = i.value; });
        b.disabled = true;
        pedir('', corpo).then(function (r) {
          b.disabled = false;
          if (!r.ok) { el.querySelector('[data-erro]').textContent = r.error || 'Não foi possível salvar.'; return; }
          el.querySelector('.ffop-form').removeAttribute('data-amb-sujo');
          estado.ficha.perfil = r.data; estado.editando[area] = false; aoSalvar(); desenharFaixa();
          if (CA.dados && CA.dados[estado.id]) { CA.dados[estado.id].instagram = r.data.instagram; if (window.FfCliCrm) window.FfCliCrm.redesenhar(); }
          aviso('Salvo.', 'success');
        });
      };
    }
    function desenharRedes() {
      var p = estado.ficha.perfil;
      if (estado.editando.digital) {
        return formPerfil(elRedes, 'digital', [['instagram', 'Instagram', '', '@perfil ou link'], ['tiktok', 'TikTok', '', '@perfil'], ['youtube', 'YouTube', '', 'link do canal'],
          ['facebook', 'Facebook', '', 'link da página'], ['linkedin', 'LinkedIn', '', 'link'], ['site', 'Site', '', 'endereço'],
          ['briefing', 'Briefing da marca', 'texto', 'Tom de voz, público, cores, o que não fazer, referências']], p, desenharRedes);
      }
      var linhas = [
        ['Instagram', p.instagram ? linkExt(p.instagram_url, '@' + p.instagram) : ''], ['TikTok', p.tiktok ? linkExt(p.tiktok_url, '@' + p.tiktok) : ''],
        ['YouTube', linkExt(p.youtube)], ['Facebook', linkExt(p.facebook)], ['LinkedIn', linkExt(p.linkedin)], ['Site', linkExt(p.site)]
      ].filter(function (l) { return l[1]; });
      elRedes.innerHTML = (linhas.length ? '<dl class="ffop-dl">' + linhas.map(function (l) { return '<dt>' + l[0] + '</dt><dd>' + l[1] + '</dd>'; }).join('') + '</dl>'
        : '<div class="ffcli-vazio">Nenhuma rede cadastrada. Informe o Instagram do cliente para abrir o perfil daqui e ver o @ no card.</div>') +
        (p.briefing ? '<div class="ffcli-sub">Briefing da marca</div><div class="ffop-briefing">' + esc(p.briefing) + '</div>' : '') +
        '<div class="ffcli-pe">' + (p.atualizado_em ? '<span class="ffcli-vazio">Atualizado em ' + esc(dataAno(p.atualizado_em)) + (p.atualizado_por ? ' por ' + esc(p.atualizado_por) : '') + '</span>' : '') +
        '<button type="button" class="ffcli-link" data-editar>Editar redes e briefing</button></div>';
      elRedes.querySelector('[data-editar]').onclick = function () { estado.editando.digital = true; desenharRedes(); };
    }
    function miniPost(e) {
      var a = e.arquivo, ico = '<span class="ico">' + F.ico('imagem') + esc(a ? a.tipo : 'Sem arte do Drive') + '</span>';
      if (!a) return '<span class="ffop-post-mini">' + ico + '</span>';
      return '<button type="button" class="ffop-post-mini ver" data-ver="' + e.task_id + '" title="Pré-visualizar ' + esc(a.nome) + '">' +
        '<img loading="lazy" alt="" src="/api/drive.php?cliente=' + estado.id + '&miniatura=' + encodeURIComponent(a.file_id) + '&w=480" data-sem-mini></button>';
    }
    function desenharPostagem() {
      var p = estado.ficha.postagem, perfil = estado.ficha.perfil;
      if (!p) { elPost.innerHTML = '<div class="ffcli-vazio">Sem acesso aos quadros de Tarefas.</div>'; return; }
      var t = p.totais, pode = estado.ficha.pode_tarefas;
      var h = '<div class="ffop-cab"><label class="ffop-mes" style="margin:0">Mês <input type="month" value="' + esc(estado.mes) + '" data-mes data-amb-ignorar></label>' +
        (perfil.instagram ? linkExt(perfil.instagram_url, 'Abrir @' + perfil.instagram + ' no Instagram') : '<span class="ffcli-vazio">Instagram não informado (Visão geral, "Redes e marca")</span>') + '</div>' +
        '<div class="ffop-nums">' + tile('Postadas', num(t.publicadas), 'ok') + tile('Agendadas', num(t.agendadas)) + tile('A publicar', num(t.a_publicar), t.a_publicar ? 'atencao' : '') +
        tile('Atrasadas', num(t.atrasadas), t.atrasadas ? 'alerta' : '') + tile('Não vão ao ar', num(t.nao_se_aplica)) + '</div>';
      h += p.itens.length ? '<div class="ffop-post-grade">' + p.itens.map(function (e) {
        var sit = e.publicacao === 'publicado' ? 'Publicado' + (e.publicado_em ? ' ' + data(e.publicado_em) : '') : (PUB[e.publicacao] || e.publicacao_rotulo);
        var cls = e.atrasada ? 'alerta' : e.publicacao;
        return '<div class="ffop-post">' + '<div style="position:relative">' + miniPost(e) + '<span class="ffop-post-sit"><span class="ffop-pill ' + esc(cls) + '">' + esc(sit) + (e.atrasada ? ' · atrasada' : '') + '</span></span></div>' +
          '<div class="ffop-post-txt"><b>' + esc(e.titulo) + '</b><small>' + esc(e.tipo_rotulo) + ' · ' + esc(e.situacao === 'concluida' ? 'Entregue' : e.situacao === 'aprovacao' ? 'Com o cliente' : 'Em produção') +
          (e.prazo ? ' · prazo ' + esc(data(e.prazo)) : '') + '</small></div>' +
          '<div class="ffop-post-acoes">' + (e.link_publicacao ? linkExt(e.link_publicacao, 'Abrir post') : '') +
          '<a class="ffcli-link" href="/tarefas?amb=digital&board=' + e.board_id + '&tarefa=' + e.task_id + '">Abrir cartão</a>' +
          (pode ? '<button type="button" class="ffcli-link" data-link="' + e.task_id + '">' + (e.link_publicacao ? 'Trocar link' : 'Link do post') + '</button>' : '') + '</div>' +
          '<div class="ffop-post-link ffop-fora" data-link-box="' + e.task_id + '"><input type="url" maxlength="500" placeholder="https://instagram.com/p/..." value="' + esc(e.link_publicacao || '') + '" data-amb-ignorar>' +
          '<button type="button" class="ffcli-bt princ" data-link-salvar="' + e.task_id + '">Salvar</button></div></div>';
      }).join('') + '</div>' : '<div class="ffcli-vazio">Nenhuma entrega deste cliente no mês. As entregas são os cartões marcados como entrega (aba "Entrega" do cartão).</div>';
      h += '<div class="ffcli-vazio" style="margin-top:10px">A arte é o primeiro arquivo do Drive vinculado ao cartão. O link do post também pode ser informado no cartão, aba "Entrega".</div>';
      elPost.innerHTML = h;
      elPost.querySelector('[data-mes]').onchange = function () { if (this.value) { estado.mes = this.value; carregar(true); } };
      elPost.querySelectorAll('img[data-sem-mini]').forEach(function (img) {
        img.addEventListener('error', function () { var b = img.parentNode; b.classList.remove('ver'); b.innerHTML = '<span class="ico">' + F.ico('imagem') + 'Sem miniatura</span>'; });
      });
      elPost.querySelectorAll('[data-ver]').forEach(function (b) {
        b.onclick = function () {
          var e = p.itens.filter(function (x) { return String(x.task_id) === b.dataset.ver; })[0];
          if (e && e.arquivo && window.FfDrive && window.FfDrive.previa) window.FfDrive.previa({ id: e.arquivo.file_id, nome: e.arquivo.nome, tipo: e.arquivo.tipo, link: e.arquivo.link });
        };
      });
      elPost.querySelectorAll('[data-link]').forEach(function (b) {
        b.onclick = function () { var box = elPost.querySelector('[data-link-box="' + b.dataset.link + '"]'); box.classList.toggle('ffop-fora'); box.querySelector('input').focus(); };
      });
      elPost.querySelectorAll('[data-link-salvar]').forEach(function (b) {
        b.onclick = function () {
          var tid = +b.dataset.linkSalvar, inp = elPost.querySelector('[data-link-box="' + tid + '"] input');
          b.disabled = true;
          pedir('', { acao: 'entrega_link', task_id: tid, link: inp.value }).then(function (r) {
            b.disabled = false;
            if (!r.ok) { aviso(r.error || 'Não foi possível salvar.'); return; }
            p.itens.forEach(function (x) { if (x.task_id === tid) x.link_publicacao = r.link; });
            desenharPostagem(); aviso('Link do post salvo.', 'success');
          });
        };
      });
    }

    // ── Software ─────────────────────────────────────────────────────────
    function desenharResumoSoftware() {
      var sw = estado.ficha.software, pj = estado.ficha.projetos;
      if (!sw) { elResSw.innerHTML = '<div class="ffcli-vazio">Sem acesso aos quadros de Tarefas.</div>'; return; }
      elResSw.innerHTML = '<div class="ffop-nums">' + tile('Demandas abertas', num(sw.abertas)) + tile('Atrasadas', num(sw.atrasadas), sw.atrasadas ? 'alerta' : '') +
        tile('Concluídas no mês', num(sw.concluidas_mes), 'ok') + tile('Suporte aberto', num(sw.suporte_abertas), sw.suporte_abertas ? 'atencao' : '') +
        tile('Próximo prazo', sw.proximo ? esc(data(sw.proximo)) : '-') + tile('Quadros', num(sw.quadros.length)) + (pj ? tile('Projetos', num(pj.ligados.length)) : '') + '</div>' +
        (sw.sem_responsavel ? '<div class="ffcli-vazio">' + num(sw.sem_responsavel) + ' demanda(s) sem responsável.</div>' : '') +
        (!sw.quadros.length ? '<div class="ffcli-vazio">Nenhum quadro de Software deste cliente. <a class="ffcli-link" href="/tarefas?amb=software&novo=1&cliente=' + estado.id + '">Criar o quadro do projeto</a></div>' : '');
    }
    function desenharTecnico() {
      var p = estado.ficha.perfil;
      if (estado.editando.software) {
        return formPerfil(elTec, 'software', [['sistema_url', 'Endereço do sistema', 'larga', 'https://...'], ['repositorio', 'Repositório', 'larga', 'https://github.com/...'],
          ['stack', 'Stack', '', 'PHP, MySQL, n8n...'], ['hospedagem', 'Hospedagem', '', 'EC2, Hostinger, Vercel...'],
          ['notas_tecnicas', 'Notas técnicas', 'texto', 'Integrações, rotinas, cuidados. Senha não: acessos ficam em Projetos.']], p, desenharTecnico);
      }
      var linhas = [['Sistema', linkExt(p.sistema_url)], ['Repositório', linkExt(p.repositorio)], ['Stack', esc(p.stack || '')], ['Hospedagem', esc(p.hospedagem || '')]].filter(function (l) { return l[1]; });
      elTec.innerHTML = (linhas.length ? '<dl class="ffop-dl">' + linhas.map(function (l) { return '<dt>' + l[0] + '</dt><dd>' + l[1] + '</dd>'; }).join('') + '</dl>'
        : '<div class="ffcli-vazio">Nada cadastrado. Informe o endereço do sistema, o repositório e a stack do cliente.</div>') +
        (p.notas_tecnicas ? '<div class="ffcli-sub">Notas técnicas</div><div class="ffop-briefing">' + esc(p.notas_tecnicas) + '</div>' : '') +
        '<div class="ffcli-pe">' + (p.atualizado_em ? '<span class="ffcli-vazio">Atualizado em ' + esc(dataAno(p.atualizado_em)) + (p.atualizado_por ? ' por ' + esc(p.atualizado_por) : '') + '</span>' : '') +
        '<button type="button" class="ffcli-link" data-editar>Editar perfil técnico</button></div>';
      elTec.querySelector('[data-editar]').onclick = function () { estado.editando.software = true; desenharTecnico(); };
    }
    function desenharQuadrosSoftware() {
      var sw = estado.ficha.software;
      if (!sw) { elQSw.innerHTML = '<div class="ffcli-vazio">Sem acesso aos quadros de Tarefas.</div>'; return; }
      elQSw.innerHTML = (sw.quadros.length ? '<div class="ffop-quadros">' + sw.quadros.map(function (b) {
        return '<a class="ffop-q" href="/tarefas?amb=software&board=' + b.id + '"><span><b>' + esc(b.nome) + '</b><small>' + esc(b.modelo === 'suporte' ? 'Suporte' : b.modelo === 'demandas' ? 'Demandas' : b.modelo === 'etapas' ? 'Etapas do projeto' : 'Quadro de Software') + '</small></span>' +
          '<span class="ff-pill' + (b.atrasadas ? ' ff-pill-off' : '') + '">' + num(b.abertas) + ' aberta(s)' + (b.atrasadas ? ' · ' + num(b.atrasadas) + ' atrasada(s)' : '') + (b.proximo ? ' · próximo ' + data(b.proximo) : '') + '</span></a>';
      }).join('') + '</div>' : '<div class="ffcli-vazio">Nenhum quadro de Software deste cliente.</div>') +
        (estado.ficha.pode_tarefas ? '<div class="ffcli-pe"><a class="ffcli-link" href="/tarefas?amb=software&novo=1&cliente=' + estado.id + '">+ Quadro de Software</a></div>' : '');
    }
    function desenharDemandas() {
      var sw = estado.ficha.software;
      if (!sw) { elDem.innerHTML = '<div class="ffcli-vazio">Sem acesso aos quadros de Tarefas.</div>'; return; }
      elDem.innerHTML = sw.demandas.length ? '<table class="ffop-tab"><thead><tr><th>Demanda</th><th>Prioridade</th><th>Responsável</th><th>Prazo</th></tr></thead><tbody>' + sw.demandas.map(function (t) {
        return '<tr><td><a href="/tarefas?amb=software&board=' + t.board_id + '&tarefa=' + t.id + '">' + esc(t.titulo) + '</a><small>' + esc(t.quadro) + (t.coluna ? ' · ' + esc(t.coluna) : '') + '</small></td>' +
          '<td>' + esc(PRIO[t.prioridade] || t.prioridade || '-') + '</td><td>' + esc(t.responsavel || 'Sem responsável') + '</td>' +
          '<td' + (t.atrasada ? ' class="ffop-pend"' : '') + '>' + esc(data(t.prazo) || '-') + (t.atrasada ? ' (atrasada)' : '') + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div class="ffcli-vazio">Nenhuma demanda aberta.</div>';
    }
    function desenharProjetos() {
      var pj = estado.ficha.projetos;
      sec(elProj).classList.toggle('ffop-fora', !pj);
      if (!pj) return;
      var h = pj.ligados.length ? '<div class="ffop-lista">' + pj.ligados.map(function (p) {
        return '<div class="ffop-li"><span><b>' + esc(p.nome) + '</b><small>' + esc(p.tipo_rotulo) + ' · ' + esc(p.status_rotulo) + (p.stack ? ' · ' + esc(p.stack) : '') + (p.responsavel ? ' · ' + esc(p.responsavel) : '') + '</small>' +
          '<small>' + [linkExt(p.url), linkExt(p.repositorio, 'repositório')].filter(Boolean).join(' · ') + '</small></span>' +
          '<span style="display:flex;gap:10px;align-items:center"><a class="ffcli-link" href="/projetos?p=' + encodeURIComponent(p.slug) + '">Abrir</a><button type="button" class="ffcli-link perigo" data-desligar="' + p.id + '">Desligar</button></span></div>';
      }).join('') + '</div>' : '<div class="ffcli-vazio">Nenhum projeto da pasta Projetos ligado a este cliente.</div>';
      if (pj.livres.length) {
        h += '<div class="ffcli-vinc"><select data-proj><option value="">Ligar um projeto…</option>' + pj.livres.map(function (p) {
          return '<option value="' + p.id + '">' + esc((p.subprojeto ? '  ' : '') + p.nome + (p.cliente ? ' (' + p.cliente + ')' : '')) + '</option>';
        }).join('') + '</select><button type="button" class="ffcli-bt" data-ligar>Ligar</button></div>';
      }
      h += '<div class="ffcli-erro" data-erro></div>';
      elProj.innerHTML = h;
      function ligar(pid, sim) {
        pedir('', { acao: 'projeto_ligar', cliente_id: estado.id, projeto_id: pid, ligar: sim }).then(function (r) {
          if (!r.ok) { elProj.querySelector('[data-erro]').textContent = r.error || 'Não foi possível salvar.'; return; }
          estado.ficha.projetos = r.data; desenharProjetos(); desenharResumoSoftware();
        });
      }
      var bl = elProj.querySelector('[data-ligar]');
      if (bl) bl.onclick = function () { var v = +elProj.querySelector('[data-proj]').value; if (v) ligar(v, true); };
      elProj.querySelectorAll('[data-desligar]').forEach(function (b) { b.onclick = function () { ligar(+b.dataset.desligar, false); }; });
    }

    // ── Comercial ────────────────────────────────────────────────────────
    function desenharNegociacoes() {
      var n = estado.ficha.negociacoes;
      sec(elNeg).classList.toggle('ffop-fora', !n);
      if (!n) return;
      var ST = { aberto: ['Em aberto', 'pendente'], fechado: ['Fechado', 'ok'], perdido: ['Perdido', 'alerta'] };
      elNeg.innerHTML = (n.itens.length ? '<div class="ffop-nums">' + tile('Fechado', esc(brl(n.fechado)), 'ok') + tile('Em aberto', esc(brl(n.aberto))) + tile('Negociações', num(n.itens.length)) + '</div>' +
        '<div class="ffop-lista">' + n.itens.map(function (c) {
          var st = ST[c.status] || [c.status, ''];
          return '<div class="ffop-li"><span><b><a href="/prospeccao?open=' + c.id + '">' + esc(c.titulo || 'Prospecção #' + c.id) + '</a></b>' +
            '<small>' + [c.etapa, c.vendedor ? 'vendedor ' + c.vendedor : '', c.origem ? 'origem ' + c.origem : '', c.area_servico ? AREA[c.area_servico] || (c.area_servico === 'ambos' ? 'Software e Digital' : c.area_servico) : ''].filter(Boolean).map(esc).join(' · ') + '</small>' +
            '<small>Entrou em ' + esc(dataAno(c.criado_em)) + (c.fechado_em ? ' · fechado em ' + esc(dataAno(c.fechado_em)) : '') + '</small></span>' +
            '<span style="text-align:right"><b>' + esc(brl(c.valor)) + '</b><br><span class="ffop-pill ' + st[1] + '">' + esc(st[0]) + '</span></span></div>';
        }).join('') + '</div>' : '<div class="ffcli-vazio">Nenhuma prospecção ligada a este cliente (cliente cadastrado direto).</div>');
    }

    // ── Produção e entregas (Digital e Empresa) ──────────────────────────
    function desenharQuadros() {
      var d = estado.dados;
      var lista = AMB === 'digital' ? d.quadros.filter(function (b) { return b.area === 'digital'; }) : d.quadros;
      var h = lista.length ? '<div class="ffop-quadros">' + lista.map(function (b) {
        var area = b.area || '';
        return '<a class="ffop-q" href="/tarefas?' + (area === 'software' || area === 'digital' ? 'amb=' + area + '&' : '') + 'board=' + b.id + '"><span><b>' + esc(b.nome) + '</b><small>' + esc(AREA[area] || 'Tarefas') + '</small></span>' +
          '<span class="ff-pill' + (b.atrasadas ? ' ff-pill-off' : '') + '">' + num(b.abertas) + ' aberta(s)' + (b.atrasadas ? ' · ' + num(b.atrasadas) + ' atrasada(s)' : '') + (b.proximo ? ' · próximo ' + data(b.proximo) : '') + '</span></a>';
      }).join('') + '</div>' : '<div class="ffcli-vazio">Nenhum quadro' + (AMB === 'digital' ? ' de Digital' : '') + ' deste cliente.</div>';
      if (d.pode_tarefas) h += '<div class="ffcli-pe"><a class="ffcli-link" href="/tarefas?amb=digital&novo=1&cliente=' + d.cliente.id + '">+ Quadro de Digital</a>' +
        (AMB === 'empresa' ? '<a class="ffcli-link" href="/tarefas?amb=software&novo=1&cliente=' + d.cliente.id + '">+ Quadro de Software</a>' : '') + '</div>';
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
          return tile(x[0], num(x[1]), x[2]);
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
    function desenharFaixa() {
      if (!celAmb) return;
      var v = celAmb.querySelector('.ff-cel-valor'), p = estado.ficha && estado.ficha.perfil;
      if (!p) { v.innerHTML = '<span class="ff-pill">Carregando</span>'; return; }
      if (AMB === 'digital') v.innerHTML = p.instagram ? '<a class="ff-pill" href="' + esc(p.instagram_url) + '" target="_blank" rel="noopener noreferrer">@' + esc(p.instagram) + '</a>' : '<span class="ff-pill ff-pill-off">Não informado</span>';
      else v.innerHTML = p.sistema_url ? '<a class="ff-pill" href="' + esc(p.sistema_url) + '" target="_blank" rel="noopener noreferrer">' + esc(host(p.sistema_url)) + '</a>' : '<span class="ff-pill ff-pill-off">Não informado</span>';
    }

    var TODOS = [elOper, elResDg, elResSw, elRedes, elTec, elNeg, elQuadros, elFreq, elCamp, elMes, elLista, elPost, elQSw, elDem, elProj];
    function carregar(forcar) {
      var id = campoId.value ? +campoId.value : null;
      if (!forcar && id === estado.id && estado.dados) return;
      if (id !== estado.id) estado.editando = {};
      estado.id = id; estado.dados = null; estado.ficha = null;
      if (!id) {
        TODOS.concat(elDrive ? [elDrive] : []).forEach(function (e) { e.innerHTML = '<div class="ffcli-vazio">Salve o cliente primeiro.</div>'; });
        desenharFaixa();
        return;
      }
      TODOS.forEach(function (e) { e.innerHTML = '<div class="ffcli-vazio">Carregando…</div>'; });
      desenharFaixa();
      Promise.all([pedir('?cliente=' + id + '&mes=' + estado.mes), pedir('?ficha=' + id + '&mes=' + estado.mes), apoio()]).then(function (res) {
        var r = res[0], f = res[1];
        if (estado.id !== id) return;
        if (!r.ok || !f.ok) { var msg = '<div class="ffcli-vazio">' + esc((r.ok ? f : r).error || 'Não foi possível carregar.') + '</div>'; TODOS.forEach(function (e) { e.innerHTML = msg; }); return; }
        estado.dados = r.data; estado.ficha = f.data;
        desenharResumo(); desenharFora(); desenharFaixa();
        desenharResumoDigital(); desenharRedes(); desenharPostagem();
        desenharResumoSoftware(); desenharTecnico(); desenharQuadrosSoftware(); desenharDemandas(); desenharProjetos();
        desenharNegociacoes();
        desenharQuadros(); desenharFrequencia(); desenharCampanhas(); desenharMes(); desenharLista();
      });
      if (elDrive && (forcar !== true)) window.FfDrive.painel(elDrive, id);
    }
    new MutationObserver(function () {
      if (modal.classList.contains('open')) { aplicar(); carregar(); }
      else { estado.id = -1; carregarCards(); }
    }).observe(modal, { attributes: true, attributeFilter: ['class'] });
    if (modal.classList.contains('open')) carregar();
    setInterval(function () { if (modal.classList.contains('open') && (campoId.value ? +campoId.value : null) !== estado.id) carregar(); }, 1000);
  }

  function iniciar() { carregarCards(); montar(0); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
