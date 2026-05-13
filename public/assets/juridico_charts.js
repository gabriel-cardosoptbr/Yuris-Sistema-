/**
 * juridico_charts.js — Gráficos estratégicos do Painel Jurídico
 *
 * Renderiza 6 gráficos Chart.js na aba Jurídico:
 *   1. statusDonut        — distribuição por status (ativo/arquivado/encerrado)
 *   2. deadlinesWeekChart — concentração de prazos nos próximos 7 dias
 *   3. hearingsLineChart  — curva anual de processos por mês de prazo
 *   4. byLawyerBar        — carga processual por advogado (horizontal)
 *   5. typesBar           — tipos de ação mais frequentes
 *   6. completionGauge    — taxa de finalização do mês corrente (donut radial)
 *
 * Fontes de dados:
 *   - /api/processes.php         → array de processos (status, proximo_prazo, tipo_acao, etc.)
 *   - /api/juridico_metrics.php  → by_lawyer com nomes reais via SQL JOIN
 *
 * NÃO faz CRUD, NÃO atualiza KPI cards — isso é juridico.js.
 * Este arquivo é executado DEPOIS de juridico.js (carregado em sequência no HTML).
 */
(function () {
  const PROCESSES_API = '/sistema_vendas/public/api/processes.php';
  const METRICS_API   = '/sistema_vendas/public/api/juridico_metrics.php';

  async function fetchJson(url) {
    try {
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) return null;
      return await res.json();
    } catch (e) { return null; }
  }

  // Retorna context 2D do canvas com id informado — seguro (null se não existir)
  function safeCtx(id, height) {
    const c = document.getElementById(id);
    if (!c || c.tagName !== 'CANVAS') return null;
    try { if (height) c.height = height; return c.getContext('2d'); } catch (e) { return null; }
  }

  // Converte data ISO → Date sem deslocamento UTC (fuso do usuário)
  function parseDate(v) {
    if (!v) return null;
    const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? new Date(+m[1], +m[2]-1, +m[3]) : null;
  }

  // ── Paleta de cores — azul metálico institucional ─────────────────────────
  // Objetivo: visual jurídico premium — azul aço marcante, sem neon excessivo.
  // Ordem: dos tons mais profundos/escuros aos mais claros/aço.
  const COLORS = [
    '#1E5FA8', // azul aço primário
    '#2878C8', // azul petróleo médio
    '#1A4A8A', // azul profundo
    '#3A90C4', // azul claro metálico
    '#4AAAD8', // azul aço claro
    '#2050A0', // azul naval escuro
    '#5BBCE0', // azul aço luminoso
    '#0F3060', // azul noturno
  ];

  // Grades e ticks dos eixos — neutro escuro para não poluir o gráfico
  const GRID   = 'rgba(160,180,210,0.09)';
  const TICK   = '#6B7887';
  // Cor de fundo das bordas do canvas (opaco escuro para separar fatias do donut)
  const BORDER = 'rgba(7,15,28,0.95)';

  // Resolve código de tipo de ação → nome legível via localStorage (populado por process_codes.js)
  function resolveActionName(code) {
    if (!code) return null;
    try {
      const raw = localStorage.getItem('process_action_types');
      if (!raw) return null;
      const list = JSON.parse(raw);
      const found = list.find(item =>
        String(item.code || '') === String(code) ||
        String(item.name || '') === String(code)
      );
      return found ? (found.name || found.code || code) : null;
    } catch (e) { return null; }
  }

  /**
   * Renderiza todos os gráficos recebendo o array de processos e os dados de métricas.
   * Chamado uma única vez após a carga assíncrona de ambas as APIs.
   */
  function renderCharts(processes, metricsData) {
    if (typeof Chart === 'undefined') {
      console.warn('[juridico_charts] Chart.js não disponível');
      return;
    }

    // ── 1. STATUS DONUT — distribuição por status da carteira ──────────────
    // Fonte: campo status de cada processo (calculado client-side)
    const statusCtx = safeCtx('statusDonut', 240);
    if (statusCtx) {
      const labels = ['Ativo', 'Arquivado', 'Encerrado', 'Outros'];
      const map    = { 'ativo': 0, 'arquivado': 0, 'encerrado': 0, 'outros': 0 };
      processes.forEach(p => {
        const st = String(p.status || '').toLowerCase();
        if (map.hasOwnProperty(st)) map[st]++;
        else map['outros']++;
      });
      new Chart(statusCtx, {
        type: 'doughnut',
        data: {
          labels,
          datasets: [{
            data: Object.values(map),
            // Usa quatro tons azul/cinza distintos para cada status
            backgroundColor: ['#1E5FA8', '#2E5080', '#3A90C4', '#445870'],
            borderColor:  BORDER,
            borderWidth:  2,
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { position: 'bottom', labels: { color: '#A8BDD4', font: { size: 11 }, padding: 10 } }
          }
        }
      });
    }

    // ── 2. CONCENTRAÇÃO DE PRAZOS — PRÓXIMOS 7 DIAS ────────────────────────
    // Usa data local do usuário (evita diferença de fuso servidor/browser)
    // Hoje e urgentes em vermelho/âmbar, demais em azul metálico
    const dwCtx = safeCtx('deadlinesWeekChart', 240);
    if (dwCtx) {
      const now   = new Date();
      const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      const days  = [], labels = [];
      for (let i = 0; i <= 7; i++) {
        const d = new Date(today);
        d.setDate(today.getDate() + i);
        days.push(d.getTime());
        labels.push(
          i === 0 ? 'Hoje' :
          i === 1 ? 'Amanhã' :
          d.toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit' })
        );
      }
      const counts = new Array(days.length).fill(0);
      processes.forEach(p => {
        const d   = parseDate(p.proximo_prazo);
        if (!d) return;
        const idx = days.indexOf(d.getTime());
        if (idx >= 0) counts[idx]++;
      });
      new Chart(dwCtx, {
        type: 'bar',
        data: {
          labels,
          datasets: [{
            label: 'Processos',
            data: counts,
            // Hoje = vermelho escuro, primeiros 3 dias = âmbar, restante = azul metálico
            backgroundColor: counts.map((_, i) =>
              i === 0 ? '#8A3050' :
              i <= 2  ? '#9A7020' :
              '#1E5FA8'
            ),
            borderRadius: 5,
            borderColor:  'transparent',
          }]
        },
        options: {
          responsive: true,
          plugins: { legend: { display: false } },
          scales: {
            y: { beginAtZero: true, ticks: { color: TICK, stepSize: 1 }, grid: { color: GRID } },
            x: { ticks: { color: TICK }, grid: { display: false } }
          }
        }
      });
    }

    // ── 3. CURVA ANUAL — PROCESSOS POR MÊS DE PRAZO ───────────────────────
    // Mostra sazonalidade: em quais meses do ano se concentram os prazos
    // Usa data local do usuário para definir o ano corrente
    const hCtx = safeCtx('hearingsLineChart', 240);
    if (hCtx) {
      const currentYear = new Date().getFullYear();
      const months = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
      const counts = new Array(12).fill(0);
      processes.forEach(p => {
        const d = parseDate(p.proximo_prazo);
        if (!d || d.getFullYear() !== currentYear) return;
        counts[d.getMonth()]++;
      });
      new Chart(hCtx, {
        type: 'line',
        data: {
          labels: months,
          datasets: [{
            label: 'Processos',
            data:  counts,
            borderColor:      '#2878C8',            // azul petróleo médio
            backgroundColor:  'rgba(40,120,200,0.12)',
            fill:             true,
            tension:          .35,
            pointBackgroundColor: '#2878C8',
            pointRadius:      4,
            pointHoverRadius: 6,
          }]
        },
        options: {
          responsive: true,
          plugins: { legend: { labels: { color: '#A8BDD4', font: { size: 11 } } } },
          scales: {
            y: { beginAtZero: true, ticks: { color: TICK, stepSize: 1 }, grid: { color: GRID } },
            x: { ticks: { color: TICK }, grid: { display: false } }
          }
        }
      });
    }

    // ── 4. CARGA POR ADVOGADO — barra horizontal ──────────────────────────
    // Fonte primária: metricsData.by_lawyer (SQL JOIN com users — tem nomes reais)
    // Fallback: agrupa por responsavel_user_id direto dos processos
    const blCtx = safeCtx('byLawyerBar', 300);
    if (blCtx) {
      let labels = [], data = [];
      if (metricsData && Array.isArray(metricsData.by_lawyer) && metricsData.by_lawyer.length) {
        labels = metricsData.by_lawyer.map(r => r.nome || `ID ${r.user_id}`);
        data   = metricsData.by_lawyer.map(r => Number(r.total || 0));
      } else {
        // Fallback client-side quando metrics não trouxe by_lawyer
        const map = {};
        processes.forEach(p => {
          const k = p.responsavel_user_id ? `ID: ${p.responsavel_user_id}` : 'Sem responsável';
          map[k] = (map[k] || 0) + 1;
        });
        const sorted = Object.keys(map).sort((a, b) => map[b] - map[a]).slice(0, 10);
        labels = sorted;
        data   = sorted.map(k => map[k]);
      }
      if (labels.length) {
        new Chart(blCtx, {
          type: 'bar',
          data: {
            labels,
            datasets: [{
              label: 'Processos',
              data,
              // Gradiente simulado via múltiplas cores — barras de azul mais escuro ao mais claro
              backgroundColor: data.map((_, i) => COLORS[i % COLORS.length]),
              borderRadius: 5,
              borderColor:  'transparent',
            }]
          },
          options: {
            indexAxis: 'y',
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
              x: { beginAtZero: true, ticks: { color: TICK }, grid: { color: GRID } },
              y: { ticks: { color: TICK }, grid: { display: false } }
            }
          }
        });
      }
    }

    // ── 5. TIPOS DE AÇÃO MAIS FREQUENTES ──────────────────────────────────
    // Resolve código → nome legível via localStorage (populado por process_codes.js)
    // Mostra as 8 especialidades mais comuns do escritório
    const tCtx = safeCtx('typesBar', 300);
    if (tCtx) {
      const map = {};
      processes.forEach(p => {
        const name = p.setor_nome || resolveActionName(p.tipo_acao);
        if (name) map[name] = (map[name] || 0) + 1;
      });
      const sorted = Object.keys(map).sort((a, b) => map[b] - map[a]).slice(0, 8);
      if (sorted.length) {
        new Chart(tCtx, {
          type: 'bar',
          data: {
            labels: sorted,
            datasets: [{
              data: sorted.map(k => map[k]),
              backgroundColor: COLORS,
              borderRadius: 5,
              borderColor:  'transparent',
            }]
          },
          options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
              y: { beginAtZero: true, ticks: { color: TICK }, grid: { color: GRID } },
              x: { ticks: { color: TICK }, grid: { display: false } }
            }
          }
        });
      }
    }

    // ── 6. TAXA DE FINALIZAÇÃO DO MÊS CORRENTE ────────────────────────────
    // Compara processos criados neste mês vs os que foram encerrados/arquivados neste mês
    // Indicador de produtividade: o escritório está fechando o que abre?
    const gCtx = safeCtx('completionGauge', 200);
    if (gCtx) {
      const now        = new Date();
      const startMonth = new Date(now.getFullYear(), now.getMonth(), 1);
      let closed = 0, created = 0;
      processes.forEach(p => {
        const createdAt = p.created_at  ? new Date(p.created_at)  : null;
        const updatedAt = p.updated_at  ? new Date(p.updated_at)  : null;
        const isClosed  = ['encerrado', 'arquivado', 'finalizado'].includes(String(p.status || '').toLowerCase());
        if (createdAt && createdAt >= startMonth) created++;
        if (isClosed  && updatedAt && updatedAt >= startMonth) closed++;
      });
      const pct = created > 0 ? Math.round((closed / created) * 100) : 0;
      new Chart(gCtx, {
        type: 'doughnut',
        data: {
          labels: ['Finalizados', 'Em andamento'],
          datasets: [{
            data: [closed, Math.max(0, created - closed)],
            // Preenchido em azul aço, "vazio" em azul muito escuro quase transparente
            backgroundColor: ['#1E5FA8', 'rgba(30,60,120,0.14)'],
            borderColor: BORDER,
            borderWidth: 2,
          }]
        },
        options: {
          cutout: '78%',
          responsive: true,
          plugins: { legend: { display: false } }
        }
      });
      const lbl = document.getElementById('completionLabel');
      if (lbl) lbl.textContent = `${closed} finalizado${closed !== 1 ? 's' : ''} de ${created} novo${created !== 1 ? 's' : ''} este mês (${pct}%)`;
    }

    console.log('[juridico_charts] gráficos renderizados — processos:', processes.length);
  }

  // ── Inicialização assíncrona ────────────────────────────────────────────
  async function init() {
    const [pRes, mRes] = await Promise.all([
      fetchJson(PROCESSES_API),
      fetchJson(METRICS_API)
    ]);

    let processes = [];
    if (pRes) {
      if (Array.isArray(pRes.data))                        processes = pRes.data;
      else if (Array.isArray(pRes))                        processes = pRes;
      else if (pRes.data && Array.isArray(pRes.data.data)) processes = pRes.data.data;
    }

    const metricsData = (mRes && mRes.success && mRes.data) ? mRes.data
                      : (mRes && mRes.data)                 ? mRes.data
                      : null;

    renderCharts(processes, metricsData);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
