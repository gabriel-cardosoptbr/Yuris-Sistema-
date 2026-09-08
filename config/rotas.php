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

    /* ---------------------------------------------------------------------
     * LGPD: a pasta que sombreava a pagina
     * ---------------------------------------------------------------------
     * Existia public/lgpd.php (a pagina publica "LGPD & Seguranca") E a pasta
     * public/lgpd/ (as telas do titular). A pasta ganhava do arquivo: o mod_dir
     * respondia 301 para /lgpd/, que nao tinha index.php, e o resultado em
     * producao era 403. Como /lgpd.php e link no rodape de TODA pagina legal e
     * da landing, e o nginx converte .php para a forma limpa, o visitante
     * percorria /lgpd.php -> 301 /lgpd -> 301 /lgpd/ -> 403.
     *
     * As duas telas do titular sairam de public/ e foram para
     * app/Lgpd/Paginas/. O conflito de nome acabou (/lgpd resolve para
     * public/lgpd.php pela sondagem normal) e, morando fora de public/, elas
     * tem UM endereco so: o declarado aqui. Renomear a pasta dentro de public/
     * nao bastaria, porque o Apache serve arquivo existente direto, sem
     * consultar o router, e o caminho fisico viraria um segundo endereco
     * indexavel para a mesma pagina.
     *
     * OS ENDERECOS ABAIXO NAO PODEM MUDAR NUNCA. O link de acompanhamento
     * (/lgpd/acompanhar.php?token=...) e montado em public/api/lgpd/request.php
     * e ENVIADO POR E-MAIL ao titular de dados. E-mail ja enviado nao se
     * corrige. As duas formas continuam valendo, com e sem .php.
     */
    '/lgpd/solicitar' => [
        'arquivo'  => 'app/Lgpd/Paginas/solicitar.php',
        'canonica' => '/lgpd/solicitar',
    ],
    '/lgpd/acompanhar' => [
        'arquivo'  => 'app/Lgpd/Paginas/acompanhar.php',
        'canonica' => '/lgpd/acompanhar',
    ],

];
