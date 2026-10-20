<?php
/**
 * Plataforma (edição CRM): a ligação do CRM com a plataforma Fleetiflow e a
 * carteira de clientes que usam a plataforma. Regra em app/Plataforma/, API
 * em /api/plataforma.php. Conta jurídica (Yuris) não tem esta tela.
 */
require_once __DIR__ . '/../app/bootstrap.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login'); exit; }
$ctxPla = \App\Core\AccountContext::fromSession();
$ctxPla->assertAccountActive();
if ($ctxPla->getProduto() !== 'fleetiflow') { header('Location: /dashboard'); exit; }
$activePage = 'plataforma';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$cssOrc = @filemtime(__DIR__ . '/assets/ff-orcamentos.css') ?: 1;
$cssVer = @filemtime(__DIR__ . '/assets/ff-plataforma.css') ?: 1;
$jsVer  = @filemtime(__DIR__ . '/assets/ff-plataforma.js') ?: 1;
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Plataforma</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <link rel="stylesheet" href="/assets/ff-orcamentos.css?v=<?= $cssOrc ?>">
  <link rel="stylesheet" href="/assets/ff-plataforma.css?v=<?= $cssVer ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; background: #070F1C; color: #D8E4F0; font-family: 'Poppins', system-ui, sans-serif; min-height: 100vh; }
    main.w-full { padding-top: 24px !important; }
    section.main-content { display: flex; flex-direction: column; min-width: 0; }
  </style>
</head>
<body>
<main class="w-full px-6 py-6">
  <div class="page-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <section class="main-content">
      <div class="card-shell page-header" style="margin-bottom:16px;flex-shrink:0;">
        <div class="page-header-inner">
          <div class="page-header-text">
            <h2 class="page-header-title">Plataforma</h2>
            <p class="page-header-subtitle">Os clientes que usam a plataforma Fleetiflow: plano, módulos, quanto geram e o que pediram. Abra o lead para oferecer um módulo ou outro plano.</p>
          </div>
        </div>
      </div>

      <div id="plaApp" class="orc"></div>
    </section>
  </div>
</main>

<script>
window.FF_PLA = { csrf: <?= json_encode($csrf) ?>, api: '/api/plataforma.php' };
</script>
<script src="/assets/ff-plataforma.js?v=<?= $jsVer ?>"></script>
</body>
</html>
