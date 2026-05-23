-- =============================================================================
-- MIGRATION 052 — LGPD: cobertura de logs (ip + user_agent + request_id)
-- Data: 2026-05-23
--
-- ENDEREÇA: Auditoria LGPD Fase 1, item S-79 (cobertura faltante de IP/UA).
-- Adiciona colunas necessárias para auditoria forense LGPD (Art. 37/46):
--   • IP do operador (já existe em alguns, falta em outros)
--   • User-Agent (útil pra distinguir browser vs ferramenta automatizada)
--   • request_id (UUID curto correlacionando logs do mesmo HTTP request)
--
-- IDEMPOTENTE: usa INFORMATION_SCHEMA pra checar antes.
-- =============================================================================

-- Helper inline pra ALTER condicional
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS __addColIfMissing(IN tbl VARCHAR(64), IN col VARCHAR(64), IN def VARCHAR(255))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
  ) THEN
    SET @sql := CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN ', col, ' ', def);
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END //

DELIMITER ;

-- ─── master_audit_log: adiciona user_agent + request_id ──────────────────────
CALL __addColIfMissing('master_audit_log', 'user_agent', 'VARCHAR(255) NULL');
CALL __addColIfMissing('master_audit_log', 'request_id', 'CHAR(12) NULL');

-- ─── account_audit_log: adiciona user_agent + request_id ─────────────────────
CALL __addColIfMissing('account_audit_log', 'user_agent', 'VARCHAR(255) NULL');
CALL __addColIfMissing('account_audit_log', 'request_id', 'CHAR(12) NULL');

-- ─── processo_history: adiciona ip + user_agent + request_id ────────────────
CALL __addColIfMissing('processo_history', 'ip',         'VARCHAR(45) NULL');
CALL __addColIfMissing('processo_history', 'user_agent', 'VARCHAR(255) NULL');
CALL __addColIfMissing('processo_history', 'request_id', 'CHAR(12) NULL');

-- ─── card_history: adiciona ip + user_agent + request_id ────────────────────
CALL __addColIfMissing('card_history', 'ip',         'VARCHAR(45) NULL');
CALL __addColIfMissing('card_history', 'user_agent', 'VARCHAR(255) NULL');
CALL __addColIfMissing('card_history', 'request_id', 'CHAR(12) NULL');

-- ─── task_history: adiciona ip + user_agent + request_id ────────────────────
CALL __addColIfMissing('task_history', 'ip',         'VARCHAR(45) NULL');
CALL __addColIfMissing('task_history', 'user_agent', 'VARCHAR(255) NULL');
CALL __addColIfMissing('task_history', 'request_id', 'CHAR(12) NULL');

-- Índices em request_id (correlaciona logs do mesmo request)
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS __addIdxIfMissing(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN col VARCHAR(64))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx
  ) THEN
    SET @sql := CONCAT('CREATE INDEX ', idx, ' ON ', tbl, ' (', col, ')');
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END //
DELIMITER ;

CALL __addIdxIfMissing('master_audit_log',  'idx_request_id', 'request_id');
CALL __addIdxIfMissing('account_audit_log', 'idx_request_id', 'request_id');
CALL __addIdxIfMissing('processo_history',  'idx_request_id', 'request_id');
CALL __addIdxIfMissing('card_history',      'idx_request_id', 'request_id');
CALL __addIdxIfMissing('task_history',      'idx_request_id', 'request_id');

-- Limpa procedures temporárias
DROP PROCEDURE IF EXISTS __addColIfMissing;
DROP PROCEDURE IF EXISTS __addIdxIfMissing;

-- Confirmação
SELECT 'Migration 052 (audit coverage) executada em' AS msg, NOW() AS executed_at;
