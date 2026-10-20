<?php
/**
 * Migration 155: setores do cliente são as colunas do quadro de Clientes.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Correção do dono do produto em 20/10/2026: "setores do cliente" são as colunas
 * do quadro de Clientes (`clientes_setores`: Atrasado, Atenção, Em dia...), não
 * os setores da equipe (`teams`), que a migration 154 tinha usado. O cliente
 * continua com a coluna principal em `clientes.setor_id` e pode estar também em
 * outras colunas:
 *
 *   crm_cliente_setores_extras  (cliente, setor do quadro), um par por linha.
 *
 * A tabela da 154 (`crm_cliente_setores`, cliente + team) sai, mas só se estiver
 * vazia: com alguma linha, fica onde está e o script avisa.
 * IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_155.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_155.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 155' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$existe = function (string $t) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (int) $st->fetchColumn() > 0;
};

if ($existe('crm_cliente_setores_extras')) {
    echo "  = crm_cliente_setores_extras já existe\n";
} else {
    echo "  + crm_cliente_setores_extras\n";
    if (!$dryRun) {
        $pdo->exec("CREATE TABLE crm_cliente_setores_extras (
            account_id  INT NOT NULL,
            cliente_id  INT NOT NULL,
            setor_id    INT NOT NULL COMMENT 'clientes_setores.id',
            criado_por  INT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (cliente_id, setor_id),
            KEY idx_clisetx_conta (account_id, setor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if ($existe('crm_cliente_setores')) {
    $n = (int) $pdo->query('SELECT COUNT(*) FROM crm_cliente_setores')->fetchColumn();
    if ($n === 0) {
        echo "  - crm_cliente_setores (vazia, da migration 154)\n";
        if (!$dryRun) $pdo->exec('DROP TABLE crm_cliente_setores');
    } else {
        echo "  ! crm_cliente_setores tem {$n} linha(s): fica como está\n";
    }
} else {
    echo "  = crm_cliente_setores já não existe\n";
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
