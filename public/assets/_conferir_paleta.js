/* Ferramenta de conferência da paleta (App\Core\PaletaTema). NÃO é carregada por
 * nenhuma tela: carrega-se à mão, em desenvolvimento, no console de uma tela do
 * sistema já com login feito.
 *
 * A paleta só pode trocar o TOM. Este script tira uma "assinatura" de cada
 * elemento (tem fundo? tem degradê? largura da borda? sombra? opacidade? a cor é
 * neutra/azul, verde, quente ou roxa?) com a paleta desligada e de novo com ela
 * ligada. Qualquer diferença é defeito. Foi o que faltou na primeira versão, que
 * foi ao ar com um retângulo no menu e borda verde onde não havia.
 *
 *   await conferirPaleta.varrer(['dashboard', 'tarefas'])
 *
 * devolve, por tela, quantos elementos mudaram de assinatura e exemplos. Vale
 * para o tema que estiver escolhido (localStorage.yuris_theme).
 */
window.conferirPaleta = (function () {
  function partes(cor) {
    const m = /rgba?\(([^)]+)\)/.exec(cor || '');
    if (!m) return null;
    const p = m[1].split(',').map(function (v) { return parseFloat(v); });
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  }
  function familia(cor) {
    const c = partes(cor);
    if (!c || c.a === 0) return 'nada';
    const mx = Math.max(c.r, c.g, c.b), mn = Math.min(c.r, c.g, c.b), d = mx - mn;
    const l = (mx + mn) / 510, s = d === 0 ? 0 : d / 255 / (1 - Math.abs(2 * l - 1));
    if (s < 0.2) return 'neutro-azul';
    let h = mx === c.r ? ((c.g - c.b) / d) % 6 : mx === c.g ? (c.b - c.r) / d + 2 : (c.r - c.g) / d + 4;
    h = (h * 60 + 360) % 360;
    if (h >= 175 && h < 262) return 'neutro-azul';   // prata e azul são a mesma família aqui
    if (h >= 80 && h < 175) return 'verde';
    if (h >= 262 && h < 335) return 'roxo';
    return 'quente';
  }
  function caminho(el) {
    const p = [];
    for (let e = el; e && e.tagName !== 'BODY' && p.length < 6; e = e.parentElement) {
      const i = e.parentElement ? Array.prototype.indexOf.call(e.parentElement.children, e) : 0;
      p.unshift(e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (typeof e.className === 'string' && e.className ? '.' + e.className.trim().split(/\s+/)[0] : '') + ':' + i);
    }
    return p.join('>');
  }
  function assinaturas(doc) {
    const mapa = {};
    const ver = doc.defaultView;
    doc.querySelectorAll('body *').forEach(function (el) {
      if (/^(SCRIPT|STYLE|LINK|META|NOSCRIPT)$/.test(el.tagName)) return;
      const c = ver.getComputedStyle(el);
      const borda = parseFloat(c.borderTopWidth) > 0 ? familia(c.borderTopColor) : 'sem-borda';
      mapa[caminho(el)] = [
        'fundo:' + familia(c.backgroundColor),
        c.backgroundImage === 'none' ? 'sem-imagem' : (c.backgroundImage.indexOf('gradient') >= 0 ? 'degrade' : 'imagem'),
        'borda:' + c.borderTopWidth + ' ' + c.borderLeftWidth + ' ' + borda,
        c.boxShadow === 'none' ? 'sem-sombra' : 'sombra',
        'opacidade:' + c.opacity,
        'texto:' + familia(c.color),
        c.display === 'none' ? 'oculto' : 'visivel',
      ].join(' | ');
    });
    return mapa;
  }
  function abrir(quadro, url) {
    return new Promise(function (ok) {
      quadro.onload = function () { setTimeout(ok, 1800); };
      quadro.src = url;
    });
  }
  async function varrer(paginas) {
    const quadro = document.createElement('iframe');
    quadro.style.cssText = 'position:fixed;left:-3000px;top:0;width:1360px;height:900px;border:0';
    document.body.appendChild(quadro);
    const saida = {};
    for (const p of paginas) {
      await abrir(quadro, '/' + p + '.php?paleta=0');
      const antes = assinaturas(quadro.contentDocument);
      await abrir(quadro, '/' + p + '.php?paleta=1');
      const doc = quadro.contentDocument;
      const depois = assinaturas(doc);
      const ligou = doc.querySelectorAll('link[href*="tema.php"]').length;
      const dif = [];
      Object.keys(antes).forEach(function (k) {
        if (depois[k] === undefined || depois[k] === antes[k]) return;
        const a = antes[k].split(' | '), d = depois[k].split(' | ');
        dif.push(k.split('>').slice(-2).join('>') + '  ' + a.filter(function (x, i) { return x !== d[i]; }).join(', ') + '  =>  ' + d.filter(function (x, i) { return x !== a[i]; }).join(', '));
      });
      saida[p] = { elementos: Object.keys(antes).length, paleta: ligou, diferentes: dif.length, exemplos: dif.slice(0, 6) };
    }
    try { localStorage.removeItem('yuris_paleta'); } catch (e) {}
    quadro.remove();
    return saida;
  }
  return { varrer: varrer };
})();
