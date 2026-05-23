-- =============================================================================
-- MIGRATION 037 — Fase 0 e 1 do Plano de Auditoria de Segurança
-- Data: 2026-05-21
--
-- Objetivos:
--   1. Zerar e remover users.senha_texto (LGPD/GDPR)
--   2. Criar tabela login_attempts (rate limiting)
--   3. Adicionar account_id em tabelas WhatsApp e Chat órfãs
--   4. Soft-delete em contatos
--   5. Índices em FKs sem cobertura
--
-- IDEMPOTENTE: usa IF NOT EXISTS / IF EXISTS onde possível.
-- =============================================================================

-- ── 1. Zerar e remover users.senha_texto ─────────────────────────────────────
UPDATE users SET senha_texto = NULL WHERE senha_texto IS NOT NULL;

-- DROP da coluna (envolto em PREPARE pra ser idempotente)
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'senha_texto'
);
SET @sql := IF(@col_exists > 0, 'ALTER TABLE users DROP COLUMN senha_texto', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 2. Tabela login_attempts (rate limiting) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  ip          VARCHAR(45) NOT NULL,
  login       VARCHAR(100) NOT NULL,
  success     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts_ip_login (ip, login, created_at),
  INDEX idx_login_attempts_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 3. account_id em whatsapp_messages (popular via JOIN com instances) ──────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'whatsapp_messages' AND column_name = 'account_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE whatsapp_messages ADD COLUMN account_id INT NULL AFTER id, ADD INDEX idx_wamsg_account (account_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Popula via JOIN com whatsapp_instances (que TEM account_id)
UPDATE whatsapp_messages m
  INNER JOIN whatsapp_instances i ON i.id = m.instance_id
  SET m.account_id = i.account_id
  WHERE m.account_id IS NULL AND i.account_id IS NOT NULL;


-- ── 4. account_id em whatsapp_chats ──────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'whatsapp_chats' AND column_name = 'account_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE whatsapp_chats ADD COLUMN account_id INT NULL AFTER id, ADD INDEX idx_wachat_account (account_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE whatsapp_chats c
  INNER JOIN whatsapp_instances i ON i.id = c.instance_id
  SET c.account_id = i.account_id
  WHERE c.account_id IS NULL AND i.account_id IS NOT NULL;


-- ── 5. account_id em whatsapp_contacts ───────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'whatsapp_contacts' AND column_name = 'account_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE whatsapp_contacts ADD COLUMN account_id INT NULL AFTER id, ADD INDEX idx_wacont_account (account_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE whatsapp_contacts c
  INNER JOIN whatsapp_instances i ON i.id = c.instance_id
  SET c.account_id = i.account_id
  WHERE c.account_id IS NULL AND i.account_id IS NOT NULL;


-- ── 6. account_id em whatsapp_chat_processos ─────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'whatsapp_chat_processos' AND column_name = 'account_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE whatsapp_chat_processos ADD COLUMN account_id INT NULL, ADD INDEX idx_wacp_account (account_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE whatsapp_chat_processos cp
  INNER JOIN processos p ON p.id = cp.processo_id
  SET cp.account_id = p.account_id
  WHERE cp.account_id IS NULL AND p.account_id IS NOT NULL;


-- ── 7. account_id em chat_mensagens (deriva via chat_conversas) ──────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'chat_mensagens' AND column_name = 'account_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE chat_mensagens ADD COLUMN account_id INT NULL, ADD INDEX idx_chatm_account (account_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE chat_mensagens m
  INNER JOIN chat_conversas c ON c.id = m.conversa_id
  SET m.account_id = c.account_id
  WHERE m.account_id IS NULL AND c.account_id IS NOT NULL;


-- ── 8. account_id em chat_participantes ──────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'chat_participantes' AND column_name = 'account_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE chat_participantes ADD COLUMN account_id INT NULL, ADD INDEX idx_chatp_account (account_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE chat_participantes p
  INNER JOIN chat_conversas c ON c.id = p.conversa_id
  SET p.account_id = c.account_id
  WHERE p.account_id IS NULL AND c.account_id IS NOT NULL;


-- ── 9. account_id em chat_mencoes ────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'chat_mencoes' AND column_name = 'account_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE chat_mencoes ADD COLUMN account_id INT NULL, ADD INDEX idx_chatmen_account (account_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE chat_mencoes m
  INNER JOIN chat_mensagens cm ON cm.id = m.mensagem_id
  SET m.account_id = cm.account_id
  WHERE m.account_id IS NULL AND cm.account_id IS NOT NULL;


-- ── 10. account_id em user_permissions ───────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'user_permissions' AND column_name = 'account_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE user_permissions ADD COLUMN account_id INT NULL, ADD INDEX idx_userperm_account (account_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE user_permissions p
  INNER JOIN users u ON u.id = p.user_id
  SET p.account_id = u.account_id
  WHERE p.account_id IS NULL AND u.account_id IS NOT NULL;


-- ── 11. soft-delete em contatos ──────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'contatos' AND column_name = 'deleted_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE contatos ADD COLUMN deleted_at DATETIME NULL, ADD INDEX idx_contatos_deleted (deleted_at)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 12. Índices ausentes em FKs operacionais (performance + safety) ──────────
-- processo_history.processo_id
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'processo_history' AND index_name = 'idx_proch_processo'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE processo_history ADD INDEX idx_proch_processo (processo_id, created_at DESC)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- processo_prazos.processo_id
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'processo_prazos' AND index_name = 'idx_prpz_processo'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE processo_prazos ADD INDEX idx_prpz_processo (processo_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- processo_tarefas.processo_id
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'processo_tarefas' AND index_name = 'idx_prtf_processo'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE processo_tarefas ADD INDEX idx_prtf_processo (processo_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- card_history.card_id
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'card_history' AND index_name = 'idx_cdh_card'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE card_history ADD INDEX idx_cdh_card (card_id, created_at DESC)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- card_checklist_items.card_id
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'card_checklist_items' AND index_name = 'idx_cdck_card'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE card_checklist_items ADD INDEX idx_cdck_card (card_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- webhook_logs.webhook_id
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'webhook_logs' AND index_name = 'idx_whl_webhook'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE webhook_logs ADD INDEX idx_whl_webhook (webhook_id, created_at DESC)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── FIM. Registra timestamp da execução ──────────────────────────────────────
SELECT 'Migration 037 executada com sucesso em ' AS msg, NOW() AS executed_at;
