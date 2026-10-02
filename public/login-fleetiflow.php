<?php
/**
 * Login da conta Fleetiflow (edição CRM/comercial). Visualmente é o Fleetiflow;
 * por baixo é o MESMO AuthController::attemptLogin() do login.php padrão, sem
 * autenticação paralela. O hidden `login_page` diz ao AuthController pra
 * devolver erro de senha ou logout aqui, não no /login.php do Yuris.
 *
 * CSS transcrito de propósito das classes .mkt-* do Fleetiflow real
 * (frontend/src/modules/marketing/marketing.css e components/FundoAnimado.tsx
 * do projeto Fleetiflow), não reinventado: mesmo fundo (gradiente + malha +
 * manchas animadas), mesmo cartão de vidro, mesmos inputs e botão. O seletor
 * de perfil (despachante/concessionária/etc.) do login original não existe
 * aqui de propósito — essa conta é só admin único.
 */
require_once __DIR__ . '/../app/bootstrap.php';

// Num domínio da edição CRM (crm.fleetiflow.com.br, crm.viaautodoc.com.br...)
// o login mora na raiz "/": o nome deste arquivo não aparece na barra de
// endereço, porque o domínio pode ser de outra marca. Quem chega pelo
// endereço antigo (/login-fleetiflow) vai para a raiz. Só GET/HEAD: um POST
// redirecionado viraria GET e perderia o login. Fora de domínio CRM (dev em
// localhost) o arquivo continua abrindo direto.
if (\App\Core\ProductHost::isFleetiflow()
    && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'login-fleetiflow.php'
    && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Location: /', true, 301);
    exit;
}

use App\Usuarios\AuthController;

// Marca desta porta de entrada: a da conta dona do domínio (cadastrado no
// Painel Master), ou a do Fleetiflow. Ver App\Core\ProductHost::marcaDoHost.
$mk  = \App\Core\ProductHost::marcaDoHost();
$mkP = $mk['paleta'];
$h   = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

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
  <title>Entrar — <?= $h($mk['nome']) ?></title>
  <meta name="robots" content="noindex,follow">
  <?php if ($mk['personalizada']): ?>
  <link rel="icon" href="<?= $h($mk['favicon_url']) ?>">
  <?php else: ?>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/fleetiflow-favicon-32.png?v=1"><link rel="icon" type="image/png" sizes="192x192" href="/assets/fleetiflow-favicon-192.png?v=1"><link rel="apple-touch-icon" href="/assets/fleetiflow-apple-touch-icon.png?v=1">
  <?php endif; ?>
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
      --ff-blue:<?= $h($mkP['marca']) ?>; --ff-blue-deep:<?= $h($mkP['escura']) ?>; --ff-cyan:<?= $mk['personalizada'] ? $h($mkP['clara']) : '#6FD6E8' ?>;
      --ff-blue-forte:<?= $mk['personalizada'] ? $h($mkP['forte']) : '#0043C4' ?>; --ff-rgb:<?= $h($mkP['rgb']) ?>; --ff-texto:<?= $h($mkP['texto']) ?>;
      --ff-ink:#0C1B33; --ff-ink-soft:#45526B;
      --ff-glass: rgba(255,255,255,0.55); --ff-glass-border: rgba(255,255,255,0.65);
    }
    *{box-sizing:border-box}
    html,body{margin:0; padding:0}
    body{
      min-height:100vh; font-family:'Manrope',system-ui,-apple-system,'Segoe UI',sans-serif;
      color:var(--ff-ink); position:relative; overflow-x:hidden;
<?php /* Marca própria: o fundo leva os tons da marca; o Fleetiflow segue com o azul de sempre. */ ?>
      background: <?= $mk['personalizada'] ? 'linear-gradient(180deg, rgba(var(--ff-rgb),0.10) 0%, rgba(var(--ff-rgb),0.04) 22%, #FFFFFF 45%, rgba(var(--ff-rgb),0.05) 70%, rgba(var(--ff-rgb),0.10) 100%)' : 'linear-gradient(180deg, #EAF2FF 0%, #F5F9FF 22%, #FFFFFF 45%, #F3F7FF 70%, #E8F0FF 100%)' ?>;
    }
    h1{margin:0; font-family:'Manrope',sans-serif}
    p{margin:0}

<?php /* Fundo: malha diagonal panning + manchas de cor à deriva. Transcrito de
   FundoAnimado.tsx / .mkt-mesh / .mkt-blob do app original. Comentário em PHP
   para não sair no HTML: a tela serve outras marcas. */ ?>
    .mkt-mesh{
      position:absolute; inset:0; z-index:0; pointer-events:none; opacity:.55;
      background-image:
        repeating-linear-gradient(115deg, rgba(var(--ff-rgb),0.09) 0px, rgba(var(--ff-rgb),0.09) 1px, transparent 1px, transparent 96px),
        repeating-linear-gradient(25deg, <?= $mk['personalizada'] ? 'rgba(var(--ff-rgb),0.05)' : 'rgba(111,214,232,0.10)' ?> 0px, <?= $mk['personalizada'] ? 'rgba(var(--ff-rgb),0.05)' : 'rgba(111,214,232,0.10)' ?> 1px, transparent 1px, transparent 96px);
      background-size:600px 600px, 600px 600px;
      animation: mktMeshPan 70s linear infinite;
      mask-image: linear-gradient(180deg, black 0%, black 70%, transparent 100%);
      -webkit-mask-image: linear-gradient(180deg, black 0%, black 70%, transparent 100%);
    }
    @keyframes mktMeshPan{ from{background-position:0px 0px,0px 0px} to{background-position:600px 480px,-480px 600px} }
    .mkt-blob{ position:absolute; border-radius:50%; filter:blur(70px); opacity:.55; pointer-events:none; z-index:0; }
    @keyframes mktDrift1{ 0%,100%{transform:translate(0,0) scale(1)} 50%{transform:translate(40px,-30px) scale(1.08)} }
    @keyframes mktDrift2{ 0%,100%{transform:translate(0,0) scale(1)} 50%{transform:translate(-50px,30px) scale(1.05)} }
    @keyframes mktDrift3{ 0%,100%{transform:translate(0,0) scale(1)} 50%{transform:translate(30px,40px) scale(1.1)} }
    @media (prefers-reduced-motion: reduce){ .mkt-mesh, .mkt-blob{ animation:none !important } }

    .ff-wrap{
      min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center;
      gap:22px; padding:40px 16px; position:relative; z-index:1;
    }
    /* O <a> tem a largura final (320px ou a tela): se ficasse em "auto", ele
       mediria pelo tamanho natural do PNG (1000px ou mais) e o logo cairia à
       esquerda, mesmo com o align-items:center do .ff-wrap. Logo deitado é bem
       largo (~5.7:1): limita pela LARGURA, senão estoura em celular estreito. */
    .ff-logo{display:flex; justify-content:center; text-decoration:none; width:min(320px, 100%)}
    .ff-logo img{width:100%; height:auto; display:block}

    /* ── Cartão de vidro: mesmos valores de .mkt-glass + .mkt-login-card ── */
    .ff-card{
      width:100%; max-width:460px; border-radius:24px; padding:32px;
      background:var(--ff-glass); backdrop-filter:blur(22px) saturate(160%); -webkit-backdrop-filter:blur(22px) saturate(160%);
      border:1px solid var(--ff-glass-border);
      box-shadow:0 20px 60px rgba(11,42,107,0.14), inset 0 1px 0 rgba(255,255,255,0.6);
      position:relative;
    }
    .ff-title{font-size:26px; font-weight:800; letter-spacing:-.4px; text-align:center; color:var(--ff-ink)}
    .ff-sub{margin:8px 0 26px; font-size:14px; color:var(--ff-ink-soft); text-align:center}

    .field{margin-bottom:14px}
    .field label{display:block; margin-bottom:6px; font-size:12px; font-weight:700; color:var(--ff-ink-soft)}
    .field .rel{position:relative}

    /* ── Input: mesmos valores de .mkt-input (sem ícone, o original não tem) ── */
    .mkt-input{
      width:100%; height:46px; padding:0 14px; border-radius:12px;
      border:1px solid rgba(12,27,51,0.16); background:rgba(255,255,255,0.85);
      color:var(--ff-ink); font-family:inherit; font-size:15px; outline:none;
      transition:border-color .2s ease, box-shadow .2s ease;
    }
    .mkt-input:focus{ border-color:var(--ff-blue); box-shadow:0 0 0 3px rgba(var(--ff-rgb),0.15); }
    .mkt-input.with-toggle{padding-right:40px}
    .password-toggle{
      position:absolute; right:6px; top:50%; transform:translateY(-50%);
      background:none; border:none; padding:6px; cursor:pointer; display:flex; color:var(--ff-ink-soft);
    }

    .row{display:flex; align-items:center; justify-content:space-between; gap:12px; font-size:13px; margin:14px 0 0}
    .row label{display:flex; align-items:center; gap:7px; color:var(--ff-ink-soft); cursor:pointer}

    /* ── Botão: mesmos valores de .mkt-btn-primary (pílula, mesmo gradiente) ── */
    .btn-primary{
      display:flex; align-items:center; justify-content:center; gap:8px;
      width:100%; height:48px; margin-top:22px; border:none; border-radius:999px; cursor:pointer;
      background:linear-gradient(135deg, var(--ff-blue) 0%, var(--ff-blue-forte) 100%);
      color:#fff; font-weight:700; font-size:15px; font-family:inherit;
      box-shadow:0 12px 28px rgba(var(--ff-rgb),0.35);
      transition:transform .25s ease, box-shadow .25s ease, filter .25s ease;
      position:relative; overflow:hidden;
    }
    .btn-primary:hover{ transform:translateY(-2px); box-shadow:0 18px 36px rgba(var(--ff-rgb),0.45); filter:brightness(1.06); }
    .btn-primary:active{ transform:translateY(1px) scale(.99); }
    .btn-primary[disabled]{ opacity:.7; cursor:default; transform:none !important; }

    .flash{background:rgba(196,61,61,0.10); color:#B03030; padding:10px 12px; border-radius:10px; border:1px solid rgba(196,61,61,0.25); margin-bottom:14px; font-size:13px}
    .ff-footer{font-size:12px; color:var(--ff-ink-soft); opacity:.8; text-align:center; position:relative; z-index:1}
    #termsField a{color:var(--ff-blue)}

    /* ── Entrada em cascata: logo, cartão, rodapé (.mkt-login-entra) ── */
    @media (prefers-reduced-motion: no-preference){
      /* Sem opacity:0 fixo aqui de propósito: o fill-mode "backwards" já cobre
         o estado ANTES da animação começar; opacity fixo sobreviveria ao
         fim da animação e deixaria o elemento invisível para sempre. */
      .ff-entra{ animation: ffEntra .7s cubic-bezier(.22,1,.36,1) backwards; }
      .ff-entra-2{ animation-delay:.14s }
      .ff-entra-3{ animation-delay:.28s }
      @keyframes ffEntra{ from{opacity:0; transform:translateY(28px)} to{opacity:1; transform:translateY(0)} }

      /* Dentro do cartão, os campos também entram em cascata, depois dele. */
      .ff-card .ff-title, .ff-card .ff-sub, .ff-card .flash, .ff-card .field, .ff-card .row, .ff-card .btn-primary{ animation: ffEntra .6s cubic-bezier(.22,1,.36,1) backwards; }
      .ff-card .ff-title{ animation-delay:.30s } .ff-card .ff-sub{ animation-delay:.36s } .ff-card .flash{ animation-delay:.40s }
      .ff-card .field:nth-of-type(1){ animation-delay:.44s } .ff-card .field:nth-of-type(2){ animation-delay:.52s }
      .ff-card .row{ animation-delay:.60s } .ff-card #termsField{ animation-delay:.66s } .ff-card .btn-primary{ animation-delay:.74s }

      /* O logo flutua de leve, sem parar. */
      .ff-logo img, .ff-logo > span{ animation: ffFlutua 6s ease-in-out 1.2s infinite; }
      @keyframes ffFlutua{ 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }

      /* Aro de luz que percorre a borda do cartão, na cor da marca. Em navegador
         sem @property o aro fica parado, que é só a borda de sempre. */
      @property --ff-ang{ syntax:'<angle>'; inherits:false; initial-value:0deg; }
      .ff-card::before{
        content:''; position:absolute; inset:-1px; border-radius:25px; padding:1px; pointer-events:none;
        background:conic-gradient(from var(--ff-ang), transparent 0 62%, rgba(var(--ff-rgb),0.75) 80%, transparent 92%);
        -webkit-mask:linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite:xor; mask:linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); mask-composite:exclude;
        animation: ffAro 7s linear 1s infinite;
      }
      @keyframes ffAro{ to{ --ff-ang:360deg } }

      /* Brilho que atravessa o botão de tempos em tempos. */
      .btn-primary::after{
        content:''; position:absolute; top:0; bottom:0; left:-60%; width:45%; pointer-events:none;
        background:linear-gradient(100deg, transparent 0%, rgba(255,255,255,0.38) 50%, transparent 100%);
        animation: ffBrilho 5s ease-in-out 2s infinite;
      }
      @keyframes ffBrilho{ 0%{left:-60%} 28%,100%{left:130%} }

      /* Rótulo acende quando o campo recebe o foco. */
      .field label{ transition:color .2s ease }
      .field:focus-within label{ color:var(--ff-blue) }
      .ff-card:hover{ box-shadow:0 26px 70px rgba(11,42,107,0.18), inset 0 1px 0 rgba(255,255,255,0.6); }
      .ff-card{ transition:box-shadow .4s ease }
    }

    @media (max-width:420px){ .ff-card{padding:24px 20px} }
  </style>
</head>
<body>
  <div class="mkt-mesh" aria-hidden="true"></div>
  <div class="mkt-blob" style="width:520px;height:520px;top:-160px;left:-120px;background:radial-gradient(circle, var(--ff-blue) 0%, transparent 70%);animation:mktDrift1 22s ease-in-out infinite" aria-hidden="true"></div>
  <div class="mkt-blob" style="width:460px;height:460px;top:120px;right:-160px;background:radial-gradient(circle, var(--ff-cyan) 0%, transparent 70%);animation:mktDrift2 26s ease-in-out infinite" aria-hidden="true"></div>
  <div class="mkt-blob" style="width:600px;height:600px;top:60vh;left:-200px;background:radial-gradient(circle, <?= $mk['personalizada'] ? $h($mkP['media']) : '#B9D4FF' ?> 0%, transparent 70%);animation:mktDrift3 24s ease-in-out infinite" aria-hidden="true"></div>

  <div class="ff-wrap">
    <a href="/" class="ff-logo ff-entra" title="<?= $h($mk['nome']) ?>">
      <?php if (!empty($mk['logo_url'])): ?>
      <img src="<?= $h($mk['logo_url']) ?>" alt="<?= $h($mk['nome']) ?>">
      <?php else: ?>
      <!-- Marca sem logo horizontal enviado: ícone (ou inicial) + nome. -->
      <span style="display:inline-flex;align-items:center;gap:12px;text-decoration:none">
        <?php if (!empty($mk['icone_url'])): ?>
        <img src="<?= $h($mk['icone_url']) ?>" alt="" style="height:44px;width:auto">
        <?php else: ?>
        <span style="width:44px;height:44px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:var(--ff-blue);color:var(--ff-texto);font-weight:800;font-size:22px"><?= $h($mk['inicial']) ?></span>
        <?php endif; ?>
        <span style="font-size:26px;font-weight:800;letter-spacing:-.5px;color:var(--ff-ink)"><?= $h($mk['nome']) ?></span>
      </span>
      <?php endif; ?>
    </a>

    <div class="ff-card ff-entra ff-entra-2">
      <h1 class="ff-title">Entrar</h1>
      <p class="ff-sub"><?= $h($mk['subtitulo']) ?></p>

      <?php if ($flash): ?>
        <div class="flash"><?=htmlspecialchars($flash)?></div>
      <?php endif; ?>

      <form id="loginForm" method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="login_page" value="<?= \App\Core\ProductHost::isFleetiflow() ? '/' : '/login-fleetiflow.php' ?>">
        <input type="hidden" name="aceite_termos_servidor" id="aceite_termos_servidor" value="0">

        <div class="field">
          <label>E-mail</label>
          <input class="mkt-input" name="login" type="email" placeholder="<?= $mk['personalizada'] ? 'voce@empresa.com.br' : 'voce@fleetiflow.com.br' ?>" required autocomplete="username">
        </div>

        <div class="field">
          <label>Senha</label>
          <div class="rel">
            <input class="mkt-input with-toggle" id="password" name="password" type="password" required autocomplete="current-password">
            <button type="button" class="password-toggle" aria-label="Mostrar senha"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
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

    <div class="ff-footer ff-entra ff-entra-3">&copy; <?=date('Y')?> <?= $h($mk['nome']) ?> &middot; Todos os direitos reservados</div>
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
