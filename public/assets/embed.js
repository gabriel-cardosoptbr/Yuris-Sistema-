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
  root.classList.add('yuris-embed');
  if (window.parent === window) return; // aberto sozinho: nada a avisar

  function temClasse(sel, cls) { var el = document.querySelector(sel); return !!el && el.classList.contains(cls); }
  var TELAS = {
    // a ficha do processo (editar e cadastrar são o mesmo pop-up)
    processos: { aberto: function () { var el = document.getElementById('modalProcess'); return !!el && !el.classList.contains('hidden') && getComputedStyle(el).display !== 'none'; } },
    // o painel da tarefa, ou o pop-up de cadastro (?nova=1)
    tarefas:   { aberto: function () { return temClasse('#tkDrawer', 'open') || temClasse('#modalNewTask', 'open'); } }
  };
  var pagina = location.pathname.replace(/^\/+/, '').replace(/\.php$/, '').split('/')[0];
  var cfg = TELAS[pagina];
  function avisar(tipo) { try { window.parent.postMessage({ yuris: tipo, tela: pagina }, location.origin); } catch (e) {} }
  if (!cfg) { avisar('embed-sem-popup'); return; }

  var jaAbriu = false, inicio = Date.now();
  function conferir() {
    var aberto = cfg.aberto();
    if (aberto && !jaAbriu) { jaAbriu = true; avisar('embed-pronto'); }
    if (!aberto && jaAbriu) { avisar('embed-fechar'); obs.disconnect(); clearInterval(t); }
    // Se em 12 segundos o pop-up não abriu (sem acesso, item apagado), devolve à home.
    if (!jaAbriu && Date.now() - inicio > 12000) { avisar('embed-sem-popup'); obs.disconnect(); clearInterval(t); }
  }
  var obs = new MutationObserver(conferir);
  function ligar() { obs.observe(document.body, { attributes: true, subtree: true, attributeFilter: ['class', 'style', 'hidden'] }); conferir(); }
  var t = setInterval(conferir, 400);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ligar); else ligar();
  // Esc também fecha pela home, se o pop-up do módulo não tratar a tecla.
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && jaAbriu) setTimeout(conferir, 50); });
})();
