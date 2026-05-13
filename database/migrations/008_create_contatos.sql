-- ═══════════════════════════════════════════════════════════
-- 008_create_contatos.sql
-- Entidade central de contato/cliente — âncora de todos os vínculos
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS contatos (
  id         INT           AUTO_INCREMENT PRIMARY KEY,
  nome       VARCHAR(191)  NOT NULL,
  telefone   VARCHAR(30)   NULL COMMENT 'Somente dígitos com DDI: 5511999998888',
  email      VARCHAR(191)  NULL,
  observacoes TEXT         NULL,
  created_at DATETIME      DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_telefone (telefone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Popula contatos a partir dos cards existentes que possuem telefone
INSERT IGNORE INTO contatos (nome, telefone)
SELECT
  cliente_nome,
  REGEXP_REPLACE(telefone_whatsapp, '[^0-9]', '')
FROM cards
WHERE telefone_whatsapp IS NOT NULL
  AND telefone_whatsapp <> ''
  AND deleted_at IS NULL;

-- Liga cards aos contatos pelo telefone normalizado
UPDATE cards c
  JOIN contatos ct
    ON ct.telefone = REGEXP_REPLACE(c.telefone_whatsapp, '[^0-9]', '')
SET c.contato_id = ct.id
WHERE c.telefone_whatsapp IS NOT NULL
  AND c.contato_id IS NULL;

-- Adiciona coluna contato_id em cards (se ainda não existir)
ALTER TABLE cards
  ADD COLUMN IF NOT EXISTS contato_id INT NULL AFTER id;
ALTER TABLE cards
  ADD INDEX IF NOT EXISTS idx_card_contato (contato_id);

-- Adiciona coluna contato_id em processos (se ainda não existir)
ALTER TABLE processos
  ADD COLUMN IF NOT EXISTS contato_id INT NULL AFTER id;
ALTER TABLE processos
  ADD INDEX IF NOT EXISTS idx_processo_contato (contato_id);

-- Processos herdam contato_id do card vinculado
UPDATE processos p
  JOIN cards c ON c.id = p.card_id
SET p.contato_id = c.contato_id
WHERE p.card_id IS NOT NULL
  AND c.contato_id IS NOT NULL
  AND p.contato_id IS NULL;

-- Adiciona coluna contato_id em whatsapp_chats (se ainda não existir)
ALTER TABLE whatsapp_chats
  ADD COLUMN IF NOT EXISTS contato_id INT NULL;
ALTER TABLE whatsapp_chats
  ADD INDEX IF NOT EXISTS idx_chat_contato (contato_id);

-- Chats herdam contato_id do card vinculado
UPDATE whatsapp_chats wc
  JOIN cards c ON c.id = wc.linked_card_id
SET wc.contato_id = c.contato_id
WHERE wc.linked_card_id IS NOT NULL
  AND c.contato_id IS NOT NULL
  AND wc.contato_id IS NULL;
