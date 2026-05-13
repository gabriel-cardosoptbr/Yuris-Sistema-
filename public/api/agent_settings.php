<?php
require_once __DIR__ . '/../../app/Models/Database.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    $res = $_SESSION['agent_settings'] ?? [
        'name' => '', 'enabled' => false, 'whatsapp_number' => '', 'provider' => '', 'api_key' => '', 'prompt' => ''
    ];
    echo json_encode($res);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $allowed = ['name','enabled','whatsapp_number','provider','api_key','prompt'];
    $s = [];
    foreach ($allowed as $k) {
        if (isset($input[$k])) $s[$k] = $input[$k];
    }
    // normalize enabled
    $s['enabled'] = !empty($s['enabled']) && ($s['enabled'] === true || $s['enabled'] === '1' || $s['enabled'] === 1);
    $_SESSION['agent_settings'] = $s;
    echo json_encode(['success'=>true,'data'=>$s]);
    exit;
}

http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);
exit;
