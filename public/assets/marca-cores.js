/**
 * Repinta com a cor da marca todo azul fixo das telas (edição CRM, conta com marca própria, 17/10/2026).
 *
 * O azul do Yuris e do Fleetiflow está escrito à mão em dezenas de arquivos (yuris-theme.css com
 * !important, chat, tarefas, prospecção, estilos inline). Em vez de trocar arquivo por arquivo, esta
 * camada percorre as folhas de estilo da página, os <style> que entram depois e os atributos style,
 * e troca cada cor azul pela da marca, guardando o claro e o escuro de cada tom:
 *   escuro (luminosidade < 30%)  -> tom forte da marca
 *   médio  (30% a 62%)           -> a cor da marca (a versão legível, Marca::paleta)
 *   claro  (> 62%)               -> a mesma luminosidade, no matiz da marca
 * A transparência de cada cor é mantida. Só mexe em azul (matiz 195 a 250, saturado): verde, vermelho,
 * amarelo e cinza ficam como estão. Carregado por includes/sidebar.php só quando a marca é própria.
 * As variáveis --amb-* (cores dos ambientes, já derivadas da marca) ficam como estão.
 */
(function () {
  'use strict';
  var M = window.MARCA;
  if (!M || !M.paleta || !M.cor) return;

  function hexRgb(h) {
    h = String(h).replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    var n = parseInt(h, 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }
  function hsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    var max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2, h = 0, s = 0;
    if (max !== min) {
      var d = max - min;
      s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
      h = max === r ? (g - b) / d + (g < b ? 6 : 0) : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
      h *= 60;
    }
    return [h, s, l];
  }
  function rgbDeHsl(h, s, l) {
    h /= 360;
    function f(p, q, t) { if (t < 0) t += 1; if (t > 1) t -= 1; if (t < 1 / 6) return p + (q - p) * 6 * t; if (t < 1 / 2) return q; if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6; return p; }
    if (s === 0) { var v = Math.round(l * 255); return [v, v, v]; }
    var q = l < 0.5 ? l * (1 + s) : l + s - l * s, p = 2 * l - q;
    return [Math.round(f(p, q, h + 1 / 3) * 255), Math.round(f(p, q, h) * 255), Math.round(f(p, q, h - 1 / 3) * 255)];
  }

  var MEDIO = hexRgb(M.paleta.marca), FORTE = hexRgb(M.paleta.forte || M.paleta.marca);
  var base = hsl.apply(null, hexRgb(M.cor));
  var satClara = Math.min(Math.max(base[1], 0.35), 0.6);

  function ehAzul(r, g, b) {
    if (Math.max(r, g, b) - Math.min(r, g, b) < 40) return false;
    var x = hsl(r, g, b);
    return x[0] >= 195 && x[0] <= 250 && x[1] >= 0.35;
  }
  function trocar(r, g, b) {
    var l = hsl(r, g, b)[2];
    if (l < 0.30) return FORTE;
    if (l <= 0.62) return MEDIO;
    return rgbDeHsl(base[0], satClara, l);
  }

  var RE = /#([0-9a-f]{6}|[0-9a-f]{3})\b|rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+%?)\s*)?\)/gi;
  function recolorir(valor) {
    if (!valor || (valor.indexOf('#') < 0 && valor.indexOf('rgb') < 0)) return valor;
    return valor.replace(RE, function (todo, hex, r, g, b, a) {
      var c = hex ? hexRgb(hex) : [+r, +g, +b];
      if (!ehAzul(c[0], c[1], c[2])) return todo;
      var n = trocar(c[0], c[1], c[2]);
      return a !== undefined ? 'rgba(' + n.join(',') + ',' + a + ')' : 'rgb(' + n.join(',') + ')';
    });
  }
  function recolorirEstilo(st) {
    for (var i = 0; i < st.length; i++) {
      var p = st[i];
      // As cores dos ambientes (--amb-*) já saem da marca (App\Ambientes\Ambiente::cores) em
      // tons diferentes de propósito: achatá-las num tom só apagaria a diferença entre eles.
      if (p.indexOf('--amb-') === 0) continue;
      var v = st.getPropertyValue(p), n = recolorir(v);
      if (n !== v) st.setProperty(p, n, st.getPropertyPriority(p));
    }
  }
  function recolorirRegras(regras) {
    for (var i = 0; i < regras.length; i++) {
      var r = regras[i];
      if (r.style) recolorirEstilo(r.style);
      if (r.cssRules) recolorirRegras(r.cssRules);
    }
  }
  var feitas = typeof WeakSet === 'function' ? new WeakSet() : null;
  function recolorirFolha(folha) {
    if (!folha || (feitas && feitas.has(folha))) return;
    var regras;
    try { regras = folha.cssRules; } catch (e) { return; } // folha de outro domínio (Google Fonts)
    if (!regras) return;
    if (feitas) feitas.add(folha);
    recolorirRegras(regras);
  }
  function recolorirFolhas() {
    for (var i = 0; i < document.styleSheets.length; i++) recolorirFolha(document.styleSheets[i]);
  }
  function recolorirElemento(el) {
    if (el.nodeType !== 1) return;
    if (el.tagName === 'STYLE') recolorirFolha(el.sheet);
    else if (el.tagName === 'LINK' && /stylesheet/i.test(el.rel)) {
      if (el.sheet) recolorirFolha(el.sheet);
      el.addEventListener('load', function () { recolorirFolha(el.sheet); });
    }
    if (el.hasAttribute('style')) recolorirEstilo(el.style);
    ['fill', 'stroke', 'stop-color', 'color'].forEach(function (at) {
      if (el.hasAttribute && el.hasAttribute(at)) { var v = el.getAttribute(at), n = recolorir(v); if (n !== v) el.setAttribute(at, n); }
    });
    var filhos = el.querySelectorAll ? el.querySelectorAll('style,link[rel~="stylesheet"],[style],[fill],[stroke],[stop-color]') : [];
    for (var i = 0; i < filhos.length; i++) {
      var f = filhos[i];
      if (f.tagName === 'STYLE') recolorirFolha(f.sheet);
      else if (f.tagName === 'LINK') { if (f.sheet) recolorirFolha(f.sheet); else f.addEventListener('load', function (ev) { recolorirFolha(ev.target.sheet); }); }
      if (f.hasAttribute('style')) recolorirEstilo(f.style);
      ['fill', 'stroke', 'stop-color'].forEach(function (at) { if (f.hasAttribute(at)) { var v = f.getAttribute(at), n = recolorir(v); if (n !== v) f.setAttribute(at, n); } });
    }
  }

  function iniciar() {
    recolorirFolhas();
    recolorirElemento(document.documentElement);
    // Telas montam conteúdo depois (cards, modais, o aviso de cookies): o que entra também é repintado.
    // Repintar não gera nova troca, porque a cor da marca não é azul.
    new MutationObserver(function (lista) {
      for (var i = 0; i < lista.length; i++) {
        var m = lista[i];
        if (m.type === 'attributes') { if (m.target.nodeType === 1 && m.target.hasAttribute('style')) recolorirEstilo(m.target.style); continue; }
        for (var j = 0; j < m.addedNodes.length; j++) recolorirElemento(m.addedNodes[j]);
      }
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['style'] });
    window.addEventListener('load', recolorirFolhas);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
  window.MarcaCores = { recolorir: recolorir };
})();
