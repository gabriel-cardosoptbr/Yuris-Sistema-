<?php
/**
 * secao-20-demonstracao.php, #demonstracao
 * Fechamento da home: fundo que vai do branco ao azul-claro, texto de um lado e a janela
 * do produto do outro, com as linhas de arquitetura do hero, e emenda no rodapé claro.
 */
require_once __DIR__ . '/_cenas.php';
?>
<section id="demonstracao" class="ys-final">
  <svg class="ys-final-lines" viewBox="0 0 1440 620" fill="none" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
    <g stroke="#2764B4" stroke-opacity=".10" stroke-width="1.2">
      <path d="M760 620V260a170 170 0 0 1 340 0v360"/><path d="M810 620V260a120 120 0 0 1 240 0v360"/>
      <path d="M1100 620V260a170 170 0 0 1 340 0v360"/><path d="M1150 620V260a120 120 0 0 1 240 0v360"/>
      <line x1="0" y1="64" x2="1440" y2="64"/>
    </g>
  </svg>
  <div class="ys-container ys-final-grid">
    <div class="ys-reveal">
      <h2 class="ys-final-title">Pronto para organizar sua operação jurídica em uma única plataforma?</h2>
      <p class="ys-lede">Conheça o Yuris e veja como processos, prazos, intimações, comunicação e equipe podem trabalhar no mesmo fluxo.</p>
      <div class="ys-cta-row ys-mt">
        <?= lp2_wa_btn('Olá Bruno, quero uma demonstração do Yuris!', 'Solicitar demonstração') ?>
        <a href="<?= htmlspecialchars(wa('Olá Bruno, gostaria de falar com um especialista do Yuris!'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="ys-btn ys-btn-ghost">Falar com especialista</a>
      </div>
    </div>
    <div class="ys-final-show ys-reveal" style="--d:.12s"><?= lp2_scene('showcase') ?></div>
  </div>
</section>
