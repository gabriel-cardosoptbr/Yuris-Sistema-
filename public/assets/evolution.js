/**
 * Aba Evolution (edição CRM, 15/10/2026): o servidor de WhatsApp da Inovaize.
 *
 * Fala com /api/evolution.php, que lê o Evolution na hora (só leitura), grava a
 * foto do dia e devolve instâncias, resumo, por projeto e histórico prontos
 * (App\Projetos\Evolution). Aqui só se desenha, e se grava o vínculo
 * instância → projeto e o custo mensal. Nada aqui conecta, desconecta ou apaga
 * instância. Gráficos com Chart.js; estilo de projetos.css.
 */
(function () {
  'use strict';
  const API = '/api/evolution.php';
  const $ = id => document.getElementById(id);
  if (!$('evoPagina')) return;

  const CSRF = document.querySelector('[name=csrf_token]')?.value || '';
  const brl = v => v === null || v === undefined ? null : 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const num = v => Number(v || 0).toLocaleString('pt-BR');
  const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const falta = (t = 'a preencher') => `<span class="proj-falta">${t}</span>`;
  const toast = (m, t) => { if (window.Yuris && Yuris.toast) Yuris.toast(m, t || 'info'); };
  const dataHora = s => s ? `${s.slice(8, 10)}/${s.slice(5, 7)}/${s.slice(0, 4)} ${s.slice(11, 16)}` : '–';
  const dataCurta = s => s ? `${s.slice(8, 10)}/${s.slice(5, 7)}` : '';
  const SELO = { open: 'st-producao', connecting: 'st-pausado', close: 'st-encerrado' };
  const CH = {};
  let D = null, filtro = 'todas', busca = '';

  function telefone(n) {
    if (!n) return '–';
    const m = n.match(/^55(\d{2})(\d{4,5})(\d{4})$/);
    return m ? `+55 (${m[1]}) ${m[2]}-${m[3]}` : '+' + n;
  }

  /* ── Comunicação ── */
  async function carregar() {
    $('evoAtualizar').disabled = true;
    try {
      const r = await fetch(API, { credentials: 'same-origin' });
      const j = await r.json();
      if (!r.ok) throw new Error(j.error || 'erro');
      D = j; desenhar();
    } catch (e) {
      $('evoConteudo').innerHTML = `<div class="dre-panel proj-carregando">Não foi possível carregar: ${esc(e.message)}. Recarregue a página.</div>`;
    } finally { $('evoAtualizar').disabled = false; }
  }

  async function postar(corpo) {
    const r = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(corpo) });
    let j = {};
    try { j = await r.json(); } catch (e) { /* sem JSON */ }
    if (!r.ok || !j.success) throw new Error(j.error || 'Não foi possível salvar.');
    return j;
  }

  /* ── Gráficos ── */
  function cores() {
    const claro = document.documentElement.getAttribute('data-theme') === 'light';
    const raiz = getComputedStyle(document.documentElement);
    const marca = raiz.getPropertyValue('--amb-c').trim() || raiz.getPropertyValue('--ff-marca').trim() || '#3b82f6';
    return { texto: claro ? '#575757' : '#a3adba', grade: claro ? 'rgba(17,29,45,0.08)' : 'rgba(255,255,255,0.08)', marca,
             open: '#10b981', connecting: '#f59e0b', close: '#94a3b8' };
  }
  function grafico(id, cfg) {
    if (CH[id]) { CH[id].destroy(); delete CH[id]; }
    const el = $(id); if (!el || !window.Chart) return;
    CH[id] = new Chart(el, cfg);
  }
  const eixos = (c, horizontal, fmt) => {
    const v = { ticks: { color: c.texto, font: { size: 10 }, callback: fmt || (x => num(x)) }, grid: { color: c.grade }, beginAtZero: true };
    const n = { ticks: { color: c.texto, font: { size: 11 }, callback: function (x) { const t = this.getLabelForValue(x); return t.length > 22 ? t.slice(0, 21) + '…' : t; } }, grid: { display: false } };
    return horizontal ? { x: v, y: n } : { x: n, y: v };
  };

  /* ── Desenho ── */
  function desenhar() {
    Object.keys(CH).forEach(k => { CH[k].destroy(); delete CH[k]; });
    const s = D.resumo || {};
    $('evoSub').innerHTML = D.erro ? 'O servidor de WhatsApp: conexões, projetos, volume e custo'
      : `${esc(D.endereco || 'Evolution')} · versão ${esc(D.versao || '?')} · lido em ${dataHora(D.lido_em)}`;

    const presas = D.instancias.filter(i => i.status === 'connecting');
    const hist = D.historico || [];
    const temSerie = hist.filter(h => h.mensagens !== null).length > 0;

    $('evoConteudo').innerHTML = `
      ${D.erro ? `<div class="proj-pend"><b>${esc(D.erro)}</b></div>` : ''}
      ${presas.length ? `<div class="proj-pend"><b>${presas.length} conexão(ões) presa(s) tentando reconectar.</b> Ocupam o servidor e não enviam nem recebem mensagens:
        ${presas.map(i => esc(i.nome)).join(', ')}. Para resolver, reconecte lendo o QR no sistema de origem ou apague a instância que não é mais usada (pelo manager do Evolution).</div>` : ''}
      <div class="kpi-grid proj-kpis">
        <div class="kpi-card lucro"><div class="kpi-label">Conexões</div><div class="kpi-value">${num(s.total)}</div><div class="kpi-desc">${num(s.ocupam)} ocupando o servidor</div></div>
        <div class="kpi-card receita"><div class="kpi-label">Conectadas</div><div class="kpi-value proj-pos">${num(s.conectadas)}</div><div class="kpi-desc">funcionando agora</div></div>
        <div class="kpi-card imposto"><div class="kpi-label">Reconectando</div><div class="kpi-value ${s.reconectando ? 'proj-falta' : ''}">${num(s.reconectando)}</div><div class="kpi-desc">presas, sem funcionar</div></div>
        <div class="kpi-card despesa"><div class="kpi-label">Desconectadas</div><div class="kpi-value">${num(s.desconectadas)}</div><div class="kpi-desc">fechadas</div></div>
        <div class="kpi-card resultado"><div class="kpi-label">Mensagens guardadas</div><div class="kpi-value">${num(s.mensagens)}</div><div class="kpi-desc">${num(s.contatos)} contatos · ${num(s.conversas)} conversas</div></div>
        <div class="kpi-card margem"><div class="kpi-label">Custo por mês</div><div class="kpi-value">${s.custo_mensal === null ? falta() : brl(s.custo_mensal)}</div>
          <div class="kpi-desc">${s.custo_por_conexao ? brl(s.custo_por_conexao) + ' por conexão no servidor' : 'informe em "Custo mensal"'}</div></div>
      </div>

      <div class="proj-grid2">
        <div class="dre-panel"><div class="panel-title">Situação das conexões</div><p class="panel-sub">Conectadas, presas reconectando e desconectadas</p>
          <div class="proj-canvas"><canvas id="evoStatus"></canvas></div></div>
        <div class="dre-panel"><div class="panel-title">Mensagens por dia</div><p class="panel-sub">${D.historico_desde ? 'Diferença entre as fotos diárias, desde ' + dataCurta(D.historico_desde) : 'Fotos diárias'}</p>
          <div class="proj-canvas">${temSerie ? '<canvas id="evoDia"></canvas>' : '<div class="proj-vazio">O Evolution só informa o total acumulado. A aba guarda uma foto por dia: o volume diário aparece a partir da segunda foto (amanhã).</div>'}</div></div>
      </div>
      <div class="proj-grid2">
        <div class="dre-panel"><div class="panel-title">Conexões por projeto</div><p class="panel-sub">Quantas instâncias cada projeto tem e quantas estão conectadas</p>
          <div class="proj-canvas proj-canvas-alto" style="height:${Math.max(180, D.por_projeto.length * 34 + 60)}px"><canvas id="evoProj"></canvas></div></div>
        <div class="dre-panel"><div class="panel-title">Volume por instância</div><p class="panel-sub">Mensagens guardadas em cada conexão (as 15 maiores)</p>
          <div class="proj-canvas proj-canvas-alto" style="height:${Math.max(180, Math.min(15, D.instancias.length) * 28 + 60)}px"><canvas id="evoVol"></canvas></div></div>
      </div>

      <div class="dre-panel">
        <div class="panel-title">Por projeto</div><p class="panel-sub">Conexões, volume e a parte do custo de cada projeto</p>
        <div class="dre-table-wrap"><table class="dre-table proj-itens"><thead><tr><th>Projeto</th><th style="text-align:right">Conexões</th><th style="text-align:right">Conectadas</th>
          <th style="text-align:right">Mensagens</th><th style="text-align:right">Custo/mês</th></tr></thead><tbody>
          ${D.por_projeto.map(p => `<tr><td>${p.projeto_id ? esc(p.nome) : '<span class="proj-falta">Sem projeto</span>'}</td><td class="num">${num(p.instancias)}</td>
            <td class="num">${num(p.conectadas)}</td><td class="num">${num(p.mensagens)}</td><td class="num">${s.custo_mensal === null ? '–' : brl(p.custo)}</td></tr>`).join('')}
        </tbody></table></div>
      </div>

      <div class="dre-panel">
        <div class="proj-barra">
          <div class="proj-filtros" id="evoFiltros">
            ${[['todas', 'Todas'], ['open', 'Conectadas'], ['connecting', 'Reconectando'], ['close', 'Desconectadas'], ['sem', 'Sem projeto']]
              .map(([k, t]) => `<button type="button" class="btn btn-sm ${filtro === k ? 'btn-primary' : 'btn-ghost'}" data-filtro="${k}">${t}</button>`).join('')}
          </div>
          <div class="proj-barra-dir"><input type="search" id="evoBusca" class="proj-busca" placeholder="Buscar instância, número, perfil" value="${esc(busca)}" autocomplete="off"></div>
        </div>
        <div class="dre-table-wrap"><table class="dre-table proj-itens" id="evoTabela"><thead><tr>
          <th>Instância</th><th>Número</th><th>Situação</th><th>Projeto</th><th style="text-align:right">Mensagens</th>
          <th style="text-align:right">Contatos</th><th style="text-align:right">Conversas</th><th style="text-align:right">Custo/mês</th><th>Criada</th><th>Última atividade</th>
        </tr></thead><tbody></tbody></table></div>
        <p class="proj-nota">Só leitura: esta tela não conecta, desconecta nem apaga instâncias. O projeto marcado como "sugerido" veio da conta dona da instância; escolher um projeto grava o vínculo.</p>
      </div>`;

    desenharTabela();
    const c = cores();
    grafico('evoStatus', { type: 'doughnut', data: { labels: ['Conectadas', 'Reconectando', 'Desconectadas'],
      datasets: [{ data: [s.conectadas, s.reconectando, s.desconectadas], backgroundColor: [c.open, c.connecting, c.close], borderWidth: 0 }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right', labels: { color: c.texto, boxWidth: 10, font: { size: 11 } } } } } });
    if (temSerie) grafico('evoDia', { type: 'line', data: { labels: hist.map(h => dataCurta(h.dia)), datasets: [
      { label: 'Mensagens no dia', data: hist.map(h => h.mensagens), borderColor: c.marca, backgroundColor: c.marca + '22', fill: true, tension: .35, pointRadius: 2, spanGaps: true },
    ] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: c.texto, boxWidth: 10, font: { size: 11 } } } }, scales: eixos(c, false) } });
    grafico('evoProj', { type: 'bar', data: { labels: D.por_projeto.map(p => p.nome), datasets: [
      { label: 'Conexões', data: D.por_projeto.map(p => p.instancias), backgroundColor: c.close, borderRadius: 4, maxBarThickness: 16 },
      { label: 'Conectadas', data: D.por_projeto.map(p => p.conectadas), backgroundColor: c.open, borderRadius: 4, maxBarThickness: 16 },
    ] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: c.texto, boxWidth: 10, font: { size: 11 } } } }, scales: eixos(c, true) } });
    const top = [...D.instancias].sort((a, b) => b.mensagens - a.mensagens).slice(0, 15);
    grafico('evoVol', { type: 'bar', data: { labels: top.map(i => i.nome), datasets: [
      { label: 'Mensagens', data: top.map(i => i.mensagens), backgroundColor: top.map(i => c[i.status]), borderRadius: 4, maxBarThickness: 16 },
    ] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: eixos(c, true) } });
  }

  function desenharTabela() {
    const tb = $('evoTabela')?.tBodies[0]; if (!tb) return;
    const lista = D.instancias.filter(i => {
      if (['open', 'connecting', 'close'].includes(filtro) && i.status !== filtro) return false;
      if (filtro === 'sem' && i.projeto_id) return false;
      if (!busca) return true;
      return [i.nome, i.numero, i.perfil, i.projeto_nome].join(' ').toLowerCase().includes(busca);
    });
    if (!lista.length) { tb.innerHTML = '<tr class="empty-row"><td colspan="10">Nenhuma instância neste filtro.</td></tr>'; return; }
    // Agrupado por projeto-mãe; encerrados só aparecem se já estiverem escolhidos.
    const visivel = (p, sel) => !p.encerrado || p.id === sel;
    const opt = (p, sel, nome) => `<option value="${p.id}"${p.id === sel ? ' selected' : ''}>${esc(nome || p.nome)}</option>`;
    const opcoesProj = sel => {
      const filhos = {};
      D.projetos.forEach(p => { if (p.parent_id) (filhos[p.parent_id] = filhos[p.parent_id] || []).push(p); });
      return '<option value="0">Sem projeto</option>' + D.projetos.filter(p => !p.parent_id && visivel(p, sel)).map(p => {
        const fs = (filhos[p.id] || []).filter(f => visivel(f, sel));
        return fs.length ? `<optgroup label="${esc(p.nome)}">${opt(p, sel, p.nome + ' (geral)')}${fs.map(f => opt(f, sel)).join('')}</optgroup>` : opt(p, sel);
      }).join('');
    };
    tb.innerHTML = lista.map(i => `<tr class="${i.status === 'close' ? 'inativo' : ''}">
      <td><b>${esc(i.nome)}</b><small>${esc(i.perfil || '')}</small></td>
      <td><small class="proj-medido">${telefone(i.numero)}</small></td>
      <td><span class="proj-selo ${SELO[i.status]}">${esc(D.status[i.status] || i.status)}</span>${i.status !== 'open' && i.desconectada_em ? `<small>desde ${dataHora(i.desconectada_em)}${i.motivo_desconexao ? ' · código ' + i.motivo_desconexao : ''}</small>` : ''}</td>
      <td><select class="evo-proj" data-instancia="${esc(i.nome)}">${opcoesProj(i.projeto_id)}</select>${i.projeto_auto ? '<small>sugerido</small>' : ''}</td>
      <td class="num">${num(i.mensagens)}${i.mensagens_desde_ultima_foto ? `<small>+${num(i.mensagens_desde_ultima_foto)} desde a última foto</small>` : ''}</td>
      <td class="num">${num(i.contatos)}</td><td class="num">${num(i.conversas)}</td>
      <td class="num">${D.resumo.custo_mensal === null ? '–' : brl(i.custo)}</td>
      <td><small class="proj-medido">${dataHora(i.criada_em).slice(0, 10)}</small></td>
      <td><small class="proj-medido">${dataHora(i.atualizada_em)}</small></td>
    </tr>`).join('');
  }

  /* ── Eventos ── */
  document.addEventListener('click', e => {
    const f = e.target.closest('#evoFiltros [data-filtro]');
    if (f) {
      filtro = f.dataset.filtro;
      document.querySelectorAll('#evoFiltros [data-filtro]').forEach(x => { x.classList.toggle('btn-primary', x === f); x.classList.toggle('btn-ghost', x !== f); });
      desenharTabela();
    }
  });
  document.addEventListener('input', e => { if (e.target.id === 'evoBusca') { busca = e.target.value.trim().toLowerCase(); desenharTabela(); } });
  document.addEventListener('change', async e => {
    const s = e.target.closest('.evo-proj'); if (!s) return;
    s.disabled = true;
    try { await postar({ acao: 'vincular', instancia: s.dataset.instancia, projeto_id: Number(s.value) || 0 }); toast('Vínculo salvo.', 'success'); await carregar(); }
    catch (err) { toast(err.message, 'danger'); s.disabled = false; }
  });
  $('evoAtualizar').addEventListener('click', carregar);
  $('evoCustoBtn').addEventListener('click', async () => {
    if (!(window.Yuris && Yuris.prompt) || !D) return;
    const atual = D.resumo.custo_mensal;
    const v = await Yuris.prompt('Quanto o Evolution custa por mês (R$)? É a parte do servidor que ele ocupa; deixe vazio para apagar.',
      { defaultValue: atual === null ? '' : Number(atual).toLocaleString('pt-BR', { minimumFractionDigits: 2 }), okLabel: 'Salvar custo' });
    if (v === null || v === undefined) return;
    try { await postar({ acao: 'custo_salvar', valor: v }); toast('Custo salvo.', 'success'); await carregar(); }
    catch (err) { toast(err.message, 'danger'); }
  });

  carregar();
})();
