const _projCanvas = document.getElementById('projectionChart');
const ctx = _projCanvas ? _projCanvas.getContext('2d') : null;

// On pages without an active dashboard load, mirror the last saved timestamp
try{
  const saved = localStorage.getItem('dashboard_last_update');
  if (saved) {
    const el = document.getElementById('dashboardStatus');
    if (el) { el.textContent = saved; el.style.color = '#4ade80'; }
  }
} catch(e) { /* ignore when localStorage not available */ }
const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

// Aceita valores numéricos em formato pt-BR (ponto como milhar, vírgula como decimal) ou en-US
function parseNumberFlexible(v){
  if (v === null || v === undefined) return 0;
  if (typeof v === 'number') return v;
  let s = String(v).trim();
  if (s === '') return 0;
  s = s.replace(/[^0-9,\.\-]/g, '');
  const hasDot = s.indexOf('.') !== -1;
  const hasComma = s.indexOf(',') !== -1;
  if (hasDot && hasComma) {
    // assume dot thousands, comma decimal
    s = s.replace(/\./g, '').replace(/,/g, '.');
  } else if (hasComma && !hasDot) {
    s = s.replace(/,/g, '.');
  }
  const n = parseFloat(s);
  return isNaN(n) ? 0 : n;
}

// Busca todos os cards do CRM — usado como fallback quando a API do dashboard não retorna closed_by_month
async function fetchAllCards(){
  try{
    const res = await fetch('/sistema_vendas/public/api/cards.php', { credentials: 'same-origin' });
    const j = await res.json();
    console.log('API dashboard response', j);
    return j && j.data ? j.data : [];
  } catch(e){ console.warn('fetchAllCards err', e); return []; }
}

// Agrupa valor_fechado_final por mês a partir dos cards — fallback quando a API não retorna closed_by_month
function groupClosedByMonthFromCards(cards){
  const map = {};
  cards.forEach(c => {
    if (!c) return;
    const df = c.data_fechamento || c.data_fechamento_final || c.data_fechamento;
    if (!df) return;
    if (String(df).indexOf('0000-00-00') !== -1) return;
    // accept datetime or date; ensure format YYYY-MM-DD
    const period = String(df).substr(0,7);
    const rawVal = c.valor_fechado_final || c.valor_proposta || c.valor_estimado || c.valor_fechado || 0;
    const val = parseNumberFlexible(rawVal);
    map[period] = (map[period] || 0) + val;
  });
  return Object.keys(map).sort().map(k => ({ period: k, total: map[k], count: 0 }));
}

function periodToDate(period){
  // period expected 'YYYY-MM' -> return Date(year, monthIndex, 1)
  if (!period || String(period).length < 7) return new Date();
  const parts = String(period).split('-');
  const y = parseInt(parts[0],10); const m = parseInt(parts[1],10);
  if (isNaN(y) || isNaN(m)) return new Date();
  return new Date(y, m-1, 1);
}

async function loadDashboard(start, end){
  try{
    const statusEl = document.getElementById('dashboardStatus');
    if (statusEl) { statusEl.textContent = 'Carregando dados...'; statusEl.style.color = '#6B7887'; }
    let url = '/sistema_vendas/public/api/dashboard.php';
    if (start || end) {
      const qs = new URLSearchParams();
      if (start) qs.set('start', start);
      if (end) qs.set('end', end);
      url += '?' + qs.toString();
    }
    const res = await fetch(url, { credentials: 'same-origin' });
    const j = await res.json();
    if (j.error) throw new Error(j.error || 'API error');

    let closed = j.closed_by_month || [];
    let forecast = j.forecast_by_month || [];
    let byColumn = j.by_column || [];
    // goals are isolated manual parameters; the meta card shows the selected month's value
    let goals = j.goals_by_month || [];

    // If the API didn't return closed_by_month (or it's empty), try a fallback
    // by fetching all cards and grouping `valor_fechado_final` by `data_fechamento`.
    if ((!closed || closed.length === 0) ) {
      try{
        const allCards = await fetchAllCards();
        const computed = groupClosedByMonthFromCards(allCards);
        if (computed && computed.length) {
          closed = computed;
          console.log('Using computed closed_by_month from cards (fallback)', computed);
        }
      } catch(e){ console.warn('fallback compute closed err', e); }
    }

    // build a unified set of periods (from closed and forecast only)
    const periodsSet = new Set();
    closed.forEach(r=> r.period && periodsSet.add(r.period));
    forecast.forEach(r=> r.period && periodsSet.add(r.period));
    let periods = Array.from(periodsSet).sort();
    // If the earliest period is later than January of that same year and there
    // are no periods from previous years, prepend months from January of the
    // earliest year up to the first recorded month so the X-axis starts at the
    // year's first month (e.g. data begins in 2026-04 -> start at 2026-01).
    if (periods.length) {
      try {
        const first = periods[0];
        const d0 = periodToDate(first);
        const y0 = d0.getFullYear();
        const m0 = d0.getMonth() + 1;
        const hasPrevYear = periods.some(p => { const py = parseInt(String(p).split('-')[0],10); return py < y0; });
        if (m0 > 1 && !hasPrevYear) {
          const prepend = [];
          for (let mm = 1; mm < m0; mm++) prepend.push(y0 + '-' + String(mm).padStart(2,'0'));
          periods = prepend.concat(periods);
        }
      } catch(e) { /* ignore parsing errors and leave periods as-is */ }
    }
    // keep last loaded data globally so other handlers can re-render charts
    window._lastPeriods = periods;
    // remember current displayed period (use last period as the most recent shown)
    window._currentDashboardPeriod = periods.length ? periods[periods.length-1] : null;
    // store the loaded date range so other helpers know if a range was applied
    window._dashboardRange = { start: start || null, end: end || null };

    const labels = periods.map(p => {
      const d = periodToDate(p);
      return d.toLocaleString('pt-BR',{month:'short', year:'numeric'});
    });

    const closedMap = Object.fromEntries(closed.map(r=>[r.period, parseNumberFlexible(r.total)]));
    const forecastMap = Object.fromEntries(forecast.map(r=>[r.period, parseNumberFlexible(r.total)]));
    const sumClosedMap = Object.values(closedMap).reduce((s,v)=>s+Number(v||0),0);
    console.log('closedMap', closedMap, 'sumClosedMap', sumClosedMap);
    // also log the arrays used by the chart
    console.log('periods', periods, 'closedData (mapped)', closed.map(r=>({period:r.period,total:r.total}))); 
    try{
      const allCardsDebug = await fetchAllCards();
      const totalFromCards = allCardsDebug.reduce((s,c)=> s + parseNumberFlexible(c.valor_fechado_final || c.valor_fechado || 0), 0);
      console.log('totalFromCards (sum valor_fechado_final from /api/cards.php)', totalFromCards, 'cardsCount', allCardsDebug.length);
    } catch(e){ console.warn('cards debug fetch err', e); }

    const closedData = periods.map(p => closedMap[p] || 0);
    const forecastData = periods.map(p => forecastMap[p] || 0);
    window._lastClosedData = closedData;
    window._lastForecastData = forecastData;

    // update summary cards (goal card will be set by meta widget separately)
    const totalClosed = closedData.reduce((s,n)=>s+n,0);
    const totalForecast = forecastData.reduce((s,n)=>s+n,0);
    // prefer API-provided summary when no explicit date range is applied.
    const summary = j.summary || {};
    const rangeApplied = Boolean(start || end);
    const displayClosed = rangeApplied ? totalClosed : ((typeof summary.total_closed_amount !== 'undefined' && Number(summary.total_closed_amount) > 0) ? Number(summary.total_closed_amount) : totalClosed);
    const closedEl = document.getElementById('closedValue'); if (closedEl) closedEl.textContent = currency.format(displayClosed);
    // projection: if range applied use computed forecast total, else prefer API summary
    const displayProjection = rangeApplied ? totalForecast : ((typeof summary.total_projection_amount !== 'undefined' && Number(summary.total_projection_amount) > 0) ? Number(summary.total_projection_amount) : totalForecast);
    const projEl = document.getElementById('projectionValue'); if (projEl) projEl.textContent = currency.format(displayProjection);
    // opportunities: if range applied compute from columns, else prefer API-provided count
    // Oportunidades: prefer summary value when provided (cards without data_fechamento and not in 'perdido')
    const totalCards = (typeof summary.total_opportunities_count !== 'undefined') ? Number(summary.total_opportunities_count) : (rangeApplied ? byColumn.reduce((s,row)=>s + Number(row.count||0), 0) : byColumn.reduce((s,row)=>s + Number(row.count||0), 0));
    const oppEl = document.getElementById('opportunitiesValue'); if (oppEl) oppEl.textContent = totalCards;
    // Clientes Ativos: use total_closed_count (cards with data_fechamento)
    if (typeof summary.total_closed_count !== 'undefined') { const clientsEl = document.getElementById('clientsActiveValue'); if (clientsEl) clientsEl.textContent = Number(summary.total_closed_count); }

    // (projection chart will be rendered after we compute aggregated values below)
    // render diff chart separately (Meta vs Fechados)
    // prepare goalsData aligned to periods (use goals_by_month returned from API)
    const goalsMap = Object.fromEntries(goals.map(r=>[r.period, Number(r.total)]));
    const goalsData = periods.map(p => goalsMap[p] || 0);

    // For the diff chart we only want a single aggregated point: either the
    // current/latest period (default) or the user-selected month/range.
    // Derive aggregated values and pass a single-point arrays to renderDiffChart.
    function periodFromDateString(ds){ if (!ds) return null; return ds.substr(0,7); }
    const startPeriod = periodFromDateString(start);
    const endPeriod = periodFromDateString(end);

    let aggLabels = [];
    let aggClosed = [];
    let aggGoals = [];
    let aggForecast = [];

    // For the diff chart, use the same value shown in the top "Fechados" card
    const closedValForDiff = Number(displayClosed || 0);
    // single meta (tipo=single, referencia_mes=NULL) — fallback when no month-specific goal exists
    const singleMeta = Number(j.meta_value || 0);
    if (!start && !end) {
      // default: show only the most recent period labelled as 'Atual'
      const p = window._currentDashboardPeriod;
      aggLabels = ['Atual'];
      aggClosed = [ closedValForDiff ];
      aggGoals = [ goalsData[periods.indexOf(p)] || singleMeta ];
      aggForecast = [ forecastData[periods.indexOf(p)] || 0 ];
    } else if (startPeriod && endPeriod && startPeriod === endPeriod) {
      // single month selected: show that month
      const p = startPeriod;
      aggLabels = [p];
      aggClosed = [ closedValForDiff ];
      aggGoals = [ goalsData[periods.indexOf(p)] || singleMeta ];
      aggForecast = [ forecastData[periods.indexOf(p)] || 0 ];
    } else {
      // range selected: aggregate across available periods within range
      const s = startPeriod || periods[0];
      const e = endPeriod || periods[periods.length-1];
      let sumGoals = 0;
      let sumForecast = 0;
      periods.forEach((p,i)=>{
        if (p >= s && p <= e) { sumGoals += (goalsData[i]||0); }
      });
      periods.forEach((p,i)=>{ if (p >= s && p <= e) sumForecast += (forecastData[i]||0); });
      aggLabels = [ (s === e) ? s : (s + ' — ' + e) ];
      // for the diff chart we still want the top card's closed value
      aggClosed = [ closedValForDiff ];
      aggGoals = [ sumGoals || singleMeta ];
      aggForecast = [ sumForecast ];
    }

    renderDiffChart(aggLabels, aggClosed, aggGoals);

    // render projection chart using aggregated values (single point)
    try{
      // use the same values shown in the top cards so chart bars match
      const closedVal = typeof displayClosed !== 'undefined' ? Number(displayClosed) : (aggClosed && aggClosed.length ? Number(aggClosed[0]) : 0);
      const projVal = typeof displayProjection !== 'undefined' ? Number(displayProjection) : (aggForecast && aggForecast.length ? Number(aggForecast[0]) : 0);
      const projData = {
        labels: aggLabels,
        datasets: [
          { label: 'Fechados', data: [closedVal], backgroundColor: '#1A3A5C', borderColor: '#244E7A', yAxisID: 'y', borderRadius: 2, borderSkipped: false },
          { label: 'Projeção', data: [projVal], backgroundColor: '#3D6A96', borderColor: '#4A7AAA', yAxisID: 'y', borderRadius: 2, borderSkipped: false }
        ]
      };
      const projCfg = {
        type: 'bar',
        data: projData,
        options: {
          responsive: true,
          interaction: { mode: 'index', intersect: false },
          animation: { duration: 700, easing: 'easeOutQuart' },
          scales: { x: { ticks: { color: '#6B7887' } }, y: { beginAtZero: true, ticks: { callback: v => currency.format(v), color: '#6B7887' } } },
          plugins: { legend: { labels: { color: '#A8BDD4' } }, tooltip: { callbacks: { label: ctx => currency.format(ctx.parsed.y) } } }
        }
      };
      if (ctx) {
        if (window._projChart) try{ window._projChart.destroy(); } catch(e){}
        window._projChart = new Chart(ctx, projCfg);
      }
    } catch(e){ console.error('proj chart err', e); }

    // determine meta value to use for the revenue chart:
    // prefer month-specific goal when a single month is selected or when current period has a goal,
    // otherwise fall back to the global meta_value returned by the API
    const globalMeta = typeof j.meta_value !== 'undefined' ? Number(j.meta_value) : 0;
    let displayMeta = globalMeta;
    try{
      if (window._dashboardRange && window._dashboardRange.start && window._dashboardRange.end) {
        // single-month range -> prefer that month's goal if available
        if (startPeriod && endPeriod && startPeriod === endPeriod) displayMeta = (goalsMap[startPeriod] || globalMeta);
      } else {
        // no range applied -> prefer goal for current dashboard period if present
        const cp = window._currentDashboardPeriod;
        if (cp && goalsMap[cp]) displayMeta = goalsMap[cp];
      }
    } catch(e){ displayMeta = globalMeta; }
    renderRevenueChart(periods, closedData, displayMeta);
    // render funnel for distribution by column
    window._lastByColumn = byColumn || [];
    rerenderFunnelAligned();

    if (statusEl) {
      const ts = 'Última atualização: ' + new Date().toLocaleString('pt-BR');
      statusEl.textContent = ts; statusEl.style.color = '#7ABDA0';
      try{ localStorage.setItem('dashboard_last_update', ts); } catch(e){}
    }
  } catch(err){
    console.error('dashboard load error', err);
    const statusEl = document.getElementById('dashboardStatus');
    if (statusEl) { statusEl.textContent = 'Erro ao carregar dados'; statusEl.style.color = '#B06070'; }
  }
}

// sync last-update across open tabs/windows so other pages mirror the timestamp instantly
window.addEventListener('storage', function(e){
  if (!e || e.key !== 'dashboard_last_update') return;
  const el = document.getElementById('dashboardStatus'); if (el) { el.textContent = e.newValue; el.style.color = '#4ade80'; }
});

function monthToRange(monthStr){
  // monthStr like '2026-04' -> start '2026-04-01', end last day
  if (!monthStr) return null;
  const parts = monthStr.split('-');
  if (parts.length !== 2) return null;
  const y = parseInt(parts[0],10); const m = parseInt(parts[1],10);
  const start = new Date(y, m-1, 1);
  const end = new Date(y, m, 0); // last day of month
  const pad = n => String(n).padStart(2,'0');
  return { start: `${start.getFullYear()}-${pad(start.getMonth()+1)}-${pad(start.getDate())}`, end: `${end.getFullYear()}-${pad(end.getMonth()+1)}-${pad(end.getDate())}` };
}

// Persiste o período selecionado na sessão do servidor e recarrega o dashboard
async function saveAndLoad(start, end){
  try{
    await fetch('/sistema_vendas/public/api/dashboard_settings.php', {method:'POST', credentials: 'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({start: start || '', end: end || ''})});
  } catch(err){ console.error('save settings err', err); }
  await loadDashboard(start || null, end || null);
  updateMetaWidgetForSelectedMonth();
  window.dispatchEvent(new CustomEvent('dashboard:rangeChanged', { detail: { start: start || null, end: end || null } }));
}

const _applyRangeBtn = document.getElementById('applyRange');
if (_applyRangeBtn) {
  _applyRangeBtn.addEventListener('click', async function(){
    const sEl = document.getElementById('startMonth');
    const eEl = document.getElementById('endMonth');
    const s = sEl ? sEl.value : null;
    const e = eEl ? eEl.value : null;
    let start=null,end=null;
    // Guard: monthToRange returns null when the input is empty/invalid
    if (s) { const r = monthToRange(s); if (r) start = r.start; }
    if (e) { const r = monthToRange(e); if (r) end = r.end; }
    await saveAndLoad(start,end);
  });
}

function pad2(n){ return String(n).padStart(2,'0'); }

// Aplica o filtro rápido de últimos N meses calculando o primeiro dia do mês inicial e o último do mês atual
function applyQuickMonths(months){
  const now = new Date();
  const endMonthDate = now;
  const startMonthDate = new Date(now.getFullYear(), now.getMonth() - (months-1), 1);
  const startMonthStr = startMonthDate.getFullYear() + '-' + pad2(startMonthDate.getMonth()+1);
  const endMonthStr = endMonthDate.getFullYear() + '-' + pad2(endMonthDate.getMonth()+1);
  const _startEl = document.getElementById('startMonth'); if (_startEl) _startEl.value = startMonthStr;
  const _endEl = document.getElementById('endMonth'); if (_endEl) _endEl.value = endMonthStr;
  // Guard: monthToRange pode retornar null se a string estiver malformada
  const _rs = monthToRange(startMonthStr); const _re = monthToRange(endMonthStr);
  if (!_rs || !_re) return;
  const start = _rs.start;
  const end = _re.end;
  saveAndLoad(start,end);
}

const _quick3 = document.getElementById('quick3'); if (_quick3) _quick3.addEventListener('click', ()=> applyQuickMonths(3));
const _quick6 = document.getElementById('quick6'); if (_quick6) _quick6.addEventListener('click', ()=> applyQuickMonths(6));
const _quick12 = document.getElementById('quick12'); if (_quick12) _quick12.addEventListener('click', ()=> applyQuickMonths(12));
const _clearRange = document.getElementById('clearRange');
if (_clearRange) {
  _clearRange.addEventListener('click', async ()=>{
    const sEl = document.getElementById('startMonth'); if (sEl) sEl.value = '';
    const eEl = document.getElementById('endMonth'); if (eEl) eEl.value = '';
    await saveAndLoad('','');
  });
}

// on load, fetch current settings to prefill selectors and load dashboard
(async function(){
  try{
    const res = await fetch('/sistema_vendas/public/api/dashboard_settings.php', { credentials: 'same-origin' });
    const j = await res.json();
    if (j.start) {
      const d = new Date(j.start);
      const mm = String(d.getMonth()+1).padStart(2,'0');
      const _sEl = document.getElementById('startMonth'); if (_sEl) _sEl.value = d.getFullYear() + '-' + mm;
    }
    if (j.end) {
      const d = new Date(j.end);
      const mm = String(d.getMonth()+1).padStart(2,'0');
      const _eEl = document.getElementById('endMonth'); if (_eEl) _eEl.value = d.getFullYear() + '-' + mm;
    }
    await loadDashboard(j.start || null, j.end || null);
    updateMetaWidgetForSelectedMonth();
    window.dispatchEvent(new CustomEvent('dashboard:rangeChanged', { detail: { start: j.start || null, end: j.end || null } }));
  } catch(err){
    console.error('load settings err', err);
    loadDashboard();
    window.dispatchEvent(new CustomEvent('dashboard:rangeChanged', { detail: { start: null, end: null } }));
  }
})();

// Meta widget helpers: fetch single month meta and edit it (no history)
function fmtCurrency(n){ return currency.format(Number(n||0)); }

// Busca a meta global do usuário (tipo single, sem filtro de período)
async function getCurrentGoal(){
  try{
    const res = await fetch('/sistema_vendas/public/api/goals.php?_=' + Date.now(), { credentials: 'same-origin' });
    const arr = await res.json();
    return (arr && arr.length) ? arr[0] : null;
  } catch(err){ return null; }
}

// Converte string digitada pelo usuário (pt-BR ou en-US) para número float
function parseNumberInput(s){
  if (s === null || s === undefined) return 0;
  s = String(s).trim();
  if (!s) return 0;
  s = s.replace(/\s+/g,'');
  if (s.indexOf('.')>-1 && s.indexOf(',')>-1){
    s = s.replace(/\./g,'');
    s = s.replace(/,/g,'.');
  } else if (s.indexOf(',')>-1){
    s = s.replace(/,/g,'.');
  }
  const n = Number(s);
  return isNaN(n) ? 0 : n;
}

// Atualiza o widget de meta com o valor do mês em exibição; usa token para ignorar respostas desatualizadas (race condition)
async function updateMetaWidgetForSelectedMonth(){
  const labelEl = document.getElementById('metaStaticLabel');
  const valEl = document.getElementById('metaMonthValue');
  const topEl = document.getElementById('metaTopValue');
  // if none of the meta/widget elements exist on the page, skip this logic
  if (!labelEl && !valEl && !topEl && !document.getElementById('goalValue') && !document.getElementById('goalId')) return 0;
  if (labelEl) labelEl.textContent = 'Meta';
  if (valEl) valEl.textContent = 'R$ 0,00';
  if (topEl) topEl.textContent = 'R$ 0,00';
  // set a request token so stale responses don't overwrite newer ones
  const token = Date.now() + Math.random();
  window._lastGoalRequest = token;
  const g = await getCurrentGoal();
  if (window._lastGoalRequest !== token) return;
  const goalCard = document.getElementById('goalValue');
  let numericMeta = 0;
  if (g) {
    numericMeta = Number(g.valor_meta || 0);
    valEl.textContent = fmtCurrency(numericMeta);
    if (topEl) topEl.textContent = fmtCurrency(numericMeta);
    if (goalCard) goalCard.textContent = fmtCurrency(numericMeta);
    // store id in modal hidden if present
    const gid = g.id || '';
    const gidEl = document.getElementById('goalId'); if (gidEl) gidEl.value = gid;
  } else {
    if (goalCard) goalCard.textContent = fmtCurrency(0);
    const gidEl = document.getElementById('goalId'); if (gidEl) gidEl.value = '';
    if (topEl) topEl.textContent = fmtCurrency(0);
  }
  // if we have last loaded chart data, re-render revenue chart using the (possibly) new meta
  try{
    if (typeof window._lastPeriods !== 'undefined' && typeof window._lastClosedData !== 'undefined') {
      renderRevenueChart(window._lastPeriods, window._lastClosedData, numericMeta);
    }
  } catch(e){ console.warn('re-render revenue chart after meta update err', e); }
  return numericMeta;
}

// Helpers to hide/restore chart elements and to lower/restore other body children
// Oculta os canvas dos gráficos enquanto o modal de meta está aberto (evita sobreposição de z-index no Chart.js)
function hideCharts(){
  const ids = ['projectionChart','diffChart','revenueChart','funnelChart'];
  ids.forEach(id=>{
    const el = document.getElementById(id);
    if (!el) return;
    if (typeof el.dataset._prevVisibility === 'undefined') el.dataset._prevVisibility = el.style.visibility || '';
    if (typeof el.dataset._prevPointer === 'undefined') el.dataset._prevPointer = el.style.pointerEvents || '';
    el.style.visibility = 'hidden';
    el.style.pointerEvents = 'none';
  });
}
function restoreCharts(){
  const ids = ['projectionChart','diffChart','revenueChart','funnelChart'];
  ids.forEach(id=>{
    const el = document.getElementById(id);
    if (!el) return;
    if (typeof el.dataset._prevVisibility !== 'undefined') el.style.visibility = el.dataset._prevVisibility || '';
    if (typeof el.dataset._prevPointer !== 'undefined') el.style.pointerEvents = el.dataset._prevPointer || '';
    delete el.dataset._prevVisibility; delete el.dataset._prevPointer;
  });
}
// Reduz z-index de todos os elementos filhos do body (exceto o modal) para garantir que o modal fique na frente
function lowerBodySiblings(modalEl){
  try{
    window.__modalSiblingState = [];
    Array.from(document.body.children).forEach(ch => {
      if (ch === modalEl) return;
      window.__modalSiblingState.push({el: ch, z: ch.style.zIndex || '', p: ch.style.position || '', o: ch.style.overflow || ''});
      try{ ch.style.zIndex = '0'; }catch(e){}
    });
  }catch(e){}
}
function restoreBodySiblings(){
  try{
    if (!window.__modalSiblingState) return;
    window.__modalSiblingState.forEach(s => {
      try{ s.el.style.zIndex = s.z; s.el.style.position = s.p; s.el.style.overflow = s.o; }catch(e){}
    });
    window.__modalSiblingState = null;
  }catch(e){}
}

// Fecha o modal de meta, restaura os gráficos e remove o elemento dinâmico do DOM
function closeGoalModal(){
  try{
    const gm = document.getElementById('goalModal');
    if (gm) {
      gm.classList.add('hidden');
      gm.style.display = 'none';
      try{ gm.remove(); }catch(e){}
    }
  }catch(e){}
  try{ restoreCharts(); }catch(e){}
  try{ restoreBodySiblings(); }catch(e){}
  try{ document.body.classList.remove('modal-open'); }catch(e){}
}

// Fallback event delegation: ensure cancel and submit work even if listeners
// failed to attach on the dynamic modal
document.addEventListener('click', function(e){
  try{
    const btn = e.target.closest && e.target.closest('#cancelGoal');
    if (btn) { e.preventDefault(); closeGoalModal(); }
  }catch(err){}
});
document.addEventListener('submit', function(e){
  try{
    if (e.target && e.target.id === 'goalForm') { e.preventDefault(); submitGoalForm(e); }
  }catch(err){}
});
// close on ESC
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { try{ closeGoalModal(); }catch(err){} } });

// Cria o modal de meta dinamicamente no body (singleton — não cria duplicatas)
function createGoalModal(){
  if (document.getElementById('goalModal')) return;
  const div = document.createElement('div');
  div.id = 'goalModal';
  div.className = 'fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center hidden';
  // use a very large z-index to avoid stacking issues with chart containers
  div.style.zIndex = '2147483647';
  div.innerHTML = `
    <div class="bg-white p-4 rounded shadow w-96" style="position:relative;z-index:2147483648">
      <h4 class="font-semibold mb-2">Meta Mensal</h4>
      <form id="goalForm" class="space-y-2">
        <input type="hidden" name="id" id="goalId">
        <div>
          <label class="text-sm">Valor (R$)</label>
          <input id="goalInput" type="text" inputmode="decimal" placeholder="0,00" class="w-full border p-1 rounded" required>
        </div>
        <div class="flex justify-end gap-2 mt-3">
          <button type="button" id="cancelGoal" class="px-3 py-1 bg-blue-500 text-white rounded">Cancelar</button>
          <button type="submit" class="px-3 py-1 bg-blue-800 text-white rounded">Salvar</button>
        </div>
      </form>
    </div>`;
  // append to the body so we can reliably manage other body children z-index
  document.body.appendChild(div);
  // ensure the overlay uses fixed positioning relative to the viewport
  div.style.position = 'fixed';
  div.style.top = '0'; div.style.left = '0'; div.style.right = '0'; div.style.bottom = '0';
  // cancel handler: close modal and restore charts / siblings
  document.getElementById('cancelGoal').addEventListener('click', closeGoalModal);
  document.getElementById('goalForm').addEventListener('submit', submitGoalForm);
}

// Preenche e exibe o modal de meta, ocultando os gráficos para evitar sobreposição visual
function openGoalModal(data){
  createGoalModal();
  const modal = document.getElementById('goalModal');
  document.getElementById('goalId').value = data && data.id ? data.id : '';
  document.getElementById('goalInput').value = data && data.valor_meta ? data.valor_meta : '';
  modal.classList.remove('hidden');
  try{ hideCharts(); }catch(e){}
  try{ lowerBodySiblings(modal); }catch(e){}
  try{ document.body.classList.add('modal-open'); }catch(e){}
}

async function submitGoalForm(e){
  e.preventDefault();
  const id = document.getElementById('goalId').value;
  const val = document.getElementById('goalInput').value;
  try{
    const parsedVal = parseNumberInput(val);
    if (parsedVal < 0) return alert('Valor deve ser >= 0');
    let res, jr;
    // sempre POST — backend faz upsert no registro mais recente do usuário
    res = await fetch('/sistema_vendas/public/api/goals.php', {method:'POST', credentials: 'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({valor_meta: parsedVal})});
    jr = await res.json();
    console.log('save goal response', res.status, jr);
    let savedSuccessfully = false;
    if (!res.ok || jr.error) {
      alert('Erro ao salvar meta: ' + (jr.error || res.status));
      savedSuccessfully = false;
    } else {
      savedSuccessfully = true;
      // update widget immediately using returned goal
      if (jr && jr.goal) {
        const g = jr.goal;
        const valEl = document.getElementById('metaMonthValue');
        const topEl = document.getElementById('metaTopValue');
        // Guard: valEl pode não existir em páginas sem o widget de meta
        if (valEl && typeof g.valor_meta !== 'undefined') valEl.textContent = fmtCurrency(g.valor_meta);
        if (topEl) topEl.textContent = fmtCurrency(g.valor_meta);
        const goalCard = document.getElementById('goalValue'); if (goalCard) goalCard.textContent = fmtCurrency(g.valor_meta);
      }
    }
    // close modal and restore visuals (will update charts below if saved)
    try{ closeGoalModal(); }catch(e){}
    if (savedSuccessfully) {
      // update meta widget immediately
      await updateMetaWidgetForSelectedMonth();
      // update only the 'Meta' dataset in the diff chart so projection stays unchanged
      try{
        const fresh = await getCurrentGoal();
        const newVal = (fresh && typeof fresh.valor_meta !== 'undefined') ? Number(fresh.valor_meta) : Number(parsedVal || 0);
        console.log('Updating diff chart Meta value to', newVal, 'fresh:', fresh, 'jr:', jr);
        if (window._diffChart && window._diffChart.data && Array.isArray(window._diffChart.data.datasets)) {
          const metaDs = window._diffChart.data.datasets.find(ds => ds.label === 'Meta') || window._diffChart.data.datasets[0];
          if (metaDs) {
            if (Array.isArray(metaDs.data) && metaDs.data.length > 0) metaDs.data[0] = newVal;
            else metaDs.data = [newVal];
          }
          try{ window._diffChart.update(); } catch(e){ console.error('diffChart update err', e); }
        }
      } catch(e){ console.error('update diff chart err', e); }
    }
  } catch(err){ console.error('save goal err', err); alert('Erro ao salvar meta'); }
}

// Relê os inputs de período atuais e recarrega o dashboard sem persistir (uso interno)
async function reloadDashboardWithInputs(){
  const sEl = document.getElementById('startMonth');
  const eEl = document.getElementById('endMonth');
  const s = sEl ? sEl.value : null;
  const e = eEl ? eEl.value : null;
  let start=null,end=null;
  if (s) start = monthToRange(s).start;
  if (e) end = monthToRange(e).end;
  await loadDashboard(start,end);
  await updateMetaWidgetForSelectedMonth();
}

const _editMonthMeta = document.getElementById('editMonthMeta');
if (_editMonthMeta) {
  _editMonthMeta.addEventListener('click', async ()=>{
    const g = await getCurrentGoal();
    openGoalModal(g || {valor_meta: 0});
  });
}

// large meta card removed; no extra edit button to wire

// initial meta widget fill
updateMetaWidgetForSelectedMonth();

// create/update diff chart after projection chart is rendered
async function renderDiffChart(periods, closedData, goalsData){
  try{
    const diffEl = document.getElementById('diffChart');
    if (!diffEl) return; // page doesn't include this chart
    const dctx = diffEl.getContext('2d');
    const labels = periods.map(p => {
      try {
        // if no dashboard range is applied, treat the current period as 'Atual'
        if (window._dashboardRange && !window._dashboardRange.start && !window._dashboardRange.end) {
          if (String(p) === String(window._currentDashboardPeriod) || String(p).toLowerCase() === 'atual') return 'Atual';
        }
        if (String(p).indexOf(' — ') > -1) {
          const parts = String(p).split(' — ');
          const d1 = periodToDate(parts[0]);
          const d2 = periodToDate(parts[1]);
          if (!isNaN(d1) && !isNaN(d2)) return d1.toLocaleString('pt-BR',{month:'short', year:'numeric'}) + ' — ' + d2.toLocaleString('pt-BR',{month:'short', year:'numeric'});
          return String(p);
        }
        // if p looks like YYYY-MM
        const d = periodToDate(String(p));
        if (!isNaN(d)) return d.toLocaleString('pt-BR',{month:'short', year:'numeric'});
        return String(p);
      } catch(e){ return String(p); }
    });

    const cfg = {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          { label: 'Meta', data: goalsData, backgroundColor: '#3D6A96', yAxisID: 'y', borderRadius: 2, borderSkipped: false },
          { label: 'Fechados', data: closedData, backgroundColor: '#1A3A5C', yAxisID: 'y', borderRadius: 2, borderSkipped: false }
        ]
      },
      options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        animation: { duration: 700, easing: 'easeOutQuart' },
        scales: { x: { ticks: { color: '#e6f6ff' } }, y: { beginAtZero: true, ticks: { callback: v => currency.format(v), color: '#e6f6ff' } } },
        plugins: { legend: { labels: { color: '#e6f6ff' } }, tooltip: { callbacks: { label: function(ctx){ return ctx.dataset.label + ': ' + currency.format(ctx.parsed.y); } } } }
      }
    };
    if (window._diffChart) try{ window._diffChart.destroy(); } catch(e){}
    window._diffChart = new Chart(dctx, cfg);
  } catch(err){ console.error('diff chart err', err); }
}

// Iguala a altura do painel do funil ao painel de projeção para alinhamento visual responsivo
function syncFunnelHeightToProjectionPanel(){
  try{
    const projectionPanel = document.getElementById('projectionPanel');
    const funnelPanel = document.getElementById('funnelPanel');
    const funnelChart = document.getElementById('funnelChart');
    const legendEl = document.getElementById('funnelLegend');
    if (!projectionPanel || !funnelPanel || !funnelChart) return;

    const panelH = Math.floor(projectionPanel.getBoundingClientRect().height || 0);
    if (panelH <= 0) return;

    const panelStyle = window.getComputedStyle(funnelPanel);
    const padTop = parseFloat(panelStyle.paddingTop || '0');
    const padBottom = parseFloat(panelStyle.paddingBottom || '0');
    const titleEl = funnelPanel.querySelector('h3');
    const titleH = titleEl ? Math.ceil(titleEl.getBoundingClientRect().height) : 0;
    const legendH = legendEl ? Math.ceil(legendEl.getBoundingClientRect().height) : 0;
    const chartStyle = window.getComputedStyle(funnelChart);
    const chartMarginTop = parseFloat(chartStyle.marginTop || '0');
    const legendStyle = legendEl ? window.getComputedStyle(legendEl) : null;
    const legendMarginTop = legendStyle ? parseFloat(legendStyle.marginTop || '0') : 0;
    const legendPadTop = legendStyle ? parseFloat(legendStyle.paddingTop || '0') : 0;

    const available = Math.max(
      300,
      Math.floor(panelH - padTop - padBottom - titleH - chartMarginTop - legendH - legendMarginTop - legendPadTop - 6)
    );

    funnelChart.style.height = `${available}px`;
  } catch(e){ console.warn('sync funnel height err', e); }
}

// Renderiza o funil de etapas como SVG inline com trapézios proporcionais às contagens
function renderFunnel(byColumn){
  try{
    const container = document.getElementById('funnelChart');
    const legendEl = document.getElementById('funnelLegend');
    if (!container) return; // nothing to draw here
    container.innerHTML = '';
    if (legendEl) legendEl.innerHTML = '';
    // blue palette institutional — azul/prata/grafite
    const colors = ['#244E7A','#3D6A96','#1A3A5C','#4A6A8A','#6B8DAA','#507090','#2A4A68','#365A7A'];
    if (!byColumn || byColumn.length === 0) {
      container.innerHTML = '<div class="text-center text-gray-500 py-8">Sem dados</div>';
      return;
    }
    const N = byColumn.length;
    // compute SVG viewBox height from container so funnel fills the card vertically
    const rect = container.getBoundingClientRect();
    const viewW = 100;
    const viewH = Math.max(140, Math.floor(rect.height || 220));
    // paddings in viewBox units (set to 0 so slices can reach top/bottom)
    const topPadding = 0;
    const bottomPadding = 0;
    // fixed gap in px between slices (smaller values allow taller slices)
    const gap = Math.max(2, Math.min(12, Math.floor(viewH * 0.025)));
    const totalGaps = Math.max(0, (N - 1) * gap);
    const usableH = viewH - topPadding - bottomPadding - totalGaps;
    // distribute usable height proportionally to each column's count so slices
    // reflect the actual distribution and fill the full box vertically
    // apply a small compression (exponent < 1) so very large counts don't dominate
    let counts = byColumn.map(r => Math.max(0, Number(r.count || 0)));
    let totalCount = counts.reduce((s,v)=>s+v,0);
    // if all counts are zero, fallback to equal distribution so the funnel still renders
    if (!totalCount) {
      counts = Array(N).fill(1);
      totalCount = N;
    }
    const exponent = 0.55; // <1 compresses large values, increase smaller slices
    const adjusted = counts.map(c => Math.pow(c, exponent));
    const totalAdjusted = adjusted.reduce((s,v)=>s+v,0) || 1;
    const minH = 2; // minimum slice height in px to keep them visible
    let sliceHeights = adjusted.map(a => Math.max(minH, (usableH * a) / totalAdjusted));
    // scale slice heights so their sum exactly matches usableH (fill the box)
    const sumHeights = sliceHeights.reduce((s,h)=>s+h,0) || 1;
    if (sumHeights > 0) {
      const scale = usableH / sumHeights;
      sliceHeights = sliceHeights.map(h => Math.max(2, h * scale));
    }
    const minWidth = Math.max(12, Math.round(100 / (N + 4))); // percent minimum width
    const maxCount = Math.max.apply(null, counts.concat([1]));
    // blend positional taper + data taper so it always looks like a funnel
    const baseWidths = counts.map((_, i) => {
      const t = i / Math.max(1, N - 1); // 0..1 from top to bottom
      return 100 - (t * (100 - minWidth));
    });
    const dataWidths = counts.map(c => {
      // map 0..maxCount -> minWidth..100
      return Math.max(minWidth, Math.min(100, minWidth + ((c / (maxCount || 1)) * (100 - minWidth))));
    });
    const dataInfluence = 0.35;
    const widths = baseWidths.map((bw, i) => {
      const mixed = (bw * (1 - dataInfluence)) + (dataWidths[i] * dataInfluence);
      return Math.max(minWidth, Math.min(100, mixed));
    });
    // enforce stronger decreasing steps so slices don't look like stacked bars
    const minStep = Math.max(5, (100 - minWidth) / (N + 1));
    for (let i = 1; i < widths.length; i++) {
      const maxAllowed = Math.max(minWidth, widths[i - 1] - minStep);
      widths[i] = Math.max(minWidth, Math.min(widths[i], maxAllowed));
    }

    const svgParts = [];
    svgParts.push(`<svg viewBox="0 0 ${viewW} ${viewH}" preserveAspectRatio="none" style="width:100%;height:100%;display:block">`);
      // helper to create trapezoid path with straight sides and slightly rounded tips
      function roundedTrapezoidPath(tl, ty, tr, ty2, br, by, bl, by2, r){
        // tl,tr = x coords top left/right; bl,br = x coords bottom left/right; ty,by = y coords
        // use a smaller fixed radius so sides stay mostly straight; keep a subtle rounding
        const maxAllowed = Math.min(Math.abs(tr - tl) * 0.12, Math.abs(br - bl) * 0.12, (by - ty) * 0.25);
        const rx = 2;
        // build simple path: start at top-left + rx, go to top-right - rx, arc, straight down right side, arc at bottom-right, straight to bottom-left + rx, arc, up left side, arc to close
        return `M ${tl + rx},${ty}` +
               ` L ${tr - rx},${ty}` +
               ` A ${rx},${rx} 0 0 1 ${tr},${ty + rx}` +
               ` L ${br},${by - rx}` +
               ` A ${rx},${rx} 0 0 1 ${br - rx},${by}` +
               ` L ${bl + rx},${by}` +
               ` A ${rx},${rx} 0 0 1 ${bl},${by - rx}` +
               ` L ${tl},${ty + rx}` +
               ` A ${rx},${rx} 0 0 1 ${tl + rx},${ty}` +
               ` Z`;
      }
    // render slices using computed proportional heights
    let curY = topPadding;
    for (let i = 0; i < N; i++){
      const h = sliceHeights[i] || Math.max(6, usableH / N);
      const topY = curY;
      let bottomY = topY + h;
      // only add gap after slice when it's not the last one
      if (i < N - 1) curY = bottomY + gap;
      else curY = bottomY;
      const topW = Math.max(minWidth, widths[i] || minWidth);
      const minBottomWidth = Math.max(4, Math.round(minWidth * 0.55));
      let bottomW = (i < N - 1) ? (widths[i + 1] || minBottomWidth) : (topW * 0.58);
      bottomW = Math.max(minBottomWidth, bottomW);
      if (bottomW >= topW) bottomW = Math.max(minBottomWidth, topW - 1);
      // dynamic inset avoids collapsing narrow slices into a vertical bar
      const inset = Math.min(2, Math.max(0.6, Math.min(topW, bottomW) * 0.18));
      const topLeft = (100 - topW) / 2 + inset;
      const topRight = (100 + topW) / 2 - inset;
      const bottomLeft = (100 - bottomW) / 2 + inset;
      const bottomRight = (100 + bottomW) / 2 - inset;
      const color = colors[i % colors.length];
      const count = Number(byColumn[i].count || 0);
      const pct = Math.round((count / totalCount) * 1000) / 10; // one decimal
      const name = byColumn[i].nome || '';
      // corner radius tuned by slice size to preserve trapezoid silhouette
      const rr = Math.min(8, (h || 12) * 0.33, Math.max(1.2, bottomW * 0.2));
      const pathD = roundedTrapezoidPath(topLeft, topY, topRight, topY, bottomRight, bottomY, bottomLeft, bottomY, rr);
      // transition on filter permite que o brightness do hover seja suave (mesma fluidez dos Chart.js hover)
      const strokeStyle = 'stroke:rgba(0,0,0,0.10);stroke-width:0.6';
      svgParts.push(`<path class="funnel-slice" data-idx="${i}" data-name="${escapeHtml(name)}" data-count="${count}" data-pct="${pct}" d="${pathD}" fill="${color}" style="cursor:pointer;${strokeStyle};filter:drop-shadow(0 4px 8px rgba(2,6,20,0.22));transition:filter 0.18s ease,stroke 0.18s ease,stroke-width 0.18s ease;" />`);
    }
    svgParts.push('</svg>');
    // tooltip container (styled similar to Chart.js tooltip, will be positioned near cursor)
    svgParts.push('<div id="funnelTooltip" style="position:absolute;pointer-events:none;display:none;padding:6px 8px;border-radius:4px;background:rgba(0,0,0,0.85);color:#fff;font-size:12px;z-index:50;left:0;top:0;transform:none"></div>');
    container.innerHTML = svgParts.join('');

    // attach hover handlers to slices (paths)
    // Efeito visual consistente com os outros gráficos do sistema:
    //   mouseenter → brightness(1.30) + sombra mais intensa + borda destacada (azul metálico claro)
    //   mouseleave → restaura exatamente o estado original
    // transition já declarado no style inline da path (0.18s ease) garante fluidez sem flicker
    const FILTER_DEFAULT = 'drop-shadow(0 4px 8px rgba(2,6,20,0.22))';
    const FILTER_HOVER   = 'brightness(1.30) drop-shadow(0 6px 14px rgba(2,6,20,0.38))';
    const STROKE_DEFAULT = 'rgba(0,0,0,0.10)';
    const STROKE_HOVER   = 'rgba(160,180,210,0.45)';   // borda azul metálico claro — padrão do sistema
    const STROKE_W_DEFAULT = '0.6';
    const STROKE_W_HOVER   = '1.1';

    const tooltip = container.querySelector('#funnelTooltip');
    const slices = container.querySelectorAll('path.funnel-slice');
    slices.forEach(el => {
      el.style.cursor = 'pointer';

      el.addEventListener('mouseenter', () => {
        // Destaque visual: intensifica brilho e realça a borda — igual ao hoverBackgroundColor do Chart.js
        el.style.filter      = FILTER_HOVER;
        el.style.stroke      = STROKE_HOVER;
        el.style.strokeWidth = STROKE_W_HOVER;

        // Exibe tooltip com nome da etapa, percentual e contagem
        const name = el.getAttribute('data-name') || '';
        const pct  = el.getAttribute('data-pct')  || '';
        const cnt  = el.getAttribute('data-count') || '';
        if (tooltip) { tooltip.style.display = 'block'; tooltip.textContent = `${name}: ${pct}% (${cnt})`; }
      });

      el.addEventListener('mousemove', (ev) => {
        if (!tooltip) return;
        const rect = container.getBoundingClientRect();
        let x = ev.clientX - rect.left + 12;
        let y = ev.clientY - rect.top  + 12;
        const tw = tooltip.offsetWidth  || 120;
        const th = tooltip.offsetHeight || 28;
        if (x + tw + 8 > rect.width)  x = rect.width  - tw - 8;
        if (y + th + 8 > rect.height) y = rect.height - th - 8;
        if (x < 8) x = 8; if (y < 8) y = 8;
        tooltip.style.left = x + 'px';
        tooltip.style.top  = y + 'px';
      });

      el.addEventListener('mouseleave', () => {
        // Restaura ao estado original — sem artefatos visuais residuais
        el.style.filter      = FILTER_DEFAULT;
        el.style.stroke      = STROKE_DEFAULT;
        el.style.strokeWidth = STROKE_W_DEFAULT;
        if (tooltip) tooltip.style.display = 'none';
      });
    });

    // legend centered below funnel
    if (legendEl){
      const items = byColumn.map((r,i)=>{
        const color = colors[i % colors.length];
        return `<div style="display:flex;align-items:center;gap:8px;color:#A8BDD4;font-size:13px"><div style="width:12px;height:12px;background:${color};border-radius:3px"></div><div>${escapeHtml(r.nome)}</div></div>`;
      }).join('');
      legendEl.style.marginTop = '8px';
      legendEl.innerHTML = `<div style="display:flex;justify-content:center;gap:18px;flex-wrap:wrap">${items}</div>`;
    }
  } catch(err){ console.error('funnel render err', err); }
}

// Re-renderiza o funil duas vezes para compensar mudanças de layout que ocorrem após o primeiro render
function rerenderFunnelAligned(){
  if (!Array.isArray(window._lastByColumn)) return;
  syncFunnelHeightToProjectionPanel();
  renderFunnel(window._lastByColumn);
  const chart = document.getElementById('funnelChart');
  const h1 = chart ? chart.getBoundingClientRect().height : 0;
  syncFunnelHeightToProjectionPanel();
  const h2 = chart ? chart.getBoundingClientRect().height : 0;
  if (Math.abs(h2 - h1) > 1) renderFunnel(window._lastByColumn);
}

let _funnelResizeTimer = null;
window.addEventListener('resize', function(){
  if (_funnelResizeTimer) clearTimeout(_funnelResizeTimer);
  _funnelResizeTimer = setTimeout(function(){
    if (!Array.isArray(window._lastByColumn)) return;
    rerenderFunnelAligned();
  }, 120);
});

// small helper to escape text put into attributes
function escapeHtml(str){ return String(str||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// render monthly revenue as a line chart (closed totals) and Meta as vertical bar at 'Atual'
// Plota receita acumulada (linha) x meta crescente projetada (linha) — o acumulado reflete o total do "Fechados" card
function renderRevenueChart(periods, closedData, metaValue){
  try{
    const revEl = document.getElementById('revenueChart');
    if (!revEl) return; // page doesn't include revenue chart
    const rctx = revEl.getContext('2d');
    const labels = periods.map(p => { const d = periodToDate(p); return d.toLocaleString('pt-BR',{month:'short', year:'numeric'}); });
    const metaVal = Number(metaValue || 0);
    // place meta bar at the 'Atual' label if present, otherwise at the last label
    const lowerLabels = labels.map(l => String(l).toLowerCase());
    let metaIndex = lowerLabels.indexOf('atual');
    if (metaIndex === -1) metaIndex = Math.max(0, labels.length - 1);
    // build a gradual meta line that starts at 0 and reaches metaVal at metaIndex
    const metaData = labels.map((_, i) => {
      if (metaIndex <= 0) return metaVal;
      const ratio = i / metaIndex;
      return (metaVal * ratio);
    });
    // Use cumulative receita (sum of closedData up to each month) so the last
    // point represents total closed to date. This matches the 'Fechados' card.
    const cumulative = [];
    let running = 0;
    closedData.forEach(v => { running += parseNumberFlexible(v); cumulative.push(running); });

    const receitaColor = '#4E8FD4';
    const metaColor    = '#94A3B8';
    const cfg = {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          { label: 'Receita', type: 'line', data: cumulative, borderColor: receitaColor, borderWidth: 2.5, pointBackgroundColor: receitaColor, pointRadius: 4, pointHoverRadius: 6, backgroundColor: 'rgba(78,143,212,0.10)', fill: true, tension: 0.35, yAxisID: 'y' },
          { label: 'Meta',    type: 'line', data: metaData,   borderColor: metaColor,    borderWidth: 1.8, borderDash: [6,4], pointBackgroundColor: metaColor, pointRadius: 3, pointHoverRadius: 5, backgroundColor: 'rgba(148,163,184,0.05)', fill: false, tension: 0, yAxisID: 'y' }
        ]
      },
      options: {
        responsive: true,
        animation: { duration: 900, easing: 'easeOutQuart' },
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: { ticks: { color: '#8FAFC8', font: { size: 11 } }, grid: { color: 'rgba(96,165,250,0.06)' } },
          y: { beginAtZero: true, ticks: { callback: v => currency.format(v), color: '#8FAFC8', font: { size: 11 } }, grid: { color: 'rgba(96,165,250,0.06)' } }
        },
        plugins: {
          legend: {
            position: 'top',
            labels: { color: '#C8DDEF', usePointStyle: true, pointStyleWidth: 12, padding: 20, font: { size: 12 } }
          },
          tooltip: {
            backgroundColor: 'rgba(6,16,34,0.96)',
            borderColor: 'rgba(78,143,212,0.25)',
            borderWidth: 1,
            titleColor: '#93C5FD',
            titleFont: { size: 12, weight: '600' },
            bodyColor: '#C8DDEF',
            bodyFont: { size: 12 },
            padding: 14,
            cornerRadius: 10,
            caretSize: 6,
            callbacks: {
              title: items => items[0]?.label || '',
              label: ctx => {
                const prefix = ctx.datasetIndex === 0 ? '  Receita acumulada' : '  Meta projetada';
                return `${prefix}:  ${currency.format(ctx.parsed.y)}`;
              },
              labelColor: ctx => ({
                borderColor: 'transparent',
                backgroundColor: ctx.datasetIndex === 0 ? receitaColor : metaColor,
                borderRadius: 3,
                width: 10,
                height: 10,
              })
            }
          }
        }
      }
    };
    if (rctx) {
      if (window._revenueChart) try{ window._revenueChart.destroy(); } catch(e){}
      window._revenueChart = new Chart(rctx, cfg);
    }
  } catch(err){ console.error('revenue chart err', err); }
}

  // Sidebar interactions (icons are static; no search field)
