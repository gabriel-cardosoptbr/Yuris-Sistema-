<?php
/**
 * Migration 179: lançamento do Financeiro ligado a cliente e processo, com parcelas e baixa.
 *
 * T2 (10/11/2026): ao cadastrar receita ou despesa, vincular cliente e, quando
 * houver, processo; parcelar em N lançamentos mensais; marcar como pago ou
 * recebido e baixar a pendência. T3 (valores do processo) reaproveita isto.
 *
 * dre_accounts ganha, todas NULL:
 *   cliente_id, processo_id      a quem o lançamento se refere
 *   vencimento                   data de vencimento; com ela o lançamento é um
 *                                título (a receber ou a pagar) e entra nas pendências
 *   pago_em, pago_por            a baixa: data e usuário. NULL = pendente
 *   parcelamento_id              o parcelamento de que a parcela faz parte
 *   parcela_numero, parcela_total
 * e os índices (account_id, cliente_id), (account_id, processo_id),
 * (parcelamento_id) e (account_id, vencimento).
 *
 * Tabela nova fin_parcelamentos: o parcelamento (conta, tipo, descrição,
 * cliente, processo, quantidade, valor total, primeiro vencimento, origem e
 * origem_id, quem criou). origem = 'manual' na tela de Finanças e
 * 'processo_resultado' quando nasce do resultado do processo (T3).
 *
 * O QUE JÁ EXISTE NÃO É TOCADO: as colunas nascem NULL em todo lançamento,
 * então nenhum lançamento antigo vira título nem muda de total no DRE.
 *
 * IDEMPOTENTE: cada coluna, índice e a tabela só são criados se faltarem.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_179.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_179.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo "== Migration 179: Financeiro com cliente, processo, parcelas e baixa ==" . PHP_EOL;
echo($dryRun ? "   MODO DRY-RUN: nada sera gravado." : "   MODO APLICACAO.") . PHP_EOL . PHP_EOL;

$schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$conta = function (string $sql, array $p) use ($pdo): int {
    $s = $pdo->prepare($sql); $s->execute($p); return (int) $s->fetchColumn();
};

$colunas = [
    'cliente_id'      => 'INT NULL DEFAULT NULL',
    'processo_id'     => 'INT NULL DEFAULT NULL',
    'vencimento'      => 'DATE NULL DEFAULT NULL',
    'pago_em'         => 'DATE NULL DEFAULT NULL',
    'pago_por'        => 'INT NULL DEFAULT NULL',
    'parcelamento_id' => 'INT NULL DEFAULT NULL',
    'parcela_numero'  => 'SMALLINT NULL DEFAULT NULL',
    'parcela_total'   => 'SMALLINT NULL DEFAULT NULL',
];
foreach ($colunas as $col => $def) {
    $tem = $conta('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                  [$schema, 'dre_accounts', $col]) > 0;
    echo "coluna dre_accounts.$col: " . ($tem ? 'ja existe' : 'falta') . PHP_EOL;
    if (!$tem && !$dryRun) $pdo->exec("ALTER TABLE dre_accounts ADD COLUMN $col $def");
}

$indices = [
    'idx_dre_cliente'      => '(account_id, cliente_id)',
    'idx_dre_processo'     => '(account_id, processo_id)',
    'idx_dre_parcelamento' => '(parcelamento_id)',
    'idx_dre_vencimento'   => '(account_id, vencimento)',
];
foreach ($indices as $nome => $cols) {
    $tem = $conta('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
                  [$schema, 'dre_accounts', $nome]) > 0;
    echo "indice $nome: " . ($tem ? 'ja existe' : 'falta') . PHP_EOL;
    if (!$tem && !$dryRun) $pdo->exec("ALTER TABLE dre_accounts ADD INDEX $nome $cols");
}

$temTabela = $conta('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [$schema, 'fin_parcelamentos']) > 0;
echo "tabela fin_parcelamentos: " . ($temTabela ? 'ja existe' : 'falta') . PHP_EOL;
if (!$dryRun) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_parcelamentos (
        id INT NOT NULL AUTO_INCREMENT,
        account_id INT NOT NULL,
        tipo ENUM('receita','despesa') NOT NULL DEFAULT 'receita',
        descricao VARCHAR(191) NOT NULL,
        cliente_id INT NULL DEFAULT NULL,
        processo_id INT NULL DEFAULT NULL,
        quantidade SMALLINT NOT NULL,
        valor_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        primeiro_vencimento DATE NOT NULL,
        origem VARCHAR(30) NOT NULL DEFAULT 'manual',
        origem_id INT NULL DEFAULT NULL,
        created_by INT NULL DEFAULT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_finp_conta (account_id),
        KEY idx_finp_origem (account_id, origem, origem_id),
        KEY idx_finp_cliente (account_id, cliente_id),
        KEY idx_finp_processo (account_id, processo_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
echo PHP_EOL . ($dryRun ? "Dry-run concluido. Rode sem --dry-run para aplicar." : "Migration 179 aplicada.") . PHP_EOL;
