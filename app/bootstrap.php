<?php
/**
 * app/bootstrap.php — o único require que um arquivo precisa fazer.
 *
 * Antes daqui, cada página e cada endpoint carregava classe por classe, com o
 * caminho escrito à mão: eram 1.077 linhas de `require_once` em 192 arquivos.
 * Isso tornava impossível mover um arquivo em `app/` sem caçar quem o carrega,
 * e o erro só aparecia em runtime, na linha exata.
 *
 * Agora o carregamento é automático. Quem precisa de uma classe só a usa:
 *
 *     require_once __DIR__ . '/../app/bootstrap.php';
 *     use App\Processos\Processo;
 *     $lista = Processo::list([...]);
 *
 * COMO O AUTOLOADER RESOLVE
 *
 *   App\Processos\Processo          ->  app/Processos/Processo.php
 *   App\WhatsAppAgente\AiIntake\X   ->  app/WhatsAppAgente/AiIntake/X.php
 *
 * O namespace espelha a pasta, sem exceção: isso é verificado por
 * `scripts/tests/class_refs_test.php`, que também confere se toda referência a
 * classe do projeto resolve. Rode-o ao mexer em namespace ou mover arquivo.
 *
 * As cinco classes de WhatsApp que ainda não declaram namespace estão no mapa
 * abaixo. Ao dar namespace a elas, some com a entrada correspondente.
 *
 * POR QUE O FUSO ESTÁ AQUI
 *
 * `date_default_timezone_set('UTC')` morava no topo de `Core/Database.php` e
 * valia porque todo mundo carregava aquele arquivo logo de cara. Com
 * autoloader, `Database.php` só carrega no PRIMEIRO USO da classe, e qualquer
 * `date()` antes disso pegaria o fuso do php.ini. Timestamp errado, sem erro
 * nenhum. Por isso o fuso passou a ser definido aqui, que roda primeiro.
 * (Segue também em `Database.php`, como rede, e é idempotente.)
 */

// 1) Fuso: precisa valer desde a primeira linha do request.
date_default_timezone_set('UTC');

// 2) Autoloader.
spl_autoload_register(static function (string $classe): void {
    // App\Dominio\Classe -> app/Dominio/Classe.php
    if (strncmp($classe, 'App\\', 4) === 0) {
        $arquivo = __DIR__ . DIRECTORY_SEPARATOR
                 . str_replace('\\', DIRECTORY_SEPARATOR, substr($classe, 4)) . '.php';
        if (is_file($arquivo)) {
            require_once $arquivo;
        }
    }
});
