/* Confere o SHA-256 de um PDF no navegador contra os hashes do contrato (verificar.php). */
(function () {
  'use strict';
  var campo = document.getElementById('arquivo');
  var saida = document.getElementById('resultado');
  var fonte = document.getElementById('hashes');
  if (!campo || !saida || !fonte) return;
  var hashes = {};
  try { hashes = JSON.parse(fonte.textContent || '{}'); } catch (e) { hashes = {}; }
  campo.addEventListener('change', function () {
    var f = campo.files && campo.files[0];
    if (!f) return;
    if (!window.crypto || !crypto.subtle) { mostrar('Este navegador não calcula o hash. Use um navegador atualizado.', 'erro'); return; }
    mostrar('Calculando…', '');
    f.arrayBuffer().then(function (buf) { return crypto.subtle.digest('SHA-256', buf); }).then(function (d) {
      var hex = Array.prototype.map.call(new Uint8Array(d), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
      if (hashes[hex]) mostrar('Confere: este arquivo é ' + hashes[hex] + '. SHA-256 ' + hex, 'ok');
      else mostrar('Não confere com nenhum arquivo deste contrato. SHA-256 ' + hex, 'erro');
    }).catch(function () { mostrar('Não foi possível ler o arquivo.', 'erro'); });
  });
  function mostrar(msg, tipo) { saida.hidden = false; saida.className = 'ass-aviso' + (tipo ? ' ' + tipo : ''); saida.textContent = msg; }
})();
