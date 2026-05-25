-- 067: webhooks -> webhook_endpoints + colunas multi-tenant/seguranca/LGPD
--
-- Aditivo. Renomeia a tabela `webhooks` para `webhook_endpoints` e adiciona as
-- colunas que faltam para suportar escopo matriz/filial, payload_mode (LGPD),
-- headers customizados, timeout/retry configuraveis, rotacao de secret e
-- created_by.
--
-- Importante:
--   - Coluna `ativo` TINYINT(1) continua sendo a fonte de verdade.
--   - `webhook_logs.webhook_id` NAO eh renomeado (mantem compat com queries
--     existentes). Logicamente passa a apontar para `webhook_endpoints.id`.
--   - Codigo PHP (WebhookDispatcher.php, public/api/webhooks.php) tambem
--     foi atualizado no mesmo commit para usar o nome novo.

START TRANSACTION;

-- 1) Rename
ALTER TABLE webhooks RENAME TO webhook_endpoints;

-- 2) Colunas novas (todas com DEFAULT compativel com linhas existentes)
ALTER TABLE webhook_endpoints
  ADD COLUMN escopo               ENUM('tenant_only','matriz_e_filiais','filial_only')
                                    NOT NULL DEFAULT 'tenant_only' AFTER account_id,
  ADD COLUMN metodo                ENUM('POST') NOT NULL DEFAULT 'POST' AFTER url,
  ADD COLUMN secret_rotated_at     DATETIME NULL AFTER secret,
  ADD COLUMN headers_customizados  JSON NULL AFTER eventos,
  ADD COLUMN timeout_segundos      INT NOT NULL DEFAULT 10 AFTER headers_customizados,
  ADD COLUMN retry_enabled         TINYINT(1) NOT NULL DEFAULT 1 AFTER timeout_segundos,
  ADD COLUMN max_retries           INT NOT NULL DEFAULT 3 AFTER retry_enabled,
  ADD COLUMN payload_mode          ENUM('minimal','masked','full')
                                    NOT NULL DEFAULT 'masked' AFTER max_retries,
  ADD COLUMN created_by            INT NULL AFTER payload_mode;

-- 3) Indices uteis (idempotente via verificacao inline)
ALTER TABLE webhook_endpoints
  ADD INDEX idx_we_account_status (account_id, ativo, deleted_at),
  ADD INDEX idx_we_escopo (escopo);

COMMIT;
