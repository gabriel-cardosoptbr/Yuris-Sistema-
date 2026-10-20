<?php
/**
 * Migration 152: valores do cliente (o "Financeiro" da ficha do cliente, edição CRM).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do dono do produto em 20/10/2026: o cadastro do cliente precisa dos
 * valores combinados, mensal, fixo e afins. Cada linha é um valor: mensal
 * (recorrente, com dia de vencimento), anual, fixo ou único (implantação,
 * taxa de ativação) e por demanda. Regra em app/Clientes/ValoresDoCliente.php.
 *
 *   crm_cliente_valores   tipo, descrição, valor, dia de vencimento, início,
 *                         fim, ativo, observação
 *
 * Só cria a tabela. IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_152.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_152.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 152' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$st->execute(['crm_cliente_valores']);
if ((int) $st->fetchColumn() > 0) {
    echo '  = crm_cliente_valores já existe' . PHP_EOL;
} else {
    echo '  + crm_cliente_valores' . PHP_EOL;
    if (!$dryRun) {
        $pdo->exec("
            CREATE TABLE crm_cliente_valores (
              id             INT NOT NULL AUTO_INCREMENT,
              account_id     INT NOT NULL,
              cliente_id     INT NOT NULL,
              tipo           ENUM('mensal','anual','fixo','demanda') NOT NULL DEFAULT 'mensal',
              descricao      VARCHAR(160) NOT NULL,
              valor          DECIMAL(12,2) NOT NULL,
              dia_vencimento TINYINT NULL,
              inicio         DATE NULL,
              fim            DATE NULL,
              ativo          TINYINT(1) NOT NULL DEFAULT 1,
              observacao     VARCHAR(300) NULL,
              criado_por     INT NULL,
              created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_cliente_valores (account_id, cliente_id, ativo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
echo ($dryRun ? 'Nada gravado (dry-run).' : 'Pronto.') . PHP_EOL;
