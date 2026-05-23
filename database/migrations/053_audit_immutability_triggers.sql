-- =============================================================================
-- MIGRATION 053 — LGPD: imutabilidade SQL das tabelas de auditoria
-- Data: 2026-05-23
--
-- ENDEREÇA: Auditoria LGPD Fase 1, item S-8.2 (master_audit_log mutável).
-- Adiciona triggers BEFORE UPDATE/DELETE em 8 tabelas de log/audit que
-- disparam SIGNAL SQLSTATE '45000' — bloqueia mudança/exclusão a nível
-- de banco. Mesmo o usuário root da app cai no erro.
--
-- TABELAS PROTEGIDAS:
--   1. master_audit_log
--   2. account_audit_log
--   3. processo_history
--   4. card_history
--   5. task_history
--   6. anonymization_log
--   7. lgpd_request_events
--   8. term_acceptances
--
-- EXCEÇÃO:
--   • login_attempts NÃO ganha trigger — pode precisar atualizar
--     `success` post-fact em fluxos não previstos.
--   • lgpd_consents pode receber UPDATE (revogação muda status) — não bloqueado.
--   • lgpd_requests pode receber UPDATE (DPO atualiza status/resposta) — não bloqueado.
--
-- COMO ALTERAR ESTAS TABELAS NO FUTURO (ex: ALTER TABLE):
--   DROP TRIGGER trg_XXX_no_update;
--   DROP TRIGGER trg_XXX_no_delete;
--   ALTER TABLE XXX ...;
--   <recriar triggers manualmente conforme abaixo>
--
-- IDEMPOTENTE: DROP IF EXISTS antes de CREATE.
-- =============================================================================

-- Helper macro implícito: pra cada tabela, dropa triggers antigos e cria novos.

-- ─── 1. master_audit_log ────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_mal_no_update;
DROP TRIGGER IF EXISTS trg_mal_no_delete;
DELIMITER //
CREATE TRIGGER trg_mal_no_update BEFORE UPDATE ON master_audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'master_audit_log e imutavel (LGPD Art. 37)';
END //
CREATE TRIGGER trg_mal_no_delete BEFORE DELETE ON master_audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'master_audit_log e imutavel (LGPD Art. 37)';
END //
DELIMITER ;

-- ─── 2. account_audit_log ───────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_aal_no_update;
DROP TRIGGER IF EXISTS trg_aal_no_delete;
DELIMITER //
CREATE TRIGGER trg_aal_no_update BEFORE UPDATE ON account_audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'account_audit_log e imutavel (LGPD Art. 37)';
END //
CREATE TRIGGER trg_aal_no_delete BEFORE DELETE ON account_audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'account_audit_log e imutavel (LGPD Art. 37)';
END //
DELIMITER ;

-- ─── 3. processo_history ───────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_ph_no_update;
DROP TRIGGER IF EXISTS trg_ph_no_delete;
DELIMITER //
CREATE TRIGGER trg_ph_no_update BEFORE UPDATE ON processo_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'processo_history e imutavel (LGPD Art. 37)';
END //
CREATE TRIGGER trg_ph_no_delete BEFORE DELETE ON processo_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'processo_history e imutavel (LGPD Art. 37)';
END //
DELIMITER ;

-- ─── 4. card_history ───────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_ch_no_update;
DROP TRIGGER IF EXISTS trg_ch_no_delete;
DELIMITER //
CREATE TRIGGER trg_ch_no_update BEFORE UPDATE ON card_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'card_history e imutavel (LGPD Art. 37)';
END //
CREATE TRIGGER trg_ch_no_delete BEFORE DELETE ON card_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'card_history e imutavel (LGPD Art. 37)';
END //
DELIMITER ;

-- ─── 5. task_history ───────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_th_no_update;
DROP TRIGGER IF EXISTS trg_th_no_delete;
DELIMITER //
CREATE TRIGGER trg_th_no_update BEFORE UPDATE ON task_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'task_history e imutavel (LGPD Art. 37)';
END //
CREATE TRIGGER trg_th_no_delete BEFORE DELETE ON task_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'task_history e imutavel (LGPD Art. 37)';
END //
DELIMITER ;

-- ─── 6. anonymization_log ──────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_anon_no_update;
DROP TRIGGER IF EXISTS trg_anon_no_delete;
DELIMITER //
CREATE TRIGGER trg_anon_no_update BEFORE UPDATE ON anonymization_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'anonymization_log e imutavel (LGPD Art. 12)';
END //
CREATE TRIGGER trg_anon_no_delete BEFORE DELETE ON anonymization_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'anonymization_log e imutavel (LGPD Art. 12)';
END //
DELIMITER ;

-- ─── 7. lgpd_request_events ────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_lre_no_update;
DROP TRIGGER IF EXISTS trg_lre_no_delete;
DELIMITER //
CREATE TRIGGER trg_lre_no_update BEFORE UPDATE ON lgpd_request_events
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_events e imutavel (LGPD Art. 18)';
END //
CREATE TRIGGER trg_lre_no_delete BEFORE DELETE ON lgpd_request_events
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lgpd_request_events e imutavel (LGPD Art. 18)';
END //
DELIMITER ;

-- ─── 8. term_acceptances ───────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_ta_no_update;
DROP TRIGGER IF EXISTS trg_ta_no_delete;
DELIMITER //
CREATE TRIGGER trg_ta_no_update BEFORE UPDATE ON term_acceptances
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'term_acceptances e imutavel (LGPD Art. 8)';
END //
CREATE TRIGGER trg_ta_no_delete BEFORE DELETE ON term_acceptances
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'term_acceptances e imutavel (LGPD Art. 8)';
END //
DELIMITER ;

-- ─── Confirmação ──────────────────────────────────────────────────────────
SELECT 'Migration 053 (audit immutability triggers) executada em' AS msg,
       NOW() AS executed_at,
       (SELECT COUNT(*) FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE()
          AND TRIGGER_NAME LIKE 'trg_%_no_%') AS triggers_imutabilidade;
