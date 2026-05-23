<?php
/**
 * /api/agent_settings.php — config do Agente IA por usuário (per-tenant).
 *
 * LGPD P1 (2C.2 + 2C.3) — refatorado:
 *   ANTES: salvava em $_SESSION['agent_settings'] sem CSRF.
 *     • api_key vazava via session hijack
 *     • POST sem CSRF deixava XSS gravar chave atacante
 *     • Configuração perdia-se no logout
 *     • Sem isolamento per-tenant
 *
 *   AGORA:
 *     • Persistido em agent_configs (migration 048)
 *     • UNIQUE (account_id, user_id) — cada user tem sua config no tenant
 *     • api_key cifrada com AES-256-CBC (MFA_ENCRYPTION_KEY)
 *     • GET retorna api_key ofuscada (****1234)
 *     • POST exige CSRF
 *
 * Endpoints:
 *   GET    → retorna config do user atual (api_key MASKED)
 *   POST   → cria/atualiza (api_key cifrada antes de gravar)
 */
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../app/Helpers/TotpHelper.php';   // reusa encryptSecret/decryptSecret
require_once __DIR__ . '/../../app/Helpers/EnvLoader.php';

use App\Models\Database;
use App\Helpers\AccountContext;
use App\Helpers\TotpHelper;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$userId    = $ctx->getUserId();
$accountId = $ctx->getAccountId();

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// ─── Helpers ─────────────────────────────────────────────────────────────────
function _maskApiKey(?string $plain): string
{
    if (!$plain) return '';
    if (strlen($plain) <= 8) return str_repeat('*', max(0, strlen($plain) - 2)) . substr($plain, -2);
    return str_repeat('*', strlen($plain) - 4) . substr($plain, -4);
}

function _loadRow(\PDO $pdo, int $accountId, int $userId): ?array
{
    $st = $pdo->prepare(
        'SELECT * FROM agent_configs WHERE account_id = :a AND user_id = :u LIMIT 1'
    );
    $st->execute(['a' => $accountId, 'u' => $userId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ─── GET ─────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $row = _loadRow($pdo, $accountId, $userId);
    if (!$row) {
        // Sem config → defaults vazios
        echo json_encode([
            'name' => '', 'enabled' => false, 'whatsapp_number' => '',
            'provider' => '', 'api_key' => '', 'api_key_masked' => '', 'prompt' => '',
        ]);
        exit;
    }

    // Decifra api_key SOMENTE pra extrair últimos 4 (masked). Plain nunca volta na resposta.
    $masked = '';
    if (!empty($row['api_key_enc'])) {
        try {
            $plain  = TotpHelper::decryptSecret($row['api_key_enc']);
            $masked = _maskApiKey($plain);
        } catch (\Throwable $e) {
            error_log('[agent_settings] falha ao decifrar api_key: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'name'            => $row['name']            ?? '',
        'enabled'         => (bool)$row['enabled'],
        'whatsapp_number' => $row['whatsapp_number'] ?? '',
        'provider'        => $row['provider']        ?? '',
        'api_key'         => '',         // P1 LGPD: nunca devolve plain
        'api_key_masked'  => $masked,    // só pra UI mostrar "configurada"
        'prompt'          => $row['prompt'] ?? '',
    ]);
    exit;
}

// ─── POST ────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    // P1 LGPD (2C.2): CSRF
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? ($input['_csrf'] ?? null));
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'CSRF inválido']);
        exit;
    }

    $name           = isset($input['name'])            ? trim((string)$input['name'])            : null;
    $enabled        = isset($input['enabled'])         ? (int)((bool)$input['enabled'])          : null;
    $whatsappNumber = isset($input['whatsapp_number']) ? trim((string)$input['whatsapp_number']) : null;
    $provider       = isset($input['provider'])        ? trim((string)$input['provider'])        : null;
    $apiKey         = isset($input['api_key'])         ? trim((string)$input['api_key'])         : null;
    $prompt         = isset($input['prompt'])          ? (string)$input['prompt']                : null;

    // Cifra api_key se foi fornecida NÃO-VAZIA. String vazia ignora (mantém existente).
    // P1 LGPD: ENV pode não ter MFA_ENCRYPTION_KEY — tratar gracefully se acontecer.
    $apiKeyEnc = null;
    $touchApiKey = false;
    if ($apiKey !== null && $apiKey !== '') {
        try {
            $apiKeyEnc   = TotpHelper::encryptSecret($apiKey);
            $touchApiKey = true;
        } catch (\Throwable $e) {
            http_response_code(503);
            echo json_encode([
                'error' => 'MFA_ENCRYPTION_KEY não configurada — api_key não pode ser cifrada com segurança. Defina no .env e tente novamente.'
            ]);
            exit;
        }
    }

    // Upsert: insere ou atualiza por (account_id, user_id)
    $existing = _loadRow($pdo, $accountId, $userId);

    if (!$existing) {
        $st = $pdo->prepare(
            'INSERT INTO agent_configs
              (account_id, user_id, name, enabled, whatsapp_number, provider, api_key_enc, prompt)
             VALUES (:a, :u, :name, :en, :wa, :pr, :key, :prm)'
        );
        $st->execute([
            'a'    => $accountId,
            'u'    => $userId,
            'name' => $name ?? '',
            'en'   => $enabled ?? 0,
            'wa'   => $whatsappNumber,
            'pr'   => $provider,
            'key'  => $apiKeyEnc,
            'prm'  => $prompt,
        ]);
    } else {
        // Constrói UPDATE dinâmico só com campos presentes
        $fields = []; $params = ['a' => $accountId, 'u' => $userId];
        if ($name !== null)           { $fields[] = 'name = :name';                 $params['name'] = $name; }
        if ($enabled !== null)        { $fields[] = 'enabled = :en';                $params['en']   = $enabled; }
        if ($whatsappNumber !== null) { $fields[] = 'whatsapp_number = :wa';        $params['wa']   = $whatsappNumber; }
        if ($provider !== null)       { $fields[] = 'provider = :pr';               $params['pr']   = $provider; }
        if ($touchApiKey)             { $fields[] = 'api_key_enc = :key';           $params['key']  = $apiKeyEnc; }
        if ($prompt !== null)         { $fields[] = 'prompt = :prm';                $params['prm']  = $prompt; }

        if (!empty($fields)) {
            $sql = 'UPDATE agent_configs SET ' . implode(', ', $fields) .
                   ' WHERE account_id = :a AND user_id = :u';
            $pdo->prepare($sql)->execute($params);
        }
    }

    // Retorna config atualizada com api_key SEMPRE masked
    $row = _loadRow($pdo, $accountId, $userId);
    $masked = '';
    if ($row && !empty($row['api_key_enc'])) {
        try {
            $plain  = TotpHelper::decryptSecret($row['api_key_enc']);
            $masked = _maskApiKey($plain);
        } catch (\Throwable $_e) {}
    }
    // LGPD Etapa 4: trilha de auditoria — api_key NUNCA é registrada em claro,
    // apenas flag indicando se houve toque (touchApiKey).
    \App\Models\Account::audit($accountId, 'agent_settings.updated', [
        'user_id'     => $userId,
        'entidade'    => 'agent_config',
        'entidade_id' => $userId,
        'detalhes'    => [
            'name'             => $name,
            'enabled'          => $enabled,
            'whatsapp_number'  => $whatsappNumber,
            'provider'         => $provider,
            'prompt_changed'   => $prompt !== null,
            'api_key_changed'  => $touchApiKey,
            'created'          => !$existing,
        ],
    ]);
    echo json_encode([
        'success' => true,
        'data' => [
            'name'            => $row['name']            ?? '',
            'enabled'         => (bool)($row['enabled'] ?? 0),
            'whatsapp_number' => $row['whatsapp_number'] ?? '',
            'provider'        => $row['provider']        ?? '',
            'api_key'         => '',
            'api_key_masked'  => $masked,
            'prompt'          => $row['prompt'] ?? '',
        ],
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
exit;
