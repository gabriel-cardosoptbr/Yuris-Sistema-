<?php

/**
 * rotas.php — a tabela de rotas de public/ (debito D3, 08/09/2026).
 *
 * ---------------------------------------------------------------------------
 * O QUE ENTRA AQUI
 * ---------------------------------------------------------------------------
 * So o que a sondagem automatica NAO resolve sozinha:
 *
 *   · pagina que mora FORA de public/ (o motivo de a camada existir);
 *   · endereco que aponta para arquivo de outro nome (a raiz e a home v2);
 *   · apelido que precisa continuar respondendo por compatibilidade.
 *
 * Pagina comum de public/ NAO precisa ser declarada: /clientes resolve sozinho
 * para public/clientes.php, e /crm-juridico/ para public/crm-juridico/index.php.
 * Declarar o que ja funciona so cria uma segunda fonte de verdade para
 * divergir da primeira.
 *
 * ---------------------------------------------------------------------------
 * FORMATO
 * ---------------------------------------------------------------------------
 *   'URL' => 'caminho/do/arquivo.php'                      (relativo a raiz)
 *   'URL' => ['arquivo' => '...', 'canonica' => '/outra']  (forma completa)
 *
 * 'canonica' e o endereco oficial daquela pagina. E o que sai no
 * <link rel="canonical">. Sem ele, o valor e a propria chave.
 *
 * A URL declarada casa nas formas equivalentes: '/dpo' atende /dpo, /dpo.php
 * e /dpo/. Nao declare as tres.
 *
 * ---------------------------------------------------------------------------
 * REGRA
 * ---------------------------------------------------------------------------
 * Mudar uma CHAVE daqui muda um endereco publico. Trate como mudanca externa:
 * link salvo, link em e-mail ja enviado e webhook cadastrado apontam para o
 * endereco antigo. Acrescentar chave nova e seguro; renomear nao e.
 *
 * scripts/tests/rotas_test.php confere que toda chave aponta para arquivo real
 * e que nenhuma pagina de public/ ficou inalcancavel.
 */

return [

    // A home. Desde 15/06/2026 a raiz serve a landing v2; a v1 institucional
    // ficou preservada em public/index-v1-legacy.php (rollback: apontar esta
    // rota para la). Antes do D3 isso era um require dentro de public/index.php,
    // que agora e o front controller.
    '/' => [
        'arquivo'  => 'public/v2/index.php',
        'canonica' => '/',
    ],

    // Em producao o nginx reescreve /index para /index.php, que servia a home.
    // Declarado para o comportamento nao depender do rewrite do host.
    '/index' => [
        'arquivo'  => 'public/v2/index.php',
        'canonica' => '/',
    ],

];
