-- Migration 031 — Tabela de Alíquotas Fiscais
-- Formaliza a tabela `taxes` que antes era criada inline pelo endpoint public/api/taxes.php.
-- Segura para rodar com a tabela já existente (CREATE TABLE IF NOT EXISTS).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS taxes (
  id          INT            AUTO_INCREMENT PRIMARY KEY,
  nome        VARCHAR(100)   NOT NULL DEFAULT '',
  percentual  DECIMAL(7,4)   NOT NULL DEFAULT 0.0000,
  ativo       TINYINT(1)     NOT NULL DEFAULT 1,
  created_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
