<?php
/**
 * secao-12-automacao.php, #automacao
 * Painel azul institucional dentro do container: mesmo contraste da faixa do
 * Jurídico, em forma de peça recortada, com os itens em chips.
 */
$bullets = [
  'Webhooks por evento, com envio seguro',
  'Conecta n8n, Make, Zapier ou robôs próprios',
  'Dispara ações a cada criação, atualização ou conclusão',
  'Fluxos personalizados sem sair do sistema',
];
?>
<section id="automacao" class="ys-section is-tight">
  <div class="ys-container">
    <div class="ys-panel ys-reveal">
      <div class="ys-panel-text">
        <span class="ys-eyebrow">Automações</span>
        <h2 class="ys-h2">O Yuris não precisa trabalhar sozinho.</h2>
        <p class="ys-lede">Com webhooks, o escritório conecta o Yuris às suas automações externas, robôs, CRMs, ERPs e fluxos personalizados, disparando ações automáticas quando algo acontece no sistema.</p>
        <ul class="ys-panel-chips">
          <?php foreach ($bullets as $b): ?><li><?= $b ?></li><?php endforeach; ?>
        </ul>
        <div class="ys-cta-row ys-mt"><?= lp2_wa_btn('Olá Bruno, quero automatizar a operação do meu escritório com o Yuris!', 'Automatizar minha operação') ?></div>
      </div>
      <div class="ys-panel-art"><?= lp2_scene('automacoes') ?></div>
    </div>
  </div>
</section>
