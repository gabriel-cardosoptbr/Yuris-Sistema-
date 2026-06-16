<?php /* secao-18-confianca.php, #confianca (grid 6 pilares) */
$pilares = [
  ['<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>', 'Controle', 'Cada ação tem responsável, contexto e momento.'],
  ['<path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/>', 'Segurança', 'Proteção de dados e separação por escopo desde a fundação.'],
  ['<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>', 'Automação', 'O trabalho repetitivo do escritório pode acontecer sem você.'],
  ['<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>', 'Rastreabilidade', 'Histórico imutável para qualquer verificação.'],
  ['<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>', 'Gestão', 'Visão clara do que cada equipe está fazendo.'],
  ['<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>', 'Escalabilidade', 'Cresce com o escritório, sem trocar o sistema.'],
];
?>
<section id="confianca" class="lp2-section">
  <div class="lp2-container">
    <?php lp2_head('Pilares', 'Seis pilares que sustentam o Yuris.', 'O que orienta cada decisão de produto, do schema do banco à última tela.'); ?>
    <div class="lp2-grid lp2-grid-3 lp2-reveal">
      <?php foreach ($pilares as [$ic, $t, $d]): ?>
      <div class="lp2-card">
        <div class="lp2-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $ic ?></svg></div>
        <h3><?= $t ?></h3><p><?= $d ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="lp2-section-cta lp2-reveal"><?= lp2_wa_btn('Olá Bruno, quero conversar com um especialista do Yuris!', 'Conversar com um especialista') ?></div>
  </div>
</section>
