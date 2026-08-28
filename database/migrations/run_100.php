<?php
/**
 * Runner da migration 100 (catalogo GLOBAL de modelos de IA — ai_models).
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_100.php
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_100.php
 *
 * Idempotente: CREATE TABLE IF NOT EXISTS + seed via INSERT IGNORE (nao sobrescreve
 * edicoes do admin ao reaplicar). Rollback: DROP TABLE ai_models (so volta a lista fixa
 * de fallback no codigo).
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Migration 100: ai_models (catalogo global de modelos) ==\n";
echo "DB: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS ai_models (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  provider   VARCHAR(30)  NOT NULL DEFAULT 'openai',
  code       VARCHAR(80)  NOT NULL,
  label      VARCHAR(120) NULL,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort       INT          NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_provider_code (provider, code),
  KEY idx_active (active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  [ok] tabela ai_models garantida\n";

$seed = [
    ['openai', 'gpt-4o-mini',  'gpt-4o-mini (econômico)', 1],
    ['openai', 'gpt-4.1-mini', 'gpt-4.1-mini',            2],
    ['openai', 'gpt-5.4-mini', 'gpt-5.4-mini',            3],
    ['openai', 'gpt-5.4-nano', 'gpt-5.4-nano',            4],
];
$ins = $pdo->prepare("INSERT IGNORE INTO ai_models (provider, code, label, active, sort) VALUES (?,?,?,1,?)");
$n = 0;
foreach ($seed as $s) { $ins->execute($s); $n += $ins->rowCount(); }
echo "  [ok] seed: {$n} modelo(s) novo(s) inserido(s) (existentes preservados)\n";

echo "Total de modelos no catalogo: " . (int)$pdo->query("SELECT COUNT(*) FROM ai_models")->fetchColumn() . "\n";
echo "== concluido ==\n";
