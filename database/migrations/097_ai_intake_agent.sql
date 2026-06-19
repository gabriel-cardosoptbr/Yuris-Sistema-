-- Migration 097 — Assistente Virtual de Pre-Atendimento Juridico (DDL canonico).
-- Aplicacao idempotente via run_097.php (este .sql e a referencia para schema.sql).
--
-- PREMISSA ZERO: o agente NAO cria conexao/credencial/QR proprios. Ele referencia o
-- canal existente por agent_configs.whatsapp_instance_id -> whatsapp_instances.id.
-- As credenciais da Evolution continuam em whatsapp_settings (modulo WhatsApp).

-- 1) Estende agent_configs (1 agente por canal ja existente) com a config rica.
ALTER TABLE agent_configs
  ADD COLUMN status                  VARCHAR(20)  NOT NULL DEFAULT 'inactive' AFTER enabled,
  ADD COLUMN model                   VARCHAR(60)  NULL     DEFAULT 'gpt-4o-mini' AFTER provider,
  ADD COLUMN max_questions           TINYINT      NOT NULL DEFAULT 6,
  ADD COLUMN office_name             VARCHAR(150) NULL,
  ADD COLUMN office_description      TEXT         NULL,
  ADD COLUMN office_information_json JSON         NULL,
  ADD COLUMN behavior_json           JSON         NULL,
  ADD COLUMN handoff_config_json     JSON         NULL,
  ADD COLUMN usage_limits_json       JSON         NULL,
  ADD COLUMN initial_message         TEXT         NULL,
  ADD COLUMN closing_message         TEXT         NULL,
  ADD COLUMN urgency_message         TEXT         NULL,
  ADD COLUMN handoff_message         TEXT         NULL,
  ADD COLUMN retention_days          INT          NOT NULL DEFAULT 180,
  ADD COLUMN prompt_version_id       INT          NULL;

-- 2) Catalogo GLOBAL de areas (seed via run_097; expansivel).
CREATE TABLE IF NOT EXISTS ai_area_catalog (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(50)  NOT NULL,
  name          VARCHAR(150) NOT NULL,
  subareas_json JSON         NULL,
  aliases_json  JSON         NULL,
  sort          INT          NOT NULL DEFAULT 0,
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_area_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Areas habilitadas POR AGENTE (cada escritorio ativa as que atende).
CREATE TABLE IF NOT EXISTS ai_intake_areas (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  account_id           INT          NOT NULL,
  agent_id             INT          NOT NULL,
  code                 VARCHAR(50)  NOT NULL,
  name                 VARCHAR(150) NULL,
  enabled              TINYINT(1)   NOT NULL DEFAULT 1,
  responsible_user_id  INT          NULL,
  responsible_team_id  INT          NULL,
  priority             INT          NOT NULL DEFAULT 0,
  aliases_json         JSON         NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_agent_area (agent_id, code),
  KEY idx_aia_account (account_id),
  CONSTRAINT fk_aia_agent FOREIGN KEY (agent_id) REFERENCES agent_configs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Sessoes de pre-atendimento (uma conversa = uma sessao ativa).
CREATE TABLE IF NOT EXISTS ai_intake_sessions (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  account_id               INT          NOT NULL,
  branch_id                INT          NULL,
  agent_id                 INT          NULL,
  channel_id               INT          NOT NULL,                 -- whatsapp_instances.id (canal)
  remote_jid               VARCHAR(120) NOT NULL,
  contact_id               INT          NULL,
  status                   VARCHAR(20)  NOT NULL DEFAULT 'active',
  controller_mode          VARCHAR(20)  NOT NULL DEFAULT 'bot_active',
  intent                   VARCHAR(40)  NULL,
  primary_area             VARCHAR(50)  NULL,
  secondary_areas_json     JSON         NULL,
  classification_confidence DECIMAL(4,3) NULL,
  urgency_level            VARCHAR(12)  NOT NULL DEFAULT 'normal',
  urgency_reasons_json     JSON         NULL,
  current_state            VARCHAR(40)  NOT NULL DEFAULT 'new',
  current_question         VARCHAR(60)  NULL,
  question_count           INT          NOT NULL DEFAULT 0,
  asked_questions_json     JSON         NULL,
  collected_data_json      JSON         NULL,
  mentioned_documents_json JSON         NULL,
  summary                  TEXT         NULL,
  assigned_user_id         INT          NULL,
  assigned_team_id         INT          NULL,
  prospect_id              INT          NULL,                     -- cards.id
  task_id                  INT          NULL,                     -- tasks.id
  last_message_at          DATETIME     NULL,
  expires_at               DATETIME     NULL,
  closed_at                DATETIME     NULL,
  created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ais_channel_jid (channel_id, remote_jid),
  KEY idx_ais_account (account_id),
  KEY idx_ais_status (status),
  KEY idx_ais_active (channel_id, remote_jid, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Mensagens do agente (metadados + ledger de origem para anti-loop).
--    A mensagem completa fica em whatsapp_messages; aqui guardamos referencia + origem.
CREATE TABLE IF NOT EXISTS ai_intake_messages (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  account_id            INT          NOT NULL,
  session_id            INT          NULL,
  whatsapp_message_id   INT          NULL,                        -- whatsapp_messages.id
  wamid                 VARCHAR(120) NULL,                        -- key.id da Evolution (correlacao)
  direction             ENUM('inbound','outbound') NOT NULL,
  origin                VARCHAR(20)  NOT NULL DEFAULT 'unknown',  -- bot|human_user|system_template|external_sync|unknown
  content               TEXT         NULL,
  message_type          VARCHAR(30)  NULL,
  ai_called             TINYINT(1)   NOT NULL DEFAULT 0,
  structured_result_json JSON        NULL,
  input_tokens          INT          NULL,
  output_tokens         INT          NULL,
  estimated_cost        DECIMAL(12,6) NULL,
  latency_ms            INT          NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aim_session (session_id),
  KEY idx_aim_wamid (wamid),
  KEY idx_aim_account (account_id),
  KEY idx_aim_origin_wamid (origin, wamid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Encaminhamentos (handoff para humano).
CREATE TABLE IF NOT EXISTS ai_intake_handoffs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  account_id  INT          NOT NULL,
  session_id  INT          NOT NULL,
  reason      VARCHAR(60)  NULL,
  urgency     VARCHAR(12)  NULL,
  user_id     INT          NULL,
  team_id     INT          NULL,
  prospect_id INT          NULL,
  task_id     INT          NULL,
  status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aih_session (session_id),
  KEY idx_aih_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) Uso de IA (tokens/custo por chamada — limite e relatorio).
CREATE TABLE IF NOT EXISTS ai_usage_log (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  account_id     INT          NOT NULL,
  agent_id       INT          NULL,
  session_id     INT          NULL,
  provider       VARCHAR(30)  NULL,
  model          VARCHAR(60)  NULL,
  operation      VARCHAR(40)  NULL,
  input_tokens   INT          NOT NULL DEFAULT 0,
  output_tokens  INT          NOT NULL DEFAULT 0,
  total_tokens   INT          NOT NULL DEFAULT 0,
  estimated_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
  success        TINYINT(1)   NOT NULL DEFAULT 1,
  error          VARCHAR(255) NULL,
  latency_ms     INT          NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aul_account_date (account_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) Prompts versionados (global ou por conta).
CREATE TABLE IF NOT EXISTS ai_prompts (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  account_id  INT          NULL,                                  -- NULL = global
  name        VARCHAR(80)  NOT NULL,
  version     VARCHAR(20)  NOT NULL,
  template    MEDIUMTEXT   NOT NULL,
  schema_json JSON         NULL,
  changelog   TEXT         NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  INT          NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_prompt (name, version, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
