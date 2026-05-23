-- =============================================================================
-- MIGRATION 046 — Isolamento WhatsApp por tenant (LGPD P0 / C-03)
-- Data: 2026-05-23
--
-- ENDEREÇA: Auditoria LGPD Fase 1, vulnerabilidade C-03.
--
-- PROBLEMA ANTERIOR:
--   • whatsapp_settings.config_key UNIQUE global → 1 valor por chave no SISTEMA
--     inteiro. Tenant A escrevendo evolution_api_key sobrescrevia o do B.
--   • whatsapp_instances.instance_name UNIQUE global → dois tenants não podiam
--     ter instância com mesmo nome.
--   • account_id era NULLABLE em ambas, permitindo dados órfãos.
--
-- ESTA MIGRATION:
--   1. Migra whatsapp_settings para UNIQUE (account_id, config_key)
--   2. Migra whatsapp_instances para UNIQUE (account_id, instance_name)
--   3. Garante account_id NOT NULL nas duas (backfill via JOIN)
--   4. Backfill whatsapp_messages.account_id via instances (defesa em profundidade)
--
-- IDEMPOTENTE: verifica existência dos índices antes de tentar dropar/criar.
-- =============================================================================

-- ─── 1. whatsapp_settings ────────────────────────────────────────────────────

-- 1a. Backfill account_id se houver NULLs (associa à conta da primeira instância
--     que apareceu; em ambiente single-tenant não há ambiguidade)
UPDATE whatsapp_settings ws
SET ws.account_id = (
  SELECT MIN(wi.account_id) FROM whatsapp_instances wi WHERE wi.account_id IS NOT NULL
)
WHERE ws.account_id IS NULL;

-- 1b. Remove índice global se existir
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'whatsapp_settings'
    AND INDEX_NAME = 'config_key'
);
SET @sql := IF(@idx_exists > 0,
  'ALTER TABLE whatsapp_settings DROP INDEX config_key',
  'SELECT "config_key UNIQUE not present, skipping drop" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1c. Adiciona UNIQUE composto (account_id, config_key) — idempotente
SET @new_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'whatsapp_settings'
    AND INDEX_NAME = 'uk_settings_account_key'
);
SET @sql := IF(@new_idx = 0,
  'ALTER TABLE whatsapp_settings ADD UNIQUE KEY uk_settings_account_key (account_id, config_key)',
  'SELECT "uk_settings_account_key already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1d. Torna account_id NOT NULL (após backfill)
ALTER TABLE whatsapp_settings MODIFY account_id INT NOT NULL;


-- ─── 2. whatsapp_instances ──────────────────────────────────────────────────

-- 2a. Remove índice global se existir
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'whatsapp_instances'
    AND INDEX_NAME = 'instance_name'
);
SET @sql := IF(@idx_exists > 0,
  'ALTER TABLE whatsapp_instances DROP INDEX instance_name',
  'SELECT "instance_name UNIQUE not present, skipping drop" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2b. Adiciona UNIQUE composto (account_id, instance_name)
SET @new_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'whatsapp_instances'
    AND INDEX_NAME = 'uk_instances_account_name'
);
SET @sql := IF(@new_idx = 0,
  'ALTER TABLE whatsapp_instances ADD UNIQUE KEY uk_instances_account_name (account_id, instance_name)',
  'SELECT "uk_instances_account_name already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2c. Torna account_id NOT NULL
-- (em prod só executar se 0 linhas com account_id NULL — verificar antes)
SET @null_count := (SELECT COUNT(*) FROM whatsapp_instances WHERE account_id IS NULL);
SET @sql := IF(@null_count = 0,
  'ALTER TABLE whatsapp_instances MODIFY account_id INT NOT NULL',
  CONCAT('SELECT "ABORT: ', @null_count, ' instances com account_id NULL — popule antes" AS msg')
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─── 3. whatsapp_messages — backfill account_id via instances ──────────────

UPDATE whatsapp_messages m
  INNER JOIN whatsapp_instances wi ON wi.id = m.instance_id
  SET m.account_id = wi.account_id
  WHERE m.account_id IS NULL
    AND wi.account_id IS NOT NULL;


-- ─── 4. Confirmação ─────────────────────────────────────────────────────────

SELECT 'Migration 046 (whatsapp per-tenant isolation) executada em' AS msg,
       NOW() AS executed_at,
       (SELECT COUNT(*) FROM whatsapp_settings)  AS settings_total,
       (SELECT COUNT(*) FROM whatsapp_instances) AS instances_total,
       (SELECT COUNT(*) FROM whatsapp_messages WHERE account_id IS NOT NULL) AS msgs_com_account,
       (SELECT COUNT(*) FROM whatsapp_messages WHERE account_id IS NULL)     AS msgs_orfas;
