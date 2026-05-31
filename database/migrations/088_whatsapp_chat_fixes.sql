-- 088_whatsapp_chat_fixes.sql
-- Correções de usabilidade do WhatsApp (auditoria 2026-05-31).
-- Aditiva e idempotente. NÃO apaga mensagens — só remove duplicatas EXATAS de wamid.
--
--   A) Índice pra ordenação cronológica (created_at) das mensagens por conversa.
--   C) Backfill de participant_jid (autor real em grupos) a partir do raw_payload.
--   G) Dedup + UNIQUE (instance_id, wamid) pra idempotência real (contingência).
--
-- Compatível com MariaDB 10.4 (IF NOT EXISTS em índices; JSON_VALID/JSON_EXTRACT).

-- ─────────────────────────────────────────────────────────────────────────────
-- A) Índice de ordenação cronológica por conversa.
--    A listagem agora ordena por (created_at, id) em vez de id; este índice cobre
--    o filtro (instance_id, remote_jid) + a ordenação por created_at.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE whatsapp_messages
  ADD INDEX IF NOT EXISTS idx_jid_created (instance_id, remote_jid, created_at, id);

-- ─────────────────────────────────────────────────────────────────────────────
-- C) Backfill do participant_jid (autor real em grupos) a partir do raw_payload,
--    só onde está vazio e o payload (válido) tem key.participant. Corrige o
--    histórico inserido pelo polling antes de gravarmos participant_jid.
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE whatsapp_messages
   SET participant_jid = JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.key.participant'))
 WHERE (participant_jid IS NULL OR participant_jid = '')
   AND remote_jid LIKE '%@g.us'
   AND raw_payload IS NOT NULL
   AND JSON_VALID(raw_payload)
   AND JSON_EXTRACT(raw_payload, '$.key.participant') IS NOT NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- G) Idempotência: remove duplicatas EXATAS de wamid (mantém a de menor id) e cria
--    o UNIQUE. wamid NULL é permitido em duplicidade (NULLs não colidem em UNIQUE),
--    então mensagens sem wamid não são afetadas.
-- ─────────────────────────────────────────────────────────────────────────────

-- Normaliza wamid vazio ('') para NULL — duas strings vazias colidiriam no UNIQUE,
-- mas NULLs não. Faz o índice ser seguro mesmo com mensagens sem key.id.
UPDATE whatsapp_messages SET wamid = NULL WHERE wamid = '';

DELETE m1 FROM whatsapp_messages m1
  INNER JOIN whatsapp_messages m2
     ON m1.instance_id = m2.instance_id
    AND m1.wamid       = m2.wamid
    AND m1.id          > m2.id
 WHERE m1.wamid IS NOT NULL AND m1.wamid <> '';

ALTER TABLE whatsapp_messages
  ADD UNIQUE INDEX IF NOT EXISTS uq_inst_wamid (instance_id, wamid);
