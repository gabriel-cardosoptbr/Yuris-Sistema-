/**
 * chat_interno.js — Chat Interno YURIS v2 (multi-tenant)
 * Suporta: direto | grupo | geral | setor | filial | matriz | advogado
 * Ações:   criar | arquivar | excluir | editar participantes
 */
const CI = (() => {
  'use strict';

  // ── Estado ──────────────────────────────────────────────────────────────
  const state = {
    convId       : null,
    convTipo     : null,
    convData     : null,    // dados completos da conversa aberta
    firstMsgId   : 0,
    lastMsgId    : 0,
    sending      : false,
    pollTimer    : null,
    listTimer    : null,
    allUsers     : [],      // usuários da mesma conta (cache)
    convCache    : [],      // lista de conversas
    modalData    : null,    // dados do GET ?action=modal_data
    showArquivadas: false,
  };

  // Estado de menções
  const mention = {
    active   : false,
    atPos    : -1,
    cursorEnd: -1,
    query    : '',
    results  : [],
    focusIdx : -1,
  };

  // ── Config de tipos ─────────────────────────────────────────────────────
  const TIPO = {
    direto   : { label: 'Direto',   cls: '',           color: '#7EB8F6', desc: 'Chat privado com um colega',          svg: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>' },
    grupo    : { label: 'Grupo',    cls: '--grupo',    color: '#6EC88E', desc: 'Vários participantes',                svg: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>' },
    geral    : { label: 'Geral',    cls: '--geral',    color: '#7EB8F6', desc: 'Canal de toda a equipe',              svg: '<path d="M22 17H2a3 3 0 0 0 3-3V9a7 7 0 0 1 14 0v5a3 3 0 0 0 3 3zm-8.27 4a2 2 0 0 1-3.46 0"/>' },
    setor    : { label: 'Setor',    cls: '--setor',    color: '#F59E0B', desc: 'Canal do setor / departamento',       svg: '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/>' },
    filial   : { label: 'Filial',   cls: '--filial',   color: '#A78BFA', desc: 'Com conta filial vinculada',          svg: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>' },
    matriz   : { label: 'Matriz',   cls: '--matriz',   color: '#F87171', desc: 'Com a conta matriz principal',        svg: '<line x1="3" y1="22" x2="21" y2="22"/><rect x="2" y="9" width="4" height="13"/><rect x="10" y="9" width="4" height="13"/><rect x="18" y="9" width="4" height="13"/><path d="M12 2L2 9h20z"/>' },
    advogado : { label: 'Advogado', cls: '--advogado', color: '#34D399', desc: 'Advogado associado externo',          svg: '<circle cx="12" cy="8" r="4"/><path d="M6 20v-2a6 6 0 0 1 12 0v2"/><path d="M8 14l-2 6"/><path d="M16 14l2 6"/><path d="M6 20h12"/>' },
  };

  // ── Init ────────────────────────────────────────────────────────────────
  async function init() {
    await loadConvList();
    state.listTimer = setInterval(loadConvList, 10000);
    // Fecha menus ao clicar fora
    document.addEventListener('click', e => {
      if (!e.target.closest('#ciOptionsMenu') && !e.target.closest('.ci-header-opts')) {
        qs('#ciOptionsMenu')?.classList.remove('open');
      }
    });
  }

  // ── Helpers ─────────────────────────────────────────────────────────────
  function qs(sel, ctx = document) { return ctx.querySelector(sel); }

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;');
  }

  function toast(msg, isErr = false) {
    const el = qs('#ciToast');
    if (!el) return;
    el.textContent = msg;
    el.style.display = 'block';
    el.style.background = isErr ? '#3A1020' : '#1A3A5C';
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.display = 'none'; }, 3000);
  }

  function apiFetch(url, method = 'GET', body = null) {
    const opts = { method, headers: { 'Content-Type': 'application/json' } };
    if (body) opts.body = JSON.stringify(body);
    return fetch(url, opts).then(r => r.json());
  }

  function fmtTime(dt) {
    if (!dt) return '';
    const d   = new Date((dt + '').replace(' ', 'T') + (dt.includes('Z') ? '' : 'Z'));
    const now = new Date();
    const diff = Math.floor((now - d) / 86400000);
    const hm  = d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    if (diff === 0) return hm;
    if (diff === 1) return 'Ontem ' + hm;
    if (diff < 7)   return d.toLocaleDateString('pt-BR', { weekday: 'short' }) + ' ' + hm;
    return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) + ' ' + hm;
  }

  function fmtDate(dt) {
    if (!dt) return '';
    const d   = new Date((dt + '').replace(' ', 'T') + (dt.includes('Z') ? '' : 'Z'));
    const now = new Date();
    const diff = Math.floor((now - d) / 86400000);
    if (diff === 0) return 'Hoje';
    if (diff === 1) return 'Ontem';
    return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
  }

  function avatarInitial(nome) {
    return (nome || '?').trim().charAt(0).toUpperCase();
  }

  function chkBoxSvg() {
    return `<svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor"
              stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="1.5 5 4 7.5 8.5 2"/>
            </svg>`;
  }

  // ── Parsear menções ──────────────────────────────────────────────────────
  function parseMentions(text) {
    return esc(text).replace(/@\[(user|proc|card)\|(\d+)\|([^\]]+)\]/g, (_, tipo, id, display) => {
      const urls = {
        user: '/sistema_vendas/public/usuarios.php',
        proc: '/sistema_vendas/public/processos.php?open=' + id,
        card: '/sistema_vendas/public/prospeccao.php?open=' + id,
      };
      const labels = { user: 'Usuário', proc: 'Processo', card: 'Card' };
      return `<a href="${urls[tipo] || '#'}" class="ci-mention ci-mention--${tipo}">` +
             `<span class="ci-mention-label">${labels[tipo]}</span>${esc(display)}</a>`;
    });
  }

  // ── Lista de conversas ───────────────────────────────────────────────────
  async function loadConvList() {
    try {
      const url = CI_API.conversas + '?action=list' + (state.showArquivadas ? '&arquivadas=1' : '');
      const r = await apiFetch(url);
      if (!r.ok) return;
      state.convCache = r.data || [];
      renderConvList(state.convCache);
    } catch(e) { /* silencioso */ }
  }

  function renderConvList(list) {
    const el = qs('#ciConvList');
    if (!list.length) {
      const msg = state.showArquivadas
        ? 'Nenhuma conversa arquivada'
        : 'Nenhuma conversa. Clique em <b>+ Nova</b> para começar.';
      el.innerHTML = `<div style="padding:20px;text-align:center;color:var(--muted);font-size:.78rem">${msg}</div>`;
      return;
    }
    el.innerHTML = list.map(c => {
      const tc      = TIPO[c.tipo] || TIPO.direto;
      const nome    = esc(c.display_nome || c.nome || '?');
      const preview = previewMsg(c.ultima_msg || '');
      const badge   = c.nao_lidas > 0 ? `<span class="ci-badge">${c.nao_lidas}</span>` : '';
      const time    = fmtTime(c.updated_at);
      const active  = c.id == state.convId ? ' active' : '';
      const typeBadge = !['direto','geral'].includes(c.tipo)
        ? `<span class="ci-tipo-tag ci-tipo-tag--${c.tipo}">${tc.label}</span>` : '';

      return `
      <div class="ci-conv-item${active}" onclick="CI.openConv(${c.id})">
        <div class="ci-conv-avatar ci-conv-avatar${tc.cls}">${avatarInitial(c.display_nome || c.nome)}</div>
        <div class="ci-conv-info">
          <div class="ci-conv-name">${nome}</div>
          <div class="ci-conv-preview">${preview}</div>
        </div>
        <div class="ci-conv-meta">
          ${badge}
          ${typeBadge}
          <span class="ci-conv-time">${time}</span>
        </div>
      </div>`;
    }).join('');
  }

  function previewMsg(raw) {
    if (!raw) return '';
    const clean = raw.replace(/@\[[^\]]+\]/g, '').replace(/\s+/g, ' ').trim();
    return esc(clean.length > 60 ? clean.slice(0, 60) + '…' : clean);
  }

  // ── Toggle arquivadas ────────────────────────────────────────────────────
  function toggleArquivadas() {
    state.showArquivadas = !state.showArquivadas;
    const btn   = qs('#ciBtnArch');
    const title = qs('#ciSidebarTitle');
    if (state.showArquivadas) {
      btn?.classList.add('active');
      if (title) title.textContent = 'Arquivadas';
    } else {
      btn?.classList.remove('active');
      if (title) title.textContent = 'Conversas';
    }
    // Fecha conversa aberta (pode ser de outra lista)
    state.convId = null;
    qs('#ciEmpty').style.display    = 'flex';
    qs('#ciHeader').style.display   = 'none';
    qs('#ciMessages').style.display = 'none';
    qs('#ciInputWrap').style.display = 'none';
    stopPolling();
    loadConvList();
  }

  // ── Abrir conversa ───────────────────────────────────────────────────────
  async function openConv(id) {
    if (state.convId === id) return;
    stopPolling();
    state.convId     = id;
    state.firstMsgId = 0;
    state.lastMsgId  = 0;
    state.convData   = null;

    qs('#ciEmpty').style.display      = 'none';
    qs('#ciHeader').style.display     = 'flex';
    qs('#ciMessages').style.display   = 'flex';
    qs('#ciInputWrap').style.display  = 'block';
    qs('#ciMessages').innerHTML = '<div style="text-align:center;color:var(--muted);font-size:.78rem;padding:20px">Carregando…</div>';
    qs('#ciOptionsMenu')?.classList.remove('open');

    renderConvList(state.convCache);

    try {
      const r = await apiFetch(CI_API.conversas + '?action=get&id=' + id);
      if (r.ok) {
        state.convData = r.data;
        fillHeader(r.data);
      }
    } catch(e) {}

    await loadMessages();
    markRead();
    startPolling();
  }

  function fillHeader(conv) {
    state.convTipo = conv.tipo;
    state.convData = conv;
    const tc = TIPO[conv.tipo] || TIPO.direto;
    let nome = conv.nome;
    let sub  = '';

    switch (conv.tipo) {
      case 'direto': {
        const other = (conv.participantes || []).find(p => p.id != CI_UID);
        nome = other ? other.nome : 'Conversa';
        sub  = 'Conversa direta';
        break;
      }
      case 'grupo': {
        const parts = (conv.participantes || []).map(p => p.nome).join(', ');
        sub = parts.length > 80 ? parts.slice(0, 80) + '…' : parts;
        break;
      }
      case 'geral':
        sub = 'Canal da equipe — todos os membros';
        break;
      case 'setor':
        sub = conv.team ? `Setor: ${conv.team.nome}` : 'Canal do setor';
        break;
      case 'filial':
      case 'matriz': {
        const refNome = conv.ref_account_nome || tc.label;
        const parts   = (conv.participantes || []).filter(p => p.id != CI_UID).map(p => p.nome);
        sub = `${refNome}${parts.length ? ' · ' + parts.slice(0, 3).join(', ') : ''}`;
        break;
      }
      case 'advogado': {
        const adv = (conv.participantes || []).find(p => p.id != CI_UID);
        nome = conv.nome || (adv ? `Adv. ${adv.nome}` : 'Advogado');
        sub  = 'Advogado Associado Externo';
        break;
      }
    }

    const avatar = qs('#ciHeaderAvatar');
    avatar.textContent = avatarInitial(nome);
    avatar.className   = `ci-header-avatar ci-header-avatar${tc.cls}`;

    qs('#ciHeaderName').textContent = nome || '—';
    qs('#ciHeaderSub').textContent  = sub;

    // Badge de tipo no header
    const badge = qs('#ciHeaderBadge');
    if (badge) {
      badge.textContent = tc.label;
      badge.className   = `ci-header-badge ci-header-badge--${conv.tipo}`;
      badge.style.display = conv.tipo === 'direto' ? 'none' : '';
    }

    // Atualiza opções do menu
    updateOptionsMenu(conv);
  }

  function updateOptionsMenu(conv) {
    const editBtn  = qs('#ciOptEditPart');
    const archBtn  = qs('#ciOptArchive');
    const delBtn   = qs('#ciOptDelete');

    // "Editar participantes" só faz sentido em grupo, setor, filial, matriz, advogado
    const canEdit = !['geral', 'direto'].includes(conv.tipo);
    if (editBtn) editBtn.style.display = canEdit ? 'flex' : 'none';

    // Texto do botão de arquivar
    const archIcon = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>`;
    if (archBtn) {
      archBtn.innerHTML = conv.archived_at
        ? archIcon + ' Desarquivar'
        : archIcon + ' Arquivar';
    }

    // Canal geral não pode ser excluído
    if (delBtn) delBtn.style.display = conv.tipo !== 'geral' ? 'flex' : 'none';
  }

  // ── Menu 3 pontos ─────────────────────────────────────────────────────────
  function toggleOptionsMenu(e) {
    e.stopPropagation();
    qs('#ciOptionsMenu')?.classList.toggle('open');
  }

  // ── Arquivar / Desarquivar ────────────────────────────────────────────────
  async function archiveConv() {
    qs('#ciOptionsMenu')?.classList.remove('open');
    if (!state.convId) return;
    const isArch = !!(state.convData?.archived_at);
    const action = isArch ? 'unarchive' : 'archive';
    try {
      const r = await apiFetch(CI_API.conversas, 'POST', {
        _csrf: CI_CSRF, action, conversa_id: state.convId
      });
      if (r.ok) {
        toast(isArch ? 'Conversa desarquivada' : 'Conversa arquivada');
        _closeActiveConv();
        loadConvList();
      } else toast(r.error || 'Erro', true);
    } catch(e) { toast('Falha na conexão', true); }
  }

  // ── Excluir ───────────────────────────────────────────────────────────────
  async function deleteConv() {
    qs('#ciOptionsMenu')?.classList.remove('open');
    if (!state.convId) return;
    if (!(await Yuris.confirm('Excluir esta conversa?\n\nEsta ação não pode ser desfeita.', { danger: true, okLabel: 'Excluir conversa' }))) return;
    try {
      const r = await apiFetch(CI_API.conversas, 'POST', {
        _csrf: CI_CSRF, action: 'delete', conversa_id: state.convId
      });
      if (r.ok) {
        toast('Conversa excluída');
        _closeActiveConv();
        loadConvList();
      } else toast(r.error || 'Erro', true);
    } catch(e) { toast('Falha na conexão', true); }
  }

  function _closeActiveConv() {
    state.convId   = null;
    state.convData = null;
    stopPolling();
    qs('#ciEmpty').style.display     = 'flex';
    qs('#ciHeader').style.display    = 'none';
    qs('#ciMessages').style.display  = 'none';
    qs('#ciInputWrap').style.display = 'none';
  }

  // ── Editar Participantes ──────────────────────────────────────────────────
  async function openEditParticipants() {
    qs('#ciOptionsMenu')?.classList.remove('open');
    if (!state.convData) return;

    qs('#ciEditModalOverlay').classList.add('open');

    if (!state.allUsers.length) {
      try {
        const r = await apiFetch(CI_API.users);
        state.allUsers = (r.data || []).filter(u => u.id != CI_UID);
      } catch(e) {}
    }

    const currentIds = (state.convData.participantes || []).map(p => parseInt(p.id));
    renderEditCheckboxes(currentIds);
  }

  function renderEditCheckboxes(selectedIds) {
    const el = qs('#ciEditUserCheckboxes');
    if (!state.allUsers.length) {
      el.innerHTML = '<div style="padding:12px;text-align:center;color:var(--muted);font-size:.78rem">Nenhum usuário disponível</div>';
      return;
    }
    el.innerHTML = state.allUsers.map(u => {
      const init    = esc(u.nome).trim().charAt(0).toUpperCase();
      const checked = selectedIds.includes(parseInt(u.id)) ? 'checked' : '';
      return `<label class="ci-user-check">
        <input type="checkbox" value="${u.id}" name="edit_users" ${checked}>
        <div class="ci-chk-avatar">${init}</div>
        <span class="ci-chk-name">${esc(u.nome)}</span>
        <div class="ci-chk-box">${chkBoxSvg()}</div>
      </label>`;
    }).join('');
  }

  async function saveParticipants() {
    if (!state.convData || !state.convId) return;
    const currentIds = (state.convData.participantes || []).map(p => parseInt(p.id));
    const newIds     = [...document.querySelectorAll('input[name=edit_users]:checked')].map(i => parseInt(i.value));
    const addIds     = newIds.filter(id => !currentIds.includes(id));
    const removeIds  = currentIds.filter(id => !newIds.includes(id) && id !== CI_UID);

    if (!addIds.length && !removeIds.length) { closeEditModal(); return; }

    try {
      const r = await apiFetch(CI_API.conversas, 'POST', {
        _csrf: CI_CSRF, action: 'update_participants',
        conversa_id: state.convId, add_ids: addIds, remove_ids: removeIds,
      });
      if (r.ok) {
        toast('Participantes atualizados');
        closeEditModal();
        // Recarrega dados do header
        const rd = await apiFetch(CI_API.conversas + '?action=get&id=' + state.convId);
        if (rd.ok) { state.convData = rd.data; fillHeader(rd.data); }
      } else toast(r.error || 'Erro', true);
    } catch(e) { toast('Falha na conexão', true); }
  }

  function closeEditModal() {
    qs('#ciEditModalOverlay')?.classList.remove('open');
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Modal: Nova Conversa
  // ══════════════════════════════════════════════════════════════════════════
  async function openNewModal() {
    qs('#ciModalOverlay').classList.add('open');

    // Carrega dados do modal se não estiver em cache
    if (!state.modalData) {
      try {
        const r = await apiFetch(CI_API.conversas + '?action=modal_data');
        if (r.ok) state.modalData = r;
      } catch(e) {}
    }

    qs('#ciModalNome').value = '';
    buildTypeOptions(); // renderiza cards e seleciona o primeiro automaticamente
  }

  function buildTypeOptions() {
    const md          = state.modalData || {};
    const accountTipo = md.account_tipo || 'matriz';
    const grid        = qs('#ciTypeGrid');
    if (!grid) return;

    const keys = ['direto', 'grupo'];
    if (md.teams?.length)                              keys.push('setor');
    if (accountTipo === 'matriz' && md.linked_accounts?.length) keys.push('filial');
    if (accountTipo === 'filial' && md.linked_accounts?.length) keys.push('matriz');
    if (md.advogados?.length)                          keys.push('advogado');

    grid.innerHTML = keys.map(k => {
      const t = TIPO[k];
      return `<div class="ci-type-card" data-type="${k}" onclick="CI.selectType('${k}')">
        <div class="ci-type-card-icon" style="background:${t.color}18;border:1px solid ${t.color}30">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="${t.color}"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${t.svg}</svg>
        </div>
        <div class="ci-type-card-text">
          <div class="ci-type-card-name">${t.label === 'Direto' ? 'Conversa Direta' : t.label === 'Advogado' ? 'Advogado Assoc.' : t.label}</div>
          <div class="ci-type-card-desc">${t.desc}</div>
        </div>
      </div>`;
    }).join('');

    // Seleciona o primeiro por padrão
    selectType(keys[0]);
  }

  function selectType(tipo) {
    const hidden = qs('#ciModalTipo');
    if (hidden) hidden.value = tipo;
    // Visual: borda colorida no card selecionado
    document.querySelectorAll('.ci-type-card').forEach(el => {
      const isSelected = el.dataset.type === tipo;
      const t = TIPO[el.dataset.type] || TIPO.direto;
      el.classList.toggle('selected', isSelected);
      el.style.borderColor = isSelected ? t.color : '';
      el.style.background  = isSelected ? t.color + '14' : '';
    });
    onModalTipoChange();
  }

  function closeNewModal() {
    qs('#ciModalOverlay').classList.remove('open');
    state.modalData = null; // força re-fetch na próxima abertura (dados sempre frescos)
  }

  function onModalTipoChange() {
    const tipo = qs('#ciModalTipo').value;
    const md   = state.modalData || {};

    // Esconde tudo
    ['ciModalNomeWrap','ciModalSetorWrap','ciModalFilialWrap',
     'ciModalParticipantesWrap','ciModalAdvogadoWrap'].forEach(id => {
      const el = qs('#' + id);
      if (el) el.style.display = 'none';
    });

    const users = md.users || [];

    switch (tipo) {
      case 'direto':
        show('ciModalParticipantesWrap');
        qs('#ciParticipantesLabel').textContent = 'Participante';
        renderUserCheckboxes(users, true);
        break;

      case 'grupo':
        show('ciModalNomeWrap');
        show('ciModalParticipantesWrap');
        qs('#ciParticipantesLabel').textContent = 'Participantes';
        renderUserCheckboxes(users, false);
        break;

      case 'setor':
        show('ciModalSetorWrap');
        renderTeamList(md.teams || []);
        break;

      case 'filial': {
        const accounts = md.linked_accounts || [];
        show('ciModalNomeWrap');
        if (accounts.length > 1) show('ciModalFilialWrap');
        show('ciModalParticipantesWrap');
        qs('#ciParticipantesLabel').textContent = 'Participantes da Filial';

        // Popula select de filiais
        const filialSel = qs('#ciFilialSelect');
        if (filialSel) {
          filialSel.innerHTML = accounts.map(a =>
            `<option value="${a.id}">${esc(a.nome)}</option>`
          ).join('');
        }

        const firstId = accounts[0]?.id;
        if (firstId) {
          renderUserCheckboxes(md.linked_users?.[firstId] || [], false);
          qs('#ciModalNome').value = 'Chat com ' + (accounts[0]?.nome || 'Filial');
        } else {
          renderUserCheckboxes([], false);
        }
        break;
      }

      case 'matriz': {
        const accounts = md.linked_accounts || [];
        show('ciModalNomeWrap');
        show('ciModalParticipantesWrap');
        qs('#ciParticipantesLabel').textContent = 'Participantes da Matriz';
        const firstId = accounts[0]?.id;
        if (firstId) {
          renderUserCheckboxes(md.linked_users?.[firstId] || [], false);
          qs('#ciModalNome').value = 'Chat com ' + (accounts[0]?.nome || 'Matriz');
        } else {
          renderUserCheckboxes([], false);
        }
        break;
      }

      case 'advogado':
        show('ciModalAdvogadoWrap');
        renderAdvogadoList(md.advogados || []);
        break;
    }
  }

  function show(id) {
    const el = qs('#' + id);
    if (el) el.style.display = 'block';
  }

  function onFilialChange() {
    const accountId = parseInt(qs('#ciFilialSelect')?.value || 0);
    const md = state.modalData || {};
    if (accountId && md.linked_users) {
      renderUserCheckboxes(md.linked_users[accountId] || [], false);
      const acc = (md.linked_accounts || []).find(a => a.id == accountId);
      if (acc) qs('#ciModalNome').value = 'Chat com ' + acc.nome;
    }
  }

  function renderUserCheckboxes(users, singleSelect) {
    const el = qs('#ciUserCheckboxes');
    if (!users.length) {
      el.innerHTML = '<div style="padding:12px;text-align:center;color:var(--muted);font-size:.78rem">Nenhum usuário disponível</div>';
      return;
    }
    el.innerHTML = users.map(u => {
      const init = esc(u.nome || '?').trim().charAt(0).toUpperCase();
      const type = singleSelect ? 'radio' : 'checkbox';
      const name = singleSelect ? 'conv_user_single' : 'conv_users';
      const sub  = u.account_nome ? `<span class="ci-chk-sub">${esc(u.account_nome)}</span>` : '';
      return `<label class="ci-user-check">
        <input type="${type}" value="${u.id}" name="${name}">
        <div class="ci-chk-avatar">${init}</div>
        <span class="ci-chk-name">${esc(u.nome)}${sub}</span>
        <div class="ci-chk-box">${chkBoxSvg()}</div>
      </label>`;
    }).join('');
  }

  function renderTeamList(teams) {
    const el = qs('#ciTeamList');
    if (!teams.length) {
      el.innerHTML = '<div style="padding:12px;text-align:center;color:var(--muted);font-size:.78rem">Nenhum setor disponível</div>';
      return;
    }
    el.innerHTML = teams.map((t, i) => {
      const cor     = t.cor || '#3B82F6';
      const initial = esc(t.nome).charAt(0).toUpperCase();
      return `<label class="ci-user-check" onclick="CI.onTeamSelect(${t.id})">
        <input type="radio" value="${t.id}" name="conv_team" ${i === 0 ? 'checked' : ''}>
        <div class="ci-chk-avatar" style="background:${cor}1A;border-color:${cor}44;color:${cor}">${initial}</div>
        <span class="ci-chk-name">${esc(t.nome)}</span>
        <div class="ci-chk-box">${chkBoxSvg()}</div>
      </label>`;
    }).join('');

    // Mostra membros do primeiro setor automaticamente
    if (teams.length) onTeamSelect(teams[0].id);
  }

  function onTeamSelect(teamId) {
    const md      = state.modalData || {};
    const team    = (md.teams || []).find(t => t.id == teamId);
    const wrap    = qs('#ciSetorMembrosWrap');
    const membros = qs('#ciSetorMembros');
    if (!wrap || !membros || !team) return;

    // Usa team.members diretamente (inclui o usuário logado e reflete o banco atual)
    const members = team.members || [];

    if (!members.length) {
      wrap.style.display = 'block';
      membros.innerHTML = '<span style="font-size:.75rem;color:var(--muted)">Nenhum membro cadastrado neste setor</span>';
      return;
    }

    wrap.style.display = 'block';
    const cor = team.cor || '#3B82F6';
    membros.innerHTML = members.map(u => {
      const init = esc(u.nome).trim().charAt(0).toUpperCase();
      return `<div title="${esc(u.nome)}" style="
        display:flex; align-items:center; gap:6px;
        background:${cor}12; border:1px solid ${cor}28;
        padding:4px 10px 4px 6px; border-radius:20px;
      ">
        <div style="
          width:22px; height:22px; border-radius:50%; flex-shrink:0;
          background:${cor}25; border:1px solid ${cor}50;
          display:flex; align-items:center; justify-content:center;
          font-size:.65rem; font-weight:700; color:${cor};
        ">${init}</div>
        <span style="font-size:.75rem; color:var(--text); white-space:nowrap">${esc(u.nome)}</span>
      </div>`;
    }).join('');
  }

  function renderAdvogadoList(advogados) {
    const el = qs('#ciAdvogadoList');
    if (!advogados.length) {
      el.innerHTML = '<div style="padding:12px;text-align:center;color:var(--muted);font-size:.78rem">Nenhum advogado associado disponível. Envie um convite primeiro.</div>';
      return;
    }
    el.innerHTML = advogados.map((a, i) => {
      const nome = esc(a.user_nome || a.convidado_nome || 'Advogado');
      const init = nome.trim().charAt(0).toUpperCase();
      const sub  = a.convidado_email ? `<span class="ci-chk-sub">${esc(a.convidado_email)}</span>` : '';
      return `<label class="ci-user-check">
        <input type="radio" value="${a.convidado_user_id}" name="conv_adv" ${i === 0 ? 'checked' : ''}>
        <div class="ci-chk-avatar" style="background:rgba(52,211,153,.12);border-color:rgba(52,211,153,.25);color:#34D399">${init}</div>
        <span class="ci-chk-name">${nome}${sub}</span>
        <div class="ci-chk-box">${chkBoxSvg()}</div>
      </label>`;
    }).join('');
  }

  async function confirmNewConv() {
    const tipo = qs('#ciModalTipo').value;
    let r;
    try {
      switch (tipo) {
        case 'direto': {
          const checked = [...document.querySelectorAll('input[name=conv_user_single]:checked')].map(i => parseInt(i.value));
          if (!checked.length) { toast('Selecione 1 participante', true); return; }
          r = await apiFetch(CI_API.conversas, 'POST', {
            _csrf: CI_CSRF, action: 'create_direct', usuario_id: checked[0],
          });
          break;
        }
        case 'grupo': {
          const nome    = qs('#ciModalNome').value.trim();
          const checked = [...document.querySelectorAll('input[name=conv_users]:checked')].map(i => parseInt(i.value));
          if (!nome)          { toast('Informe o nome do grupo', true); return; }
          if (!checked.length){ toast('Selecione ao menos 1 participante', true); return; }
          r = await apiFetch(CI_API.conversas, 'POST', {
            _csrf: CI_CSRF, action: 'create_group', nome, usuario_ids: checked,
          });
          break;
        }
        case 'setor': {
          const teamId = parseInt(document.querySelector('input[name=conv_team]:checked')?.value || 0);
          if (!teamId) { toast('Selecione um setor', true); return; }
          r = await apiFetch(CI_API.conversas, 'POST', {
            _csrf: CI_CSRF, action: 'create_setor', team_id: teamId,
          });
          break;
        }
        case 'filial':
        case 'matriz': {
          const nome    = qs('#ciModalNome').value.trim();
          const checked = [...document.querySelectorAll('input[name=conv_users]:checked')].map(i => parseInt(i.value));
          let refAccountId;
          if (tipo === 'filial') {
            refAccountId = parseInt(qs('#ciFilialSelect')?.value || 0);
          } else {
            refAccountId = state.modalData?.linked_accounts?.[0]?.id || 0;
          }
          if (!refAccountId)  { toast('Conta vinculada não encontrada', true); return; }
          if (!checked.length){ toast('Selecione ao menos 1 participante', true); return; }
          r = await apiFetch(CI_API.conversas, 'POST', {
            _csrf: CI_CSRF, action: 'create_vinculada',
            tipo, ref_account_id: refAccountId, nome, usuario_ids: checked,
          });
          break;
        }
        case 'advogado': {
          const advUserId = parseInt(document.querySelector('input[name=conv_adv]:checked')?.value || 0);
          if (!advUserId) { toast('Selecione um advogado', true); return; }
          r = await apiFetch(CI_API.conversas, 'POST', {
            _csrf: CI_CSRF, action: 'create_advogado', advogado_user_id: advUserId,
          });
          break;
        }
        default:
          toast('Tipo inválido', true); return;
      }

      if (r?.ok) {
        closeNewModal();
        state.modalData = null; // invalida cache
        await loadConvList();
        if (r.conversa_id) openConv(r.conversa_id);
      } else {
        toast(r?.error || 'Erro ao criar conversa', true);
      }
    } catch(e) { toast('Falha na conexão', true); }
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Mensagens
  // ══════════════════════════════════════════════════════════════════════════
  async function loadMessages(beforeId = 0) {
    if (!state.convId) return;
    try {
      let url = CI_API.mensagens + '?conversa_id=' + state.convId + '&limit=50';
      if (beforeId) url += '&before_id=' + beforeId;

      const r = await apiFetch(url);
      if (!r.ok) return;

      const msgs  = r.data || [];
      const msgEl = qs('#ciMessages');

      if (!msgs.length && !beforeId) {
        msgEl.innerHTML = '<div style="text-align:center;color:var(--muted);font-size:.78rem;padding:20px">Nenhuma mensagem ainda. Seja o primeiro a escrever.</div>';
        return;
      }
      if (!msgs.length) return;

      if (!beforeId) {
        msgEl.innerHTML  = '';
        state.firstMsgId = msgs[0]?.id || 0;
        state.lastMsgId  = msgs[msgs.length - 1]?.id || 0;
        msgEl.innerHTML  = renderMsgList(msgs);
        msgEl.scrollTop  = msgEl.scrollHeight;
      } else {
        const prevH = msgEl.scrollHeight;
        state.firstMsgId = msgs[0]?.id || state.firstMsgId;
        const html = renderMsgList(msgs);
        qs('#ciLoadMore')?.remove();
        msgEl.insertAdjacentHTML('afterbegin', html);
        msgEl.scrollTop = msgEl.scrollHeight - prevH;
      }

      if (msgs.length === 50 && !qs('#ciLoadMore', msgEl)) {
        msgEl.insertAdjacentHTML('afterbegin',
          '<button class="ci-load-more" id="ciLoadMore" onclick="CI.loadMore()">Carregar mais</button>'
        );
      }
    } catch(e) { console.error('loadMessages', e); }
  }

  async function loadMore() {
    if (!state.firstMsgId) return;
    await loadMessages(state.firstMsgId);
  }

  async function pollNew() {
    if (!state.convId || !state.lastMsgId) return;
    try {
      const r = await apiFetch(CI_API.mensagens + '?conversa_id=' + state.convId + '&after_id=' + state.lastMsgId);
      if (!r.ok || !r.data?.length) return;

      const msgEl   = qs('#ciMessages');
      const atBottom = msgEl.scrollHeight - msgEl.scrollTop - msgEl.clientHeight < 80;

      r.data.forEach(msg => {
        state.lastMsgId = Math.max(state.lastMsgId, msg.id);
        msgEl.querySelector('div[style*="Nenhuma mensagem"]')?.remove();
        msgEl.insertAdjacentHTML('beforeend', renderMsg(msg, true));
      });

      if (atBottom) msgEl.scrollTop = msgEl.scrollHeight;
      markRead();
      loadConvList();
    } catch(e) {}
  }

  function renderMsgList(msgs) {
    let html = '', lastDate = '';
    msgs.forEach(msg => {
      const d = fmtDate(msg.created_at);
      if (d !== lastDate) {
        html += `<div class="ci-date-sep"><span>${d}</span></div>`;
        lastDate = d;
      }
      html += renderMsg(msg);
    });
    return html;
  }

  function renderMsg(msg) {
    const isOut      = msg.usuario_id == CI_UID;
    const dir        = isOut ? 'out' : 'in';
    const showSender = !isOut && state.convTipo !== 'direto';
    const sender     = showSender ? `<div class="ci-msg-sender">${esc(msg.usuario_nome)}</div>` : '';
    const texto      = parseMentions(msg.mensagem || '');
    const time       = fmtTime(msg.created_at);

    return `<div class="ci-msg ci-msg--${dir}" id="cmsg-${msg.id}">
      ${sender}
      <div class="ci-msg-bubble">${texto}</div>
      <div class="ci-msg-time">${time}</div>
    </div>`;
  }

  // ── Enviar mensagem ──────────────────────────────────────────────────────
  async function send() {
    if (state.sending) return;
    const ta  = qs('#ciTextarea');
    const txt = ta.value.trim();
    if (!txt || !state.convId) return;

    state.sending = true;
    const btn = qs('.ci-send-btn');
    if (btn) btn.disabled = true;

    const mencoes = extractMencoes(txt);
    try {
      const r = await apiFetch(CI_API.mensagens, 'POST', {
        _csrf: CI_CSRF, conversa_id: state.convId, mensagem: txt, mencoes,
      });
      if (r.ok && r.data) {
        ta.value = '';
        ta.style.height = '';
        closeMentionPanel();

        const msgEl = qs('#ciMessages');
        msgEl.querySelector('div[style*="Nenhuma mensagem"]')?.remove();
        state.lastMsgId = Math.max(state.lastMsgId, r.data.id);
        msgEl.insertAdjacentHTML('beforeend', renderMsg(r.data));
        msgEl.scrollTop = msgEl.scrollHeight;
        loadConvList();
        markRead();
      } else {
        toast(r.error || 'Erro ao enviar', true);
      }
    } catch(e) { toast('Falha na conexão', true); }
    finally {
      state.sending = false;
      if (btn) btn.disabled = false;
    }
  }

  function extractMencoes(texto) {
    const re = /@\[(user|proc|card)\|(\d+)\|([^\]]+)\]/g;
    const out = [];
    const tipoMap = { user: 'usuario', proc: 'processo', card: 'card' };
    const urlMap  = {
      user: '/sistema_vendas/public/usuarios.php',
      proc: id => '/sistema_vendas/public/processos.php?id=' + id,
      card: id => '/sistema_vendas/public/prospeccao.php?card=' + id,
    };
    let m;
    while ((m = re.exec(texto)) !== null) {
      const [, tipo, id, display] = m;
      const url = typeof urlMap[tipo] === 'function' ? urlMap[tipo](id) : urlMap[tipo];
      out.push({ tipo: tipoMap[tipo], referencia_id: parseInt(id), texto_exibido: display, url_destino: url });
    }
    return out;
  }

  function markRead() {
    if (!state.convId) return;
    apiFetch(CI_API.conversas, 'POST', { _csrf: CI_CSRF, action: 'mark_read', conversa_id: state.convId })
      .catch(() => {});
  }

  // ── Polling ──────────────────────────────────────────────────────────────
  function startPolling() {
    stopPolling();
    state.pollTimer = setInterval(pollNew, 3000);
  }

  function stopPolling() {
    clearInterval(state.pollTimer);
    state.pollTimer = null;
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Sistema de menções
  // ══════════════════════════════════════════════════════════════════════════
  let _mpanelTab      = 'auto';
  let _mpanelDebounce = null;

  function onInput(ta) {
    ta.style.height = '';
    ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';

    const before   = ta.value.slice(0, ta.selectionStart);
    const atMatch  = before.match(/@(?!\[)([^@]*)$/);

    if (atMatch) {
      mention.atPos     = before.length - atMatch[0].length;
      mention.cursorEnd = ta.selectionStart;
      openMentionPanel(atMatch[1]);
    } else {
      closeMentionPanel();
    }
  }

  function openMentionPanel(initialQuery = '') {
    const panel = qs('#ciMentionPanel');
    const input = qs('#ciMpanelSearch');
    if (!panel || !input) return;
    mention.active = true;
    panel.classList.add('open');
    input.value = initialQuery;
    input.focus();
    doMpanelSearch(initialQuery);
  }

  function closeMentionPanel() {
    mention.active  = false;
    mention.results = [];
    mention.focusIdx = -1;
    qs('#ciMentionPanel')?.classList.remove('open');
  }

  function setMentionTab(tab, btn) {
    _mpanelTab = tab;
    document.querySelectorAll('.ci-mtab').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    doMpanelSearch(qs('#ciMpanelSearch')?.value || '');
  }

  function onMpanelSearch(q) {
    clearTimeout(_mpanelDebounce);
    _mpanelDebounce = setTimeout(() => doMpanelSearch(q), 200);
  }

  async function doMpanelSearch(q) {
    const resultsEl = qs('#ciMpanelResults');
    if (!resultsEl) return;
    resultsEl.innerHTML = '<div class="ci-mpanel-empty">Buscando…</div>';
    try {
      const url = CI_API.mencoes + '?q=' + encodeURIComponent(q) + '&type=' + _mpanelTab + '&limit=30';
      const r = await apiFetch(url);
      mention.results  = r.data || [];
      mention.focusIdx = -1;
      renderMpanelResults(mention.results, q);
    } catch(e) {
      resultsEl.innerHTML = '<div class="ci-mpanel-empty">Erro ao buscar</div>';
    }
  }

  function renderMpanelResults(results, q) {
    const el = qs('#ciMpanelResults');
    if (!results.length) {
      el.innerHTML = `<div class="ci-mpanel-empty">Nenhum resultado${q ? ' para "' + esc(q) + '"' : ''}</div>`;
      return;
    }
    const groups    = { usuario: [], processo: [], card: [] };
    const labelMap  = { usuario: 'Usuários', processo: 'Processos', card: 'Cards' };
    results.forEach(item => { (groups[item.tipo] || groups.card).push(item); });

    let html = '', globalIdx = 0;
    const renderItem = (item, idx) => {
      const iconClass = item.tipo === 'processo' ? '--proc' : item.tipo === 'card' ? '--card' : '';
      const letter    = item.tipo === 'usuario' ? avatarInitial(item.display) : item.tipo === 'processo' ? 'P' : 'C';
      return `<div class="ci-mention-item${mention.focusIdx === idx ? ' focused' : ''}" onclick="CI.selectMentionItem(${idx})">
        <div class="ci-mention-icon ci-mention-icon${iconClass}">${letter}</div>
        <div class="ci-mention-text">
          <div class="ci-mention-name">${esc(item.display)}</div>
          ${item.sub ? `<div class="ci-mention-sub">${esc(item.sub)}</div>` : ''}
        </div>
      </div>`;
    };

    if (_mpanelTab === 'auto') {
      ['usuario','processo','card'].forEach(tipo => {
        if (!groups[tipo].length) return;
        html += `<div class="ci-mpanel-section">${labelMap[tipo]}</div>`;
        groups[tipo].forEach(item => { html += renderItem(item, globalIdx++); });
      });
    } else {
      results.forEach(item => { html += renderItem(item, globalIdx++); });
    }
    el.innerHTML = html;
  }

  function selectMentionItem(idx) {
    const item = mention.results[idx];
    if (!item) return;
    const ta     = qs('#ciTextarea');
    const val    = ta.value;
    const before = val.slice(0, mention.atPos);
    const after  = val.slice(mention.cursorEnd ?? mention.atPos);
    ta.value     = before + item.token + ' ' + after;
    const pos    = (before + item.token + ' ').length;
    ta.setSelectionRange(pos, pos);
    ta.focus();
    closeMentionPanel();
    ta.style.height = '';
    ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
  }

  function onMpanelKey(e) {
    if (e.key === 'Escape')    { closeMentionPanel(); qs('#ciTextarea')?.focus(); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); mention.focusIdx = Math.min(mention.focusIdx + 1, mention.results.length - 1); renderMpanelResults(mention.results, qs('#ciMpanelSearch')?.value || ''); }
    if (e.key === 'ArrowUp')   { e.preventDefault(); mention.focusIdx = Math.max(mention.focusIdx - 1, 0); renderMpanelResults(mention.results, qs('#ciMpanelSearch')?.value || ''); }
    if (e.key === 'Enter' && mention.focusIdx >= 0) { e.preventDefault(); selectMentionItem(mention.focusIdx); }
  }

  function onKeyDown(e) {
    if (e.key === 'Escape' && mention.active) { closeMentionPanel(); return; }
    if (e.key === 'Enter' && !e.shiftKey && !mention.active) { e.preventDefault(); send(); }
  }

  // ── API pública ──────────────────────────────────────────────────────────
  return {
    init, openConv, loadMore,
    // mensagens
    onInput, onKeyDown, send,
    // menções
    selectMentionItem, closeMentionPanel, setMentionTab, onMpanelSearch, onMpanelKey,
    // modal nova conversa
    openNewModal, closeNewModal, onModalTipoChange, onFilialChange, confirmNewConv, selectType, onTeamSelect,
    // header / opções
    toggleOptionsMenu, archiveConv, deleteConv, toggleArquivadas,
    // edit participants
    openEditParticipants, closeEditModal, saveParticipants,
  };
})();

document.addEventListener('DOMContentLoaded', CI.init);
