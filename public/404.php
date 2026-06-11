<?php
/**
 * 404.php — página de erro brandada do Yuris.
 *
 * Ativação:
 *   · Produção: ErrorDocument 404 /404.php no conf do Apache (Dockerfile zz-yuris.conf).
 *   · XAMPP local: ErrorDocument no .htaccess da raiz.
 * http_response_code(404) garante o status correto mesmo acessada direto.
 */
http_response_code(404);
require_once __DIR__ . '/includes/lp_helpers.php';

$cssVerLp = @filemtime(__DIR__ . '/assets/landing.css')   ?: 1;
$cssVerSp = @filemtime(__DIR__ . '/assets/seo-pages.css') ?: 1;

$SEO = [
    'title'       => 'Página não encontrada — Yuris',
    'description' => 'A página que você procura não existe ou mudou de endereço. Veja os caminhos mais usados do Yuris.',
    'path'        => '/404.php',
    'robots'      => 'noindex,follow',
    'css'         => [
        '/assets/landing.css?v=' . $cssVerLp,
        '/assets/seo-pages.css?v=' . $cssVerSp,
    ],
];
?><!doctype html>
<html lang="pt-BR">
<head>
<?php require __DIR__ . '/includes/seo_head.php'; ?>
</head>
<body class="lp">

<header class="lp-header" role="banner">
  <div class="lp-container lp-header-inner">
    <a href="/" class="lp-logo" aria-label="Yuris — Sistema Jurídico Inteligente">
      <img src="/assets/img/logo-144.webp" alt="" width="36" height="36">
      <span class="lp-logo-text">YURIS</span>
    </a>
    <div class="lp-header-cta">
      <a href="/login.php" class="lp-btn lp-btn-ghost">Entrar</a>
      <a href="<?= wa('Olá Bruno, quero uma demonstração do Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Solicitar demonstração
      </a>
    </div>
  </div>
</header>

<main>
<section class="sp-hero">
  <div class="lp-container">
    <span class="lp-eyebrow">Erro 404</span>
    <h1>Essa página não foi encontrada.</h1>
    <p class="sp-lede">O endereço pode ter mudado ou nunca existiu por aqui. Sem problema — estes são os caminhos mais úteis do Yuris:</p>
  </div>
</section>

<section class="sp-relacionados" aria-label="Páginas principais">
  <div class="lp-container">
    <div class="lp-grid lp-grid-3">
      <a class="lp-card" href="/">
        <h3>Início</h3>
        <p>Conheça o Yuris — Sistema Jurídico Inteligente para advogados e escritórios.</p>
      </a>
      <a class="lp-card" href="/sistema-juridico/">
        <h3>Sistema Jurídico</h3>
        <p>O que o Yuris centraliza: processos, prazos, intimações, CRM e financeiro.</p>
      </a>
      <a class="lp-card" href="/planos.php">
        <h3>Planos</h3>
        <p>Preços públicos, tudo incluído, a partir de R$ 220/mês.</p>
      </a>
      <a class="lp-card" href="/crm-juridico/">
        <h3>CRM Jurídico</h3>
        <p>Funil comercial em Kanban, do lead ao processo.</p>
      </a>
      <a class="lp-card" href="/demonstracao/">
        <h3>Demonstração</h3>
        <p>Veja o sistema funcionando com a rotina do seu escritório.</p>
      </a>
      <a class="lp-card" href="/sobre/">
        <h3>Sobre o Yuris</h3>
        <p>Quem é o Yuris e os pilares que orientam o produto.</p>
      </a>
    </div>
  </div>
</section>
</main>

<?php require __DIR__ . '/includes/lp_footer.php'; ?>

</body>
</html>
