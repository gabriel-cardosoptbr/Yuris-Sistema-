/* ═══════════════════════════════════════════════════════════════════════
   novidade.js: o aviso de "atualização nova" (10/11/2026).

   Mostra uma vez, por pessoa e por navegador, o que mudou na atualização. O
   conteúdo vem de App\Inicio\Novidade (já filtrado pelas permissões da pessoa),
   entregue pelo menu compartilhado (includes/sidebar.php) em window.YURIS_NOVIDADE:
     { id, titulo, subtitulo, itens: [{icone, titulo, texto}], marca, usuario, forcar }

   · Lembra que viu em localStorage ("yuris_novidade_<usuário>" = id). Sem
     localStorage, cai para sessionStorage; sem nenhum dos dois, só abre se pedido
     com ?novidade=1 (para não aparecer em toda tela).
   · Troque o id (App\Inicio\Novidade::ID) para anunciar de novo.
   · ?novidade=1 abre mesmo já visto (prévia).
   · Fecha com o botão, o X, o Esc ou o clique fora. O foco volta para onde estava.
   · Espera a tela da interface nova terminar de se vestir antes de aparecer.
   · O desenho e a animação estão em assets/novidade.css; "reduzir movimento" desliga a animação.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var CFG = window.YURIS_NOVIDADE;
  if (!CFG || !CFG.id || !CFG.itens || !CFG.itens.length) return;
  if (document.documentElement.classList.contains('yuris-embed')) return;

  var chave = 'yuris_novidade_' + (CFG.usuario || 0);

  function lerDe(armazem) { try { return window[armazem].getItem(chave); } catch (_) { return undefined; } }
  function gravarEm(armazem, v) { try { window[armazem].setItem(chave, v); return true; } catch (_) { return false; } }
  function podeGuardar() {
    var t = '__yuris_teste';
    try { localStorage.setItem(t, '1'); localStorage.removeItem(t); return 'localStorage'; } catch (_) {}
    try { sessionStorage.setItem(t, '1'); sessionStorage.removeItem(t); return 'sessionStorage'; } catch (_) {}
    return null;
  }

  var armazem = podeGuardar();
  var forcar = !!CFG.forcar;
  if (!forcar) {
    if (!armazem) return;                          // sem como lembrar: não repete a cada tela
    var visto = lerDe('localStorage') || lerDe('sessionStorage');
    if (visto === CFG.id) return;
  }

  var SVG = {
    novo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3z"/><path d="M19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8L19 16z"/></svg>',
    brilho: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0c.6 6.2 5.8 11.4 12 12-6.2.6-11.4 5.8-12 12-.6-6.2-5.8-11.4-12-12C6.2 11.4 11.4 6.2 12 0z"/></svg>',
    fechar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    moeda: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 6.5v11M9.2 9.2h4.1a1.6 1.6 0 0 1 0 3.2h-2.6a1.6 1.6 0 0 0 0 3.2h4.1"/></svg>',
    grafico: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20h18"/><path d="M6 16v-4M11 16V8M16 16v-6M21 16V5"/></svg>',
    balanca: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v16M7 20h10"/><path d="M5 7h14"/><path d="M5 7l-3 7a3 3 0 0 0 6 0L5 7zM19 7l-3 7a3 3 0 0 0 6 0l-3-7z"/></svg>',
    pessoa: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
    publicacao: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>'
  };

  function el(tag, cls, texto) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (texto != null) e.textContent = texto;
    return e;
  }

  function montar() {
    var marca = CFG.marca ? String(CFG.marca) : 'Yuris';
    var fundo = el('div', 'nov-fundo');
    var card = el('div', 'nov-card');
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-modal', 'true');
    card.setAttribute('aria-labelledby', 'nov-titulo');
    card.setAttribute('aria-describedby', 'nov-sub');

    var topo = el('div', 'nov-topo');
    var confete = el('div', 'nov-confete');
    confete.setAttribute('aria-hidden', 'true');
    var cores = ['#F1F5F9', '#CBD5E1', '#93B4E6', '#E2E8F0', '#7FA0D8'];
    for (var i = 0; i < 18; i++) {
      var c = document.createElement('i');
      var lado = (i % 2 ? 1 : -1);
      c.style.setProperty('--x', (lado * (24 + (i * 17) % 150)) + 'px');
      c.style.setProperty('--y', (60 + (i * 23) % 110) + 'px');
      c.style.setProperty('--r', (lado * (120 + (i * 41) % 300)) + 'deg');
      c.style.setProperty('--d', (0.45 + (i % 6) * 0.07) + 's');
      c.style.setProperty('--c', cores[i % cores.length]);
      confete.appendChild(c);
    }
    topo.appendChild(confete);

    var x = el('button', 'nov-x');
    x.type = 'button';
    x.setAttribute('aria-label', 'Fechar');
    x.innerHTML = SVG.fechar;
    topo.appendChild(x);

    var selo = el('div', 'nov-selo');
    selo.setAttribute('aria-hidden', 'true');
    selo.appendChild(el('span', 'nov-anel'));
    selo.appendChild(el('span', 'nov-anel'));
    var ic = el('div', 'nov-selo-ic');
    ic.innerHTML = SVG.novo;
    selo.appendChild(ic);
    ['b1', 'b2', 'b3'].forEach(function (b) {
      var s = el('span', 'nov-brilho ' + b);
      s.innerHTML = SVG.brilho;
      selo.appendChild(s);
    });
    topo.appendChild(selo);

    topo.appendChild(el('div', 'nov-tag', 'Novidade'));
    var h = el('h2', 'nov-titulo', (CFG.titulo || 'Atualização nova') + ' no ' + marca);
    h.id = 'nov-titulo';
    topo.appendChild(h);
    var sub = el('p', 'nov-sub', CFG.subtitulo || '');
    sub.id = 'nov-sub';
    topo.appendChild(sub);
    card.appendChild(topo);

    var corpo = el('div', 'nov-corpo');
    var ul = el('ul', 'nov-lista');
    CFG.itens.forEach(function (it, n) {
      var li = el('li', 'nov-item');
      li.style.setProperty('--i', n);
      var icone = el('div', 'nov-item-ic');
      icone.setAttribute('aria-hidden', 'true');
      icone.innerHTML = SVG[it.icone] || SVG.novo;
      var txt = el('div', 'nov-item-txt');
      txt.appendChild(el('strong', '', it.titulo));
      txt.appendChild(el('span', '', it.texto));
      li.appendChild(icone);
      li.appendChild(txt);
      ul.appendChild(li);
    });
    corpo.appendChild(ul);
    card.appendChild(corpo);

    var rodape = el('div', 'nov-rodape');
    var ok = el('button', 'nov-ok', 'Entendi, vamos lá');
    ok.type = 'button';
    ok.style.setProperty('--n', CFG.itens.length);
    rodape.appendChild(ok);
    rodape.appendChild(el('p', 'nov-nota', 'Este aviso aparece só uma vez.'));
    card.appendChild(rodape);

    fundo.appendChild(card);
    return { fundo: fundo, card: card, ok: ok, x: x };
  }

  var aberto = false;
  function abrir() {
    if (aberto) return;
    aberto = true;
    var antes = document.activeElement;
    var p = montar();
    document.body.appendChild(p.fundo);
    document.documentElement.classList.add('nov-aberto');
    try { p.ok.focus({ preventScroll: true }); } catch (_) { p.ok.focus(); }

    var fechado = false;
    function fechar() {
      if (fechado) return;
      fechado = true;
      // Lembra que viu: no mesmo armazém de onde lemos (ou no que funciona).
      if (armazem) gravarEm(armazem, CFG.id);
      document.removeEventListener('keydown', teclas, true);
      p.fundo.classList.add('nov-saindo');
      var feito = false;
      function remover() {
        if (feito) return;
        feito = true;
        if (p.fundo.parentNode) p.fundo.parentNode.removeChild(p.fundo);
        document.documentElement.classList.remove('nov-aberto');
        aberto = false;
        try { if (antes && antes.focus) antes.focus({ preventScroll: true }); } catch (_) {}
      }
      p.fundo.addEventListener('animationend', function (e) { if (e.target === p.fundo) remover(); });
      setTimeout(remover, 400);                    // "reduzir movimento" não dispara animationend
    }
    function teclas(e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fechar(); return; }
      if (e.key !== 'Tab') return;
      var a = [p.x, p.ok], i = a.indexOf(document.activeElement);
      if (e.shiftKey && i <= 0) { e.preventDefault(); a[1].focus(); }
      else if (!e.shiftKey && (i === 1 || i === -1)) { e.preventDefault(); a[0].focus(); }
    }
    document.addEventListener('keydown', teclas, true);
    p.ok.addEventListener('click', fechar);
    p.x.addEventListener('click', fechar);
    p.fundo.addEventListener('mousedown', function (e) { if (e.target === p.fundo) fechar(); });
  }

  // Espera a tela terminar de carregar e, na interface nova, de se vestir.
  function quandoPronto() {
    var h = document.documentElement;
    var depois = function () { setTimeout(abrir, 650); };
    if (h.classList.contains('inn-vestindo')) {
      var ja = false;
      var go = function () { if (ja) return; ja = true; depois(); };
      document.addEventListener('inn:revelada', go, { once: true });
      setTimeout(go, 2600);
    } else {
      depois();
    }
  }
  if (document.readyState === 'complete') quandoPronto();
  else window.addEventListener('load', quandoPronto, { once: true });

  window.YurisNovidade = { abrir: abrir };
})();
