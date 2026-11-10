/* ═══════════════════════════════════════════════════════════════════════════
   interface-nova-telas.js: arrumações de uma tela só (interface nova, prévia).
   Par da seção "Processos" de interface-nova.css. Carregado por
   includes/sidebar.php nas telas de dentro, fora do ?embed=1.

   Processos (10/11/2026): "quero clicar aqui e ver o calendário processual
   estilo Kanban, ver um calendário literalmente, com datas, dias e meses, e a
   agenda jurídica em linhas: as três visões, e eu só mudo a tela. E sempre no
   mês atual, ou um botão Mês atual."
   O painel "Calendário Processual" ganha a troca de visão:
     - Quadro: as colunas por mês de sempre (o processos.js desenha);
     - Calendário: o mês em grade, cada processo no dia do próximo prazo; lido
       dos cartões do quadro, então vale o mesmo ano e os mesmos filtros, e
       clicar num processo abre a ficha dele (o Editar do cartão);
     - Agenda: a Agenda Jurídica Prioritária (o #upcomingList, que o
       processos.js continua enchendo), que sai do fim da página.
   Abre no mês atual (o quadro rola até a coluna dele, o calendário mostra ele)
   e o botão "Mês atual" volta para lá, trocando o ano se precisar. A visão
   escolhida fica guardada neste navegador.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var html = document.documentElement;
  if (html.classList.contains('yuris-embed') || /[?&]embed=1(&|$)/.test(location.search)) return;

  var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  var CHAVE = 'yuris_processos_vista';
  function guardada() { try { return localStorage.getItem(CHAVE) || 'quadro'; } catch (e) { return 'quadro'; } }
  function guarda(v) { try { localStorage.setItem(CHAVE, v); } catch (e) {} }
  function el(tag, cls, html_) { var e = document.createElement(tag); if (cls) e.className = cls; if (html_ != null) e.innerHTML = html_; return e; }
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function vistasDeProcessos() {
    var quadro = document.getElementById('columnsWrapper');
    var lista = document.getElementById('upcomingList');
    var ano = document.getElementById('yearFilter');
    if (!quadro || !lista || !ano || document.querySelector('.inn-vistas')) return;
    var painel = quadro.closest('.proc-panel');
    var titulo = painel && painel.querySelector('.proc-section-title');
    var cabeca = titulo && titulo.closest('.flex');
    var sub = painel && painel.querySelector('.proc-subtitle');
    if (!cabeca) return;

    // A agenda vem para cá; o painel dela, vazio, sai.
    var painelAgenda = lista.closest('.proc-panel');
    var vAgenda = el('div', 'inn-vista inn-vista-agenda'); vAgenda.appendChild(lista);

    // ── Os filtros da agenda (10/11/2026, "na agenda tenho que ter filtros: encerrado, aberto; filtros de
    // data") ── pela situação de cada prazo (o selo da linha: vencido, hoje ou urgente, esta semana, em dia,
    // encerrado), com a contagem de cada uma, e por período (de, até). Filtra as linhas que o processos.js
    // desenhou (o ano escolhido em cima vale), e refiltra quando ele redesenha.
    var SITUACOES = [['todos', 'Todos'], ['vencido', 'Vencidos'], ['urgente', 'Hoje e urgentes'], ['semana', 'Esta semana'], ['emdia', 'Em dia'], ['encerrado', 'Encerrados']];
    var fAg = el('div', 'inn-ag-filtros',
      '<div class="inn-ag-chips" role="group" aria-label="Situação do prazo">' + SITUACOES.map(function (x) {
        return '<button type="button" class="inn-ag-chip' + (x[0] === 'todos' ? ' on' : '') + '" data-f="' + x[0] + '" aria-pressed="' + (x[0] === 'todos') + '"><span>' + x[1] + '</span><b data-inn="1"></b></button>';
      }).join('') + '</div>' +
      '<div class="inn-ag-datas"><label>De <input type="date" class="inn-ag-de"></label><label>até <input type="date" class="inn-ag-ate"></label>' +
      '<button type="button" class="inn-ag-limpar"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Limpar</button></div>');
    vAgenda.insertBefore(fAg, lista);
    var agVazio = el('div', 'inn-ag-vazio', 'Nenhum prazo com este filtro.'); agVazio.hidden = true;
    vAgenda.appendChild(agVazio);
    var fSit = 'todos', fDe = fAg.querySelector('.inn-ag-de'), fAte = fAg.querySelector('.inn-ag-ate');
    function situacaoDe(row) {
      var t = ((row.querySelector('.proc-badge') || {}).textContent || '').toLowerCase();
      return /vencid/.test(t) ? 'vencido' : /hoje|urgente/.test(t) ? 'urgente' : /semana/.test(t) ? 'semana' : /encerrad/.test(t) ? 'encerrado' : 'emdia';
    }
    function dataDe(row) { var m = /(\d{2})\/(\d{2})\/(\d{4})/.exec((row.querySelector('.proc-agenda-date') || {}).textContent || ''); return m ? m[3] + '-' + m[2] + '-' + m[1] : ''; }
    function filtrarAgenda() {
      var de = fDe.value, ate = fAte.value, conta = { todos: 0 }, vistas = 0;
      [].forEach.call(lista.querySelectorAll('.proc-agenda-row'), function (r) {
        var d = dataDe(r), sit = situacaoDe(r);
        var noPeriodo = (!de || (d && d >= de)) && (!ate || (d && d <= ate));
        if (noPeriodo) { conta.todos++; conta[sit] = (conta[sit] || 0) + 1; }
        var ok = noPeriodo && (fSit === 'todos' || sit === fSit);
        r.hidden = !ok; if (ok) vistas++;
      });
      [].forEach.call(fAg.querySelectorAll('.inn-ag-chip'), function (c) { c.querySelector('b').textContent = String(conta[c.dataset.f] || 0); });
      agVazio.hidden = vistas > 0 || !lista.querySelector('.proc-agenda-row');
      fAg.querySelector('.inn-ag-limpar').hidden = fSit === 'todos' && !de && !ate;
    }
    fAg.addEventListener('click', function (e) {
      var c = e.target.closest('.inn-ag-chip');
      if (c) {
        fSit = c.dataset.f;
        [].forEach.call(fAg.querySelectorAll('.inn-ag-chip'), function (x) { var on = x === c; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        filtrarAgenda(); return;
      }
      if (e.target.closest('.inn-ag-limpar')) { fDe.value = ''; fAte.value = ''; fAg.querySelector('.inn-ag-chip[data-f="todos"]').click(); }
    });
    fDe.addEventListener('change', filtrarAgenda); fAte.addEventListener('change', filtrarAgenda);
    new MutationObserver(filtrarAgenda).observe(lista, { childList: true });
    filtrarAgenda();
    var vCal = el('div', 'inn-vista inn-vista-cal');
    quadro.classList.add('inn-vista', 'inn-vista-quadro');
    quadro.parentNode.insertBefore(vCal, quadro.nextSibling);
    vCal.parentNode.insertBefore(vAgenda, vCal.nextSibling);
    if (painelAgenda && painelAgenda !== painel) painelAgenda.remove();

    var SUB = {
      quadro: 'Processos organizados por mês de prazo; role para o lado para ver os outros meses',
      calendario: 'Cada processo no dia do próximo prazo; clique para abrir a ficha',
      agenda: 'Próximos prazos ordenados por urgência: os mais críticos aparecem primeiro'
    };
    var ICONE = {
      quadro: '<rect x="3" y="4" width="5" height="16" rx="1.5"/><rect x="10" y="4" width="5" height="11" rx="1.5"/><rect x="17" y="4" width="4" height="7" rx="1.5"/>',
      calendario: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
      agenda: '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>'
    };
    var NOME = { quadro: 'Quadro', calendario: 'Calendário', agenda: 'Agenda' };
    var barra = el('div', 'inn-vistas');
    var seg = el('div', 'inn-vista-seg'); seg.setAttribute('role', 'tablist'); seg.setAttribute('aria-label', 'Ver os processos como');
    ['quadro', 'calendario', 'agenda'].forEach(function (v) {
      var b = el('button', 'inn-vista-btn', '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONE[v] + '</svg><span>' + NOME[v] + '</span>');
      b.type = 'button'; b.dataset.vista = v; b.setAttribute('role', 'tab');
      seg.appendChild(b);
    });
    var atual = el('button', 'inn-mes-atual', '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/></svg><span>Mês atual</span>');
    atual.type = 'button';
    barra.appendChild(seg); barra.appendChild(atual);
    cabeca.appendChild(barra);

    var vista = guardada(); if (!NOME[vista]) vista = 'quadro';
    var hoje = new Date();
    var visto = null;   // o mês que o calendário mostra: { a, m }

    // O ícone do painel é sempre o calendário: a camada escolhe o ícone pelas palavras do título e do
    // subtítulo, e o subtítulo da Agenda ("os mais críticos") dava o triângulo de alerta.
    function iconeDoPainel() {
      var ic = painel.querySelector('.inn-card-ic');
      if (ic && !ic.dataset.innFixo) { ic.dataset.innFixo = '1'; ic.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + ICONE.calendario + '</svg>'; }
    }
    setTimeout(iconeDoPainel, 1500);
    function mostrar(v) {
      vista = v; guarda(v);
      [].forEach.call(seg.children, function (b) { var on = b.dataset.vista === v; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
      quadro.hidden = v !== 'quadro'; vCal.hidden = v !== 'calendario'; vAgenda.hidden = v !== 'agenda';
      if (sub) sub.textContent = SUB[v];
      iconeDoPainel();
      atual.hidden = v === 'agenda';
      if (v === 'calendario') desenhar();
      if (v === 'quadro') requestAnimationFrame(function () { rolarAoMes(hoje.getMonth(), false); });
    }
    seg.addEventListener('click', function (e) { var b = e.target.closest('.inn-vista-btn'); if (b && b.dataset.vista !== vista) mostrar(b.dataset.vista); });

    // ── O quadro no mês ─────────────────────────────────────────────────
    // A coluna do mês atual fica marcada (o dia a dia mora nela).
    function marcarMesAtual() {
      var esteAno = +ano.value === hoje.getFullYear();
      [].forEach.call(quadro.querySelectorAll('.proc-col'), function (c) { c.classList.toggle('inn-col-hoje', esteAno && c.getAttribute('data-month') === String(hoje.getMonth())); });
    }
    function rolarAoMes(m, suave) {
      if (+ano.value !== hoje.getFullYear() && m === hoje.getMonth()) return;
      var col = quadro.querySelector('.proc-col[data-month="' + m + '"]'); if (!col) return;
      var rolo = quadro.querySelector('.proc-board') || quadro;
      var d = col.getBoundingClientRect().left - rolo.getBoundingClientRect().left - 4;
      rolo.scrollTo({ left: rolo.scrollLeft + d, behavior: suave ? 'smooth' : 'auto' });
    }

    // ── O calendário ────────────────────────────────────────────────────
    function processos() {
      return [].map.call(quadro.querySelectorAll('.proc-col:not([data-month="nodate"]) .proc-card'), function (c) {
        var m = /(\d{2})\/(\d{2})\/(\d{4})/.exec((c.querySelector('.proc-card-date') || {}).textContent || '');
        if (!m) return null;
        var selo = ((c.querySelector('.proc-badge') || {}).textContent || '').trim();
        var tom = /vencid|hoje|urgente/i.test(selo) ? 'erro' : /semana/i.test(selo) ? 'aviso' : /encerrad/i.test(selo) ? 'neutro' : 'ok';
        return { id: c.getAttribute('data-id'), d: +m[1], m: +m[2] - 1, a: +m[3], num: ((c.querySelector('.proc-card-num') || {}).textContent || '').trim(),
                 cli: ((c.querySelector('.proc-card-client') || {}).textContent || '').trim(), selo: selo, tom: tom,
                 info: [].map.call(c.querySelectorAll('.proc-card-meta'), function (x) { return x.textContent.trim(); }).filter(Boolean) };
      }).filter(Boolean);
    }
    function mesInicial() {
      var a = +ano.value;
      if (!a || a === hoje.getFullYear()) return { a: hoje.getFullYear(), m: hoje.getMonth() };
      return { a: a, m: 0 };
    }
    function desenhar() {
      if (!visto) visto = mesInicial();
      var ps = processos().filter(function (p) { return p.a === visto.a && p.m === visto.m; });
      var porDia = {}; ps.forEach(function (p) { (porDia[p.d] = porDia[p.d] || []).push(p); });
      var primeiro = new Date(visto.a, visto.m, 1), dias = new Date(visto.a, visto.m + 1, 0).getDate();
      var antes = primeiro.getDay(), total = Math.ceil((antes + dias) / 7) * 7;
      var h = '<div class="inn-cal-topo">' +
        '<button type="button" class="inn-cal-nav" data-passo="-1" aria-label="Mês anterior"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>' +
        '<div class="inn-cal-mes"><b data-inn="1">' + MESES[visto.m].charAt(0).toUpperCase() + MESES[visto.m].slice(1) + ' de ' + visto.a + '</b><span>' + (ps.length ? ps.length + (ps.length === 1 ? ' prazo no mês' : ' prazos no mês') : 'nenhum prazo no mês') + '</span></div>' +
        '<button type="button" class="inn-cal-nav" data-passo="1" aria-label="Próximo mês"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>' +
        '</div><div class="inn-cal-semana">' + ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'].map(function (d) { return '<span>' + d + '</span>'; }).join('') + '</div><div class="inn-cal-grade">';
      for (var i = 0; i < total; i++) {
        var dia = i - antes + 1, dentro = dia >= 1 && dia <= dias;
        var data = new Date(visto.a, visto.m, dia);
        var ehHoje = data.toDateString() === hoje.toDateString();
        var itens = dentro ? (porDia[dia] || []) : [];
        h += '<div class="inn-cal-dia' + (dentro ? '' : ' fora') + (ehHoje ? ' hoje' : '') + (itens.length ? ' tem' : '') + '"' +
             (dentro ? ' data-dia="' + dia + '" role="button" tabindex="0" aria-label="Ver o dia ' + dia + '"' : '') + '>' +
             '<span class="inn-cal-n" data-inn="1">' + data.getDate() + '</span>' +
             itens.map(function (p) {
               return '<button type="button" class="inn-cal-item" data-id="' + esc(p.id) + '" title="' + esc(p.selo) + '"><i class="inn-cal-ponto ' + p.tom + '" aria-hidden="true"></i>' +
                      '<b>' + esc(p.cli) + '</b><small>' + esc(p.num) + '</small></button>';
             }).join('') + '</div>';
      }
      vCal.innerHTML = h + '</div>';
    }
    function irAoMes(a, m) {
      visto = { a: a, m: m };
      // Outro ano: o quadro carrega esse ano (o calendário lê os cartões dele) e o calendário se redesenha.
      if (String(a) !== ano.value && [].some.call(ano.options, function (o) { return o.value === String(a); })) {
        ano.value = String(a); ano.dispatchEvent(new Event('change', { bubbles: true }));
      }
      desenhar();
    }
    vCal.addEventListener('click', function (e) {
      var n = e.target.closest('.inn-cal-nav');
      if (n) { var m = visto.m + (+n.dataset.passo), a = visto.a; if (m < 0) { m = 11; a--; } if (m > 11) { m = 0; a++; } irAoMes(a, m); return; }
      var it = e.target.closest('.inn-cal-item');
      if (it) { abrirFicha(it.dataset.id); return; }
      var d = e.target.closest('.inn-cal-dia[data-dia]');
      if (d) abrirDia(+d.dataset.dia);
    });
    vCal.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.inn-cal-dia[data-dia]')) { e.preventDefault(); abrirDia(+e.target.dataset.dia); }
    });
    function abrirFicha(id) { var ed = quadro.querySelector('.proc-card[data-id="' + id + '"] .editBtn'); if (ed) ed.click(); }

    // ── A janela do dia ("clico no dia e aparece tudo o que eu tenho nele; escolho qual e abre") ──
    // Tudo o que vence no dia, um cartão por processo (cliente, número, estado, setor e vara);
    // clicar num cartão abre a ficha dele por cima (a janela fica embaixo e volta ao fechar a ficha).
    var SEMANA = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
    var fundoDia = el('div', 'inn-dia-overlay'); fundoDia.hidden = true;
    fundoDia.setAttribute('role', 'dialog'); fundoDia.setAttribute('aria-modal', 'true');
    document.body.appendChild(fundoDia);
    var focoAntes = null;
    function abrirDia(dia) {
      var data = new Date(visto.a, visto.m, dia);
      var ps = processos().filter(function (p) { return p.a === visto.a && p.m === visto.m && p.d === dia; });
      var titulo = SEMANA[data.getDay()] + ', ' + dia + ' de ' + MESES[visto.m] + ' de ' + visto.a;
      fundoDia.setAttribute('aria-label', titulo);
      fundoDia.innerHTML = '<div class="inn-dia-janela">' +
        '<div class="inn-dia-topo"><span class="inn-dia-ic" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + ICONE.calendario + '</svg></span>' +
        '<div class="inn-dia-titulo"><b data-inn="1">' + esc(titulo) + '</b><span>' + (ps.length ? ps.length + (ps.length === 1 ? ' prazo neste dia' : ' prazos neste dia') + '; clique para abrir' : 'nenhum prazo neste dia') + '</span></div>' +
        '<button type="button" class="inn-dia-fechar" aria-label="Fechar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>' +
        '<div class="inn-dia-lista">' + (ps.length ? ps.map(function (p) {
          return '<button type="button" class="inn-dia-item" data-id="' + esc(p.id) + '">' +
            '<span class="inn-dia-linha"><small>' + esc(p.num) + '</small>' + (p.selo ? '<em class="inn-dia-selo ' + p.tom + '">' + esc(p.selo) + '</em>' : '') + '</span>' +
            '<b>' + esc(p.cli) + '</b>' + p.info.map(function (x) { return '<span class="inn-dia-info">' + esc(x) + '</span>'; }).join('') +
            '<span class="inn-dia-abrir">Abrir a ficha<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>' +
            '</button>';
        }).join('') : '<div class="inn-dia-vazio">Nada vence neste dia.</div>') + '</div></div>';
      focoAntes = document.activeElement;
      fundoDia.hidden = false;
      setTimeout(function () { var b = fundoDia.querySelector('.inn-dia-item, .inn-dia-fechar'); if (b) b.focus({ preventScroll: true }); }, 60);
    }
    function fecharDia() { fundoDia.hidden = true; if (focoAntes && focoAntes.focus) focoAntes.focus({ preventScroll: true }); }
    fundoDia.addEventListener('click', function (e) {
      if (e.target === fundoDia || e.target.closest('.inn-dia-fechar')) { fecharDia(); return; }
      var it = e.target.closest('.inn-dia-item'); if (it) abrirFicha(it.dataset.id);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !fundoDia.hidden && !document.querySelector('#modalProcess:not(.hidden)')) fecharDia(); });
    // O quadro se refaz (ano, filtro, cadastro): o calendário acompanha.
    var pedido = null, primeiraVez = true;
    new MutationObserver(function () {
      clearTimeout(pedido);
      pedido = setTimeout(function () {
        marcarMesAtual();
        if (vista === 'calendario') desenhar();
        // Na primeira vez que o quadro chega, ele já abre no mês atual.
        if (primeiraVez && quadro.querySelector('.proc-col')) { primeiraVez = false; if (vista === 'quadro') rolarAoMes(hoje.getMonth(), false); }
      }, 120);
    }).observe(quadro, { childList: true, subtree: true });

    atual.addEventListener('click', function () {
      var a = hoje.getFullYear(), m = hoje.getMonth();
      if (vista === 'calendario') { irAoMes(a, m); return; }
      if (ano.value !== String(a)) { ano.value = String(a); ano.dispatchEvent(new Event('change', { bubbles: true })); primeiraVez = true; return; }
      rolarAoMes(m, true);
    });

    mostrar(vista);
  }

  function ligar() { vistasDeProcessos(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ligar); else ligar();
})();
