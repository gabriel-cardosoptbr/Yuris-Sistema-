<?php
/**
 * secao-09-comunicacao.php, #comunicacao
 * Título grande em cima, ocupando a largura; embaixo, a conversa ao lado de
 * quatro blocos compactos. Quem fala aqui é a cena de conversa.
 */
$bullets = [
  'Atendimento centralizado por advogado',
  'Conversas vinculadas ao cliente e ao processo',
  'Histórico preservado mesmo com troca de responsável',
  'Chat interno entre matriz, filial e equipe',
];
?>
<section id="comunicacao" class="ys-section ys-bg-2">
  <div class="ys-container">
    <?php lp2_head('Comunicação', 'O cliente fala pelo WhatsApp. O escritório responde com organização.', 'O Yuris ajuda a transformar conversas em histórico útil, conectando atendimento, cliente, processo e equipe em um só lugar.'); ?>
    <div class="ys-chatgrid">
      <div class="ys-reveal"><?= lp2_scene('comunicacao') ?></div>
      <div class="ys-chatgrid-side ys-reveal" style="--d:.12s">
        <ul class="ys-minis">
          <?php foreach ($bullets as $b): ?>
          <li><?= ys_check() ?><span><?= $b ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?= lp2_wa_btn('Olá Bruno, quero centralizar o atendimento do meu escritório no Yuris!', 'Centralizar meu atendimento') ?>
      </div>
    </div>
  </div>
</section>
