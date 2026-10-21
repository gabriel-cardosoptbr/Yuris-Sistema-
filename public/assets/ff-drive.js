/* ═══════════════════════════════════════════════════════════════════════
   ff-drive.js: o Google Drive do cliente (ambientes da Inovaize).
   window.FfDrive.painel(el, clienteId[, {raiz, aoSecoes}])  a pasta do cliente (ou de uma
                                           seção) na ficha: navegar, pré-visualizar, baixar um
                                           ou tudo (.zip), selecionar, enviar direto ao Google
                                           (qualquer tamanho, arrastando), nova pasta, link e
                                           compartilhamento, criar seção com a pasta aberta
   window.FfDrive.escolher(el, clienteId, aoEscolher)  escolher um arquivo
                                           da pasta (material, cartão)
   window.FfDrive.previa({id, nome, tipo, link})  pré-visualizar um arquivo
   API em /api/drive.php. A lista diz sempre de quando é: "sincronizado às
   14:32"; se o Drive falhar, avisa e marca a lista como a última conhecida.
   Atualiza sozinha a cada minuto enquanto está na tela.
   ═══════════════════════════════════════════════════════════════════════ */
window.FfDrive = (function () {
  'use strict';
  var CSRF = function () { return (window.FF_AGENDA && window.FF_AGENDA.csrf) || window.YURIS_CSRF || ''; };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function quando(v) { if (!v) return ''; var s = String(v); return s.slice(8, 10) + '/' + s.slice(5, 7) + ' às ' + s.slice(11, 16); }
  function pedir(qs, corpo) {
    var op = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (corpo instanceof FormData) { op.method = 'POST'; op.headers['X-CSRF-Token'] = CSRF(); op.body = corpo; }
    else if (corpo) { op.method = 'POST'; op.headers['Content-Type'] = 'application/json'; op.headers['X-CSRF-Token'] = CSRF(); op.body = JSON.stringify(corpo); }
    return fetch('/api/drive.php' + (qs || ''), op).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão com o servidor.' }; });
  }
  var CSS = '.ffd{display:flex;flex-direction:column;gap:10px;font-size:13px}.ffd-topo{display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap}' +
    '.ffd-cam{display:flex;flex-wrap:wrap;gap:4px;align-items:center;font-size:12.5px}.ffd-cam button{background:none;border:0;padding:2px 4px;color:var(--ff-marca,#015DFC);font:inherit;font-weight:700;cursor:pointer}' +
    '.ffd-cam span{opacity:.5}.ffd-lista{list-style:none;margin:0;padding:0;display:flex;flex-direction:column}.ffd-lista li{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 4px;border-top:1px solid rgba(17,29,45,.08)}' +
    '.ffd-lista li:first-child{border-top:0}.ffd-nome{display:flex;flex-direction:column;min-width:0}.ffd-nome b{font-size:13px;overflow-wrap:anywhere}.ffd-nome small{font-size:11.5px;opacity:.7}' +
    '.ffd-nome button{background:none;border:0;padding:0;text-align:left;color:var(--ff-marca,#015DFC);font:inherit;font-weight:700;cursor:pointer}.ffd-acoes{display:flex;gap:6px;flex-wrap:wrap}' +
    '.ffd-sync{font-size:12px;opacity:.8}.ffd-aviso{padding:10px 12px;border-radius:10px;background:#FFF4D6;color:#8A4B06;font-size:12.5px;line-height:1.45}.ffd-aviso.erro{background:#FEE2E2;color:#991B1B}.ffd-aviso.ok{background:#DCFCE7;color:#166534}' +
    '.ffd-nav{border:1px solid rgba(17,29,45,.10);border-radius:12px;padding:10px;display:flex;flex-direction:column;gap:10px}.ffd-nav-topo{display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap}' +
    '.ffd-abrir{display:flex;flex-direction:column;background:none;border:0;padding:0;font:inherit;color:inherit;text-align:left;cursor:pointer;width:100%}.ffd-abrir:hover b{color:var(--ff-marca,#015DFC)}' +
    '.ffd-nav-arqs{font-size:12px;display:flex;flex-wrap:wrap;gap:4px 6px;align-items:center}.ffd-nav-arqs span{padding:2px 8px;border-radius:99px;background:rgba(17,29,45,.06);overflow-wrap:anywhere}' +
    'html:not([data-theme="light"]) .ffd-nav{border-color:rgba(255,255,255,.10)}html:not([data-theme="light"]) .ffd-nav-arqs span{background:rgba(255,255,255,.08)}' +
    '.ffd-linkbox{display:flex;flex-wrap:wrap;gap:6px 8px;align-items:center;padding:8px 10px;border-radius:10px;background:rgba(17,29,45,.04)}.ffd-link-rot{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;opacity:.7}' +
    '.ffd-linkbox input{flex:1;min-width:180px;padding:6px 9px;border-radius:8px;border:1px solid rgba(17,29,45,.16);font:inherit;font-size:12px;background:transparent;color:inherit}' +
    '.ffd-pill{display:inline-flex;align-items:center;height:24px;padding:0 10px;border-radius:99px;font-size:11.5px;font-weight:700;background:#EEF0F3;color:#374151}.ffd-pill.ok{background:#D1FAE5;color:#047857}.ffd-pill.restrito{background:#FFF4D6;color:#B45309}' +
    '[data-link-msg]{font-size:12px;font-weight:700;color:#047857}' +
    '.ffd-cel{position:relative;display:flex;flex-direction:column;min-width:0}.ffd-cel .ffd-item{border-bottom-left-radius:0;border-bottom-right-radius:0;border-bottom:0}.ffd-cel.marcado .ffd-item,.ffd-cel.marcado .ffd-cel-acoes{border-color:var(--ff-marca,#015DFC)}' +
    '.ffd-cel-acoes{display:flex;justify-content:space-between;align-items:center;gap:6px;padding:6px 10px;border:1px solid rgba(17,29,45,.10);border-top:0;border-radius:0 0 12px 12px;background:var(--ff-card,#fff)}.ffd-cel-acoes small{font-size:11px;opacity:.7}' +
    '.ffd-marca{position:absolute;top:8px;left:8px;background:#fff;border-radius:6px;padding:3px;display:flex;box-shadow:0 1px 4px rgba(0,0,0,.25)}.ffd-marca input{width:18px;height:18px;margin:0;cursor:pointer}' +
    '.ffd-selbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:8px 10px;border-radius:10px;background:rgba(var(--ff-marca-rgb,1,93,252),.08)}' +
    '.ffd-envio{display:grid;grid-template-columns:minmax(0,1fr) 140px 120px;gap:10px;align-items:center;font-size:12px;padding:4px 0}.ffd-envio-nome{overflow-wrap:anywhere}.ffd-envio-nome small{opacity:.7}.ffd-envio-barra{height:6px;border-radius:99px;background:rgba(17,29,45,.10);overflow:hidden}.ffd-envio-barra i{display:block;height:100%;background:var(--ff-marca,#015DFC)}' +
    '.ffd-envio.ok .ffd-envio-sit{color:#047857;font-weight:700}.ffd-envio.erro .ffd-envio-sit{color:#B91C1C;font-weight:700}.ffd-envio.ok .ffd-envio-barra i{background:#10B981}' +
    '[data-ffd-solta].soltando{outline:2px dashed var(--ff-marca,#015DFC);outline-offset:4px;border-radius:12px}.ffd-vazia{padding:22px;text-align:center;border:1px dashed rgba(17,29,45,.18);border-radius:12px;font-size:12.5px;opacity:.8}' +
    '@media (max-width:640px){.ffd-envio{grid-template-columns:1fr}}' +
    'html:not([data-theme="light"]) .ffd-linkbox{background:rgba(255,255,255,.05)}html:not([data-theme="light"]) .ffd-cel-acoes{background:#13213A;border-color:rgba(255,255,255,.10)}' +
    '.ffd-busca{display:flex;gap:6px}.ffd-busca input{flex:1;padding:7px 10px;border-radius:9px;border:1px solid rgba(17,29,45,.16);font:inherit;background:transparent;color:inherit}' +
    '.ffd-velha{opacity:.65}.ffd-pessoas{display:flex;flex-direction:column;gap:4px}.ffd-pessoas label{display:flex;gap:6px;align-items:center}' +
    'html:not([data-theme="light"]) .ffd-aviso{background:rgba(180,83,9,.25);color:#FDE68A}html:not([data-theme="light"]) .ffd-aviso.erro{background:rgba(185,28,28,.25);color:#FECACA}html:not([data-theme="light"]) .ffd-aviso.ok{background:rgba(21,128,61,.25);color:#BBF7D0}html:not([data-theme="light"]) .ffd-lista li{border-color:rgba(255,255,255,.08)}';
  CSS += '.ffd-grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}' +
    '.ffd-item{display:flex;flex-direction:column;border:1px solid rgba(17,29,45,.10);border-radius:12px;overflow:hidden;background:var(--ff-card,#fff);cursor:pointer;text-align:left;padding:0;font:inherit;color:inherit;min-width:0}' +
    '.ffd-item:hover,.ffd-item:focus-visible{border-color:var(--ff-marca,#015DFC);outline:none;box-shadow:0 6px 16px rgba(17,29,45,.10)}' +
    '.ffd-mini{position:relative;height:112px;background:rgba(17,29,45,.05);display:flex;align-items:center;justify-content:center;overflow:hidden}' +
    '.ffd-mini img{width:100%;height:100%;object-fit:cover;display:block}' +
    '.ffd-mini .ffd-ico{display:flex;flex-direction:column;align-items:center;gap:4px;font-size:11px;font-weight:700;opacity:.7}.ffd-mini .ffd-ico svg{width:34px;height:34px}' +
    '.ffd-item-txt{padding:8px 10px;display:flex;flex-direction:column;gap:2px;min-width:0}.ffd-item-txt b{font-size:12.5px;overflow-wrap:anywhere;line-height:1.3}.ffd-item-txt small{font-size:11px;opacity:.7}' +
    '.ffd-item.pasta .ffd-mini{background:rgba(var(--ff-marca-rgb,1,93,252),.08);color:var(--ff-marca,#015DFC)}' +
    '.ffd-prev-fundo{position:fixed;inset:0;z-index:10060;background:rgba(7,15,28,.72);display:flex;align-items:center;justify-content:center;padding:16px}' +
    '.ffd-prev{width:min(1100px,100%);height:min(86vh,900px);background:#fff;border-radius:14px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(7,15,28,.4)}' +
    '.ffd-prev-cab{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(17,29,45,.10);flex-wrap:wrap}' +
    '.ffd-prev-cab b{font-size:14px;overflow-wrap:anywhere;color:#1F2937}.ffd-prev-cab small{display:block;font-size:11.5px;color:#767676}.ffd-prev-acoes{display:flex;gap:8px;align-items:center}' +
    '.ffd-prev iframe{flex:1;border:0;width:100%;background:#F4F6F9}.ffd-prev-nota{padding:6px 14px;font-size:11.5px;color:#767676;border-top:1px solid rgba(17,29,45,.08)}' +
    'html:not([data-theme="light"]) .ffd-item{background:#13213A;border-color:rgba(255,255,255,.10)}html:not([data-theme="light"]) .ffd-mini{background:rgba(255,255,255,.05)}' +
    'html:not([data-theme="light"]) .ffd-prev{background:#13213A}html:not([data-theme="light"]) .ffd-prev-cab b{color:#E7EBF1}html:not([data-theme="light"]) .ffd-prev iframe{background:#0F1B2D}';

  var ICO_PASTA = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
  var ICO_ARQ = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
  function miniUrl(clienteId, f, w) { return '/api/drive.php?cliente=' + clienteId + '&miniatura=' + encodeURIComponent(f.id) + '&w=' + (w || 480) + '&v=' + encodeURIComponent(f.modificado_em || ''); }
  // Cartão de um item: pasta abre, arquivo mostra a miniatura (ou o ícone do tipo, se o Drive não tiver).
  function cartaoItem(clienteId, f, i) {
    if (f.pasta) return '<button type="button" class="ffd-item pasta" data-pasta="' + esc(f.id) + '"><span class="ffd-mini"><span class="ffd-ico">' + ICO_PASTA + 'Pasta</span></span>' +
      '<span class="ffd-item-txt"><b>' + esc(f.nome) + '</b><small>' + (f.modificado_em ? 'atualizada em ' + esc(quando(f.modificado_em)) : 'pasta') + '</small></span></button>';
    return '<button type="button" class="ffd-item" data-ver="' + i + '" title="Pré-visualizar ' + esc(f.nome) + '"><span class="ffd-mini">' +
      '<img class="ffd-img" loading="lazy" alt="" data-tipo="' + esc(f.tipo) + '" src="' + miniUrl(clienteId, f) + '">' +
      '</span><span class="ffd-item-txt"><b>' + esc(f.nome) + '</b><small>' + esc(f.tipo) + (f.modificado_em ? ' · ' + esc(quando(f.modificado_em)) : '') + '</small></span></button>';
  }
  // Arquivo sem miniatura no Drive (404): no lugar da imagem, o ícone e o tipo.
  function semMiniatura(raiz) {
    raiz.querySelectorAll('img.ffd-img').forEach(function (img) {
      img.addEventListener('error', function () {
        var s = document.createElement('span');
        s.className = 'ffd-ico';
        s.innerHTML = ICO_ARQ + esc(img.getAttribute('data-tipo') || 'Arquivo');
        img.replaceWith(s);
      }, { once: true });
    });
  }
  // Pré-visualização dentro do sistema (o visualizador do próprio Drive: imagem, vídeo, PDF, documento).
  function previa(f, baixar) {
    var fundo = document.createElement('div');
    fundo.className = 'ffd-prev-fundo';
    fundo.innerHTML = '<div class="ffd-prev" role="dialog" aria-modal="true" aria-label="Pré-visualização de ' + esc(f.nome) + '"><div class="ffd-prev-cab"><span><b>' + esc(f.nome) + '</b><small>' + esc(f.tipo) +
      (f.modificado_em ? ' · atualizado em ' + esc(quando(f.modificado_em)) : '') + '</small></span><span class="ffd-prev-acoes">' +
      (baixar ? '<a class="ffcli-link" href="' + esc(baixar) + '" download>Baixar</a>' : '') +
      (f.link ? '<a class="ffcli-link" href="' + esc(f.link) + '" target="_blank" rel="noopener noreferrer">Abrir no Drive</a>' : '') +
      '<button type="button" class="ffcli-bt" data-fechar>Fechar</button></span></div>' +
      '<iframe src="https://drive.google.com/file/d/' + encodeURIComponent(f.id) + '/preview" allow="autoplay" title="' + esc(f.nome) + '"></iframe>' +
      '<div class="ffd-prev-nota">Se a pré-visualização pedir acesso, sua conta Google ainda não tem acesso à pasta do cliente: veja "Acessos da equipe".</div></div>';
    document.body.appendChild(fundo);
    function fechar() { fundo.remove(); document.removeEventListener('keydown', tecla, true); }
    function tecla(e) { if (e.key === 'Escape') { e.preventDefault(); fechar(); } }
    document.addEventListener('keydown', tecla, true);
    fundo.querySelector('[data-fechar]').onclick = fechar;
    fundo.querySelector('[data-fechar]').focus();
    fundo.addEventListener('click', function (e) { if (e.target === fundo) fechar(); });
  }

  function estilo() { if (document.getElementById('ffdCss')) return; var s = document.createElement('style'); s.id = 'ffdCss'; s.textContent = CSS; document.head.appendChild(s); }
  function botao(txt, attr, principal) { return '<button type="button" class="ffcli-bt' + (principal ? ' princ' : '') + '" ' + attr + '>' + esc(txt) + '</button>'; }

  // ── Painel da ficha ──────────────────────────────────────────────────
  // painel(el, clienteId[, op]): op.raiz = id de uma subpasta (seção do Drive: a navegação
  // não sobe acima dela); op.aoSecoes(lista) = a ficha redesenha as seções depois de criar uma.
  function painel(el, clienteId, op) {
    op = op || {};
    estilo();
    var est = { cliente: clienteId, pasta: op.raiz || null, dados: null, timer: null, aviso: null, selecao: null, enviando: 0 };
    el.innerHTML = '<div class="ffcli-vazio">Carregando o Drive…</div>';
    if (el._ffdTimer) clearInterval(el._ffdTimer);

    pedir('?estado=1').then(function (r) { if (r.ok && r.data.aviso) { est.aviso = r.data.aviso; } carregar(false); });

    function volta() { return location.pathname + '?open=' + clienteId + (location.search.indexOf('amb=') >= 0 ? '&' + location.search.replace(/^\?/, '').split('&').filter(function (p) { return p.indexOf('amb=') === 0; }).join('&') : ''); }
    function carregar(forcar, pasta) {
      if (est.cliente !== clienteId) return;
      if (pasta !== undefined) { est.pasta = pasta; est.selecao = null; }
      var qs = '?cliente=' + clienteId + (est.pasta ? '&pasta=' + encodeURIComponent(est.pasta) : '') + (forcar ? '&forcar=1' : '');
      return pedir(qs).then(function (r) {
        if (!document.body.contains(el)) return;
        if (!r.ok) { el.innerHTML = '<div class="ffd-aviso erro" role="alert">' + esc(r.error || 'Não foi possível abrir o Drive.') + '</div>'; return; }
        est.dados = r.data;
        desenhar();
      });
    }
    function aviso() {
      if (!est.aviso) return '';
      var a = est.aviso; est.aviso = null;
      return '<div class="ffd-aviso ' + (a.ok ? 'ok' : 'erro') + '" role="status">' + esc(a.msg) + '</div>';
    }
    function urlBaixar(f) { return '/api/drive.php?cliente=' + clienteId + '&baixar=' + encodeURIComponent(f.id); }
    function urlZip(ids) { return '/api/drive.php?cliente=' + clienteId + '&zip=1' + (est.dados && est.dados.atual_id ? '&pasta=' + encodeURIComponent(est.dados.atual_id) : '') + (ids ? '&ids=' + encodeURIComponent(ids.join(',')) : ''); }
    function arquivos() { return (est.dados.itens || []).filter(function (f) { return !f.pasta; }); }
    function desenhar() {
      var d = est.dados, c = d.conexao;
      if (!c.configurado) { el.innerHTML = aviso() + '<div class="ffd-aviso">O Google Drive ainda não foi configurado no servidor. Depois de configurado, um administrador conecta a conta corporativa aqui.</div>'; return; }
      if (!c.conectado) {
        el.innerHTML = aviso() + '<div class="ffd-aviso">Nenhuma conta Google conectada' + (c.dominio ? ' (só contas @' + esc(c.dominio) + ')' : '') + '.</div>' +
          (c.pode_conectar ? '<a class="ffcli-bt princ" style="text-decoration:none;align-self:flex-start" href="/api/drive_oauth.php?iniciar=1&volta=' + encodeURIComponent(volta()) + '">Conectar conta Google</a>'
            : '<div class="ffcli-vazio">Peça a um administrador para conectar a conta Google da empresa.</div>');
        return;
      }
      if (!d.itens) { if (op.raiz) { el.innerHTML = '<div class="ffd-aviso">O cliente não tem mais a pasta do Drive ligada. Ligue a pasta no bloco "Google Drive do cliente".</div>'; return; } desenharEscolhaPasta(); return; }
      // Numa seção, o caminho começa na pasta da seção.
      var cam = d.caminho, k0 = op.raiz ? cam.map(function (p) { return p.id; }).indexOf(op.raiz) : 0;
      if (k0 > 0) cam = cam.slice(k0);
      var arqs = arquivos(), sel = est.selecao;
      var h = aviso() + '<div class="ffd" data-ffd-solta>';
      h += '<div class="ffd-topo"><div class="ffd-cam">' + cam.map(function (p, i) {
        return (i ? '<span>/</span>' : '') + (p.id === d.atual_id ? '<b>' + esc(p.nome) + '</b>' : '<button type="button" data-pasta="' + esc(p.id) + '">' + esc(p.nome) + '</button>');
      }).join('') + '</div><div class="ffd-acoes">' + botao('Enviar arquivos', 'data-enviar', true) + botao('Nova pasta', 'data-nova') +
        (arqs.length ? botao('Baixar tudo (.zip)', 'data-zip') + botao(sel ? 'Cancelar seleção' : 'Selecionar', 'data-selecionar') : '') + botao('Atualizar', 'data-atualizar') + '</div></div>';
      h += '<div class="ffd-linkbox" data-linkbox><span class="ffcli-vazio">Carregando o link da pasta…</span></div>';
      h += '<div data-nova-form></div><div data-envios></div>';
      if (!d.atual) {
        h += '<div class="ffd-aviso erro" role="alert">Não foi possível ler o Drive agora: ' + esc(d.erro || 'erro desconhecido') + ' ' +
          (d.sincronizado_em ? 'A lista abaixo é a última conhecida, de ' + esc(quando(d.sincronizado_em)) + ', e pode estar desatualizada.' : 'Ainda não há lista desta pasta.') + '</div>';
      }
      if (sel) h += '<div class="ffd-selbar"><b data-sel-n>' + Object.keys(sel).length + ' selecionado(s)</b>' + botao('Todos', 'data-sel-todos') + botao('Baixar selecionados (.zip)', 'data-sel-zip', true) + '</div>';
      h += d.itens.length ? '<div class="ffd-grade' + (d.atual ? '' : ' ffd-velha') + '">' + d.itens.map(function (f, i) {
        if (f.pasta) return cartaoItem(clienteId, f, i);
        return '<div class="ffd-cel' + (sel && sel[f.id] ? ' marcado' : '') + '">' + cartaoItem(clienteId, f, i) +
          (sel ? '<label class="ffd-marca" title="Selecionar"><input type="checkbox" data-sel="' + esc(f.id) + '"' + (sel[f.id] ? ' checked' : '') + '></label>' : '') +
          '<div class="ffd-cel-acoes"><a class="ffcli-link" href="' + urlBaixar(f) + '" download>Baixar</a>' + (f.tamanho ? '<small>' + tam(f.tamanho) + '</small>' : '') + '</div></div>';
      }).join('') + '</div>' : '<div class="ffd-vazia">Pasta vazia. Arraste arquivos para cá ou use "Enviar arquivos".</div>';
      h += '<div class="ffd-topo"><span class="ffd-sync">' + (d.sincronizado_em ? (d.atual ? 'Sincronizado ' : 'Última leitura ') + esc(quando(d.sincronizado_em)) : '') + ' · arraste arquivos para dentro do painel para enviar</span>' +
        '<span class="ffd-acoes">' + botao('Acessos da equipe', 'data-acessos') +
        (op.aoSecoes && d.atual_id !== (op.raiz || d.pasta.id) ? botao('Criar seção com esta pasta', 'data-secao') : '') +
        (c.pode_conectar && !op.raiz ? botao('Trocar pasta', 'data-trocar') : '') + '</span></div>';
      h += '<input type="file" data-arquivo multiple hidden><div data-extra></div></div>';
      el.innerHTML = h;
      ligar();
      desenharLink();
    }
    function tam(b) { b = +b || 0; return b >= 1073741824 ? (b / 1073741824).toFixed(1).replace('.', ',') + ' GB' : b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }

    // ── Link da pasta e compartilhamento ─────────────────────────────────
    function desenharLink() {
      var box = el.querySelector('[data-linkbox]'), pastaId = est.dados.atual_id;
      if (!box) return;
      pedir('?cliente=' + clienteId + '&compartilhamento=1&pasta=' + encodeURIComponent(pastaId)).then(function (r) {
        if (!document.body.contains(box) || !est.dados || est.dados.atual_id !== pastaId) return;
        if (!r.ok) { box.innerHTML = '<span class="ffcli-vazio">Não foi possível ler o compartilhamento: ' + esc(r.error || '') + '</span>'; return; }
        var x = r.data;
        var sit = x.publico ? '<span class="ffd-pill ok">Qualquer pessoa com o link pode ' + (x.publico === 'writer' ? 'editar' : x.publico === 'commenter' ? 'comentar' : 'ver') + '</span>'
          : x.dominio ? '<span class="ffd-pill">Todos de @' + esc(x.dominio.dominio) + ' com o link</span>'
          : '<span class="ffd-pill restrito">Restrito: só quem tem acesso' + (x.pessoas ? ' (' + x.pessoas + (x.pessoas === 1 ? ' pessoa)' : ' pessoas)') : '') + '</span>';
        box.innerHTML = '<span class="ffd-link-rot">Link da pasta</span><input type="text" readonly value="' + esc(x.link) + '" data-link data-amb-ignorar aria-label="Link da pasta">' +
          botao('Copiar link', 'data-copiar') + '<a class="ffcli-link" href="' + esc(x.link) + '" target="_blank" rel="noopener noreferrer">Abrir no Drive</a>' + sit +
          (r.pode_mudar ? botao(x.publico ? 'Fechar o link (restrito)' : 'Liberar para quem tiver o link', 'data-publico') : '') + '<span data-link-msg></span>';
        var inp = box.querySelector('[data-link]'), msg = box.querySelector('[data-link-msg]');
        box.querySelector('[data-copiar]').onclick = function () {
          var feito = function () { msg.textContent = 'Link copiado.'; setTimeout(function () { msg.textContent = ''; }, 2500); };
          if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(x.link).then(feito, function () { inp.select(); document.execCommand('copy'); feito(); });
          else { inp.select(); document.execCommand('copy'); feito(); }
        };
        inp.onclick = function () { inp.select(); };
        var bp = box.querySelector('[data-publico]');
        if (bp) bp.onclick = function () {
          bp.disabled = true; msg.textContent = '';
          pedir('', { acao: 'link_publico', cliente_id: clienteId, pasta_id: pastaId, abrir: !x.publico }).then(function (y) {
            if (!y.ok) { bp.disabled = false; msg.textContent = y.error || 'O Drive não deixou mudar.'; return; }
            desenharLink();
          });
        };
      });
    }

    // ── Envio direto para o Google (qualquer tamanho), com progresso ─────
    function enviarArquivos(lista) {
      var arqs = Array.prototype.slice.call(lista || []);
      if (!arqs.length) return;
      var box = el.querySelector('[data-envios]'), pastaId = est.dados.atual_id;
      var linhas = arqs.map(function (f, i) {
        var li = document.createElement('div'); li.className = 'ffd-envio';
        li.innerHTML = '<span class="ffd-envio-nome">' + esc(f.name) + ' <small>' + tam(f.size) + '</small></span><span class="ffd-envio-barra"><i style="width:0%"></i></span><span class="ffd-envio-sit">Na fila</span>';
        box.appendChild(li); return li;
      });
      est.enviando += arqs.length;
      var i = 0;
      function proximo() {
        if (i >= arqs.length) { carregar(true); return; }
        var f = arqs[i], li = linhas[i]; i++;
        var barra = li.querySelector('i'), sit = li.querySelector('.ffd-envio-sit');
        function falhou(m) { sit.textContent = m; li.classList.add('erro'); est.enviando--; proximo(); }
        sit.textContent = 'Preparando…';
        pedir('', { acao: 'envio_abrir', cliente_id: clienteId, pasta_id: pastaId, nome: f.name, mime: f.type || 'application/octet-stream', tamanho: f.size }).then(function (r) {
          if (!r.ok) return falhou(r.error || 'Não foi possível começar o envio.');
          var x = new XMLHttpRequest();
          x.open('PUT', r.url);
          x.setRequestHeader('Content-Type', f.type || 'application/octet-stream');
          x.upload.onprogress = function (e) { if (e.lengthComputable) { var p = Math.round(100 * e.loaded / e.total); barra.style.width = p + '%'; sit.textContent = p + '%'; } };
          x.onerror = function () { falhou('A conexão caiu no envio. Tente de novo.'); };
          x.onload = function () {
            if (x.status !== 200 && x.status !== 201) return falhou('O Google recusou o envio (HTTP ' + x.status + ').');
            var j = {}; try { j = JSON.parse(x.responseText); } catch (e) { /* segue sem id */ }
            sit.textContent = 'Conferindo…';
            pedir('', { acao: 'envio_registrar', cliente_id: clienteId, file_id: j.id || '' }).then(function (y) {
              est.enviando--;
              if (!y.ok) { sit.textContent = y.error || 'Enviado, mas não consegui conferir.'; li.classList.add('erro'); }
              else { barra.style.width = '100%'; sit.textContent = 'Enviado'; li.classList.add('ok'); }
              proximo();
            });
          };
          sit.textContent = '0%';
          x.send(f);
        });
      }
      proximo();
    }

    function ligar() {
      el.querySelectorAll('[data-pasta]').forEach(function (b) { b.onclick = function () { carregar(false, b.dataset.pasta); }; });
      el.querySelectorAll('[data-ver]').forEach(function (b) {
        b.onclick = function (e) {
          var f = est.dados.itens[+b.dataset.ver];
          if (est.selecao) { e.preventDefault(); if (est.selecao[f.id]) delete est.selecao[f.id]; else est.selecao[f.id] = true; desenhar(); return; }
          previa(f, urlBaixar(f));
        };
      });
      semMiniatura(el);
      var a = el.querySelector('[data-atualizar]');
      if (a) a.onclick = function () { a.disabled = true; a.textContent = 'Atualizando…'; carregar(true); };
      var inp = el.querySelector('[data-arquivo]'), env = el.querySelector('[data-enviar]');
      if (env) env.onclick = function () { inp.click(); };
      if (inp) inp.onchange = function () { enviarArquivos(inp.files); inp.value = ''; };
      // Arrastar arquivos do computador para dentro do painel.
      var zona = el.querySelector('[data-ffd-solta]');
      if (zona) {
        zona.addEventListener('dragover', function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0) { e.preventDefault(); zona.classList.add('soltando'); } });
        zona.addEventListener('dragleave', function (e) { if (!zona.contains(e.relatedTarget)) zona.classList.remove('soltando'); });
        zona.addEventListener('drop', function (e) { zona.classList.remove('soltando'); if (e.dataTransfer && e.dataTransfer.files.length) { e.preventDefault(); enviarArquivos(e.dataTransfer.files); } });
      }
      var bz = el.querySelector('[data-zip]');
      if (bz) bz.onclick = function () { location.href = urlZip(null); };
      var bs = el.querySelector('[data-selecionar]');
      if (bs) bs.onclick = function () { est.selecao = est.selecao ? null : {}; desenhar(); };
      el.querySelectorAll('[data-sel]').forEach(function (c) { c.onchange = function () { if (c.checked) est.selecao[c.dataset.sel] = true; else delete est.selecao[c.dataset.sel]; desenhar(); }; });
      var st = el.querySelector('[data-sel-todos]');
      if (st) st.onclick = function () { arquivos().forEach(function (f) { est.selecao[f.id] = true; }); desenhar(); };
      var sz = el.querySelector('[data-sel-zip]');
      if (sz) sz.onclick = function () { var ids = Object.keys(est.selecao); if (!ids.length) return; location.href = urlZip(ids); };
      var bn = el.querySelector('[data-nova]');
      if (bn) bn.onclick = function () {
        var box = el.querySelector('[data-nova-form]');
        box.innerHTML = '<div class="ffd-busca" data-amb-guarda><input type="text" maxlength="200" data-nova-nome placeholder="Nome da pasta nova"> ' + botao('Criar pasta', 'data-nova-ok', true) + botao('Cancelar', 'data-nova-x') + '</div><div data-nova-erro></div>';
        var n = box.querySelector('[data-nova-nome]'); n.focus();
        function criar() {
          if (!n.value.trim()) { n.focus(); return; }
          pedir('', { acao: 'nova_pasta', cliente_id: clienteId, pasta_id: est.dados.atual_id, nome: n.value.trim() }).then(function (r) {
            if (!r.ok) { box.querySelector('[data-nova-erro]').innerHTML = '<div class="ffd-aviso erro">' + esc(r.error || 'Não foi possível criar.') + '</div>'; return; }
            carregar(true);
          });
        }
        box.querySelector('[data-nova-ok]').onclick = criar;
        n.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); criar(); } if (e.key === 'Escape') box.innerHTML = ''; });
        box.querySelector('[data-nova-x]').onclick = function () { box.innerHTML = ''; };
      };
      var bsec = el.querySelector('[data-secao]');
      if (bsec) bsec.onclick = function () {
        var box = el.querySelector('[data-extra]'), atual = est.dados.caminho[est.dados.caminho.length - 1];
        box.innerHTML = '<div class="ffd-aviso">A pasta <b>' + esc(atual.nome) + '</b> vira uma seção própria na ficha, com o título que você der (ex.: Mídias prontas).</div>' +
          '<div class="ffd-busca" data-amb-guarda><input type="text" maxlength="120" data-sec-titulo placeholder="Título da seção" value="' + esc(atual.nome) + '"> ' + botao('Criar seção', 'data-sec-ok', true) + '</div><div data-sec-erro></div>';
        var ti = box.querySelector('[data-sec-titulo]'); ti.focus(); ti.select();
        box.querySelector('[data-sec-ok]').onclick = function () {
          pedir('', { acao: 'secao_criar', cliente_id: clienteId, titulo: ti.value, pasta_id: atual.id }).then(function (r) {
            if (!r.ok) { box.querySelector('[data-sec-erro]').innerHTML = '<div class="ffd-aviso erro">' + esc(r.error || 'Não foi possível criar a seção.') + '</div>'; return; }
            box.innerHTML = '<div class="ffd-aviso ok">Seção criada.</div>';
            op.aoSecoes(r.data);
          });
        };
      };
      var ac = el.querySelector('[data-acessos]');
      if (ac) ac.onclick = function () {
        var box = el.querySelector('[data-extra]');
        box.innerHTML = '<div class="ffcli-vazio">Conferindo os acessos no Drive…</div>';
        pedir('?cliente=' + clienteId + '&acessos=1').then(function (r) {
          if (!r.ok) { box.innerHTML = '<div class="ffd-aviso erro" role="alert">' + esc(r.error || 'Não foi possível conferir.') + '</div>'; return; }
          var d = r.data;
          var h = '<div class="ffd-aviso">Para abrir os arquivos pelo link, a pessoa precisa de acesso à pasta no Drive. ' + (d.link_publico ? 'A pasta está aberta para qualquer pessoa com o link.' : '') + '</div>';
          h += '<div><b>Com acesso no Drive:</b> ' + (d.com_acesso.length ? d.com_acesso.map(function (u) { return esc(u.nome); }).join(', ') : 'ninguém da equipe') + '</div>';
          if (d.sem_acesso.length) {
            h += '<div><b>Veem o cliente no sistema, mas não no Drive:</b></div><div class="ffd-pessoas">' + d.sem_acesso.map(function (u) {
              return '<label>' + (r.pode_dar && u.email ? '<input type="checkbox" value="' + esc(u.email) + '" checked> ' : '') + esc(u.nome) + (u.email ? ' <small>(' + esc(u.email) + ')</small>' : ' <small>(sem e-mail no cadastro)</small>') + '</label>';
            }).join('') + '</div>' + (r.pode_dar ? botao('Dar acesso de edição aos marcados', 'data-dar', true) : '<div class="ffcli-vazio">Peça a um administrador para dar o acesso.</div>');
          }
          box.innerHTML = h;
          var dar = box.querySelector('[data-dar]');
          if (dar) dar.onclick = function () {
            var emails = Array.prototype.map.call(box.querySelectorAll('input:checked'), function (i) { return i.value; });
            if (!emails.length) return;
            dar.disabled = true;
            pedir('', { acao: 'dar_acesso', cliente_id: clienteId, emails: emails }).then(function (x) {
              if (!x.ok) { box.insertAdjacentHTML('beforeend', '<div class="ffd-aviso erro">' + esc(x.error || 'Não foi possível dar o acesso.') + '</div>'); dar.disabled = false; return; }
              ac.click();
            });
          };
        });
      };
      var tr = el.querySelector('[data-trocar]');
      if (tr) tr.onclick = desenharEscolhaPasta;
    }
    // Escolher a pasta do cliente: navegar no Drive como no Google (Meu Drive,
    // Compartilhados comigo, drives compartilhados, subpastas) ou procurar pelo nome.
    function desenharEscolhaPasta() {
      var d = est.dados;
      el.innerHTML = '<div class="ffd">' + aviso() + '<div class="ffcli-vazio">' + (d.pasta ? 'Pasta atual: <b>' + esc(d.pasta.nome) + '</b>. ' : 'Este cliente ainda não tem pasta do Drive. ') +
        'Navegue pelo Drive abaixo e clique em "Usar esta pasta", ou procure pelo nome, ou cole o link.</div>' +
        '<div class="ffd-busca" data-amb-guarda><input type="text" data-ref placeholder="Nome da pasta para procurar, ou o link dela" data-amb-ignorar>' +
        botao('Procurar', 'data-procurar') + botao('Usar este link', 'data-usar', true) + '</div><div data-res></div><div data-erro></div>' +
        '<div class="ffd-nav"><div class="ffd-nav-topo"><div class="ffd-cam" data-cam></div><div data-usar-aberta></div></div>' +
        '<div class="ffd-busca"><input type="search" data-filtro placeholder="Buscar pelo nome (nesta pasta)" aria-label="Buscar pasta pelo nome" data-amb-ignorar>' +
        botao('Procurar no Drive todo', 'data-filtro-drive') + '</div><div data-filtro-info></div><div data-nav></div></div>' +
        (d.pasta ? '<div>' + botao('Cancelar', 'data-cancelar') + '</div>' : '') + '</div>';
      var ref = el.querySelector('[data-ref]'), res = el.querySelector('[data-res]'), err = el.querySelector('[data-erro]');
      var nav = el.querySelector('[data-nav]'), cam = el.querySelector('[data-cam]'), usarAberta = el.querySelector('[data-usar-aberta]');
      var pilha = [{ id: null, nome: 'Drive' }];
      var filtro = el.querySelector('[data-filtro]'), filtroInfo = el.querySelector('[data-filtro-info]');
      function normal(v) { return String(v || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
      // Filtra na hora as pastas da tela; sem resultado, oferece procurar no Drive inteiro.
      function filtrar() {
        var termo = normal(filtro.value.trim()), itens = nav.querySelectorAll('[data-nome]'), vistos = 0;
        itens.forEach(function (it) { var ok = !termo || it.getAttribute('data-nome').indexOf(termo) >= 0; it.style.display = ok ? '' : 'none'; if (ok) vistos++; });
        if (!termo || !itens.length) { filtroInfo.innerHTML = ''; return; }
        filtroInfo.innerHTML = vistos ? '<div class="ffcli-vazio">' + vistos + (vistos === 1 ? ' pasta' : ' pastas') + ' com "' + esc(filtro.value.trim()) + '" aqui. Não é nenhuma? Use "Procurar no Drive todo".</div>'
          : '<div class="ffcli-vazio">Nenhuma pasta com "' + esc(filtro.value.trim()) + '" nesta pasta. ' + botao('Procurar "' + filtro.value.trim() + '" no Drive todo', 'data-filtro-drive2') + '</div>';
        var b2 = filtroInfo.querySelector('[data-filtro-drive2]');
        if (b2) b2.onclick = procurarNoDrive;
      }
      function procurarNoDrive() {
        if (!filtro.value.trim()) { filtro.focus(); return; }
        ref.value = filtro.value.trim();
        procurar();
        res.scrollIntoView({ block: 'nearest' });
      }
      filtro.addEventListener('input', filtrar);
      filtro.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (nav.querySelectorAll('[data-nome]:not([style*="none"])').length === 0) procurarNoDrive(); } });
      el.querySelector('[data-filtro-drive]').onclick = procurarNoDrive;
      function vincular(r) {
        err.innerHTML = '';
        pedir('', { acao: 'vincular_pasta', cliente_id: clienteId, referencia: r }).then(function (x) {
          if (!x.ok) { err.innerHTML = '<div class="ffd-aviso erro" role="alert">' + esc(x.error || 'Não foi possível ligar a pasta.') + '</div>'; return; }
          est.pasta = null; carregar(true);
        });
      }
      function real(id) { return id && id !== 'root' && id !== 'shared'; }
      function ir(i) {
        pilha = pilha.slice(0, i + 1);
        var atual = pilha[pilha.length - 1];
        cam.innerHTML = pilha.map(function (p, k) {
          return (k ? '<span>/</span>' : '') + (k === pilha.length - 1 ? '<b>' + esc(p.nome) + '</b>' : '<button type="button" data-cam-i="' + k + '">' + esc(p.nome) + '</button>');
        }).join('');
        cam.querySelectorAll('[data-cam-i]').forEach(function (b) { b.onclick = function () { ir(+b.dataset.camI); }; });
        usarAberta.innerHTML = real(atual.id) ? botao('Usar esta pasta (' + atual.nome + ')', 'data-usar-esta', true) : '';
        var ua = usarAberta.querySelector('[data-usar-esta]');
        if (ua) ua.onclick = function () { vincular(atual.id); };
        // Segura a altura enquanto carrega: sem isso o painel encolhe e a tela pula.
        nav.style.minHeight = nav.offsetHeight ? nav.offsetHeight + 'px' : '';
        nav.innerHTML = '<div class="ffcli-vazio">Abrindo…</div>';
        filtro.value = ''; filtroInfo.innerHTML = '';
        pedir('?cliente=' + clienteId + '&navegar=' + encodeURIComponent(atual.id || '')).then(function (x) {
          if (pilha[pilha.length - 1] !== atual) return;
          if (!x.ok) { nav.style.minHeight = ''; nav.innerHTML = '<div class="ffd-aviso erro" role="alert">' + esc(x.error || 'Não foi possível abrir esta pasta.') + '</div>'; return; }
          var r = x.data, h = '';
          if (r.pasta && r.pasta.nome && !atual.nomeOk) { atual.nome = r.pasta.nome; atual.nomeOk = true; ir(pilha.length - 1); return; }
          h += r.pastas.length ? '<div class="ffd-grade">' + r.pastas.map(function (p, k) {
            return '<div class="ffd-item pasta" style="cursor:default" data-nome="' + esc(normal(p.nome)) + '"><button type="button" class="ffd-abrir" data-abrir="' + k + '" title="Abrir ' + esc(p.nome) + '">' +
              '<span class="ffd-mini" style="height:72px"><span class="ffd-ico">' + ICO_PASTA + '</span></span>' +
              '<span class="ffd-item-txt"><b>' + esc(p.nome) + '</b>' + (p.modificado_em ? '<small>atualizada em ' + esc(quando(p.modificado_em)) + '</small>' : '') + '</span></button>' +
              (r.lugares ? '' : '<span class="ffd-item-txt" style="padding-top:0">' + botao('Usar esta pasta', 'data-escolher="' + k + '"', true) + '</span>') + '</div>';
          }).join('') + '</div>' : '<div class="ffcli-vazio">' + (r.lugares ? 'Nada para mostrar.' : 'Nenhuma subpasta aqui.') + '</div>';
          if (r.arquivos.length) h += '<div class="ffd-nav-arqs"><b>Arquivos nesta pasta:</b> ' + r.arquivos.map(function (a) { return '<span>' + esc(a.nome) + '</span>'; }).join('') + (r.mais_arquivos ? '<span>e mais…</span>' : '') + '</div>';
          nav.innerHTML = h;
          nav.style.minHeight = '';
          // Abriu outra pasta: o caminho dela fica à vista (a lista nova começa ali).
          if (i > 0 || pilha.length > 1) cam.scrollIntoView({ block: 'nearest' });
          nav.querySelectorAll('[data-abrir]').forEach(function (b) { b.onclick = function () { var p = r.pastas[+b.dataset.abrir]; pilha.push({ id: p.id, nome: p.nome, nomeOk: true }); ir(pilha.length - 1); }; });
          nav.querySelectorAll('[data-escolher]').forEach(function (b) { b.onclick = function () { b.disabled = true; vincular(r.pastas[+b.dataset.escolher].id); }; });
        });
      }
      ir(0);
      el.querySelector('[data-usar]').onclick = function () { if (ref.value.trim()) vincular(ref.value.trim()); else ref.focus(); };
      function procurar() {
        res.innerHTML = '<div class="ffcli-vazio">Procurando…</div>';
        pedir('?cliente=' + clienteId + '&buscar=' + encodeURIComponent(ref.value.trim())).then(function (x) {
          if (!x.ok) { res.innerHTML = '<div class="ffd-aviso erro">' + esc(x.error || 'Não foi possível procurar.') + '</div>'; return; }
          res.innerHTML = x.data.length ? '<ul class="ffd-lista">' + x.data.map(function (p, k) {
            return '<li><span class="ffd-nome"><b>' + esc(p.nome) + '</b><small>' + (p.em ? 'em: ' + esc(p.em) + ' · ' : '') + (p.modificado_em ? 'atualizada em ' + esc(quando(p.modificado_em)) : '') + '</small></span>' +
              '<span class="ffd-acoes">' + botao('Abrir', 'data-ver-pasta="' + k + '"') + botao('Usar esta pasta', 'data-id="' + esc(p.id) + '"', true) + '</span></li>';
          }).join('') + '</ul>' : '<div class="ffcli-vazio">Nenhuma pasta com esse nome.</div>';
          res.querySelectorAll('[data-id]').forEach(function (b) { b.onclick = function () { vincular(b.dataset.id); }; });
          res.querySelectorAll('[data-ver-pasta]').forEach(function (b) {
            b.onclick = function () { var p = x.data[+b.dataset.verPasta]; pilha = [pilha[0], { id: p.id, nome: p.nome, nomeOk: true }]; ir(1); nav.scrollIntoView({ block: 'nearest' }); };
          });
        });
      }
      el.querySelector('[data-procurar]').onclick = procurar;
      ref.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); procurar(); } });
      var cc = el.querySelector('[data-cancelar]');
      if (cc) cc.onclick = function () { carregar(false); };
    }
    // Atualização automática enquanto o painel está na tela.
    el._ffdTimer = setInterval(function () {
      if (!document.body.contains(el)) { clearInterval(el._ffdTimer); return; }
      if (!el.offsetParent || !est.dados || !est.dados.itens || est.enviando > 0 || est.selecao || el.querySelector('[data-extra] *') || el.querySelector('[data-nova-form] *') || el.querySelector('[data-ref]')) return;
      carregar(false);
    }, 60000);
  }

  // ── Escolher um arquivo da pasta do cliente ──────────────────────────
  function escolher(el, clienteId, aoEscolher) {
    estilo();
    var pasta = null;
    function ir(p) {
      pasta = p === undefined ? pasta : p;
      el.innerHTML = '<div class="ffcli-vazio">Abrindo a pasta do cliente…</div>';
      pedir('?cliente=' + clienteId + (pasta ? '&pasta=' + encodeURIComponent(pasta) : '')).then(function (r) {
        if (!r.ok) { el.innerHTML = '<div class="ffd-aviso erro">' + esc(r.error || 'Não foi possível abrir o Drive.') + '</div>'; return; }
        var d = r.data;
        if (!d.itens) { el.innerHTML = '<div class="ffcli-vazio">' + (!d.conexao.conectado ? 'O Google Drive não está conectado.' : 'O cliente ainda não tem pasta do Drive (ligue na ficha do cliente, aba Arquivos).') + ' Informe o arquivo abaixo.</div>'; return; }
        el.innerHTML = '<div class="ffd"><div class="ffd-cam">' + d.caminho.map(function (p, i) {
          return (i ? '<span>/</span>' : '') + (p.id === d.atual_id ? '<b>' + esc(p.nome) + '</b>' : '<button type="button" data-p="' + esc(p.id) + '">' + esc(p.nome) + '</button>');
        }).join('') + '</div>' + (d.atual ? '' : '<div class="ffd-aviso erro">Lista de ' + esc(quando(d.sincronizado_em)) + ': ' + esc(d.erro || '') + '</div>') +
          (d.itens.length ? '<div class="ffd-grade">' + d.itens.map(function (f, i) {
            if (f.pasta) return cartaoItem(clienteId, f, i).replace('data-pasta=', 'data-p=');
            return '<div class="ffd-item" style="cursor:default">' + cartaoItem(clienteId, f, i).replace(/^<button[^>]*>/, '').replace(/<\/button>$/, '') +
              '<span class="ffd-item-txt" style="padding-top:0;flex-direction:row;gap:6px">' + botao('Ver', 'data-v="' + i + '"') + botao('Escolher', 'data-i="' + i + '"', true) + '</span></div>';
          }).join('') + '</div>' : '<div class="ffcli-vazio">Pasta vazia.</div>') + '</div>';
        el.querySelectorAll('[data-p]').forEach(function (b) { b.onclick = function () { ir(b.dataset.p); }; });
        el.querySelectorAll('[data-v]').forEach(function (b) { b.onclick = function () { previa(d.itens[+b.dataset.v]); }; });
        semMiniatura(el);
        el.querySelectorAll('[data-i]').forEach(function (b) { b.onclick = function () { var f = d.itens[+b.dataset.i]; aoEscolher({ id: f.id, nome: f.nome, link: f.link }); el.querySelectorAll('[data-i]').forEach(function (x) { x.textContent = 'Escolher'; }); b.textContent = 'Escolhido'; }; });
      });
    }
    ir(null);
  }

  // previa(arquivo): a pré-visualização dentro do sistema (aba Postagem da ficha usa).
  return { painel: painel, escolher: escolher, previa: function (f) { estilo(); previa(f); } };
})();
