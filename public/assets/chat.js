/**
 * chat.js — Lógica do módulo Chat WhatsApp (Evolution API)
 * Padrão: objeto ChatApp com estado centralizado.
 */
const ChatApp = (() => {
  // ── Estado ─────────────────────────────────────────────────
  let state = {
    status       : 'close',   // open | close | connecting | qr
    currentJid   : null,
    currentName  : '',
    chats        : [],
    filter       : 'all',
    searchTerm   : '',
    teamFilter   : null,      // null=todos | 0=sem setor | N=setor específico
    teamFilterName: 'Todos os setores',
    teamFilterCor : null,
    userFilter   : null,      // null=todos | 0=sem responsável | N=user específico
    userFilterName: 'Todos os responsáveis',
    lastMsgId    : 0,
    lastMsgAt    : '',       // created_at da msg mais NOVA carregada (cursor do poll)
    firstMsgId   : 0,        // id da msg mais ANTIGA carregada (desempate do cursor)
    firstMsgAt   : '',       // created_at da msg mais ANTIGA carregada (cursor scroll-up)
    hasMoreOlder : true,     // false quando o servidor devolve 0 antigas
    loadingOlder : false,    // semaforo pra evitar requests duplicadas no scroll
    groupMembersCache: {},   // { 'XXXXX@g.us': [members] } — popula em updateGroupHeader
    contactsLidMap: {},      // { 'localPartDoJid': {name, phone} } — pra resolver @mencoes LID/@s.whatsapp.net
    replyTo      : null,     // { wamid, content, sender, type } — set por setReply(); enviado no proximo send()
    menuOpenFor  : null,     // id da msg cujo popup de acoes esta aberto (1 por vez)
    msgIndex     : {},       // { msgId: {wamid, content, sender, type, fromMe, isDeleted} } — cache pra setReply/reactMessage sem refetch
    unreadTotal  : 0,        // total nao lidas (somatorio chats) — pinta document.title (N)
    notifPerm    : null,     // 'granted' | 'denied' | 'default' — Notification API browser
    notifSound   : null,     // Audio element pra tocar quando chega msg nova
    pollingTimer : null,
    statusTimer  : null,
    qrTimer      : null,
    mediaRecorder: null,
    recording    : false,
    pendingFile  : null,      // { base64, mimetype, filename, type }
    settings     : {},
  };

  // ── Inicialização ───────────────────────────────────────────
  async function init() {
    await loadSettings();
    await checkStatus();
    startStatusPolling();
    setupInfiniteScroll();
    setupNotifications();
    // showConnectedUI já é chamado dentro de setConnectionStatus quando status='open'
  }

  // ── Notificações nativas + badge título + som ──────────────────────────────
  // Pede permissão ao usuário (uma vez) e prepara áudio + capa do título.
  // Quando chega msg nova inbound (pollNewMessages): toca som, mostra Notification,
  // e atualiza document.title com (N) — N é total de não lidas em todos os chats.
  function setupNotifications() {
    // Notification API: pede permissão na primeira vez
    if ('Notification' in window) {
      state.notifPerm = Notification.permission;
      if (state.notifPerm === 'default') {
        // Não pede imediato — espera o user interagir (clicar em qualquer lugar)
        // pra evitar pop-up agressivo no carregamento.
        const askOnce = () => {
          Notification.requestPermission().then(p => { state.notifPerm = p; });
          document.removeEventListener('click', askOnce);
        };
        document.addEventListener('click', askOnce, { once: true });
      }
    }
    // Audio: beep curto em base64 (440Hz, ~150ms) — sem precisar de arquivo extra
    // Gerado dinamicamente com WebAudio pra não depender de asset externo.
    state.notifSound = makeBeep();
  }

  function makeBeep() {
    // Closure que gera um beep curto via WebAudio API.
    // Mais leve que carregar .mp3 e não polui DOM.
    return () => {
      try {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;
        const ctx = new AC();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        osc.frequency.value = 720;
        osc.type = 'sine';
        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.12, ctx.currentTime + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18);
        osc.start();
        osc.stop(ctx.currentTime + 0.2);
      } catch (e) {}
    };
  }

  // Atualiza document.title com (N) baseado em state.chats[].unread_count
  function updateTitleBadge() {
    const total = (state.chats || []).reduce((s, c) => s + (parseInt(c.unread_count, 10) || 0), 0);
    state.unreadTotal = total;
    const base = 'Yuris';
    document.title = total > 0 ? `(${total}) ${base}` : base;
  }

  // Notifica + toca som pra mensagens novas inbound que NÃO vieram do chat aberto
  // (usuário olhando outra conversa ou em outra aba do browser).
  function notifyNewMessage(msg) {
    if (!msg || msg.direction !== 'inbound') return;
    const isCurrentChat = msg.remote_jid === state.currentJid;
    const tabVisible    = !document.hidden;
    // Se está olhando ESSA conversa nesta aba, não notifica (não faz sentido)
    if (isCurrentChat && tabVisible) return;

    // Som sempre que chegar (mesmo na conversa atual, mas em aba escondida)
    if (state.notifSound) state.notifSound();

    // Notification API só funciona se foi autorizada
    if (state.notifPerm === 'granted' && !tabVisible) {
      const sender = resolveSenderName(msg.contact_name) || formatPhone(msg.phone) || 'Mensagem nova';
      const body   = (msg.message_content || msg.caption || '[Mídia]').slice(0, 120);
      try {
        const n = new Notification(sender, {
          body,
          icon: '/assets/favicon-32.png',
          tag : msg.remote_jid, // agrupa notificações do mesmo chat
        });
        n.onclick = () => {
          window.focus();
          if (msg.remote_jid !== state.currentJid) {
            openChat(msg.remote_jid, msg.contact_name || '', msg.phone || '');
          }
          n.close();
        };
      } catch (e) {}
    }
  }

  // Listener (uma vez) que dispara loadOlderMessages quando o user rola pro topo.
  // Threshold 80px: dispara um pouco antes do topo absoluto pra precarregar.
  function setupInfiniteScroll() {
    const msgsEl = qs('#chatMessages');
    if (!msgsEl) return;
    msgsEl.addEventListener('scroll', () => {
      if (msgsEl.scrollTop < 80 && state.hasMoreOlder && !state.loadingOlder) {
        loadOlderMessages();
      }
    }, { passive: true });
  }

  // ── Settings ────────────────────────────────────────────────
  async function loadSettings() {
    try {
      const r = await apiFetch(API.config);
      state.settings = r.settings || {};
      const s = state.settings;
      setVal('cfgBaseUrl',  s.evolution_base_url  || 'http://localhost:8080');
      setVal('cfgApiKey',   s.evolution_api_key   || '');
      setVal('cfgInstance', s.evolution_instance  || 'yuris-crm');
      setVal('cfgWebhook',  s.webhook_url         || '');
    } catch(e) {}
  }

  function openSettings()  { modal('settingsModal', true); }
  function closeSettings() { modal('settingsModal', false); }

  async function saveSettings() {
    const payload = {
      _csrf               : CSRF,
      evolution_base_url  : getVal('cfgBaseUrl').trim(),
      evolution_api_key   : getVal('cfgApiKey').trim(),
      evolution_instance  : getVal('cfgInstance').trim(),
      webhook_url         : getVal('cfgWebhook').trim(),
    };
    try {
      await apiFetch(API.config, 'POST', payload);
      toast('Configurações salvas', 'success');
      closeSettings();
      await checkStatus();
    } catch(e) {
      // Mostra a mensagem real do servidor (ex.: 409 "chave ja vinculada a outra
      // conta") em vez de um erro generico, pra o usuario entender o bloqueio.
      toast(e.message || 'Erro ao salvar configurações', 'error');
    }
  }

  async function applyWebhook() {
    const url = getVal('cfgWebhook').trim();
    if (!url) { toast('Informe a URL do webhook', 'error'); return; }
    try {
      await apiFetch(API.instances, 'POST', {
        _csrf : CSRF,
        action: 'set_webhook',
        url,
      });
      toast('Webhook configurado com sucesso', 'success');
    } catch(e) {
      toast('Erro ao configurar webhook', 'error');
    }
  }

  // ── Status & Conexão ────────────────────────────────────────
  async function checkStatus() {
    try {
      const r = await apiFetch(API.instances + '?action=status');
      const s = r.status || 'close';
      setConnectionStatus(s, r.instance);
    } catch(e) {
      setConnectionStatus('close');
    }
  }

  function setConnectionStatus(status, instance = {}) {
    const wasConnected = state.status === 'open';
    state.status = status;
    if (instance && instance.id) state.instanceId = Number(instance.id);
    loadAgentToggle();
    const dot   = qs('#waDot');
    const label = qs('#waStatusLabel');
    const badge = qs('#connBadge');
    const btnConnect    = qs('#btnConnect');
    const btnConnectTop = qs('#btnConnectTop');
    const btnDisconnect = qs('#btnDisconnect');
    const btnSync       = qs('#btnSync');
    const btnContacts   = qs('#btnContacts');
    const connScreen    = qs('#connectionScreen');
    const chatArea      = qs('#chatArea');
    const chatEmpty     = qs('#chatEmptyState');

    const labels = {
      open       : 'Conectado',
      connecting : 'Conectando…',
      qr         : 'Aguardando QR',
      close      : 'Desconectado',
    };

    // Dot
    dot.className = 'wa-status-dot ' + (status === 'open' ? 'connected' : status === 'close' ? 'disconnected' : 'connecting');
    label.textContent = labels[status] || 'Desconectado';

    // Badge
    if (badge) {
      badge.className = 'conn-status-badge ' + (status === 'open' ? 'connected' : status === 'close' ? 'disconnected' : 'connecting');
      badge.innerHTML = `<span class="wa-status-dot ${dot.className.split(' ')[1]}" style="width:7px;height:7px"></span>${labels[status] || 'Desconectado'}`;
    }

    // KPI status
    setText('kpiStatus', labels[status] || '—');

    // Número
    const phone = instance.phone || '';
    if (phone) setText('kpiPhone', formatPhone(phone));

    // Botões
    if (btnConnectTop) btnConnectTop.style.display = status === 'open' ? 'none'        : 'inline-flex';
    if (btnDisconnect) btnDisconnect.style.display  = status === 'open' ? 'inline-flex' : 'none';
    if (btnContacts)   btnContacts.style.display    = status === 'open' ? 'inline-flex' : 'none';

    // Sincronizar fica sempre visível — quando desconectado vira "Reconectar"
    if (btnSync) {
      btnSync.style.display = 'inline-flex';
      if (status === 'open') {
        btnSync.innerHTML = _syncBtnSvg;
        btnSync.title     = 'Sincronizar conversas';
      } else {
        btnSync.innerHTML = '<svg style="display:inline;width:13px;height:13px;margin-right:5px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.38 1.27 4.79L2.05 22l5.38-1.37c1.37.74 2.93 1.16 4.61 1.16 5.45 0 9.9-4.45 9.9-9.91 0-2.65-1.03-5.13-2.9-7A9.83 9.83 0 0 0 12.04 2z"/></svg>Reconectar';
        btnSync.title     = 'Reconectar WhatsApp';
      }
    }

    if (status === 'open') {
      _discDismissed = false; // reconectou: rearma o aviso pra uma próxima queda
      connScreen.style.display = 'none';
      if (state.currentJid) {
        chatArea.style.display = 'flex';
        chatEmpty.style.display = 'none';
      } else {
        chatArea.style.display = 'none';
        chatEmpty.style.display = 'flex';
      }
      // showConnectedUI só na primeira conexão, não em cada polling
      if (!wasConnected) showConnectedUI();
    } else {
      connScreen.style.display = 'flex';
      chatArea.style.display   = 'none';
      chatEmpty.style.display  = 'none';
      // Mostra o alerta de desconexão SEMPRE que não estiver conectado — não só
      // na transição detectada pelo polling. Antes, quem ABRIA a tela já
      // desconectado (a sessão caiu enquanto estava noutra aba) não via aviso
      // nenhum, só o QR, e não entendia que tinha caído da base (Evolution).
      // 'connecting' = caiu e está tentando voltar; 'close'/'qr' = precisa QR.
      if (!_discDismissed) {
        _showDisconnectAlert();
        if (status === 'connecting') {
          _setReconnectStatus('Desconectou da base (Evolution). Tentando reconectar automaticamente…');
        } else {
          _setReconnectStatus('Desconectou da base (Evolution). Escaneie o QR Code para reconectar.');
        }
      }
    }
  }

  function showConnectedUI() {
    loadChats();
    startChatPolling();
  }

  async function connectWhatsApp() {
    setConnectionStatus('connecting');
    try {
      const r = await apiFetch(API.instances, 'POST', { _csrf: CSRF, action: 'connect' });
      if (r.qr) {
        displayQr(r.qr);
      }
    } catch(e) {
      toast('Erro ao conectar. Verifique as configurações da Evolution API.', 'error');
      setConnectionStatus('close');
    }
  }

  function chatConfirm(msg) {
    return new Promise(resolve => {
      const overlay = document.getElementById('chatConfirmOverlay');
      if (!overlay) {
        // Fallback: usa Yuris.confirm (sem "localhost diz")
        if (window.Yuris && typeof Yuris.confirm === 'function') {
          Yuris.confirm(msg).then(resolve);
        } else {
          resolve(window.confirm(msg));
        }
        return;
      }
      document.getElementById('chatConfirmMsg').textContent = msg;
      overlay.style.display = 'flex';
      const yes = document.getElementById('chatConfirmYes');
      const no  = document.getElementById('chatConfirmNo');
      function done(v) { overlay.style.display = 'none'; yes.onclick = null; no.onclick = null; resolve(v); }
      yes.onclick = () => done(true);
      no.onclick  = () => done(false);
    });
  }

  async function disconnectWhatsApp() {
    if (!await chatConfirm('Deseja desconectar o WhatsApp?')) return;
    try {
      await apiFetch(API.instances, 'POST', { _csrf: CSRF, action: 'logout' });
      setConnectionStatus('close');
      stopPolling();
      clearChatList();
      closeChat();
      toast('WhatsApp desconectado', 'success');
    } catch(e) {
      toast('Erro ao desconectar', 'error');
    }
  }

  function displayQr(qrData) {
    const img = qs('#qrCodeImg');
    if (img) {
      img.src = qrData;
      qs('#qrContainer').classList.add('visible');
    }
    setConnectionStatus('qr');
    // O QR do WhatsApp gira a cada ~20s; atualizamos antes disso pra nunca mostrar QR morto.
    clearTimeout(state.qrTimer);
    state.qrTimer = setTimeout(() => {
      if (state.status === 'qr') refreshQr();
    }, 18000);
  }

  async function refreshQr() {
    try {
      const r = await apiFetch(API.instances + '?action=qr');
      if (r.qr) displayQr(r.qr);
    } catch(e) {}
  }

  // ── Polling ─────────────────────────────────────────────────
  // ── Auto-reconexão ──────────────────────────────────────────
  let _reconnectAttempts = 0;
  let _reconnectTimer    = null;
  // Quando o usuário fecha (X) o alerta de desconexão, respeitamos: não o
  // reexibimos a cada polling. Reseta ao reconectar, pra avisar de novo numa
  // próxima queda.
  let _discDismissed     = false;
  const _MAX_AUTO        = 3;
  const _RETRY_DELAYS    = [15000, 30000, 60000]; // 15s, 30s, 60s

  function _scheduleReconnect() {
    clearTimeout(_reconnectTimer);
    if (_reconnectAttempts >= _MAX_AUTO) {
      _setReconnectStatus('Reconexão automática falhou. Use o botão "Gerar QR Code".');
      return;
    }
    const delay = _RETRY_DELAYS[_reconnectAttempts] ?? 60000;
    const secs  = Math.round(delay / 1000);
    _setReconnectStatus(`Tentativa ${_reconnectAttempts + 1} de ${_MAX_AUTO} em ${secs}s…`);
    _reconnectTimer = setTimeout(_doReconnect, delay);
  }

  async function _doReconnect() {
    if (state.status === 'open') { _clearReconnect(); return; }
    _reconnectAttempts++;
    _setReconnectStatus(`Reconectando… (tentativa ${_reconnectAttempts}/${_MAX_AUTO})`);
    try {
      const r = await apiFetch(API.instances, 'POST', { _csrf: CSRF, action: 'restart' });
      if (r.qr) { displayQr(r.qr); _clearReconnect(); return; }
      // Aguarda 8s e verifica se voltou
      await new Promise(res => setTimeout(res, 8000));
      await checkStatus();
      if (state.status === 'open') { _clearReconnect(); return; }
    } catch(e) { /* segue tentando */ }
    _scheduleReconnect();
  }

  function _clearReconnect() {
    clearTimeout(_reconnectTimer);
    _reconnectTimer    = null;
    _reconnectAttempts = 0;
  }

  function _setReconnectStatus(msg) {
    const el = qs('#reconnectStatus');
    if (el) el.textContent = msg;
  }

  function _showDisconnectAlert() {
    const el = qs('#disconnectAlert');
    if (el) el.classList.add('visible');
  }

  function _hideDisconnectAlert() {
    const el = qs('#disconnectAlert');
    if (el) el.classList.remove('visible');
  }

  function dismissDisconnectAlert() { _discDismissed = true; _hideDisconnectAlert(); }

  async function manualReconnect() {
    _clearReconnect();
    _setReconnectStatus('Gerando QR Code…');
    const btn = qs('#btnSync');
    if (btn) { btn.disabled = true; btn.textContent = 'Conectando…'; }
    try {
      // Tenta restart silencioso primeiro (reutiliza sessão salva)
      const restart = await apiFetch(API.instances, 'POST', { _csrf: CSRF, action: 'restart' }).catch(() => null);
      if (restart && restart.qr) {
        displayQr(restart.qr);
        _hideDisconnectAlert();
      } else {
        // Sesssão expirou — gera novo QR
        const r = await apiFetch(API.instances, 'POST', { _csrf: CSRF, action: 'connect' });
        if (r.qr) { displayQr(r.qr); _hideDisconnectAlert(); }
      }
    } catch(e) {
      toast('Erro ao reconectar. Verifique as configurações.', 'error');
    } finally {
      if (btn) { btn.disabled = false; }
      // O label do botão vai ser corrigido no próximo tick do checkStatus
    }
  }

  function startStatusPolling() {
    clearInterval(state.statusTimer);
    state.statusTimer = setInterval(async () => {
      const prevStatus = state.status;
      await checkStatus();

      if (state.status === 'open') {
        // Voltou a conectar
        if (prevStatus !== 'open') {
          _clearReconnect();
          _hideDisconnectAlert();
          loadChats();
          startChatPolling();
        }
      } else if (prevStatus === 'open' && state.status !== 'open') {
        // Acabou de desconectar
        _showDisconnectAlert();
        _setReconnectStatus('Tentando reconectar automaticamente…');
        _scheduleReconnect();
      }
    }, 6000);
  }

  let _liveRefreshTick = 0;
  let _discoverTick    = 0;
  // Trava de concorrência do TICK inteiro (não só do refreshLiveMessages):
  // o setInterval é async e não espera o tick anterior terminar. Se a lista ou
  // a Evolution responderem lento, os ticks de 4s EMPILHAM requests, esgotam o
  // pool de conexões do navegador e a sidebar parece "congelar" (só destrava ao
  // clicar numa conversa, que dispara um loadChats direto). Com esta flag, no
  // máximo UM tick roda por vez; os demais são pulados até o atual voltar.
  let _tickRunning = false;
  // Listener de visibilidade registrado uma única vez (idempotente entre re-inits).
  let _visBound    = false;

  // Corpo de um ciclo de atualização ao vivo: recarrega a LISTA (preview +
  // não-lidas + ordenação) e, se houver conversa aberta, anexa as msgs novas.
  // Reutiliza as funções existentes (loadChats/refreshLiveMessages/pollNewMessages)
  // — nada de re-render da conversa inteira; o append/dedupe/scroll já é seguro.
  async function _chatPollTick() {
    if (_tickRunning) return;            // tick anterior ainda em voo → pula
    if (document.hidden) return;         // aba oculta → não consome rede à toa
    if (state.status !== 'open') return; // só quando o WhatsApp está conectado
    _tickRunning = true;
    try {
      _discoverTick++;
      if (_discoverTick >= 8) {      // a cada 32s: descobre chats novos na Evolution API
        _discoverTick = 0;
        const r = await apiFetch(API.discover).catch(() => null);
        if (r && r.new_chats > 0) await loadChats(true);
      }

      await loadChats(true);

      if (state.currentJid) {
        await refreshLiveMessages(); // a cada 4s: busca mensagens novas na Evolution API
        await pollNewMessages();
      }
    } catch (e) { /* silencioso — não derruba o intervalo por um erro pontual */ }
    finally { _tickRunning = false; }
  }

  function startChatPolling() {
    _liveRefreshTick = 0;
    _discoverTick    = 0;
    clearInterval(state.pollingTimer);
    // Único setInterval (guardado em state.pollingTimer) — o clearInterval acima
    // evita timers duplicados se startChatPolling rodar de novo (ex.: reconexão).
    state.pollingTimer = setInterval(_chatPollTick, 4000);

    // Ao voltar pra aba, faz um catch-up imediato (sem esperar o próximo tick de
    // 4s), pra lista e contadores ficarem em dia na hora. Registrado só uma vez.
    if (!_visBound) {
      _visBound = true;
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden && state.status === 'open') _chatPollTick();
      });
    }
  }

  function stopPolling() {
    clearInterval(state.pollingTimer);
    clearInterval(state.statusTimer);
    clearTimeout(state.qrTimer);
  }

  // ── Chat list ────────────────────────────────────────────────
  async function loadChats(silent = false) {
    try {
      const params = new URLSearchParams({ search: state.searchTerm });
      if (state.teamFilter !== null) params.set('team_id', String(state.teamFilter));
      if (state.userFilter !== null) params.set('user_id', String(state.userFilter));
      // Quando filtro = "archived", pede ao servidor a lista de arquivadas
      // (default sempre vem is_archived=0 — sem isso, lista vem vazia).
      if (state.filter === 'archived') params.set('archived', '1');
      const r = await apiFetch(API.chats + '?' + params);
      state.chats = r.chats || [];
      renderChatList();
      prefetchSidebarPhotos();   // fotos da lista em background (gentil, não bloqueia)
      setText('kpiChats',  String(state.chats.length));
      setText('kpiUnread', String(r.total_unread || 0));

      // Auto-abre conversa quando veio via ?jid= da aba de Prospecção
      if (!_autoOpenDone && typeof AUTO_OPEN_JID !== 'undefined' && AUTO_OPEN_JID && state.status === 'open') {
        _autoOpenDone = true;
        const target = state.chats.find(c => c.remote_jid === AUTO_OPEN_JID);
        if (target) openChat(target.remote_jid, target.contact_name, target.phone);
      }
    } catch(e) {
      if (!silent) toast('Erro conversas: ' + (e.message || 'desconhecido'), 'error');
    }
  }

  // Sobe o chat para o topo localmente (sem request), igual ao WhatsApp
  function bumpChat(jid, lastContent, fromMe) {
    const idx = state.chats.findIndex(c => c.remote_jid === jid);
    if (idx === -1) return;
    const chat = state.chats[idx];
    chat.last_message_content = lastContent;
    chat.last_message_from_me = fromMe ? 1 : 0;
    chat.last_message_at      = new Date().toISOString().replace('T', ' ').slice(0, 19);
    // Remove da posição atual e coloca logo após os fixados
    state.chats.splice(idx, 1);
    const firstUnpinned = state.chats.findIndex(c => !c.is_pinned);
    state.chats.splice(firstUnpinned === -1 ? 0 : firstUnpinned, 0, chat);
    renderChatList();
  }

  function renderChatList() {
    const container = qs('#chatList');
    if (!container) return;

    let chats = state.chats;

    // Filtro
    if (state.filter === 'unread')     chats = chats.filter(c => c.unread_count > 0);
    if (state.filter === 'groups')     chats = chats.filter(c => c.is_group == 1);
    if (state.filter === 'individual') chats = chats.filter(c => c.is_group != 1);
    if (state.filter === 'pinned')     chats = chats.filter(c => c.is_pinned == 1);
    // Arquivadas: API filtra por padrão is_archived=0; quando filter=archived
    // o servidor já retorna apenas arquivadas (parâmetro ?archived=1 em loadChats).
    // Aqui apenas mantém a lista que veio do servidor.

    if (!chats.length) {
      const msg = state.status === 'open' ? 'Nenhuma conversa encontrada' : 'Conecte o WhatsApp para ver as conversas';
      container.innerHTML = `<div class="chat-list-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>${msg}</span></div>`;
      return;
    }

    container.innerHTML = chats.map(renderChatItem).join('');

    // Atualiza document.title (N) — total de não lidas em todos os chats
    updateTitleBadge();
  }

  function renderChatItem(chat) {
    // display_name vem resolvido pelo backend (pushName real da msg inbound, sem
    // "Você"/telefone). Fallback: real_phone (telefone VERDADEIRO, resolvido pelo
    // backend mesmo quando o chat guardou o LID) formatado. Nunca exibe o LID cru.
    const resolved  = resolveSenderName(chat.display_name) || resolveSenderName(chat.contact_name);
    const realPhone = chat.real_phone || (/^\d{10,13}$/.test(String(chat.phone || '')) ? chat.phone : '');
    const nameRaw   = resolved || formatPhone(realPhone) || 'Contato';
    const initial   = (nameRaw || '?').charAt(0).toUpperCase();
    const name      = esc(nameRaw);
    const preview = esc(chat.last_message_content || '');
    const time    = chat.last_message_at ? formatTime(chat.last_message_at) : '';
    const unread  = chat.unread_count > 0
      ? `<span class="chat-unread-badge">${chat.unread_count > 99 ? '99+' : chat.unread_count}</span>` : '';
    const pinIcon = chat.is_pinned == 1
      ? '<span class="chat-pinned-icon" title="Fixado"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:11px;height:11px;color:#7EB8F6"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V17z"/></svg></span>'
      : '';
    const isActive = chat.remote_jid === state.currentJid ? ' active' : '';
    const previewClass = chat.last_message_from_me == 1 ? ' outbound' : '';
    const avatarHtml = chat.profile_pic_url
      ? `<img src="${esc(chat.profile_pic_url)}" alt="${initial}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" onerror="this.parentElement.textContent='${initial}'">`
      : initial;

    const sectorTag = (chat.team_nome && chat.team_id)
      ? `<div class="chat-item-sector">
           <span class="chat-item-sector-dot" style="background:${esc(chat.team_cor || '#6B7887')}"></span>
           <span class="chat-item-sector-name" style="color:${esc(chat.team_cor || '#7A8898')}">${esc(chat.team_nome)}</span>
         </div>` : '';

    return `<div class="chat-item${isActive}" onclick="ChatApp.openChat('${esc(chat.remote_jid)}','${name}','${esc(chat.phone || '')}')">
      <div class="chat-avatar">${avatarHtml}</div>
      <div class="chat-item-info">
        <div class="chat-item-row1">
          <span class="chat-item-name">${name}</span>
          <span class="chat-item-time">${time}</span>
        </div>
        ${sectorTag}
        <div class="chat-item-row2">
          <span class="chat-item-preview${previewClass}">${preview}</span>
          <span style="display:flex;align-items:center;gap:4px">${pinIcon}${unread}</span>
        </div>
      </div>
    </div>`;
  }

  function clearChatList() {
    state.chats = [];
    renderChatList();
    setText('kpiChats',  '—');
    setText('kpiUnread', '—');
  }

  // ── Abrir / fechar chat ─────────────────────────────────────
  // AbortController da conversa atual: cancela as buscas pesadas (foto/refresh
  // na Evolution) da conversa ANTERIOR ao abrir uma nova. Sem isto, clicar rápido
  // entre conversas empilhava chamadas travadas e saturava o pool de conexões do
  // navegador — chegando a engasgar até o reload da página.
  let _evoAbort = null;
  function openChat(jid, name, phone) {
    phone = phone || '';
    try { if (_evoAbort) _evoAbort.abort(); } catch (_) {}
    _evoAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    state.currentJid  = jid;
    state.currentName = name;
    state.lastMsgId   = 0;

    // Mostra área de chat imediatamente (sem await)
    qs('#connectionScreen').style.display = 'none';
    qs('#chatArea').style.display         = 'flex';
    qs('#chatEmptyState').style.display   = 'none';

    // Header do chat
    const chatObj = state.chats.find(c => c.remote_jid === jid);
    // Subtítulo do cabeçalho: TELEFONE real, NUNCA o LID cru (id interno @lid). O
    // real_phone já vem resolvido do backend; só cai pro phone quando ele já é um
    // número discável (10-13 díg.). Sem telefone → vazio (melhor que o LID).
    const headerPhone = (chatObj && chatObj.real_phone)
                     || (/^\d{10,13}$/.test(String(phone || '')) ? phone : '');
    const displayName = name || formatPhone(headerPhone) || 'Contato';
    const initial     = (displayName || '?').charAt(0).toUpperCase();
    const el = document.getElementById('activeAvatar');
    if (el) el.textContent = initial;
    setText('activeName',  displayName);
    setText('activePhone', headerPhone ? formatPhone(headerPhone) : '');

    // Badge de setor — atualiza com o chat atual (pode ter team_id já carregado)
    updateSectorBadge(chatObj || null);

    // Botão "Assumir conversa" — reflete o estado de pausa do agente nesta conversa
    renderTakeoverBtn(!!(chatObj && (chatObj.agent_paused == 1 || chatObj.agent_paused === true)));

    // Header de grupo: foto + contador "(N membros)" + click pra abrir lista
    updateGroupHeader(jid, chatObj);

    // Limpa mensagens antigas e mostra spinner
    const msgsEl = qs('#chatMessages');
    if (msgsEl) msgsEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#4A5568;font-size:.82rem">Carregando mensagens…</div>';

    // Atualiza lista (item ativo)
    renderChatList();

    // Zera não lidas (fire-and-forget)
    apiFetch(API.chats, 'POST', { _csrf: CSRF, action: 'mark_read', remote_jid: jid }).catch(() => {});

    // Render INSTANTÂNEO: mostra o que já está no banco PRIMEIRO (leitura ~ms).
    // Antes, o render ficava refém de refreshLiveMessages() — chamada HTTP à
    // Evolution que às vezes levava 5–20s — deixando "Carregando mensagens…"
    // travado mesmo com tudo já salvo. Agora a Evolution sincroniza em SEGUNDO
    // PLANO e, ao terminar, só ANEXA as novas via pollNewMessages (sem resetar
    // o scroll). Se a conversa estava vazia, aí sim recarrega do zero.
    loadMessages().then(scrollToBottom).catch(() => {});
    refreshLiveMessages().then(() => {
      if (state.currentJid !== jid) return;                    // trocou de conversa → ignora
      if (state.lastMsgId) pollNewMessages();                  // já tinha msgs → anexa as novas
      else loadMessages().then(scrollToBottom).catch(() => {}); // estava vazia → carrega o que veio
    }).catch(() => {});
  }

  function closeChat() {
    state.currentJid  = null;
    state.currentName = '';
    state.lastMsgId   = 0;
    state.lastMsgAt   = '';
    renderChatList();
    qs('#chatArea').style.display       = 'none';
    qs('#chatEmptyState').style.display = state.status === 'open' ? 'flex' : 'none';
    qs('#connectionScreen').style.display = state.status === 'open' ? 'none' : 'flex';
  }

  // ── Mensagens ───────────────────────────────────────────────
  async function loadMessages() {
    if (!state.currentJid) return;
    const jid0 = state.currentJid;   // trava anti-corrida ao clicar rápido entre conversas
    const msgsEl = qs('#chatMessages');
    // Reseta estado de paginacao ao abrir conversa nova
    state.firstMsgId   = 0;
    state.firstMsgAt   = '';
    state.lastMsgAt    = '';
    state.hasMoreOlder = true;
    state.loadingOlder = false;
    try {
      const r = await apiFetch(API.messages + '?jid=' + encodeURIComponent(jid0) + '&limit=50');
      if (state.currentJid !== jid0) return;   // usuário já trocou de conversa → não renderiza a errada
      const msgs = r.messages || [];
      if (!msgs.length) {
        msgsEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#4A5568;font-size:.82rem">Nenhuma mensagem nesta conversa</div>';
        state.hasMoreOlder = false;
      } else {
        msgsEl.innerHTML = renderMessagesWithDateSeparators(msgs);
        // msgs vem em ordem CRONOLÓGICA (mais antiga → mais nova). Os cursores são
        // as bordas reais: primeira = mais antiga (scroll-up), última = mais nova (poll).
        const _first = msgs[0], _last = msgs[msgs.length - 1];
        state.firstMsgId = _first.id; state.firstMsgAt = _first.created_at || '';
        state.lastMsgId  = _last.id;  state.lastMsgAt  = _last.created_at || '';
        if (msgs.length < 50) state.hasMoreOlder = false;
        scrollToBottom();
      }
    } catch(e) {
      if (msgsEl) msgsEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#B06070;font-size:.82rem;padding:16px;text-align:center">Erro: ' + (e.message || 'falha ao carregar mensagens') + '</div>';
      toast('Erro mensagens: ' + (e.message || ''), 'error');
    }
  }

  // Scroll infinito: carrega mensagens ANTIGAS (before_id < firstMsgId) ao
  // rolar pra cima, preservando posicao visual pro user nao perder o ponto.
  async function loadOlderMessages() {
    if (!state.currentJid)     return;
    if (!state.hasMoreOlder)   return;
    if (state.loadingOlder)    return;
    if (!state.firstMsgId)     return;
    state.loadingOlder = true;
    const msgsEl = qs('#chatMessages');
    // Spinner no topo (removido depois)
    const spinner = document.createElement('div');
    spinner.className = 'msg-older-spinner';
    spinner.style.cssText = 'text-align:center;padding:10px;color:#7A8898;font-size:.78rem';
    spinner.textContent = 'Carregando mensagens anteriores…';
    msgsEl.insertBefore(spinner, msgsEl.firstChild);
    // Preserva a distancia do topo da PRIMEIRA mensagem visivel atual,
    // pra apos prepend manter a mesma msg no mesmo lugar pra o user.
    const prevScrollHeight = msgsEl.scrollHeight;
    try {
      const r = await apiFetch(
        API.messages + '?jid=' + encodeURIComponent(state.currentJid)
        + '&before_id=' + state.firstMsgId
        + '&before_at=' + encodeURIComponent(state.firstMsgAt || '')
        + '&limit=50'
      );
      const msgs = r.messages || [];
      spinner.remove();
      if (!msgs.length) {
        state.hasMoreOlder = false;
        return;
      }
      // Prepend (mais antigas vao no topo). renderMessagesWithDateSeparators
      // ja injeta separadores. Como vamos PREPEND, removemos o separador da
      // primeira msg ja renderizada se a data combinar — evita duplicar.
      const html = renderMessagesWithDateSeparators(msgs);
      msgsEl.insertAdjacentHTML('afterbegin', html);
      // Bloco antigo vem cronológico; a nova borda "mais antiga" é o primeiro item.
      const _oldest = msgs[0];
      state.firstMsgId = _oldest.id; state.firstMsgAt = _oldest.created_at || '';
      if (msgs.length < 50) state.hasMoreOlder = false;
      // Restaura scroll position pra usuario nao sentir o jump
      const newScrollHeight = msgsEl.scrollHeight;
      msgsEl.scrollTop = newScrollHeight - prevScrollHeight;
      // Limpa separadores duplicados consecutivos (caso a 1a msg antiga seja
      // do mesmo dia que a 1a msg ja renderizada antes do prepend)
      dedupeDateSeparators(msgsEl);
    } catch (e) {
      spinner.remove();
      toast('Erro ao carregar antigas: ' + (e.message || ''), 'error');
    } finally {
      state.loadingOlder = false;
    }
  }

  // Remove .msg-date-sep que ficou adjacente a outro .msg-date-sep com mesma data
  // (acontece ao prepend quando dia se repete).
  function dedupeDateSeparators(container) {
    const seps = container.querySelectorAll('.msg-date-sep');
    let lastKey = null;
    seps.forEach(s => {
      const k = s.dataset.dayKey || '';
      // Se for igual ao anterior, remove ESTE; senao atualiza lastKey
      if (k && k === lastKey) {
        s.remove();
      } else {
        lastKey = k;
      }
    });
  }

  async function pollNewMessages() {
    if (!state.currentJid || !state.lastMsgId) return;
    try {
      const r = await apiFetch(
        API.messages + '?jid=' + encodeURIComponent(state.currentJid)
        + '&after_id=' + state.lastMsgId
        + '&after_at=' + encodeURIComponent(state.lastMsgAt || '')
      );
      let msgs = r.messages || [];
      if (!msgs.length) return;

      // TRAVA DE ORDEM (anti-bagunça pós-sync): só anexa no rodapé o que é REALMENTE
      // mais novo que a última msg já renderizada. Mensagem antiga vinda de um sync
      // (data passada, id alto) NÃO entra aqui — apareceria fora de lugar no fim da
      // conversa. Ela surge no lugar cronológico certo no próximo carregamento/scroll.
      // Redundante com o cursor after_at do backend, mas protege também quando o
      // navegador ainda roda um chat.js em cache antigo (sem after_at).
      const _lastAt = state.lastMsgAt || '';
      if (_lastAt) {
        msgs = msgs.filter(m => (m.created_at || '') >= _lastAt);
        if (!msgs.length) return;
      }
      // Ordena o lote por data real (created_at, id) antes de anexar
      msgs.sort((a, b) => {
        const ca = a.created_at || '', cb = b.created_at || '';
        if (ca !== cb) return ca < cb ? -1 : 1;
        return (a.id || 0) - (b.id || 0);
      });

      const container = qs('#chatMessages');
      const atBottom  = isNearBottom(container);

      msgs.forEach(m => {
        // Evita duplicata
        if (qs('#msg-' + m.id)) return;
        // Se a nova msg cair em dia diferente da ultima ja renderizada, injeta separador
        const lastSep = container.lastElementChild?.dataset?.dayKey
                     || container.querySelector('.msg-row:last-child')?.dataset?.dayKey;
        const myKey   = dayKey(m.created_at);
        if (myKey && myKey !== lastSep) {
          container.insertAdjacentHTML('beforeend', renderDateSeparator(m.created_at, myKey));
        }
        container.insertAdjacentHTML('beforeend', renderMessage(m));
        // Notifica msg inbound (som + Notification API + badge)
        notifyNewMessage(m);
      });

      // Avança o cursor do poll pra borda mais NOVA retornada (created_at + id).
      // Não dá pra usar max(id): após sync de antigas, id não é mais cronológico.
      const _newest = msgs[msgs.length - 1];
      if (_newest) { state.lastMsgAt = _newest.created_at || state.lastMsgAt; state.lastMsgId = _newest.id; }

      if (atBottom) scrollToBottom();

      // Bump imediato do chat atual para o topo + recarrega lista completa
      const lastMsg = msgs[msgs.length - 1];
      if (lastMsg) bumpChat(state.currentJid, lastMsg.message_content || lastMsg.caption || '...', lastMsg.direction === 'outbound');
      await loadChats(true);
    } catch(e) {}
  }

  async function refreshMessages() {
    state.lastMsgId = 0;
    state.lastMsgAt = '';
    await refreshLiveMessages();
    await loadMessages();
  }

  // Trava de concorrência: a Evolution às vezes responde lento (5–20s). Sem
  // isto, o poll de 4s EMPILHA chamadas travadas, esgota o pool de conexões do
  // navegador e atrasa até o loadMessages rápido. Com a trava, no máximo UMA
  // sincronização roda por vez; as demais são puladas (o banco já é mostrado).
  let _refreshing = false;
  async function refreshLiveMessages() {
    if (!state.currentJid) return;
    if (_refreshing) return;
    _refreshing = true;
    try {
      await apiFetch(API.refresh + '?jid=' + encodeURIComponent(state.currentJid), 'GET', null, _evoAbort && _evoAbort.signal);
    } catch(e) { /* silencioso — sem webhook, ou abort ao trocar de conversa */ }
    finally { _refreshing = false; }
  }

  function renderMessage(msg) {
    const dir     = msg.direction || 'inbound';
    const time    = formatTime(msg.created_at);
    const isDel   = !!(msg.is_deleted && msg.is_deleted != 0);
    const content = isDel ? renderDeleted() : buildMessageContent(msg);
    const status  = dir === 'outbound' ? renderStatus(msg.status) : '';

    const isGroup  = state.currentJid && state.currentJid.endsWith('@g.us');
    // Em grupos, sempre tenta mostrar o autor inbound. Ordem de fallback:
    //   1) contact_name resolvido pelo SQL (group_members → contacts → original)
    //   2) telefone formatado a partir do participant_jid (quando contact_name
    //      veio fantasma ou rotulado como "Você" por outro path)
    const dispName = resolveSenderName(msg.contact_name)
                  || phoneFromJid(msg.participant_jid);
    const sender   = (isGroup && dir === 'inbound' && dispName && !isDel)
      ? `<div class="msg-sender">${esc(dispName)}</div>` : '';
    const dKey     = dayKey(msg.created_at);

    // ── Quoted preview (msg citada) ───────────────────────────────────────────
    // Renderiza no topo da bubble um trecho clicavel da msg original. Considera
    // tambem o snapshot embutido (quoted_sender/quoted_content via contextInfo),
    // pra exibir citacao de msg que nao esta sincronizada no banco (migration 089).
    const hasQuote = msg.quoted_wamid &&
      (msg.quoted_content || msg.quoted_caption || msg.quoted_type || msg.quoted_sender);
    const quoted = (!isDel && hasQuote) ? renderQuoted(msg) : '';

    // ── Menu de acoes (botao 3 pontos no canto) ───────────────────────────────
    // So mostra em msgs nao-apagadas. Outbound pode apagar; inbound nao.
    const menuBtn = isDel ? '' : renderMenuBtn(msg);

    // ── Cache no state pra setReply/reactMessage acharem dados sem refetch ────
    // Sempre popular (mesmo sem wamid) pra que o menu de ações apareça;
    // ações que requerem wamid (reply/react/delete) checam antes e exibem toast.
    if (msg.id) {
      state.msgIndex[msg.id] = {
        wamid     : msg.wamid || '',
        content   : msg.message_content || msg.caption || '',
        type      : msg.message_type || 'text',
        sender    : dispName || (dir === 'outbound' ? 'Você' : ''),
        fromMe    : dir === 'outbound',
        isDeleted : isDel,
      };
    }

    // ── Reactions agregadas (pílulas abaixo da bubble) ────────────────────────
    const reactions = (!isDel && Array.isArray(msg.reactions) && msg.reactions.length)
      ? renderReactions(msg)
      : '';

    return `<div class="msg-row ${dir}" id="msg-${msg.id}" data-day-key="${dKey}" data-wamid="${esc(msg.wamid || '')}">
      <div class="msg-bubble">${sender}${quoted}${content}${menuBtn}</div>
      ${reactions}
      <div class="msg-meta">
        <span>${time}</span>
        ${status}
      </div>
    </div>`;
  }

  // ── Helpers de render (quoted / deleted / menu / reactions) ─────────────────

  function renderDeleted() {
    return `<span class="msg-deleted">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
      Mensagem apagada
    </span>`;
  }

  // Renderiza preview da mensagem citada (texto curto + nome do autor + indicador
  // por tipo: [Imagem]/[Audio]/[Video]/[Documento]). Clicavel: rola pra msg
  // original via scrollToWamid().
  function renderQuoted(msg) {
    const sender = msg.quoted_sender || 'Contato';
    let preview = msg.quoted_content || msg.quoted_caption || '';
    if (!preview) {
      preview = {
        image    : '📷 Imagem',
        video    : '🎥 Vídeo',
        audio    : '🎵 Áudio',
        document : '📄 Documento',
        sticker  : '✨ Sticker',
      }[msg.quoted_type] || 'Mensagem';
    }
    // Limita a 80 chars pra nao quebrar layout
    if (preview.length > 80) preview = preview.slice(0, 77) + '…';
    return `<div class="msg-quoted" onclick="ChatApp.scrollToWamid('${esc(msg.quoted_wamid)}')" title="Ir para mensagem original">
      <div class="msg-quoted-bar"></div>
      <div style="flex:1;min-width:0">
        <div style="font-size:.72rem;font-weight:700;color:#7EB8F6">${esc(sender)}</div>
        <div class="msg-quoted-text">${esc(preview)}</div>
      </div>
    </div>`;
  }

  // Botao 3 pontos no canto da bubble. Hover/click abre o popup .msg-actions-menu.
  // Evento stopPropagation impede que o clique propague pra fora e feche o menu.
  function renderMenuBtn(msg) {
    return `<button class="msg-menu-btn" title="Ações" onclick="event.stopPropagation();ChatApp.toggleMsgMenu(${msg.id}, this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><circle cx="12" cy="6" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="12" cy="18" r="1.2"/></svg>
    </button>`;
  }

  // Renderiza grupo de pílulas de reactions agrupadas por emoji.
  // Pílula com .me realça a reação do usuário atual. Clique = toggle (remove se .me, adiciona senão).
  function renderReactions(msg) {
    const pills = msg.reactions.map(r => {
      const mine = r.mine ? 'me' : '';
      // Click no pill: se mine → remove (emoji=''); senão → adiciona
      const action = r.mine ? `ChatApp.reactMessage(${msg.id}, '')` : `ChatApp.reactMessage(${msg.id}, '${esc(r.emoji)}')`;
      return `<span class="msg-reaction-pill ${mine}" onclick="${action}" title="${r.count} reação${r.count>1?'ões':''}">
        ${esc(r.emoji)}${r.count > 1 ? `<span class="cnt">${r.count}</span>` : ''}
      </span>`;
    }).join('');
    return `<div class="msg-reactions">${pills}</div>`;
  }

  // ── Separador de data entre mensagens (Hoje / Ontem / dd-mm-yyyy) ────────
  // Recebe a lista crua e devolve HTML com <div class="msg-date-sep"> injetado
  // entre grupos de dias diferentes (estilo WhatsApp Web).
  function renderMessagesWithDateSeparators(msgs) {
    let html = '';
    let lastKey = null;
    for (const m of msgs) {
      const k = dayKey(m.created_at);
      if (k && k !== lastKey) {
        html += renderDateSeparator(m.created_at, k);
        lastKey = k;
      }
      html += renderMessage(m);
    }
    return html;
  }

  function renderDateSeparator(timestamp, key) {
    const label = formatDateGroup(timestamp);
    return `<div class="msg-date-sep" data-day-key="${key || ''}"><span>${esc(label)}</span></div>`;
  }

  // Chave canonica do dia (YYYY-MM-DD) pra comparar igualdade entre mensagens.
  function dayKey(timestamp) {
    if (!timestamp) return '';
    const d = new Date(timestamp.replace(' ', 'T') + (timestamp.includes('Z') ? '' : 'Z'));
    if (isNaN(d)) return '';
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${dd}`;
  }

  // Rotulo amigavel: 'Hoje', 'Ontem', ou dd/mm/yyyy. Sem dia-da-semana
  // (estilo WhatsApp mobile: depois de ontem vai direto pra data numerica).
  function formatDateGroup(timestamp) {
    if (!timestamp) return '';
    const d = new Date(timestamp.replace(' ', 'T') + (timestamp.includes('Z') ? '' : 'Z'));
    if (isNaN(d)) return '';
    const today  = new Date(); today.setHours(0,0,0,0);
    const yest   = new Date(today); yest.setDate(today.getDate() - 1);
    const target = new Date(d);     target.setHours(0,0,0,0);
    if (target.getTime() === today.getTime()) return 'Hoje';
    if (target.getTime() === yest.getTime())  return 'Ontem';
    return target.toLocaleDateString('pt-BR'); // dd/mm/yyyy
  }

  // Extrai e formata o telefone de um JID estilo '5511999991111@s.whatsapp.net'
  // ou '12345678901234@lid'. Devolve '' se nao for um JID utilizavel.
  function phoneFromJid(jid) {
    if (!jid) return '';
    const local = String(jid).split('@')[0];
    if (!/^\d{8,}$/.test(local)) return ''; // LIDs muito longos ou nao-numericos
    if (/^\d{14,}$/.test(local)) return ''; // LID interno do WhatsApp, suprime
    return formatPhone(local) || local;
  }

  const MEDIA_PROXY = '/api/whatsapp/media.php?msg_id=';

  function mediaUrl(msg) {
    if (!msg || !msg.id) return '';
    return MEDIA_PROXY + encodeURIComponent(msg.id);
  }

  function buildMessageContent(msg) {
    const type     = msg.message_type || 'text';
    // formatMentions roda DEPOIS de esc() — adiciona spans seguros com nomes
    // tambem escapados internamente. groupJid usado pra lookup no cache.
    const groupJid = state.currentJid && state.currentJid.endsWith('@g.us') ? state.currentJid : null;
    const content  = formatMentions(esc(msg.message_content || ''), groupJid);
    const caption  = formatMentions(esc(msg.caption || ''), groupJid);
    const mUrl     = mediaUrl(msg);

    switch (type) {
      case 'image':
        return `<div class="msg-image-wrap" style="display:inline-block;cursor:pointer;"
                     onclick="ChatApp.openImage('${mUrl}')">
                  <img class="msg-image" src="${mUrl}" alt="Imagem"
                       onerror="this.parentElement.onclick=null;this.style.display='none';this.nextElementSibling.style.display='flex'">
                  <div style="display:none;align-items:center;gap:6px;padding:10px 14px;background:rgba(30,40,60,.6);border:1px solid rgba(148,163,184,.15);border-radius:8px;color:#6B7887;font-size:.8rem;cursor:default;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    Imagem indisponível
                  </div>
                </div>${caption ? `<span class="msg-caption">${caption}</span>` : ''}`;

      case 'video':
        return `<video controls style="max-width:280px;max-height:200px;border-radius:8px;display:block" src="${mUrl}"
                       onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"></video><div style="display:none;align-items:center;gap:6px;padding:8px 12px;background:rgba(30,40,60,.6);border:1px solid rgba(148,163,184,.15);border-radius:8px;color:#6B7887;font-size:.78rem;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>Vídeo expirado (não está mais disponível)</div>${caption ? `<span class="msg-caption">${caption}</span>` : ''}`;

      case 'audio':
        // onerror: se o proxy devolver 404 (mídia antiga já expirada no WhatsApp),
        // troca o player quebrado (0:00) por um rótulo honesto. Áudio novo (com
        // cache) toca normal; só os expirados mostram o aviso.
        return `<div class="msg-audio">
          <audio controls src="${mUrl}" style="max-width:240px;height:36px" preload="metadata"
                 onloadedmetadata="ChatApp.fixAudioDuration(this)"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"></audio>
          <div style="display:none;align-items:center;gap:6px;padding:8px 12px;background:rgba(30,40,60,.6);border:1px solid rgba(148,163,184,.15);border-radius:8px;color:#6B7887;font-size:.78rem;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>
            Áudio expirado (não está mais disponível)
          </div>
        </div>`;

      case 'document': {
        const mime = (msg.media_mimetype || '').toLowerCase();
        // Arquivo de áudio enviado como documento → renderiza como player
        if (mime.startsWith('audio/')) {
          return `<div class="msg-audio">
            <audio controls src="${mUrl}" style="max-width:240px;height:36px" preload="metadata"
                   onloadedmetadata="ChatApp.fixAudioDuration(this)"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"></audio>
            <div style="display:none;align-items:center;gap:6px;padding:8px 12px;background:rgba(30,40,60,.6);border:1px solid rgba(148,163,184,.15);border-radius:8px;color:#6B7887;font-size:.78rem;">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>
              Áudio expirado (não está mais disponível)
            </div>
            ${msg.media_filename ? `<span class="msg-caption">${esc(msg.media_filename)}</span>` : ''}
          </div>`;
        }
        // Arquivo de vídeo enviado como documento → renderiza como vídeo
        if (mime.startsWith('video/')) {
          return `<video controls style="max-width:280px;max-height:200px;border-radius:8px;display:block" src="${mUrl}"></video>${caption ? `<span class="msg-caption">${caption}</span>` : ''}`;
        }
        // Demais documentos → link de download
        return `<a class="msg-doc" href="${mUrl}" target="_blank" rel="noopener" download="${esc(msg.media_filename || 'documento')}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width:16px;height:16px;flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>
          ${esc(msg.media_filename || 'Documento')}
        </a>${caption ? `<span class="msg-caption">${caption}</span>` : ''}`;
      }

      case 'sticker':
        return `<img src="${mUrl}" alt="Sticker" style="max-width:100px;max-height:100px" onerror="this.style.display='none'">`;

      case 'reaction':
        return `<span style="font-size:1.4rem">${content}</span>`;

      default:
        return content ? content.replace(/\n/g, '<br>') : '<em style="color:#4A5568">Mensagem não suportada</em>';
    }
  }

  function renderStatus(status) {
    const icons = {
      pending  : `<svg class="msg-status-icon pending" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>`,
      sent     : `<svg class="msg-status-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>`,
      delivered: `<svg class="msg-status-icon delivered" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/><polyline points="17 6 9 17 4 12"/></svg>`,
      read     : `<svg class="msg-status-icon read" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/><polyline points="17 6 9 17 4 12"/></svg>`,
      failed   : `<svg class="msg-status-icon" style="color:#B06070" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
    };
    return icons[status] || icons.sent;
  }

  // ── Envio ────────────────────────────────────────────────────
  async function send() {
    if (!state.currentJid) return;

    // Se tem arquivo pendente
    if (state.pendingFile) {
      await sendMedia();
      return;
    }

    const input = qs('#chatInput');
    const text  = (input.value || '').trim();
    if (!text) return;

    input.value = '';
    autoResize(input);

    // Otimista: insere na tela imediatamente
    const tempId = 'temp-' + Date.now();
    const tempHtml = `<div class="msg-row outbound" id="${tempId}">
      <div class="msg-bubble">${esc(text).replace(/\n/g,'<br>')}</div>
      <div class="msg-meta"><span>${formatTime(new Date().toISOString())}</span>
        <svg class="msg-status-icon pending" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
    </div>`;
    qs('#chatMessages').insertAdjacentHTML('beforeend', tempHtml);
    scrollToBottom();

    try {
      // Snapshot do replyTo (atual) e limpa state antes do request pra que
      // se o user comecar a digitar outra coisa, nao acumule citacao.
      const replySnap = state.replyTo;
      if (replySnap) cancelReply();

      const r = await apiFetch(API.send, 'POST', {
        _csrf     : CSRF,
        remote_jid: state.currentJid,
        type      : 'text',
        text,
        quoted_id : replySnap ? replySnap.wamid : null,
      });

      // Remove temp e insere real
      const el = qs('#' + tempId);
      if (el) el.remove();
      if (r.message_id) {
        qs('#chatMessages').insertAdjacentHTML('beforeend', renderMessage({
          id             : r.message_id,
          wamid          : r.wamid,
          direction      : 'outbound',
          message_type   : 'text',
          message_content: text,
          status         : 'sent',
          created_at     : new Date().toISOString(),
          // Repassa preview da quoted pra renderizar imediatamente (otimista)
          quoted_wamid   : replySnap ? replySnap.wamid : null,
          quoted_content : replySnap ? replySnap.content : null,
          quoted_type    : replySnap ? replySnap.type : null,
          quoted_sender  : replySnap ? replySnap.sender : null,
        }));
        state.lastMsgId = Math.max(state.lastMsgId, r.message_id);
        scrollToBottom();
        bumpChat(state.currentJid, text, true);
      }
    } catch(e) {
      const el = qs('#' + tempId);
      if (el) el.querySelector('.msg-bubble').style.opacity = '.5';
      toast('Falha ao enviar mensagem', 'error');
    }
  }

  async function sendMedia() {
    const file    = state.pendingFile;
    const caption = (qs('#chatInput').value || '').trim();
    clearFile();
    qs('#chatInput').value = '';

    // Insere preview otimista
    const tempId  = 'temp-' + Date.now();
    const preview = `<div class="msg-row outbound" id="${tempId}">
      <div class="msg-bubble" style="opacity:.6">
        📎 Enviando ${esc(file.filename || 'arquivo')}…
      </div>
    </div>`;
    qs('#chatMessages').insertAdjacentHTML('beforeend', preview);
    scrollToBottom();

    try {
      const r = await apiFetch(API.send, 'POST', {
        _csrf     : CSRF,
        remote_jid: state.currentJid,
        type      : file.type,
        media     : file.base64,
        mimetype  : file.mimetype,
        filename  : file.filename,
        caption,
      });

      const el = qs('#' + tempId);
      if (el) el.remove();

      if (r.message_id) {
        qs('#chatMessages').insertAdjacentHTML('beforeend', renderMessage({
          id           : r.message_id,
          direction    : 'outbound',
          message_type : file.type,
          caption,
          status       : 'sent',
          created_at   : new Date().toISOString(),
        }));
        state.lastMsgId = Math.max(state.lastMsgId, r.message_id);
        scrollToBottom();
        const mediaLabel = {'image':'[Imagem]','audio':'[Áudio]','video':'[Vídeo]','document':'[Documento]'}[file.type] || '[Arquivo]';
        bumpChat(state.currentJid, caption || mediaLabel, true);
      }
    } catch(e) {
      const el = qs('#' + tempId);
      if (el) el.remove();
      toast('Falha ao enviar arquivo', 'error');
    }
  }

  function onKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      send();
    }
  }

  function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
  }

  // ── Arquivos ─────────────────────────────────────────────────
  function onFileSelected(input) {
    const file = input.files[0];
    if (!file) return;

    if (file.size > 64 * 1024 * 1024) {
      toast('Arquivo muito grande (máx 64 MB)', 'error');
      input.value = '';
      return;
    }

    const reader = new FileReader();
    reader.onload = (e) => {
      const dataUrl  = e.target.result;
      const base64   = dataUrl.split(',')[1];
      const mimetype = file.type || detectMime(file.name);
      const type     = detectType(mimetype);

      state.pendingFile = { base64, mimetype, filename: file.name, type };

      qs('#filePreviewName').textContent = file.name;
      qs('#filePreview').classList.add('visible');
    };
    reader.readAsDataURL(file);
    input.value = '';
  }

  function clearFile() {
    state.pendingFile = null;
    qs('#filePreview').classList.remove('visible');
    qs('#filePreviewName').textContent = '';
  }

  // ── Áudio ────────────────────────────────────────────────────
  async function toggleAudio() {
    if (state.recording) {
      stopRecording();
      return;
    }

    // Tenta via MediaRecorder
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const rec    = new MediaRecorder(stream);
        const chunks = [];

        rec.ondataavailable = (e) => { if (e.data.size > 0) chunks.push(e.data); };
        rec.onstop = async () => {
          const blob     = new Blob(chunks, { type: 'audio/ogg; codecs=opus' });
          const base64   = await blobToBase64(blob);
          const b64clean = base64.split(',')[1];
          state.pendingFile = { base64: b64clean, mimetype: 'audio/ogg', filename: 'audio.ogg', type: 'audio' };
          stream.getTracks().forEach(t => t.stop());
          await sendMedia();
        };

        state.mediaRecorder = rec;
        rec.start();
        state.recording = true;
        qs('#audioBtn').classList.add('recording');
        toast('Gravando… clique novamente para parar', 'success');
        return;
      } catch(e) {}
    }

    // Fallback: seletor de arquivo de áudio
    qs('#audioFileInput').click();
  }

  function stopRecording() {
    if (state.mediaRecorder && state.mediaRecorder.state !== 'inactive') {
      state.mediaRecorder.stop();
    }
    state.recording = false;
    qs('#audioBtn').classList.remove('recording');
  }

  function onAudioFileSelected(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = async (e) => {
      const base64 = e.target.result.split(',')[1];
      state.pendingFile = { base64, mimetype: file.type, filename: file.name, type: 'audio' };
      await sendMedia();
    };
    reader.readAsDataURL(file);
    input.value = '';
  }

  // ── Busca e filtros ──────────────────────────────────────────
  let searchTimeout = null;
  function searchChats(val) {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      state.searchTerm = val.trim();
      loadChats();
    }, 300);
  }

  function setFilter(filter) {
    const prev = state.filter;
    state.filter = filter;
    qs('.chat-filter-btn.active').classList.remove('active');
    qs('[data-filter="' + filter + '"]').classList.add('active');
    // Quando alterna entre archived↔outros, precisa refazer a query no servidor
    // (default vem só is_archived=0; archived precisa de ?archived=1)
    if (prev === 'archived' || filter === 'archived') {
      loadChats(true);
    } else {
      renderChatList();
    }
  }

  // ── Filtro de setor na sidebar ───────────────────────────────
  // Aplica filtro de setor: null=todos, 0=sem setor, N=setor N
  async function setTeamFilter(id, nome, cor) {
    const dd = qs('#sectorFilterDd');
    if (dd) dd.style.display = 'none';

    state.teamFilter    = id !== undefined ? id : null;
    state.teamFilterName = nome || 'Todos os setores';
    state.teamFilterCor  = cor  || null;

    // Atualiza visual do botão
    const btn  = qs('#sectorFilterBtn');
    const dot  = qs('#sectorFilterDot');
    const lbl  = qs('#sectorFilterLabel');
    if (btn && dot && lbl) {
      if (id === null) {
        // Sem filtro — estado neutro
        dot.style.background = '#4A5568';
        lbl.textContent = 'Todos os setores';
        btn.classList.remove('active');
      } else if (id === 0) {
        dot.style.background = '#6B7887';
        lbl.textContent = 'Sem setor';
        btn.classList.add('active');
      } else {
        dot.style.background = cor || '#6B7887';
        lbl.textContent = nome || 'Setor';
        btn.classList.add('active');
      }
    }

    await loadChats();
  }

  // Abre/fecha dropdown de setores para filtro da sidebar
  async function toggleTeamFilterDropdown(e) {
    if (e) e.stopPropagation();
    const dd = qs('#sectorFilterDd');
    if (!dd) return;

    if (dd.style.display !== 'none') {
      dd.style.display = 'none';
      return;
    }

    await _loadTeams();

    const currentId = state.teamFilter !== null ? String(state.teamFilter) : null;

    let html = `<div class="chat-sector-dd-item${currentId === null ? ' active' : ''}"
                     onclick="ChatApp.setTeamFilter(null)">
                  <span class="csf-dd-dot" style="background:#4A5568"></span>
                  Todos os setores
                </div>`;

    if (_linkData.teams.length) {
      html += `<hr class="chat-sector-dd-divider">`;
      html += _linkData.teams.map(t => `
        <div class="chat-sector-dd-item${currentId === String(t.id) ? ' active' : ''}"
             onclick="ChatApp.setTeamFilter(${t.id},'${esc(t.nome)}','${esc(t.cor || '#6B7887')}')">
          <span class="csf-dd-dot" style="background:${esc(t.cor || '#6B7887')}"></span>
          ${esc(t.nome)}
        </div>`).join('');

      html += `<hr class="chat-sector-dd-divider">`;
      html += `<div class="chat-sector-dd-item${currentId === '0' ? ' active' : ''}"
                    onclick="ChatApp.setTeamFilter(0,'Sem setor')">
                 <span class="csf-dd-dot" style="background:#6B7887"></span>
                 Sem setor atribuído
               </div>`;
    } else {
      html += `<div class="chat-sector-dd-item" style="color:#4A5568;cursor:default">Nenhum setor cadastrado</div>`;
    }

    dd.innerHTML = html;
    dd.style.display = 'block';

    setTimeout(() => {
      document.addEventListener('click', function _close() {
        if (dd) dd.style.display = 'none';
        document.removeEventListener('click', _close);
      }, { once: true });
    }, 0);
  }

  // ── Filtro por responsável (mesmo padrão do setor) ───────────
  // Lazy: carrega lista de users só na primeira abertura do dropdown.
  async function _loadUsersForFilter() {
    if (_linkData.users.length) return;
    try {
      const r = await fetch('/api/users.php', { credentials: 'same-origin' }).then(r => r.json());
      _linkData.users = Array.isArray(r) ? r : (r.data || []);
    } catch(e) { _linkData.users = []; }
  }

  function setUserFilter(id, nome) {
    state.userFilter     = id !== undefined ? id : null;
    state.userFilterName = nome || 'Todos os responsáveis';

    // Atualiza o label do botão
    const lbl = qs('#userFilterLabel');
    const dot = qs('#userFilterDot');
    if (lbl) lbl.textContent = state.userFilterName;
    if (dot) dot.style.background = id ? '#2563EB' : '#4A5568';

    // Marca botão como active se tem filtro selecionado
    const btn = qs('#userFilterBtn');
    if (btn) btn.classList.toggle('active', id !== null);

    // Fecha dropdown
    const dd = qs('#userFilterDd');
    if (dd) dd.style.display = 'none';

    loadChats();
  }

  // Abre/fecha dropdown de responsáveis para filtro da sidebar
  async function toggleUserFilterDropdown(e) {
    if (e) e.stopPropagation();
    const dd = qs('#userFilterDd');
    if (!dd) return;

    if (dd.style.display !== 'none') {
      dd.style.display = 'none';
      return;
    }

    await _loadUsersForFilter();

    const currentId = state.userFilter !== null ? String(state.userFilter) : null;

    let html = `<div class="chat-sector-dd-item${currentId === null ? ' active' : ''}"
                     onclick="ChatApp.setUserFilter(null)">
                  <span class="csf-dd-dot" style="background:#4A5568"></span>
                  Todos os responsáveis
                </div>`;

    if (_linkData.users.length) {
      html += `<hr class="chat-sector-dd-divider">`;
      html += _linkData.users.map(u => `
        <div class="chat-sector-dd-item${currentId === String(u.id) ? ' active' : ''}"
             onclick="ChatApp.setUserFilter(${u.id},'${esc(u.nome || u.email || ('Usuário #' + u.id))}')">
          <span class="csf-dd-dot" style="background:#2563EB"></span>
          ${esc(u.nome || u.email || ('Usuário #' + u.id))}
        </div>`).join('');

      html += `<hr class="chat-sector-dd-divider">`;
      html += `<div class="chat-sector-dd-item${currentId === '0' ? ' active' : ''}"
                    onclick="ChatApp.setUserFilter(0,'Sem responsável')">
                 <span class="csf-dd-dot" style="background:#6B7887"></span>
                 Sem responsável atribuído
               </div>`;
    } else {
      html += `<div class="chat-sector-dd-item" style="color:#4A5568;cursor:default">Nenhum usuário cadastrado</div>`;
    }

    dd.innerHTML = html;
    dd.style.display = 'block';

    setTimeout(() => {
      document.addEventListener('click', function _close() {
        if (dd) dd.style.display = 'none';
        document.removeEventListener('click', _close);
      }, { once: true });
    }, 0);
  }

  // ── Pin ──────────────────────────────────────────────────────
  async function togglePin() {
    if (!state.currentJid) return;
    await apiFetch(API.chats, 'POST', {
      _csrf      : CSRF,
      action     : 'toggle_pin',
      remote_jid : state.currentJid,
    });
    await loadChats(true);
    toast('Conversa fixada/desafixada', 'success');
  }

  // ── Menu de mais opções ───────────────────────────────────────
  function toggleMoreMenu(e) {
    if (e) e.stopPropagation();
    const dd = qs('#chatMoreDd');
    if (!dd) return;
    const isOpen = dd.style.display !== 'none';
    dd.style.display = isOpen ? 'none' : 'block';
    if (!isOpen) {
      setTimeout(() => {
        document.addEventListener('click', function _close() {
          if (dd) dd.style.display = 'none';
          document.removeEventListener('click', _close);
        }, { once: true });
      }, 0);
    }
  }

  function closeMoreMenu() {
    const dd = qs('#chatMoreDd');
    if (dd) dd.style.display = 'none';
  }

  // ── Marcar conversa como não lida ──────────────────────────────────────────
  async function markChatUnread() {
    if (!state.currentJid) return;
    try {
      await apiFetch(API.chats, 'POST', {
        _csrf      : CSRF,
        action     : 'mark_unread',
        remote_jid : state.currentJid,
      });
      toast('Conversa marcada como não lida', 'success');
      await loadChats(true);
    } catch (e) {
      toast('Erro ao marcar não lida', 'error');
    }
  }

  // ── Arquivar / desarquivar conversa ────────────────────────────────────────
  async function toggleArchive() {
    if (!state.currentJid) return;
    try {
      await apiFetch(API.chats, 'POST', {
        _csrf      : CSRF,
        action     : 'toggle_archive',
        remote_jid : state.currentJid,
      });
      toast('Conversa arquivada/desarquivada', 'success');
      closeChat();
      await loadChats(true);
    } catch (e) {
      toast('Erro ao arquivar', 'error');
    }
  }

  // ── Excluir conversa ─────────────────────────────────────────
  async function confirmDeleteChat() {
    closeMoreMenu();
    if (!state.currentJid) return;
    const name = state.currentName || state.currentJid;
    if (!(await Yuris.confirm(`Excluir a conversa com "${name}"?\n\nTodas as mensagens locais serão removidas. Esta ação não pode ser desfeita.`, { danger: true, okLabel: 'Excluir conversa' }))) return;
    deleteChat();
  }

  async function deleteChat() {
    if (!state.currentJid) return;
    try {
      await apiFetch(API.chats, 'POST', {
        _csrf      : CSRF,
        action     : 'delete',
        remote_jid : state.currentJid,
      });
      toast('Conversa excluída', 'success');
      closeChat();
      await loadChats(true);
    } catch(e) {
      toast('Erro ao excluir: ' + (e.message || 'tente novamente'), 'error');
    }
  }

  // ── Sincronizar chats existentes ────────────────────────────
  let _syncTimer = null;

  function _syncBannerShow() {
    const banner   = qs('#syncBanner');
    const textEl   = qs('#syncBannerText');
    const timerEl  = qs('#syncTimer');
    if (!banner) return;

    let elapsed = 0;
    banner.classList.add('visible');

    clearInterval(_syncTimer);
    _syncTimer = setInterval(() => {
      elapsed++;
      const m = Math.floor(elapsed / 60);
      const s = elapsed % 60;
      if (timerEl) timerEl.textContent = m > 0 ? `${m}m ${s}s` : `${elapsed}s`;

      if (elapsed === 120 && textEl) {
        textEl.textContent = 'A sincronização ainda está em andamento. Algumas conversas podem aparecer aos poucos.';
        textEl.style.color = '#F6AD55';
      } else if (elapsed < 120 && textEl) {
        textEl.textContent = 'Sincronizando conversas e mensagens… isso pode levar de 1 a 2 minutos.';
        textEl.style.color = '';
      }
    }, 1000);
  }

  function _syncBannerHide() {
    clearInterval(_syncTimer);
    _syncTimer = null;
    const banner  = qs('#syncBanner');
    const textEl  = qs('#syncBannerText');
    const timerEl = qs('#syncTimer');
    if (banner)  banner.classList.remove('visible');
    if (textEl)  { textEl.textContent = 'Sincronizando conversas e mensagens…'; textEl.style.color = ''; }
    if (timerEl) timerEl.textContent = '0s';
  }

  const _syncBtnSvg = '<svg style="display:inline;width:13px;height:13px;margin-right:5px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>Sincronizar';

  async function syncChats() {
    // Se desconectado, tenta reconectar em vez de sincronizar
    if (state.status !== 'open') {
      await manualReconnect();
      return;
    }

    const btn = qs('#btnSync');
    if (btn) { btn.disabled = true; btn.textContent = 'Sincronizando…'; }
    _syncBannerShow();
    try {
      const r = await apiFetch(API.sync, 'POST', { _csrf: CSRF, import_messages: true });
      _syncBannerHide();
      toast(`Sincronizado: ${r.synced} conversa(s), ${r.messages} mensagem(ns)`, 'success');
      await loadChats();
    } catch(e) {
      _syncBannerHide();
      toast('Erro ao sincronizar: ' + (e.message || 'verifique a conexão'), 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = _syncBtnSvg; }
    }
  }

  // ── Contatos ─────────────────────────────────────────────────
  async function openContacts() {
    modal('contactsModal', true);
    const box = qs('#contactsList');
    box.innerHTML = '<p style="color:#4A5568;font-size:.82rem">Carregando…</p>';
    try {
      const r = await apiFetch(API.contacts);
      const list = r.contacts || [];
      if (!list.length) {
        box.innerHTML = '<p style="color:#4A5568;font-size:.82rem">Nenhum contato sincronizado. Clique em Sincronizar primeiro.</p>';
        return;
      }
      box.innerHTML = list.map(c => `
        <div class="contact-row">
          <div class="contact-row-info">
            <div class="contact-jid">${esc(c.remote_jid)}</div>
            <div class="contact-phone">${esc(c.phone || '')}</div>
          </div>
          <input type="text" class="contact-name-input"
            value="${esc(c.push_name || '')}" placeholder="Nome do contato"
            data-jid="${esc(c.remote_jid)}"
            onchange="ChatApp.saveContactName(this)">
        </div>`).join('');
    } catch(e) {
      box.innerHTML = '<p style="color:#B06070;font-size:.82rem">Erro ao carregar contatos</p>';
    }
  }

  function closeContacts() { modal('contactsModal', false); }

  async function saveContactName(input) {
    const jid  = input.dataset.jid;
    const name = input.value.trim();
    try {
      await apiFetch(API.contacts, 'POST', { _csrf: CSRF, remote_jid: jid, push_name: name });
      toast('Nome salvo — reabra o chat para ver', 'success');
    } catch(e) {
      toast('Erro ao salvar nome', 'error');
    }
  }

  // ── Vincular ─────────────────────────────────────────────────
  // ── Link pickers ─────────────────────────────────────────────
  let _linkData         = { cards: [], processos: [], users: [], teams: [] };
  let _linkPicker       = { card: null, processos: [], user: null }; // processos: array
  let _linkClickOutside = null;
  let _autoOpenDone     = false;

  const _PICKER_CFG = {
    card    : { dropdown: 'pickerCardDropdown',  list: 'pickerCardList',  search: 'pickerCardSearch',  nameEl: 'pickerCardName',  trigger: 'pickerCard' },
    processo: { dropdown: 'pickerAddProcessoDD', list: 'pickerAddProcessoList', search: 'pickerAddProcessoSearch', nameEl: null, trigger: 'pickerAddProcesso' },
    user    : { dropdown: 'pickerUserDropdown',  list: 'pickerUserList',  search: 'pickerUserSearch',  nameEl: 'pickerUserName',  trigger: 'pickerUser' },
  };

  function _closePicker(type) {
    const cfg = _PICKER_CFG[type];
    const dd  = qs('#' + cfg.dropdown);
    if (dd) dd.classList.remove('open');
  }

  function _closeAllPickers() {
    Object.keys(_PICKER_CFG).forEach(_closePicker);
    if (_linkClickOutside) { document.removeEventListener('click', _linkClickOutside); _linkClickOutside = null; }
  }

  function openLinkPicker(type) {
    const cfg = _PICKER_CFG[type];
    const dd  = qs('#' + cfg.dropdown);
    if (!dd) return;
    const isOpen = dd.classList.contains('open');
    _closeAllPickers();
    if (isOpen) return;  // toggle: já estava aberto → apenas fecha

    dd.classList.add('open');
    _renderLinkList(type, _linkData[type === 'card' ? 'cards' : type === 'processo' ? 'processos' : 'users']);

    const inp = qs('#' + cfg.search);
    if (inp) { inp.value = ''; setTimeout(() => inp.focus(), 50); }

    // Fechar ao clicar fora
    _linkClickOutside = (e) => {
      const trigger = qs('#' + cfg.trigger);
      if (trigger && !trigger.contains(e.target)) _closeAllPickers();
    };
    setTimeout(() => document.addEventListener('click', _linkClickOutside), 0);
  }

  function filterLinkPicker(type, term) {
    const data = type === 'card' ? _linkData.cards : type === 'processo' ? _linkData.processos : _linkData.users;
    const t    = term.toLowerCase();
    const filtered = !t ? data : data.filter(item => {
      if (type === 'card')     return (item.cliente_nome||'').toLowerCase().includes(t) || (item.empresa_nome||'').toLowerCase().includes(t);
      if (type === 'processo') return (item.cliente_nome||'').toLowerCase().includes(t) || (item.numero||'').toLowerCase().includes(t);
      return (item.nome||'').toLowerCase().includes(t);
    });
    _renderLinkList(type, filtered);
  }

  function _renderLinkList(type, items) {
    const cfg  = _PICKER_CFG[type];
    const list = qs('#' + cfg.list);
    if (!list) return;
    if (!items.length) {
      list.innerHTML = '<div class="link-picker-empty">Nenhum resultado encontrado</div>';
      return;
    }
    list.innerHTML = items.map((item, i) => {
      let label = '', sub = '';
      if (type === 'card') {
        label = esc(item.cliente_nome || '—');
        sub   = item.empresa_nome ? `<span class="link-picker-sub">· ${esc(item.empresa_nome)}</span>` : '';
      } else if (type === 'processo') {
        label = esc(item.cliente_nome || '—');
        sub   = item.numero ? `<span class="link-picker-sub">#${esc(item.numero)}</span>` : '';
      } else {
        label = esc(item.nome || '—');
      }
      return `<div class="link-picker-item" data-idx="${i}">${sub ? sub + ' ' : ''}${label}</div>`;
    }).join('');

    // Event listeners via JS — evita problema de aspas no onclick="..."
    list.querySelectorAll('.link-picker-item').forEach((el, i) => {
      const item = items[i];
      const name = type === 'card'     ? (item.cliente_nome || '')
                 : type === 'processo' ? (item.cliente_nome || '')
                 : (item.nome || '');
      el.addEventListener('click', (e) => {
        e.stopPropagation(); // impede disparo do "fechar ao clicar fora"
        selectLinkItem(type, item.id, name || String(item.id));
      });
    });
  }

  function selectLinkItem(type, id, name) {
    if (type === 'processo') {
      // Processos: adiciona à lista (não substitui)
      if (!_linkPicker.processos.find(p => String(p.id) === String(id))) {
        _linkPicker.processos.push({ id, name });
        _renderLinkedProcessoList();
      }
      _closePicker('processo');
      if (_linkClickOutside) { document.removeEventListener('click', _linkClickOutside); _linkClickOutside = null; }
      return;
    }

    _linkPicker[type] = { id, name };
    const cfg    = _PICKER_CFG[type];
    const nameEl = cfg.nameEl ? qs('#' + cfg.nameEl) : null;
    if (nameEl) { nameEl.textContent = name || String(id); nameEl.classList.add('selected'); }
    const trigger = qs('#' + cfg.trigger);
    if (trigger) trigger.querySelector('.link-picker-trigger')?.classList.add('has-value');

    // Mostra botão de navegação para card
    if (type === 'card') {
      const goBtn = qs('#pickerCardGoBtn');
      if (goBtn) {
        goBtn.href = '/prospeccao.php?open=' + encodeURIComponent(id);
        goBtn.style.display = 'flex';
      }
    }

    _closePicker(type);
    if (_linkClickOutside) { document.removeEventListener('click', _linkClickOutside); _linkClickOutside = null; }

    // Ao selecionar um card: auto-vincula processos ligados a ele (se ainda não há processos)
    if (type === 'card' && _linkPicker.processos.length === 0) {
      const linked = _linkData.processos.filter(p => String(p.card_id) === String(id));
      linked.forEach(p => selectLinkItem('processo', p.id, p.cliente_nome || ('#' + (p.numero || p.id))));
    }
  }

  function _renderLinkedProcessoList() {
    const container = qs('#linkedProcessosList');
    if (!container) return;
    if (!_linkPicker.processos.length) {
      container.innerHTML = '<span style="font-size:.78rem;color:#3A5A7A;font-style:italic">Nenhum processo vinculado</span>';
      return;
    }
    container.innerHTML = _linkPicker.processos.map((p, i) =>
      `<div style="display:flex;align-items:center;gap:6px;padding:5px 8px;border-radius:6px;background:rgba(5,18,39,.7);border:1px solid rgba(96,165,250,.14)">
        <span style="flex:1;font-size:.82rem;color:#C0D8F0">${esc(p.name || String(p.id))}</span>
        <a href="/processos.php?open=${encodeURIComponent(p.id)}"
           class="btn-yuris btn-yuris-sm" title="Abrir processo">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:11px;height:11px"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          Abrir
        </a>
        <button onclick="ChatApp.removeLinkedProcesso(${i},event)" class="btn-yuris btn-yuris-sm btn-yuris-danger" title="Remover">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:10px;height:10px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>`
    ).join('');
  }

  function removeLinkedProcesso(index, event) {
    if (event) event.stopPropagation();
    _linkPicker.processos.splice(index, 1);
    _renderLinkedProcessoList();
  }

  function clearLinkItem(type, event) {
    if (event) event.stopPropagation();
    _linkPicker[type] = null;
    const cfg    = _PICKER_CFG[type];
    const nameEl = cfg.nameEl ? qs('#' + cfg.nameEl) : null;
    if (nameEl) { nameEl.textContent = '— Nenhum vinculado —'; nameEl.classList.remove('selected'); }
    const trigger = qs('#' + cfg.trigger);
    if (trigger) trigger.querySelector('.link-picker-trigger')?.classList.remove('has-value');
    if (type === 'card') { const goBtn = qs('#pickerCardGoBtn'); if (goBtn) goBtn.style.display = 'none'; }
    _closePicker(type);
  }

  // ── Setor no Header ─────────────────────────────────────────
  // Carrega os setores do usuário logado (lazy, com cache)
  async function _loadTeams() {
    if (_linkData.teams.length) return; // já carregado
    try {
      const r = await fetch('/api/teams.php?action=for_chat_filter', { credentials: 'same-origin' }).then(r => r.json());
      _linkData.teams = Array.isArray(r) ? r : (r.data || []);
    } catch(e) { _linkData.teams = []; }
  }

  // Atualiza o badge de setor no header quando uma conversa é aberta
  function updateSectorBadge(chat) {
    const wrap = qs('#sectorBadgeWrap');
    const btn  = qs('#sectorBadgeBtn');
    const dot  = qs('#sectorDot');
    const name = qs('#sectorBadgeName');
    if (!wrap) return;

    wrap.style.display = 'block';

    if (chat && chat.team_id && chat.team_nome) {
      const color = chat.team_cor || '#6B7887';
      dot.style.background = color;
      name.textContent     = chat.team_nome;
      btn.style.setProperty('--sector-color', color);
      btn.classList.add('has-sector');
    } else {
      dot.style.background = '#4A5568';
      name.textContent     = 'Setor';
      btn.classList.remove('has-sector');
    }
  }

  // Abre/fecha o dropdown de setores no header
  async function toggleSectorDropdown(e) {
    if (e) e.stopPropagation();
    const dd = qs('#sectorDropdown');
    if (!dd) return;

    if (dd.style.display !== 'none') {
      dd.style.display = 'none';
      return;
    }

    await _loadTeams();
    const chat       = state.chats.find(c => c.remote_jid === state.currentJid);
    const currentId  = chat?.team_id ? String(chat.team_id) : null;

    let html = '';
    if (_linkData.teams.length === 0) {
      html = '<div class="sector-dd-item" style="color:#4A5568;cursor:default">Nenhum setor cadastrado</div>';
    } else {
      html = _linkData.teams.map(t => `
        <div class="sector-dd-item${currentId === String(t.id) ? ' active' : ''}"
             onclick="ChatApp.setSectorDirect(${t.id},'${esc(t.nome)}','${esc(t.cor || '#6B7887')}')">
          <span class="sector-dd-dot" style="background:${esc(t.cor || '#6B7887')}"></span>
          ${esc(t.nome)}
        </div>`).join('');

      if (currentId) {
        html += '<hr class="sector-dd-divider">';
        html += `<div class="sector-dd-item sector-dd-remove" onclick="ChatApp.setSectorDirect(null)">
                   <span class="sector-dd-dot" style="background:#B06070"></span>Remover setor
                 </div>`;
      }
    }
    dd.innerHTML = html;
    dd.style.display = 'block';

    // Fecha ao clicar fora
    setTimeout(() => {
      document.addEventListener('click', function _close() {
        if (dd) dd.style.display = 'none';
        document.removeEventListener('click', _close);
      }, { once: true });
    }, 0);
  }

  // Atribui ou remove o setor direto do header (sem abrir o modal)
  async function setSectorDirect(teamId, teamNome, teamCor) {
    const dd = qs('#sectorDropdown');
    if (dd) dd.style.display = 'none';
    if (!state.currentJid) return;

    try {
      await apiFetch(API.chats, 'POST', {
        _csrf      : CSRF,
        action     : 'set_team',
        remote_jid : state.currentJid,
        team_id    : teamId || null,
      });

      // Atualiza o chat local no state imediatamente (sem recarregar tudo)
      const chat = state.chats.find(c => c.remote_jid === state.currentJid);
      if (chat) {
        chat.team_id   = teamId || null;
        chat.team_nome = teamNome || null;
        chat.team_cor  = teamCor  || null;
      }
      updateSectorBadge(chat || { team_id: teamId, team_nome: teamNome, team_cor: teamCor });
      toast(teamId ? `Setor: ${teamNome}` : 'Setor removido', 'success');
    } catch(e) {
      toast('Erro ao atribuir setor', 'error');
    }
  }

  async function openLinkModal() {
    if (!state.currentJid) return;

    // Carrega dados lazy (paralelo) — teams agora via for_chat_filter
    if (!_linkData.cards.length || !_linkData.users.length) {
      try {
        const [rCards, rProcessos, rUsers] = await Promise.all([
          fetch('/api/cards.php',    { credentials: 'same-origin' }).then(r => r.json()),
          fetch('/api/processes.php',{ credentials: 'same-origin' }).then(r => r.json()),
          fetch('/api/users.php',    { credentials: 'same-origin' }).then(r => r.json()),
        ]);
        _linkData.cards    = Array.isArray(rCards)    ? rCards    : (rCards.data    || []);
        _linkData.processos= Array.isArray(rProcessos)? rProcessos: (rProcessos.data|| []);
        _linkData.users    = Array.isArray(rUsers)    ? rUsers    : (rUsers.data    || []);
      } catch(e) {}
    }

    // Reseta pickers
    clearLinkItem('card', null);
    clearLinkItem('user', null);
    _linkPicker.processos = [];
    _renderLinkedProcessoList();
    const _goBtn = qs('#pickerCardGoBtn'); if (_goBtn) _goBtn.style.display = 'none';

    // ── Fonte primária: contato_vinculos (vínculo permanente no banco) ──────────
    let _vinculosCarregados = false;
    try {
      const rv = await fetch(
        '/api/whatsapp/contato_vinculos.php?jid=' + encodeURIComponent(state.currentJid),
        { credentials: 'same-origin' }
      ).then(r => r.json());

      if (rv.ok && rv.vinculos && rv.vinculos.length) {
        _vinculosCarregados = true;
        rv.vinculos.forEach(v => {
          if (v.tipo_vinculo === 'card') {
            const c = _linkData.cards.find(x => String(x.id) === String(v.referencia_id));
            if (c) selectLinkItem('card', c.id, c.cliente_nome);
          }
          if (v.tipo_vinculo === 'processo') {
            const p = _linkData.processos.find(x => String(x.id) === String(v.referencia_id));
            if (p && !_linkPicker.processos.find(e => String(e.id) === String(p.id))) {
              selectLinkItem('processo', p.id, p.cliente_nome || ('#' + (p.numero || p.id)));
            }
          }
        });
      }
    } catch(e) {}

    // ── Fallback: whatsapp_chats.linked_card_id / linked_user_id ────────────
    const chat = state.chats.find(c => c.remote_jid === state.currentJid);
    if (chat) {
      // Card: usa linked_card_id do banco sempre que o picker ainda estiver vazio
      if (!_linkPicker.card && chat.linked_card_id) {
        const c = _linkData.cards.find(x => String(x.id) === String(chat.linked_card_id));
        if (c) selectLinkItem('card', c.id, c.cliente_nome);
      }
      if (chat.linked_user_id) {
        const u = _linkData.users.find(x => String(x.id) === String(chat.linked_user_id));
        if (u) selectLinkItem('user', u.id, u.nome);
      }
      // BUGFIX: removida referência a `teamSel` (variável fantasma — quebrava
      // openLinkModal com ReferenceError, modal não abria). Setor agora é
      // gerenciado pelo dropdown no header (toggleSectorDropdown), não pelo
      // modal Vincular. Resíduo de refatoração antiga.
    }

    // ── Processos da tabela de junção (complementar, evita duplicatas) ───────
    try {
      const rp = await apiFetch(API.chats + '?action=get_processos&jid=' + encodeURIComponent(state.currentJid));
      (rp.processo_ids || []).forEach(pid => {
        if (_linkPicker.processos.find(e => String(e.id) === String(pid))) return; // já adicionado via vinculos
        const p = _linkData.processos.find(x => String(x.id) === String(pid));
        if (p) selectLinkItem('processo', p.id, p.cliente_nome || ('#' + (p.numero || p.id)));
      });
    } catch(e) {}

    modal('linkModal', true);
  }

  function closeLinkModal() {
    _closeAllPickers();
    modal('linkModal', false);
  }

  async function saveLink() {
    if (!state.currentJid) {
      toast('Nenhuma conversa selecionada', 'error');
      return;
    }
    try {
      await apiFetch(API.chats, 'POST', {
        _csrf        : CSRF,
        action       : 'link',
        remote_jid   : state.currentJid,
        card_id      : _linkPicker.card?.id ?? null,
        user_id      : _linkPicker.user?.id ?? null,
        processo_ids : _linkPicker.processos.map(p => p.id),
      });
      toast('Vínculo salvo com sucesso!', 'success');
      closeLinkModal();
      await loadChats(true); // atualiza lista para refletir o vínculo
    } catch(e) {
      toast('Erro ao salvar: ' + (e.message || 'verifique a conexão'), 'error');
    }
  }

  // ── Visualizar imagem — lightbox interno ─────────────────────
  function openImage(proxyUrl) {
    if (!proxyUrl) return;
    if (typeof window._lbOpen === 'function') { window._lbOpen(proxyUrl); return; }
    window.open(proxyUrl, '_blank'); // fallback
  }

  // ── Utilidades DOM ───────────────────────────────────────────
  function qs(sel)          { return document.querySelector(sel); }
  function setText(id, txt) { const e = document.getElementById(id); if (e) e.textContent = txt; }
  function getVal(id)       { const e = document.getElementById(id); return e ? e.value : ''; }
  function setVal(id, val)  { const e = document.getElementById(id); if (e) e.value = val; }
  function esc(str)         { return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function modal(id, open)  { const el = document.getElementById(id); if (el) el.classList.toggle('open', open); }

  function scrollToBottom() {
    const c = qs('#chatMessages');
    if (c) c.scrollTop = c.scrollHeight;
  }

  function isNearBottom(el) {
    if (!el) return true;
    return el.scrollHeight - el.scrollTop - el.clientHeight < 120;
  }

  function formatTime(dt) {
    if (!dt) return '';
    // Adiciona 'Z' para indicar UTC — PHP armazena em UTC, browser converte para horário local
    const d   = new Date(dt.replace(' ', 'T') + (dt.includes('Z') ? '' : 'Z'));
    const now = new Date();
    const diffDays = Math.floor((now - d) / 86400000);
    if (isNaN(d.getTime())) return '';
    const hm = d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    if (diffDays === 0) return hm;
    if (diffDays === 1) return 'Ontem ' + hm;
    if (diffDays < 7)  return d.toLocaleDateString('pt-BR', { weekday: 'short' }) + ' ' + hm;
    return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) + ' ' + hm;
  }

  function resolveSenderName(name) {
    if (!name) return '';
    const s = String(name).trim();
    if (!s) return '';
    if (/^\d{14,}$/.test(s)) return '';        // LID interno do WhatsApp — suprimir
    // 'Você'/'Voce'/'You' nao faz sentido em msg INBOUND (autor real era outro);
    // suprime pra que o fallback (participant_jid) entre.
    if (/^(voc[êe]|you|me)$/i.test(s)) return '';
    return formatPhone(s) || s;                 // telefone → formata; nome normal → exibe direto
  }

  function formatPhone(phone) {
    if (!phone) return '';
    const d = String(phone).replace(/\D/g, '');
    if (d.length === 13) return `+${d.slice(0,2)} (${d.slice(2,4)}) ${d.slice(4,9)}-${d.slice(9)}`;
    if (d.length === 12) return `+${d.slice(0,2)} (${d.slice(2,4)}) ${d.slice(4,8)}-${d.slice(8)}`;
    if (d.length === 11) return `(${d.slice(0,2)}) ${d.slice(2,7)}-${d.slice(7)}`;
    return phone;
  }

  function detectType(mime) {
    if (mime.startsWith('image/'))  return 'image';
    if (mime.startsWith('video/'))  return 'video';
    if (mime.startsWith('audio/'))  return 'audio';
    return 'document';
  }

  function detectMime(name) {
    const ext = (name.split('.').pop() || '').toLowerCase();
    const map = { jpg:'image/jpeg',jpeg:'image/jpeg',png:'image/png',gif:'image/gif',webp:'image/webp',
                  mp4:'video/mp4',mov:'video/quicktime',avi:'video/x-msvideo',
                  mp3:'audio/mpeg',ogg:'audio/ogg',wav:'audio/wav',
                  pdf:'application/pdf',doc:'application/msword',
                  docx:'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                  xls:'application/vnd.ms-excel',xlsx:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                  zip:'application/zip',rar:'application/x-rar-compressed' };
    return map[ext] || 'application/octet-stream';
  }

  function blobToBase64(blob) {
    return new Promise((res) => {
      const r = new FileReader();
      r.onloadend = () => res(r.result);
      r.readAsDataURL(blob);
    });
  }

  // ── Toast ────────────────────────────────────────────────────
  let toastTimer = null;
  function toast(msg, type = '') {
    const el = document.getElementById('chatToast');
    if (!el) return;
    el.textContent  = msg;
    el.className    = 'show' + (type ? ' ' + type : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.className = ''; }, 3200);
  }

  // ── HTTP helper ──────────────────────────────────────────────
  async function apiFetch(url, method = 'GET', body = null, signal = null) {
    const opts = {
      method,
      cache  : 'no-store',
      headers: { 'Content-Type': 'application/json' },
    };
    if (body) opts.body = JSON.stringify(body);
    if (signal) opts.signal = signal;   // permite abortar (ex.: trocar de conversa)
    const res  = await fetch(url, opts);
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Erro HTTP ' + res.status);
    if (data.error) throw new Error(data.error);
    return data;
  }

  // ── Grupo: header (foto + contador) + modal de membros ───────────────────
  // Chamado em openChat. Se nao for grupo, esconde elementos extra.
  function updateGroupHeader(jid, chatObj) {
    const isGroup = jid && jid.endsWith('@g.us');
    const subEl   = qs('#activePhone');

    // Foto do chat (profile_pic_url ja sincronizado em whatsapp_chats)
    const avatar = qs('#activeAvatar');
    if (avatar) {
      // Reset (caso conversa anterior tenha imagem)
      avatar.style.backgroundImage = '';
      const pic = chatObj && chatObj.profile_pic_url;
      if (pic) {
        avatar.textContent = '';
        avatar.style.backgroundImage = `url("${pic}")`;
        avatar.style.backgroundSize  = 'cover';
        avatar.style.backgroundPosition = 'center';
      } else if (jid && !isGroup) {
        // Lazy fetch: conversa 1:1 sem foto cacheada → busca na Evolution
        // Sem isso, 9 de 10 conversas 1:1 ficam só com a inicial cinza.
        fetchProfilePicLazy(jid, chatObj && chatObj.real_phone).then(picUrl => {
          // Só aplica se ainda estiver na mesma conversa (user pode ter trocado)
          if (state.currentJid === jid && picUrl && avatar) {
            avatar.textContent = '';
            avatar.style.backgroundImage = `url("${picUrl}")`;
            avatar.style.backgroundSize = 'cover';
            avatar.style.backgroundPosition = 'center';
            // Atualiza state.chats pra sidebar mostrar foto também
            const c = state.chats.find(x => x.remote_jid === jid);
            if (c) { c.profile_pic_url = picUrl; renderChatList(); }
          }
        });
      }
    }

    if (!isGroup) {
      // Conversa 1:1: garante sublabel default
      return;
    }

    // Fetch dos membros e atualiza sublabel + handler de click + popula cache de menções
    fetch('/api/whatsapp/group_members.php?jid=' + encodeURIComponent(jid), {
      credentials: 'same-origin'
    })
      .then(r => r.json())
      .then(d => {
        const members = (d && d.members) || [];
        const n = (d && d.count) || members.length;
        if (n > 0 && subEl) {
          subEl.textContent = `Grupo · ${n} membro${n === 1 ? '' : 's'} · clique pra ver`;
          subEl.style.cursor = 'pointer';
          subEl.onclick = () => showGroupMembersModal(jid, members);
        }
        // Cache pra resolver @menções no conteudo das mensagens
        state.groupMembersCache[jid] = members;
        // Mapa de contacts da instancia (inclui @lid e @s.whatsapp.net)
        // Sobrescreve o cache global — o JID local part eh chave unica
        if (d && d.contacts_map) {
          state.contactsLidMap = Object.assign({}, state.contactsLidMap, d.contacts_map);
        }
        // Re-resolve menções pendentes que renderizaram antes do cache popular
        refreshPendingMentions(jid);
      })
      .catch(() => { /* silencioso — header continua mostrando phone */ });
  }

  // ── Busca dentro da conversa atual ─────────────────────────────────────────
  // Abre/fecha barra de busca acima das mensagens. Debounce 300ms enquanto
  // digita. Endpoint messages.php?search=texto retorna até 50 matches.
  let _searchTimer = null;
  function toggleChatSearch() {
    const bar = qs('#chatSearchBar');
    if (!bar) return;
    if (bar.style.display === 'none' || !bar.style.display) {
      bar.style.display = 'flex';
      const inp = qs('#chatSearchInput');
      if (inp) { inp.value = ''; inp.focus(); }
      qs('#chatSearchCount').textContent = '';
    } else {
      closeChatSearch();
    }
  }

  function closeChatSearch() {
    const bar = qs('#chatSearchBar');
    if (bar) bar.style.display = 'none';
    qs('#chatSearchInput').value = '';
    qs('#chatSearchCount').textContent = '';
    // Restaura a lista completa de mensagens
    loadMessages().then(scrollToBottom);
  }

  function onChatSearchInput() {
    clearTimeout(_searchTimer);
    const term = (qs('#chatSearchInput').value || '').trim();
    _searchTimer = setTimeout(() => doChatSearch(term), 300);
  }

  async function doChatSearch(term) {
    if (!state.currentJid) return;
    const countEl = qs('#chatSearchCount');
    const msgsEl  = qs('#chatMessages');
    if (!term) {
      // Vazio: volta msgs completas
      if (countEl) countEl.textContent = '';
      loadMessages().then(scrollToBottom);
      return;
    }
    try {
      const r = await apiFetch(
        API.messages + '?jid=' + encodeURIComponent(state.currentJid)
        + '&search=' + encodeURIComponent(term)
      );
      const msgs = r.messages || [];
      if (countEl) countEl.textContent = msgs.length ? `${msgs.length} resultado${msgs.length===1?'':'s'}` : 'Nenhum resultado';
      if (!msgs.length) {
        msgsEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#7A8898;font-size:.82rem;padding:32px">Nada encontrado para "' + esc(term) + '"</div>';
        return;
      }
      msgsEl.innerHTML = renderMessagesWithDateSeparators(msgs);
      // Highlight do termo nos resultados
      highlightSearchTerm(msgsEl, term);
      // Scroll pra primeira ocorrência (que é a mais antiga visível)
      const firstMatch = msgsEl.querySelector('.search-match');
      if (firstMatch) firstMatch.scrollIntoView({ block: 'center' });
    } catch (e) {
      if (countEl) countEl.textContent = 'Erro';
    }
  }

  // Wrap simples: regex case-insensitive aplica .search-match nos text nodes
  // (não toca em HTML interno tipo <a>, <span class="msg-mention">, etc).
  function highlightSearchTerm(container, term) {
    if (!term) return;
    const re = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
    const targets = [];
    let node;
    while ((node = walker.nextNode())) {
      if (re.test(node.nodeValue) && !node.parentElement.closest('.msg-meta, .msg-date-sep')) {
        targets.push(node);
      }
    }
    targets.forEach(t => {
      const span = document.createElement('span');
      span.innerHTML = t.nodeValue.replace(re, '<span class="search-match">$1</span>');
      t.replaceWith(...span.childNodes);
    });
  }

  // ── Foto de perfil 1:1 lazy fetch ──────────────────────────────────────────
  // Busca foto on-demand quando openChat() abre uma conversa que não tem
  // profile_pic_url cacheado. Resultado vai pra whatsapp_chats no servidor +
  // cache local pra não pedir duas vezes pra mesma conversa (mesmo se vier null).
  const _picFetchAttempts = new Set();
  async function fetchProfilePicLazy(jid, number) {
    if (!jid || _picFetchAttempts.has(jid)) return null;
    _picFetchAttempts.add(jid);
    try {
      const r = await apiFetch(API.contacts, 'POST', {
        _csrf : CSRF,
        action: 'fetch_pic',
        jid,
        number: number || '',   // telefone real (LID não resolve foto)
      }, _evoAbort && _evoAbort.signal);
      return r && r.profile_pic_url ? r.profile_pic_url : null;
    } catch (e) {
      // Abortado ao trocar de conversa → libera o jid pra tentar de novo depois
      if (e && e.name === 'AbortError') _picFetchAttempts.delete(jid);
      return null;
    }
  }

  // ── Sync de fotos da lista (background, gentil) ────────────────────────────
  // Busca a foto dos chats 1:1 que ainda não têm, UMA POR VEZ com respiro de
  // 150ms, e atualiza a sidebar conforme cada foto chega. Usa o TELEFONE real
  // (real_phone) — o LID não resolve foto. Grupos: pulados (foto vem do
  // group-info). Cacheia no servidor → custo só na 1ª vez. Sequencial = nunca
  // satura o pool de conexões (e o session-lock já foi resolvido).
  let _photoBatchRunning = false;
  async function prefetchSidebarPhotos() {
    if (_photoBatchRunning) return;
    _photoBatchRunning = true;
    try {
      const alvos = (state.chats || []).filter(c =>
        c && c.remote_jid
        && !c.profile_pic_url
        && !String(c.remote_jid).endsWith('@g.us')
        && !_picFetchAttempts.has(c.remote_jid)
        && (c.real_phone || /^\d{10,13}$/.test(String(c.phone || '')))
      );
      for (const c of alvos) {
        if (!state.chats.some(x => x.remote_jid === c.remote_jid)) continue; // saiu da lista
        const url = await fetchProfilePicLazy(c.remote_jid, c.real_phone || c.phone);
        if (url) {
          const ch = state.chats.find(x => x.remote_jid === c.remote_jid);
          if (ch) { ch.profile_pic_url = url; renderChatList(); }
        }
        await new Promise(r => setTimeout(r, 150)); // respiro: gentil com Evolution + pool
      }
    } finally { _photoBatchRunning = false; }
  }

  // ── Menções em grupos: @<numero> → @<NomeReal> ─────────────────────────
  // Detecta @\d{8,} no texto e resolve via cache de group_members.
  // Quando o membro não está no cache (ainda fetching), marca span como
  // pending pra re-resolver depois (refreshPendingMentions).
  function resolveMentionName(num, groupJid) {
    // 1) Membros do grupo: match por phone OR participant_jid prefix
    if (groupJid) {
      const members = state.groupMembersCache[groupJid];
      if (members && members.length) {
        for (const m of members) {
          const phone = String(m.phone || '').replace(/\D/g, '');
          const pjid  = String(m.participant_jid || '');
          if (phone === num)               return m.push_name || phoneFromJid(pjid) || phone;
          if (pjid.startsWith(num + '@'))  return m.push_name || phoneFromJid(pjid) || num;
        }
      }
    }
    // 2) Contacts da instancia (whatsapp_contacts) — cobre LIDs @lid e tambem
    //    contatos @s.whatsapp.net que nao estao no group_members.
    const c = state.contactsLidMap[num];
    if (c) {
      // Se push_name eh apenas o telefone formatado (sem nome real), prefere
      // o phone curto pra ficar mais limpo
      const name = String(c.name || '').trim();
      if (name && !/^\+?\d[\d\s\-()]*$/.test(name)) return name; // tem letras = nome real
      return name || (c.phone ? formatPhone(c.phone) : null);
    }
    return null;
  }

  function formatMentions(text, groupJid) {
    if (!text) return text;
    // Detecta @<digitos> isolado (não dentro de e-mail/URL).
    return text.replace(/(^|[\s>(])@(\d{8,})\b/g, (full, pre, num) => {
      const name = resolveMentionName(num, groupJid);
      if (name) {
        return `${pre}<span class="msg-mention">@${esc(name)}</span>`;
      }
      // Pending: marca pra re-resolver quando cache popular
      return `${pre}<span class="msg-mention" data-mention-num="${num}">@${num}</span>`;
    });
  }

  // Percorre todos os spans .msg-mention[data-mention-num] e tenta resolver.
  function refreshPendingMentions(groupJid) {
    document.querySelectorAll('.msg-mention[data-mention-num]').forEach(el => {
      const num  = el.dataset.mentionNum;
      const name = resolveMentionName(num, groupJid);
      if (name) {
        el.textContent = '@' + name;
        el.removeAttribute('data-mention-num');
      }
    });
  }

  // Popula e abre o modal ESTÁTICO de membros (já existe em chat.php com os ids
  // #groupMembersList / #groupMembersCount e classe .open). Bug 2026-05-31: a
  // versão antiga tentava criar um modal dinâmico e escrevia em #gmList — como o
  // modal estático já existia, o querySelector('#gmList') vinha null e dava
  // TypeError → o clique em "clique pra ver" não fazia nada.
  function showGroupMembersModal(groupJid, members) {
    const list  = document.getElementById('groupMembersList');
    const count = document.getElementById('groupMembersCount');
    if (!list) { toast('Não foi possível abrir a lista de membros', 'error'); return; }

    if (count) count.textContent = members && members.length ? `(${members.length})` : '';

    if (!members || !members.length) {
      list.innerHTML = '<div class="gm-empty" style="padding:16px;color:#7A8898;font-size:.85rem;text-align:center">Nenhum membro listado. Aguarde a próxima sincronização.</div>';
    } else {
      list.innerHTML = members.map(m => {
        const nome  = esc(m.push_name || phoneFromJid(m.participant_jid) || m.participant_jid || '?');
        const phone = esc(m.phone || '');
        const ini   = esc(((m.push_name || nome || '?').trim()[0] || '?').toUpperCase());
        let role = '';
        if (m.role === 'superadmin') role = '<span class="gm-role gm-superadmin">Criador</span>';
        else if (m.role === 'admin') role = '<span class="gm-role gm-admin">Admin</span>';
        return `
          <div class="gm-row">
            <div class="gm-avatar">${ini}</div>
            <div class="gm-info">
              <div class="gm-name">${nome}</div>
              ${phone ? `<div class="gm-phone">${phone}</div>` : ''}
            </div>
            ${role}
          </div>`;
      }).join('');
    }
    modal('groupMembersModal', true);
  }

  // Fecha o modal de membros do grupo (chamado pelos botões do modal estático).
  function closeGroupMembers() { modal('groupMembersModal', false); }

  // ═══════════════════════════════════════════════════════════════════════════
  // P2 wire-up (2026-05-25): Reply, Reactions, Delete
  // Backend (model + endpoint) já estava todo pronto desde a auditoria 2026-05-24;
  // faltava só conectar o frontend. CSS .msg-actions-menu / .msg-reactions / .chat-reply-bar
  // já está em chat.php. Aqui só adicionamos os handlers JS.
  // ═══════════════════════════════════════════════════════════════════════════

  // Emojis comuns no quick-picker (padrão WhatsApp). Ordem fixa.
  const QUICK_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

  // ── Reply (citar mensagem) ─────────────────────────────────────────────────
  // Mostra a barra acima do input com preview da msg sendo respondida.
  // O quoted_id (wamid) é enviado no próximo send().
  function setReply(msgId) {
    const info = state.msgIndex[msgId];
    if (!info) {
      toast('Mensagem não encontrada', 'error');
      return;
    }
    if (!info.wamid) {
      toast('Mensagem muito antiga — sem ID para responder no WhatsApp', 'error');
      return;
    }
    state.replyTo = {
      wamid   : info.wamid,
      content : info.content,
      type    : info.type,
      sender  : info.sender,
    };
    const bar  = qs('#chatReplyBar');
    const text = qs('#chatReplyText');
    if (!bar || !text) return;

    // Preview amigável: ' [Imagem]' / ' [Audio]' / 'texto curto'
    let preview = info.content || '';
    if (!preview) {
      preview = ({
        image    : '📷 Imagem',
        video    : '🎥 Vídeo',
        audio    : '🎵 Áudio',
        document : '📄 Documento',
        sticker  : '✨ Sticker',
      })[info.type] || 'Mensagem';
    }
    if (preview.length > 80) preview = preview.slice(0, 77) + '…';

    text.textContent = (info.sender ? info.sender + ': ' : '') + preview;
    bar.style.display = 'flex';
    closeMsgMenu();
    // Foca o input pra digitar a resposta
    const input = qs('#chatInput');
    if (input) input.focus();
  }

  function cancelReply() {
    state.replyTo = null;
    const bar = qs('#chatReplyBar');
    if (bar) bar.style.display = 'none';
  }

  // ── Menu de ações por mensagem (3 pontos) ──────────────────────────────────
  // Popup posicionado próximo ao botão. Único aberto por vez (state.menuOpenFor).
  // Clique fora fecha (handler global em init).
  function toggleMsgMenu(msgId, anchorEl) {
    // Se já está aberto pra essa msg → fecha
    if (state.menuOpenFor === msgId) {
      closeMsgMenu();
      return;
    }
    closeMsgMenu();
    const info = state.msgIndex[msgId];
    if (!info) return;

    // Cria popup global se ainda não existe
    let menu = qs('#msgActionsPopup');
    if (!menu) {
      menu = document.createElement('div');
      menu.id = 'msgActionsPopup';
      menu.className = 'msg-actions-menu';
      menu.style.position = 'fixed';
      menu.style.zIndex = '9999';
      menu.style.display = 'none';
      document.body.appendChild(menu);
    }

    // Botão Apagar só faz sentido pras mensagens próprias (outbound).
    // Reagir + Responder disponíveis pra todas.
    const reactRow = `<div class="msg-actions-reactions">
      ${QUICK_REACTIONS.map(em => `<button class="msg-act-react" onclick="ChatApp.reactMessage(${msgId}, '${em}')" title="Reagir com ${em}">${em}</button>`).join('')}
    </div>`;

    const deleteBtn = info.fromMe
      ? `<button class="danger" onclick="ChatApp.confirmDeleteMessage(${msgId})">
           <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
           Apagar mensagem
         </button>`
      : '';

    menu.innerHTML = `
      <button onclick="ChatApp.setReply(${msgId})">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
        Responder
      </button>
      ${reactRow}
      ${deleteBtn}
    `;

    // Posiciona próximo ao botão (clamp pra não sair da tela)
    const rect = anchorEl.getBoundingClientRect();
    menu.style.display = 'flex';
    const mw = menu.offsetWidth || 220;
    const mh = menu.offsetHeight || 140;
    let left = rect.right - mw;
    let top  = rect.bottom + 4;
    if (left < 8)             left = 8;
    if (top + mh > window.innerHeight - 8) top = rect.top - mh - 4;
    menu.style.left = left + 'px';
    menu.style.top  = top  + 'px';

    state.menuOpenFor = msgId;
  }

  function closeMsgMenu() {
    const menu = qs('#msgActionsPopup');
    if (menu) menu.style.display = 'none';
    state.menuOpenFor = null;
  }

  // ── Reaction (emoji) ───────────────────────────────────────────────────────
  // Envia pra API e atualiza otimisticamente. emoji='' remove a reaction.
  async function reactMessage(msgId, emoji) {
    const info = state.msgIndex[msgId];
    if (!info) { toast('Mensagem não encontrada', 'error'); return; }
    if (!info.wamid) {
      toast('Mensagem muito antiga — sem ID para reagir', 'error');
      return;
    }
    closeMsgMenu();

    try {
      const r = await apiFetch(API.reaction, 'POST', {
        _csrf       : CSRF,
        jid         : state.currentJid,
        message_id  : msgId,
        target_wamid: info.wamid,
        emoji,
        from_me     : info.fromMe ? 1 : 0,
      });
      // Aplica local (otimista). O servidor já gravou no DB.
      updateReactionLocally(msgId, emoji);
      // Se a Evolution rejeitou o envio externo, avisa o user
      if (r.evolution && r.evolution.ok === false) {
        const msg = r.evolution.message || ('HTTP ' + (r.evolution.http || '?'));
        toast('Reação salva, mas WhatsApp rejeitou: ' + msg, 'warn');
      }
    } catch (e) {
      toast('Erro ao enviar reação', 'error');
    }
  }

  // Atualiza pílulas localmente sem refetch. Idempotente: se já reagiu com o
  // mesmo emoji, vira no-op (servidor mantém UNIQUE por reactor).
  function updateReactionLocally(msgId, emoji) {
    const row = qs('#msg-' + msgId);
    if (!row) return;
    // Remove qualquer .me anterior do usuário
    let container = row.querySelector('.msg-reactions');
    let reactions = [];
    if (container) {
      reactions = Array.from(container.querySelectorAll('.msg-reaction-pill')).map(p => {
        const em  = (p.firstChild && p.firstChild.textContent) ? p.firstChild.textContent.trim() : '';
        const cntEl = p.querySelector('.cnt');
        const cnt = cntEl ? parseInt(cntEl.textContent, 10) : 1;
        return { emoji: em, count: cnt, mine: p.classList.contains('me') };
      });
    }
    // Decremento da reação atual do usuário
    const myCurrentIdx = reactions.findIndex(r => r.mine);
    if (myCurrentIdx >= 0) {
      reactions[myCurrentIdx].count -= 1;
      reactions[myCurrentIdx].mine   = false;
      if (reactions[myCurrentIdx].count <= 0) reactions.splice(myCurrentIdx, 1);
    }
    // Incrementa nova (se não for emoji vazio)
    if (emoji) {
      const exists = reactions.find(r => r.emoji === emoji);
      if (exists) { exists.count += 1; exists.mine = true; }
      else        reactions.push({ emoji, count: 1, mine: true });
    }

    // Re-render container
    if (!container) {
      container = document.createElement('div');
      container.className = 'msg-reactions';
      row.insertBefore(container, row.querySelector('.msg-meta'));
    }
    if (!reactions.length) {
      container.remove();
    } else {
      container.innerHTML = reactions.map(r => {
        const action = r.mine
          ? `ChatApp.reactMessage(${msgId}, '')`
          : `ChatApp.reactMessage(${msgId}, '${esc(r.emoji)}')`;
        return `<span class="msg-reaction-pill ${r.mine?'me':''}" onclick="${action}">${esc(r.emoji)}${r.count>1?`<span class="cnt">${r.count}</span>`:''}</span>`;
      }).join('');
    }
  }

  // ── Delete (apagar mensagem) ───────────────────────────────────────────────
  // Confirma antes (revoke pra todos é destrutivo). Atualiza UI em "Mensagem apagada".
  async function confirmDeleteMessage(msgId) {
    closeMsgMenu();
    const info = state.msgIndex[msgId];
    if (!info || !info.fromMe) {
      toast('Só é possível apagar suas próprias mensagens', 'error');
      return;
    }
    if (!info.wamid) {
      toast('Mensagem muito antiga — sem ID para apagar no WhatsApp', 'error');
      return;
    }
    if (!confirm('Apagar esta mensagem para todos? Esta ação não pode ser desfeita.')) return;

    try {
      await apiFetch(API.messageAction, 'POST', {
        _csrf       : CSRF,
        action      : 'delete',
        message_id  : msgId,
        jid         : state.currentJid,
        target_wamid: info.wamid,
      });
      // Atualiza UI local: troca conteúdo por "Mensagem apagada"
      const row = qs('#msg-' + msgId);
      if (row) {
        const bubble = row.querySelector('.msg-bubble');
        if (bubble) bubble.innerHTML = `<span class="msg-deleted">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
          Mensagem apagada
        </span>`;
        // Remove reactions (não fazem mais sentido)
        const reacts = row.querySelector('.msg-reactions');
        if (reacts) reacts.remove();
      }
      // Atualiza cache
      if (state.msgIndex[msgId]) state.msgIndex[msgId].isDeleted = true;
      toast('Mensagem apagada', 'success');
    } catch (e) {
      toast('Erro ao apagar mensagem', 'error');
    }
  }

  // ── Fix da duração de áudio OGG/Opus (voz do WhatsApp) ──────────────────────
  // Áudio de voz é OGG/Opus sem duração no cabeçalho → o player nativo mostra
  // "0:00" até tocar tudo. Truque consagrado: forçar um seek pro fim faz o browser
  // varrer o arquivo e calcular a duração real; depois voltamos pro início. Aí o
  // player passa a exibir "0:16" ANTES de clicar, igual WhatsApp.
  // Defensivo: só roda se a duração veio inválida (Infinity/NaN); qualquer erro é
  // silencioso (no pior caso, mantém o comportamento atual de 0:00).
  function fixAudioDuration(el) {
    try {
      if (!el || (isFinite(el.duration) && el.duration > 0)) return; // já tem duração
      if (el.dataset.durFix === '1') return; // evita loop
      el.dataset.durFix = '1';
      const onUpdate = () => {
        el.removeEventListener('timeupdate', onUpdate);
        // volta pro início; agora el.duration já é finito e o player mostra o total
        try { el.currentTime = 0; } catch (_) {}
      };
      el.addEventListener('timeupdate', onUpdate);
      el.currentTime = 1e101; // seek "pro fim" → força o cálculo da duração
    } catch (_) { /* silencioso — mantém 0:00 no pior caso */ }
  }

  // ── Scroll para mensagem citada ────────────────────────────────────────────
  // Quando o usuário clica numa .msg-quoted, rola até a msg original e destaca.
  function scrollToWamid(wamid) {
    if (!wamid) return;
    const target = document.querySelector(`.msg-row[data-wamid="${CSS.escape(wamid)}"]`);
    if (!target) {
      toast('Mensagem original fora da janela atual. Role para cima para carregá-la.', 'info');
      return;
    }
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    // Destaque temporário
    target.style.transition = 'background .3s';
    const orig = target.style.background;
    target.style.background = 'rgba(37,99,235,.18)';
    setTimeout(() => { target.style.background = orig; }, 1500);
  }

  // Listener global pra fechar o menu de ações ao clicar fora
  document.addEventListener('click', (e) => {
    if (!state.menuOpenFor) return;
    const menu = qs('#msgActionsPopup');
    if (menu && menu.contains(e.target)) return;
    if (e.target.closest('.msg-menu-btn')) return;
    closeMsgMenu();
  });

  // ── API pública ──────────────────────────────────────────────
  // ── Assumir conversa (pausa/retoma o agente de IA SÓ nesta conversa) ────────
  function renderTakeoverBtn(paused) {
    const btn = document.getElementById('btnTakeover');
    const lbl = document.getElementById('btnTakeoverLabel');
    if (!btn || !lbl) return;
    if (paused) {
      lbl.textContent = 'Liberar IA';
      btn.title = 'Você assumiu esta conversa. Clique para reativar o agente.';
      btn.style.color = '#34D399';
      btn.style.borderColor = 'rgba(52,211,153,.45)';
    } else {
      lbl.textContent = 'Assumir';
      btn.title = 'Assumir conversa (pausar o agente nesta conversa)';
      btn.style.color = '#F59E0B';
      btn.style.borderColor = 'rgba(245,158,11,.3)';
    }
  }

  async function toggleTakeover() {
    if (!state.currentJid) return;
    const chatObj = state.chats.find(c => c.remote_jid === state.currentJid);
    const cur  = !!(chatObj && (chatObj.agent_paused == 1 || chatObj.agent_paused === true));
    const next = cur ? 0 : 1;
    const body = { _csrf: CSRF, paused: next, remote_jid: state.currentJid };
    if (chatObj && chatObj.id)          body.chat_id     = chatObj.id;
    if (chatObj && chatObj.instance_id) body.instance_id = chatObj.instance_id;
    try {
      const r = await apiFetch('/api/whatsapp/agent_takeover.php', 'POST', body);
      if (r && r.ok) {
        if (chatObj) chatObj.agent_paused = next;
        renderTakeoverBtn(next === 1);
        toast(next ? 'Você assumiu a conversa, o agente foi pausado aqui' : 'Agente reativado nesta conversa', 'success');
      }
    } catch (e) {
      toast(e.message || 'Não foi possível alterar o atendimento', 'error');
    }
  }

  // ── Agente IA: liga/desliga GLOBAL do canal (atalho no header) ──────────────
  async function loadAgentToggle() {
    const btn = qs('#btnAgentToggle'); if (!btn) return;
    // Aparece mesmo com o canal desconectado (o id da instancia existe no banco), pra o
    // admin sempre alcancar "Configurar agente" / ligar-desligar. Ligar de fato exige
    // canal open (o backend retorna 409). So exige ter a instancia resolvida.
    if (!state.instanceId) { btn.style.display = 'none'; return; }
    // Ja carregado para este canal? Nao refaz a cada tick do polling (~6s).
    if (state._agentToggleFetchedFor === state.instanceId && btn.dataset.mode) return;
    try {
      const r = await apiFetch('/api/whatsapp/agent_channel_toggle.php?instance_id=' + state.instanceId);
      if (!r || r.ok !== true) { btn.style.display = 'none'; return; }
      state._agentToggleFetchedFor = state.instanceId;
      renderAgentToggle(r);
    } catch (e) {
      // 403 (nao owner/admin) ou 404 (sem canal no escopo): esconde e nao fica re-tentando neste canal
      btn.style.display = 'none';
      if (/owner|admin|acesso|unauthorized|encontrad/i.test(e.message || '')) state._agentToggleFetchedFor = state.instanceId;
    }
  }
  function renderAgentToggle(r) {
    const btn = qs('#btnAgentToggle'); const lbl = qs('#btnAgentToggleLabel'); if (!btn) return;
    btn.style.display = 'inline-flex';
    if (!r.has_agent) {
      lbl.textContent = 'Configurar agente';
      btn.style.color = '#9ab0c9'; btn.style.borderColor = 'rgba(160,180,210,.3)';
      btn.title = 'Nenhum agente configurado neste canal. Abrir a tela Agente de IA.';
      btn.dataset.mode = 'setup';
    } else if (r.enabled) {
      lbl.textContent = 'Agente: Ligado';
      btn.style.color = '#10b981'; btn.style.borderColor = 'rgba(16,185,129,.45)';
      btn.title = 'O agente responde automaticamente nas conversas (menos as que você assumir). Clique para desligar no canal inteiro.';
      btn.dataset.mode = 'on';
    } else {
      lbl.textContent = 'Agente: Desligado';
      btn.style.color = '#9ab0c9'; btn.style.borderColor = 'rgba(160,180,210,.3)';
      btn.title = 'O agente está desligado neste canal. Clique para ligar.';
      btn.dataset.mode = 'off';
    }
  }
  async function toggleAgentChannel() {
    const btn = qs('#btnAgentToggle'); if (!btn) return;
    const mode = btn.dataset.mode;
    if (mode === 'setup') { window.location.href = 'agente.php'; return; }
    const turnOn = (mode !== 'on');
    const msg = turnOn
      ? 'Ligar o agente de IA neste canal? Ele passará a responder automaticamente as conversas (menos as que você assumir).'
      : 'Desligar o agente de IA no canal inteiro? Nenhuma conversa será respondida automaticamente.';
    if (!confirm(msg)) return;
    try {
      const r = await apiFetch('/api/whatsapp/agent_channel_toggle.php', 'POST', { _csrf: CSRF, instance_id: state.instanceId, enabled: turnOn ? 1 : 0 });
      if (r && r.ok) { renderAgentToggle(r); toast(turnOn ? 'Agente ligado no canal' : 'Agente desligado no canal', 'success'); }
      else { toast((r && r.error) || 'Não foi possível alterar o agente', 'error'); }
    } catch (e) {
      toast(e.message || 'Não foi possível alterar o agente', 'error');
    }
  }

  return {
    init, checkStatus, connectWhatsApp, disconnectWhatsApp,
    manualReconnect, dismissDisconnectAlert,
    refreshQr, loadChats, openChat, closeChat,
    loadMessages, refreshMessages, pollNewMessages,
    send, sendMedia, onKeyDown, autoResize,
    onFileSelected, clearFile,
    toggleAudio, stopRecording, onAudioFileSelected,
    searchChats, setFilter, setTeamFilter, toggleTeamFilterDropdown,
    setUserFilter, toggleUserFilterDropdown, togglePin,
    toggleMoreMenu, closeMoreMenu, confirmDeleteChat,
    openSettings, closeSettings, saveSettings, applyWebhook,
    syncChats,
    openContacts, closeContacts, saveContactName,
    openLinkModal, closeLinkModal, saveLink,
    openLinkPicker, filterLinkPicker, selectLinkItem, clearLinkItem, removeLinkedProcesso,
    toggleSectorDropdown, setSectorDirect,
    openImage,
    // P2 wire-up (2026-05-25)
    setReply, cancelReply,
    toggleMsgMenu, closeMsgMenu, fixAudioDuration,
    reactMessage, confirmDeleteMessage,
    scrollToWamid,
    // P0+P1 (2026-05-25) — busca + arquivar + marcar nao lida
    toggleChatSearch, closeChatSearch, onChatSearchInput,
    markChatUnread, toggleArchive, toggleTakeover, toggleAgentChannel,
    // Modal de membros do grupo — botoes do chat.php chamam ChatApp.closeGroupMembers
    showGroupMembersModal, closeGroupMembers,
  };
})();

document.addEventListener('DOMContentLoaded', () => ChatApp.init());
