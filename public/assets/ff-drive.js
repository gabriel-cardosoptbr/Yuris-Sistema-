/* ═══════════════════════════════════════════════════════════════════════
   ff-drive.js: o Google Drive do cliente (ambientes da Inovaize).
   window.FfDrive.painel(el, clienteId)    a pasta na aba Arquivos da ficha
   window.FfDrive.escolher(el, clienteId, aoEscolher)  escolher um arquivo
                                           da pasta (material, cartão)
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
      h += d.itens.length ? '<ul class="ffd-lista' + (d.atual ? '' : ' ffd-velha') + '">' + d.itens.map(function (f) {
        return '<li><span class="ffd-nome">' + (f.pasta ? '<button type="button" data-pasta="' + esc(f.id) + '">' + esc(f.nome) + '</button>' : '<b>' + esc(f.nome) + '</b>') +
          '<small>' + esc(f.tipo) + (f.modificado_em ? ' · atualizado em ' + esc(quando(f.modificado_em)) : '') + '</small></span>' +
          (f.link ? '<a class="ffcli-link" href="' + esc(f.link) + '" target="_blank" rel="noopener noreferrer">Abrir</a>' : '') + '</li>';
      }).join('') + '</ul>' : '<div class="ffcli-vazio">Pasta vazia.</div>';
      h += '<div class="ffd-topo"><span class="ffd-sync">' + (d.sincronizado_em ? (d.atual ? 'Sincronizado ' : 'Última leitura ') + esc(quando(d.sincronizado_em)) : '') + '</span>' +
        '<span class="ffd-acoes">' + botao('Atualizar', 'data-atualizar') + botao('Enviar arquivo', 'data-enviar') + botao('Acessos da equipe', 'data-acessos') +
        (c.pode_conectar ? botao('Trocar pasta', 'data-trocar') : '') + '</span></div>';
      h += '<input type="file" data-arquivo hidden><div data-extra></div></div>';
      el.innerHTML = h;
      ligar();
    }
    function ligar() {
      el.querySelectorAll('[data-pasta]').forEach(function (b) { b.onclick = function () { carregar(false, b.dataset.pasta); }; });
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
          (d.itens.length ? '<ul class="ffd-lista">' + d.itens.map(function (f, i) {
            return '<li><span class="ffd-nome">' + (f.pasta ? '<button type="button" data-p="' + esc(f.id) + '">' + esc(f.nome) + '</button>' : '<b>' + esc(f.nome) + '</b>') + '<small>' + esc(f.tipo) + '</small></span>' +
              (f.pasta ? '' : botao('Escolher', 'data-i="' + i + '"')) + '</li>';
          }).join('') + '</ul>' : '<div class="ffcli-vazio">Pasta vazia.</div>') + '</div>';
        el.querySelectorAll('[data-p]').forEach(function (b) { b.onclick = function () { ir(b.dataset.p); }; });
        el.querySelectorAll('[data-i]').forEach(function (b) { b.onclick = function () { var f = d.itens[+b.dataset.i]; aoEscolher({ id: f.id, nome: f.nome, link: f.link }); el.querySelectorAll('[data-i]').forEach(function (x) { x.textContent = 'Escolher'; }); b.textContent = 'Escolhido'; }; });
      });
    }
    ir(null);
  }

  return { painel: painel, escolher: escolher };
})();
