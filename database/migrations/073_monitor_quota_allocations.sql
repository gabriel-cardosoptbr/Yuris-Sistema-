-- ============================================================================
-- Migration 073 — monitor_quota_allocations
-- ============================================================================
-- DATA: 2026-05-26
-- CONTEXTO: Add-on Monitoramentos (Etapa 1 da arquitetura aprovada).
--
-- DECISÃO D2 APROVADA — modelo HÍBRIDO:
--   Por default: pool aberto. Matriz tem N monitors (via account_quota_overrides)
--   e qualquer filial/advogado vinculada consome do mesmo pool.
--   OPCIONAL: matriz pode reservar X monitors pra filial Y / advogado Z
--   (preenche linha aqui). Validação: filial só pode usar até essa quantidade
--   reservada, mesmo se sobrar no pool.
--
-- USO TÍPICO:
--   - Matriz com 10 monitors no pool, sem nenhuma row aqui → todos usam.
--   - Matriz reserva 3 pra Filial SP → Filial SP só usa 3, sobra 7 pra matriz+outras.
--   - Matriz revoga alocação → Filial perde a reserva (volta ao pool aberto).
--
-- HISTÓRICO LIDA pela camada BillingGuard estendida no MonitorQuota helper.
-- ============================================================================

CREATE TABLE IF NOT EXISTS monitor_quota_allocations (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parent_account_id   INT UNSIGNED NOT NULL,
                      -- FK lógica → accounts.id (matriz que aloca)
  target_account_id   INT UNSIGNED NULL,
                      -- FK lógica → accounts.id (filial OU conta de advogado).
                      -- NULL se a alocação é direto pra um user (advogado interno).
  target_user_id      INT UNSIGNED NULL,
                      -- FK lógica → users.id (usuário/advogado interno da matriz).
                      -- NULL se a alocação é pra account inteira (filial).
                      -- CHECK lógico: pelo menos um dos dois target_* não-NULL.
  allocated           INT UNSIGNED NOT NULL,
                      -- Quantos monitors estão RESERVADOS pra esse destino.
                      -- Filial só pode criar até esse número, mesmo com pool sobrando.
  status              ENUM('active','revoked') NOT NULL DEFAULT 'active',
                      -- revoked = matriz cancelou a reserva.
  created_by          INT UNSIGNED NOT NULL,
                      -- users.id do admin/owner que alocou.
  revoked_at          DATETIME NULL,
  revoked_by          INT UNSIGNED NULL,
  observacoes         VARCHAR(500) NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_mqa_parent  (parent_account_id, status),
  INDEX idx_mqa_target_acc  (target_account_id, status),
  INDEX idx_mqa_target_user (target_user_id, status)

  -- Nota: CHECK constraint (target_account_id OR target_user_id) foi removida
  -- aqui porque MariaDB <10.5 ignora CHECK. Validação fica no PHP
  -- (MonitorQuotaAllocation::create lança exception se ambos NULL).
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
