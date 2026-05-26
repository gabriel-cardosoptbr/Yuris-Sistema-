-- ============================================================================
-- Migration 079 — adicionar 'quarterly' (trimestral) aos enums de ciclo
-- ============================================================================
-- DATA: 2026-05-26
-- CONTEXTO: Cliente pediu que monitoramento seja assinatura (add-on) e que
--           os 3 ciclos sejam suportados: Mensal / Trimestral / Anual.
--
-- IMPACTO:
--   - subscriptions.billing_cycle: ENUM('monthly','yearly') -> add 'quarterly'
--   - account_quota_overrides.billing_cycle: ENUM('monthly','yearly','one_off')
--       -> add 'quarterly'
--
-- ADITIVO. Dados existentes preservados. Default 'monthly' mantido.
-- ============================================================================

ALTER TABLE subscriptions
  MODIFY COLUMN billing_cycle ENUM('monthly','quarterly','yearly')
    NOT NULL DEFAULT 'monthly';

ALTER TABLE account_quota_overrides
  MODIFY COLUMN billing_cycle ENUM('monthly','quarterly','yearly','one_off')
    NULL;

-- Validação pós-migration:
--   SHOW COLUMNS FROM subscriptions LIKE 'billing_cycle';
--   SHOW COLUMNS FROM account_quota_overrides LIKE 'billing_cycle';
