-- 018_add_numero_cnj_processos.sql
-- O que faz: Adiciona a coluna numero_cnj na tabela processos
-- Por que: O modelo TaskLink.php (resolveNome) busca o campo numero_cnj da tabela
--          processos para exibir o identificador do processo em vínculos de tarefas.
--          O schema atual tem apenas a coluna `numero` (genérica), mas o código
--          referencia explicitamente `numero_cnj`, causando SQL error na resolução
--          do nome de processos vinculados a tarefas.

SET NAMES utf8mb4;

ALTER TABLE processos
  ADD COLUMN IF NOT EXISTS numero_cnj VARCHAR(30) DEFAULT NULL
    COMMENT 'Número CNJ do processo (formato NNNNNNN-DD.AAAA.J.TT.OOOO)';

-- Popula numero_cnj com o valor de numero para processos já existentes
UPDATE processos
SET numero_cnj = numero
WHERE numero_cnj IS NULL AND numero IS NOT NULL;
