/* ═══════════════════════════════════════════════════════════════════════
   ff-drive.js: o Google Drive do cliente (ambientes da Inovaize).
   window.FfDrive.painel(el, clienteId)    a pasta na aba Arquivos da ficha
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
  function previa(f) {
    var fundo = document.createElement('div');
    fundo.className = 'ffd-prev-fundo';
    fundo.innerHTML = '<div class="ffd-prev" role="dialog" aria-modal="true" aria-label="Pré-visualização de ' + esc(f.nome) + '"><div class="ffd-prev-cab"><span><b>' + esc(f.nome) + '</b><small>' + esc(f.tipo) +
      (f.modificado_em ? ' · atualizado em ' + esc(quando(f.modificado_em)) : '') + '</small></span><span class="ffd-prev-acoes">' +
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
  function painel(el, clienteId) {
    estilo();
    var est = { cliente: clienteId, pasta: null, dados: null, timer: null, aviso: null };
    el.innerHTML = '<div class="ffcli-vazio">Carregando o Drive…</div>';
    if (el._ffdTimer) clearInterval(el._ffdTimer);

    pedir('?estado=1').then(function (r) { if (r.ok && r.data.aviso) { est.aviso = r.data.aviso; } carregar(false); });

    function volta() { return location.pathname + '?open=' + clienteId + (location.search.indexOf('amb=') >= 0 ? '&' + location.search.replace(/^\?/, '').split('&').filter(function (p) { return p.indexOf('amb=') === 0; }).join('&') : ''); }
    function carregar(forcar, pasta) {
      if (est.cliente !== clienteId) return;
      if (pasta !== undefined) est.pasta = pasta;
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
    function desenhar() {
      var d = est.dados, c = d.conexao;
      if (!c.configurado) { el.innerHTML = aviso() + '<div class="ffd-aviso">O Google Drive ainda não foi configurado no servidor. Depois de configurado, um administrador conecta a conta corporativa aqui.</div>'; return; }
      if (!c.conectado) {
        el.innerHTML = aviso() + '<div class="ffd-aviso">Nenhuma conta Google conectada' + (c.dominio ? ' (só contas @' + esc(c.dominio) + ')' : '') + '.</div>' +
          (c.pode_conectar ? '<a class="ffcli-bt princ" style="text-decoration:none;align-self:flex-start" href="/api/drive_oauth.php?iniciar=1&volta=' + encodeURIComponent(volta()) + '">Conectar conta Google</a>'
            : '<div class="ffcli-vazio">Peça a um administrador para conectar a conta Google da empresa.</div>');
        return;
      }
      if (!d.itens) { desenharEscolhaPasta(); return; }
      var h = aviso() + '<div class="ffd">';
      h += '<div class="ffd-topo"><div class="ffd-cam">' + d.caminho.map(function (p, i) {
        return (i ? '<span>/</span>' : '') + (p.id === d.atual_id ? '<b>' + esc(p.nome) + '</b>' : '<button type="button" data-pasta="' + esc(p.id) + '">' + esc(p.nome) + '</button>');
      }).join('') + '</div><div class="ffd-acoes">' + (d.pasta.link ? '<a class="ffcli-link" href="' + esc(d.pasta.link) + '" target="_blank" rel="noopener noreferrer">Abrir pasta no Drive</a>' : '') + '</div></div>';
      if (!d.atual) {
        h += '<div class="ffd-aviso erro" role="alert">Não foi possível ler o Drive agora: ' + esc(d.erro || 'erro desconhecido') + ' ' +
          (d.sincronizado_em ? 'A lista abaixo é a última conhecida, de ' + esc(quando(d.sincronizado_em)) + ', e pode estar desatualizada.' : 'Ainda não há lista desta pasta.') + '</div>';
      }
      h += d.itens.length ? '<div class="ffd-grade' + (d.atual ? '' : ' ffd-velha') + '">' + d.itens.map(function (f, i) { return cartaoItem(clienteId, f, i); }).join('') + '</div>'
        : '<div class="ffcli-vazio">Pasta vazia.</div>';
      h += '<div class="ffd-topo"><span class="ffd-sync">' + (d.sincronizado_em ? (d.atual ? 'Sincronizado ' : 'Última leitura ') + esc(quando(d.sincronizado_em)) : '') + '</span>' +
        '<span class="ffd-acoes">' + botao('Atualizar', 'data-atualizar') + botao('Enviar arquivo', 'data-enviar') + botao('Acessos da equipe', 'data-acessos') +
        (c.pode_conectar ? botao('Trocar pasta', 'data-trocar') : '') + '</span></div>';
      h += '<input type="file" data-arquivo hidden><div data-extra></div></div>';
      el.innerHTML = h;
      ligar();
    }
    function ligar() {
      el.querySelectorAll('[data-pasta]').forEach(function (b) { b.onclick = function () { carregar(false, b.dataset.pasta); }; });
      el.querySelectorAll('[data-ver]').forEach(function (b) { b.onclick = function () { previa(est.dados.itens[+b.dataset.ver]); }; });
      semMiniatura(el);
      var a = el.querySelector('[data-atualizar]');
      if (a) a.onclick = function () { a.disabled = true; a.textContent = 'Atualizando…'; carregar(true); };
      var inp = el.querySelector('[data-arquivo]'), env = el.querySelector('[data-enviar]');
      if (env) env.onclick = function () { inp.click(); };
      if (inp) inp.onchange = function () {
        if (!inp.files[0]) return;
        var fd = new FormData();
        fd.append('acao', 'enviar'); fd.append('cliente_id', clienteId); fd.append('arquivo', inp.files[0]);
        if (est.dados.atual_id) fd.append('pasta_id', est.dados.atual_id);
        env.disabled = true; env.textContent = 'Enviando…';
        pedir('', fd).then(function (r) {
          if (!r.ok) { el.querySelector('[data-extra]').innerHTML = '<div class="ffd-aviso erro" role="alert">' + esc(r.error || 'Não foi possível enviar.') + '</div>'; env.disabled = false; env.textContent = 'Enviar arquivo'; return; }
          carregar(false);
        });
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
    function desenharEscolhaPasta() {
      var d = est.dados;
      el.innerHTML = '<div class="ffd">' + aviso() + '<div class="ffcli-vazio">' + (d.pasta ? 'Pasta atual: ' + esc(d.pasta.nome) + '. ' : 'Este cliente ainda não tem pasta do Drive. ') +
        'Cole o link da pasta ou procure pelo nome.</div><div class="ffd-busca" data-amb-guarda><input type="text" data-ref placeholder="Link da pasta do Drive ou nome para procurar" data-amb-ignorar>' +
        botao('Procurar', 'data-procurar') + botao('Usar este link', 'data-usar', true) + '</div><div data-res></div><div data-erro></div>' +
        (d.pasta ? botao('Cancelar', 'data-cancelar') : '') + '</div>';
      var ref = el.querySelector('[data-ref]'), res = el.querySelector('[data-res]'), err = el.querySelector('[data-erro]');
      function vincular(r) {
        err.innerHTML = '';
        pedir('', { acao: 'vincular_pasta', cliente_id: clienteId, referencia: r }).then(function (x) {
          if (!x.ok) { err.innerHTML = '<div class="ffd-aviso erro" role="alert">' + esc(x.error || 'Não foi possível ligar a pasta.') + '</div>'; return; }
          est.pasta = null; carregar(true);
        });
      }
      el.querySelector('[data-usar]').onclick = function () { if (ref.value.trim()) vincular(ref.value.trim()); else ref.focus(); };
      el.querySelector('[data-procurar]').onclick = function () {
        res.innerHTML = '<div class="ffcli-vazio">Procurando…</div>';
        pedir('?cliente=' + clienteId + '&buscar=' + encodeURIComponent(ref.value.trim())).then(function (x) {
          if (!x.ok) { res.innerHTML = '<div class="ffd-aviso erro">' + esc(x.error || 'Não foi possível procurar.') + '</div>'; return; }
          res.innerHTML = x.data.length ? '<ul class="ffd-lista">' + x.data.map(function (p) {
            return '<li><span class="ffd-nome"><b>' + esc(p.nome) + '</b><small>' + (p.modificado_em ? 'atualizada em ' + esc(quando(p.modificado_em)) : '') + '</small></span>' + botao('Usar esta pasta', 'data-id="' + esc(p.id) + '"') + '</li>';
          }).join('') + '</ul>' : '<div class="ffcli-vazio">Nenhuma pasta com esse nome.</div>';
          res.querySelectorAll('[data-id]').forEach(function (b) { b.onclick = function () { vincular(b.dataset.id); }; });
        });
      };
      var cc = el.querySelector('[data-cancelar]');
      if (cc) cc.onclick = function () { carregar(false); };
    }
    // Atualização automática enquanto o painel está na tela.
    el._ffdTimer = setInterval(function () {
      if (!document.body.contains(el)) { clearInterval(el._ffdTimer); return; }
      if (!el.offsetParent || !est.dados || !est.dados.itens || el.querySelector('[data-extra] *') || el.querySelector('[data-ref]')) return;
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
