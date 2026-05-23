-- ═══════════════════════════════════════════════════════════════════════════════
-- 034_servidor_sync_users.sql
--
-- Patch IDEMPOTENTE para sincronizar a tabela `users` do SERVIDOR de produção
-- com o estado atual do sistema multi-tenant.
--
-- Pode ser executado várias vezes sem quebrar (usa IF NOT EXISTS).
-- Não apaga dados existentes — apenas adiciona colunas faltantes e backfilla.
--
-- Como rodar (no phpMyAdmin do servidor):
--   1. Acesse o banco do servidor → aba SQL
--   2. Cole este arquivo todo e clique em Executar
--   3. Confira o SELECT final pra ver que todos têm codigo_advogado
-- ═══════════════════════════════════════════════════════════════════════════════

-- 1) account_id ─────────────────────────────────────────────────────────────────
--    Adiciona o tenant ID. Se não existir nenhuma conta ainda, cria a conta
--    "Escritório Principal" e usa o id dela como padrão.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_users_account (account_id);

-- Garante que existe ao menos uma conta principal (matriz)
INSERT INTO accounts (nome, tipo, codigo_vinculo, status)
SELECT 'Escritório Principal', 'matriz', UUID(), 'active'
WHERE NOT EXISTS (SELECT 1 FROM accounts LIMIT 1);

-- Preenche account_id dos usuários órfãos com a conta mais antiga
UPDATE users
SET account_id = (SELECT id FROM accounts ORDER BY id ASC LIMIT 1)
WHERE account_id IS NULL;

-- 2) role (controle granular: owner|admin|manager|user|viewer) ──────────────────
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS role
    ENUM('owner','admin','manager','user','viewer')
    NOT NULL DEFAULT 'user'
    AFTER perfil,
  ADD INDEX IF NOT EXISTS idx_users_role (role);

-- Mapeia perfil legado para role (executa só se o role ainda estiver no default)
UPDATE users
SET role = CASE WHEN perfil = 'admin' THEN 'owner' ELSE 'user' END
WHERE role = 'user' AND (perfil = 'admin');

-- 3) codigo_vinculo (legado — formato xxxx-xxxx-xxxx-xxxx) ──────────────────────
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS codigo_vinculo VARCHAR(19) DEFAULT NULL;

-- Tenta criar UNIQUE; ignora se já existir
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'users'
        AND INDEX_NAME   = 'codigo_vinculo'
    ),
    'SELECT "uk_users_codigo_vinculo já existe"',
    'ALTER TABLE users ADD UNIQUE KEY codigo_vinculo (codigo_vinculo)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) codigo_advogado (ID UNIVERSAL — formato ADV-XXXXXX) ────────────────────────
--    Esse é o "CPF" do advogado dentro da plataforma.
--    Único em toda a base, independente da conta (matriz/filial/associado).
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS codigo_advogado VARCHAR(20) DEFAULT NULL
    COMMENT 'ID universal do advogado (ADV-XXXXXX) — único na plataforma';

-- Backfill: gera ADV-XXXXXX para qualquer user sem o código
UPDATE users
SET codigo_advogado = CONCAT('ADV-', UPPER(SUBSTRING(MD5(CONCAT(id, RAND(), UNIX_TIMESTAMP())), 1, 6)))
WHERE codigo_advogado IS NULL OR codigo_advogado = '';

-- Tenta criar UNIQUE
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'users'
        AND INDEX_NAME   = 'uk_users_codigo_advogado'
    ),
    'SELECT "uk_users_codigo_advogado já existe"',
    'ALTER TABLE users ADD UNIQUE INDEX uk_users_codigo_advogado (codigo_advogado)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) Sincroniza codigo_vinculo = codigo_advogado ────────────────────────────────
--    Unifica os dois códigos no mesmo valor (ADV-XXXXXX).
--    Quem buscar pelo nome antigo continua encontrando.
UPDATE users
SET codigo_vinculo = codigo_advogado
WHERE codigo_advogado IS NOT NULL
  AND (codigo_vinculo IS NULL OR codigo_vinculo != codigo_advogado);

-- 6) senha_texto (opcional — usado em modos de "ver senha em texto") ────────────
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS senha_texto VARCHAR(255) DEFAULT NULL;

-- ═══════════════════════════════════════════════════════════════════════════════
-- VERIFICAÇÃO
-- ═══════════════════════════════════════════════════════════════════════════════
SELECT id, nome, login, account_id, role, codigo_advogado, codigo_vinculo
FROM users
ORDER BY id;
