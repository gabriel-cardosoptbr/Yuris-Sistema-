<?php
/* secao-19-faq.php, #faq
   Renderiza os <details> a partir de $content['faq']. O schema FAQPage é
   gerado no index.php a partir do MESMO array, visível e schema nunca divergem. */
?>
<section id="faq" class="lp2-section">
  <div class="lp2-container">
    <?php lp2_head('Perguntas frequentes', 'O que todo escritório pergunta antes de conhecer o Yuris.'); ?>
    <div class="lp2-faq lp2-reveal">
      <?php foreach ($content['faq'] as $f): ?>
      <details>
        <summary><?= htmlspecialchars($f['q'], ENT_QUOTES, 'UTF-8') ?></summary>
        <div class="lp2-faq-answer"><?= htmlspecialchars($f['a'], ENT_QUOTES, 'UTF-8') ?></div>
      </details>
      <?php endforeach; ?>
    </div>
    <div class="lp2-section-cta lp2-reveal"><?= lp2_wa_btn('Olá Bruno, tenho algumas perguntas sobre o Yuris!', 'Tirar minhas dúvidas no WhatsApp') ?></div>
  </div>
</section>
