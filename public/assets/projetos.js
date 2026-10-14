/**
 * Aba Projetos (edição CRM, 14/10/2026).
 *
 * Carteira de projetos: o que cada um consome, quanto arrecada, a margem e o
 * dossiê com o que falta preencher. Fala com /api/projetos.php; toda conta de
 * dinheiro (equivalente mensal, rateio de servidor, arrecadado, pendências)
 * mora em App\Projetos\Projeto e chega pronta. Aqui só se desenha e se edita.
 */
(function () {
  'use strict';
  const API = '/api/projetos.php';
  const $ = id => document.getElementById(id);
  if (!$('projPagina')) return;

  const CSRF = document.querySelector('[name=csrf_token]')?.value || '';
  const brl = v => v === null || v === undefined ? null : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const moeda = (v, m) => (m === 'USD' ? 'US$ ' : 'R$ ') + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const num = v => Number(v || 0).toLocaleString('pt-BR');
  const data = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : '';
  const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const falta = (t = 'a preencher') => `<span class="proj-falta">${t}</span>`;
  const toast = (m, t) => { if (window.Yuris && Yuris.toast) Yuris.toast(m, t || 'info'); };
  const sinal = v => v > 0 ? 'proj-pos' : (v < 0 ? 'proj-neg' : '');

  let P = { projetos: [], servidores: [], resumo: {}, opcoes: {}, cotacao_usd: 5.5 };
  let filtro = 'ativos', busca = '', abertoId = null;

  /* ── Comunicação ── */
  async function chamar(corpo) {
    const r = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(corpo) });
    let j = {};
    try { j = await r.json(); } catch (e) { /* resposta sem JSON */ }
    if (!r.ok || !j.success) throw new Error(j.error || 'Não foi possível salvar. Tente de novo.');
    receber(j);
    return j;
  }

  async function carregar() {
    try {
      const r = await fetch(API, { credentials: 'same-origin' });
      const j = await r.json();
      if (!r.ok) throw new Error(j.error || 'erro');
      receber(j);
    } catch (e) {
      $('projTabela').tBodies[0].innerHTML = '<tr class="empty-row"><td colspan="9">Não foi possível carregar os projetos. Recarregue a página.</td></tr>';
    }
  }

  function receber(j) {
    P = j;
    desenharResumo();
    desenharTabela();
    desenharGraficos();
    desenharServidores();
    preencherFornecedores();
    if (abertoId) {
      if (P.projetos.some(p => p.id === abertoId)) abrirDossie(abertoId); else fechar('projDossie');
    }
  }

  /* ── Resumo ── */
  function desenharResumo() {
    const s = P.resumo || {};
    $('kProjetos').textContent = num(s.projetos_ativos);
    $('kProjetosDesc').textContent = `${num(s.projetos_total)} no total · ${num(s.usuarios)} usuários`;
    $('kReceita').textContent = brl(s.receita_mensal);
    $('kReceitaDesc').textContent = s.sem_receita ? `${s.sem_receita} projeto(s) que cobram sem receita lançada` : 'recorrente, projetos não encerrados';
    $('kCusto').textContent = brl(s.custo_mensal);
    $('kMargem').textContent = brl(s.margem_mensal);
    $('kMargem').className = 'kpi-value ' + sinal(s.margem_mensal);
    $('kMargemDesc').textContent = s.margem_pct === null || s.margem_pct === undefined ? 'sem receita para comparar' : `${String(s.margem_pct).replace('.', ',')}% da receita`;
    $('kArrecadado').textContent = brl(s.arrecadado);
    $('kPendencias').textContent = num(s.pendencias);
    $('kPendenciasDesc').textContent = s.servidores_sem_valor ? `inclui ${s.servidores_sem_valor} servidor(es) sem custo` : 'informações faltando';
    $('projCotacao').textContent = 'R$ ' + Number(P.cotacao_usd).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
  }

  /* ── Tabela ── */
  function passaFiltro(p) {
    if (filtro === 'ativos' && p.status === 'encerrado') return false;
    if (['producao', 'desenvolvimento', 'pausado', 'encerrado'].includes(filtro) && p.status !== filtro) return false;
    if (filtro === 'pendentes' && !p.calc.pendencias.length) return false;
    if (!busca) return true;
    const alvo = [p.nome, p.cliente, p.stack, p.url, p.servidor_nome, ...p.itens.map(i => i.fornecedor + ' ' + i.descricao)].join(' ').toLowerCase();
    return alvo.includes(busca);
  }

  const selo = st => `<span class="proj-selo st-${esc(st)}">${esc(P.opcoes.status?.[st] || st)}</span>`;

  function barraComp(c) {
    const cls = c.completude >= 80 ? '' : (c.completude >= 50 ? 'meio' : 'baixo');
    const n = c.pendencias.length;
    return `<div class="proj-comp"><div class="proj-barra-comp"><i class="${cls}" style="width:${c.completude}%"></i></div>
            <small>${c.completude}% · ${n ? n + (n === 1 ? ' pendência' : ' pendências') : 'completo'}</small></div>`;
  }

  function desenharTabela() {
    const tb = $('projTabela').tBodies[0];
    const lista = P.projetos.filter(passaFiltro);
    if (!lista.length) {
      tb.innerHTML = `<tr class="empty-row"><td colspan="9">${P.projetos.length ? 'Nenhum projeto neste filtro.' : 'Nenhum projeto cadastrado ainda. Use "Novo projeto".'}</td></tr>`;
      return;
    }
    tb.innerHTML = lista.map(p => {
      const c = p.calc;
      const cobra = ['cliente', 'saas'].includes(p.tipo);
      const sub = [p.cliente, P.opcoes.tipos?.[p.tipo]].filter(Boolean).join(' · ');
      const receita = c.receita_mensal > 0 ? brl(c.receita_mensal) : (cobra ? falta() : '<span class="proj-falta" style="font-style:normal;color:var(--muted)">–</span>');
      const usuarios = p.usuarios_ativos === null ? (cobra ? falta() : '–') : num(p.usuarios_ativos);
      const onde = p.servidor_nome ? esc(p.servidor_nome) : falta('não informado');
      return `<tr data-id="${p.id}">
        <td class="proj-nome"><b>${esc(p.nome)}</b><small>${esc(sub)}</small></td>
        <td>${selo(p.status)}</td>
        <td>${onde}</td>
        <td class="num">${usuarios}</td>
        <td class="num">${receita}</td>
        <td class="num">${brl(c.custo_mensal)}${c.custo_servidor === null ? '<br><small class="proj-falta">servidor a preencher</small>' : ''}</td>
        <td class="num ${sinal(c.margem_mensal)}">${brl(c.margem_mensal)}</td>
        <td class="num">${c.arrecadado > 0 ? brl(c.arrecadado) : '–'}</td>
        <td>${barraComp(c)}</td>
      </tr>`;
    }).join('');
  }

  /* ── Gráficos de barras ── */
  function desenharGraficos() {
    const cat = {};
    const nomesCat = P.opcoes.categorias?.custo || {};
    P.projetos.filter(p => p.status !== 'encerrado').forEach(p => {
      Object.entries(p.calc.custo_por_categoria || {}).forEach(([k, v]) => {
        if (k === 'servidor') return; // servidor entra pela lista de servidores (inteiro, mesmo sem projeto)
        cat[k] = (cat[k] || 0) + v;
      });
    });
    const totalSrv = (P.servidores || []).reduce((s, x) => s + (x.valor_mensal_brl || 0), 0);
    if (totalSrv > 0) cat.servidor = totalSrv;
    const linhasCat = Object.entries(cat).filter(([, v]) => v > 0).sort((a, b) => b[1] - a[1]);
    const maxCat = Math.max(...linhasCat.map(l => l[1]), 1);
    $('projCategorias').innerHTML = linhasCat.length ? linhasCat.map(([k, v]) => `
      <div class="proj-bl"><span title="${esc(nomesCat[k] || k)}">${esc(nomesCat[k] || k)}</span>
        <div class="proj-bl-trilho"><i class="c" style="width:${(v / maxCat * 100).toFixed(1)}%"></i></div><b>${brl(v)}</b></div>`).join('')
      : '<div class="proj-vazio">Nenhum custo com valor preenchido ainda.</div>';

    const ativos = P.projetos.filter(p => p.status !== 'encerrado' && (p.calc.receita_mensal > 0 || p.calc.custo_mensal > 0))
      .sort((a, b) => (b.calc.receita_mensal + b.calc.custo_mensal) - (a.calc.receita_mensal + a.calc.custo_mensal)).slice(0, 10);
    const maxR = Math.max(...ativos.map(p => Math.max(p.calc.receita_mensal, p.calc.custo_mensal)), 1);
    $('projRanking').innerHTML = ativos.length ? ativos.map(p => `
      <div class="proj-bl"><span title="${esc(p.nome)}">${esc(p.nome)}</span>
        <div class="proj-bl-trilho"><i class="r" style="width:${(p.calc.receita_mensal / maxR * 100).toFixed(1)}%" title="Receita ${brl(p.calc.receita_mensal)}"></i><i class="c" style="width:${(p.calc.custo_mensal / maxR * 100).toFixed(1)}%" title="Custo ${brl(p.calc.custo_mensal)}"></i></div>
        <b class="${sinal(p.calc.margem_mensal)}">${brl(p.calc.margem_mensal)}</b></div>`).join('')
      : '<div class="proj-vazio">Lance receitas e custos para ver quem dá lucro.</div>';
  }

  /* ── Dossiê ── */
  function abrirDossie(id) {
    const p = P.projetos.find(x => x.id === id);
    if (!p) return;
    abertoId = id;
    const c = p.calc;
    $('dosNome').textContent = p.nome;
    $('dosSub').innerHTML = [selo(p.status), esc(P.opcoes.tipos?.[p.tipo] || p.tipo), p.cliente ? 'Cliente: ' + esc(p.cliente) : ''].filter(Boolean).join(' &nbsp;·&nbsp; ');

    const caixa = (rot, val, extra) => `<div class="proj-num"><span>${rot}</span><b>${val}</b>${extra ? `<small>${extra}</small>` : ''}</div>`;
    $('dosNumeros').innerHTML =
      caixa('Receita/mês', brl(c.receita_mensal), c.receita_unica > 0 ? 'mais ' + brl(c.receita_unica) + ' únicos' : '') +
      caixa('Custo/mês', brl(c.custo_mensal), c.custo_servidor === null ? 'servidor a preencher' : (c.custo_servidor ? 'servidor ' + brl(c.custo_servidor) : '')) +
      caixa('Margem/mês', `<span class="${sinal(c.margem_mensal)}">${brl(c.margem_mensal)}</span>`, c.margem_pct === null ? '' : String(c.margem_pct).replace('.', ',') + '%') +
      caixa('Custo por usuário', c.custo_por_usuario === null ? falta() : brl(c.custo_por_usuario), p.usuarios_ativos ? num(p.usuarios_ativos) + ' usuários' : '') +
      caixa('Receita por usuário', c.receita_por_usuario === null ? falta() : brl(c.receita_por_usuario), '') +
      caixa('Arrecadado', brl(c.arrecadado), 'pelo contrato, até hoje');

    const pend = $('dosPendencias');
    pend.className = 'proj-pend' + (c.pendencias.length ? '' : ' ok');
    pend.innerHTML = c.pendencias.length
      ? `<b>Dossiê ${c.completude}% completo. Falta preencher:</b><ul>${c.pendencias.map(t => `<li>${esc(t)}</li>`).join('')}</ul>`
      : '<b>Dossiê completo.</b> Todas as informações do projeto estão preenchidas.';

    const link = u => u ? (/^https?:\/\//i.test(u) ? `<a href="${esc(u)}" target="_blank" rel="noopener">${esc(u)}</a>` : esc(u)) : falta();
    const srv = p.servidor_nome ? `${esc(p.servidor_nome)} (peso ${p.servidor_peso})` : falta('não informado');
    const campo = (rot, html, largo) => `<div${largo ? ' class="largo"' : ''}><dt>${rot}</dt><dd>${html}</dd></div>`;
    $('dosFicha').innerHTML =
      campo('Endereço de produção', link(p.url)) + campo('Onde roda', srv) + campo('Stack', p.stack ? esc(p.stack) : falta()) +
      campo('Repositório', link(p.repositorio)) + campo('Responsável', p.responsavel ? esc(p.responsavel) : falta()) +
      campo('Usuários ativos', p.usuarios_ativos === null ? falta() : num(p.usuarios_ativos)) +
      campo('Início', p.inicio ? data(p.inicio) : '–') + campo('Atualizado em', p.atualizado_em ? data(p.atualizado_em.slice(0, 10)) : '–') + campo('', '') +
      campo('Descrição', p.descricao ? esc(p.descricao) : falta(), true) +
      (p.observacao ? campo('Observações', esc(p.observacao), true) : '');

    const linhaItem = it => {
      const valor = it.valor_unitario === null ? falta() :
        moeda(it.valor_unitario, it.moeda) + (Number(it.quantidade) !== 1 ? ` × ${String(it.quantidade).replace('.', ',')}` : '') +
        (it.moeda === 'USD' && it.total_brl !== null ? `<small>${brl(it.total_brl)}</small>` : '');
      const rec = esc(P.opcoes.recorrencias?.[it.recorrencia] || it.recorrencia) +
        (it.inicio || it.fim ? `<small>${it.inicio ? 'desde ' + data(it.inicio) : ''}${it.fim ? ' até ' + data(it.fim) : ''}</small>` : '');
      const cat = P.opcoes.categorias?.[it.natureza]?.[it.categoria] || it.categoria;
      return `<tr class="${it.vigente ? '' : 'inativo'}">
        <td>${esc(it.descricao)}<small>${esc(cat)}${it.observacao ? ' · ' + esc(it.observacao) : ''}</small></td>
        <td>${it.fornecedor ? esc(it.fornecedor) : '–'}</td>
        <td class="num">${valor}</td>
        <td>${rec}</td>
        <td class="num">${it.mensal_brl === null ? falta() : (it.recorrencia === 'unico' ? '–' : brl(it.mensal_brl))}</td>
        <td><span class="proj-acoes"><button type="button" class="btn btn-ghost btn-sm" data-editar-item="${it.id}">Editar</button><button type="button" class="btn btn-ghost btn-sm proj-perigo" data-excluir-item="${it.id}">Excluir</button></span></td>
      </tr>`;
    };
    const custos = p.itens.filter(i => i.natureza === 'custo');
    const linhaSrv = p.servidor_id ? `<tr><td>Servidor: ${esc(p.servidor_nome)}<small>rateio pelo peso ${p.servidor_peso} entre os projetos do servidor</small></td><td>–</td>
        <td class="num">${c.custo_servidor === null ? falta() : brl(c.custo_servidor)}</td><td>Mensal</td>
        <td class="num">${c.custo_servidor === null ? falta() : brl(c.custo_servidor)}</td>
        <td><button type="button" class="btn btn-ghost btn-sm" data-ir-servidores>Servidores</button></td></tr>` : '';
    $('dosCustos').innerHTML = linhaSrv + custos.map(linhaItem).join('') ||
      '<tr class="empty-row"><td colspan="6">Nenhum custo lançado. Servidor, IA, WhatsApp, domínio, licenças...</td></tr>';
    const receitas = p.itens.filter(i => i.natureza === 'receita');
    $('dosReceitas').innerHTML = receitas.map(linhaItem).join('') ||
      '<tr class="empty-row"><td colspan="6">Nenhuma receita lançada.</td></tr>';
    abrir('projDossie');
  }

  /* ── Modais ── */
  function abrir(id) { $(id).style.display = 'flex'; }
  function fechar(id) { $(id).style.display = 'none'; if (id === 'projDossie') abertoId = null; }
  function msg(id, texto) { const el = $(id); el.textContent = texto || ''; el.style.display = texto ? 'block' : 'none'; }
  const opcoes = (obj, sel) => Object.entries(obj || {}).map(([k, v]) => `<option value="${esc(k)}"${k === sel ? ' selected' : ''}>${esc(v)}</option>`).join('');

  function abrirProjeto(p) {
    $('pmTitulo').textContent = p ? 'Editar projeto' : 'Novo projeto';
    $('pmId').value = p ? p.id : '';
    $('pmNome').value = p?.nome || '';
    $('pmTipo').innerHTML = opcoes(P.opcoes.tipos, p?.tipo || 'cliente');
    $('pmStatus').innerHTML = opcoes(P.opcoes.status, p?.status || 'producao');
    $('pmServidor').innerHTML = '<option value="">Não informado</option>' + P.servidores.map(s => `<option value="${s.id}"${p && p.servidor_id === s.id ? ' selected' : ''}>${esc(s.nome)}</option>`).join('');
    $('pmPeso').value = p?.servidor_peso || 1;
    const campos = { pmCliente: 'cliente', pmResponsavel: 'responsavel', pmUrl: 'url', pmRepo: 'repositorio', pmStack: 'stack', pmInicio: 'inicio', pmDescricao: 'descricao', pmObs: 'observacao' };
    Object.entries(campos).forEach(([el, c]) => { $(el).value = p?.[c] || ''; });
    $('pmUsuarios').value = p && p.usuarios_ativos !== null ? p.usuarios_ativos : '';
    msg('pmMsg', '');
    abrir('projModal');
    setTimeout(() => $('pmNome').focus(), 30);
  }

  async function salvarProjeto() {
    const corpo = {
      acao: 'projeto_salvar', id: Number($('pmId').value) || 0, nome: $('pmNome').value, tipo: $('pmTipo').value,
      status: $('pmStatus').value, cliente: $('pmCliente').value, responsavel: $('pmResponsavel').value, url: $('pmUrl').value,
      repositorio: $('pmRepo').value, servidor_id: Number($('pmServidor').value) || 0, servidor_peso: Number($('pmPeso').value) || 1,
      stack: $('pmStack').value, usuarios_ativos: $('pmUsuarios').value, inicio: $('pmInicio').value,
      descricao: $('pmDescricao').value, observacao: $('pmObs').value,
    };
    $('pmSalvar').disabled = true;
    try {
      const j = await chamar(corpo);
      fechar('projModal');
      toast(corpo.id ? 'Projeto atualizado.' : 'Projeto criado.', 'success');
      abrirDossie(j.id || corpo.id);
    } catch (e) { msg('pmMsg', e.message); }
    finally { $('pmSalvar').disabled = false; }
  }

  function abrirItem(natureza, it) {
    const p = P.projetos.find(x => x.id === abertoId);
    if (!p) return;
    const nat = it ? it.natureza : natureza;
    $('imTitulo').textContent = it ? (nat === 'custo' ? 'Editar custo' : 'Editar receita') : (nat === 'custo' ? 'Novo custo' : 'Nova receita');
    $('imSub').textContent = p.nome;
    $('imFornecedorRot').textContent = nat === 'custo' ? 'Fornecedor' : 'Quem paga';
    $('imId').value = it ? it.id : '';
    $('imNatureza').value = nat;
    $('imCategoria').innerHTML = opcoes(P.opcoes.categorias?.[nat], it?.categoria || (nat === 'custo' ? 'servidor' : 'mensalidade'));
    $('imRecorrencia').innerHTML = opcoes(P.opcoes.recorrencias, it?.recorrencia || 'mensal');
    $('imDescricao').value = it?.descricao || '';
    $('imFornecedor').value = it?.fornecedor || (nat === 'receita' ? (p.cliente || '') : '');
    $('imValor').value = it && it.valor_unitario !== null ? Number(it.valor_unitario).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) : '';
    $('imMoeda').value = it?.moeda || 'BRL';
    $('imQtd').value = it ? String(it.quantidade).replace('.', ',') : '1';
    $('imInicio').value = it?.inicio || '';
    $('imFim').value = it?.fim || '';
    $('imObs').value = it?.observacao || '';
    msg('imMsg', '');
    previa();
    abrir('itemModal');
    setTimeout(() => $('imDescricao').focus(), 30);
  }

  function lerNumero(txt) {
    let s = String(txt || '').trim().replace(/^(R\$|US\$|\$)\s*/, '');
    if (!s) return null;
    if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
    return /^\d+(\.\d{1,2})?$/.test(s) ? parseFloat(s) : NaN;
  }

  function previa() {
    const v = lerNumero($('imValor').value), q = lerNumero($('imQtd').value) ?? 1;
    const el = $('imPrevia');
    if (v === null) { el.textContent = 'Sem valor: o item fica como "a preencher" e aparece nas pendências do dossiê.'; return; }
    if (Number.isNaN(v) || Number.isNaN(q)) { el.textContent = 'Valor ou quantidade inválido. Use o formato 1.234,56.'; return; }
    const usd = $('imMoeda').value === 'USD';
    const total = v * q, emReal = usd ? total * P.cotacao_usd : total;
    const rec = $('imRecorrencia').value;
    let t = (usd ? moeda(total, 'USD') + ' ≈ ' : '') + brl(emReal);
    if (rec === 'anual') t += ` por ano, ${brl(emReal / 12)} por mês`;
    else if (rec === 'mensal') t += ' por mês';
    else t += ' uma vez';
    el.textContent = t;
  }

  async function salvarItem() {
    const corpo = {
      acao: 'item_salvar', id: Number($('imId').value) || 0, projeto_id: abertoId, natureza: $('imNatureza').value,
      categoria: $('imCategoria').value, descricao: $('imDescricao').value, fornecedor: $('imFornecedor').value,
      valor_unitario: $('imValor').value, moeda: $('imMoeda').value, quantidade: $('imQtd').value,
      recorrencia: $('imRecorrencia').value, inicio: $('imInicio').value, fim: $('imFim').value, observacao: $('imObs').value,
    };
    $('imSalvar').disabled = true;
    try {
      await chamar(corpo);
      fechar('itemModal');
      toast('Item salvo.', 'success');
    } catch (e) { msg('imMsg', e.message); }
    finally { $('imSalvar').disabled = false; }
  }

  async function confirmar(texto, ok) {
    if (window.Yuris && Yuris.confirm) return Yuris.confirm(texto, { okLabel: ok || 'Excluir', danger: true });
    return false;
  }

  /* ── Servidores ── */
  function desenharServidores() {
    const tb = $('srvLinhas');
    if (!P.servidores.length) { tb.innerHTML = '<tr class="empty-row"><td colspan="4">Nenhum servidor cadastrado.</td></tr>'; return; }
    tb.innerHTML = P.servidores.map(s => `<tr>
      <td><b>${esc(s.nome)}</b><div class="proj-srv-projs">${esc([s.provedor, s.endereco].filter(Boolean).join(' · '))}</div></td>
      <td><div class="proj-srv-projs">${s.projetos.length ? s.projetos.map(x => esc(x.nome) + (x.rateio !== null ? ' (' + brl(x.rateio) + ')' : '')).join(', ') : 'nenhum projeto ligado'}</div></td>
      <td class="num" style="text-align:right">${s.valor_mensal === null ? falta() : moeda(s.valor_mensal, s.moeda)}</td>
      <td style="text-align:right;white-space:nowrap"><span class="proj-acoes"><button type="button" class="btn btn-ghost btn-sm" data-editar-srv="${s.id}">Editar</button><button type="button" class="btn btn-ghost btn-sm proj-perigo" data-excluir-srv="${s.id}">Excluir</button></span></td>
    </tr>`).join('');
  }

  function formServidor(s) {
    $('srvFormTitulo').textContent = s ? 'Editar ' + s.nome : 'Novo servidor';
    $('srvId').value = s ? s.id : '';
    $('srvNome').value = s?.nome || '';
    $('srvProvedor').value = s?.provedor || '';
    $('srvEndereco').value = s?.endereco || '';
    $('srvValor').value = s && s.valor_mensal !== null ? Number(s.valor_mensal).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) : '';
    $('srvMoeda').value = s?.moeda || 'BRL';
    $('srvObs').value = s?.observacao || '';
    msg('srvMsg', '');
  }

  async function salvarServidor() {
    const corpo = { acao: 'servidor_salvar', id: Number($('srvId').value) || 0, nome: $('srvNome').value, provedor: $('srvProvedor').value,
                    endereco: $('srvEndereco').value, valor_mensal: $('srvValor').value, moeda: $('srvMoeda').value, observacao: $('srvObs').value };
    $('srvSalvar').disabled = true;
    try { await chamar(corpo); formServidor(null); toast('Servidor salvo.', 'success'); }
    catch (e) { msg('srvMsg', e.message); }
    finally { $('srvSalvar').disabled = false; }
  }

  function preencherFornecedores() {
    const nomes = new Set();
    P.projetos.forEach(p => p.itens.forEach(i => { if (i.fornecedor) nomes.add(i.fornecedor); }));
    $('projFornecedores').innerHTML = [...nomes].sort().map(n => `<option value="${esc(n)}"></option>`).join('');
  }

  /* ── Eventos ── */
  $('projTabela').addEventListener('click', e => { const tr = e.target.closest('tr[data-id]'); if (tr) abrirDossie(Number(tr.dataset.id)); });
  $('projFiltros').addEventListener('click', e => {
    const b = e.target.closest('[data-filtro]'); if (!b) return;
    filtro = b.dataset.filtro;
    $('projFiltros').querySelectorAll('[data-filtro]').forEach(x => { x.classList.toggle('btn-primary', x === b); x.classList.toggle('btn-ghost', x !== b); });
    desenharTabela();
  });
  $('projBusca').addEventListener('input', e => { busca = e.target.value.trim().toLowerCase(); desenharTabela(); });
  $('projNovoBtn').addEventListener('click', () => abrirProjeto(null));
  $('projServidoresBtn').addEventListener('click', () => { formServidor(null); abrir('srvModal'); });
  $('pmSalvar').addEventListener('click', salvarProjeto);
  $('imSalvar').addEventListener('click', salvarItem);
  $('srvSalvar').addEventListener('click', salvarServidor);
  $('srvLimpar').addEventListener('click', () => formServidor(null));
  ['imValor', 'imQtd'].forEach(id => $(id).addEventListener('input', previa));
  ['imMoeda', 'imRecorrencia'].forEach(id => $(id).addEventListener('change', previa));
  $('dosEditar').addEventListener('click', () => abrirProjeto(P.projetos.find(x => x.id === abertoId)));
  $('dosExcluir').addEventListener('click', async () => {
    const p = P.projetos.find(x => x.id === abertoId); if (!p) return;
    if (!(await confirmar(`Excluir o projeto "${p.nome}" e todos os itens dele?`))) return;
    try { await chamar({ acao: 'projeto_excluir', id: p.id }); fechar('projDossie'); toast('Projeto excluído.', 'success'); }
    catch (e) { toast(e.message, 'danger'); }
  });

  document.addEventListener('click', async e => {
    const f = e.target.closest('[data-fechar]');
    if (f) { fechar(f.dataset.fechar); return; }
    const n = e.target.closest('[data-novo-item]');
    if (n) { abrirItem(n.dataset.novoItem, null); return; }
    if (e.target.closest('[data-ir-servidores]')) { formServidor(null); abrir('srvModal'); return; }
    const ei = e.target.closest('[data-editar-item]');
    if (ei) {
      const p = P.projetos.find(x => x.id === abertoId);
      const it = p?.itens.find(i => i.id === Number(ei.dataset.editarItem));
      if (it) abrirItem(it.natureza, it);
      return;
    }
    const xi = e.target.closest('[data-excluir-item]');
    if (xi) {
      if (!(await confirmar('Excluir este item?'))) return;
      try { await chamar({ acao: 'item_excluir', id: Number(xi.dataset.excluirItem) }); toast('Item excluído.', 'success'); }
      catch (err) { toast(err.message, 'danger'); }
      return;
    }
    const es = e.target.closest('[data-editar-srv]');
    if (es) { formServidor(P.servidores.find(s => s.id === Number(es.dataset.editarSrv))); $('srvNome').focus(); return; }
    const xs = e.target.closest('[data-excluir-srv]');
    if (xs) {
      if (!(await confirmar('Excluir este servidor? Os projetos ligados a ele ficam sem servidor.'))) return;
      try { await chamar({ acao: 'servidor_excluir', id: Number(xs.dataset.excluirSrv) }); formServidor(null); toast('Servidor excluído.', 'success'); }
      catch (err) { toast(err.message, 'danger'); }
    }
  });
  // Clique no fundo escuro fecha o modal
  document.querySelectorAll('.modal-shell').forEach(m => m.addEventListener('mousedown', e => { if (e.target === m) fechar(m.id); }));
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    const aberto = ['itemModal', 'projModal', 'srvModal', 'projDossie'].find(id => $(id).style.display === 'flex');
    if (aberto) fechar(aberto);
  });

  $('projCotacaoBtn').addEventListener('click', async () => {
    if (!(window.Yuris && Yuris.prompt)) return;
    const v = await Yuris.prompt('Cotação do dólar usada para converter os custos em US$ (R$ por US$ 1):',
      { defaultValue: String(P.cotacao_usd).replace('.', ','), okLabel: 'Salvar cotação' });
    if (v === null || v === undefined || String(v).trim() === '') return;
    try { await chamar({ acao: 'cotacao_salvar', valor: v }); toast('Cotação atualizada.', 'success'); }
    catch (e) { toast(e.message, 'danger'); }
  });

  carregar();
})();
