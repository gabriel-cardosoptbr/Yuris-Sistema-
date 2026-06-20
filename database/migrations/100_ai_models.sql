-- Migration 100 — Catalogo GLOBAL de modelos de IA (gerenciavel no Painel Master).
-- Fonte unica dos dropdowns de "Modelo" do agente em TODAS as contas. O super-admin
-- adiciona/remove/ativa modelos aqui (aba Agente IA -> card da OpenAI) e isso reflete
-- em todo o sistema. Idempotente (CREATE TABLE IF NOT EXISTS + seed via INSERT IGNORE).
CREATE TABLE IF NOT EXISTS ai_models (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  provider   VARCHAR(30)  NOT NULL DEFAULT 'openai',
  code       VARCHAR(80)  NOT NULL,
  label      VARCHAR(120) NULL,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort       INT          NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_provider_code (provider, code),
  KEY idx_active (active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ai_models (provider, code, label, active, sort) VALUES
  ('openai', 'gpt-4o-mini',  'gpt-4o-mini (econômico)', 1, 1),
  ('openai', 'gpt-4.1-mini', 'gpt-4.1-mini',            1, 2),
  ('openai', 'gpt-5.4-mini', 'gpt-5.4-mini',            1, 3),
  ('openai', 'gpt-5.4-nano', 'gpt-5.4-nano',            1, 4);
