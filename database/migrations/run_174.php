<?php
/**
 * Migration 174: link do processo no tribunal informado à mão (05/11/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Reunião de 23/10/2026: quando a busca automática não acha o processo ou não
 * alcança o tribunal, a pessoa precisa de um caminho alternativo. A coluna
 * `processos.link_tribunal` guarda o endereço, validado em
 * Processo::normalizarLinkTribunal (só http e https). O sistema nunca acessa este
 * link sozinho: ele só vira o botão "Abrir" na ficha do processo.
 *
 * Uma coluna nova, nenhuma alteração nas existentes. IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_174.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_174.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 174' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$existe = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'processos' AND column_name = 'link_tribunal'");
if ((int) $existe->fetchColumn() > 0) {
    echo '  processos.link_tribunal: já existe' . PHP_EOL;
} elseif ($dryRun) {
    echo '  processos.link_tribunal: seria criada' . PHP_EOL;
} else {
    $pdo->exec('ALTER TABLE processos ADD COLUMN link_tribunal VARCHAR(500) NULL DEFAULT NULL AFTER vara_comarca');
    echo '  processos.link_tribunal: criada' . PHP_EOL;
}

echo 'Fim.' . PHP_EOL;
