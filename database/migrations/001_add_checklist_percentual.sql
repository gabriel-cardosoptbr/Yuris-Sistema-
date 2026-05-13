-- Migration: Add checklist_percentual to cards and populate from existing checklist items
-- Run this against the `sistema_vendas` database (e.g. via mysql CLI or phpMyAdmin)

-- Add column if not exists (MySQL 8.0.16+ supports IF NOT EXISTS)
ALTER TABLE cards
  ADD COLUMN IF NOT EXISTS checklist_percentual DECIMAL(5,2) DEFAULT 0;

-- Populate checklist_percentual using existing card_checklist_items data
UPDATE cards c
LEFT JOIN (
  SELECT card_id, COUNT(*) AS total, SUM(concluido) AS done
  FROM card_checklist_items
  GROUP BY card_id
) cc ON c.id = cc.card_id
SET c.checklist_percentual = CASE WHEN COALESCE(cc.total,0) > 0 THEN ROUND((cc.done/cc.total) * 100, 2) ELSE 0 END;

-- Optional: ensure column exists in older MySQL where IF NOT EXISTS isn't supported
-- If the ALTER TABLE with IF NOT EXISTS fails on your MySQL, run these commands instead:
-- ALTER TABLE cards ADD COLUMN checklist_percentual DECIMAL(5,2) DEFAULT 0;
-- then run the UPDATE above.
