<?php
/**
 * Migration 169: a mesma pessoa em mais de uma conta (29/10/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * O Kauê vende para a Inovaize e para a Fleetiflow, com o mesmo login, e quer
 * trocar de empresa por um botão e ver o WhatsApp das duas junto. Cada conta
 * continua tendo o seu usuário (o da Fleetiflow é um usuário de verdade da
 * Fleetiflow: responsável de lead, dono de conversa, vendedor com as regras de
 * lá), e esta tabela diz que os dois são a mesma pessoa. Ver
 * App\Usuarios\PessoaVinculada.
 *
 * Uma tabela nova, nenhuma alteração nas existentes:
 *
 *   usuario_vinculos  user_id (um por linha) e a chave da pessoa. Usuários com a
 *                     mesma chave são a mesma pessoa.
 *
 * IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_169.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_169.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 169' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$tabelas = [
    'usuario_vinculos' => "CREATE TABLE usuario_vinculos (
        user_id    INT NOT NULL PRIMARY KEY,
        pessoa     VARCHAR(40) NOT NULL,
        criado_em  DATETIME NOT NULL,
        criado_por INT NULL,
        KEY idx_usuario_vinculos_pessoa (pessoa)
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
