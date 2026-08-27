<?php
/**
 * Painel Master — leitura do master_audit_log.
 *
 * GET /api/master/audit.php
 *   ?super_admin_id=X
 *   ?target_type=account|user|plan|subscription|invoice
 *   ?target_id=X
 *   ?acao=account.create   (LIKE)
 *   ?from=YYYY-MM-DD
 *   ?to=YYYY-MM-DD
 *   ?limit=N (default 100, max 500)
 *
 * Acesso: super_admin apenas.
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ApiResponse.php';
require_once __DIR__ . '/../../../app/Master/MasterAudit.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') ApiResponse::methodNotAllowed();

$filters = [];
foreach (['super_admin_id','target_type','target_id','acao','from','to'] as $k) {
    if (!empty($_GET[$k])) $filters[$k] = $_GET[$k];
}
$limit = (int) ($_GET['limit'] ?? 100);

$rows = MasterAudit::list($filters, $limit);

// Decodifica metadata pra cada row
foreach ($rows as &$r) {
    if (!empty($r['metadata'])) {
        $decoded = json_decode($r['metadata'], true);
        $r['metadata'] = $decoded ?: $r['metadata'];
    }
}

ApiResponse::ok(['entries' => $rows]);
