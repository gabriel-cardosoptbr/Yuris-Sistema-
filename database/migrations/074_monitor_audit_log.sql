-- ============================================================================
-- Migration 074 — monitor_audit_log + triggers de imutabilidade LGPD
-- ============================================================================
-- DATA: 2026-05-26
-- CONTEXTO: Add-on Monitoramentos — auditoria operacional.
--
-- POR QUE TABELA PRÓPRIA (não master_audit_log):
--   master_audit_log é restrito a ações do super_admin via Painel Master.
--   Ações operacionais (matriz cria monitor, filial pausa, advogado solicita)
--   merecem tabela dedicada — mesma garantia de imutabilidade, escopo distinto.
--
-- AÇÕES REGISTRADAS:
--   COTA:
--     quota_granted     - Master liberou grant gratuito ou registrou compra
--     quota_reduced     - Master reduziu limite (alerta se uso > novo limite)
--     quota_allocated   - Matriz reservou X pra filial/advogado
--     quota_revoked     - Matriz desfez alocação
--   MONITOR:
--     monitor_created   - Criação (origem: user/admin/master/auto_profile/request)
--     monitor_activated - status ativo
--     monitor_paused    - status pausado (mantém cota reservada)
--     monitor_canceled  - status arquivado (libera cota)
--     monitor_updated   - mudou intervalo/prioridade/etc
--     monitor_failed    - cron marcou erro persistente
--   SOLICITAÇÃO:
--     request_created   - advogado solicitou
--     request_approved  - admin aprovou (gera monitor + consome cota)
--     request_denied    - admin recusou
--   PERMISSÃO:
--     permission_changed - admin trocou toggle "advogado pode criar próprio"
--
-- IMUTABILIDADE (LGPD Art. 37 — trilha de auditoria não pode ser alterada):
--   Triggers BEFORE UPDATE e BEFORE DELETE rejeitam qualquer modificação.
--   Mesmo padrão de master_audit_log + processo_history.
-- ============================================================================

CREATE TABLE IF NOT EXISTS monitor_audit_log (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id              INT UNSIGNED NOT NULL,
                          -- escopo da ação (conta afetada)
  monitor_id              INT UNSIGNED NULL,
                          -- push_monitors.id se aplicável (NULL pra ações de cota)
  acao                    ENUM(
                            'quota_granted','quota_reduced',
                            'quota_allocated','quota_revoked',
                            'monitor_created','monitor_activated',
                            'monitor_paused','monitor_canceled',
                            'monitor_updated','monitor_failed',
                            'request_created','request_approved',
                            'request_denied',
                            'permission_changed'
                          ) NOT NULL,
  descricao               VARCHAR(500) NULL,
                          -- texto livre humano (ex: "Master liberou 5 monitors via grant")
  actor_user_id           INT UNSIGNED NULL,
                          -- users.id que executou
  actor_role              VARCHAR(50) NULL,
                          -- snapshot do role (preservado mesmo se user mudar role depois)
  actor_super_admin_id    INT UNSIGNED NULL,
                          -- super_admins.id se ação foi via Painel Master
  ip                      VARCHAR(45) NULL,
                          -- IPv4 ou IPv6
  user_agent              VARCHAR(255) NULL,
  dados_antes             JSON NULL,
                          -- snapshot estado antes (ex: {limit:5, status:'ativo'})
  dados_depois            JSON NULL,
                          -- snapshot estado depois (ex: {limit:10, status:'ativo'})
  request_id              VARCHAR(60) NULL,
                          -- correlação LGPD (RequestId helper)
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_mal_acc_created  (account_id, created_at),
  INDEX idx_mal_mon_created  (monitor_id, created_at),
  INDEX idx_mal_acao_created (acao, created_at),
  INDEX idx_mal_request      (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Triggers de imutabilidade (LGPD Art. 37) ───────────────────────────────
-- Padrão idêntico ao de master_audit_log (migration 053) e processo_history.

DELIMITER //

DROP TRIGGER IF EXISTS trg_monitor_audit_no_update //
CREATE TRIGGER trg_monitor_audit_no_update
  BEFORE UPDATE ON monitor_audit_log
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'monitor_audit_log: UPDATE proibido (LGPD Art.37 — auditoria imutavel)';
END //

DROP TRIGGER IF EXISTS trg_monitor_audit_no_delete //
CREATE TRIGGER trg_monitor_audit_no_delete
  BEFORE DELETE ON monitor_audit_log
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'monitor_audit_log: DELETE proibido (LGPD Art.37 — auditoria imutavel)';
END //

DELIMITER ;
