/* ═══════════════════════════════════════════════════════════════════════
   ff-lead-crm.js: o lead da Prospecção (edição CRM) em mais de uma etapa e
   em mais de um setor, e o card com mais informação. Sem mexer em
   prospeccao.php:
     - card do lead: setores, etapas a mais, orçamentos, contratos e último
       contato registrado, num bloco antes do rodapé; o nome inteiro, sem corte;
     - cópia do card ("Também nesta etapa") em cada etapa a mais. A cópia não
       tem data-id nem a classe card-mini: o arrastar grava a ordem de todo
       [data-id] da coluna, e a cópia mudaria a etapa principal. O funil e o
       painel contam só a principal;
     - filtro "Setor" na barra: o lead aparece em cada setor dele;
     - seção "Etapas e setores" na ficha do lead.
   API /api/crm_lead_quadro.php (App\Prospeccao\QuadroDoLead).
   Carregado pelo sidebar.php em conta CRM; só age na tela da Prospecção.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var REAL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 });
  var Q = null, ETAPAS = [], SETORES = [], pedindo = null, observador = null, raiz = null, FILTRO = '';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function csrf() { return (window.FF_AGENDA && window.FF_AGENDA.csrf) || (window.YURIS_NOTIF && window.YURIS_NOTIF.csrf) || (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; }
  function cor(c) { return /^#[0-9a-fA-F]{3,8}$/.test(c || '') ? c : '#3B82F6'; }
  function porId(lista, id) { for (var i = 0; i < lista.length; i++) if (lista[i].id === id) return lista[i]; return null; }
  function diasDesde(iso) { if (!iso) return null; var d = new Date(String(iso).replace(' ', 'T')); return isNaN(d) ? null : Math.max(0, Math.floor((Date.now() - d.getTime()) / 86400000)); }
  function haQuanto(d) { if (d == null) return ''; if (d === 0) return 'hoje'; if (d < 30) return 'há ' + d + (d === 1 ? ' dia' : ' dias'); var m = Math.floor(d / 30); return 'há ' + m + (m === 1 ? ' mês' : ' meses'); }
  function iniciais(t) { var p = String(t || '?').trim().split(/\s+/); return ((p[0] || '?')[0] + (p.length > 1 ? p[1][0] : '')).toUpperCase(); }

  var CSS = '' +
    // Sem nome cortado com reticências: o nome inteiro, quebrando linha.
    'html[data-theme="light"] .card-mini.lc .lc-nome{white-space:normal !important;overflow:visible !important;text-overflow:clip !important;overflow-wrap:anywhere}' +
    '.fflc-mais{display:flex;flex-direction:column;gap:6px;margin-top:8px;font-family:Manrope,system-ui,sans-serif}' +
    '.fflc-tags{display:flex;flex-wrap:wrap;gap:4px}' +
    '.fflc-tag{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:99px;background:rgba(255,255,255,.08);color:#E5E7EB}' +
    '.fflc-tag i{width:7px;height:7px;border-radius:50%}' +
    '.fflc-tag.etapa{background:transparent;border:1px dashed rgba(148,163,184,.45)}' +
    '.fflc-linhas{display:flex;flex-wrap:wrap;gap:4px 10px}' +
    '.fflc-l{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:#9CA3AF}' +
    '.fflc-l svg{width:12px;height:12px;flex-shrink:0}' +
    '.fflc-l b{color:#E5E7EB;font-weight:700}' +
    '.fflc-l.ok b{color:#34D399}.fflc-l.espera b{color:#FBBF24}' +
    'html[data-theme="light"] .fflc-tag{background:#F3F4F6;color:#374151}' +
    'html[data-theme="light"] .fflc-tag.etapa{background:transparent;border-color:#D1D5DB;color:#4B5563}' +
    'html[data-theme="light"] .fflc-l{color:#6B7280}html[data-theme="light"] .fflc-l b{color:#1F2937}' +
    'html[data-theme="light"] .fflc-l.ok b{color:#047857}html[data-theme="light"] .fflc-l.espera b{color:#B45309}' +
    // Cópia do card noutra etapa
    '.fflc-copia{display:block;margin-bottom:10px;padding:10px 12px;border-radius:14px;cursor:pointer;background:rgba(15,34,56,.9);border:1px dashed rgba(148,163,184,.4);font-family:Manrope,system-ui,sans-serif}' +
    'html[data-theme="light"] .fflc-copia{background:#fff;border-color:#CBD5E1}' +
    '.fflc-copia:hover{box-shadow:0 6px 18px rgba(17,29,45,.10)}' +
    '.fflc-aqui{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;margin-bottom:6px}' +
    '.fflc-cid{display:flex;align-items:center;gap:9px}' +
    '.fflc-logo{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;background:var(--ff-marca-suave,#D6E4FF);color:var(--ff-marca-forte,#013DF2);flex-shrink:0}' +
    '.fflc-nome{font-size:13.5px;font-weight:800;color:#F3F4F6;overflow-wrap:anywhere}html[data-theme="light"] .fflc-nome{color:#1F2937}' +
    '.fflc-sub{font-size:11px;color:#9CA3AF}' +
    '.fflc-valor{margin-top:6px;font-size:12px;font-weight:700;color:#E5E7EB}html[data-theme="light"] .fflc-valor{color:#1F2937}' +
    '.fflc-escondido{display:none !important}' +
    // Ficha: seção Etapas e setores
    '.fflc-grupo{margin-bottom:12px}.fflc-grupo:last-child{margin-bottom:0}' +
    '.fflc-grupo-tit{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#575757;margin-bottom:6px}' +
    '.fflc-opts{display:flex;flex-wrap:wrap;gap:6px}' +
    '.fflc-opt{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:99px;border:1px solid rgba(17,29,45,.16);background:#fff;font:600 12.5px Manrope,system-ui,sans-serif;color:#374151;cursor:pointer}' +
    '.fflc-opt i{width:8px;height:8px;border-radius:50%}' +
    '.fflc-opt.on{background:rgba(var(--ff-marca-rgb,1,93,252),.10);border-color:rgba(var(--ff-marca-rgb,1,93,252),.45);color:var(--ff-marca-forte,#013DF2)}' +
    '.fflc-opt[disabled]{cursor:default;opacity:1}' +
    '.fflc-dica{font-size:11.5px;color:#767676;margin-top:6px}.fflc-erro{font-size:12px;color:#B91C1C;margin-top:4px}';

  var ICO = {
    orc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h6M9 17h6"/></svg>',
    doc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
    fala: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12Z"/></svg>'
  };
  var STATUS_ORC = { RASCUNHO: 'rascunho', APROVADO: 'aprovado', REJEITADO: 'rejeitado', CONVERTIDO: 'virou processo' };

  function vazio() { return { setor: null, setores_extras: [], etapas_extras: [], orcamentos: { total: 0 }, contratos: { total: 0 }, ultimo_contato: null }; }
  function setoresDe(d) { return [d.setor].concat(d.setores_extras || []).filter(function (x, i, a) { return x && a.indexOf(x) === i; }); }

  function tagsHtml(d, etapaAtual) {
    var t = setoresDe(d).map(function (id) { var s = porId(SETORES, id); return s ? '<span class="fflc-tag"><i style="background:' + cor(s.cor) + '"></i>' + esc(s.nome) + '</span>' : ''; });
    (d.etapas_extras || []).forEach(function (id) {
      if (id === etapaAtual) return;
      var e = porId(ETAPAS, id); if (e) t.push('<span class="fflc-tag etapa">também em ' + esc(e.nome) + '</span>');
    });
    t = t.filter(Boolean);
    return t.length ? '<div class="fflc-tags">' + t.join('') + '</div>' : '';
  }
  function linhasHtml(d) {
    var l = [], o = d.orcamentos || {}, k = d.contratos || {};
    if (o.total) l.push('<span class="fflc-l' + (o.aprovado ? ' ok' : '') + '">' + ICO.orc + '<span><b>' + o.total + '</b> ' + (o.total === 1 ? 'orçamento' : 'orçamentos') +
      (o.aprovado ? ' · <b>' + REAL.format(o.aprovado) + '</b> aprovado' : (o.ultimo_status ? ' · último ' + (STATUS_ORC[o.ultimo_status] || o.ultimo_status.toLowerCase()) : '')) + '</span></span>');
    if (k.total) l.push('<span class="fflc-l' + (k.aguardando ? ' espera' : (k.assinados ? ' ok' : '')) + '">' + ICO.doc + '<span><b>' + k.total + '</b> ' + (k.total === 1 ? 'contrato' : 'contratos') +
      (k.aguardando ? ' · <b>' + k.aguardando + '</b> aguardando assinatura' : (k.assinados ? ' · <b>' + k.assinados + '</b> ' + (k.assinados === 1 ? 'assinado' : 'assinados') : '')) + '</span></span>');
    var dc = diasDesde(d.ultimo_contato);
    if (dc != null) l.push('<span class="fflc-l">' + ICO.fala + '<span>contato registrado <b>' + haQuanto(dc) + '</b></span></span>');
    return l.length ? '<div class="fflc-linhas">' + l.join('') + '</div>' : '';
  }

  function decorar(card) {
    var id = +card.getAttribute('data-id'), d = (Q && Q[id]) || null;
    var velho = card.querySelector('.fflc-mais'); if (velho) velho.remove();
    card.setAttribute('data-fflc', '1');
    if (!d) return;
    var h = tagsHtml(d, +card.getAttribute('data-coluna-id')) + linhasHtml(d);
    if (!h) return;
    var el = document.createElement('div');
    el.className = 'fflc-mais';
    el.innerHTML = h;
    var rodape = card.querySelector('.lc-rodape');
    if (rodape) card.insertBefore(el, rodape); else card.appendChild(el);
  }

  function copiaHtml(d, etapa) {
    var sub = [d.empresa && d.empresa !== d.nome ? d.empresa : '', d.local || ''].filter(Boolean).join(' · ');
    return '<div class="fflc-aqui">Também nesta etapa</div>' +
      '<div class="fflc-cid"><span class="fflc-logo">' + esc(iniciais(d.empresa || d.nome)) + '</span><div><div class="fflc-nome">' + esc(d.nome || 'Lead') + '</div>' +
      (sub ? '<div class="fflc-sub">' + esc(sub) + '</div>' : '') + '</div></div>' +
      (d.valor ? '<div class="fflc-valor">Potencial ' + REAL.format(d.valor) + '/mês</div>' : '') +
      '<div class="fflc-mais">' + tagsHtml(d, etapa) + linhasHtml(d) +
      (d.responsavel ? '<div class="fflc-sub">Consultor: ' + esc(d.responsavel) + '</div>' : '') + '</div>';
  }

  function passaFiltro(id) {
    if (!FILTRO) return true;
    var d = (Q && Q[id]) || vazio(), s = setoresDe(d);
    return FILTRO === 'sem' ? !s.length : s.indexOf(+FILTRO) >= 0;
  }

  function semObservar(fn) {
    if (observador) observador.disconnect();
    try { fn(); } finally { if (observador && raiz) observador.observe(raiz, { childList: true, subtree: true }); }
  }
  function aplicar(forcar) {
    if (!raiz || !Q) return;
    semObservar(function () {
      raiz.querySelectorAll('.card-mini.lc[data-id]').forEach(function (c) { if (forcar || c.getAttribute('data-fflc') !== '1') decorar(c); });
      raiz.querySelectorAll('.fflc-copia').forEach(function (c) { c.remove(); });
      raiz.querySelectorAll('.card-mini.lc[data-id]').forEach(function (card) {
        var id = +card.getAttribute('data-id'), d = Q[id];
        if (!d || !(d.etapas_extras || []).length) return;
        var aqui = +card.getAttribute('data-coluna-id');
        d.etapas_extras.forEach(function (etapa) {
          if (etapa === aqui) return;
          var lista = document.getElementById('col-' + etapa);
          if (!lista) return;
          var cp = document.createElement('div');
          cp.className = 'fflc-copia';
          cp.setAttribute('data-fflc-lead', String(id));
          cp.innerHTML = copiaHtml(d, etapa);
          cp.addEventListener('click', function () { if (typeof window.openEditModal === 'function') window.openEditModal(String(id)); });
          lista.appendChild(cp);
        });
      });
      // Filtro por setor e o aviso de etapa vazia.
      raiz.querySelectorAll('.card-mini.lc[data-id]').forEach(function (c) { c.classList.toggle('fflc-escondido', !passaFiltro(+c.getAttribute('data-id'))); });
      raiz.querySelectorAll('.fflc-copia').forEach(function (c) { c.classList.toggle('fflc-escondido', !passaFiltro(+c.getAttribute('data-fflc-lead'))); });
      raiz.querySelectorAll('.cardsList').forEach(function (lista) {
        var visiveis = lista.querySelectorAll('.card-mini:not(.fflc-escondido), .fflc-copia:not(.fflc-escondido)').length;
        var nota = lista.querySelector('.empty-note');
        if (nota) nota.style.display = visiveis ? 'none' : '';
        var pill = document.getElementById('col-count-' + String(lista.id).replace('col-', ''));
        if (pill) {
          if (FILTRO) { if (!pill.hasAttribute('data-fflc-orig')) pill.setAttribute('data-fflc-orig', pill.textContent); pill.textContent = lista.querySelectorAll('.card-mini:not(.fflc-escondido)').length; }
          else if (pill.hasAttribute('data-fflc-orig')) { pill.textContent = pill.getAttribute('data-fflc-orig'); pill.removeAttribute('data-fflc-orig'); }
        }
      });
    });
  }

  function carregar() {
    if (pedindo) return pedindo;
    pedindo = fetch('/api/crm_lead_quadro.php?quadro=1', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r.ok) return;
        Q = r.leads || {}; ETAPAS = r.etapas || []; SETORES = r.setores || [];
        montarFiltro();
        aplicar(true);
      })
      .catch(function () {})
      .then(function () { pedindo = null; });
    return pedindo;
  }

  function montarFiltro() {
    var chip = document.getElementById('filterChip');
    if (!chip || document.getElementById('fflcFiltroSetor') || !SETORES.length) return;
    var rot = chip.closest('label') || chip.parentNode;
    var l = document.createElement('label');
    l.className = rot.className || 'field-group';
    l.innerHTML = '<span class="field-label">Setor</span><select id="fflcFiltroSetor" class="' + esc(chip.className) + '"><option value="">Todos os setores</option>' +
      SETORES.map(function (s) { return '<option value="' + s.id + '">' + esc(s.nome) + '</option>'; }).join('') + '<option value="sem">Sem setor</option></select>';
    rot.parentNode.insertBefore(l, rot.nextSibling);
    l.querySelector('select').addEventListener('change', function () { FILTRO = this.value; aplicar(false); });
  }

  // ── Ficha do lead: seção "Etapas e setores" ─────────────────────────
  function montarFicha() {
    var F = window.FfFicha, shell = document.getElementById('modalEdit'), form = document.getElementById('editForm');
    if (!F || !shell || !form || !form.elements.id || document.getElementById('fflcSection')) return;
    var sec = document.createElement('div');
    sec.className = 'form-section ffag-sec';
    sec.id = 'fflcSection';
    sec.innerHTML = '<div data-corpo></div><div class="fflc-erro" data-erro></div>';
    var antes = document.getElementById('ffContratoSection') || document.getElementById('ffOrcamentoSection') || document.getElementById('ffAgendaSection');
    var dir = form.querySelector('.ff-col-dir');
    if (antes && antes.parentNode) antes.parentNode.insertBefore(sec, antes.nextSibling);
    else if (dir) dir.insertBefore(sec, dir.firstChild);
    else return;
    F.secao(sec, 0, 'Etapas e setores', 'grade', 'ambar');
    F.emAbas(sec, ['geral', 'comercial']);
    var corpo = sec.querySelector('[data-corpo]'), erro = sec.querySelector('[data-erro]');
    var estado = null;

    function opt(item, on, travado, extra) {
      return '<button type="button" class="fflc-opt' + (on ? ' on' : '') + '"' + (travado ? ' disabled title="' + esc(travado) + '"' : ' data-v="' + item.id + '"') + ' aria-pressed="' + on + '">' +
        (item.cor ? '<i style="background:' + cor(item.cor) + '"></i>' : '') + esc(item.nome) + (extra || '') + '</button>';
    }
    function desenhar() {
      if (!estado) return;
      var e = estado.data.etapas, s = estado.data.setores;
      var h = '<div class="fflc-grupo"><div class="fflc-grupo-tit">Etapas do funil</div><div class="fflc-opts" data-grupo="etapas">' +
        estado.etapas_conta.map(function (x) {
          var principal = x.id === e.principal;
          return opt(x, principal || e.extras.indexOf(x.id) >= 0, principal ? 'Etapa principal: mude arrastando o card no quadro' : '', principal ? ' · principal' : '');
        }).join('') + '</div><div class="fflc-dica">O card aparece também nas etapas marcadas. Os números do funil contam só a principal.</div></div>';
      h += '<div class="fflc-grupo"><div class="fflc-grupo-tit">Setores</div>';
      if (!estado.setores_conta.length) h += '<div class="fflc-dica">A conta ainda não tem setores. Crie em Configurações, Setores.</div>';
      else h += '<div class="fflc-opts" data-grupo="setores">' + estado.setores_conta.map(function (x) {
        var principal = x.id === s.principal;
        return opt(x, principal || s.extras.indexOf(x.id) >= 0, principal ? 'Setor principal (o mesmo do Chat)' : '', principal ? ' · principal' : '');
      }).join('') + '</div><div class="fflc-dica">Pode marcar mais de um. Sem setor, o primeiro marcado vira o principal.</div>';
      h += '</div>';
      corpo.innerHTML = h;
      corpo.querySelectorAll('[data-v]').forEach(function (b) {
        b.onclick = function () {
          var grupo = b.parentNode.getAttribute('data-grupo'), v = +b.getAttribute('data-v');
          var base = grupo === 'etapas' ? e.extras.slice() : setoresMarcados(s);
          var i = base.indexOf(v); if (i >= 0) base.splice(i, 1); else base.push(v);
          b.disabled = true;
          var corpoReq = { acao: grupo, card_id: +form.elements.id.value };
          corpoReq[grupo] = base;
          fetch('/api/crm_lead_quadro.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify(corpoReq) })
            .then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Sem conexão.' }; })
            .then(function (r) {
              erro.textContent = r.ok ? '' : (r.error || 'Não foi possível salvar.');
              if (r.ok) { estado.data = r.data; carregar(); }
              desenhar();
            });
        };
      });
    }
    function setoresMarcados(s) { return (s.principal ? [s.principal] : []).concat(s.extras); }
    function abrir() {
      var id = form.elements.id.value;
      estado = null;
      if (!id) { corpo.innerHTML = '<div class="fflc-dica">Salve o lead para marcar etapas e setores.</div>'; return; }
      corpo.innerHTML = '<div class="fflc-dica">Carregando…</div>';
      fetch('/api/crm_lead_quadro.php?card_id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (form.elements.id.value !== id) return;
          if (!r.ok) { corpo.innerHTML = '<div class="fflc-dica">' + esc(r.error || 'Não foi possível carregar.') + '</div>'; return; }
          estado = r; desenhar();
        })
        .catch(function () { corpo.innerHTML = '<div class="fflc-dica">Sem conexão.</div>'; });
    }
    F.aoAbrir(shell, abrir);
  }

  function iniciar() {
    var col = document.querySelector('.kanban-col');
    if (!col) return;
    raiz = col.parentElement;
    var st = document.createElement('style'); st.id = 'fflcCss'; st.textContent = CSS; document.head.appendChild(st);
    // Juntando as mudanças: o quadro chega em lotes e o arrastar mexe no DOM a cada
    // passo. Durante o arrastar não faz nada; ao soltar, roda uma vez.
    var pendente = null;
    function agendar() {
      clearTimeout(pendente);
      pendente = setTimeout(function () {
        if (document.querySelector('.sortable-chosen, .sortable-drag, .sortable-fallback')) { agendar(); return; }
        aplicar(false);
      }, 120);
    }
    observador = new MutationObserver(function (muts) {
      if (!Q) return;
      // Card que mudou de etapa (arrastado): a gravação vem logo depois; relê o quadro.
      var arrastou = muts.some(function (m) { return Array.prototype.some.call(m.addedNodes, function (n) { return n.nodeType === 1 && n.matches && n.matches('.card-mini[data-fflc="1"]'); }); });
      if (arrastou) { clearTimeout(iniciar.t); iniciar.t = setTimeout(carregar, 1500); }
      agendar();
    });
    observador.observe(raiz, { childList: true, subtree: true });
    carregar();
    setTimeout(montarFicha, 0);
    // Ao fechar a ficha, relê (orçamento, contrato ou etapa podem ter mudado).
    var shell = document.getElementById('modalEdit');
    if (shell) {
      var aberto = false;
      new MutationObserver(function () {
        var agora = !shell.classList.contains('hidden');
        if (aberto && !agora) carregar();
        aberto = agora;
      }).observe(shell, { attributes: true, attributeFilter: ['class', 'style'] });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(iniciar, 0); });
  else setTimeout(iniciar, 0);
})();
