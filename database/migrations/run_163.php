<?php
/**
 * Migration 163: seções do Google Drive na ficha do cliente (Inovaize, 21/10/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do dono do produto: além da pasta do cliente, a ficha tem várias
 * seções do Drive, cada uma com um título e uma pasta ("Mídias prontas" em
 * cima, "Mídias editadas" embaixo), na ordem que a equipe quiser.
 *
 *   drive_secoes_cliente   título, pasta (dentro da pasta do cliente) e ordem
 *
 * Só tabela nova. IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_163.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_163.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 163' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$st->execute(['drive_secoes_cliente']);
if ((int) $st->fetchColumn() > 0) {
    echo "  = drive_secoes_cliente já existe\n";
} else {
    echo "  + drive_secoes_cliente\n";
    if (!$dryRun) {
        $pdo->exec("CREATE TABLE drive_secoes_cliente (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            account_id   INT NOT NULL,
            cliente_id   INT NOT NULL,
            titulo       VARCHAR(120) NOT NULL,
            folder_id    VARCHAR(128) NOT NULL,
            folder_nome  VARCHAR(255) NULL,
            ordem        INT NOT NULL DEFAULT 0,
            criado_por   INT NULL,
            criado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_dsc_cliente (account_id, cliente_id, ordem)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
