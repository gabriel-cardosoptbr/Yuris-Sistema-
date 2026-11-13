<?php
/**
 * secao-07-juridico.php, #juridico
 * Faixa azul-marinho de ponta a ponta: é o coração do produto, e a primeira
 * virada forte de contraste depois das seções claras. A cena fica à direita,
 * maior que o texto, e os itens viram linhas numeradas.
 */
require_once __DIR__ . '/_cenas.php';
$bullets = [
  'Histórico processual com origem de cada ação',
  'Vínculos com advogados associados e badge de origem',
  'Permissões por escopo: matriz, filial e advogado',
  'Movimentações, documentos e auditoria integrados',
];
?>
<section id="juridico" class="ys-section ys-bg-2 ys-juridico">
  <div class="ys-container">
    <div class="ys-split ys-split--wide-right">
      <div class="ys-reveal">
        <h2 class="ys-h2">Cada processo precisa contar sua própria história.</h2>
        <p class="ys-lede">No Yuris, processos não são apenas registros. Eles carregam histórico, responsáveis, vínculos, permissões e eventos importantes para que o escritório saiba exatamente o que aconteceu, quando aconteceu e quem realizou cada ação.</p>
        <?= lp2_rows($bullets) ?>
        <div class="ys-cta-row ys-mt"><?= lp2_wa_btn('Olá Bruno, quero organizar meus processos com o Yuris!', 'Quero organizar meus processos') ?></div>
      </div>
      <div class="ys-reveal" style="--d:.12s"><?= lp2_scene('juridico') ?></div>
    </div>
  </div>
</section>
