<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Helpers/TenantGuard.php';

use App\Helpers\TenantGuard;

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}

// CSRF (auditoria 2026-06-01): POST gravava periodo na sessao sem validar.
TenantGuard::requireSameOriginOrCsrf();

$method = $_SERVER['REQUEST_METHOD'];
// GET: return current settings
if ($method === 'GET') {
    $res = [
        'start' => $_SESSION['dashboard_start'] ?? null,
        'end' => $_SESSION['dashboard_end'] ?? null
    ];
    echo json_encode($res);
    exit;
}

// POST: accept JSON {start, end} and store in session (validate YYYY-MM-DD)
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $start = isset($input['start']) ? trim($input['start']) : null;
    $end = isset($input['end']) ? trim($input['end']) : null;
    $ok = true;
    $err = null;
    $isDate = function($s){ return preg_match('/^\d{4}-\d{2}-\d{2}$/',$s); };
    if ($start !== null && $start !== '' && !$isDate($start)) { $ok = false; $err = 'Invalid start date'; }
    if ($end !== null && $end !== '' && !$isDate($end)) { $ok = false; $err = 'Invalid end date'; }
    if (!$ok) { http_response_code(400); echo json_encode(['error'=>$err]); exit; }
    if ($start === '') $start = null;
    if ($end === '') $end = null;
    $_SESSION['dashboard_start'] = $start;
    $_SESSION['dashboard_end'] = $end;
    $userId    = (int)$_SESSION['user_id'];
    $accountId = (int)($_SESSION['account_id'] ?? 0);
    if ($accountId > 0) {
        \App\Models\Account::audit($accountId, 'dashboard_settings.updated', [
            'user_id'     => $userId,
            'entidade'    => 'dashboard_settings',
            'entidade_id' => $userId,
            'detalhes'    => ['start' => $start, 'end' => $end],
        ]);
    }
    echo json_encode(['success'=>true,'start'=>$start,'end'=>$end]);
    exit;
}

http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);
exit;
