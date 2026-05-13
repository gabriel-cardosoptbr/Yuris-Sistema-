-- 023_add_prazo_task_checklist_items.sql
-- O que faz: Adiciona a coluna prazo na tabela task_checklist_items
-- Por que: O modelo TaskChecklist.php (create) aceita e insere um campo `prazo`
--          (do tipo DATE) nos itens de checklist de tarefas. O endpoint
--          task_checklist.php passa `$input['prazo']` ao criar um item.
--          A migration 013_create_tasks.sql não inclui esta coluna.
--          O código já possui um workaround (ALTER TABLE em runtime via
--          INFORMATION_SCHEMA), mas a coluna deve ser parte do schema declarativo
--          para garantir consistência em todos os ambientes.

SET NAMES utf8mb4;

ALTER TABLE task_checklist_items
  ADD COLUMN IF NOT EXISTS prazo DATE DEFAULT NULL
    COMMENT 'Prazo individual do item do checklist (opcional)';
