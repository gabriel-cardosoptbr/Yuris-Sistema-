<?php
/**
 * secao-10-operacao.php, #operacao e #cliente, lado a lado.
 *
 * Operação conta o funil e termina no "sim"; Cliente conta o que vem depois do
 * "sim", que é onde quase todo sistema jurídico perde a memória. São as duas
 * metades da mesma história, por isso dividem a seção em duas colunas de pesos
 * diferentes, em vez de virarem duas seções iguais em sequência.
 *
 * A cena 'cliente' mostra UMA linha do tempo só, com a conversão marcada no meio
 * dela: a conversão é um evento na história, não o começo de outra.
 */
require_once __DIR__ . '/_cenas.php';
$operacao = [
  'Funil de prospecção com etapas customizáveis',
  'Cards com responsável, etiquetas e campos do escritório',
  'Histórico de movimentação no funil',
  'Canal de aquisição registrado: de onde cada lead veio',
  'Um clique transforma a prospecção em cliente, sem redigitar nada',
  'E o processo pode nascer direto da prospecção, com o contexto junto',
];
$cliente = [
  'Uma linha do tempo só, do primeiro contato em diante',
  'Documentos, etiquetas e campos do escritório acompanham',
  'Processos, conversas e tarefas continuam ligados',
  'O sistema avisa quando o cliente já existe, antes de duplicar',
];
?>
<section class="ys-section">
  <div class="ys-container">
    <div class="ys-pair">
      <article id="operacao" class="ys-pair-item ys-reveal">
        <h2 class="ys-h3 ys-pair-title">Da primeira conversa ao processo: acompanhe tudo.</h2>
        <p>Organize a entrada de novos clientes, acompanhe oportunidades e conecte a prospecção à operação jurídica sem perder contexto.</p>
        <?= lp2_scene('operacao') ?>
        <?= lp2_checks($operacao) ?>
        <div class="ys-cta-row ys-mt"><?= lp2_wa_btn('Olá Bruno, quero estruturar minha prospecção com o Yuris!', 'Estruturar minha prospecção') ?></div>
      </article>

      <article id="cliente" class="ys-pair-item is-tint ys-reveal" style="--d:.12s">
        <h2 class="ys-h3 ys-pair-title">O cliente fecha, e a história continua de onde parou.</h2>
        <p>Quando a prospecção vira cliente, nada é deixado para trás. O histórico não recomeça, e os processos, as conversas de WhatsApp, as tarefas, os documentos e o canal por onde aquela pessoa chegou seguem junto com ela.</p>
        <?= lp2_scene('cliente') ?>
        <?= lp2_checks($cliente) ?>
        <div class="ys-cta-row ys-mt"><?= lp2_wa_btn('Olá Bruno, quero ver como o histórico do cliente acompanha a conversão no Yuris!', 'Ver a linha do tempo do cliente') ?></div>
      </article>
    </div>
  </div>
</section>
