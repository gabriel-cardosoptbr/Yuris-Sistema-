-- Migration 095: recorrencia custom — persistir a 'unidade' (day/week/month/year)
--
-- Auditoria 2026-06-01 / ALTA #19:
-- No fluxo de tarefa recorrente 'custom' o frontend envia `unidade`
-- (day|week|month|year), mas a coluna NAO existia em task_recurrences.
-- Resultado: TaskRecurrence::calcularProximaData() caia sempre no fallback
-- 'day' ($unit = $this->data['unidade'] ?? 'day') e "a cada 2 semanas/meses/anos"
-- se comportava silenciosamente como "a cada 2 dias".
--
-- VARCHAR(10) (em vez de ENUM) para casar 1:1 com os valores que o modificador
-- de DateTime aceita ("+N day|week|month|year") e evitar futuras migrations de
-- ENUM caso novas unidades sejam suportadas. Default 'day' espelha o fallback.
--
-- NAO aplicar manualmente: rode database/migrations/run_095.php (idempotente).

ALTER TABLE `task_recurrences`
  ADD COLUMN `unidade` VARCHAR(10) NOT NULL DEFAULT 'day'
  COMMENT 'Unidade da recorrencia custom: day|week|month|year (usado por calcularProximaData)'
  AFTER `intervalo`;
