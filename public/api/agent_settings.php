<?php
/**
 * /api/agent_settings.php — config do Agente IA POR CANAL (whatsapp_instance_id).
 *
 * CORREÇÃO OBRIGATÓRIA (integração com WhatsApp): o agente agora REFERENCIA uma
 * instância WhatsApp existente (fonte única da verdade), em vez de guardar um
 * número solto. A gestão do canal (conectar/QR/reconectar) continua só em
 * Comunicação -> Chat WhatsApp. Esta tela só vincula o canal e define o
 * comportamento do LLM.
 *
 * Modelo (migration 081):
 *   agent_configs é chaveado por UNIQUE(whatsapp_instance_id) = 1 agente por canal.
 *   Salva: account_id (dono do canal), branch_id (filial quando aplicável),
 *   whatsapp_instance_id, name, enabled, updated_by + provider/api_key_enc/prompt
 *   (cérebro do LLM). NÃO duplica número/status/url/apikey/webhook (vêm por join).
 *
 * Segurança:
 *   - owner/admin apenas (configurar o canal do agente é ação de gestão)
 *   - whatsapp_instance_id precisa pertencer ao escopo acessível (matriz/filial)
 *   - enabled=1 só é aceito se o canal estiver conectado (status='open')
 *   - api_key cifrada com App\Helpers\Crypto (AES-256-GCM); GET devolve só masked
 *   - POST exige CSRF
 *
 * Endpoints:
 *   GET ?instance_id=ID → config do canal (ou defaults vazios) + info read-only do canal
 *   GET                 → defaults vazios (nenhum canal selecionado)
 *   POST                → upsert por whatsapp_instance_id
 */
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../app/Helpers/Crypto.php';        // cifragem padrão (GCM / APP_ENCRYPTION_KEY)
require_once __DIR__ . '/../../app/Helpers/TotpHelper.php';    // só p/ ler configs LEGADAS (CBC / MFA_ENCRYPTION_KEY)
require_once __DIR__ . '/../../app/Helpers/EnvLoader.php';

use App\Models\Database;
use App\Helpers\AccountContext;
use App\Helpers\Crypto;
use App\Helpers\TotpHelper;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx       = AccountContext::fromSession();
$userId    = $ctx->getUserId();
$accountId = $ctx->getAccountId();

// Configurar o agente/canal é ação de gestão.
if (!$ctx->isOwnerOrAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Apenas owner/admin pode configurar o agente']);
    exit;
}

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// ─── Helpers ─────────────────────────────────────────────────────────────────
function _maskApiKey(?string $plain): string
{
    if (!$plain) return '';
    if (strlen($plain) <= 8) return str_repeat('*', max(0, strlen($plain) - 2)) . substr($plain, -2);
    return str_repeat('*', strlen($plain) - 4) . substr($plain, -4);
}

function _decryptApiKey(?string $enc): ?string
{
    if ($enc === null || $enc === '') return null;
    try {
        return Crypto::decrypt($enc);
    } catch (\Throwable $_) {
        try {
            return TotpHelper::decryptSecret($enc);
        } catch (\Throwable $_e) {
            error_log('[agent_settings] api_key indecifrável (nem GCM nem CBC legado)');
            return null;
        }
    }
}

/** account_ids cujas instâncias a sessão pode vincular: acessíveis + matriz (p/ filial). */
function _instanceScope(AccountContext $ctx): array
{
    $scope = $ctx->getAccessibleAccountIds();
    $matrizId = $ctx->getPipelineAccountId();
    if ($matrizId > 0 && !in_array($matrizId, $scope, true)) $scope[] = $matrizId;
    return array_values(array_unique(array_filter(array_map('intval', $scope), fn($v) => $v > 0)));
}

/** Retorna a instância SE pertencer ao escopo da sessão; senão null. */
function _resolveInstance(\PDO $pdo, AccountContext $ctx, int $instanceId): ?array
{
    if ($instanceId <= 0) return null;
    $scope = _instanceScope($ctx);
    if (empty($scope)) return null;
    $ph = []; $params = ['id' => $instanceId];
    foreach ($scope as $i => $aid) { $k = "s{$i}"; $ph[] = ":{$k}"; $params[$k] = $aid; }
    $st = $pdo->prepare(
        "SELECT i.id, i.account_id, i.instance_name, i.display_name, i.phone, i.status,
                a.nome AS account_nome, a.tipo AS account_tipo
           FROM whatsapp_instances i
           INNER JOIN accounts a ON a.id = i.account_id
          WHERE i.id = :id AND i.account_id IN (" . implode(',', $ph) . ")
          LIMIT 1"
    );
    $st->execute($params);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Config do agente vinculada a uma instância. */
function _loadConfigByInstance(\PDO $pdo, int $instanceId): ?array
{
    $st = $pdo->prepare('SELECT * FROM agent_configs WHERE whatsapp_instance_id = :iid LIMIT 1');
    $st->execute(['iid' => $instanceId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Monta o payload de resposta (config + info read-only do canal). */
function _buildPayload(?array $cfg, ?array $inst): array
{
    $masked = '';
    if ($cfg && !empty($cfg['api_key_enc'])) {
        $plain = _decryptApiKey($cfg['api_key_enc']);
        if ($plain !== null) $masked = _maskApiKey($plain);
    }
    return [
        'name'            => $cfg['name']     ?? '',
        'enabled'         => (bool)($cfg['enabled'] ?? 0),
        'provider'        => $cfg['provider'] ?? '',
        'api_key'         => '',                 // nunca devolve plain
        'api_key_masked'  => $masked,
        'prompt'          => $cfg['prompt']   ?? '',
        // Info read-only do canal (vem por relacionamento; nunca duplicada/credencial)
        'channel'         => $inst ? [
            'instance_id'   => (int)$inst['id'],
            'instance_name' => $inst['instance_name'],
            'display_name'  => $inst['display_name'] ?: $inst['instance_name'],
            'phone'         => $inst['phone'],
            'status'        => $inst['status'] ?: 'close',
            'connected'     => ($inst['status'] === 'open'),
            'account_nome'  => $inst['account_nome'],
            'account_tipo'  => $inst['account_tipo'],
        ] : null,
    ];
}

// ─── GET ─────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $instanceId = (int)($_GET['instance_id'] ?? 0);
    if ($instanceId <= 0) {
        // Nenhum canal selecionado → defaults vazios.
        echo json_encode(_buildPayload(null, null));
        exit;
    }
    $inst = _resolveInstance($pdo, $ctx, $instanceId);
    if (!$inst) {
        http_response_code(403);
        echo json_encode(['error' => 'Canal não encontrado ou fora do seu acesso']);
        exit;
    }
    $cfg = _loadConfigByInstance($pdo, $instanceId);
    echo json_encode(_buildPayload($cfg, $inst));
    exit;
}

// ─── POST ────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? ($input['_csrf'] ?? null));
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'CSRF inválido']);
        exit;
    }

    $instanceId = (int)($input['whatsapp_instance_id'] ?? 0);
    if ($instanceId <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'Selecione um canal WhatsApp (whatsapp_instance_id) para o agente.']);
        exit;
    }
    $inst = _resolveInstance($pdo, $ctx, $instanceId);
    if (!$inst) {
        http_response_code(403);
        echo json_encode(['error' => 'Canal não encontrado ou fora do seu acesso']);
        exit;
    }

    $name     = isset($input['name'])     ? trim((string)$input['name'])     : null;
    $enabled  = isset($input['enabled'])  ? (int)((bool)$input['enabled'])   : null;
    $provider = isset($input['provider']) ? trim((string)$input['provider']) : null;
    $apiKey   = isset($input['api_key'])  ? trim((string)$input['api_key'])  : null;
    $prompt   = isset($input['prompt'])   ? (string)$input['prompt']         : null;

    // Gate: não dá pra ATIVAR o agente com o canal desconectado.
    if ($enabled === 1 && ($inst['status'] ?? 'close') !== 'open') {
        http_response_code(409);
        echo json_encode([
            'error' => 'WhatsApp desconectado: reconecte o canal em Comunicação -> Chat WhatsApp antes de ativar o agente.',
            'code'  => 'CHANNEL_DISCONNECTED',
        ]);
        exit;
    }

    // Cifra api_key se veio NÃO-VAZIA (string vazia mantém a existente).
    $apiKeyEnc = null;
    $touchApiKey = false;
    if ($apiKey !== null && $apiKey !== '') {
        try {
            $apiKeyEnc   = Crypto::encrypt($apiKey);
            $touchApiKey = true;
        } catch (\Throwable $e) {
            error_log('[agent_settings] APP_ENCRYPTION_KEY ausente/inválida: ' . $e->getMessage());
            http_response_code(503);
            echo json_encode(['error' => 'APP_ENCRYPTION_KEY não configurada — api_key não pode ser cifrada com segurança.']);
            exit;
        }
    }

    $ownerAccountId = (int)$inst['account_id'];                                   // config vive com o dono do canal
    $branchId       = ($inst['account_tipo'] === 'filial') ? $ownerAccountId : null; // filial proprietária quando aplicável

    $existing = _loadConfigByInstance($pdo, $instanceId);

    // Adoção: 1º vínculo deste canal? Aproveita a config "solta" (sem instância) mais
    // recente DESTE usuário pra preservar provider/api_key/prompt já configurados.
    if (!$existing) {
        $st = $pdo->prepare(
            'SELECT * FROM agent_configs
              WHERE user_id = :u AND whatsapp_instance_id IS NULL
              ORDER BY updated_at DESC LIMIT 1'
        );
        $st->execute(['u' => $userId]);
        $legacy = $st->fetch(\PDO::FETCH_ASSOC);
        if ($legacy) {
            $pdo->prepare(
                'UPDATE agent_configs
                    SET whatsapp_instance_id = :iid, account_id = :acc, branch_id = :br, updated_by = :ub
                  WHERE id = :id'
            )->execute(['iid' => $instanceId, 'acc' => $ownerAccountId, 'br' => $branchId, 'ub' => $userId, 'id' => (int)$legacy['id']]);
            $existing = _loadConfigByInstance($pdo, $instanceId);
        }
    }

    if (!$existing) {
        $pdo->prepare(
            'INSERT INTO agent_configs
              (account_id, branch_id, user_id, whatsapp_instance_id, name, enabled, provider, api_key_enc, prompt, updated_by)
             VALUES (:acc, :br, :u, :iid, :name, :en, :pr, :key, :prm, :ub)'
        )->execute([
            'acc'  => $ownerAccountId,
            'br'   => $branchId,
            'u'    => $userId,
            'iid'  => $instanceId,
            'name' => $name ?? '',
            'en'   => $enabled ?? 0,
            'pr'   => $provider,
            'key'  => $apiKeyEnc,
            'prm'  => $prompt,
            'ub'   => $userId,
        ]);
    } else {
        // UPDATE dinâmico só com o que veio; sempre normaliza account_id/branch_id/updated_by.
        $fields = ['account_id = :acc', 'branch_id = :br', 'updated_by = :ub'];
        $params = ['iid' => $instanceId, 'acc' => $ownerAccountId, 'br' => $branchId, 'ub' => $userId];
        if ($name !== null)     { $fields[] = 'name = :name';        $params['name'] = $name; }
        if ($enabled !== null)  { $fields[] = 'enabled = :en';       $params['en']   = $enabled; }
        if ($provider !== null) { $fields[] = 'provider = :pr';      $params['pr']   = $provider; }
        if ($touchApiKey)       { $fields[] = 'api_key_enc = :key';  $params['key']  = $apiKeyEnc; }
        if ($prompt !== null)   { $fields[] = 'prompt = :prm';       $params['prm']  = $prompt; }
        $pdo->prepare('UPDATE agent_configs SET ' . implode(', ', $fields) . ' WHERE whatsapp_instance_id = :iid')
            ->execute($params);
    }

    \App\Models\Account::audit($ownerAccountId, 'agent_settings.updated', [
        'user_id'     => $userId,
        'entidade'    => 'agent_config',
        'entidade_id' => $instanceId,
        'detalhes'    => [
            'whatsapp_instance_id' => $instanceId,
            'name'                 => $name,
            'enabled'              => $enabled,
            'provider'             => $provider,
            'prompt_changed'       => $prompt !== null,
            'api_key_changed'      => $touchApiKey,
            'created'              => !$existing,
        ],
    ]);

    $cfg  = _loadConfigByInstance($pdo, $instanceId);
    echo json_encode(['success' => true, 'data' => _buildPayload($cfg, $inst)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
exit;
