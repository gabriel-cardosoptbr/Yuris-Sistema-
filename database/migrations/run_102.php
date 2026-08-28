<?php
/**
 * Runner da migration 102 (telemetria de cache: ai_usage_log.cached_input_tokens).
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_102.php
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_102.php
 *
 * Idempotente: so adiciona a coluna se ainda nao existir (information_schema).
 * Rollback: ALTER TABLE ai_usage_log DROP COLUMN cached_input_tokens.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Migration 102: ai_usage_log.cached_input_tokens ==\n";
echo "DB: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

$has = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_usage_log' AND COLUMN_NAME = 'cached_input_tokens'");
$has->execute();
if ((int)$has->fetchColumn() > 0) {
    echo "  [skip] coluna cached_input_tokens ja existe\n";
} else {
    $pdo->exec("ALTER TABLE ai_usage_log ADD COLUMN cached_input_tokens INT NOT NULL DEFAULT 0 AFTER input_tokens");
    echo "  [ok] coluna cached_input_tokens adicionada\n";
}
echo "== Migration 102 concluida ==\n";
