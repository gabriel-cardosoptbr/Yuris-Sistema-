-- =============================================================================
-- MIGRATION 044 — Tipo 'advogado' em accounts (terceiro tipo de tenant)
-- Data: 2026-05-23
--
-- Adiciona 'advogado' ao ENUM accounts.tipo. Antes só havia:
--   ENUM('matriz','filial')
-- Agora:
--   ENUM('matriz','filial','advogado')
--
-- Conceito: um "Advogado" é um tenant standalone — não pertence a matriz,
-- não tem filiais, geralmente é um escritório solo de 1 advogado.
-- O account fica com matriz_id = NULL (igual matriz), mas tipo='advogado'.
-- O user owner desse account tem is_advogado=1 + oab/oab_uf.
--
-- IDEMPOTENTE: MODIFY ENUM com os 3 valores. Rodar várias vezes é no-op
-- depois que já está no estado final (MySQL ignora o ALTER se idêntico).
-- =============================================================================

ALTER TABLE accounts
  MODIFY COLUMN tipo ENUM('matriz','filial','advogado')
  NOT NULL DEFAULT 'matriz';

SELECT 'Migration 044 (accounts.tipo += advogado) executada em' AS msg,
       NOW() AS executed_at;
