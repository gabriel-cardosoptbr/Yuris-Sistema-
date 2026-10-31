<?php
/**
 * Migration 172: o retrato da fila ganha tabela própria (31/10/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Os fluxos do n8n mandam ao CRM um retrato da fila de aberturas (Operação › Fila, ver
 * App\WhatsAppAgente\FilaDaProspeccao). Ele ficava em whatsapp_settings (TEXT, 64 KB), o que
 * obrigava a cortar a lista de próximos. Com a tabela própria (MEDIUMTEXT, 16 MB) o painel
 * mostra a fila INTEIRA, sem limite.
 *
 *   sdr_fila_retratos  o último retrato de cada fluxo (planilha, listas) de cada conta.
 *
 * Uma tabela nova, nenhuma alteração nas existentes. IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_172.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_172.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 172' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$tabelas = [
    'sdr_fila_retratos' => "CREATE TABLE sdr_fila_retratos (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id    INT NOT NULL,
        fluxo         VARCHAR(20) NOT NULL,
        retrato       MEDIUMTEXT NOT NULL,
        atualizado_em DATETIME NOT NULL,
        UNIQUE KEY uk_fila_retrato (account_id, fluxo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$existe = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
foreach ($tabelas as $nome => $sql) {
    $existe->execute([$nome]);
    if ((int) $existe->fetchColumn() > 0) { echo "  {$nome}: já existe" . PHP_EOL; continue; }
    if ($dryRun) { echo "  {$nome}: seria criada" . PHP_EOL; continue; }
    $pdo->exec($sql);
    echo "  {$nome}: criada" . PHP_EOL;
}

echo 'Fim.' . PHP_EOL;
