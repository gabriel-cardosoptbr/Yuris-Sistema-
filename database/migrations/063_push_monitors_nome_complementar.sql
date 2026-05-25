-- ============================================================================
-- Migration 063 — push_monitors.nome_complementar (filtro AND no provider)
-- ============================================================================
--
-- Adiciona campo opcional pra filtro adicional. Quando preenchido, vai como
-- AND na query do provider (ex: OAB=357838 AND nomeAdvogado="Bruno...").
-- Aumenta precisão da busca DJEN — evita resultados falsos positivos quando
-- a OAB pode ser de outro advogado homônimo.
--
-- Uso típico:
--   tipo='oab', valor='357838', uf='SP', nome_complementar='Bruno Carreira Ferreira'
--   → DJEN GET /comunicacao?numeroOab=357838&ufOab=SP&nomeAdvogado=Bruno...
--
-- IDEMPOTENTE.
-- ============================================================================

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name   = 'push_monitors'
    AND column_name  = 'nome_complementar'
);
SET @sql := IF(@has_col = 0,
  'ALTER TABLE push_monitors
     ADD COLUMN nome_complementar VARCHAR(200) DEFAULT NULL AFTER valor_monitorado',
  'SELECT "[063] push_monitors.nome_complementar já existe — skip" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT '[063] Migration aplicada: push_monitors.nome_complementar' AS resultado;
