<?php
/**
 * secao-19-faq.php, #faq
 * Renderiza os <details> a partir de $content['faq']. O schema FAQPage é gerado
 * no index.php a partir do MESMO array: visível e schema nunca divergem.
 */
?>
<section id="faq" class="ys-section">
  <div class="ys-container">
    <div class="ys-faqgrid">
      <div class="ys-faqgrid-side ys-reveal">
        <span class="ys-eyebrow">Perguntas frequentes</span>
        <h2 class="ys-h2">O que todo escritório pergunta antes de conhecer o Yuris.</h2>
        <?= lp2_wa_btn('Olá Bruno, tenho algumas perguntas sobre o Yuris!', 'Tirar minhas dúvidas no WhatsApp') ?>
      </div>
      <div class="ys-faq ys-reveal" style="--d:.12s">
        <?php foreach ($content['faq'] as $f): ?>
        <details>
          <summary><?= htmlspecialchars($f['q'], ENT_QUOTES, 'UTF-8') ?></summary>
          <div class="ys-faq-a"><?= htmlspecialchars($f['a'], ENT_QUOTES, 'UTF-8') ?></div>
        </details>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
