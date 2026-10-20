<?php
/**
 * Migration 161: a área do cliente marcada direto na ficha (ambientes da Inovaize).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido da Inovaize em 20/10/2026. Até aqui o cliente só entrava em Software ou
 * Digital pelo que já tinha cadastrado (valor com área, quadro, frequência,
 * campanha). Cliente recém-fechado, ainda sem nada disso, ficava "sem área". Agora
 * a equipe marca na ficha:
 *
 *   crm_cliente_areas   (cliente, área) marcada por alguém: software ou digital
 *
 * Soma às áreas que vêm do resto (App\Ambientes\AreaDoCliente). Só tabela nova.
 * IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_161.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_161.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 161' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$st->execute(['crm_cliente_areas']);
if ((int) $st->fetchColumn() > 0) {
    echo "  = crm_cliente_areas já existe\n";
} else {
    echo "  + crm_cliente_areas\n";
    if (!$dryRun) {
        $pdo->exec("CREATE TABLE crm_cliente_areas (
            cliente_id  INT NOT NULL,
            area        VARCHAR(20) NOT NULL COMMENT 'software, digital',
            account_id  INT NOT NULL,
            criado_por  INT NULL,
            criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (cliente_id, area),
            KEY idx_cliarea_conta (account_id, area)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
