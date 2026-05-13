-- ═══════════════════════════════════════════════════════════
-- 007_whatsapp_chat_processos.sql
-- Permite vincular múltiplos processos a uma conversa WhatsApp
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS whatsapp_chat_processos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  instance_id INT           NOT NULL,
  remote_jid  VARCHAR(120)  NOT NULL,
  processo_id INT           NOT NULL,
  created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_chat_processo (instance_id, remote_jid, processo_id),
  KEY idx_instance_jid (instance_id, remote_jid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
