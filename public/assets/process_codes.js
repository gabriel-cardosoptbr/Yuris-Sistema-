(function(){
  // Simple client-side manager for "tipos de ação" stored in localStorage.
  // Key used: 'process_action_types'
  const storageKey = 'process_action_types';

  const DEFAULT_TYPES = [
    'Ação de Cobrança','Ação de Indenização','Ação Trabalhista','Ação Civil Pública',
    'Ação de Despejo','Ação de Divórcio','Ação de Alimentos','Ação de Guarda',
    'Ação de Inventário','Ação de Usucapião','Ação Monitória','Execução Fiscal',
    'Execução de Título Extrajudicial','Mandado de Segurança','Habeas Corpus',
    'Recurso Ordinário','Recurso de Revista','Embargos de Declaração',
    'Ação Previdenciária','Ação de Revisão de Benefício','Ação Consumerista',
    'Ação de Rescisão Contratual','Ação de Reintegração de Posse','Cautelar',
    'Tutela Antecipada','Ação Declaratória','Ação Anulatória'
  ].map((name, i) => ({ id: 1000 + i, code: '', name }));

  function getList(){
    try{
      const raw = localStorage.getItem(storageKey);
      const saved = raw ? JSON.parse(raw) : [];
      if (saved.length > 0) return saved;
      // sem dados salvos → retorna defaults (não persiste, usuário pode customizar)
      return DEFAULT_TYPES;
    }catch(e){ return DEFAULT_TYPES; }
  }
  function saveList(list){ try{ localStorage.setItem(storageKey, JSON.stringify(list||[])); }catch(e){ console.error('save failed', e); } }

  function populateSelect(){
    const sel = document.getElementById('tipo_acao'); if (!sel) return;
    const cur = sel.value;
    const list = getList();
    sel.innerHTML = '<option value="">Tipo de ação</option>';
    list.forEach(item=>{
      const opt = document.createElement('option'); opt.value = item.name || item.code; opt.textContent = item.name || item.code; sel.appendChild(opt);
    });
    if (cur) sel.value = cur;
  }

  // Modal UI
  function ensureModal(){
    if (document.getElementById('actionTypesModal')) return;
    const INP = `width:100%;padding:9px 11px;border-radius:8px;border:1px solid rgba(96,165,250,.25);background:rgba(5,18,39,.85);color:#d6eaff;font-size:.84rem;font-family:inherit;box-sizing:border-box`;
    const modal = document.createElement('div'); modal.id = 'actionTypesModal'; modal.style.cssText='display:none;position:fixed;inset:0;background:rgba(2,6,23,.72);backdrop-filter:blur(3px);align-items:center;justify-content:center;z-index:3000';
    modal.innerHTML = `
      <div style="width:560px;max-width:96vw;background:linear-gradient(165deg,rgba(10,24,46,.99),rgba(7,18,36,.99));border:1px solid rgba(96,165,250,.22);border-radius:16px;box-shadow:0 24px 60px rgba(2,6,23,.7);overflow:hidden">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid rgba(96,165,250,.12);background:rgba(8,22,44,.5)">
          <span style="font-size:1rem;font-weight:700;color:#dbeafe">Tipos de Ação</span>
          <button id="closeActionTypesModal" style="display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:7px;border:1px solid rgba(96,165,250,.25);background:transparent;color:#93c5fd;font-size:.8rem;cursor:pointer">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Fechar
          </button>
        </div>
        <div style="padding:16px 20px;display:flex;gap:8px;background:rgba(5,14,30,.4);border-bottom:1px solid rgba(96,165,250,.08)">
          <input id="newActionCode" placeholder="Código (ex: 01)" style="${INP};flex:0 0 110px" />
          <input id="newActionName" placeholder="Nome do tipo de ação..." style="${INP};flex:1" />
          <button id="addActionBtn" style="flex-shrink:0;padding:0 16px;height:38px;border-radius:8px;border:none;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-size:.84rem;font-weight:600;cursor:pointer;white-space:nowrap">Adicionar</button>
          <button id="cancelEditActionBtn" style="display:none;flex-shrink:0;padding:0 12px;height:38px;border-radius:8px;border:1px solid rgba(96,165,250,.25);background:transparent;color:#93c5fd;font-size:.84rem;cursor:pointer">Cancelar</button>
        </div>
        <div style="max-height:320px;overflow-y:auto;padding:8px 12px 12px">
          <table id="actionTypesTable" style="width:100%;border-collapse:collapse">
            <thead><tr style="border-bottom:1px solid rgba(96,165,250,.1)">
              <th style="text-align:left;padding:8px;font-size:.72rem;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:.06em;width:80px">Código</th>
              <th style="text-align:left;padding:8px;font-size:.72rem;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:.06em">Nome</th>
              <th style="text-align:right;padding:8px;font-size:.72rem;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:.06em">Ações</th>
            </tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    document.getElementById('closeActionTypesModal').addEventListener('click', function(){ modal.style.display='none'; });

    document.getElementById('addActionBtn').addEventListener('click', function(){
      const code = (document.getElementById('newActionCode')||{}).value.trim();
      const name = (document.getElementById('newActionName')||{}).value.trim();
      if (!name) return alert('Preencha o nome do tipo de ação');
      const editId = this.dataset.editId || null;
      let list = getList();
      if (editId) {
        // update
        list = list.map(it => it.id == editId ? Object.assign({}, it, { code, name }) : it);
        delete this.dataset.editId; this.textContent = 'Adicionar'; document.getElementById('cancelEditActionBtn').style.display='none';
      } else {
        const id = Date.now(); list.push({ id: id, code: code, name: name });
      }
      saveList(list); document.getElementById('newActionCode').value=''; document.getElementById('newActionName').value=''; renderTable(); populateSelect();
    });

    document.getElementById('cancelEditActionBtn').addEventListener('click', function(){
      const addBtn = document.getElementById('addActionBtn'); document.getElementById('newActionCode').value=''; document.getElementById('newActionName').value=''; delete addBtn.dataset.editId; addBtn.textContent='Adicionar'; this.style.display='none';
    });
  }

  const BTN_EDIT = `display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;border:1px solid rgba(96,165,250,.3);background:rgba(37,99,235,.18);color:#93c5fd;font-size:.75rem;font-weight:600;cursor:pointer;transition:background .15s`;
  const BTN_DEL  = `display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;border:1px solid rgba(239,68,68,.3);background:rgba(220,38,38,.18);color:#fca5a5;font-size:.75rem;font-weight:600;cursor:pointer;transition:background .15s`;
  const ICON_EDIT = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
  const ICON_DEL  = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>`;

  function renderTable(){
    const tbody = document.querySelector('#actionTypesTable tbody'); if (!tbody) return; tbody.innerHTML='';
    const list = getList();
    list.forEach((item, idx)=>{
      const tr = document.createElement('tr');
      tr.style.cssText = `border-bottom:1px solid rgba(96,165,250,.07);transition:background .15s`;
      tr.onmouseenter = ()=>{ tr.style.background='rgba(37,99,235,.07)'; };
      tr.onmouseleave = ()=>{ tr.style.background=''; };
      tr.innerHTML = `
        <td style="padding:10px 8px;font-size:.78rem;color:#7eb8f6;font-weight:600;width:70px">${item.code||'—'}</td>
        <td style="padding:10px 8px;font-size:.84rem;color:#d6eaff">${item.name||''}</td>
        <td style="padding:10px 8px;text-align:right;white-space:nowrap;display:flex;gap:6px;justify-content:flex-end">
          <button data-id="${item.id}" class="editActionBtn" style="${BTN_EDIT}">${ICON_EDIT} Editar</button>
          <button data-id="${item.id}" class="delActionBtn" style="${BTN_DEL}">${ICON_DEL} Excluir</button>
        </td>`;
      tbody.appendChild(tr);
    });
    if (!list.length) {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td colspan="3" style="padding:20px;text-align:center;color:rgba(148,163,184,.5);font-size:.82rem">Nenhum tipo cadastrado</td>`;
      tbody.appendChild(tr);
    }
    attachRowButtons();
  }

  function attachRowButtons(){
    document.querySelectorAll('.delActionBtn').forEach(b=>{ b.onclick = function(){ if (!confirm('Excluir tipo de ação?')) return; const id = this.getAttribute('data-id'); let list = getList(); list = list.filter(it=> it.id != id); saveList(list); renderTable(); populateSelect(); } });
    document.querySelectorAll('.editActionBtn').forEach(b=>{ b.onclick = function(){ const id = this.getAttribute('data-id'); const list = getList(); const it = list.find(x=> x.id == id); if (!it) return; document.getElementById('newActionCode').value = it.code||''; document.getElementById('newActionName').value = it.name||''; const addBtn = document.getElementById('addActionBtn'); addBtn.dataset.editId = id; addBtn.textContent = 'Atualizar'; document.getElementById('cancelEditActionBtn').style.display='inline-block'; } });
  }

  // wire manage button with robust initialization
  function init(){
    ensureModal(); populateSelect();
    const manageBtn = document.getElementById('manageActionTypesBtn');
    if (manageBtn) manageBtn.addEventListener('click', function(){ const modal = document.getElementById('actionTypesModal'); if (modal) { renderTable(); modal.style.display='flex'; } });
    // refresh the select if modal changes localStorage elsewhere
    window.addEventListener('storage', function(e){ if (e.key === storageKey) populateSelect(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

})();
