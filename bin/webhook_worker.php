<?php
/**
 * bin/webhook_worker.php — consumidor da fila de webhook_deliveries.
 *
 * Executado por cron a cada minuto (Linux) ou Task Scheduler (Windows).
 * Pega ate N rows com status IN ('pending','retrying') que ja sao elegiveis
 * (scheduled_retry_at NULL ou <= NOW()) e tenta entregar cada uma via
 * WebhookDispatcher::processDelivery().
 *
 * NAO eh um loop infinito — roda 1 batch e sai. O cron volta a invocar.
 *
 * Locking leve: a row eh marcada como 'retrying' com tentativa atual ANTES
 * do POST, evitando duplo pickup por workers concorrentes.
 *
 * Uso (Windows Task Scheduler):
 *   Programa:   C:\xampp\php\php.exe
 *   Argumentos: C:\xampp\htdocs\sistema_vendas\bin\webhook_worker.php
 *   Trigger:    diariamente, repetir a cada 1 minuto, indefinidamente
 *
 * Uso (Linux cron):
 *   * * * * * /usr/bin/php /var/www/sistema_vendas/bin/webhook_worker.php
 *
 * Variaveis de ambiente respeitadas:
 *   WEBHOOK_WORKER_BATCH  — quantas rows por execucao (default 50)
 *   WEBHOOK_WORKER_LOG    — se 1, imprime resumo no stdout (default 0)
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Webhooks\WebhookDispatcher;

$batchSize = (int)($_ENV['WEBHOOK_WORKER_BATCH'] ?? getenv('WEBHOOK_WORKER_BATCH') ?: 50);
if ($batchSize < 1 || $batchSize > 500) $batchSize = 50;
$verbose = ($_ENV['WEBHOOK_WORKER_LOG'] ?? getenv('WEBHOOK_WORKER_LOG')) == '1';

try {
    $pdo = Database::getConnection();

    // Pega rows elegiveis. ORDER BY id pra ser FIFO dentro do mesmo prazo.
    $sel = $pdo->prepare(
        "SELECT * FROM webhook_deliveries
          WHERE status IN ('pending','retrying')
            AND (scheduled_retry_at IS NULL OR scheduled_retry_at <= NOW())
          ORDER BY id ASC
          LIMIT {$batchSize}"
    );
    $sel->execute();
    $rows = $sel->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($rows)) {
        if ($verbose) fwrite(STDOUT, "[webhook_worker] nada a fazer\n");
        exit(0);
    }

    $ok = 0; $fail = 0; $cancel = 0;
    foreach ($rows as $row) {
        // Locking leve: bumpa tentativa + marca processing por status='retrying'
        // (preserva status='pending' se for 1a tentativa, mas tira da janela de pickup)
        $pdo->prepare(
            "UPDATE webhook_deliveries
                SET status='retrying',
                    scheduled_retry_at=NULL,
                    updated_at=NOW()
              WHERE id=? AND status IN ('pending','retrying')"
        )->execute([(int)$row['id']]);

        $before = $row['status'];
        WebhookDispatcher::processDelivery($pdo, $row);

        // Re-le status pos-process
        $after = $pdo->prepare("SELECT status FROM webhook_deliveries WHERE id=?");
        $after->execute([(int)$row['id']]);
        $newStatus = (string)$after->fetchColumn();
        if ($newStatus === 'success')         $ok++;
        elseif ($newStatus === 'failed')      $fail++;
        elseif ($newStatus === 'canceled')    $cancel++;
        // 'retrying' continua na fila pra proxima execucao
    }

    if ($verbose) {
        fwrite(STDOUT, "[webhook_worker] processados=" . count($rows)
            . " ok={$ok} fail={$fail} cancel={$cancel}\n");
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[webhook_worker] FATAL: ' . $e->getMessage() . "\n");
    error_log('[webhook_worker] FATAL: ' . $e->getMessage());
    exit(1);
}
