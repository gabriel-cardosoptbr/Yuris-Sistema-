-- =============================================================================
-- MIGRATION 047 — 2FA / TOTP para super_admin (LGPD P0 / C-09)
-- Data: 2026-05-23
--
-- ENDEREÇA: Auditoria LGPD Fase 1, vulnerabilidade C-09.
--
-- PROBLEMA:
--   Painel Master tem acesso cross-tenant total (criar/editar contas, resetar
--   senha de qualquer usuário, gestão de billing). Estava protegido APENAS por
--   senha + rate-limit. Qualquer phishing comprometia o SaaS inteiro.
--
-- SOLUÇÃO:
--   Adiciona campos para autenticação 2FA via TOTP (RFC 6238). Compatível com
--   Google Authenticator, Authy, 1Password, Bitwarden etc. Backup codes para
--   recuperação. Secret cifrado at-rest (AES-256-CBC com MFA_ENCRYPTION_KEY do .env).
--
-- MODO: OPT-IN.
--   • mfa_enabled DEFAULT 0 — super_admins continuam logando com só senha.
--   • Banner no Painel Master convida a configurar 2FA.
--   • Quando super_admin configura, vira obrigatório SÓ para ele.
--
-- IDEMPOTENTE: usa INFORMATION_SCHEMA pra checar antes de tentar adicionar.
-- =============================================================================

-- ─── 1. mfa_enabled ─────────────────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'super_admins'
    AND COLUMN_NAME = 'mfa_enabled'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE super_admins ADD COLUMN mfa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER ativo',
  'SELECT "mfa_enabled already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 2. mfa_secret (Base32 cifrado AES-256-CBC) ──────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'super_admins'
    AND COLUMN_NAME = 'mfa_secret'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE super_admins ADD COLUMN mfa_secret VARBINARY(255) DEFAULT NULL COMMENT "Base32 secret cifrado (AES-256-CBC com MFA_ENCRYPTION_KEY)"',
  'SELECT "mfa_secret already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 3. mfa_backup_codes_hash (JSON de bcrypt hashes) ──────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'super_admins'
    AND COLUMN_NAME = 'mfa_backup_codes_hash'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE super_admins ADD COLUMN mfa_backup_codes_hash TEXT DEFAULT NULL COMMENT "JSON array de 10 backup codes (bcrypt). Códigos one-time, removidos ao usar."',
  'SELECT "mfa_backup_codes_hash already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 4. timestamps auditáveis ───────────────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'super_admins'
    AND COLUMN_NAME = 'mfa_setup_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE super_admins ADD COLUMN mfa_setup_at DATETIME DEFAULT NULL COMMENT "Quando 2FA foi habilitado"',
  'SELECT "mfa_setup_at already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'super_admins'
    AND COLUMN_NAME = 'mfa_last_used_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE super_admins ADD COLUMN mfa_last_used_at DATETIME DEFAULT NULL COMMENT "Último uso bem-sucedido de código TOTP ou backup"',
  'SELECT "mfa_last_used_at already exists" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─── Confirmação ────────────────────────────────────────────────────────────
SELECT 'Migration 047 (super_admin MFA) executada em' AS msg,
       NOW() AS executed_at,
       (SELECT COUNT(*) FROM super_admins) AS super_admins_total,
       (SELECT COUNT(*) FROM super_admins WHERE mfa_enabled = 1) AS com_mfa,
       (SELECT COUNT(*) FROM super_admins WHERE mfa_enabled = 0) AS sem_mfa;
