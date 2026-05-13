-- 020_add_dre_accounts_extra_cols.sql
-- O que faz: Adiciona as colunas recorrencia, data_referencia e descricao na tabela dre_accounts
-- Por que:
--   1. O modelo DREAccount.php (create e update) faz INSERT/UPDATE com os campos
--      `recorrencia` e `data_referencia`, que não existem no schema criado pela
--      migration 002_create_dre_accounts.sql — causando SQL error em toda operação
--      de escrita no módulo financeiro.
--   2. O modelo TaskLink.php (resolveNome) tenta fazer SELECT da coluna `descricao`
--      em dre_accounts para resolver o nome de um vínculo do tipo 'dre_account' em
--      tarefas. Sem essa coluna, o SQL falha e o vínculo exibe apenas "#ID".

SET NAMES utf8mb4;

ALTER TABLE dre_accounts
  ADD COLUMN IF NOT EXISTS recorrencia VARCHAR(30) DEFAULT 'fixa'
    COMMENT 'Tipo de recorrência do lançamento: fixa | variavel | unica',

  ADD COLUMN IF NOT EXISTS data_referencia DATE DEFAULT NULL
    COMMENT 'Data de referência do lançamento (mês/ano para fixos mensais)',

  ADD COLUMN IF NOT EXISTS descricao TEXT DEFAULT NULL
    COMMENT 'Descrição longa do lançamento (usada por TaskLink para resolver nome)';
