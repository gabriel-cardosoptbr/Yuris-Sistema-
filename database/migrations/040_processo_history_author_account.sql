-- 040 — Adiciona origem (matriz/filial) do autor no histórico processual.
--
-- Hoje processo_history grava apenas user_email (nome de exibição).
-- Quando uma matriz vê histórico de um processo da filial (sync ativo),
-- não dá pra distinguir se a ação foi feita por alguém da matriz ou da filial.
-- Esta migration adiciona contexto do autor pra renderizar badge MATRIZ/FILIAL.

DELIMITER //

DROP PROCEDURE IF EXISTS yuris_add_author_account_cols//
CREATE PROCEDURE yuris_add_author_account_cols()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'processo_history'
      AND COLUMN_NAME  = 'author_account_id'
  ) THEN
    ALTER TABLE processo_history
      ADD COLUMN author_account_id   INT NULL
        COMMENT 'Conta do usuário que executou a ação (snapshot, não FK)',
      ADD COLUMN author_account_tipo VARCHAR(20) NULL
        COMMENT 'matriz | filial — snapshot no momento da ação',
      ADD COLUMN author_account_nome VARCHAR(150) NULL
        COMMENT 'Nome da conta do autor — snapshot pra exibição',
      ADD INDEX idx_ph_author_account (author_account_id);
  END IF;
END//

CALL yuris_add_author_account_cols()//
DROP PROCEDURE yuris_add_author_account_cols//

DELIMITER ;
