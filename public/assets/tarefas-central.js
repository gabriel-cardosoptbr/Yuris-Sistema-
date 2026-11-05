/* tarefas-central.js — a Central de Tarefas (carrega depois de tarefas.js).
 *
 * Mostra numa tela só o que mora em três tabelas: quadros, tarefas processuais
 * e prazos do processo (App\Tarefas\Central). Nada é copiado: concluir ou editar
 * aqui chama os mesmos endpoints da ficha do processo, então o registro é um só.
 *
 * Quem classifica por período é o servidor (campos `periodos` e `coluna_central`).
 * Esta tela só filtra, para contador, Kanban e lista nunca discordarem.
 */
'use strict';

window.TkCentral = (function () {
  const HABILITADA = !!window.TK_CENTRAL;
  const COLUNAS = [
    ['atrasadas', 'Atrasadas', '#ef4444'],
    ['hoje', 'Hoje', '#2563eb'],
    ['amanha', 'Amanhã', '#0891b2'],
    ['semana', 'Esta semana', '#7c3aed'],
    ['mes', 'Este mês', '#059669'],
    ['depois', 'Mais adiante', '#64748b'],
    ['sem_data', 'Sem data', '#94a3b8'],
    ['concluidas', 'Concluídas', '#16a34a'],
  ];
  const ROTULO = { tarefa: 'Escritório', processo_tarefa: 'Tarefa processual', prazo: 'Prazo processual' };

  let ativa = false;
  let itens = [];
  let contagem = {};
  let hoje = '';
  const filtrosCentral = { tipo: '', situacao: 'abertas' };

  const $ = id => document.getElementById(id);
  const achar = chave => itens.find(i => i.chave === chave);

  function dataBr(i) {
    if (!i.data) return '';
    const [a, m, d] = i.data.split('-');
    return `${d}/${m}/${a}` + (i.hora ? ` ${i.hora}` : '');
  }
  function atrasada(i) { return !i.concluida && i.periodos.includes('atrasadas'); }

  /* Período e origem são filtrados aqui; o resto vem filtrado do servidor. */
  function visiveis() {
    let l = typeof _applyOriginFilter === 'function' ? _applyOriginFilter(itens) : itens;
    if (filters.prazo) l = l.filter(i => i.periodos.includes(filters.prazo));
    return l;
  }

  /* ── Entrar e sair da central ───────────────────────────────────────────── */
  async function abrir() {
    ativa = true;
    currentBoard = null;
    columns = [];
    document.body.classList.add('tk-em-central');
    const sel = $('tkBoardSelect');
    if (sel) sel.value = 'central';
    await carregar();
  }
  function sair() {
    ativa = false;
    document.body.classList.remove('tk-em-central');
    atualizarChips({});
  }

  async function carregar() {
    let qs = '?situacao=' + encodeURIComponent(filtrosCentral.situacao);
    if (filtrosCentral.tipo)  qs += '&tipo=' + filtrosCentral.tipo;
    if (filters.responsavel_id) qs += '&responsavel_id=' + encodeURIComponent(filters.responsavel_id);
    if (filters.prioridade)   qs += '&prioridade=' + encodeURIComponent(filters.prioridade);
    if (filters.busca)        qs += '&busca=' + encodeURIComponent(filters.busca);
    const r = await GET('/tarefas_central.php' + qs);
    const d = r.data || {};
    itens = d.itens || [];
    contagem = d.contagem || {};
    hoje = d.hoje || '';
    atualizarChips(contagem);
    renderView();
  }

  function atualizarChips(c) {
    document.querySelectorAll('.tk-periodo').forEach(b => {
      const n = b.querySelector('.tk-periodo-n');
      if (!n) return;
      const v = c[b.dataset.periodo];
      n.textContent = v ? String(v) : '';
      n.style.display = v ? '' : 'none';
    });
  }

  /* ── Views ──────────────────────────────────────────────────────────────── */
  function render(view) {
    if (view === 'kanban') renderKanban();
    if (view === 'lista')  renderLista();
    if (view === 'calendario') renderCalendario();
  }

  function etiqueta(i) {
    const cls = i.origem === 'tarefa' ? (i.processo_id ? 'tkc-juridica' : 'tkc-escritorio') : (i.origem === 'prazo' ? 'tkc-prazo' : 'tkc-juridica');
    const texto = i.origem === 'tarefa' && i.processo_id ? 'Jurídica' : ROTULO[i.origem];
    return `<span class="tkc-tipo ${cls}">${texto}</span>`;
  }
  function linhaProcesso(i) {
    if (!i.processo_id) return i.quadro_nome ? `<div class="tkc-ctx">${esc(i.quadro_nome)}${i.coluna_nome ? ' · ' + esc(i.coluna_nome) : ''}</div>` : '';
    const partes = [i.cliente_nome, i.processo_numero].filter(Boolean).map(esc).join(' · ');
    return `<div class="tkc-ctx">${partes}</div>`;
  }
  function caixa(i) {
    const titulo = i.concluida ? 'Reabrir' : 'Concluir';
    return `<button type="button" class="tkc-check${i.concluida ? ' feita' : ''}" data-concluir="${esc(i.chave)}" title="${titulo}" aria-label="${titulo}: ${esc(i.titulo)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></button>`;
  }

  function cartao(i) {
    const el = document.createElement('div');
    el.className = 'tk-card tkc-card' + (i.concluida ? ' tkc-feita' : '');
    el.dataset.prioridade = i.prioridade;
    el.dataset.chave = i.chave;
    el.tabIndex = 0;
    el.setAttribute('role', 'button');
    el.innerHTML = `
      <div class="tkc-topo">${etiqueta(i)}${caixa(i)}</div>
      <div class="tk-card-title">${esc(i.titulo)}</div>
      ${linhaProcesso(i)}
      <div class="tk-card-meta">
        <span class="tk-badge tk-badge-${esc(i.prioridade)}">${labelPrioridade(i.prioridade)}</span>
        ${i.data ? `<span class="tk-prazo${atrasada(i) ? ' atrasado' : ''}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>${dataBr(i)}</span>` : ''}
      </div>
      ${i.responsavel_nome ? `<div class="tkc-resp"><span class="tk-avatar-sm">${esc(i.responsavel_nome.charAt(0).toUpperCase())}</span>${esc(i.responsavel_nome)}</div>` : ''}
    `;
    return el;
  }

  function ligar(raiz) {
    raiz.querySelectorAll('[data-concluir]').forEach(b => b.addEventListener('click', e => {
      e.stopPropagation();
      alternar(b.dataset.concluir);
    }));
    raiz.querySelectorAll('[data-chave]').forEach(c => {
      c.addEventListener('click', () => abrirItem(c.dataset.chave));
      c.addEventListener('keydown', e => {
        if (e.target !== c || (e.key !== 'Enter' && e.key !== ' ')) return;
        e.preventDefault();
        abrirItem(c.dataset.chave);
      });
    });
  }

  function renderKanban() {
    const el = $('tkKanban');
    el.innerHTML = '';
    const lista = visiveis();
    COLUNAS.forEach(([id, nome, cor]) => {
      const doGrupo = lista.filter(i => i.coluna_central === id);
      // Atrasadas, Hoje e Amanhã ficam sempre, mesmo vazias: é onde o olho vai.
      if (!doGrupo.length && !['atrasadas', 'hoje', 'amanha'].includes(id)) return;
      const col = document.createElement('div');
      col.className = 'tk-col';
      col.innerHTML = `
        <div class="tk-col-header">
          <div class="tk-col-dot" style="background:${cor}"></div>
          <div class="tk-col-name">${nome}</div>
          <div class="tk-col-count">${doGrupo.length}</div>
        </div>
        <div class="tk-col-body"></div>`;
      const corpo = col.querySelector('.tk-col-body');
      if (doGrupo.length) doGrupo.forEach(i => corpo.appendChild(cartao(i)));
      else corpo.innerHTML = '<div class="tkc-vazio">Nada por aqui.</div>';
      el.appendChild(col);
    });
    ligar(el);
  }

  function renderLista() {
    const el = $('tkLista');
    const lista = visiveis();
    el.innerHTML = `
      <div class="tk-list-row tk-list-header tkc-lista">
        <span></span><span>Tarefa</span><span>Tipo</span><span>Prioridade</span><span>Data</span><span>Responsável</span>
      </div>
      ${lista.map(i => `
        <div class="tk-list-row tkc-lista${i.concluida ? ' tkc-feita' : ''}" data-chave="${esc(i.chave)}" tabindex="0" role="button">
          <span>${caixa(i)}</span>
          <span><span class="tkc-lista-titulo">${esc(i.titulo)}</span>${linhaProcesso(i)}</span>
          <span>${etiqueta(i)}</span>
          <span><span class="tk-badge tk-badge-${esc(i.prioridade)}">${labelPrioridade(i.prioridade)}</span></span>
          <span class="${atrasada(i) ? 'tkc-atraso' : ''}">${i.data ? dataBr(i) : 'Sem data'}</span>
          <span>${i.responsavel_nome ? esc(i.responsavel_nome) : 'Sem responsável'}</span>
        </div>`).join('') || '<div class="tkc-vazio tkc-vazio-lista">Nenhuma tarefa com esses filtros.</div>'}`;
    ligar(el);
  }

  /* ── Concluir e reabrir: cada origem pelo seu endpoint ──────────────────── */
  async function alternar(chave) {
    const i = achar(chave);
    if (!i) return;
    try {
      if (i.origem === 'processo_tarefa') {
        await PATCH('/processo_tarefas.php', { id: i.id, concluido: i.concluida ? 0 : 1 });
      } else if (i.origem === 'prazo') {
        await PATCH('/processo_prazos.php', { id: i.id, status: i.concluida ? 'pendente' : 'concluido' });
      } else if (i.concluida) {
        await POST('/tasks.php?action=reopen', { id: i.id });
      } else {
        await POST('/tasks.php?action=complete', { id: i.id });
      }
      showToast(i.concluida ? 'Reaberta' : 'Concluída ✓', { type: 'success', duration: 2500 });
      await carregar();
    } catch (e) {
      showToast('Não foi possível salvar. Tente de novo.', { type: 'error' });
    }
  }

  /* ── Abrir um item ──────────────────────────────────────────────────────── */
  let editando = null;
  function abrirItem(chave) {
    const i = achar(chave);
    if (!i) return;
    if (i.origem === 'tarefa') { openDrawer(i.id); return; }

    editando = i;
    $('ipTitulo').textContent = ROTULO[i.origem];
    $('ipContexto').textContent = [i.cliente_nome, i.processo_numero].filter(Boolean).join(' · ');
    $('ipDescricao').value = i.titulo;
    $('ipData').value = i.data || '';
    $('ipPrioridade').value = ['baixa', 'media', 'alta'].includes(i.prioridade) ? i.prioridade : 'media';
    $('ipResp').innerHTML = buildProcRespOptions('Sem responsável', i.responsavel_nome || '');
    // Responsável antigo que não está mais na lista continua aparecendo.
    if (i.responsavel_nome && $('ipResp').value !== i.responsavel_nome) {
      $('ipResp').insertAdjacentHTML('beforeend', `<option value="${esc(i.responsavel_nome)}" selected>${esc(i.responsavel_nome)}</option>`);
    }
    $('ipAbrirProcesso').href = '/processos.php?open=' + encodeURIComponent(i.processo_id);
    $('ipConcluir').textContent = i.concluida ? 'Reabrir' : '✓ Concluir';
    $('modalItemProcesso').classList.add('open');
    setTimeout(() => $('ipDescricao').focus(), 40);
  }
  function fecharItem() {
    $('modalItemProcesso').classList.remove('open');
    editando = null;
  }
  async function salvarItem() {
    if (!editando) return;
    const texto = $('ipDescricao').value.trim();
    if (!texto) { $('ipDescricao').focus(); return; }
    const comum = {
      id: editando.id,
      prioridade: $('ipPrioridade').value,
      responsavel: $('ipResp').value || null,
    };
    const btn = $('ipSalvar');
    btn.disabled = true;
    try {
      if (editando.origem === 'prazo') {
        await PATCH('/processo_prazos.php', { ...comum, descricao: texto, data_limite: $('ipData').value || null });
      } else {
        await PATCH('/processo_tarefas.php', { ...comum, titulo: texto, data_tarefa: $('ipData').value || null });
      }
      fecharItem();
      showToast('Salvo no processo ✓', { type: 'success', duration: 2500 });
      await carregar();
    } catch (e) {
      showToast('Não foi possível salvar. Tente de novo.', { type: 'error' });
    } finally {
      btn.disabled = false;
    }
  }

  /* ── Nova tarefa: escritório ou jurídica ────────────────────────────────── */
  let tipoNova = 'escritorio';
  let processoEscolhido = null;
  let buscaTimer = null;

  function trocarTipo(tipo) {
    tipoNova = tipo;
    document.querySelectorAll('.tk-tipo-opcao').forEach(b => {
      const on = b.dataset.tipo === tipo;
      b.classList.toggle('active', on);
      b.setAttribute('aria-checked', on ? 'true' : 'false');
    });
    const jur = tipo === 'juridica';
    document.getElementById('modalNewTask').classList.toggle('tk-nova-juridica', jur);
    $('ntProcessoCampo').style.display = jur ? '' : 'none';
    $('ntQuadroCampo').style.display = !jur && ativa ? '' : 'none';

    // Tarefa do processo só tem data. A de quadro tem data e hora.
    const prazo = $('ntPrazo');
    const valor = prazo.value;
    prazo.type = jur ? 'date' : 'datetime-local';
    prazo.value = jur ? valor.slice(0, 10) : (valor ? valor.slice(0, 10) + 'T09:00' : '');

    const urgente = $('ntPrioridade').querySelector('option[value="urgente"]');
    if (urgente) urgente.disabled = jur;
    if (jur && $('ntPrioridade').value === 'urgente') $('ntPrioridade').value = 'alta';
    if (jur && !$('ntResponsavel').value && window.YURIS_USER_ID) $('ntResponsavel').value = String(window.YURIS_USER_ID);
    $('ntSave').textContent = jur ? 'Criar no processo' : 'Criar tarefa';
    if (jur && !processoEscolhido) setTimeout(() => $('ntProcessoBusca').focus(), 40);
  }

  /* Chamada a cada abertura do modal. */
  async function prepararNova(dataInicial) {
    if (!HABILITADA) return;
    const quadro = $('ntQuadro');
    if (ativa) {
      quadro.innerHTML = boards.map(b => `<option value="${b.id}">${esc(b.cliente_nome ? b.cliente_nome + ' · ' + b.nome : b.nome)}</option>`).join('');
      await colunasDoQuadro();
    }
    trocarTipo(ativa ? 'juridica' : tipoNova);
    if (dataInicial) {
      $('ntPrazo').value = tipoNova === 'juridica' ? dataInicial : dataInicial + 'T09:00';
    }
  }
  async function colunasDoQuadro() {
    const id = Number($('ntQuadro').value);
    if (!id) { renderColSelect('ntColuna', []); return; }
    const r = await GET(`/task_boards.php?id=${id}`);
    renderColSelect('ntColuna', (r.data && r.data.colunas) || []);
  }

  function escolherProcesso(p) {
    processoEscolhido = p;
    $('ntProcessoLista').innerHTML = '';
    $('ntProcessoLista').style.display = 'none';
    $('ntProcessoBusca').style.display = p ? 'none' : '';
    const box = $('ntProcessoEscolhido');
    box.style.display = p ? 'flex' : 'none';
    if (p) {
      box.querySelector('.tk-proc-nome').textContent = p.cliente_nome || 'Cliente não informado';
      box.querySelector('.tk-proc-num').textContent = p.numero;
      $('ntTitulo').focus();
    } else {
      $('ntProcessoBusca').value = '';
      $('ntProcessoBusca').focus();
    }
  }

  async function buscarProcessos(termo) {
    const lista = $('ntProcessoLista');
    if (termo.trim().length < 2) { lista.style.display = 'none'; lista.innerHTML = ''; return; }
    let r;
    try { r = await GET('/tarefas_central.php?acao=processos&q=' + encodeURIComponent(termo)); }
    catch (e) { return; }
    // Resposta velha de uma busca anterior não sobrescreve a atual.
    if ($('ntProcessoBusca').value !== termo) return;
    const achados = r.data || [];
    lista.style.display = 'block';
    lista.innerHTML = achados.length
      ? achados.map((p, n) => `<button type="button" class="tk-proc-item" data-n="${n}">
          <span class="tk-proc-nome">${esc(p.cliente_nome || 'Cliente não informado')}</span>
          <span class="tk-proc-num">${esc(p.numero)}${p.tipo_acao ? ' · ' + esc(p.tipo_acao) : ''}</span>
        </button>`).join('')
      : '<div class="tk-proc-nada">Nenhum processo encontrado.</div>';
    lista.querySelectorAll('.tk-proc-item').forEach(b =>
      b.addEventListener('click', () => escolherProcesso(achados[Number(b.dataset.n)])));
  }

  async function salvarJuridica() {
    const titulo = $('ntTitulo').value.trim();
    if (!processoEscolhido) { showToast('Escolha o processo da tarefa.', { type: 'warn' }); $('ntProcessoBusca').focus(); return false; }
    if (!titulo) { $('ntTitulo').focus(); return false; }
    const respId = $('ntResponsavel').value;
    const u = users.find(x => String(x.id) === String(respId));
    await POST('/processo_tarefas.php', {
      processo_id: processoEscolhido.id,
      titulo,
      prioridade: $('ntPrioridade').value,
      responsavel: u ? u.nome : null,
      data_tarefa: $('ntPrazo').value || null,
    });
    showToast('Tarefa criada no processo ✓', { type: 'success', duration: 3000 });
    escolherProcesso(null);
    return true;
  }

  function bind() {
    if (!HABILITADA) return;
    document.querySelectorAll('.tk-tipo-opcao').forEach(b => b.addEventListener('click', () => trocarTipo(b.dataset.tipo)));
    $('ntQuadro').addEventListener('change', colunasDoQuadro);
    $('ntProcessoBusca').addEventListener('input', e => {
      clearTimeout(buscaTimer);
      const termo = e.target.value;
      buscaTimer = setTimeout(() => buscarProcessos(termo), 220);
    });
    $('ntProcessoTrocar').addEventListener('click', () => escolherProcesso(null));

    $('fltTipo').addEventListener('change', e => { filtrosCentral.tipo = e.target.value; carregar(); });
    $('fltSituacao').addEventListener('change', e => { filtrosCentral.situacao = e.target.value; carregar(); });

    $('ipFechar').addEventListener('click', fecharItem);
    $('ipSalvar').addEventListener('click', salvarItem);
    $('ipConcluir').addEventListener('click', async () => {
      if (!editando) return;
      const chave = editando.chave;
      fecharItem();
      await alternar(chave);
    });
    $('modalItemProcesso').addEventListener('click', e => { if (e.target === e.currentTarget) fecharItem(); });
  }

  return {
    habilitada: () => HABILITADA,
    ativa: () => ativa,
    abrir, sair, carregar, render, bind, abrirItem, prepararNova, salvarJuridica,
    /* Tipo escolhido no modal: 'juridica' é gravado no processo. */
    tipoNova: () => (HABILITADA ? tipoNova : 'escritorio'),
    quadroDaNova: () => Number($('ntQuadro')?.value) || 0,
    /* O calendário é um só: aqui entram os itens da central no formato dele. */
    paraCalendario: () => visiveis().filter(i => i.data).map(i => ({
      id: i.id, chave: i.chave, titulo: i.titulo, prazo: i.data + (i.hora ? ` ${i.hora}:00` : ' 00:00:00'), origem: i.origem, concluida: i.concluida,
    })),
  };
})();
