-- =============================================================================
-- MIGRATION 050 — LGPD: solicitações de titulares (Art. 18)
-- Data: 2026-05-23
--
-- ENDEREÇA: Auditoria LGPD Fase 1, Etapa 6 (Central LGPD / direitos do titular).
-- Cria tabelas para receber, gerir e auditar solicitações dos titulares conforme
-- Art. 18 da LGPD (acesso, correção, anonimização, eliminação, portabilidade,
-- revogação de consentimento, etc).
--
-- IDEMPOTENTE: CREATE TABLE IF NOT EXISTS.
-- =============================================================================

-- ─── 1. lgpd_requests — solicitações dos titulares ──────────────────────────
CREATE TABLE IF NOT EXISTS lgpd_requests (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,

  -- Identificação do titular
  titular_nome     VARCHAR(150) NOT NULL,
  titular_email    VARCHAR(150) NOT NULL,
  titular_cpf      VARCHAR(14)  NULL,
  titular_telefone VARCHAR(30)  NULL,

  -- Vínculo opcional (se titular já é usuário ou se conta detém os dados)
  account_id  INT NULL COMMENT 'Tenant que armazena os dados (NULL se não identificado)',
  user_id     INT NULL COMMENT 'Se o titular já é usuário cadastrado',

  -- A solicitação em si (Art. 18 LGPD)
  tipo ENUM(
    'confirmacao_existencia',
    'acesso',
    'correcao',
    'anonimizacao',
    'bloqueio',
    'eliminacao',
    'portabilidade',
    'info_compartilhamento',
    'revogacao_consentimento',
    'revisao_decisao_automatizada'
  ) NOT NULL,
  descricao TEXT COMMENT 'Detalhes da solicitação informados pelo titular',

  -- Workflow / status
  status ENUM('aberto','em_analise','aguardando_titular','concluido','rejeitado','expirado')
    NOT NULL DEFAULT 'aberto',
  motivo_rejeicao TEXT NULL,

  -- Datas
  prazo_resposta DATETIME NOT NULL COMMENT 'LGPD Art. 19 — padrão 15 dias',
  recebido_em    DATETIME DEFAULT CURRENT_TIMESTAMP,
  respondido_em  DATETIME NULL,

  -- Resposta
  respondido_por_user_id INT NULL,
  resposta TEXT NULL,
  arquivo_resposta_path VARCHAR(500) NULL COMMENT 'Path do ZIP de export (portabilidade)',

  -- Auditoria
  ip_origem   VARCHAR(45),
  user_agent  VARCHAR(255),
  token_acesso CHAR(64) NOT NULL COMMENT 'Token único pra titular acompanhar sem login',

  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_token (token_acesso),
  KEY idx_email (titular_email),
  KEY idx_status_prazo (status, prazo_resposta),
  KEY idx_account (account_id),
  KEY idx_tipo (tipo, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 2. lgpd_request_events — auditoria de cada solicitação ─────────────────
CREATE TABLE IF NOT EXISTS lgpd_request_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT NOT NULL,
  evento VARCHAR(80) NOT NULL
    COMMENT 'criado | identidade_confirmada | aceito | em_analise | dados_buscados | exportado | respondido | rejeitado | expirado',
  observacao TEXT,
  user_id INT NULL COMMENT 'Operador (NULL se ação automatizada do sistema)',
  ip VARCHAR(45),
  user_agent VARCHAR(255),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  KEY idx_request_event (request_id, created_at),
  KEY idx_evento (evento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── Confirmação ────────────────────────────────────────────────────────────
SELECT 'Migration 050 (lgpd_requests + events) executada em' AS msg,
       NOW() AS executed_at,
       (SELECT COUNT(*) FROM lgpd_requests) AS reqs_total,
       (SELECT COUNT(*) FROM lgpd_request_events) AS events_total;
