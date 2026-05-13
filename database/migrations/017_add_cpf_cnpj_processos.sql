-- 017_add_cpf_cnpj_processos.sql
-- O que faz: Adiciona a coluna cpf_cnpj_parte_contraria na tabela processos
-- Por que: O modelo Processo.php (create e update) referencia esta coluna no INSERT e no
--          array $allowed de UPDATE, mas a migration 004_create_processes.sql não a inclui.
--          Sem esta coluna, qualquer criação/atualização de processo com CPF/CNPJ da
--          parte contrária gera erro de SQL.

SET NAMES utf8mb4;

ALTER TABLE processos
  ADD COLUMN IF NOT EXISTS cpf_cnpj_parte_contraria VARCHAR(20) DEFAULT NULL
    COMMENT 'CPF ou CNPJ da parte contrária';
