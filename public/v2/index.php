<?php
/**
 * /v2/index.php, a home do Yuris (design system "Editorial LegalTech", assets/site/).
 *
 * A v1 (../index.php) permanece 100% intacta. Esta versão vive em /v2/ como
 * preview (noindex) e será "promovida" para a raiz quando aprovada, bastará
 * um stub de 1 linha em ../index.php apontando para cá (ver plano).
 *
 * Arquitetura: orquestrador fino. Monta $SEO + faz require dos partials na
 * ordem das seções. Toda a copy e os schemas vêm de data/content.php.
 */

require_once __DIR__ . '/../includes/site_chrome.php';  // wa(), $waSvg, ys_header(), ys_footer(), ys_css()
require_once __DIR__ . '/partials/_render.php';         // helpers de render (lp2_split, lp2_head, lp2_wa_btn…)
require_once __DIR__ . '/partials/_cenas.php';          // lp2_scene(): ilustrações de produto (nunca a tela real)
$content = require __DIR__ . '/data/content.php';

$jsVer = ys_asset_ver('assets/site/site.js');

/*
 * Preview vs produção (detecta /v2/ na URL):
 *  - Preview (/v2/):     noindex,follow + canonical próprio /v2/ (não compete com a raiz).
 *  - Raiz (promovido):   index,follow   + canonical /.
 * Assim a promoção via stub NÃO exige editar SEO manualmente.
 */
$isPreview = (strpos($_SERVER['REQUEST_URI'] ?? '', '/v2/') !== false);

/* FAQPage derivado do MESMO array que renderiza os <details> visíveis (sem drift). */
$schemas = $content['schemas'];
if (!empty($content['faq'])) {
    $faqItems = [];
    foreach ($content['faq'] as $f) {
        $faqItems[] = [
            '@type'          => 'Question',
            'name'           => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ];
    }
    $schemas[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqItems];
}

$SEO = [
    'title'       => $content['meta']['title'],
    'description' => $content['meta']['description'],
    'path'        => $isPreview ? '/v2/' : '/',
    'robots'      => $isPreview ? 'noindex,follow' : 'index,follow',
    'og_image'    => $content['meta']['og_image'],
    'jsonld'      => $schemas,
    'fonts'       => YS_FONT_URL,
    'theme_color' => '#102A46',
    'css'         => ys_css(['site-home.css']),
    'preload_img' => ['/assets/site/img/hero.webp?v=' . ys_asset_ver('assets/site/img/hero.webp')],
];

/* Ordem das seções. São 13, cada uma com um papel (13/11/2026): a primeira dobra, cinco
   destaques (cada um com a ilustração de um recurso), automações, o antes e depois,
   segurança, para quem, a origem da marca, perguntas e o convite. Os ids antigos continuam
   valendo como endereço. O site nunca mostra a tela real do sistema (ver _cenas.php). */
$secoes = [
    'secao-01-hero',          // #inicio
    'secao-07-juridico',      // #juridico, #recursos, #produto
    'secao-08-intimacoes',    // #intimacoes
    'secao-10-operacao',      // #operacao, #comunicacao, #cliente
    'secao-11-gestao',        // #gestao, #vinculos
    'secao-14-dashboards',    // #dashboards
    'secao-12-automacao',     // #automacao, #integracoes
    'secao-04-dores',         // #dores, #comparativo: sem sistema / com o Yuris
    'secao-13-seguranca',     // #seguranca
    'secao-17-para-quem',     // #para-quem
    'secao-02-origem',        // #origem
    'secao-19-faq',           // #faq
    'secao-20-demonstracao',  // #demonstracao
];
?><!doctype html>
<html lang="pt-BR" class="no-js">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="ys">

<?php require __DIR__ . '/partials/header.php'; ?>

<main id="conteudo">
<?php foreach ($secoes as $sec) { require __DIR__ . "/partials/{$sec}.php"; } ?>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<!-- Sem dependências e sem build; o conteúdo é 100% visível sem JS. -->
<script src="/assets/site/site.js?v=<?= $jsVer ?>" defer></script>
<!-- Cookie consent (mesmo módulo da v1) -->
<script src="/assets/cookie-consent.js?v=3"></script>
</body>
</html>
