<?php
/**
 * webhook_health.php — Saude do WEBHOOK INBOUND (Painel Master, B3 Bloco 2).
 *
 * Agrega ai_agent_events (codes webhook_*: reject/unresolved/no_auth/bad_payload/
 * config_error/error/compat/silent) + whatsapp_instances (status/last_event_at) numa
 * visao de seguranca/saude do webhook de ENTRADA (Evolution -> Yuris): status por canal,
 * anomalias por tipo nas ultimas 24h e as ocorrencias recentes. NAO reinstrumenta nada;
 * so le a telemetria que o webhook.php grava. Read-only, fail-soft.
 *
 * Acesso: super_admin em sessao master_mode. GET.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Models\Database;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) ApiResponse::forbidden('Acesso somente pelo Painel Master.');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') ApiResponse::methodNotAllowed();

$pdo = Database::getConnection();
$SILENT_MIN = 30; // canal 'open' sem evento (last_event_at) ha > 30 min = webhook silencioso

// ── Status por canal (foco no webhook de entrada) ───────────────────────────
$channels = []; $chOpen = 0; $chSilent = 0;
try {
    $sql = "SELECT wi.id, wi.instance_name, wi.status, wi.last_event_at, wi.account_id,
                   a.nome AS account_nome, ws.config_value AS webhook_url,
                   (SELECT COUNT(*) FROM whatsapp_messages m
                     WHERE m.instance_id = wi.id AND m.created_at > (NOW()-INTERVAL 24 HOUR)) AS msgs_24h,
                   (SELECT COUNT(*) FROM ai_agent_events e
                     WHERE e.instance_id = wi.id AND e.code LIKE 'webhook\\_%'
                       AND e.created_at > (NOW()-INTERVAL 24 HOUR)) AS anomalies_24h,
                   (SELECT COUNT(*) FROM ai_agent_events e
                     WHERE e.instance_id = wi.id AND e.code = 'webhook_reject'
                       AND e.created_at > (NOW()-INTERVAL 24 HOUR)) AS rejects_24h
              FROM whatsapp_instances wi
              LEFT JOIN accounts a ON a.id = wi.account_id
              LEFT JOIN whatsapp_settings ws ON ws.account_id = wi.account_id AND ws.config_key = 'webhook_url'
             ORDER BY (wi.status='open') DESC, wi.id";
    foreach ($pdo->query($sql) as $r) {
        $open = ($r['status'] === 'open');
        // "Silencioso" espelha o alarme do cron (whatsapp_health_tick, refino 2026-07-10):
        // so conta canal (a) roteado pro NOSSO webhook.php (nao n8n/terceiro, que nunca
        // gera evento aqui por design), (b) COM baseline (last_event_at != NULL — canal
        // recem-conectado NAO e "silencioso") e (c) parado ha > SILENT_MIN. Evita os 2
        // falsos-positivos: canal externo (ex.: Bruno/inovaize) e canal novo.
        $routedToYuris = $r['webhook_url'] !== null && stripos((string)$r['webhook_url'], '/api/whatsapp/webhook.php') !== false;
        $silent = $open && $routedToYuris && $r['last_event_at'] !== null
                  && strtotime((string)$r['last_event_at']) < time() - $SILENT_MIN * 60;
        if ($open)   $chOpen++;
        if ($silent) $chSilent++;
        $channels[] = [
            'account_id'      => (int)$r['account_id'],
            'account_nome'    => $r['account_nome'] ?: ('Conta #' . (int)$r['account_id']),
            'instance_name'   => $r['instance_name'],
            'status'          => $r['status'],
            'last_event_at'   => $r['last_event_at'],
            'silent'          => $silent,
            'routed_to_yuris' => $routedToYuris,
            'msgs_24h'        => (int)$r['msgs_24h'],
            'anomalies_24h'   => (int)$r['anomalies_24h'],
            'rejects_24h'     => (int)$r['rejects_24h'],
        ];
    }
} catch (\Throwable $e) { /* fail-soft */ }

// ── Ocorrencias recentes (ultimas 60) ───────────────────────────────────────
$recent = [];
try {
    foreach ($pdo->query(
        "SELECT e.code, e.level, e.account_id, e.instance_id, e.detail, e.created_at, a.nome AS account_nome
           FROM ai_agent_events e
           LEFT JOIN accounts a ON a.id = e.account_id
          WHERE e.code LIKE 'webhook\\_%'
          ORDER BY e.id DESC LIMIT 60"
    ) as $r) {
        $recent[] = [
            'code'         => $r['code'],
            'level'        => $r['level'],
            'account_id'   => $r['account_id'] !== null ? (int)$r['account_id'] : null,
            'account_nome' => $r['account_nome'],
            'instance_id'  => $r['instance_id'] !== null ? (int)$r['instance_id'] : null,
            'detail'       => $r['detail'] ? (json_decode((string)$r['detail'], true) ?: null) : null,
            'created_at'   => $r['created_at'],
        ];
    }
} catch (\Throwable $e) {}

// ── Resumo (contadores 24h por tipo) ────────────────────────────────────────
$totals = ['anomalies_24h' => 0, 'rejects_24h' => 0, 'unresolved_24h' => 0, 'errors_24h' => 0, 'compat_24h' => 0, 'silent_24h' => 0];
try {
    $r = $pdo->query(
        "SELECT
            COALESCE(COUNT(*),0) tot,
            COALESCE(SUM(code='webhook_reject'),0) rej,
            COALESCE(SUM(code='webhook_unresolved'),0) unr,
            COALESCE(SUM(code='webhook_error'),0) err,
            COALESCE(SUM(code='webhook_compat'),0) cmp,
            COALESCE(SUM(code='webhook_silent'),0) sil
          FROM ai_agent_events
         WHERE code LIKE 'webhook\\_%' AND created_at > (NOW()-INTERVAL 24 HOUR)"
    )->fetch(\PDO::FETCH_ASSOC);
    $totals = [
        'anomalies_24h'  => (int)$r['tot'],
        'rejects_24h'    => (int)$r['rej'],
        'unresolved_24h' => (int)$r['unr'],
        'errors_24h'     => (int)$r['err'],
        'compat_24h'     => (int)$r['cmp'],
        'silent_24h'     => (int)$r['sil'],
    ];
} catch (\Throwable $e) {}

ApiResponse::ok([
    'summary' => array_merge([
        'channels_total'  => count($channels),
        'channels_open'   => $chOpen,
        'channels_silent' => $chSilent,
    ], $totals),
    'channels'             => $channels,
    'recent'               => $recent,
    'silent_threshold_min' => $SILENT_MIN,
]);
