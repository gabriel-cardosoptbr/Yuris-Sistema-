<?php

/**
 * index.php — front controller.
 *
 * Dois papeis, e os dois passam pelo mesmo caminho:
 *
 *  1. E o DirectoryIndex da raiz. Um GET em "/" cai aqui, e a rota "/" da
 *     config/rotas.php serve a landing v2 (home desde 15/06/2026; a v1
 *     institucional continua preservada em index-v1-legacy.php, e o rollback
 *     e apontar a rota "/" para ela).
 *
 *  2. E o destino do fallback de public/.htaccess: toda URL que NAO existe
 *     como arquivo chega aqui para o Router resolver, e o que ele nao resolver
 *     vira o 404 brandado.
 *
 * URL que corresponde a um arquivo existente nao passa por aqui: o Apache
 * serve direto, como sempre serviu.
 *
 * O require final e AQUI, e nao dentro do Router, de proposito: pagina PHP de
 * topo de pilha espera escopo global. Incluindo de dentro de um metodo, as
 * variaveis de topo do arquivo incluido virariam locais daquele metodo e todo
 * `global $x` de partial deixaria de enxerga-las.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$__arquivoDaRota = \App\Core\Router::resolveRequisicao();

if ($__arquivoDaRota !== null) {
    require $__arquivoDaRota;
}
