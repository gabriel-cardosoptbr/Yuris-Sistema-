<?php
/**
 * secao-14-dashboards.php, #dashboards
 * Texto numa coluna estreita e o painel à direita; os itens correm numa régua de
 * quatro colunas embaixo. Os números da cena são de demonstração, e a legenda diz.
 */
$bullets = [
  'Indicadores de processos, prazos e intimações',
  'Visão por matriz, filial ou advogado',
  'Comparativos por período',
  'Funil de prospecção e produtividade da equipe',
];
?>
<section id="dashboards" class="ys-section ys-bg-tint">
  <div class="ys-container">
    <div class="ys-split ys-split--wide-right">
      <div class="ys-reveal">
        <h2 class="ys-h2">O que não é medido vira ruído.</h2>
        <p class="ys-lede">Tenha uma visão clara da operação jurídica, acompanhe o volume de tarefas, processos, clientes e oportunidades em painéis objetivos.</p>
        <div class="ys-cta-row ys-mt"><?= lp2_wa_btn('Olá Bruno, quero ver os dashboards e indicadores do Yuris!', 'Ver indicadores em ação') ?></div>
      </div>
      <div class="ys-reveal" style="--d:.12s">
        <?= lp2_scene('dashboards') ?>
        <p class="ys-note">Dados de demonstração</p>
      </div>
    </div>
    <ul class="ys-ruler ys-reveal">
      <?php foreach ($bullets as $b): ?><li><?= $b ?></li><?php endforeach; ?>
    </ul>
  </div>
</section>
