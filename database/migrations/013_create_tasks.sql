-- Módulo Tarefas YURIS — tabelas + seed de quadros padrão
-- Executar uma vez via phpMyAdmin ou linha de comando MySQL

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS task_boards (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nome        VARCHAR(150)                        NOT NULL,
  descricao   TEXT                                NULL,
  tipo        ENUM('pessoal','compartilhado')      NOT NULL DEFAULT 'pessoal',
  owner_id    INT                                 NOT NULL,
  cor         VARCHAR(20)                         DEFAULT '#6366f1',
  ordem       INT                                 DEFAULT 0,
  ativo       TINYINT(1)                          DEFAULT 1,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_owner (owner_id),
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_board_members (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  board_id   INT                                      NOT NULL,
  user_id    INT                                      NOT NULL,
  papel      ENUM('owner','editor','viewer')           DEFAULT 'editor',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_board_user (board_id, user_id),
  FOREIGN KEY (board_id) REFERENCES task_boards(id)  ON DELETE CASCADE,
  FOREIGN KEY (user_id)  REFERENCES users(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_columns (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  board_id            INT            NOT NULL,
  nome                VARCHAR(100)   NOT NULL,
  ordem               INT            DEFAULT 0,
  cor                 VARCHAR(20)    DEFAULT '#94a3b8',
  is_coluna_inicial   TINYINT(1)     DEFAULT 0,
  is_coluna_concluido TINYINT(1)     DEFAULT 0,
  created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_board (board_id),
  FOREIGN KEY (board_id) REFERENCES task_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_recurrences (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tipo         ENUM('diaria','semanal','quinzenal','mensal','anual','custom') NOT NULL,
  intervalo    INT          DEFAULT 1,
  dias_semana  JSON         NULL,
  dia_mes      INT          NULL,
  data_inicio  DATE         NOT NULL,
  data_fim     DATE         NULL,
  ativa        TINYINT(1)   DEFAULT 1,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  board_id        INT                                                     NOT NULL,
  column_id       INT                                                     NOT NULL,
  titulo          VARCHAR(255)                                            NOT NULL,
  descricao       TEXT                                                    NULL,
  prioridade      ENUM('baixa','media','alta','urgente')                  DEFAULT 'media',
  prazo           DATETIME                                                NULL,
  prazo_tipo      ENUM('legal','interno','administrativo')                DEFAULT 'interno',
  responsavel_id  INT                                                     NULL,
  criado_por_id   INT                                                     NOT NULL,
  status          ENUM('ativa','concluida','arquivada')                   DEFAULT 'ativa',
  concluida_em    DATETIME                                                NULL,
  ordem           INT                                                     DEFAULT 0,
  recorrencia_id  INT                                                     NULL,
  origem_task_id  INT                                                     NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_board_column (board_id, column_id),
  INDEX idx_responsavel  (responsavel_id),
  INDEX idx_prazo        (prazo),
  INDEX idx_status       (status),
  FOREIGN KEY (board_id)       REFERENCES task_boards(id)      ON DELETE CASCADE,
  FOREIGN KEY (column_id)      REFERENCES task_columns(id)     ON DELETE RESTRICT,
  FOREIGN KEY (recorrencia_id) REFERENCES task_recurrences(id) ON DELETE SET NULL,
  FOREIGN KEY (responsavel_id) REFERENCES users(id)            ON DELETE SET NULL,
  FOREIGN KEY (criado_por_id)  REFERENCES users(id)            ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_links (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  task_id    INT                                                       NOT NULL,
  link_type  ENUM('contato','processo','card','dre_account')           NOT NULL,
  link_id    INT                                                       NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_task   (task_id),
  INDEX idx_lookup (link_type, link_id),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_checklist_items (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  task_id    INT                     NOT NULL,
  descricao  VARCHAR(500)            NOT NULL,
  concluido  TINYINT(1)              DEFAULT 0,
  ordem      INT                     DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task (task_id),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_comments (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  task_id    INT   NOT NULL,
  user_id    INT   NOT NULL,
  mensagem   TEXT  NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task (task_id),
  FOREIGN KEY (task_id) REFERENCES tasks(id)  ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_history (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  task_id     INT           NOT NULL,
  user_id     INT           NULL,
  acao        VARCHAR(80)   NOT NULL,
  antes_json  JSON          NULL,
  depois_json JSON          NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_task_data (task_id, created_at),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_attachments (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  task_id      INT            NOT NULL,
  file_path    VARCHAR(500)   NOT NULL,
  file_name    VARCHAR(255)   NOT NULL,
  mime_type    VARCHAR(100)   NULL,
  file_size    INT            NULL,
  uploaded_by  INT            NOT NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task (task_id),
  FOREIGN KEY (task_id)     REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_time_entries (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  task_id           INT               NOT NULL,
  user_id           INT               NOT NULL,
  inicio            DATETIME          NOT NULL,
  fim               DATETIME          NULL,
  duracao_minutos   INT               NULL,
  valor_hora        DECIMAL(10,2)     NULL,
  descricao         VARCHAR(500)      NULL,
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task (task_id),
  FOREIGN KEY (task_id)  REFERENCES tasks(id)  ON DELETE CASCADE,
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_reminders (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  task_id     INT                                    NOT NULL,
  user_id     INT                                    NOT NULL,
  lembrar_em  DATETIME                               NOT NULL,
  canal       ENUM('sistema','whatsapp','email')     DEFAULT 'sistema',
  enviado     TINYINT(1)                             DEFAULT 0,
  enviado_em  DATETIME                               NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pendentes (enviado, lembrar_em),
  FOREIGN KEY (task_id) REFERENCES tasks(id)  ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- SEED: quadros padrão para o primeiro usuário admin existente
-- ─────────────────────────────────────────────────────────────

SET @admin_id = (SELECT id FROM users WHERE perfil = 'admin' ORDER BY id LIMIT 1);

-- 1. Meu Dia (pessoal)
INSERT INTO task_boards (nome, tipo, owner_id, cor, ordem) VALUES ('Meu Dia', 'pessoal', @admin_id, '#6366f1', 1);
SET @b1 = LAST_INSERT_ID();
INSERT INTO task_columns (board_id, nome, ordem, cor, is_coluna_inicial, is_coluna_concluido) VALUES
  (@b1, 'A fazer',       1, '#94a3b8', 1, 0),
  (@b1, 'Em andamento',  2, '#3b82f6', 0, 0),
  (@b1, 'Concluído',     3, '#22c55e', 0, 1);

-- 2. Prazos do Escritório (compartilhado)
INSERT INTO task_boards (nome, tipo, owner_id, cor, ordem) VALUES ('Prazos do Escritório', 'compartilhado', @admin_id, '#ef4444', 2);
SET @b2 = LAST_INSERT_ID();
INSERT INTO task_columns (board_id, nome, ordem, cor, is_coluna_inicial, is_coluna_concluido) VALUES
  (@b2, 'Esta semana',       1, '#f59e0b', 1, 0),
  (@b2, 'Próximos 7 dias',   2, '#3b82f6', 0, 0),
  (@b2, 'Atrasados',         3, '#ef4444', 0, 0),
  (@b2, 'Cumpridos',         4, '#22c55e', 0, 1);

-- 3. Petições (compartilhado)
INSERT INTO task_boards (nome, tipo, owner_id, cor, ordem) VALUES ('Petições', 'compartilhado', @admin_id, '#8b5cf6', 3);
SET @b3 = LAST_INSERT_ID();
INSERT INTO task_columns (board_id, nome, ordem, cor, is_coluna_inicial, is_coluna_concluido) VALUES
  (@b3, 'A redigir',           1, '#94a3b8', 1, 0),
  (@b3, 'Em revisão',          2, '#f59e0b', 0, 0),
  (@b3, 'Aguardando cliente',  3, '#6366f1', 0, 0),
  (@b3, 'Protocolada',         4, '#22c55e', 0, 1);

-- 4. Atendimento (compartilhado)
INSERT INTO task_boards (nome, tipo, owner_id, cor, ordem) VALUES ('Atendimento', 'compartilhado', @admin_id, '#0ea5e9', 4);
SET @b4 = LAST_INSERT_ID();
INSERT INTO task_columns (board_id, nome, ordem, cor, is_coluna_inicial, is_coluna_concluido) VALUES
  (@b4, 'Novo contato',        1, '#94a3b8', 1, 0),
  (@b4, 'Em contato',          2, '#3b82f6', 0, 0),
  (@b4, 'Aguardando retorno',  3, '#f59e0b', 0, 0),
  (@b4, 'Convertido',          4, '#22c55e', 0, 1);
