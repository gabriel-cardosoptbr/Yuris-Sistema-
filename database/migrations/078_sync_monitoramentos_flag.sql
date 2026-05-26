-- ============================================================================
-- Migration 078 — sync_monitoramentos em account_vinculos e advogado_vinculos
-- ============================================================================
-- DATA: 2026-05-26
-- CONTEXTO: Add-on Monitoramentos — flag de sincronização granular.
--
-- DECISÃO D5 APROVADA: seguir padrão dos outros módulos.
--   Hoje account_vinculos já tem sync_enabled (master switch), sync_processos,
--   sync_cards, sync_tarefas. Cada flag controla se matriz enxerga aquele
--   módulo da filial via AccountContext::getAccessibleAccountIds('processos'|
--   'cards'|'tarefas').
--
--   Adicionamos sync_monitoramentos com mesma semântica:
--     1 = matriz vê os monitors da filial vinculada (mesmo pool/agregado)
--     0 = isolado (matriz e filial veem só seus próprios monitors)
--
--   Default = 1 (aderente ao modelo "pool aberto").
--
--   Mesma flag adicionada em advogado_vinculos (host matriz/filial ↔ advogado
--   solo). Se ligada, host enxerga monitors do advogado vinculado.
--
-- IMPACTO NO CÓDIGO (próxima etapa):
--   AccountContext::getAccessibleAccountIds('monitoramentos') vai usar essa
--   flag pra decidir quais accounts retornar.
-- ============================================================================

-- account_vinculos (matriz ↔ filial)
ALTER TABLE account_vinculos
  ADD COLUMN sync_monitoramentos TINYINT(1) NOT NULL DEFAULT 1
    AFTER sync_tarefas;

-- advogado_vinculos (host ↔ advogado solo)
ALTER TABLE advogado_vinculos
  ADD COLUMN sync_monitoramentos TINYINT(1) NOT NULL DEFAULT 1
    AFTER sync_tarefas;

-- Atualiza timestamp de sync (consistente com como outras flags foram add)
UPDATE account_vinculos
   SET sync_updated_at = NOW()
   WHERE sync_updated_at IS NULL;

-- Pós-migration: validar com
--   SELECT id, matriz_account_id, filial_account_id,
--          sync_enabled, sync_processos, sync_cards, sync_tarefas, sync_monitoramentos
--   FROM account_vinculos LIMIT 5;
