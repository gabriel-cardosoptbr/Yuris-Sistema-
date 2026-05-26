-- ============================================================================
-- Migration 058 — dre_codes: UNIQUE por tenant (account_id, code)
-- ============================================================================
--
-- BUG CORRIGIDO:
--   A tabela `dre_codes` tinha `UNIQUE KEY (code)` GLOBAL — sem account_id.
--   Em multi-tenant isso quebra tudo: se o tenant A cria "01", "1.1.01" etc.,
--   o tenant B NUNCA consegue criar os mesmos códigos (que são os MAIS usuais
--   em plano de contas). O frontend mostrava apenas "Erro ao salvar código",
--   sem explicar a colisão.
--
--   Reproduzido empiricamente:
--     INSERT (account_id=1, code='01')  → SQLSTATE 23000 (Duplicate entry '01'
--     for key 'code') porque tenant 9 já tinha esse code.
--
-- CORREÇÃO:
--   1. Dropa UNIQUE(code) global
--   2. Adiciona UNIQUE(account_id, code) — cada tenant tem seu próprio
--      conjunto de códigos, isolados dos outros.
--
-- IDEMPOTENTE: usa INFORMATION_SCHEMA pra só dropar/adicionar se necessário.
-- ============================================================================

-- 1. DROPA o UNIQUE global se existir
SET @has_unique_code := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE()
    AND table_name   = 'dre_codes'
    AND index_name   = 'code'
    AND non_unique   = 0
);
SET @sql := IF(@has_unique_code > 0,
  'ALTER TABLE dre_codes DROP INDEX `code`',
  'SELECT "[058] UNIQUE(code) global ja foi removido — skip" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. ADICIONA UNIQUE(account_id, code) se ainda não existir
SET @has_tenant_unique := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE()
    AND table_name   = 'dre_codes'
    AND index_name   = 'uq_dre_codes_account_code'
);
SET @sql := IF(@has_tenant_unique = 0,
  'ALTER TABLE dre_codes ADD UNIQUE KEY uq_dre_codes_account_code (account_id, code)',
  'SELECT "[058] UNIQUE(account_id,code) ja existe — skip" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT '[058] Migration aplicada: dre_codes agora unique por (account_id, code)' AS resultado;
