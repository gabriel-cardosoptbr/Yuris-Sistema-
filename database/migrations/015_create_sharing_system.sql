-- ═══════════════════════════════════════════════════════════════════════════════
-- 015_create_sharing_system.sql
-- Sistema de Vínculos, Compartilhamento e Convites
--
-- PADRÕES DE MERCADO ADOTADOS:
--   • account_vinculos  → Google Workspace (domain federation) / Slack Connect
--   • resource_shares   → Notion "Share page" / Google Docs colaboradores
--   • advogado_convites → Figma invite link / GitHub repo collaborator invite
--   • notifications     → Slack / Linear notification inbox
--
-- DEPENDE DE: 014_create_accounts.sql
-- ═══════════════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. VÍNCULOS ENTRE CONTAS (Matriz ↔ Filial)
--    Fluxo: Filial solicita → Matriz aprova → vínculo ativo
--    Inspirado no modelo "Organization Connection" do GitHub Enterprise
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS account_vinculos (
  id                  INT          AUTO_INCREMENT PRIMARY KEY,
  matriz_account_id   INT          NOT NULL,
  filial_account_id   INT          NOT NULL,
  status              ENUM('pending','active','suspended','rejected')
                                   DEFAULT 'pending',
  solicitado_por      INT          DEFAULT NULL
                                   COMMENT 'user_id que iniciou a solicitação',
  aprovado_por        INT          DEFAULT NULL
                                   COMMENT 'user_id owner/admin da Matriz que aprovou',
  solicitado_em       DATETIME     DEFAULT CURRENT_TIMESTAMP,
  aprovado_em         DATETIME     DEFAULT NULL,
  suspenso_em         DATETIME     DEFAULT NULL,
  motivo_suspensao    TEXT         DEFAULT NULL,
  created_at          DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_vinculo (matriz_account_id, filial_account_id),
  INDEX idx_vmc_filial  (filial_account_id),
  INDEX idx_vmc_status  (status),

  CONSTRAINT fk_vmc_matriz  FOREIGN KEY (matriz_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_vmc_filial  FOREIGN KEY (filial_account_id) REFERENCES accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. COMPARTILHAMENTO SELETIVO DE RECURSOS
--    Granularidade: por recurso individual OU todos os recursos de um tipo
--    Padrão: "Shared with" do Notion + ACL do Google Drive
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS resource_shares (
  id               INT          AUTO_INCREMENT PRIMARY KEY,
  resource_type    ENUM('card','processo','contato','task_board')
                                NOT NULL,
  resource_id      INT          NOT NULL,
  from_account_id  INT          NOT NULL
                                COMMENT 'Conta dona do recurso',
  to_account_id    INT          DEFAULT NULL
                                COMMENT 'NULL = compartilhado com TODAS as contas vinculadas (Matriz→todas Filiais ou vice-versa)',
  to_user_id       INT          DEFAULT NULL
                                COMMENT 'NULL = para toda a conta | preenchido = para usuário específico dentro da conta',
  permission_level ENUM('view','edit','full')
                                NOT NULL DEFAULT 'view'
                                COMMENT 'view=leitura | edit=edição | full=edição+exclusão+recompartilhamento',
  criado_por       INT          NOT NULL
                                COMMENT 'user_id que criou o share',
  status           ENUM('active','revoked')
                                DEFAULT 'active',
  created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
  revoked_at       DATETIME     DEFAULT NULL,
  revoked_by       INT          DEFAULT NULL,

  INDEX idx_rs_resource (resource_type, resource_id),
  INDEX idx_rs_from     (from_account_id),
  INDEX idx_rs_to       (to_account_id),
  INDEX idx_rs_to_user  (to_user_id),
  INDEX idx_rs_status   (status),

  CONSTRAINT fk_rs_from FOREIGN KEY (from_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_rs_to   FOREIGN KEY (to_account_id)   REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. CONVITES PARA ADVOGADO ASSOCIADO (externo)
--    Token: bin2hex(random_bytes(32)) — criptograficamente seguro
--    Padrão: Figma invite link + GitHub collaborator invite com expiração
--    Idempotência: UNIQUE em (resource_type, resource_id, convidado_email, status='pending')
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS advogado_convites (
  id                  INT          AUTO_INCREMENT PRIMARY KEY,
  resource_type       ENUM('card','processo','contato')
                                   NOT NULL,
  resource_id         INT          NOT NULL,
  from_account_id     INT          NOT NULL,

  -- Destinatário
  convidado_user_id   INT          DEFAULT NULL
                                   COMMENT 'Preenchido após aceite, se o email já tem cadastro no sistema',
  convidado_email     VARCHAR(191) NOT NULL,
  convidado_nome      VARCHAR(191) DEFAULT NULL,

  -- Token de aceite (nunca usar uniqid ou rand())
  token_convite       VARCHAR(64)  NOT NULL UNIQUE
                                   COMMENT 'bin2hex(random_bytes(32)) — 256 bits de entropia',

  permission_level    ENUM('view','edit')
                                   NOT NULL DEFAULT 'view',
  mensagem            TEXT         DEFAULT NULL
                                   COMMENT 'Mensagem personalizada ao convidado (opcional)',

  status              ENUM('pending','accepted','rejected','revoked','expired')
                                   DEFAULT 'pending',

  -- Rastreabilidade temporal
  expires_at          DATETIME     NOT NULL,
  created_at          DATETIME     DEFAULT CURRENT_TIMESTAMP,
  responded_at        DATETIME     DEFAULT NULL,
  revoked_at          DATETIME     DEFAULT NULL,
  revoked_by          INT          DEFAULT NULL
                                   COMMENT 'user_id que revogou',

  INDEX idx_conv_token    (token_convite),
  INDEX idx_conv_email    (convidado_email),
  INDEX idx_conv_resource (resource_type, resource_id),
  INDEX idx_conv_status   (status),
  INDEX idx_conv_expires  (expires_at),

  CONSTRAINT fk_conv_from FOREIGN KEY (from_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. NOTIFICAÇÕES INTERNAS DE CONTA
--    Modelo: Linear notification center / Slack notification inbox
--    Serve para: convite recebido, vínculo pendente, share criado
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS account_notifications (
  id           INT          AUTO_INCREMENT PRIMARY KEY,
  account_id   INT          NOT NULL,
  user_id      INT          DEFAULT NULL
               COMMENT 'NULL = notificação para toda a conta | preenchido = só para este usuário',
  tipo         VARCHAR(50)  NOT NULL
               COMMENT 'vinculo.pendente | convite.recebido | share.criado | convite.aceito | convite.rejeitado',
  titulo       VARCHAR(255) NOT NULL,
  mensagem     TEXT         DEFAULT NULL,
  lida         TINYINT(1)   DEFAULT 0,
  payload      JSON         DEFAULT NULL
               COMMENT 'Dados extras: { resource_type, resource_id, from_account_id, ... }',
  created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  lida_em      DATETIME     DEFAULT NULL,

  INDEX idx_notif_account (account_id),
  INDEX idx_notif_user    (user_id),
  INDEX idx_notif_lida    (lida),
  INDEX idx_notif_tipo    (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
