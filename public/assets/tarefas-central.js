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

  async function carregar(soSeMudou) {
    let qs = '?situacao=' + encodeURIComponent(filtrosCentral.situacao);
    if (filtrosCentral.tipo)  qs += '&tipo=' + filtrosCentral.tipo;
    if (filters.responsavel_id) qs += '&responsavel_id=' + encodeURIComponent(filters.responsavel_id);
    if (filters.prioridade)   qs += '&prioridade=' + encodeURIComponent(filters.prioridade);
    if (filters.busca)        qs += '&busca=' + encodeURIComponent(filters.busca);
    const r = await GET('/tarefas_central.php' + qs);
    const d = r.data || {};
    // Ao voltar do pop-up do processo: só redesenha se algo mudou lá (a tarefa concluída sai
    // da lista); sem mudança, a tela fica exatamente como estava.
    if (soSeMudou && JSON.stringify(d.itens || []) === JSON.stringify(itens) && JSON.stringify(d.contagem || {}) === JSON.stringify(contagem)) return;
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
    // Editar sem sair da tela. O clique no cartão abre o processo inteiro.
    const editar = i.origem === 'tarefa' ? '' : `<button type="button" class="tkc-editar" data-editar="${esc(i.chave)}" title="Editar aqui" aria-label="Editar aqui: ${esc(i.titulo)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button>`;
    return `<span class="tkc-acoes">${editar}<button type="button" class="tkc-check${i.concluida ? ' feita' : ''}" data-concluir="${esc(i.chave)}" title="${titulo}" aria-label="${titulo}: ${esc(i.titulo)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></button></span>`;
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
    raiz.querySelectorAll('[data-editar]').forEach(b => b.addEventListener('click', e => {
      e.stopPropagation();
      editarItem(b.dataset.editar);
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

  /* Tarefa e prazo do processo só fazem sentido com o processo em volta: abre a
     ficha inteira, que desce até o item e o destaca (ver focarNoProcesso). */
  function linkDoProcesso(i) {
    const foco = (i.origem === 'prazo' ? 'prazo:' : 'tarefa:') + i.id;
    return `/processos.php?open=${encodeURIComponent(i.processo_id)}&foco=${foco}&volta=tarefas`;
  }

  /* "Tem que só abrir o pop-up, que nem a gente combinou" (08/11/2026): na interface nova o
     processo abre por cima da Central, já descendo até a tarefa e piscando nela
     (embed-abrir.js, o mesmo da home); a Central fica onde estava. Sem ele, o link de sempre. */
  function abrirNoPopup(i) {
    if (!window.YurisEmbed) return false;
    const foco = (i.origem === 'prazo' ? 'prazo:' : 'tarefa:') + i.id;
    YurisEmbed.abrir(`/processos?open=${encodeURIComponent(i.processo_id)}&foco=${foco}`, { aoFechar: () => carregar(true).catch(() => {}) });
    return true;
  }

  function abrirItem(chave) {
    const i = achar(chave);
    if (!i) return;
    if (i.origem === 'tarefa') { openDrawer(i.id); return; }
    if (abrirNoPopup(i)) return;
    location.href = linkDoProcesso(i);
  }

  function editarItem(chave) {
    const i = achar(chave);
    if (!i) return;
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
    $('ipAbrirProcesso').href = linkDoProcesso(i);
    $('ipAbrirProcesso').onclick = e => {
      if (!window.YurisEmbed || e.ctrlKey || e.metaKey || e.shiftKey || e.button) return;
      e.preventDefault(); const alvo = editando; fecharItem(); abrirNoPopup(alvo);
    };
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
  let clienteEscolhido = '';
  let buscaTimer = null, clienteTimer = null;
  let tarefasDoProcesso = [];
  let oqueCriar = ''; // '' (nada aberto) | tarefa | prazo | vinculo
  let rascunho = { desc: '', data: '', prio: 'media', resp: '' };
  let resumoProcesso = { tarefas: [], prazos: [], vinculos: [] };

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
    if (jur && !processoEscolhido) {
      // As duas listas já aparecem abertas: os clientes e os processos mais recentes.
      buscarClientes($('ntClienteBusca').value);
      buscarProcessos($('ntProcessoBusca').value);
      setTimeout(() => $('ntProcessoBusca').focus(), 40);
    }
  }

  // "Tarefa de escritório": o cadastro pequeno vira o painel completo da tarefa. O painel
  // abre primeiro (a tela embutida na home não pode ficar um instante sem pop-up aberto)
  // e nasce da caixa do cadastro; o título já digitado vai junto.
  function ampliarParaPainel() {
    const modal = document.getElementById('modalNewTask');
    const caixa = modal.querySelector('.tk-modal');
    const de = caixa ? caixa.getBoundingClientRect() : null;
    const prazo = $('ntPrazo').value;
    window.abrirTarefaNoPainel({ de, boardId: Number($('ntQuadro').value) || 0, prazo: prazo || null, titulo: tipoNova === 'escritorio' ? $('ntTitulo').value.trim() : '' });
    modal.classList.remove('open');
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
    $('ntProcessoLista').style.display = p ? 'none' : 'block';
    $('ntProcessoBusca').style.display = p ? 'none' : '';
    const box = $('ntProcessoEscolhido');
    box.style.display = p ? 'flex' : 'none';
    if (p) {
      box.querySelector('.tk-proc-nome').textContent = p.numero;
      box.querySelector('.tk-proc-num').textContent = [p.cliente_nome || 'Cliente não informado', p.tipo_acao].filter(Boolean).join(' · ');
      // Escolher o processo também fixa o cliente dele no primeiro campo.
      if (p.cliente_nome && clienteEscolhido !== p.cliente_nome) escolherCliente({ nome: p.cliente_nome, processos: null }, true);
      carregarTarefasDoProcesso(p.id);
      $('ntTitulo').focus();
    } else {
      $('ntProcessoBusca').value = '';
      tarefasDoProcesso = [];
      oqueCriar = '';
      $('ntProcTarefas').style.display = 'none';
      $('ntProcTarefas').innerHTML = '';
      buscarProcessos('');
      $('ntProcessoBusca').focus();
    }
  }

  function escolherCliente(c, semRecarregar) {
    clienteEscolhido = c ? c.nome : '';
    $('ntClienteLista').innerHTML = '';
    $('ntClienteLista').style.display = c ? 'none' : 'block';
    $('ntClienteBusca').style.display = c ? 'none' : '';
    const box = $('ntClienteEscolhido');
    box.style.display = c ? 'flex' : 'none';
    if (c) {
      box.querySelector('.tk-proc-nome').textContent = c.nome;
      box.querySelector('.tk-proc-num').textContent = c.processos ? c.processos + (c.processos === 1 ? ' processo' : ' processos') : 'Cliente do processo';
    } else {
      $('ntClienteBusca').value = '';
      buscarClientes('');
    }
    if (semRecarregar) return;
    // Trocou o cliente: o processo escolhido de outro cliente sai, e a lista mostra os dele.
    if (processoEscolhido && c && processoEscolhido.cliente_nome !== c.nome) escolherProcesso(null);
    else if (!processoEscolhido) buscarProcessos($('ntProcessoBusca').value, true);
  }

  async function buscarClientes(termo) {
    const lista = $('ntClienteLista');
    let r;
    try { r = await GET('/tarefas_central.php?acao=clientes&q=' + encodeURIComponent(termo.trim())); }
    catch (e) { return; }
    if ($('ntClienteBusca').value !== termo || clienteEscolhido) return;
    const achados = r.data || [];
    lista.style.display = 'block';
    lista.innerHTML = achados.length
      ? achados.map((c, n) => `<button type="button" class="tk-proc-item" data-n="${n}">
          <span class="tk-proc-nome">${esc(c.nome)}</span>
          <span class="tk-proc-num">${c.processos} ${c.processos === 1 ? 'processo' : 'processos'}</span>
        </button>`).join('')
      : '<div class="tk-proc-nada">Nenhum cliente com processo.</div>';
    lista.querySelectorAll('.tk-proc-item').forEach(b =>
      b.addEventListener('click', () => {
        const c = achados[Number(b.dataset.n)];
        escolherCliente(c);
        // Cliente com um processo só: ele já vem escolhido.
        if (c.processos === 1) GET('/tarefas_central.php?acao=processos&cliente=' + encodeURIComponent(c.nome))
          .then(x => { const ps = x.data || []; if (ps.length === 1 && clienteEscolhido === c.nome) escolherProcesso(ps[0]); }).catch(() => {});
      }));
  }

  async function buscarProcessos(termo, forcar) {
    const lista = $('ntProcessoLista');
    if (processoEscolhido && !forcar) return;
    let r;
    try {
      r = await GET('/tarefas_central.php?acao=processos&q=' + encodeURIComponent(termo.trim())
        + (clienteEscolhido ? '&cliente=' + encodeURIComponent(clienteEscolhido) : ''));
    } catch (e) { return; }
    // Resposta velha de uma busca anterior não sobrescreve a atual.
    if ($('ntProcessoBusca').value !== termo || processoEscolhido) return;
    const achados = r.data || [];
    lista.style.display = 'block';
    lista.innerHTML = achados.length
      ? achados.map((p, n) => `<button type="button" class="tk-proc-item" data-n="${n}">
          <span class="tk-proc-nome">${esc(p.numero)}</span>
          <span class="tk-proc-num">${esc(p.cliente_nome || 'Cliente não informado')}${p.tipo_acao ? ' · ' + esc(p.tipo_acao) : ''}</span>
        </button>`).join('')
      : '<div class="tk-proc-nada">' + (clienteEscolhido ? 'Este cliente não tem processo com esse termo.' : 'Nenhum processo encontrado.') + '</div>';
    lista.querySelectorAll('.tk-proc-item').forEach(b =>
      b.addEventListener('click', () => escolherProcesso(achados[Number(b.dataset.n)])));
  }

  // As tarefas processuais do processo escolhido: a fazer primeiro, depois as feitas.
  // "Será que tem que fazer ou não? Se já foi feito ou não."
  // O processo escolhido por inteiro, como na ficha: prazos, tarefas e vínculos, cada um
  // com o que já foi feito e o que falta. O bloco do que se vai criar fica primeiro.
  async function carregarTarefasDoProcesso(id) {
    const box = $('ntProcTarefas');
    box.style.display = 'block';
    box.innerHTML = '<div class="tk-jur-tit">Este processo</div><div class="tk-jur-osso"></div><div class="tk-jur-osso"></div><div class="tk-jur-osso"></div>';
    const pega = url => GET(url).then(x => x.data || []).catch(() => null);
    const [ts, ps, vs] = await Promise.all([
      pega('/processo_tarefas.php?processo_id=' + id),
      pega('/processo_prazos.php?processo_id=' + id),
      pega('/resource_shares.php?resource_type=processo&resource_id=' + id),
    ]);
    if (!processoEscolhido || processoEscolhido.id !== id) return;
    resumoProcesso = {
      tarefas: (ts || []).map(t => ({ titulo: t.titulo || '', feita: Number(t.concluido) === 1, data: t.data_tarefa || '', resp: t.responsavel || '' })),
      prazos: (ps || []).map(p => ({ titulo: p.descricao || '', feita: p.status === 'concluido', data: (p.data_limite || '').slice(0, 10), resp: p.responsavel || '' })),
      vinculos: (vs || []).filter(v => v.status === 'active').map(v => ({ titulo: v.to_user_nome ? 'Advogado: ' + v.to_user_nome : (v.to_account_nome ? 'Conta: ' + v.to_account_nome : 'Conta #' + v.to_account_id), feita: true, data: '', resp: ({ view: 'Visualização', edit: 'Edição', full: 'Acesso total' })[v.permission_level] || '' })),
      falhou: ts === null && ps === null,
    };
    tarefasDoProcesso = resumoProcesso.tarefas;
    desenharResumo();
  }
  // Os três blocos do processo, cada um com o seu "Adicionar": "isso não tem de existir antes
  // de eu selecionar um processo; depois, dentro dos campos, tem de ter a opção de fazer algo".
  // O formulário abre dentro do bloco escolhido, com Descrição (não título).
  function desenharResumo() {
    const box = $('ntProcTarefas');
    if (!processoEscolhido) return;
    guardarRascunho();
    const data = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4) : '';
    const hoje = new Date().toISOString().slice(0, 10);
    const ADD = { tarefas: ['tarefa', 'Nova tarefa'], prazos: ['prazo', 'Novo prazo'], vinculos: ['vinculo', 'Adicionar vínculo'] };
    const bloco = (chave, titulo, vazio) => {
      const itens = resumoProcesso[chave];
      const abertas = itens.filter(t => !t.feita).length, feitas = itens.length - abertas;
      const conta = chave === 'vinculos' ? itens.length + (itens.length === 1 ? ' ativo' : ' ativos')
        : abertas + (chave === 'prazos' ? ' em aberto' : ' a fazer') + ' · ' + feitas + (chave === 'prazos' ? (feitas === 1 ? ' cumprido' : ' cumpridos') : (feitas === 1 ? ' feita' : ' feitas'));
      const [oque, rot] = ADD[chave], aberto = oqueCriar === oque;
      return '<div class="tk-jur-bloco' + (aberto ? ' atual' : '') + '" data-bloco="' + chave + '">'
        + '<div class="tk-jur-tit"><span class="tk-jur-tit-t">' + titulo + ' <span>' + conta + '</span></span>'
        + '<button type="button" class="tk-jur-add' + (aberto ? ' aberto' : '') + '" data-oque="' + oque + '" aria-expanded="' + aberto + '">'
        + (aberto ? 'Fechar' : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>' + rot) + '</button></div>'
        + (aberto && oque !== 'vinculo' ? formulario(oque) : '')
        + (itens.length
          ? '<div class="tk-jur-itens">' + itens.map((t, n) => {
              const venc = chave === 'prazos' && !t.feita && t.data && t.data < hoje;
              const est = chave === 'vinculos' ? 'Ativo' : chave === 'prazos' ? (t.feita ? 'Cumprido' : venc ? 'Vencido' : 'Em aberto') : (t.feita ? 'Feita' : 'A fazer');
              return `<div class="tk-jur-item${t.feita ? ' feita' : ''}${chave === 'vinculos' ? ' vinc' : ''}${venc ? ' venc' : ''}" data-bloco="${chave}" data-n="${n}">
                <span class="tk-jur-marca" aria-hidden="true">${t.feita ? '<svg viewBox="0 0 24 24"><polyline points="5 12 10 17 19 7"/></svg>' : ''}</span>
                <span class="tk-jur-t"><b>${esc(t.titulo)}</b><small>${[est, data(t.data), t.resp].filter(Boolean).map(esc).join(' · ')}</small></span>
              </div>`; }).join('') + '</div>'
          : '<div class="tk-proc-nada">' + vazio + '</div>') + '</div>';
    };
    box.innerHTML = resumoProcesso.falhou ? '<div class="tk-proc-nada">Não consegui ler este processo agora.</div>'
      : bloco('tarefas', 'Tarefas processuais', 'Nenhuma tarefa neste processo ainda.')
      + bloco('prazos', 'Prazos processuais', 'Nenhum prazo neste processo ainda.')
      + bloco('vinculos', 'Vínculos do processo', 'Nenhum vínculo: o processo só é visto por este escritório.');
    const desc = $('ntJurDesc');
    if (desc) { desc.addEventListener('input', () => { rascunho.desc = desc.value; avisarRepetida(); }); setTimeout(() => desc.focus(), 30); }
    avisarRepetida();
  }
  // O formulário dentro do bloco: descrição, data, prioridade e responsável, e o botão de criar.
  function formulario(oque) {
    const prazo = oque === 'prazo';
    const resp = users.map(u => `<option value="${u.id}"${String(u.id) === String(rascunho.resp) ? ' selected' : ''}>${esc(u.nome)}</option>`).join('');
    const prio = [['baixa', 'Baixa'], ['media', 'Média'], ['alta', 'Alta']].concat(prazo ? [['urgente', 'Urgente']] : [])
      .map(([v, t]) => `<option value="${v}"${rascunho.prio === v ? ' selected' : ''}>${t}</option>`).join('');
    return `<div class="tk-jur-form">
      <div class="tk-field"><label for="ntJurDesc">Descrição *</label>
        <textarea id="ntJurDesc" rows="3" placeholder="${prazo ? 'O que vence: contestação, réplica, recurso, manifestação…' : 'O que precisa ser feito no processo'}">${esc(rascunho.desc)}</textarea>
        <div id="ntTituloAviso" class="tk-jur-aviso" style="display:none"></div></div>
      <div class="tk-jur-form-linha">
        <div class="tk-field"><label for="ntJurData">${prazo ? 'Data limite *' : 'Data'}</label><input type="date" id="ntJurData" value="${esc(rascunho.data)}"></div>
        <div class="tk-field"><label for="ntJurPrio">Prioridade</label><select id="ntJurPrio">${prio}</select></div>
        <div class="tk-field"><label for="ntJurResp">Responsável</label><select id="ntJurResp"><option value="">Sem responsável</option>${resp}</select></div>
      </div>
      <div class="tk-jur-form-acoes"><button type="button" class="tk-modal-btn tk-modal-btn-primary" id="ntJurCriar">${prazo ? 'Criar o prazo' : 'Criar a tarefa'}</button></div>
    </div>`;
  }
  function guardarRascunho() {
    if ($('ntJurDesc')) rascunho.desc = $('ntJurDesc').value;
    if ($('ntJurData')) rascunho.data = $('ntJurData').value;
    if ($('ntJurPrio')) rascunho.prio = $('ntJurPrio').value;
    if ($('ntJurResp')) rascunho.resp = $('ntJurResp').value;
  }
  function oquePorBloco() { return { tarefa: 'tarefas', prazo: 'prazos', vinculo: 'vinculos' }[oqueCriar] || ''; }

  // Abrir ou fechar o "Adicionar" de um bloco. Vínculo vai direto para a ficha do processo.
  function trocarOque(oque) {
    if (oque === 'vinculo') { abrirVinculos(); return; }
    guardarRascunho();
    oqueCriar = oqueCriar === oque ? '' : oque;
    if (!rascunho.resp && window.YURIS_USER_ID) rascunho.resp = String(window.YURIS_USER_ID);
    if (oqueCriar === 'tarefa' && rascunho.prio === 'urgente') rascunho.prio = 'alta';
    if (processoEscolhido) desenharResumo();
  }
  function abrirVinculos() {
    if (!processoEscolhido) return;
    // O vínculo é acesso entre escritórios: abre a ficha do processo na seção de vínculos,
    // com o "+ Adicionar vínculo" de sempre (código, conferência da conta e permissão).
    const embutido = document.documentElement.classList.contains('yuris-embed');
    location.href = '/processos?open=' + processoEscolhido.id + '&foco=vinculos' + (embutido ? '&embed=1' : '');
  }
  // A descrição igual (ou contida) a uma que já existe no bloco acende ela e avisa.
  function avisarRepetida() {
    const d = $('ntJurDesc');
    const t = d ? d.value.trim().toLowerCase() : '';
    let aviso = '';
    const alvo = oquePorBloco();
    document.querySelectorAll('#ntProcTarefas .tk-jur-item').forEach(el => {
      if (!alvo || el.dataset.bloco !== alvo || alvo === 'vinculos') { el.classList.remove('igual'); return; }
      const x = resumoProcesso[alvo][Number(el.dataset.n)];
      const igual = t.length >= 4 && x && (x.titulo.toLowerCase() === t || x.titulo.toLowerCase().includes(t));
      el.classList.toggle('igual', igual);
      if (igual && !aviso) aviso = alvo === 'prazos'
        ? (x.feita ? 'Já existe um prazo assim, e ele já foi cumprido.' : 'Já existe um prazo assim, ainda em aberto.')
        : (x.feita ? 'Já existe uma tarefa assim, e ela já foi feita.' : 'Já existe uma tarefa assim, ainda a fazer.');
    });
    const el = $('ntTituloAviso');
    if (!el) return;
    el.textContent = aviso;
    el.style.display = aviso ? 'block' : 'none';
  }

  async function salvarJuridica() {
    if (!processoEscolhido) { showToast('Escolha o processo.', { type: 'warn' }); $('ntProcessoBusca').focus(); return false; }
    if (!oqueCriar) { showToast('Escolha o que fazer no processo: nova tarefa ou novo prazo.', { type: 'warn' }); return false; }
    if (oqueCriar === 'vinculo') { abrirVinculos(); return false; }
    guardarRascunho();
    const desc = rascunho.desc.trim();
    if (!desc) { $('ntJurDesc') && $('ntJurDesc').focus(); return false; }
    const u = users.find(x => String(x.id) === String(rascunho.resp));
    if (oqueCriar === 'prazo') {
      if (!rascunho.data) { showToast('Prazo processual precisa da data limite.', { type: 'warn' }); $('ntJurData') && $('ntJurData').focus(); return false; }
      await POST('/processo_prazos.php', {
        processo_id: processoEscolhido.id,
        descricao: desc,
        data_limite: rascunho.data,
        prioridade: rascunho.prio,
        responsavel: u ? u.nome : null,
      });
      showToast('Prazo criado no processo ✓', { type: 'success', duration: 3000 });
    } else {
      await POST('/processo_tarefas.php', {
        processo_id: processoEscolhido.id,
        titulo: desc,
        prioridade: rascunho.prio === 'urgente' ? 'alta' : rascunho.prio,
        responsavel: u ? u.nome : null,
        data_tarefa: rascunho.data || null,
      });
      showToast('Tarefa criada no processo ✓', { type: 'success', duration: 3000 });
    }
    rascunho = { desc: '', data: '', prio: 'media', resp: '' };
    oqueCriar = '';
    escolherProcesso(null);
    escolherCliente(null, true);
    return true;
  }

  function bind() {
    if (!HABILITADA) return;
    document.querySelectorAll('.tk-tipo-opcao').forEach(b => b.addEventListener('click', () => {
      if (b.dataset.tipo === 'escritorio' && window.abrirTarefaNoPainel) { ampliarParaPainel(); return; }
      trocarTipo(b.dataset.tipo);
    }));
    $('ntQuadro').addEventListener('change', colunasDoQuadro);
    $('ntProcessoBusca').addEventListener('input', e => {
      clearTimeout(buscaTimer);
      const termo = e.target.value;
      buscaTimer = setTimeout(() => buscarProcessos(termo), 220);
    });
    $('ntProcessoTrocar').addEventListener('click', () => escolherProcesso(null));
    $('ntClienteBusca').addEventListener('input', e => {
      clearTimeout(clienteTimer);
      const termo = e.target.value;
      clienteTimer = setTimeout(() => buscarClientes(termo), 200);
    });
    $('ntClienteTrocar').addEventListener('click', () => { const tinha = !!processoEscolhido; escolherCliente(null, true); if (tinha) escolherProcesso(null); else buscarProcessos($('ntProcessoBusca').value, true); });
    $('ntProcTarefas').addEventListener('click', e => {
      const add = e.target.closest('.tk-jur-add'); if (add) { trocarOque(add.dataset.oque); return; }
      if (e.target.closest('#ntJurCriar')) $('ntSave').click();
    });

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
