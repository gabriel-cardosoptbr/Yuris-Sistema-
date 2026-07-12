<?php
/**
 * crachas.php — Provisionamento do CRACHA (2o fator do webhook) por conta (Painel Master).
 *
 * Mostra o ciclo de vida AUTOMATICO do cracha, pra o super-admin ter ciencia do que foi
 * feito / se executou / se deu problema, sem nada manual:
 *   1) TOKEN gerado no provisionamento (WhatsAppProvisioningService -> webhook_token_autogen)
 *   2) EVOLUTION mandando (Fase B automatica via blindagem no setWebhook do provisionamento)
 *   3) ESTRITO ligado sozinho pelo reconciliador do whatsapp_health_tick, apos prova de que
 *      o cracha chega limpo (webhook_strict_auto_enabled)
 *
 * So LE a telemetria (ai_agent_events codes webhook_*) + whatsapp_settings/instances. NAO
 * expoe o valor do token. Read-only, fail-soft. Acesso: super_admin em master_mode. GET.
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

// ── Status do cracha por canal ──────────────────────────────────────────────
$channels = [];
$sum = ['total' => 0, 'estrito' => 0, 'tolerante' => 0, 'aguardando' => 0, 'sem_cracha' => 0, 'externo' => 0];
try {
    $sql = "SELECT wi.id, wi.instance_name, wi.status, wi.last_event_at, wi.account_id,
                   a.nome AS account_nome, ws.config_value AS webhook_url,
                   (SELECT (st.config_value IS NOT NULL AND st.config_value <> '')
                      FROM whatsapp_settings st WHERE st.account_id = wi.account_id AND st.config_key = 'webhook_token' LIMIT 1) AS has_token,
                   (SELECT ss.config_value FROM whatsapp_settings ss
                      WHERE ss.account_id = wi.account_id AND ss.config_key = 'webhook_token_strict' LIMIT 1) AS strict_raw,
                   (SELECT COUNT(*) FROM ai_agent_events e WHERE e.account_id = wi.account_id
                      AND e.code IN ('webhook_compat','webhook_strict_missing') AND e.created_at > (NOW()-INTERVAL 24 HOUR)) AS pend_24h,
                   (SELECT COUNT(*) FROM ai_agent_events e WHERE e.account_id = wi.account_id
                      AND e.code = 'webhook_reject' AND e.created_at > (NOW()-INTERVAL 24 HOUR)) AS reject_24h,
                   (SELECT COUNT(*) FROM ai_agent_events e WHERE e.account_id = wi.account_id
                      AND e.code = 'cracha_ok' AND e.created_at > (NOW()-INTERVAL 24 HOUR)) AS ok_24h,
                   (SELECT MAX(e.created_at) FROM ai_agent_events e WHERE e.account_id = wi.account_id AND e.code = 'webhook_token_autogen') AS token_gen_at,
                   (SELECT MAX(e.created_at) FROM ai_agent_events e WHERE e.account_id = wi.account_id AND e.code IN ('webhook_strict_auto_enabled','webhook_strict_enabled')) AS strict_at
              FROM whatsapp_instances wi
              LEFT JOIN accounts a ON a.id = wi.account_id
              LEFT JOIN whatsapp_settings ws ON ws.account_id = wi.account_id AND ws.config_key = 'webhook_url'
             ORDER BY (wi.status='open') DESC, wi.id";
    foreach ($pdo->query($sql) as $r) {
        $routed  = $r['webhook_url'] !== null && stripos((string)$r['webhook_url'], '/api/whatsapp/webhook.php') !== false;
        $hasTok  = (bool)$r['has_token'];
        $strict  = ($r['strict_raw'] === '1');
        $pend    = (int)$r['pend_24h'];
        $ok      = (int)$r['ok_24h'];   // cracha_ok em 24h = prova de que a Evolution manda o cracha
        // Etapa do ciclo do cracha (o que a UI mostra como "status"). ORDEM importa: um canal
        // ESTRITO e sempre 'estrito', mesmo com anomalia recente (a anomalia vira o alerta ⚠,
        // nao rebaixa o stage). 'tolerante' exige prova positiva (cracha_ok); sem ela, o token
        // esta setado mas a Evolution ainda nao confirma o envio -> 'aguardando' (Fase B).
        if (!$routed)               $stage = 'externo';        // n8n/terceiro: cracha nao se aplica
        elseif (!$hasTok)           $stage = 'sem_cracha';     // ainda nao provisionado
        elseif ($strict)            $stage = 'estrito';        // 100%: 2o fator obrigatorio
        elseif ($ok > 0)            $stage = 'tolerante';      // cracha chegando (provado); estrito liga sozinho
        else                        $stage = 'aguardando';     // token setado mas sem prova de envio (Fase B)
        $sum['total']++; $sum[$stage] = ($sum[$stage] ?? 0) + 1;
        $channels[] = [
            'instance_id'    => (int)$r['id'],
            'account_id'     => (int)$r['account_id'],
            'account_nome'   => $r['account_nome'] ?: ('Conta #' . (int)$r['account_id']),
            'instance_name'  => $r['instance_name'],
            'status'         => $r['status'],
            'stage'          => $stage,
            'routed_to_yuris'=> $routed,
            'has_token'      => $hasTok,
            'strict'         => $strict,
            'pend_24h'       => $pend,
            'reject_24h'     => (int)$r['reject_24h'],
            'last_event_at'  => $r['last_event_at'],
            'token_gen_at'   => $r['token_gen_at'],
            'strict_at'      => $r['strict_at'],
        ];
    }
} catch (\Throwable $e) { /* fail-soft */ }

// ── Log do ciclo de vida (o que foi feito / executou / deu problema) ────────
$events = [];
try {
    foreach ($pdo->query(
        "SELECT e.code, e.level, e.account_id, e.detail, e.created_at, a.nome AS account_nome
           FROM ai_agent_events e
           LEFT JOIN accounts a ON a.id = e.account_id
          WHERE e.code IN ('webhook_token_autogen','webhook_strict_auto_enabled','webhook_strict_enabled',
                           'webhook_compat','webhook_strict_missing','webhook_reject','webhook_config_error')
          ORDER BY e.id DESC LIMIT 60"
    ) as $r) {
        $events[] = [
            'code'         => $r['code'],
            'level'        => $r['level'],
            'account_id'   => $r['account_id'] !== null ? (int)$r['account_id'] : null,
            'account_nome' => $r['account_nome'],
            'detail'       => $r['detail'] ? (json_decode((string)$r['detail'], true) ?: null) : null,
            'created_at'   => $r['created_at'],
        ];
    }
} catch (\Throwable $e) {}

ApiResponse::ok([
    'summary'  => $sum,
    'channels' => $channels,
    'events'   => $events,
]);
