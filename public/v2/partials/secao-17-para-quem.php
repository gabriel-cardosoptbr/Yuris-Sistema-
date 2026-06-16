<?php /* secao-17-para-quem.php, #para-quem (tags) */
$personas = [
  'Advogado autônomo', 'Escritório pequeno', 'Escritório em crescimento', 'Operação com equipe',
  'Matriz com filiais', 'Jurídico interno', 'Alto volume de processos', 'Atendimento por WhatsApp',
];
?>
<section id="para-quem" class="lp2-section">
  <div class="lp2-container">
    <?php lp2_head('Para quem é', 'O Yuris é para escritórios que precisam de controle.', 'Diferentes perfis, uma mesma necessidade: organização e rastreabilidade.'); ?>
    <div class="lp2-tags lp2-reveal">
      <?php foreach ($personas as $p) echo '<span class="lp2-tag-pill">' . $p . '</span>'; ?>
    </div>
    <div class="lp2-section-cta lp2-reveal"><?= lp2_wa_btn('Olá Bruno, gostaria de saber se o Yuris atende o perfil do meu escritório!', 'Falar com a equipe Yuris') ?></div>
  </div>
</section>
