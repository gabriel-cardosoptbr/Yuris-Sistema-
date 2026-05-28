/**
 * juridico.js — Painel Jurídico (visão estratégica)
 *
 * Responsabilidades:
 *   - Buscar processos de /api/processes.php (dados completos da carteira)
 *   - Buscar métricas de /api/juridico_metrics.php (nomes de advogados + deadlines pré-calculadas)
 *   - Calcular KPIs ESTRATÉGICOS client-side (carteira ativa, encerrados, vencidos, sem mov., etc.)
 *   - Renderizar: KPI cards, resumo executivo, listas de prazos, distribuição por advogado
 *   - Controlar o popup de alertas de prazos (salvo em sessionStorage — aparece 1x por sessão)
 *
 * NÃO renderiza gráficos — isso é responsabilidade de juridico_charts.js.
 * NÃO gerencia CRUD de processos — isso é responsabilidade de processos.js.
 *
 * Diferença de processos.js:
 *   processos.js → foco operacional: prazos hoje/amanhã/semana, Kanban, tarefas, histórico
 *   juridico.js  → foco estratégico: volume total, produtividade, risco latente, carga por advogado
 */
document.addEventListener('DOMContentLoaded', () => {
  const METRICS_API   = '/api/juridico_metrics.php';
  const PROCESSES_API = '/api/processes.php';

  // ── Helpers de data ────────────────────────────────────────────────────────

  // Converte data ISO (YYYY-MM-DD) → DD/MM/AAAA para exibição
  function fmtDate(v) {
    if (!v) return '—';
    const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? `${m[3]}/${m[2]}/${m[1]}` : String(v);
  }

  // Converte data ISO → objeto Date sem deslocamento UTC (respeitando fuso do usuário)
  function parseDate(v) {
    if (!v) return null;
    const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? new Date(+m[1], +m[2]-1, +m[3]) : null;
  }

  function addDays(d, n) { const r = new Date(d); r.setDate(r.getDate() + n); return r; }

  // Atualiza textContent de um elemento pelo ID — silencioso se o elemento não existir
  function set(id, val) { const el = document.getElementById(id); if (el) el.textContent = val; }

  // Retorna badge HTML indicando urgência relativa ao dia atual do usuário
  function urgBadge(prazoStr) {
    const d = parseDate(prazoStr);
    if (!d) return '';
    const now   = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const diff  = Math.floor((d - today) / 864e5);
    if (diff < 0)   return '<span class="jur-badge badge-danger" style="margin-left:4px">Vencido</span>';
    if (diff === 0) return '<span class="jur-badge badge-danger" style="margin-left:4px">Hoje</span>';
    if (diff <= 3)  return '<span class="jur-badge badge-warn"   style="margin-left:4px">Urgente</span>';
    if (diff <= 7)  return '<span class="jur-badge badge-info"   style="margin-left:4px">7 dias</span>';
    return '';
  }

  // ── Métricas estratégicas (client-side) ───────────────────────────────────
  /**
   * Calcula KPIs ESTRATÉGICOS a partir do array de processos.
   * Estes indicadores são diferentes dos operacionais de processos.js:
   *   - active_count   : total NÃO encerrado/arquivado (inclui 'ativo','concluido','suspenso',NULL)
   *   - encerrados     : total com status 'encerrado' ou 'arquivado'
   *   - novos_mes      : abertos no mês corrente (usa created_at ou data_inicio)
   *   - vencidos       : ativos com proximo_prazo < hoje (risco crítico)
   *   - sem_mov        : ativos sem ultima_movimentacao há 30+ dias (risco latente)
   *   - advogados_count: quantidade de advogados distintos com processos ativos
   *   - deadlines_*    : arrays para o popup de alertas (mesmos cálculos que processos.js)
   */
  function computeStrategicKPIs(processes) {
    const now        = new Date();
    const today      = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const t3         = addDays(today, 3);
    const t7         = addDays(today, 7);
    const t15        = addDays(today, 15);
    const t30        = addDays(today, 30);
    const startMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    const limit30ago = new Date(today); limit30ago.setDate(today.getDate() - 30);

    let active_count = 0, encerrados = 0, novos_mes = 0, vencidos = 0, sem_mov = 0;
    const advogadosSet = new Set();
    const urgent = [], dl_today = [], dl_7 = [], dl_15 = [], dl_30 = [];

    processes.forEach(p => {
      const st       = String(p.status || '').toLowerCase();
      const inactive = (st === 'arquivado' || st === 'encerrado');

      // Conta totais por status
      if (!inactive) active_count++;
      else encerrados++;

      // Processos abertos neste mês (usa created_at ou data_inicio como fallback)
      const criadoEm = p.created_at  ? new Date(p.created_at)  :
                       p.data_inicio ? parseDate(p.data_inicio) : null;
      if (criadoEm && criadoEm >= startMonth) novos_mes++;

      // Advogados únicos com processos ativos
      if (!inactive && p.responsavel_user_id) advogadosSet.add(p.responsavel_user_id);

      // Métricas de risco para processos ativos
      if (!inactive) {
        const d    = parseDate(p.proximo_prazo);
        const ulMov = p.ultima_movimentacao ? new Date(p.ultima_movimentacao) : null;

        // Vencido: prazo já ultrapassado
        if (d && d < today) vencidos++;

        // Sem movimentação: nenhuma ou não atualizado há 30+ dias
        if (!ulMov || ulMov < limit30ago) sem_mov++;

        // Arrays para popup de alertas de prazo (lógica compartilhada com processos.php)
        if (d) {
          if (d >= today && d <= t3)             urgent.push(p);
          if (d.getTime() === today.getTime())   dl_today.push(p);
          else if (d > today && d <= t7)         dl_7.push(p);
          else if (d > t7   && d <= t15)         dl_15.push(p);
          else if (d > t15  && d <= t30)         dl_30.push(p);
        }
      }
    });

    return {
      active_count,
      encerrados,
      novos_mes,
      vencidos,
      sem_mov,
      advogados_count: advogadosSet.size,
      urgent,
      deadlines_today: dl_today,
      deadlines_7:     dl_7,
      deadlines_15:    dl_15,
      deadlines_30:    dl_30,
    };
  }

  // ── Fallback: distribui por lawyer quando metrics não retorna nomes ─────────
  // Prefere metricsLawyers (tem nomes reais via SQL JOIN com users) — fallback para IDs
  function computeByLawyer(processes, metricsLawyers) {
    if (metricsLawyers && metricsLawyers.length > 0) return metricsLawyers;
    const map = {};
    processes.forEach(p => {
      const key = p.responsavel_user_id ? `ID: ${p.responsavel_user_id}` : 'Sem responsável';
      map[key] = (map[key] || 0) + 1;
    });
    return Object.keys(map).sort((a, b) => map[b] - map[a]).map(k => ({ nome: k, total: map[k] }));
  }

  // ── Render: colunas de prazos críticos ────────────────────────────────────
  // Preenche cada coluna (hoje/7/15/30 dias) com lista de processos e atualiza o contador
  function renderProxList(listId, countId, items) {
    set(countId, items ? items.length : 0);
    const el = document.getElementById(listId);
    if (!el) return;
    if (!items || !items.length) {
      el.innerHTML = '<div class="prox-empty">Nenhum processo neste período</div>';
      return;
    }
    el.innerHTML = items.map(p =>
      `<div class="prox-row">` +
        `<div class="prox-row-num">${p.numero || '—'}</div>` +
        `<div class="prox-row-client">${p.cliente_nome || '—'}${urgBadge(p.proximo_prazo)}</div>` +
        `<div class="prox-row-meta">${fmtDate(p.proximo_prazo)}${p.responsavel ? ` · ${p.responsavel}` : ''}</div>` +
      `</div>`
    ).join('');
  }

  // ── Render: distribuição por responsável (barras de progresso) ────────────
  // Mostra fatia percentual de cada advogado na carteira total — visão gerencial de carga
  function renderDistResponsavel(byLawyer) {
    const el = document.getElementById('distResponsavelList');
    if (!el) return;
    if (!byLawyer || !byLawyer.length) {
      el.innerHTML = '<div style="color:var(--muted);font-size:.82rem;padding:8px 0">Nenhum responsável cadastrado nos processos.</div>';
      return;
    }
    const total = byLawyer.reduce((s, r) => s + Number(r.total || 0), 0) || 1;
    el.innerHTML = byLawyer.slice(0, 10).map(r => {
      const cnt = Number(r.total || 0);
      const pct = Math.round((cnt / total) * 100);
      // Layout em card vertical: nome completo no topo + estatísticas embaixo
      return `<div class="resp-row">` +
        `<div class="resp-name">${r.nome || 'Sem responsável'}</div>` +
        `<div class="resp-stats">` +
          `<div class="resp-bar-wrap"><div class="resp-bar" style="width:${pct}%"></div></div>` +
          `<div class="resp-count">${cnt}</div>` +
          `<div class="resp-pct">${pct}%</div>` +
        `</div>` +
      `</div>`;
    }).join('');
  }

  // ── Render: lista linear de prazos desta semana ───────────────────────────
  // Exibe processos com prazo em até 7 dias no painel lateral direito
  function renderDeadlinesList(items) {
    const el = document.getElementById('deadlinesList');
    if (!el) return;
    if (!items || !items.length) {
      el.innerHTML = '<div style="color:var(--muted);font-size:.82rem">Nenhum prazo nos próximos 7 dias</div>';
      return;
    }
    el.innerHTML = items.map(p =>
      `<div class="list-row">` +
        `<div style="font-weight:600;color:#e2f0ff">${p.numero || '—'}${urgBadge(p.proximo_prazo)}</div>` +
        `<div style="font-size:.76rem;color:var(--muted)">${p.cliente_nome || ''} · ${fmtDate(p.proximo_prazo)}</div>` +
      `</div>`
    ).join('');
  }

  // ── Render: resumo executivo estratégico ─────────────────────────────────
  /**
   * Monta texto narrativo com linguagem GERENCIAL.
   * Foco: estado da carteira, riscos latentes, produtividade — não urgência operacional.
   * Omite partes com valor zero para manter o texto limpo.
   */
  function renderResumo(kpis) {
    const el = document.getElementById('resumoJuridico');
    if (!el) return;
    const { active_count, encerrados, novos_mes, vencidos, sem_mov, advogados_count } = kpis;
    const parts = [];
    parts.push(`Carteira com <strong>${active_count}</strong> processo${active_count !== 1 ? 's' : ''} ativo${active_count !== 1 ? 's' : ''}`);
    if (encerrados   > 0) parts.push(`<strong>${encerrados}</strong> encerrado${encerrados !== 1 ? 's' : ''}`);
    if (novos_mes    > 0) parts.push(`<strong>${novos_mes}</strong> novo${novos_mes !== 1 ? 's' : ''} este mês`);
    if (advogados_count > 0) parts.push(`distribuída entre <strong>${advogados_count}</strong> advogado${advogados_count !== 1 ? 's' : ''}`);
    if (vencidos     > 0) parts.push(`<strong style="color:#B06070">${vencidos}</strong> com prazo vencido`);
    if (sem_mov      > 0) parts.push(`<strong style="color:#C4A040">${sem_mov}</strong> sem movimentação recente`);
    el.innerHTML = parts.join(' · ') + '.';
  }

  // ── Popup de alertas de prazos ────────────────────────────────────────────
  const ALERT_KEYS = { hoje: 'jur_alert_hoje', d7: 'jur_alert_7', d15: 'jur_alert_15', d30: 'jur_alert_30' };

  // Conecta os toggles de alerta ao localStorage para persistir preferências entre sessões
  function loadAlertSettings() {
    const wire = (id, key) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.checked = localStorage.getItem(key) !== 'false';
      el.addEventListener('change', () => localStorage.setItem(key, el.checked ? 'true' : 'false'));
    };
    wire('alertToggleHoje', ALERT_KEYS.hoje);
    wire('alertToggle7',    ALERT_KEYS.d7);
    wire('alertToggle15',   ALERT_KEYS.d15);
    wire('alertToggle30',   ALERT_KEYS.d30);
  }

  /**
   * Exibe popup de alertas uma única vez por sessão do browser (flag em sessionStorage).
   * Usa deadlines de metricsData quando disponível (têm nomes de responsável via JOIN).
   * Fallback para arrays calculados client-side em kpis.
   */
  function showAlertPopup(kpis, metricsData) {
    if (sessionStorage.getItem('jur_alert_shown')) return;
    const isOn = k => localStorage.getItem(k) !== 'false';

    const blocks = [
      { key: ALERT_KEYS.hoje, label: 'Prazos Hoje',      items: metricsData.deadlines_today || kpis.deadlines_today, cls: 'badge-danger' },
      { key: ALERT_KEYS.d7,   label: 'Próximos 7 dias',  items: metricsData.deadlines_7     || kpis.deadlines_7,     cls: 'badge-warn'   },
      { key: ALERT_KEYS.d15,  label: 'Próximos 15 dias', items: metricsData.deadlines_15    || kpis.deadlines_15,    cls: 'badge-info'   },
      { key: ALERT_KEYS.d30,  label: 'Próximos 30 dias', items: metricsData.deadlines_30    || kpis.deadlines_30,    cls: 'badge-ok'     },
    ].filter(b => isOn(b.key) && b.items && b.items.length > 0);

    if (!blocks.length) return;

    const body = document.getElementById('alertModalBody');
    if (!body) return;
    body.innerHTML = blocks.map(b =>
      `<div class="alert-block">` +
        `<div class="alert-block-header">` +
          `<span class="alert-block-label">${b.label}</span>` +
          `<span class="alert-block-count">${b.items.length} processo${b.items.length !== 1 ? 's' : ''}</span>` +
        `</div>` +
        `<div class="alert-block-list">` +
          b.items.slice(0, 8).map(p =>
            `<div class="alert-item">` +
              `<div class="alert-item-client">${p.cliente_nome || '—'} <span class="jur-badge ${b.cls}" style="margin-left:4px">${fmtDate(p.proximo_prazo)}</span></div>` +
              `<div class="alert-item-meta">${p.numero || '—'}${p.responsavel ? ' · ' + p.responsavel : ''}</div>` +
            `</div>`
          ).join('') +
        `</div>` +
      `</div>`
    ).join('');

    const modal = document.getElementById('alertModal');
    if (modal) modal.classList.remove('hidden');
    sessionStorage.setItem('jur_alert_shown', '1');
  }

  // Registra handlers dos botões do modal de alertas
  function wireAlertModal() {
    const close = () => { const m = document.getElementById('alertModal'); if (m) m.classList.add('hidden'); };
    const btn   = (id, fn) => { const el = document.getElementById(id); if (el) el.addEventListener('click', fn); };
    btn('alertModalClose',  close);
    btn('alertModalClose2', close);
    btn('alertModalVerBtn', () => {
      close();
      // Rola até a seção de prazos críticos ao clicar em "Ver processos"
      const sec = document.getElementById('secaoProximos');
      if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  // ── Carga principal ────────────────────────────────────────────────────────
  /**
   * Busca processes e metrics em paralelo, calcula todos os KPIs e renderiza a página.
   * Usa Promise.all para minimizar tempo de espera total.
   */
  async function load() {
    const [pRes, mRes] = await Promise.all([
      fetch(PROCESSES_API, { credentials: 'same-origin' })
        .then(r => r.ok ? r.json() : null).catch(() => null),
      fetch(METRICS_API, { credentials: 'same-origin' })
        .then(r => r.ok ? r.json() : null).catch(() => null),
    ]);

    // Tolera resposta como { data: [...] } ou diretamente como array
    let processes = [];
    if (pRes) {
      if (Array.isArray(pRes.data))                        processes = pRes.data;
      else if (Array.isArray(pRes))                        processes = pRes;
      else if (pRes.data && Array.isArray(pRes.data.data)) processes = pRes.data.data;
    }

    const metricsData = (mRes && mRes.success && mRes.data) ? mRes.data
                      : (mRes && mRes.data)                 ? mRes.data
                      : {};

    console.log('[juridico.js] processos:', processes.length,
                '| metrics ok:', !!(mRes && mRes.success),
                '| by_lawyer:', (metricsData.by_lawyer || []).length);

    // Calcula todos os KPIs estratégicos client-side
    const kpis = computeStrategicKPIs(processes);

    // ── Atualiza KPI cards estratégicos ──
    // statActiveVal     : carteira ativa (fonte: computeStrategicKPIs)
    // statEncerradosVal : total encerrados/arquivados
    // statNovosMesVal   : abertos no mês corrente
    // statVencidosVal   : ativos com prazo vencido (risco crítico)
    // statSemMovVal     : ativos sem movimentação há 30+ dias — usa no_update da API quando disponível
    // statAdvogadosVal  : advogados únicos com processos ativos
    set('statActiveVal',    kpis.active_count);
    set('statEncerradosVal', kpis.encerrados);
    set('statNovosMesVal',  kpis.novos_mes);
    set('statVencidosVal',  kpis.vencidos);
    set('statSemMovVal',    (metricsData.no_update && metricsData.no_update.length > 0)
                              ? metricsData.no_update.length
                              : kpis.sem_mov);
    set('statAdvogadosVal', kpis.advogados_count);

    // IDs legados mantidos ocultos no HTML — atualizados para não quebrar código que possa
    // referenciar esses IDs de outras abas (ex: dashboard.php)
    set('statNoUpdateVal', (metricsData.no_update || []).length);

    // Resumo executivo estratégico
    renderResumo(kpis);

    // Colunas de prazos críticos — prefere dados de metrics (têm nome de responsável via JOIN)
    const proxHoje = (metricsData.deadlines_today || []).length ? metricsData.deadlines_today : kpis.deadlines_today;
    const prox7    = (metricsData.deadlines_7    || []).length ? metricsData.deadlines_7    : kpis.deadlines_7;
    const prox15   = (metricsData.deadlines_15   || []).length ? metricsData.deadlines_15   : kpis.deadlines_15;
    const prox30   = (metricsData.deadlines_30   || []).length ? metricsData.deadlines_30   : kpis.deadlines_30;

    renderProxList('proxHojeList', 'proxHojeCount', proxHoje);
    renderProxList('prox7List',    'prox7Count',    prox7);
    renderProxList('prox15List',   'prox15Count',   prox15);
    renderProxList('prox30List',   'prox30Count',   prox30);

    // Distribuição por responsável (barra de progresso)
    const byLawyer = computeByLawyer(processes, metricsData.by_lawyer);
    renderDistResponsavel(byLawyer);

    // Lista lateral de prazos desta semana
    renderDeadlinesList(prox7);

    // Popup de alertas (executa 1x por sessão)
    showAlertPopup(kpis, metricsData);
  }

  loadAlertSettings();
  wireAlertModal();
  load().catch(e => console.error('[juridico.js] load error:', e));
});
