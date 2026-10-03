/**
 * Reembolsos da tela Finanças (edição CRM, 03/10/2026).
 *
 * Alguém pagou uma conta da empresa do próprio bolso e a empresa devolve, à
 * vista ou parcelado. Fala com /api/reembolsos.php; a regra de dinheiro (divisão
 * das parcelas, vencimentos, situação) mora em App\Financas\Reembolso. Aqui só
 * se mostra uma prévia da divisão, com a mesma conta, para quem está digitando.
 */
(function () {
  'use strict';
  const API = '/api/reembolsos.php';
  const $ = id => document.getElementById(id);
  if (!$('reembPanel')) return;

  const CSRF = document.querySelector('[name=csrf_token]')?.value || '';
  const brl = v => 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const data = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : '';
  const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const toast = (m, t) => { if (window.Yuris && Yuris.toast) Yuris.toast(m, t || 'info'); };
  const hojeLocal = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };

  const SITUACAO = {
    pendente: ['A pagar', 'tbadge-fixa'],
    pagando:  ['Pagando', 'tbadge-variavel'],
    atrasado: ['Atrasado', 'tbadge-despesa'],
    pago:     ['Pago', 'tbadge-receita'],
  };
  const selo = s => { const [t, c] = SITUACAO[s] || [s, 'tbadge-fixa']; return `<span class="tbadge ${c}">${t}</span>`; };

  let lista = [], filtro = 'abertos', hoje = hojeLocal(), aberto = null;

  /** "1.234,56" | "1234,56" | "1234.56" => centavos, ou null. */
  function centavos(txt) {
    let s = String(txt || '').trim().replace(/^R\$\s*/, '');
    if (!s) return null;
    if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
    if (!/^\d+(\.\d{1,2})?$/.test(s)) return null;
    return Math.round(parseFloat(s) * 100);
  }
  // Mesma conta de Reembolso::dividir e ::vencimentos.
  function dividir(total, n) { const b = Math.floor(total / n), r = total - b * n; return Array.from({ length: n }, (_, i) => b + (i < r ? 1 : 0)); }
  function vencimentos(primeiro, n) {
    const [a, m, d] = primeiro.split('-').map(Number);
    return Array.from({ length: n }, (_, i) => {
      const ano = a + Math.floor((m - 1 + i) / 12), mes = (m - 1 + i) % 12;
      const ultimo = new Date(Date.UTC(ano, mes + 1, 0)).getUTCDate();
      return `${ano}-${String(mes + 1).padStart(2, '0')}-${String(Math.min(d, ultimo)).padStart(2, '0')}`;
    });
  }

  async function chamar(corpo) {
    const r = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(corpo) });
    let j = {};
    try { j = await r.json(); } catch (e) { /* resposta sem JSON */ }
    if (!r.ok || !j.success) throw new Error(j.error || 'Não foi possível salvar. Tente de novo.');
    return j;
  }

  async function carregar() {
    try {
      const r = await fetch(API, { credentials: 'same-origin' });
      const j = await r.json();
      if (!r.ok) throw new Error(j.error || 'erro');
      lista = j.data || []; hoje = j.hoje || hoje;
      const s = j.resumo || {};
      $('reembAPagar').textContent = brl(s.a_pagar);
      $('reembAtrasado').textContent = brl(s.atrasado);
      $('reembAtrasoCaixa').classList.toggle('reemb-num-atraso', (s.atrasado || 0) > 0); // vermelho só quando há atraso
      $('reembPago').textContent = brl(s.pago);
      $('reembEmAberto').textContent = String(s.em_aberto || 0);
      desenhar();
    } catch (e) {
      $('reembTabela').tBodies[0].innerHTML = '<tr class="empty-row"><td colspan="7">Não foi possível carregar os reembolsos. Recarregue a página.</td></tr>';
    }
  }

  function desenhar() {
    const tb = $('reembTabela').tBodies[0];
    const itens = lista.filter(r => filtro === 'todos' || (filtro === 'pagos' ? r.situacao === 'pago' : r.situacao !== 'pago'));
    if (!itens.length) {
      const msg = !lista.length ? 'Nenhum reembolso cadastrado. Clique em "Novo reembolso" para começar.'
        : filtro === 'pagos' ? 'Nenhum reembolso pago ainda.' : 'Nenhum reembolso em aberto. Tudo devolvido.';
      tb.innerHTML = `<tr class="empty-row"><td colspan="7">${msg}</td></tr>`;
      return;
    }
    tb.innerHTML = itens.map(r => {
      const pct = r.qtd_parcelas ? Math.round(r.qtd_pagas / r.qtd_parcelas * 100) : 0;
      const forma = r.qtd_parcelas > 1 ? `${r.qtd_parcelas}x` : 'À vista';
      const detalhe = r.situacao === 'pago' ? 'Tudo devolvido'
        : `${r.qtd_pagas} de ${r.qtd_parcelas} paga${r.qtd_parcelas > 1 ? 's' : ''} · falta ${brl(r.valor_aberto)}` + (r.proximo_vencimento ? ` · vence ${data(r.proximo_vencimento)}` : '');
      return `<tr data-id="${r.id}">
        <td><b>${esc(r.favorecido)}</b></td>
        <td class="reemb-desc">${esc(r.descricao)}</td>
        <td class="reemb-nowrap">${data(r.data_despesa)}</td>
        <td class="val-col">${brl(r.valor_total)}</td>
        <td><div class="reemb-prog"><span>${forma}</span><div class="reemb-barra"><i style="width:${pct}%"></i></div><small>${detalhe}</small></div></td>
        <td>${selo(r.situacao)}</td>
        <td><span class="reemb-acoes">
          <button type="button" class="btn btn-primary btn-sm" data-acao="parcelas">${r.situacao === 'pago' ? 'Ver pagamentos' : 'Registrar pagamento'}</button>
          <button type="button" class="btn btn-ghost btn-sm" data-acao="editar">Editar</button>
          <button type="button" class="btn btn-ghost btn-sm" data-acao="excluir">Excluir</button>
        </span></td>
      </tr>`;
    }).join('');
  }

  // ── Novo / editar ────────────────────────────────────────────────────────
  const sel = $('reembParcelas');
  sel.innerHTML = Array.from({ length: 24 }, (_, i) => `<option value="${i + 1}">${i === 0 ? 'À vista' : (i + 1) + 'x (parcelado)'}</option>`).join('');

  function previa() {
    const n = parseInt(sel.value, 10) || 1, c = centavos($('reembValor').value), prim = $('reembPrimeiro').value;
    $('reembPrimeiroRotulo').textContent = n > 1 ? 'Vencimento da 1ª parcela' : 'Vencimento';
    const el = $('reembPrevia');
    if (c === null || c <= 0) { el.textContent = 'Informe o valor'; return; }
    if (c < n) { el.textContent = 'Valor pequeno demais para tantas parcelas'; return; }
    const vs = dividir(c, n), ds = prim ? vencimentos(prim, n) : [];
    if (n === 1) { el.textContent = brl(c / 100) + (ds[0] ? ' em ' + data(ds[0]) : ''); return; }
    const iguais = vs[0] === vs[n - 1];
    el.textContent = (iguais ? `${n}x de ${brl(vs[0] / 100)}` : `${n}x: ${brl(vs[0] / 100)} nas primeiras, ${brl(vs[n - 1] / 100)} nas demais`)
      + (ds.length ? `, de ${data(ds[0])} a ${data(ds[n - 1])}` : '');
  }
  ['reembValor', 'reembPrimeiro'].forEach(id => { $(id).addEventListener('input', previa); $(id).addEventListener('change', previa); });
  sel.addEventListener('change', previa);

  function abrirForm(r) {
    $('reembId').value = r ? r.id : '';
    $('reembModalTitulo').textContent = r ? 'Editar reembolso' : 'Novo reembolso';
    $('reembFavorecido').value = r ? r.favorecido : '';
    $('reembDescricao').value = r ? r.descricao : '';
    $('reembData').value = r ? r.data_despesa : hoje;
    $('reembValor').value = r ? Number(r.valor_total).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';
    sel.value = String(r ? r.qtd_parcelas : 1);
    $('reembPrimeiro').value = r ? (r.parcelas[0]?.vencimento || hoje) : hoje;
    $('reembObs').value = r ? (r.observacao || '') : '';
    const travado = !!(r && r.qtd_pagas > 0);
    ['reembValor', 'reembParcelas', 'reembPrimeiro'].forEach(id => { $(id).disabled = travado; });
    $('reembTravado').style.display = travado ? 'block' : 'none';
    $('reembMsg').style.display = 'none';
    previa();
    $('reembModal').style.display = 'flex';
    setTimeout(() => $('reembFavorecido').focus(), 60);
  }

  $('reembNovoBtn').addEventListener('click', () => abrirForm(null));

  $('reembSalvarBtn').addEventListener('click', async () => {
    const msg = $('reembMsg'), btn = $('reembSalvarBtn');
    const erro = t => { msg.textContent = t; msg.style.display = 'block'; };
    const c = centavos($('reembValor').value);
    if (!$('reembFavorecido').value.trim()) return erro('Informe para quem é o reembolso.');
    if (!$('reembDescricao').value.trim()) return erro('Descreva o que foi pago.');
    if (!$('reembData').value) return erro('Informe a data da despesa.');
    if (c === null || c <= 0) return erro('Informe um valor maior que zero, como 150,00.');
    if (!$('reembPrimeiro').value) return erro('Informe o vencimento.');
    msg.style.display = 'none';
    const id = parseInt($('reembId').value, 10) || 0;
    btn.disabled = true;
    try {
      await chamar({
        acao: id ? 'atualizar' : 'criar', id,
        favorecido: $('reembFavorecido').value.trim(), descricao: $('reembDescricao').value.trim(),
        data_despesa: $('reembData').value, valor_total: (c / 100).toFixed(2),
        parcelas: parseInt(sel.value, 10) || 1, primeiro_vencimento: $('reembPrimeiro').value,
        observacao: $('reembObs').value.trim(),
      });
      $('reembModal').style.display = 'none';
      toast(id ? 'Reembolso atualizado.' : 'Reembolso cadastrado.', 'success');
      if (!id && filtro === 'pagos') definirFiltro('abertos');
      carregar();
    } catch (e) { erro(e.message); }
    finally { btn.disabled = false; }
  });

  // ── Parcelas / pagamentos ────────────────────────────────────────────────
  // Marcar como paga abre o quadro "Registrar pagamento" com quem pagou (já com
  // o nome de quem está logado) e a data (hoje). Vale para uma parcela ou, em
  // "Marcar todas como pagas", para todas as em aberto.
  const usuario = $('reembPanel').dataset.usuario || '';
  let aPagar = null; // { tipo: 'parcela', id } | { tipo: 'todas' }

  function desenharParcelas(r) {
    aberto = r;
    fecharPagar();
    $('reembParcelasTitulo').textContent = r.favorecido + ': ' + brl(r.valor_total);
    $('reembParcelasSub').textContent = r.descricao + ' · despesa de ' + data(r.data_despesa)
      + (r.situacao === 'pago' ? ' · tudo devolvido' : ' · falta ' + brl(r.valor_aberto));
    $('reembParcelasLinhas').innerHTML = r.parcelas.map(p => {
      const situ = p.pago_em ? `<span class="tbadge tbadge-receita">Paga em ${data(p.pago_em)}</span>${p.pago_por_nome ? '<small class="reemb-quem">por ' + esc(p.pago_por_nome) + '</small>' : ''}`
        : p.atrasada ? '<span class="tbadge tbadge-despesa">Atrasada</span>' : '<span class="tbadge tbadge-fixa">A pagar</span>';
      const botao = p.pago_em
        ? `<button type="button" class="btn btn-ghost btn-sm" data-desfazer="${p.id}">Desfazer</button>`
        : `<button type="button" class="btn btn-primary btn-sm" data-pagar="${p.id}">Marcar como paga</button>`;
      return `<tr><td class="reemb-nowrap">${r.qtd_parcelas > 1 ? p.numero + 'ª de ' + r.qtd_parcelas : 'À vista'}</td>
        <td class="reemb-nowrap">${data(p.vencimento)}</td><td class="val-col">${brl(p.valor)}</td><td>${situ}</td><td>${botao}</td></tr>`;
    }).join('');
    const obs = $('reembParcelasObs');
    obs.style.display = r.observacao ? 'block' : 'none';
    obs.textContent = r.observacao || '';
    $('reembQuitarBtn').style.display = r.qtd_pagas < r.qtd_parcelas && r.qtd_parcelas > 1 ? '' : 'none';
    $('reembParcelasMsg').style.display = 'none';
  }

  function abrirPagar(alvo) {
    aPagar = alvo;
    const r = aberto;
    if (alvo.tipo === 'parcela') {
      const p = r.parcelas.find(x => x.id === alvo.id);
      $('reembPagarTitulo').textContent = 'Registrar pagamento ' + (r.qtd_parcelas > 1 ? `da ${p.numero}ª parcela` : 'do reembolso') + ` (${brl(p.valor)})`;
    } else {
      const n = r.qtd_parcelas - r.qtd_pagas;
      $('reembPagarTitulo').textContent = `Registrar pagamento das ${n} parcelas em aberto (${brl(r.valor_aberto)})`;
    }
    if (!$('reembPagoPor').value) $('reembPagoPor').value = usuario;
    if (!$('reembPagoEm').value) $('reembPagoEm').value = hoje;
    $('reembParcelasMsg').style.display = 'none';
    $('reembPagar').style.display = 'block';
    $('reembPagar').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    setTimeout(() => $('reembPagoPor').focus(), 60);
  }
  function fecharPagar() { aPagar = null; $('reembPagar').style.display = 'none'; }

  function abrirParcelas(r) {
    $('reembPagoPor').value = usuario;
    $('reembPagoEm').value = hoje;
    desenharParcelas(r);
    $('reembParcelasModal').style.display = 'flex';
  }

  async function acaoParcela(corpo, ok) {
    const msg = $('reembParcelasMsg'), btn = $('reembPagarConfirmar');
    btn.disabled = true;
    try {
      const j = await chamar(corpo);
      if (j.data) desenharParcelas(j.data);
      toast(ok, 'success');
      carregar();
    } catch (e) { msg.textContent = e.message; msg.style.display = 'block'; }
    finally { btn.disabled = false; }
  }

  $('reembParcelasLinhas').addEventListener('click', e => {
    const pagar = e.target.closest('[data-pagar]'), desfazer = e.target.closest('[data-desfazer]');
    if (pagar) abrirPagar({ tipo: 'parcela', id: +pagar.dataset.pagar });
    if (desfazer) acaoParcela({ acao: 'desfazer', parcela_id: +desfazer.dataset.desfazer }, 'Pagamento desfeito.');
  });
  $('reembQuitarBtn').addEventListener('click', () => { if (aberto) abrirPagar({ tipo: 'todas' }); });
  $('reembPagarCancelar').addEventListener('click', fecharPagar);
  $('reembPagarConfirmar').addEventListener('click', () => {
    if (!aberto || !aPagar) return;
    const msg = $('reembParcelasMsg');
    const quem = $('reembPagoPor').value.trim(), em = $('reembPagoEm').value;
    if (!quem) { msg.textContent = 'Informe quem fez o pagamento.'; msg.style.display = 'block'; return; }
    if (!em) { msg.textContent = 'Informe a data do pagamento.'; msg.style.display = 'block'; return; }
    if (aPagar.tipo === 'parcela') acaoParcela({ acao: 'pagar', parcela_id: aPagar.id, pago_em: em, pago_por_nome: quem }, 'Pagamento registrado.');
    else acaoParcela({ acao: 'quitar', id: aberto.id, pago_em: em, pago_por_nome: quem }, 'Reembolso quitado.');
  });

  // ── Tabela: ações e filtro ───────────────────────────────────────────────
  $('reembTabela').addEventListener('click', async e => {
    const b = e.target.closest('[data-acao]'); if (!b) return;
    const r = lista.find(x => x.id === +b.closest('tr').dataset.id); if (!r) return;
    if (b.dataset.acao === 'parcelas') return abrirParcelas(r);
    if (b.dataset.acao === 'editar') return abrirForm(r);
    if (b.dataset.acao === 'excluir') {
      const pergunta = `Excluir o reembolso de ${r.favorecido} (${brl(r.valor_total)})?` + (r.qtd_pagas ? ' Os pagamentos registrados nele saem junto.' : '');
      const sim = window.Yuris && Yuris.confirm ? await Yuris.confirm(pergunta, { danger: true, okLabel: 'Excluir' }) : false;
      if (!sim) return;
      try { await chamar({ acao: 'excluir', id: r.id }); toast('Reembolso excluído.', 'success'); carregar(); }
      catch (err) { toast(err.message, 'error'); }
    }
  });

  function definirFiltro(f) {
    filtro = f;
    document.querySelectorAll('.reemb-filtros [data-filtro]').forEach(b => {
      const ativo = b.dataset.filtro === f;
      b.classList.toggle('btn-primary', ativo); b.classList.toggle('btn-ghost', !ativo);
    });
    desenhar();
  }
  document.querySelectorAll('.reemb-filtros [data-filtro]').forEach(b => b.addEventListener('click', () => definirFiltro(b.dataset.filtro)));

  // ── Fechar modais: botão, fundo e Esc ────────────────────────────────────
  const fechar = id => { $(id).style.display = 'none'; };
  document.querySelectorAll('[data-fechar]').forEach(b => b.addEventListener('click', () => fechar(b.dataset.fechar)));
  ['reembModal', 'reembParcelasModal'].forEach(id => $(id).addEventListener('click', e => { if (e.target.id === id) fechar(id); }));
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if ($('reembPagar').style.display === 'block') return fecharPagar();
    ['reembModal', 'reembParcelasModal'].forEach(fechar);
  });

  carregar();
})();
