-- =============================================================================
-- MIGRATION 054 — LGPD: incidentes de segurança (Art. 48)
-- Data: 2026-05-23
--
-- ENDEREÇA: Auditoria LGPD Fase 1, Etapa 8 (notificação de incidentes).
--
-- Cria as tabelas para registro, workflow e auditoria de incidentes de
-- segurança envolvendo dados pessoais. A LGPD obriga o controlador a
-- comunicar à ANPD e aos titulares afetados em prazo razoável quando
-- houver risco/dano relevante (Art. 48 §1).
--
-- TABELAS:
--   • security_incidents              — registro principal (mutável: workflow)
--   • security_incident_events        — timeline imutável (audit trail)
--
-- O workflow de status é livre — qualquer transição é registrada em events
-- (ex.: detectado → em_analise → contido → mitigado → notificado_anpd →
--  notificado_titulares → encerrado). 'falso_positivo' encerra sem notificar.
--
-- IMUTABILIDADE: events ganham trigger BEFORE UPDATE/DELETE (SIGNAL 45000).
-- Padrão consistente com migration 053 (Etapa 4).
--
-- ESCOPO MULTI-TENANT:
--   account_id pode ser NULL (incidente de plataforma) ou apontar para 1 tenant.
--   Quando NULL, considera-se "todas as contas potencialmente afetadas".
--
-- IDEMPOTENTE: CREATE TABLE IF NOT EXISTS / DROP TRIGGER IF EXISTS.
-- =============================================================================

-- ─── 1. security_incidents — registro principal ─────────────────────────────
CREATE TABLE IF NOT EXISTS security_incidents (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,

  -- Escopo
  account_id INT NULL COMMENT 'Tenant afetado (NULL = plataforma / múltiplos)',

  -- Classificação
  tipo ENUM(
    'vazamento_dados',
    'acesso_indevido',
    'ransomware',
    'phishing',
    'dos_ddos',
    'exposicao_credenciais',
    'perda_dispositivo',
    'engenharia_social',
    'config_indevida',
    'outro'
  ) NOT NULL,
  severidade ENUM('baixa','media','alta','critica') NOT NULL DEFAULT 'media',
  status ENUM(
    'detectado',
    'em_analise',
    'contido',
    'mitigado',
    'notificado_anpd',
    'notificado_titulares',
    'encerrado',
    'falso_positivo'
  ) NOT NULL DEFAULT 'detectado',

  -- Identificação
  titulo VARCHAR(200) NOT NULL,
  descricao_interna TEXT NULL COMMENT 'Detalhes técnicos (apenas DPO/admin)',
  descricao_publica TEXT NULL COMMENT 'Versão sanitizada para titulares/ANPD',

  -- Impacto
  dados_afetados JSON NULL COMMENT 'Estrutura: {categorias:[pii_basica|financeiro|juridico|autenticacao],titulares_estimados:N,registros:N,dados_sensiveis:bool}',
  impacto TEXT NULL COMMENT 'Avaliação do impacto aos titulares',
  causa_raiz TEXT NULL,
  medidas_imediatas TEXT NULL COMMENT 'Contenção (primeiras horas)',
  medidas_corretivas TEXT NULL COMMENT 'Mitigação + lições aprendidas',

  -- Datas do ciclo de vida
  detectado_em DATETIME NOT NULL COMMENT 'Quando a equipe descobriu',
  ocorrido_em  DATETIME NULL COMMENT 'Início estimado do incidente (pode ser antes da deteccao)',
  contido_em   DATETIME NULL,
  mitigado_em  DATETIME NULL,
  notificacao_anpd_em DATETIME NULL,
  notificacao_anpd_protocolo VARCHAR(100) NULL,
  notificacao_titulares_em DATETIME NULL,
  notificacao_titulares_canal VARCHAR(50) NULL COMMENT 'email | telefone | aviso_publico | nenhum',
  encerrado_em DATETIME NULL,

  -- Atribuição
  reportado_por_user_id INT NULL COMMENT 'Quem abriu o registro',
  responsavel_user_id   INT NULL COMMENT 'DPO ou responsável pela resposta',

  -- Trilha forense
  request_id VARCHAR(32) NULL COMMENT 'Correlação com master_audit_log',

  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_status_severidade (status, severidade),
  KEY idx_account (account_id),
  KEY idx_detectado (detectado_em),
  KEY idx_tipo (tipo, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 2. security_incident_events — timeline imutável ────────────────────────
CREATE TABLE IF NOT EXISTS security_incident_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  incident_id BIGINT NOT NULL,
  tipo_evento VARCHAR(80) NOT NULL
    COMMENT 'criado | status_alterado | medida_aplicada | notificacao_anpd | notificacao_titulares | comentario | anexo | reabertura',
  descricao TEXT,
  status_anterior VARCHAR(40) NULL,
  status_novo     VARCHAR(40) NULL,
  payload JSON NULL,
  user_id INT NULL COMMENT 'Operador (NULL se ação do sistema)',
  ip VARCHAR(45),
  user_agent VARCHAR(255),
  request_id VARCHAR(32) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  KEY idx_incident_event (incident_id, created_at),
  KEY idx_tipo_evento (tipo_evento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 3. Triggers de imutabilidade em events ─────────────────────────────────
-- (padrão da migration 053 — security_incidents mutável; events não)
DROP TRIGGER IF EXISTS trg_sie_no_update;
DROP TRIGGER IF EXISTS trg_sie_no_delete;
DELIMITER //
CREATE TRIGGER trg_sie_no_update BEFORE UPDATE ON security_incident_events
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_incident_events e imutavel (LGPD Art. 48)';
END //
CREATE TRIGGER trg_sie_no_delete BEFORE DELETE ON security_incident_events
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_incident_events e imutavel (LGPD Art. 48)';
END //
DELIMITER ;


-- ─── Confirmação ────────────────────────────────────────────────────────────
SELECT 'Migration 054 (security_incidents + events) executada em' AS msg,
       NOW() AS executed_at,
       (SELECT COUNT(*) FROM security_incidents) AS incidents_total,
       (SELECT COUNT(*) FROM security_incident_events) AS events_total,
       (SELECT COUNT(*) FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE()
          AND TRIGGER_NAME IN ('trg_sie_no_update','trg_sie_no_delete')) AS triggers_imutabilidade;
