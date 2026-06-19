<?php
/**
 * /api/master/ai_openai.php — Painel Master: Security Key GLOBAL da OpenAI.
 *
 * A chave vale para TODAS as instancias do agente (os escritorios nao informam chave).
 * Fica cifrada (Crypto/APP_ENCRYPTION_KEY) em app_settings; nunca volta em claro.
 *
 * Acesso: super_admin em sessao master_mode. Escrita exige nivel != viewer. CSRF nos POST.
 *
 *   GET                       -> { has_key, masked }
 *   POST action=save  {api_key}            -> salva a chave (cifrada)
 *   POST action=test  {api_key?}           -> valida chamando a OpenAI (/v1/models)
 *   POST action=clear                      -> remove a chave global
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';
require_once __DIR__ . '/../../../app/Helpers/AiSettings.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;
use App\Helpers\AiSettings;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$pdo    = \App\Models\Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

/** Valida a chave chamando GET /v1/models (barato, sem tokens). */
function _openaiValidate(string $key): array {
    if ($key === '') return ['valid' => false, 'detail' => 'chave vazia'];
    $ch = curl_init('https://api.openai.com/v1/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['valid' => false, 'detail' => 'falha de rede'];
    if ($code === 200) {
        $j = json_decode((string)$body, true);
        $n = is_array($j['data'] ?? null) ? count($j['data']) : 0;
        return ['valid' => true, 'detail' => $n . ' modelos disponiveis'];
    }
    if ($code === 401) return ['valid' => false, 'detail' => 'chave rejeitada (401)'];
    return ['valid' => false, 'detail' => 'HTTP ' . $code];
}

// ─── GET ───────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    ApiResponse::ok(AiSettings::openAiStatus($pdo));
}

// ─── POST ──────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? ($in['_csrf'] ?? null));
    if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
    }
    if ($ctx->getSuperAdminLevel() === 'viewer') {
        ApiResponse::forbidden('Seu nível de acesso (viewer) não permite alterar a chave.');
    }

    $action = $in['action'] ?? 'save';
    $apiKey = trim((string)($in['api_key'] ?? ''));

    if ($action === 'test') {
        // testa a chave informada (antes de salvar) ou, se vazia, a chave ja salva.
        $key = $apiKey !== '' ? $apiKey : (AiSettings::openAiKey($pdo) ?? '');
        $res = _openaiValidate($key);
        MasterAudit::log('ai_openai.test', 'app_settings', null, 'Validou a Security Key da OpenAI', ['valid' => $res['valid']]);
        ApiResponse::ok($res);
    }

    if ($action === 'clear') {
        AiSettings::clearOpenAiKey($pdo);
        MasterAudit::log('ai_openai.clear', 'app_settings', null, 'Removeu a Security Key global da OpenAI', []);
        ApiResponse::ok(['cleared' => true] + AiSettings::openAiStatus($pdo) + ['message' => 'Chave removida.']);
    }

    if ($action === 'save') {
        if ($apiKey === '' || strlen($apiKey) < 20) {
            ApiResponse::badRequest('Informe uma Security Key válida da OpenAI (sk-...).');
        }
        try {
            AiSettings::saveOpenAiKey($pdo, $apiKey);
        } catch (\Throwable $e) {
            error_log('[master/ai_openai] save falhou: ' . $e->getMessage());
            http_response_code(503);
            echo json_encode(['ok' => false, 'error' => 'APP_ENCRYPTION_KEY não configurada — chave não pode ser cifrada.']);
            exit;
        }
        MasterAudit::log('ai_openai.save', 'app_settings', null, 'Atualizou a Security Key global da OpenAI', []);
        ApiResponse::ok(AiSettings::openAiStatus($pdo) + ['message' => 'Security Key salva e cifrada.']);
    }

    ApiResponse::badRequest('Ação desconhecida (use save, test ou clear).');
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
