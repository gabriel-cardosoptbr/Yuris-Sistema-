/* _auditar_espacos.js: ferramenta de desenvolvimento, nenhuma página carrega (11/11/2026).
 * "Tem muito lugar sem espaço, corrija em todo o sistema." Mede, no que está visível:
 *   1. ENCOSTADO: conteúdo (texto, botão, campo, caixa de dentro) a menos de 6 px da borda de
 *      uma caixa (elemento com borda visível ou fundo diferente do de trás, canto arredondado).
 *   2. COLADO: duas peças irmãs, uma embaixo da outra, em que pelo menos uma é caixa, com
 *      menos de 6 px de vão (o cabeçalho do pop-up colado nas opções, por exemplo).
 * Uso no console: carregar este arquivo e chamar auditarEspacos(). Devolve as ocorrências
 * agrupadas pela classe. Menu, aviso de cookies, tabela e o que é uma linha só ficam de fora. */
window.auditarEspacos = function () {
  var MIN = 6;
  function rgba(c) { var m = String(c || '').match(/rgba?\(([^)]+)\)/); if (!m) return null; var p = m[1].split(/[,\s/]+/).filter(Boolean).map(Number); return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1]; }
  function fundoDe(el) { var e = el.parentElement; while (e) { var c = rgba(getComputedStyle(e).backgroundColor); if (c && c[3] > .5) return c; e = e.parentElement; } return [255, 255, 255, 1]; }
  function visivel(el) { var r = el.getBoundingClientRect(); if (!r.width || !r.height || r.bottom < 0 || r.top > innerHeight) return false; var cs = getComputedStyle(el); return cs.visibility !== 'hidden' && +cs.opacity > .3; }
  function ehCaixa(el) {
    if (/^(TABLE|TR|TD|TH|TBODY|THEAD|INPUT|SELECT|TEXTAREA|BUTTON|IMG|SVG|CANVAS|LABEL|OPTION|A|SPAN|B|I|EM|SMALL|STRONG)$/.test(el.tagName)) return false;
    if (el.offsetWidth < 80 || el.offsetHeight < 30) return false;
    var cs = getComputedStyle(el);
    if (parseFloat(cs.borderTopLeftRadius) < 4) return false;
    var borda = parseFloat(cs.borderTopWidth) >= 1 && cs.borderTopStyle !== 'none' && (rgba(cs.borderTopColor) || [0, 0, 0, 0])[3] > .05;
    var c = rgba(cs.backgroundColor), f = fundoDe(el);
    var fundo = c && c[3] > .3 && (Math.abs(c[0] - f[0]) + Math.abs(c[1] - f[1]) + Math.abs(c[2] - f[2]) > 6);
    return borda || fundo || (cs.boxShadow && cs.boxShadow !== 'none' && /inset/.test(cs.boxShadow));
  }
  // Para o vão entre peças, botão e link grandes também contam (as opções da Nova Tarefa são botões).
  function ehBloco(el) {
    if (ehCaixa(el)) return true;
    if (!/^(BUTTON|A)$/.test(el.tagName) || el.offsetWidth < 120 || el.offsetHeight < 40) return false;
    var cs = getComputedStyle(el);
    return parseFloat(cs.borderTopWidth) >= 1 && cs.borderTopStyle !== 'none';
  }
  var FORA = '.sidebar, .mobile-tabbar, #yuris-cookie-banner, #yuris-cookie-prefs, table, svg, script, style';
  var res = {}, n = 0;
  function anota(tipo, el, extra) { var k = tipo + ' ' + String(el.getAttribute('class') || el.tagName).split(' ').filter(function (c) { return c.indexOf('inn-') !== 0; }).slice(0, 2).join('.'); if (!res[k]) res[k] = { n: 0, ex: extra }; res[k].n++; n++; }
  var todos = [].slice.call(document.querySelectorAll('body *')).filter(function (el) { return !el.closest(FORA) && visivel(el); });
  todos.forEach(function (el) {
    if (!ehCaixa(el)) return;
    var r = el.getBoundingClientRect(), cs = getComputedStyle(el);
    var bl = parseFloat(cs.borderLeftWidth) || 0, bt = parseFloat(cs.borderTopWidth) || 0, br = parseFloat(cs.borderRightWidth) || 0;
    // O conteúdo de dentro: o que tem texto próprio ou é peça (campo, botão, caixa).
    var min = Infinity, quem = '';
    [].forEach.call(el.querySelectorAll('*'), function (d) {
      if (!visivel(d) || d.closest(FORA)) return;
      var proprio = [].some.call(d.childNodes, function (x) { return x.nodeType === 3 && x.nodeValue.trim(); });
      if (!proprio && !/^(INPUT|SELECT|TEXTAREA|BUTTON|IMG)$/.test(d.tagName) && !ehCaixa(d)) return;
      var q = d.getBoundingClientRect();
      // Linha de largura inteira (lista tipo menu, com o próprio espaço interno) não encosta: o texto dela tem respiro.
      if (q.width >= r.width - bl - br - 2) { var cd = getComputedStyle(d); if (!proprio || (parseFloat(cd.paddingLeft) >= 8 && parseFloat(cd.paddingTop) >= 6)) return; }   // faixa de cabeçalho com respiro próprio
      var esq = q.left - r.left - bl, topo = q.top - r.top - bt, dir = r.right - br - q.right;
      var m = Math.min(esq, topo, dir);
      if (m < min) { min = m; quem = (d.getAttribute('class') || d.tagName).toString().split(' ')[0] + ' "' + (d.textContent || '').trim().slice(0, 18) + '"'; }
    });
    if (min < MIN && min > -2) anota('ENCOSTADO', el, Math.round(min) + 'px: ' + quem);
  });
  todos.forEach(function (el) {
    var irmao = el.nextElementSibling; while (irmao && (!visivel(irmao) || irmao.closest(FORA))) irmao = irmao.nextElementSibling;
    if (!irmao || el.closest(FORA)) return;
    // Rótulo de campo em cima do campo é perto de propósito.
    if (el.tagName === 'LABEL' || (el.offsetHeight < 22 && !ehBloco(el))) return;
    // A peça de baixo conta como caixa se ela é caixa ou se começa com uma (as opções da Nova Tarefa
    // moram num contêiner sem caixa, e o topo delas é o topo dele).
    var bt0 = irmao.getBoundingClientRect().top;
    var comecaComCaixa = ehBloco(irmao) || [].some.call(irmao.querySelectorAll('*'), function (d) { return visivel(d) && ehBloco(d) && d.getBoundingClientRect().top - bt0 < 2; });
    if (!ehBloco(el) && !comecaComCaixa) return;
    var a = el.getBoundingClientRect(), b = irmao.getBoundingClientRect();
    if (Math.abs(a.left - b.left) > 40 && b.top < a.bottom) return;   // lado a lado
    var vao = b.top - a.bottom;
    if (vao < MIN && vao > -2 && b.left < a.right && b.right > a.left) anota('COLADO', irmao, Math.round(vao) + 'px depois de ' + String(el.getAttribute('class') || el.tagName).split(' ')[0]);
  });
  return location.pathname + ' [' + n + '] ' + JSON.stringify(Object.keys(res).map(function (k) { return k + ' x' + res[k].n + ' (' + res[k].ex + ')'; }));
};
