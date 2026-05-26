document.addEventListener('DOMContentLoaded', () => {
  const API  = '/sistema_vendas/public/api/agent_settings.php';
  const form = document.getElementById('agentForm');
  if (!form) return;

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

  // ── Update KPI cards & diagnostics ────────────────────────────────────────
  function updateCards(d) {
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    const cls = (id, c) => { const el = document.getElementById(id); if (el) { el.className = ''; el.className = c; } };

    const enabled  = d.enabled;
    const hasWa    = !!(d.whatsapp_number && d.whatsapp_number.trim());
    const hasProv  = !!(d.provider && d.provider.trim());
    const hasKey   = !!(d.api_key && d.api_key.trim());
    const hasPrompt= !!(d.prompt && d.prompt.trim());
    const hasName  = !!(d.name && d.name.trim());

    // Status card
    set('kpiStatus', enabled ? 'Ativo' : 'Inativo');
    cls('dotStatus', enabled ? 'kpi-dot dot-ok' : 'kpi-dot dot-neutral');
    const cardStatus = document.getElementById('cardStatus');
    if (cardStatus) { cardStatus.className = 'kpi-card ' + (enabled ? 'kpi-ok' : ''); }

    // WhatsApp card
    if (hasWa) {
      const masked = d.whatsapp_number.length > 6
        ? d.whatsapp_number.slice(0,4) + '…' + d.whatsapp_number.slice(-4)
        : d.whatsapp_number;
      set('kpiWhats', masked);
      set('kpiWhatsFoot', 'número configurado');
      cls('dotWhats', 'kpi-dot dot-ok');
    } else {
      set('kpiWhats', 'Não configurado');
      set('kpiWhatsFoot', 'adicione o número');
      cls('dotWhats', 'kpi-dot dot-warn');
    }

    // Provider card
    set('kpiProvider', hasProv ? d.provider : 'Pendente');
    cls('dotProvider', hasProv ? 'kpi-dot dot-ok' : 'kpi-dot dot-warn');

    // Prompt card
    if (hasPrompt) {
      const len = d.prompt.trim().length;
      set('kpiPrompt', 'Configurado');
      set('kpiPromptFoot', len + ' caracteres');
      cls('dotPrompt', 'kpi-dot dot-ok');
    } else {
      set('kpiPrompt', 'Pendente');
      set('kpiPromptFoot', 'prompt não definido');
      cls('dotPrompt', 'kpi-dot dot-warn');
    }

    // Toggle label
    const lbl = document.getElementById('toggleLabel');
    if (lbl) lbl.textContent = enabled ? 'Agente ativo' : 'Agente inativo';

    // Last update
    try {
      const saved = localStorage.getItem('agent_last_save');
      if (saved) set('kpiLastUpdate', saved);
      else set('kpiLastUpdate', 'Nunca salvo');
    } catch(e) { set('kpiLastUpdate', '—'); }

    // Diagnostics
    const diagBadge = (id, ok, okLabel, failLabel) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.textContent = ok ? okLabel : failLabel;
      el.className   = 'diag-badge ' + (ok ? 'diag-ok' : 'diag-warn');
    };
    diagBadge('diagNameBadge', hasName,   'Definido',     'Não definido');
    diagBadge('diagWaBadge',   hasWa,     'Configurado',  'Pendente');
    diagBadge('diagProvBadge', hasProv,   d.provider||'Ok', 'Pendente');
    diagBadge('diagKeyBadge',  hasKey,    'Configurada',  'Pendente');
    diagBadge('diagPrmBadge',  hasPrompt, 'Configurado',  'Pendente');
  }

  // ── Prompt char counter ───────────────────────────────────────────────────
  const promptField   = document.getElementById('promptField');
  const promptCounter = document.getElementById('promptCounter');
  const PROMPT_WARN   = 2000;
  const PROMPT_MAX    = 4000;
  if (promptField && promptCounter) {
    const updateCounter = () => {
      const len = promptField.value.length;
      promptCounter.textContent = len.toLocaleString('pt-BR') + ' caracteres';
      promptCounter.className = 'char-counter' + (len > PROMPT_MAX ? ' over' : len > PROMPT_WARN ? ' near' : '');
    };
    promptField.addEventListener('input', updateCounter);
    updateCounter();
  }

  // ── Toggle label ──────────────────────────────────────────────────────────
  const toggleCb = document.getElementById('toggleEnabled');
  if (toggleCb) {
    toggleCb.addEventListener('change', () => {
      const lbl = document.getElementById('toggleLabel');
      if (lbl) lbl.textContent = toggleCb.checked ? 'Agente ativo' : 'Agente inativo';
    });
  }

  // ── API key: show/hide toggle ─────────────────────────────────────────────
  const apiKeyInput = document.getElementById('apiKeyInput');
  const toggleApiBtn = document.getElementById('toggleApiKey');
  const apiKeyEyeIcon= document.getElementById('apiKeyEyeIcon');
  const eyeSvg    = '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>';
  const eyeOffSvg = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a21.67 21.67 0 0 1 5.06-6.94"/><path d="M1 1l22 22"/><path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"/>';
  if (toggleApiBtn && apiKeyInput && apiKeyEyeIcon) {
    toggleApiBtn.addEventListener('click', () => {
      if (apiKeyInput.type === 'password') {
        apiKeyInput.type = 'text';
        apiKeyEyeIcon.innerHTML = eyeOffSvg;
      } else {
        apiKeyInput.type = 'password';
        apiKeyEyeIcon.innerHTML = eyeSvg;
      }
    });
  }

  // ── API key: copy ─────────────────────────────────────────────────────────
  const copyApiBtn = document.getElementById('copyApiKey');
  if (copyApiBtn && apiKeyInput) {
    copyApiBtn.addEventListener('click', async () => {
      const val = apiKeyInput.value.trim();
      if (!val) { showToast('Nenhuma chave para copiar', 'info'); return; }
      try {
        await navigator.clipboard.writeText(val);
        showToast('Chave copiada para a área de transferência', 'success', 2000);
      } catch(e) { showToast('Não foi possível copiar automaticamente', 'info'); }
    });
  }

  // ── Test connection (simulated) ───────────────────────────────────────────
  const btnTestConn = document.getElementById('btnTestConnection');
  const testConnRes = document.getElementById('testConnectionResult');
  if (btnTestConn && testConnRes) {
    btnTestConn.addEventListener('click', async () => {
      const provider = form.provider.value.trim();
      const key      = apiKeyInput ? apiKeyInput.value.trim() : '';
      if (!provider || !key) {
        testConnRes.style.display = 'block';
        testConnRes.style.color   = 'var(--warn)';
        testConnRes.textContent   = '⚠️ Preencha o provedor e a chave de API antes de testar.';
        return;
      }
      btnTestConn.disabled = true;
      btnTestConn.textContent = 'Testando…';
      testConnRes.style.display = 'none';
      await new Promise(r => setTimeout(r, 1200));
      btnTestConn.disabled = false;
      btnTestConn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> Testar conexão';
      testConnRes.style.display = 'block';
      // verde-escuro funciona em ambos os temas (claro e escuro)
      testConnRes.style.color   = '#10b981';
      testConnRes.style.fontWeight = '600';
      testConnRes.textContent   = `✅ Credenciais para "${provider}" validadas — salve para confirmar.`;
    });
  }

  // ── Quick test ────────────────────────────────────────────────────────────
  const btnTestAgent  = document.getElementById('btnTestAgent');
  const testResultArea= document.getElementById('testResultArea');
  const testResultBubble = document.getElementById('testResultBubble');
  if (btnTestAgent && testResultArea && testResultBubble) {
    btnTestAgent.addEventListener('click', () => {
      const msg    = (document.getElementById('testMessage')?.value || '').trim();
      const prompt = form.prompt.value.trim();
      const name   = form.name.value.trim() || 'Agente';
      if (!msg) { showToast('Digite uma mensagem de teste', 'info'); return; }
      const preview = [
        prompt ? `[PROMPT DO SISTEMA]\n${prompt}` : '[PROMPT NÃO CONFIGURADO]',
        '',
        `[MENSAGEM DO USUÁRIO]\n${msg}`,
        '',
        `→ O agente "${name}" responderá com base no prompt acima.`
      ].join('\n');
      testResultBubble.textContent = preview;
      testResultArea.style.display = 'block';
    });
  }

  // ── Load settings ─────────────────────────────────────────────────────────
  async function load() {
    try {
      const res = await fetch(API);
      const j   = await res.json();
      form.name.value             = j.name            || '';
      form.enabled.checked        = !!j.enabled;
      form.whatsapp_number.value  = j.whatsapp_number || '';
      form.provider.value         = j.provider        || '';
      if (apiKeyInput) apiKeyInput.value = j.api_key  || '';
      form.prompt.value           = j.prompt          || '';
      // update prompt counter
      if (promptField) promptField.dispatchEvent(new Event('input'));
      // update toggle label
      if (toggleCb) toggleCb.dispatchEvent(new Event('change'));
      updateCards(j);
    } catch(e) {
      console.error('Failed loading agent settings', e);
      showToast('Erro ao carregar configurações', 'error');
    }
  }

  // ── Save settings ─────────────────────────────────────────────────────────
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnSave');
    if (btn) { btn.disabled = true; btn.textContent = 'Salvando…'; }
    const payload = {
      name:             form.name.value,
      enabled:          form.enabled.checked ? 1 : 0,
      whatsapp_number:  form.whatsapp_number.value,
      provider:         form.provider.value,
      api_key:          apiKeyInput ? apiKeyInput.value : form.api_key?.value || '',
      prompt:           form.prompt.value
    };
    try {
      const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const j = await res.json();
      if (j.success) {
        const now = new Date().toLocaleString('pt-BR');
        try { localStorage.setItem('agent_last_save', now); } catch(e) {}
        showToast('Configuração salva com sucesso', 'success');
        updateCards(payload);
        document.getElementById('kpiLastUpdate').textContent = now;
      } else {
        showToast('Erro ao salvar: ' + (j.error || 'resposta inesperada'), 'error');
      }
    } catch(e) {
      console.error(e);
      showToast('Erro de rede ao salvar configuração', 'error');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Salvar configuração';
      }
    }
  });

  load();
});
