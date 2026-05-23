-- =============================================================================
-- MIGRATION 042 — Corrige encoding dos planos seedados na 038
-- Data: 2026-05-22
--
-- Quando a migration 038 foi executada via cmd.exe (Windows), o terminal
-- recodificou os caracteres acentuados (UTF-8 → CP850 → UTF-8), corrompendo:
--   "Básico"      → "B├ísico"
--   "escritórios" → "escrit├│rios"
--
-- Esta migration usa HEX literals via CHAR(... USING utf8mb4) pra garantir
-- que o byte sequence chegue intacto ao MariaDB independente do terminal.
--
-- IDEMPOTENTE: o UPDATE só toca registros onde o nome ainda está corrompido
-- (LIKE com box-drawing char ├ = U+251C = 0xE2 0x94 0x9C em UTF-8).
-- =============================================================================

-- Restaura "Básico" (caso esteja "B├ísico")
UPDATE plans
SET nome = CONCAT('B', CHAR(0xC3, 0xA1 USING utf8mb4), 'sico')
WHERE slug = 'basico'
  AND nome LIKE CONCAT('%', CHAR(0xE2, 0x94, 0x9C USING utf8mb4), '%');

-- Restaura "Plano inicial para escritórios solo ou pequenos." (basico)
UPDATE plans
SET descricao = CONCAT('Plano inicial para escrit', CHAR(0xC3, 0xB3 USING utf8mb4), 'rios solo ou pequenos.')
WHERE slug = 'basico'
  AND descricao LIKE CONCAT('%', CHAR(0xE2, 0x94, 0x9C USING utf8mb4), '%');

-- Restaura "Plano completo para escritórios em crescimento." (profissional)
UPDATE plans
SET descricao = CONCAT('Plano completo para escrit', CHAR(0xC3, 0xB3 USING utf8mb4), 'rios em crescimento.')
WHERE slug = 'profissional'
  AND descricao LIKE CONCAT('%', CHAR(0xE2, 0x94, 0x9C USING utf8mb4), '%');

SELECT 'Migration 042 (fix encoding plans) executada em' AS msg, NOW() AS executed_at;
