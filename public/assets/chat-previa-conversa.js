/* chat-previa-conversa.js — prévia da conversa ao passar o mouse na lista (edição Fleetiflow).
 *
 * Como no WhatsApp: parar o mouse numa conversa por meio segundo mostra, ao lado
 * da lista, as últimas mensagens. Só lê (ChatApp.previaConversa → messages.php),
 * então quem tem não lidas continua com não lidas: dá para ver se a pessoa
 * respondeu sem "abrir" a conversa. A conversa aberta não ganha prévia.
 */
(function () {
  'use strict';
  // ChatApp é `const` no chat.js: existe no escopo global, mas NÃO em window.ChatApp.
  if (typeof ChatApp === 'undefined' || typeof ChatApp.previaConversa !== 'function') return;
  const lista = document.getElementById('chatList');
  if (!lista) return;
  // Sem mouse (celular, tablet) não há "passar por cima".
  if (window.matchMedia && !window.matchMedia('(hover: hover)').matches) return;

  const ESPERA_MS = 500;
  const cache = new Map();   // jid -> { chave, linhas }: refaz quando chega mensagem nova
  let painel = null;
  let alvo = null;           // jid sob o mouse (a lista se redesenha a cada poucos segundos,
                             // então o elemento muda; a conversa, não)
  let timer = null;
  let pedido = 0;            // descarta resposta de um hover que já passou

  function fechar() {
    clearTimeout(timer);
    timer = null;
    alvo = null;
    pedido++;
    if (painel) { painel.remove(); painel = null; }
  }

  function itemDe(jid) {
    return lista.querySelector('.chat-item[data-jid="' + (window.CSS && CSS.escape ? CSS.escape(jid) : jid) + '"]');
  }

  function posicionar(jid) {
    const el = itemDe(jid);
    if (!painel || !el) { if (painel && !el) fechar(); return; }
    const r = el.getBoundingClientRect();
    const larg = painel.offsetWidth;
    const alt = painel.offsetHeight;
    // À direita da lista; se não couber, à esquerda.
    let x = r.right + 10;
    if (x + larg > window.innerWidth - 8) x = Math.max(8, r.left - larg - 10);
    let y = r.top;
    if (y + alt > window.innerHeight - 8) y = Math.max(8, window.innerHeight - alt - 8);
    painel.style.left = x + 'px';
    painel.style.top = y + 'px';
  }

  function corpo(linhas) {
    if (!linhas.length) return '<div class="ffp-vazio">Sem mensagens salvas nesta conversa.</div>';
    return linhas.map(function (l) {
      return '<div class="ffp-msg ' + (l.entrada ? 'ffp-in' : 'ffp-out') + '">' +
        (l.autor ? '<div class="ffp-autor">' + l.autor + '</div>' : '') +
        (l.midia ? '<span class="ffp-midia">' + l.midia + '</span>' + (l.texto ? ' ' : '') : '') +
        (l.texto ? '<span class="ffp-texto">' + l.texto + '</span>' : '') +
        '<span class="ffp-hora">' + l.hora + '</span></div>';
    }).join('');
  }

  function montar(jid, linhas, carregando) {
    const el = itemDe(jid);
    if (!el) return;
    const c = ChatApp.dadosConversa(jid) || {};
    const nome = (el.querySelector('.chat-item-name') || {}).textContent || '';
    const naoLidas = parseInt(c.unread_count, 10) || 0;
    if (!painel) {
      painel = document.createElement('div');
      painel.className = 'ffp-painel';
      painel.setAttribute('role', 'tooltip');
      document.body.appendChild(painel);
    }
    const esc = function (s) { return String(s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; }); };
    painel.innerHTML =
      '<div class="ffp-topo">' +
        '<strong class="ffp-nome">' + esc(nome.trim()) + '</strong>' +
        (naoLidas > 0
          ? '<span class="ffp-selo">' + (naoLidas > 99 ? '99+' : naoLidas) + (naoLidas === 1 ? ' não lida' : ' não lidas') + ' · continua assim</span>'
          : '<span class="ffp-selo ffp-selo-neutro">Prévia</span>') +
      '</div>' +
      '<div class="ffp-corpo">' + (carregando ? '<div class="ffp-vazio">Carregando…</div>' : corpo(linhas)) + '</div>';
    posicionar(jid);
    const cp = painel.querySelector('.ffp-corpo');
    if (cp) cp.scrollTop = cp.scrollHeight; // a mais recente embaixo, à vista
  }

  async function mostrar(jid) {
    const el = itemDe(jid);
    if (!el || el.classList.contains('active')) return;
    const c = ChatApp.dadosConversa(jid) || {};
    const chave = String(c.last_message_at || '') + '|' + String(c.last_message_content || '');
    const guardada = cache.get(jid);
    if (guardada && guardada.chave === chave) { montar(jid, guardada.linhas, false); return; }
    const meu = ++pedido;
    montar(jid, [], true);
    try {
      const linhas = await ChatApp.previaConversa(jid, 6);
      cache.set(jid, { chave: chave, linhas: linhas });
      if (meu !== pedido || alvo !== jid) return;
      montar(jid, linhas, false);
    } catch (e) {
      if (meu !== pedido || alvo !== jid) return;
      if (painel) painel.querySelector('.ffp-corpo').innerHTML = '<div class="ffp-vazio">Não deu para carregar a prévia.</div>';
    }
  }

  lista.addEventListener('mouseover', function (e) {
    const el = e.target.closest('.chat-item[data-jid]');
    const jid = el ? el.getAttribute('data-jid') : null;
    if (!jid || jid === alvo) return;
    fechar();
    alvo = jid;
    timer = setTimeout(function () { if (alvo === jid) mostrar(jid); }, ESPERA_MS);
  });
  lista.addEventListener('mouseleave', fechar);
  lista.addEventListener('scroll', fechar, { passive: true });
  lista.addEventListener('mousedown', fechar);      // clicou: vai abrir a conversa
  lista.addEventListener('contextmenu', fechar);    // menu do botão direito tem a vez
  window.addEventListener('blur', fechar);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });
})();
