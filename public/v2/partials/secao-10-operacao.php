<?php
/**
 * secao-10-operacao.php, #operacao (e #comunicacao, #cliente): do primeiro contato ao
 * cliente, com a ilustração da conversa que entra no funil. Reúne o que antes eram três seções
 * (comunicação, prospecção e ficha do cliente), que contavam a mesma história em pedaços.
 */
require_once __DIR__ . '/_cenas.php';
lp2_spot([
  'id' => 'operacao', 'fundo' => 'ys-bg-2', 'cena' => 'funil', 'ancoras' => ['comunicacao', 'cliente'],
  'h2' => 'O cliente fala pelo WhatsApp. O escritório responde com organização.',
  'lede' => 'A conversa entra no funil, vira cliente e vira processo sem ninguém redigitar nada. O histórico não recomeça: documentos, conversas, tarefas e o canal por onde a pessoa chegou seguem junto com ela.',
  'itens' => [
    'WhatsApp do escritório ligado ao cliente e ao processo',
    'Funil de prospecção com as etapas do seu escritório',
    'Canal de aquisição registrado: de onde cada lead veio',
    'Um clique transforma a prospecção em cliente',
    'O sistema avisa quando o cliente já existe, antes de duplicar',
  ],
  'wa' => ['Olá Bruno, quero centralizar o atendimento e a prospecção do meu escritório no Yuris!', 'Centralizar meu atendimento'],
]);
