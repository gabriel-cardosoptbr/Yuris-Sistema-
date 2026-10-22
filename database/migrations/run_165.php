<?php
/**
 * Migration 165: quem enviou cada mensagem pelo Chat do CRM (22/10/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * As métricas de atendimento (Comunicação › Atendimento, App\WhatsAppAgente\
 * MetricasAtendimento) medem o tempo de resposta por operador. Até aqui a
 * mensagem enviada não dizia quem a digitou; o api/whatsapp/send.php passa a
 * gravar o usuário da sessão em:
 *
 *   whatsapp_messages.sent_by_user_id   INT NULL
 *
 * Mensagem antiga fica NULL e vale para o dono da conversa. Só coluna nova.
 * IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_165.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_165.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 165' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
$st->execute(['whatsapp_messages', 'sent_by_user_id']);
if ((int) $st->fetchColumn() > 0) {
    echo "  = whatsapp_messages.sent_by_user_id já existe\n";
} else {
    echo "  + whatsapp_messages.sent_by_user_id INT NULL\n";
    if (!$dryRun) $pdo->exec('ALTER TABLE whatsapp_messages ADD COLUMN sent_by_user_id INT NULL');
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
