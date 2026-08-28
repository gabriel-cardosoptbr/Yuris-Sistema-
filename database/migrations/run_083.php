<?php
// Runner standalone da migration 083 (app_settings — config global do sistema).
//
// Uso local:  php database/migrations/run_083.php
// Uso prod:   docker exec -i yuris_app php /var/www/html/database/migrations/run_083.php
//
// Cria a tabela app_settings (key/value global). Idempotente: CREATE TABLE IF NOT EXISTS.

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Migration 083: app_settings (config global) ==\n";
echo "Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
echo "DB: "     . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS app_settings (
        config_key   VARCHAR(100) NOT NULL PRIMARY KEY,
        config_value MEDIUMTEXT   DEFAULT NULL,
        updated_at   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
echo "  [ok] tabela app_settings garantida\n";
echo "== Migration 083 concluída ==\n";
