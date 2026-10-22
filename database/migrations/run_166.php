<?php
/**
 * Migration 166: excluir conversa guarda as mensagens (22/10/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * "Excluir conversa" no Chat apagava a conversa e TODAS as mensagens dela do
 * banco. Pedido da Inovaize: guardar as mensagens (o histórico e as métricas
 * de atendimento não podem sumir porque alguém limpou a lista). A conversa
 * excluída passa a ser só marcada:
 *
 *   whatsapp_chats.excluida_em   DATETIME NULL
 *
 * Marcada, sai da lista, das não lidas e de "esperando agora"; as mensagens
 * ficam. Se o contato escrever de novo, a conversa volta com o histórico.
 * Só coluna nova. IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_166.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_166.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 166' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
$st->execute(['whatsapp_chats', 'excluida_em']);
if ((int) $st->fetchColumn() > 0) {
    echo "  = whatsapp_chats.excluida_em já existe\n";
} else {
    echo "  + whatsapp_chats.excluida_em DATETIME NULL\n";
    if (!$dryRun) $pdo->exec('ALTER TABLE whatsapp_chats ADD COLUMN excluida_em DATETIME NULL');
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
