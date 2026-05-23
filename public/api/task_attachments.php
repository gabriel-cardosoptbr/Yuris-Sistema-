<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../app/Helpers/TenantGuard.php';
require_once __DIR__ . '/../../app/Helpers/ProcessoAudit.php';
require_once __DIR__ . '/../../app/Helpers/TaskAudit.php';

use App\Helpers\AccountContext;
use App\Helpers\TenantGuard;
use App\Helpers\TaskAudit;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx = AccountContext::fromSession();

$userId = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

function fail(string $msg, int $c = 400): void { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
function ok(mixed $d = null): void { echo json_encode(['ok'=>true,'data'=>$d]); exit; }

// mime-types permitidos (sem executáveis)
const ALLOWED_MIME = [
    'image/jpeg','image/png','image/gif','image/webp','image/svg+xml',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain','text/csv',
    'application/zip','application/x-zip-compressed',
];

if ($method === 'GET') {
    $taskId = (int)($_GET['task_id'] ?? 0);
    if (!$taskId) fail('task_id obrigatório');
    TenantGuard::assertTaskAcessivel($ctx, $taskId);

    $pdo  = \App\Models\Database::getConnection();
    $stmt = $pdo->prepare('SELECT * FROM task_attachments WHERE task_id = ? ORDER BY created_at DESC');
    $stmt->execute([$taskId]);
    ok($stmt->fetchAll(\PDO::FETCH_ASSOC));
}

if ($method === 'POST') {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) fail('CSRF inválido');

    $taskId = (int)($_POST['task_id'] ?? 0);
    if (!$taskId) fail('task_id obrigatório');
    TenantGuard::assertTaskAcessivel($ctx, $taskId);
    if (empty($_FILES['file'])) fail('Arquivo obrigatório');

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) fail('Erro no upload');

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mime     = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, ALLOWED_MIME)) fail('Tipo de arquivo não permitido');

    $dir = __DIR__ . '/../uploads/tasks/' . $taskId . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $dest     = $dir . uniqid() . '_' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) fail('Falha ao salvar arquivo');

    $pdo = \App\Models\Database::getConnection();
    $pdo->prepare('INSERT INTO task_attachments (task_id, file_path, file_name, mime_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?)')
        ->execute([$taskId, str_replace(__DIR__ . '/..', '', $dest), $file['name'], $mime, $file['size'], $userId]);
    // Propaga ao histórico processual se a tarefa está vinculada a processo(s)
    TaskAudit::onAttachmentAdded($taskId, (string)$file['name']);
    ok(['id' => $pdo->lastInsertId()]);
}

if ($method === 'DELETE') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) fail('CSRF inválido');

    $id  = (int)($input['id'] ?? 0);
    $pdo = \App\Models\Database::getConnection();
    $row = $pdo->prepare('SELECT * FROM task_attachments WHERE id = ? AND uploaded_by = ?');
    $row->execute([$id, $userId]);
    $att = $row->fetch(\PDO::FETCH_ASSOC);
    if (!$att) fail('Não encontrado', 404);

    $fullPath = __DIR__ . '/..' . $att['file_path'];
    if (file_exists($fullPath)) unlink($fullPath);
    $pdo->prepare('DELETE FROM task_attachments WHERE id = ?')->execute([$id]);
    // Propaga ao histórico processual
    if (!empty($att['task_id'])) TaskAudit::onAttachmentRemoved((int)$att['task_id'], (string)($att['file_name'] ?? ''));
    ok();
}

fail('Método não suportado', 405);
