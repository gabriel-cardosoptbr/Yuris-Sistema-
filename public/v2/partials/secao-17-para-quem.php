<?php
/**
 * secao-17-para-quem.php, #para-quem
 * Duas colunas: título à esquerda, os perfis como lista tipográfica grande à
 * direita, cada um numa linha, sem caixa em volta.
 */
$personas = [
  'Advogado autônomo', 'Escritório pequeno', 'Escritório em crescimento', 'Operação com equipe',
  'Matriz com filiais', 'Jurídico interno', 'Alto volume de processos', 'Atendimento por WhatsApp',
];
?>
<section id="para-quem" class="ys-section ys-bg-2">
  <div class="ys-container">
    <div class="ys-quem">
      <div class="ys-reveal">
        <h2 class="ys-h2">O Yuris é para escritórios que precisam de controle.</h2>
        <p class="ys-lede">Diferentes perfis, uma mesma necessidade: organização e rastreabilidade.</p>
        <?= lp2_wa_btn('Olá Bruno, gostaria de saber se o Yuris atende o perfil do meu escritório!', 'Falar com a equipe Yuris') ?>
      </div>
      <ul class="ys-quem-list ys-reveal" style="--d:.12s">
        <?php foreach ($personas as $p): ?><li><?= $p ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
</section>
