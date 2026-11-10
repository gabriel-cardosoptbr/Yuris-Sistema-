<?php
/**
 * Migration 181: observação da baixa e juros da parcela (10/11/2026, pedido no T2).
 *
 * "Se atrasar a parcela, com o campo de observação: foi atrasado, se teve juros,
 * se não teve. Esses campos também refletem no DRE." Na baixa de uma parcela
 * (App\Financas\Parcelamento::baixar) o usuário pode informar juros ou multa e
 * uma observação. O juro vira um lançamento próprio do Financeiro, do mesmo tipo
 * da parcela e na data da baixa, ligado a ela por juros_de_id: assim o DRE soma o
 * juro e mostra de onde ele veio.
 *
 * dre_accounts ganha, as duas NULL:
 *   baixa_obs     VARCHAR(500)  observação da baixa (atraso, acordo de juros...)
 *   juros_de_id   INT           neste lançamento de juros, a parcela de origem
 * e o índice idx_dre_juros_de (juros_de_id).
 *
 * O QUE JÁ EXISTE NÃO É TOCADO. Precisa da 179. IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_181.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_181.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo "== Migration 181: observacao da baixa e juros da parcela ==" . PHP_EOL;
echo($dryRun ? "   MODO DRY-RUN: nada sera gravado." : "   MODO APLICACAO.") . PHP_EOL . PHP_EOL;

$schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$conta = function (string $sql, array $p) use ($pdo): int { $s = $pdo->prepare($sql); $s->execute($p); return (int) $s->fetchColumn(); };
if (!$conta('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$schema, 'dre_accounts', 'pago_em'])) {
    echo "Falta a migration 179. Rode a 179 antes." . PHP_EOL; exit(1);
}
foreach (['baixa_obs' => 'VARCHAR(500) NULL DEFAULT NULL', 'juros_de_id' => 'INT NULL DEFAULT NULL'] as $col => $def) {
    $tem = $conta('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$schema, 'dre_accounts', $col]) > 0;
    echo "coluna dre_accounts.$col: " . ($tem ? 'ja existe' : 'falta') . PHP_EOL;
    if (!$tem && !$dryRun) $pdo->exec("ALTER TABLE dre_accounts ADD COLUMN $col $def");
}
$tem = $conta('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', [$schema, 'dre_accounts', 'idx_dre_juros_de']) > 0;
echo "indice idx_dre_juros_de: " . ($tem ? 'ja existe' : 'falta') . PHP_EOL;
if (!$tem && !$dryRun) $pdo->exec('ALTER TABLE dre_accounts ADD INDEX idx_dre_juros_de (juros_de_id)');
echo PHP_EOL . ($dryRun ? "Dry-run concluido. Rode sem --dry-run para aplicar." : "Migration 181 aplicada.") . PHP_EOL;
