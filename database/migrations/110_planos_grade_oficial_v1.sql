-- ============================================================================
-- Migration 110 — Grade oficial de planos v1 (Solo / Equipe / Escritório /
--                 Studio / Enterprise) + feature keys novas
-- ============================================================================
-- DATA: 2026-07-16
-- CONTEXTO:
--   Até aqui existiam TRÊS catálogos de preço divergentes:
--     1. banco  : basico R$149 / profissional R$299 / enterprise R$599
--     2. cards do site  : Solo R$220 / Equipe R$220 / Escritório R$370 / Studio R$670
--     3. JSON-LD do site: 149 / 249 / 449 / 749
--   Nenhum batia com o outro. Esta migration estabelece UMA grade oficial,
--   igual à da planilha "YURIS - Planos Oficiais v1 (5 planos).xlsx" e à
--   nota "Comparativo de Planos.md" do cofre.
--
-- O QUE FAZ:
--   1. Aposenta os planos legados (basico, profissional, enterprise):
--      renomeia o slug para *_legado e marca ativo=0.
--      NÃO apaga: assinaturas existentes apontam para plan_id (int) e
--      continuam funcionando com o preço que já contrataram.
--      teste_gratis e pago_padrao ficam INTOCADOS (trial e placeholder em uso).
--   2. Cria os 5 planos novos.
--   3. Semeia plan_features de cada um, incluindo 4 feature keys NOVAS:
--        ai.triagens_mes       — franquia mensal de triagens do agente de IA
--        aasp_enabled          — integração AASP
--        planejamento          — tela de Planejamento Comercial
--        advogados_associados  — vínculo de advogado parceiro
--
-- SEMÂNTICA de plan_features (ver BillingGuard.php:62-66):
--   limit_value NULL + is_enabled=1 → ilimitado
--   limit_value 0                   → desabilitado
--   limit_value N                   → limite numérico (soma overrides)
--   SEM LINHA                       → getLimit() devolve false = LIBERA (fail-soft)
--
-- IDEMPOTENTE: rodar duas vezes não duplica nada.
-- ROLLBACK: ver bloco comentado no fim do arquivo.
--
-- PREFIRA RODAR PELO RUNNER: php database/migrations/run_110.php
--   (o runner tem as mesmas travas de idempotência e imprime o resultado)
-- ============================================================================

-- ── 1. Aposenta os planos legados ───────────────────────────────────────────
UPDATE plans SET slug = 'basico_legado',       ativo = 0 WHERE slug = 'basico';
UPDATE plans SET slug = 'profissional_legado', ativo = 0 WHERE slug = 'profissional';
UPDATE plans SET slug = 'enterprise_legado',   ativo = 0 WHERE slug = 'enterprise';

-- ── 2. Cria os planos novos (só se o slug ainda não existir) ────────────────
INSERT INTO plans (slug, nome, descricao, preco_mensal_cents, preco_anual_cents, moeda, trial_dias, ativo, destaque, ordem)
SELECT * FROM (SELECT
  'solo' AS slug, 'Solo' AS nome,
  'Para o advogado autônomo organizar a rotina e não perder prazo nem cliente.' AS descricao,
  14900 AS pm, 152400 AS pa, 'BRL' AS moeda, 14 AS trial, 1 AS ativo, 0 AS destaque, 1 AS ordem
) t WHERE NOT EXISTS (SELECT 1 FROM plans p WHERE p.slug = 'solo');

INSERT INTO plans (slug, nome, descricao, preco_mensal_cents, preco_anual_cents, moeda, trial_dias, ativo, destaque, ordem)
SELECT * FROM (SELECT
  'equipe', 'Equipe',
  'Para o escritório pequeno que atende e capta em equipe.',
  24900, 254400, 'BRL', 14, 1, 1, 2
) t WHERE NOT EXISTS (SELECT 1 FROM plans p WHERE p.slug = 'equipe');

INSERT INTO plans (slug, nome, descricao, preco_mensal_cents, preco_anual_cents, moeda, trial_dias, ativo, destaque, ordem)
SELECT * FROM (SELECT
  'escritorio', 'Escritório',
  'Para o escritório estruturado que quer automação, AASP e unidades conectadas.',
  44900, 458400, 'BRL', 14, 1, 0, 3
) t WHERE NOT EXISTS (SELECT 1 FROM plans p WHERE p.slug = 'escritorio');

INSERT INTO plans (slug, nome, descricao, preco_mensal_cents, preco_anual_cents, moeda, trial_dias, ativo, destaque, ordem)
SELECT * FROM (SELECT
  'studio', 'Studio',
  'Para bancas maiores: operação completa e prioridade no suporte.',
  74900, 764400, 'BRL', 14, 1, 0, 4
) t WHERE NOT EXISTS (SELECT 1 FROM plans p WHERE p.slug = 'studio');

INSERT INTO plans (slug, nome, descricao, preco_mensal_cents, preco_anual_cents, moeda, trial_dias, ativo, destaque, ordem)
SELECT * FROM (SELECT
  'enterprise', 'Enterprise',
  'Sob consulta. Implantação assistida, migração de dados e integrações sob medida. Mensalidade negociada.',
  0, 0, 'BRL', 0, 1, 0, 5
) t WHERE NOT EXISTS (SELECT 1 FROM plans p WHERE p.slug = 'enterprise');

-- ── 3. Features por plano ───────────────────────────────────────────────────
-- NULL = ilimitado · 0 = desabilitado · N = limite
-- (o runner PHP faz isso de forma mais legível; este bloco é o equivalente SQL)

-- Solo
INSERT INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, k.fk, k.lv, 1 FROM plans p JOIN (
  SELECT 'max_users' fk, 2 lv UNION ALL SELECT 'monitors.limit', 1 UNION ALL
  SELECT 'ai.triagens_mes', 50 UNION ALL SELECT 'max_filiais', 0 UNION ALL
  SELECT 'chat_interno', 0 UNION ALL SELECT 'webhooks', 0 UNION ALL
  SELECT 'aasp_enabled', 0 UNION ALL SELECT 'planejamento', 0 UNION ALL
  SELECT 'advogados_associados', 0 UNION ALL SELECT 'whatsapp_enabled', 1 UNION ALL
  SELECT 'integracoes_api', 0
) k WHERE p.slug = 'solo'
  AND NOT EXISTS (SELECT 1 FROM plan_features pf WHERE pf.plan_id = p.id AND pf.feature_key = k.fk);

-- Equipe
INSERT INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, k.fk, k.lv, 1 FROM plans p JOIN (
  SELECT 'max_users' fk, 5 lv UNION ALL SELECT 'monitors.limit', 3 UNION ALL
  SELECT 'ai.triagens_mes', 200 UNION ALL SELECT 'max_filiais', 0 UNION ALL
  SELECT 'chat_interno', 1 UNION ALL SELECT 'webhooks', 0 UNION ALL
  SELECT 'aasp_enabled', 0 UNION ALL SELECT 'planejamento', 1 UNION ALL
  SELECT 'advogados_associados', 1 UNION ALL SELECT 'whatsapp_enabled', 1 UNION ALL
  SELECT 'integracoes_api', 0
) k WHERE p.slug = 'equipe'
  AND NOT EXISTS (SELECT 1 FROM plan_features pf WHERE pf.plan_id = p.id AND pf.feature_key = k.fk);

-- Escritório
INSERT INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, k.fk, k.lv, 1 FROM plans p JOIN (
  SELECT 'max_users' fk, 10 lv UNION ALL SELECT 'monitors.limit', 6 UNION ALL
  SELECT 'ai.triagens_mes', 500 UNION ALL SELECT 'max_filiais', 3 UNION ALL
  SELECT 'chat_interno', 1 UNION ALL SELECT 'webhooks', 1 UNION ALL
  SELECT 'aasp_enabled', 1 UNION ALL SELECT 'planejamento', 1 UNION ALL
  SELECT 'advogados_associados', 1 UNION ALL SELECT 'whatsapp_enabled', 1 UNION ALL
  SELECT 'integracoes_api', 1
) k WHERE p.slug = 'escritorio'
  AND NOT EXISTS (SELECT 1 FROM plan_features pf WHERE pf.plan_id = p.id AND pf.feature_key = k.fk);

-- Studio (max_filiais ilimitado entra separado por causa do NULL)
INSERT INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, k.fk, k.lv, 1 FROM plans p JOIN (
  SELECT 'max_users' fk, 20 lv UNION ALL SELECT 'monitors.limit', 12 UNION ALL
  SELECT 'ai.triagens_mes', 1500 UNION ALL
  SELECT 'chat_interno', 1 UNION ALL SELECT 'webhooks', 1 UNION ALL
  SELECT 'aasp_enabled', 1 UNION ALL SELECT 'planejamento', 1 UNION ALL
  SELECT 'advogados_associados', 1 UNION ALL SELECT 'whatsapp_enabled', 1 UNION ALL
  SELECT 'integracoes_api', 1
) k WHERE p.slug = 'studio'
  AND NOT EXISTS (SELECT 1 FROM plan_features pf WHERE pf.plan_id = p.id AND pf.feature_key = k.fk);

INSERT INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, 'max_filiais', NULL, 1 FROM plans p WHERE p.slug = 'studio'
  AND NOT EXISTS (SELECT 1 FROM plan_features pf WHERE pf.plan_id = p.id AND pf.feature_key = 'max_filiais');

-- Enterprise: tudo ilimitado/ligado
INSERT INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, k.fk, NULL, 1 FROM plans p JOIN (
  SELECT 'max_users' fk UNION ALL SELECT 'monitors.limit' UNION ALL
  SELECT 'ai.triagens_mes' UNION ALL SELECT 'max_filiais'
) k WHERE p.slug = 'enterprise'
  AND NOT EXISTS (SELECT 1 FROM plan_features pf WHERE pf.plan_id = p.id AND pf.feature_key = k.fk);

INSERT INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, k.fk, 1, 1 FROM plans p JOIN (
  SELECT 'chat_interno' fk UNION ALL SELECT 'webhooks' UNION ALL
  SELECT 'aasp_enabled' UNION ALL SELECT 'planejamento' UNION ALL
  SELECT 'advogados_associados' UNION ALL SELECT 'whatsapp_enabled' UNION ALL
  SELECT 'integracoes_api'
) k WHERE p.slug = 'enterprise'
  AND NOT EXISTS (SELECT 1 FROM plan_features pf WHERE pf.plan_id = p.id AND pf.feature_key = k.fk);

-- max_processos / max_cards ilimitados nos 5 planos novos
INSERT INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
SELECT p.id, k.fk, NULL, 1 FROM plans p JOIN (
  SELECT 'max_processos' fk UNION ALL SELECT 'max_cards'
) k WHERE p.slug IN ('solo','equipe','escritorio','studio','enterprise')
  AND NOT EXISTS (SELECT 1 FROM plan_features pf WHERE pf.plan_id = p.id AND pf.feature_key = k.fk);

-- ── Conferência pós-migration ───────────────────────────────────────────────
--   SELECT p.slug, p.nome, p.preco_mensal_cents/100 AS mensal, p.ativo, p.ordem
--     FROM plans p ORDER BY p.ativo DESC, p.ordem;
--   SELECT p.slug, pf.feature_key, pf.limit_value
--     FROM plan_features pf JOIN plans p ON p.id = pf.plan_id
--    WHERE p.slug IN ('solo','equipe','escritorio','studio','enterprise')
--    ORDER BY p.ordem, pf.feature_key;

-- ── ROLLBACK (manual, se precisar) ──────────────────────────────────────────
-- DELETE pf FROM plan_features pf JOIN plans p ON p.id = pf.plan_id
--   WHERE p.slug IN ('solo','equipe','escritorio','studio','enterprise');
-- DELETE FROM plans WHERE slug IN ('solo','equipe','escritorio','studio')
--   AND id NOT IN (SELECT plan_id FROM subscriptions);
-- UPDATE plans SET slug='basico',       ativo=1 WHERE slug='basico_legado';
-- UPDATE plans SET slug='profissional', ativo=1 WHERE slug='profissional_legado';
-- (cuidado: 'enterprise' precisa que o novo seja removido/renomeado antes)
