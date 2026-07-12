<?php
/**
 * whatsapp_health_tick.php — alarme de WEBHOOK SILENCIOSO (Onda 4 / 4D, refinado).
 *
 * Para cada canal 'open' **roteado pro Yuris** (whatsapp_settings.webhook_url aponta pro
 * nosso webhook.php, nao pro n8n/terceiro) cujo last_event_at (cursor 4B) ficou parado
 * por > 30 min **dentro do horario comercial**, registra um evento webhook_silent em
 * ai_agent_events (deduped: no maximo 1 por canal por hora) + uma linha WaLog. E o
 * detector do pior incidente invisivel: Evolution viva, webhook morto -> o bot
 * simplesmente para de responder e ninguem percebe. O card "Saude do WhatsApp" no
 * Master mostra o mesmo ao vivo; este cron deixa o RASTRO (historico de janelas
 * silenciosas) mesmo quando ninguem esta olhando.
 *
 * Refino (2026-07-10) contra os 2 falsos-positivos observados no deploy da 4D:
 *  1) Canal roteado pro n8n (ex.: Bruno Ferreira 2.0, conta 73 -> webhook_url aponta pra
 *     yuris.inovaize.com/.../instances.php, NAO pro nosso webhook.php): o Yuris nunca vai
 *     receber evento desse canal por design, entao NUNCA deve ser candidato a alarme.
 *  2) last_event_at NULL = "nunca teve baseline" (canal novo, ou coluna 107 recem-criada),
 *     nao "ficou silencioso". So alarma quem JA teve pelo menos 1 evento e parou.
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
$BIZ_START  = 8;  // horario comercial (hora local do servidor) — inicio
$BIZ_END    = 20; // horario comercial — fim (exclusivo)
$pdo = Database::getConnection();
$flagged = [];

// Fora do horario comercial (ou fim de semana), nao ha expectativa de fluxo constante
// de mensagens -> silencio e normal, nao sintoma de webhook morto.
$now      = new \DateTime();
$dow      = (int)$now->format('N'); // 1=seg .. 7=dom
$hour     = (int)$now->format('G');
$isBizTime = $dow <= 6 && $hour >= $BIZ_START && $hour < $BIZ_END;

if (!$isBizTime) {
    if (!$isCli) echo json_encode(['ok' => true, 'flagged' => [], 'skipped' => 'fora do horario comercial', 'ts' => date('c')]);
    else echo "webhook_silent: fora do horario comercial, nada checado\n";
    exit;
}

try {
    // Somente canais 'open' cujo webhook_url esta explicitamente configurado para o
    // NOSSO endpoint (evita false-positive do canal espelhado no n8n) e que JA tiveram
    // pelo menos 1 evento (evita false-positive de baseline NULL em canal novo).
    $rows = $pdo->query(
        "SELECT wi.id, wi.account_id, wi.last_event_at
           FROM whatsapp_instances wi
           JOIN whatsapp_settings ws
             ON ws.account_id = wi.account_id
            AND ws.config_key = 'webhook_url'
            AND ws.config_value LIKE '%/api/whatsapp/webhook.php%'
          WHERE wi.status = 'open'
            AND wi.last_event_at IS NOT NULL
            AND wi.last_event_at < (NOW() - INTERVAL {$SILENT_MIN} MINUTE)"
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

    // Retencao (B3 Bloco 2): expurga eventos de telemetria com mais de 30 dias — a tabela
    // ai_agent_events passou a receber tambem as anomalias do webhook, entao nao pode
    // crescer sem limite. LIMIT evita lock longo (ate 5000 por tick, ~cada 15min). Best-effort.
    $pdo->exec("DELETE FROM ai_agent_events WHERE created_at < (NOW() - INTERVAL 30 DAY) LIMIT 5000");
} catch (\Throwable $e) {
    error_log('[whatsapp_health_tick] ' . $e->getMessage());
}

if (!$isCli) echo json_encode(['ok' => true, 'flagged' => $flagged, 'ts' => date('c')]);
else echo 'webhook_silent flagged: ' . (implode(',', $flagged) ?: '(nenhum)') . "\n";
