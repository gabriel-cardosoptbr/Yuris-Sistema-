-- ============================================================
-- Migration 028 — Limpeza de duplicatas de tarefas recorrentes
-- Criado após mudança para abordagem in-place (renovação no lugar).
-- O sistema anterior criava uma nova tarefa por ocorrência;
-- esta migration arquiva as instâncias antigas, mantendo apenas
-- a mais recente (maior prazo) de cada recorrência.
-- ============================================================

-- Ver duplicatas antes (informativo)
-- SELECT recorrencia_id, COUNT(*) AS total
-- FROM tasks
-- WHERE recorrencia_id IS NOT NULL AND status != 'arquivada'
-- GROUP BY recorrencia_id HAVING COUNT(*) > 1;

-- Arquiva todas as instâncias antigas de cada recorrência,
-- mantendo apenas a de maior prazo (e maior id em caso de empate).
UPDATE tasks
SET status = 'arquivada'
WHERE recorrencia_id IS NOT NULL
  AND status != 'arquivada'
  AND id NOT IN (
      SELECT id FROM (
          SELECT t2.id
          FROM tasks t2
          WHERE t2.recorrencia_id IS NOT NULL
            AND t2.status != 'arquivada'
          ORDER BY t2.prazo DESC, t2.id DESC
          -- Pega o ID mais recente por recorrência
      ) sub
      WHERE (sub.id) IN (
          SELECT MAX(sub2.id)
          FROM (
              SELECT t3.id, t3.recorrencia_id,
                     ROW_NUMBER() OVER (
                         PARTITION BY t3.recorrencia_id
                         ORDER BY t3.prazo DESC, t3.id DESC
                     ) AS rn
              FROM tasks t3
              WHERE t3.recorrencia_id IS NOT NULL
                AND t3.status != 'arquivada'
          ) sub2
          WHERE sub2.rn = 1
          GROUP BY sub2.recorrencia_id
      )
  );

-- Versão simplificada (MySQL 8+ com window functions):
-- UPDATE tasks t
-- JOIN (
--     SELECT id,
--            ROW_NUMBER() OVER (PARTITION BY recorrencia_id ORDER BY prazo DESC, id DESC) AS rn
--     FROM tasks
--     WHERE recorrencia_id IS NOT NULL AND status != 'arquivada'
-- ) ranked ON ranked.id = t.id
-- SET t.status = 'arquivada'
-- WHERE ranked.rn > 1;
