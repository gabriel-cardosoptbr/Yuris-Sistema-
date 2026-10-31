<?php
/**
 * Migration 173: o vendedor escolhe o nome que aparece nas mensagens (31/10/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do dono da Fleetiflow: o vendedor poder preencher como quer ser chamado
 * ("às vezes ele quer que chame ele pelo sobrenome"). A coluna `users.nome_mensagens`
 * guarda esse nome (App\WhatsAppAgente\VendedorDoLead). Vazia (NULL), vale o primeiro
 * nome do cadastro, como era antes: quem não preencher não muda nada.
 *
 * Uma coluna nova, nenhuma alteração nas existentes. IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_173.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_173.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 173' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$existe = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'nome_mensagens'");
if ((int) $existe->fetchColumn() > 0) {
    echo '  users.nome_mensagens: já existe' . PHP_EOL;
} elseif ($dryRun) {
    echo '  users.nome_mensagens: seria criada' . PHP_EOL;
} else {
    $pdo->exec('ALTER TABLE users ADD COLUMN nome_mensagens VARCHAR(40) NULL DEFAULT NULL');
    echo '  users.nome_mensagens: criada' . PHP_EOL;
}

echo 'Fim.' . PHP_EOL;
