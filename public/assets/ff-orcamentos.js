/* ═══════════════════════════════════════════════════════════════════════
   ff-orcamentos.js: a tela Orçamentos do CRM (edição Fleetiflow).
   Regra em app/Orcamentos/, API em /api/crm_orcamentos.php.

   - Lista com abas (Ativos, Rascunho, Aprovado, Negócio fechado, Rejeitado)
     e a aba Serviços e taxas (o catálogo, com repasse imediato/mensal e
     "cliente vê").
   - Ficha com "Para o cliente" (igual à impressão) e "Controle do escritório".
   - Formulário: lead ou cliente, placa, serviços e débitos do veículo.
   Endereços: ?id=N abre a ficha; ?novo=1&card=N abre o formulário já com o lead.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var CFG = window.FF_ORC || {};
  // Modo veículo (placa, débitos, honorário) ou genérico (só serviços). Ver Orcamento::modoVeiculo.
  var VEICULO = CFG.veiculo !== false;
  var raiz = document.getElementById('orcApp');
  if (!raiz) return;

  var REAL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  var ROTULO = { RASCUNHO: 'Rascunho', APROVADO: 'Aprovado', CONVERTIDO: 'Negócio fechado', REJEITADO: 'Rejeitado' };
  var ABAS = [
    { id: 'ativos', rotulo: 'Ativos' }, { id: 'RASCUNHO', rotulo: 'Rascunho' }, { id: 'APROVADO', rotulo: 'Aprovado' },
    { id: 'CONVERTIDO', rotulo: 'Negócio fechado' }, { id: 'REJEITADO', rotulo: 'Rejeitado' }
  ];
  var estado = { aba: 'ativos', apoio: null };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function dinheiro(v) { return REAL.format(Number(v) || 0); }
  function numero(texto) {
    var s = String(texto == null ? '' : texto).trim();
    if (s.indexOf(',') >= 0) s = s.replace(/\./g, '').replace(',', '.');
    var n = Number(s);
    return isFinite(n) ? n : 0;
  }
  function paraTexto(n) { return n ? String(n).replace('.', ',') : ''; }
  function dataBr(s) { return s ? new Date(String(s).replace(' ', 'T')).toLocaleDateString('pt-BR') : '—'; }

  function api(metodo, qs, corpo) {
    var op = { method: metodo, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (corpo) {
      op.headers['Content-Type'] = 'application/json';
      op.headers['X-CSRF-Token'] = CFG.csrf || '';
      op.body = JSON.stringify(corpo);
    }
    return fetch(CFG.api + (qs || ''), op)
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Resposta inválida do servidor.' }; }); })
      .catch(function () { return { ok: false, error: 'Sem conexão. Tente de novo.' }; });
  }

  function apoio() {
    if (estado.apoio) return Promise.resolve(estado.apoio);
    return api('GET', '?apoio=1').then(function (r) {
      if (r.ok) estado.apoio = r.data;
      return estado.apoio;
    });
  }

  function navegar(params) {
    var q = new URLSearchParams(params).toString();
    history.pushState(null, '', location.pathname + (q ? '?' + q : ''));
    rotear();
  }
  window.addEventListener('popstate', rotear);

  /* ── Lista ───────────────────────────────────────────────────────────── */
  function renderLista(aviso) {
    var abas = ABAS.map(function (a) {
      return '<button type="button" class="orc-aba" data-aba="' + a.id + '" aria-pressed="' + (estado.aba === a.id) + '">' + a.rotulo + '</button>';
    }).join('') + '<span class="orc-aba-sep"></span><button type="button" class="orc-aba" data-aba="servicos" aria-pressed="' + (estado.aba === 'servicos') + '">Serviços e taxas</button>';
    raiz.innerHTML = '<div class="orc-abas" role="group" aria-label="Situação">' + abas + '</div>' +
      (aviso ? '<p class="orc-aviso">' + esc(aviso) + '</p>' : '') + '<div data-conteudo><p class="orc-vazio">Carregando…</p></div>';
    raiz.querySelectorAll('[data-aba]').forEach(function (b) {
      b.addEventListener('click', function () { estado.aba = b.dataset.aba; renderLista(); });
    });
    var alvo = raiz.querySelector('[data-conteudo]');
    if (estado.aba === 'servicos') return renderServicos(alvo);

    api('GET', estado.aba === 'ativos' ? '' : '?status=' + estado.aba).then(function (r) {
      if (!r.ok) { alvo.innerHTML = '<p class="orc-erro">' + esc(r.error) + '</p>'; return; }
      if (!r.data.length) {
        alvo.innerHTML = '<div class="orc-cartao orc-vazio">' + (VEICULO
          ? 'Nenhum orçamento aqui. Monte um com os serviços e os débitos do veículo: o cliente recebe as taxas e o honorário, e o controle do escritório fica na ficha.'
          : 'Nenhum orçamento aqui. Monte um com os serviços do cliente: ele recebe o que é cobrado, e o controle interno fica na ficha.') + '</div>';
        return;
      }
      alvo.innerHTML = '<div class="orc-tabela-wrap"><table class="orc-tabela"><thead><tr>' +
        ['Nº', 'Cliente'].concat(VEICULO ? ['Placa'] : [], ['Itens', 'Total', 'Situação', 'Vendedor', 'Criado em']).map(function (c) { return '<th>' + c + '</th>'; }).join('') +
        '</tr></thead><tbody>' + r.data.map(function (o) {
          return '<tr data-id="' + o.id + '" tabindex="0"><td class="orc-mono">' + o.numero + '</td><td class="orc-forte">' + esc(o.cliente_nome) + '</td>' +
            (VEICULO ? '<td class="orc-mono">' + esc(o.placa || '—') + '</td>' : '') + '<td>' + o.itens + '</td><td class="orc-num orc-forte">' + dinheiro(o.total) + '</td>' +
            '<td><span class="orc-pill orc-pill-' + o.status + '">' + ROTULO[o.status] + '</span></td><td>' + esc(o.vendedor_nome || '—') + '</td>' +
            '<td class="orc-num">' + dataBr(o.created_at) + '</td></tr>';
        }).join('') + '</tbody></table></div>';
      alvo.querySelectorAll('tr[data-id]').forEach(function (tr) {
        var abrir = function () { navegar({ id: tr.dataset.id }); };
        tr.addEventListener('click', abrir);
        tr.addEventListener('keydown', function (e) { if (e.key === 'Enter') abrir(); });
      });
    });
  }

  /* ── Ficha ───────────────────────────────────────────────────────────── */
  function renderFicha(id) {
    raiz.innerHTML = '<p class="orc-vazio">Carregando orçamento…</p>';
    api('GET', '?id=' + encodeURIComponent(id)).then(function (r) {
      if (!r.ok) { raiz.innerHTML = '<p class="orc-erro">' + esc(r.error) + '</p>'; return; }
      var f = r.data, s = f.status;
      var botoes = '';
      botoes += '<a class="orc-btn orc-btn-sm" href="/orcamento_imprimir.php?id=' + f.id + '" target="_blank" rel="noopener">Imprimir / PDF</a>';
      if (f.cliente_telefone) {
        var fone = String(f.cliente_telefone).replace(/\D/g, '');
        if (fone.length === 10 || fone.length === 11) fone = '55' + fone;
        botoes += '<a class="orc-btn orc-btn-sm" href="https://wa.me/' + fone + '?text=' + encodeURIComponent(f.texto_whatsapp) + '" target="_blank" rel="noopener">Enviar no WhatsApp</a>';
      }
      if (s === 'RASCUNHO' || s === 'APROVADO') botoes += '<button type="button" class="orc-btn orc-btn-sm" data-acao="editar">Editar</button>';
      if (s === 'RASCUNHO' || s === 'APROVADO') botoes += '<button type="button" class="orc-btn orc-btn-sm orc-btn-perigo" data-acao="rejeitar">Rejeitar</button>';
      if (s === 'REJEITADO') botoes += '<button type="button" class="orc-btn orc-btn-sm orc-btn-principal" data-acao="reabrir">Reabrir para refazer</button>';
      if (s === 'RASCUNHO') botoes += '<button type="button" class="orc-btn orc-btn-sm orc-btn-principal" data-acao="aprovar">Aprovar</button>';
      if (s === 'APROVADO') botoes += '<button type="button" class="orc-btn orc-btn-sm orc-btn-principal" data-acao="converter">Fechar negócio</button>';
      if (f.card_id) botoes += '<a class="orc-btn orc-btn-sm" href="/prospeccao.php?open=' + f.card_id + '">Abrir lead</a>';

      var itens = f.itens.map(function (i) {
        return '<div class="orc-item"><div style="flex:1"><div class="orc-item-tit">' + esc(i.descricao) +
          (i.natureza === 'DEBITO' ? ' <span class="orc-pill orc-pill-debito">débito do veículo</span>' : '') + '</div>' +
          '<div class="orc-item-sub">' + paraTexto(i.quantidade) + ' × ' + dinheiro(i.valor_unitario) + (i.desconto > 0 ? ' − ' + dinheiro(i.desconto) + ' desconto' : '') + '</div></div>' +
          '<span class="orc-num orc-forte">' + dinheiro(i.subtotal) + '</span></div>';
      }).join('');

      var cliente = f.para_o_cliente.map(function (l) { return linha(l.descricao, l.valor); }).join('') + linha('Orçamento', f.total, true);
      var interno = f.interno;
      var controle = linha('Valor recebido', interno.valor_recebido) +
        interno.saidas.map(function (sd) {
          var det = sd.categoria === 'CUSTO' ? 'custo' : sd.debito ? 'débito do veículo, repasse imediato'
            : 'repasse ' + (sd.prazo === 'MENSAL' ? 'mensal' : 'imediato') + (sd.visivel_cliente ? '' : (VEICULO ? ', dentro do honorário do cliente' : ', dentro do valor do serviço'));
          return linha(sd.descricao, -sd.valor, false, det);
        }).join('') + linha(VEICULO ? 'Serviço / Honorário' : 'Valor líquido do serviço', interno.honorario, true, null, interno.honorario >= 0);

      raiz.innerHTML =
        '<button type="button" class="orc-voltar" data-voltar>← Voltar para Orçamentos</button>' +
        '<p class="orc-erro" hidden></p>' +
        '<div class="orc-cartao"><div class="orc-ficha-topo"><h3>Orçamento nº ' + f.numero + ' · ' + esc(f.cliente_nome) + '</h3>' +
          '<span class="orc-pill orc-pill-' + s + '">' + ROTULO[s] + '</span><span style="flex:1"></span>' + botoes + '</div>' +
          '<div class="orc-ficha-meta">' + (VEICULO ? '<span>Placa <b>' + esc(f.placa || '—') + '</b>' + (f.veiculo ? ' · ' + esc(f.veiculo) : '') + '</span>' : '') +
          '<span>Vendedor <b>' + esc(f.vendedor_nome || '—') + '</b></span><span>Canal <b>' + esc(f.canal || '—') + '</b></span>' +
          '<span>Criado em <b>' + dataBr(f.created_at) + '</b></span>' + (f.cliente_documento ? '<span>Documento <b>' + esc(f.cliente_documento) + '</b></span>' : '') + '</div>' +
          (f.observacoes ? '<p class="orc-item-sub" style="margin:10px 0 0">' + esc(f.observacoes) + '</p>' : '') +
          '<div class="orc-itens">' + itens + '</div>' +
          '<div class="orc-total"><span>Total</span><span>' + dinheiro(f.total) + '</span></div></div>' +
        '<div class="orc-duas"><div class="orc-cartao"><h4>Para o cliente</h4><p class="orc-sub">É assim que sai na impressão e no WhatsApp.</p>' + cliente + '</div>' +
          '<div class="orc-cartao"><h4>Controle do escritório</h4><p class="orc-sub">Não vai para o cliente.</p>' + controle +
          (interno.sem_taxas.length ? '<p class="orc-nota">' + esc(interno.sem_taxas.join(', ')) + ': sem taxas cadastradas, o valor inteiro conta como honorário. Cadastre em Serviços e taxas.</p>' : '') +
          '</div></div>';

      raiz.querySelector('[data-voltar]').addEventListener('click', function () { navegar({}); });
      raiz.querySelectorAll('[data-acao]').forEach(function (b) {
        b.addEventListener('click', function () {
          var acao = b.dataset.acao;
          if (acao === 'editar') { abrirFormulario(f); return; }
          if (acao === 'converter' && !window.confirm('Fechar o negócio? O valor fechado e a data vão para o lead, e o orçamento não muda mais.')) return;
          b.disabled = true;
          api('POST', '', { acao: acao, id: f.id }).then(function (res) {
            b.disabled = false;
            if (!res.ok) { mostrarErro(res.error); return; }
            if (acao === 'rejeitar') {
              history.pushState(null, '', location.pathname);
              estado.aba = 'ativos';
              renderLista('Orçamento nº ' + f.numero + ' rejeitado. Ele saiu da lista e fica na aba Rejeitado, de onde pode ser reaberto.');
              return;
            }
            renderFicha(f.id);
          });
        });
      });
    });
  }

  function linha(rotulo, valor, total, detalhe, bom) {
    return '<div class="orc-linha' + (total ? ' orc-linha-total' : '') + '"><span>' + esc(rotulo) + (detalhe ? '<small>' + esc(detalhe) + '</small>' : '') +
      '</span><span' + (bom ? ' class="orc-bom"' : '') + '>' + dinheiro(valor) + '</span></div>';
  }
  function mostrarErro(t) {
    var e = raiz.querySelector('.orc-erro');
    if (!e) { window.alert(t); return; }
    e.textContent = t; e.hidden = false; e.scrollIntoView({ block: 'nearest' });
  }

  /* ── Formulário ──────────────────────────────────────────────────────── */
  function abrirFormulario(ficha, leadInicial) {
    apoio().then(function (ap) {
      if (!ap) { window.alert('Não foi possível abrir o formulário.'); return; }
      montarFormulario(ap, ficha, leadInicial);
    });
  }

  function montarFormulario(ap, ficha, leadInicial) {
    var editando = !!ficha;
    var alvo = ficha
      ? { tipo: ficha.card_id ? 'lead' : (ficha.cliente_id ? 'cliente' : null), id: ficha.card_id || ficha.cliente_id || null }
      : (leadInicial ? { tipo: 'lead', id: leadInicial.id } : { tipo: null, id: null });
    var linhas = ficha && ficha.itens.length
      ? ficha.itens.map(function (i) {
          return { natureza: i.natureza, servico_id: i.servico_id || '', descricao: i.descricao, quantidade: paraTexto(i.quantidade) || '1', valor_unitario: paraTexto(i.valor_unitario), desconto: paraTexto(i.desconto) };
        })
      : [{ natureza: 'SERVICO', servico_id: '', descricao: '', quantidade: '1', valor_unitario: '', desconto: '' }];

    var ov = document.createElement('div');
    ov.className = 'orc-overlay';
    var base = ficha || {};
    var inicial = leadInicial || {};
    ov.innerHTML =
      '<div class="orc-painel" role="dialog" aria-modal="true" aria-labelledby="orcFormTit">' +
        '<div class="orc-painel-cab"><div><h3 id="orcFormTit">' + (editando ? 'Editar orçamento nº ' + ficha.numero : 'Novo orçamento') + '</h3>' +
          '<p>' + (editando ? 'Ao salvar, o orçamento volta para rascunho e precisa ser aprovado de novo.' : (VEICULO ? 'Nasce em rascunho. IPVA, multas e taxas entram como débito do veículo: repasse ao órgão, sem honorário.' : 'Nasce em rascunho e vai para o cliente depois de aprovado.')) + '</p></div>' +
          '<button type="button" class="orc-x" aria-label="Fechar" data-fechar>×</button></div>' +
        '<div class="orc-painel-corpo">' +
          '<p class="orc-erro" hidden></p>' +
          '<div class="orc-grade">' +
            '<label class="orc-campo"><span>Cliente (busque o lead ou o cliente)</span><input name="cliente_nome" autocomplete="off" maxlength="190" value="' + esc(base.cliente_nome || inicial.nome || '') + '" placeholder="Nome, CPF/CNPJ ou telefone"><div class="orc-sugestoes" hidden></div></label>' +
            '<label class="orc-campo"><span>CPF/CNPJ</span><input name="cliente_documento" maxlength="30" value="' + esc(base.cliente_documento || inicial.documento || '') + '"></label>' +
            '<label class="orc-campo"><span>WhatsApp do cliente</span><input name="cliente_telefone" maxlength="50" value="' + esc(base.cliente_telefone || inicial.telefone || '') + '"></label>' +
          '</div>' +
          '<div class="orc-dica" data-vinculo></div>' +
          '<div class="orc-grade">' +
            (VEICULO ? '<label class="orc-campo"><span>Placa</span><input name="placa" maxlength="10" value="' + esc(base.placa || '') + '" placeholder="AAA0A00"></label>' +
            '<label class="orc-campo"><span>Veículo (opcional)</span><input name="veiculo" maxlength="120" value="' + esc(base.veiculo || '') + '" placeholder="Marca e modelo"></label>' : '') +
            '<label class="orc-campo"><span>Vendedor</span><select name="vendedor_id"><option value="">Sem vendedor</option>' +
              ap.equipe.map(function (u) {
                var sel = base.vendedor_id ? base.vendedor_id === u.id : (!editando && u.id === ap.eu);
                return '<option value="' + u.id + '"' + (sel ? ' selected' : '') + '>' + esc(u.nome) + '</option>';
              }).join('') + '</select></label>' +
            '<label class="orc-campo"><span>Canal (opcional)</span><input name="canal" maxlength="60" value="' + esc(base.canal || '') + '" placeholder="Indicação, WhatsApp…"></label>' +
          '</div>' +
          '<div><div class="orc-campo"><span>' + (VEICULO ? 'Serviços e débitos do veículo' : 'Serviços') + '</span></div><div class="orc-linhas-form" data-linhas></div>' +
            '<div class="orc-acrescentar" style="margin-top:8px"><button type="button" data-mais="SERVICO">+ Adicionar serviço</button>' + (VEICULO ? '<button type="button" data-mais="DEBITO">+ Adicionar débito do veículo</button>' : '') + '</div>' +
            '<div class="orc-total"><span data-total-rotulo>Total</span><span data-total>R$ 0,00</span></div></div>' +
          '<label class="orc-campo"><span>Observações (opcional, saem na impressão)</span><textarea name="observacoes" maxlength="4000">' + esc(base.observacoes || '') + '</textarea></label>' +
        '</div>' +
        '<div class="orc-painel-pe"><button type="button" class="orc-btn" data-fechar>Cancelar</button>' +
          '<button type="button" class="orc-btn orc-btn-principal" data-salvar>' + (editando ? 'Salvar alterações' : 'Criar orçamento') + '</button></div>' +
      '</div>';
    document.body.appendChild(ov);

    function fechar() { document.removeEventListener('keydown', tecla, true); ov.remove(); }
    function tecla(e) { if (e.key === 'Escape') { e.stopPropagation(); fechar(); } }
    document.addEventListener('keydown', tecla, true);
    ov.querySelectorAll('[data-fechar]').forEach(function (b) { b.addEventListener('click', fechar); });

    var campo = function (n) { return ov.querySelector('[name="' + n + '"]'); };
    var vinculo = ov.querySelector('[data-vinculo]');
    function mostrarVinculo() {
      vinculo.textContent = alvo.id ? (alvo.tipo === 'lead' ? 'Ligado ao lead: aprovar grava o valor da proposta no card, e fechar o negócio grava o valor fechado.' : 'Ligado ao cliente cadastrado.') : 'Sem lead ligado: o orçamento fica só aqui.';
    }
    mostrarVinculo();

    // Busca de lead e cliente.
    var nome = campo('cliente_nome'), caixa = ov.querySelector('.orc-sugestoes'), espera = null;
    nome.addEventListener('input', function () {
      alvo = { tipo: null, id: null }; mostrarVinculo();
      clearTimeout(espera);
      var q = nome.value.trim();
      if (q.length < 2) { caixa.hidden = true; return; }
      espera = setTimeout(function () {
        api('GET', '?buscar=' + encodeURIComponent(q)).then(function (r) {
          if (!r.ok || !r.data.length) { caixa.hidden = true; return; }
          caixa.innerHTML = r.data.map(function (s, i) {
            return '<button type="button" data-i="' + i + '">' + esc(s.nome) + '<small>' + (s.tipo === 'lead' ? 'Lead' : 'Cliente') +
              [s.documento, s.telefone].filter(Boolean).map(function (x) { return ' · ' + esc(x); }).join('') + '</small></button>';
          }).join('');
          caixa.hidden = false;
          caixa.querySelectorAll('button').forEach(function (b) {
            b.addEventListener('click', function () {
              var s = r.data[Number(b.dataset.i)];
              alvo = { tipo: s.tipo, id: s.id };
              nome.value = s.nome;
              if (s.documento) campo('cliente_documento').value = s.documento;
              if (s.telefone) campo('cliente_telefone').value = s.telefone;
              caixa.hidden = true; mostrarVinculo();
            });
          });
        });
      }, 250);
    });
    nome.addEventListener('blur', function () { setTimeout(function () { caixa.hidden = true; }, 200); });

    // Linhas.
    var lista = ov.querySelector('[data-linhas]');
    function opcoes(l) {
      var valor = l.natureza === 'DEBITO' ? 'debito:' + (ap.debitos.indexOf(l.descricao) >= 0 ? l.descricao : 'Taxas diversas') : String(l.servico_id || '');
      var html = '<option value="">Serviço livre</option><optgroup label="Serviços">' +
        ap.servicos.map(function (s) { return '<option value="' + s.id + '">' + esc(s.nome) + '</option>'; }).join('') +
        '</optgroup>' + (VEICULO || l.natureza === 'DEBITO' ? '<optgroup label="Débitos do veículo (repasse)">' +
        ap.debitos.map(function (d) { return '<option value="debito:' + esc(d) + '">' + esc(d) + '</option>'; }).join('') + '</optgroup>' : '');
      return { html: html, valor: valor };
    }
    function subtotal(l) { return numero(l.quantidade || '1') * numero(l.valor_unitario) - numero(l.desconto); }
    function desenharLinhas() {
      lista.innerHTML = linhas.map(function (l, i) {
        var o = opcoes(l);
        return '<div><div class="orc-linha-form" data-i="' + i + '">' +
          '<select data-c="tipo" aria-label="Tipo do item">' + o.html + '</select>' +
          '<input data-c="descricao" maxlength="200" placeholder="Descrição" value="' + esc(l.descricao) + '">' +
          '<input data-c="quantidade" placeholder="Qtd" inputmode="decimal" value="' + esc(l.quantidade) + '">' +
          '<input data-c="valor_unitario" placeholder="Valor un." inputmode="decimal" value="' + esc(l.valor_unitario) + '">' +
          '<input data-c="desconto" placeholder="Desconto" inputmode="decimal" value="' + esc(l.desconto) + '">' +
          '<span class="orc-sub-total" data-sub>' + dinheiro(subtotal(l)) + '</span>' +
          '<button type="button" class="orc-tirar" data-tirar aria-label="Remover linha"' + (linhas.length === 1 ? ' disabled' : '') + '>×</button></div>' +
          (l.natureza === 'DEBITO' ? '<div class="orc-dica">Débito do veículo: repasse integral ao órgão, não conta como honorário.</div>' : '') + '</div>';
      }).join('');
      lista.querySelectorAll('.orc-linha-form').forEach(function (row) {
        var i = Number(row.dataset.i), l = linhas[i];
        row.querySelector('[data-c="tipo"]').value = opcoes(l).valor;
        row.querySelector('[data-c="tipo"]').addEventListener('change', function (e) {
          var v = e.target.value;
          if (v.indexOf('debito:') === 0) {
            linhas[i] = Object.assign(l, { natureza: 'DEBITO', servico_id: '', descricao: v.slice(7), quantidade: '1' });
          } else {
            var s = ap.servicos.find(function (x) { return String(x.id) === v; });
            linhas[i] = Object.assign(l, { natureza: 'SERVICO', servico_id: v, descricao: s ? s.nome : '', valor_unitario: s && s.valor_padrao != null ? paraTexto(s.valor_padrao) : l.valor_unitario });
          }
          desenharLinhas();
        });
        ['descricao', 'quantidade', 'valor_unitario', 'desconto'].forEach(function (c) {
          row.querySelector('[data-c="' + c + '"]').addEventListener('input', function (e) {
            l[c] = c === 'descricao' ? e.target.value : e.target.value.replace(/[^\d.,]/g, '');
            if (c !== 'descricao') e.target.value = l[c];
            row.querySelector('[data-sub]').textContent = dinheiro(subtotal(l));
            somar();
          });
        });
        row.querySelector('[data-tirar]').addEventListener('click', function () { linhas.splice(i, 1); desenharLinhas(); });
      });
      somar();
    }
    function somar() {
      var total = 0, debitos = 0;
      linhas.forEach(function (l) { var s = subtotal(l); total += s; if (l.natureza === 'DEBITO') debitos += s; });
      ov.querySelector('[data-total]').textContent = dinheiro(total);
      ov.querySelector('[data-total-rotulo]').textContent = debitos > 0 ? 'Total, dos quais ' + dinheiro(debitos) + ' em débitos do veículo' : 'Total';
    }
    ov.querySelectorAll('[data-mais]').forEach(function (b) {
      b.addEventListener('click', function () {
        linhas.push(b.dataset.mais === 'DEBITO'
          ? { natureza: 'DEBITO', servico_id: '', descricao: 'IPVA', quantidade: '1', valor_unitario: '', desconto: '' }
          : { natureza: 'SERVICO', servico_id: '', descricao: '', quantidade: '1', valor_unitario: '', desconto: '' });
        desenharLinhas();
      });
    });
    desenharLinhas();

    var erro = ov.querySelector('.orc-erro'), salvar = ov.querySelector('[data-salvar]');
    salvar.addEventListener('click', function () {
      erro.hidden = true;
      var itens = linhas.filter(function (l) { return l.descricao.trim() || l.valor_unitario.trim(); }).map(function (l) {
        return { natureza: l.natureza, servico_id: l.natureza === 'SERVICO' && l.servico_id ? Number(l.servico_id) : null, descricao: l.descricao.trim(),
                 quantidade: numero(l.quantidade || '1'), valor_unitario: numero(l.valor_unitario), desconto: numero(l.desconto || '0') };
      });
      var corpo = {
        acao: editando ? 'atualizar' : 'criar', id: editando ? ficha.id : undefined,
        card_id: alvo.tipo === 'lead' ? alvo.id : null, cliente_id: alvo.tipo === 'cliente' ? alvo.id : null,
        cliente_nome: nome.value.trim(), cliente_documento: campo('cliente_documento').value.trim(), cliente_telefone: campo('cliente_telefone').value.trim(),
        placa: campo('placa').value.trim(), veiculo: campo('veiculo').value.trim(), vendedor_id: Number(campo('vendedor_id').value) || null,
        canal: campo('canal').value.trim(), observacoes: campo('observacoes').value.trim(), itens: itens
      };
      salvar.disabled = true;
      api('POST', '', corpo).then(function (r) {
        salvar.disabled = false;
        if (!r.ok) { erro.textContent = r.error || 'Não foi possível salvar.'; erro.hidden = false; erro.scrollIntoView({ block: 'nearest' }); return; }
        fechar();
        navegar({ id: r.data.id });
      });
    });
    setTimeout(function () { nome.focus(); }, 30);
  }

  /* ── Serviços e taxas ────────────────────────────────────────────────── */
  function renderServicos(alvo) {
    api('GET', '?servicos=1').then(function (r) {
      if (!r.ok) { alvo.innerHTML = '<p class="orc-erro">' + esc(r.error) + '</p>'; return; }
      desenharServicos(alvo, r.data);
    });
  }

  function desenharServicos(alvo, servicos) {
    var podeEditar = !estado.apoio || estado.apoio.pode_editar_catalogo;
    alvo.innerHTML = '<p class="orc-aviso">Cada serviço tem o preço de tabela e as taxas. ' + (VEICULO
        ? '<b>Repasse imediato</b> sai junto com o processo (Detran); <b>repasse mensal</b> sai numa fatura do mês (SERPRO). <b>Cliente vê</b> ligado mostra a taxa em linha própria no orçamento; desligado, ela fica dentro do honorário que o cliente lê.'
        : '<b>Repasse imediato</b> sai junto com a entrega; <b>repasse mensal</b> sai numa fatura do mês. <b>Cliente vê</b> ligado mostra a taxa em linha própria no orçamento; desligado, ela fica dentro do valor do serviço.') +
      (podeEditar ? '' : ' Só o dono ou um administrador altera estes valores.') + '</p><p class="orc-erro" hidden></p>' +
      '<div class="orc-servicos">' + servicos.map(function (s) {
        return '<div class="orc-cartao" data-servico="' + s.id + '"><div class="orc-servico-cab">' +
          '<input data-c="nome" maxlength="120" value="' + esc(s.nome) + '" aria-label="Nome do serviço">' +
          '<input data-c="valor_padrao" inputmode="decimal" placeholder="Preço de tabela" value="' + esc(s.valor_padrao == null ? '' : paraTexto(s.valor_padrao)) + '" aria-label="Preço de tabela">' +
          '<label style="display:inline-flex;gap:6px;align-items:center;font-size:12.5px;white-space:nowrap"><input type="checkbox" data-c="ativo"' + (s.ativo ? ' checked' : '') + '> Ativo</label>' +
          '<button type="button" class="orc-btn orc-btn-sm" data-salvar-servico>Salvar</button></div>' +
          '<div class="orc-taxas">' + s.componentes.map(function (c) {
            return '<div class="orc-taxa" data-taxa="' + c.id + '"><span class="orc-pill' + (c.categoria === 'CUSTO' ? '' : ' orc-pill-APROVADO') + '">' + (c.categoria === 'CUSTO' ? 'Custo' : 'Repasse') + '</span>' +
              '<span class="orc-taxa-desc">' + esc(c.descricao) + '</span>' +
              (c.categoria === 'REPASSE'
                ? '<select data-prazo aria-label="Quando o repasse sai"><option value="IMEDIATO"' + (c.prazo === 'IMEDIATO' ? ' selected' : '') + '>Repasse imediato</option><option value="MENSAL"' + (c.prazo === 'MENSAL' ? ' selected' : '') + '>Repasse mensal</option></select>' +
                  '<label title="Ligado: a taxa sai em linha própria no orçamento do cliente."><input type="checkbox" data-visivel' + (c.visivel_cliente ? ' checked' : '') + '> Cliente vê</label>'
                : '') +
              '<span class="orc-num orc-forte">' + dinheiro(c.valor) + '</span>' +
              '<button type="button" class="orc-btn orc-btn-sm orc-btn-leve" data-tirar-taxa aria-label="Remover taxa">×</button></div>';
          }).join('') + '</div>' +
          '<div class="orc-taxa-nova"><select data-n="categoria"><option value="REPASSE">Repasse</option><option value="CUSTO">Custo</option></select>' +
            '<input data-n="descricao" maxlength="120" placeholder="' + (VEICULO ? 'Taxa (ex.: Detran, SERPRO, placa)' : 'Taxa (ex.: imposto, plataforma, licença)') + '">' +
            '<input data-n="valor" inputmode="decimal" placeholder="Valor">' +
            '<select data-n="prazo"><option value="IMEDIATO">Repasse imediato</option><option value="MENSAL">Repasse mensal</option></select>' +
            '<label style="display:inline-flex;gap:5px;align-items:center;font-size:12px;white-space:nowrap"><input type="checkbox" data-n="visivel" checked> Cliente vê</label>' +
            '<button type="button" class="orc-btn orc-btn-sm" data-add-taxa>Adicionar taxa</button></div>' +
          (s.para_o_cliente.length
            ? '<div class="orc-cliente-ve"><b>O cliente vê:</b> ' + s.para_o_cliente.map(function (l) { return esc(l.descricao) + ' ' + dinheiro(l.valor); }).join(' · ') +
              ' &nbsp;·&nbsp; <b>fica para o escritório:</b> ' + dinheiro(s.honorario) + '</div>'
            : '<div class="orc-cliente-ve">Sem preço de tabela: o valor é digitado em cada orçamento.</div>') +
          '</div>';
      }).join('') + '</div>' +
      '<div class="orc-cartao" style="margin-top:12px"><div class="orc-servico-cab"><input data-novo-nome maxlength="120" placeholder="Novo serviço"><input data-novo-valor inputmode="decimal" placeholder="Preço de tabela"><span></span>' +
      '<button type="button" class="orc-btn orc-btn-sm orc-btn-principal" data-novo-servico>Criar serviço</button></div></div>';

    var erro = alvo.querySelector('.orc-erro');
    function enviar(corpo) {
      erro.hidden = true;
      return api('POST', '', corpo).then(function (r) {
        if (!r.ok) { erro.textContent = r.error; erro.hidden = false; erro.scrollIntoView({ block: 'nearest' }); return; }
        estado.apoio = null; // o formulário relê o catálogo
        desenharServicos(alvo, r.data);
      });
    }
    alvo.querySelectorAll('[data-servico]').forEach(function (card) {
      var id = Number(card.dataset.servico);
      var c = function (n) { return card.querySelector('[data-c="' + n + '"]'); };
      card.querySelector('[data-salvar-servico]').addEventListener('click', function () {
        enviar({ acao: 'servico_salvar', id: id, nome: c('nome').value, valor_padrao: c('valor_padrao').value.trim(), ativo: c('ativo').checked });
      });
      card.querySelectorAll('[data-taxa]').forEach(function (t) {
        var taxa = Number(t.dataset.taxa);
        var prazo = t.querySelector('[data-prazo]'), vis = t.querySelector('[data-visivel]');
        if (prazo) prazo.addEventListener('change', function () { enviar({ acao: 'taxa_ajustar', taxa_id: taxa, prazo: prazo.value }); });
        if (vis) vis.addEventListener('change', function () { enviar({ acao: 'taxa_ajustar', taxa_id: taxa, visivel_cliente: vis.checked }); });
        t.querySelector('[data-tirar-taxa]').addEventListener('click', function () {
          if (window.confirm('Remover esta taxa do serviço?')) enviar({ acao: 'taxa_remover', taxa_id: taxa });
        });
      });
      var n = function (k) { return card.querySelector('[data-n="' + k + '"]'); };
      card.querySelector('[data-add-taxa]').addEventListener('click', function () {
        enviar({ acao: 'taxa_adicionar', servico_id: id, categoria: n('categoria').value, descricao: n('descricao').value, valor: n('valor').value,
                 prazo: n('prazo').value, visivel_cliente: n('visivel').checked });
      });
    });
    alvo.querySelector('[data-novo-servico]').addEventListener('click', function () {
      enviar({ acao: 'servico_salvar', nome: alvo.querySelector('[data-novo-nome]').value, valor_padrao: alvo.querySelector('[data-novo-valor]').value.trim() });
    });
  }

  /* ── Rotas ───────────────────────────────────────────────────────────── */
  function rotear() {
    var p = new URLSearchParams(location.search);
    if (p.get('id')) { renderFicha(p.get('id')); return; }
    renderLista();
    if (p.get('novo')) {
      var card = p.get('card');
      history.replaceState(null, '', location.pathname);
      if (card) {
        api('GET', '?lead=' + encodeURIComponent(card)).then(function (r) { abrirFormulario(null, r.ok ? r.data : null); });
      } else {
        abrirFormulario(null, null);
      }
    }
  }

  document.getElementById('orcNovo').addEventListener('click', function () { abrirFormulario(null, null); });
  apoio();
  rotear();
})();
