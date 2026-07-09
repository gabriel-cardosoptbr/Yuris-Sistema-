-- 105_whatsapp_freshness_gate.sql
--
-- H1 (auditoria WhatsApp 2026-07-06): freshness gate para o polling de conversas.
--
-- Antes: discover.php (poll ~32s) e refresh_chat.php (poll ~4s) rodavam por sessao de
-- usuario sem cache compartilhado. N atendentes olhando a MESMA conversa/instancia =
-- N chamadas identicas a /chat/findMessages a cada ciclo, multiplicando a carga na
-- Evolution. Agora cada endpoint marca o momento do ultimo sync e, dentro de uma
-- janela curta, serve do banco sem repetir a chamada.
--
--   whatsapp_chats.last_synced_at      -> gate por (instance_id, remote_jid) no refresh_chat
--   whatsapp_instances.last_discovered_at -> gate por instancia no discover
--
-- Colunas nullable, sem default: linhas antigas ficam NULL (nunca "fresh") e o primeiro
-- poll sincroniza normalmente. Codigo e fail-open (se a coluna faltar, faz o sync normal).

ALTER TABLE whatsapp_chats     ADD COLUMN last_synced_at     DATETIME NULL DEFAULT NULL;
ALTER TABLE whatsapp_instances ADD COLUMN last_discovered_at DATETIME NULL DEFAULT NULL;
