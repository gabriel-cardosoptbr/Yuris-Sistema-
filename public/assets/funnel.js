function $(id) {
  return document.getElementById(id);
}

function n(v) {
  return Number(v || 0);
}

function fmtMoney(v) {
  return n(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function fmtNum(v) {
  return Math.round(n(v)).toLocaleString('pt-BR');
}

function fmtPct(v) {
  return (n(v) * 100).toFixed(1) + '%';
}

function parseMoneyBR(value) {
  if (typeof value === 'number') return value;
  var s = String(value || '').trim();
  if (!s) return 0;
  s = s.replace(/\s/g, '').replace(/R\$/gi, '');
  if (s.indexOf(',') !== -1) {
    s = s.replace(/\./g, '').replace(',', '.');
  } else {
    var parts = s.split('.');
    if (parts.length > 2) {
      var dec = parts.pop();
      s = parts.join('') + '.' + dec;
    }
  }
  s = s.replace(/[^0-9.-]/g, '');
  return Number(s) || 0;
}

function readValue(id) {
  var el = $(id);
  if (!el) return 0;
  if (el.classList.contains('input-currency')) {
    return parseMoneyBR(el.value);
  }
  return n(el.value);
}

function updateKpi(id, value) {
  var el = $(id);
  if (!el) return;
  el.textContent = value;
}

function conversionMetrics(vendas, propostas, visitas, oportunidades) {
  var convPropostas = propostas ? vendas / propostas : 0;
  var convVisitas = visitas ? propostas / visitas : 0;
  var convOportunidades = oportunidades ? visitas / oportunidades : 0;
  var convGeral = oportunidades ? vendas / oportunidades : 0;

  return {
    propostas: convPropostas,
    visitas: convVisitas,
    oportunidades: convOportunidades,
    geral: convGeral,
  };
}

function findBottleneck(conversions) {
  var candidates = [
    { key: 'propostas', value: conversions.propostas, label: 'Propostas enviadas → Contratos fechados' },
    { key: 'visitas', value: conversions.visitas, label: 'Consultas comerciais → Propostas enviadas' },
    { key: 'oportunidades', value: conversions.oportunidades, label: 'Leads qualificados → Consultas comerciais' },
  ];

  var worst = null;
  candidates.forEach(function (item) {
    if (worst === null || item.value < worst.value) {
      worst = item;
    }
  });

  return worst;
}

function renderFunnel(containerId, stages, bottleneckKey) {
  var container = $(containerId);
  if (!container) return;
  container.innerHTML = '';

  var max = Math.max.apply(null, stages.map(function (s) { return s.value; }).concat([1]));

  var topValue = stages.length ? (stages[0].value || 1) : 1; // primeiro estágio = topo do funil

  stages.forEach(function (stage, idx) {
    var pctTop = max ? (stage.value / max) * 100 : 100;
    var convNext = stage.next !== null ? (stage.value ? stage.next / stage.value : 0) : null;
    var isLast = stage.next === null;

    // Para a última etapa: mostra "Taxa global" (contratos ÷ leads do topo) em vez de "—"
    var convLabel = isLast ? 'Taxa global' : 'Conversão p/ próxima';
    var convValue = isLast
      ? fmtPct(topValue ? stage.value / topValue : 0)
      : (convNext === null ? '—' : fmtPct(convNext));

    var el = document.createElement('div');
    el.className = 'funnel-stage' + (stage.key === bottleneckKey ? ' is-bottleneck' : '');
    el.innerHTML =
      '<div class="funnel-shape">' +
        '<div class="funnel-head">' +
          '<div>' +
            '<div class="funnel-name">' + stage.label + '</div>' +
            '<div class="section-subtitle" style="margin-top:3px">' + fmtNum(stage.value) + '</div>' +
          '</div>' +
          '<div class="text-sm font-semibold text-blue-100">' + Math.round(pctTop) + '%</div>' +
        '</div>' +
        '<div class="funnel-metrics">' +
          '<span>% do topo: <strong>' + Math.round(pctTop) + '%</strong></span>' +
          '<span>' + convLabel + ': <strong>' + convValue + '</strong></span>' +
        '</div>' +
      '</div>';

    container.appendChild(el);

    requestAnimationFrame(function () {
      setTimeout(function () {
        el.classList.add('show-step');
      }, idx * 70 + 30);
    });
  });
}

function renderConversions(conversions, meta, ticket, vendasNec, bottleneckKey) {
  var calcEl = $('calculations');
  if (!calcEl) return;

  var items = [
    {
      id: 'propostas',
      label: 'Contratos fechados ÷ Propostas enviadas',
      value: fmtPct(conversions.propostas),
    },
    {
      id: 'visitas',
      label: 'Propostas enviadas ÷ Consultas comerciais',
      value: fmtPct(conversions.visitas),
    },
    {
      id: 'oportunidades',
      label: 'Consultas comerciais ÷ Leads qualificados',
      value: fmtPct(conversions.oportunidades),
    },
    {
      id: 'geral',
      label: 'Conversão geral (Contratos ÷ Leads)',
      value: fmtPct(conversions.geral),
    },
    {
      id: 'meta',
      label: 'Meta mensal',
      value: fmtMoney(meta),
    },
    {
      id: 'ticket',
      label: 'Meta ÷ Honorário médio (contratos necessários)',
      value: fmtNum(vendasNec),
    },
  ];

  calcEl.innerHTML = items.map(function (item) {
    var cls = item.id === bottleneckKey ? 'conversion-item is-bottleneck' : 'conversion-item';
    return (
      '<div class="' + cls + '">' +
        '<div class="conversion-label">' + item.label + '</div>' +
        '<div class="conversion-value">' + item.value + '</div>' +
      '</div>'
    );
  }).join('');
}

function updateGoalIndicator(vendasNec, vendasAtual) {
  var pill = $('goalIndicatorPill');
  var text = $('goalIndicatorText');
  if (!pill || !text) return;

  pill.classList.remove('is-ok', 'is-warn', 'is-danger');

  if (vendasAtual <= 0) {
    pill.classList.add('is-danger');
    pill.textContent = 'Agressiva';
    text.textContent = 'Cadência atual zerada. Ajuste o histórico para avaliar a viabilidade.';
    return;
  }

  if (vendasNec <= vendasAtual) {
    pill.classList.add('is-ok');
    pill.textContent = 'Realista';
    text.textContent = 'A meta está dentro da cadência atual do escritório.';
    return;
  }

  if (vendasNec <= vendasAtual * 1.2) {
    pill.classList.add('is-warn');
    pill.textContent = 'Desafiadora';
    text.textContent = 'Será necessário ganho tático de conversão e disciplina comercial.';
    return;
  }

  pill.classList.add('is-danger');
  pill.textContent = 'Agressiva';
  text.textContent = 'A meta exige aumento relevante de volume e/ou melhoria nas conversões.';
}

function calculateGoalStages(vendasNec, vendas, propostas, visitas, oportunidades, conversions) {
  var factor = vendas > 0 ? (vendasNec / vendas) : 0;

  var propostasNec = factor > 0 ? Math.ceil(propostas * factor) : 0;
  var consultasNec = factor > 0 ? Math.ceil(visitas * factor) : 0;
  var leadsNec = factor > 0 ? Math.ceil(oportunidades * factor) : 0;

  if (!propostasNec && conversions.propostas > 0) {
    propostasNec = Math.ceil(vendasNec / conversions.propostas);
  }
  if (!consultasNec && conversions.visitas > 0 && propostasNec > 0) {
    consultasNec = Math.ceil(propostasNec / conversions.visitas);
  }
  if (!leadsNec && conversions.oportunidades > 0 && consultasNec > 0) {
    leadsNec = Math.ceil(consultasNec / conversions.oportunidades);
  }

  return {
    propostasNec: propostasNec,
    consultasNec: consultasNec,
    leadsNec: leadsNec,
  };
}

function fmtPctValue(v) {
  return n(v).toFixed(1).replace('.', ',') + '%';
}

function buildDeltaText(label, atual, alvo) {
  var delta = alvo - atual;
  var pct = atual > 0 ? ((delta / atual) * 100) : 0;
  var action = 'Manter';
  var value = fmtNum(alvo);
  var detail = 'Sem variação necessária';

  if (atual <= 0 && alvo > 0) {
    action = 'Novo patamar';
    value = fmtNum(alvo);
    detail = 'Sair de 0 para ' + fmtNum(alvo);
  } else if (delta > 0) {
    action = 'Aumentar';
    value = '+' + fmtNum(delta);
    detail = fmtPctValue(pct) + ' (de ' + fmtNum(atual) + ' para ' + fmtNum(alvo) + ')';
  } else if (delta < 0) {
    action = 'Reduzir';
    value = fmtNum(delta);
    detail = fmtPctValue(Math.abs(pct)) + ' (de ' + fmtNum(atual) + ' para ' + fmtNum(alvo) + ')';
  }

  return {
    label: label,
    action: action,
    value: value,
    detail: detail,
  };
}

function updateGapSummary(state, sim) {
  var note = $('bottleneckText');
  if (!note || !state || !sim) return;

  var parts = [
    buildDeltaText('Leads qualificados', state.oportunidades, sim.oportunidades),
    buildDeltaText('Consultas comerciais', state.visitas, sim.visitas),
    buildDeltaText('Propostas enviadas', state.propostas, sim.propostas),
    buildDeltaText('Contratos fechados', state.vendas, sim.vendas),
  ];

  var bottleneckLine = '';
  if (state.bottleneck) {
    bottleneckLine = 'Gargalo atual: <strong>' + state.bottleneck.label + '</strong> (' + fmtPct(state.bottleneck.value) + ').';
  } else {
    bottleneckLine = 'Gargalo atual em análise.';
  }

  note.innerHTML =
    '<div class="gap-title">Resumo do aumento necessário para sair do comercial e atingir o simulado</div>' +
    '<div class="gap-grid">' +
      parts.map(function (item) {
        return (
          '<div class="gap-item">' +
            '<div class="gap-item-label">' + item.label + '</div>' +
            '<div class="gap-item-value">' + item.action + ' ' + item.value + '</div>' +
            '<div class="gap-item-sub">' + item.detail + '</div>' +
          '</div>'
        );
      }).join('') +
    '</div>' +
    '<div class="gap-footer">' + bottleneckLine + '</div>';
}

function computeAndRender() {
  var vendas = readValue('vendas');
  var propostas = readValue('propostas');
  var visitas = readValue('visitas');
  var oportunidades = readValue('oportunidades');
  var meta = readValue('meta');
  var ticket = readValue('ticket') || 1;
  var dias = readValue('dias') || 1;
  var vendedores = readValue('vendedores') || 1;

  var conversions = conversionMetrics(vendas, propostas, visitas, oportunidades);
  var bottleneck = findBottleneck(conversions);

  var vendasNec = Math.ceil(meta / ticket);
  var goalStages = calculateGoalStages(vendasNec, vendas, propostas, visitas, oportunidades, conversions);
  var consultasDia = Math.ceil((goalStages.consultasNec || 0) / dias) || 0;
  var vendasPorResponsavel = Math.ceil(vendasNec / vendedores) || 0;
  var oppDia = Math.floor(oportunidades / dias) || 0;

  updateKpi('kpiMeta', fmtMoney(meta));
  updateKpi('kpiHonorario', fmtMoney(ticket));
  updateKpi('kpiContratosNec', fmtNum(vendasNec));
  updateKpi('kpiLeadsNec', fmtNum(goalStages.leadsNec || 0));
  updateKpi('kpiConsultasDia', fmtNum(consultasDia));
  updateKpi('kpiContratosResp', fmtNum(vendasPorResponsavel));

  updateGoalIndicator(vendasNec, vendas);

  var op = $('operational');
  if (op) {
    op.innerHTML =
      'Para atingir <strong>' + fmtMoney(meta) + '</strong> com honorário médio de <strong>' + fmtMoney(ticket) + '</strong>, o escritório precisa de <strong>' + fmtNum(vendasNec) + ' contratos</strong>. ' +
      'Com a estrutura atual, isso representa aproximadamente <strong>' + fmtNum(goalStages.leadsNec || 0) + ' leads qualificados</strong>, ' +
      '<strong>' + fmtNum(goalStages.consultasNec || 0) + ' consultas comerciais</strong> e ' +
      '<strong>' + fmtNum(goalStages.propostasNec || 0) + ' propostas enviadas</strong>. ' +
      'Ritmo sugerido: <strong>' + fmtNum(consultasDia) + ' consultas/dia útil</strong> e <strong>' + fmtNum(vendasPorResponsavel) + ' contratos por responsável</strong>.';
  }

  var exec = $('executiveSummary');
  if (exec) {
    var execItems = [
      { label: 'Meta mensal', value: fmtMoney(meta) },
      { label: 'Honorário médio', value: fmtMoney(ticket) },
      { label: 'Contratos necessários', value: fmtNum(vendasNec) },
      { label: 'Leads qualificados', value: fmtNum(goalStages.leadsNec || 0) },
      { label: 'Consultas comerciais', value: fmtNum(goalStages.consultasNec || 0) },
      { label: 'Propostas enviadas', value: fmtNum(goalStages.propostasNec || 0) },
      { label: 'Leads por dia útil', value: fmtNum(oppDia) },
      { label: 'Contratos por responsável', value: fmtNum(vendasPorResponsavel) },
    ];

    exec.innerHTML =
      '<div class="exec-summary">' +
        '<div class="exec-title">Visão consolidada da meta comercial</div>' +
        '<div class="exec-grid">' +
          execItems.map(function (item) {
            return (
              '<div class="exec-item">' +
                '<div class="exec-item-label">' + item.label + '</div>' +
                '<div class="exec-item-value">' + item.value + '</div>' +
              '</div>'
            );
          }).join('') +
        '</div>' +
        '<div class="exec-note">' +
          'Para atingir <strong>' + fmtMoney(meta) + '</strong>, o escritório precisa fechar <strong>' + fmtNum(vendasNec) + ' contratos</strong> ' +
          'e sustentar um topo de funil de aproximadamente <strong>' + fmtNum(goalStages.leadsNec || 0) + ' leads</strong> no mês.' +
        '</div>' +
      '</div>';
  }

  renderFunnel('leftFunnel', [
    { key: 'oportunidades', label: 'Leads qualificados', value: oportunidades, next: visitas },
    { key: 'visitas', label: 'Consultas comerciais', value: visitas, next: propostas },
    { key: 'propostas', label: 'Propostas enviadas', value: propostas, next: vendas },
    { key: 'vendas', label: 'Contratos fechados', value: vendas, next: null },
  ], bottleneck ? bottleneck.key : null);

  renderConversions(conversions, meta, ticket, vendasNec, bottleneck ? bottleneck.key : null);

  window._funnelState = {
    vendas: vendas,
    propostas: propostas,
    visitas: visitas,
    oportunidades: oportunidades,
    ticket: ticket,
    dias: dias,
    vendedores: vendedores,
    conversions: conversions,
    bottleneck: bottleneck,
  };
}

function renderSimulationFromInputs() {
  var state = window._funnelState;
  if (!state) return;

  var desired = readValue('simVendas');
  var vendas = state.vendas;
  var propostas = state.propostas;
  var visitas = state.visitas;
  var oportunidades = state.oportunidades;
  var vendedores = state.vendedores || 1;
  var ticket = state.ticket || 0;

  var rPropostas = 0;
  var rVisitas = 0;
  var rOportunidades = 0;

  if (desired > 0 && vendas > 0) {
    var factor = desired / vendas;
    rPropostas = Math.ceil(propostas * factor);
    rVisitas = Math.ceil(visitas * factor);
    rOportunidades = Math.ceil(oportunidades * factor);
  } else if (desired > 0) {
    var conv = state.conversions || {};
    if ((conv.propostas || 0) > 0) {
      rPropostas = Math.ceil(desired / conv.propostas);
    }
    if ((conv.visitas || 0) > 0 && rPropostas > 0) {
      rVisitas = Math.ceil(rPropostas / conv.visitas);
    }
    if ((conv.oportunidades || 0) > 0 && rVisitas > 0) {
      rOportunidades = Math.ceil(rVisitas / conv.oportunidades);
    }
  }

  renderFunnel('rightFunnel', [
    { key: 'oportunidades', label: 'Leads qualificados (simulado)', value: rOportunidades, next: rVisitas },
    { key: 'visitas', label: 'Consultas comerciais (simulado)', value: rVisitas, next: rPropostas },
    { key: 'propostas', label: 'Propostas enviadas (simulado)', value: rPropostas, next: desired },
    { key: 'vendas', label: 'Contratos fechados (simulado)', value: desired, next: null },
  ], null);

  var simLeads = $('simLeads');
  var simConsultas = $('simConsultas');
  var simPropostas = $('simPropostas');
  var simReceita = $('simReceita');
  var simDistribuicao = $('simDistribuicao');

  if (simLeads) simLeads.textContent = fmtNum(rOportunidades);
  if (simConsultas) simConsultas.textContent = fmtNum(rVisitas);
  if (simPropostas) simPropostas.textContent = fmtNum(rPropostas);
  if (simReceita) simReceita.textContent = fmtMoney(desired * ticket);

  if (simDistribuicao) {
    var contratosPorResp = Math.ceil((desired || 0) / (vendedores || 1)) || 0;
    var leadsPorResp = Math.ceil((rOportunidades || 0) / (vendedores || 1)) || 0;
    simDistribuicao.textContent =
      fmtNum(contratosPorResp) + ' contratos por responsável | ' + fmtNum(leadsPorResp) + ' leads por responsável';
  }

  updateGapSummary(state, {
    oportunidades: rOportunidades,
    visitas: rVisitas,
    propostas: rPropostas,
    vendas: desired,
  });
}

function markDirty(el) {
  if (!el) return;
  el.classList.add('is-dirty');
  clearTimeout(el._dirtyTimeout);
  el._dirtyTimeout = setTimeout(function () {
    el.classList.remove('is-dirty');
  }, 520);
}

function setupCurrencyInput(id) {
  var el = $(id);
  if (!el) return;

  el.addEventListener('focus', function () {
    var value = parseMoneyBR(el.value);
    el.value = value > 0 ? value.toFixed(2).replace('.', ',') : '';
  });

  el.addEventListener('blur', function () {
    var value = parseMoneyBR(el.value);
    el.value = fmtMoney(value);
    computeAndRender();
    renderSimulationFromInputs();
  });
}

/* ── Persistência localStorage ──────────────────────────── */
var FUNIL_KEY  = 'funil_params_v1';
var FUNIL_IDS  = ['meta', 'ticket', 'dias', 'vendedores', 'vendas', 'propostas', 'visitas', 'oportunidades', 'simVendas'];

function saveParams() {
  try {
    var data = {};
    FUNIL_IDS.forEach(function (id) {
      var el = $(id);
      if (el) data[id] = el.value;
    });
    localStorage.setItem(FUNIL_KEY, JSON.stringify(data));
  } catch (e) { /* silently fail */ }
}

function loadParams() {
  try {
    var raw = localStorage.getItem(FUNIL_KEY);
    if (!raw) return false;
    var data = JSON.parse(raw);
    FUNIL_IDS.forEach(function (id) {
      var el = $(id);
      if (el && data[id] !== undefined && data[id] !== '') {
        el.value = data[id];
      }
    });
    return true;
  } catch (e) { return false; }
}

document.addEventListener('DOMContentLoaded', function () {
  /* Restaura valores salvos (sobrescreve os defaults do HTML) */
  loadParams();

  setupCurrencyInput('meta');
  setupCurrencyInput('ticket');

  FUNIL_IDS.forEach(function (id) {
    var el = $(id);
    if (!el) return;
    el.addEventListener('input', function () {
      markDirty(el);
      saveParams();
      computeAndRender();
      renderSimulationFromInputs();
    });
    /* blur já formata moeda — garante salvar o valor formatado */
    el.addEventListener('blur', function () {
      saveParams();
    });
  });

  /* Render inicial com os valores carregados */
  computeAndRender();
  renderSimulationFromInputs();
});
