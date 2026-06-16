<?php
/**
 * secao-01-hero.php, #inicio
 *
 * Hero "alma romana": rosa dos ventos ao fundo (símbolo de direção do manual),
 * headline em serifada, CTA WhatsApp preservado, trust chips e o mockup de
 * dashboard (SVG inline reaproveitado da v1). SEM .lp2-reveal de propósito:
 * a primeira dobra pinta sem esperar o JS (LCP). A entrada é animada por
 * sections/hero.js via gsap.from (estado final = visível, seguro sem JS).
 */
$h = $content['hero'];

/* Ícones (Feather) pareados por índice com hero.trust do content.php. */
$trustIcons = [
    '<path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/>',
    '<circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/>',
    '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
];

/* Ícones dos chips flutuantes, pareados com hero.chips. */
$chipIcons = [
    '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
];
?>
<section id="inicio" class="lp2-hero">

  <!-- Rosa dos ventos ao fundo (watermark), símbolo de direção do manual de marca. -->
  <div class="lp2-hero-aura" aria-hidden="true">
    <svg class="lp2-compass" viewBox="0 0 200 200" fill="none">
      <defs>
        <polygon id="lp2RayL" points="100,8 104,92 100,100 96,92" fill="currentColor"/>
        <polygon id="lp2RayS" points="100,40 103,93 100,100 97,93" fill="currentColor"/>
      </defs>
      <g stroke="currentColor" stroke-width="0.8" opacity="0.7">
        <circle cx="100" cy="100" r="95"/>
        <circle cx="100" cy="100" r="70"/>
        <circle cx="100" cy="100" r="7"/>
      </g>
      <g>
        <use href="#lp2RayL"/><use href="#lp2RayL" transform="rotate(90 100 100)"/>
        <use href="#lp2RayL" transform="rotate(180 100 100)"/><use href="#lp2RayL" transform="rotate(270 100 100)"/>
        <use href="#lp2RayS" transform="rotate(45 100 100)"/><use href="#lp2RayS" transform="rotate(135 100 100)"/>
        <use href="#lp2RayS" transform="rotate(225 100 100)"/><use href="#lp2RayS" transform="rotate(315 100 100)"/>
      </g>
    </svg>
  </div>

  <div class="lp2-container">
    <div class="lp2-hero-grid">

      <div class="lp2-hero-text">
        <span class="lp2-eyebrow"><?= htmlspecialchars($h['eyebrow'], ENT_QUOTES, 'UTF-8') ?></span>
        <h1 class="lp2-hero-title"><?= $h['h1_html'] ?></h1>
        <p class="lp2-hero-sub"><?= htmlspecialchars($h['sub'], ENT_QUOTES, 'UTF-8') ?></p>

        <div class="lp2-hero-ctas">
          <a href="<?= wa($content['cta_demo']) ?>" target="_blank" rel="noopener" class="lp2-btn lp2-btn-primary lp2-btn-wa">
            <?= $waSvg ?>
            <?= htmlspecialchars($h['cta_primary_label'], ENT_QUOTES, 'UTF-8') ?>
          </a>
          <a href="<?= htmlspecialchars($h['cta_ghost_href'], ENT_QUOTES, 'UTF-8') ?>" class="lp2-btn lp2-btn-ghost">
            <?= htmlspecialchars($h['cta_ghost_label'], ENT_QUOTES, 'UTF-8') ?>
          </a>
        </div>

        <ul class="lp2-hero-trust">
          <?php foreach ($h['trust'] as $i => $t): ?>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $trustIcons[$i] ?? '' ?></svg>
            <?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="lp2-hero-mockup">
        <div class="lp2-mockup-frame">
          <div class="lp2-mockup-card">
            <svg viewBox="0 0 600 380" xmlns="http://www.w3.org/2000/svg">
              <defs>
                <linearGradient id="dashG1" x1="0" y1="0" x2="1" y2="1">
                  <stop offset="0" stop-color="#1E5FA8"/><stop offset="1" stop-color="#0F3060"/>
                </linearGradient>
              </defs>
              <rect x="0" y="0" width="120" height="380" fill="#060D1A"/>
              <rect x="14" y="18" width="92" height="32" rx="6" fill="#1E3A5F"/>
              <rect x="14" y="62"  width="92" height="22" rx="5" fill="#0D2540" opacity=".55"/>
              <rect x="14" y="92"  width="92" height="22" rx="5" fill="#1E4A8A"/>
              <rect x="14" y="122" width="92" height="22" rx="5" fill="#0D2540" opacity=".55"/>
              <rect x="14" y="152" width="92" height="22" rx="5" fill="#0D2540" opacity=".55"/>
              <rect x="14" y="182" width="92" height="22" rx="5" fill="#0D2540" opacity=".55"/>
              <rect x="14" y="212" width="92" height="22" rx="5" fill="#0D2540" opacity=".55"/>
              <rect x="140" y="16" width="180" height="14" rx="3" fill="#A8BDD4" opacity=".75"/>
              <rect x="140" y="36" width="120" height="9"  rx="3" fill="#6B7887"/>
              <rect x="490" y="16" width="90"  height="28" rx="6" fill="url(#dashG1)"/>
              <g>
                <rect x="140" y="64" width="135" height="78" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
                <rect x="152" y="76" width="48" height="9" rx="2" fill="#6B7887"/>
                <text x="152" y="118" fill="#E2EAF2" font-family="Inter" font-size="22" font-weight="700">38</text>
                <text x="190" y="118" fill="#5BBCE0" font-family="Inter" font-size="11">processos</text>
                <rect x="285" y="64" width="135" height="78" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
                <rect x="297" y="76" width="48" height="9" rx="2" fill="#6B7887"/>
                <text x="297" y="118" fill="#E2EAF2" font-family="Inter" font-size="22" font-weight="700">12</text>
                <text x="334" y="118" fill="#5BBCE0" font-family="Inter" font-size="11">prazos hoje</text>
                <rect x="430" y="64" width="150" height="78" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
                <rect x="442" y="76" width="60" height="9" rx="2" fill="#6B7887"/>
                <text x="442" y="118" fill="#E2EAF2" font-family="Inter" font-size="22" font-weight="700">7</text>
                <text x="467" y="118" fill="#5BBCE0" font-family="Inter" font-size="11">intimações</text>
              </g>
              <g>
                <rect x="140" y="158" width="285" height="200" rx="12" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
                <rect x="156" y="174" width="100" height="11" rx="3" fill="#A8BDD4" opacity=".7"/>
                <g stroke="#1A3A5C" stroke-opacity=".3">
                  <line x1="156" y1="220" x2="412" y2="220"/><line x1="156" y1="260" x2="412" y2="260"/>
                  <line x1="156" y1="300" x2="412" y2="300"/><line x1="156" y1="340" x2="412" y2="340"/>
                </g>
                <g>
                  <rect x="170" y="270" width="16" height="70"  rx="3" fill="url(#dashG1)"/>
                  <rect x="200" y="245" width="16" height="95"  rx="3" fill="url(#dashG1)"/>
                  <rect x="230" y="232" width="16" height="108" rx="3" fill="url(#dashG1)"/>
                  <rect x="260" y="210" width="16" height="130" rx="3" fill="#3A90C4"/>
                  <rect x="290" y="252" width="16" height="88"  rx="3" fill="url(#dashG1)"/>
                  <rect x="320" y="225" width="16" height="115" rx="3" fill="url(#dashG1)"/>
                  <rect x="350" y="200" width="16" height="140" rx="3" fill="#3A90C4"/>
                  <rect x="380" y="240" width="16" height="100" rx="3" fill="url(#dashG1)"/>
                </g>
              </g>
              <g>
                <rect x="435" y="158" width="145" height="200" rx="12" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
                <rect x="447" y="174" width="80" height="10" rx="3" fill="#A8BDD4" opacity=".7"/>
                <g font-family="Inter" font-size="9">
                  <rect x="447" y="198" width="121" height="32" rx="6" fill="#081526"/>
                  <circle cx="460" cy="214" r="5" fill="#5BBCE0"/>
                  <rect x="473" y="208" width="70" height="6" rx="2" fill="#A8BDD4" opacity=".8"/>
                  <rect x="473" y="218" width="50" height="5" rx="2" fill="#6B7887"/>
                  <rect x="447" y="238" width="121" height="32" rx="6" fill="#081526"/>
                  <circle cx="460" cy="254" r="5" fill="#F59E0B"/>
                  <rect x="473" y="248" width="74" height="6" rx="2" fill="#A8BDD4" opacity=".8"/>
                  <rect x="473" y="258" width="46" height="5" rx="2" fill="#6B7887"/>
                  <rect x="447" y="278" width="121" height="32" rx="6" fill="#081526"/>
                  <circle cx="460" cy="294" r="5" fill="#4ADE80"/>
                  <rect x="473" y="288" width="68" height="6" rx="2" fill="#A8BDD4" opacity=".8"/>
                  <rect x="473" y="298" width="56" height="5" rx="2" fill="#6B7887"/>
                  <rect x="447" y="318" width="121" height="32" rx="6" fill="#081526"/>
                  <circle cx="460" cy="334" r="5" fill="#5BBCE0"/>
                  <rect x="473" y="328" width="62" height="6" rx="2" fill="#A8BDD4" opacity=".8"/>
                  <rect x="473" y="338" width="52" height="5" rx="2" fill="#6B7887"/>
                </g>
              </g>
            </svg>
          </div>
        </div>

        <div class="lp2-hero-chips" aria-hidden="true">
          <?php foreach ($h['chips'] as $i => $chip): ?>
          <div class="lp2-chip lp2-chip-<?= $i + 1 ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $chipIcons[$i] ?? '' ?></svg>
            <span><?= $chip['n'] !== '' ? $chip['n'] . ' ' : '' ?><?= $chip['label'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>
</section>
