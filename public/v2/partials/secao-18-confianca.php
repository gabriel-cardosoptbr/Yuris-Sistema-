<?php
/**
 * secao-18-confianca.php, #confianca
 * Seis pilares marcados com numeral romano: pilar é estrutura, e a origem do
 * nome é o Direito Romano. Linha fina no topo de cada um, sem card.
 */
$pilares = [
  ['I', 'Controle', 'Cada ação tem responsável, contexto e momento.'],
  ['II', 'Segurança', 'Proteção de dados e separação por escopo desde a fundação.'],
  ['III', 'Automação', 'O trabalho repetitivo do escritório pode acontecer sem você.'],
  ['IV', 'Rastreabilidade', 'Histórico imutável para qualquer verificação.'],
  ['V', 'Gestão', 'Visão clara do que cada equipe está fazendo.'],
  ['VI', 'Escalabilidade', 'Cresce com o escritório, sem trocar o sistema.'],
];
?>
<section id="confianca" class="ys-section">
  <div class="ys-container">
    <?php lp2_head('Pilares', 'Seis pilares que sustentam o Yuris.', 'O que orienta cada decisão de produto, do schema do banco à última tela.'); ?>
    <ol class="ys-pilares ys-reveal">
      <?php foreach ($pilares as [$n, $t, $d]): ?>
      <li>
        <span class="ys-pilar-n" aria-hidden="true"><?= $n ?></span>
        <h3><?= $t ?></h3>
        <p><?= $d ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
    <div class="ys-section-cta ys-reveal"><?= lp2_wa_btn('Olá Bruno, quero conversar com um especialista do Yuris!', 'Conversar com um especialista') ?></div>
  </div>
</section>
