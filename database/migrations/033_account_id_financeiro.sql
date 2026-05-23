-- ═══════════════════════════════════════════════════════════════════════════════
-- 033_account_id_financeiro.sql
--
-- Adiciona account_id em tabelas que estavam vazando entre tenants:
--   - dre_accounts
--   - dre_codes
--   - taxes
--   - webhooks
--   - whatsapp_settings
--
-- Backfill: todos os registros existentes ficam na conta id=1 (matriz principal).
-- ═══════════════════════════════════════════════════════════════════════════════

ALTER TABLE dre_accounts
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_dre_accounts_account (account_id);
UPDATE dre_accounts SET account_id = 1 WHERE account_id IS NULL;

ALTER TABLE dre_codes
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_dre_codes_account (account_id);
UPDATE dre_codes SET account_id = 1 WHERE account_id IS NULL;

ALTER TABLE taxes
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_taxes_account (account_id);
UPDATE taxes SET account_id = 1 WHERE account_id IS NULL;

ALTER TABLE webhooks
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_webhooks_account (account_id);
UPDATE webhooks SET account_id = 1 WHERE account_id IS NULL;

ALTER TABLE whatsapp_settings
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_whatsapp_settings_account (account_id);
UPDATE whatsapp_settings SET account_id = 1 WHERE account_id IS NULL;
