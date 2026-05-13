-- ═══════════════════════════════════════════════════════════════════════════════
-- 027_add_missing_multitenancy_cols.sql
-- Alinha o schema do banco com o que os Models PHP esperam.
--
-- POR QUE ESTA MIGRATION EXISTE:
--   O schema.sql canônico (gerado após 001-026) criou as tabelas de multi-tenancy
--   com estrutura simplificada. Os Models PHP (AccountVinculo, ResourceShare,
--   AdvogadoConvite, AccountNotification, Account) referenciam colunas adicionais
--   necessárias para rastreabilidade, auditoria e idempotência.
--   Esta migration adiciona as colunas faltantes sem quebrar dados existentes.
--
-- SEGURO EXECUTAR: todas as operações usam ADD COLUMN IF NOT EXISTS
-- ═══════════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. accounts
--    Faltam: plano, configuracoes, codigo_vinculo populado
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `accounts`
  ADD COLUMN IF NOT EXISTS `plano`          VARCHAR(50)  NOT NULL DEFAULT 'basico'
      COMMENT 'basico | profissional | enterprise'
      AFTER `status`,
  ADD COLUMN IF NOT EXISTS `configuracoes`  JSON         DEFAULT NULL
      COMMENT 'JSON de configurações personalizadas da conta'
      AFTER `plano`;

-- Garante que toda conta tenha codigo_vinculo preenchido (seed pode ter deixado NULL)
UPDATE `accounts`
SET `codigo_vinculo` = LOWER(CONCAT(
  LPAD(HEX(FLOOR(RAND() * 0xFFFF)), 4, '0'),
  LPAD(HEX(FLOOR(RAND() * 0xFFFF)), 4, '0'),
  LPAD(HEX(FLOOR(RAND() * 0xFFFF)), 4, '0'),
  LPAD(HEX(FLOOR(RAND() * 0xFFFF)), 4, '0')
))
WHERE `codigo_vinculo` IS NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. account_vinculos
--    Faltam: solicitado_por, aprovado_por, solicitado_em, aprovado_em,
--            suspenso_em, motivo_suspensao
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `account_vinculos`
  ADD COLUMN IF NOT EXISTS `solicitado_por`   INT       DEFAULT NULL
      COMMENT 'user_id que iniciou a solicitação de vínculo'
      AFTER `status`,
  ADD COLUMN IF NOT EXISTS `aprovado_por`     INT       DEFAULT NULL
      COMMENT 'user_id owner/admin da Matriz que aprovou'
      AFTER `solicitado_por`,
  ADD COLUMN IF NOT EXISTS `solicitado_em`    DATETIME  DEFAULT CURRENT_TIMESTAMP
      COMMENT 'Timestamp da solicitação'
      AFTER `aprovado_por`,
  ADD COLUMN IF NOT EXISTS `aprovado_em`      DATETIME  DEFAULT NULL
      COMMENT 'Timestamp da aprovação'
      AFTER `solicitado_em`,
  ADD COLUMN IF NOT EXISTS `suspenso_em`      DATETIME  DEFAULT NULL
      COMMENT 'Timestamp da suspensão'
      AFTER `aprovado_em`,
  ADD COLUMN IF NOT EXISTS `motivo_suspensao` TEXT      DEFAULT NULL
      COMMENT 'Motivo informado ao suspender'
      AFTER `suspenso_em`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. resource_shares
--    Faltam: to_user_id, criado_por, revoked_at, revoked_by
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `resource_shares`
  ADD COLUMN IF NOT EXISTS `to_user_id`  INT      DEFAULT NULL
      COMMENT 'NULL = toda a conta | preenchido = usuário específico'
      AFTER `to_account_id`,
  ADD COLUMN IF NOT EXISTS `criado_por`  INT      DEFAULT NULL
      COMMENT 'user_id que criou o share'
      AFTER `permission_level`,
  ADD COLUMN IF NOT EXISTS `revoked_at`  DATETIME DEFAULT NULL
      COMMENT 'Timestamp da revogação'
      AFTER `status`,
  ADD COLUMN IF NOT EXISTS `revoked_by`  INT      DEFAULT NULL
      COMMENT 'user_id que revogou'
      AFTER `revoked_at`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. advogado_convites
--    Faltam: convidado_user_id, responded_at, revoked_at, revoked_by
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `advogado_convites`
  ADD COLUMN IF NOT EXISTS `convidado_user_id` INT      DEFAULT NULL
      COMMENT 'Preenchido após aceite se o email já tem usuário no sistema'
      AFTER `from_account_id`,
  ADD COLUMN IF NOT EXISTS `responded_at`      DATETIME DEFAULT NULL
      COMMENT 'Timestamp do aceite ou rejeição'
      AFTER `expires_at`,
  ADD COLUMN IF NOT EXISTS `revoked_at`        DATETIME DEFAULT NULL
      COMMENT 'Timestamp da revogação'
      AFTER `responded_at`,
  ADD COLUMN IF NOT EXISTS `revoked_by`        INT      DEFAULT NULL
      COMMENT 'user_id que revogou o convite'
      AFTER `revoked_at`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. account_notifications
--    Falta: lida_em
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `account_notifications`
  ADD COLUMN IF NOT EXISTS `lida_em` DATETIME DEFAULT NULL
      COMMENT 'Timestamp de quando foi lida'
      AFTER `lida`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. Índice em to_user_id (resource_shares) para performance
-- ─────────────────────────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS _idx_safe_027;
DELIMITER $$
CREATE PROCEDURE _idx_safe_027(tbl VARCHAR(100), idx VARCHAR(100), col VARCHAR(100))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD INDEX `', idx, '` (`', col, '`)');
    PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

CALL _idx_safe_027('resource_shares', 'idx_to_user', 'to_user_id');
CALL _idx_safe_027('advogado_convites', 'idx_convidado_user', 'convidado_user_id');
CALL _idx_safe_027('advogado_convites', 'idx_convidado_email', 'convidado_email');
CALL _idx_safe_027('advogado_convites', 'idx_status', 'status');
CALL _idx_safe_027('advogado_convites', 'idx_expires', 'expires_at');

DROP PROCEDURE IF EXISTS _idx_safe_027;
