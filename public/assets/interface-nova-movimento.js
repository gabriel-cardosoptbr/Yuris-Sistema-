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
  if (html.classList.contains('yuris-embed')) return;
  html.classList.add('inn-mov');

  // ── O que é item: o que entra um a um dentro do bloco dele ──────────────
  var SEL_ITEM = '.tk-col, .tk-card, .tk-list-row, .proc-col, .proc-card, .cli-card, .kpi-card, .chat-kpi, .chat-item, '
               + '.history-item, .lead-card, .kanban-card, .int-card, .intim-card, .pub-card, .ff-card, tbody > tr';
  var inicio = performance.now(), ultimoClique = -1e9;
  document.addEventListener('pointerdown', function () { ultimoClique = performance.now(); }, true);
  function podeAnimar() { var n = performance.now(); return n - inicio < 5000 || n - ultimoClique < 1500; }

  function entrarItens(raiz) {
    if (!raiz.querySelectorAll) return;
    var itens = (raiz.matches && raiz.matches(SEL_ITEM) ? [raiz] : []).concat([].slice.call(raiz.querySelectorAll(SEL_ITEM)));
    var porPai = new Map();
    itens.forEach(function (el) {
      if (el.dataset.inn) return;
      el.dataset.inn = '1';
      // Quem já tem transform próprio (a coluna do quadro gira em rotateX para a barra
      // ficar em cima) não entra: a animação sobrescreveria o giro e o texto viraria de cabeça para baixo.
      if (getComputedStyle(el).transform !== 'none') return;
      var k = porPai.get(el.parentNode) || 0;
      porPai.set(el.parentNode, k + 1);
      if (k < 14) { el.style.setProperty('--mov-i', k); el.classList.add('inn-item'); }
    });
  }
  // Os ícones crescem: todo svg pequeno (até 56px) da tela, na ordem em que aparece.
  function crescerIcones(raiz) {
    if (!raiz.querySelectorAll) return;
    var k = 0;
    [].forEach.call(raiz.querySelectorAll('svg'), function (s) {
      if (k >= 40 || s.dataset.inn) return;
      var r = s.getBoundingClientRect();
      if (r.width < 10 || r.width > 56 || r.height > 56) return;
      s.dataset.inn = '1'; s.style.setProperty('--mov-i', k++); s.classList.add('inn-cresce');
    });
  }
  // Os números contam de zero: todo elemento folha que é só um número inteiro.
  // O número zera no mesmo instante em que chega (09/11/2026: antes ele aparecia pronto, sumia
  // para zero 160 ms depois e subia, "quando abre a aba dá problema"). Se a tela trocar o
  // valor no meio da contagem, ela para e o valor da tela fica.
  function contar(el, dur, alvo) {
    if (alvo == null) alvo = parseInt(el.textContent, 10);
    else if (el.textContent !== '0') return; // a tela já escreveu outro valor por cima do zero
    if (!(alvo > 0)) return;
    var t0 = null, escrito = el.textContent;
    function passo(t) {
      if (el.textContent !== escrito) return;
      if (t0 === null) t0 = t;
      var p = Math.min(1, (t - t0) / dur); p = 1 - Math.pow(1 - p, 3);
      el.textContent = escrito = String(Math.round(alvo * p));
      if (p < 1) requestAnimationFrame(passo);
      else { el.classList.add('inn-contou'); el.addEventListener('animationend', function f() { el.classList.remove('inn-contou'); el.removeEventListener('animationend', f); }); }
    }
    requestAnimationFrame(passo);
  }
  function contarNumeros(raiz) {
    if (!raiz.querySelectorAll) return;
    var k = 0, todos = [].slice.call(raiz.querySelectorAll('*'));
    if (raiz.nodeType === 1) todos.unshift(raiz);
    for (var i = 0; i < todos.length && k < 40; i++) {
      var el = todos[i];
      if (el.children.length || el.dataset.inn || /^(INPUT|TEXTAREA|SELECT|OPTION|SCRIPT|STYLE|TITLE)$/.test(el.tagName)) continue;
      // Quem ainda está vazio ou em zero não é marcado: o contador das abas e das colunas
      // é preenchido depois, trocando só o texto, e aí conta.
      if (!/^\s*\d{1,6}\s*$/.test(el.textContent) || !(parseInt(el.textContent, 10) > 0)) continue;
      if (parseFloat(getComputedStyle(el).fontSize) < 10) continue;
      el.dataset.inn = '1'; k++;
      var alvo = parseInt(el.textContent, 10);
      el.textContent = '0';
      setTimeout(contar.bind(null, el, 800, alvo), 160 + k * 40);
    }
  }

  function ligar() {
    // A tela: .main-content, ou o main ao lado do menu (Clientes, Webhooks, Chat interno).
    var main = document.querySelector('.main-content') || [].filter.call(document.querySelectorAll('main'), function (m) { return !m.querySelector('.sidebar'); })[0] || document.body;
    if (main !== document.body && !main.classList.contains('main-content')) main.classList.add('inn-tela-entra');
    entrarItens(main); crescerIcones(main); contarNumeros(main);
    new MutationObserver(function (ms) {
      if (!podeAnimar()) return;
      ms.forEach(function (m) {
        if (m.type === 'characterData') { var p = m.target.parentElement; if (p && main.contains(p)) contarNumeros(p); return; }
        [].forEach.call(m.addedNodes, function (n) {
          if (n.nodeType === 1) { entrarItens(n); contarNumeros(n); }
          else if (n.nodeType === 3 && n.parentElement) contarNumeros(n.parentElement);
        });
      });
    }).observe(main, { childList: true, subtree: true, characterData: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ligar); else ligar();


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
  // nas peças de uma linha só; para em 26 para a entrada não demorar.
  function linhasDe(painel) {
    var out = [], ph = painel.getBoundingClientRect().height;
    (function desce(el, nivel) {
      [].forEach.call(el.children, function (f) {
        if (out.length >= 26 || /^(SCRIPT|STYLE|TEMPLATE|INPUT|OPTION)$/.test(f.tagName)) return;
        var r = f.getBoundingClientRect(); if (r.width < 8 || r.height < 6) return;
        // Embrulho grande (corpo com rolagem, formulário, seção, grade de campos): desce, mesmo
        // quando é um embrulho só (o corpo do Editar Processo embrulha o formulário inteiro).
        if (nivel < 5 && f.children.length >= 1 && r.height > Math.min(120, ph * .25) && !/^(BUTTON|A|LABEL|SELECT|TEXTAREA|TABLE|UL|OL|svg)$/i.test(f.tagName)) { desce(f, nivel + 1); return; }
        out.push(f);
      });
    })(painel, 0);
    return out;
  }
  function entrarPopup(fundo) {
    var painel = painelDe(fundo); if (!painel) return;
    var semAnimProp = getComputedStyle(painel).animationName === 'none';
    if (semAnimProp) { painel.classList.remove('inn-pop-surge'); void painel.offsetWidth; painel.classList.add('inn-pop-surge'); }
    var k = 0;
    linhasDe(painel).forEach(function (el) {
      if (getComputedStyle(el).transform !== 'none') return;
      el.style.setProperty('--mov-i', k++);
      el.classList.remove('inn-pop-entra'); void el.offsetWidth; el.classList.add('inn-pop-entra');
      el.addEventListener('animationend', function f(e) { if (e.target === el) { el.classList.remove('inn-pop-entra'); el.removeEventListener('animationend', f); } });
    });
    var ic = painel.querySelector('.inn-cab-ic, .inn-pop-ic');
    if (ic) { ic.style.animation = 'none'; void ic.offsetWidth; ic.style.animation = ''; }
    painel.addEventListener('animationend', function f(e) { if (e.target === painel) { painel.classList.remove('inn-pop-surge'); painel.removeEventListener('animationend', f); } });
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
    ms.forEach(function (m) {
      if (m.type === 'attributes') { if (m.target.nodeType === 1 && m.target.matches && m.target.matches(SEL_POP + ', dialog')) alvos.add(m.target); return; }
      [].forEach.call(m.addedNodes, function (n) {
        if (n.nodeType !== 1) return;
        if (n.matches && n.matches(SEL_POP + ', dialog')) alvos.add(n);
        else if (n.querySelectorAll) [].forEach.call(n.querySelectorAll(SEL_POP), function (x) { alvos.add(x); });
      });
    });
    if (alvos.size) conferirPopups(alvos);
  }).observe(document.body, { attributes: true, attributeFilter: ['class', 'style', 'hidden', 'open'], childList: true, subtree: true });

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
  var cliqueRecente = 0;
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('button, a, [role="tab"], .inn-aba, [onclick], label')) cliqueRecente = performance.now();
  }, true);
  function entrarEmFila(lista) {
    lista.slice(0, 22).forEach(function (el, k) {
      if (getComputedStyle(el).transform !== 'none') return;
      el.style.setProperty('--mov-i', k);
      el.classList.remove('inn-pop-entra'); void el.offsetWidth; el.classList.add('inn-pop-entra');
      el.addEventListener('animationend', function f(e) { if (e.target === el) { el.classList.remove('inn-pop-entra'); el.removeEventListener('animationend', f); } });
    });
  }
  new MutationObserver(function (ms) {
    if (performance.now() - cliqueRecente > 900) return;
    var porPai = new Map(), paineis = [];
    ms.forEach(function (m) {
      var el = m.target; if (el.nodeType !== 1 || el.closest('.sidebar')) return;
      if (m.type === 'attributes') {
        // O painel que passou a ficar ativo (tk-tab-pane.active), grande e não um botão.
        if (m.attributeName !== 'class' || classesLigadas(m.oldValue) || !classesLigadas(el.className)) return;
        if (/^(BUTTON|A|LABEL|INPUT|SELECT|TEXTAREA|LI)$/.test(el.tagName) || el.classList.contains('inn-aba')) return;
        var r = el.getBoundingClientRect(); if (r.height < 60 || r.width < 160) return;
        paineis.push(el); return;
      }
      [].forEach.call(m.addedNodes, function (n) {
        if (n.nodeType !== 1 || /^(SCRIPT|STYLE|OPTION)$/.test(n.tagName) || n.closest('.sidebar, .inn-emb')) return;
        var r = n.getBoundingClientRect(); if (r.height < 14 || r.width < 40) return;
        var arr = porPai.get(n.parentNode) || []; arr.push(n); porPai.set(n.parentNode, arr);
      });
    });
    paineis.forEach(function (p) { entrarEmFila(linhasDe(p)); });
    porPai.forEach(function (arr) { if (!paineis.some(function (p) { return p.contains(arr[0]); })) entrarEmFila(arr); });
  }).observe(document.body, { attributes: true, attributeOldValue: true, attributeFilter: ['class'], childList: true, subtree: true });

  // ── Sair pelo menu ──────────────────────────────────────────────────────
  // Desde 08/11/2026 é o menu-fixo.js: o item enche, os blocos da tela fecham e o menu fica
  // parado na troca de página (view transition entre documentos). Nada aqui.
})();
