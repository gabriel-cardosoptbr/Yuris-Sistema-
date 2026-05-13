-- ═══════════════════════════════════════════════════════════════════════════════
-- 014_create_accounts.sql
-- Multi-tenancy: Contas (Matriz / Filial) + Auditoria
--
-- PADRÃO DE MERCADO:
--   Shared-database / Shared-schema com tenant_id por coluna.
--   Modelo adotado por: Clio, PracticePanther, HubSpot, Pipedrive, Salesforce.
--   Referência: https://docs.aws.amazon.com/wellarchitected/saas-lens
--
-- EXECUÇÃO SEGURA (sem downtime):
--   1. Cria tabela accounts
--   2. Insere conta padrão para dados existentes
--   3. Adiciona colunas em users como NULLABLE
--   4. Popula com a conta padrão
--   5. Converte para NOT NULL
-- ═══════════════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. TABELA DE CONTAS (TENANTS)
--    Hierarquia: Matriz → Filial (max 1 nível, evita complexidade de árvore)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS accounts (
  id              INT           AUTO_INCREMENT PRIMARY KEY,
  nome            VARCHAR(191)  NOT NULL,
  tipo            ENUM('matriz','filial')
                                NOT NULL DEFAULT 'matriz',
  matriz_id       INT           DEFAULT NULL
                                COMMENT 'NULL se for matriz; FK para accounts.id se for filial',
  codigo_vinculo  VARCHAR(64)   NOT NULL UNIQUE
                                COMMENT 'UUID público para filial solicitar vínculo — nunca expor internamente',
  plano           VARCHAR(50)   NOT NULL DEFAULT 'basico'
                                COMMENT 'basico | profissional | enterprise',
  status          ENUM('active','suspended','cancelled')
                                DEFAULT 'active',
  configuracoes   JSON          DEFAULT NULL
                                COMMENT 'JSON de configurações personalizadas da conta',
  created_at      DATETIME      DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME      DEFAULT NULL,

  INDEX idx_accounts_tipo      (tipo),
  INDEX idx_accounts_matriz_id (matriz_id),
  INDEX idx_accounts_status    (status),

  CONSTRAINT fk_account_matriz
    FOREIGN KEY (matriz_id) REFERENCES accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. CONTA PADRÃO para migração de dados existentes (idempotente)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO accounts (nome, tipo, codigo_vinculo, status)
SELECT 'Escritório Principal', 'matriz', UUID(), 'active'
WHERE NOT EXISTS (SELECT 1 FROM accounts LIMIT 1);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. EVOLUÇÃO DA TABELA users
--    Adiciona: account_id, role (mais granular que perfil)
--    Mantém: perfil (compatibilidade retroativa)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS account_id INT NULL
    AFTER id
    COMMENT 'FK → accounts.id | Tenant do usuário',

  ADD COLUMN IF NOT EXISTS role
    ENUM('owner','admin','manager','user','viewer')
    NOT NULL DEFAULT 'user'
    AFTER perfil
    COMMENT 'owner = dono da conta | admin = gestor | manager = gerente | user = operacional | viewer = somente leitura';

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. POPULAR account_id nos usuários existentes
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE users
SET account_id = (SELECT id FROM accounts ORDER BY id ASC LIMIT 1),
    role = CASE
             WHEN perfil = 'admin' THEN 'owner'
             ELSE 'user'
           END
WHERE account_id IS NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. ÍNDICES em users
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE users
  ADD INDEX IF NOT EXISTS idx_users_account (account_id),
  ADD INDEX IF NOT EXISTS idx_users_role    (role);

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. LOG DE AUDITORIA DE CONTAS
--    Padrão: imutável, nunca deletar registros, apenas inserir
--    Referência: Stripe audit log, AWS CloudTrail
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS account_audit_log (
  id            INT          AUTO_INCREMENT PRIMARY KEY,
  account_id    INT          NOT NULL,
  user_id       INT          DEFAULT NULL,
  acao          VARCHAR(100) NOT NULL
                COMMENT 'Ex: vinculo.criado | share.revogado | convite.enviado | recurso.acessado',
  resource_type VARCHAR(50)  DEFAULT NULL,
  resource_id   INT          DEFAULT NULL,
  detalhes      JSON         DEFAULT NULL,
  ip            VARCHAR(45)  DEFAULT NULL,
  user_agent    VARCHAR(500) DEFAULT NULL,
  created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_audit_account  (account_id),
  INDEX idx_audit_user     (user_id),
  INDEX idx_audit_acao     (acao),
  INDEX idx_audit_resource (resource_type, resource_id),
  INDEX idx_audit_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
