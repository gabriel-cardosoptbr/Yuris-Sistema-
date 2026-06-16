<?php /* secao-15-integracoes.php, #integracoes — grade premium centralizada (4 + 3) */
$integra = [
  ['WhatsApp', 'Atendimento e mensagens', 'whatsapp'],
  ['n8n', 'Automação self-hosted', 'n8n'],
  ['Make', 'Cenários visuais', 'make'],
  ['Zapier', 'Conexões rápidas', 'zapier'],
  ['Publicações oficiais', 'Monitoramento de intimações', 'publicacoes'],
  ['API REST', 'Integrações próprias', 'apirest'],
  ['Webhooks', 'Integração por eventos', 'webhooks'],
];

/* Ícones lineares coerentes: traço ciano (.ii-base/.ii-flow), acento dourado
   (.ii-acc stroke / .ii-dot fill). Mesma viewBox (32) e espessura (via CSS).
   .ii-flow = parte que redesenha no hover. Decorativos (aria-hidden no wrapper). */
if (!function_exists('lp2_integra_icon')) {
  function lp2_integra_icon($k) {
    $svg = [
      'whatsapp' =>
        '<path class="ii-base" d="M16 7.5a8.5 8.5 0 0 0-7.4 12.7L7.2 25l5-1.3A8.5 8.5 0 1 0 16 7.5z"/>'
        . '<path class="ii-flow" d="M12.2 16.3l2.6 2.6 4.9-5.1"/>',
      'n8n' =>
        '<circle class="ii-base" cx="8.5" cy="11" r="2.3"/><circle class="ii-base" cx="8.5" cy="21" r="2.3"/>'
        . '<circle class="ii-acc" cx="23.5" cy="16" r="2.7"/>'
        . '<path class="ii-flow" d="M10.7 11.8 21 15.2M10.7 20.2 21 16.8"/>',
      'make' =>
        '<rect class="ii-base" x="6" y="6.5" width="8" height="8" rx="2.2"/><rect class="ii-base" x="18" y="17.5" width="8" height="8" rx="2.2"/>'
        . '<path class="ii-flow" d="M14 10.5h4a3 3 0 0 1 3 3v4"/><circle class="ii-dot" cx="21" cy="10.5" r="1.5"/>',
      'zapier' =>
        '<path class="ii-flow" d="M17.5 6l-7.5 11.2h5.3L14 26l8.5-13.2h-5.4z"/>'
        . '<circle class="ii-dot" cx="24" cy="7.5" r="1.5"/><circle class="ii-base" cx="7.5" cy="24" r="1.7"/>',
      'publicacoes' =>
        '<path class="ii-base" d="M8.5 6.5h9l5.5 5.5v13.5H8.5z"/><path class="ii-base" d="M17.5 6.5v5.5h5.5"/>'
        . '<path class="ii-flow" d="M12 17h7M12 20.5h5"/><circle class="ii-dot" cx="22.5" cy="9" r="2.2"/>',
      'apirest' =>
        '<path class="ii-base" d="M12 7c-2.4 0-3 1.1-3 3v1.6c0 1.6-.9 2.4-2.4 2.4 1.5 0 2.4.8 2.4 2.4V18c0 1.9.6 3 3 3"/>'
        . '<path class="ii-base" d="M20 7c2.4 0 3 1.1 3 3v1.6c0 1.6.9 2.4 2.4 2.4-1.5 0-2.4.8-2.4 2.4V18c0 1.9-.6 3-3 3"/>'
        . '<path class="ii-flow" d="M13.5 14h5"/><path class="ii-flow" d="M18.5 18h-5"/><circle class="ii-dot" cx="20.4" cy="18" r="1.1"/>',
      'webhooks' =>
        '<circle class="ii-base" cx="8" cy="12" r="2.4"/><circle class="ii-acc" cx="23" cy="20.5" r="2.4"/>'
        . '<path class="ii-flow" d="M9.7 13.8C13 16.4 15.5 18 20.6 20"/><path class="ii-flow" d="M20 21l1.7 1.7 2.9-3.4"/>',
    ];
    $inner = $svg[$k] ?? '';
    return '<svg class="ii" viewBox="0 0 32 32" fill="none" aria-hidden="true">' . $inner . '</svg>';
  }
}
?>
<section id="integracoes" class="lp2-section">
  <div class="lp2-container">
    <?php lp2_head('Integrações', 'Conecte o Yuris ao ecossistema do seu escritório.', 'O sistema foi pensado para evoluir com integrações e automações, permitindo que o escritório conecte dados e fluxos sem ficar preso a processos manuais.'); ?>
    <ul class="lp2-integra lp2-reveal" role="list">
      <?php foreach ($integra as $i => [$nome, $desc, $key]): ?>
      <li class="lp2-integra-card" style="--i:<?= $i ?>">
        <span class="lp2-integra-conn" aria-hidden="true"></span>
        <span class="lp2-integra-ic" aria-hidden="true"><?= lp2_integra_icon($key) ?></span>
        <div class="lp2-integra-name"><?= $nome ?></div>
        <p><?= $desc ?></p>
        <span class="lp2-integra-flow" aria-hidden="true"></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <div class="lp2-section-cta lp2-reveal"><?= lp2_wa_btn('Olá Bruno, quero integrar o Yuris com as ferramentas do meu escritório!', 'Conectar minhas ferramentas') ?></div>
  </div>
</section>
