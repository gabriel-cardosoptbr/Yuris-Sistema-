-- ═══════════════════════════════════════════════════════════════════════════════
-- 030_expand_chat_multitenancy.sql
-- Expande o chat interno para suportar multi-tenancy completo.
--
-- NOVIDADES:
--   • account_id     → segrega conversas por tenant (obrigatório multi-tenancy)
--   • tipo expandido → setor | filial | matriz | advogado
--   • team_id        → FK para teams.id (tipo=setor)
--   • ref_account_id → conta destino para tipos filial/matriz (cross-account)
--   • archived_at    → soft-archive (conversa some da lista mas não é deletada)
--   • deleted_at     → soft-delete (conversa excluída, dados preservados)
--
-- CONSTRAINT ÚNICA:
--   • Somente 1 canal "geral" por conta (UNIQUE em tipo+account_id para tipo=geral)
--
-- SEGURO EXECUTAR: todos os ALTER usam IF NOT EXISTS / MODIFY idempotente
-- ═══════════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Expande ENUM tipo
--    Adicionamos: setor | filial | matriz | advogado
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `chat_conversas`
  MODIFY COLUMN `tipo`
    ENUM('direto','grupo','geral','setor','filial','matriz','advogado')
    NOT NULL DEFAULT 'direto'
    COMMENT 'direto=2 usuários | grupo=N | geral=canal conta | setor=team | filial/matriz=cross-account | advogado=externo';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Novas colunas
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `chat_conversas`
  ADD COLUMN IF NOT EXISTS `account_id`     INT UNSIGNED NULL
    COMMENT 'FK accounts.id — tenant dono da conversa'
    AFTER `id`,

  ADD COLUMN IF NOT EXISTS `team_id`        INT UNSIGNED NULL
    COMMENT 'FK teams.id — preenchido quando tipo = setor'
    AFTER `tipo`,

  ADD COLUMN IF NOT EXISTS `ref_account_id` INT UNSIGNED NULL
    COMMENT 'Conta de destino — preenchido nos tipos filial/matriz (cross-account)'
    AFTER `team_id`,

  ADD COLUMN IF NOT EXISTS `archived_at`    DATETIME NULL DEFAULT NULL
    COMMENT 'Preenchido quando a conversa é arquivada; NULL = ativa'
    AFTER `updated_at`,

  ADD COLUMN IF NOT EXISTS `deleted_at`     DATETIME NULL DEFAULT NULL
    COMMENT 'Soft-delete — conversa excluída mas dados preservados'
    AFTER `archived_at`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Popula account_id das conversas existentes com a conta padrão
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE `chat_conversas`
SET `account_id` = (SELECT `id` FROM `accounts` ORDER BY `id` ASC LIMIT 1)
WHERE `account_id` IS NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Índices de performance e integridade
-- ─────────────────────────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS _idx_safe_030;
DELIMITER $$
CREATE PROCEDURE _idx_safe_030(tbl VARCHAR(100), idx VARCHAR(100), col VARCHAR(200))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = tbl
      AND INDEX_NAME   = idx
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD INDEX `', idx, '` (', col, ')');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

CALL _idx_safe_030('chat_conversas', 'idx_cc_account',    '`account_id`');
CALL _idx_safe_030('chat_conversas', 'idx_cc_team',       '`team_id`');
CALL _idx_safe_030('chat_conversas', 'idx_cc_ref_acc',    '`ref_account_id`');
CALL _idx_safe_030('chat_conversas', 'idx_cc_deleted',    '`deleted_at`');
CALL _idx_safe_030('chat_conversas', 'idx_cc_archived',   '`archived_at`');

DROP PROCEDURE IF EXISTS _idx_safe_030;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. Constraint: apenas 1 canal "geral" por conta
--    UNIQUE KEY em (account_id, tipo) filtrado — simulado via índice parcial
--    MySQL não suporta partial index, então usamos unique em canal_geral_key
-- ─────────────────────────────────────────────────────────────────────────────
-- Adiciona coluna auxiliar para garantir unicidade do canal geral por conta
ALTER TABLE `chat_conversas`
  ADD COLUMN IF NOT EXISTS `canal_geral_key` VARCHAR(64) NULL DEFAULT NULL
    COMMENT 'NULL para não-geral; account_id.toString() para tipo=geral (garante unicidade)'
    AFTER `deleted_at`;

-- Preenche a coluna nos canais gerais existentes
UPDATE `chat_conversas`
SET `canal_geral_key` = CAST(`account_id` AS CHAR)
WHERE `tipo` = 'geral' AND `canal_geral_key` IS NULL AND `account_id` IS NOT NULL;

-- Cria unique key (se não existir)
DROP PROCEDURE IF EXISTS _uniq_safe_030;
DELIMITER $$
CREATE PROCEDURE _uniq_safe_030()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'chat_conversas'
      AND INDEX_NAME   = 'uq_cc_canal_geral'
  ) THEN
    ALTER TABLE `chat_conversas`
      ADD UNIQUE KEY `uq_cc_canal_geral` (`canal_geral_key`);
  END IF;
END$$
DELIMITER ;

CALL _uniq_safe_030();
DROP PROCEDURE IF EXISTS _uniq_safe_030;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. Verificação final
-- ─────────────────────────────────────────────────────────────────────────────
-- DESCRIBE chat_conversas;
-- SELECT id, account_id, nome, tipo, team_id, ref_account_id, archived_at, deleted_at FROM chat_conversas;
