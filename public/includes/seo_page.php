<?php
/**
 * seo_page.php — layout das páginas públicas de marketing/SEO do Yuris
 * (/sistema-juridico/, /crm-juridico/, /sobre/ …).
 *
 * Mesmo padrão do legal_page.php: a página define um array e dá require.
 * Visual: design system do site (assets/site/site.css, body.ys).
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
 *     'cena'        => 'operacao',                 // opcional: cena do hero (ver $cenasPorPagina)
 *   ];
 *   require __DIR__ . '/../includes/seo_page.php';
 *
 * Schemas automáticos: BreadcrumbList + (se houver 'faq') FAQPage.
 * FAQ renderizada com <details>/<summary> — rastreável e sem JS.
 *
 * Duas coisas que o layout monta sozinho, sem conteúdo novo:
 *   - a cena de produto do hero, escolhida pelo endereço da página;
 *   - o índice lateral "Nesta página", tirado dos <h2> do próprio corpo_html.
 */

require_once __DIR__ . '/site_chrome.php';      // wa(), $waSvg, ys_header(), ys_footer(), ys_css()
require_once __DIR__ . '/../v2/partials/_cenas.php';   // lp2_scene(): dados fictícios

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

/* Cena de produto do hero, por página. Sobre e blog ficam só com o texto. */
$cenasPorPagina = [
    '/sistema-juridico/'           => 'tab-dashboard',
    '/crm-juridico/'               => 'operacao',
    '/gestao-escritorio-advocacia/' => 'gestao',
    '/automacao-juridica/'         => 'automacoes',
    '/controle-de-processos/'      => 'juridico',
    '/prospeccao-juridica/'        => 'tab-tarefas',
    '/financeiro-juridico/'        => 'dashboards',
    '/lgpd-escritorios-advocacia/' => 'vinculos',
    '/demonstracao/'               => 'comunicacao',
];
$pgCena = $SEO_PAGE['cena'] ?? ($cenasPorPagina[$pgPath] ?? null);

/* Índice lateral: ids nos <h2> do corpo. Sem casar nada, o corpo fica como veio. */
$pgToc = [];
$corpoComIds = preg_replace_callback('~<h2>(.*?)</h2>~s', static function (array $m) use (&$pgToc): string {
    $id = 'secao-' . (count($pgToc) + 1);
    $pgToc[] = [$id, trim(strip_tags($m[1]))];
    return '<h2 id="' . $id . '">' . $m[1] . '</h2>';
}, $pgCorpo);
if ($corpoComIds !== null) {
    $pgCorpo = $corpoComIds;
} else {
    $pgToc = [];
}
$temToc = count($pgToc) >= 3;

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
$SEO = [
    'title'       => $SEO_PAGE['title']       ?? ($pgH1 . ' | Yuris'),
    'description' => $SEO_PAGE['description'] ?? $pgLede,
    'path'        => $pgPath,
    'robots'      => $SEO_PAGE['robots']      ?? 'index,follow',
    'og_image'    => $SEO_PAGE['og_image']    ?? '/assets/img/og-image.jpg',
    'fonts'       => YS_FONT_URL,
    'theme_color' => '#102A46',
    'css'         => ys_css($pgCena ? ['site-product.css'] : []),
    'jsonld'      => $pgJsonld,
];
?><!doctype html>
<html lang="pt-BR" class="no-js">
<head>
<?php require __DIR__ . '/seo_head.php'; ?>
  <script>document.documentElement.classList.replace('no-js','js');</script>
</head>
<body class="ys">

<?php
ys_header([
    'ctaMsg'   => $pgCtaMsg,
    'ctaLabel' => $pgCtaLabel,
    'drawer'   => ys_drawer_paginas(),
]);
?>

<main id="conteudo">

<section class="ys-pagehero<?= $pgPath === '/sobre/' ? ' is-sobre' : '' ?>">
  <div class="ys-container">
    <div class="ys-pagehero-grid<?= $pgCena ? ' has-art' : '' ?>">
      <div>
        <nav class="ys-breadcrumb" aria-label="Você está aqui">
          <ol>
            <li><a href="/">Início</a></li>
            <li aria-current="page"><?= $h($pgBreadcrumb) ?></li>
          </ol>
        </nav>
        <?php if ($pgEyebrow): ?><span class="ys-eyebrow"><?= $h($pgEyebrow) ?></span><?php endif; ?>
        <h1><?= $h($pgH1) ?></h1>
        <?php if ($pgLede): ?><p class="ys-lede"><?= $h($pgLede) ?></p><?php endif; ?>
        <div class="ys-cta-row">
          <?= ys_wa_btn($pgCtaMsg, $pgCtaLabel) ?>
          <a href="/planos" class="ys-btn ys-btn-ghost">Ver planos</a>
        </div>
      </div>
      <?php if ($pgCena): ?>
      <div class="ys-pagehero-art">
        <?= lp2_scene($pgCena) ?>
        <p class="ys-note">Dados de demonstração</p>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<article class="ys-article">
  <div class="ys-container<?= $temToc ? ' has-toc' : '' ?>">
    <div class="ys-article-grid<?= $temToc ? ' has-toc' : '' ?>">
      <?php if ($temToc): ?>
      <nav class="ys-toc" aria-label="Nesta página">
        <strong>Nesta página</strong>
        <ol>
          <?php foreach ($pgToc as [$id, $titulo]): ?>
          <li><a href="#<?= $id ?>"><?= $h($titulo) ?></a></li>
          <?php endforeach; ?>
        </ol>
      </nav>
      <?php endif; ?>

      <div>
        <div class="ys-prose"><?= $pgCorpo ?></div>

        <?php if ($pgFaq): ?>
        <section class="ys-pagefaq" aria-label="Perguntas frequentes">
          <h2>Perguntas frequentes</h2>
          <div class="ys-faq">
            <?php foreach ($pgFaq as $item): ?>
            <details>
              <summary><?= $h($item['q']) ?></summary>
              <div class="ys-faq-a"><p><?= $h($item['a']) ?></p></div>
            </details>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endif; ?>
      </div>
    </div>
  </div>
</article>

<?php if ($pgRel): ?>
<section class="ys-related" aria-label="Conteúdo relacionado">
  <div class="ys-container">
    <h2>Veja também</h2>
    <div class="ys-related-grid">
      <?php foreach ($pgRel as $rel): ?>
      <a href="<?= $h($rel['href']) ?>">
        <h3><?= $h($rel['titulo']) ?></h3>
        <p><?= $h($rel['desc']) ?></p>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="ys-cta-wrap">
  <div class="ys-container">
    <div class="ys-cta">
      <span class="ys-eyebrow">Vamos conversar</span>
      <h2><?= $h($pgCtaTitulo) ?></h2>
      <p><?= $h($pgCtaTexto) ?></p>
      <div class="ys-cta-row">
        <?= ys_wa_btn($pgCtaMsg, $pgCtaLabel) ?>
        <a href="/planos" class="ys-btn ys-btn-ghost">Conhecer os planos</a>
      </div>
    </div>
  </div>
</section>

</main>

<?php ys_footer(); ?>

<script src="/assets/site/site.js?v=<?= ys_asset_ver('assets/site/site.js') ?>" defer></script>
</body>
</html>
