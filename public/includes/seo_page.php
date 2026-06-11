<?php
/**
 * seo_page.php — layout das páginas públicas de marketing/SEO do Yuris
 * (/sistema-juridico/, /crm-juridico/, /sobre/ …).
 *
 * Mesmo padrão do legal_page.php: a página define um array e dá require.
 * Visual idêntico à landing (landing.css + seo-pages.css, body.lp).
 *
 * Uso (ex.: public/crm-juridico/index.php):
 *   $SEO_PAGE = [
 *     'title'       => 'CRM Jurídico para Advogados | Yuris',
 *     'description' => 'Meta description única (~155 chars).',
 *     'path'        => '/crm-juridico/',          // canônico, com / final
 *     'breadcrumb'  => 'CRM Jurídico',
 *     'eyebrow'     => 'CRM Jurídico',             // rótulo acima do H1
 *     'h1'          => 'CRM jurídico para captar e organizar clientes',
 *     'lede'        => 'Resposta direta de 1-3 frases (bloco AEO).',
 *     'corpo_html'  => '<h2>…</h2><p>…</p>',       // conteúdo principal
 *     'faq'         => [ ['q' => '…?', 'a' => 'resposta 40-80 palavras'], … ],
 *     'relacionados'=> [ ['href'=>'/automacao-juridica/','titulo'=>'…','desc'=>'…'], … ],
 *     'cta_msg'     => 'Olá Bruno, quero conhecer o CRM do Yuris!',
 *     'cta_label'   => 'Solicitar demonstração',
 *     'cta_titulo'  => 'Pronto para organizar a captação do seu escritório?',
 *     'cta_texto'   => 'Frase de apoio do CTA final.',
 *     'jsonld'      => [ [...] ],                  // schemas EXTRAS (Service etc.)
 *     'robots'      => 'index,follow',             // opcional
 *   ];
 *   require __DIR__ . '/../includes/seo_page.php';
 *
 * Schemas automáticos: BreadcrumbList + (se houver 'faq') FAQPage.
 * FAQ renderizada com <details>/<summary> — rastreável e sem JS.
 */

require_once __DIR__ . '/lp_helpers.php';

if (!isset($SEO_PAGE) || !is_array($SEO_PAGE)) {
    http_response_code(500);
    exit('seo_page.php: var $SEO_PAGE não definida');
}

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$pgPath       = $SEO_PAGE['path']        ?? '/';
$pgBreadcrumb = $SEO_PAGE['breadcrumb']  ?? ($SEO_PAGE['h1'] ?? 'Página');
$pgEyebrow    = $SEO_PAGE['eyebrow']     ?? '';
$pgH1         = $SEO_PAGE['h1']          ?? 'Yuris';
$pgLede       = $SEO_PAGE['lede']        ?? '';
$pgCorpo      = $SEO_PAGE['corpo_html']  ?? '';
$pgFaq        = $SEO_PAGE['faq']         ?? [];
$pgRel        = $SEO_PAGE['relacionados'] ?? [];
$pgCtaMsg     = $SEO_PAGE['cta_msg']     ?? 'Olá Bruno, quero uma demonstração do Yuris!';
$pgCtaLabel   = $SEO_PAGE['cta_label']   ?? 'Solicitar demonstração';
$pgCtaTitulo  = $SEO_PAGE['cta_titulo']  ?? 'Pronto para conhecer o Yuris de perto?';
$pgCtaTexto   = $SEO_PAGE['cta_texto']   ?? 'Fale com a equipe e veja o sistema funcionando com a rotina do seu escritório.';

/* ── Schemas automáticos ── */
$pgJsonld = [];

$pgJsonld[] = [
    '@context' => 'https://schema.org',
    '@type'    => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => 'https://yuris.com.br/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $pgBreadcrumb, 'item' => 'https://yuris.com.br' . $pgPath],
    ],
];

if ($pgFaq) {
    $faqItems = [];
    foreach ($pgFaq as $item) {
        $faqItems[] = [
            '@type' => 'Question',
            'name'  => $item['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
        ];
    }
    $pgJsonld[] = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $faqItems,
    ];
}

foreach (($SEO_PAGE['jsonld'] ?? []) as $extra) {
    $pgJsonld[] = $extra;
}

/* ── Monta o $SEO do seo_head.php ── */
$cssVerLp = @filemtime(__DIR__ . '/../assets/landing.css')   ?: 1;
$cssVerSp = @filemtime(__DIR__ . '/../assets/seo-pages.css') ?: 1;
$jsVerLp  = @filemtime(__DIR__ . '/../assets/landing.js')    ?: 1;

$SEO = [
    'title'       => $SEO_PAGE['title']       ?? ($pgH1 . ' | Yuris'),
    'description' => $SEO_PAGE['description'] ?? $pgLede,
    'path'        => $pgPath,
    'robots'      => $SEO_PAGE['robots']      ?? 'index,follow',
    'og_image'    => $SEO_PAGE['og_image']    ?? '/assets/img/og-image.jpg',
    'css'         => [
        '/assets/landing.css?v=' . $cssVerLp,
        '/assets/seo-pages.css?v=' . $cssVerSp,
    ],
    'jsonld'      => $pgJsonld,
];
?><!doctype html>
<html lang="pt-BR">
<head>
<?php require __DIR__ . '/seo_head.php'; ?>
</head>
<body class="lp">

<header class="lp-header" role="banner">
  <div class="lp-container lp-header-inner">
    <a href="/" class="lp-logo" aria-label="Yuris — Sistema Jurídico Inteligente">
      <img src="/assets/img/logo-144.webp" alt="" width="36" height="36">
      <span class="lp-logo-text">YURIS</span>
    </a>

    <nav class="lp-nav" aria-label="Navegação principal">
      <a href="/">Início</a>
      <a href="/sistema-juridico/">Sistema Jurídico</a>
      <a href="/crm-juridico/">CRM</a>
      <a href="/automacao-juridica/">Automação</a>
      <a href="/planos.php">Planos</a>
      <a href="/blog/">Blog</a>
    </nav>

    <div class="lp-header-cta">
      <a href="/login.php" class="lp-btn lp-btn-ghost">Entrar</a>
      <a href="<?= wa($pgCtaMsg) ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        <?= $h($pgCtaLabel) ?>
      </a>
      <button class="lp-menu-toggle" aria-label="Abrir menu" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
    </div>
  </div>
</header>

<!-- Drawer mobile -->
<div class="lp-drawer-backdrop" aria-hidden="true"></div>
<aside class="lp-drawer" aria-label="Menu mobile">
  <button class="lp-drawer-close" aria-label="Fechar menu" type="button">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>
  <div class="lp-drawer-title">Navegação</div>
  <a href="/">Início</a>
  <a href="/sistema-juridico/">Sistema Jurídico</a>
  <a href="/crm-juridico/">CRM Jurídico</a>
  <a href="/automacao-juridica/">Automação Jurídica</a>
  <a href="/controle-de-processos/">Controle de Processos</a>
  <a href="/planos.php">Planos</a>
  <a href="/blog/">Blog</a>
  <div class="lp-drawer-title">Acesso</div>
  <a href="/login.php" class="lp-btn lp-btn-ghost">Entrar</a>
  <a href="<?= wa($pgCtaMsg) ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
    <?= $waSvg ?>
    <?= $h($pgCtaLabel) ?>
  </a>
</aside>

<main>

<section class="sp-hero">
  <div class="lp-container">
    <nav class="sp-breadcrumb" aria-label="Você está aqui">
      <ol>
        <li><a href="/">Início</a></li>
        <li aria-current="page"><?= $h($pgBreadcrumb) ?></li>
      </ol>
    </nav>
    <?php if ($pgEyebrow): ?><span class="lp-eyebrow"><?= $h($pgEyebrow) ?></span><?php endif; ?>
    <h1><?= $h($pgH1) ?></h1>
    <?php if ($pgLede): ?><p class="sp-lede"><?= $h($pgLede) ?></p><?php endif; ?>
    <div class="lp-hero-ctas">
      <a href="<?= wa($pgCtaMsg) ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        <?= $h($pgCtaLabel) ?>
      </a>
      <a href="/planos.php" class="lp-btn lp-btn-ghost">Ver planos</a>
    </div>
  </div>
</section>

<article class="sp-article">
  <div class="lp-container">
    <?= $pgCorpo ?>

    <?php if ($pgFaq): ?>
    <section class="sp-faq" aria-label="Perguntas frequentes">
      <h2>Perguntas frequentes</h2>
      <?php foreach ($pgFaq as $item): ?>
      <details>
        <summary><?= $h($item['q']) ?></summary>
        <div class="sp-faq-resposta"><p><?= $h($item['a']) ?></p></div>
      </details>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
  </div>
</article>

<?php if ($pgRel): ?>
<section class="sp-relacionados" aria-label="Conteúdo relacionado">
  <div class="lp-container">
    <h2>Veja também</h2>
    <div class="lp-grid lp-grid-3">
      <?php foreach ($pgRel as $rel): ?>
      <a class="lp-card" href="<?= $h($rel['href']) ?>">
        <h3><?= $h($rel['titulo']) ?></h3>
        <p><?= $h($rel['desc']) ?></p>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="lp-section">
  <div class="lp-container">
    <div class="lp-cta-final">
      <span class="lp-eyebrow">Vamos conversar</span>
      <h2><?= $h($pgCtaTitulo) ?></h2>
      <p><?= $h($pgCtaTexto) ?></p>
      <div class="lp-hero-ctas">
        <a href="<?= wa($pgCtaMsg) ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
          <?= $waSvg ?>
          <?= $h($pgCtaLabel) ?>
        </a>
        <a href="/planos.php" class="lp-btn lp-btn-ghost">Conhecer os planos</a>
      </div>
    </div>
  </div>
</section>

</main>

<?php require __DIR__ . '/lp_footer.php'; ?>

<script src="/assets/landing.js?v=<?= $jsVerLp ?>" defer></script>
</body>
</html>
