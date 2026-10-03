<?php
/**
 * Migration 138: reembolsos e as parcelas de cada um.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do cliente (Inovaize, 03/10/2026): às vezes alguém paga uma conta da
 * empresa do próprio bolso, e a empresa precisa devolver. A tela Finanças passa
 * a registrar esse reembolso: para quem, quanto, de quê, e se a empresa já
 * pagou, à vista ou parcelado, parcela por parcela.
 *
 *   reembolsos           o reembolso em si (favorecido, descrição, data da
 *                        despesa, valor total). Exclusão é lógica (deleted_at).
 *   reembolso_parcelas   uma linha por parcela, com vencimento e a data em que
 *                        foi paga (NULL = ainda não paga). À vista = 1 parcela.
 *
 * A situação (pendente, pagando, pago, atrasado) não é gravada: sai das
 * parcelas, em App\Financas\Reembolso::situacao(), para nunca desencontrar.
 *
 * Valores em DECIMAL(12,2), nunca float. account_id nas duas tabelas: toda
 * leitura e escrita filtra por conta, inclusive a das parcelas.
 *
 * IDEMPOTENTE. Não altera dado existente.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_138.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_138.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function tabelaExiste(\PDO $pdo, string $tabela): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$tabela]);
    return (int) $st->fetchColumn() > 0;
}

linha('== Migration 138: reembolsos e reembolso_parcelas ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$tabelas = [
    'reembolsos' => "CREATE TABLE reembolsos (
        id INT NOT NULL AUTO_INCREMENT,
        account_id INT NOT NULL,
        favorecido VARCHAR(150) NOT NULL,
        descricao VARCHAR(255) NOT NULL,
        data_despesa DATE NOT NULL,
        valor_total DECIMAL(12,2) NOT NULL,
        observacao TEXT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_reemb_conta (account_id, deleted_at, data_despesa)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'reembolso_parcelas' => "CREATE TABLE reembolso_parcelas (
        id INT NOT NULL AUTO_INCREMENT,
        reembolso_id INT NOT NULL,
        account_id INT NOT NULL,
        numero SMALLINT UNSIGNED NOT NULL,
        valor DECIMAL(12,2) NOT NULL,
        vencimento DATE NOT NULL,
        pago_em DATE NULL DEFAULT NULL,
        pago_por INT NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_reemb_parcela (reembolso_id, numero),
        KEY idx_rp_conta (account_id, pago_em, vencimento),
        CONSTRAINT fk_rp_reembolso FOREIGN KEY (reembolso_id) REFERENCES reembolsos (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($tabelas as $nome => $sql) {
    if (tabelaExiste($pdo, $nome)) {
        linha("   $nome: ja existe, nada a fazer.");
        continue;
    }
    linha("   $nome: sera criada.");
    if ($dryRun) continue;
    $pdo->exec($sql);
    linha("   $nome: criada.");
}
linha();
linha($dryRun ? '   (dry-run) nada aplicado.' : '   pronto.');
