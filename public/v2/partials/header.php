<?php
/**
 * header.php, cabeçalho da home. A marcação mora em includes/site_chrome.php
 * (ys_header), a mesma das demais páginas públicas; aqui ficam só os destinos
 * próprios da home, que são âncoras da página.
 */
ys_header([
    'logoHref' => '#inicio',
    'ctaMsg'   => $content['cta_demo'],
    'nav'      => [
        ['#inicio', 'Início'],
        ['#recursos', 'Recursos'],
        ['#juridico', 'Jurídico'],
        ['#automacao', 'Automação'],
        ['#seguranca', 'Segurança'],
        ['/planos', 'Planos'],
        ['#demonstracao', 'Demonstração'],
    ],
]);
