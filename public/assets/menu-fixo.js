/* ═══════════════════════════════════════════════════════════════════════════
   menu-fixo.js: o menu fica parado quando se troca de tela (interface nova,
   08/11/2026). Par da seção "menu fixo" de interface-nova.css.

   "Quando trocar de tela pelo menu, a única coisa que tem que animar é a tela.
   O menu não pode ficar recarregando, senão parece que estou recarregando a
   página. Ele tem que ser que nem no início: clica e o campo é preenchido. E a
   tela encolhe, os campos fechando e abrindo de novo."

   Como:
   1. Ao clicar num item do menu, o item enche a partir do ponto do clique com
      a cor de ativo, o grupo dele abre, os blocos da tela fecham de baixo para
      cima, e a tela nova é pedida na hora (a dobra acontece enquanto o servidor
      responde; antes esperava 430 ms para só então pedir). NENHUM grupo fecha
      sozinho (09/11/2026: o grupo da tela de antes fechava e os itens pulavam,
      "o menu está recarregando"): os grupos abertos vão para a tela nova
      (sessionStorage yuris_menu_grupos, lido pelo script dos grupos em
      includes/sidebar.php antes da primeira pintura).
   2. A troca é uma view transition entre documentos (@view-transition, em
      interface-nova.css): o navegador segura a imagem da página antiga e, na
      nova, mostra o menu novo no lugar do antigo sem animação. Como o menu já
      estava igual ao que a tela nova desenha, ele não pisca nem se mexe. A tela
      nova aparece ao vivo e os blocos dela abrem (a entrada de cada folha de
      movimento). Onde o navegador não tem view transition entre documentos,
      fica só o passo 1 e a entrada.
   3. A rolagem do menu volta ao ponto em que estava (guardada ao sair; o menu
      a lê num script inline logo depois do <aside>, em includes/sidebar.php).

   Carregado por includes/sidebar.php em toda tela da interface nova, fora do
   modo embutido (?embed=1).
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var html = document.documentElement;
  if (html.classList.contains('yuris-embed') || /[?&]embed=1(&|$)/.test(location.search)) return;
  var menu = document.querySelector('.sidebar');
  var semMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // 3. A rolagem do menu, de uma tela para a outra.
  window.addEventListener('pagehide', function () {
    try { if (menu) sessionStorage.setItem('yuris_menu_rolagem', String(menu.scrollTop)); } catch (e) {}
  });

  function rgb(c) {
    var m = /rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+))?\s*\)/.exec(c || '');
    return m ? [+m[1], +m[2], +m[3], m[4] == null ? 1 : +m[4]] : null;
  }
  function fundo(el) { var v = rgb(getComputedStyle(el).backgroundColor); return v && v[3] > .02 ? 'rgba(' + v.join(',') + ')' : ''; }
  // O fundo de ativo é um degradê (background-image); sem degradê, a cor.
  function fundoAtivo(el) { var cs = getComputedStyle(el); return cs.backgroundImage && cs.backgroundImage !== 'none' ? cs.backgroundImage : fundo(el); }

  // 1a. O item enche a partir do clique, com o fundo que ele tem quando ativo.
  function encher(a, x, y) {
    var r = a.getBoundingClientRect(); if (!r.width || !r.height) return;
    var antes = fundo(a);
    a.style.setProperty('transition', 'none', 'important');
    a.classList.add('active'); a.setAttribute('aria-current', 'page');
    var cor = fundoAtivo(a);
    a.style.removeProperty('transition');
    if (!cor || cor === antes) return;
    var cx = Math.max(0, Math.min(r.width, x - r.left)), cy = Math.max(0, Math.min(r.height, y - r.top));
    a.style.setProperty('--mf-cx', Math.round(cx) + 'px');
    a.style.setProperty('--mf-cy', Math.round(cy) + 'px');
    a.style.setProperty('--mf-raio', Math.ceil(Math.hypot(Math.max(cx, r.width - cx), Math.max(cy, r.height - cy)) + 2) + 'px');
    a.style.setProperty('--mf-cor', cor);
    a.style.setProperty('--mf-antes', antes || 'transparent');
    a.classList.add('mf-enchendo');
    // No fim do círculo a marca sai: o item fica só com o ativo de sempre (o brilho entra pela
    // transição dele).
    a.addEventListener('animationend', function f(e) { if (e.animationName === 'mf-enche') { a.classList.remove('mf-enchendo'); a.removeEventListener('animationend', f); } });
  }

  // 1b. O menu fica como a tela nova vai desenhar: o item clicado é o ativo, o grupo dele
  // está aberto e com a marca, e os outros grupos ficam como estão. O retrato dos grupos
  // abertos vai para a tela nova, que nasce com o mesmo menu.
  function arrumarGrupos(a) {
    var meu = a.closest('.sidebar-group');
    [].forEach.call(document.querySelectorAll('.sidebar nav a.active, .sidebar nav a[aria-current]'), function (o) {
      if (o !== a) { o.classList.remove('active'); o.removeAttribute('aria-current'); }
    });
    [].forEach.call(document.querySelectorAll('.sidebar .sidebar-group'), function (g) {
      var t = g.querySelector('.sidebar-group-toggle'), k = g.getAttribute('data-group');
      if (g === meu) {
        g.classList.add('has-active');
        if (k) { g.classList.add('open'); if (t) t.setAttribute('aria-expanded', 'true'); }
      } else {
        g.classList.remove('has-active');
      }
    });
    guardarGrupos();
  }
  // O retrato dos grupos abertos, para a tela nova nascer com o mesmo menu (também pelo logo).
  function guardarGrupos() {
    var retrato = {};
    [].forEach.call(document.querySelectorAll('.sidebar .sidebar-group[data-group]'), function (g) {
      retrato[g.getAttribute('data-group')] = g.classList.contains('open');
    });
    try { sessionStorage.setItem('yuris_menu_grupos', JSON.stringify(retrato)); } catch (e) {}
  }

  // 1c. Sair pelo menu. Em captura, para ficar na frente da saída de cada folha de movimento
  // (que vê o preventDefault e não faz nada).
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest('.sidebar nav a[href], .sidebar a.sb-logo-link[href], .mobile-tabbar a[href]');
    if (!a || a.target === '_blank' || a.classList.contains('is-logout') || a.hasAttribute('data-sb-recolher')) return;
    var href = a.getAttribute('href') || '';
    if (href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
    var u; try { u = new URL(a.href, location.href); } catch (x) { return; }
    if (u.origin !== location.origin) return;
    e.preventDefault();
    if (semMovimento) { location.href = u.href; return; }
    if (a.closest('.sidebar nav')) {
      arrumarGrupos(a);
      // O título de grupo que é link (grupo de um item só) não vira pílula: a marca dele é a
      // do grupo (has-active), e é assim que a tela nova o desenha.
      if (!a.classList.contains('sidebar-group-toggle') && !a.classList.contains('active')) encher(a, e.clientX, e.clientY);
      else if (!a.classList.contains('sidebar-group-toggle')) a.setAttribute('aria-current', 'page');
    } else if (a.closest('.sidebar')) {
      guardarGrupos();
    }
    html.classList.add('inn-menu-saindo');
    // Pede a tela nova logo depois de pintar o primeiro quadro do enchimento e da dobra: o
    // navegador segue animando a página atual enquanto o servidor responde.
    // (Sem quadro, como numa aba em segundo plano, o relógio garante a ida.)
    var foi = false;
    function ir() { if (foi) return; foi = true; location.href = u.href; }
    requestAnimationFrame(function () { requestAnimationFrame(ir); });
    setTimeout(ir, 150);
  }, true);

  // Voltar pelo histórico (a página sai do cache do navegador como estava): desfaz a saída.
  window.addEventListener('pageshow', function () {
    html.classList.remove('inn-menu-saindo');
    [].forEach.call(document.querySelectorAll('.mf-enchendo'), function (el) { el.classList.remove('mf-enchendo'); });
  });
})();
