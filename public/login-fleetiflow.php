<?php
/**
 * Login da conta Fleetiflow (edição CRM/comercial). Visualmente é o Fleetiflow;
 * por baixo é o MESMO AuthController::attemptLogin() do login.php padrão, sem
 * autenticação paralela. O hidden `login_page` diz ao AuthController pra
 * devolver erro de senha ou logout aqui, não no /login.php do Yuris.
 */
require_once __DIR__ . '/../app/bootstrap.php';

use App\Usuarios\AuthController;

session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$flash = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AuthController::attemptLogin();
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Entrar — Fleetiflow</title>
  <meta name="robots" content="noindex,follow">
  <link rel="icon" type="image/png" href="/sistema_vendas/Imagens/fleetiflow-icone.png">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script>/* ff_terms_preboot — mesma lógica do login padrão: sem flash de reaceite */
    (function(){try{
      if (localStorage.getItem("yuris_terms_accepted_v1") === "1") {
        var s = document.createElement("style");
        s.id = "ff-terms-prehide";
        s.textContent = "#termsField{display:none !important}";
        document.head.appendChild(s);
      }
    }catch(e){}})();
  </script>
  <style>
    :root{
      --ff-blue:#015DFC; --ff-blue-deep:#0B2A6B; --ff-cyan:#6FD6E8;
      --ff-ink:#0C1B33; --ff-ink-soft:#45526B;
      --ff-glass: rgba(255,255,255,0.6); --ff-glass-border: rgba(255,255,255,0.7);
    }
    *{box-sizing:border-box}
    body{
      margin:0; min-height:100vh; font-family:'Manrope',system-ui,-apple-system,'Segoe UI',sans-serif;
      color:var(--ff-ink);
      background:
        radial-gradient(ellipse at 15% 15%, rgba(1,93,252,0.16) 0%, transparent 55%),
        radial-gradient(ellipse at 85% 85%, rgba(111,214,232,0.22) 0%, transparent 55%),
        #F2F5FA;
      display:flex; align-items:center; justify-content:center; padding:32px 16px;
    }
    .ff-wrap{width:100%; max-width:420px; display:flex; flex-direction:column; align-items:center; gap:22px}
    .ff-logo{display:block; text-decoration:none}
    .ff-logo img{height:52px; width:auto; display:block}
    .ff-card{
      width:100%; background:var(--ff-glass); backdrop-filter:blur(18px); -webkit-backdrop-filter:blur(18px);
      border:1px solid var(--ff-glass-border); border-radius:20px; padding:32px 28px;
      box-shadow:0 24px 56px rgba(12,27,51,0.16);
    }
    h1{margin:0; font-size:24px; font-weight:800; letter-spacing:-.4px; text-align:center}
    .ff-sub{margin:8px 0 24px; font-size:14px; color:var(--ff-ink-soft); text-align:center}
    .field{margin-bottom:14px}
    .field label{display:block; margin-bottom:6px; font-size:12px; font-weight:700; color:var(--ff-ink-soft)}
    .field .rel{position:relative}
    .field input{
      width:100%; padding:12px 13px; border-radius:10px; border:1px solid rgba(12,27,51,0.14);
      background:#fff; color:var(--ff-ink); font:inherit; font-size:14px; outline:none; transition:border-color .15s;
    }
    .field input:focus{border-color:var(--ff-blue)}
    .field input.with-icon{padding-left:38px}
    .field .icon{position:absolute; left:11px; top:50%; transform:translateY(-50%); opacity:.55}
    .password-toggle{position:absolute; right:6px; top:50%; transform:translateY(-50%); background:none; border:none; padding:6px; cursor:pointer}
    .row{display:flex; align-items:center; justify-content:space-between; gap:12px; font-size:13px; margin:14px 0 0}
    .row label{display:flex; align-items:center; gap:7px; color:var(--ff-ink-soft); cursor:pointer}
    .btn-primary{
      display:block; width:100%; padding:13px; margin-top:20px; border:none; border-radius:12px; cursor:pointer;
      background:linear-gradient(135deg, var(--ff-blue) 0%, var(--ff-blue-deep) 100%);
      color:#fff; font-weight:700; font-size:15px; font-family:inherit;
      box-shadow:0 10px 24px rgba(1,93,252,0.30); transition:filter .15s, transform .1s;
    }
    .btn-primary:hover{filter:brightness(1.06)}
    .btn-primary:active{transform:translateY(1px)}
    .flash{background:rgba(196,61,61,0.10); color:#B03030; padding:10px 12px; border-radius:10px; border:1px solid rgba(196,61,61,0.25); margin-bottom:14px; font-size:13px}
    .ff-footer{font-size:12px; color:var(--ff-ink-soft); opacity:.8; text-align:center}
    #termsField a{color:var(--ff-blue)}
    @media (max-width:420px){ .ff-card{padding:24px 20px} }
  </style>
</head>
<body>
  <div class="ff-wrap">
    <a href="/" class="ff-logo" title="Fleetiflow">
      <img src="/sistema_vendas/Imagens/fleetiflow-horizontal.png" alt="Fleetiflow">
    </a>

    <div class="ff-card">
      <h1>Entrar</h1>
      <p class="ff-sub">Central Comercial</p>

      <?php if ($flash): ?>
        <div class="flash"><?=htmlspecialchars($flash)?></div>
      <?php endif; ?>

      <form id="loginForm" method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="login_page" value="/login-fleetiflow.php">
        <input type="hidden" name="aceite_termos_servidor" id="aceite_termos_servidor" value="0">

        <div class="field">
          <label>E-mail</label>
          <div class="rel">
            <span class="icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#45526B" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="2,4 12,13 22,4"/></svg></span>
            <input name="login" type="email" placeholder="voce@fleetiflow.com.br" required class="with-icon" autocomplete="username">
          </div>
        </div>

        <div class="field">
          <label>Senha</label>
          <div class="rel">
            <span class="icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#45526B" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
            <input id="password" name="password" type="password" required class="with-icon" autocomplete="current-password">
            <button type="button" class="password-toggle" aria-label="Mostrar senha"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#45526B" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
          </div>
        </div>

        <div class="row">
          <label><input type="checkbox" name="remember"> Manter conectado</label>
        </div>

        <!-- LGPD: aceite obrigatório de termos, só na primeira vez por usuário -->
        <div id="termsField" class="field" style="margin-top:14px;font-size:12.5px;color:var(--ff-ink-soft);line-height:1.5">
          <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer">
            <input type="checkbox" name="aceite_termos" id="aceite_termos" required style="margin-top:3px">
            <span>Li e concordo com os <a href="/termos.php" target="_blank">Termos de Uso</a> e a <a href="/privacidade.php" target="_blank">Política de Privacidade</a>.</span>
          </label>
        </div>

        <button type="submit" class="btn-primary">Entrar</button>
      </form>
    </div>

    <div class="ff-footer">&copy; <?=date('Y')?> Fleetiflow &middot; Todos os direitos reservados</div>
  </div>

  <script src="/assets/cookie-consent.js?v=1"></script>
  <script>
    (function(){
      const btn = document.querySelector('.password-toggle');
      const pw = document.getElementById('password');
      if (btn && pw) {
        btn.addEventListener('click', function(){
          if (pw.type === 'password') { pw.type = 'text'; btn.setAttribute('aria-label','Ocultar senha'); }
          else { pw.type = 'password'; btn.setAttribute('aria-label','Mostrar senha'); }
        });
      }

      // LGPD: aceite "uma vez por usuário" — mesma lógica do login padrão
      const emailInput = document.querySelector('input[name="login"]');
      const termsField = document.getElementById('termsField');
      const checkboxEl = document.getElementById('aceite_termos');
      const hiddenSrv  = document.getElementById('aceite_termos_servidor');
      const preHide    = document.getElementById('ff-terms-prehide');

      function hideTerms(){
        if (termsField) termsField.style.display = 'none';
        if (checkboxEl) { checkboxEl.checked = true; checkboxEl.required = false; }
        if (hiddenSrv)  hiddenSrv.value = '1';
      }
      function showTerms(){
        if (preHide) preHide.remove();
        if (termsField) termsField.style.display = '';
        if (checkboxEl) { checkboxEl.checked = false; checkboxEl.required = true; }
        if (hiddenSrv)  hiddenSrv.value = '0';
        try { localStorage.removeItem('yuris_terms_accepted_v1'); } catch(e){}
      }
      async function checkTermsForEmail(email){
        if (!email || !email.includes('@')) return;
        try {
          const r = await fetch('/api/auth/check_terms.php?email=' + encodeURIComponent(email), { cache: 'no-store' });
          const j = await r.json();
          if (j && j.ok && j.accepted) hideTerms(); else showTerms();
        } catch(e){ /* silencioso */ }
      }
      if (localStorage.getItem('yuris_terms_accepted_v1') === '1') hideTerms();
      if (emailInput) {
        emailInput.addEventListener('blur',  () => checkTermsForEmail(emailInput.value.trim()));
        emailInput.addEventListener('change',() => checkTermsForEmail(emailInput.value.trim()));
        let pollCount = 0;
        const pollId = setInterval(() => {
          if (++pollCount > 10 || (emailInput.value && emailInput.value.includes('@'))) {
            clearInterval(pollId);
            if (emailInput.value) checkTermsForEmail(emailInput.value.trim());
          }
        }, 300);
      }

      const form = document.getElementById('loginForm');
      if (form) {
        form.addEventListener('submit', function(){
          const marcou = checkboxEl && checkboxEl.checked;
          const srvOk  = hiddenSrv && hiddenSrv.value === '1';
          if (marcou || srvOk) { try { localStorage.setItem('yuris_terms_accepted_v1', '1'); } catch(_){} }
        });
      }
    })();
  </script>
</body>
</html>
