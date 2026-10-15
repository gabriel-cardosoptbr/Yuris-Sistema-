<?php
/**
 * Migration 149: reembolso ganha o tipo (reembolso ou estorno) e a pessoa da equipe.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido da Inovaize (15/10/2026): além do reembolso (alguém pagou uma conta da
 * empresa do próprio bolso), às vezes a empresa precisa fazer um ESTORNO para um
 * dos sócios (Gabriel, Marcelo, Milton). Nos dois casos a empresa deve à pessoa,
 * à vista ou parcelado, e a tela precisa mostrar, por pessoa, quanto falta
 * pagar, quanto já foi pago e se foi pago em parte.
 *
 *   reembolsos.tipo                'reembolso' (padrão, tudo o que já existe) ou 'estorno'
 *   reembolsos.favorecido_user_id  o usuário da conta que recebe (NULL = pessoa de
 *                                  fora, só pelo nome em `favorecido`)
 *
 * IDEMPOTENTE. Não altera dado existente (os reembolsos atuais ficam 'reembolso'
 * e sem usuário).
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_149.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_149.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function colunaExiste(\PDO $pdo, string $tabela, string $coluna): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$tabela, $coluna]);
    return (int) $st->fetchColumn() > 0;
}

linha('== Migration 149: reembolsos.tipo e reembolsos.favorecido_user_id ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$colunas = [
    'tipo' => "ALTER TABLE reembolsos ADD COLUMN tipo VARCHAR(10) NOT NULL DEFAULT 'reembolso' AFTER account_id",
    'favorecido_user_id' => "ALTER TABLE reembolsos ADD COLUMN favorecido_user_id INT NULL DEFAULT NULL AFTER favorecido, ADD KEY idx_reemb_pessoa (account_id, favorecido_user_id)",
];
foreach ($colunas as $col => $sql) {
    if (colunaExiste($pdo, 'reembolsos', $col)) { linha("   reembolsos.$col: ja existe, nada a fazer."); continue; }
    linha("   reembolsos.$col: sera criada.");
    if ($dryRun) continue;
    $pdo->exec($sql);
    linha("   reembolsos.$col: criada.");
}
linha();
linha($dryRun ? '   (dry-run) nada aplicado.' : '   pronto.');
