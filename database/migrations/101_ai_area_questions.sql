-- Migration 101 — Banco de PERGUNTAS POR AREA do pre-atendimento (DDL canonico).
-- Aplicacao idempotente via run_101.php (este .sql e a referencia para schema.sql).
--
-- O "segundo mundo" do motor: alem das perguntas genericas (nome, relato, quando, processo,
-- prazo, documentos, cidade), cada AREA classificada tem perguntas especificas de triagem.
-- Sao DETERMINISTICAS (PHP/DB), NAO entram no prompt mestre e NAO custam tokens. 100% editaveis
-- no Painel Master. area_code casa com ai_area_catalog.code (e com primary_practice_area).

CREATE TABLE IF NOT EXISTS ai_area_questions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  area_code     VARCHAR(50)  NOT NULL,                 -- ai_area_catalog.code
  question_key  VARCHAR(40)  NOT NULL,                 -- estavel, snake_case, unico por area
  question_text VARCHAR(500) NOT NULL,                 -- texto exibido ao lead (sem travessao)
  help_text     VARCHAR(255) NULL,                     -- nota interna (editor do Master)
  sort          INT          NOT NULL DEFAULT 0,       -- ordem de exibicao na area
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_area_qkey (area_code, question_key),
  KEY idx_aq_area (area_code, active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
