<?php
/** secao-11-gestao.php, #gestao (e #vinculos): matriz, filiais e advogados, com a ilustração de quem vê o quê. */
require_once __DIR__ . '/_cenas.php';
lp2_spot([
  'id' => 'gestao', 'flip' => true, 'cena' => 'estrutura', 'ancoras' => ['vinculos'],
  'h2' => 'Cada pessoa vê o que precisa. A gestão enxerga o que importa.',
  'lede' => 'Matriz, filiais e advogados associados trabalham juntos sem se misturar. Cada processo fica ligado a quem é responsável, com permissão por escopo e rastreabilidade de ponta a ponta.',
  'itens' => [
    'Matriz com visão consolidada e controle total',
    'Filiais com equipes e advogados próprios',
    'Advogado associado vinculado só aos processos dele',
    'Permissões por perfil, módulo e escopo',
  ],
  'wa' => ['Olá Bruno, quero organizar matriz, filial e permissões no Yuris!', 'Organizar minha estrutura'],
]);
