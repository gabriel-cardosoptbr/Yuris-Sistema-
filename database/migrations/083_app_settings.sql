-- ═══════════════════════════════════════════════════════════
-- 083_app_settings.sql
-- Config GLOBAL do sistema (key/value), gerida só pelo Painel Master.
-- Primeiro uso: credenciais de admin da Evolution (base URL, AUTHENTICATION_API_KEY
-- cifrada, webhook canônico) para PROVISIONAR instâncias automaticamente.
--
-- Não confundir com whatsapp_settings (config POR conta). Esta é global (1 linha por chave).
-- Idempotente: CREATE TABLE IF NOT EXISTS (runner run_083.php).
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS app_settings (
  config_key   VARCHAR(100) NOT NULL PRIMARY KEY,
  config_value MEDIUMTEXT   DEFAULT NULL,
  updated_at   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
