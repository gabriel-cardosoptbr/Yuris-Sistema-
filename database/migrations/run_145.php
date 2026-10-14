<?php
/**
 * Migration 145: liga e desliga a Vitória nos números de TREINO por comando.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do dono do produto em 14/10/2026: número de treino passava por cima do
 * "Assumir conversa", então a Vitória entrava na conversa de verdade dele com o
 * número Fleeti Flow (alguém do time escrevendo à mão). Agora a Vitória fica
 * DESLIGADA nos números de treino e só liga quando ele manda "#ativar" (e
 * desliga com "#desativar"). Ver SdrFleetiflow::comandoDeTreino.
 *
 *   sdr_treino_estado  uma linha por número de treino (DDD + 8 últimos dígitos,
 *                      a mesma chave do n8n): ligado ou não, e quando mudou
 *
 * IDEMPOTENTE. Não altera dado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_145.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_145.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$st->execute(['sdr_treino_estado']);

linha('Migration 145' . ($dryRun ? ' (dry-run)' : ''));
if ((int) $st->fetchColumn() > 0) {
    linha('sdr_treino_estado: já existe');
} else {
    linha('sdr_treino_estado: criar');
    if (!$dryRun) {
        $pdo->exec("
            CREATE TABLE sdr_treino_estado (
              chave_fone VARCHAR(20) NOT NULL,
              ativo      TINYINT(1) NOT NULL DEFAULT 0,
              mudado_em  DATETIME NOT NULL,
              PRIMARY KEY (chave_fone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        linha('sdr_treino_estado: criada');
    }
}
linha('ok');
