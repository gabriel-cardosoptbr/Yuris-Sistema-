<?php
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Master/Account.php';
require_once __DIR__ . '/../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../app/Billing/PlanFeature.php';
require_once __DIR__ . '/../../app/Integracoes/WebhookDispatcher.php';

use App\Core\Database;
use App\Core\AccountContext;
use App\Billing\PlanFeature;
use App\Integracoes\WebhookDispatcher;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();
// Webhooks = CONFIGURAÇÃO da conta — cada tenant define seus próprios endpoints/eventos.
$tenantIds = [$accountId];

if ($_SESSION['user_perfil'] !== 'admin') {
    http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
}

// Módulo por plano. Vale para TODOS os métodos (inclusive GET): se o plano não
// inclui Webhooks, a conta não lista nem cria endpoint.
PlanFeature::assertEnabled($accountId, PlanFeature::F_WEBHOOKS);

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
// FIX #29 (auditoria 2026-06-01): a migration 067 renomeou `webhooks` -> `webhook_endpoints`
// e TODO o resto do modulo (list/get/create/update/delete + WebhookDispatcher) opera sobre
// `webhook_endpoints` e `webhook_deliveries`. O auto-create antigo criava a tabela ERRADA
// ('webhooks') com schema defasado (sem account_id/escopo/payload_mode/...), e o guard de
// coluna logo abaixo ja consultava 'webhook_endpoints' — num reset, todas as queries
// quebravam. Agora criamos as tabelas CERTAS com o schema completo (espelho das migrations
// 067 e 069). webhook_logs NAO e mais criada: nada le dela (logs vem de webhook_deliveries).
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_endpoints (
        id                    INT AUTO_INCREMENT PRIMARY KEY,
        account_id            INT NOT NULL,
        escopo                ENUM('tenant_only','matriz_e_filiais','filial_only') NOT NULL DEFAULT 'tenant_only',
        nome                  VARCHAR(191) NOT NULL,
        url                   VARCHAR(500) NOT NULL,
        metodo                ENUM('POST') NOT NULL DEFAULT 'POST',
        secret                VARCHAR(255) DEFAULT NULL,
        secret_rotated_at     DATETIME DEFAULT NULL,
        eventos               JSON DEFAULT NULL,
        headers_customizados  JSON DEFAULT NULL,
        timeout_segundos      INT NOT NULL DEFAULT 10,
        retry_enabled         TINYINT(1) NOT NULL DEFAULT 1,
        max_retries           INT NOT NULL DEFAULT 3,
        payload_mode          ENUM('minimal','masked','full') NOT NULL DEFAULT 'masked',
        created_by            INT DEFAULT NULL,
        ativo                 TINYINT(1) DEFAULT 1,
        deleted_at            DATETIME DEFAULT NULL,
        created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at            DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_we_account_status (account_id, ativo, deleted_at),
        INDEX idx_we_escopo (escopo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_deliveries (
        id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
        webhook_endpoint_id  INT NOT NULL,
        account_id           INT NOT NULL,
        event_code           VARCHAR(100) NOT NULL,
        event_id             VARCHAR(64)  NOT NULL,
        payload              JSON NOT NULL,
        request_url          VARCHAR(500) NOT NULL,
        request_headers      JSON NULL,
        response_status      INT NULL,
        response_body        TEXT NULL,
        response_time_ms     INT NULL,
        status               ENUM('pending','success','failed','retrying','canceled') NOT NULL DEFAULT 'pending',
        tentativa            INT NOT NULL DEFAULT 1,
        erro                 TEXT NULL,
        scheduled_retry_at   DATETIME NULL,
        delivered_at         DATETIME NULL,
        created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_wd_endpoint_time (webhook_endpoint_id, created_at),
        INDEX idx_wd_status_retry (status, scheduled_retry_at),
        INDEX idx_wd_account_time (account_id, created_at),
        INDEX idx_wd_event_id (event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // garante coluna deleted_at se tabela já existia sem ela (instalacoes pre-067)
    try {
        $pdo->query('SELECT deleted_at FROM webhook_endpoints LIMIT 0');
    } catch (\Throwable $e) {
        $pdo->exec('ALTER TABLE webhook_endpoints ADD COLUMN deleted_at DATETIME DEFAULT NULL');
    }
} catch (\Throwable $e) {}

// ── GET catalog ───────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'catalog') {
    echo json_encode(['data' => WebhookDispatcher::catalog()]);
    exit;
}

// ── GET logs (Etapa 8: agora le de webhook_deliveries) ──────────────────────
if ($method === 'GET' && $action === 'logs') {
    $wid   = (int)($_GET['id'] ?? 0);
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    // Tenant filtra direto em d.account_id (indexed por idx_wd_account_time)
    $where = "WHERE d.account_id IN $tenantIn";
    $params = $_whParams;
    if ($wid) {
        $where .= ' AND d.webhook_endpoint_id = :wid';
        $params['wid'] = $wid;
    }
    $stmt = $pdo->prepare("
        SELECT d.id, d.webhook_endpoint_id AS webhook_id, w.nome AS webhook_nome,
               d.event_code AS event_key, d.response_status,
               d.response_time_ms AS duration_ms,
               CASE WHEN d.status='success' THEN 1 ELSE 0 END AS success,
               d.status, d.tentativa, d.scheduled_retry_at, d.event_id,
               d.created_at, LEFT(d.response_body, 300) AS response_body
        FROM webhook_deliveries d
        INNER JOIN webhook_endpoints w ON w.id = d.webhook_endpoint_id
        $where
        ORDER BY d.created_at DESC LIMIT $limit
    ");
    $stmt->execute($params);
    echo json_encode(['data' => $stmt->fetchAll()]);
    exit;
}

// ── GET single or list ────────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM webhook_endpoints WHERE id = :id AND deleted_at IS NULL AND account_id IN $tenantIn LIMIT 1");
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

            $stCnt = $pdo->prepare("SELECT COUNT(*) FROM webhook_deliveries WHERE webhook_endpoint_id = ?");
            $stCnt->execute([$id]);
            $cnt = (int)$stCnt->fetchColumn();
            $row['total_logs'] = $cnt;

            $row['success_rate'] = null;
            if ($cnt > 0) {
                $stOk = $pdo->prepare("SELECT COUNT(*) FROM webhook_deliveries WHERE webhook_endpoint_id = ? AND status = 'success'");
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
        (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.webhook_endpoint_id = w.id) AS total_logs,
        (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.webhook_endpoint_id = w.id AND d.status = 'success') AS success_logs,
        (SELECT MAX(d.created_at) FROM webhook_deliveries d WHERE d.webhook_endpoint_id = w.id) AS last_delivery
        FROM webhook_endpoints w WHERE w.deleted_at IS NULL AND w.account_id IN $tenantIn ORDER BY w.created_at DESC");
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
        // ─── LGPD P1 (2B.5): filtra por tenant ──────────────────────────────
        // Antes desta correção, qualquer owner/admin podia "testar" (disparar
        // entrega real) qualquer webhook do sistema passando o ID, gerando
        // log e payload em URL controlada por outro escritório.
        $stmt = $pdo->prepare(
            "SELECT * FROM webhook_endpoints WHERE id = :id AND deleted_at IS NULL AND account_id IN $tenantIn LIMIT 1"
        );
        $stmt->execute(['id' => $id] + $_whParams);
        // ───────────────────────────────────────────────────────────────────────
        $hook = $stmt->fetch();
        if (!$hook) { http_response_code(404); echo json_encode(['error' => 'Webhook not found']); exit; }

        $payload = WebhookDispatcher::buildPayload('webhook.test', [
            'entity'    => 'webhook',
            'entity_id' => $id,
            'data'      => ['mensagem' => 'Este é um evento de teste do Yuris CRM', 'webhook_nome' => $hook['nome']],
        ]);
        // FIX #28 (auditoria 2026-06-01): entrega DIRETA ao endpoint testado, sem passar
        // por fire()/findSubscribers(). Antes, um webhook que nao assinava 'webhook.test'
        // recebia zero entregas mas a API respondia success=true (sucesso enganoso).
        // Agora o resultado reflete a entrega REAL (status HTTP) e nunca promete sucesso falso.
        $res     = WebhookDispatcher::deliverTest($pdo, $hook, $payload);
        $ok      = $res['status'] === 'success';
        \App\Master\Account::audit($accountId, 'webhook.tested', [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'entidade'    => 'webhook',
            'entidade_id' => (int)$id,
            'detalhes'    => ['nome' => $hook['nome'], 'url' => $hook['url'], 'resultado' => $res['status'], 'http_status' => $res['http_status']],
        ]);
        if ($ok) {
            echo json_encode([
                'success'     => true,
                'message'     => 'Evento de teste entregue (HTTP ' . ($res['http_status'] ?? '2xx') . ')',
                'delivery_id' => $res['delivery_id'],
                'http_status' => $res['http_status'],
            ]);
        } else {
            // Status nao-2xx, retrying, canceled (ex.: SSRF) ou falha de conexao.
            $detalhe = $res['http_status'] !== null
                ? ('o endpoint respondeu HTTP ' . $res['http_status'])
                : 'o endpoint não respondeu';
            echo json_encode([
                'success'     => false,
                'error'       => 'Falha na entrega de teste: ' . $detalhe . '. Verifique os logs.',
                'delivery_id' => $res['delivery_id'],
                'http_status' => $res['http_status'],
                'status'      => $res['status'],
            ]);
        }
        exit;
    }

    // Etapa 9 — Rotacionar secret (gera novo, retorna UMA vez)
    if ($action === 'rotate_secret') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
        $stmt = $pdo->prepare("SELECT id, nome FROM webhook_endpoints WHERE id = :id AND deleted_at IS NULL AND account_id IN $tenantIn LIMIT 1");
        $stmt->execute(['id' => $id] + $_whParams);
        $hook = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$hook) { http_response_code(404); echo json_encode(['error' => 'Webhook not found']); exit; }
        $newSecret = bin2hex(random_bytes(32)); // 64 hex chars = 256 bits
        $pdo->prepare("UPDATE webhook_endpoints SET secret = ?, secret_rotated_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([$newSecret, $id]);
        \App\Master\Account::audit($accountId, 'webhook.secret_rotated', [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'entidade'    => 'webhook',
            'entidade_id' => (int)$id,
            'detalhes'    => ['nome' => $hook['nome']], // nunca logar o secret
        ]);
        echo json_encode(['success' => true, 'secret' => $newSecret, 'message' => 'Anote o secret — não será mostrado de novo.']);
        exit;
    }

    // Etapa 9 — Reenviar delivery (clona row com novo event_id, status=pending)
    if ($action === 'resend') {
        $deliveryId = (int)($input['delivery_id'] ?? 0);
        if (!$deliveryId) { http_response_code(400); echo json_encode(['error' => 'Missing delivery_id']); exit; }
        $stmt = $pdo->prepare(
            "SELECT d.* FROM webhook_deliveries d
               INNER JOIN webhook_endpoints e ON e.id = d.webhook_endpoint_id
              WHERE d.id = :id AND e.account_id IN $tenantIn LIMIT 1"
        );
        $stmt->execute(['id' => $deliveryId] + $_whParams);
        $d = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$d) { http_response_code(404); echo json_encode(['error' => 'Delivery não encontrada']); exit; }

        require_once __DIR__ . '/../../app/Integracoes/WebhookPayloadBuilder.php';
        $newEventId = \App\Integracoes\WebhookPayloadBuilder::generateEventId();
        $ins = $pdo->prepare(
            "INSERT INTO webhook_deliveries
               (webhook_endpoint_id, account_id, event_code, event_id, payload, request_url, status, tentativa, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', 1, NOW(), NOW())"
        );
        $ins->execute([
            (int)$d['webhook_endpoint_id'], (int)$d['account_id'],
            (string)$d['event_code'], $newEventId,
            (string)$d['payload'], (string)$d['request_url'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Tenta entregar agora (modo sync default). Em modo async, worker pega.
        $hk = $pdo->prepare("SELECT * FROM webhook_endpoints WHERE id = ?");
        $hk->execute([(int)$d['webhook_endpoint_id']]);
        $hook = $hk->fetch(\PDO::FETCH_ASSOC);
        if ($hook) {
            $row = $pdo->prepare("SELECT * FROM webhook_deliveries WHERE id = ?");
            $row->execute([$newId]);
            $rowData = $row->fetch(\PDO::FETCH_ASSOC);
            if ($rowData) {
                \App\Integracoes\WebhookDispatcher::processDelivery($pdo, $rowData);
            }
        }

        \App\Master\Account::audit($accountId, 'webhook.resent', [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'entidade'    => 'webhook_delivery',
            'entidade_id' => $newId,
            'detalhes'    => ['delivery_original' => $deliveryId, 'event_code' => $d['event_code']],
        ]);
        echo json_encode(['success' => true, 'delivery_id' => $newId, 'event_id' => $newEventId]);
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
    // Etapa 4: SSRF guard
    require_once __DIR__ . '/../../app/Core/WebhookUrlValidator.php';
    [$urlOk, $urlErr] = \App\Core\WebhookUrlValidator::isPublicUrl($url);
    if (!$urlOk) { http_response_code(400); echo json_encode(['error' => 'URL bloqueada: ' . $urlErr]); exit; }

    // Etapa 8: campos novos (defaults seguros)
    $payloadMode = in_array(($input['payload_mode'] ?? 'masked'), ['minimal','masked','full'], true)
        ? $input['payload_mode'] : 'masked';
    $escopo = in_array(($input['escopo'] ?? 'tenant_only'), ['tenant_only','matriz_e_filiais','filial_only'], true)
        ? $input['escopo'] : 'tenant_only';
    $timeoutSeg  = max(1, min(60, (int)($input['timeout_segundos'] ?? 10)));
    $retryEnabled= isset($input['retry_enabled']) ? (int)(bool)$input['retry_enabled'] : 1;
    $maxRetries  = max(1, min(10, (int)($input['max_retries'] ?? 3)));
    $headersJson = null;
    if (!empty($input['headers_customizados'])) {
        $h = is_array($input['headers_customizados'])
            ? $input['headers_customizados']
            : json_decode((string)$input['headers_customizados'], true);
        if (is_array($h)) $headersJson = json_encode($h, JSON_UNESCAPED_UNICODE);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO webhook_endpoints
           (account_id, nome, url, secret, ativo, eventos,
            payload_mode, escopo, timeout_segundos, retry_enabled, max_retries,
            headers_customizados, created_by, created_at, updated_at)
         VALUES (?,?,?,?,?,?, ?,?,?,?,?, ?,?, NOW(), NOW())"
    );
    $ok   = $stmt->execute([
        $accountId, $nome, $url, $secret ?: null, $ativo, json_encode(array_values($eventos)),
        $payloadMode, $escopo, $timeoutSeg, $retryEnabled, $maxRetries,
        $headersJson, $_SESSION['user_id'] ?? null,
    ]);
    $newId = (int)$pdo->lastInsertId();
    if ($ok) {
        \App\Master\Account::audit($accountId, 'webhook.created', [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'entidade'    => 'webhook',
            'entidade_id' => $newId,
            'detalhes'    => [
                'nome' => $nome, 'url' => $url, 'ativo' => $ativo,
                'eventos' => array_values($eventos),
                'payload_mode' => $payloadMode, 'escopo' => $escopo,
                'timeout_segundos' => $timeoutSeg,
                'retry_enabled' => $retryEnabled, 'max_retries' => $maxRetries,
                // secret omitido propositalmente — segredo não vai pra trilha
            ],
        ]);
    }
    echo json_encode(['success' => (bool)$ok, 'id' => $newId]);
    exit;
}

// ── PUT update ────────────────────────────────────────────────────────────────
if ($method === 'PUT' || $method === 'PATCH') {
    $id   = (int)($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }

    $fields = []; $params = [];
    $changed = [];
    if (isset($input['nome']))    { $fields[] = 'nome = ?';    $params[] = trim($input['nome']);            $changed['nome']  = trim($input['nome']); }
    if (isset($input['url'])) {
        $newUrl = trim($input['url']);
        // Etapa 4: SSRF guard tambem na edicao
        require_once __DIR__ . '/../../app/Core/WebhookUrlValidator.php';
        [$urlOk, $urlErr] = \App\Core\WebhookUrlValidator::isPublicUrl($newUrl);
        if (!$urlOk) { http_response_code(400); echo json_encode(['error' => 'URL bloqueada: ' . $urlErr]); exit; }
        $fields[] = 'url = ?';
        $params[] = $newUrl;
        $changed['url'] = $newUrl;
    }
    if (isset($input['secret']))  { $fields[] = 'secret = ?';  $params[] = trim($input['secret']) ?: null;  $changed['secret'] = '***'; /* nunca logar segredo em claro */ }
    if (isset($input['ativo']))   { $fields[] = 'ativo = ?';   $params[] = (int)$input['ativo'];            $changed['ativo'] = (int)$input['ativo']; }
    if (isset($input['eventos'])) {
        $ev = array_filter((array)$input['eventos'], fn($e) => in_array($e, WebhookDispatcher::allEventKeys()) || $e === '*');
        $fields[] = 'eventos = ?'; $params[] = json_encode(array_values($ev));
        $changed['eventos'] = array_values($ev);
    }
    // Etapa 8: campos novos
    if (isset($input['payload_mode']) && in_array($input['payload_mode'], ['minimal','masked','full'], true)) {
        $fields[] = 'payload_mode = ?'; $params[] = $input['payload_mode'];
        $changed['payload_mode'] = $input['payload_mode'];
    }
    if (isset($input['escopo']) && in_array($input['escopo'], ['tenant_only','matriz_e_filiais','filial_only'], true)) {
        $fields[] = 'escopo = ?'; $params[] = $input['escopo'];
        $changed['escopo'] = $input['escopo'];
    }
    if (isset($input['timeout_segundos'])) {
        $t = max(1, min(60, (int)$input['timeout_segundos']));
        $fields[] = 'timeout_segundos = ?'; $params[] = $t;
        $changed['timeout_segundos'] = $t;
    }
    if (isset($input['retry_enabled'])) {
        $r = (int)(bool)$input['retry_enabled'];
        $fields[] = 'retry_enabled = ?'; $params[] = $r;
        $changed['retry_enabled'] = $r;
    }
    if (isset($input['max_retries'])) {
        $m = max(1, min(10, (int)$input['max_retries']));
        $fields[] = 'max_retries = ?'; $params[] = $m;
        $changed['max_retries'] = $m;
    }
    if (isset($input['headers_customizados'])) {
        $h = is_array($input['headers_customizados'])
            ? $input['headers_customizados']
            : json_decode((string)$input['headers_customizados'], true);
        $hJson = is_array($h) ? json_encode($h, JSON_UNESCAPED_UNICODE) : null;
        $fields[] = 'headers_customizados = ?'; $params[] = $hJson;
        $changed['headers_customizados'] = $hJson ? '(custom)' : '(cleared)';
    }
    if (!$fields) { echo json_encode(['success' => true]); exit; }

    // snapshot pré-update (filtrado pelo tenant)
    $stPrev = $pdo->prepare("SELECT id, nome, url, ativo, eventos FROM webhook_endpoints WHERE id = :id AND account_id IN $tenantIn LIMIT 1");
    $stPrev->execute(['id' => $id] + $_whParams);
    $dadosAntes = $stPrev->fetch(PDO::FETCH_ASSOC) ?: null;

    $fields[] = 'updated_at = NOW()';
    // tenant placeholders (positional)
    $tenantPos = [];
    foreach ($tenantIds as $aid) $tenantPos[] = (int)$aid;
    $tenantInPos = '(' . implode(',', array_fill(0, count($tenantPos), '?')) . ')';
    $params[] = $id;
    foreach ($tenantPos as $aid) $params[] = $aid;
    $stmt = $pdo->prepare("UPDATE webhook_endpoints SET " . implode(', ', $fields) . " WHERE id = ? AND account_id IN $tenantInPos");
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit; }
    \App\Master\Account::audit($accountId, 'webhook.updated', [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'entidade'    => 'webhook',
        'entidade_id' => $id,
        'dados_antes' => $dadosAntes,
        'detalhes'    => $changed,
    ]);
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? ($input['id'] ?? 0));
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $stPrev = $pdo->prepare("SELECT id, nome, url, ativo, eventos FROM webhook_endpoints WHERE id = :id AND account_id IN $tenantIn LIMIT 1");
    $stPrev->execute(['id' => $id] + $_whParams);
    $dadosAntes = $stPrev->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt = $pdo->prepare("UPDATE webhook_endpoints SET deleted_at = NOW() WHERE id = :id AND account_id IN $tenantIn");
    $stmt->execute(['id' => $id] + $_whParams);
    if ($stmt->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit; }
    \App\Master\Account::audit($accountId, 'webhook.deleted', [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'entidade'    => 'webhook',
        'entidade_id' => $id,
        'dados_antes' => $dadosAntes,
    ]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
