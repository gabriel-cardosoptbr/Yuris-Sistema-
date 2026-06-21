-- Migration 102 — telemetria de cache: registra os tokens de entrada servidos do CACHE da OpenAI.
-- Aplicacao idempotente via run_102.php. O custo ja credita o cache (IntakeEngine::cost); aqui
-- passamos a PERSISTIR a contagem para medir a taxa de acerto por conta no Painel Master.

ALTER TABLE ai_usage_log
  ADD COLUMN cached_input_tokens INT NOT NULL DEFAULT 0 AFTER input_tokens;
