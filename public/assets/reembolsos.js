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
  let usuarios = [], pessoas = [], filtroPessoa = '', filtroTipo = '';

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
      usuarios = j.usuarios || []; pessoas = j.pessoas || [];
      desenharPessoas();
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

  // ── Por pessoa (estorno e reembolso, 15/10/2026) ──────────────────────────
  // O saldo de cada pessoa vem pronto de Reembolso::porPessoa. Clicar no cartão
  // filtra a tabela por ela; clicar de novo tira o filtro.
  const chavePessoa = r => r.favorecido_user_id ? 'u' + r.favorecido_user_id : 'n' + String(r.favorecido || '').trim().toLowerCase();
  const chaveDoResumo = p => p.user_id ? 'u' + p.user_id : 'n' + String(p.nome || '').trim().toLowerCase();
  const SIT_PESSOA = { quitado: ['Quitado', 'tbadge-receita'], parcial: ['Pago em parte', 'tbadge-variavel'], pendente: ['Pendente', 'tbadge-fixa'], atrasado: ['Em atraso', 'tbadge-despesa'] };
  const nomeTipo = t => t === 'estorno' ? 'estorno' : 'reembolso';

  function desenharPessoas() {
    const el = $('reembPessoasLista');
    if (!pessoas.length) { el.innerHTML = '<div class="reemb-vazio-pessoas">Ninguém tem nada a receber. Os lançamentos aparecem aqui por pessoa.</div>'; }
    else el.innerHTML = pessoas.map(p => {
      const [t, c] = SIT_PESSOA[p.situacao] || [p.situacao, 'tbadge-fixa'];
      const partes = [p.reembolso_aberto > 0 ? 'reembolso ' + brl(p.reembolso_aberto) : '', p.estorno_aberto > 0 ? 'estorno ' + brl(p.estorno_aberto) : ''].filter(Boolean).join(' · ');
      const k = chaveDoResumo(p);
      return `<button type="button" class="reemb-pessoa${filtroPessoa === k ? ' ativa' : ''}" data-pessoa="${esc(k)}">
        <div class="reemb-pessoa-topo"><b>${esc(p.nome)}</b><span class="tbadge ${c}">${t}</span></div>
        <div class="reemb-pessoa-falta"><span>Falta pagar</span><b>${brl(p.aberto)}</b></div>
        <small>Já pago ${brl(p.pago)} de ${brl(p.total)}${partes ? '<br>Em aberto: ' + partes : ''}${p.atrasado > 0 ? '<br>' + brl(p.atrasado) + ' em atraso' : ''}</small>
      </button>`;
    }).join('');
    const selP = $('reembFiltroPessoa'), atual = filtroPessoa;
    selP.innerHTML = '<option value="">Todas as pessoas</option>' + pessoas.map(p => `<option value="${esc(chaveDoResumo(p))}">${esc(p.nome)}</option>`).join('');
    selP.value = pessoas.some(p => chaveDoResumo(p) === atual) ? atual : '';
    filtroPessoa = selP.value;
  }

  $('reembPessoasLista').addEventListener('click', e => {
    const b = e.target.closest('[data-pessoa]'); if (!b) return;
    filtroPessoa = filtroPessoa === b.dataset.pessoa ? '' : b.dataset.pessoa;
    $('reembFiltroPessoa').value = filtroPessoa;
    document.querySelectorAll('.reemb-pessoa').forEach(x => x.classList.toggle('ativa', x.dataset.pessoa === filtroPessoa));
    desenhar();
  });
  $('reembFiltroPessoa').addEventListener('change', e => {
    filtroPessoa = e.target.value;
    document.querySelectorAll('.reemb-pessoa').forEach(x => x.classList.toggle('ativa', x.dataset.pessoa === filtroPessoa));
    desenhar();
  });
  $('reembFiltroTipo').addEventListener('change', e => { filtroTipo = e.target.value; desenhar(); });

  function desenhar() {
    const tb = $('reembTabela').tBodies[0];
    const itens = lista.filter(r => filtro === 'todos' || (filtro === 'pagos' ? r.situacao === 'pago' : r.situacao !== 'pago'))
      .filter(r => !filtroPessoa || chavePessoa(r) === filtroPessoa)
      .filter(r => !filtroTipo || (r.tipo || 'reembolso') === filtroTipo);
    if (!itens.length) {
      const msg = !lista.length ? 'Nada cadastrado. Use "Novo reembolso" ou "Novo estorno" para começar.'
        : filtro === 'pagos' ? 'Nenhum lançamento pago neste filtro.' : 'Nada em aberto neste filtro. Tudo pago.';
      tb.innerHTML = `<tr class="empty-row"><td colspan="7">${msg}</td></tr>`;
      return;
    }
    tb.innerHTML = itens.map(r => {
      const pct = r.qtd_parcelas ? Math.round(r.qtd_pagas / r.qtd_parcelas * 100) : 0;
      const forma = r.qtd_parcelas > 1 ? `${r.qtd_parcelas}x` : 'À vista';
      const detalhe = r.situacao === 'pago' ? 'Tudo pago'
        : `${r.qtd_pagas} de ${r.qtd_parcelas} paga${r.qtd_parcelas > 1 ? 's' : ''} · falta ${brl(r.valor_aberto)}` + (r.proximo_vencimento ? ` · vence ${data(r.proximo_vencimento)}` : '');
      return `<tr data-id="${r.id}">
        <td><b>${esc(r.favorecido)}</b><span class="reemb-tipo reemb-tipo-${esc(r.tipo || 'reembolso')}">${nomeTipo(r.tipo)}</span></td>
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

  // Tipo muda os textos do formulário; a regra (parcelas, pagamento) é a mesma.
  function textosDoTipo() {
    const est = $('reembTipo').value === 'estorno', id = $('reembId').value;
    $('reembModalTitulo').textContent = (id ? 'Editar ' : 'Novo ') + (est ? 'estorno' : 'reembolso');
    $('reembModalSub').textContent = est ? 'Um valor que a empresa precisa devolver a uma pessoa' : 'O que a empresa precisa devolver a alguém que pagou uma conta dela';
    $('reembPessoaRotulo').textContent = est ? 'Para quem é o estorno' : 'Quem pagou a conta';
    $('reembDataRotulo').textContent = est ? 'Data do estorno' : 'Data da despesa';
    $('reembDescricao').placeholder = est ? 'Ex: devolução de valor cobrado a mais, devolução de aporte' : 'Ex: hospedagem do site, combustível da visita ao cliente';
    $('reembSalvarBtn').textContent = est ? 'Salvar estorno' : 'Salvar reembolso';
  }
  $('reembTipo').addEventListener('change', textosDoTipo);

  // "Para quem": os usuários da conta, ou "Outra pessoa" com o nome digitado.
  function preencherPessoas(r) {
    const s = $('reembPessoa');
    s.innerHTML = '<option value="">Escolha a pessoa</option>' + usuarios.map(u => `<option value="${u.id}">${esc(u.nome)}</option>`).join('') + '<option value="outra">Outra pessoa…</option>';
    if (r && r.favorecido_user_id && usuarios.some(u => u.id === r.favorecido_user_id)) s.value = String(r.favorecido_user_id);
    else if (r) s.value = 'outra';
    else s.value = '';
    $('reembFavorecido').value = r && s.value === 'outra' ? r.favorecido : '';
    $('reembOutraCaixa').style.display = s.value === 'outra' ? '' : 'none';
  }
  $('reembPessoa').addEventListener('change', () => {
    const outra = $('reembPessoa').value === 'outra';
    $('reembOutraCaixa').style.display = outra ? '' : 'none';
    if (outra) setTimeout(() => $('reembFavorecido').focus(), 30);
  });

  function abrirForm(r, tipoNovo) {
    $('reembId').value = r ? r.id : '';
    $('reembTipo').value = r ? (r.tipo || 'reembolso') : (tipoNovo || 'reembolso');
    preencherPessoas(r);
    textosDoTipo();
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
    setTimeout(() => $('reembPessoa').focus(), 60);
  }

  $('reembNovoBtn').addEventListener('click', () => abrirForm(null, 'reembolso'));
  $('reembNovoEstornoBtn').addEventListener('click', () => abrirForm(null, 'estorno'));

  $('reembSalvarBtn').addEventListener('click', async () => {
    const msg = $('reembMsg'), btn = $('reembSalvarBtn');
    const erro = t => { msg.textContent = t; msg.style.display = 'block'; };
    const c = centavos($('reembValor').value);
    const tipo = $('reembTipo').value, pessoa = $('reembPessoa').value;
    if (!pessoa) return erro(tipo === 'estorno' ? 'Escolha para quem é o estorno.' : 'Escolha quem pagou a conta.');
    if (pessoa === 'outra' && !$('reembFavorecido').value.trim()) return erro('Informe o nome da pessoa.');
    if (!$('reembDescricao').value.trim()) return erro(tipo === 'estorno' ? 'Descreva o motivo do estorno.' : 'Descreva o que foi pago.');
    if (!$('reembData').value) return erro(tipo === 'estorno' ? 'Informe a data do estorno.' : 'Informe a data da despesa.');
    if (c === null || c <= 0) return erro('Informe um valor maior que zero, como 150,00.');
    if (!$('reembPrimeiro').value) return erro('Informe o vencimento.');
    msg.style.display = 'none';
    const id = parseInt($('reembId').value, 10) || 0;
    btn.disabled = true;
    try {
      await chamar({
        acao: id ? 'atualizar' : 'criar', id, tipo,
        favorecido_user_id: pessoa === 'outra' ? 0 : Number(pessoa),
        favorecido: pessoa === 'outra' ? $('reembFavorecido').value.trim() : '', descricao: $('reembDescricao').value.trim(),
        data_despesa: $('reembData').value, valor_total: (c / 100).toFixed(2),
        parcelas: parseInt(sel.value, 10) || 1, primeiro_vencimento: $('reembPrimeiro').value,
        observacao: $('reembObs').value.trim(),
      });
      $('reembModal').style.display = 'none';
      toast((tipo === 'estorno' ? 'Estorno ' : 'Reembolso ') + (id ? 'atualizado.' : 'cadastrado.'), 'success');
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
    $('reembParcelasSub').textContent = r.descricao + ' · ' + (r.tipo === 'estorno' ? 'estorno de ' : 'despesa de ') + data(r.data_despesa)
      + (r.situacao === 'pago' ? ' · tudo pago' : ' · falta ' + brl(r.valor_aberto));
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
      $('reembPagarTitulo').textContent = 'Registrar pagamento ' + (r.qtd_parcelas > 1 ? `da ${p.numero}ª parcela` : 'do ' + nomeTipo(r.tipo)) + ` (${brl(p.valor)})`;
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
    else acaoParcela({ acao: 'quitar', id: aberto.id, pago_em: em, pago_por_nome: quem }, (aberto.tipo === 'estorno' ? 'Estorno' : 'Reembolso') + ' quitado.');
  });

  // ── Tabela: ações e filtro ───────────────────────────────────────────────
  $('reembTabela').addEventListener('click', async e => {
    const b = e.target.closest('[data-acao]'); if (!b) return;
    const r = lista.find(x => x.id === +b.closest('tr').dataset.id); if (!r) return;
    if (b.dataset.acao === 'parcelas') return abrirParcelas(r);
    if (b.dataset.acao === 'editar') return abrirForm(r);
    if (b.dataset.acao === 'excluir') {
      const pergunta = `Excluir o ${nomeTipo(r.tipo)} de ${r.favorecido} (${brl(r.valor_total)})?` + (r.qtd_pagas ? ' Os pagamentos registrados nele saem junto.' : '');
      const sim = window.Yuris && Yuris.confirm ? await Yuris.confirm(pergunta, { danger: true, okLabel: 'Excluir' }) : false;
      if (!sim) return;
      try { await chamar({ acao: 'excluir', id: r.id }); toast((r.tipo === 'estorno' ? 'Estorno' : 'Reembolso') + ' excluído.', 'success'); carregar(); }
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
