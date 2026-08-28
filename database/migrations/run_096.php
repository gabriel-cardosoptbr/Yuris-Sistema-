<?php
// Runner standalone da migration 096 (whatsapp_channel_accounts).
//
// Uso local:  php database/migrations/run_096.php
// Uso prod:   docker exec -i yuris_app php /var/www/html/database/migrations/run_096.php
//
// Cria a tabela de autorização explícita de canal + coluna can_delete_messages +
// backfill das linhas de DONO para instâncias já existentes. Idempotente.

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Migration 096: whatsapp_channel_accounts ==\n";
echo "Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
echo "DB: "     . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS whatsapp_channel_accounts (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        channel_id  INT NOT NULL,
        account_id  INT NOT NULL,
        access_type ENUM('owner','shared') NOT NULL DEFAULT 'shared',
        can_view            TINYINT(1) NOT NULL DEFAULT 0,
        can_send            TINYINT(1) NOT NULL DEFAULT 0,
        can_sync            TINYINT(1) NOT NULL DEFAULT 0,
        can_delete_messages TINYINT(1) NOT NULL DEFAULT 0,
        can_manage          TINYINT(1) NOT NULL DEFAULT 0,
        granted_by  INT DEFAULT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at  DATETIME DEFAULT NULL,
        UNIQUE KEY uk_channel_account (channel_id, account_id),
        KEY idx_wca_account (account_id),
        KEY idx_wca_channel (channel_id),
        KEY idx_wca_active  (account_id, revoked_at),
        CONSTRAINT fk_wca_channel FOREIGN KEY (channel_id) REFERENCES whatsapp_instances(id) ON DELETE CASCADE,
        CONSTRAINT fk_wca_account FOREIGN KEY (account_id) REFERENCES accounts(id)            ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
echo "  [ok] tabela whatsapp_channel_accounts garantida\n";

// Coluna can_delete_messages (idempotente — tabela pode ter sido criada sem ela).
$hasCol = function (string $t, string $c) use ($pdo): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$t, $c]);
    return (int)$s->fetchColumn() > 0;
};
if (!$hasCol('whatsapp_channel_accounts', 'can_delete_messages')) {
    $pdo->exec("ALTER TABLE whatsapp_channel_accounts
                ADD COLUMN can_delete_messages TINYINT(1) NOT NULL DEFAULT 0 AFTER can_sync");
    echo "  [ok] coluna can_delete_messages adicionada\n";
} else {
    echo "  [skip] coluna can_delete_messages ja existe\n";
}

// Backfill das linhas de dono (idempotente via LEFT JOIN).
$n = $pdo->exec(
    "INSERT INTO whatsapp_channel_accounts (channel_id, account_id, access_type, can_view, can_send, can_sync, can_delete_messages, can_manage, created_at)
     SELECT wi.id, wi.account_id, 'owner', 1, 1, 1, 1, 1, NOW()
       FROM whatsapp_instances wi
       LEFT JOIN whatsapp_channel_accounts wca
         ON wca.channel_id = wi.id AND wca.account_id = wi.account_id
      WHERE wca.id IS NULL"
);
echo "  [ok] backfill de donos: {$n} linha(s) inserida(s)\n";

// Garante que donos pré-existentes tenham can_delete_messages = 1.
$u = $pdo->exec("UPDATE whatsapp_channel_accounts SET can_delete_messages = 1
                  WHERE access_type = 'owner' AND can_delete_messages = 0 AND revoked_at IS NULL");
echo "  [ok] donos atualizados com can_delete_messages: {$u} linha(s)\n";
echo "== Migration 096 concluída ==\n";
