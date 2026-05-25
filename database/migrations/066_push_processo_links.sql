-- ============================================================================
-- Migration 066 — push_processo_links (auto-vinculação intimação→processo)
-- ============================================================================
--
-- Mapeia "número CNJ do processo" → "processo_id do CRM Yuris" por tenant.
-- Quando user vincula manualmente uma intimação a um processo, criamos uma
-- entrada aqui. A partir daí, TODAS as próximas intimações com o mesmo
-- numero_processo já caem auto-vinculadas (sem intervenção).
--
-- TAMBÉM: aplicação retroativa — ao vincular, atualiza push_events passadas
-- que ainda não estavam vinculadas (rodado no endpoint link_process).
--
-- IDEMPOTENTE.
-- ============================================================================

CREATE TABLE IF NOT EXISTS push_processo_links (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id      INT UNSIGNED NOT NULL,
  numero_processo VARCHAR(40)  NOT NULL,
  processo_id     INT UNSIGNED NOT NULL,
  created_by      INT UNSIGNED DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_account_processo (account_id, numero_processo),
  KEY idx_processo (processo_id),
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT '[066] Migration aplicada: push_processo_links' AS resultado;
