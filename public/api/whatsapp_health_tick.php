<?php
/**
 * whatsapp_health_tick.php — alarme de WEBHOOK SILENCIOSO (Onda 4 / 4D).
 *
 * Para cada canal 'open' cujo last_event_at (cursor 4B) ficou parado por > 30 min,
 * registra um evento webhook_silent em ai_agent_events (deduped: no maximo 1 por canal
 * por hora) + uma linha WaLog. E o detector do pior incidente invisivel: Evolution viva,
 * webhook morto -> o bot simplesmente para de responder e ninguem percebe. O card
 * "Saude do WhatsApp" no Master mostra o mesmo ao vivo; este cron deixa o RASTRO
 * (historico de janelas silenciosas) mesmo quando ninguem esta olhando.
 *
 * Protegido por CRON_TOKEN (CLI e trusted, mesmo padrao do lgpd_retention_tick).
 * Rodar a cada ~15 min. GET /api/whatsapp_health_tick.php?token=<CRON_TOKEN>  ou CLI.
 */
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Helpers/EnvLoader.php';
require_once __DIR__ . '/../../app/Helpers/WaLog.php';
require_once __DIR__ . '/../../app/Services/AiIntake/AgentEvent.php';

use App\Models\Database;
use App\Helpers\EnvLoader;
use App\Helpers\WaLog;
use App\Services\AiIntake\AgentEvent;

EnvLoader::load();
$isCli    = (PHP_SAPI === 'cli');
$envToken = EnvLoader::get('CRON_TOKEN', '');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$envToken || $envToken === 'yuris_cron_token_change_me') {
        http_response_code(503); echo json_encode(['ok' => false, 'error' => 'CRON_TOKEN não configurado']); exit;
    }
    if (($_GET['token'] ?? '') !== $envToken) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit;
    }
}

$SILENT_MIN = 30; // canal open sem evento ha > 30min = silencioso
$DEDUP_MIN  = 55; // nao alerta o mesmo canal mais de 1x por ~hora
$pdo = Database::getConnection();
$flagged = [];

try {
    $rows = $pdo->query(
        "SELECT id, account_id, last_event_at FROM whatsapp_instances
          WHERE status = 'open'
            AND (last_event_at IS NULL OR last_event_at < (NOW() - INTERVAL {$SILENT_MIN} MINUTE))"
    )->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $inst = (int)$r['id'];
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM ai_agent_events
              WHERE code = 'webhook_silent' AND instance_id = ?
                AND created_at > (NOW() - INTERVAL {$DEDUP_MIN} MINUTE)"
        );
        $st->execute([$inst]);
        if ((int)$st->fetchColumn() > 0) continue; // ja alertado nesta janela

        AgentEvent::log($pdo, 'webhook_silent', ['last_event_at' => (string)$r['last_event_at']], 'error', (int)$r['account_id'], null, $inst);
        WaLog::line('webhook_silent', ['instance' => $inst, 'last' => (string)$r['last_event_at']], 'error');
        $flagged[] = $inst;
    }
} catch (\Throwable $e) {
    error_log('[whatsapp_health_tick] ' . $e->getMessage());
}

if (!$isCli) echo json_encode(['ok' => true, 'flagged' => $flagged, 'ts' => date('c')]);
else echo 'webhook_silent flagged: ' . (implode(',', $flagged) ?: '(nenhum)') . "\n";
