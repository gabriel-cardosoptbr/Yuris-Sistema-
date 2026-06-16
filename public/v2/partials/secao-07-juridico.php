<?php /* secao-07-juridico.php, #juridico (split com mini-demonstração de produto) */
require_once __DIR__ . '/_cenas.php';
$check = lp2_check();
$bullets = [
  'Histórico processual com origem de cada ação',
  'Vínculos com advogados associados e badge de origem',
  'Permissões por escopo: matriz, filial e advogado',
  'Movimentações, documentos e auditoria integrados',
];
?>
<section id="juridico" class="lp2-section">
  <div class="lp2-container">
    <div class="lp2-split lp2-reveal">
      <div class="lp2-split-text">
        <span class="lp2-eyebrow">Jurídico</span>
        <h2>Cada processo precisa contar sua própria história.</h2>
        <p>No Yuris, processos não são apenas registros. Eles carregam histórico, responsáveis, vínculos, permissões e eventos importantes para que o escritório saiba exatamente o que aconteceu, quando aconteceu e quem realizou cada ação.</p>
        <ul class="lp2-split-bullets"><?php foreach ($bullets as $b) echo '<li>' . $check . '<span>' . $b . '</span></li>'; ?></ul>
        <?= lp2_wa_btn('Olá Bruno, quero organizar meus processos com o Yuris!', 'Quero organizar meus processos') ?>
      </div>
      <div class="lp2-split-visual lp2-demo-wrap">
        <?= lp2_scene('juridico') ?>
      </div>
    </div>
  </div>
</section>
