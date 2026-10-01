/* ═══════════════════════════════════════════════════════════════════════
   ff-ficha.js: peças da ficha do lead e do cliente na edição CRM, no desenho
   do Fleetiflow. Carregado só quando $edicaoCrm é verdadeiro (prospeccao.php
   e clientes.php), junto com ff-ficha.css.

   O módulo NÃO conhece os formulários: ele oferece as peças (faixa de resumo,
   abas, duas colunas, seção numerada com ícone, menu do termômetro,
   formatadores) e cada página monta a sua ficha num bloco <script> próprio,
   dentro de `if ($edicaoCrm)`. Os inputs, ids e names continuam os mesmos:
   as peças só reorganizam o DOM que já existe, para o JS legado das páginas
   seguir funcionando sem saber que a ficha mudou de roupa.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* Ícones (traço, 24x24). currentColor, para pintar pela classe. */
  var SVG = {
    grade:      '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    dolar:      '<line x1="12" y1="2" x2="12" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    zap:        '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
    clipe:      '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
    relogio:    '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    doc:        '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    pessoa:     '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    pino:       '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
    calendario: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    nota:       '<path d="M15.5 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8.5z"/><path d="M15 3v6h6"/><line x1="8" y1="13" x2="14" y2="13"/><line x1="8" y1="17" x2="12" y2="17"/>',
    predio:     '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
    externo:    '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
    fechar:     '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    seta:       '<polyline points="6 9 12 15 18 9"/>',
    chama:      '<path d="M12 22c4.4 0 7-2.9 7-6.6 0-3.1-1.9-5.1-3.3-6.6-.4 1.6-1.3 2.6-2.4 3.1C13.4 9.6 13 7 10.6 4.3 10 7.5 7.5 9.2 6.3 11.4 5.3 13 5 14.3 5 15.4 5 19.1 7.6 22 12 22z"/>',
    floco:      '<line x1="12" y1="2" x2="12" y2="22"/><line x1="4.9" y1="6.5" x2="19.1" y2="17.5"/><line x1="19.1" y1="6.5" x2="4.9" y2="17.5"/>',
    tarefa:     '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    bandeira:   '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
    salvar:     '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
    lixo:       '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
    arquivar:   '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
    chat:       '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    maleta:     '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>'
  };
  function ico(nome, classe) {
    return '<svg' + (classe ? ' class="' + classe + '"' : '') + ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (SVG[nome] || '') + '</svg>';
  }

  /* ── Formatadores ─────────────────────────────────────────────────────── */
  function numero(v) {
    if (typeof v === 'number') return v;
    var s = String(v == null ? '' : v).trim();
    if (!s) return 0;
    // "1.500,50" e "15000.00" chegam aqui; o ponto só é milhar se vier antes
    // de exatamente três dígitos seguidos de nada ou de não-dígito.
    s = s.replace(/[^\d,.-]/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  }
  function fmtBRL(v) {
    return numero(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }
  function fmtFone(v) {
    var d = String(v == null ? '' : v).replace(/\D/g, '');
    if (!d) return '';
    if (d.length >= 12 && d.slice(0, 2) === '55') {
      var ddd = d.slice(2, 4), resto = d.slice(4);
      return '+55 ' + ddd + ' ' + (resto.length > 8 ? resto.slice(0, 5) + '-' + resto.slice(5) : resto.slice(0, 4) + '-' + resto.slice(4));
    }
    if (d.length === 11) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
    if (d.length === 10) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6);
    return d;
  }
  function iniciais(nome) {
    var p = String(nome || '').trim().split(/\s+/).filter(Boolean);
    if (!p.length) return '?';
    return (p[0][0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
  }
  function fmtDataHora(iso) {
    if (!iso) return '';
    var d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(iso);
    var dd = String(d.getDate()).padStart(2, '0'), mm = String(d.getMonth() + 1).padStart(2, '0');
    var hh = String(d.getHours()).padStart(2, '0'), mi = String(d.getMinutes()).padStart(2, '0');
    return dd + '/' + mm + '/' + d.getFullYear() + ' · ' + hh + ':' + mi;
  }
  function codigo(prefixo, id) {
    var n = parseInt(id, 10);
    return n > 0 ? '#' + prefixo + '-' + String(n).padStart(6, '0') : '';
  }

  /* ── Cabeçalho: número, data e X ──────────────────────────────────────── */
  function cabecalho(headerEl, aoFechar) {
    var meta = document.createElement('div');
    meta.className = 'ff-meta';
    var x = document.createElement('button');
    x.type = 'button'; x.className = 'ff-fechar'; x.setAttribute('aria-label', 'Fechar'); x.innerHTML = ico('fechar');
    x.addEventListener('click', function () { if (aoFechar) aoFechar(); });
    headerEl.appendChild(meta);
    headerEl.appendChild(x);
    return {
      meta: function (idTxt, criadoTxt) {
        meta.innerHTML = (idTxt ? '<b>' + esc(idTxt) + '</b>' : '') + (criadoTxt ? esc('Criado em ' + criadoTxt) : '');
      }
    };
  }

  /* ── Faixa de resumo ──────────────────────────────────────────────────── */
  function resumo(painel, antesDe, celulas) {
    var el = document.createElement('div');
    el.className = 'ff-resumo';
    el.innerHTML = celulas.map(function (c) {
      return '<div class="ff-cel" data-cel="' + esc(c.chave) + '">' +
               (c.semRotulo ? '' : '<span class="ff-cel-rotulo">' + esc(c.rotulo) + '</span>') +
               '<div class="ff-cel-valor"></div>' +
             '</div>';
    }).join('');
    painel.insertBefore(el, antesDe);
    return {
      el: el,
      celula: function (chave) { return el.querySelector('[data-cel="' + chave + '"]'); },
      valor:  function (chave) { return el.querySelector('[data-cel="' + chave + '"] .ff-cel-valor'); },
      set: function (chave, html) { var v = this.valor(chave); if (v) v.innerHTML = html; return v; }
    };
  }

  /* ── Abas ─────────────────────────────────────────────────────────────── */
  function abas(painel, antesDe, lista, aoTrocar) {
    var nav = document.createElement('div');
    nav.className = 'ff-abas';
    nav.setAttribute('role', 'tablist');
    nav.innerHTML = lista.map(function (a) {
      return '<button type="button" class="ff-aba" role="tab" data-aba="' + esc(a.chave) + '">' + ico(a.icone) + '<span>' + esc(a.rotulo) + '</span></button>';
    }).join('');
    painel.insertBefore(nav, antesDe);
    var atual = null;
    function mostrar(chave) {
      atual = chave;
      painel.setAttribute('data-ff-aba', chave);
      nav.querySelectorAll('.ff-aba').forEach(function (b) { b.classList.toggle('ativa', b.getAttribute('data-aba') === chave); });
      painel.querySelectorAll('[data-ff-abas]').forEach(function (s) {
        var minhas = (s.getAttribute('data-ff-abas') || '').split(/\s+/);
        s.classList.toggle('ff-oculta', minhas.indexOf(chave) === -1);
      });
      if (aoTrocar) aoTrocar(chave);
    }
    nav.addEventListener('click', function (ev) {
      var b = ev.target.closest('.ff-aba');
      if (b) mostrar(b.getAttribute('data-aba'));
    });
    return { el: nav, mostrar: mostrar, atual: function () { return atual; } };
  }

  /* Marca em que abas cada seção aparece. */
  function emAbas(el, lista) {
    if (!el) return;
    el.setAttribute('data-ff-abas', lista.join(' '));
  }

  /* ── Duas colunas ─────────────────────────────────────────────────────── */
  function colunas(corpo, esq, dir) {
    var grade = document.createElement('div');
    grade.className = 'ff-colunas';
    var a = document.createElement('div'); a.className = 'ff-col ff-col-esq';
    var b = document.createElement('div'); b.className = 'ff-col ff-col-dir';
    esq.filter(Boolean).forEach(function (s) { a.appendChild(s); });
    dir.filter(Boolean).forEach(function (s) { b.appendChild(s); });
    grade.appendChild(a); grade.appendChild(b);
    corpo.insertBefore(grade, corpo.firstChild);
    return grade;
  }

  /* ── Seção numerada, com ícone e recolhível ───────────────────────────── */
  function secao(el, numero, titulo, icone, cor) {
    if (!el) return;
    var t = el.querySelector('.form-section-title');
    if (!t) {
      t = document.createElement('div');
      t.className = 'form-section-title';
      el.insertBefore(t, el.firstChild);
    }
    el.setAttribute('data-ff-titulo', titulo);
    el.setAttribute('data-ff-numero', numero ? '1' : '');
    t.innerHTML = '<span class="ff-ico' + (cor ? ' ff-ico-' + cor : '') + '">' + ico(icone) + '</span><span>' + esc((numero ? numero + '. ' : '') + titulo) + '</span>';
    t.addEventListener('click', function (ev) {
      // Clique em link ou botão dentro do título não recolhe.
      if (ev.target.closest('a, button, input, select')) return;
      el.classList.toggle('ff-fechada');
    });
  }

  /* Renumera as seções visíveis na ordem dada: bloco escondido não deixa buraco na contagem. */
  function renumerar(lista) {
    var n = 0;
    lista.filter(Boolean).forEach(function (el) {
      if (el.getAttribute('data-ff-numero') !== '1') return;
      var alvo = el.querySelector('.form-section-title span:last-child');
      if (!alvo) return;
      if (el.style.display === 'none') return;
      n++;
      alvo.textContent = n + '. ' + (el.getAttribute('data-ff-titulo') || '');
    });
  }

  /* Cria uma seção nova em volta de elementos já existentes (clientes.php). */
  function envolver(filhos, classeExtra) {
    var s = document.createElement('div');
    s.className = 'form-section' + (classeExtra ? ' ' + classeExtra : '');
    filhos.filter(Boolean).forEach(function (f) { s.appendChild(f); });
    return s;
  }

  /* ── Termômetro ───────────────────────────────────────────────────────── */
  var TERMO_ICONE = { quente: 'chama', morno: 'chama', frio: 'floco', congelado: 'floco' };
  function termo(chave, rotulo, manual, porque) {
    chave = chave || 'frio';
    return '<button type="button" class="ff-termo ff-termo-' + esc(chave) + '" data-termo' +
             ' title="' + esc((porque ? porque : '') + (manual ? (porque ? ' · ' : '') + 'escolhido à mão' : '') || 'Clique para trocar') + '">' +
             ico(TERMO_ICONE[chave] || 'chama') + '<span>' + esc(rotulo || chave) + '</span>' + (manual ? '<span class="ff-mao"></span>' : '') +
           '</button>';
  }

  var _fecharMenu = null;
  function fecharMenu() {
    var m = document.getElementById('ffMenu');
    if (m) m.remove();
    if (_fecharMenu) { document.removeEventListener('click', _fecharMenu, true); _fecharMenu = null; }
  }
  /** Menu Automático / Quente / Morno / Frio / Congelado embaixo do botão. */
  function menuTermo(botao, atual, aoEscolher) {
    fecharMenu();
    var menu = document.createElement('div');
    menu.id = 'ffMenu';
    menu.className = 'ff-menu';
    menu.innerHTML = [['', 'Automático (regra do termômetro)'], ['quente', 'Quente'], ['morno', 'Morno'], ['frio', 'Frio'], ['congelado', 'Congelado']]
      .map(function (o) {
        return '<button type="button" data-valor="' + o[0] + '" class="mt-' + (o[0] || 'auto') + (String(atual || '') === o[0] ? ' ativo' : '') + '">' + o[1] + '</button>';
      }).join('');
    document.body.appendChild(menu);
    var r = botao.getBoundingClientRect();
    menu.style.top  = Math.min(r.bottom + 6, window.innerHeight - menu.offsetHeight - 12) + 'px';
    menu.style.left = Math.min(r.left, window.innerWidth - menu.offsetWidth - 12) + 'px';
    menu.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-valor]');
      if (!b) return;
      ev.stopPropagation();
      fecharMenu();
      aoEscolher(b.getAttribute('data-valor'));
    });
    _fecharMenu = function (ev) {
      if (ev.target.closest('#ffMenu') || ev.target.closest('[data-termo]')) return;
      fecharMenu();
    };
    setTimeout(function () { if (_fecharMenu) document.addEventListener('click', _fecharMenu, true); }, 0);
  }

  /* ── Peças prontas da faixa ───────────────────────────────────────────── */
  /** Avatar + nome + papel, com um <select> transparente por cima (sem name!). */
  function celulaResponsavel(host, selectOrigem, subRotulo) {
    host.innerHTML = '<div class="ff-resp"><span class="ff-avatar"></span><span class="ff-resp-txt"><span class="ff-resp-nome"></span><span class="ff-resp-sub"></span></span>' + ico('seta') + '<select class="ff-resp-sel" aria-label="Responsável"></select></div>';
    var sel = host.querySelector('.ff-resp-sel');
    sel.innerHTML = selectOrigem.innerHTML;
    sel.value = selectOrigem.value;
    sel.disabled = selectOrigem.disabled;
    sel.addEventListener('change', function () {
      selectOrigem.value = sel.value;
      selectOrigem.dispatchEvent(new Event('change', { bubbles: true }));
    });
    var opt = selectOrigem.options[selectOrigem.selectedIndex];
    var nome = selectOrigem.value && opt ? opt.textContent.trim() : '';
    var av = host.querySelector('.ff-avatar');
    av.textContent = nome ? iniciais(nome) : '?';
    av.classList.toggle('ff-avatar-vazio', !nome);
    host.querySelector('.ff-resp-nome').textContent = nome || 'Sem responsável';
    host.querySelector('.ff-resp-sub').textContent = nome ? (typeof subRotulo === 'function' ? subRotulo(selectOrigem.value, nome) : (subRotulo || '')) : 'Clique para escolher';
  }

  /** Select em pílula espelhando um select do formulário (sem name!). */
  function celulaSelect(host, selectOrigem, classe) {
    var sel = host.querySelector('select.ff-sel');
    if (!sel) {
      host.innerHTML = '<select class="ff-sel' + (classe ? ' ' + classe : '') + '"></select>';
      sel = host.querySelector('select.ff-sel');
      sel.addEventListener('change', function () {
        selectOrigem.value = sel.value;
        selectOrigem.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
    if (sel.innerHTML !== selectOrigem.innerHTML) sel.innerHTML = selectOrigem.innerHTML;
    sel.value = selectOrigem.value;
    sel.disabled = selectOrigem.disabled;
    return sel;
  }

  /** Ícone verde + número formatado + atalho para abrir a conversa. */
  function celulaWhatsapp(host, numeroBruto, aoAbrir) {
    var fone = fmtFone(numeroBruto);
    host.innerHTML = '<span class="ff-zap-ico' + (fone ? '' : ' ff-zap-off') + '">' + ico('zap') + '</span>' +
      (fone ? '<span class="ff-txt">' + esc(fone) + '</span><button type="button" class="ff-link" title="Abrir conversa">' + ico('externo') + '</button>'
            : '<span class="ff-txt ff-vazio-txt">Não informado</span>');
    var b = host.querySelector('.ff-link');
    if (b && aoAbrir) b.addEventListener('click', aoAbrir);
  }

  /** Nome em destaque, com a "logo" (inicial ou ícone) e um atalho opcional. */
  function celulaNome(host, nome, legenda, iconeOuInicial, aoAbrir, tituloAtalho) {
    var cel = host.closest('.ff-cel');
    var logo = cel.querySelector('.ff-logo');
    if (!logo) {
      logo = document.createElement('span');
      logo.className = 'ff-logo';
      cel.insertBefore(logo, cel.firstChild);
      var wrap = document.createElement('div');
      while (logo.nextSibling) wrap.appendChild(logo.nextSibling);
      cel.appendChild(wrap);
    }
    logo.innerHTML = iconeOuInicial && iconeOuInicial.length <= 2 ? esc(iconeOuInicial) : ico(iconeOuInicial || 'predio');
    host.innerHTML = '<span class="ff-txt ff-nome">' + esc(nome || legenda || 'Sem nome') + '</span>' +
      (aoAbrir ? '<button type="button" class="ff-link" title="' + esc(tituloAtalho || 'Abrir') + '">' + ico('externo') + '</button>' : '');
    var b = host.querySelector('.ff-link');
    if (b) b.addEventListener('click', aoAbrir);
  }

  /** Botões do rodapé ganham ícone e as classes de cor da ficha. */
  function botao(el, icone, classe) {
    if (!el) return;
    if (icone && !el.querySelector('svg')) el.insertAdjacentHTML('afterbegin', ico(icone));
    if (classe) el.classList.add(classe);
  }

  /** Quando o modal abre (perde `hidden` ou ganha `open`), roda a função. */
  function aoAbrir(shell, fn) {
    var mo = new MutationObserver(function () {
      var aberto = shell.classList.contains('open') || (!shell.classList.contains('hidden') && getComputedStyle(shell).display !== 'none');
      // Roda a cada mudança de classe enquanto aberto: fechar e reabrir no mesmo
      // instante (fechar a ficha e clicar em Novo) chega aqui como UMA mutação.
      if (aberto) fn();
    });
    mo.observe(shell, { attributes: true, attributeFilter: ['class', 'style'] });
  }

  window.FfFicha = {
    esc: esc, ico: ico, numero: numero, fmtBRL: fmtBRL, fmtFone: fmtFone, iniciais: iniciais, fmtDataHora: fmtDataHora, codigo: codigo,
    cabecalho: cabecalho, resumo: resumo, abas: abas, emAbas: emAbas, colunas: colunas, secao: secao, renumerar: renumerar, envolver: envolver,
    termo: termo, menuTermo: menuTermo, fecharMenu: fecharMenu,
    celulaResponsavel: celulaResponsavel, celulaSelect: celulaSelect, celulaWhatsapp: celulaWhatsapp, celulaNome: celulaNome,
    botao: botao, aoAbrir: aoAbrir
  };
})();
