<?php
/**
 * A página das Visões Gerais dos ambientes (Empresa, Software, Digital). Incluída
 * pelas páginas finas de public/ (empresa.php, software.php, digital.php), que
 * definem $ambientePagina antes. O Comercial usa o painel de sempre (/dashboard).
 *
 * Guarda no servidor (Ambiente::exigir): sem login vai para o login; sem o módulo
 * ou sem permissão para o ambiente, para o painel. Os números vêm de
 * /api/ambientes.php e /api/digital.php, que conferem a mesma regra.
 */
require_once __DIR__ . '/../../bootstrap.php';
session_start();

$ambientePagina = $ambientePagina ?? 'empresa';
$ctxAmb = \App\Ambientes\Ambiente::exigir($ambientePagina);
$infoAmb = \App\Ambientes\Ambiente::LISTA[$ambientePagina];
$verAmb = (string) ($_GET['ver'] ?? '');
if ($ambientePagina !== 'digital' || !in_array($verAmb, ['entregas', 'campanhas'], true)) $verAmb = '';
$activePage = $verAmb !== '' ? 'digital_' . $verAmb : $infoAmb['ativo'];
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$titulos = [
    'empresa'  => ['Visão Geral da Empresa', 'Toda a Inovaize num lugar: receita, clientes, vendas, projetos, entregas, atrasos e pendências.'],
    'software' => ['Visão Geral de Software', 'Projetos em andamento, responsáveis, próximos prazos e pendências da operação de Software.'],
    'digital'  => ['Visão Geral de Digital / Marketing', 'A operação por cliente: entregas do mês, previsto x realizado, aprovações, materiais e campanhas.'],
];
[$tituloAmb, $subtituloAmb] = $titulos[$ambientePagina];
if ($verAmb === 'entregas') { $tituloAmb = 'Entregas'; $subtituloAmb = 'Cada entrega do mês com produção, aprovação, entrega e publicação. A contagem é de entregas, não de itens de checklist.'; }
if ($verAmb === 'campanhas') { $tituloAmb = 'Campanhas'; $subtituloAmb = 'Campanhas de tráfego pago por cliente: plataforma, verba, período e situação.'; }
$veAmb = @filemtime(__DIR__ . '/../../../public/assets/ambientes-visao.js') ?: 1;
$vcAmb = @filemtime(__DIR__ . '/../../../public/assets/ambientes.css') ?: 1;
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($tituloAmb) ?></title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=20">
  <link rel="stylesheet" href="/assets/ff-contratos.css?v=<?= @filemtime(__DIR__ . '/../../../public/assets/ff-contratos.css') ?: 1 ?>">
  <link rel="stylesheet" href="/assets/ambientes.css?v=<?= $vcAmb ?>">
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
    <?php include __DIR__ . '/../../../public/includes/sidebar.php'; ?>

    <section class="main-content">
      <div class="card-shell page-header" style="margin-bottom:16px;flex-shrink:0;">
        <div class="page-header-inner">
          <div class="page-header-text">
            <h2 class="page-header-title"><?= htmlspecialchars($tituloAmb) ?></h2>
            <p class="page-header-subtitle"><?= htmlspecialchars($subtituloAmb) ?></p>
          </div>
          <div class="page-header-actions" id="ambAcoes"></div>
        </div>
      </div>

      <div id="ambApp" class="ctr amb" aria-live="polite"><div class="amb-carregando">Carregando…</div></div>
    </section>
  </div>
</main>

<script>
window.AMB = <?= json_encode([
    'ambiente' => $ambientePagina, 'ver' => $verAmb, 'csrf' => $csrf,
    'mes' => preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mes'] ?? '')) ? (string) $_GET['mes'] : date('Y-m'),
    'admin' => $ctxAmb->isOwnerOrAdmin(),
    'pode' => ['tarefas' => \App\Ambientes\Ambiente::podePagina('tarefas'), 'clientes' => \App\Ambientes\Ambiente::podePagina('clientes'),
               'financas' => \App\Ambientes\Ambiente::podePagina('financas'), 'contratos' => \App\Contratos\Permissao::podeVer()],
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php if ($verAmb === ''): /* gráficos das Visões Gerais, o mesmo Chart.js do painel comercial */ ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="/assets/ambientes-visao.js?v=<?= $veAmb ?>"></script>
</body>
</html>
