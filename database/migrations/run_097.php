<?php
/**
 * Runner standalone da migration 097 (Assistente de Pre-Atendimento Juridico).
 *
 * Uso local:  C:\xampp\php\php.exe database/migrations/run_097.php
 * Uso prod:   docker exec -i yuris_app php /var/www/html/database/migrations/run_097.php
 *
 * Idempotente: estende agent_configs (colunas guardadas por information_schema),
 * cria as 7 tabelas ai_* (CREATE IF NOT EXISTS) e semeia o catalogo global de areas
 * + o prompt universal v1. Reaplicar e seguro.
 *
 * PREMISSA ZERO: o agente referencia o canal existente (agent_configs.whatsapp_instance_id);
 * NAO cria conexao/credencial/QR. Credenciais Evolution ficam em whatsapp_settings.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Migration 097: Assistente de Pre-Atendimento Juridico ==\n";
echo "Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
echo "DB: "     . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

$hasCol = function (string $t, string $c) use ($pdo): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$t, $c]);
    return (int)$s->fetchColumn() > 0;
};

// ── 1) Estende agent_configs ───────────────────────────────────────────────
$cols = [
    'status'                  => "VARCHAR(20)  NOT NULL DEFAULT 'inactive'",
    'model'                   => "VARCHAR(60)  NULL DEFAULT 'gpt-4o-mini'",
    'max_questions'           => "TINYINT      NOT NULL DEFAULT 6",
    'office_name'             => "VARCHAR(150) NULL",
    'office_description'      => "TEXT         NULL",
    'office_information_json' => "JSON         NULL",
    'behavior_json'           => "JSON         NULL",
    'handoff_config_json'     => "JSON         NULL",
    'usage_limits_json'       => "JSON         NULL",
    'initial_message'         => "TEXT         NULL",
    'closing_message'         => "TEXT         NULL",
    'urgency_message'         => "TEXT         NULL",
    'handoff_message'         => "TEXT         NULL",
    'retention_days'          => "INT          NOT NULL DEFAULT 180",
    'prompt_version_id'       => "INT          NULL",
];
foreach ($cols as $name => $def) {
    if (!$hasCol('agent_configs', $name)) {
        $pdo->exec("ALTER TABLE agent_configs ADD COLUMN {$name} {$def}");
        echo "  [ok] agent_configs.+{$name}\n";
    } else {
        echo "  [skip] agent_configs.{$name} ja existe\n";
    }
}

// ── 2..8) Tabelas ai_* (CREATE IF NOT EXISTS) ──────────────────────────────
$tables = [];

$tables['ai_area_catalog'] = "CREATE TABLE IF NOT EXISTS ai_area_catalog (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  subareas_json JSON NULL,
  aliases_json JSON NULL,
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_area_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$tables['ai_intake_areas'] = "CREATE TABLE IF NOT EXISTS ai_intake_areas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  agent_id INT NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(150) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  responsible_user_id INT NULL,
  responsible_team_id INT NULL,
  priority INT NOT NULL DEFAULT 0,
  aliases_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_agent_area (agent_id, code),
  KEY idx_aia_account (account_id),
  CONSTRAINT fk_aia_agent FOREIGN KEY (agent_id) REFERENCES agent_configs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$tables['ai_intake_sessions'] = "CREATE TABLE IF NOT EXISTS ai_intake_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  branch_id INT NULL,
  agent_id INT NULL,
  channel_id INT NOT NULL,
  remote_jid VARCHAR(120) NOT NULL,
  contact_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  controller_mode VARCHAR(20) NOT NULL DEFAULT 'bot_active',
  intent VARCHAR(40) NULL,
  primary_area VARCHAR(50) NULL,
  secondary_areas_json JSON NULL,
  classification_confidence DECIMAL(4,3) NULL,
  urgency_level VARCHAR(12) NOT NULL DEFAULT 'normal',
  urgency_reasons_json JSON NULL,
  current_state VARCHAR(40) NOT NULL DEFAULT 'new',
  current_question VARCHAR(60) NULL,
  question_count INT NOT NULL DEFAULT 0,
  asked_questions_json JSON NULL,
  collected_data_json JSON NULL,
  mentioned_documents_json JSON NULL,
  summary TEXT NULL,
  assigned_user_id INT NULL,
  assigned_team_id INT NULL,
  prospect_id INT NULL,
  task_id INT NULL,
  last_message_at DATETIME NULL,
  expires_at DATETIME NULL,
  closed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ais_channel_jid (channel_id, remote_jid),
  KEY idx_ais_account (account_id),
  KEY idx_ais_status (status),
  KEY idx_ais_active (channel_id, remote_jid, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$tables['ai_intake_messages'] = "CREATE TABLE IF NOT EXISTS ai_intake_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  session_id INT NULL,
  whatsapp_message_id INT NULL,
  wamid VARCHAR(120) NULL,
  direction ENUM('inbound','outbound') NOT NULL,
  origin VARCHAR(20) NOT NULL DEFAULT 'unknown',
  content TEXT NULL,
  message_type VARCHAR(30) NULL,
  ai_called TINYINT(1) NOT NULL DEFAULT 0,
  structured_result_json JSON NULL,
  input_tokens INT NULL,
  output_tokens INT NULL,
  estimated_cost DECIMAL(12,6) NULL,
  latency_ms INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aim_session (session_id),
  KEY idx_aim_wamid (wamid),
  KEY idx_aim_account (account_id),
  KEY idx_aim_origin_wamid (origin, wamid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$tables['ai_intake_handoffs'] = "CREATE TABLE IF NOT EXISTS ai_intake_handoffs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  session_id INT NOT NULL,
  reason VARCHAR(60) NULL,
  urgency VARCHAR(12) NULL,
  user_id INT NULL,
  team_id INT NULL,
  prospect_id INT NULL,
  task_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aih_session (session_id),
  KEY idx_aih_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$tables['ai_usage_log'] = "CREATE TABLE IF NOT EXISTS ai_usage_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  agent_id INT NULL,
  session_id INT NULL,
  provider VARCHAR(30) NULL,
  model VARCHAR(60) NULL,
  operation VARCHAR(40) NULL,
  input_tokens INT NOT NULL DEFAULT 0,
  output_tokens INT NOT NULL DEFAULT 0,
  total_tokens INT NOT NULL DEFAULT 0,
  estimated_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
  success TINYINT(1) NOT NULL DEFAULT 1,
  error VARCHAR(255) NULL,
  latency_ms INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aul_account_date (account_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$tables['ai_prompts'] = "CREATE TABLE IF NOT EXISTS ai_prompts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NULL,
  name VARCHAR(80) NOT NULL,
  version VARCHAR(20) NOT NULL,
  template MEDIUMTEXT NOT NULL,
  schema_json JSON NULL,
  changelog TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_prompt (name, version, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

foreach ($tables as $name => $ddl) {
    $pdo->exec($ddl);
    echo "  [ok] tabela {$name} garantida\n";
}

// ── Seed do catalogo de areas + prompt v1 ──────────────────────────────────
$seed = require __DIR__ . '/../seeds/ai_intake_catalog.php';

$ins = $pdo->prepare(
    "INSERT INTO ai_area_catalog (code, name, subareas_json, aliases_json, sort, active)
     VALUES (:code, :name, :sub, :ali, :sort, 1)
     ON DUPLICATE KEY UPDATE name=VALUES(name), subareas_json=VALUES(subareas_json),
       aliases_json=VALUES(aliases_json), sort=VALUES(sort), active=1"
);
$i = 0;
foreach ($seed['areas'] as $a) {
    $ins->execute([
        ':code' => $a['code'],
        ':name' => $a['name'],
        ':sub'  => json_encode($a['subareas'] ?? [], JSON_UNESCAPED_UNICODE),
        ':ali'  => json_encode($a['aliases'] ?? [], JSON_UNESCAPED_UNICODE),
        ':sort' => $i++,
    ]);
}
echo "  [ok] catalogo de areas semeado: {$i} area(s)\n";

// Schema estrito do Structured Output (secao 23). Fonte de verdade em runtime:
// App\WhatsAppAgente\AiIntake\IntakeSchema::jsonSchema(). Aqui guardamos um snapshot p/ versionamento.
$schema = [
  'type' => 'object', 'additionalProperties' => false,
  'required' => ['intent','primary_practice_area','secondary_practice_areas','classification_confidence',
    'answer_relevant_to_current_question','extracted_data','urgency_level','urgency_reasons','risk_flags',
    'missing_essential_fields','suggested_next_question_key','enough_for_handoff','should_handoff_immediately',
    'should_stop_bot','summary'],
  'properties' => [
    'intent' => ['type'=>'string','enum'=>['office_information','new_intake','existing_case','human_request','document_submission','non_legal','unknown']],
    'primary_practice_area' => ['type'=>['string','null']],
    'secondary_practice_areas' => ['type'=>'array','items'=>['type'=>'string']],
    'classification_confidence' => ['type'=>'number'],
    'answer_relevant_to_current_question' => ['type'=>'boolean'],
    'extracted_data' => ['type'=>'object','additionalProperties'=>false,
      'required'=>['name','city','state','main_report','event_date','has_existing_case','case_number','has_deadline','deadline_description','has_hearing','hearing_date','has_official_notice','has_documents','mentioned_documents'],
      'properties'=>[
        'name'=>['type'=>['string','null']], 'city'=>['type'=>['string','null']], 'state'=>['type'=>['string','null']],
        'main_report'=>['type'=>['string','null']], 'event_date'=>['type'=>['string','null']],
        'has_existing_case'=>['type'=>['boolean','null']], 'case_number'=>['type'=>['string','null']],
        'has_deadline'=>['type'=>['boolean','null']], 'deadline_description'=>['type'=>['string','null']],
        'has_hearing'=>['type'=>['boolean','null']], 'hearing_date'=>['type'=>['string','null']],
        'has_official_notice'=>['type'=>['boolean','null']], 'has_documents'=>['type'=>['boolean','null']],
        'mentioned_documents'=>['type'=>'array','items'=>['type'=>'string']],
      ]],
    'urgency_level' => ['type'=>'string','enum'=>['normal','high','critical']],
    'urgency_reasons' => ['type'=>'array','items'=>['type'=>'string']],
    'risk_flags' => ['type'=>'array','items'=>['type'=>'string']],
    'missing_essential_fields' => ['type'=>'array','items'=>['type'=>'string']],
    'suggested_next_question_key' => ['type'=>['string','null']],
    'enough_for_handoff' => ['type'=>'boolean'],
    'should_handoff_immediately' => ['type'=>'boolean'],
    'should_stop_bot' => ['type'=>'boolean'],
    'summary' => ['type'=>'string'],
  ],
];

$p = $seed['prompt'];
$exists = $pdo->prepare("SELECT id FROM ai_prompts WHERE name=? AND version=? AND account_id IS NULL LIMIT 1");
$exists->execute([$p['name'], $p['version']]);
if (!$exists->fetchColumn()) {
    $pdo->prepare(
        "INSERT INTO ai_prompts (account_id, name, version, template, schema_json, changelog, active)
         VALUES (NULL, :name, :ver, :tpl, :schema, :chg, 1)"
    )->execute([
        ':name'   => $p['name'],
        ':ver'    => $p['version'],
        ':tpl'    => $p['template'],
        ':schema' => json_encode($schema, JSON_UNESCAPED_UNICODE),
        ':chg'    => $p['changelog'] ?? '',
    ]);
    echo "  [ok] prompt v1 '{$p['name']}' semeado\n";
} else {
    echo "  [skip] prompt v1 '{$p['name']}' ja existe\n";
}

echo "== Migration 097 concluida ==\n";
