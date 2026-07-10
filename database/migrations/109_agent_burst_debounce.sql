-- 109_agent_burst_debounce.sql
--
-- Onda 4 / 4C: debounce de rajada do agente (opt-in). Quando o cliente digita em
-- pedaços ("Oi" / "queria saber" / "sobre pensao"), cada mensagem hoje vira 1 chamada
-- de LLM. Com burst_debounce_ms > 0, o agente espera esse tempo apos o flush e deixa a
-- ULTIMA mensagem responder por todas (as anteriores saem 'superseded'), enviando ao
-- modelo a AGREGACAO das mensagens nao respondidas (nada se perde).
--
-- DESLIGADO por padrao (0): o mecanismo fica pronto mas nao muda o comportamento nem
-- adiciona latencia ate ser habilitado por agente (ex.: 3000-4000 ms). Aditiva, sem risco.

ALTER TABLE agent_configs
  ADD COLUMN burst_debounce_ms INT NOT NULL DEFAULT 0;
