<?php
/**
 * secao-01-hero.php, #inicio
 *
 * Composição assimétrica: texto à esquerda; à direita um painel azul-marinho
 * com linhas de arquitetura (a "estrutura" do H1: arcos e colunas), a janela do
 * sistema saindo do painel e quatro marcadores em posições fixas. É uma peça
 * só, não um dashboard ao lado de um título.
 *
 * Os números são os mesmos da versão anterior, DE DEMONSTRAÇÃO, e a legenda diz
 * isso. Sem .ys-reveal de propósito: a primeira dobra pinta sem esperar o JS.
 */
$h = $content['hero'];

/* Ícones pareados por índice com hero.trust do content.php. */
$trustIcons = [
    '<path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/>',
    '<circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/>',
    '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
];

/* Ícones dos marcadores, pareados com hero.chips. */
$chipIcons = [
    '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
];

/* Barras do gráfico da janela: as mesmas alturas relativas do mockup anterior. */
$barras = [70, 95, 108, 130, 88, 115, 140, 100];

/* Foto do hero: entra sozinha quando o arquivo existir; sem ele, fica o painel. */
$temFoto = is_file(__DIR__ . '/../../assets/site/img/hero.webp');
?>
<section id="inicio" class="ys-hero">
  <div class="ys-container">
    <div class="ys-hero-grid">

      <div class="ys-hero-text">
        <span class="ys-eyebrow"><?= htmlspecialchars($h['eyebrow'], ENT_QUOTES, 'UTF-8') ?></span>
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

        <div class="ys-win" aria-hidden="true">
          <div class="ys-win-bar"><i></i><i></i><i></i><span>Yuris · Painel</span></div>
          <div class="ys-win-body">
            <div class="ys-win-side">
              <b></b><u></u><u class="on"></u><u></u><u></u><u></u><u></u>
            </div>
            <div class="ys-win-main">
              <div class="ys-win-kpis">
                <div><small>Processos</small><strong>38</strong></div>
                <div class="on"><small>Prazos hoje</small><strong>12</strong></div>
                <div><small>Intimações</small><strong>7</strong></div>
              </div>
              <div class="ys-win-lower">
                <div class="ys-win-chart">
                  <?php foreach ($barras as $i => $b): ?>
                  <span style="height:<?= round($b / 140 * 100) ?>%"<?= in_array($i, [3, 6], true) ? ' class="on"' : '' ?>></span>
                  <?php endforeach; ?>
                </div>
                <ul class="ys-win-list">
                  <li><i class="a"></i><span></span></li>
                  <li><i class="b"></i><span></span></li>
                  <li><i class="c"></i><span></span></li>
                  <li><i class="a"></i><span></span></li>
                </ul>
              </div>
            </div>
          </div>
        </div>

        <div class="ys-hero-chips" aria-hidden="true">
          <?php foreach ($h['chips'] as $i => $chip): ?>
          <div class="ys-chip ys-chip-<?= $i + 1 ?>">
            <span class="ys-chip-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $chipIcons[$i] ?? '' ?></svg></span>
            <span><?= $chip['n'] !== '' ? $chip['n'] . ' ' : '' ?><?= $chip['label'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <p class="ys-hero-note">Dados de demonstração</p>
      </div>

    </div>

    <ul class="ys-strip">
      <?php foreach ($h['trust'] as $i => $t): ?>
      <li>
        <span class="ys-strip-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $trustIcons[$i] ?? '' ?></svg></span>
        <?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
