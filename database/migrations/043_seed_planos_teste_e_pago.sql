-- =============================================================================
-- MIGRATION 043 — Seed dos planos "Teste Grátis" e "Plano Pago Padrão"
-- Data: 2026-05-23
--
-- Adiciona dois planos solicitados pelo dono do SaaS:
--   1. "Teste Grátis" (slug: teste_gratis) — 14 dias trial, R$ 0, features liberadas
--   2. "Plano Pago Padrão" (slug: pago_padrao) — placeholder pra configurar depois
--
-- IDEMPOTENTE: INSERT IGNORE no UNIQUE plans.slug. Pode rodar várias vezes
-- sem duplicar nem sobrescrever ajustes manuais feitos pelo Painel Master.
--
-- ENCODING SAFE: usa CHAR(... USING utf8mb4) pra HEX literals — mesmo padrão
-- da migration 042. Garante que "Grátis"/"Período"/"cobrança"/"Padrão" cheguem
-- intactos ao MariaDB mesmo via cmd.exe (Windows).
-- =============================================================================


-- ── 1. Teste Grátis ──────────────────────────────────────────────────────────
-- nome:      "Teste Grátis"
-- descricao: "Período de teste sem cobrança. Acesso completo durante o trial."
--
-- IMPORTANTE: usa CONCAT 'Teste Gr' + 'á' + 'tis' = 'Teste Grátis'
-- (atenção ao 'r' — primeira versão escrevi 'Teste G' + 'á' resultando 'Gátis')
INSERT IGNORE INTO plans (slug, nome, descricao, preco_mensal_cents, preco_anual_cents, trial_dias, ativo, destaque, ordem)
VALUES (
  'teste_gratis',
  CONCAT('Teste Gr', CHAR(0xC3, 0xA1 USING utf8mb4), 'tis'),
  CONCAT(
    'Per', CHAR(0xC3, 0xAD USING utf8mb4),
    'odo de teste sem cobran', CHAR(0xC3, 0xA7 USING utf8mb4),
    'a. Acesso completo durante o trial.'
  ),
  0,      -- preço mensal R$ 0
  0,      -- preço anual R$ 0
  14,     -- trial de 14 dias
  1,      -- ativo
  0,      -- sem destaque
  0       -- primeiro da lista
);


-- ── 2. Plano Pago Padrão (placeholder) ───────────────────────────────────────
-- nome:      "Plano Pago Padrão"
-- descricao: "Configure preço, limites e módulos conforme necessidade."
INSERT IGNORE INTO plans (slug, nome, descricao, preco_mensal_cents, preco_anual_cents, trial_dias, ativo, destaque, ordem)
VALUES (
  'pago_padrao',
  CONCAT('Plano Pago Padr', CHAR(0xC3, 0xA3 USING utf8mb4), 'o'),
  CONCAT(
    'Configure pre', CHAR(0xC3, 0xA7 USING utf8mb4),
    'o, limites e m', CHAR(0xC3, 0xB3 USING utf8mb4),
    'dulos conforme necessidade.'
  ),
  0,      -- preço zerado — você define pelo Painel Master
  0,      -- preço anual zerado
  0,      -- sem trial
  1,      -- ativo (pra aparecer na lista)
  0,
  5       -- ordem 5 (depois dos legados, antes dos enterprises)
);


-- ── 3. Features do "Teste Grátis" ────────────────────────────────────────────
-- Durante o trial, libera tudo (sem limites) pra experiência completa.
INSERT IGNORE INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, f.k, f.v, 1 FROM plans p
CROSS JOIN (
  SELECT 'max_users'        k, NULL  v UNION ALL  -- ilimitado
  SELECT 'max_processos',         NULL    UNION ALL  -- ilimitado
  SELECT 'max_cards',             NULL    UNION ALL  -- ilimitado
  SELECT 'max_filiais',           NULL    UNION ALL  -- ilimitado
  SELECT 'whatsapp_enabled',      1       UNION ALL  -- liberado
  SELECT 'chat_interno',          1       UNION ALL  -- liberado
  SELECT 'webhooks',              1       UNION ALL  -- liberado
  SELECT 'integracoes_api',       1                  -- liberado
) f
WHERE p.slug = 'teste_gratis';


-- ── 4. Features do "Plano Pago Padrão" ───────────────────────────────────────
-- Tudo placeholder NULL/desabilitado — você configura pelo Painel Master.
-- Como BillingGuard.getLimit() retorna false (libera) quando NÃO há features,
-- deixamos a tabela vazia. Configurações vêm depois via /api/master/plans.php PATCH.

-- (intencionalmente sem INSERT em plan_features pra pago_padrao)


SELECT 'Migration 043 (planos teste_gratis + pago_padrao) executada em' AS msg,
       NOW() AS executed_at;
