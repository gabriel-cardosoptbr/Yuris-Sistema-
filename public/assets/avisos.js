/* ═══════════════════════════════════════════════════════════════════════
   avisos.js: o "bling", o aviso na tela e os avisos no aparelho (22/10/2026).

   Três peças, todas em window.YurisAvisos:

   · mostrar(aviso)  toca dois toques curtos (gerados na hora pelo navegador,
                     sem arquivo de som) e mostra o aviso no canto da tela, com
                     o link para abrir. Quem chama: o notifications.js (a cada
                     20 s, os avisos de tempo real novos do sino) e o sw.js
                     (push que chega com a página aberta e em foco). O mesmo id
                     nunca toca duas vezes.
   · ativar() / desativar() / testar() / estado()
                     o Web Push deste aparelho: pede a permissão, inscreve no
                     serviço de push do navegador e manda a inscrição para
                     /api/avisos_push.php. Com o sistema fechado, quem mostra a
                     notificação é o public/sw.js.
   · o card "Receber avisos no aparelho" da tela inicial (sidebar.php).

   O navegador só deixa tocar som depois do primeiro toque/clique na página:
   o som é destravado no primeiro gesto e, antes dele, o aviso aparece mudo.
   Config: window.YURIS_AVISOS = { csrf, chave, usuario } (sidebar.php).
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var CFG = window.YURIS_AVISOS || {};
  var vistos = {};

  function guardado(k, v) {
    try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (_) {}
    return null;
  }

  /* ── Som ──────────────────────────────────────────────────────────────── */
  var audio = null;
  function destravar() {
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      if (audio.state === 'suspended') audio.resume();
    } catch (_) {}
  }
  ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) {
    document.addEventListener(ev, destravar, { once: true, passive: true, capture: true });
  });
  function somLigado() { return guardado('avisos_som') !== '0'; }
  function bling() {
    if (!somLigado() || !audio || audio.state !== 'running') return;
    try {
      var t = audio.currentTime;
      [[880, 0], [1318.5, 0.13]].forEach(function (n) {
        var o = audio.createOscillator(), g = audio.createGain();
        o.type = 'sine';
        o.frequency.value = n[0];
        g.gain.setValueAtTime(0.0001, t + n[1]);
        g.gain.exponentialRampToValueAtTime(0.22, t + n[1] + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + n[1] + 0.55);
        o.connect(g); g.connect(audio.destination);
        o.start(t + n[1]); o.stop(t + n[1] + 0.6);
      });
    } catch (_) {}
  }

  /* ── Aviso na tela ────────────────────────────────────────────────────── */
  var ICONES = {
    lead_novo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>',
    mensagem_nova: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.2A8.4 8.4 0 0 1 12 3.1a8.4 8.4 0 0 1 9 8.4z"/></svg>',
    padrao: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'
  };
  function pilha() {
    var p = document.getElementById('yavPilha');
    if (!p) {
      p = document.createElement('div');
      p.id = 'yavPilha';
      p.className = 'yav-pilha';
      p.setAttribute('aria-live', 'polite');
      document.body.appendChild(p);
    }
    return p;
  }
  // Link do aviso de mensagem nova: /chat?numero=N&jid=J.
  function daConversa(url) {
    var m = /^\/chat\?numero=(\d+)&jid=([^&]+)$/.exec(typeof url === 'string' ? url : '');
    if (!m) return null;
    try { return { numero: Number(m[1]), jid: decodeURIComponent(m[2]) }; } catch (_) { return null; }
  }
  // A pessoa está com esta conversa aberta no Chat (ChatApp do chat.js)?
  function naTela(conv) {
    if (!conv || typeof ChatApp === 'undefined' || !ChatApp.conversaAberta) return false;
    var a = ChatApp.conversaAberta();
    return a.jid === conv.jid && (!a.numero || a.numero === conv.numero);
  }
  function mostrar(n) {
    if (!n) return;
    var id = parseInt(n.id, 10) || 0;
    if (id && vistos[id]) return;
    if (id) vistos[id] = 1;
    var conv = n.tipo === 'mensagem_nova' ? daConversa(n.url) : null;
    // Mensagem da conversa que já está na tela: nem som nem aviso (o servidor marca lido).
    if (conv && naTela(conv) && document.visibilityState === 'visible') return;
    bling();

    var el = document.createElement('div');
    el.className = 'yav';
    el.setAttribute('role', 'status');
    el.innerHTML = '<span class="yav-ic" aria-hidden="true">' + (ICONES[n.tipo] || ICONES.padrao) + '</span>'
      + '<div class="yav-txt"><strong></strong><span></span></div>'
      + '<button type="button" class="yav-x" aria-label="Fechar o aviso"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>';
    el.querySelector('strong').textContent = n.titulo || 'Aviso';
    var corpo = el.querySelector('.yav-txt span');
    if (n.mensagem || n.corpo) corpo.textContent = n.mensagem || n.corpo; else corpo.remove();
    var url = typeof n.url === 'string' && n.url.charAt(0) === '/' && n.url.charAt(1) !== '/' ? n.url : '';
    if (url) el.classList.add('yav-link');

    var timer = null;
    function fechar() { clearTimeout(timer); el.classList.add('yav-saindo'); setTimeout(function () { el.remove(); }, 220); }
    function agendar() { clearTimeout(timer); timer = setTimeout(fechar, 12000); }
    el.addEventListener('mouseenter', function () { clearTimeout(timer); });
    el.addEventListener('mouseleave', agendar);
    el.addEventListener('click', function (e) {
      if (e.target.closest('.yav-x')) { fechar(); return; }
      // Já no Chat, no mesmo número: abre a conversa sem recarregar a página.
      if (conv && typeof ChatApp !== 'undefined' && ChatApp.conversaAberta) {
        var a = ChatApp.conversaAberta();
        if (a.numero === conv.numero) { fechar(); ChatApp.openChatByJid(conv.jid); return; }
      }
      if (url) location.href = url;
    });
    var p = pilha();
    p.insertBefore(el, p.firstChild);
    while (p.children.length > 3) p.lastChild.remove();
    agendar();
    try { document.dispatchEvent(new CustomEvent('yuris:aviso', { detail: n })); } catch (_) {}
  }

  /* ── Push deste aparelho ──────────────────────────────────────────────── */
  var ua = navigator.userAgent || '';
  var ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  var instalado = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  var suportado = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && !!CFG.chave;

  function bytes(b64u) {
    var s = atob(b64u.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((b64u.length + 3) % 4));
    var a = new Uint8Array(s.length);
    for (var i = 0; i < s.length; i++) a[i] = s.charCodeAt(i);
    return a;
  }
  function registro() {
    return navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function () { return navigator.serviceWorker.ready; });
  }
  function api(acao, corpo) {
    return fetch('/api/avisos_push.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf || '' },
      body: JSON.stringify(Object.assign({ acao: acao }, corpo || {}))
    }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { j.ok = r.ok; return j; }); });
  }
  function inscrever(reg) {
    return reg.pushManager.getSubscription().then(function (s) {
      return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes(CFG.chave) });
    }).then(function (s) {
      var dados = s.toJSON();
      return api('inscrever', dados).then(function (j) {
        if (j.ok) guardado('avisos_inscrito', CFG.usuario + '|' + dados.endpoint + '|' + new Date().toDateString());
        return j;
      });
    });
  }
  /** 'ativo' | 'inativo' | 'bloqueado' | 'instalar' (iPhone fora do app) | 'sem_suporte' */
  function estado() {
    if (!suportado) return Promise.resolve(ios && !instalado ? 'instalar' : 'sem_suporte');
    if (Notification.permission === 'denied') return Promise.resolve('bloqueado');
    if (Notification.permission !== 'granted') return Promise.resolve('inativo');
    return registro().then(function (r) { return r.pushManager.getSubscription(); })
      .then(function (s) { return s ? 'ativo' : 'inativo'; })
      .catch(function () { return 'inativo'; });
  }
  function ativar() {
    if (!suportado) return Promise.resolve(ios && !instalado ? 'instalar' : 'sem_suporte');
    destravar();
    return Promise.resolve(Notification.requestPermission()).then(function (p) {
      if (p !== 'granted') return p === 'denied' ? 'bloqueado' : 'inativo';
      return registro().then(inscrever).then(function (j) { return j.ok ? 'ativo' : 'erro'; });
    }).catch(function () { return 'erro'; });
  }
  function desativar() {
    if (!suportado) return Promise.resolve('sem_suporte');
    return registro().then(function (r) { return r.pushManager.getSubscription(); }).then(function (s) {
      if (!s) return 'inativo';
      var ep = s.endpoint;
      return s.unsubscribe().then(function () { guardado('avisos_inscrito', ''); return api('remover', { endpoint: ep }); })
        .then(function () { return 'inativo'; });
    }).catch(function () { return 'erro'; });
  }
  function testar() { return api('testar'); }

  // Já autorizado: confere uma vez por dia que este aparelho está inscrito para
  // QUEM está logado (o mesmo celular pode trocar de usuário; inscrição vence).
  if (suportado && Notification.permission === 'granted') {
    window.addEventListener('load', function () {
      registro().then(function (reg) {
        return reg.pushManager.getSubscription().then(function (s) {
          var marca = s ? CFG.usuario + '|' + s.endpoint + '|' + new Date().toDateString() : '';
          if (!s || guardado('avisos_inscrito') !== marca) return inscrever(reg);
        });
      }).catch(function () {});
    });
  }

  // Push que chega com a página aberta e em foco: o sw.js manda para cá.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function (e) {
      if (e.data && e.data.tipo === 'yuris-aviso') mostrar(e.data.aviso);
    });
  }

  /* ── Card "Receber avisos no aparelho" (tela inicial) ─────────────────── */
  var CHAVE_ADIADO = 'avisos_card_adiado';
  function adiado() { return Date.now() - (+guardado(CHAVE_ADIADO) || 0) < 7 * 864e5; }
  function cardAvisos() {
    var card = document.getElementById('avisosCard');
    if (!card || adiado()) return;
    var instalar = document.getElementById('appCard');
    if (instalar && !instalar.hidden) return; // no celular, instalar o app vem antes
    estado().then(function (e) { if (e === 'inativo') card.hidden = false; });
  }

  window.YurisAvisos = {
    mostrar: mostrar,
    bling: function () { destravar(); bling(); },
    somLigado: somLigado,
    ligarSom: function (sim) { guardado('avisos_som', sim ? '1' : '0'); },
    estado: estado,
    ativar: ativar,
    desativar: desativar,
    testar: testar,
    ativarPeloCard: function () {
      var card = document.getElementById('avisosCard');
      ativar().then(function (e) {
        if (card) card.hidden = true;
        if (e === 'ativo') testar();
      });
    },
    adiarCard: function () {
      guardado(CHAVE_ADIADO, String(Date.now()));
      var card = document.getElementById('avisosCard');
      if (card) card.hidden = true;
    }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(cardAvisos, 600); });
  else setTimeout(cardAvisos, 600);
})();
