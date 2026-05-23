-- ═══════════════════════════════════════════════════════════════════════════════
-- 035_servidor_full_multitenant.sql
-- ═══════════════════════════════════════════════════════════════════════════════
-- Pacote ÚNICO e IDEMPOTENTE que migra um servidor SINGLE-TENANT para
-- a arquitetura MULTI-TENANT completa do Yuris.
--
-- Pode ser executado várias vezes — todas as operações usam IF NOT EXISTS.
-- Não destrói dados existentes; apenas adiciona estrutura e migra os registros
-- atuais para a conta principal (matriz).
--
-- COMO RODAR NO SERVIDOR:
--   1. Faça BACKUP do banco antes (mysqldump ou pelo phpMyAdmin → Exportar)
--   2. phpMyAdmin → selecione o banco → aba SQL
--   3. Cole este arquivo todo e clique em Executar
--   4. Confira os SELECTs de verificação no final
--
-- AGRUPA AS MIGRATIONS:
--   014, 015, 016, 022, 024, 025, 027, 032, 033, 034
-- ═══════════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ═════════════════════════════════════════════════════════════════════════════
-- BLOCO 1 — TABELA `accounts` (tenants do sistema)
-- ═════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS accounts (
  id              INT           AUTO_INCREMENT PRIMARY KEY,
  nome            VARCHAR(191)  NOT NULL,
  tipo            ENUM('matriz','filial') NOT NULL DEFAULT 'matriz',
  matriz_id       INT           DEFAULT NULL COMMENT 'NULL se for matriz; FK para accounts.id se for filial',
  codigo_vinculo  VARCHAR(64)   NOT NULL UNIQUE COMMENT 'UUID público para vínculo',
  plano           VARCHAR(50)   NOT NULL DEFAULT 'basico' COMMENT 'basico | profissional | enterprise',
  status          ENUM('active','suspended','cancelled') DEFAULT 'active',
  configuracoes   JSON          DEFAULT NULL,
  created_at      DATETIME      DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME      DEFAULT NULL,
  INDEX idx_accounts_tipo      (tipo),
  INDEX idx_accounts_matriz_id (matriz_id),
  INDEX idx_accounts_status    (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Caso a tabela já existisse sem essas colunas (migração antiga incompleta)
ALTER TABLE accounts
  ADD COLUMN IF NOT EXISTS plano         VARCHAR(50) NOT NULL DEFAULT 'basico' AFTER status,
  ADD COLUMN IF NOT EXISTS configuracoes JSON        DEFAULT NULL              AFTER plano,
  ADD COLUMN IF NOT EXISTS deleted_at    DATETIME    DEFAULT NULL;

-- Cria a conta principal se não existir nenhuma ainda
INSERT INTO accounts (nome, tipo, codigo_vinculo, plano, status)
SELECT 'Escritório Principal', 'matriz',
       LOWER(CONCAT(
         LPAD(HEX(FLOOR(RAND()*0xFFFF)),4,'0'),'-',
         LPAD(HEX(FLOOR(RAND()*0xFFFF)),4,'0'),'-',
         LPAD(HEX(FLOOR(RAND()*0xFFFF)),4,'0'),'-',
         LPAD(HEX(FLOOR(RAND()*0xFFFF)),4,'0')
       )),
       'basico', 'active'
WHERE NOT EXISTS (SELECT 1 FROM accounts LIMIT 1);

-- Garante código de vínculo em todas as contas
UPDATE accounts
SET codigo_vinculo = LOWER(CONCAT(
  LPAD(HEX(FLOOR(RAND()*0xFFFF)),4,'0'),'-',
  LPAD(HEX(FLOOR(RAND()*0xFFFF)),4,'0'),'-',
  LPAD(HEX(FLOOR(RAND()*0xFFFF)),4,'0'),'-',
  LPAD(HEX(FLOOR(RAND()*0xFFFF)),4,'0')
))
WHERE codigo_vinculo IS NULL OR codigo_vinculo = '';

SET @default_account_id = (SELECT id FROM accounts ORDER BY id ASC LIMIT 1);

-- ═════════════════════════════════════════════════════════════════════════════
-- BLOCO 2 — TABELA `users` (multi-tenant + role + codigo_advogado)
-- ═════════════════════════════════════════════════════════════════════════════
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD COLUMN IF NOT EXISTS role
    ENUM('owner','admin','manager','user','viewer') NOT NULL DEFAULT 'user' AFTER perfil,
  ADD COLUMN IF NOT EXISTS codigo_vinculo  VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS codigo_advogado VARCHAR(20) DEFAULT NULL
      COMMENT 'ID universal do advogado (ADV-XXXXXX) — único na plataforma',
  ADD COLUMN IF NOT EXISTS senha_texto     VARCHAR(255) DEFAULT NULL,
  ADD INDEX IF NOT EXISTS idx_users_account (account_id),
  ADD INDEX IF NOT EXISTS idx_users_role    (role);

-- Popula account_id em todos os usuários com a conta padrão
SET @sql := CONCAT('UPDATE users SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: gera ADV-XXXXXX para users sem código
UPDATE users
SET codigo_advogado = CONCAT('ADV-', UPPER(SUBSTRING(MD5(CONCAT(id, RAND(), UNIX_TIMESTAMP())), 1, 6)))
WHERE codigo_advogado IS NULL OR codigo_advogado = '';

-- Mapeia perfil legado → role (só atualiza quem ainda está no default 'user')
UPDATE users
SET role = CASE WHEN perfil = 'admin' THEN 'owner' ELSE 'user' END
WHERE role = 'user' AND perfil = 'admin';

-- Sincroniza codigo_vinculo = codigo_advogado (unifica)
UPDATE users
SET codigo_vinculo = codigo_advogado
WHERE codigo_advogado IS NOT NULL
  AND (codigo_vinculo IS NULL OR codigo_vinculo != codigo_advogado);

-- Cria UNIQUE em codigo_advogado se ainda não existir
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='uk_users_codigo_advogado'),
  'SELECT 1',
  'ALTER TABLE users ADD UNIQUE INDEX uk_users_codigo_advogado (codigo_advogado)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ═════════════════════════════════════════════════════════════════════════════
-- BLOCO 3 — TABELA `account_audit_log` (log imutável)
-- ═════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS account_audit_log (
  id            INT          AUTO_INCREMENT PRIMARY KEY,
  account_id    INT          NOT NULL,
  user_id       INT          DEFAULT NULL,
  acao          VARCHAR(100) NOT NULL,
  entidade      VARCHAR(50)  DEFAULT NULL,
  entidade_id   INT          DEFAULT NULL,
  dados_antes   JSON         DEFAULT NULL,
  dados_depois  JSON         DEFAULT NULL,
  ip            VARCHAR(45)  DEFAULT NULL,
  user_agent    VARCHAR(500) DEFAULT NULL,
  created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_account  (account_id),
  INDEX idx_audit_user     (user_id),
  INDEX idx_audit_acao     (acao),
  INDEX idx_audit_entidade (entidade, entidade_id),
  INDEX idx_audit_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═════════════════════════════════════════════════════════════════════════════
-- BLOCO 4 — TABELA `account_vinculos` (matriz ↔ filial)
-- ═════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS account_vinculos (
  id                INT      AUTO_INCREMENT PRIMARY KEY,
  matriz_account_id INT      NOT NULL,
  filial_account_id INT      NOT NULL,
  status            ENUM('pending','active','suspended','rejected') DEFAULT 'pending',
  solicitado_por    INT      DEFAULT NULL,
  aprovado_por      INT      DEFAULT NULL,
  solicitado_em     DATETIME DEFAULT CURRENT_TIMESTAMP,
  aprovado_em       DATETIME DEFAULT NULL,
  suspenso_em       DATETIME DEFAULT NULL,
  motivo_suspensao  TEXT     DEFAULT NULL,
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vinculo (matriz_account_id, filial_account_id),
  INDEX idx_vmc_filial (filial_account_id),
  INDEX idx_vmc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE account_vinculos
  ADD COLUMN IF NOT EXISTS solicitado_por   INT      DEFAULT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS aprovado_por     INT      DEFAULT NULL AFTER solicitado_por,
  ADD COLUMN IF NOT EXISTS solicitado_em    DATETIME DEFAULT CURRENT_TIMESTAMP AFTER aprovado_por,
  ADD COLUMN IF NOT EXISTS aprovado_em      DATETIME DEFAULT NULL AFTER solicitado_em,
  ADD COLUMN IF NOT EXISTS suspenso_em      DATETIME DEFAULT NULL AFTER aprovado_em,
  ADD COLUMN IF NOT EXISTS motivo_suspensao TEXT     DEFAULT NULL AFTER suspenso_em;

-- ═════════════════════════════════════════════════════════════════════════════
-- BLOCO 5 — TABELA `resource_shares` (compartilhamento)
-- ═════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS resource_shares (
  id               INT      AUTO_INCREMENT PRIMARY KEY,
  resource_type    ENUM('card','processo','contato','task_board','module') NOT NULL,
  resource_id      INT      NOT NULL DEFAULT 0,
  module_key       VARCHAR(40) DEFAULT NULL COMMENT 'Chave do módulo quando resource_type=module',
  from_account_id  INT      NOT NULL,
  to_account_id    INT      DEFAULT NULL,
  to_user_id       INT      DEFAULT NULL,
  permission_level ENUM('view','edit','full') NOT NULL DEFAULT 'view',
  criado_por       INT      DEFAULT NULL,
  status           ENUM('active','revoked') DEFAULT 'active',
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  revoked_at       DATETIME DEFAULT NULL,
  revoked_by       INT      DEFAULT NULL,
  INDEX idx_rs_resource (resource_type, resource_id),
  INDEX idx_rs_module   (module_key, status),
  INDEX idx_rs_from     (from_account_id),
  INDEX idx_rs_to       (to_account_id),
  INDEX idx_rs_to_user  (to_user_id),
  INDEX idx_rs_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Caso tabela já existisse sem essas colunas
ALTER TABLE resource_shares
  MODIFY COLUMN resource_type ENUM('card','processo','contato','task_board','module') NOT NULL,
  ADD COLUMN IF NOT EXISTS module_key VARCHAR(40) DEFAULT NULL AFTER resource_id,
  ADD COLUMN IF NOT EXISTS to_user_id INT      DEFAULT NULL AFTER to_account_id,
  ADD COLUMN IF NOT EXISTS criado_por INT      DEFAULT NULL AFTER permission_level,
  ADD COLUMN IF NOT EXISTS revoked_at DATETIME DEFAULT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS revoked_by INT      DEFAULT NULL AFTER revoked_at,
  ADD INDEX IF NOT EXISTS idx_rs_module   (module_key, status),
  ADD INDEX IF NOT EXISTS idx_rs_to_user  (to_user_id);

-- ═════════════════════════════════════════════════════════════════════════════
-- BLOCO 6 — TABELA `advogado_convites` (legado; ainda usada para histórico)
-- ═════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS advogado_convites (
  id                INT          AUTO_INCREMENT PRIMARY KEY,
  resource_type     ENUM('card','processo','contato') NOT NULL,
  resource_id       INT          NOT NULL,
  from_account_id   INT          NOT NULL,
  convidado_user_id INT          DEFAULT NULL,
  convidado_email   VARCHAR(191) NOT NULL,
  convidado_nome    VARCHAR(191) DEFAULT NULL,
  token_convite     VARCHAR(64)  NOT NULL UNIQUE,
  permission_level  ENUM('view','edit') NOT NULL DEFAULT 'view',
  mensagem          TEXT         DEFAULT NULL,
  status            ENUM('pending','accepted','rejected','revoked','expired') DEFAULT 'pending',
  expires_at        DATETIME     NOT NULL,
  created_at        DATETIME     DEFAULT CURRENT_TIMESTAMP,
  responded_at      DATETIME     DEFAULT NULL,
  revoked_at        DATETIME     DEFAULT NULL,
  revoked_by        INT          DEFAULT NULL,
  INDEX idx_conv_token   (token_convite),
  INDEX idx_conv_email   (convidado_email),
  INDEX idx_conv_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═════════════════════════════════════════════════════════════════════════════
-- BLOCO 7 — TABELA `account_notifications`
-- ═════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS account_notifications (
  id         INT          AUTO_INCREMENT PRIMARY KEY,
  account_id INT          NOT NULL,
  user_id    INT          DEFAULT NULL,
  tipo       VARCHAR(50)  NOT NULL,
  titulo     VARCHAR(255) NOT NULL,
  mensagem   TEXT         DEFAULT NULL,
  lida       TINYINT(1)   DEFAULT 0,
  lida_em    DATETIME     DEFAULT NULL,
  payload    JSON         DEFAULT NULL,
  created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_account (account_id),
  INDEX idx_notif_user    (user_id),
  INDEX idx_notif_lida    (lida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═════════════════════════════════════════════════════════════════════════════
-- BLOCO 8 — account_id em TODOS os recursos
-- ═════════════════════════════════════════════════════════════════════════════

-- Cards
ALTER TABLE cards
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_cards_account (account_id);
SET @sql := CONCAT('UPDATE cards SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Processos
ALTER TABLE processos
  ADD COLUMN IF NOT EXISTS account_id  INT NULL AFTER id,
  ADD COLUMN IF NOT EXISTS account_seq INT NULL AFTER account_id,
  ADD INDEX IF NOT EXISTS idx_processos_account (account_id);
SET @sql := CONCAT('UPDATE processos SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill account_seq (1..N por conta), só para registros sem
SET @prev := NULL, @seq := 0;
UPDATE processos p
INNER JOIN (
  SELECT id, account_id,
         (@seq := IF(@prev = account_id, @seq + 1, 1)) AS new_seq,
         (@prev := account_id) AS _p
  FROM processos
  WHERE account_seq IS NULL OR account_seq = 0
  ORDER BY account_id ASC, id ASC
) t ON t.id = p.id
SET p.account_seq = t.new_seq;

-- UNIQUE composto (account_id, account_seq)
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='processos' AND INDEX_NAME='uk_processos_account_seq'),
  'SELECT 1',
  'ALTER TABLE processos ADD UNIQUE INDEX uk_processos_account_seq (account_id, account_seq)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Contatos
ALTER TABLE contatos
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_contatos_account (account_id);
SET @sql := CONCAT('UPDATE contatos SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Pipeline Columns
ALTER TABLE pipeline_columns
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_pipeline_account (account_id);
SET @sql := CONCAT('UPDATE pipeline_columns SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Task Boards
ALTER TABLE task_boards
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_taskboards_account (account_id);
SET @sql := CONCAT('UPDATE task_boards SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Goals
ALTER TABLE goals
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_goals_account (account_id);
SET @sql := CONCAT('UPDATE goals SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- WhatsApp Instances
ALTER TABLE whatsapp_instances
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_wpinst_account (account_id);
SET @sql := CONCAT('UPDATE whatsapp_instances SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- DRE Accounts (financeiro)
ALTER TABLE dre_accounts
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_dre_accounts_account (account_id);
SET @sql := CONCAT('UPDATE dre_accounts SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- DRE Codes
ALTER TABLE dre_codes
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_dre_codes_account (account_id);
SET @sql := CONCAT('UPDATE dre_codes SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Taxes
ALTER TABLE taxes
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_taxes_account (account_id);
SET @sql := CONCAT('UPDATE taxes SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Webhooks
ALTER TABLE webhooks
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_webhooks_account (account_id);
SET @sql := CONCAT('UPDATE webhooks SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- WhatsApp Settings
ALTER TABLE whatsapp_settings
  ADD COLUMN IF NOT EXISTS account_id INT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_whatsapp_settings_account (account_id);
SET @sql := CONCAT('UPDATE whatsapp_settings SET account_id = ', @default_account_id, ' WHERE account_id IS NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ═════════════════════════════════════════════════════════════════════════════
-- VERIFICAÇÕES FINAIS
-- ═════════════════════════════════════════════════════════════════════════════
SELECT '═══ CONTAS ═══' AS info;
SELECT id, nome, tipo, plano, status, codigo_vinculo FROM accounts;

SELECT '═══ USUÁRIOS ═══' AS info;
SELECT id, nome, login, account_id, role, codigo_advogado FROM users ORDER BY id LIMIT 20;

SELECT '═══ TABELAS COM account_id ═══' AS info;
SELECT TABLE_NAME, COUNT(*) AS cols_account_id
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'account_id'
GROUP BY TABLE_NAME
ORDER BY TABLE_NAME;
