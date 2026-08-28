<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Webhooks\WebhookDispatcher;
use App\Core\AccountContext;
use App\Billing\PlanFeature;

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
    try { $pdo->query('SELECT account_id FROM users LIMIT 0');     $hasAccountCol  = true;  } catch (\Throwable $e) {}
    try { $pdo->query('SELECT role FROM users LIMIT 0');            $hasRoleCol     = true;  } catch (\Throwable $e) {}
    // senha_texto foi removida do schema (Fase 0 audit — LGPD). Nenhuma senha em
    // texto plano é lida/retornada (audit #21: caminho morto removido).
    $hasCodigo = false;
    try { $pdo->query('SELECT codigo_advogado FROM users LIMIT 0'); $hasCodigo = true;  } catch (\Throwable $e) {}

    $selRole     = $hasRoleCol    ? 'role'           : 'perfil AS role';
    $selAccount  = $hasAccountCol ? 'account_id'     : 'NULL AS account_id';
    // codigo_advogado é o ID universal do advogado (formato ADV-XXXXXX).
    // Retornamos no nome legado "codigo_vinculo" para compat com o frontend existente.
    $selCodigo   = $hasCodigo     ? 'codigo_advogado AS codigo_vinculo' : 'NULL AS codigo_vinculo';

    // Variantes qualificadas com o alias "u." para a listagem com JOIN em accounts
    // (a query single-row acima não usa alias). Em colunas inexistentes o fallback
    // é "NULL AS ..." — que NÃO pode receber prefixo "u.", por isso geramos à parte.
    $selRoleU    = $hasRoleCol    ? 'u.role'             : 'u.perfil AS role';
    $selCodigoU  = $hasCodigo     ? 'u.codigo_advogado AS codigo_vinculo' : 'NULL AS codigo_vinculo';

    if ($id) {
        $where = $hasAccountCol
            ? 'WHERE id = :id AND deleted_at IS NULL AND (account_id = :acc OR id = :self)'
            : 'WHERE id = :id AND deleted_at IS NULL';
        $params = $hasAccountCol
            ? ['id' => $id, 'acc' => $accountId, 'self' => $requesterId]
            : ['id' => $id];

        $stmt = $pdo->prepare(
            "SELECT id, nome, login AS email, perfil, $selRole, status, $selAccount, $selCodigo, created_at, updated_at
             FROM users $where LIMIT 1"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row) {
            try {
                $ps = $pdo->prepare('SELECT page FROM user_permissions WHERE user_id = ?');
                $ps->execute([$id]);
                $row['permissions'] = $ps->fetchAll(PDO::FETCH_COLUMN);
            } catch (\Throwable $e) { $row['permissions'] = []; }
        }
        echo json_encode(['data' => $row ? [$row] : []]);
        exit;
    }

    // Lista usuários — display_id é sequencial dentro da conta (1, 2, 3, ...).
    // O id físico (PK auto_increment) continua disponível para chaves técnicas.
    if ($hasAccountCol) {
        // FIX (audit #20/#24): antes a listagem filtrava por UMA conta só
        // (account_id = :acc), então a matriz nunca via usuários das filiais.
        // Agora escopamos por getAccessibleAccountIds() (matriz + filiais
        // vinculadas + advogados) e fazemos JOIN em accounts para devolver
        // account_nome/account_tipo — espelhando getAccessibleUsers(). Isso
        // habilita o agrupamento Matriz/Filial/Advogado em populateUserSelect
        // e o seletor de membros de Setores enxergar filiais.
        $accessibleIds = $ctx->getAccessibleAccountIds();
        $ph     = [];
        $params = [];
        foreach ($accessibleIds as $i => $aid) {
            $key          = "acc_{$i}";
            $ph[]         = ":{$key}";
            $params[$key] = (int)$aid;
        }
        $inClause = implode(',', $ph);
        $stmt = $pdo->prepare(
            "SELECT u.id,
                    ROW_NUMBER() OVER (PARTITION BY u.account_id ORDER BY u.id ASC) AS display_id,
                    u.nome, u.login AS email, u.perfil, $selRoleU, u.status,
                    u.account_id,
                    a.nome AS account_nome,
                    a.tipo AS account_tipo,
                    $selCodigoU, u.created_at, u.updated_at
             FROM users u
             INNER JOIN accounts a ON a.id = u.account_id
             WHERE u.deleted_at IS NULL AND u.account_id IN ($inClause)
             ORDER BY
                CASE WHEN a.tipo = 'matriz' THEN 0 ELSE 1 END,
                a.nome ASC,
                u.nome ASC"
        );
        $stmt->execute($params);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id,
                    ROW_NUMBER() OVER (ORDER BY id ASC) AS display_id,
                    nome, login AS email, perfil, $selRole, status, $selAccount, $selCodigo, created_at, updated_at
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

// garante tabela user_permissions (senha_texto foi REMOVIDA — Fase 0 audit/LGPD)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        page VARCHAR(100) NOT NULL,
        UNIQUE KEY uk_user_page (user_id, page)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

if ($method === 'POST') {
    $nome = trim($input['nome'] ?? '');
    $email = trim($input['email'] ?? '');
    $senha = $input['senha'] ?? '';
    $perfil = $input['perfil'] ?? 'user';
    if ($nome === '' || $email === '' || $senha === '') {
        http_response_code(400); echo json_encode(['error' => 'Missing fields']); exit;
    }
    // LGPD + integridade: email único no sistema (DB tem UNIQUE em users.login,
    // mas validar aqui pra dar mensagem amigável em vez de constraint violation crua).
    $stmt = $pdo->prepare('SELECT id FROM users WHERE login = :login AND deleted_at IS NULL LIMIT 1');
    $stmt->execute(['login' => $email]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Já existe um usuário com este email no sistema']);
        exit;
    }
    // Apenas owner/admin pode criar usuários
    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode criar usuários']);
        exit;
    }

    // Limite de usuários do plano (402 se estourou). Ordem igual à de
    // push/monitors.php: permissão primeiro, cota depois, criação por último.
    PlanFeature::assertCanAddUser($ctx->getAccountId());

    $role = $input['role'] ?? 'user';
    if (!in_array($role, ['owner','admin','manager','user','viewer'])) $role = 'user';
    // Protege: somente owner pode criar outro owner
    if ($role === 'owner' && !$ctx->isOwner()) $role = 'admin';

    $hash = password_hash($senha, PASSWORD_BCRYPT);

    // Gera ID universal do advogado (ADV-XXXXXX).
    // Único em toda a plataforma — independe de matriz/filial/associado.
    $codigoAdvogado = 'ADV-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

    $cols   = ['nome'=>$nome,'login'=>$email,'senha_hash'=>$hash,'perfil'=>$perfil,'status'=>'active'];
    // senha_texto REMOVIDA do checks (Fase 0 audit/LGPD)
    $checks = [
        'account_id'      => $accountId,
        'role'            => $role,
        'codigo_vinculo'  => $codigoAdvogado,   // legado — preenchido com o mesmo valor pra manter compat
        'codigo_advogado' => $codigoAdvogado,
    ];
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
        WebhookDispatcher::fire($accountId, 'usuario.created', WebhookDispatcher::buildPayload('usuario.created', [
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

    // ─── LGPD P0: IDOR FIX ─────────────────────────────────────────────────────
    // Antes desta correção, qualquer admin/owner do tenant A podia trocar senha
    // ou alterar role de usuário do tenant B passando o id no body — porque
    // o UPDATE final usava apenas `WHERE id = :id`, sem `AND account_id = :acc`.
    // Aqui validamos antes de prosseguir: usuário precisa existir no tenant
    // corrente (ou ser o próprio requester, que sempre pode editar a si).
    $isRoot      = ((int)$id === 1);
    $requesterId = $ctx->getUserId();
    if ((int)$id !== $requesterId) {
        $stmtTenant = $pdo->prepare(
            'SELECT id FROM users WHERE id = :id AND account_id = :acc AND deleted_at IS NULL LIMIT 1'
        );
        $stmtTenant->execute(['id' => $id, 'acc' => $accountId]);
        if (!$stmtTenant->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'Usuário não encontrado no seu tenant']);
            exit;
        }
    }
    // ───────────────────────────────────────────────────────────────────────────

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
    // detecta colunas opcionais (senha_texto foi REMOVIDA — Fase 0 audit)
    $hasRoleCol    = false;
    try { $pdo->query('SELECT role FROM users LIMIT 0');        $hasRoleCol    = true; } catch (\Throwable $e) {}

    if ($nome   !== null) { $fields[] = 'nome = :nome';     $params['nome']  = $nome; }
    if ($email  !== null) {
        // LGPD + integridade: email único no sistema. Trocar pra um email
        // que JÁ pertence a outro user (qualquer tenant) deve ser bloqueado
        // ANTES do UPDATE — senão estoura o UNIQUE constraint cru.
        $dup = $pdo->prepare('SELECT id FROM users WHERE login = :em AND id != :id AND deleted_at IS NULL LIMIT 1');
        $dup->execute(['em' => $email, 'id' => $id]);
        if ($dup->fetchColumn()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Já existe outro usuário com este email no sistema']);
            exit;
        }
        $fields[] = 'login = :login';   $params['login'] = $email;
    }
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
        // NUNCA mais salvamos senha em texto plano (Fase 0 audit/LGPD)
    }

    if (count($fields) > 0) {
        // P0 LGPD: incluir account_id no WHERE como defesa em profundidade
        // (mesmo com a verificação acima, garante que SQL nunca atinge outro tenant).
        // Para o próprio requester editando a si mesmo, $accountId já está correto.
        $params['acc'] = $accountId;
        $sql  = 'UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id AND account_id = :acc';
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
    $userAfter = $wRow->fetch(PDO::FETCH_ASSOC);
    WebhookDispatcher::fire($accountId, $wEventKey, WebhookDispatcher::buildPayload($wEventKey, [
        'entity' => 'usuario', 'entity_id' => (int)$id,
        'data' => $userAfter,
    ]));

    // LGPD Etapa 4: audit (acao depende do que mudou)
    \App\Master\Account::audit($accountId, $wEventKey, [
        'user_id'     => $requesterId,
        'entidade'    => 'user',
        'entidade_id' => (int)$id,
        'detalhes'    => $userAfter,
    ]);

    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? ($input['id'] ?? null);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    if ((int)$id === 1) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'O usuário raiz não pode ser excluído']); exit; }

    // ─── LGPD P0: IDOR FIX ─────────────────────────────────────────────────────
    // Bloqueia delete cross-tenant. Admin do tenant A não pode deletar usuário
    // de tenant B passando o id no path. Usuário precisa pertencer ao próprio
    // tenant (e não pode se auto-deletar — protege contra acidente).
    $requesterId = $ctx->getUserId();
    if ((int)$id === $requesterId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Você não pode excluir sua própria conta por aqui']);
        exit;
    }
    $stmtTenant = $pdo->prepare(
        'SELECT id FROM users WHERE id = :id AND account_id = :acc AND deleted_at IS NULL LIMIT 1'
    );
    $stmtTenant->execute(['id' => $id, 'acc' => $accountId]);
    if (!$stmtTenant->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado no seu tenant']);
        exit;
    }
    // Apenas owner/admin pode excluir outros usuários do tenant
    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode excluir usuários']);
        exit;
    }
    // ───────────────────────────────────────────────────────────────────────────

    $prevUser = $pdo->prepare('SELECT id, nome, login AS email, perfil FROM users WHERE id = ? LIMIT 1');
    $prevUser->execute([$id]);
    $prevUser = $prevUser->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = :id AND account_id = :acc');
    $ok = $stmt->execute(['id' => $id, 'acc' => $accountId]);
    if ($ok && $prevUser) {
        WebhookDispatcher::fire($accountId, 'usuario.deleted', WebhookDispatcher::buildPayload('usuario.deleted', [
            'entity' => 'usuario', 'entity_id' => (int)$id, 'data' => $prevUser,
        ]));
        // LGPD Etapa 4: audit
        \App\Master\Account::audit($accountId, 'user.deleted', [
            'user_id'     => $requesterId,
            'entidade'    => 'user',
            'entidade_id' => (int)$id,
            'dados_antes' => $prevUser,
        ]);
    }
    echo json_encode(['success'=> (bool)$ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);
