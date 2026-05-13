-- Migration 028 — Tabelas de Times e Membros
-- Cria as tabelas `teams` e `team_members` para agrupamento de usuários.

CREATE TABLE IF NOT EXISTS teams (
  id          INT           AUTO_INCREMENT PRIMARY KEY,
  account_id  INT           NOT NULL,
  nome        VARCHAR(100)  NOT NULL,
  cor         VARCHAR(7)    NOT NULL DEFAULT '#3B82F6',
  descricao   VARCHAR(255)  DEFAULT NULL,
  created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at  TIMESTAMP     NULL DEFAULT NULL,
  INDEX idx_teams_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_members (
  id          INT       AUTO_INCREMENT PRIMARY KEY,
  team_id     INT       NOT NULL,
  user_id     INT       NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY  uk_team_user (team_id, user_id),
  INDEX       idx_tm_team (team_id),
  INDEX       idx_tm_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
