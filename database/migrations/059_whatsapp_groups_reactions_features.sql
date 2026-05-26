-- ============================================================================
-- Migration 059 — WhatsApp: grupos + reactions + manual name lock + participant
-- ============================================================================
--
-- ROADMAP DA AUDITORIA (2026-05-24) executada nesta migration:
--   P0-F  : participant_jid em whatsapp_messages (autor real em grupos)
--   P1-H  : is_manual_name em whatsapp_contacts (não sobrescrever rename manual)
--   P1-I  : whatsapp_group_members (lista de membros do grupo + role)
--   P2-M  : whatsapp_reactions (reactions anexadas à mensagem alvo)
--
-- IDEMPOTENTE: cada ALTER/CREATE consulta INFORMATION_SCHEMA antes.
-- ============================================================================

-- ─── 1) whatsapp_messages.participant_jid ───────────────────────────────
--    Em mensagens de grupo, key.remoteJid = JID do grupo (@g.us).
--    key.participant = JID real do autor (@s.whatsapp.net ou @lid).
--    Webhook agora extrai e salva aqui.
SET @has_participant := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name   = 'whatsapp_messages'
    AND column_name  = 'participant_jid'
);
SET @sql := IF(@has_participant = 0,
  'ALTER TABLE whatsapp_messages
     ADD COLUMN participant_jid VARCHAR(80) DEFAULT NULL AFTER remote_jid,
     ADD INDEX idx_participant (instance_id, participant_jid)',
  'SELECT "[059] participant_jid já existe — skip" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 2) whatsapp_contacts.is_manual_name ────────────────────────────────
--    Flag que indica nome editado manualmente — webhook NÃO deve sobrescrever.
SET @has_manual := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name   = 'whatsapp_contacts'
    AND column_name  = 'is_manual_name'
);
SET @sql := IF(@has_manual = 0,
  'ALTER TABLE whatsapp_contacts
     ADD COLUMN is_manual_name TINYINT(1) NOT NULL DEFAULT 0 AFTER push_name',
  'SELECT "[059] is_manual_name já existe — skip" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 3) whatsapp_chats.is_manual_name (mesmo flag pra contact_name) ─────
SET @has_chat_manual := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name   = 'whatsapp_chats'
    AND column_name  = 'is_manual_name'
);
SET @sql := IF(@has_chat_manual = 0,
  'ALTER TABLE whatsapp_chats
     ADD COLUMN is_manual_name TINYINT(1) NOT NULL DEFAULT 0 AFTER contact_name',
  'SELECT "[059] whatsapp_chats.is_manual_name já existe — skip" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 4) whatsapp_group_members ──────────────────────────────────────────
--    Lista de membros de cada grupo. Snapshot do último fetchGroupInfo.
CREATE TABLE IF NOT EXISTS whatsapp_group_members (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id      INT UNSIGNED NOT NULL,
  instance_id     INT UNSIGNED NOT NULL,
  group_jid       VARCHAR(80)  NOT NULL,
  participant_jid VARCHAR(80)  NOT NULL,
  push_name       VARCHAR(200) DEFAULT NULL,
  phone           VARCHAR(32)  DEFAULT NULL,
  role            ENUM('admin','superadmin','member') NOT NULL DEFAULT 'member',
  added_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_group_member (instance_id, group_jid, participant_jid),
  KEY idx_group (instance_id, group_jid),
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 5) whatsapp_reactions ──────────────────────────────────────────────
--    Reactions (emoji) anexadas a uma mensagem específica.
--    Uma reaction de mesmo reactor substitui a anterior (UNIQUE).
CREATE TABLE IF NOT EXISTS whatsapp_reactions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  instance_id   INT UNSIGNED NOT NULL,
  target_wamid  VARCHAR(255) NOT NULL,
  reactor_jid   VARCHAR(80)  NOT NULL,
  reactor_name  VARCHAR(200) DEFAULT NULL,
  emoji         VARCHAR(16)  NOT NULL,
  is_from_me    TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reaction (instance_id, target_wamid, reactor_jid),
  KEY idx_target (target_wamid),
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 6) whatsapp_messages.is_deleted (marcar como apagada) ──────────────
SET @has_deleted := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name   = 'whatsapp_messages'
    AND column_name  = 'is_deleted'
);
SET @sql := IF(@has_deleted = 0,
  'ALTER TABLE whatsapp_messages
     ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
  'SELECT "[059] is_deleted já existe — skip" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT '[059] Migration aplicada: participant_jid + manual_name + group_members + reactions + is_deleted' AS resultado;
