<?php
/**
 * Runner da migration 101 (banco de PERGUNTAS POR AREA do pre-atendimento — ai_area_questions).
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_101.php
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_101.php
 *
 * Idempotente: CREATE TABLE IF NOT EXISTS + seed via INSERT IGNORE (nao sobrescreve edicoes do
 * admin ao reaplicar; apenas adiciona perguntas novas). Rollback: DROP TABLE ai_area_questions
 * (o motor volta a usar so as perguntas genericas).
 *
 * As perguntas sao DETERMINISTICAS e NAO entram no prompt mestre. O modelo so classifica a area;
 * o backend (IntakeEngine) escolhe a pergunta especifica. Sem custo de tokens.
 */
require_once __DIR__ . '/../../app/Models/Database.php';

use App\Models\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Migration 101: ai_area_questions (perguntas por area) ==\n";
echo "DB: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS ai_area_questions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  area_code     VARCHAR(50)  NOT NULL,
  question_key  VARCHAR(40)  NOT NULL,
  question_text VARCHAR(500) NOT NULL,
  help_text     VARCHAR(255) NULL,
  sort          INT          NOT NULL DEFAULT 0,
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_area_qkey (area_code, question_key),
  KEY idx_aq_area (area_code, active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  [ok] tabela ai_area_questions garantida\n";

// Seed: INSERT IGNORE preserva edicoes do admin; so adiciona o que ainda nao existe.
$seed = require __DIR__ . '/../seeds/ai_area_questions.php';
$ins = $pdo->prepare(
    "INSERT IGNORE INTO ai_area_questions (area_code, question_key, question_text, help_text, sort, active)
     VALUES (:code, :key, :text, :help, :sort, 1)"
);

$novos = 0; $areas = 0; $total = 0;
foreach ($seed as $areaCode => $questions) {
    $areas++;
    $i = 0;
    foreach ($questions as $q) {
        $total++;
        $ins->execute([
            ':code' => $areaCode,
            ':key'  => $q['key'],
            ':text' => $q['text'],
            ':help' => $q['help'] ?? null,
            ':sort' => $i++,
        ]);
        $novos += $ins->rowCount();
    }
}
echo "  [ok] seed: {$areas} area(s), {$total} pergunta(s) no catalogo, {$novos} nova(s) inserida(s) (existentes preservadas)\n";
echo "Total de perguntas na tabela: " . (int)$pdo->query("SELECT COUNT(*) FROM ai_area_questions")->fetchColumn() . "\n";
echo "== Migration 101 concluida ==\n";
