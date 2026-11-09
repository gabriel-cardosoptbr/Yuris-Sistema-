/* ═══════════════════════════════════════════════════════════════════════════
   inicio-movimento.js: o movimento da home nova (prévia, 08/11/2026).
   Par do inicio-movimento.css. Não muda regra nenhuma da home: observa o que
   os outros scripts fazem (trocar a lista do dia, marcar o dia, abrir o pop-up)
   e põe o movimento por cima.

     - entrada: numera a faixa, os quatro números e os cartões para subirem um a um;
     - números: contam de zero até o valor (cartões de cima, prazos, pop-up da pessoa);
     - preenchimento: grava o ponto do clique em --cx/--cy para o círculo nascer ali;
       a onda do toque nos botões, cartões e ícones;
     - lista do dia: some antes da troca e entra item por item depois; título e
       mês acompanham;
     - pop-up: o conteúdo entra campo por campo (o nascer do cartão fica no
       inicio-detalhe.js, que sabe qual cartão foi clicado);
     - sair: link para outra tela leva a página embora antes de navegar.
   Carregado por includes/dashboard_inicio.php depois do inicio-detalhe.js.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var html = document.documentElement;
  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  html.classList.add('ini-mov');

  // ── A entrada ───────────────────────────────────────────────────────────
  var i = 0;
  document.querySelectorAll('.ini-sala, .ini-kpi, .ini-card').forEach(function (el) {
    el.style.setProperty('--mov-i', i++);
    el.classList.add('ini-sobe');
    el.addEventListener('animationend', function f() { el.classList.add('ini-pronto'); el.removeEventListener('animationend', f); });
  });

  // ── Os números crescem ──────────────────────────────────────────────────
  function contar(el, dur) {
    var m = /^(\d{1,6})([\s\S]*)$/.exec(el.textContent || '');
    if (!m || el.dataset.contou) return;
    var alvo = el.dataset.alvo ? +el.dataset.alvo : +m[1], resto = m[2];
    el.dataset.contou = '1';
    if (alvo === 0) return;
    var t0 = null;
    function passo(t) {
      if (t0 === null) t0 = t;
      var p = Math.min(1, (t - t0) / dur);
      p = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(alvo * p) + resto;
      if (p < 1) requestAnimationFrame(passo);
      else { el.classList.add('ini-contou'); el.addEventListener('animationend', function f() { el.classList.remove('ini-contou'); el.removeEventListener('animationend', f); }); }
    }
    el.textContent = '0' + resto;
    requestAnimationFrame(passo);
  }
  // Zera já (09/11/2026): esperar o atraso com o número pronto na tela fazia ele aparecer,
  // sumir para zero e subir.
  document.querySelectorAll('.ini-kpi b, .ini-ctr-t b, .ini-sala p b').forEach(function (el, k) {
    var m = /^(\d{1,6})([\s\S]*)$/.exec(el.textContent || '');
    if (m && +m[1] > 0 && !el.dataset.contou) { el.dataset.alvo = m[1]; el.textContent = '0' + m[2]; }
    setTimeout(function () { contar(el, 900); }, 180 + k * 70);
  });

  // ── O ponto do clique e a onda do toque ─────────────────────────────────
  function ondular(b) {
    b.classList.remove('ini-ondula'); void b.offsetWidth; b.classList.add('ini-ondula');
    b.addEventListener('animationend', function f(e) { if (e.animationName === 'ini-onda') { b.classList.remove('ini-ondula'); b.removeEventListener('animationend', f); } });
  }
  document.addEventListener('pointerdown', function (e) {
    if (e.button > 0 || !e.target.closest) return;
    var b = e.target.closest('.ini-cal-dia, .ini-atalho, .ini-btn, .idt-btn, .ini-kpi, .ini-ctr, a.ini-ic, .ini-cartao');
    if (!b) return;
    var r = b.getBoundingClientRect(), cx = e.clientX - r.left, cy = e.clientY - r.top;
    b.style.setProperty('--cx', Math.round(cx) + 'px');
    b.style.setProperty('--cy', Math.round(cy) + 'px');
    // O raio até o canto mais longe: o círculo tem de cobrir a caixa inteira, alta ou larga.
    b.style.setProperty('--raio', Math.ceil(Math.hypot(Math.max(cx, r.width - cx), Math.max(cy, r.height - cy)) + 2) + 'px');
    if (!b.matches('.ini-cal-dia, .ini-atalho')) ondular(b);
  }, true);

  // ── A lista do dia, o título e o mês ────────────────────────────────────
  var lista = document.getElementById('iniDiaLista'), titulo = document.getElementById('iniDiaTitulo'), conta = document.getElementById('iniDiaConta'), dias = document.getElementById('iniCalDias');
  function retoca(el, cls) { if (!el) return; el.classList.remove(cls); void el.offsetWidth; el.classList.add(cls); }
  if (lista) {
    document.addEventListener('pointerdown', function (e) {
      if (e.target.closest && e.target.closest('#iniCalDias .ini-cal-dia, .ini-atalho[data-faixa]')) lista.classList.add('ini-lista-saindo');
    }, true);
    new MutationObserver(function () {
      lista.classList.remove('ini-lista-saindo');
      var k = 0;
      [].forEach.call(lista.children, function (el) { el.style.setProperty('--mov-i', k++); el.classList.add('ini-entra'); });
      retoca(titulo, 'ini-troca'); retoca(conta, 'ini-troca');
    }).observe(lista, { childList: true });
  }
  var direcao = 0;
  document.addEventListener('click', function (e) { var n = e.target.closest && e.target.closest('.ini-cal-nav'); if (n) direcao = +n.getAttribute('data-cal') || 0; }, true);
  if (dias) new MutationObserver(function () {
    if (!direcao) return;
    dias.classList.remove('ini-mes-esq', 'ini-mes-dir'); void dias.offsetWidth;
    dias.classList.add(direcao < 0 ? 'ini-mes-esq' : 'ini-mes-dir'); direcao = 0;
  }).observe(dias, { childList: true });

  // ── O conteúdo do pop-up entra campo por campo ──────────────────────────
  var SEL = '.idt-campo, .idt-sec-t, .idt-prazo, .idt-pub-grupo, .idt-pub-adv, .idt-pub-bloco, .idt-pub-timbre, .idt-numeros > div, '
          + '.idt-texto, .idt-entrada, .idt-rotulo, .idt-vazio, .idt-pub-link, .idt-escolha, .idt-acoes > *';
  function preencher(raiz) {
    if (!raiz.querySelectorAll) return;
    var alvos = (raiz.matches && raiz.matches(SEL) ? [raiz] : []).concat([].slice.call(raiz.querySelectorAll(SEL)));
    var k = 0;
    alvos.forEach(function (el) { if (el.dataset.mov) return; el.dataset.mov = '1'; el.style.setProperty('--mov-i', k++); el.classList.add('idt-entra'); });
    raiz.querySelectorAll('.idt-numeros b').forEach(function (b) { setTimeout(function () { contar(b, 750); }, 260); });
  }
  var obsJanela = new MutationObserver(function (ms) {
    ms.forEach(function (m) { [].forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) preencher(n); }); });
  });
  // Na primeira abertura o conteúdo já está lá quando o aviso chega: preenche o que existe e passa a vigiar.
  function vigiarJanela(f) { var j = f.querySelector('.idt-janela'); if (!j) return; preencher(j); obsJanela.observe(j, { childList: true, subtree: true }); }
  var jaFundo = document.querySelector('.idt-fundo'); if (jaFundo) vigiarJanela(jaFundo);
  new MutationObserver(function (ms) {
    ms.forEach(function (m) { [].forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1 && n.classList.contains('idt-fundo')) vigiarJanela(n); }); });
  }).observe(document.body, { childList: true });

  // ── Sair para outra tela ────────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.target === '_blank' || a.hasAttribute('download') || a.classList.contains('is-logout')) return;
    var href = a.getAttribute('href') || '';
    if (href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
    var u; try { u = new URL(a.href, location.href); } catch (x) { return; }
    if (u.origin !== location.origin) return;
    e.preventDefault();
    html.classList.add('ini-saindo');
    setTimeout(function () { location.href = u.href; }, 230);
  });
  window.addEventListener('pageshow', function () { html.classList.remove('ini-saindo'); });
})();
