<?php /* secao-15-integracoes.php, #integracoes (grid 8) */
$integra = [
  ['WhatsApp', 'Atendimento e mensagens'],
  ['n8n', 'Automação self-hosted'],
  ['Make', 'Cenários visuais'],
  ['Zapier', 'Conexões rápidas'],
  ['Publicações oficiais', 'Monitoramento de intimações'],
  ['API REST', 'Integrações próprias'],
  ['Webhooks', 'Integração por eventos'],
];
?>
<section id="integracoes" class="lp2-section">
  <div class="lp2-container">
    <?php lp2_head('Integrações', 'Conecte o Yuris ao ecossistema do seu escritório.', 'O sistema foi pensado para evoluir com integrações e automações, permitindo que o escritório conecte dados e fluxos sem ficar preso a processos manuais.'); ?>
    <div class="lp2-integra lp2-reveal">
      <?php foreach ($integra as [$nome, $desc]): ?>
      <div class="lp2-integra-card">
        <div class="lp2-integra-name"><?= $nome ?></div>
        <p><?= $desc ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="lp2-section-cta lp2-reveal"><?= lp2_wa_btn('Olá Bruno, quero integrar o Yuris com as ferramentas do meu escritório!', 'Conectar minhas ferramentas') ?></div>
  </div>
</section>
