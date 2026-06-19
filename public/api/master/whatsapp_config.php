<?php
/**
 * /api/master/whatsapp_config.php — config Evolution por conta + GLOBAL + provisionamento.
 *
 * Fase 5 + automação: super_admin gere a conexão Evolution de cada conta E a
 * credencial GLOBAL (admin key do servidor) usada pra CRIAR instâncias.
 *
 * Acesso: super_admin em sessão master_mode.
 *
 * GET ?global=1        → config global (base_url, webhook_url, admin_key mascarada)
 * GET ?list=1          → contas com config WhatsApp (resumo, sem chave)
 * GET ?account_id=N    → config da conta N (evolution_api_key mascarada)
 *
 * POST action=save_global  {evolution_base_url?, evolution_admin_key?, webhook_url?}
 * POST action=provision    {account_id}  → cria instância na Evolution + salva config da conta + webhook
 * POST (sem action)        {account_id, ...}  → salva config da conta (manual)
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';
require_once __DIR__ . '/../../../app/Helpers/Crypto.php';
require_once __DIR__ . '/../../../app/Services/EvolutionApiService.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;
use App\Helpers\Crypto;

session_start();
header('Cache-Control: no-store');
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$pdo    = \App\Models\Database::getConnection();
$model  = new WhatsAppInstance();
$method = $_SERVER['REQUEST_METHOD'];

const GK_BASE = 'evolution_global_base_url';
const GK_HOOK = 'evolution_global_webhook_url';
const GK_KEY  = 'evolution_global_admin_key_enc';
const HOOK_DEFAULT = 'https://yuris.com.br/api/whatsapp/webhook.php';

function _appGet(\PDO $pdo, string $k): ?string {
    $s = $pdo->prepare('SELECT config_value FROM app_settings WHERE config_key = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string)$v;
}
function _appSet(\PDO $pdo, string $k, string $v): void {
    $pdo->prepare('INSERT INTO app_settings (config_key, config_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)')->execute([$k, $v]);
}
/** Config global decifrada: [base_url, webhook_url, admin_key]. */
function _globalCfg(\PDO $pdo): array {
    $base = (string)(_appGet($pdo, GK_BASE) ?? '');
    $hook = (string)(_appGet($pdo, GK_HOOK) ?? HOOK_DEFAULT);
    $enc  = _appGet($pdo, GK_KEY);
    $key  = '';
    if ($enc) { try { $key = (string)Crypto::decrypt($enc); } catch (\Throwable $_) { $key = ''; } }
    return [$base, $hook, $key];
}
function _maskKey(array $s): array {
    if (!empty($s['evolution_api_key'])) {
        $k = $s['evolution_api_key'];
        $s['evolution_api_key_masked'] = strlen($k) > 8 ? str_repeat('*', strlen($k) - 4) . substr($k, -4) : '****';
        $s['evolution_api_key'] = '';
    }
    return $s;
}

// ─── GET ─────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['global'])) {
        [$base, $hook, $key] = _globalCfg($pdo);
        ApiResponse::ok([
            'evolution_base_url'     => $base,
            'webhook_url'            => $hook,
            'has_admin_key'          => $key !== '',
            'admin_key_masked'       => $key !== '' ? (str_repeat('*', max(0, strlen($key) - 4)) . substr($key, -4)) : '',
        ]);
    }

    if (!empty($_GET['list'])) {
        $sql = "SELECT a.id AS account_id, a.nome AS account_nome, a.tipo AS account_tipo,
                       MAX(CASE WHEN ws.config_key='evolution_instance' THEN ws.config_value END) AS evolution_instance,
                       MAX(CASE WHEN ws.config_key='evolution_base_url' THEN ws.config_value END) AS evolution_base_url,
                       MAX(CASE WHEN ws.config_key='evolution_api_key'  THEN 1 ELSE 0 END)         AS has_key
                  FROM accounts a
                  JOIN whatsapp_settings ws ON ws.account_id = a.id
                 GROUP BY a.id, a.nome, a.tipo
                 ORDER BY a.nome ASC";
        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['has_key'] = ((int)$r['has_key'] > 0); }
        ApiResponse::ok(['accounts' => $rows]);
    }

    $accountId = (int)($_GET['account_id'] ?? 0);
    if ($accountId <= 0) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'account_id obrigatório']); exit; }
    $acc = $pdo->prepare("SELECT id, nome, tipo FROM accounts WHERE id = ? LIMIT 1");
    $acc->execute([$accountId]);
    $accRow = $acc->fetch(\PDO::FETCH_ASSOC);
    if (!$accRow) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Conta não encontrada']); exit; }
    ApiResponse::ok(['account' => $accRow, 'settings' => _maskKey($model->getSettings($accountId))]);
}

// ─── POST ────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['_csrf'] ?? ($in['csrf_token'] ?? null));
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
    }
    $action = $in['action'] ?? '';

    // ── Salvar config GLOBAL (admin key cifrada) ──────────────────────────────
    if ($action === 'save_global') {
        if (isset($in['evolution_base_url'])) _appSet($pdo, GK_BASE, trim((string)$in['evolution_base_url']));
        if (isset($in['webhook_url']))        _appSet($pdo, GK_HOOK, trim((string)$in['webhook_url']) ?: HOOK_DEFAULT);
        $adminKey = isset($in['evolution_admin_key']) ? trim((string)$in['evolution_admin_key']) : '';
        if ($adminKey !== '') {
            try { _appSet($pdo, GK_KEY, Crypto::encrypt($adminKey)); }
            catch (\Throwable $e) {
                http_response_code(503);
                echo json_encode(['ok' => false, 'error' => 'APP_ENCRYPTION_KEY ausente: não foi possível cifrar a admin key.']);
                exit;
            }
        }
        MasterAudit::log('whatsapp_global.updated', 'app_settings', null, 'Config global Evolution atualizada',
            ['base_url' => $in['evolution_base_url'] ?? null, 'webhook' => $in['webhook_url'] ?? null, 'admin_key' => $adminKey !== '' ? '***changed***' : 'mantida']);
        [$base, $hook, $key] = _globalCfg($pdo);
        ApiResponse::ok(['evolution_base_url' => $base, 'webhook_url' => $hook, 'has_admin_key' => $key !== '']);
    }

    // ── Provisionar instância da conta (cria na Evolution) ─────────────────────
    if ($action === 'provision') {
        $accountId = (int)($in['account_id'] ?? 0);
        if ($accountId <= 0) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'account_id obrigatório']); exit; }

        [$base, $hook, $adminKey] = _globalCfg($pdo);
        if ($base === '' || $adminKey === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Configure a URL base e a admin key global da Evolution antes de criar instâncias.']);
            exit;
        }

        $acc = $pdo->prepare("SELECT id, nome FROM accounts WHERE id = ? LIMIT 1");
        $acc->execute([$accountId]);
        $accRow = $acc->fetch(\PDO::FETCH_ASSOC);
        if (!$accRow) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Conta não encontrada']); exit; }

        // Nome da instância: slug do nome da conta + id (único, [a-zA-Z0-9_-]).
        $slug = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', (string)$accRow['nome']));
        if ($slug === '') $slug = 'conta';
        $name  = substr($slug, 0, 24) . '-' . $accountId;
        $token = bin2hex(random_bytes(18)); // chave própria da instância (roteia o webhook)

        try {
            $globalEvo = new EvolutionApiService(['evolution_base_url' => $base, 'evolution_api_key' => $adminKey]);
            $res = $globalEvo->createInstance($name, '', $token);
            if (!empty($res['_error'])) {
                http_response_code(502);
                echo json_encode(['ok' => false, 'error' => 'Evolution recusou criar a instância: ' . $res['_error']]);
                exit;
            }
            // Usa a apikey que a Evolution REALMENTE atribuiu (varia por versão); senão o token.
            $apikey = $res['hash']['apikey'] ?? (is_string($res['hash'] ?? null) ? $res['hash'] : null)
                   ?? $res['instance']['apikey'] ?? $res['instance']['token'] ?? $token;

            // Salva config da conta
            $model->saveSetting($accountId, 'evolution_base_url', $base);
            $model->saveSetting($accountId, 'evolution_instance', $name);
            $model->saveSetting($accountId, 'evolution_api_key',  (string)$apikey);
            $model->saveSetting($accountId, 'webhook_url',        $hook);

            // Aplica o webhook canônico com ?token (auth da própria instância)
            $acctEvo = new EvolutionApiService(['evolution_base_url' => $base, 'evolution_api_key' => (string)$apikey, 'evolution_instance' => $name]);
            $hookUrl = $hook . (strpos($hook, '?') === false ? '?' : '&') . 'token=' . urlencode((string)$apikey);
            $acctEvo->setWebhook($name, $hookUrl);

            // Cria a linha local da instância
            $model->findOrCreate($name, (string)$accRow['nome'] ?: $name, $accountId);

            MasterAudit::log('whatsapp.provision', 'account', $accountId,
                "Instância Evolution '{$name}' criada para a conta", ['instance' => $name]);

            ApiResponse::ok([
                'instance' => $name,
                'message'  => "Instância criada. A conta já pode escanear o QR em Comunicação, Chat WhatsApp.",
            ]);
        } catch (\Throwable $e) {
            error_log('[whatsapp_config provision] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Falha ao provisionar: ' . $e->getMessage()]);
            exit;
        }
    }

    // ── Excluir instância da conta (apaga na Evolution + limpa local) ──────────
    // Pra "resetar" um canal travado/bugado e criar outro do zero.
    if ($action === 'delete_instance') {
        $accountId = (int)($in['account_id'] ?? 0);
        if ($accountId <= 0) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'account_id obrigatório']); exit; }

        $settings = $model->getSettings($accountId);
        $instName = trim((string)($settings['evolution_instance'] ?? ''));
        $acctKey  = (string)($settings['evolution_api_key'] ?? '');
        [$gBase, $gHook, $gKey] = _globalCfg($pdo);
        $base = ((string)($settings['evolution_base_url'] ?? '')) ?: $gBase;

        // 1) Best-effort na Evolution (logout + delete). Usa a chave da própria
        //    instância se houver, senão a admin key global. Não derruba se falhar
        //    (ex.: instância já não existe lá) — o objetivo é resetar mesmo assim.
        $evoResult = 'pulado (sem nome de instância salvo)';
        if ($instName !== '' && $base !== '') {
            $authKey = $acctKey !== '' ? $acctKey : $gKey;
            if ($authKey !== '') {
                try {
                    $evo = new EvolutionApiService(['evolution_base_url' => $base, 'evolution_api_key' => $authKey, 'evolution_instance' => $instName]);
                    try { $evo->logoutInstance($instName); } catch (\Throwable $_) {}
                    $del = $evo->deleteInstance($instName);
                    $evoResult = !empty($del['_error']) ? ('Evolution recusou: ' . $del['_error']) : 'excluída na Evolution';
                } catch (\Throwable $e) {
                    $evoResult = 'erro Evolution: ' . $e->getMessage();
                }
            } else {
                $evoResult = 'pulado (sem chave p/ autenticar na Evolution)';
            }
        }

        // 2) Limpeza local em transação. Essas tabelas têm instance_id SEM FK →
        //    apaga na mão pra não deixar órfão. agent_configs desvincula sozinho
        //    (FK ON DELETE SET NULL).
        $deleted = ['instances'=>0,'mensagens'=>0,'conversas'=>0,'contatos'=>0,'membros'=>0,'reacoes'=>0];
        try {
            $pdo->beginTransaction();
            $stI = $pdo->prepare('SELECT id FROM whatsapp_instances WHERE account_id = ?' . ($instName !== '' ? ' AND instance_name = ?' : ''));
            $stI->execute($instName !== '' ? [$accountId, $instName] : [$accountId]);
            $ids = $stI->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            if ($ids) {
                $ph  = implode(',', array_fill(0, count($ids), '?'));
                $map = ['whatsapp_messages'=>'mensagens','whatsapp_chats'=>'conversas','whatsapp_contacts'=>'contatos','whatsapp_group_members'=>'membros','whatsapp_reactions'=>'reacoes'];
                foreach ($map as $tbl => $k) {
                    $d = $pdo->prepare("DELETE FROM {$tbl} WHERE instance_id IN ($ph)");
                    $d->execute($ids);
                    $deleted[$k] = $d->rowCount();
                }
                $dI = $pdo->prepare("DELETE FROM whatsapp_instances WHERE id IN ($ph)");
                $dI->execute($ids);
                $deleted['instances'] = $dI->rowCount();
            }
            // Limpa a conexão (mantém a conta na lista p/ poder recriar): zera
            // instance + api_key; preserva base_url/webhook.
            $pdo->prepare("DELETE FROM whatsapp_settings WHERE account_id = ? AND config_key IN ('evolution_instance','evolution_api_key')")->execute([$accountId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[whatsapp_config delete_instance] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Falha ao limpar dados locais: ' . $e->getMessage()]);
            exit;
        }

        MasterAudit::log('whatsapp.delete_instance', 'account', $accountId,
            "Instância '{$instName}' excluída (Evolution: {$evoResult})", ['instance' => $instName, 'evolution' => $evoResult, 'deleted' => $deleted]);

        ApiResponse::ok([
            'instance'  => $instName,
            'evolution' => $evoResult,
            'deleted'   => $deleted,
            'message'   => "Instância excluída. Evolution: {$evoResult}. Agora você pode clicar em \"Criar instância\" pra começar do zero.",
        ]);
    }

    // ── Salvar config da conta (manual) ───────────────────────────────────────
    $accountId = (int)($in['account_id'] ?? 0);
    if ($accountId <= 0) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'account_id obrigatório']); exit; }

    $allowed = ['evolution_base_url', 'evolution_api_key', 'evolution_instance', 'webhook_enabled', 'webhook_url'];
    $touched = [];
    foreach ($allowed as $key) {
        if (!isset($in[$key])) continue;
        if ($key === 'evolution_api_key' && trim((string)$in[$key]) === '') continue;
        if ($key === 'evolution_api_key') {
            $conf = $model->apiKeyConflict((string)$in[$key], $accountId);
            if (!empty($conf)) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'Essa chave da Evolution já está vinculada a outra conta (account_id=' . implode(',', $conf) . ').']);
                exit;
            }
        }
        $model->saveSetting($accountId, $key, (string)$in[$key]);
        $touched[$key] = ($key === 'evolution_api_key') ? '***changed***' : (string)$in[$key];
    }
    MasterAudit::log('whatsapp_config.updated', 'account', $accountId, 'Config Evolution da conta atualizada via Master', $touched);
    ApiResponse::ok(['settings' => _maskKey($model->getSettings($accountId)), 'touched' => array_keys($touched)]);
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
