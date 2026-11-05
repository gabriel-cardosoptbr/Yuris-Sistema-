<?php
/**
 * secao-16-comparativo.php, #comparativo: Antes × Com o Yuris.
 *
 * Tabela editorial de sete linhas, com o rótulo do assunto à esquerda. Substitui
 * o palco animado de sete cenas SVG, que ocupava 3.500 px e dependia do GSAP:
 * aqui o texto é todo visível, indexável e lido de uma vez. As frases são as
 * mesmas.
 */
$linhas = [
  ['Processos', 'Processos espalhados em pastas e planilhas.', 'Processos centralizados, com vínculos e histórico.'],
  ['Prazos', 'Prazos lembrados de memória.', 'Prazos organizados, com responsáveis e alertas.'],
  ['Comunicação', 'Conversas perdidas no WhatsApp pessoal.', 'Comunicação conectada a cliente e processo.'],
  ['Tarefas', 'Tarefas sem dono e sem prazo.', 'Tarefas com responsáveis e status claros.'],
  ['Intimações', 'Intimações sem histórico de vínculo.', 'Intimações vinculadas e auditáveis.'],
  ['Gestão', 'Equipe sem visibilidade da operação.', 'Gestão por matriz, filial e advogado.'],
  ['Auditoria', 'Baixa rastreabilidade em qualquer auditoria.', 'Histórico e auditoria como parte da operação.'],
];
?>
<section id="comparativo" class="ys-section">
  <div class="ys-container">
    <?php lp2_head('Antes &amp; depois', 'A rotina jurídica que existe hoje, e a que pode existir amanhã.'); ?>

    <div class="ys-cmp ys-reveal" role="table" aria-label="Comparativo: antes e com o Yuris">
      <div class="ys-cmp-head" role="row">
        <span role="columnheader"><span class="ys-sr">Assunto</span></span>
        <span role="columnheader">Antes</span>
        <span role="columnheader">Com o Yuris</span>
      </div>
      <?php foreach ($linhas as [$assunto, $antes, $depois]): ?>
      <div class="ys-cmp-row" role="row">
        <strong role="rowheader"><?= $assunto ?></strong>
        <span role="cell" class="ys-cmp-a" data-label="Antes"><?= $antes ?></span>
        <span role="cell" class="ys-cmp-b" data-label="Com o Yuris"><?= ys_check() ?><?= $depois ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="ys-section-cta ys-reveal"><?= lp2_wa_btn('Olá Bruno, quero transformar a rotina do meu escritório com o Yuris!', 'Quero essa transformação') ?></div>
  </div>
</section>
