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
    return fetch(API + (opts && opts.qs ? opts.qs : ''), {
      method: (opts && opts.method) || 'GET',
      headers: Object.assign(
        { 'Content-Type': 'application/json' },
        (opts && opts.method && opts.method !== 'GET') ? { 'X-CSRF-Token': CSRF } : {}
      ),
      credentials: 'same-origin',
      cache: 'no-store',
      body: opts && opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (res) {
      if (!res.ok) return null;          // 401/4xx/5xx → silencioso (ex.: sessão expirada)
      return res.json().catch(function () { return null; });
    }).catch(function () { return null; }); // rede off → não quebra a página
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
  function loadList() {
    if (loading) return;
    loading = true;
    elList.innerHTML = '<div class="yuris-notif-empty">Carregando…</div>';
    fetchJson({ qs: '' }).then(function (data) {
      loading = false;
      var items = (data && Array.isArray(data.data)) ? data.data : null;
      if (items === null) {
        elList.innerHTML = '<div class="yuris-notif-empty">Não foi possível carregar.</div>';
        return;
      }
      renderList(items);
    });
  }

  function renderList(items) {
    if (!items.length) {
      elList.innerHTML = '<div class="yuris-notif-empty">Nenhuma notificação.</div>';
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
    elList.innerHTML = html;
    if (elMarkAll) elMarkAll.disabled = !anyUnread;
  }

  // ── Marcar como lida ────────────────────────────────────────────────────────
  function marcarLida(id, itemEl) {
    if (!id) return;
    fetchJson({ method: 'PATCH', body: { id: id, csrf_token: CSRF } }).then(function (data) {
      if (!data || !data.success) return; // falhou → mantém estado atual
      if (itemEl) {
        itemEl.classList.remove('is-unread');
        itemEl.dataset.unread = '0';
        var dot = itemEl.querySelector('.yuris-notif-dot');
        if (dot) dot.remove();
      }
      setBadge(Math.max(0, currentBadgeCount() - 1));
      // Se não sobrou nenhuma não lida visível, desabilita "marcar todas".
      if (elMarkAll && !elList.querySelector('.yuris-notif-item[data-unread="1"]')) {
        elMarkAll.disabled = true;
      }
    });
  }

  function marcarTodasLidas() {
    if (elMarkAll && elMarkAll.disabled) return;
    fetchJson({ method: 'PATCH', body: { all: true, csrf_token: CSRF } }).then(function (data) {
      if (!data || !data.success) return;
      setBadge(0);
      // Limpa estado de não lidas na lista aberta.
      Array.prototype.forEach.call(elList.querySelectorAll('.yuris-notif-item.is-unread'), function (el) {
        el.classList.remove('is-unread');
        el.dataset.unread = '0';
        var dot = el.querySelector('.yuris-notif-dot');
        if (dot) dot.remove();
      });
      if (elMarkAll) elMarkAll.disabled = true;
    });
  }

  // ── Abrir/fechar dropdown ────────────────────────────────────────────────────
  function openPanel() {
    if (open) return;
    open = true;
    elPanel.hidden = false;
    elBtn.setAttribute('aria-expanded', 'true');
    loadList();
    document.addEventListener('click', onDocClick, true);
    document.addEventListener('keydown', onKeydown, true);
  }

  function closePanel() {
    if (!open) return;
    open = false;
    elPanel.hidden = true;
    elBtn.setAttribute('aria-expanded', 'false');
    document.removeEventListener('click', onDocClick, true);
    document.removeEventListener('keydown', onKeydown, true);
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
      if (item.dataset.unread === '1') marcarLida(id, item);
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
