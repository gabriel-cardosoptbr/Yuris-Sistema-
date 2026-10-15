<?php
/**
 * Migration 148: foto diária das instâncias do Evolution (aba Evolution).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido da Inovaize (15/10/2026): uma aba no CRM para saber quanto o Evolution
 * (o servidor de WhatsApp da Inovaize, evolution.inovaize.com) está custando,
 * quantas conexões existem, de quais projetos, e o volume de cada uma.
 *
 * O Evolution só informa o TOTAL acumulado de mensagens, contatos e conversas de
 * cada instância. Para mostrar o ritmo (mensagens por dia), a aba guarda uma foto
 * por instância por dia; a diferença entre dois dias é o volume do dia.
 *
 *   evolution_snapshots   uma linha por conta + instância + dia: situação da
 *                         conexão e os três totais. Gravada ao abrir a aba (e por
 *                         quem chamar App\Projetos\Evolution::gravarFoto); no
 *                         mesmo dia, a foto é atualizada, nunca duplicada.
 *
 * account_id é a conta dona da aba (a Inovaize), não a conta da instância.
 * IDEMPOTENTE. Não altera dado existente.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_148.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_148.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

linha('== Migration 148: evolution_snapshots ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evolution_snapshots'");
$st->execute();
if ((int) $st->fetchColumn() > 0) {
    linha('   evolution_snapshots: ja existe, nada a fazer.');
} else {
    linha('   evolution_snapshots: sera criada.');
    if (!$dryRun) {
        $pdo->exec("CREATE TABLE evolution_snapshots (
            id INT NOT NULL AUTO_INCREMENT,
            account_id INT NOT NULL,
            dia DATE NOT NULL,
            instancia VARCHAR(120) NOT NULL,
            status VARCHAR(20) NOT NULL,
            mensagens INT NOT NULL DEFAULT 0,
            contatos INT NOT NULL DEFAULT 0,
            conversas INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_evo_foto (account_id, instancia, dia),
            KEY idx_evo_dia (account_id, dia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        linha('   evolution_snapshots: criada.');
    }
}
linha();
linha($dryRun ? '   (dry-run) nada aplicado.' : '   pronto.');
