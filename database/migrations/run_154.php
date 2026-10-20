<?php
/**
 * Migration 154: setores do cliente (mais de um por cliente).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do dono do produto em 20/10/2026: o card do cliente mostra a que setor
 * ele pertence (Concessionária, Locação...), e pode ser mais de um. O setor é o
 * mesmo do lead e do Chat (tabela `teams`, migration 140); o lead tem um só
 * (`cards.team_id`), o cliente passa a ter vários.
 *
 *   crm_cliente_setores  (cliente, setor), um par por linha, com a conta.
 *
 * Preenche com o setor que os leads de origem do cliente já tinham
 * (cards.cliente_id + cards.team_id), para o card não nascer vazio. Só insere
 * na tabela nova: nada existente muda.
 * IDEMPOTENTE (INSERT IGNORE sobre a chave cliente + setor).
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_154.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_154.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 154' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$st->execute(['crm_cliente_setores']);
$existe = (int) $st->fetchColumn() > 0;
if ($existe) {
    echo "  = crm_cliente_setores já existe\n";
} else {
    echo "  + crm_cliente_setores\n";
    if (!$dryRun) {
        $pdo->exec("CREATE TABLE crm_cliente_setores (
            account_id  INT NOT NULL,
            cliente_id  INT NOT NULL,
            team_id     INT NOT NULL,
            criado_por  INT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (cliente_id, team_id),
            KEY idx_cliset_conta (account_id, team_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

$origem = "FROM cards c
             JOIN clientes cl ON cl.id = c.cliente_id AND cl.account_id = c.account_id AND cl.deleted_at IS NULL
             JOIN teams t ON t.id = c.team_id AND t.account_id = c.account_id AND t.deleted_at IS NULL
            WHERE c.cliente_id IS NOT NULL AND c.team_id IS NOT NULL AND c.deleted_at IS NULL";
$n = (int) $pdo->query("SELECT COUNT(DISTINCT c.cliente_id, c.team_id) $origem")->fetchColumn();
echo "  + setor do lead de origem para {$n} par(es) cliente/setor\n";
if (!$dryRun && $n > 0) {
    $pdo->exec("INSERT IGNORE INTO crm_cliente_setores (account_id, cliente_id, team_id) SELECT DISTINCT c.account_id, c.cliente_id, c.team_id $origem");
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
