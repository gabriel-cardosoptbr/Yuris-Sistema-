-- =============================================================================
-- MIGRATION 051 — LGPD: Retenção e Anonimização (Art. 16, 18 IV/V/VI)
-- Data: 2026-05-23
--
-- ENDEREÇA: Auditoria LGPD Fase 1, Etapa 7.
-- Cria política de retenção configurável + colunas anonymized_at em tabelas
-- com PII + log de operações de anonimização.
--
-- IDEMPOTENTE: CREATE TABLE IF NOT EXISTS + INFORMATION_SCHEMA check.
-- =============================================================================

-- ─── 1. retention_policies — política configurável de retenção ──────────────
CREATE TABLE IF NOT EXISTS retention_policies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entidade VARCHAR(100) NOT NULL
    COMMENT 'Ex: webhook_logs | login_attempts | whatsapp_messages_payload | emails_outbox_body',
  campo_referencia VARCHAR(80) NOT NULL DEFAULT 'created_at'
    COMMENT 'Coluna usada pra calcular idade (created_at, sent_at, last_activity_at)',
  retencao_dias INT NOT NULL DEFAULT 90
    COMMENT 'Tempo de retenção antes da ação',
  acao_apos ENUM('purge_fisico','anonimizar','arquivar') NOT NULL DEFAULT 'purge_fisico',
  base_legal TEXT NULL
    COMMENT 'Justificativa LGPD para a política (Art. 7, II — obrigação legal; legítimo interesse; etc)',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_run DATETIME NULL,
  ultimo_purge_count INT NOT NULL DEFAULT 0,
  ultimo_status VARCHAR(50) NULL COMMENT 'success | error | running',
  ultimo_erro TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_entidade (entidade),
  KEY idx_ativo_run (ativo, ultimo_run)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 2. anonymization_log — auditoria de cada anonimização ──────────────────
CREATE TABLE IF NOT EXISTS anonymization_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  entidade VARCHAR(100) NOT NULL COMMENT 'user | contato | card | processo | etc',
  entidade_id INT NOT NULL,
  motivo VARCHAR(255) NULL COMMENT 'Texto livre — ex: "Solicitação LGPD #42"',
  executado_por_user_id INT NULL,
  lgpd_request_id BIGINT NULL COMMENT 'FK lgpd_requests.id se origem foi solicitação',
  campos_afetados TEXT NULL COMMENT 'JSON array dos campos zerados/mascarados',
  ip VARCHAR(45),
  executado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_entidade (entidade, entidade_id),
  KEY idx_request (lgpd_request_id),
  KEY idx_data (executado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 3. Colunas anonymized_at + deletion_reason em tabelas com PII ──────────
-- users
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'anonymized_at');
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE users ADD COLUMN anonymized_at DATETIME NULL, ADD COLUMN deletion_reason VARCHAR(200) NULL, ADD KEY idx_anon (anonymized_at)',
  'SELECT "users.anonymized_at já existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- contatos
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contatos' AND COLUMN_NAME = 'anonymized_at');
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE contatos ADD COLUMN anonymized_at DATETIME NULL, ADD COLUMN deletion_reason VARCHAR(200) NULL, ADD KEY idx_anon (anonymized_at)',
  'SELECT "contatos.anonymized_at já existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- cards
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cards' AND COLUMN_NAME = 'anonymized_at');
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE cards ADD COLUMN anonymized_at DATETIME NULL, ADD COLUMN deletion_reason VARCHAR(200) NULL, ADD KEY idx_anon (anonymized_at)',
  'SELECT "cards.anonymized_at já existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- processos
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'processos' AND COLUMN_NAME = 'anonymized_at');
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE processos ADD COLUMN anonymized_at DATETIME NULL, ADD COLUMN deletion_reason VARCHAR(200) NULL, ADD KEY idx_anon (anonymized_at)',
  'SELECT "processos.anonymized_at já existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- whatsapp_messages — deleted_at + retention_until
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE whatsapp_messages ADD COLUMN deleted_at DATETIME NULL, ADD COLUMN retention_until DATETIME NULL, ADD KEY idx_deleted (deleted_at), ADD KEY idx_retention (retention_until)',
  'SELECT "whatsapp_messages.deleted_at já existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- chat_mensagens — deleted_at
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_mensagens' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE chat_mensagens ADD COLUMN deleted_at DATETIME NULL, ADD KEY idx_deleted (deleted_at)',
  'SELECT "chat_mensagens.deleted_at já existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─── 4. Seeds iniciais de retention_policies ────────────────────────────────
-- (idempotente: INSERT IGNORE por entidade UNIQUE)
INSERT IGNORE INTO retention_policies
  (entidade, campo_referencia, retencao_dias, acao_apos, base_legal, ativo) VALUES
  ('webhook_logs',              'created_at', 90,
   'purge_fisico',
   'Logs de entrega de webhook não tem valor probatório legal após 90 dias. Minimização (LGPD Art. 6 III).',
   1),
  ('login_attempts',            'created_at', 90,
   'purge_fisico',
   'Marco Civil Art. 15 (180 dias) — usamos prazo menor para tentativas falhas; bem-sucedidas seriam mantidas.',
   1),
  ('whatsapp_messages_payload', 'created_at', 30,
   'anonimizar',
   'raw_payload contém metadata + mediaKey que não precisa ficar após processado. NULL na coluna preserva metadata da mensagem.',
   1),
  ('emails_outbox_body',        'sent_at',     7,
   'purge_fisico',
   'Body HTML/text de email enviado pode conter tokens/links sensíveis. Após confirmação de envio (7d) o corpo é purgado.',
   1),
  ('gateway_events_received',   'received_at', 90,
   'purge_fisico',
   'Idempotency key já processada; payload sensível não deve persistir.',
   1);


-- ─── 5. Confirmação ────────────────────────────────────────────────────────
SELECT 'Migration 051 (LGPD retenção+anonimização) executada em' AS msg,
       NOW() AS executed_at,
       (SELECT COUNT(*) FROM retention_policies) AS policies_total,
       (SELECT COUNT(*) FROM retention_policies WHERE ativo=1) AS policies_ativas,
       (SELECT COUNT(*) FROM anonymization_log) AS anonimizacoes_executadas;
