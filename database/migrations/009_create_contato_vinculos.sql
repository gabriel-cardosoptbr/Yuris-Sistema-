-- ═══════════════════════════════════════════════════════════
-- 009_create_contato_vinculos.sql
-- Tabela de vínculos permanentes: contato ↔ card/processo/chat
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS contato_vinculos (
  id            INT           AUTO_INCREMENT PRIMARY KEY,
  contato_id    INT           NOT NULL,
  tipo_vinculo  ENUM('card','processo','chat') NOT NULL,
  referencia_id INT           NOT NULL  COMMENT 'PK da tabela referenciada pelo tipo_vinculo',
  origem        VARCHAR(50)   NOT NULL DEFAULT 'manual'
                COMMENT 'Origem do vínculo: manual, sync, webhook, migracao',
  ativo         TINYINT(1)    NOT NULL DEFAULT 1,
  created_at    DATETIME      DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Garante que o mesmo vínculo não seja inserido duas vezes
  UNIQUE KEY uniq_vinculo (contato_id, tipo_vinculo, referencia_id),

  KEY idx_contato    (contato_id),
  KEY idx_referencia (tipo_vinculo, referencia_id),
  KEY idx_ativo      (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Migração retroativa ───────────────────────────────────────────────────────
-- Importa vínculos existentes em cards.contato_id
INSERT IGNORE INTO contato_vinculos (contato_id, tipo_vinculo, referencia_id, origem)
SELECT contato_id, 'card', id, 'migracao'
FROM cards
WHERE contato_id IS NOT NULL
  AND deleted_at IS NULL;

-- Importa vínculos existentes em processos.contato_id
INSERT IGNORE INTO contato_vinculos (contato_id, tipo_vinculo, referencia_id, origem)
SELECT contato_id, 'processo', id, 'migracao'
FROM processos
WHERE contato_id IS NOT NULL
  AND deleted_at IS NULL;

-- Importa vínculos existentes em whatsapp_chats.contato_id
INSERT IGNORE INTO contato_vinculos (contato_id, tipo_vinculo, referencia_id, origem)
SELECT contato_id, 'chat', id, 'migracao'
FROM whatsapp_chats
WHERE contato_id IS NOT NULL;
