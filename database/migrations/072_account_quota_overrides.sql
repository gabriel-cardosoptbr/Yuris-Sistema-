-- ============================================================================
-- Migration 072 — account_quota_overrides
-- ============================================================================
-- DATA: 2026-05-26
-- CONTEXTO: Add-on Monitoramentos (cota separada do plano comercial).
--
-- MODELO COMERCIAL APROVADO:
--   Plano do sistema = só acesso ao Yuris.
--   Monitoramento = produto separado, cobrado por unidade (OAB/advogado).
--   Default em qualquer plano = 0 monitors.
--   Cota efetiva só via override (master_grant OU purchase).
--
-- O QUE ESTA TABELA FAZ:
--   Sobrescreve o limite default de `plan_features` por conta específica.
--   Genérica — vale para QUALQUER feature_key (não só monitors.limit).
--   Cliente pode ter múltiplas linhas ATIVAS empilhando: grant promo + purchase.
--
-- REGRA DE SOMA (não MAX):
--   limite_efetivo(account) = SUM(limit_value WHERE active e não expirado)
--   Exemplo: cliente compra 5 (source='purchase') + Master dá 1 promo
--   (source='promo', expires_at=+30d) → tem 6 disponíveis até promoção expirar.
--
-- CAMPOS PREPARATÓRIOS PRA GATEWAY FUTURO (D10 aprovado):
--   unit_price_cents, billing_cycle, contract_ref, gateway_subscription_id.
--   Todos NULL agora. Sem implementar gateway/checkout/cobrança automática
--   nesta etapa — apenas reservamos espaço pra evitar ALTER TABLE depois.
-- ============================================================================

CREATE TABLE IF NOT EXISTS account_quota_overrides (
  id                          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id                  INT UNSIGNED NOT NULL,
  feature_key                 VARCHAR(60) NOT NULL,
                              -- ex: 'monitors.limit', 'max_users.bonus', etc.
  limit_value                 INT NULL,
                              -- quantidade liberada por este override.
                              -- NULL = ilimitado (raro, só pra casos master especiais).
  source                      ENUM(
                                'master_grant',     -- Master liberou grátis/promo/cortesia
                                'purchase',         -- Cliente contratou (registro comercial)
                                'trial',            -- Trial automático (não usado agora)
                                'promo',            -- Campanha temporal
                                'migration'         -- Mig legada (compat)
                              ) NOT NULL DEFAULT 'master_grant',

  -- ── CAMPOS PREPARATÓRIOS PRA COBRANÇA FUTURA ────────────────────────────
  -- IMPORTANTE: NÃO implementar gateway/checkout/cobrança automática agora.
  -- Estes campos são apenas registro comercial-administrativo. Quando gateway
  -- entrar (sprint futura), webhook do gateway popula gateway_subscription_id
  -- e preenche unit_price_cents/billing_cycle automaticamente.
  unit_price_cents            INT NULL,
                              -- ex: 4990 = R$ 49,90 por monitor/mês.
                              -- Metadado: NÃO dispara cobrança. KPI de MRR no Master.
  billing_cycle               ENUM('monthly','yearly','one_off') NULL,
                              -- Metadado de contrato. NÃO renova automaticamente.
  contract_ref                VARCHAR(120) NULL,
                              -- Nº de contrato/proposta interno (controle comercial).
  gateway_subscription_id     VARCHAR(120) NULL,
                              -- Vazio agora. Webhook do gateway preenche no futuro.
  -- ── /CAMPOS PREPARATÓRIOS ───────────────────────────────────────────────

  expires_at                  DATETIME NULL,
                              -- NULL = não expira (purchases permanentes).
                              -- DATETIME = grant temporário (trial 30d, promo).
                              -- BillingGuard ignora overrides expirados.
  set_by_super_admin_id       INT UNSIGNED NULL,
                              -- FK lógica → super_admins.id (quem criou).
                              -- NULL apenas se origem='migration'.
  revoked_at                  DATETIME NULL,
                              -- Quando NÃO NULL = soft-cancel. BillingGuard ignora.
  revoked_by_super_admin_id   INT UNSIGNED NULL,
  observacoes                 VARCHAR(500) NULL,
                              -- Texto livre. Ex: "Cliente migrou de plano legado".

  created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

  -- Índice principal: busca rápida por conta+feature ativos
  INDEX idx_aqo_acc_key (account_id, feature_key, revoked_at),
  -- Filtragem por origem (KPI Master)
  INDEX idx_aqo_source (source, revoked_at),
  -- Limpeza de expirados (cron poderia varrer)
  INDEX idx_aqo_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
