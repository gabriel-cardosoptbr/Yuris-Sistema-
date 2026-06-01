-- ═══════════════════════════════════════════════════════════
-- 090_create_clientes.sql
-- Módulo Clientes (Operações) — base real de clientes do escritório,
-- separada da Prospecção (leads/oportunidades).
--
-- Estrutura espelha o padrão da Prospecção (cards + pipeline_columns)
-- mas com finalidade e dados isolados.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS + checks no runner PHP.
-- O seed dos 8 setores padrão por account_id é feito no run_090.php.
-- ═══════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- clientes_setores — colunas do kanban (uma "etapa operacional")
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS clientes_setores (
  id          INT           AUTO_INCREMENT PRIMARY KEY,
  account_id  INT           NOT NULL,
  nome        VARCHAR(191)  NOT NULL,
  slug        VARCHAR(191)  NOT NULL,
  cor         VARCHAR(20)   DEFAULT '#6366f1',
  ordem       INT           DEFAULT 0,
  ativo       TINYINT(1)    DEFAULT 1,
  created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cs_account (account_id),
  KEY idx_cs_account_ordem (account_id, ordem),
  UNIQUE KEY uk_cs_account_slug (account_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────────────
-- clientes — base de clientes ativos/antigos do escritório
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS clientes (
  id                INT            AUTO_INCREMENT PRIMARY KEY,
  account_id        INT            NOT NULL,
  contato_id        INT            DEFAULT NULL COMMENT 'FK para contatos (dedupe por telefone/email)',
  setor_id          INT            NOT NULL,
  responsavel_id    INT            DEFAULT NULL COMMENT 'FK para users — responsável interno',

  -- Identificação
  nome              VARCHAR(191)   NOT NULL,
  tipo_cliente      ENUM('PF','PJ')                                                                       DEFAULT 'PF',
  cpf_cnpj          VARCHAR(20)    DEFAULT NULL,

  -- Contato
  telefone          VARCHAR(50)    DEFAULT NULL,
  whatsapp          VARCHAR(50)    DEFAULT NULL,
  email             VARCHAR(191)   DEFAULT NULL,

  -- Operacional
  origem            ENUM('cliente_antigo','cliente_ativo','indicacao','cadastro_manual','outro')          DEFAULT 'cadastro_manual',
  status            VARCHAR(50)    DEFAULT 'ativo' COMMENT 'ativo | pausado | arquivado',
  ordem_no_setor    INT            DEFAULT 0,
  observacoes       TEXT           DEFAULT NULL,

  -- Auditoria
  created_by        INT            DEFAULT NULL,
  updated_by        INT            DEFAULT NULL,
  created_at        DATETIME       DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        DATETIME       DEFAULT NULL COMMENT 'Soft delete (arquivamento permanente)',
  deleted_by        INT            DEFAULT NULL,

  -- LGPD
  anonymized_at     DATETIME       DEFAULT NULL,
  deletion_reason   VARCHAR(200)   DEFAULT NULL,

  KEY idx_cli_account (account_id),
  KEY idx_cli_account_setor_ordem (account_id, setor_id, ordem_no_setor),
  KEY idx_cli_account_telefone (account_id, telefone),
  KEY idx_cli_account_whatsapp (account_id, whatsapp),
  KEY idx_cli_account_cpf_cnpj (account_id, cpf_cnpj),
  KEY idx_cli_account_deleted (account_id, deleted_at),
  KEY idx_cli_contato (contato_id),
  KEY idx_cli_responsavel (responsavel_id),
  KEY idx_cli_anon (anonymized_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─────────────────────────────────────────────────────────────
-- clientes_history — histórico de ações no cliente (audit log)
-- Espelha task_history em estrutura.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS clientes_history (
  id          INT           AUTO_INCREMENT PRIMARY KEY,
  cliente_id  INT           NOT NULL,
  account_id  INT           NOT NULL,
  user_id     INT           DEFAULT NULL,
  acao        VARCHAR(80)   NOT NULL COMMENT 'created | updated | moved | archived | restored | merged',
  antes_json  LONGTEXT      DEFAULT NULL CHECK (json_valid(antes_json)),
  depois_json LONGTEXT      DEFAULT NULL CHECK (json_valid(depois_json)),
  created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ch_cliente_data (cliente_id, created_at),
  KEY idx_ch_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
