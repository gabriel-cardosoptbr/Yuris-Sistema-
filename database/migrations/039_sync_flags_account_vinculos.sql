-- 039 — Toggle de sincronização por vínculo matriz↔filial.
--
-- Adiciona controle granular: a matriz pode ativar/desativar sincronização
-- por filial, e dentro disso escolher quais módulos puxar
-- (processos / cards / tarefas).
--
-- Default = tudo ligado (mantém comportamento atual).
-- Quando sync_enabled = 0, nenhum módulo é puxado dessa filial,
-- independentemente dos flags individuais.
--
-- Idempotente: usa information_schema pra checar antes de ALTER.

DELIMITER //

DROP PROCEDURE IF EXISTS yuris_add_sync_columns//
CREATE PROCEDURE yuris_add_sync_columns()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'account_vinculos'
      AND COLUMN_NAME  = 'sync_enabled'
  ) THEN
    ALTER TABLE account_vinculos
      ADD COLUMN sync_enabled  TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Toggle mestre: matriz puxa dados desta filial?',
      ADD COLUMN sync_processos TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Sincronizar processos da filial?',
      ADD COLUMN sync_cards     TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Sincronizar cards/leads (prospecção)?',
      ADD COLUMN sync_tarefas   TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Sincronizar tarefas (boards)?',
      ADD COLUMN sync_updated_at DATETIME NULL DEFAULT NULL
        COMMENT 'Última alteração dos flags de sincronização';
  END IF;
END//

CALL yuris_add_sync_columns()//
DROP PROCEDURE yuris_add_sync_columns//

DELIMITER ;
