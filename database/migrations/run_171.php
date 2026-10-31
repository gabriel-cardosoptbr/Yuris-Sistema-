<?php
/**
 * Migration 171: a roleta sorteia ANTES do envio (30/10/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * O dono da Fleetiflow pediu que a abertura saia com o nome do atendente
 * sorteado ("Sou o Cauê", "Sou a Vitória"). Então o sorteio passa a acontecer
 * antes de a mensagem ser montada, e no robô da planilha o card do lead só nasce
 * depois do envio. O sorteio fica guardado pelo telefone e é ligado ao card
 * quando ele nasce. Ver App\WhatsAppAgente\Roleta::reservar.
 *
 * Em sdr_roleta_sorteios (migration 170):
 *   card_id   passa a aceitar NULL (sorteio feito antes de o card existir)
 *   telefone  novo: os dígitos do telefone do lead, para achar o sorteio dele
 *   índice    (account_id, telefone)
 * A chave única (conta, card) continua: NULL não conflita no MySQL.
 *
 * IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_171.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_171.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 171' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$tabela = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sdr_roleta_sorteios'")->fetchColumn();
if (!(int) $tabela) { echo '  sdr_roleta_sorteios não existe: rode a migration 170 antes.' . PHP_EOL; exit(1); }

$coluna = function (string $nome) use ($pdo): ?array {
    $st = $pdo->prepare("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sdr_roleta_sorteios' AND column_name = ?");
    $st->execute([$nome]);
    $r = $st->fetch(\PDO::FETCH_ASSOC);
    return $r ?: null;
};
$indice = function (string $nome) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sdr_roleta_sorteios' AND index_name = ?");
    $st->execute([$nome]);
    return (int) $st->fetchColumn() > 0;
};

$passos = [];
if (($coluna('card_id')['IS_NULLABLE'] ?? 'YES') === 'NO') $passos['card_id aceita NULL'] = 'ALTER TABLE sdr_roleta_sorteios MODIFY card_id INT NULL';
if (!$coluna('telefone')) $passos['coluna telefone'] = 'ALTER TABLE sdr_roleta_sorteios ADD COLUMN telefone VARCHAR(20) NULL AFTER card_id';
if (!$indice('idx_roleta_telefone')) $passos['índice (conta, telefone)'] = 'ALTER TABLE sdr_roleta_sorteios ADD KEY idx_roleta_telefone (account_id, telefone)';

if (!$passos) echo '  nada a fazer: já aplicada' . PHP_EOL;
foreach ($passos as $nome => $sql) {
    if ($dryRun) { echo "  {$nome}: seria feito" . PHP_EOL; continue; }
    $pdo->exec($sql);
    echo "  {$nome}: feito" . PHP_EOL;
}

echo 'Fim.' . PHP_EOL;
