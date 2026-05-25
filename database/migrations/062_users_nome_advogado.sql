-- ============================================================================
-- Migration 062 — users.nome_advogado (busca DJEN por nome)
-- ============================================================================
--
-- Adiciona campo opcional users.nome_advogado pra busca no DJEN/intimações.
-- Se vazio, sistema usa users.nome como fallback.
-- Útil quando o nome de exibição (apelido) difere do nome formal cadastrado
-- na OAB e que aparece no Diário ("Dr. Fulano de Tal vs Fulano").
--
-- IDEMPOTENTE: consulta INFORMATION_SCHEMA antes do ALTER.
-- ============================================================================

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name   = 'users'
    AND column_name  = 'nome_advogado'
);
SET @sql := IF(@has_col = 0,
  'ALTER TABLE users
     ADD COLUMN nome_advogado VARCHAR(200) DEFAULT NULL AFTER oab_uf,
     ADD INDEX idx_nome_advogado (nome_advogado)',
  'SELECT "[062] users.nome_advogado já existe — skip" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT '[062] Migration aplicada: users.nome_advogado' AS resultado;
