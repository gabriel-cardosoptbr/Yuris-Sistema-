-- 070: fila para dispatch assincrono
--
-- WebhookDispatcher::fire() passara a INSERIR aqui (rapido) em vez de chamar
-- HTTP no request principal. O worker bin/webhook_worker.php (cron 1 min)
-- consome esta fila, faz fan-out pros endpoints subscritos e grava em
-- webhook_deliveries.
--
-- Idempotencia: event_id eh propagado pra deliveries; destino pode deduplicar.

CREATE TABLE IF NOT EXISTS webhook_event_queue (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  account_id      INT NOT NULL,
  event_code      VARCHAR(100) NOT NULL,
  event_id        VARCHAR(64)  NOT NULL,
  payload         JSON NOT NULL,
  status          ENUM('pending','processing','done','dead')
                    NOT NULL DEFAULT 'pending',
  attempts        INT NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_weq_pickup (status, next_attempt_at),
  INDEX idx_weq_account (account_id, created_at),
  INDEX idx_weq_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
