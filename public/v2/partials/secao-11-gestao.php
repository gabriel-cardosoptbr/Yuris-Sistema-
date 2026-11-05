<?php
/**
 * secao-11-gestao.php, #gestao
 * Composição central: a cena no meio, com dois itens de cada lado. É a imagem do
 * argumento: a gestão no centro enxergando as pontas.
 */
$bullets = [
  'Matriz com visão consolidada e controle total',
  'Filiais com equipes e advogados próprios',
  'Advogados associados com vínculo independente',
  'Permissões e identificação de origem por escopo',
];
?>
<section id="gestao" class="ys-section ys-bg-2">
  <div class="ys-container">
    <?php lp2_head('Gestão', 'Cada pessoa vê o que precisa. A gestão enxerga o que importa.', 'O Yuris foi pensado para operações com matriz, filiais, advogados e equipes diferentes, mantendo controle de acesso, identificação de origem e rastreabilidade.', 'center'); ?>
    <div class="ys-orbit">
      <ul class="ys-orbit-col ys-reveal">
        <li><span>01</span><?= $bullets[0] ?></li>
        <li><span>02</span><?= $bullets[1] ?></li>
      </ul>
      <div class="ys-orbit-mid ys-reveal" style="--d:.1s"><?= lp2_scene('gestao') ?></div>
      <ul class="ys-orbit-col ys-reveal" style="--d:.2s">
        <li><span>03</span><?= $bullets[2] ?></li>
        <li><span>04</span><?= $bullets[3] ?></li>
      </ul>
    </div>
    <div class="ys-section-cta ys-reveal"><?= lp2_wa_btn('Olá Bruno, quero organizar matriz, filial e permissões no Yuris!', 'Configurar matriz e filial') ?></div>
  </div>
</section>
