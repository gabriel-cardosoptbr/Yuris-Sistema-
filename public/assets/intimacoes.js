/* eslint-env browser */
/*
 * intimacoes.js — Frontend do módulo Intimações.
 *
 * IIFE module. Expõe window.YurisIntimacoesApp com:
 *   .init()         — inicializa listeners e carrega dados
 *   .buscarHoje()   — força refresh do cache do dia
 *   .buscarManual() — busca por período/filtros (sem persistir)
 *   .loadPersistidos() — recarrega lista do banco
 *
 * Estado consolidado em this.state. UI render via this.render().
 */
(function () {
  'use strict';

  const App = {
    state: {
      items: [],
      filters: { lida: null, favorita: null, com_prazo: null },
      loading: false,
      // sourceFilter: filtra publicações por provider. null = todos; 'aasp' = aba AASP ativa.
      sourceFilter: null,
    },
    csrf: null,
    apiBase: null,
    accountId: null,
    notify: null,

    init(cfg) {
      this.csrf      = cfg.csrf;
      this.apiBase   = cfg.apiBase;
      this.accountId = cfg.accountId;
      this.notify    = window.yurisNotify || ((m) => console.log('[notify]', m));
      this.userProfile = { oab: '', oab_uf: '', nome_advogado: '', nome: '' };
      this.bindEvents();
      this.initDatePicker();
      // Detect aba ativa inicial — DJEN por padrão (data-tab="djen" no HTML)
      const activeTab = document.querySelector('.int-tab.active')?.dataset?.tab || 'djen';
      this.state.sourceFilter = activeTab === 'aasp' ? 'aasp' : (activeTab === 'djen' ? 'djen' : null);
      // Default ao carregar a página: mostra SÓ HOJE. Antes mostrava o cache
      // inteiro cross-day (19 items de vários dias), o que confundia o user.
      // Pra ver mais dias, usa os shortcuts (7/30 dias) ou o calendário.
      this.setPeriodoShortcut(1);
      this.updateSearchHint();
      this.loadUserDefaults().then(() => this.loadPersistidos());
    },

    /** Inicializa o calendário visual Flatpickr no input "Período". */
    initDatePicker() {
      const tryInit = () => {
        if (!window.flatpickr) { return false; }
        const inp = document.getElementById('filterPeriodo');
        if (!inp) return false;
        // Configura locale pt-BR se disponível
        if (window.flatpickr.l10ns && window.flatpickr.l10ns.pt) {
          window.flatpickr.localize(window.flatpickr.l10ns.pt);
        }
        this.fp = window.flatpickr(inp, {
          mode: 'range',
          dateFormat: 'd/m/Y',
          maxDate: 'today',
          showMonths: 1,
          allowInput: false,
          onChange: (dates) => {
            const di = document.getElementById('filterDataInicio');
            const df = document.getElementById('filterDataFim');
            if (dates.length >= 1) di.value = this._isoDate(dates[0]);
            if (dates.length >= 2) df.value = this._isoDate(dates[1]);
            if (dates.length === 0) { di.value = ''; df.value = ''; }
            // Mostra banner LGPD se data antiga
            const hoje = new Date().toISOString().slice(0, 10);
            const isManual = (di.value && di.value < hoje) || (df.value && df.value < hoje);
            document.getElementById('bannerBuscaManual')?.classList.toggle('show', isManual);
          },
        });
        return true;
      };
      if (!tryInit()) {
        // Flatpickr carrega via <script defer> — espera até estar pronto
        const t = setInterval(() => { if (tryInit()) clearInterval(t); }, 100);
        setTimeout(() => clearInterval(t), 5000);
      }
    },

    _isoDate(d) {
      const pad = (n) => String(n).padStart(2, '0');
      return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    },

    /** Helpers de período: define range a partir de uma quantidade de dias. */
    setPeriodoShortcut(days) {
      const today = new Date();
      const start = new Date(); start.setDate(today.getDate() - (days - 1));
      if (this.fp) this.fp.setDate([start, today], true);
      // Garante hidden inputs setados — Flatpickr no modo range trata
      // [hoje, hoje] (2 datas iguais) como 1 só e onChange não seta data_fim.
      const di = document.getElementById('filterDataInicio');
      const df = document.getElementById('filterDataFim');
      if (di) di.value = this._isoDate(start);
      if (df) df.value = this._isoDate(today);
      // Banner LGPD + microcopy reagem
      const hoje = this._isoDate(today);
      const isManual = (di.value && di.value < hoje);
      document.getElementById('bannerBuscaManual')?.classList.toggle('show', isManual);
      this.updateSearchHint();
    },

    clearPeriodo() {
      if (this.fp) this.fp.clear();
      const di = document.getElementById('filterDataInicio');
      const df = document.getElementById('filterDataFim');
      if (di) di.value = ''; if (df) df.value = '';
      document.getElementById('bannerBuscaManual')?.classList.remove('show');
    },

    // ────────────────────────────────────────────────────────────────────────
    // EVENT BINDING
    // ────────────────────────────────────────────────────────────────────────

    bindEvents() {
      const $ = (id) => document.getElementById(id);

      $('btnBuscarHoje')?.addEventListener('click', () => this.buscarHoje());
      $('btnAplicarFiltro')?.addEventListener('click', () => this.aplicarFiltros());
      $('btnLimparFiltro')?.addEventListener('click', () => this.limparFiltros());

      // Shortcuts de período
      $('periodHoje')?.addEventListener('click', () => this.setPeriodoShortcut(1));
      $('period7dias')?.addEventListener('click', () => this.setPeriodoShortcut(7));
      $('period30dias')?.addEventListener('click', () => this.setPeriodoShortcut(30));
      $('periodLimpar')?.addEventListener('click', () => this.clearPeriodo());

      // Nudge no filtro lateral abre o modal Monitoramento
      $('lnkOpenMon')?.addEventListener('click', (e) => { e.preventDefault(); this.openMonitors(); });

      // Salvar perfil dentro do modal Monitoramento
      $('myProfSave')?.addEventListener('click', () => this.saveMyProfile());

      // Profile Setup Modal (forçado quando user busca sem nada)
      $('profileModalClose')?.addEventListener('click', () => this.closeProfileModal());
      $('profSkip')?.addEventListener('click', () => { this.closeProfileModal(); this.buscarHoje(true); });
      $('profSubmit')?.addEventListener('click', () => this.submitProfileModal());
      $('profileModal')?.addEventListener('click', (e) => {
        if (e.target.id === 'profileModal') this.closeProfileModal();
      });

      // Modal Monitores
      $('btnMonitores')?.addEventListener('click', () => this.openMonitors());
      $('monitorsClose')?.addEventListener('click', () => this.closeMonitors());
      $('monitorsModal')?.addEventListener('click', (e) => {
        if (e.target.id === 'monitorsModal') this.closeMonitors();
      });
      $('monNewSubmit')?.addEventListener('click', () => this.createMonitor());
      $('monitorsList')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-mon-action]');
        if (!btn) return;
        const id = parseInt(btn.dataset.monId, 10);
        const act = btn.dataset.monAction;
        this.handleMonitorAction(act, id, btn);
      });

      // Tabs — agora com switch real entre DJEN e AASP
      document.querySelectorAll('.int-tab').forEach((t) => {
        t.addEventListener('click', () => {
          document.querySelectorAll('.int-tab').forEach((x) => x.classList.remove('active'));
          t.classList.add('active');
          this.switchTab(t.dataset.tab || 'djen');
        });
      });

      // Aba AASP — botões do painel e modal
      $('btnAaspConectar')?.addEventListener('click', () => this.openAaspModal('create'));
      $('aaspModalClose')?.addEventListener('click', () => this.closeAaspModal());
      $('aaspCancel')?.addEventListener('click', () => this.closeAaspModal());
      $('aaspModal')?.addEventListener('click', (e) => {
        if (e.target.id === 'aaspModal') this.closeAaspModal();
      });
      $('aaspTipo')?.addEventListener('change', (e) => {
        const isEmpresa = e.target.value === 'empresa';
        const row = $('aaspEmpresaRow');
        if (row) row.style.display = isEmpresa ? '' : 'none';
      });
      $('aaspTest')?.addEventListener('click', () => this.testAaspChave());
      $('aaspSave')?.addEventListener('click', () => this.saveAaspIntegration());

      // Inputs do form: ao mudar, invalida a assinatura do último teste
      // (assim chave nova vai voltar a precisar testar pra salvar como active)
      ['aaspChave', 'aaspTipo', 'aaspCodigos'].forEach((id) => {
        $(id)?.addEventListener('input', () => {
          if (this.aaspState.lastTestSignature !== null) {
            // Só invalida se a assinatura realmente mudou (evita resets desnecessários)
            if (this._aaspFormSignature() !== this.aaspState.lastTestSignature) {
              this.aaspState.lastTestSignature = null;
              this._updateAaspSaveButton();
            }
          }
        });
      });
      $('aaspTipo')?.addEventListener('change', () => {
        // mesmo handler de input pro select
        if (this.aaspState.lastTestSignature !== null
            && this._aaspFormSignature() !== this.aaspState.lastTestSignature) {
          this.aaspState.lastTestSignature = null;
          this._updateAaspSaveButton();
        }
      });

      // Shortcuts de data no teste de chave AASP
      $('aaspTestDateHoje')?.addEventListener('click', () => {
        $('aaspTestData').value = new Date().toISOString().slice(0, 10);
      });
      $('aaspTestDateOntem')?.addEventListener('click', () => {
        const d = new Date(); d.setDate(d.getDate() - 1);
        $('aaspTestData').value = d.toISOString().slice(0, 10);
      });
      $('aaspTestDateUltSexta')?.addEventListener('click', () => {
        const d = new Date();
        // Volta até cair em sexta-feira (dia 5)
        while (d.getDay() !== 5) { d.setDate(d.getDate() - 1); }
        // Se hoje é sexta, pega a sexta anterior pra garantir intimações já publicadas
        if (d.toDateString() === new Date().toDateString()) {
          d.setDate(d.getDate() - 7);
        }
        $('aaspTestData').value = d.toISOString().slice(0, 10);
      });

      // Delegated nos cards de integração AASP (sincronizar/rotacionar/excluir)
      $('aaspIntegrationsList')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-aasp-action]');
        if (!btn) return;
        const id  = parseInt(btn.dataset.aaspId, 10);
        const act = btn.dataset.aaspAction;
        this.handleAaspIntegrationAction(act, id, btn);
      });

      // Mostra banner LGPD quando seleciona data antiga + atualiza microcopy
      ['filterDataInicio', 'filterDataFim'].forEach((id) => {
        $(id)?.addEventListener('change', () => {
          const di = $('filterDataInicio')?.value;
          const df = $('filterDataFim')?.value;
          const hoje = new Date().toISOString().slice(0, 10);
          const isManual = (di && di < hoje) || (df && df < hoje);
          $('bannerBuscaManual')?.classList.toggle('show', isManual);
          this.updateSearchHint();
        });
      });
      // Atualiza microcopy ao mudar qualquer filtro
      ['filterPeriodo','filterTribunal','filterProcesso','filterOab','filterNomeAdv'].forEach((id) => {
        $(id)?.addEventListener('change', () => this.updateSearchHint());
        $(id)?.addEventListener('input',  () => this.updateSearchHint());
      });

      // Delegated click handler na lista (lida / favorita / popups / toggle-text)
      $('intList')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const action = btn.dataset.action;
        const hash   = btn.dataset.hash;
        const eventId = btn.dataset.eventId ? parseInt(btn.dataset.eventId, 10) : null;

        if (action === 'toggle-text') {
          // Expande/recolhe o texto da publicação (não exige item no state)
          const card = btn.closest('.int-pub-card');
          const txt  = card?.querySelector('.int-pub-text');
          if (txt) {
            const isExp = txt.classList.toggle('expanded');
            btn.textContent = isExp
              ? 'Recolher'
              : 'Ver tudo (' + parseInt(txt.dataset.textLen || '0', 10).toLocaleString('pt-BR') + ' caracteres)';
          }
          return;
        }

        const item = this.state.items.find((x) => x.hash_conteudo === hash);
        if (!item) return;
        if (action.startsWith('popup-')) {
          this.openActionPopup(action, item, eventId, btn);
        } else {
          this.handleAction(action, item, eventId);
        }
      });
    },

    // ────────────────────────────────────────────────────────────────────────
    // ACTIONS (HTTP CALLS)
    // ────────────────────────────────────────────────────────────────────────

    async buscarHoje(skipSetupCheck = false) {
      // Guard: sem filtro + sem perfil pessoal + sem nenhum monitor no tenant → força setup
      // Se já tem monitor cadastrado (mesmo que de outro user do tenant), pula o modal —
      // a busca de hoje vai usar os monitores existentes via cron/list.
      if (!skipSetupCheck) {
        const f = this.collectFilters({ ignoreDates: true });
        const semFiltro = !f.numero_oab && !f.nome_advogado && !f.numero_processo;
        const p = this.userProfile || {};
        const semPerfilPessoal = !p.oab && !p.nome_advogado;
        const semMonitorTenant = !p.hasMonitors;
        if (semFiltro && semPerfilPessoal && semMonitorTenant) {
          this.openProfileModal();
          return;
        }
      }

      this.setLoading(true);
      try {
        const data = await this.api('POST', '/search.php', {
          mode: 'cache_hoje',
          filters: this.collectFilters({ ignoreDates: true }),
        });
        if (!data.ok) throw new Error(data.error || 'Erro ao buscar');
        this.notify(`Cache atualizado: ${data.cached} ${this._pl(data.cached, 'nova', 'novas')}, ${data.total} no total.`, 'success');
        // FORÇA filtro de data=HOJE no painel lateral — botão "Buscar publicações
        // de hoje" tem que mostrar SÓ hoje, não dias passados que estão no cache.
        // Sem isso, loadPersistidos lista o cache inteiro (cross-day).
        this.setPeriodoShortcut(1);
        await this.loadPersistidos();
      } catch (err) {
        this.notify('Erro ao buscar publicações: ' + err.message, 'error');
      } finally {
        this.setLoading(false);
      }
    },

    async aplicarFiltros() {
      const f = this.collectFilters();
      const hoje = new Date().toISOString().slice(0, 10);
      const isManual = (f.data_inicio && f.data_inicio < hoje) || (f.data_fim && f.data_fim < hoje);

      // Aba AASP + período preenchido → busca direta na AASP (fan-out por dia)
      if (this.state.sourceFilter === 'aasp' && (f.data_inicio || f.data_fim)) {
        await this.searchAaspPeriodo(f);
        return;
      }

      if (isManual) {
        // Busca remota sem persistir (DJEN)
        this.setLoading(true);
        try {
          const data = await this.api('POST', '/search.php', { mode: 'manual', filters: f });
          if (!data.ok) throw new Error(data.error || 'Erro ao buscar');
          this.state.items = (data.items || []).map((x) => ({ ...x, _from_search: true }));
          this.render();
          document.getElementById('bannerBuscaManual')?.classList.add('show');
          this.notify(`${this.state.items.length} resultado(s) — não persistidos.`, 'info');
        } catch (err) {
          this.notify('Erro: ' + err.message, 'error');
        } finally {
          this.setLoading(false);
        }
      } else {
        // Lista local com filtros de status
        await this.loadPersistidos();
      }
    },

    /**
     * Busca AASP por período (fan-out: 1 chamada por dia com intimação no range).
     * Usa primeira integração ativa/pending do tenant por padrão.
     */
    async searchAaspPeriodo(filters) {
      const ints = (this.aaspState.integrations || []).filter((i) => i.status === 'active' || i.status === 'pending');
      if (!ints.length) {
        this.notify('Nenhuma integração AASP ativa. Cadastre uma chave AASP antes de buscar.', 'error');
        return;
      }
      // Default: usa a primeira ativa/pending. Se múltiplas, TODO: dropdown.
      const integrationId = ints[0].id;

      // Range — se só uma das datas, completa com hoje no outro lado
      const hoje = new Date().toISOString().slice(0, 10);
      const dataIni = filters.data_inicio || hoje;
      const dataFim = filters.data_fim    || hoje;

      this.setLoading(true);
      // Mostra status "buscando" no painel AASP
      this._renderAaspLastSearch({
        loading: true, dataIni, dataFim, nome: ints[0].nome,
      });
      try {
        const data = await this.api('POST', '/sistema_vendas/public/api/aasp/search.php', {
          integration_id: integrationId,
          data_inicio: dataIni,
          data_fim: dataFim,
          persistir: false,  // busca manual nunca persiste (igual DJEN)
        });
        if (!data.ok) throw new Error(data.error || 'Erro AASP');
        // Mostra items na lista compartilhada — reusa template do DJEN
        this.state.items = (data.items || []).map((x) => ({ ...x, _from_search: true }));
        this.render();
        document.getElementById('bannerBuscaManual')?.classList.add('show');
        const diasReais = (data.dias_com_intimacao || []).length;
        const diasRange = data.dias_no_range || 0;

        // Resumo da última busca no painel AASP (em cima)
        this._renderAaspLastSearch({
          dataIni: data.data_inicio, dataFim: data.data_fim,
          nome: data.integration_nome,
          diasNoRange: diasRange, diasComIntimacao: data.dias_com_intimacao || [],
          total: data.total, requestCount: data.request_count, duracaoMs: data.duracao_ms,
          jornaisStatus: data.jornais_status, jornaisDatasRaw: data.jornais_datas_raw || [],
          usedFallback: data.used_fallback,
          firstItemRaw: data.first_item_raw, firstItemRawKeys: data.first_item_raw_keys || [],
        });

        if (data.total === 0) {
          let msg = `AASP "${data.integration_nome}": 0 intimações em ${this.fmtData(dataIni)}..${this.fmtData(dataFim)}.`;
          if (data.jornais_status === 'vazio' && data.used_fallback) {
            msg += ` GetJornais confirmou 0 dias com intimação; fallback consultou ${data.request_count} dia(s) e nada veio.`;
            msg += ` Provável: chave AASP não tem intimações cadastradas no monitoramento, ou ainda está em ativação.`;
          } else if (data.jornais_status === 'erro') {
            msg += ` GetJornais falhou; fallback dia-a-dia também sem itens.`;
          } else if (diasReais > 0 && data.total === 0) {
            msg += ` AASP listou ${diasReais} dias com intimação, mas nenhum item veio (provável: chave de outro associado).`;
          }
          this.notify(msg, 'info');
        } else {
          this.notify(
            `AASP "${data.integration_nome}": ${data.total} ${this._pl(data.total, 'intimação', 'intimações')} em ${diasReais} de ${diasRange} dias · ${data.duracao_ms}ms · ${data.request_count} ${this._pl(data.request_count, 'chamada', 'chamadas')}`,
            'success'
          );
        }
        // Atualiza KPI dos cards de integração (status pode ter virado active)
        await this.loadAaspIntegrations();
      } catch (err) {
        this.notify('AASP busca falhou: ' + err.message, 'error');
        this._renderAaspLastSearch({
          dataIni, dataFim, nome: ints[0].nome, erro: err.message,
        });
      } finally {
        this.setLoading(false);
      }
    },

    /** Preenche o painel "Resumo da última busca AASP". */
    _renderAaspLastSearch(info) {
      const el = document.getElementById('aaspLastSearch');
      if (!el) return;
      el.style.display = '';
      const e = (s) => this._esc(s);
      const range = `${this.fmtData(info.dataIni)} a ${this.fmtData(info.dataFim)}`;
      if (info.loading) {
        el.innerHTML = `<strong>⏳ Buscando…</strong> AASP "${e(info.nome)}" · período ${e(range)}`;
        return;
      }
      if (info.erro) {
        el.innerHTML = `<strong style="color:#F87171;">Erro:</strong> ${e(info.erro)} · "${e(info.nome)}" · ${e(range)}`;
        return;
      }
      const diasIntim = (info.diasComIntimacao || []).map((d) => this.fmtData(d)).join(', ') || 'nenhum';

      // Diagnóstico do GetJornaisComIntimacoes
      let diagnose = '';
      if (info.jornaisStatus === 'vazio' && info.usedFallback) {
        diagnose = `<br><span class="muted">Diagnóstico: o endpoint GetJornaisComIntimacoes da AASP retornou <strong>0 dias com intimação</strong> nos últimos dias. Por garantia, consultei ${info.requestCount} dia(s) um por um (fallback). Resultado: ${info.total} item(ns).</span>`;
      } else if (info.jornaisStatus === 'erro') {
        diagnose = `<br><span class="muted">Diagnóstico: GetJornaisComIntimacoes falhou. Fallback consultou ${info.requestCount} dia(s) individualmente.</span>`;
      } else if ((info.jornaisDatasRaw || []).length > 0) {
        const fora = (info.jornaisDatasRaw || []).filter((d) => d < info.dataIni || d > info.dataFim);
        if (fora.length) {
          diagnose = `<br><span class="muted">AASP listou ${info.jornaisDatasRaw.length} dia(s) com intimação no total (últimos ${info.diasNoRange}+), mas só ${(info.diasComIntimacao||[]).length} caem no seu período pedido.</span>`;
        }
      }

      // Diagnóstico extra: se há items mas o card aparece vazio, mostrar campos raw
      // pra dev ajustar o normalize(). Só aparece quando há items.
      let rawDiag = '';
      if (info.firstItemRaw && info.firstItemRawKeys && info.firstItemRawKeys.length) {
        const rawJson = JSON.stringify(info.firstItemRaw, null, 2);
        const rawTrunc = rawJson.length > 3000 ? rawJson.slice(0, 3000) + '\n…(truncado)' : rawJson;
        rawDiag = `
          <details style="margin-top:10px;cursor:pointer;">
            <summary style="font-size:.78rem;font-weight:600;color:#93c5fd;">
              ⚠ Diagnóstico: campos raw do 1º item AASP (clique pra expandir)
            </summary>
            <div style="margin-top:6px;font-size:.72rem;">
              <strong>Campos disponíveis (${info.firstItemRawKeys.length}):</strong>
              <code style="display:block;padding:6px;background:rgba(8,20,40,.6);border-radius:4px;margin-top:4px;font-size:.7rem;overflow-x:auto;">${e(info.firstItemRawKeys.join(', '))}</code>
              <strong style="display:block;margin-top:8px;">Payload completo do 1º item:</strong>
              <pre style="margin-top:4px;padding:8px;max-height:280px;overflow:auto;background:rgba(8,20,40,.6);border-radius:5px;font-size:.7rem;white-space:pre-wrap;word-break:break-word;">${e(rawTrunc)}</pre>
              <span class="muted">Se os cards estão aparecendo vazios, manda print deste bloco que o normalize() precisa ser ajustado pros nomes reais dos campos.</span>
            </div>
          </details>`;
      }

      el.innerHTML = `
        <strong>Última busca:</strong> "${e(info.nome)}" · período ${e(range)}<br>
        <strong>${info.total}</strong> ${this._pl(info.total, 'intimação', 'intimações')} · <strong>${(info.diasComIntimacao || []).length}</strong> de ${info.diasNoRange} ${this._pl(info.diasNoRange, 'dia', 'dias')} com intimação · ${info.duracaoMs}ms · ${info.requestCount} ${this._pl(info.requestCount, 'chamada', 'chamadas')}
        ${(info.diasComIntimacao || []).length ? `<br><span class="muted">Dias com intimação: ${e(diasIntim)}</span>` : ''}
        ${diagnose}
        ${rawDiag}
      `;
    },

    async loadPersistidos() {
      this.setLoading(true);
      try {
        const f = this.collectFilters();
        const qs = new URLSearchParams();
        if (this.state.filters.lida !== null)     qs.set('lida',      String(this.state.filters.lida));
        if (this.state.filters.favorita !== null) qs.set('favorita',  String(this.state.filters.favorita));
        if (this.state.filters.com_prazo !== null)qs.set('com_prazo', String(this.state.filters.com_prazo));
        if (f.data_inicio)     qs.set('data_inicio', f.data_inicio);
        if (f.data_fim)        qs.set('data_fim',    f.data_fim);
        if (f.sigla_tribunal)  qs.set('tribunal',    f.sigla_tribunal);
        if (f.numero_processo) qs.set('processo',    f.numero_processo);
        // Filtro por provider — set quando aba AASP ou DJEN explícita
        if (this.state.sourceFilter) qs.set('source_id', this.state.sourceFilter);
        qs.set('limit', '100');

        const url = '/list.php?' + qs.toString();
        const data = await this.api('GET', url);
        if (!data.ok) throw new Error(data.error || 'Erro');
        this.state.items = data.items || [];
        this.render();
        this.updateKpi(data.nao_lidas);
        this.updateToolbarCacheInfo();
      } catch (err) {
        this.notify('Erro ao carregar: ' + err.message, 'error');
      } finally {
        this.setLoading(false);
      }
    },

    async handleAction(action, item, eventId) {
      try {
        const body = { action, payload: item };
        if (eventId) body.event_id = eventId;

        const data = await this.api('POST', '/persist.php', body);
        if (!data.ok) throw new Error(data.error || 'Erro');

        // Atualiza item local
        item.id = data.event_id;
        if (action === 'read')     { item.lida = 1; item._from_cache = false; }
        if (action === 'unread')   { item.lida = 0; }
        if (action === 'favorite') { item.favorita = data.favorita ? 1 : 0; item._from_cache = false; }

        this.render();
        await this.refreshKpi();
      } catch (err) {
        this.notify('Erro: ' + err.message, 'error');
      }
    },

    // ────────────────────────────────────────────────────────────────────────
    // FILTROS
    // ────────────────────────────────────────────────────────────────────────

    collectFilters(opts = {}) {
      const $ = (id) => document.getElementById(id);
      const f = {
        numero_oab:      $('filterOab')?.value.trim()      || '',
        sigla_tribunal:  $('filterTribunal')?.value        || '',
        numero_processo: $('filterProcesso')?.value.trim() || '',
        data_inicio:     opts.ignoreDates ? '' : ($('filterDataInicio')?.value || ''),
        data_fim:        opts.ignoreDates ? '' : ($('filterDataFim')?.value    || ''),
        nome_advogado:   $('filterNomeAdv')?.value.trim()  || '',
      };
      // Aplica filtros de status no state (não vão pro provider — usados em loadPersistidos)
      this.state.filters.lida      = $('fNaoLidas')?.checked ? 0 : ($('fLidas')?.checked ? 1 : null);
      this.state.filters.favorita  = $('fFavoritas')?.checked ? 1 : null;
      this.state.filters.com_prazo = $('fComPrazo')?.checked  ? 1 : null;
      return f;
    },

    limparFiltros() {
      document.querySelectorAll('.int-filter-input').forEach((i) => { i.value = ''; });
      document.querySelectorAll('.int-filter-cb input').forEach((c) => { c.checked = (c.id === 'fNaoLidas'); });
      document.getElementById('bannerBuscaManual')?.classList.remove('show');
      this.state.filters = { lida: 0, favorita: null, com_prazo: null };
      // Limpa também o calendário visual
      this.clearPeriodo();
      // Re-aplica defaults do perfil (se houver)
      this.applyUserDefaults();
      this.loadPersistidos();
    },

    // ────────────────────────────────────────────────────────────────────────
    // PERFIL DO USUÁRIO (OAB + Nome como filtro padrão)
    // ────────────────────────────────────────────────────────────────────────

    async loadUserDefaults() {
      try {
        const data = await this.api('GET', '/user_filters.php');
        if (!data.ok) return;
        this.userProfile = data.filters || {};
        this.userProfile.missing = data.missing || [];
        this.userProfile.hasMonitors = !!data.has_monitors;
        this.applyUserDefaults();
        this.updateProfileNudge();
      } catch (e) {
        console.warn('[intimacoes] loadUserDefaults falhou:', e.message);
      }
    },

    /** Pré-preenche os inputs do filtro lateral com os defaults do perfil. */
    applyUserDefaults() {
      const p = this.userProfile || {};
      const $ = (id) => document.getElementById(id);
      const oabInp = $('filterOab');
      const nomeInp = $('filterNomeAdv');
      if (oabInp && !oabInp.value) {
        oabInp.value = (p.oab_uf || '') + (p.oab || '');
      }
      if (nomeInp && !nomeInp.value) {
        nomeInp.value = p.nome_advogado || p.nome || '';
      }
    },

    /** Mostra o nudge "Salvar como meu perfil" se faltam dados ou sem monitores. */
    updateProfileNudge() {
      const nudge = document.getElementById('profileNudge');
      if (!nudge) return;
      const p = this.userProfile || {};
      const oabFilled = ((p.oab || '') !== '');
      const nomeFilled = ((p.nome_advogado || p.nome || '') !== '');
      const incomplete = !oabFilled || !p.hasMonitors;
      nudge.style.display = incomplete ? 'block' : 'none';
    },

    /** Salva perfil a partir dos campos DENTRO do modal Monitoramento. */
    async saveMyProfile() {
      const $ = (id) => document.getElementById(id);
      const uf   = ($('myProfUf')?.value   || '').trim().toUpperCase();
      const oab  = ($('myProfOab')?.value  || '').trim();
      const nome = ($('myProfNome')?.value || '').trim();

      if (!oab && !nome) {
        this.notify('Preencha OAB ou Nome (pelo menos um).', 'warn');
        return;
      }

      const statusEl = $('myProfStatus');
      if (statusEl) statusEl.textContent = 'salvando…';

      try {
        const data = await this.api('PATCH', '/user_filters.php', {
          oab, oab_uf: uf, nome_advogado: nome, auto_monitor: true,
        });
        if (!data.ok) throw new Error(data.error || 'Erro');
        this.userProfile = data.filters || {};
        this.userProfile.hasMonitors = true;

        const created = (data.monitors_created || []).filter(m => m.novo);
        const reused  = (data.monitors_created || []).filter(m => !m.novo);
        let msg = 'Perfil salvo.';
        if (created.length) msg += ' ' + created.length + ' monitor(es) criado(s).';
        if (reused.length)  msg += ' ' + reused.length + ' já existia(m).';
        this.notify(msg, 'success');
        if (statusEl) statusEl.textContent = 'salvo ✓';

        // Atualiza UI: nudge desaparece, inputs do filtro lateral preenchidos, lista de monitores recarrega
        this.applyUserDefaults();
        this.updateProfileNudge();
        this.loadMonitors();
      } catch (err) {
        if (statusEl) statusEl.textContent = '';
        this.notify('Erro: ' + err.message, 'error');
      }
    },

    // ────────────────────────────────────────────────────────────────────────
    // PROFILE SETUP MODAL (forçado quando user busca sem filtro + sem perfil)
    // ────────────────────────────────────────────────────────────────────────

    openProfileModal() {
      const $ = (id) => document.getElementById(id);
      // Pré-popula com o que tiver no filtro ou no userProfile
      const oabFiltro  = ($('filterOab')?.value     || '').trim();
      const nomeFiltro = ($('filterNomeAdv')?.value || '').trim();
      const p = this.userProfile || {};

      let oab = '', uf = '';
      if (oabFiltro) {
        const m = /^([A-Z]{2})\s*0*(\d+)$/i.exec(oabFiltro);
        if (m) { uf = m[1].toUpperCase(); oab = m[2]; } else { oab = oabFiltro; }
      } else {
        oab = p.oab || ''; uf = p.oab_uf || '';
      }

      $('profUf').value   = uf;
      $('profOab').value  = oab;
      $('profNome').value = nomeFiltro || p.nome_advogado || p.nome || '';

      $('profileModal')?.classList.add('show');
      setTimeout(() => $('profOab')?.focus(), 100);
    },

    closeProfileModal() {
      document.getElementById('profileModal')?.classList.remove('show');
    },

    async submitProfileModal() {
      const $ = (id) => document.getElementById(id);
      const uf   = ($('profUf')?.value   || '').trim().toUpperCase();
      const oab  = ($('profOab')?.value  || '').trim();
      const nome = ($('profNome')?.value || '').trim();

      if (!oab && !nome) {
        this.notify('Preencha OAB ou Nome (pelo menos um).', 'warn');
        return;
      }

      try {
        const data = await this.api('PATCH', '/user_filters.php', {
          oab, oab_uf: uf, nome_advogado: nome, auto_monitor: true,
        });
        if (!data.ok) throw new Error(data.error || 'Erro');
        this.userProfile = data.filters || {};
        this.userProfile.hasMonitors = true;

        const created = (data.monitors_created || []).filter(m => m.novo);
        let msg = 'Perfil salvo';
        if (created.length) msg += ` (${created.length} monitor(es) criado(s))`;
        this.notify(msg + '. Buscando suas intimações…', 'success');

        // Re-aplica defaults nos inputs do filtro lateral e dispara busca
        this.applyUserDefaults();
        this.updateProfileNudge();
        this.closeProfileModal();
        await this.buscarHoje(true); // skipSetupCheck=true
      } catch (err) {
        this.notify('Erro: ' + err.message, 'error');
      }
    },

    // ────────────────────────────────────────────────────────────────────────
    // RENDER
    // ────────────────────────────────────────────────────────────────────────

    render() {
      const list = document.getElementById('intList');
      if (!list) return;
      // Dedup: mesma intimação (processo+data+tribunal+source) pode vir N vezes
      // do provider (DJEN cria 1 entrada por intimado/destinatário quando a
      // mesma decisão tem múltiplos). Consolidamos visualmente preservando
      // o conteúdo do primeiro e somando lista de destinatários.
      const items = this._dedupItems(this.state.items);

      if (!items.length) {
        list.innerHTML = `
          <div class="int-empty">
            <span class="int-empty-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </span>
            <h3>Nenhuma publicação encontrada</h3>
            <p>Ajuste os filtros ou clique em <strong>Buscar publicações de hoje</strong>.</p>
          </div>`;
        return;
      }

      // Agrupar por data
      const grupos = {};
      items.forEach((it) => {
        const d = it.data_disponibilizacao || '0000-00-00';
        if (!grupos[d]) grupos[d] = [];
        grupos[d].push(it);
      });
      const datas = Object.keys(grupos).sort().reverse();

      let html = '';
      for (const d of datas) {
        const label = this.fmtData(d);
        html += `<div class="int-day-header">Dia ${label} — ${grupos[d].length} ${this._pl(grupos[d].length, 'publicação', 'publicações')}</div>`;
        for (const it of grupos[d]) {
          html += this.renderCard(it);
        }
      }
      list.innerHTML = html;

      // KPI dinâmico: conta items NÃO-LIDOS na vista atual.
      // Inclui itens não-persistidos (cache/search) que por natureza não foram lidos.
      const naoLidas = items.filter((it) => !it.lida).length;
      this.updateKpi(naoLidas);
    },

    /**
     * Dedup visual: DESABILITADO por escolha do usuário (2026-05-25).
     * Quando habilitado, agrupava por (source_id + tribunal + processo + data)
     * consolidando intimados de uma mesma decisão. User preferiu manter 1 card
     * por entrada do Diário pra bater 1:1 com a AASP nativa.
     *
     * Pra reabilitar: substituir o return por implementação de Map por chave
     * (ver histórico git do commit 5cd9dc3).
     */
    _dedupItems(items) {
      return items || [];
    },

    /** Renderiza linha "Adv: Nome1 (UF-OAB1), ..." destacando OAB do user. */
    _renderAdvogadosLine(advs) {
      if (!Array.isArray(advs) || !advs.length) return '';
      const myOab = (this.userProfile && this.userProfile.oab || '').replace(/\D/g, '');
      const myUf  = (this.userProfile && this.userProfile.oab_uf || '').toUpperCase();
      const esc = (s) => this._esc(s);
      const parts = advs.slice(0, 8).map(a => {
        const oab = (a.oab || '').replace(/\D/g, '');
        const uf  = (a.uf || '').toUpperCase();
        const isMine = myOab && oab === myOab && (!myUf || uf === myUf);
        const label = `${esc(a.nome || '?')} (${esc(uf)}-${esc(oab)})`;
        return isMine ? `<strong style="color:#34D399;">${label} ✓</strong>` : label;
      });
      const more = advs.length > 8 ? ` <em>+${advs.length - 8} outros</em>` : '';
      return `<div class="int-pub-advs"><strong>Adv:</strong> ${parts.join(', ')}${more}</div>`;
    },

    renderCard(it) {
      const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
      );
      const unread = !it.lida && !it._from_search;
      const fromCache = it._from_cache;
      const eventIdAttr = it.id ? `data-event-id="${it.id}"` : '';
      const procMask = it.numero_processo_mascara || it.numero_processo || '—';
      // Defesa em profundidade: cache antigo pode ter HTML inline → limpa no render
      const texto = this.cleanText(it.conteudo || it.resumo || it.texto || '');
      const orgao = it.orgao || 'Órgão não identificado';
      const tipo  = it.tipo_comunicacao || 'Comunicação';
      const hasComment = !!(it.comentario && it.comentario.trim());
      const hasPrazo   = !!it.com_prazo;
      const hasLink    = !!it.processo_id;

      const tagLine = [
        hasComment ? '<span style="color:#93c5fd;" title="Tem comentário">• comentário</span>' : '',
        hasPrazo   ? `<span style="color:#fbbf24;" title="Prazo">• prazo ${esc(this.fmtData(it.prazo_data || ''))}</span>` : '',
        hasLink    ? '<span style="color:#a78bfa;" title="Vinculado a processo">• processo vinculado</span>' : '',
      ].filter(Boolean).join(' ');

      return `
        <div class="int-pub-card ${unread ? 'unread' : ''}" data-hash="${esc(it.hash_conteudo)}">
          <div class="int-pub-body">
            <div class="int-pub-meta">
              <span class="int-pub-trib">${esc(it.tribunal)}</span>
              <span><strong>${esc(tipo)}</strong></span>
              <span>Processo: <strong>${esc(procMask)}</strong></span>
              ${fromCache ? '<span style="color:#fbbf24;">cache temporário</span>' : ''}
              ${it._from_search ? '<span style="color:#fbbf24;">resultado não persistido</span>' : ''}
            </div>
            <div class="int-pub-orgao">${esc(orgao)}</div>
            ${this._renderAdvogadosLine(it.advogados)}
            <div class="int-pub-text" data-text-len="${texto.length}">${esc(this._formatLegal(texto))}${texto.length > 400 ? '<div class="int-pub-text-fade"></div>' : ''}</div>
            ${texto.length > 400
              ? `<button class="int-pub-text-toggle" data-action="toggle-text" data-hash="${esc(it.hash_conteudo)}">Ver tudo (${texto.length.toLocaleString('pt-BR')} caracteres)</button>`
              : ''}
            ${tagLine ? `<div class="int-pub-meta" style="margin-top:6px;">${tagLine}</div>` : ''}
          </div>
          <div class="int-pub-actions">
            <button class="int-icon-btn" title="${it.lida ? 'Marcar como não lida' : 'Marcar como lida'}"
                    data-action="${it.lida ? 'unread' : 'read'}" data-hash="${esc(it.hash_conteudo)}" ${eventIdAttr}>
              ${it.lida
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>'}
            </button>
            <button class="int-icon-btn" title="${it.favorita ? 'Remover dos favoritos' : 'Favoritar'}"
                    data-action="favorite" data-hash="${esc(it.hash_conteudo)}" ${eventIdAttr}
                    style="${it.favorita ? 'color:#fbbf24;' : ''}">
              <svg viewBox="0 0 24 24" fill="${it.favorita ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </button>
            <button class="int-icon-btn" title="Definir prazo"
                    data-action="popup-deadline" data-hash="${esc(it.hash_conteudo)}" ${eventIdAttr}
                    style="${hasPrazo ? 'color:#fbbf24;' : ''}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </button>
            <button class="int-icon-btn" title="Comentário"
                    data-action="popup-comment" data-hash="${esc(it.hash_conteudo)}" ${eventIdAttr}
                    style="${hasComment ? 'color:#93c5fd;' : ''}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            </button>
            <button class="int-icon-btn" title="Vincular ao processo"
                    data-action="popup-link" data-hash="${esc(it.hash_conteudo)}" ${eventIdAttr}
                    style="${hasLink ? 'color:#a78bfa;' : ''}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            </button>
          </div>
        </div>`;
    },

    // ────────────────────────────────────────────────────────────────────────
    // POPUPS DE AÇÃO (comment / deadline / link-process)
    // ────────────────────────────────────────────────────────────────────────

    closeActionPopup() {
      document.querySelectorAll('.int-action-popup').forEach((p) => p.remove());
    },

    openActionPopup(action, item, eventId, anchor) {
      this.closeActionPopup();
      const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

      let body = '';
      if (action === 'popup-comment') {
        body = `
          <h4>Comentário interno <span class="close-x" data-close="1">×</span></h4>
          <label>Visível apenas para você</label>
          <textarea id="popInpComment" placeholder="Anotações sobre esta intimação...">${esc(item.comentario || '')}</textarea>
          <div class="actions">
            <button class="int-btn" data-close="1">Cancelar</button>
            <button class="int-btn primary" data-submit="comment">Salvar</button>
          </div>`;
      } else if (action === 'popup-deadline') {
        body = `
          <h4>Definir prazo <span class="close-x" data-close="1">×</span></h4>
          <label>Data limite</label>
          <input type="date" id="popInpDeadline" value="${esc(item.prazo_data || '')}">
          <label class="int-popup-checkbox">
            <input type="checkbox" id="popInpCreateTask" ${!item.com_prazo ? 'checked' : ''}>
            Criar tarefa automática no módulo Tarefas
          </label>
          <div class="actions">
            ${item.com_prazo ? '<button class="int-btn" data-submit="deadline-clear" style="background:rgba(176,96,112,.15);border-color:rgba(176,96,112,.3);color:#f87171;">Remover prazo</button>' : ''}
            <button class="int-btn" data-close="1">Cancelar</button>
            <button class="int-btn primary" data-submit="deadline">Salvar</button>
          </div>`;
      } else if (action === 'popup-link') {
        body = `
          <h4>Vincular ao processo <span class="close-x" data-close="1">×</span></h4>
          <label>Buscar por número, autor, réu ou descrição</label>
          <input type="text" id="popInpLink" placeholder="ex: 1002107-16.2025...">
          <div class="int-popup-suggest" id="popLinkSuggest" style="display:none;"></div>
          <div class="actions">
            <button class="int-btn" data-close="1">Cancelar</button>
          </div>`;
      } else {
        return;
      }

      const pop = document.createElement('div');
      pop.className = 'int-action-popup';
      pop.dataset.hash = item.hash_conteudo || '';
      if (eventId) pop.dataset.eventId = eventId;
      pop.innerHTML = body;
      document.body.appendChild(pop);

      // Posicionamento: à direita do botão clicado, ou abaixo se faltar espaço
      const rect = anchor.getBoundingClientRect();
      pop.style.top  = (window.scrollY + rect.bottom + 6) + 'px';
      pop.style.left = Math.max(8, Math.min(window.innerWidth - 400, window.scrollX + rect.left - 280)) + 'px';

      // Eventos do popup
      pop.addEventListener('click', (e) => {
        if (e.target.dataset.close === '1') { this.closeActionPopup(); return; }
        const sub = e.target.dataset.submit;
        if (sub) this.submitActionPopup(sub, item, eventId, pop);
      });

      // Autocomplete pra link-process
      if (action === 'popup-link') {
        const inp = pop.querySelector('#popInpLink');
        const sugg = pop.querySelector('#popLinkSuggest');
        let t = null;
        inp.addEventListener('input', () => {
          clearTimeout(t);
          const q = inp.value.trim();
          if (q.length < 2) { sugg.style.display = 'none'; return; }
          t = setTimeout(() => this.searchProcessos(q, sugg, item, eventId, pop), 280);
        });
        setTimeout(() => inp.focus(), 80);
      } else {
        const firstInp = pop.querySelector('textarea, input');
        if (firstInp) setTimeout(() => firstInp.focus(), 80);
      }

      // Fecha ao clicar fora
      setTimeout(() => {
        const outsideHandler = (ev) => {
          if (!pop.contains(ev.target) && !anchor.contains(ev.target)) {
            this.closeActionPopup();
            document.removeEventListener('click', outsideHandler, true);
          }
        };
        document.addEventListener('click', outsideHandler, true);
      }, 50);
    },

    async submitActionPopup(submitType, item, eventId, pop) {
      try {
        if (submitType === 'comment') {
          const texto = pop.querySelector('#popInpComment')?.value || '';
          const data = await this.api('POST', '/persist.php', {
            action: 'comment',
            payload: item,
            event_id: eventId || null,
            extra: { texto },
          });
          if (!data.ok) throw new Error(data.error || 'Erro');
          item.id = data.event_id;
          item.comentario = texto;
          this.notify('Comentário salvo.', 'success');
        } else if (submitType === 'deadline' || submitType === 'deadline-clear') {
          const dateVal  = submitType === 'deadline-clear' ? null : (pop.querySelector('#popInpDeadline')?.value || null);
          const createTk = !!(pop.querySelector('#popInpCreateTask')?.checked);
          if (submitType === 'deadline' && !dateVal) {
            this.notify('Selecione uma data.', 'warn');
            return;
          }
          const data = await this.api('POST', '/persist.php', {
            action: 'deadline',
            payload: item,
            event_id: eventId || null,
            extra: { data: dateVal, create_task: createTk },
          });
          if (!data.ok) throw new Error(data.error || 'Erro');
          item.id = data.event_id;
          item.com_prazo  = dateVal ? 1 : 0;
          item.prazo_data = dateVal;
          if (data.task_id) {
            this.notify(`Prazo salvo + tarefa criada (#${data.task_id}).`, 'success');
          } else if (data.task_warning) {
            this.notify('Prazo salvo. Tarefa: ' + data.task_warning, 'warn');
          } else {
            this.notify(submitType === 'deadline-clear' ? 'Prazo removido.' : 'Prazo salvo.', 'success');
          }
        }
        this.closeActionPopup();
        this.render();
        this.refreshKpi();
      } catch (err) {
        this.notify('Erro: ' + err.message, 'error');
      }
    },

    async searchProcessos(q, sugg, item, eventId, pop) {
      try {
        const data = await this.api('GET', '/search_processos.php?q=' + encodeURIComponent(q));
        if (!data.ok) throw new Error(data.error || 'Erro');
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
          ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        if (!data.items.length) {
          sugg.innerHTML = '<div class="int-popup-suggest-item"><span class="sub">Nenhum processo encontrado.</span></div>';
        } else {
          sugg.innerHTML = data.items.map((p) =>
            `<div class="int-popup-suggest-item" data-pid="${p.id}">
               <div class="label">${esc(p.label)}</div>
               <div class="sub">${esc(p.sublabel || '')}</div>
             </div>`
          ).join('');
          sugg.querySelectorAll('[data-pid]').forEach((el) => {
            el.addEventListener('click', () => this.linkProcesso(parseInt(el.dataset.pid, 10), item, eventId, pop));
          });
        }
        sugg.style.display = 'block';
      } catch (err) {
        sugg.innerHTML = `<div class="int-popup-suggest-item"><span class="sub" style="color:#f87171;">Erro: ${err.message}</span></div>`;
        sugg.style.display = 'block';
      }
    },

    async linkProcesso(processoId, item, eventId, pop) {
      try {
        const data = await this.api('POST', '/persist.php', {
          action: 'link_process',
          payload: item,
          event_id: eventId || null,
          extra: { processo_id: processoId },
        });
        if (!data.ok) throw new Error(data.error || 'Erro');
        item.id = data.event_id;
        item.processo_id = data.processo_id;
        this.notify('Vinculado ao processo #' + processoId + '.', 'success');
        this.closeActionPopup();
        this.render();
      } catch (err) {
        this.notify('Erro: ' + err.message, 'error');
      }
    },

    // ────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ────────────────────────────────────────────────────────────────────────

    async api(method, path, body) {
      const opts = { method, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin' };
      // Todo método mutating precisa de CSRF — mesmo DELETE sem body.
      // Antes, DELETE/POST/PATCH sem body iam sem _csrf e o backend retornava
      // 403 silencioso (botão "Excluir" parecia não fazer nada).
      const mutating = method !== 'GET' && method !== 'HEAD';
      if (mutating || body) {
        opts.body = JSON.stringify(Object.assign({ _csrf: this.csrf }, body || {}));
      }
      // Aceita path absoluto (começa com /sistema_vendas/ ou http) — útil pra
      // atingir /api/aasp/* ou outros endpoints fora do apiBase do módulo Push.
      // Paths "/algo.php" são tratados como RELATIVOS ao apiBase (legacy),
      // senão quebraria '/monitors.php', '/list.php', etc → 404 em localhost root.
      const isAbs = path.startsWith('/sistema_vendas/') || /^https?:\/\//.test(path);
      const url = isAbs ? path : (this.apiBase + path);
      const r = await fetch(url, opts);
      const txt = await r.text();
      let json = {};
      try {
        json = JSON.parse(txt);
      } catch (_) {
        // Resposta não-JSON. Detecta HTML do Apache 404/500 e dá mensagem útil
        // em vez de cuspir <!DOCTYPE><html>...</html> inteiro no UI.
        const isHtml = /^\s*(<!doctype|<html)/i.test(txt);
        if (isHtml && r.status === 404) {
          json = { error: `Endpoint não encontrado (${url}). Refresque a página com Ctrl+F5 — pode ser cache antigo do JavaScript.` };
        } else if (isHtml) {
          json = { error: `Servidor retornou HTML (status ${r.status}) em vez de JSON. Verifique logs do Apache.` };
        } else {
          json = { error: txt.length > 200 ? txt.slice(0, 200) + '…' : (txt || 'Resposta vazia') };
        }
      }
      if (!r.ok) throw new Error(json.error || ('HTTP ' + r.status));
      return json;
    },

    setLoading(b) {
      this.state.loading = b;
      const list = document.getElementById('intList');
      if (b && list && !list.querySelector('.int-loading-bar')) {
        list.insertAdjacentHTML('afterbegin', '<div class="int-loading-bar" style="height:3px;background:linear-gradient(90deg,#244E7A,#6898C0,#244E7A);background-size:200% 100%;animation:loadingPulse 1.2s infinite;border-radius:3px;margin-bottom:10px;"></div>');
      } else if (!b) {
        list?.querySelector('.int-loading-bar')?.remove();
      }
    },

    updateKpi(n) {
      const el = document.getElementById('kpiNaoLidas');
      if (el) {
        const num = parseInt(n, 10) || 0;
        el.textContent = `${num} ${num === 1 ? 'não lida' : 'não lidas'}`;
        // Esconde o badge se 0 — visual mais limpo
        el.style.display = num > 0 ? '' : 'none';
      }
    },

    async refreshKpi() {
      try {
        const data = await this.api('GET', '/list.php?limit=1');
        this.updateKpi(data.nao_lidas || 0);
      } catch (_) { /* silently */ }
    },

    fmtData(s) {
      if (!s || s.length < 10) return s || '';
      return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4);
    },

    /**
     * Limpa texto vindo do DJEN. Equivalente JS do DjenProvider::cleanText.
     * Algumas publicações antigas em cache ainda têm HTML inline — esta função
     * normaliza no momento do render como defesa em profundidade.
     */
    cleanText(s) {
      if (!s) return '';
      let t = String(s);
      // 1) <script>/<style> INTEIROS (multiline) — vinha vazando CSS
      t = t.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, ' ');
      t = t.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi,   ' ');
      // 2) Doctype + tags de estrutura
      t = t.replace(/<!DOCTYPE[^>]*>/gi, ' ');
      t = t.replace(/<(html|head|body|meta|title|link|style|script)\b[^>]*>/gi, ' ');
      t = t.replace(/<\/(html|head|body|meta|title|link|style|script)>/gi, ' ');
      // 3) Blocos CSS soltos: body{...}, #divHeader{...}, .x{...}, @media{...}
      t = t.replace(/[@#.]?[a-zA-Z_][\w-]*\s*\{[^{}]*\}/g, ' ');
      t = t.replace(/\b[a-zA-Z-]+\s*:\s*[^;{}]+;/g, ' ');
      // 4) Quebras estruturais antes de strip
      t = t.replace(/<\s*br\s*\/?>/gi, '\n');
      t = t.replace(/<\/(p|div|tr|li)>/gi, '\n');
      t = t.replace(/<\/td>/gi, ' ');
      // 5) Strip tags restantes
      t = t.replace(/<[^>]+>/g, '');
      // 6) Decode entidades via textarea
      const dec = document.createElement('textarea');
      dec.innerHTML = t;
      t = dec.value;
      // 7) Normaliza whitespace (inclui NBSP  )
      t = t.replace(/[ \t]/g, ' ');
      t = t.replace(/ {2,}/g, ' ');
      t = t.replace(/\n{3,}/g, '\n\n');
      return t.trim();
    },

    fmtDateTime(s) {
      if (!s) return '—';
      const d = s.length > 10 ? this.fmtData(s.slice(0, 10)) + ' ' + s.slice(11, 16) : this.fmtData(s);
      return d;
    },

    // ────────────────────────────────────────────────────────────────────────
    // MONITORES
    // ────────────────────────────────────────────────────────────────────────

    openMonitors() {
      document.getElementById('monitorsModal')?.classList.add('show');
      this.fillMyProfileForm();
      this.loadMonitors();
    },

    /** Pré-popula os inputs "Meu perfil" dentro do modal Monitoramento. */
    fillMyProfileForm() {
      const $ = (id) => document.getElementById(id);
      const p = this.userProfile || {};
      if ($('myProfUf'))   $('myProfUf').value   = p.oab_uf || '';
      if ($('myProfOab'))  $('myProfOab').value  = p.oab    || '';
      if ($('myProfNome')) $('myProfNome').value = p.nome_advogado || '';
      const st = $('myProfStatus');
      if (st) {
        const hasAny = !!(p.oab || p.nome_advogado);
        st.textContent = hasAny ? 'perfil salvo ✓' : 'ainda não configurado';
        st.style.color = hasAny ? '#15803D' : '#7A8898';
      }
    },

    closeMonitors() {
      document.getElementById('monitorsModal')?.classList.remove('show');
    },

    async loadMonitors() {
      const list = document.getElementById('monitorsList');
      if (!list) return;
      list.innerHTML = '<div class="int-mon-empty">Carregando…</div>';
      try {
        const data = await this.api('GET', '/monitors.php');
        if (!data.ok) throw new Error(data.error || 'Erro');
        this.renderMonitors(data.monitors || []);
      } catch (err) {
        list.innerHTML = `<div class="int-mon-empty" style="color:#f87171;">Erro: ${err.message}</div>`;
      }
    },

    renderMonitors(rows) {
      const list = document.getElementById('monitorsList');
      if (!list) return;
      if (!rows.length) {
        list.innerHTML = '<div class="int-mon-empty">Nenhum monitor cadastrado. Adicione um acima.</div>';
        return;
      }
      const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

      let html = '';
      for (const m of rows) {
        const pillClass = m.status === 'ativo' ? '' : (m.status === 'pausado' ? 'paused' : 'erro');
        const ufStr = m.uf ? ` (${esc(m.uf)})` : '';
        const tribStr = m.tribunal ? ` · ${esc(m.tribunal)}` : '';
        const prox = m.proxima_consulta_em ? `próxima: ${this.fmtDateTime(m.proxima_consulta_em)}` : 'aguardando 1ª execução';
        const ult  = m.ultima_consulta_em  ? `última: ${this.fmtDateTime(m.ultima_consulta_em)}`  : '';
        const err  = m.ultimo_erro ? `<span style="color:#f87171;"> · ${esc(m.ultimo_erro.substring(0, 60))}</span>` : '';
        const toggleAct = m.status === 'ativo' ? 'pause' : 'resume';
        const toggleLabel = m.status === 'ativo' ? 'Pausar' : 'Ativar';

        // Rótulo combinado OAB + Nome (precisão AND no DJEN)
        const nomeC = (m.nome_complementar || '').trim();
        const tipo  = (m.tipo_monitoramento || '').toUpperCase();
        let label;
        if (tipo === 'OAB' && nomeC) {
          label = `<strong>OAB + NOME:</strong> ${esc(m.valor_monitorado)}${ufStr} <span style="color:#a78bfa;">+</span> "${esc(nomeC)}"`;
        } else {
          label = `<strong>${esc(tipo)}: ${esc(m.valor_monitorado)}</strong>${ufStr}`;
        }

        html += `
          <div class="int-mon-row">
            <span class="pill ${pillClass}">${esc(m.status)}</span>
            <div class="grow">
              <div>${label}${tribStr}</div>
              <div class="meta">a cada ${esc(m.intervalo_minutos)} min · ${prox} ${ult ? '· ' + ult : ''}${err}</div>
            </div>
            <button class="int-btn" data-mon-action="${toggleAct}" data-mon-id="${m.id}" style="padding:5px 10px;font-size:.75rem;">${toggleLabel}</button>
            <button class="int-btn" data-mon-action="delete" data-mon-id="${m.id}" style="padding:5px 10px;font-size:.75rem;background:rgba(176,96,112,.15);border-color:rgba(176,96,112,.3);color:#f87171;">Excluir</button>
          </div>`;
      }
      list.innerHTML = html;
    },

    async createMonitor() {
      const $ = (id) => document.getElementById(id);
      const proc   = ($('monNewProc')?.value   || '').trim();
      const trib   = ($('monNewTrib')?.value   || '').trim().toUpperCase();
      const interv = parseInt($('monNewIntervalo')?.value || '120', 10);

      if (!proc) {
        this.notify('Preencha o número do processo (CNJ).', 'warn');
        return;
      }

      try {
        const data = await this.api('POST', '/monitors.php', {
          tipo_monitoramento: 'processo',
          valor_monitorado: proc,
          tribunal: trib,
          intervalo_minutos: interv,
        });
        if (!data.ok) throw new Error(data.error || 'Erro');
        this.notify(`Processo monitorado: ${proc}`, 'success');
        $('monNewProc').value = '';   // limpa só o processo; mantém tribunal/intervalo pra próximo cadastro
        this.loadMonitors();
      } catch (err) {
        this.notify('Erro: ' + err.message, 'error');
      }
    },

    async handleMonitorAction(action, id, btn) {
      if (!id) return;
      try {
        if (action === 'delete') {
          if (!confirm('Excluir este monitor? Notificações futuras param.')) return;
          const data = await this.api('DELETE', '/monitors.php?id=' + id);
          if (!data.ok) throw new Error(data.error || 'Erro');
          this.notify('Monitor removido.', 'success');
          this.loadMonitors();
          return;
        }
        if (action === 'pause' || action === 'resume') {
          const newStatus = action === 'pause' ? 'pausado' : 'ativo';
          const data = await this.api('PATCH', '/monitors.php?id=' + id, { status: newStatus });
          if (!data.ok) throw new Error(data.error || 'Erro');
          this.notify(newStatus === 'ativo' ? 'Monitor ativado.' : 'Monitor pausado.', 'success');
          this.loadMonitors();
        }
      } catch (err) {
        this.notify('Erro: ' + err.message, 'error');
      }
    },

    // ────────────────────────────────────────────────────────────────────────
    // ABAS — switch entre Diário (DJEN), AASP, Busca ativa, Termos
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Mostra/esconde painéis conforme aba ativa.
     * O #intList é COMPARTILHADO entre abas — só o filtro de source muda.
     * Quando aba=aasp, o filtro vira 'aasp' e o feed mostra só intimações AASP.
     *
     * IMPORTANTE: limpa state.items antes de recarregar pra evitar "vazamento"
     * dos items da aba anterior enquanto a request nova está em andamento.
     */
    switchTab(tab) {
      const $ = (id) => document.getElementById(id);
      const aaspPanel = $('intAaspPanel');
      const banner    = $('bannerBuscaManual');

      // Limpa estado visual imediatamente — evita ver lista antiga enquanto carrega
      this.state.items = [];
      this.render();

      if (tab === 'aasp') {
        if (aaspPanel) aaspPanel.style.display = '';
        if (banner)    banner.classList.remove('show');
        this.state.sourceFilter = 'aasp';
        this.loadAaspIntegrations();
        this.loadPersistidos();
      } else {
        if (aaspPanel) aaspPanel.style.display = 'none';
        // Esconde resumo da última busca AASP ao sair da aba
        const lastEl = document.getElementById('aaspLastSearch');
        if (lastEl) lastEl.style.display = 'none';
        // Na aba DJEN, filtra explicitamente source_id='djen' (deixa AASP separado).
        // Antes era null (cross-provider) mas isso fazia AASP aparecer na aba DJEN.
        this.state.sourceFilter = (tab === 'djen') ? 'djen' : null;
        this.loadPersistidos();
      }
      // Atualiza microcopy do botão "Buscar publicações" e KPI da toolbar
      this.updateSearchHint();
      this.updateToolbarCacheInfo();
    },

    /** Atualiza o KPI "Cache atual" no toolbar baseado na aba ativa. */
    updateToolbarCacheInfo() {
      // Usa id específico — antes querySelector pegava o aaspSummary do
      // painel AASP por engano (1ª ocorrência da classe no DOM).
      const el = document.getElementById('intCacheToolbar');
      if (!el) return;
      const src   = this.state.sourceFilter;
      // Conta items DEDUPLICADOS pra bater com o que aparece nos cards
      // (sem isso, KPI mostrava 25 raw enquanto o feed mostrava 6 dedupados).
      const items = this._dedupItems(this.state.items || []);
      const cache = items.filter(it => it._from_cache).length;
      const events = items.length - cache;
      const total = items.length;

      // Source label
      let srcLabel = '';
      if (src === 'aasp')      srcLabel = 'AASP';
      else if (src === 'djen') srcLabel = 'DJEN';

      // Date label — reflete o ESTADO REAL do filtro, não assume "hoje"
      const f = this.collectFilters();
      const hoje = new Date().toISOString().slice(0, 10);
      let dateLabel = '';
      if (f.data_inicio === hoje && f.data_fim === hoje) {
        dateLabel = 'hoje';
      } else if (f.data_inicio && f.data_fim && f.data_inicio === f.data_fim) {
        dateLabel = this.fmtData(f.data_inicio);
      } else if (f.data_inicio && f.data_fim) {
        dateLabel = `${this.fmtData(f.data_inicio)} a ${this.fmtData(f.data_fim)}`;
      } else if (f.data_inicio) {
        dateLabel = `desde ${this.fmtData(f.data_inicio)}`;
      } else {
        dateLabel = 'sem filtro de data';
      }

      const label = srcLabel ? `${srcLabel} — ${dateLabel}` : dateLabel.charAt(0).toUpperCase() + dateLabel.slice(1);
      const detail = (cache > 0 && events > 0)
        ? ` <span style="opacity:.7;">(${cache} no cache · ${events} salvas)</span>`
        : '';
      el.innerHTML = `${label}: <strong>${total}</strong> ${this._pl(total, 'publicação', 'publicações')}${detail}`;
    },

    /**
     * Atualiza a microcopy abaixo do botão "Buscar publicações" pra deixar claro
     * o que vai acontecer baseado em: aba ativa + período preenchido ou não.
     */
    updateSearchHint() {
      const hint = document.getElementById('searchHint');
      if (!hint) return;
      const f = this.collectFilters();
      const hoje = new Date().toISOString().slice(0, 10);
      const temPeriodo = !!(f.data_inicio || f.data_fim);
      const periodoAntigo = (f.data_inicio && f.data_inicio < hoje) || (f.data_fim && f.data_fim < hoje);

      hint.classList.remove('aasp-mode', 'realtime-mode');

      if (this.state.sourceFilter === 'aasp') {
        hint.classList.add('aasp-mode');
        if (temPeriodo) {
          const di = f.data_inicio || hoje;
          const df = f.data_fim    || hoje;
          hint.innerHTML = `Vai buscar na <strong>AASP</strong> em tempo real, do dia <strong>${this.fmtData(di)}</strong> ao dia <strong>${this.fmtData(df)}</strong>. <strong>Não persiste</strong> — só salva se você interagir.`;
        } else {
          hint.innerHTML = `Vai mostrar o <strong>cache AASP</strong> atual + intimações já salvas (com seu status lida/favorita). Pra busca por período, defina datas acima.`;
        }
      } else {
        if (periodoAntigo) {
          hint.classList.add('realtime-mode');
          hint.innerHTML = `Vai buscar no <strong>DJEN em tempo real</strong> (datas passadas). <strong>Não persiste</strong> — só salva se você interagir.`;
        } else if (temPeriodo) {
          hint.innerHTML = `Vai filtrar a lista local (cache + persistidos) pelo período.`;
        } else {
          hint.innerHTML = `Vai mostrar a lista local (cache do dia + intimações já salvas) com os critérios definidos.`;
        }
      }
    },

    // ────────────────────────────────────────────────────────────────────────
    // AASP — INTEGRAÇÕES
    // ────────────────────────────────────────────────────────────────────────

    /** Cache do estado da aba AASP. */
    aaspState: {
      integrations: [],
      summary: { total: 0, ativas: 0, pending: 0, erros: 0, crypto_ok: false },
      can_manage: false,
      modalMode: null,   // 'create' | 'rotate' | 'edit'
      modalId: null,
      // Assinatura do último teste OK — JSON.stringify([chave, tipo, codigos.sort()]).
      // Se ainda igual no momento do save, pula o estado "pending" e salva direto como active.
      lastTestSignature: null,
    },

    async loadAaspIntegrations() {
      const listEl = document.getElementById('aaspIntegrationsList');
      const sumEl  = document.getElementById('aaspSummary');
      try {
        const data = await this.api('GET', '/sistema_vendas/public/api/aasp/integrations.php');
        if (!data.ok) throw new Error(data.error || 'Erro');
        this.aaspState.integrations = data.integrations || [];
        this.aaspState.summary      = data.summary      || this.aaspState.summary;
        this.aaspState.can_manage   = !!data.can_manage;
        this.renderAaspIntegrations();
        this.updateAaspKpi();
      } catch (err) {
        if (sumEl) sumEl.textContent = '';
        if (listEl) {
          listEl.innerHTML = `<div class="int-empty" style="padding:30px 20px;">
            <h3 style="color:#F87171;">Erro ao carregar integrações</h3>
            <p>${this._esc(err.message)}</p>
          </div>`;
        }
      }
    },

    updateAaspKpi() {
      const kpi = document.getElementById('kpiAaspIntegracoes');
      const s = this.aaspState.summary || {};
      if (!kpi) return;
      const ativas = s.ativas | 0;
      kpi.textContent = ativas;
      kpi.style.display = ativas > 0 ? '' : 'none';
    },

    renderAaspIntegrations() {
      const listEl = document.getElementById('aaspIntegrationsList');
      const sumEl  = document.getElementById('aaspSummary');
      const items  = this.aaspState.integrations || [];
      const sum    = this.aaspState.summary || {};

      // Sumário
      if (sumEl) {
        const parts = [];
        parts.push(`<strong>${sum.total | 0}</strong> ${this._pl(sum.total | 0, 'integração', 'integrações')}`);
        if (sum.ativas | 0) parts.push(`<span style="color:#34D399">${sum.ativas} ativa(s)</span>`);
        if (sum.pending | 0) parts.push(`<span style="color:#FBBF24">${sum.pending} pendente(s)</span>`);
        if (sum.erros | 0) parts.push(`<span style="color:#F87171">${sum.erros} com erro</span>`);
        sumEl.innerHTML = parts.join(' · ');
        if (!sum.crypto_ok) {
          sumEl.innerHTML += ` · <span style="color:#F87171" title="APP_ENCRYPTION_KEY ausente no servidor">⚠ chave de cifra ausente</span>`;
        }
      }

      // Lista vazia
      if (!items.length) {
        listEl.innerHTML = `
          <div class="int-empty" style="padding:36px 20px;">
            <span class="int-empty-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </span>
            <h3>Nenhuma integração AASP configurada</h3>
            <p>Conecte sua chave AASP (obtida em <a href="https://intimacaoapi-cadastro.aasp.org.br/" target="_blank" rel="noopener" style="color:#93c5fd;text-decoration:underline;">intimacaoapi-cadastro.aasp.org.br</a>) pra puxar suas intimações automaticamente.</p>
          </div>`;
        return;
      }

      // Render cards
      const html = items.map((i) => {
        const status   = i.status || 'pending';
        const ultimaTs = i.ultima_sync_em ? this.fmtDateTime(i.ultima_sync_em) : '—';
        const codigos  = Array.isArray(i.codigos_associados) ? i.codigos_associados : [];
        const tipoChip = i.tipo === 'empresa' ? 'Empresa' : 'Associado';
        const codInfo  = i.tipo === 'empresa' && codigos.length
          ? ` · ${codigos.length} cód.` : '';
        const ultErro  = (status === 'error' && i.ultima_sync_erro)
          ? `<div style="margin-top:4px;font-size:.72rem;color:#F87171;">⚠ ${this._esc(i.ultima_sync_erro)}</div>`
          : '';
        const canManage = this.aaspState.can_manage;
        const actions = canManage ? `
          <button class="int-icon-btn" data-aasp-action="sync" data-aasp-id="${i.id}" title="Sincronizar agora — puxa intimações da AASP">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          </button>
          <button class="int-icon-btn" data-aasp-action="rotate" data-aasp-id="${i.id}" title="Trocar a chave AASP (rotação de credencial)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="15" r="4"/><line x1="9.85" y1="12.15" x2="20" y2="2"/><line x1="17" y1="5" x2="20" y2="8"/><line x1="14" y1="8" x2="17" y2="11"/></svg>
          </button>
          <button class="int-icon-btn" data-aasp-action="delete" data-aasp-id="${i.id}" title="Excluir integração — apaga credencial cifrada do banco">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
          </button>` : '';
        return `
          <div class="int-aasp-int-card status-${this._esc(status)}">
            <div class="int-aasp-int-info">
              <h4>${this._esc(i.nome)} <span class="int-aasp-mask">${this._esc(i.chave_masked)}</span></h4>
              <div class="meta">
                <span class="chip ${this._esc(status)}">${this._esc(status)}</span>
                <span class="chip">${tipoChip}${codInfo}</span>
                · Última sync: ${ultimaTs}
                · Hoje: <strong>${i.total_intimacoes_hoje | 0}</strong>
                · Total: ${i.total_intimacoes_lifetime | 0}
              </div>
              ${ultErro}
            </div>
            <div class="int-aasp-int-actions">${actions}</div>
          </div>`;
      }).join('');
      listEl.innerHTML = html;
    },

    async handleAaspIntegrationAction(action, id, btn) {
      if (!id) return;
      if (action === 'delete') {
        if (!confirm('Excluir esta integração AASP? A chave cifrada será removida. Esta ação não pode ser desfeita.')) return;
        try {
          const data = await this.api('DELETE', '/sistema_vendas/public/api/aasp/integrations.php', { id });
          if (!data.ok) throw new Error(data.error || 'Erro');
          this.notify('Integração AASP excluída.', 'success');
          await this.loadAaspIntegrations();
        } catch (err) {
          this.notify('Erro: ' + err.message, 'error');
        }
        return;
      }
      if (action === 'rotate') {
        const integ = this.aaspState.integrations.find((x) => x.id === id);
        if (!integ) return;
        this.openAaspModal('rotate', integ);
        return;
      }
      if (action === 'sync') {
        try {
          btn.disabled = true;
          this.notify('Sincronizando AASP…', 'info');
          const data = await this.api('POST', '/sistema_vendas/public/api/aasp/sync.php', {
            integration_id: id,
            // diferencial=false por padrão (manual): traz tudo do dia.
            // Cron usa diferencial=true (incremental) — Fase F.
            diferencial: false,
          });
          if (!data.ok) throw new Error(data.error || 'Erro');
          this.notify(
            `AASP: ${data.total} ${this._pl(data.total, 'intimação', 'intimações')} no dia, ${data.cached_novos} ${this._pl(data.cached_novos, 'nova', 'novas')} no cache · ${data.duracao_ms}ms`,
            'success'
          );
          await this.loadAaspIntegrations();
          // Atualiza o feed (caso aba AASP esteja ativa) — puxa as novas do cache
          await this.loadPersistidos();
        } catch (err) {
          this.notify('Erro ao sincronizar AASP: ' + err.message, 'error');
        } finally {
          btn.disabled = false;
        }
      }
    },

    // ── Modal ────────────────────────────────────────────────────────────

    openAaspModal(mode, integ) {
      const $ = (id) => document.getElementById(id);
      this.aaspState.modalMode = mode;
      this.aaspState.modalId   = integ ? integ.id : null;
      // Reset assinatura — modal recém-aberto não tem teste OK ainda
      this.aaspState.lastTestSignature = null;

      // Reset form
      $('aaspNome').value = integ ? integ.nome : '';
      $('aaspTipo').value = integ ? (integ.tipo || 'associado') : 'associado';
      $('aaspIntervalo').value = integ ? (integ.intervalo_minutos || 120) : 120;
      $('aaspChave').value = '';
      $('aaspCodigos').value = integ && Array.isArray(integ.codigos_associados)
        ? integ.codigos_associados.join('\n') : '';
      $('aaspEmpresaRow').style.display = ($('aaspTipo').value === 'empresa') ? '' : 'none';
      $('aaspTestResult').innerHTML = '';
      $('aaspTestResult').className = '';

      // Modo rotate: bloqueia campos exceto chave
      const lockOnRotate = mode === 'rotate';
      $('aaspNome').disabled       = lockOnRotate;
      $('aaspTipo').disabled       = lockOnRotate;
      $('aaspIntervalo').disabled  = lockOnRotate;
      $('aaspCodigos').disabled    = lockOnRotate;
      $('aaspModalTitle').textContent = lockOnRotate
        ? `Trocar chave: ${integ.nome}`
        : 'Conectar chave AASP';

      $('aaspModal')?.classList.add('show');
      setTimeout(() => $('aaspChave')?.focus(), 60);
    },

    closeAaspModal() {
      document.getElementById('aaspModal')?.classList.remove('show');
      // Limpa o input chave por segurança (mesmo que estado de sessão expire)
      const c = document.getElementById('aaspChave'); if (c) c.value = '';
      this.aaspState.lastTestSignature = null;
    },

    /** Calcula a assinatura atual dos campos críticos do form AASP. */
    _aaspFormSignature() {
      const $ = (id) => document.getElementById(id);
      const chave  = ($('aaspChave').value || '').trim();
      const tipo   = $('aaspTipo').value;
      const codTxt = ($('aaspCodigos').value || '').trim();
      const codigos = codTxt
        ? codTxt.split(/[\s,]+/).map((x) => parseInt(x, 10)).filter((x) => x > 0).sort((a,b) => a-b)
        : [];
      return JSON.stringify([chave, tipo, codigos]);
    },

    /** Lê e valida campos. Devolve {ok, payload, error?}. */
    _collectAaspForm(includeChave = true) {
      const $ = (id) => document.getElementById(id);
      const nome  = ($('aaspNome').value || '').trim();
      const tipo  = $('aaspTipo').value;
      const interv = parseInt($('aaspIntervalo').value, 10) || 120;
      const chave = ($('aaspChave').value || '').trim();
      const codTxt = ($('aaspCodigos').value || '').trim();
      const codigos = codTxt
        ? codTxt.split(/[\s,]+/).map((x) => parseInt(x, 10)).filter((x) => x > 0)
        : [];

      if (!nome) return { ok: false, error: 'Informe o nome da integração' };
      if (includeChave && !chave) return { ok: false, error: 'Informe a chave AASP' };
      if (tipo === 'empresa' && !codigos.length) {
        return { ok: false, error: 'Modo empresa exige pelo menos 1 código de associado (a API AASP rejeita sem)' };
      }
      return {
        ok: true,
        payload: { nome, tipo, intervalo_minutos: interv, codigos_associados: codigos, chave },
      };
    },

    async testAaspChave() {
      const r = this._collectAaspForm(true);
      const box = document.getElementById('aaspTestResult');
      if (!r.ok) {
        box.className = 'error'; box.innerHTML = this._esc(r.error);
        return;
      }
      // Data opcional — se vazio, backend usa hoje
      const dataTeste = (document.getElementById('aaspTestData')?.value || '').trim();
      box.className = 'info';
      box.innerHTML = dataTeste ? `Testando data ${dataTeste}…` : 'Testando (hoje)…';
      const btn = document.getElementById('aaspTest');
      btn.disabled = true;
      try {
        const data = await this.api('POST', '/sistema_vendas/public/api/aasp/test.php', {
          chave: r.payload.chave,
          tipo: r.payload.tipo,
          codigos_associados: r.payload.codigos_associados,
          data: dataTeste || undefined,
        });
        if (!data.ok) {
          box.className = 'error';
          box.innerHTML = `<strong>Falhou:</strong> ${this._esc(data.error || 'erro desconhecido')}`;
          return;
        }
        box.className = 'ok';
        // Marca assinatura — se o user salvar sem mudar nada, status vira active direto
        this.aaspState.lastTestSignature = this._aaspFormSignature();
        this._updateAaspSaveButton();
        const sampleNorm = data.normalized_sample
          ? `<pre>${this._esc(JSON.stringify(data.normalized_sample, null, 2).slice(0, 1200))}${JSON.stringify(data.normalized_sample).length > 1200 ? '\n…(truncado)' : ''}</pre>`
          : '<em>(sem itens retornados nessa data)</em>';
        // Sample raw também — útil pra eu (dev) ajustar o normalize() se algum campo da AASP estiver com nome diferente
        const sampleRaw = data.sample_raw
          ? `<details style="margin-top:8px;"><summary style="cursor:pointer;font-size:.72rem;color:#93c5fd;">Raw da AASP (campos brutos)</summary>
             <pre>${this._esc(JSON.stringify(data.sample_raw, null, 2).slice(0, 1500))}</pre></details>`
          : '';
        box.innerHTML = `<strong>OK</strong> · ${data.total} ${this._pl(data.total, 'intimação', 'intimações')} · ${data.duracao_ms}ms · ${data.request_count || 1} ${this._pl(data.request_count || 1, 'chamada', 'chamadas')} · data: ${data.data_consultada || 'hoje'}
          <div style="margin-top:6px;font-size:.74rem;color:#7A8898;">Sample normalizado:</div>
          ${sampleNorm}${sampleRaw}
          <div style="margin-top:8px;font-size:.74rem;color:#34D399;">
            <strong>✓ Conexão validada.</strong> Se salvar sem mudar nada, integração será gravada como <strong>ativa</strong> direto (sem passar por pending).
          </div>`;
      } catch (err) {
        box.className = 'error';
        box.innerHTML = `<strong>Falhou:</strong> ${this._esc(err.message)}`;
      } finally {
        btn.disabled = false;
      }
    },

    async saveAaspIntegration() {
      const mode = this.aaspState.modalMode;
      const id   = this.aaspState.modalId;
      const btn  = document.getElementById('aaspSave');
      const box  = document.getElementById('aaspTestResult');

      // Foi testado OK antes? (assinatura ainda bate com o estado atual do form)
      const validated = (this._aaspFormSignature() === this.aaspState.lastTestSignature);

      if (mode === 'rotate') {
        // Só chave nova é enviada
        const chave = (document.getElementById('aaspChave').value || '').trim();
        if (!chave) {
          box.className = 'error'; box.innerHTML = 'Informe a nova chave';
          return;
        }
        btn.disabled = true;
        try {
          const data = await this.api('PATCH', '/sistema_vendas/public/api/aasp/integrations.php', { id, chave, validated });
          if (!data.ok) throw new Error(data.error || 'Erro');
          this.notify(
            validated
              ? 'Chave AASP rotacionada e marcada como ativa (testou OK antes).'
              : 'Chave AASP rotacionada. Status volta pra pending até próximo sync.',
            'success'
          );
          this.closeAaspModal();
          await this.loadAaspIntegrations();
        } catch (err) {
          box.className = 'error';
          box.innerHTML = `<strong>Falhou:</strong> ${this._esc(err.message)}`;
        } finally {
          btn.disabled = false;
        }
        return;
      }

      // Modo create
      const r = this._collectAaspForm(true);
      if (!r.ok) {
        box.className = 'error'; box.innerHTML = this._esc(r.error);
        return;
      }
      btn.disabled = true;
      try {
        const data = await this.api('POST', '/sistema_vendas/public/api/aasp/integrations.php', { ...r.payload, validated });
        if (!data.ok) throw new Error(data.error || 'Erro');
        this.notify(
          validated
            ? `Integração AASP "${r.payload.nome}" salva como ativa (testou OK antes).`
            : `Integração AASP "${r.payload.nome}" salva como pending — clique em Sincronizar pra ativar.`,
          'success'
        );
        this.closeAaspModal();
        await this.loadAaspIntegrations();
      } catch (err) {
        box.className = 'error';
        box.innerHTML = `<strong>Falhou:</strong> ${this._esc(err.message)}`;
      } finally {
        btn.disabled = false;
      }
    },

    /** Atualiza visual do botão Salvar pra indicar se vai gravar como active ou pending. */
    _updateAaspSaveButton() {
      const btn = document.getElementById('aaspSave');
      if (!btn) return;
      const validated = (this._aaspFormSignature() === this.aaspState.lastTestSignature);
      btn.textContent = validated ? 'Salvar como ativa ✓' : 'Salvar integração';
    },

    /** Escape HTML mínimo (defesa contra XSS em mensagens dinâmicas). */
    _esc(s) {
      if (s == null) return '';
      return String(s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    },

    /** Pluralização PT-BR: 1 → singular; 0/2+ → plural. */
    _pl(n, sing, plur) {
      return (Number(n) === 1) ? sing : (plur || sing + 's');
    },

    /**
     * Adiciona quebras de linha semânticas em texto jurídico inline.
     * AASP/DJEN devolvem tudo numa linha — esta função detecta marcadores
     * formais e insere \n\n antes deles pra ficar legível.
     *
     * Cuidado: usa uppercase + boundary pra não quebrar dentro de frases.
     * Ex: "decisão" minúscula não quebra, "DECISÃO" maiúscula sim.
     */
    _formatLegal(s) {
      if (!s) return '';
      let t = String(s);
      // 1) Quebra ANTES de marcadores institucionais/processuais (uppercase)
      const breakBefore = [
        /\bPODER JUDICIÁRIO\b/g,
        /\bRECLAMANTE\s*:/g,
        /\bRECLAMADO\s*:/g,
        /\bEXEQUENTE\s*:/g,
        /\bEXECUTAD[OA]\s*:/g,
        /\bAUTOR\(?A?\)?\s*:/g,
        /\bRÉU\(?S?\)?\s*:/g,
        /\bINTIMAÇÃO\b(?!\s*(?:de|do|da|por|com))/g,  // INTIMAÇÃO keyword (não "intimação de ID")
        /\bDECISÃO\b(?!\s+ID)/g,                       // DECISÃO keyword (não "Decisão ID xxx")
        /\bDESPACHO\b(?!\s+ID)/g,
        /\bSENTENÇA\b(?!\s+ID)/g,
        /\bACÓRDÃO\b(?!\s+ID)/g,
        /\bCONCLUSÃO\b/g,
        /\bVistos\b\s*(?:etc)?\./g,                    // "Vistos." / "Vistos etc."
        /\bFica V\. Sa\./g,
        /\bFica\s+intimad[oa]\b/g,
        /\bIntimado\(s\)\s*\/?\s*Citado\(s\)/g,
        /\bPara\s+maiores\s+informações/g,
      ];
      for (const re of breakBefore) {
        t = t.replace(re, '\n\n$&');
      }

      // 2) Quebra ANTES de itens numerados "1- ", "2- ", "3- " (passos de decisão)
      t = t.replace(/\s(\d+)-\s/g, '\n$1- ');

      // 3) Quebra ANTES de "ADV: " / "Adv - " / "ADV - " (lista de advogados final)
      t = t.replace(/\s+(-\s*ADV\s*:)/gi, '\n$1');
      t = t.replace(/\s+(Adv\s+-)/g, '\n$1');

      // 4) Quebra ANTES de "Intimado(s) / Citado(s)" se grudou na sentença anterior
      // (já coberto acima, mas reforça pra casos tipo "...Substituta Intimado(s)")
      t = t.replace(/([a-záéíóúçãõ])(Intimado\(s\))/g, '$1\n\n$2');

      // 5) Quebra ANTES de "- NOME LTDA" / "- NOME S.A." em listas de intimados
      // (cada parte em sua linha quando listadas após Intimado(s) / Citado(s))
      t = t.replace(/\s+-\s+([A-Z][A-Z\.\s\&]{3,}(LTDA|S\.A\.|EIRELI|ME|EPP))/g, '\n - $1');

      // 6) Quebra ANTES de "Em DD/MM/YYYY" (timestamps)
      t = t.replace(/\s+(Em\s+\d{2}\/\d{2}\/\d{4})/g, '\n$1');

      // 7) Normaliza: max 2 quebras seguidas, sem quebra no início
      t = t.replace(/\n{3,}/g, '\n\n').replace(/^\n+/, '');
      return t;
    },
  };

  // Animação CSS via injeção (uma vez)
  if (!document.getElementById('int-styles-runtime')) {
    const st = document.createElement('style');
    st.id = 'int-styles-runtime';
    st.textContent = '@keyframes loadingPulse { 0% { background-position: 0% 0; } 100% { background-position: -200% 0; } }';
    document.head.appendChild(st);
  }

  window.YurisIntimacoesApp = App;
})();
