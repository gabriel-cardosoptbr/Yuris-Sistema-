<?php
/**
 * Orçamentos do CRM (edição Fleetiflow). Regra em app/Orcamentos/, API em
 * /api/crm_orcamentos.php, impressão para o cliente em /orcamento_imprimir.php.
 * Conta jurídica (Yuris) não tem esta tela.
 */
require_once __DIR__ . '/../app/bootstrap.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login'); exit; }
$ctxOrc = \App\Core\AccountContext::fromSession();
$ctxOrc->assertAccountActive();
if ($ctxOrc->getProduto() !== 'fleetiflow') { header('Location: /dashboard'); exit; }
// Veículo (despachante) ou genérico (só serviços): ver App\Orcamentos\Orcamento::modoVeiculo.
$orcVeiculo = \App\Orcamentos\Orcamento::modoVeiculo(\App\Master\Account::findById((int) $ctxOrc->getAccountId()) ?? []);
$activePage = 'orcamentos';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$cssVer = @filemtime(__DIR__ . '/assets/ff-orcamentos.css') ?: 1;
$jsVer  = @filemtime(__DIR__ . '/assets/ff-orcamentos.js') ?: 1;
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Orçamentos</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <link rel="stylesheet" href="/assets/ff-orcamentos.css?v=<?= $cssVer ?>">
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
            <h2 class="page-header-title">Orçamentos</h2>
            <p class="page-header-subtitle"><?= $orcVeiculo
                ? 'Serviços e débitos do veículo num orçamento só. O cliente vê as taxas e o honorário; o controle do escritório fica aqui.'
                : 'Os serviços do cliente num orçamento só. O cliente vê o que é cobrado; o controle interno (taxas e custos) fica aqui.' ?></p>
          </div>
          <div class="page-header-actions">
            <button id="orcNovo" type="button" class="orc-btn orc-btn-principal">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Novo orçamento
            </button>
          </div>
        </div>
      </div>

      <div id="orcApp" class="orc"></div>
    </section>
  </div>
</main>

<script>
window.FF_ORC = { csrf: <?= json_encode($csrf) ?>, api: '/api/crm_orcamentos.php', veiculo: <?= $orcVeiculo ? 'true' : 'false' ?> };
</script>
<script src="/assets/ff-orcamentos.js?v=<?= $jsVer ?>"></script>
</body>
</html>
