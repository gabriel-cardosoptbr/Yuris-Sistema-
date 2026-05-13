-- 021_add_deleted_at_webhooks.sql
-- O que faz: Adiciona a coluna deleted_at na tabela webhooks (soft-delete)
-- Por que: O WebhookDispatcher::fire() filtra os webhooks com
--          "WHERE ativo = 1 AND deleted_at IS NULL", mas a migration de schema
--          original (schema.sql) não inclui a coluna deleted_at em webhooks.
--          Além disso, webhooks.php executa soft-delete fazendo
--          "UPDATE webhooks SET deleted_at = NOW()" e a listagem filtra com
--          "WHERE w.deleted_at IS NULL". Sem esta coluna, o sistema dispara
--          webhooks excluídos e falha ao tentar o soft-delete.
--          Nota: webhooks.php já tenta adicionar a coluna via try/catch como
--          workaround — esta migration torna a estrutura declarativa e idempotente.

SET NAMES utf8mb4;

ALTER TABLE webhooks
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL
    COMMENT 'Timestamp de exclusão lógica (soft-delete)';
