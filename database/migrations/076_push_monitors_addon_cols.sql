-- ============================================================================
-- Migration 076 — push_monitors: colunas pra suporte ao add-on
-- ============================================================================
-- DATA: 2026-05-26
-- CONTEXTO: Add-on Monitoramentos — estender push_monitors existente.
--
-- POR QUE ESTENDER (e não criar tabela 'monitorings' nova):
--   push_monitors já tem 800+ linhas de dados produtivos, models maduros
--   (PushMonitor), services integrados (PushMonitorRunner, DjenProvider,
--   AaspSyncRunner), cron rodando. Tabela nova seria reescrever tudo.
--
-- COLUNAS ADICIONADAS:
--   assigned_user_id      → atribuição direta a um user (advogado interno).
--                           NULL = monitor "do pool" (matriz/filial geral).
--   assigned_account_id   → alocação a uma filial inteira ou conta de advogado.
--                           NULL se atribuição é via assigned_user_id.
--                           Os 2 podem coexistir (filial X + user Y dentro dela).
--   consome_cota          → flag pra marcar monitors "grátis" que NÃO consomem
--                           cota (futuras promoções, monitors do próprio sistema).
--                           Default 1 = todos consomem.
--   origem_criacao        → rastreio de origem pro audit log.
--
-- NÃO ADICIONA UNIQUE NESTA MIGRATION:
--   UNIQUE (account_id, tipo, valor, uf, status) seria ideal pra evitar
--   duplicatas, mas se já tem duplicatas reais nos dados produtivos, ALTER
--   falha. Migration separada (futura) faz: SELECT duplicatas → revisão
--   manual → CREATE UNIQUE quando estiver limpo.
-- ============================================================================

ALTER TABLE push_monitors
  ADD COLUMN assigned_user_id INT UNSIGNED NULL AFTER created_by,
  ADD COLUMN assigned_account_id INT UNSIGNED NULL AFTER assigned_user_id,
  ADD COLUMN consome_cota TINYINT(1) NOT NULL DEFAULT 1 AFTER status,
  ADD COLUMN origem_criacao ENUM(
    'user',                -- user criou via UI normal
    'admin',               -- admin criou pra outra pessoa
    'master_seed',         -- super_admin seedou via Painel Master
    'request_approved',    -- veio de monitor_requests aprovada
    'auto_profile'         -- criado automaticamente em user_filters.php
  ) NOT NULL DEFAULT 'user' AFTER consome_cota,

  ADD INDEX idx_pm_assigned_user (assigned_user_id),
  ADD INDEX idx_pm_assigned_acc  (assigned_account_id),
  ADD INDEX idx_pm_consome_cota  (account_id, consome_cota, status),
                                 -- query principal de cálculo de cota usada
  ADD INDEX idx_pm_origem        (origem_criacao);
