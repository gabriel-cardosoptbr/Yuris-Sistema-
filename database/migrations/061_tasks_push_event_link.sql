-- ============================================================================
-- Migration 061 — Tasks: linkagem opcional com push_events (intimações)
-- ============================================================================
--
-- Adiciona tasks.push_event_id (FK opcional → push_events.id).
-- Permite criar tarefa automaticamente quando user marca prazo numa intimação.
-- A tarefa carrega o histórico de origem; se a intimação for apagada, FK
-- vira NULL (ON DELETE SET NULL) — tarefa segue viva mas perde o link.
--
-- IDEMPOTENTE: consulta INFORMATION_SCHEMA antes do ALTER.
-- ============================================================================

-- ─── 1) tasks.push_event_id ─────────────────────────────────────────────────
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name   = 'tasks'
    AND column_name  = 'push_event_id'
);
SET @sql := IF(@has_col = 0,
  'ALTER TABLE tasks
     ADD COLUMN push_event_id INT UNSIGNED NULL DEFAULT NULL AFTER recorrencia_id,
     ADD INDEX idx_push_event (push_event_id)',
  'SELECT "[061] tasks.push_event_id já existe — skip" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 2) FK opcional (não bloqueia se push_events for apagada) ───────────────
SET @has_fk := (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE table_schema = DATABASE()
    AND table_name   = 'tasks'
    AND column_name  = 'push_event_id'
    AND referenced_table_name = 'push_events'
);
SET @sql := IF(@has_fk = 0,
  'ALTER TABLE tasks
     ADD CONSTRAINT fk_tasks_push_event
     FOREIGN KEY (push_event_id) REFERENCES push_events(id)
     ON DELETE SET NULL',
  'SELECT "[061] FK fk_tasks_push_event já existe — skip" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT '[061] Migration aplicada: tasks.push_event_id + FK' AS resultado;
