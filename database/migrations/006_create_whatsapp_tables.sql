-- ═══════════════════════════════════════════════════════════
-- 006_create_whatsapp_tables.sql
-- Módulo Chat — Integração Evolution API (WhatsApp)
-- ═══════════════════════════════════════════════════════════

-- Instâncias do WhatsApp conectadas ao CRM
CREATE TABLE IF NOT EXISTS whatsapp_instances (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  instance_name   VARCHAR(100)  NOT NULL UNIQUE,
  display_name    VARCHAR(150)  NULL,
  status          ENUM('open','close','connecting','qr') DEFAULT 'close',
  phone           VARCHAR(30)   NULL,
  profile_name    VARCHAR(150)  NULL,
  profile_pic_url TEXT          NULL,
  qr_code_base64  MEDIUMTEXT    NULL,
  evolution_token VARCHAR(255)  NULL,
  webhook_url     TEXT          NULL,
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contatos do WhatsApp
CREATE TABLE IF NOT EXISTS whatsapp_contacts (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  instance_id     INT           NOT NULL,
  remote_jid      VARCHAR(120)  NOT NULL,
  push_name       VARCHAR(255)  NULL,
  phone           VARCHAR(30)   NULL,
  profile_pic_url TEXT          NULL,
  is_group        TINYINT(1)    DEFAULT 0,
  is_favorite     TINYINT(1)    DEFAULT 0,
  linked_card_id       INT      NULL,
  linked_processo_id   INT      NULL,
  linked_user_id       INT      NULL,
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_contact (instance_id, remote_jid),
  KEY idx_instance (instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chats (uma linha por conversa, com resumo do último status)
CREATE TABLE IF NOT EXISTS whatsapp_chats (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  instance_id          INT           NOT NULL,
  remote_jid           VARCHAR(120)  NOT NULL,
  contact_name         VARCHAR(255)  NULL,
  phone                VARCHAR(30)   NULL,
  profile_pic_url      TEXT          NULL,
  last_message_content TEXT          NULL,
  last_message_type    VARCHAR(30)   NULL,
  last_message_at      TIMESTAMP     NULL,
  last_message_from_me TINYINT(1)    DEFAULT 0,
  unread_count         INT           DEFAULT 0,
  is_pinned            TINYINT(1)    DEFAULT 0,
  is_archived          TINYINT(1)    DEFAULT 0,
  is_group             TINYINT(1)    DEFAULT 0,
  linked_card_id       INT           NULL,
  linked_processo_id   INT           NULL,
  linked_user_id       INT           NULL,
  created_at           TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_chat (instance_id, remote_jid),
  KEY idx_instance_updated (instance_id, last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mensagens recebidas e enviadas
CREATE TABLE IF NOT EXISTS whatsapp_messages (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  instance_id      INT           NOT NULL,
  wamid            VARCHAR(120)  NULL,
  remote_jid       VARCHAR(120)  NOT NULL,
  contact_name     VARCHAR(255)  NULL,
  phone            VARCHAR(30)   NULL,
  message_type     VARCHAR(30)   NOT NULL DEFAULT 'text',
  message_content  TEXT          NULL,
  caption          TEXT          NULL,
  media_url        TEXT          NULL,
  media_mimetype   VARCHAR(100)  NULL,
  media_filename   VARCHAR(255)  NULL,
  media_base64     MEDIUMTEXT    NULL,
  direction        ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
  status           ENUM('pending','sent','delivered','read','failed') DEFAULT 'pending',
  quoted_wamid     VARCHAR(120)  NULL,
  raw_payload      MEDIUMTEXT    NULL,
  created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_jid     (instance_id, remote_jid),
  KEY idx_wamid   (wamid),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configurações da Evolution API por instância (persistência de config da UI)
CREATE TABLE IF NOT EXISTS whatsapp_settings (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  config_key   VARCHAR(80)  NOT NULL UNIQUE,
  config_value TEXT         NULL,
  updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valores padrão de configuração
INSERT IGNORE INTO whatsapp_settings (config_key, config_value) VALUES
  ('evolution_base_url',    'http://localhost:8080'),
  ('evolution_api_key',     ''),
  ('evolution_instance',    'yuris-crm'),
  ('webhook_enabled',       '1');
