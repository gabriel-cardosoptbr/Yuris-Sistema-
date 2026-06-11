<?php
/**
 * seo_head.php — bloco <head> padronizado das páginas PÚBLICAS do Yuris.
 *
 * Centraliza title, description, canonical, Open Graph, Twitter Card,
 * favicons, fonts e JSON-LD. Toda página pública nova deve usar este
 * include em vez de duplicar <head> na mão (páginas legadas migram aos
 * poucos — NÃO é obrigatório refatorar o que já funciona).
 *
 * Uso:
 *   $SEO = [
 *     'title'       => 'CRM Jurídico para Advogados | Yuris',   // <= 60 chars ideal
 *     'description' => 'Texto único e persuasivo (até ~155 chars).',
 *     'path'        => '/crm-juridico/',       // caminho canônico (com / final em pastas)
 *     'robots'      => 'index,follow',          // ou 'noindex,follow'
 *     'og_type'     => 'website',               // opcional (default website)
 *     'og_image'    => '/assets/img/og-image.jpg', // opcional (default imagem da marca)
 *     'css'         => ['/assets/landing.css'], // opcional: folhas extras além do padrão
 *     'jsonld'      => [ [...], [...] ],        // opcional: array de schemas (1 <script> cada)
 *   ];
 *   require __DIR__ . '/../includes/seo_head.php';   // dentro de <head>…</head>
 *
 * IMPORTANTE: este include emite apenas as TAGS internas do <head>.
 * O <!doctype>, <html lang="pt-BR">, <head> e <body> ficam na página.
 */

/** Domínio canônico público do Yuris.
 *  yuris.inovaize.com segue ativo, mas o canônico de SEO é o yuris.com.br
 *  (decisão 2026-06-11 — quando o 301 definitivo for feito, nada muda aqui). */
const YURIS_BASE_URL = 'https://yuris.com.br';

if (!isset($SEO) || !is_array($SEO)) {
    $SEO = [];
}

$seoTitle  = $SEO['title']       ?? 'Yuris — Sistema Jurídico Inteligente';
$seoDesc   = $SEO['description'] ?? 'Sistema jurídico para advogados e escritórios: processos, prazos, intimações, CRM, financeiro e WhatsApp em um só lugar.';
$seoPath   = $SEO['path']        ?? '/';
$seoRobots = $SEO['robots']      ?? 'index,follow';
$seoOgType = $SEO['og_type']     ?? 'website';
$seoOgImg  = $SEO['og_image']    ?? '/assets/img/og-image.jpg';
$seoUrl    = YURIS_BASE_URL . $seoPath;
$seoOgImgAbs = (strpos($seoOgImg, 'http') === 0) ? $seoOgImg : YURIS_BASE_URL . $seoOgImg;

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $h($seoTitle) ?></title>
  <meta name="description" content="<?= $h($seoDesc) ?>">
  <meta name="robots" content="<?= $h($seoRobots) ?>">
  <link rel="canonical" href="<?= $h($seoUrl) ?>">
  <meta name="theme-color" content="#070F1C">

  <meta property="og:type" content="<?= $h($seoOgType) ?>">
  <meta property="og:site_name" content="Yuris">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:url" content="<?= $h($seoUrl) ?>">
  <meta property="og:title" content="<?= $h($seoTitle) ?>">
  <meta property="og:description" content="<?= $h($seoDesc) ?>">
  <meta property="og:image" content="<?= $h($seoOgImgAbs) ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= $h($seoTitle) ?>">
  <meta name="twitter:description" content="<?= $h($seoDesc) ?>">
  <meta name="twitter:image" content="<?= $h($seoOgImgAbs) ?>">

  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
<?php foreach (($SEO['css'] ?? []) as $cssHref): ?>
  <link rel="stylesheet" href="<?= $h($cssHref) ?>">
<?php endforeach; ?>
<?php foreach (($SEO['jsonld'] ?? []) as $schema): ?>
  <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endforeach; ?>
