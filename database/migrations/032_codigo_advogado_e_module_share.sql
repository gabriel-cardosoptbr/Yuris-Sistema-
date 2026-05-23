-- ═══════════════════════════════════════════════════════════════════════════════
-- 032_codigo_advogado_e_module_share.sql
--
-- 1) Identificador único do advogado (ADV-XXXXXX) em users
-- 2) Suporte a compartilhamento de módulo inteiro em resource_shares
--    (module_key + resource_type='module' + resource_id pode ser 0/NULL)
-- ═══════════════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. users.codigo_advogado
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS codigo_advogado VARCHAR(20) DEFAULT NULL
    COMMENT 'Identificador único nacional do advogado no sistema (ADV-XXXXXX)';

-- Backfill: gera ADV-XXXXXX para users existentes que não têm
UPDATE users
SET codigo_advogado = CONCAT('ADV-', UPPER(SUBSTRING(MD5(CONCAT(id, RAND(), UNIX_TIMESTAMP())), 1, 6)))
WHERE codigo_advogado IS NULL OR codigo_advogado = '';

-- Adiciona UNIQUE depois do backfill (evita conflito)
ALTER TABLE users
  ADD UNIQUE INDEX IF NOT EXISTS uk_users_codigo_advogado (codigo_advogado);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. resource_shares: suporte a module
-- ─────────────────────────────────────────────────────────────────────────────
-- 2.1 amplia o enum resource_type para incluir 'module'
ALTER TABLE resource_shares
  MODIFY COLUMN resource_type ENUM('card','processo','contato','task_board','module') NOT NULL;

-- 2.2 adiciona module_key (NULL quando resource_type != 'module')
ALTER TABLE resource_shares
  ADD COLUMN IF NOT EXISTS module_key VARCHAR(40) DEFAULT NULL
    COMMENT 'Chave do módulo quando resource_type=module (ex: processos, juridico, dashboard)'
    AFTER resource_id;

-- 2.3 índice para busca rápida por módulo
ALTER TABLE resource_shares
  ADD INDEX IF NOT EXISTS idx_resource_shares_module (module_key, status);

-- 2.4 permite resource_id = 0 quando módulo (já é INT NOT NULL com default NULL — mantém 0)
-- Sem alteração de schema; convenção: quando resource_type='module', resource_id=0.

-- ─────────────────────────────────────────────────────────────────────────────
-- Verificações
-- ─────────────────────────────────────────────────────────────────────────────
-- SELECT id, nome, codigo_advogado FROM users ORDER BY id;
-- DESCRIBE resource_shares;
