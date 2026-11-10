/**
 * financeiro-parcelas.js — Finanças: cliente, processo, parcelas e baixa (T2, 10/11/2026).
 *
 * - No "Novo lançamento": cliente e processo (só os do cliente) opcionais, e
 *   "Parcelar ou lançar com vencimento" (quantidade, primeiro vencimento, valor
 *   total ou de cada parcela), com a prévia das datas e valores pela mesma regra
 *   do servidor (App\Financas\Parcelamento).
 * - Painel "A receber e a pagar": títulos com filtro por cliente, processo, tipo
 *   e situação; totais (total, baixado, pendente); por cliente e por processo;
 *   baixa, desfazer e edição de valor e vencimento de cada parcela.
 * API: /api/financeiro_parcelas.php. Sem alert/confirm/prompt: modal e mensagem na tela.
 */
(function () {
  'use strict';
  const API = '/api/financeiro_parcelas.php';
  const csrf = (document.querySelector('input[name="csrf_token"]') || {}).value || '';
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const brl = (v) => (Number(v) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const dataBr = (d) => (d ? String(d).slice(0, 10).split('-').reverse().join('/') : '');
  const numero = (s) => {
    s = String(s ?? '').trim().replace(/[^\d,.\-]/g, '');
    if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
    return Math.round((parseFloat(s) || 0) * 100) / 100;
  };
  let clientes = [];
  let ultimaVisao = null;

  async function api(metodo, url, corpo) {
    const op = { method: metodo, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf } };
    if (corpo) op.body = JSON.stringify(Object.assign({ csrf_token: csrf }, corpo));
    const res = await fetch(url, op);
    let j = {};
    try { j = await res.json(); } catch (e) { /* corpo vazio */ }
    if (!res.ok) throw new Error(j.error || ('Erro ' + res.status));
    return j;
  }

  function aviso(el, texto, tipo) {
    if (!el) return;
    el.textContent = texto || '';
    el.className = 'fin-aviso' + (tipo ? ' fin-aviso-' + tipo : '');
    el.hidden = !texto;
  }

  // ── Regras de data e valor (iguais às do servidor) ─────────────────────
  function vencimentos(primeiro, qtd) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(primeiro || '');
    if (!m || qtd < 1) return [];
    const ano = +m[1], mes = +m[2], dia = +m[3], out = [];
    for (let i = 0; i < qtd; i++) {
      const mm0 = mes - 1 + i, a = ano + Math.floor(mm0 / 12), mm = (mm0 % 12) + 1;
      const ultimo = new Date(a, mm, 0).getDate();
      out.push(`${a}-${String(mm).padStart(2, '0')}-${String(Math.min(dia, ultimo)).padStart(2, '0')}`);
    }
    return out;
  }
  function dividir(total, qtd) {
    const c = Math.round(total * 100), base = Math.floor(c / qtd);
    if (qtd < 1 || base <= 0) return [];
    const out = Array(qtd).fill(base);
    out[qtd - 1] += c - base * qtd;
    return out.map((x) => x / 100);
  }

  // ── Selects de cliente e processo ──────────────────────────────────────
  async function carregarClientes() {
    try {
      const j = await api('GET', API + '?clientes=1');
      clientes = j.data || [];
    } catch (e) { clientes = []; }
    ['lanCliente', 'finFiltroCliente'].forEach((id) => {
      const sel = $(id);
      if (!sel) return;
      const atual = sel.value;
      sel.innerHTML = `<option value="">${id === 'lanCliente' ? 'Sem cliente' : 'Todos os clientes'}</option>` +
        clientes.map((c) => `<option value="${c.id}">${esc(c.nome)}</option>`).join('');
      if (atual) sel.value = atual;
    });
  }
  async function carregarProcessos(clienteId, selId, rotuloVazio) {
    const sel = $(selId);
    if (!sel) return;
    sel.innerHTML = `<option value="">${rotuloVazio}</option>`;
    sel.disabled = !clienteId;
    if (!clienteId) return;
    try {
      const j = await api('GET', API + '?processos_do_cliente=' + encodeURIComponent(clienteId));
      const lista = j.data || [];
      sel.innerHTML = `<option value="">${lista.length ? rotuloVazio : 'Este cliente não tem processo'}</option>` +
        lista.map((p) => `<option value="${p.id}">${esc(p.numero || ('Processo #' + p.id))}</option>`).join('');
    } catch (e) { /* sem processos */ }
  }

  // ── Formulário: parcelar ───────────────────────────────────────────────
  function parcelarLigado() { return !!($('lanParcelar') && $('lanParcelar').checked); }
  function previa() {
    const box = $('lanParcelasPrevia');
    if (!box) return;
    const qtd = parseInt(($('lanQtdParcelas') || {}).value || '0', 10);
    const datas = vencimentos(($('lanPrimeiroVenc') || {}).value, qtd);
    const valor = numero(($('valor_fixo') || {}).value);
    const porParcela = ($('lanValorModo') || {}).value === 'parcela';
    const valores = porParcela ? Array(qtd).fill(valor) : dividir(valor, qtd);
    if (!datas.length || !valores.length || valor <= 0) { box.textContent = 'Preencha valor, quantidade e primeiro vencimento para ver as parcelas.'; return; }
    const total = valores.reduce((a, b) => a + b, 0);
    box.innerHTML = `<strong>${qtd} ${qtd === 1 ? 'lançamento' : 'parcelas'}, total ${brl(total)}</strong><ol>` +
      datas.map((d, i) => `<li>${dataBr(d)}: ${brl(valores[i])}</li>`).join('') + '</ol>';
  }
  function mostrarParcelas() {
    const box = $('lanParcelasBox');
    if (box) box.hidden = !parcelarLigado();
    const comp = $('data_referencia');
    if (comp) comp.closest('.form-group').style.opacity = parcelarLigado() ? '.45' : '';
    previa();
  }

  /** Chamado pelo dre.js ao clicar em "Adicionar lançamento" com "Parcelar" ligado. */
  async function salvarDoFormulario() {
    const msg = $('addAccountMsg');
    const descricao = (($('nome') || {}).value || '').trim();
    const valorTxt = (($('valor_fixo') || {}).value || '').trim();
    const qtd = parseInt(($('lanQtdParcelas') || {}).value || '0', 10);
    const primeiro = ($('lanPrimeiroVenc') || {}).value || '';
    const faltam = [];
    if (!descricao) faltam.push('descrição');
    if (!valorTxt) faltam.push('valor');
    if (!(qtd >= 1)) faltam.push('quantidade de parcelas');
    if (!primeiro) faltam.push('primeiro vencimento');
    if (faltam.length) { aviso(msg, 'Preencha: ' + faltam.join(', '), 'erro'); return false; }
    const corpo = {
      acao: 'criar', tipo: ($('tipo') || {}).value || 'receita', descricao,
      codigo: ($('codigo') || {}).value || '', cliente_id: ($('lanCliente') || {}).value || '',
      processo_id: ($('lanProcesso') || {}).value || '', quantidade: qtd, primeiro_vencimento: primeiro,
    };
    if (($('lanValorModo') || {}).value === 'parcela') corpo.valor_parcela = valorTxt; else corpo.valor_total = valorTxt;
    try {
      const j = await api('POST', API, corpo);
      aviso(msg, `${j.parcelas.length} ${j.parcelas.length === 1 ? 'lançamento criado' : 'parcelas criadas'} em "A receber e a pagar".`, 'ok');
      ['nome', 'valor_fixo'].forEach((id) => { if ($(id)) $(id).value = ''; });
      previa();
      if (typeof window.dreRecarregar === 'function') window.dreRecarregar();
      carregarVisao();
      carregarCarteira(); if (!$('finAbaProjecao')?.hidden) carregarProjecao();
      return true;
    } catch (e) {
      aviso(msg, e.message, 'erro');
      return false;
    }
  }

  // ── Painel: A receber e a pagar ────────────────────────────────────────
  const diasTxt = (n) => `${n} ${n === 1 ? 'dia' : 'dias'} de atraso`;
  function situacaoHtml(it) {
    let selo, extras = [];
    if (it.situacao === 'pago') {
      selo = `<span class="fin-sit fin-sit-ok">${it.tipo === 'despesa' ? 'Pago' : 'Recebido'} em ${dataBr(it.pago_em)}</span>`;
      extras.push(it.dias_atraso > 0 ? diasTxt(it.dias_atraso) : 'em dia');
      extras.push(it.juros > 0 ? `juros ${brl(it.juros)}` : 'sem juros');
    } else if (it.situacao === 'atrasado') {
      selo = '<span class="fin-sit fin-sit-atraso">Atrasado</span>';
      extras.push(diasTxt(it.dias_atraso));
    } else {
      selo = '<span class="fin-sit">Pendente</span>';
    }
    if (it.baixa_obs) extras.push(esc(it.baixa_obs));
    return selo + (extras.length ? `<span class="fin-detalhe">${extras.join(' · ')}</span>` : '');
  }
  /** Links para a ficha do cliente e do processo: de onde vem o lançamento. */
  function origemHtml(it) {
    const partes = [];
    if (it.cliente_id && it.cliente_nome) partes.push(`<a href="/clientes?open=${encodeURIComponent(it.cliente_id)}" title="Abrir o cliente">${esc(it.cliente_nome)}</a>`);
    if (it.processo_id && it.processo_numero) partes.push(`<a href="/processos?open=${encodeURIComponent(it.processo_id)}" title="Abrir o processo">Processo ${esc(it.processo_numero)}</a>`);
    return partes.join(' · ');
  }
  function totaisHtml(t) {
    const linha = (rotulo, x, baixado) => `<div class="fin-tot"><span>${rotulo}</span><b>${brl(x.total)}</b>
      <small>${baixado} ${brl(x.pago)} · pendente <strong>${brl(x.pendente)}</strong>${x.juros > 0 ? ` · juros ${brl(x.juros)}` : ''}</small></div>`;
    return linha('A receber', t.receita, 'recebido') + linha('A pagar', t.despesa, 'pago');
  }
  function grupoHtml(lista, rotulo) {
    if (!lista.length) return `<p class="fin-vazio">Nenhum título ${rotulo === 'Cliente' ? 'com cliente' : 'com processo'} neste filtro.</p>`;
    return `<table class="dre-table fin-grupo"><thead><tr><th>${rotulo}</th><th style="text-align:right">A receber</th><th style="text-align:right">Recebido</th>
      <th style="text-align:right">Pendente</th><th style="text-align:right">A pagar pendente</th></tr></thead><tbody>` +
      lista.map((g) => `<tr><td>${esc(g.nome || ('#' + g.id))}</td><td class="val-col">${brl(g.totais.receita.total)}</td>
        <td class="val-col">${brl(g.totais.receita.pago)}</td><td class="val-col"><strong>${brl(g.totais.receita.pendente)}</strong></td>
        <td class="val-col">${brl(g.totais.despesa.pendente)}</td></tr>`).join('') + '</tbody></table>';
  }
  async function carregarVisao() {
    const tbody = document.querySelector('#finTitulos tbody');
    if (!tbody) return;
    const qs = new URLSearchParams({ visao: '1' });
    [['cliente_id', 'finFiltroCliente'], ['processo_id', 'finFiltroProcesso'], ['tipo', 'finFiltroTipo'], ['situacao', 'finFiltroSituacao']]
      .forEach(([k, id]) => { const v = ($(id) || {}).value; if (v) qs.set(k, v); });
    try {
      const j = await api('GET', API + '?' + qs.toString());
      const v = j.data;
      ultimaVisao = v;
      $('finTotais').innerHTML = totaisHtml(v.totais);
      $('finPorCliente').innerHTML = grupoHtml(v.por_cliente, 'Cliente');
      $('finPorProcesso').innerHTML = grupoHtml(v.por_processo, 'Processo');
      if (!v.itens.length) { tbody.innerHTML = '<tr class="empty-row"><td colspan="7">Nenhum título neste filtro. Use "Parcelar ou lançar com vencimento" no formulário acima.</td></tr>'; return; }
      tbody.innerHTML = v.itens.map((it) => {
        const pago = it.situacao === 'pago';
        const acoes = pago
          ? `<button type="button" class="btn btn-ghost btn-sm" data-fin-acao="desfazer" data-id="${it.id}">Desfazer baixa</button>`
          : `<button type="button" class="btn btn-primary btn-sm" data-fin-acao="baixar" data-id="${it.id}">${it.tipo === 'despesa' ? 'Marcar pago' : 'Marcar recebido'}</button>
             <button type="button" class="btn btn-ghost btn-sm" data-fin-acao="editar" data-id="${it.id}">Editar</button>`;
        const cli = it.cliente_id && it.cliente_nome ? `<a class="fin-link" href="/clientes?open=${encodeURIComponent(it.cliente_id)}" title="Abrir o cliente">${esc(it.cliente_nome)}</a>` : '';
        const pro = it.processo_id && it.processo_numero ? `<a class="fin-link" href="/processos?open=${encodeURIComponent(it.processo_id)}" title="Abrir o processo">${esc(it.processo_numero)}</a>` : '';
        return `<tr data-id="${it.id}"><td>${dataBr(it.vencimento)}</td><td>${esc(it.nome)}</td>
          <td>${cli}</td><td>${pro}</td>
          <td class="val-col ${it.tipo === 'receita' ? 'val-receita' : 'val-despesa'}">${brl(it.valor_fixo)}</td>
          <td>${situacaoHtml(it)}</td><td style="text-align:right;white-space:nowrap">${acoes}</td></tr>`;
      }).join('');
    } catch (e) {
      tbody.innerHTML = `<tr class="empty-row"><td colspan="7">Erro: ${esc(e.message)}</td></tr>`;
    }
  }
  // ── Carteira de processos e projeção mensal (T3) ───────────────────────
  const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
  const mesBr = (ym) => { const [a, m] = String(ym).split('-'); return `${MESES[(+m) - 1]}/${a}`; };
  async function carregarCarteira() {
    const box = $('finCarteiraNums');
    if (!box) return;
    try {
      const [c, p] = await Promise.all([
        api('GET', '/api/processo_valores.php?carteira=1'),
        api('GET', '/api/processo_valores.php?projecao=1&meses=12&so_processos=1'),
      ]);
      const k = c.data;
      const num = (rot, val, sub) => `<div class="fin-tot"><span>${rot}</span><b>${val}</b><small>${sub}</small></div>`;
      box.innerHTML =
        num('Carteira potencial', brl(k.potencial), `${k.com_estimativa} ${k.com_estimativa === 1 ? 'processo' : 'processos'} com valor estimado`) +
        num('Em aberto', brl(k.em_aberto), `${k.abertos} sem resultado ainda`) +
        num('Projetado x obtido', `${brl(k.projetado)} → ${brl(k.obtido)}`, `${k.encerrados} com resultado${k.improcedentes ? `, ${k.improcedentes} improcedente${k.improcedentes > 1 ? 's' : ''}` : ''}`) +
        num('Diferença', brl(k.diferenca), k.aproveitamento === null ? 'sem projeção nos encerrados' : `aproveitamento de ${String(k.aproveitamento).replace('.', ',')}%`);
      const tb = document.querySelector('#finProjecao tbody');
      const linhas = Object.entries(p.data.meses || {});
      tb.innerHTML = (p.data.atrasado > 0 ? `<tr><td><strong>Em atraso</strong></td><td class="val-col val-despesa">${brl(p.data.atrasado)}</td><td class="val-col">—</td></tr>` : '') +
        linhas.map(([m, v]) => `<tr><td>${mesBr(m)}</td><td class="val-col val-receita">${brl(v.previsto)}</td><td class="val-col">${brl(v.recebido)}</td></tr>`).join('');
    } catch (e) {
      box.innerHTML = `<div class="fin-vazio">Erro: ${esc(e.message)}</div>`;
    }
  }

  // ── Aba Projeção (10/11/2026) ──────────────────────────────────────────
  async function carregarProjecao() {
    const rec = $('finProjReceber');
    if (!rec) return;
    try {
      const j = await api('GET', API + '?projecao=1&meses=12');
      const p = j.data;
      const cards = (x, verbo, baixado) => {
        const c = (rot, val, sub, destaque) => `<div class="fin-tot${destaque ? ' fin-tot-destaque' : ''}"><span>${rot}</span><b>${brl(val)}</b><small>${sub}</small></div>`;
        return c('Ainda neste mês', x.mes_atual, `vence até o fim de ${mesBr(p.mes_atual)}`) +
          c('Próximo mês', x.proximo_mes, `vence em ${mesBr(p.proximo_mes)}`) +
          c('Saldo futuro total', x.saldo_futuro, `tudo que ${verbo} de hoje em diante`, true) +
          c('Em atraso', x.atrasado, 'vencido e ainda pendente') +
          c(baixado + ' neste mês', x.baixado_mes, `total em aberto ${brl(x.total_aberto)}`);
      };
      rec.innerHTML = cards(p.receita, 'vence a receber', 'Recebido');
      $('finProjPagar').innerHTML = cards(p.despesa, 'vence a pagar', 'Pago');
      document.querySelector('#finProjMeses tbody').innerHTML = Object.entries(p.meses).map(([m, v]) =>
        `<tr><td>${mesBr(m)}</td><td class="val-col val-receita">${brl(v.receita)}</td><td class="val-col">${brl(v.recebido)}</td>
         <td class="val-col val-despesa">${brl(v.despesa)}</td><td class="val-col">${brl(v.pago)}</td></tr>`).join('');
    } catch (e) {
      rec.innerHTML = `<div class="fin-vazio">Erro: ${esc(e.message)}</div>`;
    }
  }
  function trocarAba(aba) {
    document.querySelectorAll('[data-fin-aba]').forEach((b) => {
      const ativa = b.dataset.finAba === aba;
      b.classList.toggle('btn-primary', ativa);
      b.classList.toggle('btn-ghost', !ativa);
      b.setAttribute('aria-selected', ativa ? 'true' : 'false');
    });
    $('finAbaTitulos').hidden = aba !== 'titulos';
    $('finAbaProjecao').hidden = aba !== 'projecao';
    if (aba === 'projecao') carregarProjecao();
  }

  function tituloDaVisao(id) { return ((ultimaVisao && ultimaVisao.itens) || []).find((x) => String(x.id) === String(id)); }

  // ── Modal: baixa e edição ──────────────────────────────────────────────
  let modo = null, alvo = null;
  function hojeBr() {
    const d = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Sao_Paulo' }));
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
  async function abrirModal(m, id) {
    let it = tituloDaVisao(id);
    if (!it) {
      try { const j = await api('GET', API + '?visao=1'); ultimaVisao = j.data; it = tituloDaVisao(id); } catch (e) { /* segue */ }
    }
    if (!it) return;
    modo = m; alvo = it;
    $('finModalTitulo').textContent = m === 'baixar' ? (it.tipo === 'despesa' ? 'Marcar como pago' : 'Marcar como recebido') : 'Editar parcela';
    $('finModalDesc').textContent = `${it.nome} · ${brl(it.valor_fixo)} · vence ${dataBr(it.vencimento)}`;
    $('finModalValorWrap').hidden = m !== 'editar';
    $('finModalJurosWrap').hidden = m !== 'baixar';
    $('finModalObsWrap').hidden = m !== 'baixar';
    $('finModalJuros').value = '';
    $('finModalObs').value = '';
    $('finModalDataRotulo').textContent = m === 'baixar' ? 'Data da baixa' : 'Vencimento';
    $('finModalData').value = m === 'baixar' ? hojeBr() : String(it.vencimento).slice(0, 10);
    $('finModalValor').value = (Number(it.valor_fixo) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    aviso($('finModalMsg'), '');
    mostrarAtraso();
    $('finModal').style.display = 'flex';
  }
  /** Na baixa, o atraso sai da data: vencimento até a data escolhida. */
  function mostrarAtraso() {
    const el = $('finModalAtraso');
    if (!el) return;
    if (modo !== 'baixar' || !alvo) { el.hidden = true; return; }
    const v = new Date(String(alvo.vencimento).slice(0, 10) + 'T12:00:00');
    const d = new Date(($('finModalData').value || '') + 'T12:00:00');
    const dias = Math.round((d - v) / 86400000);
    el.hidden = !(dias > 0);
    el.textContent = dias > 0 ? `${diasTxt(dias)} (venceu em ${dataBr(alvo.vencimento)}). Informe juros ou multa, se houver, e a observação.` : '';
  }
  function fecharModal() { $('finModal').style.display = 'none'; modo = null; alvo = null; }
  async function salvarModal() {
    if (!alvo) return;
    const btn = $('finModalSalvar');
    btn.disabled = true;
    try {
      if (modo === 'baixar') await api('POST', API, { acao: 'baixar', id: alvo.id, data: $('finModalData').value,
                                                      juros: $('finModalJuros').value, obs: $('finModalObs').value });
      else await api('POST', API, { acao: 'editar', id: alvo.id, valor: $('finModalValor').value, vencimento: $('finModalData').value });
      fecharModal();
      carregarVisao();
      carregarCarteira(); if (!$('finAbaProjecao')?.hidden) carregarProjecao();
      if (typeof window.dreRecarregar === 'function') window.dreRecarregar();
    } catch (e) {
      aviso($('finModalMsg'), e.message, 'erro');
    } finally {
      btn.disabled = false;
    }
  }

  function iniciar() {
    if (!$('finTitulos') && !$('lanCliente')) return;
    carregarClientes();
    $('lanCliente')?.addEventListener('change', (e) => carregarProcessos(e.target.value, 'lanProcesso', 'Sem processo'));
    $('lanParcelar')?.addEventListener('change', mostrarParcelas);
    ['lanQtdParcelas', 'lanPrimeiroVenc', 'lanValorModo', 'valor_fixo'].forEach((id) => {
      $(id)?.addEventListener('input', previa);
      $(id)?.addEventListener('change', previa);
    });
    $('finFiltroCliente')?.addEventListener('change', async (e) => {
      await carregarProcessos(e.target.value, 'finFiltroProcesso', 'Todos os processos');
      carregarVisao();
    });
    ['finFiltroProcesso', 'finFiltroTipo', 'finFiltroSituacao'].forEach((id) => $(id)?.addEventListener('change', carregarVisao));
    document.querySelector('#finTitulos')?.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-fin-acao]');
      if (!b) return;
      const acao = b.dataset.finAcao, id = b.dataset.id;
      if (acao === 'desfazer') {
        b.disabled = true;
        try { await api('POST', API, { acao: 'desfazer', id }); carregarVisao(); carregarCarteira(); if (!$('finAbaProjecao')?.hidden) carregarProjecao(); if (window.dreRecarregar) window.dreRecarregar(); }
        catch (err) { aviso($('finPainelMsg'), err.message, 'erro'); }
        finally { b.disabled = false; }
      } else {
        abrirModal(acao, id);
      }
    });
    $('finModalFechar')?.addEventListener('click', fecharModal);
    $('finModalCancelar')?.addEventListener('click', fecharModal);
    $('finModalSalvar')?.addEventListener('click', salvarModal);
    $('finModalData')?.addEventListener('change', mostrarAtraso);
    $('finModalData')?.addEventListener('input', mostrarAtraso);
    $('finModal')?.addEventListener('click', (e) => { if (e.target.id === 'finModal') fecharModal(); });
    document.querySelectorAll('[data-fin-aba]').forEach((b) => b.addEventListener('click', () => trocarAba(b.dataset.finAba)));
    mostrarParcelas();
    carregarVisao();
    carregarCarteira(); if (!$('finAbaProjecao')?.hidden) carregarProjecao();
  }

  window.FinParcelas = {
    ativo: parcelarLigado,
    salvarDoFormulario,
    cliente: () => ($('lanCliente') || {}).value || '',
    processo: () => ($('lanProcesso') || {}).value || '',
    abrirEdicao: (id) => abrirModal('editar', id),
    abrirBaixa: (id) => abrirModal('baixar', id),
    /** Desfaz a baixa (botão da tabela do DRE). */
    desfazer: async (id) => {
      await api('POST', API, { acao: 'desfazer', id });
      carregarVisao(); carregarCarteira(); if (!$('finAbaProjecao')?.hidden) carregarProjecao();
      if (typeof window.dreRecarregar === 'function') window.dreRecarregar();
    },
    origemHtml,
    recarregar: carregarVisao,
    _vencimentos: vencimentos,
    _dividir: dividir,
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();
