-- 068: catalogo de eventos persistido em DB
--
-- Hoje o catalogo vive hardcoded em WebhookDispatcher::catalog(). Esta tabela
-- permite enriquecer descricao, payload de exemplo e habilitar/desabilitar
-- eventos sem deploy. O codigo PHP continua sendo a fonte da definicao dos
-- nomes de evento; a tabela eh um espelho persistido pra UI/documentacao.

CREATE TABLE IF NOT EXISTS webhook_events (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  codigo          VARCHAR(100) NOT NULL UNIQUE,
  nome            VARCHAR(191) NOT NULL,
  descricao       TEXT NULL,
  categoria       VARCHAR(60)  NOT NULL,
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  payload_exemplo JSON NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_we_cat (categoria, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
