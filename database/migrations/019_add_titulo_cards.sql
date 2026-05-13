-- 019_add_titulo_cards.sql
-- O que faz: Adiciona a coluna titulo na tabela cards
-- Por que: O modelo TaskLink.php (resolveNome) busca o campo `titulo` da tabela
--          cards para exibir o nome do card em vínculos de tarefas. O schema atual
--          não possui coluna `titulo` — o campo equivalente é `cliente_nome`.
--          Sem esta coluna, a resolução de nome de cards vinculados a tarefas
--          retorna NULL e exibe apenas "#ID" no frontend.

SET NAMES utf8mb4;

ALTER TABLE cards
  ADD COLUMN IF NOT EXISTS titulo VARCHAR(255) DEFAULT NULL
    COMMENT 'Título do card para exibição em vínculos de tarefas (espelha cliente_nome)';

-- Popula titulo com cliente_nome para cards existentes
UPDATE cards
SET titulo = cliente_nome
WHERE titulo IS NULL AND cliente_nome IS NOT NULL AND deleted_at IS NULL;
