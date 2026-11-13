<?php
/** secao-07-juridico.php, #juridico: o processo e a sua história, com a ilustração da ficha do processo. */
require_once __DIR__ . '/_cenas.php';
lp2_spot([
  'id' => 'juridico', 'fundo' => 'ys-bg-2', 'ancoras' => ['recursos', 'produto'], 'cena' => 'processo',
  'h2' => 'Cada processo precisa contar sua própria história.',
  'lede' => 'No Yuris, o processo não é só um registro. Ele carrega histórico, responsáveis, vínculos e permissões, para o escritório saber o que aconteceu, quando aconteceu e quem fez.',
  'itens' => [
    'Histórico processual com a origem de cada ação',
    'Prazos, tarefas e documentos dentro do próprio processo',
    'Vínculo com advogados associados, cada um no seu escopo',
    'Valores da causa, parcelas e resultado ligados ao financeiro',
  ],
  'wa' => ['Olá Bruno, quero organizar meus processos com o Yuris!', 'Quero organizar meus processos'],
]);
