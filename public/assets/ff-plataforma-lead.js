/* ═══════════════════════════════════════════════════════════════════════
   ff-plataforma-lead.js: a seção "Conta na plataforma" da ficha do lead
   (Prospecção, edição CRM). Liga o lead à conta da plataforma pelo código
   FLT-XXXX-XXXX e mostra o que a conta tem e gera: plano, módulos, uso,
   dinheiro, pedidos e o que dá para oferecer. Tudo por /api/plataforma.php,
   que fala com a API da plataforma. Carregado pelo sidebar.php.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var REAL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  var SITUACAO = {
    ativa: ['Ativa', ' ffag-pill-verde'], teste: ['Em teste', ' ffag-pill-azul'], inadimplente: ['Inadimplente', ' ffag-pill-vermelho'],
    suspensa: ['Suspensa', ' ffag-pill-vermelho'], cancelada: ['Cancelada', ' ffag-pill-vermelho']
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function real(v) { return v == null ? '—' : REAL.format(v); }
  function csrf() { return (window.FF_AGENDA && window.FF_AGENDA.csrf) || ''; }
  function dataBr(iso) {
    if (!iso) return '—';
    var d = new Date(String(iso).replace(' ', 'T') + (/[zZ+]/.test(iso) ? '' : 'Z'));
    return isNaN(d) ? iso : d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
  }
  function situacaoDe(r) {
    if (!r.conta.ativa) return 'suspensa';
    var s = r.assinatura && r.assinatura.status;
    return { TRIAL: 'teste', INADIMPLENTE: 'inadimplente', SUSPENSA: 'suspensa', CANCELADA: 'cancelada' }[s] || 'ativa';
  }
  function uso(u) { return u.usados + (u.limite ? ' de ' + u.limite : ''); }

  function api(params) {
    return fetch('/api/plataforma.php?' + params, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); });
  }
  function postar(corpo) {
    return fetch('/api/plataforma.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify(corpo)
    }).then(function (r) { return r.json(); });
  }

  function montar() {
    var F = window.FfFicha, shell = document.getElementById('modalEdit'), form = document.getElementById('editForm');
    if (!F || !shell || !form || !form.elements.id) return;

    var sec = document.createElement('div');
    sec.className = 'form-section ffag-sec';
    sec.id = 'ffPlataformaSection';
    sec.innerHTML = '<div class="ffpl"></div>';
    var antes = document.getElementById('ffOrcamentoSection') || document.getElementById('ffAgendaSection');
    var dir = form.querySelector('.ff-col-dir');
    if (antes && antes.parentNode) antes.parentNode.insertBefore(sec, antes.nextSibling);
    else if (dir) dir.insertBefore(sec, dir.firstChild);
    else return;
    F.secao(sec, 0, 'Conta na plataforma', 'predio', '');
    F.emAbas(sec, ['geral', 'comercial']);

    var caixa = sec.querySelector('.ffpl');
    var atual = null;   // o último retorno de ?card_id=
    var planos = null;  // catálogo, carregado uma vez
    var ultimo = '';

    function cardId() { return form.elements.id.value; }

    function erro(msg) {
      var e = caixa.querySelector('[data-erro]');
      if (e) { e.textContent = msg; e.hidden = !msg; }
      else if (msg) window.alert(msg);
    }

    function acao(corpo, ok) {
      corpo.card_id = cardId();
      return postar(corpo).then(function (r) {
        if (!r.ok) { erro(r.error || 'Não foi possível concluir.'); return; }
        if (ok) ok(r.data);
        carregar(true);
      }).catch(function () { erro('Sem conexão.'); });
    }

    var esperas = 0;
    function carregar(forcar) {
      var id = cardId();
      if (!id) {
        // A ficha abre antes de preencher o formulário (?open= busca o lead
        // depois), e preencher não muda a classe do modal: espera o id chegar.
        caixa.innerHTML = '';
        if (esperas++ < 20) setTimeout(function () { carregar(forcar); }, 300);
        return;
      }
      esperas = 0;
      // aoAbrir dispara a cada mutação do modal: só busca de novo ao trocar de lead.
      if (!forcar && id === ultimo && atual) return;
      ultimo = id;
      caixa.innerHTML = '<div class="ffag-vazio">Carregando…</div>';
      api('card_id=' + encodeURIComponent(id) + (forcar === 'api' ? '&atualizar=1' : '')).then(function (r) {
        if (cardId() !== id) return;
        if (!r.ok) { caixa.innerHTML = '<div class="ffag-vazio">' + esc(r.error || 'Não foi possível carregar.') + '</div>'; return; }
        atual = r.data;
        desenhar();
      }).catch(function () { caixa.innerHTML = '<div class="ffag-vazio">Sem conexão.</div>'; });
    }

    function desenhar() {
      var d = atual;
      if (!d.ligada) {
        caixa.innerHTML = '<div class="ffag-vazio">O CRM ainda não está ligado à plataforma.' +
          (d.pode_configurar ? ' <a href="/plataforma.php">Ligar agora</a>' : ' Peça a um administrador.') + '</div>';
        return;
      }
      if (!d.vinculo) { desenharSemVinculo(d); return; }
      desenharConta(d);
    }

    function desenharSemVinculo(d) {
      var h = '<div class="ffag-vazio" style="text-align:left">Ligue este lead à conta dele na plataforma para ver plano, módulos, uso e o que ele gera. ' +
        'O código (FLT-XXXX-XXXX) aparece na tela "Conta" da plataforma.</div>' +
        '<div class="ffpl-linha"><input data-codigo placeholder="FLT-XXXX-XXXX" maxlength="20" autocomplete="off">' +
        '<button type="button" class="ffag-btn ffag-btn-principal" data-ligar>Ligar</button></div>' +
        '<div class="ffpl-erro" data-erro hidden></div>';
      if (d.sugestoes && d.sugestoes.length) {
        h += '<div class="ffpl-rotulo">Pode ser uma destas</div>' + d.sugestoes.map(function (c) {
          return '<div class="ffpl-sugestao"><div><b>' + esc(c.nome) + '</b> <span class="ffpl-codigo">' + esc(c.codigo) + '</span>' +
            (c.cidade ? '<div style="opacity:.7;font-size:11.5px">' + esc(c.cidade) + (c.uf ? '/' + esc(c.uf) : '') + '</div>' : '') + '</div>' +
            '<button type="button" class="ffag-btn ffag-btn-leve" data-sugestao="' + esc(c.codigo) + '">Ligar</button></div>';
        }).join('');
      }
      caixa.innerHTML = h;
      var campo = caixa.querySelector('[data-codigo]');
      caixa.querySelector('[data-ligar]').addEventListener('click', function () {
        acao({ acao: 'vincular', codigo: campo.value });
      });
      campo.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); acao({ acao: 'vincular', codigo: campo.value }); } });
      Array.prototype.forEach.call(caixa.querySelectorAll('[data-sugestao]'), function (b) {
        b.addEventListener('click', function () { acao({ acao: 'vincular', codigo: b.getAttribute('data-sugestao') }); });
      });
    }

    function desenharConta(d) {
      var r = d.resumo;
      if (!r) {
        caixa.innerHTML = '<div class="ffpl-erro">' + esc(d.erro || 'A plataforma não respondeu.') + '</div>';
        return;
      }
      var sit = SITUACAO[situacaoDe(r)];
      var plano = (r.plano.comercial && r.plano.comercial.nome) || (r.plano.cobranca && r.plano.cobranca.nome) || 'Sem plano';
      var f = r.financeiro, u = r.uso, m = r.movimentoDaConta;
      var abertos = (r.solicitacoes || []).filter(function (s) { return s.status === 'ABERTA'; });

      var h = '';
      if (d.de_cache) h += '<div class="ffpl-aviso">A plataforma não respondeu agora (' + esc(d.erro || '') + '). Dados de ' + dataBr(d.atualizado_em) + '.</div>';
      h += '<div class="ffpl-erro" data-erro hidden></div>';
      h += '<div class="ffpl-topo"><span class="ffpl-nome">' + esc(r.conta.nome) + '</span><span class="ffpl-codigo">' + esc(r.conta.codigo) + '</span>' +
        '<span class="ffag-pill' + sit[1] + '">' + sit[0] + '</span><span class="ffag-pill">' + esc(plano) + '</span></div>';

      h += '<div class="ffpl-grade">' +
        num('Mensalidade', r.plano.cobranca ? real(r.plano.cobranca.valorMensal) : '—') +
        num('Saldo', real(f.saldo)) +
        num('Gerou no mês', real(f.geradoNoMes)) +
        num('Gerou no total', real(f.geradoTotal)) + '</div>';
      if (f.mensalidadesEmAtraso.quantidade) {
        h += '<div class="ffpl-erro">' + f.mensalidadesEmAtraso.quantidade + ' mensalidade(s) em atraso: ' + real(f.mensalidadesEmAtraso.valor) + '.</div>';
      }

      if (abertos.length) {
        h += '<div class="ffpl-rotulo">Pedidos da conta</div>' + abertos.map(pedido).join('');
      }

      h += '<div class="ffpl-rotulo">Módulos</div><div class="ffpl-chips">' + r.modulos.map(function (mo) {
        return '<span class="ffpl-chip" data-ativo="' + (mo.ativo ? 1 : 0) + '">' + esc(mo.nome) +
          (d.pode_mudar ? '<button type="button" data-modulo="' + esc(mo.modulo) + '" data-ligar-mod="' + (mo.ativo ? 0 : 1) + '">' + (mo.ativo ? 'Desligar' : 'Liberar') + '</button>'
            : '<span style="opacity:.6;font-size:11px;padding-right:6px">' + (mo.ativo ? 'ligado' : 'não contratado') + '</span>') + '</span>';
      }).join('') + '</div>';

      h += '<div class="ffpl-rotulo">Uso</div><div class="ffpl-grade">' +
        num('Usuários', uso(u.usuarios)) + num('Consultas no mês', uso(u.consultasNoMes)) +
        num('Processos abertos', u.processosAbertos) + num('Veículos', u.veiculos) + '</div>';

      var cq = r.consultas;
      if (cq) {
        h += '<div class="ffpl-rotulo">Consultas</div><div class="ffpl-grade">' +
          num('No total', cq.total.toLocaleString('pt-BR')) + num('Neste mês', cq.noMes.toLocaleString('pt-BR')) +
          num('Média por mês', cq.mediaMensal.toLocaleString('pt-BR')) + num('Pela API', cq.pelaApi.toLocaleString('pt-BR')) +
          num('Gasto com consultas', real(cq.cobrado.valor)) + num('Sem cobrança', cq.semCobranca.toLocaleString('pt-BR')) + '</div>';
        if (cq.porMes && cq.porMes.length) {
          var maior = Math.max.apply(null, cq.porMes.map(function (x) { return x.quantidade; })) || 1;
          h += '<div class="ffpl-meses">' + cq.porMes.map(function (x) {
            var p = x.mes.split('-');
            return '<div class="ffpl-mes"><span>' + p[1] + '/' + p[0].slice(2) + '</span><i style="width:' + Math.max(4, Math.round(x.quantidade / maior * 100)) + '%"></i><b>' + x.quantidade + '</b></div>';
          }).join('') + '</div>';
        }
      }

      h += '<div class="ffpl-rotulo">Movimento da conta no mês</div><div class="ffpl-grade">' +
        num('Recebeu', real(m.honorariosRecebidosNoMes)) + num('A receber', real(m.honorariosEmAberto)) +
        num('Consumo de saldo', real(f.consumoNoMes.valor)) + num('Processos novos', u.processosNoMes) + '</div>';
      if (!m.usaFinanceiro) h += '<div style="opacity:.7;font-size:11.5px">A conta não usa o módulo Financeiro: recebimentos podem estar fora do sistema.</div>';

      if (r.oportunidades && r.oportunidades.length) {
        h += '<div class="ffpl-rotulo">O que oferecer</div><ul class="ffpl-oport">' + r.oportunidades.map(function (o) {
          return '<li><b>' + esc(o.titulo) + '.</b> ' + esc(o.detalhe) + '</li>';
        }).join('') + '</ul>';
      }

      if (d.pode_mudar) {
        h += '<div class="ffpl-rotulo">Trocar plano</div><div class="ffpl-linha"><select data-plano><option value="">Carregando planos…</option></select>' +
          '<button type="button" class="ffag-btn ffag-btn-leve" data-aplicar-plano>Aplicar</button></div>';
      }

      if (d.eventos && d.eventos.length) {
        h += '<div class="ffpl-rotulo">Últimos avisos da plataforma</div>' + d.eventos.slice(0, 5).map(function (e) {
          return '<div class="ffpl-evento"><b>' + esc(e.assunto) + '</b> <span>' + dataBr(e.recebido_em) + '</span><div>' + esc(e.texto) + '</div></div>';
        }).join('');
      }

      h += '<div data-movimentos class="ffpl-mais" hidden></div>';
      h += '<div class="ffag-sec-pe"><a href="#" data-ver-mov>Ver movimentos</a><a href="#" data-atualizar>Atualizar</a><a href="#" data-desligar>Desligar</a></div>';
      caixa.innerHTML = h;
      ligarEventos(r);
    }

    function num(rotulo, valor) {
      return '<div class="ffpl-num"><span>' + esc(rotulo) + '</span><b>' + esc(valor) + '</b></div>';
    }

    function pedido(s) {
      var assunto = s.tipo === 'MODULO' ? 'Quer o módulo ' + (s.moduloNome || s.modulo) : s.tipo === 'PLANO' ? 'Quer mudar para ' + (s.planoNome || 'outro plano') : 'Quer falar com o comercial';
      var acaoPrincipal = s.tipo === 'MODULO' ? 'Liberar e atender' : s.tipo === 'PLANO' ? 'Trocar plano e atender' : 'Marcar como atendido';
      return '<div class="ffpl-pedido"><div><b>' + esc(assunto) + '</b> <span style="opacity:.7">· ' + esc(s.solicitadoPorNome) + ', ' + dataBr(s.criadoEm) + '</span>' +
        (s.mensagem ? '<div>' + esc(s.mensagem) + '</div>' : '') + '</div>' +
        (atual.pode_mudar ? '<div class="ffpl-linha"><button type="button" class="ffag-btn ffag-btn-principal" data-atender="' + esc(s.id) + '">' + acaoPrincipal + '</button>' +
          '<button type="button" class="ffag-btn ffag-btn-leve" data-recusar="' + esc(s.id) + '">Recusar</button></div>' : '') + '</div>';
    }

    function ligarEventos(r) {
      Array.prototype.forEach.call(caixa.querySelectorAll('[data-modulo]'), function (b) {
        b.addEventListener('click', function () {
          var ligar = b.getAttribute('data-ligar-mod') === '1';
          var nome = b.parentNode.firstChild.textContent;
          if (!window.confirm((ligar ? 'Liberar' : 'Desligar') + ' o módulo ' + nome + ' na conta do cliente?' + (ligar ? ' A cobrança é com o Financeiro da Fleetiflow.' : ''))) return;
          acao({ acao: 'modulo', modulo: b.getAttribute('data-modulo'), ativo: ligar });
        });
      });

      var porId = {};
      (r.solicitacoes || []).forEach(function (s) { porId[s.id] = s; });
      Array.prototype.forEach.call(caixa.querySelectorAll('[data-atender]'), function (b) {
        b.addEventListener('click', function () { atender(porId[b.getAttribute('data-atender')]); });
      });
      Array.prototype.forEach.call(caixa.querySelectorAll('[data-recusar]'), function (b) {
        b.addEventListener('click', function () {
          var resposta = window.prompt('Resposta para o cliente (aparece na tela dele):', '');
          if (resposta === null) return;
          acao({ acao: 'responder', solicitacao_id: b.getAttribute('data-recusar'), status: 'RECUSADA', resposta: resposta });
        });
      });

      var sel = caixa.querySelector('[data-plano]');
      if (sel) {
        carregarPlanos().then(function (p) {
          var atualId = r.plano.comercial && r.plano.comercial.id;
          sel.innerHTML = '<option value="">Escolha o plano</option>' + p.planos.map(function (pl) {
            return '<option value="' + esc(pl.id) + '"' + (pl.id === atualId ? ' disabled' : '') + '>' + esc(pl.nome) + ' · ' + real(pl.valorReferencia) + '/mês' + (pl.id === atualId ? ' (atual)' : '') + '</option>';
          }).join('');
        }).catch(function () { sel.innerHTML = '<option value="">Sem planos</option>'; });
        caixa.querySelector('[data-aplicar-plano]').addEventListener('click', function () {
          if (!sel.value) return;
          var nome = sel.options[sel.selectedIndex].text;
          if (!window.confirm('Aplicar ' + nome + ' na conta do cliente? Os limites e os módulos do plano entram na hora, e a mensalidade passa a ser a do plano.')) return;
          acao({ acao: 'plano', plano_id: sel.value });
        });
      }

      caixa.querySelector('[data-atualizar]').addEventListener('click', function (e) { e.preventDefault(); carregar('api'); });
      caixa.querySelector('[data-desligar]').addEventListener('click', function (e) {
        e.preventDefault();
        if (window.confirm('Desligar este lead da conta ' + r.conta.codigo + '? A conta deixa de ver você como consultor.')) acao({ acao: 'desvincular' });
      });
      caixa.querySelector('[data-ver-mov]').addEventListener('click', function (e) {
        e.preventDefault();
        var alvo = caixa.querySelector('[data-movimentos]');
        if (!alvo.hidden) { alvo.hidden = true; return; }
        alvo.hidden = false;
        alvo.innerHTML = '<div class="ffag-vazio">Carregando…</div>';
        api('card_id=' + encodeURIComponent(cardId()) + '&financeiro=1').then(function (x) {
          if (!x.ok) { alvo.innerHTML = '<div class="ffpl-erro">' + esc(x.error) + '</div>'; return; }
          alvo.innerHTML = movimentos(x.data);
        });
      });
    }

    function atender(s) {
      if (!s) return;
      var resposta = window.prompt('Resposta para o cliente (aparece na tela dele):', s.tipo === 'MODULO' ? 'Módulo liberado. Bom uso!' : s.tipo === 'PLANO' ? 'Plano alterado. Bom uso!' : '');
      if (resposta === null) return;
      var antes = Promise.resolve(true);
      if (s.tipo === 'MODULO' && s.modulo) {
        antes = postar({ acao: 'modulo', card_id: cardId(), modulo: s.modulo, ativo: true }).then(function (r) { if (!r.ok) { erro(r.error); return false; } return true; });
      } else if (s.tipo === 'PLANO' && s.planoComercialId) {
        antes = postar({ acao: 'plano', card_id: cardId(), plano_id: s.planoComercialId }).then(function (r) { if (!r.ok) { erro(r.error); return false; } return true; });
      }
      antes.then(function (seguir) {
        if (seguir) acao({ acao: 'responder', solicitacao_id: s.id, status: 'ATENDIDA', resposta: resposta });
      });
    }

    function movimentos(d) {
      var h = '<div class="ffpl-rotulo">Mensalidades</div>';
      h += d.mensalidades.length ? '<table class="ffpl-tabela">' + d.mensalidades.slice(0, 12).map(function (m) {
        return '<tr><td>' + esc(m.competencia) + '</td><td>' + real(m.valor) + '</td><td>' + ({ PAGO: 'paga', ATRASADO: 'em atraso', PENDENTE: 'a vencer' }[m.status] || m.status) + '</td></tr>';
      }).join('') + '</table>' : '<div class="ffag-vazio">Nenhuma mensalidade.</div>';
      h += '<div class="ffpl-rotulo">Recargas de saldo</div>';
      h += d.recargas.length ? '<table class="ffpl-tabela">' + d.recargas.slice(0, 12).map(function (rc) {
        return '<tr><td>' + dataBr(rc.criadaEm) + '</td><td>' + real(rc.valor) + '</td><td>' + (rc.metodo === 'PIX' ? 'Pix' : 'cartão') + '</td><td>' + esc(rc.status.toLowerCase()) + '</td></tr>';
      }).join('') + '</table>' : '<div class="ffag-vazio">Nenhuma recarga.</div>';
      h += '<div class="ffpl-rotulo">Extrato do saldo</div>';
      h += d.extrato.length ? '<table class="ffpl-tabela">' + d.extrato.slice(0, 30).map(function (e) {
        return '<tr><td>' + dataBr(e.criadoEm) + '</td><td>' + esc(e.descricao) + '</td><td style="text-align:right">' + real(e.valor) + '</td></tr>';
      }).join('') + '</table>' : '<div class="ffag-vazio">Sem movimento.</div>';
      return h;
    }

    function carregarPlanos() {
      if (planos) return Promise.resolve(planos);
      return api('planos=1').then(function (r) { planos = r.ok ? r.data : { planos: [] }; return planos; });
    }

    F.aoAbrir(shell, function () { carregar(false); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', montar);
  else montar();
})();
