-- ============================================================================
-- Migration 075 — monitor_requests
-- ============================================================================
-- DATA: 2026-05-26
-- CONTEXTO: Add-on Monitoramentos — fluxo "advogado solicita, admin aprova".
--
-- DECISÃO D4 APROVADA — pending_approval consome cota:
--   Quando advogado solicita, BillingGuard já decrementa saldo (reserva).
--   Evita race: dois advogados solicitam ao mesmo tempo na última vaga,
--   ambos veem "disponível", admin aprova ambos → estoura limite.
--   Com reserva via pending: 1º solicita → saldo cai → 2º vê "0 disponível"
--   e recebe mensagem "aguardando aprovação anterior".
--
-- FLUXO:
--   1. Advogado sem permissão direta clica "Solicitar monitor"
--   2. POST /api/push/requests.php → INSERT aqui com status='pending'
--   3. MonitorAudit::log('request_created')
--   4. Notifica admin via AccountNotification
--   5. Admin abre /configuracoes/monitoramentos.php aba "Solicitações"
--   6. Aprova → atualiza status='approved' + cria push_monitor + preenche
--      resulting_monitor_id + MonitorAudit::log('request_approved')
--   7. OU recusa → status='denied' + motivo_recusa
--   8. Solicitante recebe notificação do resultado
-- ============================================================================

CREATE TABLE IF NOT EXISTS monitor_requests (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id              INT UNSIGNED NOT NULL,
                          -- conta que solicita (escopo)
  requesting_user_id      INT UNSIGNED NOT NULL,
                          -- users.id de quem solicitou
  tipo_monitoramento      ENUM('oab','processo','nome','customizado') NOT NULL,
                          -- mesma enum de push_monitors.tipo_monitoramento
  valor_monitorado        VARCHAR(120) NOT NULL,
                          -- OAB, número de processo, nome, etc.
  uf                      CHAR(2) NULL,
                          -- só pra tipo='oab'
  nome_complementar       VARCHAR(200) NULL,
                          -- pra refinar busca (mesmo padrão de push_monitors)
  source_id               VARCHAR(40) NOT NULL DEFAULT 'djen',
                          -- 'djen' ou 'aasp' (origem dos providers existentes)
  justificativa           VARCHAR(500) NULL,
                          -- texto livre do solicitante (por que precisa do monitor)
  status                  ENUM(
                            'pending',     -- aguardando admin
                            'approved',    -- aprovado, monitor criado
                            'denied',      -- recusado
                            'canceled'     -- solicitante cancelou antes da decisão
                          ) NOT NULL DEFAULT 'pending',
  approved_by             INT UNSIGNED NULL,
                          -- users.id do admin que aprovou
  approved_at             DATETIME NULL,
  denied_at               DATETIME NULL,
  motivo_recusa           VARCHAR(300) NULL,
  resulting_monitor_id    INT UNSIGNED NULL,
                          -- push_monitors.id criado quando aprovado.
                          -- NULL se status != 'approved'.
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_mr_acc_status   (account_id, status),
  INDEX idx_mr_user_status  (requesting_user_id, status),
  INDEX idx_mr_resulting    (resulting_monitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
