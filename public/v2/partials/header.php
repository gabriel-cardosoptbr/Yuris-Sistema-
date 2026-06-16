<?php
/**
 * header.php, header fixo + drawer mobile da v2 (classes lp2-).
 * Âncoras e links preservados da v1. Usa wa() + $waSvg do escopo do index.php.
 */
$ctaMsg = $content['cta_demo'];

/* Links de navegação (âncoras + páginas), idênticos à v1. */
$nav = [
    ['#inicio', 'Início'],
    ['#recursos', 'Recursos'],
    ['#juridico', 'Jurídico'],
    ['#automacao', 'Automação'],
    ['#seguranca', 'Segurança'],
    ['/planos.php', 'Planos'],
    ['#demonstracao', 'Demonstração'],
];
?>
<header class="lp2-header" role="banner">
  <div class="lp2-container lp2-header-inner">
    <a href="#inicio" class="lp2-logo" aria-label="Yuris, Sistema Jurídico Inteligente">
      <img src="/assets/img/logo-144.webp" alt="" width="40" height="40">
      <span class="lp2-logo-text">YURIS</span>
    </a>

    <nav class="lp2-nav" aria-label="Navegação principal">
      <?php foreach ($nav as [$href, $label]): ?>
      <a href="<?= $href ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="lp2-header-cta">
      <a href="/login.php" class="lp2-btn lp2-btn-ghost">Entrar</a>
      <a href="<?= wa($ctaMsg) ?>" target="_blank" rel="noopener" class="lp2-btn lp2-btn-primary lp2-btn-wa">
        <?= $waSvg ?>
        Solicitar demonstração
      </a>
      <button class="lp2-menu-toggle" aria-label="Abrir menu" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
    </div>
  </div>
</header>

<!-- Drawer mobile -->
<div class="lp2-drawer-backdrop" aria-hidden="true"></div>
<aside class="lp2-drawer" aria-label="Menu mobile">
  <button class="lp2-drawer-close" aria-label="Fechar menu" type="button">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>
  <div class="lp2-drawer-title">Navegação</div>
  <?php foreach ($nav as [$href, $label]): ?>
  <a href="<?= $href ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <div class="lp2-drawer-title">Acesso</div>
  <a href="/login.php" class="lp2-btn lp2-btn-ghost">Entrar</a>
  <a href="<?= wa($ctaMsg) ?>" target="_blank" rel="noopener" class="lp2-btn lp2-btn-primary lp2-btn-wa">
    <?= $waSvg ?>
    Solicitar demonstração
  </a>
</aside>
