/* ═══════════════════════════════════════════════════════════════════════════
   interface-nova-hover.js: todo cartão do sistema se mexe no hover (11/11/2026).
   Par do interface-nova-hover.css. Vale na home e em todas as telas da prévia.

   A REGRA (pedido do dono, 11/11/2026: "se for card de texto, para o lado
   direito; se for outro tipo de card, para cima"):

     1. Cartão em LISTA, um embaixo do outro (compromisso, prazo, publicação,
        conversa, tarefa, cartão do quadro): desliza 2 px para a DIREITA.
     2. Cartão em FILEIRA ou GRADE, um ao lado do outro (indicador, atalho, dia
        do calendário, cartão de cliente em grade), ou com número grande:
        SOBE 2 px.
     3. Cartão sozinho: largo e baixo (mais de 2,6 vezes mais largo que alto) é
        de texto e vai para a direita; o resto sobe.

   Só cartão CLICÁVEL se mexe (link, botão, papel de botão, onclick, data-href, cursor de mão): o
   movimento promete que o clique faz alguma coisa (11/11/2026).

   O que é cartão: caixa com canto arredondado (6 px ou mais) e borda, fundo
   próprio ou sombra, de 110 px de largura e 34 a 260 px de altura (mais alto,
   só item de lista, como a publicação; abaixo de 44 px, só com dois filhos ou
   mais: uma linha com um filho é botão), que não se chama coluna, painel, grade
   ou lista, nem estado vazio, que não é
   botão, campo, aba, selo, ícone, menu, faixa de foto nem pop-up, não tem campo
   de digitação dentro e não guarda dois ou mais cartões (aí é painel ou coluna,
   e quem se mexe são os de dentro). Do cartão de dentro para o de fora, vale o
   mais de fora que ainda é cartão.

   Como: a decisão é tomada na hora do hover (o que a tela desenha depois, o
   Chat, o quadro de Tarefas, entra sozinho) e também numa passada em tempo
   ocioso, para a folga (abaixo) já estar pronta quando o mouse chega. O
   movimento usa a propriedade `translate`, separada do `transform` que as
   entradas animadas e os hovers antigos usam, para os dois não se anularem.

   Folga: lista que rola (ou caixa com overflow escondido) corta o cartão que
   sai da borda (11/11/2026, a agenda da home comia a borda direita). Quando o
   cartão encosta na borda do lado para onde vai, a caixa que corta ganha a
   folga que falta no padding, uma vez só.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var html = document.documentElement;
  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  html.classList.add('inn-hv');

  var PASSO = 2;            // px que o cartão anda
  var FOLGA = PASSO + 1;    // px livres que o lado para onde ele anda precisa ter
  var LADO = 'inn-hv-lado', CIMA = 'inn-hv-cima';
  var TRANSICAO = 'translate .2s cubic-bezier(.2,.7,.2,1)';

  var TAGS_NAO = /^(BUTTON|INPUT|SELECT|TEXTAREA|LABEL|TABLE|THEAD|TBODY|TR|TD|TH|SVG|IMG|CANVAS|IFRAME|VIDEO|FORM|FIELDSET|NAV|HEADER|FOOTER|BODY|HTML|MAIN|UL|OL|DIALOG|OPTION)$/;
  var CLASSE_NAO = /(^|[-_\s])(btn|button|botao|campo|field|input|select|dropdown|tab|tabs|aba|abas|chip|selo|badge|tag|pill|toast|tooltip|modal|overlay|backdrop|drawer|popup|popover|dialog|sidebar|menu|navbar|topbar|tabbar|sala|hero|banner|foto|photo|avatar|logo|editor|ql|chart|grafico|skeleton|fantasma|dragging|ghost|chosen|sortable)([-_\s]|$)|inn-(selo|aba|ic-btn|toast|cab-ic|card-ic|kpi-ic)|ini-(ic|av|chev|mini|ev-hora)|(^|[-_])(empty|vazio|placeholder)(\s|$)/i;
  // Quem guarda cartões pelo nome (coluna do quadro, painel, grade, lista): nunca se mexe, mesmo
  // vazio. Sem isso a coluna de Tarefas, medida antes de a lista chegar, virava cartão (11/11/2026).
  var RE_RECIPIENTE = /(^|[-_])(col|cols|column|columns|coluna|colunas|lane|kanban|board|quadro|panel|painel|grid|wrap|wrapper|container|list|lista|body|corpo|content|shell)(\s|$)/i;
  var FORA ='.sidebar, .mobile-tabbar, .inn-menu, [role="dialog"] > header, .page-header, .inn-mov-saindo';
  var FOTO_NAO = '[draggable="true"].sortable-chosen, .sortable-ghost, .sortable-drag';
  var MAX_ALTURA = 260, MIN_ALTURA = 34, MIN_LARGURA = 110;

  function nomeDe(el) { return String((typeof el.className === 'string' ? el.className : (el.getAttribute && el.getAttribute('class'))) || '') + ' ' + (el.id || ''); }
  function alfa(c) {
    if (!c || c === 'transparent') return 0;
    var m = String(c).match(/rgba?\(([^)]+)\)/); if (!m) return 1;
    var p = m[1].split(/[,\s/]+/).filter(Boolean); return p.length > 3 ? parseFloat(p[3]) : 1;
  }

  function itemDeLista(el) {
    var c1 = String(typeof el.className === 'string' ? el.className : '').split(/\s+/)[0];
    if (!c1 || !el.parentElement) return false;
    return [].some.call(el.parentElement.children, function (s) { return s !== el && s.classList.contains(c1); });
  }
  // ── É caixa de cartão? (só pela própria caixa, sem olhar os filhos) ──────
  function ehCaixa(el) {
    if (!el || el.nodeType !== 1 || TAGS_NAO.test(el.tagName)) return false;
    var w = el.offsetWidth, h = el.offsetHeight;
    if (w < MIN_LARGURA || h < MIN_ALTURA) return false;
    // Alto demais só é cartão se for item de lista (irmão com a mesma classe): a publicação inteira
    // tem 400 px e é cartão; o painel de gráfico, sozinho, não.
    if (h > MAX_ALTURA && !itemDeLista(el)) return false;
    // Uma linha só com um filho (a data do topo, o link "Indicadores") é botão ou etiqueta, não cartão.
    if (h < 44 && el.children.length <= 1) return false;
    var nome = nomeDe(el);
    if (CLASSE_NAO.test(nome) || RE_RECIPIENTE.test(nome)) return false;
    var r = el.getAttribute('role'); if (r && /^(tab|dialog|menu|menuitem|option|tooltip|navigation)$/.test(r)) return false;   // role=button fica: o cartão de Tarefas é role=button
    var cs = getComputedStyle(el);
    if (cs.position === 'fixed' || cs.position === 'sticky' || cs.display === 'inline' || cs.visibility === 'hidden') return false;
    if (parseFloat(cs.borderTopLeftRadius) < 6) return false;
    var borda = parseFloat(cs.borderTopWidth) >= 1 && cs.borderTopStyle !== 'none' && alfa(cs.borderTopColor) >= .06;
    var fundo = alfa(cs.backgroundColor) >= .3 || (cs.backgroundImage && cs.backgroundImage !== 'none' && !/url\(/.test(cs.backgroundImage));
    var sombra = cs.boxShadow && cs.boxShadow !== 'none';
    if (!borda && !(fundo && sombra)) return false;
    return true;
  }

  // ── Guarda dois ou mais cartões? (então é painel ou coluna) ──────────────
  function guardaCartoes(el) {
    var achados = 0, vistos = 0;
    var w = document.createTreeWalker(el, NodeFilter.SHOW_ELEMENT, null);
    var n = w.nextNode();
    while (n && vistos++ < 600) {
      if (ehCaixa(n)) { if (++achados >= 2) return true; }
      n = w.nextNode();
    }
    return false;
  }
  function temCampo(el) { return !!el.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), textarea, select, [contenteditable="true"], .ql-editor'); }

  // Irmão da mesma classe que é painel: este também é, só está vazio agora (a coluna "Hoje" de
  // Processos Próximos sem processo subia sozinha enquanto as vizinhas, cheias, ficavam paradas).
  function irmaoEhPainel(el) {
    var c1 = String(typeof el.className === 'string' ? el.className : '').split(/\s+/)[0];
    if (!c1 || !el.parentElement) return false;
    var n = 0;
    return [].some.call(el.parentElement.children, function (s) { return s !== el && n++ < 8 && s.classList.contains(c1) && guardaCartoes(s); });
  }
  function ehPainel(el) { return temCampo(el) || guardaCartoes(el) || irmaoEhPainel(el); }
  // Só se mexe o cartão que faz alguma coisa ao clicar (11/11/2026: os blocos de tarefas, prazos e
  // vínculos da Nova Tarefa deslizavam para a direita sem abrir nada, "animação que não devia existir").
  // O movimento promete o clique: link, botão, papel de botão, onclick, data-href, ou cursor de mão.
  function clicavel(el) {
    if (el.matches('a[href], button, [role="button"], [role="link"], [onclick], [data-href], [data-id], [data-chave], [tabindex]:not([tabindex="-1"])')) return true;
    return getComputedStyle(el).cursor === 'pointer';
  }
  function cartaoValido(el) {
    if (!ehCaixa(el) || !clicavel(el)) return false;
    if (el.closest(FORA) || el.matches(FOTO_NAO)) return false;
    if (ehPainel(el)) return false;
    return true;
  }

  // ── Do alvo do mouse, o cartão: o mais de fora que ainda é cartão ────────
  function cartaoDe(alvo) {
    var achado = null, el = alvo, passos = 0;
    while (el && el.nodeType === 1 && el !== document.body && passos++ < 14) {
      if (el.classList.contains(LADO) || el.classList.contains(CIMA)) { achado = el; }
      else if (ehCaixa(el)) {
        if (el.closest(FORA)) return achado;
        if (ehPainel(el)) break;   // painel: para aqui, fica o de dentro
        if (clicavel(el)) achado = el;
      }
      el = el.parentElement;
    }
    return achado;
  }

  // ── Para onde: a regra do topo ───────────────────────────────────────────
  function numeroGrande(el) {
    var w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null), t, vistos = 0;
    while ((t = w.nextNode()) && vistos++ < 80) {
      var s = t.nodeValue.trim();
      if (!s || !/^[\d.,%R$\s+-]{1,14}$/.test(s) || !/\d/.test(s)) continue;
      var p = t.parentElement; if (p && parseFloat(getComputedStyle(p).fontSize) >= 20) return true;
    }
    return false;
  }
  function irmaos(el) {
    var p = el.parentElement; if (!p) return [];
    var lista = [].filter.call(p.children, function (s) { return s !== el && s.offsetWidth >= MIN_LARGURA * .6 && s.offsetHeight >= 20; });
    // Cartão embrulhado sozinho (cada um no próprio <div>): os irmãos são os do embrulho.
    if (!lista.length && p.children.length === 1 && p.parentElement) {
      var avo = p.parentElement;
      lista = [].filter.call(avo.children, function (s) { return s !== p && s.offsetWidth >= MIN_LARGURA * .6 && s.offsetHeight >= 20; });
      return { els: lista, ref: p };
    }
    return { els: lista, ref: el };
  }
  function direcao(el) {
    if (numeroGrande(el)) return CIMA;
    var ir = irmaos(el), r = ir.ref ? ir.ref.getBoundingClientRect() : el.getBoundingClientRect();
    var lado = false, empilhado = false;
    (ir.els || []).forEach(function (s) {
      var q = s.getBoundingClientRect();
      if (Math.abs(q.top - r.top) < 8 && Math.abs(q.left - r.left) > 20) lado = true;
      else if (Math.abs(q.left - r.left) < 8 && Math.abs(q.top - r.top) > 8) empilhado = true;
    });
    if (lado) return CIMA;
    if (empilhado) return LADO;
    return el.offsetWidth > el.offsetHeight * 2.6 ? LADO : CIMA;
  }

  // ── A transição do translate, somada à que o cartão já tinha ─────────────
  function somarTransicao(el) {
    if (el.dataset.innHvTr) return;
    if (el.classList.contains('inn-c-agora')) return;      // a camada está trocando a cor sem transição
    var cs = getComputedStyle(el), props = cs.transitionProperty || '';
    el.dataset.innHvTr = '1';
    if (/(^|,\s*)(all|translate)(\s*,|$)/.test(props)) return;
    var atual = el.style.getPropertyValue('transition');
    if (atual) { el.style.setProperty('transition', atual + ', ' + TRANSICAO); return; }
    var dur = (cs.transitionDuration || '').split(','), fun = (cs.transitionTimingFunction || '').split(/,(?![^(]*\))/), atr = (cs.transitionDelay || '').split(',');
    var partes = props.split(',').map(function (p, i) {
      p = p.trim(); if (!p || p === 'none') return '';
      return p + ' ' + (dur[i % dur.length] || '0s').trim() + ' ' + (fun[i % fun.length] || 'ease').trim() + ' ' + (atr[i % atr.length] || '0s').trim();
    }).filter(function (p) { return p && !/ 0s [^ ]+ 0s$/.test(p); });
    partes.push(TRANSICAO);
    el.style.setProperty('transition', partes.join(', '));
  }

  // ── Folga na caixa que corta ─────────────────────────────────────────────
  function corta(cs) { return /(auto|scroll|hidden|clip)/.test(cs.overflowX + ' ' + cs.overflowY); }
  function darFolga(el, dir) {
    var r = el.getBoundingClientRect(), a = el.parentElement, n = 0;
    while (a && a !== document.body && a !== html && n++ < 12) {
      var cs = getComputedStyle(a);
      if (corta(cs)) {
        var ar = a.getBoundingClientRect();
        if (dir === LADO) {
          var dentroDir = ar.left + a.clientLeft + a.clientWidth, sobra = dentroDir - r.right;
          if (sobra < FOLGA && sobra > -2) {
            var pd = parseFloat(cs.paddingRight) || 0;
            a.style.setProperty('padding-right', (pd + FOLGA - Math.max(0, sobra)) + 'px', 'important');
          }
        } else {
          var dentroTopo = ar.top + a.clientTop, sobraT = r.top - dentroTopo + a.scrollTop;
          if (sobraT < FOLGA && sobraT > -2) {
            var pt = parseFloat(cs.paddingTop) || 0;
            a.style.setProperty('padding-top', (pt + FOLGA - Math.max(0, sobraT)) + 'px', 'important');
          }
        }
      }
      a = a.parentElement;
    }
  }

  function marcar(el) {
    if (el.classList.contains(LADO) || el.classList.contains(CIMA)) return el;
    var dir = direcao(el);
    somarTransicao(el);
    el.classList.add(dir);
    darFolga(el, dir);
    return el;
  }

  // ── No hover ─────────────────────────────────────────────────────────────
  var ultimo = null;
  document.addEventListener('pointerover', function (e) {
    if (e.pointerType === 'touch') return;
    var alvo = e.target; if (!alvo || alvo === ultimo) return;
    ultimo = alvo;
    var c = cartaoDe(alvo); if (c && !c.closest(FORA)) marcar(c);
  }, { passive: true, capture: true });

  // ── Em tempo ocioso: a folga pronta antes do mouse chegar ────────────────
  var ocioso = window.requestIdleCallback || function (f) { return setTimeout(function () { f({ timeRemaining: function () { return 8; } }); }, 60); };
  var fila = [], pedida = false;
  function varrer() {
    var raiz = document.querySelector('.inn-tela') || document.querySelector('.ini') || document.querySelector('main') || document.body;
    // O que foi marcado e depois encheu de cartões (a lista chegou depois) deixa de ser cartão.
    [].forEach.call(raiz.querySelectorAll('.' + LADO + ', .' + CIMA), function (el) {
      if (el.isConnected && ehPainel(el)) el.classList.remove(LADO, CIMA);
    });
    fila = [].slice.call(raiz.querySelectorAll('div, li, a, article, section, aside'));
    if (!pedida) { pedida = true; ocioso(trabalhar); }
  }
  function trabalhar(prazo) {
    pedida = false;
    var feitos = 0;
    while (fila.length && (prazo.timeRemaining() > 2 || feitos < 40)) {
      var el = fila.shift(); feitos++;
      if (!el.isConnected || el.classList.contains(LADO) || el.classList.contains(CIMA)) continue;
      if (el.offsetHeight < MIN_ALTURA || el.offsetWidth < MIN_LARGURA) continue;
      var r = el.getBoundingClientRect(); if (r.bottom < -200 || r.top > innerHeight * 3) continue;
      if (!cartaoValido(el)) continue;
      // O mais de fora vence: a lista vem na ordem do documento, o pai foi visto antes;
      // se ele virou cartão, quem se mexe é ele.
      if (el.parentElement && el.parentElement.closest('.' + LADO + ', .' + CIMA)) continue;
      marcar(el);
    }
    if (fila.length) { pedida = true; ocioso(trabalhar); }
  }
  var timer = null;
  // Uma varredura marcada não é empurrada pela mutação seguinte: em Publicações a tela muda o
  // tempo todo e o adiamento nunca chegava ao fim (nenhum cartão era preparado, 11/11/2026).
  function agendar(ms) { if (timer) return; timer = setTimeout(function () { timer = null; varrer(); }, ms); }
  // Logo depois do HTML (o script é defer), sem esperar o 'load': no Jurídico um recurso externo
  // segura o 'load' por segundos e nada era preparado.
  agendar(1200);
  // Lista que chega depois (Chat, Tarefas, troca de filtro): só filhos novos, nunca atributo,
  // para a camada não acordar a si mesma (as classes e a folga daqui são atributo).
  try {
    new MutationObserver(function (ms) {
      for (var i = 0; i < ms.length; i++) if (ms[i].addedNodes.length) { agendar(900); return; }
    }).observe(document.body, { childList: true, subtree: true });
  } catch (e) {}

  window.InnHover = { ehCaixa: ehCaixa, guardaCartoes: guardaCartoes, cartaoDe: cartaoDe, direcao: direcao, marcar: marcar, varrer: varrer };
})();
