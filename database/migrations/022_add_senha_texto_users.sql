-- 022_add_senha_texto_users.sql
-- O que faz: Adiciona a coluna senha_texto na tabela users
-- Por que: O endpoint users.php verifica a existência de senha_texto com
--          SELECT e adiciona via ALTER TABLE em tempo de execução (workaround).
--          A coluna é usada para armazenar a senha em texto plano (para exibição
--          na UI de administração de usuários — funcionalidade de conveniência).
--          Esta migration torna a estrutura declarativa e elimina o workaround
--          em runtime que pode causar race conditions.
--          ATENÇÃO DE SEGURANÇA: armazenar senha em texto plano é uma prática
--          insegura. Mantenha esta coluna somente se for requisito explícito de
--          negócio e garanta que o banco não seja exposto.

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS senha_texto VARCHAR(255) DEFAULT NULL
    COMMENT 'Senha em texto plano — armazenada apenas para exibição na UI admin (requisito de negócio)';
