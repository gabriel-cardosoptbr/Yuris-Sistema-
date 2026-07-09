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
require_once __DIR__ . '/../../app/Services/WhatsAppChannelAccessService.php'; // autorizacao POR GRANT de canal (nao hierarquia)

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

/**
 * Linha rica do canal (display + conta) por id JA AUTORIZADO. Sem escopo: a autorizacao
 * e feita pelo chamador via WhatsAppChannelAccessService::resolveForRequest (grant de canal).
 */
function _loadInstanceDisplayById(\PDO $pdo, int $instanceId): ?array
{
    if ($instanceId <= 0) return null;
    $st = $pdo->prepare(
        "SELECT i.id, i.account_id, i.instance_name, i.display_name, i.phone, i.status,
                a.nome AS account_nome, a.tipo AS account_tipo
           FROM whatsapp_instances i
           INNER JOIN accounts a ON a.id = i.account_id
          WHERE i.id = :id
          LIMIT 1"
    );
    $st->execute(['id' => $instanceId]);
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

/** Decodifica coluna JSON em array (tolerante). */
function _jdec($v): array {
    if (is_array($v)) return $v;
    if (is_string($v) && $v !== '') { $d = json_decode($v, true); return is_array($d) ? $d : []; }
    return [];
}

/** Catalogo GLOBAL de areas (para o painel listar e o admin habilitar as que atende). */
function _catalog(\PDO $pdo): array {
    try {
        return $pdo->query("SELECT code, name FROM ai_area_catalog WHERE active = 1 ORDER BY sort ASC, name ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) { return []; }
}
function _catalogMap(\PDO $pdo): array {
    $m = [];
    foreach (_catalog($pdo) as $a) $m[$a['code']] = $a['name'];
    return $m;
}

/** Areas habilitadas/config do agente. */
function _agentAreas(\PDO $pdo, int $agentId): array {
    if ($agentId <= 0) return [];
    try {
        $st = $pdo->prepare("SELECT code, name, enabled, responsible_user_id, priority FROM ai_intake_areas WHERE agent_id = ? ORDER BY priority DESC, name ASC");
        $st->execute([$agentId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) { return []; }
}

/** Monta o payload de resposta (config rica + info read-only do canal). NUNCA credencial Evolution. */
function _buildPayload(?array $cfg, ?array $inst): array
{
    $masked = '';
    if ($cfg && !empty($cfg['api_key_enc'])) {
        $plain = _decryptApiKey($cfg['api_key_enc']);
        if ($plain !== null) $masked = _maskApiKey($plain);
    }
    return [
        'name'              => $cfg['name']     ?? '',
        'enabled'           => (bool)($cfg['enabled'] ?? 0),
        'status'            => $cfg['status']   ?? 'inactive',
        'provider'          => $cfg['provider'] ?? 'openai',
        'model'             => $cfg['model']    ?? 'gpt-4o-mini',
        'api_key'           => '',               // nunca devolve plain
        'api_key_masked'    => $masked,
        'prompt'            => $cfg['prompt']   ?? '',
        'max_questions'     => (int)($cfg['max_questions'] ?? 6),
        'office_name'       => $cfg['office_name'] ?? '',
        'office_description'=> $cfg['office_description'] ?? '',
        'initial_message'   => $cfg['initial_message'] ?? '',
        'closing_message'   => $cfg['closing_message'] ?? '',
        'urgency_message'   => $cfg['urgency_message'] ?? '',
        'handoff_message'   => $cfg['handoff_message'] ?? '',
        'office_information'=> _jdec($cfg['office_information_json'] ?? null),
        'behavior'          => _jdec($cfg['behavior_json'] ?? null),
        'handoff_config'    => _jdec($cfg['handoff_config_json'] ?? null),
        'usage_limits'      => _jdec($cfg['usage_limits_json'] ?? null),
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
        // Nenhum canal selecionado → defaults vazios (+ catalogo p/ a UI montar a lista).
        $payload = _buildPayload(null, null);
        $payload['catalog'] = _catalog($pdo);
        $payload['areas']   = [];
        echo json_encode($payload);
        exit;
    }
    // Autoriza POR GRANT de canal (403+exit se negar). 'view' basta pra ler a config.
    $auth = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $instanceId, 'view');
    $instanceId = (int)$auth['channel_id'];
    $inst = _loadInstanceDisplayById($pdo, $instanceId);
    if (!$inst) { http_response_code(404); echo json_encode(['error' => 'Canal não encontrado']); exit; }
    $cfg = _loadConfigByInstance($pdo, $instanceId);
    $payload = _buildPayload($cfg, $inst);
    $payload['catalog'] = _catalog($pdo);
    $payload['areas']   = $cfg ? _agentAreas($pdo, (int)$cfg['id']) : [];
    echo json_encode($payload);
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
    // Autoriza POR GRANT de canal com 'manage' (exclusivo do DONO): hierarquia matriz/filial
    // nao concede reconfigurar o agente de canal alheio. 403+exit se negar.
    $auth = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $instanceId, 'manage');
    $instanceId = (int)$auth['channel_id'];
    $inst = _loadInstanceDisplayById($pdo, $instanceId);
    if (!$inst) { http_response_code(404); echo json_encode(['error' => 'Canal não encontrado']); exit; }

    $name     = isset($input['name'])     ? trim((string)$input['name'])     : null;
    $enabled  = isset($input['enabled'])  ? (int)((bool)$input['enabled'])   : null;
    $provider = isset($input['provider']) ? trim((string)$input['provider']) : null;
    $apiKey   = isset($input['api_key'])  ? trim((string)$input['api_key'])  : null;
    $prompt   = isset($input['prompt'])   ? (string)$input['prompt']         : null;

    // Config rica (migration 097). office_information/behavior/handoff/usage -> JSON.
    $model      = isset($input['model'])              ? trim((string)$input['model'])              : null;
    $maxQ       = isset($input['max_questions'])      ? max(3, min(8, (int)$input['max_questions'])) : null;
    $officeName = isset($input['office_name'])        ? trim((string)$input['office_name'])        : null;
    $officeDesc = isset($input['office_description']) ? (string)$input['office_description']        : null;
    $initialMsg = isset($input['initial_message'])    ? (string)$input['initial_message']          : null;
    $closingMsg = isset($input['closing_message'])    ? (string)$input['closing_message']          : null;
    $urgencyMsg = isset($input['urgency_message'])    ? (string)$input['urgency_message']          : null;
    $handoffMsg = isset($input['handoff_message'])    ? (string)$input['handoff_message']          : null;
    $officeInfo = (isset($input['office_information']) && is_array($input['office_information'])) ? json_encode($input['office_information'], JSON_UNESCAPED_UNICODE) : null;
    $behavior   = (isset($input['behavior'])           && is_array($input['behavior']))          ? json_encode($input['behavior'], JSON_UNESCAPED_UNICODE)          : null;
    $handoffCfg = (isset($input['handoff_config'])     && is_array($input['handoff_config']))     ? json_encode($input['handoff_config'], JSON_UNESCAPED_UNICODE)    : null;
    $usageLim   = (isset($input['usage_limits'])       && is_array($input['usage_limits']))       ? json_encode($input['usage_limits'], JSON_UNESCAPED_UNICODE)      : null;
    $status     = ($enabled === null) ? null : ($enabled === 1 ? 'active' : 'inactive');

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
              (account_id, branch_id, user_id, whatsapp_instance_id, name, enabled, status, provider, model,
               api_key_enc, prompt, max_questions, office_name, office_description, office_information_json,
               behavior_json, handoff_config_json, usage_limits_json, initial_message, closing_message,
               urgency_message, handoff_message, updated_by)
             VALUES (:acc, :br, :u, :iid, :name, :en, :st, :pr, :model, :key, :prm, :mq, :on, :od, :oi,
               :bh, :hc, :ul, :im, :cm, :um, :hm, :ub)'
        )->execute([
            'acc' => $ownerAccountId, 'br' => $branchId, 'u' => $userId, 'iid' => $instanceId,
            'name' => $name ?? '', 'en' => $enabled ?? 0, 'st' => $status ?? 'inactive',
            'pr' => $provider ?? 'openai', 'model' => $model ?? 'gpt-4o-mini', 'key' => $apiKeyEnc,
            'prm' => $prompt, 'mq' => $maxQ ?? 6, 'on' => $officeName, 'od' => $officeDesc, 'oi' => $officeInfo,
            'bh' => $behavior, 'hc' => $handoffCfg, 'ul' => $usageLim, 'im' => $initialMsg, 'cm' => $closingMsg,
            'um' => $urgencyMsg, 'hm' => $handoffMsg, 'ub' => $userId,
        ]);
    } else {
        // UPDATE dinâmico só com o que veio; sempre normaliza account_id/branch_id/updated_by.
        $fields = ['account_id = :acc', 'branch_id = :br', 'updated_by = :ub'];
        $params = ['iid' => $instanceId, 'acc' => $ownerAccountId, 'br' => $branchId, 'ub' => $userId];
        if ($name !== null)       { $fields[] = 'name = :name';                  $params['name']  = $name; }
        if ($enabled !== null)    { $fields[] = 'enabled = :en';                 $params['en']    = $enabled; }
        if ($status !== null)     { $fields[] = 'status = :st';                  $params['st']    = $status; }
        if ($provider !== null)   { $fields[] = 'provider = :pr';                $params['pr']    = $provider; }
        if ($model !== null)      { $fields[] = 'model = :model';                $params['model'] = $model; }
        if ($touchApiKey)         { $fields[] = 'api_key_enc = :key';            $params['key']   = $apiKeyEnc; }
        if ($prompt !== null)     { $fields[] = 'prompt = :prm';                 $params['prm']   = $prompt; }
        if ($maxQ !== null)       { $fields[] = 'max_questions = :mq';           $params['mq']    = $maxQ; }
        if ($officeName !== null) { $fields[] = 'office_name = :on';             $params['on']    = $officeName; }
        if ($officeDesc !== null) { $fields[] = 'office_description = :od';      $params['od']    = $officeDesc; }
        if ($officeInfo !== null) { $fields[] = 'office_information_json = :oi'; $params['oi']    = $officeInfo; }
        if ($behavior !== null)   { $fields[] = 'behavior_json = :bh';          $params['bh']    = $behavior; }
        if ($handoffCfg !== null) { $fields[] = 'handoff_config_json = :hc';    $params['hc']    = $handoffCfg; }
        if ($usageLim !== null)   { $fields[] = 'usage_limits_json = :ul';      $params['ul']    = $usageLim; }
        if ($initialMsg !== null) { $fields[] = 'initial_message = :im';        $params['im']    = $initialMsg; }
        if ($closingMsg !== null) { $fields[] = 'closing_message = :cm';        $params['cm']    = $closingMsg; }
        if ($urgencyMsg !== null) { $fields[] = 'urgency_message = :um';        $params['um']    = $urgencyMsg; }
        if ($handoffMsg !== null) { $fields[] = 'handoff_message = :hm';        $params['hm']    = $handoffMsg; }
        $pdo->prepare('UPDATE agent_configs SET ' . implode(', ', $fields) . ' WHERE whatsapp_instance_id = :iid')
            ->execute($params);
    }

    $cfg     = _loadConfigByInstance($pdo, $instanceId);
    $agentId = (int)($cfg['id'] ?? 0);

    // Sincroniza as areas habilitadas POR AGENTE (cada escritorio ativa o que atende).
    if ($agentId > 0 && is_array($input['areas'] ?? null)) {
        $catMap = _catalogMap($pdo);
        $ups = $pdo->prepare(
            'INSERT INTO ai_intake_areas (account_id, agent_id, code, name, enabled, responsible_user_id, priority)
             VALUES (:acc, :ag, :code, :name, :en, :ru, :pr)
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), responsible_user_id=VALUES(responsible_user_id),
               priority=VALUES(priority), name=VALUES(name)'
        );
        foreach ($input['areas'] as $a) {
            $code = trim((string)($a['code'] ?? ''));
            if ($code === '' || !isset($catMap[$code])) continue; // so areas do catalogo
            $ups->execute([
                'acc'  => $ownerAccountId, 'ag' => $agentId, 'code' => $code, 'name' => $catMap[$code],
                'en'   => (int)!empty($a['enabled']),
                'ru'   => !empty($a['responsible_user_id']) ? (int)$a['responsible_user_id'] : null,
                'pr'   => (int)($a['priority'] ?? 0),
            ]);
        }
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
            'model'                => $model,
            'prompt_changed'       => $prompt !== null,
            'api_key_changed'      => $touchApiKey,
            'areas_synced'         => is_array($input['areas'] ?? null) ? count($input['areas']) : 0,
            'created'              => !$existing,
        ],
    ]);

    $payload = _buildPayload($cfg, $inst);
    $payload['catalog'] = _catalog($pdo);
    $payload['areas']   = $agentId ? _agentAreas($pdo, $agentId) : [];
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
exit;
