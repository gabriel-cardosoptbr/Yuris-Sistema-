<?php
/** secao-14-dashboards.php, #dashboards: indicadores e financeiro, com a ilustração dos indicadores. */
require_once __DIR__ . '/_cenas.php';
lp2_spot([
  'id' => 'dashboards', 'fundo' => 'ys-bg-tint', 'cena' => 'indicadores',
  'h2' => 'O que não é medido vira ruído.',
  'lede' => 'Os números do escritório saem do que já está no sistema: contratos, parcelas, processos e tarefas. Sem planilha paralela e sem fechar o mês no escuro.',
  'itens' => [
    'Receita, custos, impostos e resultado do período',
    'Parcelas por cliente e por processo, com baixa e projeção',
    'Indicadores de processos, prazos e intimações',
    'Desempenho da equipe e funil de prospecção',
  ],
  'wa' => ['Olá Bruno, quero ver os indicadores e o financeiro do Yuris!', 'Ver indicadores em ação'],
]);
