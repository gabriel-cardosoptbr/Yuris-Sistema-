-- =============================================================================
-- MIGRATION 041 — Painel Master / Cadastro Rico (matriz, filial, advogado)
-- Data: 2026-05-22
--
-- Adiciona:
--   • accounts: razao_social, cnpj, email, telefone, cidade, estado
--   • accounts.status: expandido para incluir 'trial','overdue','inactive'
--   • users: oab, oab_uf, is_advogado
--
-- Padrão de idempotência: usa information_schema pra detectar se a coluna
-- já existe, igual às migrations 037/038. Pode rodar N vezes sem erro.
-- =============================================================================


-- ── 1. accounts: razao_social ────────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = 'razao_social'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE accounts ADD COLUMN razao_social VARCHAR(191) NULL AFTER nome',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 2. accounts: cnpj ────────────────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = 'cnpj'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE accounts ADD COLUMN cnpj VARCHAR(20) NULL AFTER razao_social, ADD INDEX idx_accounts_cnpj (cnpj)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 3. accounts: email ───────────────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = 'email'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE accounts ADD COLUMN email VARCHAR(150) NULL AFTER cnpj',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 4. accounts: telefone ────────────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = 'telefone'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE accounts ADD COLUMN telefone VARCHAR(30) NULL AFTER email',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 5. accounts: cidade ──────────────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = 'cidade'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE accounts ADD COLUMN cidade VARCHAR(100) NULL AFTER telefone',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 6. accounts: estado (UF) ─────────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = 'estado'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE accounts ADD COLUMN estado CHAR(2) NULL AFTER cidade',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 7. accounts.status: expandir ENUM (idempotente — MODIFY rescreve toda hora,
-- mas mantém os valores antigos compatíveis) ────────────────────────────────
-- Antes:  enum('active','suspended','cancelled')
-- Depois: enum('active','trial','overdue','suspended','cancelled','inactive')
ALTER TABLE accounts MODIFY COLUMN status
  ENUM('active','trial','overdue','suspended','cancelled','inactive')
  NOT NULL DEFAULT 'active';


-- ── 8. users: oab ────────────────────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'oab'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE users ADD COLUMN oab VARCHAR(20) NULL AFTER codigo_advogado, ADD INDEX idx_users_oab (oab)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 9. users: oab_uf ─────────────────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'oab_uf'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE users ADD COLUMN oab_uf CHAR(2) NULL AFTER oab',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 10. users: is_advogado ───────────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'is_advogado'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE users ADD COLUMN is_advogado TINYINT(1) NOT NULL DEFAULT 0 AFTER oab_uf, ADD INDEX idx_users_is_advogado (is_advogado)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 11. users: telefone (alguns sistemas já têm — checa antes) ───────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'telefone'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE users ADD COLUMN telefone VARCHAR(30) NULL AFTER login',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 12. invoices: observacoes (gestão manual de pagamentos) ──────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'observacoes_cobranca'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE invoices ADD COLUMN observacoes_cobranca TEXT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 13. invoices: marcado_manualmente_por (auditoria de marcação manual) ─────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'marcado_manualmente_por'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE invoices ADD COLUMN marcado_manualmente_por INT NULL COMMENT "FK super_admins.id quando alterado manualmente"',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 14. invoices: marcado_manualmente_em (timestamp da marcação manual) ──────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'marcado_manualmente_em'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE invoices ADD COLUMN marcado_manualmente_em DATETIME NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 15. Backfill: codigo_advogado existente → is_advogado=1 ──────────────────
UPDATE users
SET is_advogado = 1
WHERE codigo_advogado IS NOT NULL
  AND codigo_advogado != ''
  AND is_advogado = 0;


SELECT 'Migration 041 (Painel Master cadastro rico) executada em' AS msg, NOW() AS executed_at;
