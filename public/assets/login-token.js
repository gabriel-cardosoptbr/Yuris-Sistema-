/* ═══════════════════════════════════════════════════════════════════════
   login-token.js: o login pega o token da sessão de AGORA antes de enviar
   (28/10/2026).

   A tela de login aberta há mais de 24 min (ou reaberta pelo Chrome no dia
   seguinte) carrega o token de uma sessão que o PHP já apagou, e o login
   voltava com "Token inválido." sem conferir a senha. Aqui, no envio, o
   formulário busca /api/auth/csrf.php, troca o token e envia de novo.

   Escuta na fase de captura do document, antes dos outros "submit" da tela
   (a animação de saída do login.php), e só segura o primeiro envio. Sem
   resposta do servidor, envia com o token que já estava: o servidor decide.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.id !== 'loginForm' || form.dataset.tokenNovo === '1') return;
    var campo = form.querySelector('input[name="csrf_token"]');
    if (!campo || !window.fetch) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    var reenviar = function () {
      form.dataset.tokenNovo = '1';
      if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
    };
    fetch('/api/auth/csrf.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.token) campo.value = j.token; })
      .catch(function () { /* sem rede: segue com o token da tela */ })
      .then(reenviar);
  }, true);
})();
