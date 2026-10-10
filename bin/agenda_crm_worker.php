<?php
/**
 * Agenda do lead (edição CRM): lembretes e mensagens programadas.
 * Ver App\Prospeccao\AgendaDoLead.
 *
 * Roda um lote e sai. Agendar a cada minuto:
 *   Linux:   * * * * * docker exec yuris_app php /var/www/html/bin/agenda_crm_worker.php
 *   Windows: Task Scheduler com C:\xampp\php\php.exe e o caminho deste arquivo, a cada 1 minuto
 *
 * AGENDA_CRM_LOG=1 imprime o resumo.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Prospeccao\AgendaDoLead;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// Trava: o agendador atrasa e sobrepõe. Sem a trava, dois lotes juntos.
if ((int) $pdo->query("SELECT GET_LOCK('agenda_crm_worker', 0)")->fetchColumn() !== 1) exit(0);

try {
    $lembretes = AgendaDoLead::processarLembretes();
    $envio     = AgendaDoLead::processarMensagens();
    if (getenv('AGENDA_CRM_LOG') === '1') {
        echo date('c') . " lembretes={$lembretes} enviadas={$envio['enviadas']} falhas={$envio['falhas']}"
            . " canceladas={$envio['canceladas']} interrompidas={$envio['interrompidas']}" . PHP_EOL;
    }
} catch (\Throwable $e) {
    error_log('[agenda_crm_worker] ' . $e->getMessage());
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('agenda_crm_worker')");
}
