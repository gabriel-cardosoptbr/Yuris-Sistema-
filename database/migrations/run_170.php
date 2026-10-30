<?php
/**
 * Migration 170: a roleta dos leads da prospecção (30/10/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * O dono da Fleetiflow pediu que cada lead aberto pelo disparo automático vá
 * para um atendente, numa divisão que ele escolhe ("70% Cauê e 30% a Vitória").
 * A divisão fica em whatsapp_settings (chave `sdr_roleta`, sem migration); esta
 * tabela guarda cada sorteio. Ver App\WhatsAppAgente\Roleta.
 *
 * Uma tabela nova, nenhuma alteração nas existentes:
 *
 *   sdr_roleta_sorteios  um lead sorteado: o card, quem recebeu, com qual
 *                        percentual e de qual fluxo veio. A chave única
 *                        (conta, card) impede sortear o mesmo lead duas vezes.
 *                        É também a contagem da tela (quantos cada um recebeu).
 *
 * IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_170.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_170.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 170' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$tabelas = [
    'sdr_roleta_sorteios' => "CREATE TABLE sdr_roleta_sorteios (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id   INT NOT NULL,
        card_id      INT NOT NULL,
        user_id      INT NOT NULL,
        percentual   INT NOT NULL,
        origem       VARCHAR(20) NOT NULL DEFAULT 'disparo',
        instance_id  INT NULL,
        remote_jid   VARCHAR(120) NULL,
        created_at   DATETIME NOT NULL,
        UNIQUE KEY uk_roleta_card (account_id, card_id),
        KEY idx_roleta_conta (account_id, created_at),
        KEY idx_roleta_user (account_id, user_id)
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
