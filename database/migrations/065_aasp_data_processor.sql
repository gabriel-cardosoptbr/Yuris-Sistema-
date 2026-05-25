-- ============================================================================
-- Migration 065 — Registra AASP como operador externo (LGPD Art. 33 + 39)
-- ============================================================================
--
-- Insere a Associação dos Advogados de São Paulo (AASP) na tabela
-- data_processors como operador de dados (LGPD Art. 5 XI).
--
-- Fundamentação:
--   • Tenants que configuram chave AASP enviam (via API GET com query-param chave)
--     o número da OAB do advogado-associado pra retornar publicações do DJEN.
--   • A AASP retorna PII contida nas próprias publicações (nomes de advogados,
--     partes, OABs, textos de intimações).
--   • Como o tenant configura voluntariamente, a base legal é CONSENTIMENTO.
--   • A relação Yuris ↔ AASP é técnica (não há contrato Yuris-AASP) — Yuris
--     atua como intermediário, AASP serve associados pelos próprios termos.
--
-- DPA status = "dispensado":
--   • AASP é entidade pública (associação profissional) e a API é benefício
--     pra associados existentes, sem contrato bilateral Yuris-AASP.
--   • Cada tenant é responsável pela própria assinatura/aceite dos termos da
--     AASP (https://www.aasp.org.br/termos-de-uso).
--
-- Transferência internacional: NÃO (servidor AASP em território brasileiro).
--
-- IDEMPOTENTE via INSERT IGNORE (UNIQUE em data_processors.nome).
-- ============================================================================

INSERT IGNORE INTO data_processors
  (nome, categoria, papel, cnpj_ou_id, pais,
   dados_tratados, finalidade, retencao_terceiro,
   transferencia_internacional, base_legal_transferencia,
   dpa_status, certificacoes, contato_dpo_terceiro, url_politica_privacidade,
   ativo, notas)
VALUES
(
  'AASP — API de Intimações',
  'api_externa',
  'operador',
  '47.358.769/0001-31',  -- CNPJ da AASP (associação)
  'BR',
  '{"categorias":["pii_basica","juridico"],"sensiveis":false,"volume_estimado":"variavel por tenant — depende de quantos associados/processos têm intimações"}',
  'Consulta de intimações publicadas no DJEN por meio da chave de API individual de associado ou de empresa (sociedade). Yuris atua como intermediário técnico — o tenant é quem possui vínculo associativo com a AASP.',
  'Conforme política da AASP. Retenção de logs de consulta tipicamente conforme termos da associação.',
  0,
  'nao_aplicavel',
  'dispensado',
  NULL,
  'atendimento@aasp.org.br',
  'https://www.aasp.org.br/termos-de-uso/',
  1,
  'IMPORTANTE: requer cadastro prévio do associado em https://intimacaoapi-cadastro.aasp.org.br/. Yuris cifra a chave AASP at-rest via APP_ENCRYPTION_KEY (AES-256-GCM). Auditoria em aasp_credential_audit. Sem contrato bilateral Yuris-AASP — cada tenant aceita termos da AASP individualmente. Base legal do tratamento na ponta-tenant: consentimento (admin do tenant configura voluntariamente).'
);


-- ─── Confirmação ───────────────────────────────────────────────────────────
SELECT '[065] AASP registrada em data_processors' AS resultado,
       (SELECT COUNT(*) FROM data_processors WHERE nome LIKE 'AASP%') AS processors_aasp;
