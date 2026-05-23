-- =============================================================================
-- MIGRATION 048 — Agent IA configs per-tenant (LGPD P1 / S-35)
-- Data: 2026-05-23
--
-- ENDEREÇA: Auditoria LGPD Fase 1, item S-35 (agent_settings.api_key em sessão).
--
-- PROBLEMA ANTERIOR:
--   public/api/agent_settings.php salvava configurações do agente IA
--   (provider, api_key, prompt) em $_SESSION['agent_settings']. Riscos:
--     • api_key visível via session hijack (XSS, malware local)
--     • api_key duplicada entre tabs/devices do mesmo usuário (e perdida no logout)
--     • Sem isolamento per-tenant — cada user do tenant tinha sua própria sessão
--     • Sem persistência: ao expirar sessão, configuração se perde
--     • Sem CSRF: qualquer site podia gravar api_key na sessão via XSS
--
-- ESTA MIGRATION:
--   Cria tabela agent_configs com chave única por (account_id, user_id):
--   • Per-tenant: cada conta tem suas próprias configurações
--   • Per-user: cada user pode ter sua própria api_key (provider pessoal)
--   • api_key cifrada com MFA_ENCRYPTION_KEY (mesma chave do TOTP)
--
-- Comportamento esperado da app:
--   • GET  → retorna config do user logado (api_key vem MASKED)
--   • POST → grava config (cifra api_key antes), exige CSRF
--
-- IDEMPOTENTE: CREATE TABLE IF NOT EXISTS.
-- =============================================================================

CREATE TABLE IF NOT EXISTS agent_configs (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  account_id      INT NOT NULL,
  user_id         INT NOT NULL,
  name            VARCHAR(120) DEFAULT NULL,
  enabled         TINYINT(1)   NOT NULL DEFAULT 0,
  whatsapp_number VARCHAR(30)  DEFAULT NULL,
  provider        VARCHAR(50)  DEFAULT NULL
                  COMMENT 'openai | anthropic | gemini | etc',
  api_key_enc     VARBINARY(2048) DEFAULT NULL
                  COMMENT 'API key cifrada com AES-256-CBC (MFA_ENCRYPTION_KEY)',
  prompt          MEDIUMTEXT   DEFAULT NULL,
  created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_agent_account_user (account_id, user_id),
  KEY idx_agent_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Migration 048 (agent_configs) executada em' AS msg, NOW() AS executed_at;
