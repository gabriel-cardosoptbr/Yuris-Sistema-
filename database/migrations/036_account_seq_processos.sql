-- ═══════════════════════════════════════════════════════════════════════════════
-- 036_account_seq_processos.sql
--
-- Adiciona coluna `account_seq` em `processos` — número sequencial do processo
-- DENTRO da conta (tenant), gerado no INSERT e ESTÁVEL (nunca renumera).
--
-- Características:
--   - Único dentro de cada account_id (UNIQUE composto)
--   - Se um processo for excluído, o número NÃO é reutilizado
--   - ID global (PK auto_increment) continua sendo a chave técnica
-- ═══════════════════════════════════════════════════════════════════════════════

-- 1) Coluna nova (sem COMMENT pra compat com MariaDB mais antigo)
ALTER TABLE processos
  ADD COLUMN IF NOT EXISTS account_seq INT DEFAULT NULL AFTER account_id;

-- 2) Backfill — atribui sequenciais aos processos existentes baseado no id
SET @prev := NULL;
SET @seq  := 0;

UPDATE processos p
INNER JOIN (
  SELECT id,
         account_id,
         (@seq := IF(@prev = account_id, @seq + 1, 1)) AS new_seq,
         (@prev := account_id) AS _p
  FROM processos
  WHERE account_seq IS NULL OR account_seq = 0
  ORDER BY account_id ASC, id ASC
) t ON t.id = p.id
SET p.account_seq = t.new_seq;

-- 3) UNIQUE composto (cada conta tem account_seq únicos)
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'processos'
           AND INDEX_NAME   = 'uk_processos_account_seq'),
  'SELECT 1',
  'ALTER TABLE processos ADD UNIQUE INDEX uk_processos_account_seq (account_id, account_seq)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Verificação
SELECT 'Backfill ok' AS info;
SELECT account_id, COUNT(*) AS total, MIN(account_seq) AS menor, MAX(account_seq) AS maior
FROM processos
GROUP BY account_id;
