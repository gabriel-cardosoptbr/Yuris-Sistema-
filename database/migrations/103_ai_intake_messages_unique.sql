-- 103_ai_intake_messages_unique.sql
--
-- C1 (auditoria WhatsApp 2026-07-06): idempotencia FORTE da camada de IA.
--
-- Antes: a dedup do inbound do agente era check-then-act (SELECT inboundAlreadyProcessed
-- + INSERT), sem lock nem constraint. Dois webhooks concorrentes/replay do MESMO wamid
-- disparavam o LLM 2x (custo dobrado, possivel resposta dupla ao cliente, sessoes/linhas
-- duplicadas). Agora o proprio banco garante unicidade por (session_id, wamid, direction);
-- o recordInbound() virou INSERT-first e trata a violacao como "ja processado".
--
-- wamid NULL nunca colide (varios NULL sao permitidos no UNIQUE), entao mensagens de
-- sistema/outbound sem wamid seguem sem restricao.
--
-- Dedupe defensivo ANTES do UNIQUE (mantem a linha de menor id por chave). Em bases sem
-- duplicata (o normal), o DELETE nao remove nada.

DELETE m1 FROM ai_intake_messages m1
  INNER JOIN ai_intake_messages m2
    ON  m1.session_id = m2.session_id
    AND m1.direction  = m2.direction
    AND m1.wamid      = m2.wamid
    AND m1.wamid IS NOT NULL
    AND m1.id > m2.id;

ALTER TABLE ai_intake_messages
  ADD UNIQUE KEY uk_intake_msg (session_id, wamid, direction);
