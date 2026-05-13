<?php
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';

session_start(['read_and_close' => true]);
$_uid  = $_SESSION['user_id']    ?? null;
$_csrf = $_SESSION['csrf_token'] ?? '';
header('Content-Type: application/json; charset=utf-8');
if (!$_uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$model  = new WhatsAppInstance();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(['ok' => true, 'settings' => $model->getSettings()]);
    exit;
}

if ($method === 'POST') {
    $csrf    = $_csrf;
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($payload['_csrf']) || $payload['_csrf'] !== $csrf) {
        http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit;
    }

    $allowed = ['evolution_base_url', 'evolution_api_key', 'evolution_instance', 'webhook_enabled', 'webhook_url'];
    foreach ($allowed as $key) {
        if (isset($payload[$key])) {
            $model->saveSetting($key, (string)$payload[$key]);
        }
    }

    echo json_encode(['ok' => true, 'settings' => $model->getSettings()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
