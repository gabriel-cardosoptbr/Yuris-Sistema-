<?php
/**
 * Migration 137: whatsapp_instances.responsavel_user_id, o vendedor dono do número.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Na edição CRM, toda mensagem nova de um número cria o lead em "Novos leads"
 * (SdrFleetiflow::garantirCard). Até aqui o lead nascia sem responsável, e o
 * painel por vendedor (cada um vê só os próprios leads) não tinha a quem contar
 * esses leads. A regra pedida pelo cliente em 02/10/2026: o lead da automação é
 * do vendedor dono do número por onde a mensagem saiu (o número da Isa na
 * Inovaize, os números da Fleet com a Vitória).
 *
 * A coluna mora no NÚMERO (whatsapp_instances), não na conta: uma conta pode
 * ter vários números, cada um de um vendedor. NULL = sem dono, o lead continua
 * nascendo sem responsável, como sempre.
 *
 * Só cria a coluna (e o índice). Quem preenche é
 * scripts/manutencao/definir_dono_numero.php, número a número.
 *
 * IDEMPOTENTE. Não altera dado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_137.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_137.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

linha('== Migration 137: whatsapp_instances.responsavel_user_id ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_instances' AND COLUMN_NAME = 'responsavel_user_id'");
$st->execute();
if ((int) $st->fetchColumn() > 0) {
    linha('   coluna ja existe: nada a fazer.');
    exit(0);
}

$sql = "ALTER TABLE whatsapp_instances
          ADD COLUMN responsavel_user_id INT NULL DEFAULT NULL AFTER account_id,
          ADD KEY idx_wi_responsavel (responsavel_user_id)";
linha('   ' . preg_replace('/\s+/', ' ', $sql));
if ($dryRun) {
    linha('   (dry-run) nao aplicado.');
    exit(0);
}
$pdo->exec($sql);
linha('   aplicado.');
