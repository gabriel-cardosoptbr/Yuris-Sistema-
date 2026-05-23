-- =============================================================================
-- MIGRATION 038 — Fundação SaaS / Billing / Painel Master (Fase 3)
-- Data: 2026-05-21
--
-- Cria as 9 tabelas centrais que habilitam:
--   • Múltiplos planos com features e limites de uso
--   • Assinaturas por conta (subscriptions)
--   • Faturas e pagamentos
--   • Eventos de gateway idempotentes
--   • Super admins (acesso global cross-tenant)
--   • Audit log global
--
-- IDEMPOTENTE: usa CREATE TABLE IF NOT EXISTS e checks via information_schema.
-- =============================================================================


-- ── 1. plans ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plans (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  slug                 VARCHAR(50) NOT NULL UNIQUE,
  nome                 VARCHAR(100) NOT NULL,
  descricao            TEXT,
  preco_mensal_cents   INT NOT NULL DEFAULT 0,
  preco_anual_cents    INT NOT NULL DEFAULT 0,
  moeda                VARCHAR(3) NOT NULL DEFAULT 'BRL',
  trial_dias           INT NOT NULL DEFAULT 0,
  ativo                TINYINT(1) NOT NULL DEFAULT 1,
  destaque             TINYINT(1) NOT NULL DEFAULT 0,
  ordem                INT NOT NULL DEFAULT 0,
  created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_plans_ativo (ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 2. plan_features ─────────────────────────────────────────────────────────
-- Define limites e features de cada plano.
-- limit_value NULL = ilimitado; 0 = desabilitado; >0 = limite numérico.
CREATE TABLE IF NOT EXISTS plan_features (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  plan_id      INT NOT NULL,
  feature_key  VARCHAR(80) NOT NULL,
  limit_value  INT NULL,
  is_enabled   TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_plan_feature (plan_id, feature_key),
  INDEX idx_pf_plan (plan_id),
  CONSTRAINT fk_pf_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 3. subscriptions ─────────────────────────────────────────────────────────
-- Uma assinatura por account. status segue padrão Stripe-like.
CREATE TABLE IF NOT EXISTS subscriptions (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  account_id               INT NOT NULL,
  plan_id                  INT NOT NULL,
  status                   ENUM('trialing','active','past_due','canceled','unpaid','incomplete')
                              NOT NULL DEFAULT 'trialing',
  billing_cycle            ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  trial_ends_at            DATETIME NULL,
  current_period_start     DATETIME NULL,
  current_period_end       DATETIME NULL,
  cancel_at_period_end     TINYINT(1) NOT NULL DEFAULT 0,
  canceled_at              DATETIME NULL,
  payment_method_id        INT NULL,
  gateway                  VARCHAR(50) NULL,
  gateway_subscription_id  VARCHAR(120) NULL,
  created_at               DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sub_account (account_id, status),
  INDEX idx_sub_plan (plan_id),
  INDEX idx_sub_period_end (current_period_end),
  INDEX idx_sub_gateway (gateway, gateway_subscription_id),
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 4. payment_methods ───────────────────────────────────────────────────────
-- NUNCA armazena PAN/CVV — só tokens do gateway. PCI compliance.
CREATE TABLE IF NOT EXISTS payment_methods (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  account_id        INT NOT NULL,
  gateway           VARCHAR(50) NOT NULL,
  gateway_token     VARCHAR(255) NOT NULL,
  tipo              ENUM('card','pix','boleto','wallet') NOT NULL DEFAULT 'card',
  brand             VARCHAR(20) NULL,
  last4             VARCHAR(4) NULL,
  exp_month         TINYINT NULL,
  exp_year          SMALLINT NULL,
  holder_name       VARCHAR(100) NULL,
  is_default        TINYINT(1) NOT NULL DEFAULT 0,
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pm_account (account_id, is_default),
  INDEX idx_pm_gateway (gateway, gateway_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 5. invoices ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS invoices (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  subscription_id      INT NULL,
  account_id           INT NOT NULL,
  amount_cents         INT NOT NULL DEFAULT 0,
  moeda                VARCHAR(3) NOT NULL DEFAULT 'BRL',
  status               ENUM('draft','open','paid','void','uncollectible','refunded')
                          NOT NULL DEFAULT 'draft',
  due_date             DATE NULL,
  paid_at              DATETIME NULL,
  numero               VARCHAR(40) NULL UNIQUE,
  descricao            TEXT,
  gateway              VARCHAR(50) NULL,
  gateway_invoice_id   VARCHAR(120) NULL,
  pdf_url              VARCHAR(500) NULL,
  created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_inv_account (account_id, status, due_date),
  INDEX idx_inv_subscription (subscription_id),
  INDEX idx_inv_gateway (gateway, gateway_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 6. usage_counters ────────────────────────────────────────────────────────
-- Counter por account x feature x período. Atualizado por triggers ou cron.
-- Ex: ('processos', '2026-05', 142) significa "account criou 142 processos em maio".
CREATE TABLE IF NOT EXISTS usage_counters (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  account_id  INT NOT NULL,
  feature_key VARCHAR(80) NOT NULL,
  periodo     VARCHAR(10) NOT NULL,   -- 'YYYY-MM' ou 'YYYY' ou 'ALL'
  count       INT NOT NULL DEFAULT 0,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_usage (account_id, feature_key, periodo),
  INDEX idx_usage_account (account_id, periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 7. super_admins ──────────────────────────────────────────────────────────
-- Acesso global cross-tenant para o Painel Master.
-- Mantemos separado de users pra evitar mistura de roles e auditoria mais clara.
CREATE TABLE IF NOT EXISTS super_admins (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL UNIQUE,
  nome          VARCHAR(100) NOT NULL,
  nivel         ENUM('viewer','operator','super') NOT NULL DEFAULT 'operator',
  ativo         TINYINT(1) NOT NULL DEFAULT 1,
  created_by    INT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  INDEX idx_sa_user (user_id, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 8. gateway_events_received ───────────────────────────────────────────────
-- Idempotency layer pra webhooks de gateway (Stripe/MP retransmitem o mesmo
-- evento em caso de timeout). Antes de processar, INSERT IGNORE; se já existe,
-- retorna 200 sem reprocessar.
CREATE TABLE IF NOT EXISTS gateway_events_received (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  gateway       VARCHAR(50) NOT NULL,
  event_id      VARCHAR(255) NOT NULL,
  event_type    VARCHAR(100) NULL,
  payload       LONGTEXT,
  processed     TINYINT(1) NOT NULL DEFAULT 0,
  error         TEXT NULL,
  received_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  processed_at  DATETIME NULL,
  UNIQUE KEY uk_gateway_event (gateway, event_id),
  INDEX idx_gev_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 9. master_audit_log ──────────────────────────────────────────────────────
-- Auditoria global das ações do Painel Master.
-- Separado de account_audit_log (que é por tenant).
CREATE TABLE IF NOT EXISTS master_audit_log (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  super_admin_id  INT NOT NULL,
  acao            VARCHAR(80) NOT NULL,
  target_type     VARCHAR(50) NULL,   -- 'account','plan','subscription','invoice','user'
  target_id       INT NULL,
  descricao       TEXT,
  ip              VARCHAR(45) NULL,
  metadata        LONGTEXT NULL,      -- JSON com detalhes
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mal_super (super_admin_id, created_at DESC),
  INDEX idx_mal_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 10. emails_outbox ────────────────────────────────────────────────────────
-- Fila de e-mails transacionais. Worker/cron processa pendentes.
CREATE TABLE IF NOT EXISTS emails_outbox (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  account_id    INT NULL,
  to_email      VARCHAR(150) NOT NULL,
  to_name       VARCHAR(100) NULL,
  subject       VARCHAR(200) NOT NULL,
  body_html     LONGTEXT,
  body_text     TEXT,
  template_key  VARCHAR(80) NULL,
  status        ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  retries       INT NOT NULL DEFAULT 0,
  error         TEXT NULL,
  scheduled_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  sent_at       DATETIME NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_emo_status (status, scheduled_at),
  INDEX idx_emo_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 11. Seed de planos básicos (idempotente via INSERT IGNORE) ───────────────
INSERT IGNORE INTO plans (slug, nome, descricao, preco_mensal_cents, preco_anual_cents, trial_dias, ativo, destaque, ordem) VALUES
  ('basico',      'Básico',      'Plano inicial para escritórios solo ou pequenos.', 14900,  149000, 14, 1, 0, 1),
  ('profissional','Profissional','Plano completo para escritórios em crescimento.',  29900,  299000, 14, 1, 1, 2),
  ('enterprise',  'Enterprise',  'Sem limites + suporte dedicado e SLA.',            59900,  599000,  7, 1, 0, 3);


-- Seed de features por plano (idempotente — UNIQUE em plan_id+feature_key)
INSERT IGNORE INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, f.k, f.v, 1 FROM plans p
CROSS JOIN (
  SELECT 'max_users'     k, 3    v UNION ALL
  SELECT 'max_processos',     500   UNION ALL
  SELECT 'max_cards',         1000  UNION ALL
  SELECT 'max_filiais',       0     UNION ALL
  SELECT 'whatsapp_enabled',  1     UNION ALL
  SELECT 'chat_interno',      1     UNION ALL
  SELECT 'webhooks',          0     UNION ALL
  SELECT 'integracoes_api',   0
) f
WHERE p.slug = 'basico';

INSERT IGNORE INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, f.k, f.v, 1 FROM plans p
CROSS JOIN (
  SELECT 'max_users'      k, 15   v UNION ALL
  SELECT 'max_processos',     5000  UNION ALL
  SELECT 'max_cards',         10000 UNION ALL
  SELECT 'max_filiais',       3     UNION ALL
  SELECT 'whatsapp_enabled',  1     UNION ALL
  SELECT 'chat_interno',      1     UNION ALL
  SELECT 'webhooks',          1     UNION ALL
  SELECT 'integracoes_api',   1
) f
WHERE p.slug = 'profissional';

-- Enterprise: NULL = ilimitado
INSERT IGNORE INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, f.k, f.v, 1 FROM plans p
CROSS JOIN (
  SELECT 'max_users'      k, NULL v UNION ALL
  SELECT 'max_processos',     NULL  UNION ALL
  SELECT 'max_cards',         NULL  UNION ALL
  SELECT 'max_filiais',       NULL  UNION ALL
  SELECT 'whatsapp_enabled',  1     UNION ALL
  SELECT 'chat_interno',      1     UNION ALL
  SELECT 'webhooks',          1     UNION ALL
  SELECT 'integracoes_api',   1
) f
WHERE p.slug = 'enterprise';


-- ── 12. Criar assinatura default pras accounts existentes ────────────────────
-- Plano básico em trial de 14 dias.
INSERT IGNORE INTO subscriptions (account_id, plan_id, status, billing_cycle, trial_ends_at, current_period_start, current_period_end)
SELECT a.id, p.id, 'trialing', 'monthly',
       DATE_ADD(NOW(), INTERVAL 14 DAY),
       NOW(),
       DATE_ADD(NOW(), INTERVAL 1 MONTH)
FROM accounts a
CROSS JOIN plans p
WHERE p.slug = 'basico'
  AND a.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.account_id = a.id);


-- ── 13. Promove o user_id=1 (Admin matriz id=1) como super_admin inicial ─────
INSERT IGNORE INTO super_admins (user_id, nome, nivel, ativo)
SELECT u.id, u.nome, 'super', 1
FROM users u
WHERE u.id = 1 AND u.deleted_at IS NULL;


SELECT 'Migration 038 (Fase 3 SaaS foundation) executada em' AS msg, NOW() AS executed_at;
