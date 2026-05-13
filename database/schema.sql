-- schema.sql — Estado canônico do banco sistema_vendas
-- Gerado após aplicação de todas as migrations (001–027)
-- Última atualização: 2026-05-11
-- NÃO edite manualmente — use migrations numeradas para alterações.

CREATE DATABASE IF NOT EXISTS sistema_vendas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistema_vendas;

-- ─────────────────────────────────────────────────────────────────────────────
-- AUTENTICAÇÃO / USUÁRIOS
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS users (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  account_id   INT          DEFAULT NULL,
  nome         VARCHAR(191) NOT NULL,
  login        VARCHAR(100) NOT NULL UNIQUE,
  senha_hash   VARCHAR(255) NOT NULL,
  senha_texto  VARCHAR(255) DEFAULT NULL,         -- armazenado para exibição interna
  perfil       ENUM('admin','user') NOT NULL DEFAULT 'user',
  role         VARCHAR(50)  NOT NULL DEFAULT 'user', -- owner|admin|manager|user|viewer
  status       VARCHAR(20)  NOT NULL DEFAULT 'active',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at   DATETIME DEFAULT NULL,
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_permissions (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  page    VARCHAR(100) NOT NULL,
  UNIQUE KEY uk_user_page (user_id, page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- MULTI-TENANCY
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS accounts (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(191) NOT NULL,
  tipo           ENUM('matriz','filial') NOT NULL DEFAULT 'matriz',
  matriz_id      INT DEFAULT NULL,
  codigo_vinculo VARCHAR(64)  DEFAULT NULL UNIQUE,
  status         ENUM('active','suspended','cancelled') NOT NULL DEFAULT 'active',
  plano          VARCHAR(50)  NOT NULL DEFAULT 'basico',    -- basico | profissional | enterprise
  configuracoes  JSON         DEFAULT NULL,                  -- configurações personalizadas
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at     DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_vinculos (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  matriz_account_id   INT NOT NULL,
  filial_account_id   INT NOT NULL,
  status              ENUM('pending','active','suspended','rejected') NOT NULL DEFAULT 'pending',
  solicitado_por      INT      DEFAULT NULL,   -- user_id que iniciou a solicitação
  aprovado_por        INT      DEFAULT NULL,   -- user_id owner/admin da Matriz que aprovou
  solicitado_em       DATETIME DEFAULT CURRENT_TIMESTAMP,
  aprovado_em         DATETIME DEFAULT NULL,
  suspenso_em         DATETIME DEFAULT NULL,
  motivo_suspensao    TEXT     DEFAULT NULL,
  created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_vinculo (matriz_account_id, filial_account_id),
  KEY idx_filial (filial_account_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS resource_shares (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  resource_type    VARCHAR(50) NOT NULL,
  resource_id      INT NOT NULL,
  from_account_id  INT NOT NULL,
  to_account_id    INT DEFAULT NULL,
  to_user_id       INT DEFAULT NULL   COMMENT 'NULL = toda a conta | preenchido = usuário específico',
  permission_level ENUM('view','edit','full') NOT NULL DEFAULT 'view',
  criado_por       INT DEFAULT NULL   COMMENT 'user_id que criou o share',
  status           ENUM('active','revoked') NOT NULL DEFAULT 'active',
  revoked_at       DATETIME DEFAULT NULL COMMENT 'Timestamp da revogação',
  revoked_by       INT DEFAULT NULL   COMMENT 'user_id que revogou',
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_resource  (resource_type, resource_id),
  KEY idx_to_account (to_account_id),
  KEY idx_to_user   (to_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS advogado_convites (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  resource_type       VARCHAR(50)  NOT NULL,
  resource_id         INT          NOT NULL,
  from_account_id     INT          NOT NULL,
  convidado_user_id   INT          DEFAULT NULL COMMENT 'Preenchido após aceite se o email já tem usuário no sistema',
  convidado_email     VARCHAR(191) NOT NULL,
  convidado_nome      VARCHAR(191) DEFAULT NULL,
  token_convite       VARCHAR(64)  NOT NULL UNIQUE,
  permission_level    ENUM('view','edit','full') NOT NULL DEFAULT 'view',
  mensagem            TEXT         DEFAULT NULL,
  status              ENUM('pending','accepted','rejected','revoked','expired') NOT NULL DEFAULT 'pending',
  expires_at          DATETIME     DEFAULT NULL,
  responded_at        DATETIME     DEFAULT NULL COMMENT 'Timestamp do aceite ou rejeição',
  revoked_at          DATETIME     DEFAULT NULL COMMENT 'Timestamp da revogação',
  revoked_by          INT          DEFAULT NULL COMMENT 'user_id que revogou o convite',
  created_at          DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_token           (token_convite),
  KEY idx_convidado_email (convidado_email),
  KEY idx_convidado_user  (convidado_user_id),
  KEY idx_status          (status),
  KEY idx_expires         (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_notifications (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  user_id    INT DEFAULT NULL,
  tipo       VARCHAR(100) NOT NULL,
  titulo     VARCHAR(255) NOT NULL,
  mensagem   TEXT DEFAULT NULL,
  payload    JSON DEFAULT NULL,
  lida       TINYINT(1) DEFAULT 0,
  lida_em    DATETIME DEFAULT NULL COMMENT 'Timestamp de quando foi lida',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_account (account_id),
  KEY idx_user    (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id   INT NOT NULL,
  user_id      INT DEFAULT NULL,
  acao         VARCHAR(100) NOT NULL,
  entidade     VARCHAR(100) DEFAULT NULL,
  entidade_id  INT DEFAULT NULL,
  dados_antes  JSON DEFAULT NULL,
  dados_depois JSON DEFAULT NULL,
  ip           VARCHAR(45) DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_account (account_id),
  KEY idx_user    (user_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Imutável — apenas INSERT';

-- ─────────────────────────────────────────────────────────────────────────────
-- CRM / PIPELINE
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS pipeline_columns (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  account_id          INT DEFAULT NULL,
  nome                VARCHAR(191) NOT NULL,
  slug                VARCHAR(191) NOT NULL,
  cor                 VARCHAR(20) DEFAULT '#EEEEEE',
  ordem               INT DEFAULT 0,
  conta_funil         TINYINT(1) DEFAULT 1,
  conta_oportunidade  TINYINT(1) DEFAULT 1,
  conta_fechado       TINYINT(1) DEFAULT 0,
  conta_perdido       TINYINT(1) DEFAULT 0,
  peso_projecao       INT DEFAULT 0,
  created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cards (
  id                        INT AUTO_INCREMENT PRIMARY KEY,
  account_id                INT DEFAULT NULL,
  cliente_nome              VARCHAR(191) NOT NULL,
  empresa_nome              VARCHAR(191) DEFAULT NULL,
  telefone_whatsapp         VARCHAR(50)  DEFAULT NULL,
  email                     VARCHAR(191) DEFAULT NULL,
  responsavel_user_id       INT DEFAULT NULL,
  coluna_id                 INT DEFAULT NULL,
  ordem_na_coluna           INT DEFAULT 0,
  valor_estimado            DECIMAL(15,2) DEFAULT 0,
  valor_proposta            DECIMAL(15,2) DEFAULT 0,
  valor_fechado_final       DECIMAL(15,2) DEFAULT 0,
  data_prevista_fechamento  DATE DEFAULT NULL,
  data_fechamento           DATE DEFAULT NULL,
  descricao                 TEXT DEFAULT NULL,
  status                    VARCHAR(50) DEFAULT 'aberto',
  contato_id                INT DEFAULT NULL,
  checklist_percentual      DECIMAL(5,2) DEFAULT 0,
  titulo                    VARCHAR(255) DEFAULT NULL,
  deleted_by                INT DEFAULT NULL,
  created_at                DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at                DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at                DATETIME DEFAULT NULL,
  KEY idx_account    (account_id),
  KEY idx_contato    (contato_id),
  KEY idx_coluna     (coluna_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS card_history (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  card_id        INT NOT NULL,
  usuario_id     INT DEFAULT NULL,
  acao           VARCHAR(100) DEFAULT NULL,
  campo_alterado VARCHAR(100) DEFAULT NULL,
  valor_anterior TEXT DEFAULT NULL,
  valor_novo     TEXT DEFAULT NULL,
  de_coluna_id   INT DEFAULT NULL,
  para_coluna_id INT DEFAULT NULL,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_card (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS card_checklist_items (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  card_id    INT NOT NULL,
  titulo     VARCHAR(255) NOT NULL,
  concluido  TINYINT(1) DEFAULT 0,
  ordem      INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_card (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- JURÍDICO / PROCESSOS
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS processos (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  account_id               INT DEFAULT NULL,
  numero                   VARCHAR(255) DEFAULT NULL,
  numero_cnj               VARCHAR(30)  DEFAULT NULL,
  cliente_nome             VARCHAR(255) DEFAULT NULL,
  parte_contraria          VARCHAR(255) DEFAULT NULL,
  cpf_cnpj_parte_contraria VARCHAR(20)  DEFAULT NULL,
  tipo_acao                VARCHAR(255) DEFAULT NULL,
  vara_comarca             VARCHAR(255) DEFAULT NULL,
  responsavel_user_id      INT DEFAULT NULL,
  status                   VARCHAR(50) DEFAULT 'ativo',
  data_inicio              DATE DEFAULT NULL,
  proximo_prazo            DATE DEFAULT NULL,
  ultima_movimentacao      DATETIME DEFAULT NULL,
  observacoes              TEXT DEFAULT NULL,
  anexos                   JSON DEFAULT NULL,
  alerts                   JSON DEFAULT NULL,
  card_id                  INT DEFAULT NULL,
  contato_id               INT DEFAULT NULL,
  created_at               DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at               DATETIME DEFAULT NULL,
  KEY idx_account  (account_id),
  KEY idx_contato  (contato_id),
  KEY idx_prazo    (proximo_prazo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- CONTATOS
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS contatos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  account_id  INT DEFAULT NULL,
  nome        VARCHAR(191) NOT NULL,
  telefone    VARCHAR(30)  DEFAULT NULL UNIQUE,
  remote_jid  VARCHAR(120) DEFAULT NULL UNIQUE,
  email       VARCHAR(191) DEFAULT NULL,
  observacoes TEXT DEFAULT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contato_vinculos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  contato_id     INT NOT NULL,
  resource_type  VARCHAR(50) NOT NULL,
  resource_id    INT NOT NULL,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_contato  (contato_id),
  KEY idx_resource (resource_type, resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- METAS
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS goals (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  account_id     INT DEFAULT NULL,
  user_id        INT DEFAULT NULL,
  referencia_mes VARCHAR(7) DEFAULT NULL,
  valor_meta     DECIMAL(15,2) DEFAULT 0,
  tipo_meta      VARCHAR(50) DEFAULT 'global',
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- DRE / FINANCEIRO
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS dre_accounts (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  codigo         VARCHAR(64) DEFAULT NULL,
  nome           VARCHAR(191) NOT NULL,
  tipo           ENUM('receita','despesa') NOT NULL DEFAULT 'despesa',
  valor_fixo     DECIMAL(14,2) NOT NULL DEFAULT 0,
  recorrencia    VARCHAR(30) DEFAULT 'fixa',
  data_referencia DATE DEFAULT NULL,
  descricao      TEXT DEFAULT NULL,
  ativo          TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dre_codes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  codigo     VARCHAR(64) NOT NULL UNIQUE,
  descricao  VARCHAR(255) NOT NULL,
  tipo       ENUM('receita','despesa') NOT NULL DEFAULT 'despesa',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- TAREFAS (TASK BOARDS)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS task_boards (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  account_id  INT DEFAULT NULL,
  nome        VARCHAR(150) NOT NULL,
  descricao   TEXT DEFAULT NULL,
  tipo        ENUM('pessoal','compartilhado') NOT NULL DEFAULT 'pessoal',
  owner_id    INT NOT NULL,
  cor         VARCHAR(20) DEFAULT '#6366f1',
  ordem       INT DEFAULT 0,
  ativo       TINYINT(1) DEFAULT 1,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_account (account_id),
  KEY idx_owner   (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_board_members (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  board_id   INT NOT NULL,
  user_id    INT NOT NULL,
  role       VARCHAR(30) DEFAULT 'member',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_board_user (board_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_columns (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  board_id           INT NOT NULL,
  nome               VARCHAR(100) NOT NULL,
  ordem              INT DEFAULT 0,
  cor                VARCHAR(20) DEFAULT '#94a3b8',
  is_coluna_inicial  TINYINT(1) DEFAULT 0,
  is_coluna_concluido TINYINT(1) DEFAULT 0,
  created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_board (board_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_recurrences (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tipo         VARCHAR(30) NOT NULL,
  intervalo    INT DEFAULT 1,
  data_inicio  DATE DEFAULT NULL,
  data_fim     DATE DEFAULT NULL,
  ativo        TINYINT(1) DEFAULT 1,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tasks (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  board_id        INT NOT NULL,
  column_id       INT NOT NULL,
  titulo          VARCHAR(255) NOT NULL,
  descricao       TEXT DEFAULT NULL,
  prioridade      ENUM('baixa','media','alta','urgente') DEFAULT 'media',
  prazo           DATETIME DEFAULT NULL,
  prazo_tipo      VARCHAR(50) NOT NULL DEFAULT 'interno',  -- VARCHAR pois suporta tipos customizados
  responsavel_id  INT DEFAULT NULL,
  criado_por_id   INT NOT NULL,
  status          ENUM('ativa','concluida','arquivada') DEFAULT 'ativa',
  concluida_em    DATETIME DEFAULT NULL,
  ordem           INT DEFAULT 0,
  recorrencia_id  INT DEFAULT NULL,
  origem_task_id  INT DEFAULT NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_board_column (board_id, column_id),
  KEY idx_responsavel  (responsavel_id),
  KEY idx_prazo        (prazo),
  KEY idx_status       (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_links (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  task_id        INT NOT NULL,
  resource_type  VARCHAR(50) NOT NULL,
  resource_id    INT NOT NULL,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task     (task_id),
  KEY idx_resource (resource_type, resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_checklist_items (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  task_id    INT NOT NULL,
  descricao  VARCHAR(500) NOT NULL,
  concluido  TINYINT(1) DEFAULT 0,
  prazo      DATE DEFAULT NULL,
  ordem      INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_comments (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  task_id    INT NOT NULL,
  user_id    INT NOT NULL,
  conteudo   TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_history (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  task_id    INT NOT NULL,
  user_id    INT DEFAULT NULL,
  acao       VARCHAR(100) NOT NULL,
  detalhe    TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_time_entries (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  task_id     INT NOT NULL,
  user_id     INT NOT NULL,
  inicio      DATETIME NOT NULL,
  fim         DATETIME DEFAULT NULL,
  duracao_min INT DEFAULT NULL,
  nota        TEXT DEFAULT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_attachments (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  task_id     INT NOT NULL,
  user_id     INT DEFAULT NULL,
  nome        VARCHAR(255) NOT NULL,
  caminho     VARCHAR(500) NOT NULL,
  tamanho     INT DEFAULT NULL,
  mime        VARCHAR(100) DEFAULT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_reminders (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  task_id      INT NOT NULL,
  user_id      INT NOT NULL,
  lembrar_em   DATETIME NOT NULL,
  enviado      TINYINT(1) DEFAULT 0,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- WHATSAPP
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS whatsapp_instances (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  account_id       INT DEFAULT NULL,
  instance_name    VARCHAR(100) NOT NULL UNIQUE,
  display_name     VARCHAR(150) DEFAULT NULL,
  status           ENUM('open','close','connecting','qr') DEFAULT 'close',
  phone            VARCHAR(30) DEFAULT NULL,
  profile_name     VARCHAR(150) DEFAULT NULL,
  profile_pic_url  TEXT DEFAULT NULL,
  qr_code_base64   MEDIUMTEXT DEFAULT NULL,
  evolution_token  VARCHAR(255) DEFAULT NULL,
  webhook_url      TEXT DEFAULT NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_chats (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  instance_name   VARCHAR(100) NOT NULL,
  remote_jid      VARCHAR(120) NOT NULL,
  push_name       VARCHAR(191) DEFAULT NULL,
  unread_count    INT DEFAULT 0,
  last_message    TEXT DEFAULT NULL,
  last_message_at DATETIME DEFAULT NULL,
  linked_card_id  INT DEFAULT NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_instance_jid (instance_name, remote_jid),
  KEY idx_card (linked_card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_messages (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  instance_name VARCHAR(100) NOT NULL,
  remote_jid    VARCHAR(120) NOT NULL,
  message_id    VARCHAR(100) DEFAULT NULL UNIQUE,
  from_me       TINYINT(1) DEFAULT 0,
  tipo          VARCHAR(30) DEFAULT 'text',
  conteudo      TEXT DEFAULT NULL,
  media_url     TEXT DEFAULT NULL,
  status        VARCHAR(20) DEFAULT 'received',
  timestamp     INT DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_jid (instance_name, remote_jid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- CHAT INTERNO
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS chat_conversas (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tipo       VARCHAR(30) DEFAULT 'direto',
  nome       VARCHAR(191) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_participantes (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  conversa_id  INT NOT NULL,
  user_id      INT NOT NULL,
  joined_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_conv_user (conversa_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_mensagens (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  conversa_id INT NOT NULL,
  user_id     INT NOT NULL,
  conteudo    TEXT NOT NULL,
  lida        TINYINT(1) DEFAULT 0,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_conversa (conversa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_mencoes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  mensagem_id INT NOT NULL,
  user_id     INT NOT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- WEBHOOKS
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS webhooks (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nome       VARCHAR(191) NOT NULL,
  url        VARCHAR(500) NOT NULL,
  secret     VARCHAR(255) DEFAULT NULL,
  eventos    JSON DEFAULT NULL,
  ativo      TINYINT(1) DEFAULT 1,
  deleted_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_logs (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  webhook_id      INT DEFAULT NULL,
  event_key       VARCHAR(100) NOT NULL,
  payload         JSON DEFAULT NULL,
  response_status INT DEFAULT NULL,
  response_body   TEXT DEFAULT NULL,
  duration_ms     INT DEFAULT NULL,
  success         TINYINT(1) DEFAULT 0,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_webhook (webhook_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- SEEDS INICIAIS
-- ─────────────────────────────────────────────────────────────────────────────

-- Conta padrão (single-tenant)
INSERT IGNORE INTO accounts (id, nome, tipo, status) VALUES (1, 'Escritório Principal', 'matriz', 'active');

-- Admin padrão (senha: senha123)
INSERT IGNORE INTO users (id, account_id, nome, login, senha_hash, senha_texto, perfil, role, status)
VALUES (1, 1, 'Administrador', 'admin', '$2y$10$zP7PuqkH6g2aA.t3sQ3IYeiqCqK8tW86v0G6oJfJ8YpK2cJt3sA9a', 'senha123', 'admin', 'owner', 'active');

-- Colunas do pipeline
INSERT IGNORE INTO pipeline_columns (account_id, nome, slug, cor, ordem, conta_funil, conta_oportunidade, conta_fechado, conta_perdido, peso_projecao) VALUES
(1, 'Prospecção', 'prospeccao', '#0ea5a4', 1, 1, 1, 0, 0, 10),
(1, 'Proposta',   'proposta',   '#fb923c', 2, 1, 1, 0, 0, 60),
(1, 'Negociação', 'negociacao', '#f97316', 3, 1, 1, 0, 0, 80),
(1, 'Fechado',    'fechado',    '#10b981', 4, 1, 0, 1, 0, 100),
(1, 'Perdido',    'perdido',    '#ef4444', 5, 0, 0, 0, 1, 0);
