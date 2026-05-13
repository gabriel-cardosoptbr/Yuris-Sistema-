<?php
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppMessage.php';

session_start(['read_and_close' => true]);
$_uid = $_SESSION['user_id'] ?? null;

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
if (!$_uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$method    = $_SERVER['REQUEST_METHOD'];
$remoteJid = trim($_GET['jid'] ?? '');
$beforeId  = (int)($_GET['before_id'] ?? 0);
$afterId   = (int)($_GET['after_id']  ?? 0);
$limit     = min((int)($_GET['limit'] ?? 50), 100);

if ($method !== 'GET') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}
if (!$remoteJid) {
    http_response_code(400); echo json_encode(['error' => 'jid obrigatório']); exit;
}

try {
    $instModel  = new WhatsAppInstance();
    $msgModel   = new WhatsAppMessage();
    $cfg        = $instModel->getSettings();
    $instName   = $cfg['evolution_instance'] ?? 'yuris-crm';
    $row        = $instModel->findOrCreate($instName);
    $instanceId = (int)$row['id'];

    $msgs = $afterId > 0
        ? $msgModel->findAfter($instanceId, $remoteJid, $afterId)
        : $msgModel->findByJid($instanceId, $remoteJid, $limit, $beforeId);

    echo json_encode([
        'ok'       => true,
        'messages' => $msgs,
        'jid'      => $remoteJid,
        'count'    => count($msgs),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
}
