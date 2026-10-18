/* Página externa de assinatura (/assinar). Regra no servidor: app/Contratos/Assinatura.php.
   Nada aqui decide se pode assinar: a página só mostra a etapa que a API devolve. */
(function () {
  'use strict';
  var API = '/api/assinatura.php';
  var raiz = document.getElementById('ass');
  var estado = null;
  var aguardarAte = 0;
  var timer = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function chamar(metodo, corpo, query) {
    var op = { method: metodo, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (metodo === 'POST') {
      op.headers['Content-Type'] = 'application/json';
      if (estado && estado.csrf) op.headers['X-CSRF-Token'] = estado.csrf;
      op.body = JSON.stringify(corpo || {});
    }
    return fetch(API + (query || ''), op).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Resposta inesperada. Tente de novo.' }; })
        .then(function (j) { j._status = r.status; return j; });
    }).catch(function () { return { ok: false, error: 'Sem conexão. Confira a internet e tente de novo.' }; });
  }

  function aplicarMarca(e) {
    if (!e || !e.empresa) return;
    document.documentElement.style.setProperty('--marca', e.empresa.cor || '#015DFC');
    document.title = 'Assinatura · ' + (e.titulo || 'contrato');
  }

  function cabecalho(e) {
    var logo = e.empresa.logo ? '<img class="ass-logo" src="' + esc(e.empresa.logo) + '" alt="' + esc(e.empresa.nome) + '">' : '<div class="ass-empresa">' + esc(e.empresa.nome) + '</div>';
    return '<header class="ass-cab">' + logo + '</header>' +
      '<div class="ass-titulo"><span class="ass-rot">Contrato para assinatura</span><h1>' + esc(e.titulo) + '</h1></div>';
  }

  function aviso(msg, tipo) {
    var el = raiz.querySelector('.ass-aviso');
    if (!el) return;
    el.className = 'ass-aviso' + (tipo ? ' ' + tipo : '');
    el.textContent = msg || '';
    el.hidden = !msg;
  }

  function botaoOcupado(b, ocupado, texto) {
    if (!b) return;
    if (ocupado) { b.dataset.txt = b.textContent; b.disabled = true; b.textContent = texto || 'Aguarde…'; }
    else { b.disabled = false; if (b.dataset.txt) b.textContent = b.dataset.txt; }
  }

  function desenhar(e) {
    estado = e;
    aplicarMarca(e);
    if (timer) { clearInterval(timer); timer = null; }
    var h = cabecalho(e);
    if (e.etapa === 'codigo') h += telaCodigo(e);
    else if (e.etapa === 'identificacao') h += telaIdentificacao(e);
    else if (e.etapa === 'documento') h += telaDocumento(e);
    else if (e.etapa === 'assinado') h += telaAssinado(e);
    else h += '<section class="ass-cartao"><p class="ass-msg">' + esc(e.mensagem || 'Este contrato não está disponível.') + '</p></section>';
    raiz.innerHTML = h;
    ligar(e);
  }

  function telaCodigo(e) {
    return '<section class="ass-cartao">' +
      '<p class="ass-ola">Olá, ' + esc(e.participante.primeiro_nome) + '.</p>' +
      '<p>Você foi convidado a assinar este contrato como <b>' + esc(e.participante.papel) + '</b>. Para confirmar que é você, vamos mandar um código de 6 dígitos para o seu <b>' + esc(e.contato) + '</b>.</p>' +
      '<div class="ass-aviso" hidden></div>' +
      '<div id="passoEnviar"' + (e.codigo_enviado ? ' hidden' : '') + '><button class="ass-btn" id="btEnviar" type="button">Enviar código pelo WhatsApp</button></div>' +
      '<form id="fmCodigo"' + (e.codigo_enviado ? '' : ' hidden') + ' autocomplete="off">' +
        '<label for="cod">Código recebido</label>' +
        '<input id="cod" class="ass-cod" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" required>' +
        '<button class="ass-btn" id="btConfirmar" type="submit">Confirmar código</button>' +
        '<button class="ass-link" id="btReenviar" type="button">Reenviar código</button>' +
      '</form>' +
      '<p class="ass-nota">O código vale por poucos minutos e só pode ser usado uma vez. Ninguém da empresa vai pedir esse código a você.</p>' +
      '</section>';
  }

  function telaIdentificacao(e) {
    var p = e.participante_completo, cad = p.cadastro;
    var rep = p.empresa ? '<div class="ass-dado"><span>Representando</span><b>' + esc(p.empresa) + (p.cnpj ? ' · CNPJ ' + esc(p.cnpj) : '') + (p.funcao ? ' · ' + esc(p.funcao) : '') + '</b></div>' : '';
    var conf = cad && cad.cpf ? '<div class="ass-bloco" id="blCadastro"><p>Encontramos estes dados no cadastro:</p>' +
        '<div class="ass-dado"><span>Nome</span><b>' + esc(cad.nome) + '</b></div><div class="ass-dado"><span>CPF</span><b>' + esc(cad.cpf) + '</b></div>' + rep +
        '<button class="ass-btn" id="btConfirmaCad" type="button">Confirmo, estes dados são meus</button>' +
        '<button class="ass-link" id="btOutrosDados" type="button">Os dados estão diferentes</button></div>' : '';
    return '<section class="ass-cartao"><p class="ass-ok">Código confirmado.</p>' +
      '<div class="ass-aviso" hidden></div>' + conf +
      '<form id="fmIdent"' + (conf ? ' hidden' : '') + ' autocomplete="on">' +
        '<p>Informe seus dados como devem constar no registro da assinatura.</p>' + rep +
        '<label for="nome">Nome completo</label><input id="nome" autocomplete="name" required maxlength="160" value="' + esc(p.nome) + '">' +
        '<label for="cpf">CPF</label><input id="cpf" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="000.000.000-00" required>' +
        '<button class="ass-btn" type="submit" id="btIdent">Continuar</button>' +
        '<p class="ass-nota">O CPF tem os dígitos conferidos. Isso não consulta a Receita Federal.</p>' +
      '</form></section>';
  }

  function telaDocumento(e) {
    var p = e.participante_completo, id = e.identificacao, d = e.documento;
    return '<section class="ass-cartao">' +
      '<h2>Confira seus dados</h2>' +
      '<div class="ass-dado"><span>Nome</span><b>' + esc(id.nome) + '</b></div>' +
      '<div class="ass-dado"><span>CPF</span><b>' + esc(id.cpf) + '</b></div>' +
      '<div class="ass-dado"><span>Papel</span><b>' + esc(e.participante.papel) + '</b></div>' +
      (p.empresa ? '<div class="ass-dado"><span>Representando</span><b>' + esc(p.empresa) + (p.cnpj ? ' · CNPJ ' + esc(p.cnpj) : '') + '</b></div>' : '') +
      (e.prazo ? '<div class="ass-dado"><span>Prazo para assinar</span><b>' + esc(e.prazo) + '</b></div>' : '') +
      '</section>' +
      '<section class="ass-cartao ass-doc"><div class="ass-doc-cab"><h2>Documento</h2>' +
        '<a class="ass-link" href="' + API + '?documento=1&baixar=1" rel="noopener noreferrer">Baixar PDF</a></div>' +
        '<p class="ass-nota">Versão ' + esc(d.versao) + ' · ' + esc(d.nome) + '<br>SHA-256: <span class="ass-hash">' + esc(d.sha256) + '</span></p>' +
        '<div id="pdfPaginas" class="ass-pdf"><div class="ass-carregando"><span class="ass-giro"></span>Carregando o documento…</div></div>' +
      '</section>' +
      '<section class="ass-cartao ass-final">' +
        '<div class="ass-aviso" hidden></div>' +
        '<label class="ass-aceite"><input type="checkbox" id="aceite"> <span>' + esc(e.aceite.texto) + '</span></label>' +
        '<button class="ass-btn" id="btAssinar" type="button" disabled>Assinar contrato</button>' +
        '<button class="ass-link ass-recusar" id="btRecusar" type="button">Não vou assinar</button>' +
        '<div id="blRecusa" class="ass-bloco" hidden><label for="motivo">Conte por que você não vai assinar</label>' +
          '<textarea id="motivo" rows="3" maxlength="500"></textarea>' +
          '<button class="ass-btn ass-btn-sec" id="btConfRecusa" type="button">Recusar a assinatura</button></div>' +
      '</section>';
  }

  function telaAssinado(e) {
    var a = e.assinatura;
    return '<section class="ass-cartao ass-sucesso"><div class="ass-check" aria-hidden="true">✓</div><h2>Assinatura registrada</h2>' +
      '<p>Registrada pelo servidor em ' + esc(a.assinado_em) + ' (horário de Brasília).</p>' +
      '<p class="ass-nota">Identificador da assinatura: <span class="ass-hash">' + esc(a.uuid) + '</span></p>' +
      '<p>' + (a.contrato_concluido ? 'Todos já assinaram. Quem enviou o contrato tem o documento final com o comprovante.' : 'Quando todos os participantes assinarem, o contrato fica concluído.') + '</p>' +
      '<a class="ass-btn ass-btn-sec" href="' + API + '?documento=1&baixar=1" rel="noopener noreferrer">Baixar o PDF assinado</a></section>';
  }

  function ligar(e) {
    var q = function (id) { return document.getElementById(id); };
    if (e.etapa === 'codigo') {
      var enviar = function (b) {
        botaoOcupado(b, true, 'Enviando…');
        chamar('POST', { acao: 'enviar_codigo' }).then(function (r) {
          botaoOcupado(b, false);
          if (r.ok) { q('passoEnviar').hidden = true; q('fmCodigo').hidden = false; aviso('Código enviado para o seu ' + e.contato + '.', 'ok'); q('cod').focus(); contagem(60); }
          else if (r.aguardar) { q('passoEnviar').hidden = true; q('fmCodigo').hidden = false; contagem(r.aguardar); aviso(r.error, ''); }
          else tratarErro(r);
        });
      };
      q('btEnviar').onclick = function () { enviar(this); };
      q('btReenviar').onclick = function () { if (Date.now() < aguardarAte) return; enviar(this); };
      q('cod').oninput = function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); };
      q('fmCodigo').onsubmit = function (ev) {
        ev.preventDefault();
        var b = q('btConfirmar');
        botaoOcupado(b, true, 'Conferindo…');
        chamar('POST', { acao: 'confirmar', codigo: q('cod').value }).then(function (r) {
          botaoOcupado(b, false);
          if (r.ok) desenhar(r.data); else { aviso(r.error, 'erro'); q('cod').value = ''; q('cod').focus(); }
        });
      };
    }
    if (e.etapa === 'identificacao') {
      var enviarIdent = function (corpo, b) {
        botaoOcupado(b, true, 'Conferindo…');
        chamar('POST', Object.assign({ acao: 'identificar' }, corpo)).then(function (r) {
          botaoOcupado(b, false);
          if (r.ok) desenhar(r.data);
          else if (r.bloqueado) desenhar(Object.assign({}, e, { etapa: 'indisponivel', mensagem: r.error }));
          else aviso(r.error, 'erro');
        });
      };
      if (q('btConfirmaCad')) {
        q('btConfirmaCad').onclick = function () { enviarIdent({ confirmar_cadastro: true }, this); };
        q('btOutrosDados').onclick = function () { q('blCadastro').hidden = true; q('fmIdent').hidden = false; };
      }
      q('cpf').oninput = function () {
        var d = this.value.replace(/\D/g, '').slice(0, 11);
        this.value = d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2');
      };
      q('fmIdent').onsubmit = function (ev) { ev.preventDefault(); enviarIdent({ nome: q('nome').value, cpf: q('cpf').value }, q('btIdent')); };
    }
    if (e.etapa === 'documento') {
      carregarPdf();
      q('aceite').onchange = function () { q('btAssinar').disabled = !this.checked; };
      var chave = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(16).slice(2, 12);
      q('btAssinar').onclick = function () {
        if (!q('aceite').checked) return;
        var b = this;
        botaoOcupado(b, true, 'Registrando a assinatura…');
        chamar('POST', { acao: 'assinar', aceite: true, idempotencia: chave }).then(function (r) {
          if (r.ok) carregarEstado();
          else { botaoOcupado(b, false); tratarErro(r); }
        });
      };
      q('btRecusar').onclick = function () { q('blRecusa').hidden = !q('blRecusa').hidden; if (!q('blRecusa').hidden) q('motivo').focus(); };
      q('btConfRecusa').onclick = function () {
        var b = this;
        botaoOcupado(b, true, 'Registrando…');
        chamar('POST', { acao: 'recusar', motivo: q('motivo').value }).then(function (r) {
          botaoOcupado(b, false);
          if (r.ok) desenhar(Object.assign({}, e, { etapa: 'indisponivel', mensagem: 'Recusa registrada. Quem enviou o contrato será avisado.' }));
          else aviso(r.error, 'erro');
        });
      };
    }
  }

  function contagem(seg) {
    aguardarAte = Date.now() + seg * 1000;
    var b = document.getElementById('btReenviar');
    if (!b) return;
    var tick = function () {
      var falta = Math.ceil((aguardarAte - Date.now()) / 1000);
      if (falta <= 0) { b.disabled = false; b.textContent = 'Reenviar código'; clearInterval(timer); timer = null; return; }
      b.disabled = true; b.textContent = 'Reenviar em ' + falta + ' s';
    };
    tick();
    if (timer) clearInterval(timer);
    timer = setInterval(tick, 1000);
  }

  function tratarErro(r) {
    if (r.sessao === false) { desenhar(Object.assign({}, estado || {}, { etapa: 'indisponivel', mensagem: r.error })); return; }
    aviso(r.error || 'Não foi possível concluir agora.', 'erro');
  }

  function carregarPdf() {
    var alvo = document.getElementById('pdfPaginas');
    var url = API + '?documento=1';
    if (!window.pdfjsLib) {
      alvo.innerHTML = '<p class="ass-nota">Não foi possível mostrar o PDF aqui. <a class="ass-link" href="' + url + '&baixar=1">Baixe o PDF</a> para ler antes de assinar.</p>';
      return;
    }
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    window.pdfjsLib.getDocument({ url: url, withCredentials: true }).promise.then(function (doc) {
      alvo.innerHTML = '';
      var largura = Math.min(alvo.clientWidth || 360, 860);
      var fila = Promise.resolve();
      for (var i = 1; i <= doc.numPages; i++) {
        (function (n) {
          fila = fila.then(function () {
            return doc.getPage(n).then(function (pg) {
              var v1 = pg.getViewport({ scale: 1 });
              var escala = largura / v1.width, dpr = window.devicePixelRatio || 1;
              var vp = pg.getViewport({ scale: escala * dpr });
              var cv = document.createElement('canvas');
              cv.width = vp.width; cv.height = vp.height;
              cv.style.width = (vp.width / dpr) + 'px';
              cv.setAttribute('aria-label', 'Página ' + n + ' de ' + doc.numPages);
              alvo.appendChild(cv);
              return pg.render({ canvasContext: cv.getContext('2d'), viewport: vp }).promise;
            });
          });
        })(i);
      }
      return fila.then(function () {
        var fim = document.createElement('p');
        fim.className = 'ass-nota ass-fim-doc';
        fim.textContent = 'Fim do documento (' + doc.numPages + ' página' + (doc.numPages === 1 ? '' : 's') + ').';
        alvo.appendChild(fim);
      });
    }).catch(function () {
      alvo.innerHTML = '<p class="ass-nota">Não foi possível mostrar o PDF aqui. <a class="ass-link" href="' + url + '&baixar=1">Baixe o PDF</a> para ler antes de assinar.</p>';
    });
  }

  function carregarEstado() {
    return chamar('GET', null, '?estado=1').then(function (r) {
      if (r.ok) desenhar(r.data);
      else raiz.innerHTML = '<section class="ass-cartao"><p class="ass-msg">' + esc(r.error) + '</p></section>';
    });
  }

  // Outro link aberto na mesma aba muda só o fragmento, sem recarregar: recomeça com o novo.
  window.addEventListener('hashchange', function () { if (/(?:^|[#&])t=/.test(location.hash)) location.reload(); });

  // Início: o token vem no fragmento; troca por sessão e some da barra de endereço.
  var m = /(?:^|[#&])t=([A-Za-z0-9_-]{43})/.exec(location.hash || '');
  if (m) {
    var token = m[1];
    history.replaceState(null, '', location.pathname);
    chamar('POST', { acao: 'abrir', token: token }).then(function (r) {
      token = null;
      if (r.ok) desenhar(r.data);
      else raiz.innerHTML = '<section class="ass-cartao"><p class="ass-msg">' + esc(r.error) + '</p></section>';
    });
  } else {
    carregarEstado();
  }
})();
