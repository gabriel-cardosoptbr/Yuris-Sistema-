<?php
/**
 * evolution.php: a aba Evolution da edição CRM (pedido da Inovaize, 15/10/2026).
 *
 * O servidor de WhatsApp da Inovaize (Evolution API) como infraestrutura:
 * quantas conexões existem, quais estão de pé, quais estão presas tentando
 * reconectar, de quais projetos, o volume de cada uma, o ritmo por dia e quanto
 * custam. SÓ LEITURA no Evolution: nada aqui conecta, desconecta ou apaga
 * instância. Regra em App\Projetos\Evolution; a tela fala com /api/evolution.php
 * (public/assets/evolution.js) e usa o estilo de assets/projetos.css.
 *
 * Só para a conta com a pasta Projetos ligada (a dona da infraestrutura) e só
 * para administrador: vendedor da edição CRM volta para o painel dele.
 */
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AccountContext;
use App\Projetos\Projeto;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login'); exit; }
$activePage = 'evolution';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if ($ctx->vendedorCrm()) { header('Location: /dashboard'); exit; }
if (!Projeto::habilitado(\App\Master\Account::findById((int) $ctx->getAccountId()) ?? [])) { header('Location: /dashboard'); exit; }
$_evoVer = @filemtime(__DIR__ . '/assets/evolution.js') ?: 1;
$_cssVer = @filemtime(__DIR__ . '/assets/projetos.css') ?: 1;
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Evolution</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <link rel="stylesheet" href="/assets/projetos.css?v=<?= $_cssVer ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
<main class="w-full px-6 py-6">
  <div class="page-layout">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <section class="main-content space-y-4" id="evoPagina">
      <div class="dre-panel page-header"><div class="page-header-inner proj-cab">
        <div class="page-header-text">
          <h2 class="page-header-title">Evolution</h2>
          <p class="page-header-subtitle" id="evoSub">O servidor de WhatsApp: conexões, projetos, volume e custo</p>
        </div>
        <div class="proj-cab-botoes">
          <button type="button" class="btn btn-ghost btn-sm" id="evoCustoBtn">Custo mensal</button>
          <button type="button" class="btn btn-primary btn-sm" id="evoAtualizar">Atualizar agora</button>
        </div>
      </div></div>
      <div id="evoConteudo"><div class="dre-panel proj-carregando">Lendo o Evolution…</div></div>
    </section>
  </div>
</main>

<script src="/assets/evolution.js?v=<?= $_evoVer ?>"></script>
<script src="/assets/fog.js"></script>
</body>
</html>
