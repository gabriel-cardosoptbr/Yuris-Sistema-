/* ═══════════════════════════════════════════════════════════════════════════
   interface-nova-movimento.js: o movimento das telas de dentro na prévia
   (08/11/2026). Par do interface-nova-movimento.css.

   Liga html.inn-mov (fora de prefers-reduced-motion e nunca no modo embutido,
   ?embed=1, onde a tela está dentro da home) e faz a tela entrar por partes,
   como a home: os blocos sobem (isso é CSS puro, por nth-child, para não piscar
   antes do script), os ícones crescem, os números contam de zero e os itens
   (cartões, colunas, linhas) entram um a um. O que a tela desenha depois por
   JavaScript (o quadro de Tarefas, a lista do Chat) também entra assim, nos
   primeiros segundos e logo depois de um clique (trocar filtro, abrir quadro);
   fora disso, o que o servidor repõe sozinho (o Chat a cada 4 s) não se mexe.
   E leva a página embora antes de navegar pelos links do menu lateral.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var html = document.documentElement;
  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  // No modo embutido (?embed=1, a tela aberta dentro da home ou da Central) a tela de trás fica
  // parada, mas o pop-up que aparece ali ganha a entrada dele (09/11/2026, "tá faltando as
  // animações": a ficha do processo aberta pela home surgia sem movimento). inn-mov-pop liga só
  // a entrada dos pop-ups; inn-mov, o resto.
  var embutido = html.classList.contains('yuris-embed') || /[?&]embed=1(&|$)/.test(location.search);
  html.classList.add('inn-mov-pop');
  if (!embutido) html.classList.add('inn-mov');

  // ── O que é item: o que entra um a um dentro do bloco dele ──────────────
  var SEL_ITEM = '.tk-col, .tk-card, .tk-list-row, .proc-col, .proc-card, .cli-card, .kpi-card, .chat-kpi, .chat-item, '
               + '.history-item, .lead-card, .kanban-card, .int-card, .intim-card, .pub-card, .ff-card, tbody > tr';
  var inicio = performance.now(), ultimoClique = -1e9;
  document.addEventListener('pointerdown', function () { ultimoClique = performance.now(); }, true);
  function podeAnimar() { var n = performance.now(); return n - inicio < 5000 || n - ultimoClique < 1500; }

  // O menu e a barra do celular nunca entram nem crescem: em tela cujo <main> embrulha o próprio
  // menu (Webhooks), os ícones do menu ganhavam inn-cresce, que só anima dentro da tela, e ficavam
  // invisíveis para sempre (09/11/2026, revisão do sistema).
  var FORA = '.sidebar, .mobile-tabbar, .inn-emb';
  function NADA() {}
  // À vista: algum pedaço dentro da janela. O que está fora (abaixo da dobra, nas colunas à direita
  // do quadro) não se vê entrando, e pôr a entrada em tudo fazia o navegador recalcular o estilo de
  // centenas de cartões no quadro em que a tela aparece ("travando na troca de aba", 10/11/2026).
  function naTela(r) { return r.width > 0 && r.bottom > 0 && r.right > 0 && r.top < innerHeight && r.left < innerWidth; }
  // Recebe uma raiz ou uma lista delas (o observador junta as da mesma leva).
  function raizesDe(r) { return (Array.isArray(r) ? r : [r]).filter(function (x) { return x && x.querySelectorAll; }); }
  // As três abaixo LEEM e devolvem a função que ESCREVE: quem chama lê as três e só depois escreve,
  // e o navegador recalcula o estilo uma vez por leva, não uma por função (09/11/2026).
  function entrarItens(raiz) {
    var itens = [];
    raizesDe(raiz).forEach(function (rz) {
      if (rz.matches && rz.matches(SEL_ITEM)) itens.push(rz);
      [].push.apply(itens, rz.querySelectorAll(SEL_ITEM));
    });
    if (!itens.length) return NADA;
    itens = Array.from(new Set(itens));
    var porPai = new Map();
    // Primeiro lê tudo, depois escreve: ler o estilo de um item logo depois de mudar a classe do
    // anterior obrigava o navegador a recalcular a tela inteira a cada item (09/11/2026).
    itens = itens.filter(function (el) { return !el.dataset.inn && !el.closest(FORA); });
    // Quem já tem transform próprio (a coluna do quadro gira em rotateX para a barra
    // ficar em cima) não entra: a animação sobrescreveria o giro e o texto viraria de cabeça para baixo.
    var giram = itens.map(function (el) { return getComputedStyle(el).transform !== 'none'; });
    var vistos = itens.map(function (el) { return naTela(el.getBoundingClientRect()); });
    return function () {
      itens.forEach(function (el, i) {
        el.dataset.inn = '1';
        if (giram[i] || !vistos[i]) return;
        var k = porPai.get(el.parentNode) || 0;
        porPai.set(el.parentNode, k + 1);
        if (k < 14) { el.style.setProperty('--mov-i', k); el.classList.add('inn-item'); }
      });
    };
  }
  // Os ícones crescem: todo svg pequeno (até 56px) da tela, na ordem em que aparece.
  function crescerIcones(raiz) {
    if (!raiz.querySelectorAll) return NADA;
    var svgs = [].filter.call(raiz.querySelectorAll('svg'), function (s) { return !s.dataset.inn && !s.closest(FORA); });
    var medidas = svgs.map(function (s) { return s.getBoundingClientRect(); });
    return function () {
      var k = 0;
      svgs.forEach(function (s, i) {
        var r = medidas[i];
        if (k >= 40 || r.width < 10 || r.width > 56 || r.height > 56 || !naTela(r)) return;
        s.dataset.inn = '1'; s.style.setProperty('--mov-i', k++); s.classList.add('inn-cresce');
      });
    };
  }
  // Os números contam de zero: todo elemento folha que é só um número inteiro.
  // O número zera no mesmo instante em que chega (09/11/2026: antes ele aparecia pronto, sumia
  // para zero 160 ms depois e subia, "quando abre a aba dá problema").
  // A contagem é desenhada por cima (data-inn-conta e ::before), e o texto de verdade do número nunca
  // muda: tela que relê o indicador (o resumo de Configurações lia "0 usuários" no meio da contagem,
  // 09/11/2026) recebe sempre o valor certo. O texto real fica transparente enquanto conta, e é ele que
  // dá a largura, então nada pula. Se a tela trocar o valor no meio, a contagem para.
  function contar(el, dur, alvo) {
    if (!(alvo > 0) || String(parseInt(el.textContent, 10)) !== String(alvo)) { fimConta(el); return; }
    var t0 = null;
    function passo(t) {
      if (String(parseInt(el.textContent, 10)) !== String(alvo)) { fimConta(el); return; }
      if (t0 === null) t0 = t;
      var p = Math.min(1, (t - t0) / dur); p = 1 - Math.pow(1 - p, 3);
      el.dataset.innConta = String(Math.round(alvo * p));
      if (p < 1) requestAnimationFrame(passo);
      else { fimConta(el); el.classList.add('inn-contou'); el.addEventListener('animationend', function f() { el.classList.remove('inn-contou'); el.removeEventListener('animationend', f); }); }
    }
    requestAnimationFrame(passo);
  }
  function fimConta(el) { el.classList.remove('inn-contando'); delete el.dataset.innConta; el.style.removeProperty('--inn-conta-cor'); }
  // Primeiro escolhe e lê (tamanho da letra, cor), depois marca: ler o estilo de um número logo
  // depois de marcar o anterior recalculava a tela inteira a cada um (Tarefas, 09/11/2026).
  function contarNumeros(raiz) {
    var achados = [], vistos = new Set();
    raizesDe(raiz).forEach(function (rz) {
      var todos = [].slice.call(rz.querySelectorAll('*'));
      if (rz.nodeType === 1) todos.unshift(rz);
      for (var i = 0; i < todos.length && achados.length < 40; i++) {
        var el = todos[i];
        if (vistos.has(el) || el.children.length || el.dataset.inn || /^(INPUT|TEXTAREA|SELECT|OPTION|SCRIPT|STYLE|TITLE)$/.test(el.tagName)) continue;
        // Quem ainda está vazio ou em zero não é marcado: o contador das abas e das colunas
        // é preenchido depois, trocando só o texto, e aí conta. (O texto vem antes do closest, que é caro.)
        if (!/^\s*\d{1,6}\s*$/.test(el.textContent) || !(parseInt(el.textContent, 10) > 0) || el.closest(FORA)) continue;
        vistos.add(el); achados.push(el);
      }
    });
    if (!achados.length) return NADA;
    var estilos = achados.map(function (el) { var cs = getComputedStyle(el); return { tam: parseFloat(cs.fontSize), cor: cs.color, visto: naTela(el.getBoundingClientRect()) }; });
    return function () {
      var k = 0;
      achados.forEach(function (el, i) {
        if (estilos[i].tam < 10 || !estilos[i].visto || el.dataset.inn) return;
        el.dataset.inn = '1'; k++;
        var alvo = parseInt(el.textContent, 10);
        // Zera na hora, só no desenho: o texto real continua o número.
        el.style.setProperty('--inn-conta-cor', estilos[i].cor);
        el.dataset.innConta = '0'; el.classList.add('inn-contando');
        setTimeout(contar.bind(null, el, 800, alvo), 160 + k * 40);
      });
    };
  }

  function ligar() {
    // A tela: .main-content, ou o main ao lado do menu (Clientes, Webhooks, Chat interno).
    var main = document.querySelector('.main-content') || [].filter.call(document.querySelectorAll('main'), function (m) { return !m.querySelector('.sidebar'); })[0] || document.body;
    if (main !== document.body && !main.classList.contains('main-content')) main.classList.add('inn-tela-entra');
    [entrarItens(main), crescerIcones(main), contarNumeros(main)].forEach(function (escreve) { escreve(); });
    // O que a tela desenha entra uma vez por quadro, antes da pintura, e DEPOIS de a camada do
    // sistema vestir o mesmo pedaço (o quadro dela foi pedido antes, então roda antes): ela mede os
    // botões e as caixas no tamanho de verdade, não no começo da entrada, quando o cartão ainda está
    // 4% menor (o botão de 22 px do cartão de Tarefas media 21 e perdia o desenho de botão de ícone).
    // A leva inteira de uma vez: a lista que chega em dez pedaços era lida e marcada dez vezes.
    var filaNovos = [], filaNumeros = [], pedido = false;
    function entrarLeva() {
      pedido = false;
      var novos = filaNovos.filter(function (n) { return n.isConnected; });
      var numeros = filaNumeros.filter(function (n) { return n.isConnected; });
      filaNovos = []; filaNumeros = [];
      var escreveItens = novos.length ? entrarItens(novos) : NADA;
      var escreveNumeros = numeros.length ? contarNumeros(numeros) : NADA;
      escreveItens(); escreveNumeros();
    }
    new MutationObserver(function (ms) {
      if (!podeAnimar()) return;
      ms.forEach(function (m) {
        if (m.type === 'characterData') { var p = m.target.parentElement; if (p && main.contains(p)) filaNumeros.push(p); return; }
        [].forEach.call(m.addedNodes, function (n) {
          if (n.nodeType === 1) { filaNovos.push(n); filaNumeros.push(n); }
          else if (n.nodeType === 3 && n.parentElement) filaNumeros.push(n.parentElement);
        });
      });
      if (!pedido && (filaNovos.length || filaNumeros.length)) { pedido = true; requestAnimationFrame(entrarLeva); }
    }).observe(main, { childList: true, subtree: true, characterData: true });
  }
  if (!embutido) { if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ligar); else ligar(); }


  // ── O pop-up entra como o da home ───────────────────────────────────────
  // "Todos os pop-ups têm que ter animação de entrada: as barras vindo, as letras nascendo, o
  // que a gente colocou lá na entrada" (08/11/2026). Ao abrir, o painel surge, o ícone do
  // cabeçalho cresce e as linhas (cabeçalho, seções, linhas de campos, rodapé) entram uma a
  // uma, de cima para baixo, como o pop-up da home (idt-entra). Detectado no observador, antes
  // de o navegador pintar: o pop-up nunca aparece cheio e depois some para entrar.
  var SEL_POP = '[class*="modal"], [id*="modal"], [id*="Modal"], [role="dialog"], dialog, [class*="drawer"], [class*="popup"], [class*="popover"], [class*="overlay"], [id*="verlay"]';
  // Aberto é estar no layout e visível; a opacidade não conta (o fundo da Nova Tarefa aparece
  // com transição de opacidade, e no instante da checagem ainda está em zero).
  function vis(el) {
    if (!el.offsetWidth && !el.offsetHeight) return false;
    var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden' && cs.pointerEvents !== 'none';
  }
  function ehFundo(el) {
    if (el.tagName === 'DIALOG') return el.open;
    var r = el.getBoundingClientRect(); if (r.width < innerWidth * .9 || r.height < innerHeight * .9) return false;
    for (var n = el, i = 0; i < 3 && n && n !== document.body; i++, n = n.parentElement) if (getComputedStyle(n).position === 'fixed') return true;
    return false;
  }
  function painelDe(fundo) {
    if (fundo.tagName === 'DIALOG') return fundo;
    var fr = fundo.getBoundingClientRect(), melhor = null, area = 0;
    [].forEach.call(fundo.querySelectorAll('*'), function (el) {
      if (melhor && melhor.contains(el)) return;
      var r = el.getBoundingClientRect();
      if (r.width < 240 || r.height < 120 || (r.width >= fr.width * .95 && r.height >= fr.height * .95)) return;
      var a = r.width * r.height; if (a > area) { melhor = el; area = a; }
    });
    return melhor;
  }
  // As linhas: desce pelos embrulhos grandes (corpo com rolagem, formulário, seção) até chegar
  // nas peças de uma linha só; para em 40.
  // O embrulho que é um CARTÃO (fundo, moldura ou sombra própria, como a seção "Identificação do
  // processo") também entra, logo antes do que tem dentro: é o cartão que vem vindo e se enche,
  // como os cartões da Intimação na home. Antes só o de dentro entrava, e o cartão aparecia pronto
  // e vazio junto com o painel ("os cards aparecem tudo de uma vez", 10/11/2026).
  function corDe(c) { var m = /rgba?\(([^)]+)\)/.exec(c || ''); if (!m) return 0; var p = m[1].split(','); return p.length > 3 ? parseFloat(p[3]) : 1; }
  function ehCartao(f, r, cs, fundoPai, pr) {
    if (r.width >= pr.width * .98 && r.height >= pr.height * .7) return false;      // o corpo inteiro
    if (/(auto|scroll)/.test(cs.overflowY) && f.scrollHeight > f.clientHeight + 4 && r.height > pr.height * .5) return false; // o corpo que rola
    if (parseFloat(cs.borderTopWidth) > 0 && parseFloat(cs.borderLeftWidth) > 0 && corDe(cs.borderTopColor) > .04) return true;
    if (cs.boxShadow && cs.boxShadow !== 'none') return true;
    return corDe(cs.backgroundColor) > .04 && cs.backgroundColor !== fundoPai;
  }
  // ref: o pop-up de fora, quando a descida começa num pedaço dele (o que chegou depois): o "corpo
  // inteiro" e o "corpo que rola" são medidos contra o pop-up, não contra o pedaço.
  function linhasDe(painel, ref) {
    var out = [], ph = painel.getBoundingClientRect().height, pr = (ref || painel).getBoundingClientRect();
    (function desce(el, nivel, fundoPai) {
      [].forEach.call(el.children, function (f) {
        if (out.length >= 40 || /^(SCRIPT|STYLE|TEMPLATE|INPUT|OPTION)$/.test(f.tagName)) return;
        var r = f.getBoundingClientRect(); if (r.width < 8 || r.height < 6) return;
        // Embrulho grande (corpo com rolagem, formulário, seção, grade de campos): desce, mesmo
        // quando é um embrulho só (o corpo do Editar Processo embrulha o formulário inteiro).
        if (nivel < 5 && f.children.length >= 1 && r.height > Math.min(120, ph * .25) && !/^(BUTTON|A|LABEL|SELECT|TEXTAREA|TABLE|UL|OL|svg)$/i.test(f.tagName)) {
          var cs = getComputedStyle(f), fundo = corDe(cs.backgroundColor) > .04 ? cs.backgroundColor : fundoPai;
          if (ehCartao(f, r, cs, fundoPai, pr)) out.push(f);
          desce(f, nivel + 1, fundo); return;
        }
        out.push(f);
      });
    })(painel, 0, getComputedStyle(painel).backgroundColor);
    return out;
  }
  // Põe as peças para entrar, uma depois da outra, com o passo da Intimação da home: a vez k começa
  // 90 ms + k × 38 ms depois de o pop-up abrir. Só o que está à vista ganha vez (o que está abaixo
  // da dobra não atrasa quem vem depois), e a última vez é a 28: a entrada inteira dura pouco mais
  // de um segundo. A ordem é a da posição, de cima para baixo e, na mesma altura, da esquerda para
  // a direita (como Tribunal e Disponibilizada na Intimação): pela ordem do código, o rodapé de
  // alguns pop-ups entrava antes do corpo.
  // A fila (reg) guarda a hora de começar de cada peça. Quem chega depois (abaixo) entra na ordem:
  // as peças que ainda não começaram e ficam abaixo dela passam para depois dela (a animação que
  // ainda está esperando aceita a espera nova). Lê tudo antes de escrever, e recomeça quem já
  // estava entrando com uma passada de layout só (antes era uma por peça, ao abrir o pop-up).
  function fimEntra(e) { if (e.target === this && e.animationName === 'inn-pop-entra') { this.classList.remove('inn-pop-entra'); this.removeEventListener('animationend', fimEntra); } }
  var PASSO = 38, ESPERA = 90, ULTIMA = 28;
  function novaFila(painel) { var t = performance.now(); return { painel: painel, t0: t, ate: t + 1200, fila: [], ultimo: t + ESPERA - PASSO }; }
  function entrarLinhas(lista, painel, extra, reg) {
    reg = reg || novaFila(painel);
    var agora = performance.now();
    var pr = painel.getBoundingClientRect(), baixo = Math.min(innerHeight, pr.bottom);
    var vai = [];
    Array.from(new Set(lista)).forEach(function (el) {
      var r = el.getBoundingClientRect();
      if (r.top >= baixo || (!r.width && !r.height)) return;
      // Quem tem transform próprio não entra (a animação o desfaria); o transform da entrada não conta.
      if (el.classList.contains('inn-pop-entra') || getComputedStyle(el).transform === 'none') vai.push({ el: el, y: Math.round(r.top / 4), x: r.left, novo: true });
    });
    if (!vai.length) return 0;
    // As que ainda esperam a vez e ficam abaixo da primeira que chega: entram de novo na ordem.
    var topo = Math.min.apply(null, vai.map(function (v) { return v.y; }));
    var esperam = [];
    reg.fila = reg.fila.filter(function (p) {
      if (p.ini <= agora + 8 || !p.el.isConnected || !p.el.classList.contains('inn-pop-entra') || vai.some(function (v) { return v.el === p.el; })) return p.ini > agora;
      var r = p.el.getBoundingClientRect(), y = Math.round((r.top - 10) / 4);   // 10: ela espera 10 px abaixo
      if (y < topo) return true;
      esperam.push({ el: p.el, y: y, x: r.left, tA: p.tA, ini: p.ini }); return false;
    });
    var todas = vai.concat(esperam).sort(function (a, b) { return a.y - b.y || a.x - b.x; });
    var comeco = Math.max(agora, reg.ultimo + PASSO);
    if (esperam.length) comeco = Math.max(agora, Math.min(comeco, Math.min.apply(null, esperam.map(function (p) { return p.ini; }))));
    var limite = Math.max(agora, reg.t0 + ESPERA + ULTIMA * PASSO);
    var recomeca = vai.filter(function (v) { return v.el.classList.contains('inn-pop-entra'); });
    if (recomeca.length || extra) {
      recomeca.forEach(function (v) { v.el.classList.remove('inn-pop-entra'); });
      if (extra) extra();
      void painel.offsetWidth;
    }
    todas.forEach(function (v, k) {
      var ini = Math.min(comeco + k * PASSO, limite), tA = v.novo ? agora : v.tA;
      v.el.style.setProperty('--mov-i', ((ini - tA - ESPERA) / PASSO).toFixed(2));
      if (v.novo) { v.el.classList.add('inn-pop-entra'); v.el.addEventListener('animationend', fimEntra); }
      reg.fila.push({ el: v.el, tA: tA, ini: ini });
      if (ini > reg.ultimo) reg.ultimo = ini;
    });
    return vai.length;
  }
  // soLinhas: o painel já está no lugar (o pop-up da home acabou de ser mostrado); só o conteúdo entra.
  function entrarPopup(fundo, soLinhas) {
    var painel = painelDe(fundo); if (!painel) return;
    var surge = !soLinhas && getComputedStyle(painel).animationName === 'none';
    var ic = painel.querySelector('.inn-cab-ic, .inn-pop-ic');
    var reg = novaFila(painel);
    entrarLinhas(linhasDe(painel), painel, function () {
      if (surge) painel.classList.remove('inn-pop-surge');
      if (ic) ic.style.animation = 'none';
    }, reg);
    recentes = recentes.filter(function (r) { return r.painel !== painel; });
    recentes.push(reg);
    if (ic) ic.style.animation = '';
    if (surge) {
      painel.classList.add('inn-pop-surge');
      painel.addEventListener('animationend', function f(e) { if (e.target === painel) { painel.classList.remove('inn-pop-surge'); painel.removeEventListener('animationend', f); } });
    }
  }
  // ── O que chega depois de o pop-up abrir continua a fila ──────────────────
  // Muito pop-up abre vazio e é preenchido logo depois (o Nova Conversa do Chat interno desenha os
  // tipos e as pessoas depois de aberto; a ficha busca a lista no servidor; o campo aparece quando
  // se escolhe o tipo). Isso surgia pronto no meio da entrada (10/11/2026). Durante 1,2 s depois de
  // abrir, o que é inserido no pop-up, ou deixa de estar escondido (display:none, hidden, classe
  // hidden), entra na vez seguinte da fila: na hora em que entraria se já estivesse lá, ou já, se
  // essa hora passou.
  var recentes = [];
  var RE_ESCONDE = /(^|\s)(hidden|d-none|oculto|escondido|is-hidden)(\s|$)/;
  function apareceu(m) {
    var el = m.target;
    if (m.attributeName === 'style') return /display\s*:\s*none/.test(m.oldValue || '') && el.style.display !== 'none';
    if (m.attributeName === 'hidden') return m.oldValue !== null && !el.hidden;
    if (m.attributeName === 'class') return RE_ESCONDE.test(m.oldValue || '') && !RE_ESCONDE.test(el.className);
    return false;
  }
  function tardios(ms, vivos) {
    var achados = [];
    function guarda(el) {
      if (el.nodeType !== 1 || /^(SCRIPT|STYLE|TEMPLATE|OPTION|BR)$/.test(el.tagName)) return;
      for (var i = 0; i < vivos.length; i++) if (vivos[i].painel !== el && vivos[i].painel.contains(el)) { achados.push({ el: el, reg: vivos[i] }); return; }
    }
    ms.forEach(function (m) {
      if (m.type === 'childList') [].forEach.call(m.addedNodes, guarda);
      else if (m.target.nodeType === 1 && apareceu(m)) guarda(m.target);
    });
    return achados;
  }
  function entrarTardios(achados) {
    var porReg = new Map();
    achados.forEach(function (a) { if (a.el.isConnected) { var l = porReg.get(a.reg) || []; l.push(a.el); porReg.set(a.reg, l); } });
    porReg.forEach(function (els, reg) {
      // Só o de fora: o que está dentro de outro achado entra com ele.
      els = els.filter(function (el) { return !els.some(function (o) { return o !== el && o.contains(el); }); });
      var linhas = [], pr = reg.painel.getBoundingClientRect();
      els.forEach(function (el) {
        var r = el.getBoundingClientRect(); if (r.width < 8 || r.height < 6) return;
        if (r.height > 120 && el.children.length && !/^(BUTTON|A|LABEL|SELECT|TEXTAREA|TABLE|UL|OL|svg)$/i.test(el.tagName)) {
          var pai = el.parentElement ? getComputedStyle(el.parentElement).backgroundColor : '';
          if (ehCartao(el, r, getComputedStyle(el), pai, pr)) linhas.push(el);
          [].push.apply(linhas, linhasDe(el, reg.painel));
        } else linhas.push(el);
      });
      if (linhas.length) entrarLinhas(linhas, reg.painel, null, reg);
    });
  }
  function tardioDoPopup(el) {
    var agora = performance.now();
    return recentes.some(function (r) { return agora < r.ate && r.painel !== el && r.painel.contains(el); });
  }
  var abertos = new Set();
  function conferirPopups(alvos) {
    alvos.forEach(function (el) {
      if (!el.isConnected || !el.matches || !el.matches(SEL_POP + ', dialog')) return;
      var aberto = vis(el) && ehFundo(el);
      if (aberto && !abertos.has(el)) { abertos.add(el); entrarPopup(el); }
      else if (!aberto && abertos.has(el)) abertos.delete(el);
    });
  }
  new MutationObserver(function (ms) {
    var alvos = new Set();
    // Antes de abrir os novos: o pop-up que abre agora já leva o que está nele.
    if (recentes.length) {
      var agora = performance.now();
      recentes = recentes.filter(function (r) { return agora < r.ate && r.painel.isConnected; });
      var achados = recentes.length ? tardios(ms, recentes) : [];
      if (achados.length) entrarTardios(achados);
    }
    ms.forEach(function (m) {
      if (m.type === 'attributes') { if (m.target.nodeType === 1 && m.target.matches && m.target.matches(SEL_POP + ', dialog')) alvos.add(m.target); return; }
      [].forEach.call(m.addedNodes, function (n) {
        if (n.nodeType !== 1) return;
        if (n.matches && n.matches(SEL_POP + ', dialog')) alvos.add(n);
        else if (n.querySelectorAll) [].forEach.call(n.querySelectorAll(SEL_POP), function (x) { alvos.add(x); });
      });
    });
    if (alvos.size) conferirPopups(alvos);
  }).observe(document.body, { attributes: true, attributeOldValue: true, attributeFilter: ['class', 'style', 'hidden', 'open'], childList: true, subtree: true });
  // Aberto de dentro de outra tela (?embed=1: a home, a Central, o processo aberto por uma tarefa),
  // o pop-up abre escondido e só aparece quando a forma cinza chega até ele, uns 0,7 s depois: a
  // entrada já tinha passado e ele surgia pronto ("no processos os cards aparecem tudo de uma vez",
  // 10/11/2026). Quem mostra avisa (embed-visivel), e o conteúdo entra de novo, à vista.
  if (embutido) window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || e.source !== window.parent || !e.data || e.data.yuris !== 'embed-visivel') return;
    abertos.forEach(function (f) { if (f.isConnected && vis(f)) entrarPopup(f, true); });
  });

  // ── Seleção cheia: começa vazia e se preenche a partir do clique ─────────
  // "Tudo que é seleção cheia do sistema tem que começar vazio e se preencher" (08/11/2026),
  // como o dia do calendário da home. No toque, guarda o ponto e a cor de antes; quando a peça
  // tocada (ou a que está em volta dela) passa a ficar selecionada e com fundo próprio, a cor
  // nova nasce do ponto do clique e cobre a caixa inteira; a cor de antes fica por baixo até lá.
  var RE_LIGADO = /^(active|ativo|ativa|selected|is-active|is-selected|current|on|sel|atual|checked|marcado|escolhido)$|-(active|ativo|ativa|selected|on|sel)$/i;
  var toque = null;
  function corDeFundo(cs) {
    var bi = cs.backgroundImage, m = bi && bi.indexOf('gradient') >= 0 ? bi.match(/rgba?\([^)]+\)/) : null;
    return m ? m[0] : cs.backgroundColor;
  }
  function alfa(c) { var m = /rgba?\(([^)]+)\)/.exec(c || ''); if (!m) return 0; var p = m[1].split(','); return p.length > 3 ? parseFloat(p[3]) : 1; }
  document.addEventListener('pointerdown', function (e) {
    if (e.button > 0 || !e.target.closest) return;
    var el = e.target.closest('button, a, label, li, [role="tab"], [role="option"], [role="button"], [onclick], .inn-aba, td, [class*="dia"], [class*="day"], [class*="card"], [class*="chip"], [class*="opc"], [class*="opt"], [class*="tipo"], [class*="type"]');
    if (!el || el.closest('.sidebar, input, select, textarea')) { toque = null; return; }
    var r = el.getBoundingClientRect(); if (r.width < 14 || r.height < 14 || r.width > 520 || r.height > 260) { toque = null; return; }
    toque = { el: el, t: performance.now(), x: e.clientX, y: e.clientY, antes: corDeFundo(getComputedStyle(el)) };
  }, true);
  // Ligado pelas classes da TELA (as marcas inn-* da camada não contam, inclusive a do
  // próprio enchimento: senão pôr a marca parecia uma seleção nova e o enchimento recomeçava).
  function classesLigadas(cls) {
    return String(cls || '').split(/\s+/).some(function (c) { return c.indexOf('inn-') !== 0 && RE_LIGADO.test(c); });
  }
  function ligado(el) {
    if (el.getAttribute('aria-selected') === 'true' || el.getAttribute('aria-pressed') === 'true' || el.getAttribute('aria-checked') === 'true') return true;
    return classesLigadas(el.className) || el.classList.contains('inn-aba-on');
  }
  function soMarcas(antes, agora) {
    var a = String(antes || '').split(/\s+/).filter(function (c) { return c && c.indexOf('inn-') !== 0; }).sort().join(' ');
    var b = String(agora || '').split(/\s+/).filter(function (c) { return c && c.indexOf('inn-') !== 0; }).sort().join(' ');
    return a === b;
  }
  function encher(el, x, y, antes) {
    var r = el.getBoundingClientRect(); if (!r.width || !r.height) return;
    // A cor de CHEGADA: com transição ligada, o navegador devolve a cor do começo dela
    // (o cartão de tipo do Chat interno lia o cinza de antes). Desliga, lê, devolve.
    var trans = el.style.getPropertyValue('transition'), prio = el.style.getPropertyPriority('transition');
    el.style.setProperty('transition', 'none', 'important');
    var cs = getComputedStyle(el), cor = corDeFundo(cs);
    if (trans) el.style.setProperty('transition', trans, prio); else el.style.removeProperty('transition');
    if (alfa(cor) < .08 || cor === antes) return;                    // não ficou cheio: nada a encher
    var cx = Math.max(0, Math.min(r.width, x - r.left)), cy = Math.max(0, Math.min(r.height, y - r.top));
    el.style.setProperty('--inn-cx', Math.round(cx) + 'px');
    el.style.setProperty('--inn-cy', Math.round(cy) + 'px');
    el.style.setProperty('--inn-raio', Math.ceil(Math.hypot(Math.max(cx, r.width - cx), Math.max(cy, r.height - cy)) + 2) + 'px');
    el.style.setProperty('--inn-enche-cor', cor);
    el.style.setProperty('--inn-enche-antes', alfa(antes) > .02 ? antes : 'transparent');
    el.classList.remove('inn-enchendo'); void el.offsetWidth; el.classList.add('inn-enchendo');
    el.addEventListener('animationend', function f(e) { if (e.target === el && e.animationName === 'inn-enche') { el.classList.remove('inn-enchendo'); el.removeEventListener('animationend', f); } });
  }
  new MutationObserver(function (ms) {
    if (!toque || performance.now() - toque.t > 900) return;
    var feitos = new Set();
    ms.forEach(function (m) {
      var el = m.target; if (el.nodeType !== 1 || feitos.has(el) || el.classList.contains('inn-enchendo')) return;
      if (!(el === toque.el || el.contains(toque.el) || toque.el.contains(el))) return;
      if (el.closest('.sidebar') || !ligado(el)) return;
      // Só a passagem de "não escolhido" para "escolhido", e só por mudança da tela.
      if (m.attributeName === 'class') {
        if (soMarcas(m.oldValue, el.className) && !(el.classList.contains('inn-aba-on') && !/(^|\s)inn-aba-on(\s|$)/.test(m.oldValue || ''))) return;
        if (classesLigadas(m.oldValue) || /(^|\s)inn-aba-on(\s|$)/.test(m.oldValue || '')) return;
      } else if (m.oldValue === 'true') return;
      var r = el.getBoundingClientRect(); if (r.width > 520 || r.height > 260) return;
      feitos.add(el); encher(el, toque.x, toque.y, el === toque.el ? toque.antes : 'transparent');
    });
  }).observe(document.body, { attributes: true, attributeOldValue: true, attributeFilter: ['class', 'aria-selected', 'aria-pressed', 'aria-checked'], subtree: true });


  // ── O conteúdo da aba entra, e a lista que chega também ─────────────────
  // "Animação em todo canto: cliquei em Vínculos, tem que ter uma animaçãozinha do que está
  // vindo" (08/11/2026). Logo depois de um clique em aba (ou em qualquer botão), o painel que
  // passa a ficar ativo entra linha por linha, e os itens que a tela desenha de novo (a busca
  // de vínculos, a lista filtrada) entram um a um. Só nesse instante: o que a tela repõe
  // sozinha depois não se mexe.
  // Começa no passado distante: em zero, os primeiros 900 ms de toda tela contavam como "logo depois
  // de um clique" (o relógio da página também começa em zero) e o observador media a posição de cada
  // pedaço que a tela montava, em plena carga (09/11/2026, "tá travando na troca de aba").
  var cliqueRecente = -1e9;
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('button, a, [role="tab"], .inn-aba, [onclick], label')) cliqueRecente = performance.now();
  }, true);
  function entrarEmFila(lista, caixa) {
    if (lista.length && caixa && caixa.getBoundingClientRect) entrarLinhas(lista, caixa);
  }
  new MutationObserver(function (ms) {
    if (performance.now() - cliqueRecente > 900) return;
    var porPai = new Map(), paineis = [];
    ms.forEach(function (m) {
      var el = m.target; if (el.nodeType !== 1 || el.closest('.sidebar')) return;
      if (m.type === 'attributes') {
        // O painel que passou a ficar ativo (tk-tab-pane.active), grande e não um botão.
        if (m.attributeName !== 'class' || classesLigadas(m.oldValue) || !classesLigadas(el.className)) return;
        if (el.closest('.inn-pop-entra')) return;
        if (/^(BUTTON|A|LABEL|INPUT|SELECT|TEXTAREA|LI)$/.test(el.tagName) || el.classList.contains('inn-aba')) return;
        var r = el.getBoundingClientRect(); if (r.height < 60 || r.width < 160) return;
        paineis.push(el); return;
      }
      [].forEach.call(m.addedNodes, function (n) {
        if (n.nodeType !== 1 || /^(SCRIPT|STYLE|OPTION)$/.test(n.tagName) || n.closest('.sidebar, .inn-emb, .inn-pop-entra') || tardioDoPopup(n)) return;
        var r = n.getBoundingClientRect(); if (r.height < 14 || r.width < 40) return;
        var arr = porPai.get(n.parentNode) || []; arr.push(n); porPai.set(n.parentNode, arr);
      });
    });
    paineis.forEach(function (p) { entrarEmFila(linhasDe(p), p); });
    porPai.forEach(function (arr, pai) { if (!paineis.some(function (p) { return p.contains(arr[0]); })) entrarEmFila(arr, pai); });
  }).observe(document.body, { attributes: true, attributeOldValue: true, attributeFilter: ['class'], childList: true, subtree: true });

  // ── Sair pelo menu ──────────────────────────────────────────────────────
  // Desde 08/11/2026 é o menu-fixo.js: o item enche, os blocos da tela fecham e o menu fica
  // parado na troca de página (view transition entre documentos). Nada aqui.
})();
