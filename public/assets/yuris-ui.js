/**
 * Yuris UI — biblioteca de notificações e diálogos que substitui os modais
 * nativos do navegador (alert/confirm/prompt), eliminando o "localhost diz"
 * que vaza o nome do servidor e bloqueia a UI.
 *
 * API:
 *   Yuris.notify(message, opts)        → Promise<void>  (substitui alert)
 *   Yuris.confirm(message, opts)       → Promise<boolean>
 *   Yuris.prompt(message, opts)        → Promise<string|null>
 *
 * Polyfill (lado-efeito ao carregar):
 *   window.alert(msg) → vira Yuris.notify(msg) (compatível: ainda síncrono-aparente)
 *
 * confirm() e prompt() do navegador NÃO são polyfillados porque são síncronos
 * e retornam valor — o substituto é async (Promise). Use Yuris.confirm/prompt
 * diretamente com await.
 *
 * Tema-aware: lê data-theme do <html> e ajusta paleta.
 */
(function (root) {
  if (root.Yuris && typeof root.Yuris.confirm === 'function') return; // já carregada
  root.Yuris = root.Yuris || {};

  // ── Injeta CSS uma única vez ────────────────────────────────────────────
  function injectStyles() {
    if (document.getElementById('yuris-ui-styles')) return;
    const css = `
      .yui-overlay {
        position: fixed; inset: 0; z-index: 99999;
        display: flex; align-items: center; justify-content: center;
        background: rgba(15,23,42,.62);
        backdrop-filter: blur(6px);
        animation: yui-fade-in .14s ease-out;
      }
      .yui-overlay.is-closing { animation: yui-fade-out .12s ease-in forwards; }
      .yui-dialog {
        background: linear-gradient(165deg, rgba(13,28,48,.99), rgba(8,18,35,.99));
        border: 1px solid rgba(96,165,250,.22);
        border-radius: 14px;
        padding: 22px 26px 18px;
        width: 460px;
        max-width: 92vw;
        box-shadow: 0 24px 60px rgba(2,6,23,.7);
        color: #D8E4F0;
        font-family: 'Inter','Poppins',system-ui,-apple-system,Roboto,sans-serif;
        animation: yui-pop-in .18s cubic-bezier(.34,1.56,.64,1);
      }
      .yui-icon { width: 38px; height: 38px; border-radius: 10px; display:flex; align-items:center; justify-content:center; margin-bottom: 10px; }
      .yui-icon svg { width: 22px; height: 22px; stroke-width: 2.2; }
      .yui-icon-info    { background: rgba(37,99,235,.18);   color: #93c5fd; }
      .yui-icon-warn    { background: rgba(245,158,11,.18);  color: #fbbf24; }
      .yui-icon-danger  { background: rgba(239,68,68,.18);   color: #fca5a5; }
      .yui-icon-success { background: rgba(34,197,94,.18);   color: #86efac; }

      .yui-title { font-size: 1rem; font-weight: 700; color: #FFFFFF; margin: 0 0 6px; letter-spacing: .01em; }
      .yui-message { font-size: .9rem; color: #c8d8ec; line-height: 1.5; white-space: pre-wrap; }
      .yui-input {
        margin-top: 12px; width: 100%;
        padding: 10px 12px; border-radius: 8px;
        background: rgba(5,18,39,.85); border: 1px solid rgba(96,165,250,.25);
        color: #D8E4F0; font-size: .9rem;
        outline: none;
      }
      .yui-input:focus { border-color: rgba(96,165,250,.55); box-shadow: 0 0 0 3px rgba(37,99,235,.18); }
      .yui-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
      .yui-btn {
        padding: 8px 16px; border-radius: 8px; font-size: .82rem; font-weight: 600;
        cursor: pointer; border: 1px solid transparent; transition: all .15s;
        font-family: inherit;
      }
      .yui-btn-primary  { background: #2563EB; color: #FFFFFF; }
      .yui-btn-primary:hover  { background: #1D4ED8; }
      .yui-btn-danger   { background: #DC2626; color: #FFFFFF; }
      .yui-btn-danger:hover   { background: #B91C1C; }
      .yui-btn-secondary{ background: transparent; color: #c8d8ec; border-color: rgba(160,180,210,.22); }
      .yui-btn-secondary:hover{ background: rgba(160,180,210,.08); }

      /* Toast (notify) — canto inferior direito */
      .yui-toast-stack {
        position: fixed; bottom: 22px; right: 22px; z-index: 99998;
        display: flex; flex-direction: column; gap: 10px; pointer-events: none;
      }
      .yui-toast {
        pointer-events: auto;
        background: linear-gradient(165deg, rgba(13,28,48,.98), rgba(8,18,35,.98));
        border: 1px solid rgba(96,165,250,.28);
        border-left-width: 3px;
        border-radius: 10px;
        padding: 12px 16px 12px 14px;
        min-width: 280px; max-width: 420px;
        box-shadow: 0 10px 30px rgba(2,6,23,.4);
        color: #D8E4F0;
        font-family: 'Inter','Poppins',system-ui,-apple-system,Roboto,sans-serif;
        font-size: .85rem;
        display: flex; align-items: flex-start; gap: 10px;
        animation: yui-slide-in .22s cubic-bezier(.34,1.56,.64,1);
      }
      .yui-toast.is-closing { animation: yui-slide-out .18s ease-in forwards; }
      .yui-toast-info    { border-left-color: #60A5FA; }
      .yui-toast-warn    { border-left-color: #F59E0B; }
      .yui-toast-danger  { border-left-color: #EF4444; }
      .yui-toast-success { border-left-color: #22C55E; }
      .yui-toast-icon { flex: 0 0 18px; margin-top: 1px; }
      .yui-toast-icon svg { width: 18px; height: 18px; stroke-width: 2.2; }
      .yui-toast-info    .yui-toast-icon { color: #60A5FA; }
      .yui-toast-warn    .yui-toast-icon { color: #F59E0B; }
      .yui-toast-danger  .yui-toast-icon { color: #EF4444; }
      .yui-toast-success .yui-toast-icon { color: #22C55E; }
      .yui-toast-content { flex: 1; min-width: 0; }
      .yui-toast-title { font-weight: 700; color: #FFFFFF; margin-bottom: 2px; font-size: .85rem; }
      .yui-toast-body { color: #c8d8ec; white-space: pre-wrap; }
      .yui-toast-close {
        flex: 0 0 18px; background: none; border: none; padding: 0;
        color: #7a8898; cursor: pointer; font-size: 1.2rem; line-height: 1;
        margin-top: -2px;
      }
      .yui-toast-close:hover { color: #FFFFFF; }

      /* ── Tema claro ────────────────────────────────────────────── */
      html[data-theme="light"] .yui-overlay { background: rgba(15,23,42,.40); }
      html[data-theme="light"] .yui-dialog {
        background: #FFFFFF;
        border-color: #E2E8F0;
        color: #1E3A5F;
        box-shadow: 0 24px 60px rgba(15,23,42,.18);
      }
      html[data-theme="light"] .yui-title   { color: #0F172A; }
      html[data-theme="light"] .yui-message { color: #1E3A5F; }
      html[data-theme="light"] .yui-input {
        background: #FFFFFF; border-color: #CBD5E1; color: #0F172A;
      }
      html[data-theme="light"] .yui-btn-secondary {
        background: #FFFFFF; color: #475569; border-color: #CBD5E1;
      }
      html[data-theme="light"] .yui-btn-secondary:hover { background: #F8FAFC; }

      html[data-theme="light"] .yui-toast {
        background: #FFFFFF;
        border-color: #E2E8F0;
        color: #1E3A5F;
        box-shadow: 0 10px 30px rgba(15,23,42,.12);
      }
      html[data-theme="light"] .yui-toast-title { color: #0F172A; }
      html[data-theme="light"] .yui-toast-body  { color: #475569; }

      @keyframes yui-fade-in   { from {opacity:0} to {opacity:1} }
      @keyframes yui-fade-out  { from {opacity:1} to {opacity:0} }
      @keyframes yui-pop-in    { from {opacity:0;transform:scale(.96) translateY(8px)} to {opacity:1;transform:scale(1) translateY(0)} }
      @keyframes yui-slide-in  { from {opacity:0;transform:translateX(14px)} to {opacity:1;transform:translateX(0)} }
      @keyframes yui-slide-out { from {opacity:1;transform:translateX(0)} to {opacity:0;transform:translateX(14px)} }
    `;
    const s = document.createElement('style');
    s.id = 'yuris-ui-styles';
    s.textContent = css;
    document.head.appendChild(s);
  }

  // ── Helpers ─────────────────────────────────────────────────────────────
  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }
  const ICONS = {
    info:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    warn:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    danger:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"/></svg>',
  };

  // ── Dialog (notify/confirm/prompt) ──────────────────────────────────────
  function openDialog(opts) {
    injectStyles();
    return new Promise(resolve => {
      const ov = document.createElement('div');
      ov.className = 'yui-overlay';

      const dlg = document.createElement('div');
      dlg.className = 'yui-dialog';
      dlg.setAttribute('role', 'dialog');
      dlg.setAttribute('aria-modal', 'true');

      const type = opts.type || 'info';
      const iconHtml = ICONS[type] || ICONS.info;

      let inputHtml = '';
      if (opts.kind === 'prompt') {
        const def = escapeHtml(opts.defaultValue || '');
        const ph  = escapeHtml(opts.placeholder || '');
        inputHtml = `<input class="yui-input" type="text" value="${def}" placeholder="${ph}" />`;
      }

      const okLabel     = escapeHtml(opts.okLabel    || (opts.kind === 'confirm' ? 'Confirmar' : 'OK'));
      const cancelLabel = escapeHtml(opts.cancelLabel|| 'Cancelar');
      const okClass     = type === 'danger' ? 'yui-btn-danger' : 'yui-btn-primary';

      const showCancel  = opts.kind !== 'notify';

      dlg.innerHTML =
        `<div class="yui-icon yui-icon-${type}">${iconHtml}</div>` +
        (opts.title ? `<h3 class="yui-title">${escapeHtml(opts.title)}</h3>` : '') +
        `<div class="yui-message">${escapeHtml(opts.message || '')}</div>` +
        inputHtml +
        `<div class="yui-actions">
          ${showCancel ? `<button type="button" class="yui-btn yui-btn-secondary" data-act="cancel">${cancelLabel}</button>` : ''}
          <button type="button" class="yui-btn ${okClass}" data-act="ok">${okLabel}</button>
        </div>`;
      ov.appendChild(dlg);
      document.body.appendChild(ov);

      const input = dlg.querySelector('.yui-input');
      const okBtn = dlg.querySelector('[data-act="ok"]');
      const cancelBtn = dlg.querySelector('[data-act="cancel"]');

      function close(result) {
        ov.classList.add('is-closing');
        setTimeout(() => { ov.remove(); resolve(result); }, 120);
      }
      okBtn.addEventListener('click', () => {
        if (opts.kind === 'prompt')      close(input ? input.value : '');
        else if (opts.kind === 'confirm')close(true);
        else                              close(undefined);
      });
      if (cancelBtn) cancelBtn.addEventListener('click', () => {
        close(opts.kind === 'prompt' ? null : false);
      });
      // ESC fecha
      function onKey(e) {
        if (e.key === 'Escape') { document.removeEventListener('keydown', onKey); close(opts.kind === 'prompt' ? null : (opts.kind === 'confirm' ? false : undefined)); }
        if (e.key === 'Enter' && document.activeElement !== cancelBtn) {
          document.removeEventListener('keydown', onKey);
          if (opts.kind === 'prompt')      close(input ? input.value : '');
          else if (opts.kind === 'confirm')close(true);
          else                              close(undefined);
        }
      }
      document.addEventListener('keydown', onKey);
      // Click no overlay (fora do dialog) fecha como cancelar (exceto prompt)
      ov.addEventListener('click', e => {
        if (e.target === ov && opts.kind !== 'prompt') {
          close(opts.kind === 'confirm' ? false : undefined);
        }
      });
      // Foco
      setTimeout(() => (input || okBtn).focus(), 60);
    });
  }

  // ── Toast (lightweight, não bloqueia) ───────────────────────────────────
  function ensureStack() {
    let s = document.getElementById('yuris-toast-stack');
    if (!s) {
      s = document.createElement('div');
      s.id = 'yuris-toast-stack';
      s.className = 'yui-toast-stack';
      document.body.appendChild(s);
    }
    return s;
  }
  function toast(opts) {
    injectStyles();
    const stack = ensureStack();
    const t = document.createElement('div');
    const type = opts.type || 'info';
    t.className = `yui-toast yui-toast-${type}`;
    t.innerHTML =
      `<div class="yui-toast-icon">${ICONS[type] || ICONS.info}</div>` +
      `<div class="yui-toast-content">` +
        (opts.title ? `<div class="yui-toast-title">${escapeHtml(opts.title)}</div>` : '') +
        `<div class="yui-toast-body">${escapeHtml(opts.message || '')}</div>` +
      `</div>` +
      `<button type="button" class="yui-toast-close" aria-label="Fechar">×</button>`;
    stack.appendChild(t);
    const close = () => {
      t.classList.add('is-closing');
      setTimeout(() => t.remove(), 200);
    };
    t.querySelector('.yui-toast-close').addEventListener('click', close);
    const duration = opts.duration != null ? opts.duration : 4200;
    if (duration > 0) setTimeout(close, duration);
    return { close };
  }

  // ── API pública ─────────────────────────────────────────────────────────
  root.Yuris.notify = function (message, opts) {
    opts = opts || {};
    // Mensagem curta sem título → toast (não bloqueia). Caso contrário, dialog.
    if (!opts.dialog && !opts.title && String(message || '').length < 180 && !opts.blocking) {
      toast({ message, type: opts.type || 'info', duration: opts.duration, title: opts.toastTitle });
      return Promise.resolve();
    }
    return openDialog({
      kind: 'notify',
      type: opts.type || 'info',
      title: opts.title,
      message: message,
      okLabel: opts.okLabel,
    });
  };
  root.Yuris.confirm = function (message, opts) {
    opts = opts || {};
    return openDialog({
      kind: 'confirm',
      type: opts.type || (opts.danger ? 'danger' : 'warn'),
      title: opts.title || 'Confirmar ação',
      message: message,
      okLabel: opts.okLabel || (opts.danger ? 'Excluir' : 'Confirmar'),
      cancelLabel: opts.cancelLabel,
    });
  };
  root.Yuris.prompt = function (message, opts) {
    opts = opts || {};
    return openDialog({
      kind: 'prompt',
      type: opts.type || 'info',
      title: opts.title || 'Informe um valor',
      message: message,
      defaultValue: opts.defaultValue,
      placeholder: opts.placeholder,
      okLabel: opts.okLabel || 'Confirmar',
      cancelLabel: opts.cancelLabel,
    });
  };

  // Convenience helpers (mais expressivos que types crus)
  root.Yuris.toast = (message, type = 'info', opts = {}) =>
    root.Yuris.notify(message, Object.assign({ type }, opts));

  // ── Tradução de ações de histórico/auditoria ───────────────────────────
  // Todas as tabelas *_history e audit_log gravam acao em inglês/snake_case
  // (ex.: 'created', 'updated', 'moved', 'archived'). Esta função traduz pra PT-BR
  // pra exibir em UI. Aceita também variantes com namespace (ex.: 'card.moved').
  // Use em qualquer linha de histórico: `Yuris.translateAuditAcao(item.acao)`.
  //
  // Caso a acao venha desconhecida (módulo novo sem entrada aqui), retorna a
  // string original com `_` viradas em espaço e capitalizada — fallback razoável
  // que não quebra a UI.
  const AUDIT_ACAO_MAP = {
    // CRUD genérico
    'created':              'Criado',
    'updated':              'Atualizado',
    'deleted':              'Excluído',
    'archived':             'Arquivado',
    'restored':             'Restaurado',
    'moved':                'Movido',
    'reorder':              'Reordenado',
    'reordered':            'Reordenado',
    'duplicated':           'Duplicado',
    'merged':               'Mesclado',
    // Checklist
    'checklist_add':        'Item adicionado ao checklist',
    'checklist_update':     'Item do checklist atualizado',
    'checklist_toggle':     'Item do checklist marcado/desmarcado',
    'checklist_delete':     'Item do checklist removido',
    'checklist_reorder':    'Checklist reordenado',
    // Status / fluxo
    'status_changed':       'Status alterado',
    'stage_changed':        'Etapa alterada',
    'responsavel_changed':  'Responsável alterado',
    'assigned':             'Atribuído',
    'unassigned':           'Desatribuído',
    'closed':               'Fechado',
    'reopened':             'Reaberto',
    'completed':            'Concluído',
    'cancelled':            'Cancelado',
    // Vínculos
    'linked':               'Vinculado',
    'unlinked':             'Desvinculado',
    'shared':               'Compartilhado',
    'unshared':             'Compartilhamento removido',
    // Comentários / docs
    'comment_added':        'Comentário adicionado',
    'comment_deleted':      'Comentário removido',
    'document_added':       'Documento adicionado',
    'document_deleted':     'Documento removido',
    'attachment_added':     'Anexo adicionado',
    'attachment_deleted':   'Anexo removido',
    // Tempo / prazo
    'deadline_set':         'Prazo definido',
    'deadline_changed':     'Prazo alterado',
    'deadline_met':         'Prazo cumprido',
    'deadline_missed':      'Prazo perdido',
    // Login / sessão
    'login':                'Login',
    'logout':               'Logout',
    'login_failed':         'Falha no login',
    'password_changed':     'Senha alterada',
    // LGPD
    'consent_given':        'Consentimento dado',
    'consent_revoked':      'Consentimento revogado',
    'anonymized':           'Anonimizado',
    'exported':             'Exportado',
  };

  /**
   * Traduz uma string de "acao" do audit log para PT-BR.
   * Aceita strings simples ("moved"), namespaced ("card.moved") e snake_case ("checklist_add").
   * Fallback: converte snake_case → "Snake Case" capitalizado.
   */
  root.Yuris.translateAuditAcao = function (acao) {
    if (acao == null || acao === '') return '';
    const raw = String(acao).trim();
    if (raw === '') return '';

    // 1) Namespaced ("card.moved" → tenta "moved")
    const lastDot = raw.lastIndexOf('.');
    const bareKey = lastDot >= 0 ? raw.slice(lastDot + 1) : raw;
    const lowKey  = bareKey.toLowerCase();

    if (AUDIT_ACAO_MAP[lowKey]) return AUDIT_ACAO_MAP[lowKey];

    // 2) Match exato com a string completa (caso o map tenha 'card.created' etc)
    const fullLow = raw.toLowerCase();
    if (AUDIT_ACAO_MAP[fullLow]) return AUDIT_ACAO_MAP[fullLow];

    // 3) Fallback: snake_case → "Title Case Português-aproximado"
    const cleaned = bareKey.replace(/[_-]+/g, ' ').trim();
    return cleaned.charAt(0).toUpperCase() + cleaned.slice(1).toLowerCase();
  };

  // ── Polyfill: window.alert vira Yuris.notify (não-bloqueante) ──────────
  // Mantém assinatura síncrona-aparente (não retorna Promise pra não quebrar
  // chamadas legadas tipo `alert('oi'); doSomething();` — o doSomething continua
  // executando imediatamente, e o toast aparece em paralelo).
  if (!root.__yuris_alert_patched) {
    root.__yuris_alert_patched = true;
    root.alert = function (msg) {
      // Auto-detecta tipo pelo conteúdo (heurística simples — pode ser refinada)
      const text = String(msg == null ? '' : msg);
      let type = 'info';
      const low = text.toLowerCase();
      if (/erro|falha|inv[áa]lido|não.*pode|não.*pode/.test(low))         type = 'danger';
      else if (/aten[çc][ãa]o|aviso|cuidado|atrasad|warning/.test(low))         type = 'warn';
      else if (/sucesso|ok|salvo|atualizad|criado|conclu[íi]d/.test(low))       type = 'success';
      // mensagens com várias linhas viram dialog (mais legíveis)
      const useDialog = text.length >= 180 || text.includes('\n\n');
      if (useDialog) {
        root.Yuris.notify(text, { type, dialog: true });
      } else {
        toast({ message: text, type });
      }
    };
  }
})(window);
