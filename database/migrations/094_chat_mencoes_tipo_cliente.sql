-- Migration 094: adiciona 'cliente' ao ENUM chat_mencoes.tipo
--
-- Auditoria 2026-06-01 (ALTA #1): a busca de menções (@) no chat interno passou
-- a incluir a tabela `clientes` como 4ª fonte (além de usuario/processo/card).
-- Sem 'cliente' no ENUM, o INSERT da menção falha (valor truncado no MySQL
-- strict / vira '' no não-strict). Idempotente via run_094.php.

ALTER TABLE chat_mencoes
  MODIFY COLUMN tipo ENUM('usuario','processo','card','cliente') NOT NULL;
