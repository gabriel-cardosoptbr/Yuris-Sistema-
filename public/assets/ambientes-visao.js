/* ═══════════════════════════════════════════════════════════════════════
   ambientes-visao.js: as Visões Gerais dos ambientes da Inovaize (Empresa,
   Software, Digital) e as telas Entregas e Campanhas do Digital. Página em
   app/Ambientes/Paginas/visao.php; números de /api/ambientes.php e
   /api/digital.php. Visual sobre o .ctr- de ff-contratos.css e o .amb- de
   ambientes.css. Sem diálogo do navegador: confirmação em modal próprio.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var CFG = window.AMB || {};
  var app = document.getElementById('ambApp'), acoes = document.getElementById('ambAcoes');
  if (!app) return;
  var mes = CFG.mes;
  var apoio = null, clientes = null;

  // ── Utilidades ─────────────────────────────────────────────────────────
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function brl(v) { return (Number(v) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
  function num(v) { return (Number(v) || 0).toLocaleString('pt-BR'); }
  function data(v) { if (!v) return ''; var s = String(v); return s.slice(8, 10) + '/' + s.slice(5, 7) + (s.length > 10 && s.slice(11, 16) !== '00:00' ? ' ' + s.slice(11, 16) : ''); }
  function mesRotulo(m) { var n = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro']; return n[+m.slice(5, 7) - 1] + ' de ' + m.slice(0, 4); }
  function pedir(url, corpo) {
    var op = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (corpo) { op.method = 'POST'; op.headers['Content-Type'] = 'application/json'; op.headers['X-CSRF-Token'] = CFG.csrf; op.body = JSON.stringify(corpo); }
    return fetch(url, op).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão. Tente de novo.' }; });
  }
  function kpi(rot, valor, sub, href, tom) {
    var tag = href ? 'a' : 'div';
    return '<' + tag + ' class="ctr-num amb-kpi' + (tom ? ' amb-' + tom : '') + '"' + (href ? ' href="' + esc(href) + '"' : '') + '><span>' + esc(rot) + '</span><b>' + valor + '</b>' + (sub ? '<small>' + sub + '</small>' : '') + '</' + tag + '>';
  }
  function cartao(titulo, corpo, extra) { return '<section class="ctr-cartao amb-cartao"><h3>' + esc(titulo) + (extra || '') + '</h3>' + corpo + '</section>'; }
  function vazio(txt) { return '<p class="amb-vazio">' + esc(txt) + '</p>'; }
  function erro(txt) { app.innerHTML = '<div class="ctr-aviso vermelho" role="alert">' + esc(txt) + ' <button type="button" class="ctr-btn ctr-btn-sm" id="ambTentar">Tentar de novo</button></div>'; document.getElementById('ambTentar').onclick = abrir; }
  function toast(msg, tipo) {
    var t = document.createElement('div');
    t.className = 'amb-toast' + (tipo === 'erro' ? ' amb-toast-erro' : '');
    t.setAttribute('role', 'status'); t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 4200);
  }
  function confirmar(titulo, texto, rotuloOk) {
    return new Promise(function (ok) {
      var f = document.createElement('div');
      f.className = 'amb-guarda-fundo';
      f.innerHTML = '<div class="amb-guarda" role="alertdialog" aria-modal="true" aria-labelledby="ambCfT"><h3 id="ambCfT">' + esc(titulo) + '</h3><p>' + esc(texto) + '</p>' +
        '<div class="amb-guarda-acoes"><button type="button" data-n>Cancelar</button><button type="button" class="principal" data-s>' + esc(rotuloOk) + '</button></div></div>';
      document.body.appendChild(f);
      f.querySelector('[data-n]').focus();
      function fim(v) { f.remove(); ok(v); }
      f.querySelector('[data-n]').onclick = function () { fim(false); };
      f.querySelector('[data-s]').onclick = function () { fim(true); };
      f.addEventListener('keydown', function (e) { if (e.key === 'Escape') fim(false); });
    });
  }
  function seletorMes() {
    if (!acoes) return;
    acoes.innerHTML = '<label class="amb-mes"><span>Mês</span><input type="month" id="ambMes" value="' + esc(mes) + '" data-amb-ignorar></label>';
    document.getElementById('ambMes').onchange = function () {
      if (!this.value) return;
      mes = this.value;
      var u = new URL(location.href); u.searchParams.set('mes', mes); history.replaceState(null, '', u.toString());
      abrir();
    };
  }
  function linkTarefa(area, t) { return '/tarefas?amb=' + area + '&board=' + t.board_id + '&tarefa=' + t.id; }
  function proximos(lista, area) {
    if (!lista.length) return vazio('Nenhum prazo nos próximos 14 dias.');
    return '<ul class="amb-lista">' + lista.map(function (t) {
      return '<li><a href="' + linkTarefa(area, t) + '"><b>' + esc(t.titulo) + '</b><small>' + esc(t.quadro || '') + (t.responsavel ? ' · ' + esc(t.responsavel) : ' · sem responsável') + '</small></a>' +
        '<span class="amb-pill' + (t.atrasada ? ' amb-pill-verm' : '') + '">' + (t.atrasada ? 'Atrasada · ' : '') + esc(data(t.prazo)) + '</span></li>';
    }).join('') + '</ul>';
  }
  function responsaveis(lista) {
    if (!lista.length) return vazio('Nenhuma tarefa aberta com responsável.');
    return '<table class="amb-tab"><thead><tr><th>Pessoa</th><th>Abertas</th><th>Atrasadas</th><th>Próximo prazo</th></tr></thead><tbody>' + lista.map(function (r) {
      return '<tr><td>' + esc(r.nome) + '</td><td>' + num(r.abertas) + '</td><td' + (r.atrasadas ? ' class="amb-verm"' : '') + '>' + num(r.atrasadas) + '</td><td>' + esc(data(r.proximo) || '-') + '</td></tr>';
    }).join('') + '</tbody></table>';
  }

  // ── Empresa ────────────────────────────────────────────────────────────
  function empresa() {
    seletorMes();
    pedir('/api/ambientes.php?visao=empresa&mes=' + mes).then(function (r) {
      if (!r.ok) return erro(r.error || 'Não foi possível carregar a Visão Geral.');
      var d = r.data, rc = d.receita, cl = d.clientes, v = d.vendas, e = d.entregas, a = d.atrasos, p = d.pendencias, pa = rc.mrr_por_area;
      var h = '<div class="ctr-central amb-kpis">' +
        kpi('Receita recorrente', brl(rc.mrr), 'Software ' + brl(pa.software) + ' · Digital ' + brl(pa.digital) + (pa.outro || pa.sem_area ? ' · Outros ' + brl((pa.outro || 0) + (pa.sem_area || 0)) : ''), CFG.pode.financas ? '/financas?amb=empresa' : '') +
        kpi('Recebido em ' + mesRotulo(d.mes), brl(rc.recebido_mes), rc.cobrancas_atrasadas ? brl(rc.atrasado) + ' em atraso (' + num(rc.cobrancas_atrasadas) + ')' : 'Nada em atraso', CFG.pode.financas ? '/financas?amb=empresa' : '', rc.cobrancas_atrasadas ? 'alerta' : '') +
        kpi('Clientes ativos', num(cl.ativos), 'Software ' + num(cl.software) + ' · Digital ' + num(cl.digital) + ' · nas duas ' + num(cl.nas_duas), '/clientes?amb=empresa') +
        kpi('Vendas no mês', num(v.qtd), brl(v.valor), '/dashboard?amb=comercial') +
        kpi('Projetos', num(d.projetos.carteira), 'Quadros: Software ' + num(d.projetos.quadros.software) + ' · Digital ' + num(d.projetos.quadros.digital)) +
        kpi('Entregas do mês', num(e.concluidas) + '<em>/' + num(e.contratadas || e.cadastradas) + '</em>', num(e.producao) + ' em produção · ' + num(e.aprovacao) + ' aguardando aprovação', '/digital?ver=entregas&mes=' + d.mes) +
        '</div>';
      var atrasos = [
        ['Tarefas de Software atrasadas', a.tarefas_software, '/software'], ['Tarefas de Digital atrasadas', a.tarefas_digital, '/digital'],
        ['Entregas atrasadas', a.entregas, '/digital?ver=entregas&mes=' + d.mes], ['Cobranças vencidas', a.cobrancas, CFG.pode.financas ? '/financas?amb=empresa' : '', a.cobrancas ? brl(a.cobrancas_valor) : '']
      ];
      var pend = [
        ['Entregas aguardando aprovação do cliente', p.aprovacoes, '/digital?ver=entregas&mes=' + d.mes], ['Materiais esperados ainda não recebidos', p.materiais, '/digital'],
        ['Tarefas sem responsável', p.sem_responsavel, '/software']
      ];
      if (p.contratos !== null) pend.splice(2, 0, ['Contratos aguardando assinatura', p.contratos, '/contratos?amb=empresa']);
      function linhas(l) {
        return '<ul class="amb-lista amb-lista-num">' + l.map(function (x) {
          var dentro = '<b>' + esc(x[0]) + '</b>' + (x[3] ? '<small>' + esc(x[3]) + '</small>' : '');
          return '<li>' + (x[2] ? '<a href="' + esc(x[2]) + '">' + dentro + '</a>' : '<span>' + dentro + '</span>') + '<span class="amb-pill' + (x[1] ? ' amb-pill-verm' : ' amb-pill-ok') + '">' + num(x[1]) + '</span></li>';
        }).join('') + '</ul>';
      }
      h += '<div class="amb-duas">' + cartao('Atrasos', linhas(atrasos)) + cartao('Pendências', linhas(pend)) + '</div>';
      if (CFG.admin) h += '<div id="ambDrive"></div>';
      h += cartao('Como os números são contados',
        '<p class="amb-nota">Cada cliente conta uma vez no total, mesmo quando contrata Software e Digital; "nas duas" mostra quantos estão nas duas áreas. ' +
        'A receita recorrente soma cada linha de valor do cliente uma vez, na área da linha. As vendas seguem a regra do painel comercial: o negócio fechado no funil cujo cliente já tem valores cadastrados não conta de novo. ' +
        'Entregas contam cartões marcados como entrega; item de checklist nunca conta.</p>');
      app.innerHTML = h;
      if (CFG.admin) driveCartao(document.getElementById('ambDrive'));
    });
  }

  // Conexão da conta Google (só administrador): o Drive de cada cliente depende dela.
  function driveCartao(el) {
    if (!el) return;
    pedir('/api/drive.php?estado=1').then(function (r) {
      if (!r || !r.ok) return;
      var d = r.data, h = '';
      if (d.aviso) h += '<div class="ctr-aviso ' + (d.aviso.ok ? 'verde' : 'vermelho') + '" role="status">' + esc(d.aviso.msg) + '</div>';
      if (!d.configurado) h += '<p class="amb-nota">O servidor ainda não tem as credenciais do Google (GOOGLE_DRIVE_CLIENT_ID e GOOGLE_DRIVE_CLIENT_SECRET). Depois de configuradas, conecte aqui a conta corporativa.</p>';
      else if (!d.conectado) h += '<p class="amb-nota">Nenhuma conta Google conectada' + (d.dominio ? ' (só @' + esc(d.dominio) + ')' : '') + '. Conecte a conta corporativa para ligar a pasta de cada cliente.</p>' +
        '<a class="ctr-btn ctr-btn-principal" href="/api/drive_oauth.php?iniciar=1&volta=%2Fempresa">Conectar conta Google</a>';
      else h += '<p class="amb-nota">Conectado como <b>' + esc(d.email) + '</b> desde ' + esc(data(d.conectado_em)) + '. ' +
        (d.ultima_sync ? 'Última sincronização automática: ' + esc(data(d.ultima_sync)) + '. ' : 'A sincronização automática ainda não rodou. ') +
        (d.erro ? '<span class="amb-verm">Último erro: ' + esc(d.erro) + '</span>' : '') + '</p><button type="button" class="ctr-btn ctr-btn-perigo" id="ambDriveSair">Desconectar</button>';
      el.innerHTML = cartao('Google Drive', h);
      var sair = document.getElementById('ambDriveSair');
      if (sair) sair.onclick = function () {
        confirmar('Desconectar o Google Drive', 'As pastas ligadas aos clientes continuam registradas, mas ninguém verá os arquivos até conectar de novo.', 'Desconectar').then(function (sim) {
          if (!sim) return;
          pedir('/api/drive.php', { acao: 'desconectar' }).then(function (x) { if (!x.ok) return toast(x.error || 'Não foi possível desconectar.', 'erro'); driveCartao(el); });
        });
      };
    });
  }

  // ── Software ───────────────────────────────────────────────────────────
  function software() {
    if (acoes) acoes.innerHTML = CFG.pode.tarefas ? '<a class="ctr-btn ctr-btn-principal" href="/tarefas?amb=software">Abrir quadros</a>' : '';
    pedir('/api/ambientes.php?visao=software').then(function (r) {
      if (!r.ok) return erro(r.error || 'Não foi possível carregar a Visão Geral.');
      var d = r.data, t = d.tarefas;
      var h = '<div class="ctr-central amb-kpis">' +
        kpi('Projetos em andamento', num(d.carteira.length), 'na carteira de projetos', '/projetos?amb=software') +
        kpi('Quadros de Software', num(d.quadros.length), 'demandas e suporte', '/tarefas?amb=software') +
        kpi('Tarefas abertas', num(t.abertas), num(t.sem_responsavel) + ' sem responsável') +
        kpi('Atrasadas', num(t.atrasadas), t.atrasadas ? 'precisam de atenção' : 'tudo no prazo', '', t.atrasadas ? 'alerta' : '') +
        kpi('Suporte em aberto', num(t.suporte_abertos), 'chamados nos quadros de suporte') +
        '</div>';
      var quadros = d.quadros.length ? '<table class="amb-tab"><thead><tr><th>Quadro</th><th>Cliente</th><th>Abertas</th><th>Atrasadas</th><th>Próximo prazo</th></tr></thead><tbody>' +
        d.quadros.map(function (b) {
          return '<tr><td><a href="/tarefas?amb=software&board=' + b.id + '">' + esc(b.nome) + '</a></td><td>' + esc(b.cliente || 'Interno') + '</td><td>' + num(b.abertas) + '</td><td' + (b.atrasadas ? ' class="amb-verm"' : '') + '>' + num(b.atrasadas) + '</td><td>' + esc(data(b.proximo) || '-') + '</td></tr>';
        }).join('') + '</tbody></table>' : vazio('Nenhum quadro de Software. Crie um em "Demandas e suporte" para cada projeto, cliente ou para o suporte.');
      var STATUS = { desenvolvimento: 'Em desenvolvimento', producao: 'Em produção', pausado: 'Pausado' };
      var carteira = d.carteira.length ? '<table class="amb-tab"><thead><tr><th>Projeto</th><th>Cliente</th><th>Situação</th><th>Responsável</th></tr></thead><tbody>' +
        d.carteira.map(function (p) {
          return '<tr><td><a href="/projetos?amb=software&p=' + encodeURIComponent(p.slug) + '">' + esc(p.nome) + '</a></td><td>' + esc(p.cliente || '-') + '</td><td><span class="amb-pill">' + esc(STATUS[p.status] || p.status) + '</span></td><td>' + esc(p.responsavel || 'Sem responsável') + '</td></tr>';
        }).join('') + '</tbody></table>' : vazio('Nenhum projeto de Software em andamento na carteira.');
      h += cartao('Projetos em andamento', carteira) + cartao('Quadros de demandas e suporte', quadros);
      h += '<div class="amb-duas">' + cartao('Próximos prazos (14 dias)', proximos(d.proximos, 'software')) + cartao('Responsáveis', responsaveis(d.responsaveis)) + '</div>';
      app.innerHTML = h;
    });
  }

  // ── Digital ────────────────────────────────────────────────────────────
  function previsto(c) {
    var tipos = Object.keys(c.por_tipo || {});
    if (!tipos.length) return '<span class="amb-cinza">Sem frequência contratada</span>';
    return tipos.map(function (k) {
      var x = c.por_tipo[k];
      return '<span class="amb-prev"><b>' + esc(x.rotulo) + '</b> ' + num(x.entregues) + (x.previsto ? '/' + num(x.previsto) : '') + (x.pendentes ? ' <em>' + num(x.pendentes) + ' pendente' + (x.pendentes > 1 ? 's' : '') + '</em>' : '') + '</span>';
    }).join('');
  }
  function digital() {
    seletorMes();
    pedir('/api/ambientes.php?visao=digital&mes=' + mes).then(function (r) {
      if (!r.ok) return erro(r.error || 'Não foi possível carregar a Visão Geral.');
      var d = r.data, t = d.total;
      var h = '<div class="ctr-central amb-kpis">' +
        kpi('Contratadas no mês', num(t.contratadas), t.nao_cadastradas ? num(t.nao_cadastradas) + ' ainda sem cartão' : 'todas com cartão') +
        kpi('Em produção', num(t.producao), num(t.cadastradas) + ' cadastradas', '/digital?ver=entregas&mes=' + d.mes) +
        kpi('Aguardando aprovação', num(t.aprovacao), 'do cliente', '/digital?ver=entregas&mes=' + d.mes, t.aprovacao ? 'atencao' : '') +
        kpi('Concluídas', num(t.concluidas), 'entregues em ' + mesRotulo(d.mes), '/digital?ver=entregas&mes=' + d.mes) +
        kpi('Atrasadas', num(t.atrasadas), 'não concluídas com prazo vencido', '/digital?ver=entregas&mes=' + d.mes, t.atrasadas ? 'alerta' : '') +
        kpi('Materiais faltando', num(t.materiais_faltando), 'esperados e não recebidos') +
        kpi('Campanhas ativas', num(t.campanhas_ativas), 'tráfego pago', '/digital?ver=campanhas') +
        '</div>';
      var linhas = d.clientes.length ? '<table class="amb-tab amb-tab-cli"><thead><tr><th>Cliente</th><th>Previsto x realizado</th><th>Produção</th><th>Aprovação</th><th>Concluídas</th><th>Atrasadas</th><th>Quadros</th></tr></thead><tbody>' +
        d.clientes.map(function (c) {
          return '<tr><td><a href="/clientes?amb=digital&open=' + c.id + '">' + esc(c.nome) + '</a>' + (c.campanhas_ativas ? '<small class="amb-cinza">' + num(c.campanhas_ativas) + ' campanha(s) ativa(s)</small>' : '') + '</td>' +
            '<td>' + previsto(c) + '</td><td>' + num(c.producao) + '</td><td' + (c.aprovacao ? ' class="amb-amb"' : '') + '>' + num(c.aprovacao) + '</td><td>' + num(c.concluidas) + '</td>' +
            '<td' + (c.atrasadas ? ' class="amb-verm"' : '') + '>' + num(c.atrasadas) + '</td>' +
            '<td>' + (c.quadros.length ? c.quadros.map(function (q) { return '<a class="amb-pill" href="/tarefas?amb=digital&board=' + q.id + '">' + esc(q.nome) + '</a>'; }).join(' ') : '<a class="amb-cinza" href="/tarefas?amb=digital">Criar quadro</a>') + '</td></tr>';
        }).join('') + '</tbody></table>'
        : vazio('Nenhum cliente de Digital ainda. O cliente entra aqui quando tem frequência contratada, campanha, quadro de produção ou valor da área Digital.');
      h += cartao('Clientes em ' + mesRotulo(d.mes), linhas);
      h += '<div class="amb-duas">' + cartao('Próximos prazos (14 dias)', proximos(d.proximos, 'digital')) + cartao('Responsáveis', responsaveis(d.responsaveis)) + '</div>';
      app.innerHTML = h;
    });
  }

  // ── Entregas ───────────────────────────────────────────────────────────
  function entregas() {
    seletorMes();
    pedir('/api/digital.php?entregas=1&mes=' + mes).then(function (r) {
      if (!r.ok) return erro(r.error || 'Não foi possível carregar as entregas.');
      var l = r.data, filtro = 'todas';
      var cont = { todas: l.length, producao: 0, aprovacao: 0, concluida: 0, atrasada: 0 };
      l.forEach(function (e) { cont[e.situacao]++; if (e.atrasada) cont.atrasada++; });
      function desenhar() {
        var vis = l.filter(function (e) { return filtro === 'todas' || (filtro === 'atrasada' ? e.atrasada : e.situacao === filtro); });
        var chips = [['todas', 'Todas'], ['producao', 'Em produção'], ['aprovacao', 'Aguardando aprovação'], ['concluida', 'Concluídas'], ['atrasada', 'Atrasadas']].map(function (c) {
          return '<button type="button" class="ctr-aba' + (filtro === c[0] ? ' ativa' : '') + '" data-f="' + c[0] + '">' + c[1] + ' (' + num(cont[c[0]]) + ')</button>';
        }).join('');
        var tab = vis.length ? '<table class="amb-tab"><thead><tr><th>Cliente</th><th>Entrega</th><th>Produção</th><th>Aprovação</th><th>Entrega</th><th>Publicação</th><th>Prazo</th><th>Responsável</th></tr></thead><tbody>' +
          vis.map(function (e) {
            var cls = { producao: '', aprovacao: ' amb-pill-amb', concluida: ' amb-pill-ok' };
            return '<tr><td>' + esc(e.cliente || '-') + '</td><td><a href="/tarefas?amb=digital&board=' + e.board_id + '&tarefa=' + e.task_id + '">' + esc(e.titulo) + '</a><small class="amb-cinza">' + esc(e.tipo_rotulo) + ' · ' + esc(e.quadro) + '</small></td>' +
              '<td>' + esc(e.producao_rotulo) + '</td><td><span class="amb-pill' + (e.aprovacao === 'aguardando' ? ' amb-pill-amb' : e.aprovacao === 'aprovado' ? ' amb-pill-ok' : e.aprovacao === 'ajustes' ? ' amb-pill-verm' : '') + '">' + esc(e.aprovacao_rotulo) + '</span></td>' +
              '<td><span class="amb-pill' + (cls[e.situacao] || '') + '">' + esc(e.entrega_rotulo) + '</span></td><td>' + esc(e.publicacao_rotulo) + '</td>' +
              '<td' + (e.atrasada ? ' class="amb-verm"' : '') + '>' + esc(data(e.prazo) || '-') + (e.atrasada ? ' (atrasada)' : '') + '</td><td>' + esc(e.responsavel || '-') + '</td></tr>';
          }).join('') + '</tbody></table>' : vazio(l.length ? 'Nenhuma entrega neste filtro.' : 'Nenhuma entrega com competência em ' + mesRotulo(mes) + '. Marque o tipo de entrega na aba "Entrega" do cartão.');
        app.innerHTML = '<div class="ctr-filtros">' + chips + '</div>' + cartao('Entregas de ' + mesRotulo(mes), tab);
        app.querySelectorAll('[data-f]').forEach(function (b) { b.onclick = function () { filtro = b.dataset.f; desenhar(); }; });
      }
      desenhar();
    });
  }

  // ── Campanhas ──────────────────────────────────────────────────────────
  function carregarApoio() {
    return Promise.all([
      apoio ? Promise.resolve() : pedir('/api/digital.php?apoio=1').then(function (r) { apoio = r.ok ? r.data : { plataformas: {}, status_campanha: {}, equipe: [] }; }),
      clientes ? Promise.resolve() : pedir('/api/digital.php?clientes=1').then(function (r) { clientes = r.ok ? r.data : []; })
    ]);
  }
  function opts(mapa, atual) { return Object.keys(mapa).map(function (k) { return '<option value="' + k + '"' + (k === atual ? ' selected' : '') + '>' + esc(mapa[k]) + '</option>'; }).join(''); }
  function formCampanha(k, aoSalvar) {
    k = k || { status: 'planejada', plataforma: 'meta' };
    var f = document.createElement('div');
    f.className = 'amb-guarda-fundo ctr-overlay';
    f.innerHTML = '<form class="amb-guarda amb-form" data-amb-guarda novalidate><h3>' + (k.id ? 'Editar campanha' : 'Nova campanha') + '</h3>' +
      '<div class="amb-form-grade">' +
      '<label class="amb-c2">Cliente<select name="cliente_id" required><option value="">Escolha</option>' + clientes.map(function (c) { return '<option value="' + c.id + '"' + (c.id === k.cliente_id ? ' selected' : '') + '>' + esc(c.nome) + '</option>'; }).join('') + '</select></label>' +
      '<label class="amb-c2">Nome<input name="nome" maxlength="160" required value="' + esc(k.nome || '') + '" placeholder="Ex.: Black Friday 2026"></label>' +
      '<label>Plataforma<select name="plataforma">' + opts(apoio.plataformas, k.plataforma) + '</select></label>' +
      '<label>Situação<select name="status">' + opts(apoio.status_campanha, k.status) + '</select></label>' +
      '<label>Verba mensal (R$)<input name="verba_mensal" inputmode="decimal" value="' + (k.verba_mensal != null ? String(k.verba_mensal).replace('.', ',') : '') + '"></label>' +
      '<label>Responsável<select name="responsavel_id"><option value="">Sem responsável</option>' + apoio.equipe.map(function (u) { return '<option value="' + u.id + '"' + (u.id === k.responsavel_id ? ' selected' : '') + '>' + esc(u.nome) + '</option>'; }).join('') + '</select></label>' +
      '<label>Início<input type="date" name="inicio" value="' + esc(k.inicio || '') + '"></label>' +
      '<label>Fim<input type="date" name="fim" value="' + esc(k.fim || '') + '"></label>' +
      '<label class="amb-c2">Objetivo<input name="objetivo" maxlength="160" value="' + esc(k.objetivo || '') + '" placeholder="Ex.: leads para a loja"></label>' +
      '<label class="amb-c2">Link do gerenciador<input name="link" value="' + esc(k.link || '') + '" placeholder="https://..."></label>' +
      '<label class="amb-c2">Observação<input name="observacao" maxlength="500" value="' + esc(k.observacao || '') + '"></label>' +
      '</div><div class="ctr-erro" data-erro role="alert"></div>' +
      '<div class="amb-guarda-acoes"><button type="button" data-cancelar>Cancelar</button><button type="submit" class="principal">Salvar</button></div></form>';
    document.body.appendChild(f);
    var form = f.querySelector('form');
    form.querySelector('[name="cliente_id"]').focus();
    function fechar() { f.remove(); }
    f.querySelector('[data-cancelar]').onclick = fechar;
    form.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });
    form.onsubmit = function (e) {
      e.preventDefault();
      var corpo = { acao: 'campanha_salvar', id: k.id || 0 };
      new FormData(form).forEach(function (v, n) { corpo[n] = v; });
      corpo.cliente_id = +corpo.cliente_id || 0; corpo.responsavel_id = +corpo.responsavel_id || null;
      var b = form.querySelector('[type="submit"]'); b.disabled = true;
      pedir('/api/digital.php', corpo).then(function (r) {
        b.disabled = false;
        if (!r.ok) { form.querySelector('[data-erro]').textContent = r.error || 'Não foi possível salvar.'; return; }
        fechar(); toast('Campanha salva.'); aoSalvar();
      });
    };
  }
  function campanhas() {
    if (acoes) acoes.innerHTML = '<button type="button" class="ctr-btn ctr-btn-principal" id="ambNovaCampanha">Nova campanha</button>';
    var filtro = '';
    function carregar() {
      pedir('/api/digital.php?campanhas=1' + (filtro ? '&status=' + filtro : '')).then(function (r) {
        if (!r.ok) return erro(r.error || 'Não foi possível carregar as campanhas.');
        carregarApoio().then(function () {
          var l = r.data;
          var chips = [['', 'Todas']].concat(Object.keys(apoio.status_campanha).map(function (k) { return [k, apoio.status_campanha[k]]; })).map(function (c) {
            return '<button type="button" class="ctr-aba' + (filtro === c[0] ? ' ativa' : '') + '" data-f="' + c[0] + '">' + esc(c[1]) + '</button>';
          }).join('');
          var tab = l.length ? '<table class="amb-tab"><thead><tr><th>Campanha</th><th>Cliente</th><th>Plataforma</th><th>Verba mensal</th><th>Período</th><th>Situação</th><th>Responsável</th><th></th></tr></thead><tbody>' +
            l.map(function (k) {
              return '<tr><td><b>' + esc(k.nome) + '</b>' + (k.objetivo ? '<small class="amb-cinza">' + esc(k.objetivo) + '</small>' : '') + '</td><td>' + esc(k.cliente) + '</td><td>' + esc(k.plataforma_rotulo) + '</td>' +
                '<td>' + (k.verba_mensal != null ? brl(k.verba_mensal) : '-') + '</td><td>' + esc((data(k.inicio) || '?') + (k.fim ? ' a ' + data(k.fim) : '')) + '</td>' +
                '<td><span class="amb-pill' + (k.status === 'ativa' ? ' amb-pill-ok' : k.status === 'pausada' ? ' amb-pill-amb' : '') + '">' + esc(k.status_rotulo) + '</span></td><td>' + esc(k.responsavel || '-') + '</td>' +
                '<td class="amb-acoes-linha">' + (k.link ? '<a class="ctr-btn ctr-btn-sm" href="' + esc(k.link) + '" target="_blank" rel="noopener noreferrer">Gerenciador</a>' : '') +
                '<button type="button" class="ctr-btn ctr-btn-sm" data-editar="' + k.id + '">Editar</button><button type="button" class="ctr-btn ctr-btn-sm ctr-btn-perigo" data-remover="' + k.id + '">Remover</button></td></tr>';
            }).join('') + '</tbody></table>' : vazio('Nenhuma campanha' + (filtro ? ' nesta situação.' : '. Cadastre a primeira com "Nova campanha".'));
          app.innerHTML = '<div class="ctr-filtros">' + chips + '</div>' + cartao('Campanhas de tráfego pago', tab);
          app.querySelectorAll('[data-f]').forEach(function (b) { b.onclick = function () { filtro = b.dataset.f; carregar(); }; });
          app.querySelectorAll('[data-editar]').forEach(function (b) { b.onclick = function () { formCampanha(l.filter(function (k) { return k.id === +b.dataset.editar; })[0], carregar); }; });
          app.querySelectorAll('[data-remover]').forEach(function (b) {
            b.onclick = function () {
              var k = l.filter(function (x) { return x.id === +b.dataset.remover; })[0];
              confirmar('Remover campanha', 'Remover "' + k.nome + '"? O cadastro sai do sistema; nada muda na plataforma de anúncios.', 'Remover').then(function (sim) {
                if (!sim) return;
                pedir('/api/digital.php', { acao: 'campanha_remover', id: k.id }).then(function (x) { if (!x.ok) return toast(x.error || 'Não foi possível remover.', 'erro'); toast('Campanha removida.'); carregar(); });
              });
            };
          });
        });
      });
    }
    var bn = document.getElementById('ambNovaCampanha');
    if (bn) bn.onclick = function () { carregarApoio().then(function () { formCampanha(null, carregar); }); };
    carregar();
  }

  function abrir() {
    if (CFG.ambiente === 'digital' && CFG.ver === 'entregas') return entregas();
    if (CFG.ambiente === 'digital' && CFG.ver === 'campanhas') return campanhas();
    if (CFG.ambiente === 'empresa') return empresa();
    if (CFG.ambiente === 'software') return software();
    if (CFG.ambiente === 'digital') return digital();
  }
  abrir();
})();
