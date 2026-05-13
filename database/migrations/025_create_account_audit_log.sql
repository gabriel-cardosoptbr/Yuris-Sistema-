-- 025_create_account_audit_log.sql
-- O que faz: Cria a tabela account_audit_log que estava prevista na migration 015
--            mas não foi criada (a tabela não apareceu no SHOW TABLES após reset).
-- Impacto sem isso: AccountContext e Account::audit() falham silenciosamente.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `account_audit_log` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `account_id`   INT              NOT NULL,
  `user_id`      INT              DEFAULT NULL,
  `acao`         VARCHAR(100)     NOT NULL,
  `entidade`     VARCHAR(100)     DEFAULT NULL,
  `entidade_id`  INT              DEFAULT NULL,
  `dados_antes`  JSON             DEFAULT NULL,
  `dados_depois` JSON             DEFAULT NULL,
  `ip`           VARCHAR(45)      DEFAULT NULL,
  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account`   (`account_id`),
  KEY `idx_user`      (`user_id`),
  KEY `idx_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log de auditoria imutável — apenas INSERT, nunca UPDATE/DELETE';
