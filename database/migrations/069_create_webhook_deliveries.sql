-- 069: state machine de entregas (substitui webhook_logs no longo prazo)
--
-- webhook_logs continua existindo por compat. Painel novo passa a ler
-- preferencialmente daqui. A diferenca chave: deliveries tem status
-- (pending/success/failed/retrying/canceled), tentativa, scheduled_retry_at
-- e event_id (correlation key pra deduplicacao no destino).

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
  webhook_endpoint_id  INT NOT NULL,
  account_id           INT NOT NULL,
  event_code           VARCHAR(100) NOT NULL,
  event_id             VARCHAR(64)  NOT NULL,
  payload              JSON NOT NULL,
  request_url          VARCHAR(500) NOT NULL,
  request_headers      JSON NULL,
  response_status      INT NULL,
  response_body        TEXT NULL,
  response_time_ms     INT NULL,
  status               ENUM('pending','success','failed','retrying','canceled')
                         NOT NULL DEFAULT 'pending',
  tentativa            INT NOT NULL DEFAULT 1,
  erro                 TEXT NULL,
  scheduled_retry_at   DATETIME NULL,
  delivered_at         DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_wd_endpoint_time (webhook_endpoint_id, created_at),
  INDEX idx_wd_status_retry (status, scheduled_retry_at),
  INDEX idx_wd_account_time (account_id, created_at),
  INDEX idx_wd_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
