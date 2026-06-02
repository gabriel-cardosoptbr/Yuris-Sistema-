/**
 * notifications.js — Sino de notificações da conta (central account_notifications).
 *
 * Pluga a API /api/account_notifications.php na UI (Auditoria 2026-06-01, #29/#7):
 *   • GET  ?count=1  → atualiza o badge de não lidas (polling leve)
 *   • GET            → lista as notificações ao abrir o dropdown
 *   • PATCH {id}     → marca UMA notificação como lida
 *   • PATCH {all:1}  → marca TODAS como lidas
 *
 * Markup é renderizado server-side em includes/sidebar.php (#yurisNotif).
 * Config (token CSRF + endpoint) vem de window.YURIS_NOTIF, injetado pelo sidebar.
 * Sem dependências externas; auto-inicializa em DOMContentLoaded.
 */
(function () {
  'use strict';

  var CFG  = window.YURIS_NOTIF || {};
  var API  = CFG.api || '/api/account_notifications.php';
  var CSRF = CFG.csrf || '';
  var POLL_MS = 60000; // atualiza o badge a cada 60s

  // Elementos (podem não existir em páginas sem sidebar — então abortamos).
  var elWrap, elBtn, elBadge, elPanel, elList, elMarkAll;

  var open = false;
  var loading = false;
  var pollTimer = null;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Formata um datetime MySQL ("YYYY-MM-DD HH:MM:SS") em tempo relativo PT-BR.
  function tempoRelativo(raw) {
    if (!raw) return '';
    // Safari/strict não parseiam "YYYY-MM-DD HH:MM:SS" — troca o espaço por "T".
    var d = new Date(String(raw).replace(' ', 'T'));
    var t = d.getTime();
    if (isNaN(t)) return esc(raw);
    var diff = Math.floor((Date.now() - t) / 1000); // segundos
    if (diff < 0) diff = 0;
    if (diff < 60)    return 'agora mesmo';
    if (diff < 3600)  return 'há ' + Math.floor(diff / 60) + ' min';
    if (diff < 86400) return 'há ' + Math.floor(diff / 3600) + ' h';
    if (diff < 604800) return 'há ' + Math.floor(diff / 86400) + ' d';
    return d.toLocaleDateString('pt-BR');
  }

  function fetchJson(opts) {
    // Timeout duro (12s): mesmo que o servidor pendure (contenção de sessão, rede
    // ruim), o fetch é abortado e a Promise resolve em null — a UI nunca fica presa
    // em "Carregando…" pra sempre; mostra "Não foi possível carregar" e o usuário
    // reabre pra tentar de novo.
    var ctrl  = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 12000) : null;
    var done  = function () { if (timer) { clearTimeout(timer); timer = null; } };
    return fetch(API + (opts && opts.qs ? opts.qs : ''), {
      method: (opts && opts.method) || 'GET',
      headers: Object.assign(
        { 'Content-Type': 'application/json' },
        (opts && opts.method && opts.method !== 'GET') ? { 'X-CSRF-Token': CSRF } : {}
      ),
      credentials: 'same-origin',
      cache: 'no-store',
      signal: ctrl ? ctrl.signal : undefined,
      body: opts && opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (res) {
      done();
      if (!res.ok) return null;          // 401/4xx/5xx → silencioso (ex.: sessão expirada)
      return res.json().catch(function () { return null; });
    }).catch(function () { done(); return null; }); // rede off / abort → não quebra a página
  }

  // ── Badge ──────────────────────────────────────────────────────────────────
  function refreshBadge() {
    fetchJson({ qs: '?count=1' }).then(function (data) {
      if (!data || typeof data.count === 'undefined') return;
      setBadge(parseInt(data.count, 10) || 0);
    });
  }

  function setBadge(n) {
    if (!elBadge) return;
    if (n > 0) {
      elBadge.textContent = n > 99 ? '99+' : String(n);
      elBadge.hidden = false;
      if (elBtn) elBtn.classList.add('has-unread');
    } else {
      elBadge.hidden = true;
      if (elBtn) elBtn.classList.remove('has-unread');
    }
  }

  function currentBadgeCount() {
    if (!elBadge || elBadge.hidden) return 0;
    var v = parseInt(elBadge.textContent, 10);
    return isNaN(v) ? 0 : v;
  }

  // ── Lista ──────────────────────────────────────────────────────────────────
  // Re-busca o elemento AO VIVO a cada uso: robusto a re-render do sidebar / a uma
  // referência cacheada que ficou "detached" (causa do sino preso em "Carregando…"
  // mesmo com o endpoint respondendo 200/itens). Sem trava 'loading' persistente.
  function listEl() { return document.getElementById('yurisNotifList') || elList; }

  function loadList() {
    // A lista JÁ vem renderizada do servidor (sidebar.php) — fonte da verdade da
    // exibição. Aqui só atualizamos em SILÊNCIO se o fetch trouxer dados; em erro
    // ou fetch lento, mantém o que já está visível (nunca volta pra "Carregando…").
    fetchJson({ qs: '?unread=1' }).then(function (data) {
      if (data && Array.isArray(data.data)) renderList(data.data);
    });
  }

  function renderList(items) {
    var el = listEl();
    if (!el) return;
    if (items === null) {
      el.innerHTML = '<div class="yuris-notif-empty">Não foi possível carregar.</div>';
      return;
    }
    if (!items.length) {
      el.innerHTML = '<div class="yuris-notif-empty">Nenhuma notificação.</div>';
      if (elMarkAll) elMarkAll.disabled = true;
      return;
    }
    var anyUnread = false;
    var html = items.map(function (it) {
      var naoLida = !(it.lida == 1 || it.lida === true);
      if (naoLida) anyUnread = true;
      var titulo = esc(it.titulo || 'Notificação');
      var msg    = it.mensagem ? '<p class="yuris-notif-item-msg">' + esc(it.mensagem) + '</p>' : '';
      var dot    = naoLida ? '<span class="yuris-notif-dot" aria-hidden="true"></span>' : '';
      return '<button type="button" class="yuris-notif-item' + (naoLida ? ' is-unread' : '') + '"' +
             ' data-id="' + (parseInt(it.id, 10) || 0) + '" data-unread="' + (naoLida ? '1' : '0') + '">' +
               '<span class="yuris-notif-item-titulo">' + dot + titulo + '</span>' +
               msg +
               '<span class="yuris-notif-item-time">' + tempoRelativo(it.created_at) + '</span>' +
             '</button>';
    }).join('');
    el.innerHTML = html;
    if (elMarkAll) elMarkAll.disabled = !anyUnread;
  }

  // ── Marcar como lida ────────────────────────────────────────────────────────
  // Remove um item da lista com fade; se a lista esvaziar, mostra "Nenhuma…".
  function dismissItem(itemEl) {
    if (!itemEl) return;
    itemEl.style.transition = 'opacity .18s ease';
    itemEl.style.opacity = '0';
    setTimeout(function () {
      itemEl.remove();
      var le = listEl();
      if (le && !le.querySelector('.yuris-notif-item')) {
        le.innerHTML = '<div class="yuris-notif-empty">Nenhuma notificação.</div>';
        if (elMarkAll) elMarkAll.disabled = true;
      }
    }, 180);
  }

  // Clicar = CONCLUIR: marca como lida no servidor e REMOVE o item da lista (o sino
  // só mostra pendências, então some e não volta no reload). Feedback = o fade-out.
  function marcarLida(id, itemEl) {
    if (!id) { dismissItem(itemEl); return; }
    var wasUnread = !itemEl || itemEl.dataset.unread === '1';
    fetchJson({ method: 'PATCH', body: { id: id, csrf_token: CSRF } }).then(function (data) {
      if (!data || !data.success) return; // falhou → mantém o item
      if (wasUnread) setBadge(Math.max(0, currentBadgeCount() - 1));
      dismissItem(itemEl);
    });
  }

  function marcarTodasLidas() {
    if (elMarkAll && elMarkAll.disabled) return;
    fetchJson({ method: 'PATCH', body: { all: true, csrf_token: CSRF } }).then(function (data) {
      if (!data || !data.success) return;
      setBadge(0);
      // Concluiu todas → esvazia o sino (só mostra pendências).
      var le = listEl();
      if (le) le.innerHTML = '<div class="yuris-notif-empty">Nenhuma notificação.</div>';
      if (elMarkAll) elMarkAll.disabled = true;
    });
  }

  // ── Abrir/fechar dropdown ────────────────────────────────────────────────────
  // Ancora o painel (position:fixed) logo abaixo do sino, alinhado pela direita e
  // sempre dentro da viewport. Fixed escapa do clipping/overflow da sidebar estreita.
  function positionPanel() {
    if (!elPanel || !elBtn) return;
    var r = elBtn.getBoundingClientRect();
    var w = elPanel.offsetWidth || 340;
    var left = r.right - w;
    var maxLeft = window.innerWidth - w - 8;
    if (left > maxLeft) left = maxLeft;
    if (left < 8) left = 8;
    elPanel.style.top  = Math.round(r.bottom + 8) + 'px';
    elPanel.style.left = Math.round(left) + 'px';
  }

  function openPanel() {
    if (open) return;
    open = true;
    elPanel.hidden = false;
    positionPanel();
    elBtn.setAttribute('aria-expanded', 'true');
    loadList();
    document.addEventListener('click', onDocClick, true);
    document.addEventListener('keydown', onKeydown, true);
    window.addEventListener('resize', positionPanel);
    window.addEventListener('scroll', positionPanel, true);
  }

  function closePanel() {
    if (!open) return;
    open = false;
    elPanel.hidden = true;
    elBtn.setAttribute('aria-expanded', 'false');
    document.removeEventListener('click', onDocClick, true);
    document.removeEventListener('keydown', onKeydown, true);
    window.removeEventListener('resize', positionPanel);
    window.removeEventListener('scroll', positionPanel, true);
  }

  function onDocClick(e) {
    if (elWrap && !elWrap.contains(e.target)) closePanel();
  }

  function onKeydown(e) {
    if (e.key === 'Escape' || e.keyCode === 27) closePanel();
  }

  // ── Init ────────────────────────────────────────────────────────────────────
  function init() {
    elWrap    = document.getElementById('yurisNotif');
    elBtn     = document.getElementById('yurisNotifBtn');
    elBadge   = document.getElementById('yurisNotifBadge');
    elPanel   = document.getElementById('yurisNotifPanel');
    elList    = document.getElementById('yurisNotifList');
    elMarkAll = document.getElementById('yurisNotifMarkAll');
    if (!elWrap || !elBtn || !elPanel || !elList) return; // página sem sino

    elBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      open ? closePanel() : openPanel();
    });

    if (elMarkAll) {
      elMarkAll.addEventListener('click', function (e) {
        e.stopPropagation();
        marcarTodasLidas();
      });
    }

    // Delegação: clique num item marca lida (e segue link do payload, se houver).
    elList.addEventListener('click', function (e) {
      var item = e.target.closest('.yuris-notif-item');
      if (!item) return;
      var id = parseInt(item.dataset.id, 10) || 0;
      marcarLida(id, item); // clicar = concluir (marca lida + remove da lista)
    });

    // Badge: carrega já e em polling leve. Pausa quando a aba está oculta.
    refreshBadge();
    startPolling();
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { stopPolling(); }
      else { refreshBadge(); startPolling(); }
    });
  }

  function startPolling() {
    stopPolling();
    pollTimer = setInterval(refreshBadge, POLL_MS);
  }
  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
