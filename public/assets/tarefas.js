/* tarefas.js — Lógica completa do módulo Tarefas YURIS */
'use strict';

const BASE  = '/api';
const CSRF  = window.YURIS_CSRF;
const ME_ID = window.YURIS_USER_ID;

/* ── Helpers HTTP ─────────────────────────────────────────────────────────── */
async function api(path, opts = {}) {
  const url = BASE + path;
  const defaults = { headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, cache: 'no-store' };
  const res = await fetch(url, Object.assign(defaults, opts));
  if (!res.ok && res.status !== 200) throw new Error(await res.text());
  return res.json();
}
const GET    = (path)        => api(path, { method: 'GET' });
const POST   = (path, body)  => api(path, { method: 'POST',   body: JSON.stringify({ ...body, csrf_token: CSRF }) });
const PUT    = (path, body)  => api(path, { method: 'PUT',    body: JSON.stringify({ ...body, csrf_token: CSRF }) });
const PATCH  = (path, body)  => api(path, { method: 'PATCH',  body: JSON.stringify({ ...body, csrf_token: CSRF }) });
const DEL    = (path, body)  => api(path, { method: 'DELETE', body: JSON.stringify({ ...body, csrf_token: CSRF }) });

/* ── Escape HTML ──────────────────────────────────────────────────────────── */
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

/* ── Toast de notificação ─────────────────────────────────────────────────── */
function showToast(msg, { type = 'success', duration = 4500 } = {}) {
  let container = document.getElementById('tkToastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'tkToastContainer';
    container.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;display:flex;flex-direction:column;gap:10px;pointer-events:none;';
    document.body.appendChild(container);
  }
  const colors = { success: '#16a34a', info: '#2563eb', warn: '#d97706', error: '#dc2626' };
  const icons  = {
    success: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    info:    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    warn:    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    error:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
  };
  const toast = document.createElement('div');
  toast.style.cssText = `display:flex;align-items:center;gap:10px;background:${colors[type]};color:#fff;padding:11px 16px;border-radius:10px;box-shadow:0 4px 18px rgba(0,0,0,.22);font-size:14px;line-height:1.4;max-width:340px;pointer-events:auto;cursor:default;opacity:0;transform:translateY(12px);transition:opacity .22s,transform .22s;`;
  toast.innerHTML = `<span style="flex-shrink:0">${icons[type]||''}</span><span>${msg}</span>`;
  container.appendChild(toast);
  requestAnimationFrame(() => { toast.style.opacity = '1'; toast.style.transform = 'translateY(0)'; });
  const remove = () => {
    toast.style.opacity = '0'; toast.style.transform = 'translateY(12px)';
    setTimeout(() => toast.remove(), 260);
  };
  toast.addEventListener('click', remove);
  setTimeout(remove, duration);
}

/* ── Modal de confirmação customizado ─────────────────────────────────────── */
function tkConfirm(msg, { confirmLabel = 'Excluir', confirmColor = '#dc2626', icon = 'warn' } = {}) {
  return new Promise(resolve => {
    const overlay = document.getElementById('tkConfirmOverlay');
    const msgEl   = document.getElementById('tkConfirmMsg');
    const yesBtn  = document.getElementById('tkConfirmYes');
    const noBtn   = document.getElementById('tkConfirmNo');
    const iconEl  = document.getElementById('tkConfirmIcon');
    if (!overlay) {
      // Fallback: usa Yuris.confirm (sem "localhost diz")
      if (window.Yuris && typeof Yuris.confirm === 'function') {
        Yuris.confirm(msg).then(resolve);
      } else {
        resolve(window.confirm(msg));
      }
      return;
    }

    const icons = {
      warn:    `<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
      danger:  `<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`,
      archive: `<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>`,
    };

    iconEl.innerHTML  = icons[icon] || icons.warn;
    msgEl.textContent = msg;
    yesBtn.textContent = confirmLabel;
    yesBtn.style.background = confirmColor;
    overlay.style.display = 'flex';

    const finish = val => {
      overlay.style.display = 'none';
      yesBtn.replaceWith(yesBtn.cloneNode(true));
      noBtn.replaceWith(noBtn.cloneNode(true));
      resolve(val);
    };

    document.getElementById('tkConfirmYes').addEventListener('click', () => finish(true),  { once: true });
    document.getElementById('tkConfirmNo' ).addEventListener('click', () => finish(false), { once: true });
    overlay.addEventListener('click', e => { if (e.target === overlay) finish(false); }, { once: true });
  });
}

function tkAlert(msg) {
  return tkConfirm(msg, { confirmLabel: 'OK', confirmColor: '#1d4ed8', icon: 'danger' });
}

/* ── Estado global ────────────────────────────────────────────────────────── */
let boards      = [];
let currentBoard= null;
let columns     = [];
let tasks       = [];
let users       = [];
let currentView = 'kanban';
let calDate     = new Date();
let dragTaskId  = null;
let openTaskId  = null;

/* ── Filtros ──────────────────────────────────────────────────────────────── */
const filters = { responsavel_id: '', prioridade: '', prazo: '', busca: '' };

/* ── Init ─────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', async () => {
  await loadUsers();
  await loadBoards();
  bindTopbar();
  bindDrawer();
  bindModals();
  bindCalendar();
});

/* ── Carregar usuários ────────────────────────────────────────────────────── */
async function loadUsers() {
  try {
    const r = await GET('/task_users.php');
    users = r.data || [];
    populateUserSelects();
  } catch(e) { console.warn('users:', e); }
}
function populateUserSelects() {
  const selects = ['fltResponsavel','dResponsavel','ntResponsavel'];
  selects.forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const cur = el.value;
    const opts = id === 'fltResponsavel' ? '<option value="">Responsável</option>' : '<option value="">Sem responsável</option>';
    el.innerHTML = opts + users.map(u => `<option value="${u.id}">${esc(u.nome)}</option>`).join('');
    el.value = cur;
  });
}

/* ── Carregar quadros ─────────────────────────────────────────────────────── */
async function loadBoards(selectId = null) {
  const r = await GET('/task_boards.php');
  boards = r.data || [];
  renderBoardSelect();
  if (!boards.length) return;

  // Prioridade de seleção:
  // 1. ID explicitamente solicitado (ex: novo quadro criado)
  // 2. Quadro atual ainda existe na lista → mantém ele
  // 3. Fallback: primeiro da lista
  const keepId = selectId
    ?? (currentBoard && boards.find(b => b.id === currentBoard.id) ? currentBoard.id : null)
    ?? boards[0].id;

  selectBoard(keepId);
}
function renderBoardSelect() {
  const sel = document.getElementById('tkBoardSelect');
  if (!boards.length) { sel.innerHTML = '<option>Nenhum quadro</option>'; return; }
  sel.innerHTML = boards.map(b => `<option value="${b.id}">${esc(b.nome)}</option>`).join('');
  if (currentBoard) sel.value = currentBoard.id;
}
async function selectBoard(boardId) {
  const r = await GET(`/task_boards.php?id=${boardId}`);
  currentBoard = r.data;
  columns      = currentBoard.colunas || [];
  renderColSelect('dColuna', columns);
  renderColSelect('ntColuna', columns);
  await loadTasks();
}

function renderColSelect(elId, cols) {
  const el = document.getElementById(elId);
  if (!el) return;
  el.innerHTML = cols.map(c => `<option value="${c.id}">${esc(c.nome)}</option>`).join('');
}

/* ── Carregar tarefas ─────────────────────────────────────────────────────── */
async function loadTasks() {
  if (!currentBoard) return;
  let qs = `?board_id=${currentBoard.id}`;
  if (filters.responsavel_id) qs += `&responsavel_id=${filters.responsavel_id}`;
  if (filters.prioridade)     qs += `&prioridade=${filters.prioridade}`;
  if (filters.prazo)          qs += `&prazo=${filters.prazo}`;
  if (filters.busca)          qs += `&busca=${encodeURIComponent(filters.busca)}`;

  const r = await GET(`/tasks.php${qs}`);
  tasks = r.data || [];
  renderView();
}

/* ── Views ────────────────────────────────────────────────────────────────── */
// Filtro de Origem (Matriz / Filiais / específica) — aplicado client-side
// porque o backend não tem param dedicado e o conjunto já é pequeno.
function _applyOriginFilter(arr) {
  const origin = (filters && filters.origin) || '';
  if (!origin) return arr;
  return arr.filter(t => {
    const tipo = String(t.origin_account_tipo || '').toLowerCase();
    const oid  = String(t.origin_account_id || '');
    if (origin === '__matriz__'  && tipo !== 'matriz') return false;
    if (origin === '__filiais__' && tipo !== 'filial') return false;
    if (origin !== '__matriz__' && origin !== '__filiais__' && oid !== origin) return false;
    return true;
  });
}

function renderView() {
  document.getElementById('tkKanban').style.display     = currentView === 'kanban'    ? 'flex'           : 'none';
  document.getElementById('tkLista').style.display      = currentView === 'lista'     ? 'flex'           : 'none';
  document.getElementById('tkCalendario').style.display = currentView === 'calendario'? 'flex'           : 'none';
  document.getElementById('tkLista').style.flexDirection= 'column';

  // Aplica filtro de origem sobre tasks ANTES de cada view (sem reload no server).
  const _all = tasks;
  tasks = _applyOriginFilter(_all);
  try {
    if (currentView === 'kanban')    renderKanban();
    if (currentView === 'lista')     renderLista();
    if (currentView === 'calendario')renderCalendario();
  } finally {
    tasks = _all; // restaura array original (drag/drop e edits precisam dele inteiro)
  }
}

/* ── Kanban ───────────────────────────────────────────────────────────────── */
function renderKanban() {
  const el = document.getElementById('tkKanban');
  el.innerHTML = '';
  columns.forEach(col => {
    const colTasks = tasks.filter(t => t.column_id == col.id);
    const colEl = document.createElement('div');
    colEl.className = 'tk-col';
    colEl.dataset.colId = col.id;
    colEl.innerHTML = `
      <div class="tk-col-header">
        <div class="tk-col-dot" style="background:${col.cor || '#94a3b8'}"></div>
        <div class="tk-col-name">${esc(col.nome)}</div>
        <div class="tk-col-count">${colTasks.length}</div>
      </div>
      <div class="tk-col-body" id="colBody_${col.id}" data-col-id="${col.id}"></div>
      <div class="tk-col-footer">
        <button class="tk-add-inline" data-col-id="${col.id}">+ Adicionar tarefa</button>
      </div>
    `;
    el.appendChild(colEl);

    const body = colEl.querySelector('.tk-col-body');
    colTasks.sort((a,b)=>a.ordem-b.ordem).forEach(t => body.appendChild(buildCard(t)));

    // ─── BUGFIX: drag-and-drop com SortableJS ────────────────────────────────
    // ANTES: usava HTML5 nativo, ordem = body.children.length + 1 → sempre ia
    // pro FINAL da coluna (ignorava posição do drop entre cards).
    // AGORA: SortableJS (igual Pipeline) captura newIndex real do drop e
    // suporta arrastar entre colunas mantendo posição exata.
    if (typeof Sortable !== 'undefined') {
      Sortable.create(body, {
        group: 'tarefas-kanban',     // permite arrastar entre TODAS as colunas
        animation: 150,
        ghostClass: 'tk-card-ghost',
        dragClass: 'tk-card-dragging',
        forceFallback: false,
        onEnd: async (evt) => {
          const card     = evt.item;
          const taskId   = parseInt(card.dataset.taskId, 10);
          const toColEl  = evt.to;                              // body.tk-col-body
          const toColId  = parseInt(toColEl.dataset.colId, 10);
          const newIndex = evt.newIndex;                        // 0-based index na coluna destino
          // ordem é 1-based no banco — ordem do banco é apenas relativa entre cards
          // da mesma coluna, então passar newIndex + 1 já posiciona corretamente.
          if (!taskId || !toColId) return;
          try {
            await POST('/tasks.php?action=move', {
              id: taskId,
              column_id: toColId,
              ordem: newIndex + 1,
            });
            // reload completo pra normalizar ordem (mover cards de baixo, etc)
            await loadTasks();
          } catch (e) {
            // se falhou, recarrega pra desfazer visualmente
            await loadTasks();
          }
        },
      });
    }
    // ─────────────────────────────────────────────────────────────────────────
  });

  // inline add
  el.querySelectorAll('.tk-add-inline').forEach(btn => {
    btn.addEventListener('click', () => showInlineAdd(btn));
  });
}

function buildCard(t) {
  const el = document.createElement('div');
  el.className = 'tk-card';
  el.dataset.prioridade = t.prioridade;
  el.dataset.taskId     = t.id;   // BUGFIX: Sortable usa pra identificar no onEnd
  // (el.draggable e os listeners dragstart/dragend abaixo são legado do HTML5
  //  drag nativo — Sortable não precisa deles, mas deixamos pra fallback de
  //  acessibilidade se Sortable não carregar. Não atrapalham.)
  el.draggable = true;

  const prazoStr = t.prazo ? formatDate(t.prazo) : '';
  const atrasado = t.prazo && new Date(t.prazo) < new Date() && t.status === 'ativa';

  const checkTotal = parseInt(t.checklist_total) || 0;
  const checkFeito = parseInt(t.checklist_feito) || 0;
  const barPct     = checkTotal ? Math.round(checkFeito/checkTotal*100) : 0;

  // Ícone de recorrência (aparece no canto superior direito do card)
  const recIcon = t.recorrencia_id
    ? `<span class="tk-rec-badge" title="Tarefa recorrente"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg></span>`
    : '';

  // Faixa de Origem (MATRIZ / FILIAL — Nome) — só quando há múltiplas contas
  // visíveis (matriz com filiais). data-origin-tipo abre padding via CSS.
  let originStripHtml = '';
  if (window.YURIS_SHOW_ORIGIN_STRIP && t.origin_account_tipo) {
    const tipo  = String(t.origin_account_tipo).toLowerCase();
    const nome  = String(t.origin_account_nome || '');
    const cls   = tipo === 'matriz' ? 'is-matriz' : 'is-filial';
    const label = tipo === 'matriz' ? 'MATRIZ'   : 'FILIAL';
    el.setAttribute('data-origin-tipo', tipo);
    el.setAttribute('data-origin-id',   String(t.origin_account_id || ''));
    originStripHtml = `<div class="tk-card-origin-strip ${cls}">
      <span>${label}</span>
      <span class="org-name" title="${esc(nome)}">${esc(nome)}</span>
    </div>`;
  }

  el.innerHTML = `
    ${originStripHtml}
    <div class="tk-card-title">${esc(t.titulo)}${recIcon}</div>
    <div class="tk-card-meta">
      <span class="tk-badge tk-badge-${t.prioridade}">${labelPrioridade(t.prioridade)}</span>
      ${prazoStr ? `<span class="tk-prazo${atrasado?' atrasado':''}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        ${prazoStr}
      </span>` : ''}
    </div>
    <div class="tk-card-footer">
      <div class="tk-card-icons">
        ${t.total_comentarios > 0 ? `<span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>${t.total_comentarios}</span>` : ''}
        ${checkTotal > 0 ? `<span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>${checkFeito}/${checkTotal}</span>` : ''}
      </div>
      ${t.responsavel_nome ? `<div class="tk-avatar-sm">${esc(t.responsavel_nome.charAt(0).toUpperCase())}</div>` : ''}
    </div>
    ${checkTotal > 0 ? `<div class="tk-checklist-bar"><div class="tk-checklist-progress" style="width:${barPct}%"></div></div>` : ''}
    ${recStatusCard(t)}
  `;

  el.addEventListener('click', () => openDrawer(t.id));
  el.addEventListener('dragstart', () => { dragTaskId = t.id; el.classList.add('dragging'); });
  el.addEventListener('dragend',   () => { dragTaskId = null; el.classList.remove('dragging'); });
  return el;
}

function showInlineAdd(btn) {
  const colId = btn.dataset.colId;
  const footer = btn.parentElement;
  btn.style.display = 'none';
  const form = document.createElement('div');
  form.className = 'tk-inline-form';
  form.innerHTML = `
    <textarea class="tk-inline-input" placeholder="Título da tarefa" rows="2"></textarea>
    <div class="tk-inline-actions">
      <button class="tk-btn-sm tk-btn-ok">Adicionar</button>
      <button class="tk-btn-sm tk-btn-cancel">Cancelar</button>
    </div>`;
  footer.appendChild(form);
  const ta = form.querySelector('textarea');
  ta.focus();

  form.querySelector('.tk-btn-ok').addEventListener('click', async () => {
    const titulo = ta.value.trim();
    if (!titulo) return;
    await POST('/tasks.php', { board_id: currentBoard.id, column_id: parseInt(colId), titulo });
    await loadTasks();
  });
  form.querySelector('.tk-btn-cancel').addEventListener('click', () => {
    form.remove();
    btn.style.display = '';
  });
  ta.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.querySelector('.tk-btn-ok').click(); }
    if (e.key === 'Escape') form.querySelector('.tk-btn-cancel').click();
  });
}

/* ── Lista ────────────────────────────────────────────────────────────────── */
function renderLista() {
  const el = document.getElementById('tkLista');
  el.innerHTML = `
    <div class="tk-list-row tk-list-header">
      <span>Título</span><span>Prioridade</span><span>Prazo</span><span>Responsável</span><span></span>
    </div>
    ${tasks.map(t => `
      <div class="tk-list-row" data-id="${t.id}" style="cursor:pointer;">
        <span>${esc(t.titulo)}</span>
        <span><span class="tk-badge tk-badge-${t.prioridade}">${labelPrioridade(t.prioridade)}</span></span>
        <span style="color:${t.prazo&&new Date(t.prazo)<new Date()?'#f87171':'#7A8898'}">${t.prazo?formatDate(t.prazo):'—'}</span>
        <span>${t.responsavel_nome?esc(t.responsavel_nome):'—'}</span>
        <span style="color:#3D6A96;font-size:.78rem;">▶</span>
      </div>
    `).join('')}
  `;
  el.querySelectorAll('[data-id]').forEach(row =>
    row.addEventListener('click', () => openDrawer(parseInt(row.dataset.id)))
  );
}

/* ── Calendário ───────────────────────────────────────────────────────────── */
function renderCalendario() {
  const year  = calDate.getFullYear();
  const month = calDate.getMonth();
  const label = calDate.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
  document.getElementById('calMonthLabel').textContent = label.charAt(0).toUpperCase() + label.slice(1);

  const grid = document.getElementById('calGrid');
  grid.innerHTML = '';
  ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'].forEach(d => {
    const div = document.createElement('div');
    div.className = 'tk-cal-day-label';
    div.textContent = d;
    grid.appendChild(div);
  });

  const first = new Date(year, month, 1).getDay();
  const days  = new Date(year, month+1, 0).getDate();
  const today = new Date();

  for (let i = 0; i < first; i++) {
    const div = document.createElement('div');
    div.className = 'tk-cal-cell other-month';
    grid.appendChild(div);
  }

  for (let d = 1; d <= days; d++) {
    const cell = document.createElement('div');
    cell.className = 'tk-cal-cell';
    const isToday = today.getDate()===d && today.getMonth()===month && today.getFullYear()===year;
    if (isToday) cell.classList.add('today');

    const dayTasks = tasks.filter(t => {
      if (!t.prazo) return false;
      const p = new Date(t.prazo);
      return p.getDate()===d && p.getMonth()===month && p.getFullYear()===year;
    });

    cell.innerHTML = `<div class="tk-cal-num">${d}</div>` +
      dayTasks.slice(0,3).map(t=>`<div class="tk-cal-dot" data-id="${t.id}">${esc(t.titulo)}</div>`).join('');
    if (dayTasks.length > 3) cell.innerHTML += `<div class="tk-cal-dot" style="color:#4A5568;">+${dayTasks.length-3} mais</div>`;
    grid.appendChild(cell);
    cell.querySelectorAll('[data-id]').forEach(d => d.addEventListener('click', e => {
      e.stopPropagation();
      openDrawer(parseInt(d.dataset.id));
    }));
  }
}

/* ── Drawer ───────────────────────────────────────────────────────────────── */
async function openDrawer(taskId) {
  openTaskId = taskId;
  const overlay = document.getElementById('tkDrawerOverlay');
  const drawer  = document.getElementById('tkDrawer');
  overlay.classList.add('open');
  drawer.classList.add('open');
  await refreshDrawer();
  switchTab('geral');
}
function closeDrawer() {
  document.getElementById('tkDrawerOverlay').classList.remove('open');
  document.getElementById('tkDrawer').classList.remove('open');
  openTaskId = null;
}
async function refreshDrawer() {
  if (!openTaskId) return;
  let r;
  try { r = await GET(`/tasks.php?id=${openTaskId}`); }
  catch(e) { console.error('[refreshDrawer] API error:', e); return; }
  const t = r?.data;
  if (!t) { console.warn('[refreshDrawer] no task data, r=', r); return; }

  document.getElementById('dTitle').value     = t.titulo;
  document.getElementById('dDescricao').value = t.descricao || '';
  document.getElementById('dPrioridade').value= t.prioridade;
  refreshPrazoSelects(t.prazo_tipo);
  document.getElementById('dPrazoTipo').value = t.prazo_tipo;
  document.getElementById('dPrazo').value     = t.prazo ? t.prazo.slice(0,16) : '';
  document.getElementById('dResponsavel').value = t.responsavel_id || '';
  document.getElementById('dColuna').value    = t.column_id;

  // recorrência
  const rec = t.recorrencia;
  const recToggle = document.getElementById('dRecToggle');
  const recFields = document.getElementById('dRecFields');
  recToggle.checked = !!(rec && rec.ativa);
  recFields.style.display = recToggle.checked ? 'flex' : 'none';
  if (rec) {
    document.getElementById('dRecTipo').value      = rec.tipo      || 'diaria';
    document.getElementById('dRecIntervalo').value = rec.intervalo || 1;
    document.getElementById('dRecInicio').value    = rec.data_inicio ? rec.data_inicio.slice(0,10) : '';
    document.getElementById('dRecFim').value       = rec.data_fim   ? rec.data_fim.slice(0,10)    : '';
    if (rec.tipo === 'custom' && rec.unidade) {
      document.getElementById('dRecUnidade').value = rec.unidade;
    }
  } else {
    document.getElementById('dRecTipo').value      = 'diaria';
    document.getElementById('dRecIntervalo').value = 1;
    document.getElementById('dRecInicio').value    = '';
    document.getElementById('dRecFim').value       = '';
  }
  toggleCustomRec('d');

  renderChecklist(t.checklist || []);
  try { renderLinks(t.links || []); } catch(e) { console.error('[renderLinks] error:', e); }
  renderComments(t.comentarios || []);
  renderTempo(t.tempo || []);
  renderHistorico(t.historico || []);
}
function switchTab(name) {
  document.querySelectorAll('.tk-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
  document.querySelectorAll('.tk-tab-pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + name));
}

/* ── Drawer: salvar ── */
async function saveDrawer() {
  if (!openTaskId) return;

  const btn = document.getElementById('dBtnSave');
  const orig = btn.textContent;
  btn.textContent = 'Salvando…';
  btn.disabled = true;

  try {
    const recorrente = document.getElementById('dRecToggle').checked;
    let recorrencia = null;
    if (recorrente) {
      const dTipo = document.getElementById('dRecTipo').value;
      recorrencia = {
        tipo:        dTipo,
        intervalo:   dTipo === 'custom' ? (parseInt(document.getElementById('dRecIntervalo').value) || 1) : 1,
        unidade:     dTipo === 'custom' ? document.getElementById('dRecUnidade').value : null,
        data_inicio: document.getElementById('dRecInicio').value || new Date().toISOString().slice(0,10),
        data_fim:    document.getElementById('dRecFim').value || null,
      };
    }

    const r = await PUT('/tasks.php', {
      id:             openTaskId,
      titulo:         document.getElementById('dTitle').value.trim(),
      descricao:      document.getElementById('dDescricao').value,
      prioridade:     document.getElementById('dPrioridade').value,
      prazo_tipo:     document.getElementById('dPrazoTipo').value,
      prazo:          document.getElementById('dPrazo').value || null,
      responsavel_id: document.getElementById('dResponsavel').value || null,
      column_id:      document.getElementById('dColuna').value,
      recorrencia,
    });

    if (!r.ok) throw new Error(r.error || 'Erro desconhecido');

    btn.textContent = '✓ Salvo';
    btn.style.background = 'rgba(42,157,110,0.35)';
    setTimeout(() => {
      btn.textContent = orig;
      btn.style.background = '';
      btn.disabled = false;
    }, 1500);

    await loadTasks();
    await refreshDrawer();

  } catch (e) {
    btn.textContent = orig;
    btn.disabled = false;
    await tkAlert('Erro ao salvar: ' + e.message);
    console.error(e);
  }
}

/* ── Checklist ─────────────────────────────────────────────────────────────── */
function renderChecklist(items) {
  const total = items.length;
  const done  = items.filter(i => i.concluido).length;
  const pct   = total ? Math.round(done / total * 100) : 0;

  document.getElementById('dCheckProgress').textContent = `${pct}% concluído (${done}/${total})`;
  document.getElementById('dCheckProgressBar').style.width = pct + '%';

  const list = document.getElementById('dCheckList');
  list.innerHTML = '';
  if (!items.length) {
    list.innerHTML = '<div style="font-size:.8rem;color:#7a8898;padding:4px 0;">Nenhuma tarefa</div>';
    return;
  }
  const svgCal = `<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`;
  const svgEdit = `<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
  const svgDel  = `<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`;

  const chkSvg = `<svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1.5 5 4 7.5 8.5 2"/></svg>`;

  items.forEach(i => {
    const row = document.createElement('div');
    row.dataset.id = i.id;
    row.className = 'tk-check-item' + (i.concluido ? ' done' : '');

    const prazoStr = i.prazo ? (() => {
      const d    = new Date(i.prazo + 'T00:00:00');
      const hoje = new Date(); hoje.setHours(0,0,0,0);
      const diff = Math.ceil((d - hoje) / 86400000);
      const label = d.toLocaleDateString('pt-BR', {day:'2-digit',month:'2-digit'});
      const cor = diff < 0 ? '#fca5a5' : diff === 0 ? '#fcd34d' : '#9ab0c9';
      return `<span class="tk-check-prazo" style="color:${cor};">${svgCal} ${label}</span>`;
    })() : '';

    row.innerHTML = `
      <input type="checkbox" ${i.concluido ? 'checked' : ''}>
      <div class="tk-chk-box">${i.concluido ? chkSvg : ''}</div>
      <div class="tk-check-body">
        <span class="tk-check-desc">${esc(i.descricao)}</span>
      </div>
      ${prazoStr}
      <button class="tk-check-btn-edit" title="Editar">${svgEdit}</button>
      <button class="tk-check-btn-del"  title="Remover">${svgDel}</button>
    `;

    // toggle concluído — clique no checkbox customizado ou no input nativo
    row.querySelector('input[type=checkbox]').addEventListener('change', async () => {
      await POST('/task_checklist.php?action=toggle', { id: i.id });
      await refreshDrawer(); await loadTasks();
    });
    row.querySelector('.tk-chk-box').addEventListener('click', () => {
      row.querySelector('input[type=checkbox]').click();
    });

    // editar inline
    row.querySelector('.tk-check-btn-edit').addEventListener('click', () => {
      const prazoVal = i.prazo || '';
      row.innerHTML = `
        <input class="ci-edit-desc" value="${esc(i.descricao)}" style="flex:1;font-size:.82rem;background:rgba(5,18,39,.9);border:1px solid rgba(96,165,250,.3);border-radius:5px;color:#d6eaff;padding:3px 7px;outline:none;">
        <input class="ci-edit-prazo" type="date" value="${prazoVal}" style="font-size:.72rem;background:rgba(5,18,39,.9);border:1px solid rgba(96,165,250,.2);border-radius:5px;color:#9ab0c9;padding:2px 6px;outline:none;color-scheme:dark;">
        <button class="ci-save" title="Salvar" style="background:#1d4ed8;border:none;border-radius:5px;color:#fff;cursor:pointer;padding:3px 10px;font-size:.78rem;">Salvar</button>
        <button class="ci-cancel" title="Cancelar" style="background:transparent;border:none;color:#9ab0c9;cursor:pointer;padding:3px 7px;font-size:.78rem;">✕</button>
      `;
      row.querySelector('.ci-edit-desc').focus();

      row.querySelector('.ci-save').addEventListener('click', async () => {
        const desc  = row.querySelector('.ci-edit-desc').value.trim();
        const prazo = row.querySelector('.ci-edit-prazo').value || null;
        if (!desc) return;
        await PATCH('/task_checklist.php', { id: i.id, descricao: desc, prazo });
        await refreshDrawer(); await loadTasks();
      });

      row.querySelector('.ci-cancel').addEventListener('click', async () => {
        await refreshDrawer();
      });

      row.querySelector('.ci-edit-desc').addEventListener('keydown', async e => {
        if (e.key === 'Enter') row.querySelector('.ci-save').click();
        if (e.key === 'Escape') row.querySelector('.ci-cancel').click();
      });
    });

    // remover
    row.querySelector('.tk-check-btn-del').addEventListener('click', async () => {
      await DEL('/task_checklist.php', { id: i.id });
      await refreshDrawer(); await loadTasks();
    });

    list.appendChild(row);
  });
}

async function addCheckItem() {
  const input   = document.getElementById('dCheckNew');
  const prazoEl = document.getElementById('dCheckPrazo');
  const msgEl   = document.getElementById('dCheckMsg');
  const val     = input.value.trim();

  if (!val) { input.focus(); return; }

  if (!openTaskId) {
    if (msgEl) { msgEl.textContent = 'Abra uma tarefa existente para adicionar itens.'; msgEl.style.display = 'block'; }
    return;
  }

  if (msgEl) msgEl.style.display = 'none';
  const btn = document.getElementById('dCheckAdd');
  btn.textContent = '...'; btn.disabled = true;

  try {
    const r = await POST('/task_checklist.php', {
      task_id: openTaskId,
      descricao: val,
      prazo: prazoEl.value || null
    });
    if (!r || !r.ok) {
      if (msgEl) { msgEl.textContent = 'Erro: ' + (r?.error || 'falha ao salvar'); msgEl.style.display = 'block'; }
    } else {
      input.value = '';
      prazoEl.value = '';
      await refreshDrawer();
      await loadTasks();
    }
  } catch(e) {
    if (msgEl) { msgEl.textContent = 'Erro de conexão: ' + e.message; msgEl.style.display = 'block'; }
  }

  btn.textContent = 'Adicionar'; btn.disabled = false;
}
document.getElementById('dCheckNew').addEventListener('keydown', e => { if (e.key === 'Enter') addCheckItem(); });
document.getElementById('dCheckAdd').addEventListener('click', addCheckItem);

/* ── Recorrência personalizada ─────────────────────────────────────────────── */
window.toggleCustomRec = function(prefix) {
  const tipo   = document.getElementById(prefix + 'RecTipo')?.value;
  const custom = document.getElementById(prefix + 'RecCustom');
  if (custom) custom.style.display = tipo === 'custom' ? 'grid' : 'none';
};

/* ── Tarefas Processuais ───────────────────────────────────────────────────── */
async function loadProcTarefas() {
  const body = document.getElementById('dProcTarefasBody');
  if (!body || !openTaskId) return;
  body.innerHTML = '<div style="color:#4a5568;font-size:.8rem;padding:10px 0;">Carregando...</div>';
  try {
    const r = await GET(`/task_processo_tarefas.php?task_id=${openTaskId}`);
    renderProcTarefas(r.data || []);
  } catch(e) {
    body.innerHTML = `<div style="color:#f87171;font-size:.8rem;padding:8px;">Erro ao carregar: ${esc(e.message)}</div>`;
  }
}

function renderProcTarefas(groups) {
  const body = document.getElementById('dProcTarefasBody');
  if (!body) return;

  if (!groups.length) {
    body.innerHTML = `
      <div style="text-align:center;padding:32px 16px;color:#4a5568;">
        <div style="display:flex;justify-content:center;margin-bottom:10px;opacity:.35;">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#7a9abf" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <line x1="16" y1="13" x2="8" y2="13"/>
            <line x1="16" y1="17" x2="8" y2="17"/>
            <polyline points="10 9 9 9 8 9"/>
          </svg>
        </div>
        <div style="font-size:.85rem;margin-bottom:4px;color:#7a9abf;">Nenhum processo vinculado</div>
        <div style="font-size:.76rem;color:#4a5568;">Vá até a aba <strong style="color:#7a9abf">Vínculos</strong> e adicione um processo para ver as tarefas processuais aqui.</div>
      </div>`;
    return;
  }

  const iconEdit = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
  const iconVer  = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;

  // Monta opções de usuários para o select do add-row
  const userOpts = `<option value="">— Responsável —</option>` +
    users.map(u => `<option value="${esc(u.nome)}">${esc(u.nome)}</option>`).join('');

  body.innerHTML = groups.map(g => {
    const pct   = g.total > 0 ? Math.round((g.concluido / g.total) * 100) : 0;
    const url   = `/processos.php?open=${g.processo_id}`;
    const itens = g.tarefas.map(t => {
      // Serializa os dados da tarefa para o atributo data-task (usado pelo modal de edição)
      const taskJson = esc(JSON.stringify({
        id: t.id, titulo: t.titulo, data_tarefa: t.data_tarefa || '',
        responsavel: t.responsavel || '', prioridade: t.prioridade || 'media'
      }));
      const prio = t.prioridade === 'alta' ? 'Alta' : t.prioridade === 'baixa' ? 'Baixa' : 'Média';
      const prioColor = t.prioridade === 'alta' ? '#f87171' : t.prioridade === 'media' ? '#60a5fa' : '#94a3b8';
      return `
      <div class="pt-item${t.concluido ? ' pt-done' : ''}" data-id="${t.id}" data-pid="${g.processo_id}" data-task="${taskJson}">
        <button class="pt-check" onclick="toggleProcTarefa(${t.id})" title="${t.concluido ? 'Reabrir' : 'Concluir'}">
          ${t.concluido ? `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>` : ''}
        </button>
        <div class="pt-info">
          <div class="pt-titulo">${esc(t.titulo)}</div>
          <div class="pt-meta">
            ${t.responsavel ? `<span class="pt-resp"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>${esc(t.responsavel)}</span>` : ''}
            ${t.data_tarefa ? `<span class="pt-data"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>${fmtPtDate(t.data_tarefa)}</span>` : ''}
            <span style="font-size:.65rem;color:${prioColor};opacity:.8;">${prio}</span>
          </div>
        </div>
        <button class="pt-edit-btn" onclick="editProcTarefa(${t.id})" title="Editar">${iconEdit}</button>
        <button class="pt-del" onclick="deleteProcTarefa(${t.id})" title="Remover">✕</button>
      </div>`;
    }).join('');

    return `
      <div class="pt-group">
        <div class="pt-group-header" onclick="this.closest('.pt-group').classList.toggle('pt-collapsed')">
          <svg class="pt-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
          <div class="pt-group-info">
            <span class="pt-group-numero">${esc(g.numero)}</span>
            ${g.cliente_nome ? `<span class="pt-group-cliente">${esc(g.cliente_nome)}</span>` : ''}
          </div>
          <span class="pt-group-badge">${g.concluido}/${g.total}</span>
          <button class="pt-ver-btn" onclick="event.stopPropagation(); window.location.href='${url}'" title="Abrir processo">${iconVer} Ver</button>
        </div>
        <div class="pt-group-body">
          ${g.total > 0 ? `
          <div class="pt-progress-wrap">
            <span class="pt-pct">${pct}% concluído</span>
            <div class="pt-bar"><div class="pt-bar-fill" style="width:${pct}%"></div></div>
          </div>` : ''}
          <div class="pt-items">${itens}</div>
          <div class="pt-add-row">
            <input class="pt-new-input" type="text" placeholder="Nova tarefa..." data-pid="${g.processo_id}"
              onkeydown="if(event.key==='Enter') addProcTarefa(${g.processo_id})">
            <input class="pt-new-date" type="date" style="color-scheme:dark;min-width:120px;" data-pid="${g.processo_id}">
            <select class="pt-new-resp" data-pid="${g.processo_id}">${userOpts}</select>
            <button class="pt-add-btn" onclick="addProcTarefa(${g.processo_id})">+ Adicionar</button>
          </div>
        </div>
      </div>`;
  }).join('');
}

function fmtPtDate(str) {
  if (!str) return '';
  const [, m, d] = str.split('-');
  return `${d}/${m}`;
}

window.toggleProcTarefa = async (id) => {
  const el   = document.querySelector(`.pt-item[data-id="${id}"]`);
  const done = el?.classList.contains('pt-done');
  try {
    await PATCH('/processo_tarefas.php', { id, concluido: done ? 0 : 1 });
    await loadProcTarefas();
  } catch(e) { console.error('[pt] toggle', e); }
};

window.deleteProcTarefa = async (id) => {
  if (!await tkConfirm('Remover esta tarefa do processo?', { confirmLabel: 'Remover', confirmColor: '#dc2626' })) return;
  try {
    await DEL('/processo_tarefas.php', { id });
    await loadProcTarefas();
  } catch(e) { console.error('[pt] delete', e); }
};

window.addProcTarefa = async (pid) => {
  const inp  = document.querySelector(`.pt-new-input[data-pid="${pid}"]`);
  const date = document.querySelector(`.pt-new-date[data-pid="${pid}"]`);
  const resp = document.querySelector(`.pt-new-resp[data-pid="${pid}"]`);
  const titulo = inp?.value.trim();
  if (!titulo) { inp?.focus(); return; }
  try {
    await POST('/processo_tarefas.php', {
      processo_id: pid,
      titulo,
      data_tarefa:  date?.value  || null,
      responsavel:  resp?.value  || null,
    });
    if (inp)  inp.value  = '';
    if (date) date.value = '';
    if (resp) resp.value = '';
    await loadProcTarefas();
  } catch(e) { console.error('[pt] add', e); }
};

// ── Modal de edição de tarefa processual ────────────────────────────────────
let _ptEditId = null;

function ptEditModalInit() {
  const overlay  = document.getElementById('ptEditOverlay');
  if (!overlay || overlay._ptBound) return;
  overlay._ptBound = true;

  const close = () => { overlay.style.display = 'none'; _ptEditId = null; };
  document.getElementById('ptEditClose').addEventListener('click', close);
  document.getElementById('ptEditCancel').addEventListener('click', close);
  overlay.addEventListener('click', e => { if (e.target === overlay) close(); });

  document.getElementById('ptEditSave').addEventListener('click', async () => {
    if (!_ptEditId) return;
    const titulo     = document.getElementById('ptEditTitulo').value.trim();
    const data_tarefa= document.getElementById('ptEditData').value || null;
    const prioridade = document.getElementById('ptEditPrioridade').value;
    const responsavel= document.getElementById('ptEditResponsavel').value || null;
    if (!titulo) { document.getElementById('ptEditTitulo').focus(); return; }
    try {
      await PATCH('/processo_tarefas.php', { id: _ptEditId, titulo, data_tarefa, prioridade, responsavel });
      close();
      await loadProcTarefas();
    } catch(e) { console.error('[pt] edit save', e); }
  });
}

window.editProcTarefa = (id) => {
  const item = document.querySelector(`.pt-item[data-id="${id}"]`);
  if (!item) return;

  let taskData = {};
  try { taskData = JSON.parse(item.dataset.task || '{}'); } catch(e) {}

  ptEditModalInit();

  // Preenche os campos com os dados atuais
  document.getElementById('ptEditTitulo').value    = taskData.titulo    || '';
  document.getElementById('ptEditData').value      = taskData.data_tarefa || '';
  document.getElementById('ptEditPrioridade').value= taskData.prioridade || 'media';

  // Monta o select de responsável com os usuários carregados
  const selResp = document.getElementById('ptEditResponsavel');
  selResp.innerHTML = `<option value="">— Nenhum —</option>` +
    users.map(u => `<option value="${esc(u.nome)}"${u.nome === taskData.responsavel ? ' selected' : ''}>${esc(u.nome)}</option>`).join('');
  // Garante seleção mesmo se nome não estiver na lista (velho dado)
  if (taskData.responsavel && !users.find(u => u.nome === taskData.responsavel)) {
    selResp.innerHTML += `<option value="${esc(taskData.responsavel)}" selected>${esc(taskData.responsavel)}</option>`;
  }

  _ptEditId = id;
  const overlay = document.getElementById('ptEditOverlay');
  overlay.style.display = 'flex';
  setTimeout(() => document.getElementById('ptEditTitulo').focus(), 60);
};

/* ── Vínculos ──────────────────────────────────────────────────────────────── */
let currentLinkType = 'processo';
let currentLinks    = [];

function renderLinks(links) {
  currentLinks = links;
  const el = document.getElementById('dLinksList');
  if (!links.length) {
    el.innerHTML = '<div class="tk-no-links">Nenhum vínculo adicionado</div>';
    return;
  }

  const typeConfig = {
    processo:    {
      label: 'Processos', color: '#3b82f6', rgb: '59,130,246',
      url: l => `/processos.php?open=${l.link_id}`,
      icon: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>`,
    },
    contato:     {
      label: 'Clientes', color: '#10b981', rgb: '16,185,129',
      url: l => `/prospeccao.php?contato=${l.link_id}`,
      icon: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
    },
    card:        {
      label: 'Cards CRM', color: '#8b5cf6', rgb: '139,92,246',
      url: l => `/prospeccao.php?open=${l.link_id}`,
      icon: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>`,
    },
    dre_account: {
      label: 'Contas DRE', color: '#f59e0b', rgb: '245,158,11',
      url: l => `/financas.php`,
      icon: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>`,
    },
  };

  const groups = {};
  links.forEach(l => { if (!groups[l.link_type]) groups[l.link_type] = []; groups[l.link_type].push(l); });

  const openSvg = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>`;

  el.innerHTML = Object.entries(groups).map(([type, items]) => {
    const cfg = typeConfig[type] || { label: type, color: '#7a8898', rgb: '122,168,152', url: () => '#', icon: '' };
    const rows = items.map(l => `
      <div class="tk-link-row">
        <div class="tk-link-row-icon" style="--grp-color:${cfg.color}">${cfg.icon}</div>
        <div class="tk-link-row-name">
            <span style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(l.nome_display || '#'+l.link_id)}</span>
            ${l.sub_display ? `<span style="display:block;font-size:.71rem;color:#7a9abf;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(l.sub_display)}</span>` : ''}
          </div>
        <div class="tk-link-row-actions">
          <a href="${cfg.url(l)}" class="tk-link-open" title="Abrir">${openSvg} Abrir</a>
          <button class="tk-link-remove" onclick="removeLink(${l.id})" title="Remover">✕</button>
        </div>
      </div>`).join('');
    return `
      <div class="tk-link-group" style="--grp-color:${cfg.color}">
        <div class="tk-link-group-header">${cfg.icon} ${cfg.label}<span class="tk-link-count">${items.length}</span></div>
        <div class="tk-link-rows">${rows}</div>
      </div>`;
  }).join('');
}

window.removeLink = async id => {
  await DEL('/task_links.php', { id });
  await refreshDrawer();
};

async function searchVinculos(q) {
  const container = document.getElementById('dVinculoResults');
  if (!container) { console.error('dVinculoResults não encontrado'); return; }
  console.log('[vinculos] searchVinculos type=', currentLinkType, 'q=', q);
  container.innerHTML = '<div style="color:#4A5568;font-size:.78rem;padding:6px;">Buscando...</div>';
  try {
    const url = `/task_link_search.php?type=${currentLinkType}&q=${encodeURIComponent(q)}&limit=15`;
    console.log('[vinculos] fetch', url);
    const r = await GET(url);
    console.log('[vinculos] resposta', r);
    const items = r.data || [];
    const linkedIds = currentLinks.filter(l => l.link_type === currentLinkType).map(l => l.link_id);

    if (!items.length) {
      container.innerHTML = '<div style="color:#4A5568;font-size:.78rem;padding:6px;">Nenhum resultado</div>';
      return;
    }
    container.innerHTML = items.map(i => {
      const name  = i.label || '#'+i.id;
      const sub   = i.sub   || '';
      const isLinked = linkedIds.includes(i.id);
      return `
        <div class="tk-vinculos-result-item${isLinked?' linked':''}" data-id="${i.id}" data-name="${esc(name)}">
          <div style="flex:1;">
            <div class="item-label">${esc(name)}</div>
            ${sub ? `<div class="item-sub">${esc(sub)}</div>` : ''}
          </div>
          <span class="item-add">${isLinked ? '✓ vinculado' : '+ vincular'}</span>
        </div>`;
    }).join('');

    container.querySelectorAll('.tk-vinculos-result-item:not(.linked)').forEach(el => {
      el.addEventListener('click', async () => {
        await POST('/task_links.php', { task_id: openTaskId, link_type: currentLinkType, link_id: parseInt(el.dataset.id) });
        await refreshDrawer();
        searchVinculos(document.getElementById('dVinculoBusca').value);
      });
    });
  } catch(e) { container.innerHTML = `<div style="color:#f87171;font-size:.78rem;padding:6px;">Erro: ${esc(e.message)}</div>`; console.error('searchVinculos', e); }
}

document.addEventListener('DOMContentLoaded', () => {
  // tipo buttons
  document.querySelectorAll('.tk-link-type-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tk-link-type-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentLinkType = btn.dataset.type;
      searchVinculos(document.getElementById('dVinculoBusca').value);
    });
  });

  // busca com debounce
  let vinculoTimer;
  document.getElementById('dVinculoBusca').addEventListener('input', e => {
    clearTimeout(vinculoTimer);
    vinculoTimer = setTimeout(() => searchVinculos(e.target.value), 300);
  });
});

/* ── Comentários ────────────────────────────────────────────────────────────── */
function renderComments(comments) {
  document.getElementById('dCommentList').innerHTML = comments.map(c => `
    <div class="tk-comment">
      <div class="tk-avatar-sm">${esc(c.autor.charAt(0).toUpperCase())}</div>
      <div class="tk-comment-body">
        <div class="tk-comment-author">${esc(c.autor)}</div>
        <div class="tk-comment-text">${esc(c.mensagem)}</div>
        <div class="tk-comment-time">${formatDate(c.created_at)}</div>
      </div>
    </div>`).join('');
}
document.getElementById('dCommentSend').addEventListener('click', async () => {
  const input = document.getElementById('dCommentInput');
  const msg   = input.value.trim();
  if (!msg || !openTaskId) return;
  await POST('/task_comments.php', { task_id: openTaskId, mensagem: msg });
  input.value = '';
  await refreshDrawer();
});
document.getElementById('dCommentInput').addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); document.getElementById('dCommentSend').click(); }
});


/* ── Tempo ─────────────────────────────────────────────────────────────────── */
function renderTempo(entries) {
  if (!document.getElementById('dTimerTotal')) return;
  let total = 0;
  entries.forEach(e => { if (e.duracao_minutos) total += parseInt(e.duracao_minutos); });
  document.getElementById('dTimerTotal').textContent = `Total: ${total} min (${(total/60).toFixed(1)}h)`;
  const dl = document.getElementById('dTimeList');
  if (dl) dl.innerHTML = entries.map(e => `
    <div class="tk-time-entry">
      <span style="flex:1;">${e.descricao||esc(e.autor)}</span>
      <span>${e.duracao_minutos||'—'} min</span>
    </div>`).join('');

  const hasActive = entries.some(e => !e.fim);
  const ts = document.getElementById('dTimerStart'); if(ts) ts.style.display = hasActive ? 'none' : '';
  const tp = document.getElementById('dTimerStop');  if(tp) tp.style.display  = hasActive ? '' : 'none';
}
document.getElementById('dTimerStart')?.addEventListener('click', async () => {
  await POST('/task_time_entries.php', { task_id: openTaskId, action: 'start' });
  await refreshDrawer();
});
document.getElementById('dTimerStop')?.addEventListener('click', async () => {
  await POST('/task_time_entries.php', { task_id: openTaskId, action: 'stop' });
  await refreshDrawer();
});

/* ── Histórico ─────────────────────────────────────────────────────────────── */
function renderHistorico(items) {
  document.getElementById('dHistoryList').innerHTML = items.map(h => `
    <div class="tk-history-item">
      <div class="tk-history-dot"></div>
      <div>
        <strong>${esc(h.autor||'Sistema')}</strong> ${esc(h.acao)}
        <div style="font-size:.67rem;color:#3A4858;">${formatDate(h.created_at)}</div>
      </div>
    </div>`).join('');
}

/* ── Bind Drawer ────────────────────────────────────────────────────────────── */
function bindDrawer() {
  document.getElementById('dBtnClose').addEventListener('click', closeDrawer);
  document.getElementById('tkDrawerOverlay').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeDrawer();
  });
  document.getElementById('dBtnSave').addEventListener('click', saveDrawer);

  document.getElementById('dBtnConcluir').addEventListener('click', async () => {
    if (!openTaskId) return;
    const res = await POST('/tasks.php?action=complete', { id: openTaskId });
    closeDrawer();
    await loadTasks();
    // Feedback diferenciado conforme retorno da API
    const d = res?.data ?? {};
    if (d.renovada) {
      // Tarefa recorrente: permanece no lugar com novo prazo
      const proxima = d.proxima_data
        ? new Date(d.proxima_data).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
        : null;
      const msg = proxima
        ? `Ocorrência concluída ✓ — próxima recorrência: ${proxima}`
        : 'Ocorrência concluída ✓ — prazo atualizado para a próxima recorrência';
      showToast(msg, { type: 'info', duration: 5500 });
    } else if (d.encerrada) {
      showToast('Recorrência encerrada ✓ — todas as ocorrências foram concluídas', { type: 'success', duration: 5000 });
    } else {
      showToast('Tarefa concluída com sucesso ✓', { type: 'success' });
    }
  });
  document.getElementById('dBtnArquivar').addEventListener('click', async () => {
    if (!openTaskId) return;
  if (!await tkConfirm('Arquivar esta tarefa?', { confirmLabel: 'Arquivar', confirmColor: '#0369a1', icon: 'archive' })) return;
    await DEL('/tasks.php', { id: openTaskId });
    closeDrawer();
    await loadTasks();
  });
  document.querySelectorAll('.tk-tab').forEach(tab =>
    tab.addEventListener('click', () => {
      console.log('[tab] clicou', tab.dataset.tab);
      switchTab(tab.dataset.tab);
      if (tab.dataset.tab === 'vinculos')     searchVinculos(document.getElementById('dVinculoBusca').value);
      if (tab.dataset.tab === 'proc-tarefas') loadProcTarefas();
    })
  );
  document.getElementById('dTitle').addEventListener('input', function() {
    this.style.height = 'auto'; this.style.height = this.scrollHeight + 'px';
  });
  document.getElementById('dRecToggle').addEventListener('change', e => {
    document.getElementById('dRecFields').style.display = e.target.checked ? 'flex' : 'none';
  });
}

/* ── Topbar ─────────────────────────────────────────────────────────────────── */
function bindTopbar() {
  document.getElementById('tkBoardSelect').addEventListener('change', e => {
    selectBoard(parseInt(e.target.value));
  });
  document.querySelectorAll('.tk-view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tk-view-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentView = btn.dataset.view;
      renderView();
    });
  });
  ['fltResponsavel','fltPrioridade','fltPrazo'].forEach(id => {
    document.getElementById(id).addEventListener('change', e => {
      filters[id.replace('flt','').toLowerCase().replace('responsavel','responsavel_id')] = e.target.value;
      loadTasks();
    });
  });
  // fix filter key names
  document.getElementById('fltResponsavel').addEventListener('change', e => { filters.responsavel_id = e.target.value; loadTasks(); });
  document.getElementById('fltPrioridade').addEventListener('change',  e => { filters.prioridade     = e.target.value; loadTasks(); });
  document.getElementById('fltPrazo').addEventListener('change',       e => { filters.prazo          = e.target.value; loadTasks(); });
  // Filtro de Origem (matriz/filial) — só existe se o select foi renderizado pelo PHP.
  // Aplica client-side via renderView() sem refetch.
  document.getElementById('fltOrigin')?.addEventListener('change', e => { filters.origin = e.target.value; renderView(); });
  let searchTimer;
  document.getElementById('fltBusca').addEventListener('input', e => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { filters.busca = e.target.value; loadTasks(); }, 350);
  });
  document.getElementById('tkBtnNewTask').addEventListener('click', () => {
    document.getElementById('modalNewTask').classList.add('open');
  });
  document.getElementById('tkBtnNewBoard').addEventListener('click', () => {
    document.getElementById('modalNewBoard').classList.add('open');
  });
  document.getElementById('tkBtnEditBoard').addEventListener('click', () => {
    if (!currentBoard) return;
    document.getElementById('ebNome').value = currentBoard.nome || '';
    document.getElementById('ebTipo').value = currentBoard.tipo || 'pessoal';
    document.getElementById('ebCor').value  = currentBoard.cor  || '#6366f1';
    document.getElementById('modalEditBoard').classList.add('open');
  });
  document.getElementById('tkBtnDelBoard').addEventListener('click', async () => {
    if (!currentBoard) return;
    if (!await tkConfirm(`Excluir o quadro "${currentBoard.nome}"? Todas as colunas e tarefas serão removidas.`)) return;
    await DEL('/task_boards.php', { id: currentBoard.id });
    currentBoard = null;
    await loadBoards();
  });
  document.getElementById('tkBtnManageCols').addEventListener('click', () => {
    if (!currentBoard) return;
    openManageCols();
  });
}

/* ── Gerenciar Colunas ─────────────────────────────────────────────────────── */
function openManageCols() {
  renderColsList();
  document.getElementById('modalManageCols').classList.add('open');
}
function renderColsList() {
  const list = document.getElementById('colsList');
  list.innerHTML = '';
  if (!columns.length) {
    list.innerHTML = '<p style="color:#7a8898;font-size:.82rem;text-align:center;padding:12px">Nenhuma coluna</p>';
    return;
  }

  let dragSrc = null;

  columns.forEach(col => {
    const row = document.createElement('div');
    row.draggable = true;
    row.dataset.id = col.id;
    row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;background:rgba(5,18,39,.6);border:1px solid rgba(96,165,250,.1);cursor:grab;transition:opacity .15s,border-color .15s';
    row.innerHTML = `
      <svg style="flex-shrink:0;color:#4a5568;cursor:grab" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/></svg>
      <span class="col-dot" style="width:12px;height:12px;border-radius:50%;background:${col.cor||'#94a3b8'};flex-shrink:0;"></span>
      <input type="text" value="${esc(col.nome)}" data-id="${col.id}" class="col-name-input"
        style="flex:1;background:transparent;border:none;color:#d6eaff;font-size:.84rem;font-family:inherit;outline:none;padding:2px 4px;border-radius:4px;">
      <input type="color" value="${col.cor||'#94a3b8'}" data-id="${col.id}" class="col-cor-input"
        style="width:28px;height:26px;padding:1px;border-radius:4px;border:1px solid rgba(96,165,250,.2);background:transparent;cursor:pointer;">
      <button data-id="${col.id}" class="col-save-btn" title="Salvar"
        style="padding:3px 10px;border-radius:5px;border:1px solid rgba(96,165,250,.25);background:rgba(37,99,235,.18);color:#93c5fd;font-size:.75rem;cursor:pointer;white-space:nowrap">Salvar</button>
      <button data-id="${col.id}" class="col-del-btn" title="Excluir"
        style="padding:3px 8px;border-radius:5px;border:1px solid rgba(239,68,68,.2);background:rgba(220,38,38,.12);color:#fca5a5;font-size:.75rem;cursor:pointer;">✕</button>
    `;

    // atualiza bolinha de cor ao mudar o color picker
    row.querySelector('.col-cor-input').addEventListener('input', e => {
      row.querySelector('.col-dot').style.background = e.target.value;
    });

    // drag-and-drop
    row.addEventListener('dragstart', e => {
      dragSrc = row;
      row.style.opacity = '0.4';
      e.dataTransfer.effectAllowed = 'move';
    });
    row.addEventListener('dragend', () => { row.style.opacity = '1'; });
    row.addEventListener('dragover', e => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      if (dragSrc && dragSrc !== row) {
        row.style.borderColor = 'rgba(96,165,250,.5)';
      }
    });
    row.addEventListener('dragleave', () => { row.style.borderColor = 'rgba(96,165,250,.1)'; });
    row.addEventListener('drop', async e => {
      e.preventDefault();
      row.style.borderColor = 'rgba(96,165,250,.1)';
      if (!dragSrc || dragSrc === row) return;
      // reorder in DOM
      const rows = [...list.querySelectorAll('[data-id]')];
      const fromIdx = rows.indexOf(dragSrc);
      const toIdx   = rows.indexOf(row);
      if (fromIdx < toIdx) list.insertBefore(dragSrc, row.nextSibling);
      else list.insertBefore(dragSrc, row);
      // persist order
      const ids = [...list.querySelectorAll('[data-id]')].map(r => parseInt(r.dataset.id));
      await POST('/task_columns.php?action=reorder', { board_id: currentBoard.id, ids });
      await reloadBoard();
    });

    list.appendChild(row);
  });

  list.querySelectorAll('.col-save-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id   = parseInt(btn.dataset.id);
      const row  = btn.closest('[data-id]');
      const nome = row.querySelector('.col-name-input').value.trim();
      const cor  = row.querySelector('.col-cor-input').value;
      if (!nome) return;
      btn.textContent = '...';
      await PUT('/task_columns.php', { id, nome, cor });
      btn.textContent = '✓';
      setTimeout(() => { btn.textContent = 'Salvar'; }, 1200);
      const col = columns.find(c => c.id === id);
      if (col) { col.nome = nome; col.cor = cor; }
      await reloadBoard();
    });
  });

  list.querySelectorAll('.col-del-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id  = parseInt(btn.dataset.id);
      const col = columns.find(c => c.id === id);
      if (!await tkConfirm(`Excluir a coluna "${col?.nome}"? As tarefas nela serão removidas.`)) return;
      const r = await DEL('/task_columns.php', { id });
      if (!r.ok) { await tkAlert(r.error || 'Erro ao excluir'); return; }
      await reloadBoard();
      renderColsList();
    });
  });
}

async function reloadBoard() {
  if (!currentBoard) return;
  const r = await GET(`/task_boards.php?id=${currentBoard.id}`);
  currentBoard = r.data;
  columns      = currentBoard.colunas || [];
  renderColSelect('dColuna', columns);
  renderColSelect('ntColuna', columns);
  await loadTasks();
}

/* ── Modais ─────────────────────────────────────────────────────────────────── */
function bindModals() {
  // Gerenciar Colunas
  document.getElementById('colsClose').addEventListener('click', () => document.getElementById('modalManageCols').classList.remove('open'));
  document.getElementById('modalManageCols').addEventListener('click', e => { if(e.target===e.currentTarget) e.currentTarget.classList.remove('open'); });
  document.getElementById('colNewSave').addEventListener('click', async () => {
    if (!currentBoard) return;
    const nome = document.getElementById('colNewNome').value.trim();
    const cor  = document.getElementById('colNewCor').value;
    if (!nome) return;
    await POST('/task_columns.php', { board_id: currentBoard.id, nome, cor });
    document.getElementById('colNewNome').value = '';
    await reloadBoard();
    renderColsList();
  });
  document.getElementById('colNewNome').addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('colNewSave').click();
  });

  // Editar Quadro
  document.getElementById('ebCancel').addEventListener('click', () => document.getElementById('modalEditBoard').classList.remove('open'));
  document.getElementById('modalEditBoard').addEventListener('click', e => { if(e.target===e.currentTarget) e.currentTarget.classList.remove('open'); });
  document.getElementById('ebSave').addEventListener('click', async () => {
    const nome = document.getElementById('ebNome').value.trim();
    if (!nome || !currentBoard) return;
    await PUT('/task_boards.php', {
      id: currentBoard.id,
      nome,
      tipo: document.getElementById('ebTipo').value,
      cor:  document.getElementById('ebCor').value
    });
    document.getElementById('modalEditBoard').classList.remove('open');
    await loadBoards(currentBoard.id); // mantém o quadro atual após editar nome
  });

  // Novo Quadro
  document.getElementById('nbCancel').addEventListener('click', () => document.getElementById('modalNewBoard').classList.remove('open'));
  document.getElementById('modalNewBoard').addEventListener('click', e => { if(e.target===e.currentTarget) e.currentTarget.classList.remove('open'); });
  document.getElementById('nbSave').addEventListener('click', async () => {
    const nome = document.getElementById('nbNome').value.trim();
    if (!nome) return;
    const r = await POST('/task_boards.php', {
      nome, tipo: document.getElementById('nbTipo').value, cor: document.getElementById('nbCor').value
    });
    document.getElementById('modalNewBoard').classList.remove('open');
    document.getElementById('nbNome').value = '';
    if (r.data?.id) {
      await loadBoards(r.data.id); // já seleciona o novo quadro direto
    }
  });

  // Nova Tarefa
  document.getElementById('ntCancel').addEventListener('click', () => document.getElementById('modalNewTask').classList.remove('open'));
  document.getElementById('modalNewTask').addEventListener('click', e => { if(e.target===e.currentTarget) e.currentTarget.classList.remove('open'); });

  // toggle recorrência no modal nova tarefa
  document.getElementById('ntRecToggle').addEventListener('change', e => {
    const fields = document.getElementById('ntRecFields');
    fields.style.display = e.target.checked ? 'flex' : 'none';
    if (e.target.checked && !document.getElementById('ntRecInicio').value) {
      document.getElementById('ntRecInicio').value = new Date().toISOString().slice(0, 10);
    }
  });

  // salvar nova tarefa
  document.getElementById('ntSave').addEventListener('click', async () => {
    const titulo = document.getElementById('ntTitulo').value.trim();
    if (!titulo || !currentBoard) return;

    const btn = document.getElementById('ntSave');
    const orig = btn.textContent;
    btn.textContent = 'Criando…';
    btn.disabled = true;

    try {
      const recorrente = document.getElementById('ntRecToggle').checked;
      let recorrencia = null;
      if (recorrente) {
        const ntTipo = document.getElementById('ntRecTipo').value;
        recorrencia = {
          tipo:        ntTipo,
          intervalo:   ntTipo === 'custom' ? (parseInt(document.getElementById('ntRecIntervalo').value) || 1) : 1,
          unidade:     ntTipo === 'custom' ? document.getElementById('ntRecUnidade').value : null,
          data_inicio: document.getElementById('ntRecInicio').value || new Date().toISOString().slice(0, 10),
          data_fim:    document.getElementById('ntRecFim').value || null,
          ativa:       true,
        };
      }

      const payload = {
        board_id:       currentBoard.id,
        column_id:      document.getElementById('ntColuna').value,
        titulo,
        descricao:      document.getElementById('ntDescricao').value,
        prioridade:     document.getElementById('ntPrioridade').value,
        prazo_tipo:     document.getElementById('ntPrazoTipo').value,
        prazo:          document.getElementById('ntPrazo').value || null,
        responsavel_id: document.getElementById('ntResponsavel').value || null,
      };
      if (recorrencia) payload.recorrencia = recorrencia;

      await POST('/tasks.php', payload);

      // reset modal
      document.getElementById('modalNewTask').classList.remove('open');
      document.getElementById('ntTitulo').value    = '';
      document.getElementById('ntDescricao').value = '';
      document.getElementById('ntPrazo').value     = '';
      document.getElementById('ntRecToggle').checked = false;
      document.getElementById('ntRecFields').style.display = 'none';
      document.getElementById('ntRecTipo').value   = 'diaria';
      document.getElementById('ntRecInicio').value = '';
      document.getElementById('ntRecFim').value    = '';
      document.getElementById('ntRecIntervalo').value = 1;
      document.getElementById('ntRecCustom').style.display = 'none';

      await loadTasks();
    } catch(e) {
      console.error('[ntSave]', e);
      await tkAlert('Erro ao criar tarefa: ' + e.message);
    } finally {
      btn.textContent = orig;
      btn.disabled = false;
    }
  });

  document.getElementById('ntTitulo').addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('ntSave').click();
  });

} // fim bindModals

/* ── Tipos de Prazo (localStorage) ──────────────────────────────────────────── */
const PRAZO_TIPOS_KEY = 'yuris_prazo_tipos';

const PRAZO_TIPOS_DEFAULT = [
  { key: 'legal',           label: 'Legal',           cor: '#ef4444' },
  { key: 'interno',         label: 'Interno',         cor: '#3b82f6' },
  { key: 'administrativo',  label: 'Administrativo',  cor: '#f59e0b' },
];

function loadPrazoTipos() {
  try {
    const raw = localStorage.getItem(PRAZO_TIPOS_KEY);
    return raw ? JSON.parse(raw) : PRAZO_TIPOS_DEFAULT;
  } catch(e) { return PRAZO_TIPOS_DEFAULT; }
}

function savePrazoTipos(tipos) {
  try { localStorage.setItem(PRAZO_TIPOS_KEY, JSON.stringify(tipos)); } catch(e) {}
}

function refreshPrazoSelects(currentTipo) {
  const tipos = loadPrazoTipos();
  ['dPrazoTipo', 'ntPrazoTipo'].forEach(id => {
    const sel = document.getElementById(id);
    if (!sel) return;
    const prev = sel.value || currentTipo || 'interno';
    sel.innerHTML = tipos.map(t =>
      `<option value="${esc(t.key)}">${esc(t.label)}</option>`
    ).join('');
    // restaura valor anterior se existir
    if ([...sel.options].some(o => o.value === prev)) sel.value = prev;
  });
}

function openPrazoTipoConfig() {
  renderPtcList();
  document.getElementById('modalPrazoTipoConfig').classList.add('open');
}

function renderPtcList() {
  const tipos = loadPrazoTipos();
  const list  = document.getElementById('ptcList');
  list.innerHTML = tipos.map((t, i) => `
    <div class="ptc-row" data-i="${i}" style="display:flex;align-items:center;gap:8px;padding:7px 10px;background:rgba(5,18,39,.6);border:1px solid rgba(96,165,250,.1);border-radius:8px;">
      <input type="color" value="${t.cor || '#3b82f6'}" data-i="${i}" class="ptc-cor"
        style="width:28px;height:26px;padding:1px;border-radius:4px;border:1px solid rgba(96,165,250,.2);background:transparent;cursor:pointer;flex-shrink:0;">
      <input type="text" value="${esc(t.label)}" data-i="${i}" class="ptc-label" placeholder="Nome do tipo"
        style="flex:1;background:transparent;border:none;color:#d6eaff;font-size:.84rem;font-family:inherit;outline:none;padding:2px 4px;">
      <button data-i="${i}" class="ptc-del"
        style="padding:3px 8px;border-radius:5px;border:1px solid rgba(239,68,68,.2);background:rgba(220,38,38,.12);color:#fca5a5;font-size:.75rem;cursor:pointer;">✕</button>
    </div>`).join('');

  list.querySelectorAll('.ptc-del').forEach(btn => {
    btn.addEventListener('click', () => {
      const tipos2 = loadPrazoTipos();
      tipos2.splice(parseInt(btn.dataset.i), 1);
      savePrazoTipos(tipos2);
      renderPtcList();
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  refreshPrazoSelects('interno');

  document.getElementById('ptcCancel').addEventListener('click', () => {
    document.getElementById('modalPrazoTipoConfig').classList.remove('open');
  });
  document.getElementById('modalPrazoTipoConfig').addEventListener('click', e => {
    if (e.target === e.currentTarget) e.currentTarget.classList.remove('open');
  });

  document.getElementById('ptcAddBtn').addEventListener('click', () => {
    const tipos = loadPrazoTipos();
    tipos.push({ key: 'tipo_' + Date.now(), label: 'Novo tipo', cor: '#3b82f6' });
    savePrazoTipos(tipos);
    renderPtcList();
  });

  document.getElementById('ptcSave').addEventListener('click', () => {
    const rows  = document.querySelectorAll('.ptc-row');
    const tipos = [...rows].map(row => {
      const label = row.querySelector('.ptc-label').value.trim() || 'Sem nome';
      const cor   = row.querySelector('.ptc-cor').value;
      const key   = label.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/\s+/g,'_').replace(/[^a-z0-9_]/g,'');
      return { key: key || ('tipo_' + Date.now()), label, cor };
    });
    savePrazoTipos(tipos);
    refreshPrazoSelects();
    document.getElementById('modalPrazoTipoConfig').classList.remove('open');
  });
});

/* ── Faixa de status de recorrência no card ─────────────────────────────────── */
function recStatusCard(t) {
  if (!t.recorrencia_id || !t.concluida_em) return '';

  const agora     = Date.now();
  const concluida = new Date(t.concluida_em);
  const proxima   = t.prazo ? new Date(t.prazo) : null;

  // "Concluído X" — quando foi feito
  const diffConclDias = Math.max(0, Math.floor((agora - concluida) / 86400000));
  let quando;
  if (diffConclDias === 0)      quando = 'hoje';
  else if (diffConclDias === 1) quando = 'ontem';
  else if (diffConclDias < 7)   quando = `há ${diffConclDias} dias`;
  else if (diffConclDias < 14)  quando = 'há 1 semana';
  else if (diffConclDias < 30)  quando = `há ${Math.floor(diffConclDias/7)} semanas`;
  else                           quando = `há ${Math.floor(diffConclDias/30)} meses`;

  // "Próxima daqui X" — quando é a próxima
  let proximaStr = '';
  if (proxima) {
    const diffProxDias = Math.ceil((proxima - agora) / 86400000);
    if (diffProxDias <= 0)       proximaStr = 'próxima: hoje';
    else if (diffProxDias === 1) proximaStr = 'próxima: amanhã';
    else if (diffProxDias < 7)   proximaStr = `próxima: ${diffProxDias} dias`;
    else if (diffProxDias < 14)  proximaStr = 'próxima: 1 semana';
    else if (diffProxDias < 30)  proximaStr = `próxima: ${Math.floor(diffProxDias/7)} sem.`;
    else if (diffProxDias < 60)  proximaStr = 'próxima: 1 mês';
    else                          proximaStr = `próxima: ${Math.floor(diffProxDias/30)} meses`;
  }

  return `
    <div class="tk-rec-status">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      <span>Concluído ${quando}</span>
      ${proximaStr ? `<span class="tk-rec-sep">·</span><span>${proximaStr}</span>` : ''}
    </div>`;
}

/* ── Utilitários de exibição ────────────────────────────────────────────────── */
function formatDate(str) {
  if (!str) return '—';
  try {
    const d = new Date(str);
    if (isNaN(d)) return str;
    return d.toLocaleDateString('pt-BR', { day:'2-digit', month:'2-digit', year:'numeric' }) +
           (str.includes('T') || str.includes(' ') ?
             ' ' + d.toLocaleTimeString('pt-BR', { hour:'2-digit', minute:'2-digit' }) : '');
  } catch(e) { return str; }
}

function labelPrioridade(p) {
  const map = { baixa: 'Baixa', media: 'Média', alta: 'Alta', urgente: 'Urgente' };
  return map[p] || p || '—';
}

/* ── Calendário: bind navegação ─────────────────────────────────────────────── */
function bindCalendar() {
  const prev = document.getElementById('calPrev');
  const next = document.getElementById('calNext');
  if (prev) prev.addEventListener('click', () => { calDate.setMonth(calDate.getMonth() - 1); renderCalendario(); });
  if (next) next.addEventListener('click', () => { calDate.setMonth(calDate.getMonth() + 1); renderCalendario(); });
}
