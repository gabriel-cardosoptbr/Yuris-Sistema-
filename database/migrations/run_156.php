<?php
/**
 * Migration 156: o lead em mais de uma etapa e em mais de um setor.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do dono do produto em 20/10/2026, igual ao que o cliente ganhou na 155:
 *
 *   crm_card_etapas_extras   (lead, etapa do funil) além da etapa principal
 *                            (cards.coluna_id, onde o card é arrastado). O card
 *                            aparece também nessas etapas; os números do funil e
 *                            do painel contam só a principal.
 *   crm_card_setores_extras  (lead, setor da equipe `teams`) além do setor
 *                            principal (cards.team_id, migration 140).
 *
 * Só cria tabelas novas. IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_156.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_156.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 156' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$tabelas = [
    'crm_card_etapas_extras' => "CREATE TABLE crm_card_etapas_extras (
        account_id  INT NOT NULL,
        card_id     INT NOT NULL,
        coluna_id   INT NOT NULL COMMENT 'pipeline_columns.id',
        criado_por  INT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (card_id, coluna_id),
        KEY idx_cardetx_conta (account_id, coluna_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'crm_card_setores_extras' => "CREATE TABLE crm_card_setores_extras (
        account_id  INT NOT NULL,
        card_id     INT NOT NULL,
        team_id     INT NOT NULL COMMENT 'teams.id',
        criado_por  INT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (card_id, team_id),
        KEY idx_cardsetx_conta (account_id, team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
foreach ($tabelas as $nome => $sql) {
    $st->execute([$nome]);
    if ((int) $st->fetchColumn() > 0) { echo "  = $nome já existe\n"; continue; }
    echo "  + $nome\n";
    if (!$dryRun) $pdo->exec($sql);
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
