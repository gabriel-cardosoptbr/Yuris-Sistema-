<?php
/**
 * Contratos e assinatura eletrônica (edição CRM). Regra em app/Contratos/,
 * API em /api/crm_contratos.php, página de quem assina em /assinar e
 * consulta pública em /verificar. Conta jurídica (Yuris) não tem esta tela.
 */
require_once __DIR__ . '/../app/bootstrap.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login'); exit; }
$ctxCtr = \App\Core\AccountContext::fromSession();
$ctxCtr->assertAccountActive();
if ($ctxCtr->getProduto() !== 'fleetiflow' || !\App\Contratos\Permissao::podeVer()) { header('Location: /dashboard'); exit; }
$activePage = 'contratos';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$cssVer = @filemtime(__DIR__ . '/assets/ff-contratos.css') ?: 1;
$jsVer  = @filemtime(__DIR__ . '/assets/ff-contratos.js') ?: 1;
$abrir = [
    'id'         => (int) ($_GET['id'] ?? 0),
    'cliente_id' => (int) ($_GET['cliente_id'] ?? 0),
    'card_id'    => (int) ($_GET['card_id'] ?? 0),
    'novo'       => !empty($_GET['novo']),
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Contratos</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=20">
  <link rel="stylesheet" href="/assets/ff-contratos.css?v=<?= $cssVer ?>">
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
            <h2 class="page-header-title">Contratos</h2>
            <p class="page-header-subtitle">Prepare o contrato, mande um link para cada participante e acompanhe as assinaturas. Quem assina confirma por código no WhatsApp, sem criar conta.</p>
          </div>
          <div class="page-header-actions">
            <button id="ctrNovo" type="button" class="ctr-btn ctr-btn-principal">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Novo contrato
            </button>
          </div>
        </div>
      </div>

      <div id="ctrApp" class="ctr"></div>
    </section>
  </div>
</main>

<script>
window.FF_CTR = { csrf: <?= json_encode($csrf) ?>, api: '/api/crm_contratos.php', abrir: <?= json_encode($abrir) ?> };
</script>
<script src="/assets/ff-contratos.js?v=<?= $jsVer ?>"></script>
</body>
</html>
