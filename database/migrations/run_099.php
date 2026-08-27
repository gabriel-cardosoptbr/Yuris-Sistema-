<?php
/**
 * Runner da migration 099 (seed do prompt universal v3 OTIMIZADO do agente).
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_099.php
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_099.php
 *
 * Idempotente: desativa as versoes anteriores do prompt GLOBAL e insere/atualiza a v3
 * (ativa). Reusa o schema_json do prompt existente (secao 23). Reaplicar e seguro.
 * Rollback: reativar a v2 (UPDATE ai_prompts SET active=1 WHERE version='v2' ...; e
 * active=0 nas demais) ou pelo editor do Painel Master (aba Agente IA, historico).
 */
require_once __DIR__ . '/../../app/Core/Database.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Migration 099: prompt universal v3 (otimizado) ==\n";
echo "DB: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

$seed = require __DIR__ . '/../seeds/ai_prompt_v3.php';

// Schema (secao 23) reaproveitado do prompt global ja semeado na 097/098.
$schema = $pdo->query(
    "SELECT schema_json FROM ai_prompts
      WHERE name = 'pre_atendimento_universal' AND account_id IS NULL AND schema_json IS NOT NULL
      ORDER BY id ASC LIMIT 1"
)->fetchColumn();
$schema = $schema ?: null;

// Desativa qualquer versao global anterior deste prompt.
$pdo->exec("UPDATE ai_prompts SET active = 0 WHERE name = 'pre_atendimento_universal' AND account_id IS NULL");
echo "  [ok] versoes globais anteriores desativadas\n";

// Upsert da v3 (ativa).
$st = $pdo->prepare("SELECT id FROM ai_prompts WHERE name = ? AND version = ? AND account_id IS NULL LIMIT 1");
$st->execute([$seed['name'], $seed['version']]);
$id = $st->fetchColumn();

if ($id) {
    $pdo->prepare(
        "UPDATE ai_prompts SET template = :tpl, changelog = :chg, schema_json = :sch, active = 1 WHERE id = :id"
    )->execute([':tpl' => $seed['template'], ':chg' => $seed['changelog'], ':sch' => $schema, ':id' => (int)$id]);
    echo "  [ok] prompt v3 atualizado (id={$id}) e ativado\n";
} else {
    $pdo->prepare(
        "INSERT INTO ai_prompts (account_id, name, version, template, schema_json, changelog, active)
         VALUES (NULL, :name, :ver, :tpl, :sch, :chg, 1)"
    )->execute([
        ':name' => $seed['name'], ':ver' => $seed['version'], ':tpl' => $seed['template'],
        ':sch' => $schema, ':chg' => $seed['changelog'],
    ]);
    echo "  [ok] prompt v3 inserido e ativado (id=" . $pdo->lastInsertId() . ")\n";
}

$len = mb_strlen($seed['template']);
echo "  [info] tamanho do prompt v3: {$len} caracteres (~" . (int)round($len / 4) . " tokens estimados)\n";

$active = $pdo->query(
    "SELECT version FROM ai_prompts WHERE name='pre_atendimento_universal' AND active=1 ORDER BY id DESC LIMIT 1"
)->fetchColumn();
echo "  [check] versao ativa agora: {$active}\n";
echo "== Migration 099 concluida ==\n";
