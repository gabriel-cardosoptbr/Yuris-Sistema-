/* ff-contratos.js: tela Contratos do CRM (edição CRM). API em /api/crm_contratos.php.
   Central da conta, lista, ficha do contrato, participantes, convites e evidências.
   Toda regra mora no servidor (app/Contratos); aqui é só a tela. Sem alert/confirm/prompt. */
(function () {
  'use strict';
  var CFG = window.FF_CTR || {};
  var raiz = document.getElementById('ctrApp');
  var apoio = null;
  var filtro = { status: '', busca: '' };
  var EVENTOS = {
    criado: 'Contrato criado', editado: 'Dados editados', documento_anexado: 'PDF anexado', participante_incluido: 'Participante incluído',
    participante_editado: 'Participante editado', participante_removido: 'Participante removido', versao_fechada: 'Versão fechada para assinatura',
    versao_substituida: 'Versão substituída por uma nova', convite_emitido: 'Convite gerado', convite_reemitido: 'Novo link gerado',
    convite_enviado: 'Convite enviado pelo WhatsApp', convite_nao_enviado: 'Convite não enviado', acesso: 'Link aberto',
    codigo_enviado: 'Código enviado', codigo_nao_enviado: 'Código não enviado', codigo_incorreto: 'Código incorreto',
    codigo_confirmado: 'Código confirmado', identificacao: 'Dados conferidos', cpf_divergente: 'CPF diferente do esperado (bloqueado)',
    bloqueio_liberado: 'Bloqueio liberado pela equipe', documento_visualizado: 'Documento entregue ao navegador', assinado: 'Assinou',
    recusado: 'Recusou', concluido: 'Contrato concluído', expirado: 'Prazo vencido', cancelado: 'Fluxo cancelado',
    arquivo_gerado: 'Arquivo gerado', finalizado: 'Documentos finais prontos', arquivo_baixado: 'Arquivo baixado', evidencias_baixadas: 'Evidências baixadas'
  };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function bytes(n) { n = +n || 0; if (n < 1024) return n + ' B'; if (n < 1048576) return (n / 1024).toFixed(1).replace('.', ',') + ' KB'; return (n / 1048576).toFixed(1).replace('.', ',') + ' MB'; }
  function toast(msg, tipo) { if (window.Yuris && window.Yuris.toast) window.Yuris.toast(msg, tipo || 'info'); }

  function api(metodo, corpo, query, multipart) {
    var op = { method: metodo, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (metodo === 'POST') {
      op.headers['X-CSRF-Token'] = CFG.csrf;
      if (multipart) op.body = corpo;
      else { op.headers['Content-Type'] = 'application/json'; op.body = JSON.stringify(corpo || {}); }
    }
    return fetch(CFG.api + (query || ''), op).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Resposta inesperada do servidor.' }; });
    }).catch(function () { return { ok: false, error: 'Sem conexão. Tente de novo.' }; });
  }

  // ── Janela (sem diálogo nativo) ────────────────────────────────────────
  function janela(html, aoAbrir) {
    var ov = document.createElement('div');
    ov.className = 'ctr-overlay';
    ov.innerHTML = '<div class="ctr-janela" role="dialog" aria-modal="true">' + html + '</div>';
    document.body.appendChild(ov);
    var fechar = function () { ov.remove(); document.removeEventListener('keydown', tecla); };
    var tecla = function (e) { if (e.key === 'Escape') fechar(); };
    document.addEventListener('keydown', tecla);
    ov.addEventListener('mousedown', function (e) { if (e.target === ov) fechar(); });
    ov.querySelectorAll('[data-fechar]').forEach(function (b) { b.onclick = fechar; });
    if (aoAbrir) aoAbrir(ov, fechar);
    var f = ov.querySelector('input,select,textarea'); if (f) f.focus();
    return fechar;
  }
  function confirmar(titulo, texto, rotulo, comMotivo, aoConfirmar) {
    janela('<h3>' + esc(titulo) + '</h3><p class="sub">' + esc(texto) + '</p>' +
      (comMotivo ? '<label>' + esc(comMotivo) + '</label><textarea id="jMotivo" rows="3" maxlength="500"></textarea>' : '') +
      '<div class="ctr-erro" id="jErro"></div><div class="ctr-janela-acoes"><button class="ctr-btn" data-fechar>Voltar</button>' +
      '<button class="ctr-btn ctr-btn-principal" id="jOk">' + esc(rotulo) + '</button></div>', function (ov, fechar) {
      ov.querySelector('#jOk').onclick = function () {
        var b = this; b.disabled = true;
        aoConfirmar(comMotivo ? ov.querySelector('#jMotivo').value : null).then(function (r) {
          b.disabled = false;
          if (r && r.ok === false) ov.querySelector('#jErro').textContent = r.error || 'Não foi possível.'; else fechar();
        });
      };
    });
  }
  function copiar(texto, botao) {
    var ok = function () { if (botao) { var t = botao.textContent; botao.textContent = 'Copiado'; setTimeout(function () { botao.textContent = t; }, 1600); } };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(texto).then(ok);
    else { var i = document.createElement('textarea'); i.value = texto; document.body.appendChild(i); i.select(); try { document.execCommand('copy'); ok(); } catch (e) {} i.remove(); }
  }

  // ── Lista e central ────────────────────────────────────────────────────
  function lista() {
    history.replaceState(null, '', '/contratos.php');
    raiz.innerHTML = '<div class="ctr-vazio">Carregando…</div>';
    Promise.all([api('GET', null, '?central=1'), api('GET', null, '?lista=1&status=' + encodeURIComponent(filtro.status) + '&busca=' + encodeURIComponent(filtro.busca))]).then(function (rs) {
      var cen = rs[0].ok ? rs[0].data : null, itens = rs[1].ok ? rs[1].data : [];
      var h = '';
      if (cen) {
        var abertos = cen.por_status.aguardando + cen.por_status.parcial;
        h += '<div class="ctr-central">' +
          num('Contratos', cen.total, cen.por_status.rascunho + ' em rascunho') +
          num('Aguardando assinaturas', abertos, cen.por_status.parcial + ' parcialmente assinados') +
          num('Concluídos', cen.por_status.concluido, cen.assinaturas + ' assinaturas registradas') +
          num('Espaço dos assinados', bytes(cen.bytes_assinados), cen.contratos_assinados + ' contratos · ' + bytes(cen.bytes_total) + ' no total') +
          '</div>';
      }
      h += '<div class="ctr-filtros">' + [['', 'Todos'], ['rascunho', 'Rascunho'], ['aguardando', 'Aguardando'], ['parcial', 'Parcial'], ['concluido', 'Concluídos'],
        ['recusado', 'Recusados'], ['expirado', 'Expirados'], ['cancelado', 'Cancelados']].map(function (a) {
        return '<button class="ctr-aba' + (filtro.status === a[0] ? ' ativa' : '') + '" data-status="' + a[0] + '">' + a[1] + '</button>';
      }).join('') + '<input class="ctr-input ctr-busca" id="ctrBusca" placeholder="Buscar pelo título" value="' + esc(filtro.busca) + '"></div>';
      if (!rs[1].ok) h += '<div class="ctr-aviso vermelho">' + esc(rs[1].error) + '</div>';
      else if (!itens.length) h += '<div class="ctr-cartao ctr-vazio">Nenhum contrato aqui ainda. Use "Novo contrato" para preparar o primeiro.</div>';
      else {
        h += '<div class="ctr-cartao ctr-rolagem"><table class="ctr-tabela"><thead><tr><th>Contrato</th><th>Cliente / negociação</th><th>Situação</th><th>Assinaturas</th><th>Prazo</th><th>Atualizado</th></tr></thead><tbody>' +
          itens.map(function (c) {
            return '<tr class="clicavel" data-id="' + c.id + '"><td><div class="titulo">' + esc(c.titulo) + '</div><div class="ctr-sub">' + esc(c.responsavel || '') + '</div></td>' +
              '<td>' + esc(c.cliente || c.negociacao || '-') + '</td><td><span class="ctr-pill ' + c.status + '">' + esc(c.status_rotulo) + '</span></td>' +
              '<td>' + c.assinaturas + ' de ' + c.participantes + '</td><td>' + esc(c.prazo || '-') + '</td><td>' + esc(c.atualizado_em) + '</td></tr>';
          }).join('') + '</tbody></table></div>';
      }
      raiz.innerHTML = h;
      raiz.querySelectorAll('.ctr-aba').forEach(function (b) { b.onclick = function () { filtro.status = this.dataset.status; lista(); }; });
      var t; var busca = document.getElementById('ctrBusca');
      busca.oninput = function () { clearTimeout(t); var v = this.value; t = setTimeout(function () { filtro.busca = v; lista(); }, 350); };
      if (filtro.busca) { busca.focus(); busca.setSelectionRange(busca.value.length, busca.value.length); }
      raiz.querySelectorAll('tr[data-id]').forEach(function (tr) { tr.onclick = function () { ficha(+this.dataset.id); }; });
    });
  }
  function num(rot, v, sub) { return '<div class="ctr-num"><span>' + esc(rot) + '</span><b>' + esc(v) + '</b><small>' + esc(sub) + '</small></div>'; }

  // ── Busca de cliente e negociação (lista ao clicar, filtra ao digitar) ──
  function buscador(ov, campo, caixa, tipo, aoEscolher, aoLimpar) {
    var inp = ov.querySelector(campo), box = ov.querySelector(caixa), t, ultima = null;
    var rot = function (x) { return (x.tipo === 'cliente' ? 'Cliente: ' : 'Negociação: ') + x.nome; };
    function buscar(q) {
      if (ultima === q && box.innerHTML) return;
      ultima = q;
      box.innerHTML = '<div class="ctr-sugestoes"><div class="ctr-sug-nota">Buscando…</div></div>';
      api('GET', null, '?buscar=' + encodeURIComponent(q) + (tipo ? '&tipo=' + tipo : '')).then(function (r) {
        if (ultima !== q) return;
        var it = r.ok ? r.data : [];
        if (!r.ok) { box.innerHTML = '<div class="ctr-sugestoes"><div class="ctr-sug-nota">' + esc(r.error || 'Não foi possível buscar.') + '</div></div>'; return; }
        if (!it.length) {
          box.innerHTML = '<div class="ctr-sugestoes"><div class="ctr-sug-nota">' + (q ? 'Nenhum ' + (tipo === 'cliente' ? 'cliente' : 'cliente ou negociação') + ' com "' + esc(q) + '".' :
            'Nenhum ' + (tipo === 'cliente' ? 'cliente cadastrado' : 'cliente ou negociação') + ' ainda.') + '</div></div>';
          return;
        }
        box.innerHTML = '<div class="ctr-sugestoes">' + (r.recentes ? '<div class="ctr-sug-nota">Mais recentes. Digite para buscar pelo nome, CPF ou telefone.</div>' : '') +
          it.map(function (x, i) {
            return '<button type="button" data-i="' + i + '"><b>' + esc(rot(x)) + '</b>' + (x.detalhe ? '<span>' + esc(x.detalhe) + '</span>' : '') + '</button>';
          }).join('') + '</div>';
        box.querySelectorAll('button').forEach(function (b) {
          b.onmousedown = function (e) { e.preventDefault(); };
          b.onclick = function () { var x = it[+this.dataset.i]; inp.value = rot(x); box.innerHTML = ''; ultima = null; aoEscolher(x); };
        });
      });
    }
    inp.addEventListener('focus', function () { if (!inp.dataset.escolhido) buscar(inp.value.trim()); });
    inp.addEventListener('input', function () {
      delete inp.dataset.escolhido;
      if (aoLimpar) aoLimpar();
      var q = inp.value.trim(); clearTimeout(t);
      t = setTimeout(function () { buscar(q); }, 250);
    });
    inp.addEventListener('blur', function () { setTimeout(function () { box.innerHTML = ''; ultima = null; }, 150); });
    return { escolhido: function () { inp.dataset.escolhido = '1'; } };
  }

  // ── Criar / editar ─────────────────────────────────────────────────────
  function formContrato(c, pre) {
    c = c || {}; pre = pre || {};
    var equipe = (apoio && apoio.equipe) || [];
    var vinc = c.cliente ? 'Cliente: ' + c.cliente : c.negociacao ? 'Negociação: ' + c.negociacao : pre.cliente_id ? 'Cliente #' + pre.cliente_id : pre.card_id ? 'Negociação #' + pre.card_id : '';
    janela('<h3>' + (c.id ? 'Editar contrato' : 'Novo contrato') + '</h3><p class="sub">Depois de criar, anexe o PDF e inclua os participantes.</p>' +
      '<label>Título</label><input id="fTitulo" maxlength="200" value="' + esc(c.titulo || '') + '" placeholder="Ex.: Contrato de prestação de serviços">' +
      '<div class="duas"><div><label>Responsável</label><select id="fResp">' + equipe.map(function (u) {
        return '<option value="' + u.id + '"' + ((c.responsavel_id || apoio.eu) === u.id ? ' selected' : '') + '>' + esc(u.nome) + '</option>';
      }).join('') + '</select></div><div><label>Prazo para assinatura</label><input type="date" id="fPrazo" value="' + esc(c.prazo_data || '') + '"></div></div>' +
      '<label>Cliente ou negociação (opcional)</label><input id="fVinc" autocomplete="off" placeholder="Clique para ver os recentes ou digite o nome, CPF ou telefone" value="' + esc(vinc) + '"><div id="fSug"></div>' +
      '<div class="ctr-erro" id="fErro"></div><div class="ctr-janela-acoes"><button class="ctr-btn" data-fechar>Cancelar</button>' +
      '<button class="ctr-btn ctr-btn-principal" id="fSalvar">' + (c.id ? 'Salvar' : 'Criar contrato') + '</button></div>', function (ov, fechar) {
      var sel = { cliente_id: c.cliente_id || pre.cliente_id || null, card_id: c.card_id || pre.card_id || null };
      var bc = buscador(ov, '#fVinc', '#fSug', '', function (x) {
        sel = x.tipo === 'cliente' ? { cliente_id: x.id, card_id: null } : { cliente_id: null, card_id: x.id };
        bc.escolhido();
      }, function () { sel = { cliente_id: null, card_id: null }; });
      if (vinc) bc.escolhido();
      ov.querySelector('#fSalvar').onclick = function () {
        var b = this; b.disabled = true;
        var corpo = { acao: c.id ? 'atualizar' : 'criar', id: c.id || 0, titulo: ov.querySelector('#fTitulo').value, responsavel_id: +ov.querySelector('#fResp').value || null,
          prazo: ov.querySelector('#fPrazo').value, cliente_id: sel.cliente_id, card_id: sel.card_id };
        api('POST', corpo).then(function (r) {
          b.disabled = false;
          if (!r.ok) { ov.querySelector('#fErro').textContent = r.error; return; }
          fechar(); ficha(c.id || r.id);
        });
      };
    });
  }

  // ── Participante ───────────────────────────────────────────────────────
  function formParticipante(c, p) {
    p = p || {};
    var papeis = (apoio && apoio.papeis) || {};
    janela('<h3>' + (p.id ? 'Editar participante' : 'Incluir participante') + '</h3>' +
      '<p class="sub">Cada participante recebe o próprio link e confirma pelo WhatsApp informado aqui. Trocar o WhatsApp ou o CPF de quem já foi convidado invalida o link anterior.</p>' +
      '<label>Cliente cadastrado (opcional)</label><input id="pBusca" autocomplete="off" placeholder="Clique para ver os clientes ou digite o nome, CPF ou telefone" value="' +
        esc(p.cliente_id ? 'Ligado ao cadastro do cliente' : '') + '"><div id="pSug"></div>' +
      '<div class="duas"><div><label>Papel</label><select id="pPapel">' + Object.keys(papeis).map(function (k) {
        return '<option value="' + k + '"' + (p.papel === k ? ' selected' : '') + '>' + esc(papeis[k]) + '</option>';
      }).join('') + '</select></div><div><label>WhatsApp (com DDD)</label><input id="pTel" inputmode="tel" placeholder="' + (p.id ? 'Informe de novo para manter ou trocar' : '(11) 99999-0000') + '"></div></div>' +
      '<label>Nome completo</label><input id="pNome" maxlength="160" value="' + esc(p.nome || '') + '">' +
      '<label>CPF esperado (opcional)</label><input id="pCpf" inputmode="numeric" maxlength="14" placeholder="' + (p.cpf ? 'Hoje: ' + esc(p.cpf) + '. Informe de novo para manter' : 'Se informado, a assinatura com outro CPF fica bloqueada para revisão') + '">' +
      '<div id="pRep"><div class="duas"><div><label>Empresa representada</label><input id="pEmp" maxlength="160" value="' + esc(p.empresa || '') + '"></div>' +
      '<div><label>CNPJ</label><input id="pCnpj" maxlength="18" value="' + esc(p.cnpj || '') + '"></div></div><label>Função</label><input id="pFunc" maxlength="80" value="' + esc(p.funcao || '') + '"></div>' +
      '<label class="ctr-marca-check"><input type="checkbox" id="pObrig"' + (p.obrigatorio === false ? '' : ' checked') + '> Assinatura obrigatória para concluir</label>' +
      '<div class="ctr-erro" id="pErro"></div><div class="ctr-janela-acoes"><button class="ctr-btn" data-fechar>Cancelar</button>' +
      '<button class="ctr-btn ctr-btn-principal" id="pSalvar">Salvar participante</button></div>', function (ov, fechar) {
      var rep = function () { ov.querySelector('#pRep').style.display = ov.querySelector('#pPapel').value === 'representante' || ov.querySelector('#pEmp').value ? '' : 'none'; };
      ov.querySelector('#pPapel').onchange = rep; rep();
      var clienteId = p.cliente_id || null;
      var bp = buscador(ov, '#pBusca', '#pSug', 'cliente', function (x) {
        clienteId = x.id; bp.escolhido();
        ov.querySelector('#pNome').value = x.nome || '';
        if (x.telefone) ov.querySelector('#pTel').value = x.telefone;
        if (x.cpf) ov.querySelector('#pCpf').value = x.cpf.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
      }, function () { clienteId = null; });
      if (p.cliente_id) bp.escolhido();
      ov.querySelector('#pSalvar').onclick = function () {
        var b = this; b.disabled = true;
        api('POST', { acao: 'participante_salvar', id: c.id, participante_id: p.id || 0, papel: ov.querySelector('#pPapel').value,
          telefone: ov.querySelector('#pTel').value, nome: ov.querySelector('#pNome').value, cpf: ov.querySelector('#pCpf').value,
          empresa: ov.querySelector('#pEmp').value, cnpj: ov.querySelector('#pCnpj').value, funcao: ov.querySelector('#pFunc').value,
          obrigatorio: ov.querySelector('#pObrig').checked, cliente_id: clienteId }).then(function (r) {
          b.disabled = false;
          if (!r.ok) { ov.querySelector('#pErro').textContent = r.error; return; }
          fechar(); ficha(c.id);
        });
      };
    });
    // O servidor espera o id do participante no campo "id" do participante; o contrato vai em "id" da ação.
  }

  function mostrarLinks(c, links, titulo) {
    janela('<h3>' + esc(titulo) + '</h3><p class="sub">Cada link aparece só agora. Para copiar de novo, gere outro: o anterior deixa de valer.</p>' +
      links.map(function (l, i) {
        var st = l.enviado === true ? '<span class="ctr-pill concluido">Enviado pelo WhatsApp</span>' : l.enviado === false ? '<span class="ctr-pill recusado">Não enviado: ' + esc(l.erro || '') + '</span>' : '';
        return '<label>' + esc(l.nome || 'Participante') + ' ' + st + '</label><div class="ctr-link-caixa"><input readonly value="' + esc(l.link) + '" id="lk' + i + '"><button class="ctr-btn ctr-btn-sm" data-copiar="' + i + '">Copiar</button></div>';
      }).join('') + '<div class="ctr-janela-acoes"><button class="ctr-btn ctr-btn-principal" data-fechar>Pronto</button></div>', function (ov) {
      ov.querySelectorAll('[data-copiar]').forEach(function (b) { b.onclick = function () { copiar(ov.querySelector('#lk' + this.dataset.copiar).value, this); }; });
    });
  }

  // ── Ficha ──────────────────────────────────────────────────────────────
  function ficha(id) {
    history.replaceState(null, '', '/contratos.php?id=' + id);
    raiz.innerHTML = '<div class="ctr-vazio">Carregando…</div>';
    api('GET', null, '?id=' + id).then(function (r) {
      if (!r.ok) { raiz.innerHTML = '<button class="ctr-voltar" id="cVoltar">← Contratos</button><div class="ctr-aviso vermelho">' + esc(r.error) + '</div>'; document.getElementById('cVoltar').onclick = lista; return; }
      desenharFicha(r.data);
    });
  }

  function desenharFicha(c) {
    var aberto = c.status === 'aguardando' || c.status === 'parcial';
    var editavel = c.status === 'rascunho' || aberto;
    var rascunho = c.versoes.filter(function (v) { return v.status === 'rascunho'; })[0];
    var h = '<button class="ctr-voltar" id="cVoltar">← Contratos</button>' +
      '<div class="ctr-ficha-cab"><div><h2>' + esc(c.titulo) + '</h2><span class="ctr-pill ' + c.status + '">' + esc(c.status_rotulo) + '</span></div><div class="ctr-acoes">' +
      (editavel ? '<button class="ctr-btn" id="cEditar">Editar</button>' : '') +
      '<a class="ctr-btn" href="' + CFG.api + '?evidencias=' + c.id + '">Baixar evidências</a>' +
      (editavel && c.pode_gerenciar ? '<button class="ctr-btn ctr-btn-perigo" id="cCancelar">Cancelar fluxo</button>' : '') + '</div></div>';

    if (c.motivo_encerramento) h += '<div class="ctr-aviso ' + (c.status === 'concluido' ? 'verde' : 'ambar') + '">' + esc(c.motivo_encerramento) + (c.encerrado_em ? ' · ' + esc(c.encerrado_em) : '') + '</div>';
    if (!c.cadeia.integra) h += '<div class="ctr-aviso vermelho">A trilha de eventos não confere a partir do evento #' + esc(c.cadeia.quebra_em) + '. Avise o suporte: o registro pode ter sido alterado fora do sistema.</div>';

    h += '<div class="ctr-cartao"><h3>Dados</h3><div class="ctr-grade">' +
      campo('Responsável', c.responsavel) + campo('Cliente', c.cliente) + campo('Negociação', c.negociacao) + campo('Prazo para assinatura', c.prazo || 'Sem prazo') +
      campo('Criado em', c.criado_em) + (c.concluido_em ? campo('Concluído em', c.concluido_em) : '') +
      '<div class="ctr-campo"><span>Código de verificação</span><b>' + esc(c.codigo_verificacao) + ' · <a href="' + esc(c.verificacao_url) + '" target="_blank" rel="noopener noreferrer">consultar</a></b></div>' +
      '<div class="ctr-campo"><span>Identificador</span><b class="ctr-hash">' + esc(c.uuid) + '</b></div></div></div>';

    // Documento
    h += '<div class="ctr-cartao"><h3>Documento' + (editavel ? '<label class="ctr-btn ctr-btn-sm" style="margin:0">' + (c.versoes.length ? 'Enviar nova versão (PDF)' : 'Anexar PDF') +
      '<input type="file" id="cPdf" accept="application/pdf" hidden></label>' : '') + '</h3>';
    if (!c.versoes.length) h += '<div class="ctr-aviso">Anexe o PDF pronto do contrato. Ele é fechado e recebe o hash SHA-256 quando for enviado para assinatura.</div>';
    h += c.versoes.map(function (v) {
      var rot = { rascunho: 'Rascunho (ainda não enviado)', aberta: 'Em assinatura', substituida: 'Substituída', concluida: 'Assinada', encerrada: 'Encerrada' }[v.status] || v.status;
      return '<div class="ctr-evento"><time>Versão ' + v.numero + '</time><div><b>' + esc(rot) + '</b> · ' + esc(v.nome_arquivo) + ' · ' + bytes(v.bytes) +
        (v.aberta_em ? ' · fechada em ' + esc(v.aberta_em) : '') + (v.substituida_em ? ' · substituída em ' + esc(v.substituida_em) : '') +
        '<div class="ctr-hash">SHA-256 ' + esc(v.sha256) + '</div><a class="ctr-sub" href="' + CFG.api + '?baixar=' + esc(v.arquivo_uuid) + '">Baixar PDF original</a></div></div>';
    }).join('');
    if (rascunho && editavel) {
      h += '<div class="ctr-envio"><label class="ctr-marca-check" style="margin:0"><input type="checkbox" id="cZap" checked> Mandar os convites pelo WhatsApp da conta</label>' +
        '<button class="ctr-btn ctr-btn-principal" id="cEnviar">' + (aberto ? 'Substituir pela versão ' + rascunho.numero + ' e reenviar' : 'Fechar versão e enviar para assinatura') + '</button></div>' +
        (aberto ? '<div class="ctr-aviso ambar" style="margin-top:10px">Ao substituir, os links atuais deixam de valer e todos assinam de novo a versão nova. As assinaturas da versão anterior ficam registradas como da versão substituída.</div>' : '');
    }
    h += '</div>';

    // Participantes
    h += '<div class="ctr-cartao"><h3>Participantes' + (editavel ? '<button class="ctr-btn ctr-btn-sm" id="cAddPart">Incluir participante</button>' : '') + '</h3>';
    if (!c.participantes.length) h += '<div class="ctr-aviso">Inclua contratante, contratado, representante da empresa e testemunhas. Todos assinam individualmente.</div>';
    else h += '<div class="ctr-rolagem"><table class="ctr-tabela"><thead><tr><th>Participante</th><th>Confirmação</th><th>Situação</th><th>Assinatura</th></tr></thead><tbody>' + c.participantes.map(function (p) {
      var ass = p.assinaturas.filter(function (a) { return a.versao_atual; })[0];
      var antigas = p.assinaturas.filter(function (a) { return !a.versao_atual; });
      var acoes = '';
      if (aberto && p.status !== 'assinou' && p.status !== 'cancelado' && p.status !== 'recusou') {
        if (p.status === 'bloqueado') { if (c.pode_gerenciar) acoes += '<button class="ctr-btn ctr-btn-sm" data-liberar="' + p.id + '">Revisar e liberar</button>'; }
        else acoes += '<button class="ctr-btn ctr-btn-sm" data-link="' + p.id + '">Gerar link</button><button class="ctr-btn ctr-btn-sm" data-reenviar="' + p.id + '">Reenviar pelo WhatsApp</button>';
      }
      if (editavel && p.status !== 'assinou' && p.status !== 'cancelado') acoes += '<button class="ctr-btn ctr-btn-sm" data-editar="' + p.id + '">Editar</button>';
      if (editavel && !p.assinaturas.length && p.status !== 'cancelado') acoes += '<button class="ctr-btn ctr-btn-sm ctr-btn-perigo" data-remover="' + p.id + '">Remover</button>';
      return '<tr><td><div class="titulo">' + esc(p.nome) + '</div><div class="ctr-sub">' + esc(p.papel_rotulo) + (p.obrigatorio ? '' : ' · opcional') +
        (p.empresa ? ' · ' + esc(p.empresa) + ' (CNPJ ' + esc(p.cnpj) + ', ' + esc(p.funcao) + ')' : '') + '</div>' + (acoes ? '<div class="ctr-linha-acoes">' + acoes + '</div>' : '') + '</td>' +
        '<td>WhatsApp ' + esc(p.whatsapp) + (p.cpf ? '<div class="ctr-sub">CPF esperado ' + esc(p.cpf) + '</div>' : '') +
        (p.convite ? '<div class="ctr-sub">Link de ' + esc(p.convite.ultimo) + (p.convite.enviado ? ', enviado ' + esc(p.convite.enviado) : '') + (p.convite.ativo ? ', vale até ' + esc(p.convite.expira) : '') + '</div>' : '') + '</td>' +
        '<td><span class="ctr-pill ' + p.status + '">' + esc(p.status_rotulo) + '</span>' + (p.bloqueio_motivo ? '<div class="ctr-sub">' + esc(p.bloqueio_motivo) + '</div>' : '') + '</td>' +
        '<td>' + (ass ? esc(ass.assinado_em) + '<div class="ctr-sub">' + esc(ass.nome_declarado) + ' · ' + esc(ass.cpf) + ' · ' + (ass.origem === 'autodeclarado' ? 'dados declarados' : 'cadastro confirmado') +
          '</div><div class="ctr-sub">Código pelo ' + esc(ass.contato) + ' · IP ' + esc(ass.ip || '-') + '</div>' : '-') +
        (antigas.length ? '<div class="ctr-sub">Assinou a versão ' + antigas.map(function (a) { return a.versao + ' em ' + esc(a.assinado_em); }).join(', ') + ' (substituída)</div>' : '') + '</td></tr>';
    }).join('') + '</tbody></table></div>';
    h += '</div>';

    // Arquivos finais
    if (c.status === 'concluido') {
      var nomes = { comprovante: 'Comprovante de assinaturas', conclusao: 'PDF de conclusão (contrato + comprovante)', manifesto: 'Manifesto de evidências (selado)' };
      h += '<div class="ctr-cartao"><h3>Documentos finais</h3>';
      if (c.finalizacao.status !== 'ok') h += '<div class="ctr-aviso ambar">Gerando os documentos finais' + (c.finalizacao.erro ? ': ' + esc(String(c.finalizacao.erro).replace(/\.$/, '')) : '') +
        '. As assinaturas já estão registradas. ' + (c.pode_gerenciar ? '<button class="ctr-btn ctr-btn-sm" id="cReproc">Tentar agora</button>' : '') + '</div>';
      h += c.arquivos_finais.map(function (a) {
        return '<div class="ctr-evento"><time>' + esc(a.criado_em) + '</time><div><b>' + esc(nomes[a.tipo] || a.tipo) + '</b> · ' + bytes(a.bytes) +
          '<div class="ctr-hash">SHA-256 ' + esc(a.sha256) + '</div><a class="ctr-sub" href="' + CFG.api + '?baixar=' + esc(a.uuid) + '">Baixar</a></div></div>';
      }).join('') + '</div>';
    }

    // Trilha
    h += '<div class="ctr-cartao"><h3>Trilha de eventos <span class="ctr-pill ' + (c.cadeia.integra ? 'concluido' : 'recusado') + '">' + (c.cadeia.integra ? 'Corrente íntegra · ' + c.cadeia.quantidade + ' eventos' : 'Corrente quebrada') + '</span></h3>' +
      c.eventos.slice().reverse().map(function (e) {
        var part = e.participante_id ? (c.participantes.filter(function (p) { return p.id === e.participante_id; })[0] || {}).nome : '';
        var det = e.dados && (e.dados.motivo || e.dados.erro || e.dados.contato || e.dados.cpf_informado || '');
        return '<div class="ctr-evento"><time>' + esc(new Date(e.criado_em).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' })) + '</time><div><b>' + esc(EVENTOS[e.tipo] || e.tipo) + '</b>' +
          (part ? ' · ' + esc(part) : '') + (e.usuario ? ' · por ' + esc(e.usuario) : '') + (det ? '<div class="ctr-sub">' + esc(det) + '</div>' : '') + '</div></div>';
      }).join('') + '</div>';

    raiz.innerHTML = h;
    var q = function (s) { return raiz.querySelector(s); };
    q('#cVoltar').onclick = lista;
    if (q('#cEditar')) q('#cEditar').onclick = function () { formContrato(c); };
    if (q('#cCancelar')) q('#cCancelar').onclick = function () {
      confirmar('Cancelar o fluxo de assinatura', 'Ninguém mais consegue assinar. As assinaturas já feitas ficam registradas; cancelar o fluxo não desfaz um contrato já celebrado.', 'Cancelar fluxo', 'Motivo', function (m) {
        return api('POST', { acao: 'cancelar', id: c.id, motivo: m }).then(function (r) { if (r.ok) ficha(c.id); return r; });
      });
    };
    if (q('#cPdf')) q('#cPdf').onchange = function () {
      var f = this.files[0]; if (!f) return;
      var fd = new FormData(); fd.append('acao', 'anexar'); fd.append('id', c.id); fd.append('arquivo', f); fd.append('csrf_token', CFG.csrf);
      toast('Enviando o PDF…');
      api('POST', fd, '', true).then(function (r) { if (!r.ok) toast(r.error, 'error'); ficha(c.id); });
    };
    if (q('#cEnviar')) q('#cEnviar').onclick = function () {
      var b = this, zap = q('#cZap').checked;
      var go = function () {
        b.disabled = true;
        return api('POST', { acao: 'enviar', id: c.id, whatsapp: zap }).then(function (r) {
          b.disabled = false;
          if (!r.ok) { toast(r.error, 'error'); return r; }
          ficha(c.id); mostrarLinks(c, r.data.links, 'Contrato enviado para assinatura'); return r;
        });
      };
      if (aberto) confirmar('Substituir a versão em assinatura', 'Os links atuais deixam de valer e todos assinam de novo a versão nova.', 'Substituir e reenviar', null, go);
      else go();
    };
    if (q('#cAddPart')) q('#cAddPart').onclick = function () { formParticipante(c); };
    if (q('#cReproc')) q('#cReproc').onclick = function () { this.disabled = true; api('POST', { acao: 'reprocessar', id: c.id }).then(function () { ficha(c.id); }); };
    var porId = function (id) { return c.participantes.filter(function (p) { return p.id === +id; })[0]; };
    raiz.querySelectorAll('[data-editar]').forEach(function (b) { b.onclick = function () { formParticipante(c, porId(this.dataset.editar)); }; });
    raiz.querySelectorAll('[data-remover]').forEach(function (b) {
      b.onclick = function () {
        var p = porId(this.dataset.remover);
        confirmar('Remover participante', p.nome + ' sai do contrato e o link dele deixa de valer.', 'Remover', null, function () {
          return api('POST', { acao: 'participante_remover', id: c.id, participante_id: p.id }).then(function (r) { if (r.ok) ficha(c.id); return r; });
        });
      };
    });
    var novoLink = function (pid, zap) {
      var p = porId(pid);
      confirmar(zap ? 'Reenviar convite' : 'Gerar novo link', 'O link anterior de ' + p.nome + ' deixa de valer.', zap ? 'Reenviar pelo WhatsApp' : 'Gerar link', null, function () {
        return api('POST', { acao: 'novo_link', id: c.id, participante_id: p.id, whatsapp: zap }).then(function (r) {
          if (r.ok) { ficha(c.id); mostrarLinks(c, [{ nome: p.nome, link: r.data.link, enviado: r.data.enviado, erro: r.data.erro }], zap ? 'Convite reenviado' : 'Novo link'); }
          return r;
        });
      });
    };
    raiz.querySelectorAll('[data-link]').forEach(function (b) { b.onclick = function () { novoLink(this.dataset.link, false); }; });
    raiz.querySelectorAll('[data-reenviar]').forEach(function (b) { b.onclick = function () { novoLink(this.dataset.reenviar, true); }; });
    raiz.querySelectorAll('[data-liberar]').forEach(function (b) {
      b.onclick = function () {
        var p = porId(this.dataset.liberar);
        confirmar('Revisar e liberar ' + p.nome, (p.bloqueio_motivo || '') + ' Confirme com a pessoa por outro meio antes de liberar. Se o CPF esperado estava errado, corrija em "Editar".', 'Liberar e gerar link', 'O que foi conferido', function (m) {
          return api('POST', { acao: 'liberar', id: c.id, participante_id: p.id, motivo: m, whatsapp: false }).then(function (r) {
            if (r.ok) { ficha(c.id); mostrarLinks(c, [{ nome: p.nome, link: r.data.link }], 'Participante liberado'); }
            return r;
          });
        });
      };
    });
  }
  function campo(rot, v) { return '<div class="ctr-campo"><span>' + esc(rot) + '</span><b>' + esc(v || '-') + '</b></div>'; }

  // ── Início ─────────────────────────────────────────────────────────────
  api('GET', null, '?apoio=1').then(function (r) {
    apoio = r.ok ? r.data : { equipe: [], papeis: {}, eu: 0 };
    var b = document.getElementById('ctrNovo');
    if (b) b.onclick = function () { formContrato(null); };
    var a = CFG.abrir || {};
    if (a.id) ficha(a.id); else lista();
    if (a.novo || a.cliente_id || a.card_id) formContrato(null, { cliente_id: a.cliente_id || null, card_id: a.card_id || null });
  });
})();
