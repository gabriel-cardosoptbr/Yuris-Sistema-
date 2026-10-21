/* ═══════════════════════════════════════════════════════════════════════
   quadro-rolagem.js: rolagem lateral de quadro Kanban (21/10/2026).
   Em todo elemento com [data-rolagem-lateral] (hoje o quadro de Clientes):
     - a barra de rolagem fica só em cima (a de baixo some);
     - segurar o mouse no fundo do quadro (ou de uma coluna) e arrastar para
       o lado rola o quadro, como no Trello. Segurar um card continua
       movendo o card (SortableJS); link, botão e campo não começam o arrasto.
   No toque (celular) o quadro já rola com o dedo; a barra de cima continua.
   window.QuadroRolagem.ligar(el) liga num elemento criado depois.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var CSS = '.qr-topo{overflow-x:auto;overflow-y:hidden;height:14px;margin:0 2px 6px}.qr-topo>div{height:1px}' +
    // Só a barra de cima: a de baixo some (o quadro continua rolando pela de cima, arrastando e no toque).
    '.qr-sem-barra{scrollbar-width:none}.qr-sem-barra::-webkit-scrollbar{display:none;height:0}' +
    '.qr-pan{cursor:grab}.qr-pan.qr-arrastando,.qr-pan.qr-arrastando *{cursor:grabbing!important;user-select:none}';
  // O que não começa o arrasto do quadro: card (arrasta o card) e o que é clicável.
  var NAO = '.cli-card, .ffc-copia, .card-mini, a, button, input, select, textarea, label, [contenteditable="true"], [role="button"]';

  function estilo() {
    if (document.getElementById('qrCss')) return;
    var s = document.createElement('style'); s.id = 'qrCss'; s.textContent = CSS; document.head.appendChild(s);
  }

  function ligar(board) {
    if (!board || board._qrLigado) return;
    board._qrLigado = true;
    estilo();

    // ── Barra de cima ────────────────────────────────────────────────────
    var topo = document.createElement('div'), miolo = document.createElement('div');
    topo.className = 'qr-topo'; topo.setAttribute('aria-hidden', 'true');
    topo.appendChild(miolo);
    board.parentNode.insertBefore(topo, board);
    function medir() {
      miolo.style.width = board.scrollWidth + 'px';
      topo.style.display = board.scrollWidth > board.clientWidth + 1 ? '' : 'none';
      if (Math.abs(topo.scrollLeft - board.scrollLeft) > 1) topo.scrollLeft = board.scrollLeft;
    }
    // Cada lado só mexe no outro se estiverem diferentes: o eco não volta. Enquanto a
    // barra de cima anda, o encaixe das colunas fica desligado (senão dá tranco).
    var SNAP = board.style.scrollSnapType, voltaSnap = null;
    topo.addEventListener('scroll', function () {
      if (Math.abs(board.scrollLeft - topo.scrollLeft) <= 1) return;
      board.style.scrollSnapType = 'none';
      board.scrollLeft = topo.scrollLeft;
      clearTimeout(voltaSnap);
      voltaSnap = setTimeout(function () { if (!ini) board.style.scrollSnapType = SNAP; }, 300);
    });
    board.addEventListener('scroll', function () { if (Math.abs(topo.scrollLeft - board.scrollLeft) > 1) topo.scrollLeft = board.scrollLeft; });
    var pedido = null, ini = null, ultimoArrasto = 0;
    function medirDepois() { if (pedido) return; pedido = setTimeout(function () { pedido = null; medir(); }, 60); }
    if (window.ResizeObserver) new ResizeObserver(medirDepois).observe(board);
    new MutationObserver(medirDepois).observe(board, { childList: true, subtree: true });
    window.addEventListener('resize', medirDepois);
    medir();

    // ── Arrastar o quadro para o lado ────────────────────────────────────
    board.classList.add('qr-pan', 'qr-sem-barra');
    board.addEventListener('pointerdown', function (e) {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;
      if (e.target.closest(NAO)) return;
      ini = { x: e.clientX, esquerda: board.scrollLeft, moveu: false };
    });
    window.addEventListener('pointermove', function (e) {
      if (!ini) return;
      var dx = e.clientX - ini.x;
      if (!ini.moveu) {
        if (Math.abs(dx) < 5) return;
        ini.moveu = true;
        board.style.scrollSnapType = 'none';    // o encaixe das colunas brigaria com o arrasto
        board.classList.add('qr-arrastando');
      }
      board.scrollLeft = ini.esquerda - dx;
      e.preventDefault();
    });
    function soltar() {
      if (!ini) return;
      if (ini.moveu) {
        board.classList.remove('qr-arrastando');
        board.style.scrollSnapType = SNAP;
        ultimoArrasto = Date.now();
      }
      ini = null;
    }
    window.addEventListener('pointerup', soltar);
    window.addEventListener('pointercancel', soltar);
    // Soltar depois de arrastar não vale como clique (não abre ficha nem nada).
    board.addEventListener('click', function (e) { if (Date.now() - ultimoArrasto < 80) { e.stopPropagation(); e.preventDefault(); } }, true);
  }

  window.QuadroRolagem = { ligar: ligar };
  function iniciar() { document.querySelectorAll('[data-rolagem-lateral]').forEach(ligar); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
