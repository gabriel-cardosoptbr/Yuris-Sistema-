document.addEventListener('DOMContentLoaded', () => {
  const AGENT_API = '/api/agent_settings.php';
  const INST_API  = '/api/whatsapp/agent_instances.php';
  const CHAT_URL  = '/chat.php';            // Comunicação -> Chat WhatsApp
  const form = document.getElementById('agentForm');
  if (!form) return;

  const channelBox   = document.getElementById('channelBox');
  const instIdInput  = document.getElementById('whatsappInstanceId');
  const toggleCb     = document.getElementById('toggleEnabled');
  const btnSave      = document.getElementById('btnSave');
  const apiKeyInput  = document.getElementById('apiKeyInput');

  let instances = [];
  let selected  = null;   // instância selecionada (objeto)

  // ── Toast ──────────────────────────────────────────────────────────────────
  function showToast(msg, type = 'info', ms = 4000) {
    let c = document.getElementById('toastContainer');
    if (!c) { c = document.createElement('div'); c.id = 'toastContainer'; c.className = 'toast-wrap'; document.body.appendChild(c); }
    const t = document.createElement('div');
    t.className = 'toast ' + (type || 'info');
    t.textContent = msg;
    c.appendChild(t);
    void t.offsetWidth;
    t.classList.add('show');
    const hide = () => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); };
    const to = setTimeout(hide, ms);
    t.addEventListener('click', () => { clearTimeout(to); hide(); });
  }

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  function fmtDate(s) {
    if (!s) return '—';
    try { const d = new Date(s.replace(' ', 'T')); if (!isNaN(d)) return d.toLocaleString('pt-BR'); } catch(e) {}
    return s;
  }
  function statusInfo(st) {
    switch (st) {
      case 'open':       return { label: 'Conectado',     cls: 'ok',   connected: true  };
      case 'connecting': return { label: 'Conectando',    cls: 'wait', connected: false };
      case 'qr':         return { label: 'Aguardando QR',  cls: 'wait', connected: false };
      default:           return { label: 'Desconectado',  cls: 'off',  connected: false };
    }
  }

  // ── Gating de ativação ──────────────────────────────────────────────────────
  // canActivate: canal conectado. canConfigure: existe canal selecionado (pode salvar prompt/provider).
  function applyGating(canActivate, canConfigure) {
    if (toggleCb) {
      toggleCb.disabled = !canActivate;
      if (!canActivate) toggleCb.checked = false;
      const lbl = document.getElementById('toggleLabel');
      if (lbl) {
        if (!canConfigure)      lbl.textContent = 'Sem canal WhatsApp';
        else if (!canActivate)  lbl.textContent = 'Indisponível (WhatsApp desconectado)';
        else                    lbl.textContent = toggleCb.checked ? 'Agente ativo' : 'Agente inativo';
      }
    }
    if (btnSave) btnSave.disabled = !canConfigure;
  }

  // ── Render do canal ─────────────────────────────────────────────────────────
  function channelCardHTML(inst) {
    const si = statusInfo(inst.status);
    const meta = [
      `<span><b>Número:</b> ${esc(inst.phone || '—')}</span>`,
      `<span><b>Canal interno:</b> ${esc(inst.instance_name)}</span>`,
      `<span><b>Conta:</b> ${esc(inst.account_nome)} (${esc(inst.account_tipo)})</span>`,
      `<span><b>Última sync:</b> ${esc(fmtDate(inst.updated_at))}</span>`,
    ].join('');
    let warn = '';
    if (!si.connected) {
      warn = `<div class="ch-warn">WhatsApp desconectado. O agente fica indisponível até o canal voltar.
              <a class="ch-link" href="${CHAT_URL}">Reconectar em Comunicação →</a></div>`;
    }
    return `<div class="ch-row">
              <span class="ch-name">${esc(inst.display_name || inst.instance_name)}</span>
              <span class="ch-badge ${si.cls}">${si.label}</span>
            </div>
            <div class="ch-meta">${meta}</div>${warn}`;
  }

  function renderNone() {
    selected = null;
    if (instIdInput) instIdInput.value = '';
    channelBox.className = 'channel-box';
    channelBox.innerHTML = `<div class="ch-empty">
        <div class="ch-empty-title">Nenhum canal WhatsApp conectado</div>
        <div class="ch-empty-sub">Conecte um número em Comunicação, Chat WhatsApp para poder vincular e ativar o agente.</div>
        <a class="agt-btn-secondary" href="${CHAT_URL}">Configurar WhatsApp</a>
      </div>`;
    applyGating(false, false);
    updateCards(currentData());
  }

  function renderNoAccess() {
    selected = null;
    if (instIdInput) instIdInput.value = '';
    channelBox.className = 'channel-box';
    channelBox.innerHTML = `<div class="ch-empty">
        <div class="ch-empty-title">Acesso restrito</div>
        <div class="ch-empty-sub">Apenas owner/admin pode configurar o agente e o canal.</div>
      </div>`;
    applyGating(false, false);
  }

  function renderChannel() {
    channelBox.className = 'channel-box';
    let html = '';
    if (instances.length > 1) {
      const opts = instances.map(i => {
        const si = statusInfo(i.status);
        const sel = (selected && i.id === selected.id) ? ' selected' : '';
        return `<option value="${i.id}"${sel}>${esc(i.display_name || i.instance_name)} — ${si.label}${i.is_own ? '' : ' (' + esc(i.account_nome) + ')'}</option>`;
      }).join('');
      html += `<select id="channelSelect" class="field-input channel-select">${opts}</select>`;
    }
    if (selected) html += channelCardHTML(selected);
    channelBox.innerHTML = html;

    const sel = document.getElementById('channelSelect');
    if (sel) sel.addEventListener('change', () => selectInstance(parseInt(sel.value, 10)));
  }

  // ── Seleciona uma instância: hidden + render + carrega config ────────────────
  async function selectInstance(id) {
    selected = instances.find(i => i.id === id) || null;
    if (instIdInput) instIdInput.value = selected ? selected.id : '';
    renderChannel();
    if (!selected) { applyGating(false, false); return; }
    const si = statusInfo(selected.status);
    applyGating(si.connected, true);    // pode ativar só se conectado; pode configurar sempre que há canal
    await loadConfig(selected.id);
  }

  // ── Carrega a config do agente para o canal selecionado ─────────────────────
  async function loadConfig(instanceId) {
    try {
      const res = await fetch(`${AGENT_API}?instance_id=${encodeURIComponent(instanceId)}`);
      const j = await res.json();
      if (!res.ok) { showToast(j.error || 'Erro ao carregar config do canal', 'error'); return; }
      form.name.value     = j.name     || '';
      form.provider.value = j.provider || '';
      form.prompt.value   = j.prompt   || '';
      if (apiKeyInput) {
        apiKeyInput.value = '';
        apiKeyInput.placeholder = j.api_key_masked ? '•••• chave salva (deixe em branco para manter)' : 'sk-…';
      }
      const si = statusInfo(selected ? selected.status : 'close');
      if (toggleCb) toggleCb.checked = !!j.enabled && si.connected;
      if (promptField) promptField.dispatchEvent(new Event('input'));
      applyGating(si.connected, true);
      updateCards(currentData(j));
    } catch (e) {
      console.error('loadConfig', e);
      showToast('Erro de rede ao carregar config', 'error');
    }
  }

  // ── Objeto de dados atual (para os KPIs/diagnóstico) ────────────────────────
  function currentData(extra) {
    return Object.assign({
      name:     form.name ? form.name.value : '',
      enabled:  toggleCb ? toggleCb.checked : false,
      provider: form.provider ? form.provider.value : '',
      prompt:   form.prompt ? form.prompt.value : '',
      api_key_masked: apiKeyInput && apiKeyInput.placeholder.indexOf('chave salva') >= 0 ? '****' : '',
      channel:  selected,
    }, extra || {});
  }

  // ── KPIs + diagnóstico ──────────────────────────────────────────────────────
  function updateCards(d) {
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    const cls = (id, c) => { const el = document.getElementById(id); if (el) el.className = c; };

    const ch       = d.channel;
    const si       = ch ? statusInfo(ch.status) : { connected: false, label: '—' };
    const enabled  = !!d.enabled && si.connected;
    const hasProv  = !!(d.provider && d.provider.trim());
    const hasKey   = !!(d.api_key_masked && d.api_key_masked.trim());
    const hasPrompt= !!(d.prompt && d.prompt.trim());
    const hasName  = !!(d.name && d.name.trim());

    set('kpiStatus', !ch ? 'Sem canal' : (enabled ? 'Ativo' : (si.connected ? 'Inativo' : 'Indisponível')));
    cls('dotStatus', enabled ? 'kpi-dot dot-ok' : (ch && !si.connected ? 'kpi-dot dot-danger' : 'kpi-dot dot-neutral'));
    const cardStatus = document.getElementById('cardStatus');
    if (cardStatus) cardStatus.className = 'kpi-card ' + (enabled ? 'kpi-ok' : (ch && !si.connected ? 'kpi-danger' : ''));

    // WhatsApp card → canal real
    if (ch) {
      set('kpiWhats', ch.phone ? ch.phone : si.label);
      set('kpiWhatsFoot', 'canal: ' + (ch.display_name || ch.instance_name));
      cls('dotWhats', si.connected ? 'kpi-dot dot-ok' : 'kpi-dot dot-danger');
    } else {
      set('kpiWhats', 'Sem canal');
      set('kpiWhatsFoot', 'conecte em Comunicação');
      cls('dotWhats', 'kpi-dot dot-warn');
    }

    set('kpiProvider', hasProv ? d.provider : 'Pendente');
    cls('dotProvider', hasProv ? 'kpi-dot dot-ok' : 'kpi-dot dot-warn');

    if (hasPrompt) {
      set('kpiPrompt', 'Configurado');
      set('kpiPromptFoot', d.prompt.trim().length + ' caracteres');
      cls('dotPrompt', 'kpi-dot dot-ok');
    } else {
      set('kpiPrompt', 'Pendente');
      set('kpiPromptFoot', 'prompt não definido');
      cls('dotPrompt', 'kpi-dot dot-warn');
    }

    try {
      const saved = localStorage.getItem('agent_last_save');
      set('kpiLastUpdate', saved || 'Nunca salvo');
    } catch(e) { set('kpiLastUpdate', '—'); }

    const diagBadge = (id, ok, okLabel, failLabel) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.textContent = ok ? okLabel : failLabel;
      el.className   = 'diag-badge ' + (ok ? 'diag-ok' : 'diag-warn');
    };
    diagBadge('diagNameBadge', hasName,   'Definido',    'Não definido');
    diagBadge('diagWaBadge',   !!ch && si.connected, ch ? 'Conectado' : 'Sem canal', ch ? 'Desconectado' : 'Sem canal');
    diagBadge('diagProvBadge', hasProv,   d.provider || 'Ok', 'Pendente');
    diagBadge('diagKeyBadge',  hasKey,    'Configurada', 'Pendente');
    diagBadge('diagPrmBadge',  hasPrompt, 'Configurado', 'Pendente');
  }

  // ── Prompt char counter ─────────────────────────────────────────────────────
  const promptField   = document.getElementById('promptField');
  const promptCounter = document.getElementById('promptCounter');
  const PROMPT_WARN = 2000, PROMPT_MAX = 4000;
  if (promptField && promptCounter) {
    const updateCounter = () => {
      const len = promptField.value.length;
      promptCounter.textContent = len.toLocaleString('pt-BR') + ' caracteres';
      promptCounter.className = 'char-counter' + (len > PROMPT_MAX ? ' over' : len > PROMPT_WARN ? ' near' : '');
    };
    promptField.addEventListener('input', updateCounter);
    updateCounter();
  }

  // ── Toggle label dinâmico ────────────────────────────────────────────────────
  if (toggleCb) {
    toggleCb.addEventListener('change', () => {
      const lbl = document.getElementById('toggleLabel');
      if (lbl && !toggleCb.disabled) lbl.textContent = toggleCb.checked ? 'Agente ativo' : 'Agente inativo';
    });
  }

  // ── API key: show/hide + copy ────────────────────────────────────────────────
  const toggleApiBtn  = document.getElementById('toggleApiKey');
  const apiKeyEyeIcon = document.getElementById('apiKeyEyeIcon');
  const eyeSvg    = '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>';
  const eyeOffSvg = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a21.67 21.67 0 0 1 5.06-6.94"/><path d="M1 1l22 22"/><path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"/>';
  if (toggleApiBtn && apiKeyInput && apiKeyEyeIcon) {
    toggleApiBtn.addEventListener('click', () => {
      if (apiKeyInput.type === 'password') { apiKeyInput.type = 'text'; apiKeyEyeIcon.innerHTML = eyeOffSvg; }
      else { apiKeyInput.type = 'password'; apiKeyEyeIcon.innerHTML = eyeSvg; }
    });
  }
  const copyApiBtn = document.getElementById('copyApiKey');
  if (copyApiBtn && apiKeyInput) {
    copyApiBtn.addEventListener('click', async () => {
      const val = apiKeyInput.value.trim();
      if (!val) { showToast('Nenhuma chave para copiar', 'info'); return; }
      try { await navigator.clipboard.writeText(val); showToast('Chave copiada', 'success', 2000); }
      catch(e) { showToast('Não foi possível copiar', 'info'); }
    });
  }

  // ── Teste rápido (preview local do contexto) ─────────────────────────────────
  const btnTestAgent     = document.getElementById('btnTestAgent');
  const testResultArea   = document.getElementById('testResultArea');
  const testResultBubble = document.getElementById('testResultBubble');
  if (btnTestAgent && testResultArea && testResultBubble) {
    btnTestAgent.addEventListener('click', () => {
      const msg    = (document.getElementById('testMessage')?.value || '').trim();
      const prompt = form.prompt.value.trim();
      const name   = form.name.value.trim() || 'Agente';
      if (!msg) { showToast('Digite uma mensagem de teste', 'info'); return; }
      testResultBubble.textContent = [
        prompt ? `[PROMPT DO SISTEMA]\n${prompt}` : '[PROMPT NÃO CONFIGURADO]',
        '', `[MENSAGEM DO USUÁRIO]\n${msg}`, '',
        `→ O agente "${name}" responderá com base no prompt acima.`
      ].join('\n');
      testResultArea.style.display = 'block';
    });
  }

  // ── Carrega canais e decide o estado ─────────────────────────────────────────
  async function loadChannels() {
    try {
      const res = await fetch(INST_API);
      if (res.status === 403) { renderNoAccess(); return; }
      const j = await res.json();
      if (!res.ok) { showToast(j.error || 'Erro ao listar canais', 'error'); renderNone(); return; }
      instances = j.instances || [];
      if (instances.length === 0) { renderNone(); return; }
      // 1 instância: vincula automático, sem select. Várias: select (default = 1ª).
      await selectInstance(instances[0].id);
    } catch (e) {
      console.error('loadChannels', e);
      showToast('Erro de rede ao listar canais', 'error');
      renderNone();
    }
  }

  // ── Salvar ───────────────────────────────────────────────────────────────────
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!selected) { showToast('Selecione um canal WhatsApp conectado', 'info'); return; }
    const csrfTok = (form.csrf_token && form.csrf_token.value) || window.YURIS_CSRF || '';
    const payload = {
      whatsapp_instance_id: selected.id,
      name:     form.name.value,
      provider: form.provider.value,
      api_key:  apiKeyInput ? apiKeyInput.value : '',   // vazio = mantém a existente
      prompt:   form.prompt.value,
      csrf_token: csrfTok,
    };
    // Só envia 'enabled' quando o toggle está habilitado (canal conectado);
    // assim salvar com canal desconectado NÃO desativa por engano.
    if (toggleCb && !toggleCb.disabled) payload.enabled = toggleCb.checked ? 1 : 0;

    if (btnSave) { btnSave.disabled = true; btnSave.textContent = 'Salvando…'; }
    try {
      const res = await fetch(AGENT_API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfTok },
        body: JSON.stringify(payload),
      });
      const j = await res.json();
      if (res.ok && j.success) {
        const now = new Date().toLocaleString('pt-BR');
        try { localStorage.setItem('agent_last_save', now); } catch(e) {}
        showToast('Configuração salva com sucesso', 'success');
        updateCards(currentData(j.data));
        const lu = document.getElementById('kpiLastUpdate'); if (lu) lu.textContent = now;
      } else if (j.code === 'CHANNEL_DISCONNECTED') {
        showToast(j.error, 'error', 6000);
      } else {
        showToast('Erro ao salvar: ' + (j.error || 'resposta inesperada'), 'error');
      }
    } catch (e) {
      console.error(e);
      showToast('Erro de rede ao salvar', 'error');
    } finally {
      if (btnSave) {
        btnSave.disabled = !selected;
        btnSave.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Salvar configuração';
      }
    }
  });

  // Início
  applyGating(false, false);
  loadChannels();
});
