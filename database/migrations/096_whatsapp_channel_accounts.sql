-- Migration 096: whatsapp_channel_accounts
-- Camada de AUTORIZAÇÃO EXPLÍCITA de acesso a canal de WhatsApp (instância).
-- channel_id = whatsapp_instances.id. Cada conta que pode usar um canal tem UMA
-- linha aqui: a dona (access_type='owner', todas as permissões) e cada conta com
-- quem o canal foi explicitamente compartilhado (access_type='shared', permissões
-- granulares). Sem linha = sem acesso (deny-by-default).
--
-- Compartilhamento (filial usando o canal da matriz) NÃO é inferido de hierarquia
-- em runtime; exige um registro 'shared' explícito criado na concessão.

CREATE TABLE IF NOT EXISTS whatsapp_channel_accounts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    channel_id  INT NOT NULL,                                   -- whatsapp_instances.id
    account_id  INT NOT NULL,                                   -- conta que recebe o acesso
    access_type ENUM('owner','shared') NOT NULL DEFAULT 'shared',
    can_view            TINYINT(1) NOT NULL DEFAULT 0,
    can_send            TINYINT(1) NOT NULL DEFAULT 0,
    can_sync            TINYINT(1) NOT NULL DEFAULT 0,
    can_delete_messages TINYINT(1) NOT NULL DEFAULT 0,          -- apagar mensagens (shared: off por padrao)
    can_manage          TINYINT(1) NOT NULL DEFAULT 0,          -- conexao/QR/logout/webhook/excluir instancia (so dono)
    granted_by  INT DEFAULT NULL,                               -- users.id / super admin que concedeu
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at  DATETIME DEFAULT NULL,                          -- NULL = ativo
    UNIQUE KEY uk_channel_account (channel_id, account_id),
    KEY idx_wca_account (account_id),
    KEY idx_wca_channel (channel_id),
    KEY idx_wca_active  (account_id, revoked_at),
    CONSTRAINT fk_wca_channel FOREIGN KEY (channel_id) REFERENCES whatsapp_instances(id) ON DELETE CASCADE,
    CONSTRAINT fk_wca_account FOREIGN KEY (account_id) REFERENCES accounts(id)            ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: toda instância existente recebe a linha de DONA (owner, full perms).
INSERT INTO whatsapp_channel_accounts (channel_id, account_id, access_type, can_view, can_send, can_sync, can_delete_messages, can_manage, created_at)
SELECT wi.id, wi.account_id, 'owner', 1, 1, 1, 1, 1, NOW()
  FROM whatsapp_instances wi
  LEFT JOIN whatsapp_channel_accounts wca
    ON wca.channel_id = wi.id AND wca.account_id = wi.account_id
 WHERE wca.id IS NULL;
