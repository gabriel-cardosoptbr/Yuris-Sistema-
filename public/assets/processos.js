document.addEventListener('DOMContentLoaded', ()=>{
  const api             = '/api/processes.php';
  const btnNew          = document.getElementById('btnNewProcess');
  const yearFilter      = document.getElementById('yearFilter');
  const modal           = document.getElementById('modalProcess');
  const form            = document.getElementById('processForm');
  const cancelBtn       = document.getElementById('cancelProcess');
  const upcoming        = document.getElementById('upcomingList');
  const columnsWrapper  = document.getElementById('columnsWrapper');

  // ── Toast ──────────────────────────────────────────────────────────────────
  // Cria container de toast dinamicamente se não existir, para não depender de elemento no HTML
  function showToast(message, type='info', timeout=4000){
    let c = document.getElementById('toastContainer');
    if (!c){ c = document.createElement('div'); c.id='toastContainer'; c.className='toast-container'; document.body.appendChild(c); }
    const t = document.createElement('div');
    t.className = 'toast ' + (type||'info');
    t.textContent = message;
    c.appendChild(t);
    void t.offsetWidth;
    t.classList.add('show');
    setTimeout(()=>{ t.classList.remove('show'); setTimeout(()=>t.remove(),300); }, timeout);
  }

  // ── Origin strip helpers ───────────────────────────────────────────────────
  // Gera HTML da faixa "MATRIZ" / "FILIAL — Nome" no topo do card de processo.
  // Só renderiza quando há múltiplas contas visíveis (matriz com filiais).
  // Espera p.origin_account_tipo e p.origin_account_nome vindo do backend.
  function _escapeAttr(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/"/g,'&quot;')
      .replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  // Labels canônicas de tipo de conta — usar em TODO render de badge de origem
  // (faixa do card + badge do histórico). Antes existiam 2 ternários hardcoded
  // que tratavam qualquer coisa != 'matriz' como FILIAL — fazendo conta tipo
  // 'advogado' aparecer como FILIAL no histórico, errado.
  const ACCOUNT_TIPO_LABEL = { matriz: 'MATRIZ', filial: 'FILIAL', advogado: 'ADVOGADO SOLO' };
  const ACCOUNT_TIPO_CLASS = { matriz: 'is-matriz', filial: 'is-filial', advogado: 'is-advogado' };
  function _tipoLabel(t) {
    const k = String(t || '').toLowerCase();
    return ACCOUNT_TIPO_LABEL[k] || k.toUpperCase();
  }
  function _tipoClass(t) {
    const k = String(t || '').toLowerCase();
    return ACCOUNT_TIPO_CLASS[k] || 'is-filial';
  }
  function buildOriginStripHtml(p){
    if (!window.YURIS_SHOW_ORIGIN_STRIP) return '';
    const tipo = String(p && p.origin_account_tipo || '').toLowerCase();
    if (!tipo) return '';
    const nome  = _escapeAttr(p.origin_account_nome || '');
    return '<div class="proc-card-origin-strip ' + _tipoClass(tipo) + '">' +
             '<span class="org-label">' + _tipoLabel(tipo) + '</span>' +
             '<span class="org-name" title="' + nome + '">' + nome + '</span>' +
           '</div>';
  }
  // Aplica os atributos data-origin-* no elemento card (usado pra abrir padding-top via CSS)
  function applyOriginAttrs(el, p){
    if (!window.YURIS_SHOW_ORIGIN_STRIP) return;
    const tipo = String(p && p.origin_account_tipo || '').toLowerCase();
    if (!tipo) return;
    el.setAttribute('data-origin-tipo', tipo);
    el.setAttribute('data-origin-id',   String(p.origin_account_id || ''));
  }

  // ── Date helpers ───────────────────────────────────────────────────────────
  // Aceita Date, ISO string YYYY-MM-DD ou formato livre; retorna null para valores inválidos
  function parseProcessDate(value){
    if (!value) return null;
    if (value instanceof Date) return isNaN(value.getTime()) ? null : value;
    const raw = String(value).trim();
    if (!raw) return null;
    const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m){ const d = new Date(+m[1],+m[2]-1,+m[3]); return isNaN(d.getTime()) ? null : d; }
    const p = new Date(raw);
    return isNaN(p.getTime()) ? null : p;
  }

  function formatProcessDate(value){
    const dt = parseProcessDate(value);
    return dt ? dt.toLocaleDateString('pt-BR') : '-';
  }

  // Retorna o índice do mês (0-11) do próximo prazo; null quando sem prazo (coluna "Sem prazo")
  function getProcessMonthIndex(p){
    const dt = parseProcessDate(p && p.proximo_prazo);
    return dt ? dt.getMonth() : null;
  }

  // Retorna o ano do próximo prazo; null quando sem prazo (usado para validar o filtro de ano)
  function getProcessYear(p){
    const dt = parseProcessDate(p && p.proximo_prazo);
    return dt ? dt.getFullYear() : null;
  }

  // ── Urgency logic ──────────────────────────────────────────────────────────
  // Retorna classes CSS e texto de badge de urgência baseados nos dias até o prazo
  function getUrgencyInfo(dt, status){
    if (status==='encerrado'||status==='arquivado')
      return { text:'Encerrado', cardClass:'', badgeClass:'badge-neutral', rowClass:'' };
    if (!dt) return { text:null, cardClass:'', badgeClass:'badge-neutral', rowClass:'' };
    const now   = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const d     = new Date(dt.getFullYear(), dt.getMonth(), dt.getDate());
    const diff  = Math.floor((d - today) / 864e5);
    if (diff <  0)  return { text:'Vencido',      cardClass:'is-urgent', badgeClass:'badge-danger', rowClass:'row-urgent' };
    if (diff === 0) return { text:'Hoje',          cardClass:'is-urgent', badgeClass:'badge-danger', rowClass:'row-urgent' };
    if (diff <= 3)  return { text:'Urgente',       cardClass:'is-urgent', badgeClass:'badge-danger', rowClass:'row-urgent' };
    if (diff <= 7)  return { text:'Esta semana',   cardClass:'is-warn',   badgeClass:'badge-warn',   rowClass:'row-warn'   };
    if (diff <= 30) return { text:'Este mês',      cardClass:'',          badgeClass:'badge-info',   rowClass:''           };
    return                 { text:'Em dia',        cardClass:'is-ok',     badgeClass:'badge-ok',     rowClass:''           };
  }

  // ── KPI & Summary ──────────────────────────────────────────────────────────
  // Calcula e exibe os contadores do topo a partir do array de processos carregado
  function updateKPIs(data){
    const now        = new Date();
    const today      = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const in3        = new Date(today); in3.setDate(today.getDate()+3);
    const in7        = new Date(today); in7.setDate(today.getDate()+7);
    let ativos=0, hoje=0, semana=0, urgentes=0, vencidos=0, arquivados=0;
    data.forEach(p=>{
      if (p.status==='arquivado'||p.status==='encerrado'){ arquivados++; return; }
      ativos++;
      const dt = parseProcessDate(p.proximo_prazo);
      if (!dt) return;
      const d = new Date(dt.getFullYear(), dt.getMonth(), dt.getDate());
      const diff = Math.floor((d-today)/864e5);
      if (diff < 0)   { vencidos++;  return; }
      if (diff === 0) { hoje++; urgentes++; semana++; return; }
      if (diff <= 3)  { urgentes++; semana++; return; }
      if (diff <= 7)  { semana++; }
    });
    const set = (id,v)=>{ const el=document.getElementById(id); if(el) el.textContent=v; };
    set('kpiAtivos',    ativos);
    set('kpiHoje',      hoje);
    set('kpiSemana',    semana);
    set('kpiUrgentes',  urgentes);
    set('kpiVencidos',  vencidos);
    set('kpiArquivados',arquivados);
  }

  // Monta texto narrativo de resumo jurídico — "hoje" conta em "urgentes" e "semana" para consistência
  function updateResumo(data){
    const el = document.getElementById('resumoJuridico');
    if (!el) return;
    const now   = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const in7   = new Date(today); in7.setDate(today.getDate()+7);
    let ativos=0, semana=0, urgentes=0, hoje=0, vencidos=0;
    data.forEach(p=>{
      if (p.status==='arquivado'||p.status==='encerrado') return;
      ativos++;
      const dt = parseProcessDate(p.proximo_prazo);
      if (!dt) return;
      const d    = new Date(dt.getFullYear(), dt.getMonth(), dt.getDate());
      const diff = Math.floor((d-today)/864e5);
      if (diff < 0)   { vencidos++; return; }
      if (diff === 0) { hoje++; urgentes++; semana++; return; }
      if (diff <= 3)  { urgentes++; semana++; return; }
      if (diff <= 7)  { semana++; }
    });
    const parts = [`O escritório possui <strong>${ativos}</strong> processo${ativos!==1?'s':''} ativo${ativos!==1?'s':''}`];
    if (semana   > 0) parts.push(`<strong>${semana}</strong> prazo${semana!==1?'s':''} vencendo esta semana`);
    if (urgentes > 0) parts.push(`<strong>${urgentes}</strong> urgente${urgentes!==1?'s':''} (até 3 dias)`);
    if (hoje     > 0) parts.push(`<strong class="text-red-300">${hoje}</strong> prazo${hoje!==1?'s':''} fatal${hoje!==1?'is':''} hoje`);
    if (vencidos > 0) parts.push(`<strong class="text-red-400">${vencidos}</strong> prazo${vencidos!==1?'s':''} já vencido${vencidos!==1?'s':''}`);
    el.innerHTML = parts.join(', ') + '.';
  }

  // ── Barra de progresso de tarefas ─────────────────────────────────────────
  function buildTaskBar(p) {
    const total  = parseInt(p.tarefas_total  || 0);
    const feitas = parseInt(p.tarefas_feitas || 0);
    if (!total) return '';
    const pct      = Math.round((feitas / total) * 100);
    const restam   = total - feitas;
    const cor      = pct === 100 ? '#93c5fd' : pct >= 50 ? '#60a5fa' : pct > 0 ? '#3b82f6' : '#1d4ed8';
    const tipLabel = pct === 100
      ? `✓ Todas as ${total} tarefas concluídas`
      : `${feitas} de ${total} concluída${total !== 1 ? 's' : ''} · ${restam} restante${restam !== 1 ? 's' : ''}`;
    return `<div class="proc-task-bar-wrap" data-tip="${tipLabel}">` +
             `<div class="proc-task-bar-track">` +
               `<div class="proc-task-bar-fill" style="width:${pct}%;background:${cor}"></div>` +
             `</div>` +
           `</div>`;
  }

  // ── Card element (board) ───────────────────────────────────────────────────
  // Cria o elemento DOM do card no kanban sem inserir no DOM — o chamador é responsável pela inserção
  function buildCardElement(p){
    const prazo = formatProcessDate(p.proximo_prazo);
    const dt    = parseProcessDate(p.proximo_prazo);
    const urg   = getUrgencyInfo(dt, p.status);
    const badge = urg.text ? `<span class="proc-badge ${urg.badgeClass}">${urg.text}</span>` : '';
    const wrap  = document.createElement('div');
    wrap.className = 'proc-card ' + urg.cardClass;
    wrap.setAttribute('data-id', p.id);
    applyOriginAttrs(wrap, p);
    wrap.innerHTML =
      buildOriginStripHtml(p) +
      `<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;margin-bottom:2px">` +
        `<div class="proc-card-num">${p.numero||'-'}</div>${badge}` +
      `</div>` +
      `<div class="proc-card-client">${p.cliente_nome||'—'}</div>` +
      (p.setor_nome ? `<div class="proc-card-meta">${p.setor_nome}</div>` : '') +
      (p.vara_comarca ? `<div class="proc-card-meta">${p.vara_comarca}</div>` : '') +
      `<div class="proc-card-footer">` +
        `<div class="proc-card-date">` +
          `<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:2px"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>` +
          prazo +
        `</div>` +
        `<div class="proc-card-actions">` +
          `<button data-id="${p.id}" class="editBtn proc-btn-sm btn-edit">Editar</button>` +
          `<button data-id="${p.id}" class="delBtn proc-btn-sm btn-del">Excluir</button>` +
        `</div>` +
      `</div>` +
      buildTaskBar(p);
    return wrap;
  }

  // Registra handlers de editar/excluir nos botões de um card já no DOM
  function attachCardHandlers(card){
    const edit = card.querySelector('.editBtn');
    const del  = card.querySelector('.delBtn');
    if (edit) edit.addEventListener('click', async e=>{
      const id = e.target.dataset.id;
      const r  = await fetch(api+'?id='+id);
      const j  = await r.json();
      showModal(j);
    });
    if (del) del.addEventListener('click', async e=>{
      if (!(await Yuris.confirm('Excluir processo?', { danger: true, okLabel: 'Excluir' }))) return;
      const id = e.target.dataset.id;
      const r  = await fetch(api+'?id='+id, {method:'DELETE'});
      const j  = await r.json();
      if (j.success){ loadColumnsForYear(yearFilter.value); loadUpcoming(yearFilter.value); showToast('Excluído','success'); }
      else showToast('Erro ao excluir','error');
    });
  }

  // ── Insert process into columns (optimistic) ────────────────────────────────
  // Injeta a keyframe de flash CSS no head uma única vez para não duplicar a tag <style>
  function ensureFlashStyle(){
    if (document.getElementById('processFlashStyle')) return;
    const s = document.createElement('style'); s.id='processFlashStyle';
    s.textContent='@keyframes processFlash{0%{box-shadow:0 0 0 0 rgba(96,165,250,0);}50%{box-shadow:0 8px 20px 4px rgba(96,165,250,0.14);transform:translateY(-2px);}100%{box-shadow:0 0 0 0 rgba(96,165,250,0);transform:translateY(0);}} .process-flash{animation:processFlash 0.5s ease-in-out 0s 2;}';
    document.head.appendChild(s);
  }

  // Insere o processo na coluna correta do kanban sem recarregar tudo (atualização otimista pós-criação)
  function insertProcessIntoColumns(p){
    try{
      ensureFlashStyle();
      const monthIndex  = getProcessMonthIndex(p);
      const monthKey    = monthIndex===null ? 'nodate' : String(monthIndex);
      const containerId = 'month-'+monthKey;
      let container     = document.getElementById(containerId);

      if (!container){
        const col = document.createElement('div');
        col.className='proc-col'; col.setAttribute('data-month', monthKey);
        const label = monthIndex===null ? 'Sem prazo' : (monthNames[monthIndex]||'Sem prazo');
        col.innerHTML =
          `<div class="proc-col-header"><span class="proc-col-month">${label}</span><span class="proc-col-count">0</span></div>` +
          `<div class="proc-cards-list" id="${containerId}"></div>`;
        const board = columnsWrapper.querySelector('.proc-board');
        if (board) board.appendChild(col); else columnsWrapper.appendChild(col);
        container = col.querySelector('.proc-cards-list');
      }
      if (!container) return;

      const prev = container.querySelector('.proc-card[data-id="'+p.id+'"]');
      if (prev) prev.remove();
      const empty = container.querySelector('.proc-empty');
      if (empty) empty.remove();

      const el = buildCardElement(p);
      container.insertBefore(el, container.firstChild);
      attachCardHandlers(el);

      const col = container.closest('.proc-col');
      if (col){
        const cnt = container.querySelectorAll('.proc-card').length;
        const c   = col.querySelector('.proc-col-count');
        if (c) c.textContent = cnt;
      }
      el.classList.add('process-flash');
      try{ el.scrollIntoView({behavior:'smooth',block:'center'}); }catch(e){}
    }catch(e){ console.warn('insertProcessIntoColumns error',e); }
  }

  // ── Force-insert after create ──────────────────────────────────────────────
  // Insere o processo recém-criado simultaneamente na agenda e no kanban antes da resposta de re-fetch
  function forceInsert(created){
    try{
      // Insert into agenda list
      try{
        const prazo = formatProcessDate(created.proximo_prazo);
        const dt    = parseProcessDate(created.proximo_prazo);
        const urg   = getUrgencyInfo(dt, created.status);
        const badge = urg.text ? `<span class="proc-badge ${urg.badgeClass}">${urg.text}</span>` : '';
        const rowHtml =
          `<div class="proc-agenda-row ${urg.rowClass}" style="opacity:0;transition:opacity .3s">` +
            `<div class="proc-agenda-left">` +
              `<div class="proc-agenda-num">${created.numero||'-'}</div>` +
              `<div class="proc-agenda-client">${created.cliente_nome||'—'}</div>` +
              (created.setor_nome ? `<div class="proc-agenda-tipo">${created.setor_nome}</div>` : '') +
            `</div>` +
            `<div style="display:flex;align-items:center;gap:8px">${badge}</div>` +
            `<div class="proc-agenda-right">` +
              `<div class="proc-agenda-date">${prazo}</div>` +
              `<div class="proc-agenda-actions">` +
                `<button data-id="${created.id}" class="editBtn proc-btn-sm btn-edit">Editar</button>` +
                `<button data-id="${created.id}" class="delBtn proc-btn-sm btn-del">Excluir</button>` +
              `</div>` +
            `</div>` +
          `</div>`;
        upcoming.insertAdjacentHTML('afterbegin', rowHtml);
        const newRow = upcoming.firstElementChild;
        if (newRow){ requestAnimationFrame(()=>newRow.style.opacity='1'); }
      }catch(e){ console.warn('forceInsert agenda failed',e); }

      // Insert into board columns — usa proximo_prazo se houver, senão data_inicio
      if (!columnsWrapper){ console.warn('no columnsWrapper'); return; }
      const parsedDate = parseProcessDate(created.proximo_prazo) || parseProcessDate(created.data_inicio);
      const monthIndex = parsedDate ? parsedDate.getMonth() : 'nodate';
      let col = columnsWrapper.querySelector('.proc-col[data-month="'+monthIndex+'"]');
      if (!col){
        col = document.createElement('div'); col.className='proc-col'; col.setAttribute('data-month',monthIndex);
        const label = monthIndex==='nodate' ? 'Sem prazo' : (monthNames[monthIndex]||'');
        col.innerHTML =
          `<div class="proc-col-header"><span class="proc-col-month">${label}</span><span class="proc-col-count">1</span></div>` +
          `<div class="proc-cards-list" id="month-${monthIndex}"></div>`;
        const board = columnsWrapper.querySelector('.proc-board');
        if (board) board.appendChild(col); else columnsWrapper.appendChild(col);
      }
      const list = col.querySelector('.proc-cards-list');
      if (list){
        const card = buildCardElement(created);
        list.insertBefore(card, list.firstChild);
        attachCardHandlers(card);
        ensureFlashStyle(); card.classList.add('process-flash');
        try{ card.scrollIntoView({behavior:'smooth',block:'center'}); }catch(e){}
        const c = col.querySelector('.proc-col-count');
        if (c) c.textContent = list.querySelectorAll('.proc-card').length;
      }
    }catch(e){ console.warn('forceInsert error',e); }
  }

  // ── Modal ──────────────────────────────────────────────────────────────────
  let _originalProcessData = {}; // armazena valores originais para detectar mudanças reais no histórico

  // Aviso visual quando status final (encerrado/arquivado) é combinado com prazo futuro
  function _updateStatusPrazoHint(){
    const st   = document.getElementById('statusProcessoInput');
    const pz   = document.getElementById('proximoPrazoInput');
    const hint = document.getElementById('statusPrazoHint');
    if (!st || !pz || !hint){ return; }
    const isFinal = (st.value === 'encerrado' || st.value === 'arquivado');
    const today   = new Date(); today.setHours(0,0,0,0);
    const prazoFuturo = pz.value && parseProcessDate(pz.value) > today;
    hint.style.display = (isFinal && prazoFuturo) ? 'block' : 'none';
  }

  // Preenche o formulário do modal com os dados do processo ou reseta para criação
  function showModal(data=null){
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    // Popula selects de usuário imediatamente (dados já disponíveis via window._SYSTEM_USERS)
    _loadSetores().then(() => _fillSetorSelect(data?.setor_id || ''));
    _fillUserSelectById('selectResponsavelProcesso', data?.responsavel_user_id);
    _fillUserSelect('addPrazoResp');
    _fillUserSelect('novaTarefaResp');
    const titleEl = document.getElementById('modalTitle');
    if (titleEl) titleEl.textContent = data ? 'Editar Processo' : 'Cadastrar Processo';
    if (!data){
      form.reset(); form.id.value='';
      _originalProcessData = {};
      _procId = null;
      _loadCardsSelect(null);
      const h2 = document.getElementById('clienteNomeHint'); if(h2) h2.style.display='none';
      const pc=document.getElementById('prazosContainer'); if(pc) pc.innerHTML='<div style="color:#9ab0c9;font-size:.8rem">Salve o processo primeiro para adicionar prazos</div>';
      const tc=document.getElementById('tarefasContainer'); if(tc) tc.innerHTML='';
      const hp=document.getElementById('processoHistoryList'); if(hp) hp.innerHTML='';
      const tp=document.getElementById('tarefasProgress'); if(tp) tp.textContent='0% concluído (0/0)';
      const tb=document.getElementById('tarefasProgressBar'); if(tb) tb.style.width='0%';
      // Repopula selects pois form.reset() restaura o valor selecionado para o placeholder
      _fillUserSelectById('selectResponsavelProcesso', null);
      _fillUserSelect('novaTarefaResp');
      return;
    }
    _originalProcessData = {...data}; // guarda valores originais
    form.id.value             = data.id                   || '';
    form.numero.value         = data.numero               || '';
    form.cliente_nome.value   = data.cliente_nome         || '';
    form.parte_contraria.value= data.parte_contraria      || '';
    if (form.cpf_cnpj_parte_contraria) form.cpf_cnpj_parte_contraria.value = data.cpf_cnpj_parte_contraria || '';
    _fillSetorSelect(data.setor_id || '');
    form.vara_comarca.value   = data.vara_comarca         || '';
    form.status.value         = data.status               || 'ativo';
    form.data_inicio.value    = data.data_inicio ? data.data_inicio.split(' ')[0] : '';
    form.proximo_prazo.value  = data.proximo_prazo        || '';
    form.observacoes.value    = data.observacoes          || '';
    _updateStatusPrazoHint();  // recalcula aviso ao popular o form
    // Seta responsável no select após já estar populado
    const selResp = document.getElementById('selectResponsavelProcesso');
    if (selResp) selResp.value = data.responsavel_user_id || '';
    _procId = data.id || null;
    _loadCardsSelect(data.card_id || null);
    if (_procId) {
      _loadPrazos(_procId);
      _loadTarefas(_procId);
      _loadHistorico(_procId);
    } else {
      const pc=document.getElementById('prazosContainer'); if(pc) pc.innerHTML='<div style="color:#9ab0c9;font-size:.8rem">Salve o processo primeiro para adicionar prazos</div>';
      const tc=document.getElementById('tarefasContainer'); if(tc) tc.innerHTML='';
      const hp=document.getElementById('processoHistoryList'); if(hp) hp.innerHTML='';
      const tp=document.getElementById('tarefasProgress'); if(tp) tp.textContent='0% concluído (0/0)';
      const tb=document.getElementById('tarefasProgressBar'); if(tb) tb.style.width='0%';
    }
    // Notifica blocos externos (ex: Vínculos do Processo, definido em processos.php)
    // que o modal abriu. Antes existia um monkey-patch em window.showModal, mas
    // os listeners internos chamam o `showModal` LOCAL desta closure, bypassando
    // o patch — fazendo com que carregarVinculos nunca disparasse e o painel
    // aparecesse vazio mesmo com vínculos persistidos. Evento + listener resolve.
    try {
      document.dispatchEvent(new CustomEvent('processo:modal-opened', {
        detail: { id: data?.id || null }
      }));
    } catch (e) {}
  }
  function hideModal(){ modal.classList.add('hidden'); document.body.style.overflow = ''; }
  window.showModal = showModal; // expõe globalmente para auto-open via URL ?open=ID

  btnNew.addEventListener('click', ()=>showModal());
  cancelBtn.addEventListener('click', hideModal);
  document.getElementById('modalCloseX')?.addEventListener('click', hideModal);
  document.addEventListener('keydown', e=>{ if (e.key === 'Escape' && !modal.classList.contains('hidden')) hideModal(); });

  // Reavalia aviso de "status final + prazo futuro" ao alterar qualquer um dos dois campos
  document.getElementById('statusProcessoInput')?.addEventListener('change', _updateStatusPrazoHint);
  document.getElementById('proximoPrazoInput')?.addEventListener('change', _updateStatusPrazoHint);

  // ── Form submit ────────────────────────────────────────────────────────────
  form.addEventListener('submit', async e=>{
    e.preventDefault();
    const payload = {};
    new FormData(form).forEach((v,k)=>{ payload[k]=v; });
    payload.card_id = document.getElementById('proc_card_id')?.value || null;
    try{
      if (payload.id){
        payload.id = parseInt(payload.id);
        const res = await fetch(api,{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        if (!res.ok){ const txt=await res.text(); let msg=txt; try{msg=JSON.parse(txt).error||txt;}catch(e){} throw new Error(msg||'Erro no servidor'); }
        const j = await res.json();
        if (j.success){
          // Histórico de "Processo atualizado" agora é gravado server-side
          // por ProcessoAudit::logChanges() em /api/processes.php (PUT).
          // Mais confiável: não depende do client-side e detecta mudanças reais comparando prev/new.
          hideModal(); form.reset(); await loadColumnsForYear(yearFilter.value); await loadUpcoming(yearFilter.value); showToast('Atualizado','success');
        }
        else throw new Error(j.error||'Erro desconhecido');
      } else {
        const res = await fetch(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        if (!res.ok){ const txt=await res.text(); let msg=txt; try{msg=JSON.parse(txt).error||txt;}catch(e){} throw new Error(msg||'Erro no servidor'); }
        const j = await res.json();
        if (j.success){
          const created = Object.assign({},payload,j.data||{},{id:(j.data&&j.data.id)||j.id});
          // Histórico de "Processo criado" agora é gravado server-side em /api/processes.php (POST).
          // Mais confiável: garantido mesmo se o usuário fechar o modal rápido ou a rede cair aqui.
          hideModal(); form.reset();
          showToast('Processo cadastrado','success');
          const selYear    = parseInt(yearFilter.value,10);
          const crYear     = getProcessYear(created);
          if (crYear===null||crYear===selYear) insertProcessIntoColumns(created);
          await loadUpcoming(yearFilter.value);
          loadColumnsForYear(yearFilter.value).catch(e=>console.warn('sync columns failed',e));
          if (crYear!==null&&crYear!==selYear) showToast('Processo em outro ano — altere o filtro para visualizar.','info');
          return;
        }
        else throw new Error(j.error||'Erro desconhecido');
      }
    }catch(e){ console.error(e); alert('Erro ao salvar: '+(e.message||e)); }
  });

  // ── Load upcoming (agenda) ─────────────────────────────────────────────────
  // Carrega e exibe os processos ordenados por prazo ascendente (vencidos primeiro)
  async function loadUpcoming(year=null){
    try{
      // Sempre busca TODOS os processos do tenant — KPIs e Resumo refletem o panorama completo
      // (consistente com dashboard/jurídico). O filtro de ano é aplicado client-side só para a
      // Agenda Jurídica Prioritária (mesma lógica do Calendário Processual).
      const urlAll = new URL(api, window.location.origin);
      urlAll.searchParams.set('_ts',Date.now());
      const res = await fetch(urlAll.toString(),{credentials:'same-origin',cache:'no-store'});
      if (!res.ok){ const txt=await res.text(); let msg=txt; try{msg=JSON.parse(txt).error||txt;}catch(e){} throw new Error(msg||'Erro no servidor'); }
      const j       = await res.json();
      const allData = j.data||[];

      // KPIs e Resumo: sempre o total do tenant, sem filtro de ano
      updateKPIs(allData);
      updateResumo(allData);

      // Agenda: aplica filtro de ano client-side (proximo_prazo OU data_inicio no ano)
      const selYear = year ? parseInt(year, 10) : null;
      const data = selYear
        ? allData.filter(p => {
            const yp = getProcessYear(p);
            const yi = p.data_inicio ? parseProcessDate(p.data_inicio)?.getFullYear() : null;
            return yp === selYear || yi === selYear;
          })
        : allData.slice();

      // Sort by deadline ascending (vencidos first, then by proximity)
      data.sort((a,b)=>{
        if (!a.proximo_prazo) return 1;
        if (!b.proximo_prazo) return -1;
        const da = parseProcessDate(a.proximo_prazo);
        const db = parseProcessDate(b.proximo_prazo);
        if (!da&&!db) return 0;
        if (!da) return 1;
        if (!db) return -1;
        return da - db;
      });

      if (data.length===0){
        upcoming.innerHTML = '<div style="color:var(--muted);font-size:.85rem;padding:12px 0">Nenhum processo encontrado para o período selecionado.</div>';
        return;
      }

      upcoming.innerHTML = data.slice(0,50).map(p=>{
        const prazo = formatProcessDate(p.proximo_prazo);
        const dt    = parseProcessDate(p.proximo_prazo);
        const urg   = getUrgencyInfo(dt, p.status);
        const badge = urg.text ? `<span class="proc-badge ${urg.badgeClass}">${urg.text}</span>` : '';
        return (
          `<div class="proc-agenda-row ${urg.rowClass}" data-id="${p.id}">` +
            `<div class="proc-agenda-left">` +
              `<div class="proc-agenda-num">${p.numero||'-'}</div>` +
              `<div class="proc-agenda-client">${p.cliente_nome||'—'}</div>` +
              (p.setor_nome ? `<div class="proc-agenda-tipo">${p.setor_nome}</div>` : '') +
            `</div>` +
            `<div style="display:flex;align-items:center;gap:8px">${badge}</div>` +
            `<div class="proc-agenda-right">` +
              `<div class="proc-agenda-date">${prazo}</div>` +
              `<div class="proc-agenda-actions">` +
                `<button data-id="${p.id}" class="editBtn proc-btn-sm btn-edit">Editar</button>` +
                `<button data-id="${p.id}" class="delBtn proc-btn-sm btn-del">Excluir</button>` +
              `</div>` +
            `</div>` +
          `</div>`
        );
      }).join('');

      upcoming.querySelectorAll('.editBtn').forEach(b=>b.addEventListener('click', async e=>{
        const id=e.target.dataset.id; const r=await fetch(api+'?id='+id); const j=await r.json(); showModal(j);
      }));
      upcoming.querySelectorAll('.delBtn').forEach(b=>b.addEventListener('click', async e=>{
        if(!(await Yuris.confirm('Excluir processo?', { danger: true, okLabel: 'Excluir' }))) return;
        const id=e.target.dataset.id; const r=await fetch(api+'?id='+id,{method:'DELETE'}); const j=await r.json();
        if(j.success){ loadUpcoming(yearFilter.value); showToast('Excluído','success'); }
        else showToast('Erro ao excluir','error');
      }));
    }catch(e){
      console.error(e);
      upcoming.innerHTML = '<div style="color:#f87171;font-size:.84rem;padding:12px 0">Erro ao carregar processos: '+(e.message||'')+'</div>';
      showToast('Erro ao carregar processos','error');
    }
  }

  // ── Render month columns ───────────────────────────────────────────────────
  const monthNames = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

  // Constrói o kanban de 12 meses + coluna "Sem prazo" a partir do array de processos.
  // Agrupamento: usa proximo_prazo quando definido; senão cai pra data_inicio
  // (que sempre é preenchida com a data de abertura). Processo só vai pra "Sem prazo"
  // se ambas estiverem nulas.
  function renderColumns(processes){
    const groups     = Array.from({length:12},()=>[]);
    const noDateGroup= [];
    processes.forEach(p=>{
      const d = parseProcessDate(p.proximo_prazo) || parseProcessDate(p.data_inicio);
      if (d){ groups[d.getMonth()].push(p); return; }
      noDateGroup.push(p);
    });

    let html = '<div class="proc-board">';

    for (let m=0;m<12;m++){
      const grp   = groups[m];
      const count = grp.length;
      html += `<div class="proc-col" data-month="${m}">`;
      html += `<div class="proc-col-header"><span class="proc-col-month">${monthNames[m]}</span><span class="proc-col-count">${count}</span></div>`;
      html += `<div class="proc-cards-list" id="month-${m}">`;
      if (count===0){
        html += '<div class="proc-empty">Nenhum processo<br>neste mês</div>';
      } else {
        grp.forEach(p=>{
          // Se não tem proximo_prazo, mostra "Aberto em DD/MM" da data_inicio (não confunde)
          const temPrazo = !!parseProcessDate(p.proximo_prazo);
          const prazo    = temPrazo
            ? formatProcessDate(p.proximo_prazo)
            : (p.data_inicio ? 'Aberto ' + formatProcessDate(p.data_inicio) : 'Sem prazo');
          const dt       = parseProcessDate(p.proximo_prazo);
          const urg      = getUrgencyInfo(dt, p.status);
          const badge    = urg.text
            ? `<span class="proc-badge ${urg.badgeClass}">${urg.text}</span>`
            : (!temPrazo ? `<span class="proc-badge badge-neutral">Sem prazo</span>` : '');
          // data-origin-tipo abre o padding-top via CSS quando há faixa
          const otipo = window.YURIS_SHOW_ORIGIN_STRIP && p.origin_account_tipo
            ? ` data-origin-tipo="${String(p.origin_account_tipo).toLowerCase()}"`
            : '';
          html +=
            `<div class="proc-card ${urg.cardClass}" data-id="${p.id}"${otipo}>` +
              buildOriginStripHtml(p) +
              `<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;margin-bottom:2px">` +
                `<div class="proc-card-num">${p.numero||'-'}</div>${badge}` +
              `</div>` +
              `<div class="proc-card-client">${p.cliente_nome||'—'}</div>` +
              (p.setor_nome   ? `<div class="proc-card-meta">${p.setor_nome}</div>` : '') +
              (p.vara_comarca ? `<div class="proc-card-meta">${p.vara_comarca}</div>` : '') +
              `<div class="proc-card-footer">` +
                `<div class="proc-card-date"${!temPrazo ? ' style="color:var(--muted)"' : ''}>${prazo}</div>` +
                `<div class="proc-card-actions">` +
                  `<button data-id="${p.id}" class="editBtn proc-btn-sm btn-edit">Editar</button>` +
                  `<button data-id="${p.id}" class="delBtn proc-btn-sm btn-del">Excluir</button>` +
                `</div>` +
              `</div>` +
              buildTaskBar(p) +
            `</div>`;
        });
      }
      html += '</div></div>';
    }

    if (noDateGroup.length){
      html += `<div class="proc-col" data-month="nodate">`;
      html += `<div class="proc-col-header"><span class="proc-col-month">Sem prazo</span><span class="proc-col-count">${noDateGroup.length}</span></div>`;
      html += `<div class="proc-cards-list" id="month-nodate">`;
      noDateGroup.forEach(p=>{
        const otipo = window.YURIS_SHOW_ORIGIN_STRIP && p.origin_account_tipo
          ? ` data-origin-tipo="${String(p.origin_account_tipo).toLowerCase()}"`
          : '';
        html +=
          `<div class="proc-card" data-id="${p.id}"${otipo}>` +
            buildOriginStripHtml(p) +
            `<div class="proc-card-num">${p.numero||'-'}</div>` +
            `<div class="proc-card-client">${p.cliente_nome||'—'}</div>` +
            `<div class="proc-card-footer">` +
              `<div class="proc-card-date" style="color:var(--muted)">Sem prazo</div>` +
              `<div class="proc-card-actions">` +
                `<button data-id="${p.id}" class="editBtn proc-btn-sm btn-edit">Editar</button>` +
                `<button data-id="${p.id}" class="delBtn proc-btn-sm btn-del">Excluir</button>` +
              `</div>` +
            buildTaskBar(p) +
          `</div>`;
      });
      html += '</div></div>';
    }
    html += '</div>';

    columnsWrapper.innerHTML = html;

    columnsWrapper.querySelectorAll('.editBtn').forEach(b=>b.addEventListener('click', async e=>{
      const id=e.target.dataset.id; const r=await fetch(api+'?id='+id); const j=await r.json(); showModal(j);
    }));
    columnsWrapper.querySelectorAll('.delBtn').forEach(b=>b.addEventListener('click', async e=>{
      if(!(await Yuris.confirm('Excluir processo?', { danger: true, okLabel: 'Excluir' }))) return;
      const id=e.target.dataset.id; const r=await fetch(api+'?id='+id,{method:'DELETE'}); const j=await r.json();
      if(j.success){ loadColumnsForYear(yearFilter.value); loadUpcoming(yearFilter.value); showToast('Excluído','success'); }
      else showToast('Erro ao excluir','error');
    }));
    columnsWrapper.querySelectorAll('.proc-card').forEach(card=>card.addEventListener('click', async e=>{
      if(e.target.closest('button')) return;
      const id=card.dataset.id;
      const r=await fetch(api+'?id='+id); const j=await r.json(); showModal(j);
    }));
  }

  // ── Load columns for year ─────────────────────────────────────────────────
  // Busca processos filtrados pelo ano selecionado e repopula o kanban inteiro
  async function loadColumnsForYear(year){
    const url = new URL(api, window.location.origin);
    url.searchParams.set('from', year+'-01-01');
    url.searchParams.set('to',   year+'-12-31');
    url.searchParams.set('_ts',  Date.now());
    const res = await fetch(url.toString(),{credentials:'same-origin',cache:'no-store'});
    if (!res.ok) throw new Error('Erro carregando colunas');
    const j    = await res.json();
    const data = j.data||[];
    renderColumns(data);
  }

  // ── Year selector ──────────────────────────────────────────────────────────
  const now  = new Date();
  const curY = now.getFullYear();
  for (let y=curY+1; y>=curY-3; y--){
    const opt = document.createElement('option'); opt.value=y; opt.textContent=y; yearFilter.appendChild(opt);
  }
  yearFilter.value = curY;
  yearFilter.addEventListener('change', ()=>{
    loadColumnsForYear(yearFilter.value).catch(e=>{ console.error(e); showToast('Erro ao carregar colunas','error'); });
    loadUpcoming(yearFilter.value);
  });

  // ── Initial load ───────────────────────────────────────────────────────────
  loadColumnsForYear(yearFilter.value).catch(e=>{ console.error(e); showToast('Erro ao carregar colunas','error'); });
  loadUpcoming(yearFilter.value);

  // ── Sistema de busca e filtros ─────────────────────────────────────────────
  let _allProcessesCache = []; // cache de todos os processos carregados

  // Popula o select de responsável (filtro da barra) com agrupamento por conta.
  // Mantém o <option value=""> "Todos os responsáveis" já presente no HTML.
  (function _initFilterResp() {
    const sel = document.getElementById('procFilterResp');
    if (!sel || !window._SYSTEM_USERS) return;
    if (window.Yuris && typeof Yuris.populateUserSelect === 'function') {
      // Marca o option "Todos" pra ele sobreviver ao re-render do helper
      const allOpt = sel.querySelector('option[value=""]');
      if (allOpt) allOpt.setAttribute('data-keep', '1');
      Yuris.populateUserSelect(sel, window._SYSTEM_USERS, { allowEmpty: false });
      return;
    }
    window._SYSTEM_USERS.forEach(u => {
      const opt = document.createElement('option');
      opt.value = u.id; opt.textContent = u.nome;
      sel.appendChild(opt);
    });
  })();

  // Normaliza string para comparação case-insensitive sem acento
  function _norm(s) {
    return String(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
  }

  // Aplica os filtros da barra ao array de processos e retorna o filtrado
  function _applyFilters(procs) {
    const txt    = _norm(document.getElementById('procSearchText')?.value || '');
    const stat   = document.getElementById('procFilterStatus')?.value || '';
    const resp   = document.getElementById('procFilterResp')?.value || '';
    const from   = document.getElementById('procFilterDateFrom')?.value || '';
    const to     = document.getElementById('procFilterDateTo')?.value || '';
    const origin = document.getElementById('procFilterOrigin')?.value || '';
    return procs.filter(p => {
      if (txt && !_norm(p.numero).includes(txt) && !_norm(p.cliente_nome).includes(txt) && !_norm(p.setor_nome).includes(txt) && !_norm(p.parte_contraria).includes(txt)) return false;
      if (stat && p.status !== stat) return false;
      if (resp && String(p.responsavel_user_id) !== resp) return false;
      if (from && p.proximo_prazo && p.proximo_prazo < from) return false;
      if (to   && p.proximo_prazo && p.proximo_prazo > to)   return false;
      // Filtro de origem: __matriz__ / __filiais__ / id-específico
      if (origin) {
        const tipo = String(p.origin_account_tipo || '').toLowerCase();
        const oid  = String(p.origin_account_id || '');
        if (origin === '__matriz__'  && tipo !== 'matriz') return false;
        if (origin === '__filiais__' && tipo !== 'filial') return false;
        if (origin !== '__matriz__' && origin !== '__filiais__' && oid !== origin) return false;
      }
      return true;
    });
  }

  // Atualiza contador de resultados
  function _updateFilterCount(total, filtered) {
    const el = document.getElementById('procFilterCount');
    if (!el) return;
    if (total === filtered) el.textContent = `${total} processo${total !== 1 ? 's' : ''}`;
    else el.textContent = `${filtered} de ${total} processo${total !== 1 ? 's' : ''}`;
  }

  // Re-renderiza com filtros aplicados a partir do cache
  function _applyAndRender() {
    if (!_allProcessesCache.length) return;
    const filtered = _applyFilters(_allProcessesCache);
    renderColumns(filtered);
    _updateFilterCount(_allProcessesCache.length, filtered.length);
    // Filtra também a agenda — mostra/oculta linhas existentes
    const filteredIds = new Set(filtered.map(p => String(p.id)));
    document.querySelectorAll('.proc-agenda-row[data-id]').forEach(row => {
      row.style.display = filteredIds.has(row.getAttribute('data-id')) ? '' : 'none';
    });
  }

  // Intercepta loadColumnsForYear para armazenar o cache completo
  const _origLoadColumns = loadColumnsForYear;
  loadColumnsForYear = async function(year) {
    const url = new URL(api, window.location.origin);
    url.searchParams.set('from', year+'-01-01');
    url.searchParams.set('to',   year+'-12-31');
    url.searchParams.set('_ts',  Date.now());
    const res = await fetch(url.toString(),{credentials:'same-origin',cache:'no-store'});
    if (!res.ok) throw new Error('Erro carregando colunas');
    const j = await res.json();
    _allProcessesCache = j.data || [];
    const filtered = _applyFilters(_allProcessesCache);
    renderColumns(filtered);
    _updateFilterCount(_allProcessesCache.length, filtered.length);
  };

  // Eventos dos campos de filtro
  ['procSearchText','procFilterStatus','procFilterResp','procFilterDateFrom','procFilterDateTo','procFilterOrigin'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', _applyAndRender);
    document.getElementById(id)?.addEventListener('change', _applyAndRender);
  });

  // Botão limpar filtros
  document.getElementById('procFilterClear')?.addEventListener('click', () => {
    const ids = ['procSearchText','procFilterStatus','procFilterResp','procFilterDateFrom','procFilterDateTo','procFilterOrigin'];
    ids.forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
    _applyAndRender();
  });

  // ── Prazos Processuais ────────────────────────────────────────────────────
  let _procId = null; // ID do processo atualmente aberto no modal

  function _fmtD(d) { const m = String(d||'').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? `${m[3]}/${m[2]}/${m[1]}` : (d||'—'); }

  function _renderPrazo(p) {
    const cls = {concluido:'prazo-badge-concluido',vencido:'prazo-badge-vencido',pendente:'prazo-badge-pendente'}[p.status]||'prazo-badge-pendente';
    const lbl = {concluido:'Concluído',vencido:'Vencido',pendente:'Pendente'}[p.status]||'Pendente';
    return `<div class="prazo-card" data-id="${p.id}">
      <div class="prazo-card-header"><strong style="font-size:.85rem;color:#e2f0ff">${p.descricao}</strong><span class="${cls}">${lbl}</span></div>
      <div style="font-size:.78rem;color:#9ab0c9;display:flex;gap:12px;flex-wrap:wrap">
        <span>${_fmtD(p.data_limite)}</span>${p.responsavel?`<span>Resp.: ${p.responsavel}</span>`:''}<span>${p.prioridade||'media'}</span>
      </div>
      ${p.observacao?`<div style="font-size:.75rem;color:#7a9abf;font-style:italic">${p.observacao}</div>`:''}
      <div style="display:flex;gap:6px;margin-top:2px">
        ${p.status!=='concluido'?`<button type="button" style="padding:4px 10px;font-size:.75rem;border-radius:6px;background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#6ee7b7;cursor:pointer" onclick="window._togglePrazo(${p.id},'concluido')">✓ Concluir</button>`:''}
        <button type="button" style="padding:4px 10px;font-size:.75rem;border-radius:6px;background:transparent;border:1px solid rgba(239,68,68,.3);color:#fca5a5;cursor:pointer" onclick="window._deletePrazo(${p.id})">Remover</button>
      </div>
    </div>`;
  }

  async function _loadPrazos(pid) {
    const c = document.getElementById('prazosContainer'); if(!c) return;
    try {
      const r = await fetch(`/api/processo_prazos.php?processo_id=${pid}`,{credentials:'same-origin'});
      const d = await r.json();
      const pz = d.data||[];
      c.innerHTML = pz.length ? pz.map(_renderPrazo).join('') : '<div style="color:#9ab0c9;font-size:.8rem">Nenhum prazo cadastrado</div>';
      // Auto-preenche "Próximo Prazo" com o prazo pendente mais próximo
      const pendentes = pz.filter(p=>p.status==='pendente'&&p.data_limite).sort((a,b)=>a.data_limite.localeCompare(b.data_limite));
      const inp = document.getElementById('proximoPrazoInput');
      const hint = document.getElementById('proximoPrazoHint');
      if(inp){
        if(pendentes.length){ inp.value = pendentes[0].data_limite; if(hint) hint.textContent='⟵ Preenchido automaticamente pelo prazo mais próximo'; }
        else if(pz.length){ if(hint) hint.textContent='Todos os prazos estão concluídos — Cadastre um novo prazo'; }
        else { if(hint) hint.textContent='Cadastre um Prazo Processual para preencher automaticamente'; }
      }
    } catch(e){ c.innerHTML='<div style="color:#ef4444;font-size:.8rem">Erro ao carregar prazos</div>'; }
  }

  window._togglePrazo = async (id,status) => {
    await fetch('/api/processo_prazos.php',{method:'PATCH',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,status})});
    if(_procId) _loadPrazos(_procId);
  };
  window._deletePrazo = async (id) => {
    if(!(await Yuris.confirm('Remover este prazo?', { danger: true, okLabel: 'Remover' }))) return;
    await fetch(`/api/processo_prazos.php?id=${id}`,{method:'DELETE',credentials:'same-origin'});
    if(_procId) _loadPrazos(_procId);
  };

  // Abre modal próprio ao clicar "Adicionar Prazo" (sem usar prompt nativo do browser)
  document.getElementById('btnAddPrazo')?.addEventListener('click', () => {
    if(!_procId){ alert('Salve o processo primeiro para adicionar prazos.'); return; }
    const m = document.getElementById('modalAddPrazo');
    if(!m) return;
    document.getElementById('addPrazoDesc').value = '';
    document.getElementById('addPrazoData').value = '';
    document.getElementById('addPrazoPrio').value = 'media';
    document.getElementById('addPrazoResp').value = '';
    document.getElementById('addPrazoObs').value  = '';
    m.style.display = 'flex';
  });
  document.getElementById('addPrazoCancel')?.addEventListener('click', () => {
    document.getElementById('modalAddPrazo').style.display = 'none';
  });
  document.getElementById('addPrazoConfirm')?.addEventListener('click', async () => {
    const desc = document.getElementById('addPrazoDesc').value.trim();
    const data = document.getElementById('addPrazoData').value;
    if(!desc || !data){ alert('Preencha a descrição e a data limite.'); return; }
    const prio = document.getElementById('addPrazoPrio').value;
    const resp = document.getElementById('addPrazoResp').value.trim();
    const obs  = document.getElementById('addPrazoObs').value.trim();
    document.getElementById('modalAddPrazo').style.display = 'none';
    await fetch('/api/processo_prazos.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({processo_id:_procId,descricao:desc,data_limite:data,prioridade:prio,responsavel:resp,observacao:obs})});
    _loadPrazos(_procId);
  });

  // ── Tarefas Processuais ───────────────────────────────────────────────────
  function _renderTarefas(list) {
    const c=document.getElementById('tarefasContainer'),p=document.getElementById('tarefasProgress'),b=document.getElementById('tarefasProgressBar');
    if(!c) return;
    const total=list.length, done=list.filter(t=>t.concluido).length, pct=total?Math.round(done/total*100):0;
    if(p) p.textContent=`${pct}% concluído (${done}/${total})`;
    if(b) b.style.width=pct+'%';
    // Atualiza barra no card do quadro em tempo real
    if (_procId) {
      const card = document.querySelector(`.proc-card[data-id="${_procId}"]`);
      if (card) {
        const old = card.querySelector('.proc-task-bar-wrap');
        if (old) old.remove();
        const html = buildTaskBar({ tarefas_total: total, tarefas_feitas: done });
        if (html) card.insertAdjacentHTML('beforeend', html);
      }
    }
    c.innerHTML = list.length ? list.map(t=>{
      const done = t.concluido ? ' done-item' : '';
      const chkSvg = `<svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1.5 5 4 7.5 8.5 2"/></svg>`;
      const respHtml = t.responsavel
        ? `<span class="tarefa-resp"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>${t.responsavel}</span>`
        : '';
      const dataHtml = t.data_tarefa
        ? (() => {
            const d = new Date(t.data_tarefa + 'T00:00:00');
            const hoje = new Date(); hoje.setHours(0,0,0,0);
            const diff = Math.ceil((d - hoje) / 86400000);
            const label = d.toLocaleDateString('pt-BR', {day:'2-digit', month:'2-digit', year:'2-digit'});
            const cor = diff < 0 ? '#fca5a5' : diff === 0 ? '#fcd34d' : '#9ab0c9';
            return `<span class="tarefa-resp" style="color:${cor}"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>${label}</span>`;
          })()
        : '';
      return `<div class="tarefa-item${done}" id="tarefa-row-${t.id}">
        <input type="checkbox" class="tarefa-check" ${t.concluido?'checked':''} onchange="window._toggleTarefa(${t.id},this.checked)">
        <div class="tarefa-chk-box" onclick="window._toggleTarefa(${t.id},${t.concluido?'false':'true'})">${t.concluido?chkSvg:''}</div>
        <div class="tarefa-body">
          <span class="tarefa-texto${t.concluido?' done':''}">${t.titulo}</span>
          <div style="display:flex;gap:10px;flex-wrap:wrap">${respHtml}${dataHtml}</div>
        </div>
        <button type="button" class="tarefa-btn-edit"
          data-id="${t.id}"
          data-titulo="${t.titulo.replace(/"/g,'&quot;')}"
          data-resp="${(t.responsavel||'').replace(/"/g,'&quot;')}"
          data-data="${t.data_tarefa||''}"
          onclick="window._editarTarefa(this)">Editar</button>
        <button type="button" class="tarefa-del" onclick="window._deleteTarefa(${t.id})" title="Remover">✕</button>
      </div>`;
    }).join('') : '<div style="color:#7a9abf;font-size:.8rem;padding:8px 4px">Nenhuma tarefa cadastrada</div>';
  }

  async function _loadTarefas(pid) {
    try { const r=await fetch(`/api/processo_tarefas.php?processo_id=${pid}`,{credentials:'same-origin'}); _renderTarefas((await r.json()).data||[]); } catch(e){}
  }

  window._toggleTarefa = async (id,v) => {
    await fetch('/api/processo_tarefas.php',{method:'PATCH',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,concluido:v?1:0})});
    if(_procId){_loadTarefas(_procId);_loadHistorico(_procId);}
  };
  window._deleteTarefa = async (id) => {
    if(!(await Yuris.confirm('Remover esta tarefa?', { danger: true, okLabel: 'Remover' }))) return;
    await fetch(`/api/processo_tarefas.php?id=${id}`,{method:'DELETE',credentials:'same-origin'});
    if(_procId) _loadTarefas(_procId);
  };

  // Substitui o item de tarefa por um campo de edição inline
  window._editarTarefa = (btn) => {
    const id    = btn.dataset.id;
    const titulo= btn.dataset.titulo;
    const resp  = btn.dataset.resp;
    const data  = btn.dataset.data;

    document.getElementById('etId').value    = id;
    document.getElementById('etTitulo').value= titulo;
    document.getElementById('etData').value  = data || '';

    // Responsável agrupado por Matriz/Filial (helper central user_select.js).
    // Usa NOME como value porque tarefas de processo guardam responsável em texto.
    const etResp = document.getElementById('etResp');
    if (window.Yuris && typeof Yuris.buildGroupedOptionsByName === 'function') {
      etResp.innerHTML = Yuris.buildGroupedOptionsByName(_usuariosList, {
        placeholder: '— Sem responsável —',
        selectedName: resp
      });
    } else {
      etResp.innerHTML = '<option value="">— Sem responsável —</option>' +
        _usuariosList.map(u =>
          `<option value="${u.nome}"${u.nome === resp ? ' selected' : ''}>${u.nome}</option>`
        ).join('');
    }
    // Garante a option mesmo se o responsável atual não estiver mais na lista (dado antigo)
    if (resp && !_usuariosList.find(u => u.nome === resp)) {
      etResp.innerHTML += `<option value="${resp.replace(/"/g,'&quot;')}" selected>${resp}</option>`;
    }

    const modal = document.getElementById('modalEditTarefa');
    modal.style.display = 'flex';
    const etTit = document.getElementById('etTitulo');
    setTimeout(() => { if(etTit){ etTit.focus(); etTit.setSelectionRange(etTit.value.length, etTit.value.length); } }, 50);

    // fechar ao clicar fora
    modal.onclick = e => { if (e.target === modal) modal.style.display = 'none'; };
  };
  window._loadTarefasGlobal = () => { if(_procId) _loadTarefas(_procId); };
  window._salvarEdicaoTarefa = async () => {
    const id         = document.getElementById('etId')?.value;
    const novoTitulo = document.getElementById('etTitulo')?.value.trim();
    const novoResp   = document.getElementById('etResp')?.value || '';
    const novaData   = document.getElementById('etData')?.value || null;
    if (!id || !novoTitulo) return;
    document.getElementById('modalEditTarefa').style.display = 'none';
    await fetch('/api/processo_tarefas.php', {
      method:'PATCH', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id: parseInt(id), titulo: novoTitulo, responsavel: novoResp, data_tarefa: novaData})
    });
    if(_procId) { _loadTarefas(_procId); _loadHistorico(_procId); }
  };

  const _addTarefaBtn = document.getElementById('btnAddTarefa');
  const _addTarefaInput = document.getElementById('novaTarefa');
  async function _doAddTarefa(){
    const titulo=_addTarefaInput?.value.trim(); if(!titulo||!_procId){if(!_procId)alert('Salve o processo primeiro.');return;}
    await fetch('/api/processo_tarefas.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({processo_id:_procId,titulo})});
    if(_addTarefaInput) _addTarefaInput.value='';
    _loadTarefas(_procId); _loadHistorico(_procId);
  }
  _addTarefaBtn?.addEventListener('click',_doAddTarefa);
  _addTarefaInput?.addEventListener('keydown',e=>{ if(e.key==='Enter'){e.preventDefault();_doAddTarefa();} });

  // ── Histórico Processual ──────────────────────────────────────────────────
  async function _loadHistorico(pid) {
    const el=document.getElementById('processoHistoryList'); if(!el) return;
    try {
      const r=await fetch(`/api/processo_history.php?processo_id=${pid}`,{credentials:'same-origin'});
      const items=(await r.json()).data||[];
      el.innerHTML = items.length ? items.map(h=>{
        const dt=new Date(h.created_at);
        const s=dt.toLocaleDateString('pt-BR')+', '+dt.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
        // Badge de origem (MATRIZ / FILIAL / ADVOGADO SOLO) — pequeno, no topo do card.
        // Usa _tipoLabel/_tipoClass (helpers no topo do arquivo) pra cobrir todos
        // os 3 tipos de conta — antes o ternário tratava qualquer coisa != matriz
        // como FILIAL, fazendo conta tipo 'advogado' aparecer como FILIAL aqui.
        const tipo = String(h.author_account_tipo || '').toLowerCase();
        const nome = String(h.author_account_nome || '');
        let originBadge = '';
        if (tipo && nome) {
          originBadge = `<div class="timeline-origin ${_tipoClass(tipo)}" title="${escapeAttr(nome)}">${_tipoLabel(tipo)} · ${escapeAttr(nome)}</div>`;
        }
        const _acaoTraduzida = (window.Yuris && Yuris.translateAuditAcao) ? Yuris.translateAuditAcao(h.acao) : (h.acao || ''); // i18n acao via Yuris.translateAuditAcao
        return `<div class="timeline-entry"><div class="timeline-dot"></div><div class="timeline-content">${originBadge}<div class="timeline-action">${h.descricao||_acaoTraduzida}</div><div class="timeline-meta">${h.user_email||'sistema'} · ${s}</div></div></div>`;
      }).join('') : '<div style="color:#9ab0c9;font-size:.8rem">Nenhum registro ainda</div>';
    } catch(e){}
  }
  function escapeAttr(s) {
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  // ── Vínculo com cliente (Card) ────────────────────────────────────────────
  let _cardsData = []; // cache local dos cards para não buscar repetidamente

  function _cardNome(c) {
    return c.cliente_nome || c.cliente || c.titulo || `Lead #${c.id}`;
  }

  function _applyCardSelection(card) {
    const hidden  = document.getElementById('proc_card_id');
    const lk      = document.getElementById('proc_card_link');
    const linkBtn = document.getElementById('proc_card_link_btn');
    const label   = document.getElementById('proc_card_selected_label');
    const limpar  = document.getElementById('btnLimparCliente');
    if (card) {
      const nome = _cardNome(card);
      if (hidden) hidden.value = card.id;
      if (lk) lk.style.display = 'block';
      if (linkBtn) linkBtn.href = `/prospeccao.php?open=${card.id}`;
      if (label) { label.textContent = `✓ ${nome}`; label.style.color = '#6ee7b7'; }
      if (limpar) limpar.style.display = 'block';
      // Preenche campo "Cliente" e exibe aviso de vínculo
      const nomeInput = form?.querySelector('[name="cliente_nome"]');
      if (nomeInput) nomeInput.value = nome;
      const hint = document.getElementById('clienteNomeHint');
      if (hint) hint.style.display = 'block';
    } else {
      if (hidden) hidden.value = '';
      if (lk) lk.style.display = 'none';
      if (label) { label.textContent = 'Nenhum cliente vinculado'; label.style.color = '#9ab0c9'; }
      if (limpar) limpar.style.display = 'none';
      const hint = document.getElementById('clienteNomeHint');
      if (hint) hint.style.display = 'none';
    }
  }

  async function _loadCardsSelect(selectedId) {
    if (!_cardsData.length) {
      try {
        const r = await fetch('/api/cards.php', {credentials:'same-origin'});
        const d = await r.json();
        _cardsData = Array.isArray(d) ? d : (d.data || []);
      } catch(e) {}
    }
    if (selectedId) {
      const card = _cardsData.find(c => String(c.id) === String(selectedId));
      _applyCardSelection(card || null);
    } else {
      _applyCardSelection(null);
    }
  }

  // Renderiza a lista de clientes no modal (com filtro opcional)
  function _renderClienteList(filtro = '') {
    const lista = document.getElementById('clienteListaModal');
    if (!lista) return;
    const termo = filtro.toLowerCase();
    const filtrados = _cardsData.filter(c => !termo || _cardNome(c).toLowerCase().includes(termo) || (c.empresa_nome||'').toLowerCase().includes(termo));
    if (!filtrados.length) {
      lista.innerHTML = '<div style="color:#9ab0c9;font-size:.83rem;padding:16px 8px;text-align:center">Nenhum cliente encontrado</div>';
      return;
    }
    // Usa classes (.cliente-mod-item) ao inves de inline + onmouseover/out:
    // permite override limpo no tema claro via CSS :hover. Antes os handlers
    // setavam el.style.background a cada movimento de mouse, brigando com
    // qualquer regra externa de tema.
    lista.innerHTML = filtrados.map(c => {
      const nome = _cardNome(c);
      const emp  = c.empresa_nome ? `<span class="cliente-mod-emp"> · ${c.empresa_nome}</span>` : '';
      return `<div class="cliente-mod-item" onclick="window._selecionarCliente(${c.id})">
                <div class="cliente-mod-nome">${nome}${emp}</div>
              </div>`;
    }).join('');
  }

  // Chamado ao clicar num cliente no modal
  window._selecionarCliente = (cardId) => {
    const card = _cardsData.find(c => String(c.id) === String(cardId));
    _applyCardSelection(card || null);
    document.getElementById('modalSelecionarCliente').style.display = 'none';
  };

  // Botão "Buscar" abre o modal e carrega a lista
  document.getElementById('btnAbrirModalCliente')?.addEventListener('click', async () => {
    if (!_cardsData.length) {
      try {
        const r = await fetch('/api/cards.php', {credentials:'same-origin'});
        const d = await r.json();
        _cardsData = Array.isArray(d) ? d : (d.data || []);
      } catch(e) {}
    }
    document.getElementById('clienteSearchInput').value = '';
    _renderClienteList('');
    document.getElementById('modalSelecionarCliente').style.display = 'flex';
    setTimeout(() => document.getElementById('clienteSearchInput')?.focus(), 50);
  });

  // Filtro em tempo real dentro do modal
  document.getElementById('clienteSearchInput')?.addEventListener('input', function() {
    _renderClienteList(this.value);
  });

  // Fechar modal
  document.getElementById('btnFecharModalCliente')?.addEventListener('click', () => {
    document.getElementById('modalSelecionarCliente').style.display = 'none';
  });
  document.getElementById('modalSelecionarCliente')?.addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
  });

  // Botão "✕" limpa a seleção
  document.getElementById('btnLimparCliente')?.addEventListener('click', () => _applyCardSelection(null));

  // ── Lista de usuários — carregada uma vez e reutilizada em todos os selects ──
  // Usa dados embutidos pelo PHP se disponíveis — elimina fetch async e race condition
  let _usuariosList = (window._SYSTEM_USERS && window._SYSTEM_USERS.length) ? window._SYSTEM_USERS : [];
  async function _loadUsuarios() {
    if(_usuariosList.length) return _usuariosList;
    try {
      const r = await fetch('/api/users.php',{credentials:'same-origin'});
      _usuariosList = ((await r.json()).data||[]);
    } catch(e){}
    return _usuariosList;
  }

  // ── Setores ──────────────────────────────────────────────────────────────────
  let _setoresList = [];
  async function _loadSetores() {
    if (_setoresList.length) return _setoresList;
    try {
      const r = await fetch('/api/teams.php', { credentials: 'same-origin' });
      const j = await r.json();
      _setoresList = j.data || [];
    } catch(e) { _setoresList = []; }
    return _setoresList;
  }
  function _fillSetorSelect(selectedId = '') {
    const sel = document.getElementById('setor_id'); if (!sel) return;
    sel.innerHTML = '<option value="">— Selecionar setor —</option>' +
      _setoresList.map(s =>
        `<option value="${s.id}"${String(s.id) === String(selectedId) ? ' selected' : ''}>${s.nome}</option>`
      ).join('');
  }
  // Pré-carrega setores ao iniciar
  _loadSetores().then(() => _fillSetorSelect());

  // Popula selects "por nome" (legado: alguns formulários gravavam o nome em vez do id).
  // Quando o helper Yuris.populateUserSelect está disponível, usa <optgroup> por conta.
  function _fillUserSelect(selId, selectedName='') {
    const sel = document.getElementById(selId); if(!sel) return;
    if (window.Yuris && typeof Yuris.populateUserSelect === 'function') {
      // Adapta: o helper usa id como value; aqui o select histórico usa nome.
      // Renderiza pelo helper e DEPOIS reescreve value=nome (mantém o agrupamento visual).
      Yuris.populateUserSelect(sel, _usuariosList, { placeholder: '— Selecionar —' });
      sel.querySelectorAll('option[value]').forEach(o => {
        if (!o.value) return;
        const u = _usuariosList.find(x => String(x.id) === o.value);
        if (u) {
          o.value = u.nome;
          if (u.nome === selectedName) o.selected = true;
        }
      });
      return;
    }
    // Fallback (sem helper): lista chapada
    sel.innerHTML = '<option value="">— Selecionar —</option>' +
      _usuariosList.map(u=>`<option value="${u.nome}"${u.nome===selectedName?' selected':''}>${u.nome}</option>`).join('');
  }

  function _fillUserSelectById(selId, selectedId='') {
    const sel = document.getElementById(selId); if(!sel) return;
    if (window.Yuris && typeof Yuris.populateUserSelect === 'function') {
      Yuris.populateUserSelect(sel, _usuariosList, {
        placeholder: '— Selecionar responsável —',
        selected:    selectedId || null
      });
      return;
    }
    sel.innerHTML = '<option value="">— Selecionar responsável —</option>' +
      _usuariosList.map(u=>`<option value="${u.id}"${String(u.id)===String(selectedId)?' selected':''}>${u.nome}</option>`).join('');
  }

  // Popula os selects de usuário ao abrir o modal de adicionar prazo
  document.getElementById('btnAddPrazo')?.addEventListener('mousedown', async () => {
    await _loadUsuarios();
    _fillUserSelect('addPrazoResp');
    _fillUserSelect('novaTarefaResp');
  });

  // window.showModal já exposto via linha "window.showModal = showModal" — sem override adicional necessário

  // Inclui responsável ao adicionar tarefa
  const _origDoAddTarefa = _doAddTarefa;
  async function _doAddTarefaComResp(){
    const titulo=_addTarefaInput?.value.trim(); if(!titulo||!_procId){if(!_procId)alert('Salve o processo primeiro.');return;}
    const resp  = document.getElementById('novaTarefaResp')?.value || '';
    const data  = document.getElementById('novaTarefaData')?.value || null;
    await fetch('/api/processo_tarefas.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({processo_id:_procId,titulo,responsavel:resp,data_tarefa:data||null})});
    if(_addTarefaInput) _addTarefaInput.value='';
    const dataEl = document.getElementById('novaTarefaData'); if(dataEl) dataEl.value='';
    _loadTarefas(_procId); _loadHistorico(_procId);
  }
  _addTarefaBtn?.removeEventListener('click',_doAddTarefa);
  _addTarefaBtn?.addEventListener('click',_doAddTarefaComResp);
  _addTarefaInput?.removeEventListener('keydown', e=>{ if(e.key==='Enter'){e.preventDefault();_doAddTarefa();} });
  _addTarefaInput?.addEventListener('keydown',e=>{ if(e.key==='Enter'){e.preventDefault();_doAddTarefaComResp();} });

  // Pré-carrega usuários para ter disponível nos selects
  _loadUsuarios();
});
