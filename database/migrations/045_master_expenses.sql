-- =============================================================================
-- MIGRATION 045 — Despesas operacionais do SaaS (Painel Master)
-- Data: 2026-05-23
--
-- Cria a tabela master_expenses pra que o dono do SaaS controle os custos
-- do negócio: servidor, salários, APIs, marketing, infraestrutura, impostos.
--
-- Permite ver:
--   • Receita Recorrente Mensal (MRR) — vem de subscriptions/plans
--   • Despesas Mensais — vem desta tabela
--   • Lucro Líquido = MRR - Despesas
--
-- IDEMPOTENTE: CREATE TABLE IF NOT EXISTS.
-- =============================================================================

CREATE TABLE IF NOT EXISTS master_expenses (
  id                  INT AUTO_INCREMENT PRIMARY KEY,

  -- Classificação
  categoria           ENUM('servidor','pessoas','apis','marketing','infraestrutura',
                           'impostos','software','juridico','outros')
                      NOT NULL DEFAULT 'outros',
  descricao           VARCHAR(255) NOT NULL,
  fornecedor          VARCHAR(150) NULL,

  -- Valor
  valor_cents         INT NOT NULL DEFAULT 0
                      COMMENT 'Valor em centavos pra evitar floating point',
  moeda               VARCHAR(3) NOT NULL DEFAULT 'BRL',

  -- Datas
  data_competencia    DATE NOT NULL
                      COMMENT 'Mês/ano em que a despesa pertence (pra agregação no DRE)',
  vencimento          DATE NULL
                      COMMENT 'Data limite pra pagar',
  data_pagamento      DATE NULL
                      COMMENT 'Quando foi efetivamente pago',

  -- Status e recorrência
  status              ENUM('pendente','pago','atrasado','cancelado')
                      NOT NULL DEFAULT 'pendente',
  recorrencia         ENUM('nenhuma','mensal','anual')
                      NOT NULL DEFAULT 'nenhuma'
                      COMMENT 'Se mensal/anual, futuras instâncias replicam',
  metodo_pagamento    VARCHAR(50) NULL
                      COMMENT 'cartao | pix | boleto | debito | transferencia | outro',
  observacoes         TEXT NULL,

  -- Auditoria
  created_by_super_admin_id  INT NULL,
  created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          DATETIME NULL,

  -- Índices pra agregação rápida
  INDEX idx_competencia (data_competencia),
  INDEX idx_status_venc (status, vencimento),
  INDEX idx_categoria   (categoria),
  INDEX idx_deleted     (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Seed inicial: 1 despesa-exemplo pra demonstrar (não duplica se já houver linhas)
-- ENCODING SAFE: usa CHAR(0xC3, 0xA7/0xA3 USING utf8mb4) pra "produção" não
-- ser corrompida via mysql.exe < arquivo.sql (Windows cmd.exe).
INSERT INTO master_expenses
  (categoria, descricao, fornecedor, valor_cents, data_competencia, vencimento,
   status, recorrencia, observacoes)
SELECT * FROM (
  SELECT
    'servidor'    AS categoria,
    CONCAT('Hospedagem VPS produ', CHAR(0xC3, 0xA7 USING utf8mb4),
           CHAR(0xC3, 0xA3 USING utf8mb4), 'o') AS descricao,
    'DigitalOcean' AS fornecedor,
    14000          AS valor_cents,
    DATE_FORMAT(NOW(), '%Y-%m-01') AS data_competencia,
    DATE_FORMAT(LAST_DAY(NOW()), '%Y-%m-%d') AS vencimento,
    'pendente'     AS status,
    'mensal'       AS recorrencia,
    'Droplet 4GB + backups' AS observacoes
) AS t
WHERE NOT EXISTS (SELECT 1 FROM master_expenses LIMIT 1);


SELECT 'Migration 045 (master_expenses) executada em' AS msg,
       NOW() AS executed_at;
