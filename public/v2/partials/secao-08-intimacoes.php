<?php
/**
 * secao-08-intimacoes.php, #intimacoes
 * A cena vai à esquerda, sobre um painel azul-claro que passa da largura dela:
 * lê-se como peça emoldurada, não como print solto. O texto fica à direita.
 */
$bullets = [
  'Monitoramento por OAB, nome e UF',
  'Vínculo direto ao processo correspondente',
  'Conversão de prazo em tarefa com responsável',
  'Status, alertas e visão do que vence quando',
];
?>
<section id="intimacoes" class="ys-section">
  <div class="ys-container">
    <div class="ys-split ys-split--mat">
      <div class="ys-mat ys-reveal"><?= lp2_scene('intimacoes') ?></div>
      <div class="ys-reveal" style="--d:.12s">
        <h2 class="ys-h2">Intimação recebida não pode virar informação perdida.</h2>
        <p class="ys-lede">Associe intimações ao processo correto, transforme prazos em tarefas e acompanhe responsáveis sem depender de memória, prints ou conversas soltas.</p>
        <?= lp2_checks($bullets) ?>
        <div class="ys-cta-row ys-mt"><?= lp2_wa_btn('Olá Bruno, quero controlar intimações e prazos com o Yuris!', 'Quero controlar minhas intimações') ?></div>
      </div>
    </div>
  </div>
</section>
