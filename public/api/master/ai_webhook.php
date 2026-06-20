<?php
/**
 * /api/master/ai_webhook.php — Painel Master: REAPONTAR o webhook da Evolution de
 * UM canal para a URL canônica do Yuris, com 1 clique.
 *
 * Usado quando o webhook de uma instância está indo para um fluxo externo (ex.: n8n)
 * e o super-admin quer trazer as mensagens daquele número de volta para o Yuris.
 *
 * Acesso: super_admin em sessao master_mode; escrita exige nivel != viewer + CSRF. Auditado.
 *
 *   POST { instance_id, csrf_token } -> { ok, data:{ instance_id, dest:'yuris', url } }
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Services/EvolutionApiService.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) ApiResponse::forbidden('Acesso somente pelo Painel Master.');

$pdo    = \App\Models\Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// URL canônica do webhook do Yuris (fallback quando não há hook global configurado).
const HOOK_DEFAULT = 'https://yuris.com.br/api/whatsapp/webhook.php';

// Eventos que a Evolution deve disparar. NÃO incluir 'GROUPS_UPDATE' — esta versão
// da Evolution rejeita esse nome com HTTP 400.
const REPOINT_EVENTS = [
    'MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'SEND_MESSAGE',
    'CONTACTS_UPSERT', 'CONTACTS_UPDATE',
    'CHATS_UPSERT', 'CONNECTION_UPDATE',
    'GROUPS_UPSERT', 'GROUP_PARTICIPANTS_UPDATE',
];

if ($method === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? ($in['_csrf'] ?? null));
    if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
    }
    if ($ctx->getSuperAdminLevel() === 'viewer') ApiResponse::forbidden('Seu nível (viewer) não permite reapontar o webhook.');

    // 1) Carrega o canal (sem escopo — Master vê tudo).
    $iid = (int)($in['instance_id'] ?? 0);
    if ($iid <= 0) ApiResponse::badRequest('instance_id obrigatório.');
    $wi   = new \WhatsAppInstance();
    $inst = $wi->find($iid);
    if (!$inst) ApiResponse::badRequest('Instância não encontrada.');

    // 2) Config Evolution do tenant dono do canal.
    $aid = (int)$inst['account_id'];
    $cfg = $wi->getSettings($aid);
    $baseUrl  = (string)($cfg['evolution_base_url'] ?? '');
    $apiKey   = (string)($cfg['evolution_api_key']  ?? '');
    $instName = (string)($cfg['evolution_instance'] ?? '');
    if ($baseUrl === '' || $apiKey === '' || $instName === '') {
        ApiResponse::badRequest('Conta sem configuração Evolution; conecte a instância antes.');
    }

    // 3) Hook base: URL global em texto (app_settings) ou o default do Yuris.
    $st = $pdo->prepare("SELECT config_value FROM app_settings WHERE config_key = 'evolution_global_webhook_url' LIMIT 1");
    $st->execute();
    $hook = (string)($st->fetchColumn() ?: '');
    if ($hook === '') $hook = HOOK_DEFAULT;

    // 4) URL final: anexa ?token=<apikey do tenant> p/ o webhook.php identificar o tenant.
    $urlFinal = $hook . (strpos($hook, '?') === false ? '?' : '&') . 'token=' . urlencode($apiKey);

    // 5) Reaponta e verifica.
    $evo = new \EvolutionApiService($cfg);
    $setResp = $evo->setWebhook($instName, $urlFinal, REPOINT_EVENTS);

    $setHttp = (int)($setResp['_http'] ?? 0);
    if ($setHttp >= 400) {
        $msg = (string)($setResp['message'] ?? $setResp['_error'] ?? $setResp['error'] ?? 'Evolution recusou a alteração do webhook.');
        if (is_array($setResp['message'] ?? null)) $msg = json_encode($setResp['message'], JSON_UNESCAPED_UNICODE);
        ApiResponse::badRequest('Evolution: ' . mb_substr($msg, 0, 200));
    }

    $check    = $evo->getWebhook($instName);
    $checkUrl = (string)($check['url'] ?? $check['webhook']['url'] ?? '');
    $low      = strtolower($checkUrl);
    if (strpos($low, 'yuris') === false || strpos($low, 'webhook') === false) {
        $msg = (string)($check['message'] ?? $check['_error'] ?? 'não foi possível confirmar o destino do webhook após a alteração.');
        if (is_array($check['message'] ?? null)) $msg = json_encode($check['message'], JSON_UNESCAPED_UNICODE);
        ApiResponse::badRequest('Evolution: ' . mb_substr($msg, 0, 200));
    }

    // 6) Persiste a URL limpa (sem token) e audita.
    $wi->saveSetting($aid, 'webhook_url', $hook);
    MasterAudit::log('ai_webhook.repoint', 'whatsapp_instance', $iid,
        'Apontou webhook do canal ' . ($inst['instance_name'] ?? '') . ' pro Yuris',
        ['account_id' => $aid]);

    ApiResponse::ok([
        'instance_id' => $iid,
        'dest'        => 'yuris',
        'url'         => preg_replace('/((?:token|apikey)=)[^&]+/i', '$1***', $urlFinal),
    ]);
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
