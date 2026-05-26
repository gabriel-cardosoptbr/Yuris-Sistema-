-- ============================================================================
-- Migration 080 — push_today_cache: monitor_id (Etapa 10 add-on Monitoramentos)
-- ============================================================================
-- DATA: 2026-05-26
-- CONTEXTO: Etapa 10 — propagar origem do monitor automático até push_events,
-- pra rastrear ROI ("monitor X gerou N intimações neste mês").
--
-- ANTES: push_today_cache não guardava qual push_monitor criou aquele cache.
--        Quando user clicava em "marcar como lida" / "criar prazo" e o item
--        migrava do cache pra push_events, perdia-se a origem.
--
-- AGORA: PushMonitorRunner passa monitor_id no upsert do cache. Quando
--        persist.php promove o item pra push_events via PushEvent::upsert,
--        ele lê monitor_id do cache e propaga.
--
-- IDEMPOTÊNCIA: ALTER ADD COLUMN IF NOT EXISTS — pode rodar 2x sem efeito.
-- ============================================================================

ALTER TABLE push_today_cache
  ADD COLUMN IF NOT EXISTS monitor_id INT UNSIGNED NULL AFTER account_id,
  ADD INDEX IF NOT EXISTS idx_ptc_monitor (monitor_id, account_id);
