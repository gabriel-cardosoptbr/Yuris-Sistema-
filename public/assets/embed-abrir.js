/* ═══════════════════════════════════════════════════════════════════════════
   embed-abrir.js: abrir o pop-up de verdade de outro módulo sem sair da tela
   (interface nova, 08/11/2026). Par do embed.js.

   "Estou clicando para abrir a tarefa processual e ele está sendo jogado para
   outra página. Tem que só abrir o pop-up, que nem a gente combinou: abrir o
   processo e mostrar qual tarefa processual é, aquela piscada rápida."

   É o mesmo mecanismo da home (inicio-detalhe.js, abrirModulo), para as telas
   de dentro: a tela do módulo abre num iframe com ?embed=1 e mostra só o pop-up
   dela (embed.js), com tudo o que ele tem; enquanto carrega, a forma do pop-up
   em ossos cinza ("tela fantasma") vai até onde o pop-up de verdade parou.
   Fechar o pop-up tira o iframe e a tela continua onde estava.

   Uso: YurisEmbed.abrir('/processos?open=12&foco=tarefa:34', { aoFechar: fn }).
   Carregado por includes/sidebar.php só na interface nova e fora do ?embed=1.
   Sem ele (interface antiga), quem chama cai no link de sempre.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.YurisEmbed) return;
  var html = document.documentElement;
  var atual = null;

  function osso(cls, est) { return '<i class="inn-emb-osso' + (cls ? ' ' + cls : '') + '"' + (est ? ' style="' + est + '"' : '') + '></i>'; }
  function fantasma(tela) {
    var cab = '<div class="inn-emb-fan-cab">' + osso('quad') + '<span>' + osso('', 'width:42%;height:16px') + osso('', 'width:26%;height:11px;margin-top:7px') + '</span>' + osso('quad peq') + '</div>';
    var campos = '';
    for (var i = 0; i < 6; i++) campos += '<span class="inn-emb-fan-campo">' + osso('', 'width:' + (30 + (i * 13) % 30) + '%;height:10px') + osso('', 'height:38px') + '</span>';
    var abas = tela === 'tarefas' ? '<div class="inn-emb-fan-abas">' + [70, 80, 60, 64, 58].map(function (w) { return osso('', 'width:' + w + 'px;height:12px'); }).join('') + '</div>' : '';
    return cab + '<div class="inn-emb-fan-corpo">' + abas + '<div class="inn-emb-fan-grade">' + campos + '</div>' + osso('', 'height:92px;margin-top:12px') + '</div>'
      + '<div class="inn-emb-fan-pe">' + osso('', 'width:120px;height:38px') + osso('', 'width:150px;height:38px') + '</div>';
  }

  function abrir(url, opc) {
    if (atual) return;
    opc = opc || {};
    var tela = (/^\/([a-z_]+)/.exec(url) || [])[1] || '';
    var caixa = document.createElement('div');
    caixa.className = 'inn-emb';
    var fan = document.createElement('div');
    fan.className = 'inn-emb-fantasma';
    fan.innerHTML = fantasma(tela);
    caixa.appendChild(fan);
    var f = document.createElement('iframe');
    f.className = 'inn-emb-quadro'; f.title = 'Pop-up do módulo';
    f.src = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'embed=1';
    caixa.appendChild(f);
    // Clicar fora enquanto ainda carrega desiste de abrir (o quadro ainda não pega clique).
    caixa.addEventListener('mousedown', function () { if (atual && atual.caixa === caixa && !caixa.classList.contains('pronto')) fechar(); });
    document.body.appendChild(caixa);
    html.classList.add('inn-emb-aberto');
    atual = { caixa: caixa, quadro: f, aoFechar: opc.aoFechar };
    void caixa.offsetWidth; caixa.classList.add('visivel');
  }

  function fechar() {
    if (!atual) return;
    var a = atual; atual = null;
    a.caixa.classList.remove('visivel');
    html.classList.remove('inn-emb-aberto');
    setTimeout(function () { a.caixa.remove(); }, 180);
    if (typeof a.aoFechar === 'function') { try { a.aoFechar(); } catch (e) {} }
  }

  // Mostra o pop-up e avisa o quadro: lá dentro o conteúdo entra de novo, cartão por cartão,
  // agora que está à vista (a entrada de quando ele abriu passou escondida, 10/11/2026).
  function mostrar(caixa) {
    caixa.classList.add('pronto');
    var q = caixa.querySelector('.inn-emb-quadro');
    try { q.contentWindow.postMessage({ yuris: 'embed-visivel' }, location.origin); } catch (e) {}
  }
  // O pop-up chegou: a forma vai até a medida dele e some por baixo do pop-up de verdade.
  function morfar(c) {
    if (!atual) return;
    var caixa = atual.caixa, fan = caixa.querySelector('.inn-emb-fantasma');
    var comMovimento = html.classList.contains('inn-mov') && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var focar = function () { try { atual.quadro.focus(); } catch (e) {} };
    if (!fan || !c || !c.width || !comMovimento) { mostrar(caixa); focar(); return; }
    c = { left: Math.max(0, c.left), top: Math.max(0, c.top), width: Math.min(c.width, innerWidth - Math.max(0, c.left)), height: Math.min(c.height, innerHeight - Math.max(0, c.top)) };
    var r = fan.getBoundingClientRect();
    fan.style.transition = 'none'; fan.style.transform = 'none';
    fan.style.left = r.left + 'px'; fan.style.top = r.top + 'px'; fan.style.width = r.width + 'px'; fan.style.height = r.height + 'px';
    void fan.offsetWidth;
    fan.style.transition = ''; fan.classList.add('morfa');
    fan.style.left = c.left + 'px'; fan.style.top = c.top + 'px'; fan.style.width = c.width + 'px'; fan.style.height = c.height + 'px';
    setTimeout(function () { if (atual && atual.caixa === caixa) { mostrar(caixa); focar(); } }, 400);
  }

  function avisar(msg) {
    if (typeof window.showToast === 'function') { window.showToast(msg, { type: 'error', duration: 4000 }); return; }
    if (window.Yuris && typeof Yuris.notify === 'function') Yuris.notify(msg, 'error');
  }

  window.addEventListener('message', function (e) {
    if (!atual || e.origin !== location.origin || e.source !== atual.quadro.contentWindow || !e.data || !e.data.yuris) return;
    if (e.data.yuris === 'embed-pronto') morfar(e.data.caixa);
    if (e.data.yuris === 'embed-fechar') fechar();
    // Não abriu (sem acesso, item apagado): fica na tela e avisa.
    if (e.data.yuris === 'embed-sem-popup') { fechar(); avisar('Não consegui abrir agora. Tente de novo.'); }
  });
  // Esc fecha: com o foco aqui fora (o pop-up já aberto recebe o foco, e lá dentro o
  // embed.js cuida da tecla), ou ainda carregando.
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && atual) fechar(); });

  window.YurisEmbed = { abrir: abrir, fechar: fechar, aberto: function () { return !!atual; } };
})();
