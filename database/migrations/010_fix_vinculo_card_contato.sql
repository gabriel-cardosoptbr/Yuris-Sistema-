-- ═══════════════════════════════════════════════════════════
-- 010_fix_vinculo_card_contato.sql
-- Corrige a migração 008/009: adiciona contato_id em cards
-- na ordem correta e com sintaxe compatível com MySQL 5.7
-- ═══════════════════════════════════════════════════════════

-- ── Passo 1: Adicionar contato_id em cards (se ainda não existir) ────────────
-- Usa information_schema para verificar antes de criar (MySQL 5.7 safe)

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'cards'
    AND COLUMN_NAME  = 'contato_id'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE cards ADD COLUMN contato_id INT NULL',
  'SELECT ''cards.contato_id ja existe'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Passo 2: Criar índice em cards.contato_id (se ainda não existir) ─────────

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'cards'
    AND INDEX_NAME   = 'idx_card_contato'
);

SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE cards ADD INDEX idx_card_contato (contato_id)',
  'SELECT ''indice idx_card_contato ja existe'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Passo 3: Adicionar contato_id em processos (se ainda não existir) ────────

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'processos'
    AND COLUMN_NAME  = 'contato_id'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE processos ADD COLUMN contato_id INT NULL',
  'SELECT ''processos.contato_id ja existe'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Passo 4: Criar índice em processos.contato_id (se ainda não existir) ─────

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'processos'
    AND INDEX_NAME   = 'idx_processo_contato'
);

SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE processos ADD INDEX idx_processo_contato (contato_id)',
  'SELECT ''indice idx_processo_contato ja existe'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Passo 5: Adicionar contato_id em whatsapp_chats (se ainda não existir) ───

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'whatsapp_chats'
    AND COLUMN_NAME  = 'contato_id'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE whatsapp_chats ADD COLUMN contato_id INT NULL',
  'SELECT ''whatsapp_chats.contato_id ja existe'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Passo 6: Vincular cards aos contatos pelo telefone normalizado ────────────
-- Só atualiza cards que ainda não possuem contato_id

UPDATE cards c
  JOIN contatos ct
    ON ct.telefone = REGEXP_REPLACE(c.telefone_whatsapp, '[^0-9]', '')
SET c.contato_id = ct.id
WHERE c.telefone_whatsapp IS NOT NULL
  AND c.telefone_whatsapp <> ''
  AND c.contato_id IS NULL
  AND c.deleted_at IS NULL;

-- ── Passo 7: Processos herdam contato_id do card vinculado ───────────────────

UPDATE processos p
  JOIN cards c ON c.id = p.card_id
SET p.contato_id = c.contato_id
WHERE p.card_id IS NOT NULL
  AND c.contato_id IS NOT NULL
  AND p.contato_id IS NULL
  AND p.deleted_at IS NULL;

-- ── Passo 8: Chats herdam contato_id do card vinculado ───────────────────────

UPDATE whatsapp_chats wc
  JOIN cards c ON c.id = wc.linked_card_id
SET wc.contato_id = c.contato_id
WHERE wc.linked_card_id IS NOT NULL
  AND c.contato_id IS NOT NULL
  AND wc.contato_id IS NULL;

-- ── Passo 9: Popular contato_vinculos (sem duplicidade via INSERT IGNORE) ─────

INSERT IGNORE INTO contato_vinculos (contato_id, tipo_vinculo, referencia_id, origem)
SELECT contato_id, 'card', id, 'migracao'
FROM cards
WHERE contato_id IS NOT NULL
  AND deleted_at IS NULL;

INSERT IGNORE INTO contato_vinculos (contato_id, tipo_vinculo, referencia_id, origem)
SELECT contato_id, 'processo', id, 'migracao'
FROM processos
WHERE contato_id IS NOT NULL
  AND deleted_at IS NULL;

INSERT IGNORE INTO contato_vinculos (contato_id, tipo_vinculo, referencia_id, origem)
SELECT contato_id, 'chat', id, 'migracao'
FROM whatsapp_chats
WHERE contato_id IS NOT NULL;
