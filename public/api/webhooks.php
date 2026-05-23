<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../app/Services/WebhookDispatcher.php';

use App\Models\Database;
use App\Helpers\AccountContext;
use App\Services\WebhookDispatcher;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();
// Webhooks = CONFIGURAÇÃO da conta — cada tenant define seus próprios endpoints/eventos.
$tenantIds = [$accountId];

if ($_SESSION['user_perfil'] !== 'admin') {
    http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
}

// Cláusula tenant
$_whPh = []; $_whParams = [];
foreach ($tenantIds as $i => $aid) {
    $k = "whacc_{$i}";
    $_whPh[] = ":{$k}";
    $_whParams[$k] = (int)$aid;
}
$tenantIn = '(' . implode(',', $_whPh) . ')';

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? null;

// CSRF for mutating requests
if (in_array($method, ['POST','PUT','DELETE','PATCH'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400); echo json_encode(['error' => 'Invalid CSRF token']); exit;
    }
}

$pdo = Database::getConnection();

// auto-cria tabelas se não existirem (banco resetado)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS webhooks (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        nome        VARCHAR(191) NOT NULL,
        url         VARCHAR(500) NOT NULL,
        secret      VARCHAR(255) DEFAULT NULL,
        eventos     JSON DEFAULT NULL,
        ativo       TINYINT(1) DEFAULT 1,
        deleted_at  DATETIME DEFAULT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_logs (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        webhook_id      INT DEFAULT NULL,
        event_key       VARCHAR(100) NOT NULL,
        payload         JSON DEFAULT NULL,
        response_status INT DEFAULT NULL,
        response_body   TEXT DEFAULT NULL,
        duration_ms     INT DEFAULT NULL,
        success         TINYINT(1) DEFAULT 0,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // garante coluna deleted_at se tabela já existia sem ela
    try {
        $pdo->query('SELECT deleted_at FROM webhooks LIMIT 0');
    } catch (\Throwable $e) {
        $pdo->exec('ALTER TABLE webhooks ADD COLUMN deleted_at DATETIME DEFAULT NULL');
    }
} catch (\Throwable $e) {}

// ── GET catalog ───────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'catalog') {
    echo json_encode(['data' => WebhookDispatcher::catalog()]);
    exit;
}

// ── GET logs ──────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'logs') {
    $wid   = (int)($_GET['id'] ?? 0);
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    // FILTRO TENANT: INNER JOIN com webhooks garante que logs visíveis pertencem ao tenant
    $where = "WHERE w.account_id IN $tenantIn";
    $params = $_whParams;
    if ($wid) {
        $where .= ' AND l.webhook_id = :wid';
        $params['wid'] = $wid;
    }
    $stmt = $pdo->prepare("
        SELECT l.id, l.webhook_id, w.nome AS webhook_nome, l.event_key,
               l.response_status, l.duration_ms, l.success, l.created_at,
               LEFT(l.response_body,300) AS response_body
        FROM webhook_logs l
        INNER JOIN webhooks w ON w.id = l.webhook_id
        $where
        ORDER BY l.created_at DESC LIMIT $limit
    ");
    $stmt->execute($params);
    echo json_encode(['data' => $stmt->fetchAll()]);
    exit;
}

// ── GET single or list ────────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM webhooks WHERE id = :id AND deleted_at IS NULL AND account_id IN $tenantIn LIMIT 1");
        $stmt->execute(['id' => $id] + $_whParams);
        $row = $stmt->fetch();
        if ($row) {
            // ─── LGPD P1 (2A.1): SQLi fix ─────────────────────────────────────
            // Antes desta correção, $id (de $_GET['id']) era concatenado direto
            // em "SELECT ... WHERE webhook_id = $id". Trocado por prepared
            // statements com placeholder. Também limpo a lógica quebrada do
            // total_logs (prepare+execute retornava bool, sempre caía no query
            // concatenado — copy-paste antigo).
            $row['eventos']      = json_decode($row['eventos'] ?? '[]', true);

            $stCnt = $pdo->prepare("SELECT COUNT(*) FROM webhook_logs WHERE webhook_id = ?");
            $stCnt->execute([$id]);
            $cnt = (int)$stCnt->fetchColumn();
            $row['total_logs'] = $cnt;

            $row['success_rate'] = null;
            if ($cnt > 0) {
                $stOk = $pdo->prepare("SELECT COUNT(*) FROM webhook_logs WHERE webhook_id = ? AND success = 1");
                $stOk->execute([$id]);
                $ok = (int)$stOk->fetchColumn();
                $row['success_rate'] = round(($ok / $cnt) * 100);
            }
            // ───────────────────────────────────────────────────────────────────
        }
        echo json_encode(['data' => $row ?: null]);
        exit;
    }

    // list all with stats
    $stmt = $pdo->prepare("SELECT w.*,
        (SELECT COUNT(*) FROM webhook_logs l WHERE l.webhook_id = w.id) AS total_logs,
        (SELECT COUNT(*) FROM webhook_logs l WHERE l.webhook_id = w.id AND l.success = 1) AS success_logs,
        (SELECT MAX(l.created_at) FROM webhook_logs l WHERE l.webhook_id = w.id) AS last_delivery
        FROM webhooks w WHERE w.deleted_at IS NULL AND w.account_id IN $tenantIn ORDER BY w.created_at DESC");
    $stmt->execute($_whParams);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['eventos']      = json_decode($r['eventos'] ?? '[]', true);
        $r['event_count']  = count($r['eventos']);
        $r['success_rate'] = $r['total_logs'] > 0 ? round(($r['success_logs'] / $r['total_logs']) * 100) : null;
    }
    echo json_encode(['data' => $rows]);
    exit;
}

// ── POST create or test ───────────────────────────────────────────────────────
if ($method === 'POST') {
    // Test delivery
    if ($action === 'test') {
        $id = (int)($input['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM webhooks WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$id]);
        $hook = $stmt->fetch();
        if (!$hook) { http_response_code(404); echo json_encode(['error' => 'Webhook not found']); exit; }

        $payload = WebhookDispatcher::buildPayload('webhook.test', [
            'entity'    => 'webhook',
            'entity_id' => $id,
            'data'      => ['mensagem' => 'Este é um evento de teste do Yuris CRM', 'webhook_nome' => $hook['nome']],
        ]);
        // P0 LGPD: dispara só para o próprio tenant dono do webhook testado
        WebhookDispatcher::fire((int)$hook['account_id'], 'webhook.test', $payload);
        echo json_encode(['success' => true, 'message' => 'Evento de teste enviado']);
        exit;
    }

    // Create
    $nome    = trim($input['nome'] ?? '');
    $url     = trim($input['url'] ?? '');
    $secret  = trim($input['secret'] ?? '');
    $ativo   = isset($input['ativo']) ? (int)$input['ativo'] : 1;
    $eventos = array_filter((array)($input['eventos'] ?? []), fn($e) => in_array($e, WebhookDispatcher::allEventKeys()) || $e === '*');

    if (!$nome || !$url) { http_response_code(400); echo json_encode(['error' => 'Nome e URL são obrigatórios']); exit; }
    if (!filter_var($url, FILTER_VALIDATE_URL)) { http_response_code(400); echo json_encode(['error' => 'URL inválida']); exit; }

    $stmt = $pdo->prepare("INSERT INTO webhooks (account_id, nome, url, secret, ativo, eventos, created_at, updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())");
    $ok   = $stmt->execute([$accountId, $nome, $url, $secret ?: null, $ativo, json_encode(array_values($eventos))]);
    echo json_encode(['success' => (bool)$ok, 'id' => $pdo->lastInsertId()]);
    exit;
}

// ── PUT update ────────────────────────────────────────────────────────────────
if ($method === 'PUT' || $method === 'PATCH') {
    $id   = (int)($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }

    $fields = []; $params = [];
    if (isset($input['nome']))    { $fields[] = 'nome = ?';    $params[] = trim($input['nome']); }
    if (isset($input['url']))     { $fields[] = 'url = ?';     $params[] = trim($input['url']); }
    if (isset($input['secret']))  { $fields[] = 'secret = ?';  $params[] = trim($input['secret']) ?: null; }
    if (isset($input['ativo']))   { $fields[] = 'ativo = ?';   $params[] = (int)$input['ativo']; }
    if (isset($input['eventos'])) {
        $ev = array_filter((array)$input['eventos'], fn($e) => in_array($e, WebhookDispatcher::allEventKeys()) || $e === '*');
        $fields[] = 'eventos = ?'; $params[] = json_encode(array_values($ev));
    }
    if (!$fields) { echo json_encode(['success' => true]); exit; }

    $fields[] = 'updated_at = NOW()';
    // tenant placeholders (positional)
    $tenantPos = [];
    foreach ($tenantIds as $aid) $tenantPos[] = (int)$aid;
    $tenantInPos = '(' . implode(',', array_fill(0, count($tenantPos), '?')) . ')';
    $params[] = $id;
    foreach ($tenantPos as $aid) $params[] = $aid;
    $stmt = $pdo->prepare("UPDATE webhooks SET " . implode(', ', $fields) . " WHERE id = ? AND account_id IN $tenantInPos");
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit; }
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? ($input['id'] ?? 0));
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $stmt = $pdo->prepare("UPDATE webhooks SET deleted_at = NOW() WHERE id = :id AND account_id IN $tenantIn");
    $stmt->execute(['id' => $id] + $_whParams);
    if ($stmt->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit; }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
