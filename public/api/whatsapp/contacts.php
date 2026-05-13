<?php
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';

use App\Models\Database;

session_start(['read_and_close' => true]);
$_uid  = $_SESSION['user_id']    ?? null;
$_csrf = $_SESSION['csrf_token'] ?? '';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
if (!$_uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$method = $_SERVER['REQUEST_METHOD'];

try {
    $instModel  = new WhatsAppInstance();
    $cfg        = $instModel->getSettings();
    $instName   = $cfg['evolution_instance'] ?? 'yuris-crm';
    $row        = $instModel->findOrCreate($instName);
    $instanceId = (int)$row['id'];
    $pdo        = Database::getConnection();

    if ($method === 'GET') {
        $stmt = $pdo->prepare(
            'SELECT remote_jid, push_name, phone FROM whatsapp_contacts
             WHERE instance_id = ? ORDER BY push_name ASC'
        );
        $stmt->execute([$instanceId]);
        echo json_encode(['ok' => true, 'contacts' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($payload['_csrf']) || $payload['_csrf'] !== $_csrf) {
            http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit;
        }
        $jid  = trim($payload['remote_jid'] ?? '');
        $name = trim($payload['push_name']  ?? '');
        if (!$jid) { http_response_code(400); echo json_encode(['error' => 'remote_jid obrigatório']); exit; }

        $pdo->prepare(
            'UPDATE whatsapp_contacts SET push_name = ? WHERE instance_id = ? AND remote_jid = ?'
        )->execute([$name, $instanceId, $jid]);

        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(405); echo json_encode(['error' => 'Method not allowed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
