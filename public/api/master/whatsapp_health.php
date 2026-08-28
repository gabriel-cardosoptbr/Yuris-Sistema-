<?php
/**
 * whatsapp_health.php — Saude do WhatsApp/Agente (Painel Master, Onda 4 / 4D).
 *
 * Agrega o que JA existe (nao reinstrumenta): ai_usage_log (custo/cache/latencia),
 * ai_agent_events (erros), whatsapp_instances (status/last_event_at do cursor 4B),
 * whatsapp_messages e ai_intake_messages (volume 24h) numa visao operacional por canal
 * + um resumo global. Detecta "webhook silencioso" (canal open sem evento ha > 30min).
 *
 * Acesso: super_admin apenas. READ-ONLY. Fail-soft (uma agregacao que falhe nao derruba o resto).
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Core\Database;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') ApiResponse::methodNotAllowed();

$pdo = Database::getConnection();
$SILENT_MIN = 30; // canal 'open' sem evento (last_event_at) ha > 30 min = webhook silencioso

// ── Saude por canal ─────────────────────────────────────────────────────────
$channels = []; $chOpen = 0; $chSilent = 0;
try {
    $sql = "SELECT wi.id, wi.instance_name, wi.status, wi.last_event_at, wi.account_id,
                   a.nome AS account_nome,
                   (SELECT COUNT(*) FROM whatsapp_messages m
                     WHERE m.instance_id = wi.id AND m.created_at > (NOW() - INTERVAL 24 HOUR)) AS msgs_24h,
                   (SELECT COUNT(*) FROM ai_intake_messages im
                       JOIN ai_intake_sessions s ON s.id = im.session_id
                     WHERE s.channel_id = wi.id AND im.origin = 'bot'
                       AND im.created_at > (NOW() - INTERVAL 24 HOUR)) AS bot_24h,
                   (SELECT COUNT(*) FROM ai_agent_events e
                     WHERE e.instance_id = wi.id AND e.level = 'error'
                       AND e.created_at > (NOW() - INTERVAL 24 HOUR)) AS err_24h
              FROM whatsapp_instances wi
              LEFT JOIN accounts a ON a.id = wi.account_id
             ORDER BY (wi.status = 'open') DESC, wi.id";
    foreach ($pdo->query($sql) as $r) {
        $open   = ($r['status'] === 'open');
        $silent = $open && ($r['last_event_at'] === null || strtotime((string)$r['last_event_at']) < time() - $SILENT_MIN * 60);
        if ($open) $chOpen++;
        if ($silent) $chSilent++;
        $channels[] = [
            'account_id'    => (int)$r['account_id'],
            'account_nome'  => $r['account_nome'] ?: ('Conta #' . (int)$r['account_id']),
            'instance_name' => $r['instance_name'],
            'status'        => $r['status'],
            'last_event_at' => $r['last_event_at'],
            'silent'        => $silent,
            'msgs_24h'      => (int)$r['msgs_24h'],
            'bot_24h'       => (int)$r['bot_24h'],
            'err_24h'       => (int)$r['err_24h'],
        ];
    }
} catch (\Throwable $e) { /* fail-soft */ }

// ── Resumo de custo/cache/latencia (ai_usage_log, global) ───────────────────
$u = ['cost_24h' => 0.0, 'cost_30d' => 0.0, 'cache_ratio_24h' => 0.0, 'avg_latency_24h' => 0, 'calls_24h' => 0];
try {
    $r = $pdo->query(
        "SELECT
            COALESCE(SUM(CASE WHEN created_at > (NOW()-INTERVAL 24 HOUR) THEN estimated_cost ELSE 0 END),0) c24,
            COALESCE(SUM(CASE WHEN created_at > (NOW()-INTERVAL 30 DAY)  THEN estimated_cost ELSE 0 END),0) c30,
            COALESCE(SUM(CASE WHEN created_at > (NOW()-INTERVAL 24 HOUR) THEN input_tokens ELSE 0 END),0) in24,
            COALESCE(SUM(CASE WHEN created_at > (NOW()-INTERVAL 24 HOUR) THEN cached_input_tokens ELSE 0 END),0) cin24,
            COALESCE(AVG(CASE WHEN created_at > (NOW()-INTERVAL 24 HOUR) THEN latency_ms END),0) lat24,
            COALESCE(SUM(CASE WHEN created_at > (NOW()-INTERVAL 24 HOUR) THEN 1 ELSE 0 END),0) n24
         FROM ai_usage_log"
    )->fetch(\PDO::FETCH_ASSOC);
    $u['cost_24h']        = (float)$r['c24'];
    $u['cost_30d']        = (float)$r['c30'];
    $u['cache_ratio_24h'] = ((float)$r['in24'] > 0) ? ((float)$r['cin24'] / (float)$r['in24']) : 0.0;
    $u['avg_latency_24h'] = (int)round((float)$r['lat24']);
    $u['calls_24h']       = (int)$r['n24'];
} catch (\Throwable $e) {}

// ── Erros do agente 24h (ai_agent_events) ───────────────────────────────────
$err24 = 0;
try { $err24 = (int)$pdo->query("SELECT COUNT(*) FROM ai_agent_events WHERE level='error' AND created_at > (NOW()-INTERVAL 24 HOUR)")->fetchColumn(); } catch (\Throwable $e) {}

ApiResponse::ok([
    'summary' => [
        'channels_total'  => count($channels),
        'channels_open'   => $chOpen,
        'channels_silent' => $chSilent,
        'cost_24h'        => $u['cost_24h'],
        'cost_30d'        => $u['cost_30d'],
        'cache_ratio_24h' => $u['cache_ratio_24h'],
        'avg_latency_24h' => $u['avg_latency_24h'],
        'calls_24h'       => $u['calls_24h'],
        'errors_24h'      => $err24,
    ],
    'channels'             => $channels,
    'silent_threshold_min' => $SILENT_MIN,
]);
