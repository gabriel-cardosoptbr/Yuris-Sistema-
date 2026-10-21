/* ═══════════════════════════════════════════════════════════════════════
   ambientes.js: o seletor de ambiente do menu e a guarda de alteração
   pendente. Carregado pelo sidebar.php só na conta com ambientes.

   Guarda: formulário visível em que a pessoa digitou ou escolheu algo fica
   "com alteração". Sair pelo menu ou pelo seletor com um desses na tela abre
   um aviso (modal, sem diálogo do navegador). Enviar o formulário, ou ele
   sumir da tela (modal fechado depois de salvar), tira a marca.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var aside = document.querySelector('aside.sidebar.sb-com-ambientes');
  if (!aside) return;

  // ── Título da página com o ambiente ────────────────────────────────────
  var rotulo = aside.getAttribute('data-ambiente-rotulo');
  if (rotulo && document.title.indexOf(rotulo + ' · ') !== 0) document.title = rotulo + ' · ' + document.title;

  var amb = aside.getAttribute('data-ambiente');
  var areaUrl = new URLSearchParams(location.search).get('amb');
  function depoisDoCabecalho(el) {
    // Cabeçalho padrão das telas, ou o do painel comercial (.ffc-cabecalho).
    var h = document.querySelector('section.main-content .page-header, .main-content .page-header, section.main-content > .ffc-cabecalho');
    if (h && h.parentNode) { h.parentNode.insertBefore(el, h.nextSibling); return; }
    var m = document.querySelector('section.main-content');
    if (m) m.insertBefore(el, m.firstChild);
  }

  // ── Clientes filtrados pela área (?amb=software|digital) ───────────────
  if (/^\/clientes(\.php)?$/.test(location.pathname) && (areaUrl === 'software' || areaUrl === 'digital')) {
    document.addEventListener('DOMContentLoaded', function () {
      var n = document.createElement('div');
      n.className = 'amb-faixa';
      n.innerHTML = 'Mostrando os clientes de <b>' + (areaUrl === 'software' ? 'Software' : 'Digital / Marketing') + '</b>: quem tem valor, quadro' +
        (areaUrl === 'digital' ? ', frequência ou campanha' : '') + ' desta área. O cadastro é um só; todos os clientes ficam em <a href="/clientes?amb=empresa">Empresa</a>.';
      depoisDoCabecalho(n);
    });
  }

  // ── Comercial: vendas de Software e de Digital no painel ───────────────
  if (amb === 'comercial' && /^\/dashboard(\.php)?$/.test(location.pathname)) {
    document.addEventListener('DOMContentLoaded', function () {
      fetch('/api/ambientes.php?visao=comercial_areas', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); }).then(function (r) {
          if (!r || !r.ok) return;
          var d = r.data, ROT = { software: 'Software', digital: 'Digital / Marketing', ambos: 'Os dois', sem_area: 'Sem área marcada' };
          var brl = function (v) { return (Number(v) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };
          var linhas = ['software', 'digital', 'ambos', 'sem_area'].map(function (k) {
            var a = d.abertos[k] || { qtd: 0, valor: 0 }, f = d.fechados[k] || { qtd: 0, valor: 0 };
            if (!a.qtd && !f.qtd && k !== 'software' && k !== 'digital') return '';
            return '<div class="amb-area"><span>' + ROT[k] + '</span><b>' + f.qtd + ' fechado(s) · ' + brl(f.valor) + '</b><small>' + a.qtd + ' em aberto · ' + brl(a.valor) + '</small></div>';
          }).join('');
          var c = document.createElement('div');
          c.className = 'amb-comercial';
          c.innerHTML = '<div class="amb-comercial-tit">Vendas por área do serviço no mês <small>(marque a área na ficha do lead, seção "Etapas e setores")</small></div><div class="amb-areas">' + linhas + '</div>';
          depoisDoCabecalho(c);
        }).catch(function () { /* o painel segue sem o bloco */ });
    });
  }

  // ── Seletor ────────────────────────────────────────────────────────────
  var btn = document.getElementById('sbAmbBtn'), lista = document.getElementById('sbAmbLista');
  var fechando = null;
  function abrir(sim) {
    if (!btn || !lista) return;
    btn.setAttribute('aria-expanded', sim ? 'true' : 'false');
    clearTimeout(fechando);
    if (sim) {
      lista.classList.remove('sb-fechando');
      // Menu recolhido corta o que sai dele: a lista fica fixa na tela, ao lado do botão.
      if (document.documentElement.classList.contains('sb-retraido') && window.innerWidth > 900) {
        var r = btn.getBoundingClientRect();
        lista.style.position = 'fixed'; lista.style.left = Math.round(r.right + 10) + 'px'; lista.style.top = Math.round(r.top) + 'px'; lista.style.right = 'auto'; lista.style.width = '290px';
      } else {
        lista.style.position = ''; lista.style.left = ''; lista.style.top = ''; lista.style.right = ''; lista.style.width = '';
      }
      lista.hidden = false;
      var a = lista.querySelector('a.ativo') || lista.querySelector('a'); if (a) a.focus({ preventScroll: true });
    } else if (!lista.hidden) {
      // Some com a animação de fechar; sem animação (movimento reduzido), some direto.
      lista.classList.add('sb-fechando');
      fechando = setTimeout(function () { lista.hidden = true; lista.classList.remove('sb-fechando'); }, 170);
    }
  }
  if (btn && lista) {
    btn.addEventListener('click', function (e) { e.stopPropagation(); abrir(lista.hidden); });
    document.addEventListener('click', function (e) { if (!lista.hidden && !lista.contains(e.target) && e.target !== btn) abrir(false); });
    lista.addEventListener('keydown', function (e) {
      var itens = Array.prototype.slice.call(lista.querySelectorAll('a')), i = itens.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); (itens[i + 1] || itens[0]).focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); (itens[i - 1] || itens[itens.length - 1]).focus(); }
      else if (e.key === 'Escape') { e.preventDefault(); abrir(false); btn.focus(); }
    });
  }

  // ── Guarda de alteração pendente ───────────────────────────────────────
  function visivel(el) { return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length)); }
  function marcar(e) {
    var f = e.target && e.target.closest && e.target.closest('form, [data-amb-guarda]');
    if (!f || aside.contains(f) || e.target.type === 'search' || e.target.hasAttribute('data-amb-ignorar')) return;
    if (!e.isTrusted) return;
    f.setAttribute('data-amb-sujo', '1');
  }
  document.addEventListener('input', marcar, true);
  document.addEventListener('change', marcar, true);
  document.addEventListener('submit', function (e) { if (e.target && e.target.removeAttribute) e.target.removeAttribute('data-amb-sujo'); }, true);

  function pendente() {
    var sujos = document.querySelectorAll('[data-amb-sujo="1"]');
    for (var i = 0; i < sujos.length; i++) {
      if (visivel(sujos[i])) return true;
      sujos[i].removeAttribute('data-amb-sujo');   // fechou sem sair da página: já não conta
    }
    return false;
  }
  window.YurisAmbientes = {
    pendente: pendente,
    limpar: function (el) { (el || document).querySelectorAll('[data-amb-sujo]').forEach(function (x) { x.removeAttribute('data-amb-sujo'); }); }
  };

  function avisar(destino) {
    var fundo = document.createElement('div');
    fundo.className = 'amb-guarda-fundo';
    fundo.innerHTML = '<div class="amb-guarda" role="alertdialog" aria-modal="true" aria-labelledby="ambGuardaT" aria-describedby="ambGuardaD">' +
      '<h3 id="ambGuardaT">Há alterações não salvas</h3>' +
      '<p id="ambGuardaD">Você mexeu num formulário desta tela e ainda não salvou. Se sair agora, o que foi digitado se perde.</p>' +
      '<div class="amb-guarda-acoes"><button type="button" data-sair>Sair sem salvar</button><button type="button" class="principal" data-ficar>Continuar editando</button></div></div>';
    document.body.appendChild(fundo);
    var ficar = fundo.querySelector('[data-ficar]');
    ficar.focus();
    function fechar() { fundo.remove(); document.removeEventListener('keydown', tecla, true); }
    function tecla(e) { if (e.key === 'Escape') { e.preventDefault(); fechar(); } }
    document.addEventListener('keydown', tecla, true);
    ficar.onclick = fechar;
    fundo.addEventListener('click', function (e) { if (e.target === fundo) fechar(); });
    fundo.querySelector('[data-sair]').onclick = function () { window.YurisAmbientes.limpar(); fechar(); location.href = destino; };
  }

  aside.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || a.target === '_blank') return;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href)) return;
    if (!pendente()) return;
    e.preventDefault();
    abrir(false);
    avisar(a.href);
  }, true);
})();
