<?php
/**
 * head.php, conteúdo do <head> da landing v2.
 *
 * Reaproveita includes/seo_head.php (mesmo SEO da v1: charset, viewport,
 * title, description, canonical, OG, Twitter, favicons, fonts Inter/Poppins
 * e os schemas JSON-LD passados em $SEO['jsonld']). Em seguida adiciona o que
 * é específico da v2: o swap no-js→js, as fontes display "alma romana"
 * (Cinzel/Cormorant), o CSS da v2 e o import map das libs de animação.
 *
 * Espera as variáveis $SEO (montada no index.php) e $cssVer.
 */
require __DIR__ . '/../../includes/seo_head.php';
?>

  <!-- Marca <html class="js"> ANTES do CSS da v2: o estado oculto do
       .lp2-reveal só se aplica com JS (resiliência no-JS + LCP do hero). -->
  <script>(function(){var d=document.documentElement;d.classList.replace('no-js','js');if(/[?&]still/.test(location.search))d.classList.add('lp2-still');})();</script>

  <!-- Fontes display: Cinzel (capitais romanas) + Cormorant (serif elegante).
       Inter/Poppins e os preconnects já vêm do seo_head.php. -->
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500&display=swap" rel="stylesheet">

  <!-- CSS da v2 (escopo body.lp2, isolado da v1) -->
  <link rel="stylesheet" href="/assets/v2/css/index.css?v=<?= $cssVer ?>">
  <link rel="stylesheet" href="/assets/v2/css/motion.css?v=<?= $cssVer ?>">
  <link rel="stylesheet" href="/assets/v2/css/demos.css?v=<?= $cssVer ?>">

  <!-- Libs de animação sem build: resolvidas pelo <script type="module"> no fim do body. -->
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  <script type="importmap">
  {
    "imports": {
      "gsap": "https://cdn.jsdelivr.net/npm/gsap@3.12.5/index.js",
      "gsap/ScrollTrigger": "https://cdn.jsdelivr.net/npm/gsap@3.12.5/ScrollTrigger.js",
      "motion": "https://cdn.jsdelivr.net/npm/motion@11.11.13/+esm",
      "lenis": "https://cdn.jsdelivr.net/npm/lenis@1.1.18/dist/lenis.mjs"
    }
  }
  </script>
