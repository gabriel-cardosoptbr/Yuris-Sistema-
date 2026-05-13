<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Services/WebhookDispatcher.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Models\Database;
use App\Services\WebhookDispatcher;
use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

// Contexto de tenant obrigatório — aborta com 401 se inválido
$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

$_validPages = ['dashboard','planejamento','prospeccao','financas','processos','juridico','usuarios','agente','chat','chat_interno','configuracoes'];

// GET: list or single — FILTRADO POR CONTA (tenant isolation)
if ($method === 'GET') {
    $id  = $_GET['id'] ?? null;
    $pdo = Database::getConnection();
    $requesterId = $ctx->getUserId();

    // detecta quais colunas extras existem
    $hasAccountCol  = false;
    $hasRoleCol     = false;
    $hasSenhaTexto  = false;
    try { $pdo->query('SELECT account_id FROM users LIMIT 0');     $hasAccountCol  = true;  } catch (\Throwable $e) {}
    try { $pdo->query('SELECT role FROM users LIMIT 0');            $hasRoleCol     = true;  } catch (\Throwable $e) {}
    try { $pdo->query('SELECT senha_texto FROM users LIMIT 0');     $hasSenhaTexto  = true;  } catch (\Throwable $e) {}
    $hasCodigo = false;
    try { $pdo->query('SELECT codigo_vinculo FROM users LIMIT 0');  $hasCodigo      = true;  } catch (\Throwable $e) {}

    $selRole     = $hasRoleCol    ? 'role'           : 'perfil AS role';
    $selAccount  = $hasAccountCol ? 'account_id'     : 'NULL AS account_id';
    $selSenha    = $hasSenhaTexto ? 'senha_texto'    : 'NULL AS senha_texto';
    $selCodigo   = $hasCodigo     ? 'codigo_vinculo' : 'NULL AS codigo_vinculo';

    if ($id) {
        $where = $hasAccountCol
            ? 'WHERE id = :id AND deleted_at IS NULL AND (account_id = :acc OR id = :self)'
            : 'WHERE id = :id AND deleted_at IS NULL';
        $params = $hasAccountCol
            ? ['id' => $id, 'acc' => $accountId, 'self' => $requesterId]
            : ['id' => $id];

        $stmt = $pdo->prepare(
            "SELECT id, nome, login AS email, perfil, $selRole, status, $selAccount, $selSenha, $selCodigo, created_at, updated_at
             FROM users $where LIMIT 1"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row) {
            $row['senha_plain'] = $row['senha_texto'] ?? '';
            unset($row['senha_texto']);
            try {
                $ps = $pdo->prepare('SELECT page FROM user_permissions WHERE user_id = ?');
                $ps->execute([$id]);
                $row['permissions'] = $ps->fetchAll(PDO::FETCH_COLUMN);
            } catch (\Throwable $e) { $row['permissions'] = []; }
        }
        echo json_encode(['data' => $row ? [$row] : []]);
        exit;
    }

    // Lista usuários
    if ($hasAccountCol) {
        $stmt = $pdo->prepare(
            "SELECT id, nome, login AS email, perfil, $selRole, status, $selAccount, $selCodigo, created_at, updated_at
             FROM users WHERE deleted_at IS NULL AND account_id = :acc ORDER BY nome ASC"
        );
        $stmt->execute(['acc' => $accountId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, nome, login AS email, perfil, $selRole, status, $selAccount, $selCodigo, created_at, updated_at
             FROM users WHERE deleted_at IS NULL ORDER BY nome ASC"
        );
        $stmt->execute();
    }
    $rows = $stmt->fetchAll();
    echo json_encode(['data' => $rows]);
    exit;
}

// state-changing methods require CSRF check
if (in_array($method, ['POST','PUT','DELETE','PATCH'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

$pdo = Database::getConnection();

// garante tabela user_permissions e coluna senha_texto
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        page VARCHAR(100) NOT NULL,
        UNIQUE KEY uk_user_page (user_id, page)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}
try {
    $pdo->query('SELECT senha_texto FROM users LIMIT 0');
} catch (\Throwable $e) {
    $pdo->exec('ALTER TABLE users ADD COLUMN senha_texto VARCHAR(255) DEFAULT NULL');
}

if ($method === 'POST') {
    $nome = trim($input['nome'] ?? '');
    $email = trim($input['email'] ?? '');
    $senha = $input['senha'] ?? '';
    $perfil = $input['perfil'] ?? 'user';
    if ($nome === '' || $email === '' || $senha === '') {
        http_response_code(400); echo json_encode(['error' => 'Missing fields']); exit;
    }
    // simple uniqueness check
    $stmt = $pdo->prepare('SELECT id FROM users WHERE login = :login AND deleted_at IS NULL LIMIT 1');
    $stmt->execute(['login' => $email]);
    if ($stmt->fetch()) { http_response_code(400); echo json_encode(['error'=>'User exists']); exit; }
    // Apenas owner/admin pode criar usuários
    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode criar usuários']);
        exit;
    }

    $role = $input['role'] ?? 'user';
    if (!in_array($role, ['owner','admin','manager','user','viewer'])) $role = 'user';
    // Protege: somente owner pode criar outro owner
    if ($role === 'owner' && !$ctx->isOwner()) $role = 'admin';

    $hash = password_hash($senha, PASSWORD_BCRYPT);

    // detecta colunas opcionais para montar INSERT dinamicamente
    // Gera codigo_vinculo único para o novo usuário
    $codigoVinculo = implode('-', str_split(bin2hex(random_bytes(8)), 4));

    $cols   = ['nome'=>$nome,'login'=>$email,'senha_hash'=>$hash,'perfil'=>$perfil,'status'=>'active'];
    $checks = ['account_id'=>$accountId,'role'=>$role,'senha_texto'=>$senha,'codigo_vinculo'=>$codigoVinculo];
    foreach ($checks as $col => $val) {
        try { $pdo->query("SELECT $col FROM users LIMIT 0"); $cols[$col] = $val; } catch (\Throwable $e) {}
    }
    $colNames = implode(', ', array_keys($cols));
    $colPlaceholders = ':' . implode(', :', array_keys($cols));
    $stmt = $pdo->prepare("INSERT INTO users ($colNames, created_at, updated_at) VALUES ($colPlaceholders, NOW(), NOW())");
    $ok = $stmt->execute($cols);
    if ($ok) {
        $newId = (int)$pdo->lastInsertId();
        // salva permissões individuais (apenas para não-admin)
        $permissions = $input['permissions'] ?? [];
        if ($perfil !== 'admin' && is_array($permissions) && $permissions) {
            try {
                $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$newId]);
                $insP = $pdo->prepare('INSERT INTO user_permissions (user_id, page) VALUES (?,?)');
                foreach ($permissions as $page) {
                    if (in_array($page, $_validPages)) $insP->execute([$newId, $page]);
                }
            } catch (\Throwable $e) { /* tabela pode não existir */ }
        }
        WebhookDispatcher::fire('usuario.created', WebhookDispatcher::buildPayload('usuario.created', [
            'entity' => 'usuario', 'entity_id' => $newId,
            'data' => ['id' => $newId, 'nome' => $nome, 'email' => $email, 'perfil' => $perfil],
        ]));
        echo json_encode(['success'=>true,'id'=>$newId]);
    } else { echo json_encode(['success'=>false]); }
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
    $id = $input['id'] ?? null;
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }

    // user #1 is the root admin — only block perfil change; allow name/email/password
    $isRoot = ((int)$id === 1);

    $nome   = isset($input['nome'])   ? trim($input['nome'])  : null;
    $email  = isset($input['email'])  ? trim($input['email']) : null;
    $perfil = ($isRoot || !isset($input['perfil'])) ? null : $input['perfil'];
    $senha  = isset($input['senha'])  ? $input['senha']       : null;
    $fields = [];
    $params = ['id'=>$id];

    // prevent removing the last admin
    if ($perfil !== null) {
        $stmtCheck = $pdo->prepare('SELECT perfil FROM users WHERE id = :id LIMIT 1');
        $stmtCheck->execute(['id'=>$id]);
        $oldPerfil = $stmtCheck->fetchColumn();
        if ($oldPerfil === 'admin' && $perfil !== 'admin') {
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM users WHERE perfil = 'admin' AND deleted_at IS NULL AND id != :id");
            $stmtCount->execute(['id'=>$id]);
            $cnt = (int)$stmtCount->fetchColumn();
            if ($cnt <= 0) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Cannot remove last admin']); exit; }
        }
    }
    // detecta colunas opcionais
    $hasSenhaTexto = false;
    $hasRoleCol    = false;
    try { $pdo->query('SELECT senha_texto FROM users LIMIT 0'); $hasSenhaTexto = true; } catch (\Throwable $e) {}
    try { $pdo->query('SELECT role FROM users LIMIT 0');        $hasRoleCol    = true; } catch (\Throwable $e) {}

    if ($nome   !== null) { $fields[] = 'nome = :nome';     $params['nome']  = $nome; }
    if ($email  !== null) { $fields[] = 'login = :login';   $params['login'] = $email; }
    if ($perfil !== null) { $fields[] = 'perfil = :perfil'; $params['perfil'] = $perfil; }
    if ($hasRoleCol && isset($input['role'])) {
        $r = $input['role'];
        if (in_array($r, ['owner','admin','manager','user','viewer'])) {
            $fields[] = 'role = :role'; $params['role'] = $r;
        }
    }
    if ($senha !== null && $senha !== '') {
        $fields[] = 'senha_hash = :senha_hash';
        $params['senha_hash'] = password_hash($senha, PASSWORD_BCRYPT);
        if ($hasSenhaTexto) { $fields[] = 'senha_texto = :senha_texto'; $params['senha_texto'] = $senha; }
    }

    if (count($fields) > 0) {
        $sql  = 'UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
        $pdo->prepare($sql)->execute($params);
    }

    $permissionsChanged = false;
    // save permissions (only for non-admin users)
    if (isset($input['permissions']) && is_array($input['permissions'])) {
        try {
            $stmtPerfil = $pdo->prepare('SELECT perfil FROM users WHERE id = ? LIMIT 1');
            $stmtPerfil->execute([$id]);
            $currentPerfil = $stmtPerfil->fetchColumn();
            if ($currentPerfil !== 'admin') {
                $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$id]);
                $ins = $pdo->prepare('INSERT IGNORE INTO user_permissions (user_id, page) VALUES (?, ?)');
                foreach ($input['permissions'] as $pg) {
                    if (in_array($pg, $_validPages)) $ins->execute([$id, $pg]);
                }
                $permissionsChanged = true;
            }
        } catch (\Throwable $e) { /* tabela user_permissions pode não existir */ }
    }

    // fire appropriate webhook event
    $wEventKey = 'usuario.updated';
    if ($senha !== null && $senha !== '') {
        $wEventKey = 'usuario.senha_changed';
    } elseif ($permissionsChanged) {
        $wEventKey = 'usuario.permission_changed';
    }
    $wRow = $pdo->prepare('SELECT id, nome, login AS email, perfil FROM users WHERE id = ? LIMIT 1');
    $wRow->execute([$id]);
    WebhookDispatcher::fire($wEventKey, WebhookDispatcher::buildPayload($wEventKey, [
        'entity' => 'usuario', 'entity_id' => (int)$id,
        'data' => $wRow->fetch(PDO::FETCH_ASSOC),
    ]));

    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? ($input['id'] ?? null);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    if ((int)$id === 1) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'O usuário raiz não pode ser excluído']); exit; }
    $prevUser = $pdo->prepare('SELECT id, nome, login AS email, perfil FROM users WHERE id = ? LIMIT 1');
    $prevUser->execute([$id]);
    $prevUser = $prevUser->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = :id');
    $ok = $stmt->execute(['id'=>$id]);
    if ($ok && $prevUser) {
        WebhookDispatcher::fire('usuario.deleted', WebhookDispatcher::buildPayload('usuario.deleted', [
            'entity' => 'usuario', 'entity_id' => (int)$id, 'data' => $prevUser,
        ]));
    }
    echo json_encode(['success'=> (bool)$ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);
