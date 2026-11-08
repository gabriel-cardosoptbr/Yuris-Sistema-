/* ═══════════════════════════════════════════════════════════════════════════
   embed.js: uma tela do sistema mostrando SÓ o pop-up dela, dentro da home
   (interface nova, 07/11/2026).

   "Quando eu for abrir, tem que abrir exatamente o pop-up lá do campo, do
   jurídico, de operação, o que seja: o campo de verdade." A home abre a tela
   do módulo num iframe com ?embed=1; esta folha e este script escondem tudo
   menos o pop-up do módulo (o mesmo de sempre, com tudo o que ele tem) e avisam
   a home quando ele fecha, para a home tirar o iframe e se atualizar.

   Carregado por includes/sidebar.php só com ?embed=1 e só na interface nova.
   Para cada tela: o elemento do pop-up e como saber se está aberto.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var root = document.documentElement;
  var pagina = location.pathname.replace(/^\/+/, '').replace(/\.php$/, '').split('/')[0];
  root.classList.add('yuris-embed');
  root.setAttribute('data-embed-tela', pagina); // o embed.css recorta cada tela por aqui
  if (window.parent === window) return; // aberto sozinho: nada a avisar

  function temClasse(sel, cls) { var el = document.querySelector(sel); return !!el && el.classList.contains(cls); }
  function mostrando(id) { var el = document.getElementById(id); return !!el && el.style.display !== 'none' && getComputedStyle(el).display !== 'none'; }
  // fora: a tela não tem fundo próprio, então o clique fora da conversa (e o Esc) fecha.
  var TELAS = {
    // caixa: o elemento do pop-up em si, medido para a home levar a tela fantasma até ele.
    // a ficha do processo (editar e cadastrar são o mesmo pop-up)
    processos: { aberto: function () { var el = document.getElementById('modalProcess'); return !!el && !el.classList.contains('hidden') && getComputedStyle(el).display !== 'none'; }, caixa: '#modalProcess .proc-modal' },
    // o painel da tarefa, ou o pop-up de cadastro (?nova=1)
    tarefas:   { aberto: function () { return temClasse('#tkDrawer', 'open') || temClasse('#modalNewTask', 'open'); }, caixa: '#tkDrawerOverlay.open #tkDrawer, #modalNewTask.open .tk-modal' },
    // a ficha do cliente (?open=ID), e as janelas que ela abre por cima
    clientes:  { aberto: function () { return !!document.querySelector('.modal-shell.open'); }, caixa: '.modal-shell.open .modal-panel' },
    // a conversa do Chat (?jid=), a mesma de sempre; o "Voltar" dela fecha
    chat:      { aberto: function () { return mostrando('chatArea'); }, fora: '#chatContent', caixa: '#chatContent' }
  };
  function medir() {
    if (!cfg.caixa) return null;
    var el = [].filter.call(document.querySelectorAll(cfg.caixa), function (x) { return x.getBoundingClientRect().width > 0; })[0];
    if (!el) return null;
    var r = el.getBoundingClientRect();
    return { left: Math.round(r.left), top: Math.round(r.top), width: Math.round(r.width), height: Math.round(r.height) };
  }
  var cfg = TELAS[pagina];
  function avisar(tipo, caixa) { try { window.parent.postMessage({ yuris: tipo, tela: pagina, caixa: caixa || null }, location.origin); } catch (e) {} }
  if (!cfg) { avisar('embed-sem-popup'); return; }

  var jaAbriu = false, acabou = false, inicio = Date.now();
  // Um aviso só por pop-up: fechar pelo clique fora e o pop-up sumir em seguida não repetem.
  function encerrar(tipo) { if (acabou) return; acabou = true; avisar(tipo); obs.disconnect(); clearInterval(t); }
  function conferir() {
    var aberto = cfg.aberto();
    // O pop-up do módulo ainda está entrando (a animação dele dura até 220 ms); a medida
    // vai depois, para a tela fantasma da home parar exatamente onde ele parou.
    if (aberto && !jaAbriu) { jaAbriu = true; setTimeout(function () { if (!acabou) avisar('embed-pronto', medir()); }, 260); }
    if (!aberto && jaAbriu) encerrar('embed-fechar');
    // Se em 12 segundos o pop-up não abriu (sem acesso, item apagado), devolve à home.
    if (!jaAbriu && Date.now() - inicio > 12000) encerrar('embed-sem-popup');
  }
  var obs = new MutationObserver(conferir);
  function ligar() { obs.observe(document.body, { attributes: true, subtree: true, attributeFilter: ['class', 'style', 'hidden'] }); conferir(); }
  var t = setInterval(conferir, 400);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ligar); else ligar();
  // Esc também fecha pela home, se o pop-up do módulo não tratar a tecla.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || !jaAbriu) return;
    if (cfg.fora) {
      var foco = document.activeElement, caixa = document.querySelector(cfg.fora);
      if (!foco || foco === document.body || (caixa && caixa.contains(foco))) { encerrar('embed-fechar'); return; }
    }
    setTimeout(conferir, 50);
  });
  // Clique fora: o que está por trás do pop-up é invisível e não recebe clique,
  // então o clique fora cai no próprio html ou body.
  if (cfg.fora) document.addEventListener('mousedown', function (e) {
    if (jaAbriu && (e.target === document.body || e.target === document.documentElement)) encerrar('embed-fechar');
  });
})();
