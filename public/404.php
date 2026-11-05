<?php
/**
 * 404.php — página de erro brandada do Yuris.
 *
 * Ativação:
 *   · Produção: ErrorDocument 404 /404.php no conf do Apache (Dockerfile zz-yuris.conf).
 *   · XAMPP local: ErrorDocument no .htaccess da raiz.
 * http_response_code(404) garante o status correto mesmo acessada direto.
 *
 * Visual: design system do site (assets/site/site.css, body.ys). A classe
 * ys-article nas duas seções dá a largura de 880 px, que acomoda os seis
 * atalhos em três colunas e alinha o texto com eles.
 */
http_response_code(404);
require_once __DIR__ . '/includes/site_chrome.php';   // wa(), $waSvg, ys_header(), ys_footer(), ys_css()

$SEO = [
    'title'       => 'Página não encontrada — Yuris',
    'description' => 'A página que você procura não existe ou mudou de endereço. Veja os caminhos mais usados do Yuris.',
    'path'        => '/404.php',
    'robots'      => 'noindex,follow',
    'fonts'       => YS_FONT_URL,
    'theme_color' => '#102A46',
    'css'         => ys_css(),
];
?><!doctype html>
<html lang="pt-BR">
<head>
<?php require __DIR__ . '/includes/seo_head.php'; ?>
</head>
<body class="ys">

<?php ys_header(['drawer' => ys_drawer_paginas()]); ?>

<main id="conteudo">
<section class="ys-pagehero ys-article">
  <div class="ys-container">
    <span class="ys-eyebrow">Erro 404</span>
    <h1>Essa página não foi encontrada.</h1>
    <p class="ys-lede">O endereço pode ter mudado ou nunca existiu por aqui. Sem problema — estes são os caminhos mais úteis do Yuris:</p>
  </div>
</section>

<section class="ys-article ys-related" aria-label="Páginas principais">
  <div class="ys-container">
    <div class="ys-related-grid">
      <a href="/">
        <h3>Início</h3>
        <p>Conheça o Yuris — Sistema Jurídico Inteligente para advogados e escritórios.</p>
      </a>
      <a href="/sistema-juridico/">
        <h3>Sistema Jurídico</h3>
        <p>O que o Yuris centraliza: processos, prazos, intimações, CRM e financeiro.</p>
      </a>
      <a href="/planos">
        <h3>Planos</h3>
        <p>Tudo incluído, sem cobrança por módulo. Valor sob consulta.</p>
      </a>
      <a href="/crm-juridico/">
        <h3>CRM Jurídico</h3>
        <p>Funil comercial em Kanban, do lead ao processo.</p>
      </a>
      <a href="/demonstracao/">
        <h3>Demonstração</h3>
        <p>Veja o sistema funcionando com a rotina do seu escritório.</p>
      </a>
      <a href="/sobre/">
        <h3>Sobre o Yuris</h3>
        <p>Quem é o Yuris e os pilares que orientam o produto.</p>
      </a>
    </div>
  </div>
</section>
</main>

<?php ys_footer(); ?>

<script src="/assets/site/site.js?v=<?= ys_asset_ver('assets/site/site.js') ?>" defer></script>
</body>
</html>
