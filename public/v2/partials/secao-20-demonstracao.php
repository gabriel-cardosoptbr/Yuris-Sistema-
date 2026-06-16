<?php /* secao-20-demonstracao.php, #demonstracao (trailer das funções + CTA final) */
require_once __DIR__ . '/_cenas.php';
?>
<section id="demonstracao" class="lp2-section">
  <div class="lp2-container">
    <div class="lp2-final lp2-reveal">
      <span class="lp2-eyebrow">Vamos conversar</span>
      <h2>Pronto para organizar sua operação jurídica em uma única plataforma?</h2>
      <p>Conheça o Yuris e veja como processos, prazos, intimações, comunicação e equipe podem trabalhar no mesmo fluxo.</p>
      <div class="lp2-final-show"><?= lp2_scene('showcase') ?></div>
      <div class="lp2-final-ctas">
        <?= lp2_wa_btn('Olá Bruno, quero uma demonstração do Yuris!', 'Solicitar demonstração') ?>
        <a href="<?= wa('Olá Bruno, gostaria de falar com um especialista do Yuris!') ?>" target="_blank" rel="noopener" class="lp2-btn lp2-btn-ghost">Falar com especialista</a>
      </div>
    </div>
  </div>
</section>
