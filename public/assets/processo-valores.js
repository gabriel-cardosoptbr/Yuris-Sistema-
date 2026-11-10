/**
 * processo-valores.js — bloco "Valores e resultado" da ficha do processo (T3, 10/11/2026).
 *
 * Carrega quando o modal abre (evento 'processo:modal-opened' do processos.js) e
 * grava em /api/processo_valores.php DEPOIS que o processo foi salvo
 * (window.ProcValores.salvar, chamado pelo processos.js). Só grava se algo mudou:
 * processo antigo, sem valores, salva como sempre salvou.
 * O resultado vira receitas previstas no Financeiro (App\Processos\ValoresDoProcesso).
 */
(function () {
  'use strict';
  const API = '/api/processo_valores.php';
  const $ = (id) => document.getElementById(id);
  const brl = (v) => (Number(v) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const dataBr = (d) => (d ? String(d).slice(0, 10).split('-').reverse().join('/') : '');
  const numero = (s) => {
    s = String(s ?? '').trim().replace(/[^\d,.\-]/g, '');
    if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
    return Math.round((parseFloat(s) || 0) * 100) / 100;
  };
  const dinheiro = (v) => (v === null || v === undefined || v === '' ? '' :
    Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
  let carregado = '';      // retrato do que veio do servidor, para saber se mudou
  let processoAtual = null;

  // Mesmas regras de App\Financas\Parcelamento (data pelo dia do primeiro; centavos na última).
  function vencimentos(primeiro, qtd) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(primeiro || '');
    if (!m || qtd < 1) return [];
    const ano = +m[1], mes = +m[2], dia = +m[3], out = [];
    for (let i = 0; i < qtd; i++) {
      const mm0 = mes - 1 + i, a = ano + Math.floor(mm0 / 12), mm = (mm0 % 12) + 1;
      out.push(`${a}-${String(mm).padStart(2, '0')}-${String(Math.min(dia, new Date(a, mm, 0).getDate())).padStart(2, '0')}`);
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

  function campos() {
    const tipo = $('pvTipo').value;
    const temValor = tipo !== '' && tipo !== 'improcedente';
    const forma = $('pvForma').value;
    return {
      valor_causa: $('pvCausa').value.trim(),
      valor_estimado: $('pvEstimado').value.trim(),
      resultado_tipo: tipo,
      valor_resultado: temValor ? $('pvValor').value.trim() : '',
      resultado_forma: temValor ? forma : '',
      resultado_parcelas: temValor && forma === 'parcelado' ? $('pvParcelas').value : '',
      resultado_primeiro_vencimento: temValor ? $('pvPrimeiro').value : '',
    };
  }
  const retrato = () => JSON.stringify(Object.assign(campos(), {
    valor_causa: numero($('pvCausa').value), valor_estimado: numero($('pvEstimado').value), valor_resultado: numero($('pvValor').value),
  }));

  function mostrarCampos() {
    const tipo = $('pvTipo').value;
    const temValor = tipo !== '' && tipo !== 'improcedente';
    document.querySelectorAll('#procValoresBloco .pv-com-valor').forEach((el) => { el.hidden = !temValor; });
    const parcelado = $('pvForma').value === 'parcelado';
    document.querySelectorAll('#procValoresBloco .pv-parcelado').forEach((el) => { el.hidden = !temValor || !parcelado; });
    $('pvPrimeiroRotulo').textContent = parcelado ? 'Primeiro vencimento' : 'Vencimento';
    if (temValor && !$('pvPrimeiro').value) {
      const d = new Date(); d.setMonth(d.getMonth() + 1);
      $('pvPrimeiro').value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }
    previa();
  }

  function previa() {
    const el = $('pvPrevia');
    const tipo = $('pvTipo').value;
    if (tipo === '') { el.textContent = 'Sem resultado: nada vai para o Financeiro.'; return; }
    if (tipo === 'improcedente') { el.textContent = 'Improcedente: resultado R$ 0,00, nenhuma receita prevista. Parcelas pendentes deste processo saem do Financeiro (as já recebidas ficam).'; return; }
    const valor = numero($('pvValor').value);
    const qtd = $('pvForma').value === 'parcelado' ? parseInt($('pvParcelas').value || '0', 10) : 1;
    const datas = vencimentos($('pvPrimeiro').value, qtd);
    const valores = dividir(valor, qtd);
    if (!valor || !datas.length || !valores.length) { el.textContent = 'Preencha valor e vencimento para ver o que vai para o Financeiro.'; return; }
    el.innerHTML = `<strong>Vai para o Financeiro (a receber):</strong> ` +
      datas.map((d, i) => `${dataBr(d)} ${brl(valores[i])}`).join(' · ') +
      '. Parcelas já recebidas não mudam; o restante é dividido entre as pendentes.';
  }

  function mostrarFinanceiro(fin) {
    const el = $('pvFinanceiro');
    if (!fin || !fin.parcelas) { el.textContent = ''; return; }
    el.innerHTML = `<strong>No Financeiro:</strong> ${fin.parcelas} ${fin.parcelas === 1 ? 'lançamento' : 'parcelas'}, total ${brl(fin.total)},
      recebido ${brl(fin.recebido)}, pendente ${brl(fin.pendente)} · <a href="/financas#finPainel">Ver em Finanças</a>`;
  }

  function preencher(v) {
    v = v || {};
    $('pvCausa').value = dinheiro(v.valor_causa);
    $('pvEstimado').value = dinheiro(v.valor_estimado);
    $('pvTipo').value = v.resultado_tipo || '';
    $('pvValor').value = v.resultado_tipo === 'improcedente' ? '' : dinheiro(v.valor_resultado);
    $('pvForma').value = v.resultado_forma || 'avista';
    $('pvParcelas').value = v.resultado_parcelas && v.resultado_parcelas > 1 ? v.resultado_parcelas : 2;
    $('pvPrimeiro').value = v.resultado_primeiro_vencimento || '';
    $('pvAviso').hidden = true;
    mostrarFinanceiro(v.financeiro);
    mostrarCampos();
    carregado = retrato();
  }

  async function carregar(id) {
    processoAtual = id || null;
    preencher(null);
    if (!id) return;
    try {
      const res = await fetch(API + '?processo_id=' + encodeURIComponent(id), { credentials: 'same-origin', cache: 'no-store' });
      const j = await res.json();
      if (res.ok && processoAtual === id) preencher(j.data);
    } catch (e) { /* bloco fica vazio; salvar o processo não depende dele */ }
  }

  /** Grava os valores do processo, se mudaram. Lança erro com a mensagem da API. */
  async function salvar(id) {
    if (!$('procValoresBloco') || !id) return null;
    if (retrato() === carregado) return null;
    const res = await fetch(API, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ processo_id: parseInt(id, 10) }, campos())),
    });
    let j = {};
    try { j = await res.json(); } catch (e) { /* sem corpo */ }
    if (!res.ok) {
      const msg = j.error || ('Erro ' + res.status);
      const av = $('pvAviso'); av.textContent = msg; av.hidden = false;
      throw new Error(msg);
    }
    preencher(j.data);
    return j.data;
  }

  function iniciar() {
    if (!$('procValoresBloco')) return;
    $('pvTipo').addEventListener('change', mostrarCampos);
    $('pvForma').addEventListener('change', mostrarCampos);
    ['pvValor', 'pvParcelas', 'pvPrimeiro'].forEach((id) => { $(id).addEventListener('input', previa); $(id).addEventListener('change', previa); });
    document.addEventListener('processo:modal-opened', (e) => carregar(e.detail && e.detail.id ? parseInt(e.detail.id, 10) : null));
    preencher(null);
  }

  window.ProcValores = { salvar, carregar, mudou: () => retrato() !== carregado };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();
