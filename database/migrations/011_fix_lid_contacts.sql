-- ═══════════════════════════════════════════════════════════
-- 011_fix_lid_contacts.sql
-- Corrige vínculos para contatos LID (sem telefone real)
-- e popula contato_vinculos retroativamente
-- ═══════════════════════════════════════════════════════════

-- ── Passo 1: Adicionar remote_jid em contatos (identificador alternativo para LIDs) ──
SET @col = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contatos' AND COLUMN_NAME = 'remote_jid'
);
SET @sql = IF(@col = 0,
  'ALTER TABLE contatos ADD COLUMN remote_jid VARCHAR(120) NULL AFTER telefone, ADD UNIQUE KEY uniq_remote_jid (remote_jid)',
  'SELECT ''contatos.remote_jid ja existe'' AS info'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Passo 2: Criar contatos para chats @lid que têm linked_card_id mas sem contato ──
-- Usa o nome do card como nome do contato e o JID como identificador
INSERT IGNORE INTO contatos (nome, telefone, remote_jid)
SELECT
  COALESCE(NULLIF(TRIM(c.cliente_nome), ''), wc.contact_name, 'Contato'),
  NULL,
  wc.remote_jid
FROM whatsapp_chats wc
JOIN cards c ON c.id = wc.linked_card_id
WHERE wc.remote_jid LIKE '%@lid'
  AND wc.contato_id IS NULL;

-- ── Passo 3: Propagar contato_id para whatsapp_chats ─────────────────────────
-- 3a: Via remote_jid (contatos @lid recém criados)
UPDATE whatsapp_chats wc
JOIN contatos ct ON ct.remote_jid = wc.remote_jid
SET wc.contato_id = ct.id
WHERE wc.contato_id IS NULL;

-- 3b: Via linked_card_id → cards.contato_id (contatos por telefone já existentes)
UPDATE whatsapp_chats wc
JOIN cards c ON c.id = wc.linked_card_id
SET wc.contato_id = c.contato_id
WHERE wc.contato_id IS NULL AND c.contato_id IS NOT NULL;

-- ── Passo 4: Propagar contato_id para cards que ainda não têm ────────────────
UPDATE cards c
JOIN whatsapp_chats wc ON wc.linked_card_id = c.id
SET c.contato_id = wc.contato_id
WHERE c.contato_id IS NULL AND wc.contato_id IS NOT NULL;

-- ── Passo 5: Popular contato_vinculos retroativamente ────────────────────────
INSERT IGNORE INTO contato_vinculos (contato_id, tipo_vinculo, referencia_id, origem)
SELECT contato_id, 'card', id, 'migracao'
FROM cards
WHERE contato_id IS NOT NULL AND deleted_at IS NULL;

INSERT IGNORE INTO contato_vinculos (contato_id, tipo_vinculo, referencia_id, origem)
SELECT contato_id, 'processo', id, 'migracao'
FROM processos
WHERE contato_id IS NOT NULL AND deleted_at IS NULL;

INSERT IGNORE INTO contato_vinculos (contato_id, tipo_vinculo, referencia_id, origem)
SELECT contato_id, 'chat', id, 'migracao'
FROM whatsapp_chats
WHERE contato_id IS NOT NULL;

-- ── Verificação final ─────────────────────────────────────────────────────────
SELECT 'contatos' AS tabela, COUNT(*) AS total FROM contatos
UNION ALL
SELECT 'contato_vinculos', COUNT(*) FROM contato_vinculos
UNION ALL
SELECT 'chats com contato_id', COUNT(*) FROM whatsapp_chats WHERE contato_id IS NOT NULL;
