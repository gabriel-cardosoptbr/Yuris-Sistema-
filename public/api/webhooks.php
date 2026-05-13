<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Services/WebhookDispatcher.php';

use App\Models\Database;
use App\Services\WebhookDispatcher;

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
}
if ($_SESSION['user_perfil'] !== 'admin') {
    http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
}

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
    $where = $wid ? 'WHERE l.webhook_id = ?' : 'WHERE 1';
    $stmt  = $pdo->prepare("
        SELECT l.id, l.webhook_id, w.nome AS webhook_nome, l.event_key,
               l.response_status, l.duration_ms, l.success, l.created_at,
               LEFT(l.response_body,300) AS response_body
        FROM webhook_logs l
        LEFT JOIN webhooks w ON w.id = l.webhook_id
        $where
        ORDER BY l.created_at DESC LIMIT $limit
    ");
    $wid ? $stmt->execute([$wid]) : $stmt->execute();
    echo json_encode(['data' => $stmt->fetchAll()]);
    exit;
}

// ── GET single or list ────────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM webhooks WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $row['eventos']     = json_decode($row['eventos'] ?? '[]', true);
            $row['total_logs']  = (int)$pdo->prepare("SELECT COUNT(*) FROM webhook_logs WHERE webhook_id = ?")->execute([$id]) ? $pdo->query("SELECT COUNT(*) FROM webhook_logs WHERE webhook_id = $id")->fetchColumn() : 0;
            $row['success_rate']= null;
            $cnt = $pdo->query("SELECT COUNT(*) FROM webhook_logs WHERE webhook_id = $id")->fetchColumn();
            if ($cnt > 0) {
                $ok  = $pdo->query("SELECT COUNT(*) FROM webhook_logs WHERE webhook_id = $id AND success = 1")->fetchColumn();
                $row['success_rate'] = round(($ok / $cnt) * 100);
            }
        }
        echo json_encode(['data' => $row ?: null]);
        exit;
    }

    // list all with stats
    $stmt = $pdo->query("SELECT w.*,
        (SELECT COUNT(*) FROM webhook_logs l WHERE l.webhook_id = w.id) AS total_logs,
        (SELECT COUNT(*) FROM webhook_logs l WHERE l.webhook_id = w.id AND l.success = 1) AS success_logs,
        (SELECT MAX(l.created_at) FROM webhook_logs l WHERE l.webhook_id = w.id) AS last_delivery
        FROM webhooks w WHERE w.deleted_at IS NULL ORDER BY w.created_at DESC");
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
        WebhookDispatcher::fire('webhook.test', $payload);
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

    $stmt = $pdo->prepare("INSERT INTO webhooks (nome, url, secret, ativo, eventos, created_at, updated_at) VALUES (?,?,?,?,?,NOW(),NOW())");
    $ok   = $stmt->execute([$nome, $url, $secret ?: null, $ativo, json_encode(array_values($eventos))]);
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
    $params[] = $id;
    $pdo->prepare("UPDATE webhooks SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? ($input['id'] ?? 0));
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $pdo->prepare("UPDATE webhooks SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
