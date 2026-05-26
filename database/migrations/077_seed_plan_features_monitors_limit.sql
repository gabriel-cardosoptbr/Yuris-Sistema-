-- ============================================================================
-- Migration 077 — seed plan_features.monitors.limit = 0 em todos os planos
-- ============================================================================
-- DATA: 2026-05-26
-- CONTEXTO: Add-on Monitoramentos — modelo comercial.
--
-- DECISÃO D1 APROVADA — opção B (seed explícito = 0):
--   Plano do sistema NÃO inclui monitoramento. Todo cliente começa com 0.
--   Monitoramento é cobrado por unidade à parte (via account_quota_overrides
--   source='purchase' ou 'master_grant').
--
-- POR QUE SEEDAR limit_value=0 EXPLICITAMENTE (e não simplesmente omitir):
--   1. Deixa CLARO no banco que esse plano dá 0 monitors (auditoria, debug)
--   2. Evita ambiguidade: BillingGuard::getLimit() retorna 0 sem fallback
--      pra NULL ou undefined behaviour.
--   3. Quando alguém ler o banco: "ahh esse plano não inclui monitoramento"
--      em vez de "tem feature ou não tem? quem sabe..."
--   4. Auditoria comercial: linha explícita pra cada (plano, feature).
--
-- IDEMPOTENTE: não duplica se já existir.
-- ============================================================================

INSERT INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, 'monitors.limit', 0, 1
FROM plans p
WHERE NOT EXISTS (
  SELECT 1 FROM plan_features pf
  WHERE pf.plan_id = p.id
    AND pf.feature_key = 'monitors.limit'
);

-- Pós-migration: confira com
--   SELECT p.nome, pf.limit_value
--   FROM plan_features pf
--   JOIN plans p ON p.id = pf.plan_id
--   WHERE pf.feature_key = 'monitors.limit';
-- Esperado: 1 linha por plano existente, todas com limit_value=0.
