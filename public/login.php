<?php
require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Controllers/AuthController.php';

use App\Controllers\AuthController;

session_start();
// generate csrf
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
  <title>Yuris - Login</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/sistema_vendas/public/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/sistema_vendas/public/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/sistema_vendas/public/assets/yuris-theme.css?v=27">
  <style>
    :root{
      --bg:#081526;
      --card-grad:linear-gradient(165deg, #0D1E35, #081526);
    }
    body{
      background-color: #081526;
      background-image:
        radial-gradient(ellipse at 20% 50%, rgba(30,58,95,0.15) 0%, transparent 60%),
        radial-gradient(ellipse at 80% 20%, rgba(46,85,135,0.10) 0%, transparent 60%);
      background-attachment: fixed;
      color:#E8EDF5;
      font-family: Inter, 'Poppins', system-ui, -apple-system, sans-serif;
      min-height:100vh;
    }
    .login-wrap{display:flex;align-items:center;justify-content:center;padding:48px 16px;min-height:100vh;position:relative;z-index:10}
    .login-card{width:100%;max-width:520px;background:linear-gradient(165deg, #0D1E35, #081526);color:#E8EDF5;border-radius:12px;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,0.50);border:1px solid rgba(191,199,213,0.12);position:relative;z-index:20}
    .login-logo{display:flex;flex-direction:column;align-items:center;margin:-28px -28px 16px -28px}
    .login-logo .mark{width:100%;height:auto;background:transparent;display:block;overflow:hidden;border-radius:12px 12px 0 0}
    .login-logo .mark img{width:100%;height:auto;display:block;object-fit:cover;object-position:center center;transform:scale(1.22);transform-origin:center center}
    .field{margin-bottom:14px}
    .field label{color:#8A96A8;font-size:.875rem;display:block;margin-bottom:6px}
    .field input{width:100%;padding:13px 13px;border-radius:8px;border:1px solid rgba(191,199,213,0.18);background:#0A1728 !important;color:#E8EDF5 !important;-webkit-text-fill-color:#E8EDF5 !important;font-family:inherit;font-size:.875rem;outline:none;transition:border-color .15s}
    .field input:focus{border-color:rgba(191,199,213,0.38)}
    .field input:-webkit-autofill,.field input:-webkit-autofill:hover,.field input:-webkit-autofill:focus{-webkit-box-shadow:0 0 0 100px #0A1728 inset !important;-webkit-text-fill-color:#E8EDF5 !important}
    .field .rel{position:relative}
    .field .icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:.6}
    .field input.with-icon{padding-left:38px}
    .field input::placeholder{color:#5D6470}
    .password-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:transparent;border:none;padding:6px;cursor:pointer}
    .btn-primary{display:inline-block;width:100%;padding:13px;border-radius:10px;background:linear-gradient(135deg,#1E3A5F,#2E5587);border:1px solid rgba(191,199,213,0.20);color:#E8EDF5;font-weight:700;font-size:15px;letter-spacing:.4px;font-family:inherit;box-shadow:0 2px 10px rgba(0,0,0,0.40);cursor:pointer;transition:filter .2s ease, box-shadow .2s ease, transform .1s ease}
    .btn-primary:hover{filter:brightness(1.12);box-shadow:0 4px 16px rgba(46,85,135,0.40)}
    .btn-primary:active{transform:translateY(1px);filter:brightness(0.95)}
    .flash{background:rgba(139,38,53,0.18);color:#e07080;padding:10px;border-radius:8px;border:1px solid rgba(139,38,53,0.30);margin-bottom:12px}
    .small-note{font-size:13px;color:#5D6470;margin-top:6px}
    @media (max-width:520px){ .login-card{padding:18px} }

    /* Entrada suave — sem fog blobs */
    .anim-item{opacity:0;transform:translateY(12px) scale(.995)}
    .anim-item.show{opacity:1;transform:translateY(0) scale(1);transition:transform .48s cubic-bezier(.2,.9,.2,1),opacity .38s ease}

    /* Saída ao submeter */
    .login-card.leaving{opacity:0;transform:translateY(18px) scale(.98);transition:opacity .38s ease, transform .38s cubic-bezier(.2,.9,.2,1)}
  </style>
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-logo anim-item" data-i="0">
        <div class="mark" aria-hidden="true">
          <img src="/sistema_vendas/Imagens/Logo Loguin.png" alt="Logo">
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="flash anim-item" data-i="1"><?=htmlspecialchars($flash)?></div>
      <?php endif; ?>

      <form id="loginForm" method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <div class="field anim-item" data-i="2">
          <label class="text-sm">E-mail</label>
          <div class="rel">
            <span class="icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#8A96A8" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="2,4 12,13 22,4"/></svg></span>
            <input name="login" type="email" placeholder="seu@exemplo.com" required class="with-icon" />
          </div>
          <div class="small-note">Use seu e-mail como usuário (ex: admin@admin.com)</div>
        </div>

        <div class="field anim-item" data-i="3">
          <label class="text-sm">Senha</label>
          <div class="rel">
            <span class="icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#8A96A8" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
            <input id="password" name="password" type="password" required class="with-icon" />
            <button type="button" class="password-toggle" aria-label="Mostrar senha"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8A96A8" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
          </div>
        </div>

        <div class="field anim-item" data-i="4" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
          <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="remember"> Lembrar</label>
        </div>

        <div class="anim-item" data-i="5">
          <button type="submit" class="btn-primary">Entrar</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function(){
      const btn = document.querySelector('.password-toggle');
      const pw = document.getElementById('password');
      if (btn && pw) {
        btn.addEventListener('click', function(){
          if (pw.type === 'password') { pw.type = 'text'; btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8A96A8" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'; btn.setAttribute('aria-label','Ocultar senha'); }
          else { pw.type = 'password'; btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8A96A8" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'; btn.setAttribute('aria-label','Mostrar senha'); }
        });
      }

      // entrance animation: staggered show
      const animItems = Array.from(document.querySelectorAll('.anim-item'));
      function playEntrance(){
        document.body.classList.add('has-loaded');
        animItems.forEach((el, idx) => {
          const d = (parseFloat(el.dataset.i) || idx) * 80;
          setTimeout(()=> el.classList.add('show'), d);
        });
      }
      if (document.readyState === 'complete' || document.readyState === 'interactive') playEntrance();
      else document.addEventListener('DOMContentLoaded', playEntrance);

      // exit animation on submit
      const form = document.getElementById('loginForm');
      if (form) {
        form.addEventListener('submit', function(e){
          // animate then submit
          e.preventDefault();
          const card = document.querySelector('.login-card');
          if (!card) { form.submit(); return; }
          card.classList.add('leaving');
          setTimeout(()=> form.submit(), 420);
        });
      }
    })();
  </script>
</body>
</html>
