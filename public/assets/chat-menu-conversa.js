/* chat-menu-conversa.js — menu do botão direito na lista de conversas (edição Fleetiflow).
 *
 * Como no WhatsApp: botão direito numa conversa abre fixar, marcar como lida/não
 * lida, arquivar e excluir. As ações são as que o chat já tem (chats.php), via
 * ChatApp.acaoConversa(jid, acao), e valem para o número da aba aberta.
 * O texto de cada opção acompanha o estado da conversa (fixada? tem não lidas?).
 */
(function () {
  'use strict';
  if (!window.ChatApp || typeof ChatApp.acaoConversa !== 'function') return;
  const lista = document.getElementById('chatList');
  if (!lista) return;

  const ICONES = {
    abrir    : '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    fixar    : '<line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V17z"/>',
    lida     : '<polyline points="20 6 9 17 4 12"/>',
    nao_lida : '<circle cx="12" cy="12" r="4"/>',
    arquivar : '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
    excluir  : '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/>',
  };

  let menu = null;

  function fechar() {
    if (menu) { menu.remove(); menu = null; }
  }

  function item(acao, rotulo, perigo) {
    return '<button type="button" class="ffm-item' + (perigo ? ' ffm-perigo' : '') + '" role="menuitem" data-acao="' + acao + '">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + ICONES[acao] + '</svg>' +
      '<span>' + rotulo + '</span></button>';
  }

  function abrir(jid, x, y) {
    fechar();
    const c = ChatApp.dadosConversa(jid) || {};
    const fixada    = c.is_pinned == 1;
    const arquivada = c.is_archived == 1;
    const naoLidas  = parseInt(c.unread_count, 10) > 0;

    menu = document.createElement('div');
    menu.className = 'ffm-menu';
    menu.setAttribute('role', 'menu');
    menu.innerHTML =
      item('abrir', 'Abrir conversa') +
      item('fixar', fixada ? 'Desafixar conversa' : 'Fixar conversa') +
      (naoLidas ? item('lida', 'Marcar como lida') : item('nao_lida', 'Marcar como não lida')) +
      item('arquivar', arquivada ? 'Desarquivar conversa' : 'Arquivar conversa') +
      '<div class="ffm-sep"></div>' +
      item('excluir', 'Excluir conversa', true);
    document.body.appendChild(menu);

    // Abre onde o mouse está, sem sair da tela.
    const r = menu.getBoundingClientRect();
    const px = Math.min(x, window.innerWidth - r.width - 8);
    const py = Math.min(y, window.innerHeight - r.height - 8);
    menu.style.left = Math.max(8, px) + 'px';
    menu.style.top  = Math.max(8, py) + 'px';

    menu.addEventListener('click', function (e) {
      const b = e.target.closest('[data-acao]');
      if (!b) return;
      const acao = b.getAttribute('data-acao');
      fechar();
      if (acao === 'abrir') { ChatApp.openChatByJid(jid); return; }
      ChatApp.acaoConversa(jid, acao);
    });
    const primeiro = menu.querySelector('.ffm-item');
    if (primeiro) primeiro.focus({ preventScroll: true });
  }

  lista.addEventListener('contextmenu', function (e) {
    const el = e.target.closest('.chat-item[data-jid]');
    if (!el) return;
    e.preventDefault();
    abrir(el.getAttribute('data-jid'), e.clientX, e.clientY);
  });

  document.addEventListener('mousedown', function (e) { if (menu && !menu.contains(e.target)) fechar(); });
  document.addEventListener('keydown', function (e) {
    if (!menu) return;
    if (e.key === 'Escape') { fechar(); return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const itens = Array.from(menu.querySelectorAll('.ffm-item'));
      const i = itens.indexOf(document.activeElement);
      const prox = e.key === 'ArrowDown' ? (i + 1) % itens.length : (i - 1 + itens.length) % itens.length;
      itens[prox].focus();
    }
  });
  window.addEventListener('blur', fechar);
  window.addEventListener('resize', fechar);
  lista.addEventListener('scroll', fechar, { passive: true });
})();
