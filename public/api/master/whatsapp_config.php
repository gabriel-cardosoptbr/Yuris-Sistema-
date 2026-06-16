<?php
/**
 * /api/master/whatsapp_config.php — config da conexão Evolution POR CONTA (Painel Master).
 *
 * Fase 5 (separação infra/tenant): a infra global da Evolution (URL, API Key,
 * nome da instância, webhook) deixa de ser editável por admin comum de matriz/
 * filial e passa a ser gerida AQUI, só por super_admin. No painel da conta fica
 * apenas conectar/QR/reconectar/status (instances.php, owner/admin).
 *
 * Acesso: super_admin em sessão master_mode.
 *
 * Endpoints:
 *   GET ?list=1        → contas que têm config WhatsApp (resumo, sem chave)
 *   GET ?account_id=N  → config da conta N (evolution_api_key MASCARADA)
 *   POST {account_id, evolution_base_url?, evolution_api_key?, evolution_instance?, webhook_url?}
 *                      → salva (api_key vazia = mantém; bloqueia chave já usada por outra conta)
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;

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

/** Mascara a evolution_api_key (mesma regra do config.php do tenant). */
function _maskKey(array $s): array
{
    if (!empty($s['evolution_api_key'])) {
        $k = $s['evolution_api_key'];
        $s['evolution_api_key_masked'] = strlen($k) > 8 ? str_repeat('*', strlen($k) - 4) . substr($k, -4) : '****';
        $s['evolution_api_key'] = ''; // nunca devolve em claro, nem pro super_admin
    }
    return $s;
}

if ($method === 'GET') {
    // Lista de contas com config WhatsApp (resumo p/ a aba do Master)
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
        // has_key vem como soma; normaliza p/ booleano
        foreach ($rows as &$r) { $r['has_key'] = ((int)$r['has_key'] > 0); }
        ApiResponse::ok(['accounts' => $rows]);
    }

    $accountId = (int)($_GET['account_id'] ?? 0);
    if ($accountId <= 0) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'account_id obrigatório']); exit; }

    $acc = $pdo->prepare("SELECT id, nome, tipo FROM accounts WHERE id = ? LIMIT 1");
    $acc->execute([$accountId]);
    $accRow = $acc->fetch(\PDO::FETCH_ASSOC);
    if (!$accRow) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Conta não encontrada']); exit; }

    $settings = _maskKey($model->getSettings($accountId));
    ApiResponse::ok(['account' => $accRow, 'settings' => $settings]);
}

if ($method === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['_csrf'] ?? ($in['csrf_token'] ?? null));
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
    }

    $accountId = (int)($in['account_id'] ?? 0);
    if ($accountId <= 0) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'account_id obrigatório']); exit; }

    $allowed = ['evolution_base_url', 'evolution_api_key', 'evolution_instance', 'webhook_enabled', 'webhook_url'];
    $touched = [];
    foreach ($allowed as $key) {
        if (!isset($in[$key])) continue;
        // api_key vazia = mantém a atual (não zera)
        if ($key === 'evolution_api_key' && trim((string)$in[$key]) === '') continue;

        // Bloqueio "uma instância por conta/número": chave já usada por OUTRA conta
        // torna o roteamento do webhook ambíguo (findAccountByApiKey).
        if ($key === 'evolution_api_key') {
            $conf = $model->apiKeyConflict((string)$in[$key], $accountId);
            if (!empty($conf)) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'Essa chave da Evolution já está vinculada a outra conta (account_id=' . implode(',', $conf) . '). Cada conta precisa da sua própria instância/chave.']);
                exit;
            }
        }

        $model->saveSetting($accountId, $key, (string)$in[$key]);
        $touched[$key] = ($key === 'evolution_api_key') ? '***changed***' : (string)$in[$key];
    }

    MasterAudit::log('whatsapp_config.updated', 'account', $accountId,
        'Config Evolution da conta atualizada via Master', $touched);

    $settings = _maskKey($model->getSettings($accountId));
    ApiResponse::ok(['settings' => $settings, 'touched' => array_keys($touched)]);
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
