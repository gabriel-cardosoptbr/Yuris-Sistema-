<?php
/**
 * Migration 180: valores do processo, resultado e projeção (T3, 10/11/2026).
 *
 * No cadastro do processo: valor da causa, valor estimado (projetado), o
 * resultado (tipo: acordo, sentença ou condenação, execução, improcedente,
 * outro; valor; à vista ou parcelado; parcelas e primeiro vencimento). Registrar
 * o resultado alimenta o Financeiro com as receitas previstas pelo mecanismo da
 * migration 179 (fin_parcelamentos com origem 'processo_resultado').
 *
 * processos ganha, todas NULL:
 *   valor_causa, valor_estimado, valor_resultado      DECIMAL(14,2)
 *   resultado_tipo                                    VARCHAR(20)
 *   resultado_forma                                   VARCHAR(10)  avista | parcelado
 *   resultado_parcelas                                SMALLINT
 *   resultado_primeiro_vencimento, resultado_em       DATE
 *
 * O QUE JÁ EXISTE NÃO É TOCADO: processo antigo fica com tudo NULL e abre e salva
 * como antes (Processo::update não conhece estas colunas; quem grava é
 * App\Processos\ValoresDoProcesso).
 *
 * Precisa da 179. IDEMPOTENTE: cada coluna só é criada se faltar.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_180.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_180.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo "== Migration 180: valores, resultado e projecao do processo ==" . PHP_EOL;
echo($dryRun ? "   MODO DRY-RUN: nada sera gravado." : "   MODO APLICACAO.") . PHP_EOL . PHP_EOL;

$schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
$st->execute([$schema, 'fin_parcelamentos']);
if (!(int) $st->fetchColumn()) { echo "Falta a migration 179 (fin_parcelamentos). Rode a 179 antes." . PHP_EOL; exit(1); }

$colunas = [
    'valor_causa'                   => 'DECIMAL(14,2) NULL DEFAULT NULL',
    'valor_estimado'                => 'DECIMAL(14,2) NULL DEFAULT NULL',
    'valor_resultado'               => 'DECIMAL(14,2) NULL DEFAULT NULL',
    'resultado_tipo'                => 'VARCHAR(20) NULL DEFAULT NULL',
    'resultado_forma'               => 'VARCHAR(10) NULL DEFAULT NULL',
    'resultado_parcelas'            => 'SMALLINT NULL DEFAULT NULL',
    'resultado_primeiro_vencimento' => 'DATE NULL DEFAULT NULL',
    'resultado_em'                  => 'DATE NULL DEFAULT NULL',
];
foreach ($colunas as $col => $def) {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$schema, 'processos', $col]);
    $tem = (int) $s->fetchColumn() > 0;
    echo "coluna processos.$col: " . ($tem ? 'ja existe' : 'falta') . PHP_EOL;
    if (!$tem && !$dryRun) $pdo->exec("ALTER TABLE processos ADD COLUMN $col $def");
}
echo PHP_EOL . ($dryRun ? "Dry-run concluido. Rode sem --dry-run para aplicar." : "Migration 180 aplicada.") . PHP_EOL;
