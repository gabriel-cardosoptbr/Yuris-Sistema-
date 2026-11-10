<?php
/**
 * Migration 178: o cliente Pessoa Jurídica aponta para a Pessoa Física responsável.
 *
 * T1 (10/11/2026): no cadastro de PJ, vincular a PF responsável, escolhendo uma PF
 * já cadastrada ou cadastrando ali mesmo. A PF continua com cadastro próprio e
 * independente, e nada é copiado: a PJ guarda só a referência.
 *
 * clientes ganha pf_responsavel_id INT NULL, o índice idx_clientes_pf_responsavel
 * e a chave estrangeira fk_clientes_pf_responsavel -> clientes(id) ON DELETE SET NULL
 * (apagar a PF de verdade solta o vínculo; arquivar não apaga, e a leitura ignora
 * PF arquivada). Quem pode ser vinculado (só PF, mesma conta, não o próprio
 * registro) é regra de App\Clientes\ResponsavelPF, não do banco.
 *
 * O QUE JÁ EXISTE NÃO É TOCADO: a coluna nasce NULL em todo cliente.
 *
 * IDEMPOTENTE: coluna, índice e chave só são criados se faltarem.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_178.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_178.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo "== Migration 178: PF responsavel do cliente PJ ==" . PHP_EOL;
echo($dryRun ? "   MODO DRY-RUN: nada sera gravado." : "   MODO APLICACAO.") . PHP_EOL . PHP_EOL;

$schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$q = function (string $sql, array $p) use ($pdo): int {
    $s = $pdo->prepare($sql); $s->execute($p); return (int) $s->fetchColumn();
};
$temColuna = $q('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$schema, 'clientes', 'pf_responsavel_id']) > 0;
$temIndice = $q('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$schema, 'clientes', 'idx_clientes_pf_responsavel']) > 0;
$temChave  = $q("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$schema, 'clientes', 'fk_clientes_pf_responsavel']) > 0;

echo "coluna pf_responsavel_id: " . ($temColuna ? 'ja existe' : 'falta') . PHP_EOL;
echo "indice idx_clientes_pf_responsavel: " . ($temIndice ? 'ja existe' : 'falta') . PHP_EOL;
echo "chave fk_clientes_pf_responsavel: " . ($temChave ? 'ja existe' : 'falta') . PHP_EOL;

if (!$dryRun) {
    if (!$temColuna) $pdo->exec('ALTER TABLE clientes ADD COLUMN pf_responsavel_id INT NULL DEFAULT NULL AFTER responsavel_id');
    if (!$temIndice) $pdo->exec('ALTER TABLE clientes ADD INDEX idx_clientes_pf_responsavel (pf_responsavel_id)');
    if (!$temChave)  $pdo->exec('ALTER TABLE clientes ADD CONSTRAINT fk_clientes_pf_responsavel
                                   FOREIGN KEY (pf_responsavel_id) REFERENCES clientes (id) ON DELETE SET NULL');
}
echo PHP_EOL . ($dryRun ? "Dry-run concluido. Rode sem --dry-run para aplicar." : "Migration 178 aplicada.") . PHP_EOL;
