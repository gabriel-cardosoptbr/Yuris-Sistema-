<?php
/**
 * head.php, conteúdo do <head> da home.
 *
 * Reaproveita includes/seo_head.php (title, description, canonical, OG, Twitter,
 * favicons e os schemas JSON-LD de $SEO['jsonld']). As folhas e a fonte Manrope
 * vêm do próprio $SEO, montado no index.php com ys_css().
 *
 * Espera a variável $SEO.
 */
require __DIR__ . '/../../includes/seo_head.php';
?>

  <!-- Marca <html class="js"> ANTES do CSS: o estado oculto do .ys-reveal só se
       aplica com JS, então sem JS o conteúdo aparece inteiro. -->
  <script>document.documentElement.classList.replace('no-js','js');</script>
