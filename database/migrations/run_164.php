<?php
/**
 * Migration 164: dono por conversa num número de WhatsApp (Inovaize, 22/10/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Dois vendedores no mesmo número (a Isa e o Kauê), sem WhatsApp Web: cada
 * conversa tem um dono (whatsapp_chats.linked_user_id, que já existe) e cada um
 * vê só as suas. O modo é ligado por número (App\WhatsAppAgente\DonoDaConversa):
 *
 *   whatsapp_instances.dono_conversa_desde   corte; NULL = modo desligado
 *   whatsapp_instances.dono_novas_user_id    quem recebe a conversa nova
 *   whatsapp_instances.dono_antigas_user_id  quem fica com a de antes do corte
 *
 * Só colunas novas, NULL por padrão (nenhum número muda de comportamento até o
 * script scripts/manutencao/dono_por_conversa.php ligar o modo). IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_164.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_164.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 164' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$colunas = [
    'dono_conversa_desde'  => 'DATETIME NULL',
    'dono_novas_user_id'   => 'INT NULL',
    'dono_antigas_user_id' => 'INT NULL',
];
$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
foreach ($colunas as $nome => $tipo) {
    $st->execute(['whatsapp_instances', $nome]);
    if ((int) $st->fetchColumn() > 0) {
        echo "  = whatsapp_instances.$nome já existe\n";
        continue;
    }
    echo "  + whatsapp_instances.$nome $tipo\n";
    if (!$dryRun) $pdo->exec("ALTER TABLE whatsapp_instances ADD COLUMN $nome $tipo");
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
