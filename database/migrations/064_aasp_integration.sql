-- ============================================================================
-- Migration 064 — Integração AASP (Intimações)
-- ============================================================================
--
-- Cria infra de credenciais para a API de Intimações da AASP
-- (https://intimacaoapi.aasp.org.br). Apenas credenciais e auditoria —
-- as intimações em si reusam as tabelas push_* do módulo Intimações geral
-- (source_id='aasp'), evitando duplicar cron, UI, anonimização e cache.
--
-- TABELAS:
--   1) aasp_integrations           — credenciais e estado da integração
--   2) aasp_credential_audit       — auditoria de ciclo de vida da credencial
--                                    (created/rotated/tested/revoked/error)
--
-- SEGURANÇA:
--   • chave_encrypted: cifrada via app/Helpers/Crypto.php (AES-256-GCM)
--   • chave_masked   : "****" + últimos 4 dígitos para exibir no UI
--   • Nenhuma coluna de log/audit pode conter a chave em texto puro
--   • UNIQUE (account_id, nome) — uma integração nomeada única por conta
--
-- MULTI-TENANT:
--   • account_id NOT NULL em ambas as tabelas
--   • Models filtram account_id em todo SELECT/UPDATE/DELETE
--   • Apenas owner/admin do tenant pode criar/trocar/revogar (guard na API)
--
-- IDEMPOTENTE: cada CREATE/ALTER consulta INFORMATION_SCHEMA antes.
-- ============================================================================

-- ─── 1) aasp_integrations ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS aasp_integrations (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id               INT UNSIGNED NOT NULL,
  advogado_id              INT UNSIGNED DEFAULT NULL,
  nome                     VARCHAR(120) NOT NULL,
  tipo                     ENUM('associado','empresa') NOT NULL DEFAULT 'associado',
  chave_encrypted          TEXT         NOT NULL,
  chave_masked             VARCHAR(24)  NOT NULL,
  codigos_associados       TEXT         DEFAULT NULL,  -- JSON array (só modo empresa)
  status                   ENUM('active','inactive','error','pending') NOT NULL DEFAULT 'pending',
  intervalo_minutos        INT UNSIGNED NOT NULL DEFAULT 120,
  ultima_sync_em           DATETIME     DEFAULT NULL,
  ultima_sync_status       VARCHAR(50)  DEFAULT NULL,
  ultima_sync_erro         VARCHAR(500) DEFAULT NULL,
  proxima_sync_em          DATETIME     DEFAULT NULL,
  total_intimacoes_hoje    INT UNSIGNED NOT NULL DEFAULT 0,
  total_intimacoes_lifetime INT UNSIGNED NOT NULL DEFAULT 0,
  ultimo_diferencial_em    DATETIME     DEFAULT NULL,  -- pra fluxo incremental
  erros_consecutivos       INT UNSIGNED NOT NULL DEFAULT 0,
  created_by               INT UNSIGNED DEFAULT NULL,
  created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_account_nome (account_id, nome),
  KEY idx_account_status (account_id, status),
  KEY idx_proxima_sync (status, proxima_sync_em),
  KEY idx_advogado (advogado_id),
  KEY idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 2) aasp_credential_audit — auditoria de ciclo de vida da chave ──────
CREATE TABLE IF NOT EXISTS aasp_credential_audit (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id      INT UNSIGNED NOT NULL,
  integration_id  INT UNSIGNED DEFAULT NULL,  -- NULL se ação foi 'tested' antes de criar
  action          ENUM('created','rotated','tested','revoked','error','sync_ok','sync_fail') NOT NULL,
  actor_user_id   INT UNSIGNED DEFAULT NULL,  -- NULL quando feito por cron
  ip              VARCHAR(45)  DEFAULT NULL,
  user_agent      VARCHAR(255) DEFAULT NULL,
  detalhe         VARCHAR(500) DEFAULT NULL,  -- texto seguro, SEM credencial
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_account_created (account_id, created_at),
  KEY idx_integration (integration_id),
  KEY idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT '[064] Migration aplicada: aasp_integrations + aasp_credential_audit' AS resultado;
