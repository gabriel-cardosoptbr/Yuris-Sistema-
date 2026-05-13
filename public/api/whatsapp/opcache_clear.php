<?php
require_once __DIR__ . '/../../../../app/Models/Database.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

$results = [];
$files = [
    realpath(__DIR__ . '/../../../../app/Models/WhatsAppInstance.php'),
    realpath(__DIR__ . '/../../../../app/Models/WhatsAppMessage.php'),
    realpath(__DIR__ . '/../../../../app/Services/EvolutionApiService.php'),
    realpath(__FILE__),
    realpath(dirname(__FILE__) . '/chats.php'),
    realpath(dirname(__FILE__) . '/instances.php'),
    realpath(dirname(__FILE__) . '/messages.php'),
    realpath(dirname(__FILE__) . '/send.php'),
    realpath(dirname(__FILE__) . '/config.php'),
    realpath(dirname(__FILE__) . '/webhook.php'),
];

if (function_exists('opcache_reset')) {
    $reset = opcache_reset();
    $results['opcache_reset'] = $reset ? 'OK' : 'FAILED';
} else {
    $results['opcache_reset'] = 'NOT_AVAILABLE';
}

foreach ($files as $file) {
    if ($file && file_exists($file)) {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
            $results['invalidated'][] = basename($file);
        }
        // Touch file to change mtime
        touch($file);
        $results['touched'][] = basename($file);
    }
}

echo json_encode(['ok' => true, 'results' => $results]);
