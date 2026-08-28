<?php
// Runner standalone da migration 082 (whatsapp_chats.agent_paused).
//
// Uso local:  php database/migrations/run_082.php
// Uso prod:   docker exec -i yuris_app php /var/www/html/database/migrations/run_082.php
//
// Adiciona a flag de "Assumir conversa" (pausa o agente por conversa).
// Idempotente: check em information_schema antes do ADD COLUMN.

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Migration 082: whatsapp_chats.agent_paused ==\n";
echo "Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
echo "DB: "     . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

$st = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_chats' AND COLUMN_NAME = 'agent_paused'"
);
$st->execute();
if ((int)$st->fetchColumn() > 0) {
    echo "  [skip] whatsapp_chats.agent_paused já existe\n";
} else {
    $pdo->exec("ALTER TABLE whatsapp_chats ADD COLUMN agent_paused TINYINT(1) NOT NULL DEFAULT 0 AFTER linked_user_id");
    echo "  [ok] whatsapp_chats.agent_paused adicionada\n";
}

echo "== Migration 082 concluída ==\n";
