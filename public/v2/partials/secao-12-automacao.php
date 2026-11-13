<?php
/**
 * secao-12-automacao.php, #automacao (e #integracoes): automações e integrações numa
 * seção só. Sem desenho de fluxo: o texto e a lista do que conecta.
 */
$bullets = [
  'Webhooks por evento, com envio seguro',
  'Dispara a cada criação, atualização ou conclusão',
  'Fluxos do escritório sem sair do sistema',
];
$integra = [
  ['WhatsApp', 'Atendimento e mensagens'],
  ['Publicações oficiais', 'Monitoramento de intimações'],
  ['n8n', 'Automação no seu servidor'],
  ['Make', 'Cenários visuais'],
  ['Zapier', 'Conexões rápidas'],
  ['Webhooks', 'Integração por eventos'],
  ['API REST', 'Integrações próprias'],
];
?>
<section id="automacao" class="ys-section">
  <span id="integracoes"></span>
  <div class="ys-container">
    <div class="ys-spot">
      <div class="ys-spot-text ys-reveal">
        <h2 class="ys-h2">O Yuris não precisa trabalhar sozinho.</h2>
        <p class="ys-lede">Com webhooks, o escritório liga o Yuris às automações, aos robôs e aos sistemas que já usa. Quando algo acontece no sistema, a ação seguinte dispara sozinha.</p>
        <?= lp2_checks($bullets) ?>
        <div class="ys-cta-row ys-mt"><?= lp2_wa_btn('Olá Bruno, quero automatizar a operação do meu escritório com o Yuris!', 'Automatizar minha operação') ?></div>
      </div>
      <ul class="ys-liga ys-reveal" aria-label="O que o Yuris conecta">
        <?php foreach ($integra as [$nome, $desc]): ?>
        <li><strong><?= $nome ?></strong><span><?= $desc ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</section>
