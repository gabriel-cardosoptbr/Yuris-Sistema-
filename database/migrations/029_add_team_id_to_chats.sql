-- ═══════════════════════════════════════════════════════════════════════════════
-- 029_add_team_id_to_chats.sql
-- Vincula conversas do WhatsApp a um setor (team).
--
-- POR QUE ESTA MIGRATION EXISTE:
--   O sistema de times (teams) foi criado na migration 028.
--   Para que cada conversa possa ser classificada por setor e filtrada
--   por usuários responsáveis, precisamos de uma FK em whatsapp_chats.
--
-- IMPACTO:
--   - Coluna nullable: nenhum dado existente é afetado
--   - Chats sem setor ficam com team_id = NULL ("Sem setor")
--   - Atribuição é sempre manual (usuário classifica a conversa)
--
-- SEGURO EXECUTAR: ADD COLUMN IF NOT EXISTS
-- ═══════════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

ALTER TABLE `whatsapp_chats`
  ADD COLUMN IF NOT EXISTS `team_id` INT DEFAULT NULL
      COMMENT 'Setor responsável pela conversa (FK para teams.id). NULL = sem setor.'
      AFTER `is_group`;

-- Índice para filtro performático por setor
DROP PROCEDURE IF EXISTS _idx_safe_029;
DELIMITER $$
CREATE PROCEDURE _idx_safe_029(tbl VARCHAR(100), idx VARCHAR(100), col VARCHAR(100))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = tbl
      AND INDEX_NAME   = idx
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD INDEX `', idx, '` (`', col, '`)');
    PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

CALL _idx_safe_029('whatsapp_chats', 'idx_chat_team', 'team_id');

DROP PROCEDURE IF EXISTS _idx_safe_029;
