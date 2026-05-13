-- 024_add_account_id_and_role.sql
-- O que faz: Adiciona account_id (multi-tenancy) nas tabelas principais e
--            a coluna role em users. Migration 016 apresentou erros de sintaxe
--            e não foi aplicada corretamente.
-- Impacto sem isso: POST em cards/processos falha; sem isolamento de tenant.

SET NAMES utf8mb4;

-- ── users ────────────────────────────────────────────────────────────────────
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `account_id` INT DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `role` VARCHAR(50) NOT NULL DEFAULT 'user' AFTER `perfil`;

-- popula account_id dos usuários existentes: admin vira account 1, demais herdam
UPDATE `users` SET `account_id` = 1 WHERE `account_id` IS NULL;
UPDATE `users` SET `role` = 'owner' WHERE `perfil` = 'admin' AND `role` = 'user' LIMIT 1;

-- ── cards ────────────────────────────────────────────────────────────────────
ALTER TABLE `cards`
  ADD COLUMN IF NOT EXISTS `account_id` INT DEFAULT NULL AFTER `id`;

UPDATE `cards` SET `account_id` = 1 WHERE `account_id` IS NULL;

-- ── processos ────────────────────────────────────────────────────────────────
ALTER TABLE `processos`
  ADD COLUMN IF NOT EXISTS `account_id` INT DEFAULT NULL AFTER `id`;

UPDATE `processos` SET `account_id` = 1 WHERE `account_id` IS NULL;

-- ── contatos ─────────────────────────────────────────────────────────────────
ALTER TABLE `contatos`
  ADD COLUMN IF NOT EXISTS `account_id` INT DEFAULT NULL AFTER `id`;

UPDATE `contatos` SET `account_id` = 1 WHERE `account_id` IS NULL;

-- ── pipeline_columns ─────────────────────────────────────────────────────────
ALTER TABLE `pipeline_columns`
  ADD COLUMN IF NOT EXISTS `account_id` INT DEFAULT NULL AFTER `id`;

UPDATE `pipeline_columns` SET `account_id` = 1 WHERE `account_id` IS NULL;

-- ── task_boards ──────────────────────────────────────────────────────────────
ALTER TABLE `task_boards`
  ADD COLUMN IF NOT EXISTS `account_id` INT DEFAULT NULL AFTER `id`;

UPDATE `task_boards` SET `account_id` = 1 WHERE `account_id` IS NULL;

-- ── goals ────────────────────────────────────────────────────────────────────
ALTER TABLE `goals`
  ADD COLUMN IF NOT EXISTS `account_id` INT DEFAULT NULL AFTER `id`;

UPDATE `goals` SET `account_id` = 1 WHERE `account_id` IS NULL;

-- ── whatsapp_instances ───────────────────────────────────────────────────────
ALTER TABLE `whatsapp_instances`
  ADD COLUMN IF NOT EXISTS `account_id` INT DEFAULT NULL AFTER `id`;

UPDATE `whatsapp_instances` SET `account_id` = 1 WHERE `account_id` IS NULL;

-- ── índices de performance ───────────────────────────────────────────────────
-- Só cria se não existir (MariaDB/MySQL não tem IF NOT EXISTS pra índice, usa procedure)
DROP PROCEDURE IF EXISTS _add_index_safe;
DELIMITER $$
CREATE PROCEDURE _add_index_safe(tbl VARCHAR(100), idx VARCHAR(100), col VARCHAR(100))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD INDEX `', idx, '` (`', col, '`)');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

CALL _add_index_safe('users',              'idx_account_id', 'account_id');
CALL _add_index_safe('cards',              'idx_account_id', 'account_id');
CALL _add_index_safe('processos',          'idx_account_id', 'account_id');
CALL _add_index_safe('contatos',           'idx_account_id', 'account_id');
CALL _add_index_safe('pipeline_columns',   'idx_account_id', 'account_id');
CALL _add_index_safe('task_boards',        'idx_account_id', 'account_id');
CALL _add_index_safe('goals',              'idx_account_id', 'account_id');
CALL _add_index_safe('whatsapp_instances', 'idx_account_id', 'account_id');

DROP PROCEDURE IF EXISTS _add_index_safe;
