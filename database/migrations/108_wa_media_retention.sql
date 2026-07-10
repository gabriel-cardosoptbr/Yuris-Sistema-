-- 108_wa_media_retention.sql
--
-- Onda 4 / F1-F2 (versao leve): politica de retencao para o media_base64 do WhatsApp.
--
-- Contexto: o achado F1 da auditoria apontou que a retencao LGPD so cobria
-- whatsapp_messages.raw_payload (policy #3, 30d). O media_base64 (arquivo inline, ate
-- ~4MB por linha, na tabela QUENTE whatsapp_messages) crescia indefinidamente. Esta
-- policy fecha esse buraco e encaixa no H4 (tirar blob pesado da tabela quente).
--
-- Semantica: apos 90 dias, o media_base64 e NULLificado (acao 'anonimizar' -> NULL na
-- coluna, executada pelo case novo em lgpd_retention_tick.php). A LINHA da mensagem e a
-- metadata (message_type, caption, media_url, media_mimetype, media_filename) sao
-- PRESERVADAS. Midia recente segue viavel via re-fetch da Evolution (media.php tem
-- fallback por media_url e por raw_payload, re-cacheando sob demanda); midia com >90d
-- fica minimizada (LGPD Art. 6 III / 16). raw_payload ja e minimizado aos 30d (policy #3),
-- entao 90d > 30d mantem a ordem correta: a midia visivel dura mais que o metadata bruto.
--
-- Idempotente: so insere se a policy ainda nao existir (derived table + NOT EXISTS).

INSERT INTO retention_policies (entidade, campo_referencia, retencao_dias, acao_apos, base_legal, ativo)
SELECT t.entidade, t.campo_referencia, t.retencao_dias, t.acao_apos, t.base_legal, t.ativo
FROM (
  SELECT
    'whatsapp_messages_media'                                        AS entidade,
    'created_at'                                                     AS campo_referencia,
    90                                                               AS retencao_dias,
    'anonimizar'                                                     AS acao_apos,
    'media_base64 (arquivo inline ate ~4MB) e cache pesado na tabela quente whatsapp_messages; apos 90 dias a midia e minimizada NULLificando a coluna (LGPD Art. 6 III / 16). Metadata (tipo, caption, media_url, mimetype, filename) e a linha da mensagem sao preservadas; midia recente segue viavel via re-fetch da Evolution (media.php).' AS base_legal,
    1                                                                AS ativo
) AS t
WHERE NOT EXISTS (
  SELECT 1 FROM retention_policies rp WHERE rp.entidade = 'whatsapp_messages_media'
);
