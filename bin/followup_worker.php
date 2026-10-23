<?php
/**
 * Follow-up automático do WhatsApp (Operação › Follow-up). Ver App\FollowUp.
 *
 * Roda um lote e sai. Agendar a cada minuto:
 *   Linux:   * * * * * docker exec yuris_app php /var/www/html/bin/followup_worker.php
 *   Windows: Task Scheduler com C:\xampp\php\php.exe e o caminho deste arquivo, a cada 1 minuto
 *
 * FOLLOWUP_LOG=1 imprime o resumo.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\FollowUp\Envio;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// Trava: o agendador atrasa e sobrepõe. Sem a trava, dois lotes juntos.
if ((int) $pdo->query("SELECT GET_LOCK('followup_worker', 0)")->fetchColumn() !== 1) exit(0);

try {
    $r = Envio::processar();
    if (getenv('FOLLOWUP_LOG') === '1') {
        echo date('c') . " contas={$r['contas']} na_hora={$r['na_hora']} enviadas={$r['enviadas']} falhas={$r['falhas']} puladas={$r['puladas']}" . PHP_EOL;
    }
} catch (\Throwable $e) {
    error_log('[followup_worker] ' . $e->getMessage());
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('followup_worker')");
}
