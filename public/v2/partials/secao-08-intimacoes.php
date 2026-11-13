<?php
/** secao-08-intimacoes.php, #intimacoes: da publicação ao prazo, com a ilustração da publicação que vira prazo. */
require_once __DIR__ . '/_cenas.php';
lp2_spot([
  'id' => 'intimacoes', 'flip' => true, 'cena' => 'intimacao',
  'h2' => 'Intimação recebida não pode virar informação perdida.',
  'lede' => 'O Yuris acompanha as publicações oficiais e traz as intimações do escritório. Você vincula ao processo certo e transforma em prazo ou tarefa com responsável, sem depender de memória, print ou conversa solta.',
  'itens' => [
    'Monitoramento por OAB, nome e UF',
    'Vínculo direto ao processo correspondente',
    'Um clique cria o prazo ou a tarefa, já com responsável',
    'Lida, favorita, com prazo: nada fica sem tratamento',
  ],
  'wa' => ['Olá Bruno, quero controlar intimações e prazos com o Yuris!', 'Quero controlar minhas intimações'],
]);
