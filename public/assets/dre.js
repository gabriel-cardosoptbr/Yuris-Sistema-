(function(){
  const api = '/sistema_vendas/public/api/dre_accounts.php';
  const codesApi = '/sistema_vendas/public/api/dre_codes.php';
  const csrf = (document.querySelector('input[name="csrf_token"]') || {}).value || '';

  // helper for temporary blinking messages
  function showTempMsg(el, text){
    if (!el) return;
    // clear previous timers if any
    if (el.__blinkInterval) { clearInterval(el.__blinkInterval); el.__blinkInterval = null; }
    if (el.__hideTimeout) { clearTimeout(el.__hideTimeout); el.__hideTimeout = null; }
    el.textContent = text;
    el.style.display = 'block';
    el.style.opacity = '1';
    // blink by toggling opacity
    el.__blinkInterval = setInterval(()=>{ el.style.opacity = (el.style.opacity === '1' || el.style.opacity === '') ? '0.3' : '1'; }, 500);
    // hide after 5 seconds
    el.__hideTimeout = setTimeout(()=>{
      if (el.__blinkInterval) { clearInterval(el.__blinkInterval); el.__blinkInterval = null; }
      el.style.display = 'none';
      el.style.opacity = '1';
      el.textContent = '';
      el.__hideTimeout = null;
    }, 5000);
  }

  function hideTempMsg(el){ if (!el) return; if (el.__blinkInterval) { clearInterval(el.__blinkInterval); el.__blinkInterval = null; } if (el.__hideTimeout) { clearTimeout(el.__hideTimeout); el.__hideTimeout = null; } el.style.display = 'none'; el.style.opacity = '1'; el.textContent = ''; }

  function fmtMoney(v){
    const n = Number(v) || 0;
    return n.toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  }

  function fmtDate(d){
    if (!d) return '';
    // expect YYYY-MM-DD
    try{ const parts = d.split('-'); if (parts.length>=2) return parts[0] + '-' + parts[1]; }catch(e){}
    return d;
  }

  let cachedRows = [];

  async function load(){
    try{
      const res = await fetch(api);
      const j = await res.json();
      cachedRows = j.data || [];
      // populate codes and filters
      await loadCodes();
      // render using current filters
      applyFiltersAndRender(j);
      attachButtons();
    }catch(e){ console.error(e); }
  }

  function getFilters(){
    const month = (document.getElementById('filter_month')||{}).value || '';
    const recorr = (document.getElementById('filter_recorrencia')||{}).value || 'all';
    const code = (document.getElementById('filter_code')||{}).value || '';
    return { month, recorr, code };
  }

  function applyFiltersAndRender(apiPayload){
    try{
      const filters = getFilters();
      const rows = (cachedRows || []).filter(r => {
        const recorr = String(r.recorrencia || '').trim().toLowerCase();
        const isFixa = recorr === 'fixa';

        // filtro por natureza (fixa/variavel)
        if (filters.recorr && filters.recorr !== 'all') {
          if (recorr !== filters.recorr) return false;
        }

        // filtro por código
        if (filters.code && (r.codigo || '') !== filters.code) return false;

        // filtro por mês
        if (filters.month) {
          const mm = String(r.data_referencia || '').slice(0, 7);
          if (isFixa) {
            // fixo: aparece a partir do mês de cadastro (mm <= mês filtrado)
            if (mm && mm > filters.month) return false;
          } else {
            // variável: só no mês exato
            if (mm !== filters.month) return false;
          }
        }

        return true;
      });

      const fixedTbody = document.querySelector('#dreTableFixed tbody');
      const varTbody = document.querySelector('#dreTableVar tbody');
      if (fixedTbody) fixedTbody.innerHTML = '';
      if (varTbody) varTbody.innerHTML = '';
      let sumReceita = 0, sumDespesa = 0;
      rows.forEach(r => {
        const tr = document.createElement('tr');
        const recorr = (r.recorrencia === 'variavel') ? 'Variável' : 'Fixa';
        const tipoBadge = r.tipo === 'receita'
          ? '<span class="tbadge tbadge-receita">Receita</span>'
          : '<span class="tbadge tbadge-despesa">Despesa</span>';
        const recorrBadge = r.recorrencia === 'variavel'
          ? '<span class="tbadge tbadge-variavel">Variável</span>'
          : '<span class="tbadge tbadge-fixa">Fixa</span>';
        const valClass = r.tipo === 'receita' ? 'val-receita' : 'val-despesa';
        tr.innerHTML = `<td style="color:var(--muted);font-size:.72rem">${r.codigo || '—'}</td><td>${r.nome}</td><td>${tipoBadge}</td><td>${recorrBadge}</td><td style="color:var(--muted)">${fmtDate(r.data_referencia)}</td><td class="val-col ${valClass}">${fmtMoney(r.valor_fixo)}</td><td style="text-align:right"><button data-id="${r.id}" class="btn btn-edit btn-sm editBtn">Editar</button> <button data-id="${r.id}" class="btn btn-del btn-sm delBtn">Excluir</button></td>`;
        if (r.tipo === 'receita') sumReceita += Number(r.valor_fixo) || 0; else sumDespesa += Number(r.valor_fixo) || 0;
        if (r.recorrencia === 'variavel') {
          if (varTbody) varTbody.appendChild(tr);
        } else {
          if (fixedTbody) fixedTbody.appendChild(tr);
        }
      });
      const resultado = sumReceita - sumDespesa;
      // fetch closed_total up to selected month and add to receita, then update resultado
      (async function(){
        let closed = 0;
        try{
          const month = filters.month || '';
          let url = api;
          if (month) url += '?closed_month=' + encodeURIComponent(month);
          const res = await fetch(url);
          const j = await res.json();
          closed = (j && j.closed_total) ? Number(j.closed_total) : 0;
        }catch(e){ console.error(e); }
        // ensure closedOnlyVal exists
        let closedEl = document.getElementById('closedOnlyVal');
        if (!closedEl){
          const wrap = document.getElementById('dreSummary');
          if (wrap){
            const div = document.createElement('div'); div.id = 'closedOnlyRow'; div.innerHTML = 'Receita (fechados): <strong id="closedOnlyVal">' + fmtMoney(closed || 0) + '</strong>';
            wrap.insertBefore(div, wrap.firstChild);
            closedEl = document.getElementById('closedOnlyVal');
          }
        } else {
          closedEl.textContent = fmtMoney(closed || 0);
        }
        const totalReceita   = (sumReceita || 0) + (closed || 0);
        const totalResultado = totalReceita - (sumDespesa || 0);
        const margem         = totalReceita > 0 ? (totalResultado / totalReceita * 100) : 0;

        // ── Atualiza todos os KPI cards ──
        const _s = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
        _s('sumReceita',    fmtMoney(totalReceita));
        _s('sumDespesa',    fmtMoney(sumDespesa || 0));
        _s('sumResultado',  fmtMoney(totalResultado));
        _s('sumMargem',     margem.toFixed(1).replace('.', ',') + '%');
        _s('closedOnlyVal', fmtMoney(closed || 0));
        _s('sumLancamentos',fmtMoney(sumReceita || 0));

        // ── Atualiza gráfico de barras ──
        if (window.dreCharts && window.dreCharts.bar) {
          window.dreCharts.bar.data.datasets[0].data = [totalReceita, sumDespesa || 0, Math.max(0, totalResultado)];
          window.dreCharts.bar.update();
        }

        // ── Atualiza gráfico de rosca + legenda ──
        if (window.dreCharts && window.dreCharts.donut) {
          const donutVals = [Math.max(0, closed || 0), Math.max(0, sumReceita || 0)];
          window.dreCharts.donut.data.datasets[0].data = donutVals;
          window.dreCharts.donut.update();
          if (typeof window.dreUpdateDonutLegend === 'function') window.dreUpdateDonutLegend(donutVals);
        }

        // ── Atualiza resumo executivo ──
        const summaryEl = document.getElementById('execSummary');
        if (summaryEl) {
          const periodoTxt = filters.month
            ? ' em <strong>' + filters.month.split('-').reverse().join('/') + '</strong>'
            : ' no período consolidado';
          const healthCls  = margem >= 40 ? '#6ee7b7' : (margem >= 15 ? '#fcd34d' : '#fca5a5');
          summaryEl.innerHTML = 'No período' + periodoTxt + ', o escritório registra <strong>' + fmtMoney(totalReceita) + '</strong> em receita operacional, <strong>' + fmtMoney(sumDespesa || 0) + '</strong> em custos operacionais e <strong>lucro líquido de ' + fmtMoney(totalResultado) + '</strong>, representando margem de <strong style="color:' + healthCls + '">' + margem.toFixed(1).replace('.',',') + '%</strong>. Dos honorários, <strong>' + fmtMoney(closed || 0) + '</strong> vieram de contratos fechados no CRM.';
        }

        // ── Atualiza badge de saúde financeira ──
        const healthBar = document.querySelector('.health-bar');
        if (healthBar) {
          healthBar.className = 'health-bar ' + (margem >= 40 ? 'health-ok' : margem >= 15 ? 'health-warn' : 'health-danger');
          const icon  = margem >= 40 ? '▲' : (margem >= 15 ? '●' : '▼');
          const label = margem >= 40 ? 'Saudável' : (margem >= 15 ? 'Atenção' : 'Crítico');
          healthBar.innerHTML = '<span class="hl">' + icon + '</span><span>Saúde financeira: <strong>' + label + '</strong></span><span style="margin-left:auto;opacity:.7;font-size:.72rem;font-weight:400">Margem atual de ' + margem.toFixed(1).replace('.',',') + '% · Meta recomendada: ≥ 40%</span>';
        }
      })();

      // closed_total from API payload (if present) remains visible
      try{
        if (apiPayload && typeof apiPayload.closed_total !== 'undefined'){
          var wrap = document.getElementById('dreSummary');
          if (wrap && !document.getElementById('closedOnlyRow')){
            var div = document.createElement('div');
            div.id = 'closedOnlyRow';
            div.innerHTML = 'Receita (fechados): <strong id="closedOnlyVal">' + fmtMoney(apiPayload.closed_total || 0) + '</strong>';
            wrap.insertBefore(div, wrap.firstChild);
          } else if (wrap){ var el = document.getElementById('closedOnlyVal'); if (el) el.textContent = fmtMoney(apiPayload.closed_total || 0); }
        }
      }catch(e){ /* ignore */ }
      attachButtons();
    }catch(e){ console.error(e); }
  }

  async function loadCodes(){
    try{
      const res = await fetch(codesApi);
      const j = await res.json();
      const list = j.data || [];
      const mainSel = document.getElementById('codigo');
      const editSel = document.getElementById('edit_codigo');
      const filterSel = document.getElementById('filter_code');
      // populate a helper function to avoid duplication
      function fillSelect(sel){
        if (!sel) return;
        const cur = sel.value;
        sel.innerHTML = '<option value="">Código</option>';
        list.forEach(c => {
          const opt = document.createElement('option');
          const fullText = c.code + (c.descricao ? ' — ' + c.descricao : '');
          opt.value = c.code;
          opt.textContent = c.code;
          opt.title = fullText;
          sel.appendChild(opt);
        });
        if (cur) sel.value = cur;
      }
      fillSelect(mainSel);
      fillSelect(editSel);
      // populate filter select
      if (filterSel){
        const curf = filterSel.value;
        filterSel.innerHTML = '<option value="">Todos os códigos</option>';
        list.forEach(c => { const o = document.createElement('option'); o.value = c.code; o.textContent = c.code + (c.descricao?(' — '+c.descricao):''); o.title = c.descricao||''; filterSel.appendChild(o); });
        if (curf) filterSel.value = curf;
      }
      renderCodesTable(list);
    }catch(e){ console.error(e); }
  }

  function renderCodesTable(list){
    const tbody = document.querySelector('#codesTable tbody');
    if (!tbody) return; tbody.innerHTML = '';
    list.forEach(c => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td style="padding:6px 10px;color:#cde4ff;font-size:.78rem">${c.code}</td><td style="padding:6px 10px;color:var(--muted);font-size:.78rem">${c.descricao||''}</td><td style="padding:6px 10px;text-align:right"><button data-code="${c.code}" data-id="${c.id}" class="btn btn-edit btn-sm editCodeBtn">Editar</button> <button data-id="${c.id}" class="btn btn-del btn-sm delCodeBtn">Excluir</button></td>`;
      tbody.appendChild(tr);
    });
    attachCodesButtons();
  }

  function attachCodesButtons(){
    // delete handlers
    document.querySelectorAll('.delCodeBtn').forEach(b => {
      b.onclick = async function(){
        if (!(await Yuris.confirm('Excluir código?', { danger: true, okLabel: 'Excluir' }))) return;
        const id = this.getAttribute('data-id');
        const res = await fetch(codesApi+'?id='+encodeURIComponent(id), {method:'DELETE', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body: JSON.stringify({id:id, csrf_token: csrf})});
        const j = await res.json(); if (j && j.success) { loadCodes(); }
      }
    });

    // edit handlers: open dedicated popup modal (not browser prompt)
    document.querySelectorAll('.editCodeBtn').forEach(b => {
      b.onclick = async function(){
        const id = this.getAttribute('data-id');
        const row = await fetch(codesApi+'?id='+encodeURIComponent(id)).then(r=>r.json());
        const data = row.data || {};
        document.getElementById('codeEdit_code').value = data.code || '';
        document.getElementById('codeEdit_desc').value = data.descricao || '';
        document.getElementById('codeEditModal').dataset.editId = data.id || id;
        document.getElementById('codeEditModal').style.display = 'flex';
      }
    });
  }

  function renderSummary(s){
    document.getElementById('sumReceita').textContent = fmtMoney(s.receita || 0);
    document.getElementById('sumDespesa').textContent = fmtMoney(s.despesa || 0);
    document.getElementById('sumResultado').textContent = fmtMoney(s.resultado || 0);
  }

  function attachButtons(){
    document.querySelectorAll('.delBtn').forEach(b => {
      b.onclick = async function(){
        if (!(await Yuris.confirm('Excluir conta fixa?', { danger: true, okLabel: 'Excluir' }))) return;
        const id = this.getAttribute('data-id');
        const res = await fetch(api, {method:'DELETE', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body: JSON.stringify({id:id, csrf_token: csrf})});
        const j = await res.json(); if (j && j.success) { load(); }
      }
    });
    document.querySelectorAll('.editBtn').forEach(b => {
      b.onclick = async function(){
        const id = this.getAttribute('data-id');
        const row = await fetch(api+'?id='+encodeURIComponent(id)).then(r=>r.json());
        const r = row.data || {};
        // populate edit modal
        await loadCodes();
        const selCode = document.getElementById('edit_codigo'); if (selCode) selCode.value = r.codigo || '';
        const elRec = document.getElementById('edit_recorrencia'); if (elRec) elRec.value = r.recorrencia || 'fixa';
        const elTipo = document.getElementById('edit_tipo'); if (elTipo) elTipo.value = r.tipo || 'despesa';
        const elNome = document.getElementById('edit_nome'); if (elNome) elNome.value = r.nome || '';
        const elData = document.getElementById('edit_data_referencia'); if (elData) elData.value = (r.data_referencia || '').slice(0,7);
        const elValor = document.getElementById('edit_valor_fixo'); if (elValor) elValor.value = (typeof r.valor_fixo !== 'undefined') ? (Number(r.valor_fixo).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})) : '';
        document.getElementById('accountEditModal').dataset.editId = r.id || '';
          // clear any prior validation message
          const editMsg = document.getElementById('editAccountMsg'); if (editMsg) { hideTempMsg(editMsg); }
          document.getElementById('accountEditModal').style.display = 'flex';
      }
    });
  }

  document.getElementById('addBtn').addEventListener('click', async function(){
    const codigo = document.getElementById('codigo').value.trim();
    // month input returns YYYY-MM; convert to YYYY-MM-01 for DATE column
    const rawMonth = (document.getElementById('data_referencia') || {}).value || '';
    let data_referencia = null;
    if (rawMonth) {
      data_referencia = rawMonth.indexOf('-')===4 ? (rawMonth + '-01') : rawMonth;
    }
    const nome = document.getElementById('nome').value.trim();
    const tipo = document.getElementById('tipo').value;
    const recorrencia = (document.getElementById('recorrencia') || {}).value || 'fixa';
    const rawInput = (document.getElementById('valor_fixo').value || '').toString().trim();
    const normalizedInput = rawInput.replace(/\./g, '').replace(',', '.');
    const valor_fixo = parseFloat(normalizedInput) || 0;
    // validation: require nome, data_referencia and valor_fixo
    const msgEl = document.getElementById('addAccountMsg');
    let missing = [];
    if (!nome) missing.push('descrição');
    if (!rawMonth) missing.push('data (mês)');
    if (!rawInput) missing.push('valor');
    if (missing.length) {
      const txt = 'Preencha: ' + missing.join(', ');
      if (msgEl) { showTempMsg(msgEl, txt); }
      else alert(txt);
      return;
    }
    if (msgEl) { hideTempMsg(msgEl); }
    const payload = { codigo, nome, tipo, recorrencia, valor_fixo, data_referencia, csrf_token: csrf };
    const res = await fetch(api, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body: JSON.stringify(payload)});
    const j = await res.json(); if (j && j.success) {
      document.getElementById('codigo').value = ''; document.getElementById('nome').value = ''; document.getElementById('valor_fixo').value = '';
      const msgEl2 = document.getElementById('addAccountMsg'); if (msgEl2) { hideTempMsg(msgEl2); }
      load();
    } else { alert('Erro ao salvar'); }
  });

  // initial load
  load();

  // wire filter controls
  try{
    const fMonth = document.getElementById('filter_month'); if (fMonth) fMonth.addEventListener('change', () => applyFiltersAndRender());
    const fRec = document.getElementById('filter_recorrencia'); if (fRec) fRec.addEventListener('change', () => applyFiltersAndRender());
    const fCode = document.getElementById('filter_code'); if (fCode) fCode.addEventListener('change', () => applyFiltersAndRender());
    const clearBtn = document.getElementById('clearFiltersBtn'); if (clearBtn) clearBtn.addEventListener('click', () => { if (fMonth) fMonth.value=''; if (fRec) fRec.value='all'; if (fCode) fCode.value=''; applyFiltersAndRender(); });
  }catch(e){ }

  // Codes modal handlers
  document.getElementById('manageCodesBtn').addEventListener('click', function(){ document.getElementById('codesModal').style.display = 'flex'; loadCodes(); });
  document.getElementById('closeCodesModal').addEventListener('click', function(){ document.getElementById('codesModal').style.display = 'none'; });
  document.getElementById('addCodeBtn').addEventListener('click', async function(){
    const code = (document.getElementById('newCode').value||'').trim();
    const desc = (document.getElementById('newCodeDesc').value||'').trim();
    if (!code) { alert('Preencha o código'); return; }
    const addBtn = document.getElementById('addCodeBtn');
    const editId = addBtn.dataset.editId || null;
    if (editId) {
      // update
      const res = await fetch(codesApi, {method:'PUT', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body: JSON.stringify({id: editId, code: code, descricao: desc, csrf_token: csrf})});
      const j = await res.json();
      if (j && j.success) {
        document.getElementById('newCode').value=''; document.getElementById('newCodeDesc').value='';
        delete addBtn.dataset.editId; addBtn.textContent = 'Adicionar'; document.getElementById('cancelEditCodeBtn').style.display = 'none';
        loadCodes();
      } else { alert('Erro ao atualizar código'); }
    } else {
      // create
      const res = await fetch(codesApi, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body: JSON.stringify({code:code, descricao:desc, csrf_token: csrf})});
      const j = await res.json();
      if (j && j.success) { document.getElementById('newCode').value=''; document.getElementById('newCodeDesc').value=''; loadCodes(); } else { alert('Erro ao salvar código'); }
    }
  });

  // cancel edit button
  document.getElementById('cancelEditCodeBtn').addEventListener('click', function(){
    const addBtn = document.getElementById('addCodeBtn');
    document.getElementById('newCode').value=''; document.getElementById('newCodeDesc').value='';
    delete addBtn.dataset.editId; addBtn.textContent = 'Adicionar'; this.style.display = 'none';
  });

  // Code edit popup handlers (open from codes list)
  document.getElementById('closeCodeEditModal').addEventListener('click', function(){ document.getElementById('codeEditModal').style.display = 'none'; });
  document.getElementById('cancelCodeEditBtn').addEventListener('click', function(){ document.getElementById('codeEditModal').style.display = 'none'; });
  document.getElementById('saveCodeBtn').addEventListener('click', async function(){
    const id = document.getElementById('codeEditModal').dataset.editId || null;
    if (!id) return alert('ID ausente');
    const code = (document.getElementById('codeEdit_code')||{}).value.trim();
    const desc = (document.getElementById('codeEdit_desc')||{}).value.trim();
    if (!code) return alert('Preencha o código');
    const res = await fetch(codesApi, {method:'PUT', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body: JSON.stringify({id: id, code: code, descricao: desc, csrf_token: csrf})});
    const j = await res.json(); if (j && j.success) { document.getElementById('codeEditModal').style.display = 'none'; loadCodes(); } else { alert('Erro ao salvar código'); }
  });

  // Account edit modal handlers
  document.getElementById('closeAccountModal').addEventListener('click', function(){ document.getElementById('accountEditModal').style.display = 'none'; });
  document.getElementById('saveAccountBtn').addEventListener('click', async function(){
    const id = document.getElementById('accountEditModal').dataset.editId || null;
    if (!id) return alert('ID ausente');
    const codigo = (document.getElementById('edit_codigo')||{}).value || '';
    const nome = (document.getElementById('edit_nome')||{}).value || '';
    const tipo = (document.getElementById('edit_tipo')||{}).value || 'despesa';
    const recorrencia = (document.getElementById('edit_recorrencia')||{}).value || 'fixa';
    const raw = (document.getElementById('edit_valor_fixo')||{}).value || '';
    const normalized = raw.toString().replace(/\./g,'').replace(',','.');
    const valor_fixo = parseFloat(normalized) || 0;
    const rawMonth = (document.getElementById('edit_data_referencia')||{}).value || '';
    const data_referencia = rawMonth ? (rawMonth.indexOf('-')===4 ? (rawMonth + '-01') : rawMonth) : null;
    // validation: require data_referencia and valor
    const editMsg = document.getElementById('editAccountMsg');
    let missing = [];
    if (!rawMonth) missing.push('data (mês)');
    if (!raw) missing.push('valor');
    if (missing.length) {
      const txt = 'Preencha: ' + missing.join(', ');
      if (editMsg) { showTempMsg(editMsg, txt); }
      else alert(txt);
      return;
    }
    if (editMsg) { hideTempMsg(editMsg); }

    const payload = { id: id, codigo, nome, tipo, recorrencia, valor_fixo, data_referencia, csrf_token: csrf };
    const res = await fetch(api, {method:'PUT', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body: JSON.stringify(payload)});
    const j = await res.json(); if (j && j.success) { document.getElementById('accountEditModal').style.display = 'none'; load(); }
  });

  // page-specific last-update timestamp (do not mirror dashboard)
  try{
    var statusEl = document.getElementById('dashboardStatus');
    if (statusEl) statusEl.textContent = 'Última atualização: ' + new Date().toLocaleString();
  }catch(e){}

})();
