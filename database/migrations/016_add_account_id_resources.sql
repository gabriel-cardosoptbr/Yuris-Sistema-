-- ═══════════════════════════════════════════════════════════════════════════════
-- 016_add_account_id_resources.sql
-- Adiciona account_id em TODOS os recursos do sistema
--
-- ESTRATÉGIA SEGURA (zero downtime):
--   Fase 1: ADD COLUMN nullable  → sem quebrar INSERTs existentes
--   Fase 2: UPDATE com default   → popula dados históricos
--   Fase 3: MODIFY NOT NULL      → garante integridade futura
--   Fase 4: ADD INDEX + FK       → performance e referencial
--
-- DEPENDE DE: 014_create_accounts.sql, 015_create_sharing_system.sql
-- ═══════════════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────────────────
-- Obtém ID da conta padrão (criada na migration 014)
-- ─────────────────────────────────────────────────────────────────────────────
SET @default_account_id = (SELECT id FROM accounts ORDER BY id ASC LIMIT 1);

-- ─────────────────────────────────────────────────────────────────────────────
-- CARDS
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE cards
  ADD COLUMN IF NOT EXISTS account_id INT NULL
    AFTER id
    COMMENT 'FK → accounts.id | Tenant dono do card';

SET @sql = CONCAT(
  'UPDATE cards SET account_id = ', @default_account_id,
  ' WHERE account_id IS NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE cards
  MODIFY account_id INT NOT NULL,
  ADD INDEX IF NOT EXISTS idx_cards_account (account_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- PROCESSOS
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE processos
  ADD COLUMN IF NOT EXISTS account_id INT NULL
    AFTER id
    COMMENT 'FK → accounts.id | Tenant dono do processo';

SET @sql = CONCAT(
  'UPDATE processos SET account_id = ', @default_account_id,
  ' WHERE account_id IS NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE processos
  MODIFY account_id INT NOT NULL,
  ADD INDEX IF NOT EXISTS idx_processos_account (account_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- CONTATOS
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE contatos
  ADD COLUMN IF NOT EXISTS account_id INT NULL
    AFTER id
    COMMENT 'FK → accounts.id | Tenant dono do contato';

SET @sql = CONCAT(
  'UPDATE contatos SET account_id = ', @default_account_id,
  ' WHERE account_id IS NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE contatos
  MODIFY account_id INT NOT NULL,
  ADD INDEX IF NOT EXISTS idx_contatos_account (account_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- PIPELINE_COLUMNS
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE pipeline_columns
  ADD COLUMN IF NOT EXISTS account_id INT NULL
    AFTER id
    COMMENT 'FK → accounts.id | Colunas são por tenant';

SET @sql = CONCAT(
  'UPDATE pipeline_columns SET account_id = ', @default_account_id,
  ' WHERE account_id IS NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE pipeline_columns
  MODIFY account_id INT NOT NULL,
  ADD INDEX IF NOT EXISTS idx_pipeline_account (account_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- TASK_BOARDS
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE task_boards
  ADD COLUMN IF NOT EXISTS account_id INT NULL
    AFTER id
    COMMENT 'FK → accounts.id | Boards são por tenant';

SET @sql = CONCAT(
  'UPDATE task_boards SET account_id = ', @default_account_id,
  ' WHERE account_id IS NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE task_boards
  MODIFY account_id INT NOT NULL,
  ADD INDEX IF NOT EXISTS idx_taskboards_account (account_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- GOALS
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE goals
  ADD COLUMN IF NOT EXISTS account_id INT NULL
    AFTER id
    COMMENT 'FK → accounts.id | Metas são por tenant';

SET @sql = CONCAT(
  'UPDATE goals SET account_id = ', @default_account_id,
  ' WHERE account_id IS NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE goals
  MODIFY account_id INT NOT NULL,
  ADD INDEX IF NOT EXISTS idx_goals_account (account_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- WHATSAPP_INSTANCES (instâncias pertencem à conta)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE whatsapp_instances
  ADD COLUMN IF NOT EXISTS account_id INT NULL
    AFTER id
    COMMENT 'FK → accounts.id | Instâncias WhatsApp são por tenant';

SET @sql = CONCAT(
  'UPDATE whatsapp_instances SET account_id = ', @default_account_id,
  ' WHERE account_id IS NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE whatsapp_instances
  MODIFY account_id INT NOT NULL,
  ADD INDEX IF NOT EXISTS idx_wpinst_account (account_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- FOREIGN KEYS (adicionadas depois de popular para evitar erros de integridade)
-- Nota: Se FK já existir, o MySQL ignora o erro com IF NOT EXISTS (MySQL 8.0+)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE cards
  ADD CONSTRAINT IF NOT EXISTS fk_cards_account
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT;

ALTER TABLE processos
  ADD CONSTRAINT IF NOT EXISTS fk_processos_account
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT;

ALTER TABLE contatos
  ADD CONSTRAINT IF NOT EXISTS fk_contatos_account
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT;

ALTER TABLE pipeline_columns
  ADD CONSTRAINT IF NOT EXISTS fk_pipeline_account
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT;

ALTER TABLE task_boards
  ADD CONSTRAINT IF NOT EXISTS fk_taskboards_account
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT;

ALTER TABLE goals
  ADD CONSTRAINT IF NOT EXISTS fk_goals_account
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT;

ALTER TABLE whatsapp_instances
  ADD CONSTRAINT IF NOT EXISTS fk_wpinst_account
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT;

-- ─────────────────────────────────────────────────────────────────────────────
-- USERS: tornar account_id NOT NULL após popular
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE users
  MODIFY account_id INT NOT NULL,
  ADD CONSTRAINT IF NOT EXISTS fk_users_account
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT;
