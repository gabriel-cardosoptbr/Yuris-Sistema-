<?php
/**
 * secao-10b-cliente.php, #cliente (seção NOVA, entre Operação e Gestão).
 *
 * POR QUE ELA EXISTE
 *
 * A seção 10 (#operacao) conta o funil: o lead entra e caminha pelas etapas.
 * Ela termina no momento em que a pessoa fecha, e é justamente aí que estava o
 * buraco da narrativa do site: o que acontece DEPOIS do "sim".
 *
 * Em quase todo sistema jurídico, esse é o ponto em que a memória se perde. A
 * pessoa vira um cadastro novo, e tudo que aconteceu enquanto ela era
 * oportunidade fica para trás, num módulo que ninguém mais abre. Quem já passou
 * por isso reconhece o problema antes de terminar de ler o título.
 *
 * Por isso a conversão ganhou seção própria em vez de virar mais um bullet: ela
 * é a diferença que o leitor sente, não um detalhe de funcionalidade.
 *
 * A cena 'cliente' mostra UMA linha do tempo só, com a conversão marcada no
 * meio dela. É a imagem inteira do argumento: a conversão é um evento na
 * história, não o começo de outra.
 */
require_once __DIR__ . '/_cenas.php';

lp2_split([
  'id'      => 'cliente',
  'scene'   => 'cliente',
  'eyebrow' => 'Do lead ao cliente',
  'h2'      => 'O cliente fecha, e a história continua de onde parou.',
  'sub'     => 'Quando a prospecção vira cliente, nada é deixado para trás. O histórico não recomeça, e os processos, as conversas de WhatsApp, as tarefas, os documentos e o canal por onde aquela pessoa chegou seguem junto com ela.',
  'bullets' => [
    'Uma linha do tempo só, do primeiro contato em diante',
    'Documentos, etiquetas e campos do escritório acompanham',
    'Processos, conversas e tarefas continuam ligados',
    'O sistema avisa quando o cliente já existe, antes de duplicar',
  ],
  'glyph'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
  'wa'      => 'Olá Bruno, quero ver como o histórico do cliente acompanha a conversão no Yuris!',
  'cta'     => 'Ver a linha do tempo do cliente',
]);
