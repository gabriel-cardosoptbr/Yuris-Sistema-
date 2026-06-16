-- ═══════════════════════════════════════════════════════════
-- 081_agent_configs_instance_ref.sql
-- Liga o Agente de IA a uma INSTÂNCIA WhatsApp real (fonte única da verdade),
-- em vez de um número solto em texto. Cumpre a "CORREÇÃO OBRIGATÓRIA" da
-- integração do agente com o WhatsApp/Evolution.
--
-- Mudanças em agent_configs:
--   + whatsapp_instance_id INT NULL  -> referência à instância (FK ON DELETE SET NULL)
--   + branch_id            INT NULL  -> filial quando aplicável (reservado; no YURIS a filial é uma account)
--   + updated_by           INT NULL  -> quem alterou por último
--   - whatsapp_number                -> DROP (número vem por relacionamento com a instância)
--   troca UNIQUE(account_id,user_id) -> UNIQUE(whatsapp_instance_id)
--   FK whatsapp_instance_id -> whatsapp_instances(id) ON DELETE SET NULL
--
-- POR QUE UNIQUE(whatsapp_instance_id) e não (account_id, whatsapp_instance_id):
--   a filial pode usar a instância da matriz (cross-account). Logo, "1 agente por
--   canal" só é garantido com a unicidade no id da instância sozinho (senão duas
--   contas diferentes poderiam apontar agentes pro mesmo canal). Cumpre a regra
--   da spec "impedir 2 configurações concorrentes para o mesmo canal".
--
-- Mantém provider/api_key_enc/prompt (cérebro do LLM; não são infra de canal).
--
-- Mudança em whatsapp_instances:
--   + UNIQUE(account_id, phone)  [impede 2 instâncias no mesmo número] (só se não houver duplicata)
--
-- Idempotência: garantida pelo runner PHP (run_081.php) via information_schema.
-- Este SQL é referência; o runner é a fonte da verdade pra apply.
-- ═══════════════════════════════════════════════════════════

ALTER TABLE agent_configs
  ADD COLUMN branch_id            INT NULL AFTER account_id,
  ADD COLUMN whatsapp_instance_id INT NULL AFTER user_id,
  ADD COLUMN updated_by           INT NULL AFTER prompt;

-- Backfill: casa o número antigo com o phone da instância da MESMA conta (normaliza só dígitos).
UPDATE agent_configs ac
JOIN whatsapp_instances wi
  ON wi.account_id = ac.account_id
 AND wi.phone IS NOT NULL
 AND REGEXP_REPLACE(wi.phone, '[^0-9]', '') = REGEXP_REPLACE(COALESCE(ac.whatsapp_number, ''), '[^0-9]', '')
 AND REGEXP_REPLACE(COALESCE(ac.whatsapp_number, ''), '[^0-9]', '') <> ''
SET ac.whatsapp_instance_id = wi.id
WHERE ac.whatsapp_instance_id IS NULL;

-- Dedup: garante 1 config por instância (mantém o de maior id; desvincula os demais).
UPDATE agent_configs ac
JOIN (
  SELECT whatsapp_instance_id, MAX(id) AS keep_id
    FROM agent_configs
   WHERE whatsapp_instance_id IS NOT NULL
   GROUP BY whatsapp_instance_id
) k ON k.whatsapp_instance_id = ac.whatsapp_instance_id
SET ac.whatsapp_instance_id = NULL
WHERE ac.id <> k.keep_id;

ALTER TABLE agent_configs DROP INDEX uk_agent_account_user;
ALTER TABLE agent_configs ADD UNIQUE KEY uk_agent_instance (whatsapp_instance_id);
ALTER TABLE agent_configs
  ADD CONSTRAINT fk_agent_wpinstance FOREIGN KEY (whatsapp_instance_id)
  REFERENCES whatsapp_instances(id) ON DELETE SET NULL;
ALTER TABLE agent_configs DROP COLUMN whatsapp_number;

ALTER TABLE whatsapp_instances ADD UNIQUE KEY uk_instances_account_phone (account_id, phone);
