<?php
/**
 * Migration 175: tratamento das publicações (05/11/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Reunião de 23/10/2026: publicação que exige providência não pode sumir sem um
 * tratamento identificável. O que existia era "lida", que é por usuário: alguém
 * ler não diz se o escritório fez alguma coisa.
 *
 *   push_events.tratamento_*        o estado atual, do ESCRITÓRIO
 *   push_event_tratamentos          o histórico, só de inclusão: mudar o estado
 *                                   nunca apaga o que veio antes
 *
 * Quem já tem tarefa criada a partir da publicação entra como "tratada", sem
 * autor e sem data, porque esse registro não existiu na época: é só para não
 * cobrar de novo o que já teve providência. Publicação com prazo marcado à mão
 * também. O resto fica "pendente".
 *
 * IDEMPOTENTE. Nenhuma coluna existente muda, nenhum registro é apagado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_175.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_175.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 175' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$coluna = static function (string $nome) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'push_events' AND column_name = ?");
    $st->execute([$nome]);
    return (int) $st->fetchColumn() > 0;
};

$colunas = [
    'tratamento_status'  => "VARCHAR(20) NOT NULL DEFAULT 'pendente'",
    'tratamento_nota'    => 'TEXT NULL',
    'tratamento_user_id' => 'INT NULL',
    'tratamento_nome'    => 'VARCHAR(150) NULL',
    'tratamento_em'      => 'DATETIME NULL',
];
foreach ($colunas as $nome => $def) {
    if ($coluna($nome)) { echo "  push_events.{$nome}: já existe" . PHP_EOL; continue; }
    if ($dryRun) { echo "  push_events.{$nome}: seria criada" . PHP_EOL; continue; }
    $pdo->exec("ALTER TABLE push_events ADD COLUMN {$nome} {$def}");
    echo "  push_events.{$nome}: criada" . PHP_EOL;
}

$temIndice = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'push_events' AND index_name = 'idx_account_tratamento'")->fetchColumn() > 0;
if (!$temIndice && !$dryRun) {
    $pdo->exec('ALTER TABLE push_events ADD INDEX idx_account_tratamento (account_id, tratamento_status)');
    echo '  índice idx_account_tratamento: criado' . PHP_EOL;
}

$temTabela = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'push_event_tratamentos'")->fetchColumn() > 0;
if ($temTabela) {
    echo '  push_event_tratamentos: já existe' . PHP_EOL;
} elseif ($dryRun) {
    echo '  push_event_tratamentos: seria criada' . PHP_EOL;
} else {
    $pdo->exec("CREATE TABLE push_event_tratamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id BIGINT UNSIGNED NOT NULL,
        account_id INT NOT NULL,
        status_anterior VARCHAR(20) NULL,
        status_novo VARCHAR(20) NOT NULL,
        nota TEXT NULL,
        user_id INT NULL,
        user_nome VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_event (event_id),
        KEY idx_account (account_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo '  push_event_tratamentos: criada' . PHP_EOL;
}

if (!$dryRun && $coluna('tratamento_status')) {
    $n = $pdo->exec("UPDATE push_events e
                        SET e.tratamento_status = 'tratada',
                            e.tratamento_nota = 'Já tinha tarefa ou prazo antes do controle de tratamento.'
                      WHERE e.tratamento_status = 'pendente'
                        AND (EXISTS (SELECT 1 FROM tasks t WHERE t.push_event_id = e.id)
                             OR EXISTS (SELECT 1 FROM push_event_user_status s WHERE s.event_id = e.id AND s.com_prazo = 1))");
    echo "  publicações que já tinham providência: {$n}" . PHP_EOL;
}

echo 'Fim.' . PHP_EOL;
