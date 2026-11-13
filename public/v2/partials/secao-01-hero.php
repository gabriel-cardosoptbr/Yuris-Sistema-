<?php
/**
 * secao-01-hero.php, #inicio
 *
 * Composição assimétrica: texto à esquerda; à direita um painel azul-marinho
 * com linhas de arquitetura (a "estrutura" do H1: arcos e colunas), a janela do
 * sistema saindo do painel. É uma peça só, não um dashboard ao lado de um título.
 *
 * Sem legenda com tracinho acima do H1, sem etiquetas com ícone flutuando na foto,
 * sem as três bolinhas de janela e sem ícone em quadradinho na faixa de garantias
 * (13/11/2026: "isso aí é tudo cara de IA").
 *
 * Os números são os mesmos da versão anterior, DE DEMONSTRAÇÃO, e a legenda diz
 * isso. Sem .ys-reveal de propósito: a primeira dobra pinta sem esperar o JS.
 */
$h = $content['hero'];

/* Foto do hero: entra sozinha quando o arquivo existir; sem ele, fica o painel. */
$temFoto = is_file(__DIR__ . '/../../assets/site/img/hero.webp');
?>
<section id="inicio" class="ys-hero">
  <div class="ys-container">
    <div class="ys-hero-grid">

      <div class="ys-hero-text">
        <h1 class="ys-h1"><?= str_replace(['<em>', '</em>'], ['<em class="ys-hl">', '</em>'], $h['h1_html']) ?></h1>
        <p class="ys-lede"><?= htmlspecialchars($h['sub'], ENT_QUOTES, 'UTF-8') ?></p>

        <div class="ys-cta-row">
          <?= ys_wa_btn($content['cta_demo'], $h['cta_primary_label']) ?>
          <a href="<?= htmlspecialchars($h['cta_ghost_href'], ENT_QUOTES, 'UTF-8') ?>" class="ys-btn ys-btn-ghost">
            <?= htmlspecialchars($h['cta_ghost_label'], ENT_QUOTES, 'UTF-8') ?>
          </a>
        </div>
      </div>

      <div class="ys-hero-art<?= $temFoto ? ' has-photo' : '' ?>">
        <?php if ($temFoto): ?>
        <img class="ys-hero-photo" src="/assets/site/img/hero.webp?v=<?= ys_asset_ver('assets/site/img/hero.webp') ?>" alt="" width="1280" height="1440" fetchpriority="high">
        <?php endif; ?>
        <!-- Linhas de arquitetura: arcos e colunas, a estrutura da marca. -->
        <svg class="ys-hero-lines" viewBox="0 0 640 720" fill="none" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
          <g stroke="#fff" stroke-opacity=".13" stroke-width="1.2">
            <path d="M40 720V300a140 140 0 0 1 280 0v420"/>
            <path d="M84 720V300a96 96 0 0 1 192 0v420"/>
            <path d="M320 720V300a140 140 0 0 1 280 0v420"/>
            <path d="M364 720V300a96 96 0 0 1 192 0v420"/>
            <line x1="0" y1="120" x2="640" y2="120"/>
            <line x1="0" y1="96" x2="640" y2="96"/>
          </g>
        </svg>

      </div>

    </div>

    <ul class="ys-strip">
      <?php foreach ($h['trust'] as $t): ?>
      <li><?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
