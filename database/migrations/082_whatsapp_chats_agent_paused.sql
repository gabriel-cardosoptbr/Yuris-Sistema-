-- ═══════════════════════════════════════════════════════════
-- 082_whatsapp_chats_agent_paused.sql
-- "Assumir conversa": flag por conversa que PAUSA o agente de IA.
-- Quando um humano assume a conversa, agent_paused=1 e o webhook não dispara
-- a IA naquela conversa; ao liberar, volta a 0. Cumpre "humano assumiu ->
-- agente para imediatamente" (decisão: botão liga/desliga).
--
-- Idempotência: garantida pelo runner run_082.php (information_schema).
-- ═══════════════════════════════════════════════════════════

ALTER TABLE whatsapp_chats
  ADD COLUMN agent_paused TINYINT(1) NOT NULL DEFAULT 0 AFTER linked_user_id;
