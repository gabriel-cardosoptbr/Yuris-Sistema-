<?php
/**
 * secao-04-dores.php, #dores (e #comparativo, endereço antigo)
 * O problema e a resposta numa peça só: a tabela "sem sistema / com o Yuris". Substitui a
 * lista de dores e o comparativo, que diziam a mesma coisa em dois lugares da página.
 */
$linhas = [
  ['Processos',   'Espalhados em pastas e planilhas, sem histórico de quem mexeu.', 'Centralizados, com vínculos e histórico de cada ação.'],
  ['Prazos',      'Lembrados de memória, com várias datas e nenhum responsável claro.', 'Organizados, com responsável, alerta e visão do que vence quando.'],
  ['Intimações',  'Publicação recebida sem saber quem tratou nem a que processo pertence.', 'Vinculadas ao processo e convertidas em prazo ou tarefa.'],
  ['Atendimento', 'Conversa importante perdida no WhatsApp pessoal de alguém.', 'WhatsApp do escritório ligado ao cliente e ao processo.'],
  ['Tarefas',     'Sem dono e sem data: a gestão vê o tempo passar, não o trabalho.', 'Com responsável, prioridade e situação claras.'],
  ['Gestão',      'Cada unidade trabalha do seu jeito, e a matriz não enxerga.', 'Visão por matriz, filial e advogado, com permissão por escopo.'],
  ['Auditoria',   'Em qualquer questionamento, não há registro confiável do que aconteceu.', 'Histórico e auditoria como parte da operação.'],
];
?>
<section id="dores" class="ys-section ys-bg-2">
  <span id="comparativo"></span>
  <div class="ys-container">
    <?php lp2_head('', 'Quando o escritório cresce sem sistema, o controle se perde.', 'Com o Yuris, cada processo, tarefa, intimação e atendimento ganha contexto, responsável e histórico.'); ?>

    <div class="ys-cmp ys-reveal" role="table" aria-label="Comparativo: a rotina sem sistema e com o Yuris">
      <div class="ys-cmp-head" role="row">
        <span role="columnheader"><span class="ys-sr">Assunto</span></span>
        <span role="columnheader">Sem sistema</span>
        <span role="columnheader">Com o Yuris</span>
      </div>
      <?php foreach ($linhas as [$assunto, $antes, $depois]): ?>
      <div class="ys-cmp-row" role="row">
        <strong role="rowheader"><?= $assunto ?></strong>
        <span role="cell" class="ys-cmp-a" data-label="Sem sistema"><?= $antes ?></span>
        <span role="cell" class="ys-cmp-b" data-label="Com o Yuris"><?= ys_check() ?><?= $depois ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="ys-section-cta ys-reveal"><?= lp2_wa_btn('Olá Bruno, quero resolver essas dores no meu escritório com o Yuris!', 'Resolver isso no meu escritório') ?></div>
  </div>
</section>
