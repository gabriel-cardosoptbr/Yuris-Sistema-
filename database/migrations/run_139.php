<?php
/**
 * Migration 139: reembolso_parcelas.pago_por_nome, quem fez o pagamento.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido da Inovaize (03/10/2026), logo depois dos reembolsos (migration 138):
 * ao marcar uma parcela como paga, registrar QUEM pagou, além da data. É texto
 * livre (quem paga pode não ser usuário do sistema); a tela sugere a equipe e
 * já vem com o nome de quem está logado. `pago_por` (id do usuário que marcou)
 * continua sendo gravado, para a trilha.
 *
 * IDEMPOTENTE. Não altera dado existente (parcelas já pagas ficam com NULL).
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_139.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_139.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

linha('== Migration 139: reembolso_parcelas.pago_por_nome ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reembolso_parcelas'");
$st->execute();
if ((int) $st->fetchColumn() === 0) {
    linha('   reembolso_parcelas nao existe: rode a migration 138 antes.');
    exit(1);
}
$st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reembolso_parcelas' AND COLUMN_NAME = 'pago_por_nome'");
$st->execute();
if ((int) $st->fetchColumn() > 0) {
    linha('   coluna ja existe: nada a fazer.');
    exit(0);
}

$sql = "ALTER TABLE reembolso_parcelas ADD COLUMN pago_por_nome VARCHAR(150) NULL DEFAULT NULL AFTER pago_por";
linha('   ' . $sql);
if ($dryRun) {
    linha('   (dry-run) nao aplicado.');
    exit(0);
}
$pdo->exec($sql);
linha('   aplicado.');
