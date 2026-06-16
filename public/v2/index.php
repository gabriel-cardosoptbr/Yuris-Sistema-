<?php
/**
 * /v2/index.php, Landing YURIS v2 (cinematográfica, "alma romana").
 *
 * A v1 (../index.php) permanece 100% intacta. Esta versão vive em /v2/ como
 * preview (noindex) e será "promovida" para a raiz quando aprovada, bastará
 * um stub de 1 linha em ../index.php apontando para cá (ver plano).
 *
 * Arquitetura: orquestrador fino. Monta $SEO + faz require dos partials na
 * ordem das seções. Toda a copy e os schemas vêm de data/content.php.
 */

require_once __DIR__ . '/../includes/lp_helpers.php';   // wa() + $waSvg (com guards)
require_once __DIR__ . '/partials/_render.php';         // helpers de render (lp2_split, lp2_head, lp2_wa_btn…)
require_once __DIR__ . '/partials/_ilustracoes.php';    // lp2_ill() — ilustrações SVG animadas por módulo
require_once __DIR__ . '/partials/_cenas.php';          // lp2_scene(): mini-demonstrações de produto
$content = require __DIR__ . '/data/content.php';

$cssVer = max(@filemtime(__DIR__ . '/../assets/v2/css/index.css') ?: 1, @filemtime(__DIR__ . '/../assets/v2/css/motion.css') ?: 1, @filemtime(__DIR__ . '/../assets/v2/css/demos.css') ?: 1);
$jsVer  = @filemtime(__DIR__ . '/../assets/v2/js/main.js')   ?: 1;

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
];

/* Ordem das seções (IDs preservados da v1). Vão sendo adicionadas conforme
   a construção avança; o hero é a primeira a entrar. */
$secoes = [
    'secao-01-hero',
    'secao-03-produto',
    'secao-04-dores',
    'secao-05-recursos',
    'secao-06-funcionalidades',
    'secao-07-juridico',
    'secao-08-intimacoes',
    'secao-09-comunicacao',
    'secao-10-operacao',
    'secao-11-gestao',
    'secao-11b-vinculos',
    'secao-12-automacao',
    'secao-13-seguranca',
    'secao-14-dashboards',
    'secao-15-integracoes',
    'secao-16-comparativo',
    'secao-17-para-quem',
    'secao-18-confianca',
    'secao-02-origem',
    'secao-19-faq',
    'secao-20-demonstracao',
];
?><!doctype html>
<html lang="pt-BR" class="no-js">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="lp2">

<?php require __DIR__ . '/partials/header.php'; ?>

<main>
<?php foreach ($secoes as $sec) { require __DIR__ . "/partials/{$sec}.php"; } ?>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<!-- Entrypoint ESM (defer implícito), não bloqueia o render; conteúdo é 100% visível sem JS. -->
<script type="module" src="/assets/v2/js/main.js?v=<?= $jsVer ?>"></script>
<!-- Cookie consent (mesmo módulo da v1) -->
<script src="/assets/cookie-consent.js?v=1"></script>
</body>
</html>
