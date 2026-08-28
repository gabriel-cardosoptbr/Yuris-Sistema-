<?php
/**
 * Painel Master — CRUD universal de usuários (cross-tenant).
 *
 * Super_admin pode editar QUALQUER user de QUALQUER conta — matriz, filial
 * ou advogado-solo. Sem restrição de isolamento, é o "root" do SaaS.
 *
 * GET    /api/master/users.php?account_id=X     → lista users de uma conta
 * GET    /api/master/users.php?id=X             → detalhe de um user
 * POST   /api/master/users.php                  → cria user em qualquer conta
 * PATCH  /api/master/users.php                  → atualiza campos
 * POST   /api/master/users.php?reset_password=1 → reset senha (gera nova random)
 * DELETE /api/master/users.php?id=X             → soft-delete
 *
 * Acesso: super_admin apenas. CSRF nas escritas.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;
use App\Core\Database;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        ApiResponse::badRequest('CSRF inválido');
    }
}

/** Gera senha temporária legível (10 chars sem ambíguos) */
function _gerarSenhaTmp(): string {
    $alpha = 'abcdefghijkmnpqrstuvwxyz23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $alpha[random_int(0, strlen($alpha) - 1)];
    return $s;
}

// ─── GET ─────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        $stmt = $pdo->prepare(
            "SELECT u.id, u.nome, u.login AS email, u.telefone, u.perfil, u.role, u.status,
                    u.oab, u.oab_uf, u.codigo_advogado, u.is_advogado,
                    u.account_id, u.created_at, u.updated_at,
                    a.nome AS account_nome, a.tipo AS account_tipo
             FROM users u
             LEFT JOIN accounts a ON a.id = u.account_id
             WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) ApiResponse::notFound('Usuário não encontrado');
        ApiResponse::ok($row);
    }

    $where = ['u.deleted_at IS NULL']; $params = [];
    if (!empty($_GET['account_id'])) { $where[] = 'u.account_id = :acc'; $params['acc'] = (int) $_GET['account_id']; }
    if (!empty($_GET['role']))       { $where[] = 'u.role = :role';      $params['role'] = $_GET['role']; }
    if (!empty($_GET['status']))     { $where[] = 'u.status = :st';      $params['st'] = $_GET['status']; }
    if (!empty($_GET['q'])) {
        $q = '%' . $_GET['q'] . '%';
        $where[] = '(u.nome LIKE :q1 OR u.login LIKE :q2)';
        $params['q1'] = $q; $params['q2'] = $q;
    }

    $stmt = $pdo->prepare(
        "SELECT u.id, u.nome, u.login AS email, u.telefone, u.perfil, u.role, u.status,
                u.is_advogado, u.oab, u.oab_uf, u.codigo_advogado,
                u.account_id, a.nome AS account_nome, a.tipo AS account_tipo,
                u.created_at
         FROM users u
         LEFT JOIN accounts a ON a.id = u.account_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY u.nome ASC
         LIMIT 200"
    );
    $stmt->execute($params);
    ApiResponse::ok(['users' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
}

// ─── POST: criar user em qualquer conta ──────────────────────────────────────
if ($method === 'POST' && empty($_GET['reset_password'])) {
    $accountId = (int) ($input['account_id'] ?? 0);
    $nome      = trim($input['nome'] ?? '');
    $email     = trim($input['email'] ?? '');

    if (!$accountId || $nome === '' || $email === '') {
        ApiResponse::badRequest('account_id, nome e email são obrigatórios');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) ApiResponse::badRequest('Email inválido');

    $acc = $pdo->prepare("SELECT id FROM accounts WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $acc->execute(['id' => $accountId]);
    if (!$acc->fetchColumn()) ApiResponse::badRequest('Conta não encontrada');

    $dup = $pdo->prepare("SELECT id FROM users WHERE login = :em AND deleted_at IS NULL LIMIT 1");
    $dup->execute(['em' => $email]);
    if ($dup->fetchColumn()) ApiResponse::badRequest('Já existe usuário com este email');

    $senha = trim($input['senha'] ?? '');
    $senhaGerada = $senha === '';
    if ($senhaGerada) $senha = _gerarSenhaTmp();

    $role   = $input['role']   ?? 'user';
    $perfil = $input['perfil'] ?? ($role === 'owner' || $role === 'admin' ? 'admin' : 'user');

    $pdo->prepare(
        "INSERT INTO users
           (account_id, nome, login, senha_hash, perfil, role, status, telefone, created_at, updated_at)
         VALUES
           (:aid, :nome, :login, :sh, :perfil, :role, 'active', :tel, NOW(), NOW())"
    )->execute([
        'aid'    => $accountId,
        'nome'   => $nome,
        'login'  => $email,
        'sh'     => password_hash($senha, PASSWORD_BCRYPT),
        'perfil' => $perfil,
        'role'   => $role,
        'tel'    => trim($input['telefone'] ?? '') ?: null,
    ]);
    $userId = (int) $pdo->lastInsertId();

    MasterAudit::log('user.create', 'user', $userId,
        "Usuário '{$nome}' criado na conta #{$accountId}",
        ['account_id' => $accountId, 'role' => $role]);

    $resp = ['id' => $userId];
    if ($senhaGerada) $resp['senha_gerada'] = $senha;
    ApiResponse::ok($resp);
}

// ─── POST?reset_password=1: gerar nova senha ─────────────────────────────────
if ($method === 'POST' && !empty($_GET['reset_password'])) {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');

    $st = $pdo->prepare("SELECT nome FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $st->execute(['id' => $id]);
    $nome = $st->fetchColumn();
    if (!$nome) ApiResponse::notFound('Usuário não encontrado');

    $nova = !empty($input['senha']) ? trim($input['senha']) : _gerarSenhaTmp();
    $pdo->prepare("UPDATE users SET senha_hash = :sh, updated_at = NOW() WHERE id = :id")
        ->execute(['sh' => password_hash($nova, PASSWORD_BCRYPT), 'id' => $id]);

    MasterAudit::log('user.reset_password', 'user', $id, "Senha do usuário '{$nome}' foi resetada");

    ApiResponse::ok(['senha_gerada' => $nova]);
}

// ─── PATCH: editar campos ─────────────────────────────────────────────────────
if ($method === 'PATCH') {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');

    $st = $pdo->prepare("SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $st->execute(['id' => $id]);
    $prev = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$prev) ApiResponse::notFound('Usuário não encontrado');

    $sets = []; $params = ['id' => $id];

    if (isset($input['nome']))     { $sets[] = 'nome = :nome';      $params['nome']  = trim($input['nome']); }
    if (isset($input['email']))    {
        $em = trim($input['email']);
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) ApiResponse::badRequest('Email inválido');
        $du = $pdo->prepare("SELECT id FROM users WHERE login = :em AND id != :id AND deleted_at IS NULL LIMIT 1");
        $du->execute(['em' => $em, 'id' => $id]);
        if ($du->fetchColumn()) ApiResponse::badRequest('Já existe outro usuário com este email');
        $sets[] = 'login = :login';
        $params['login'] = $em;
    }
    if (isset($input['telefone'])) { $sets[] = 'telefone = :tel';   $params['tel']   = trim($input['telefone']) ?: null; }
    if (isset($input['role']))     {
        if (!in_array($input['role'], ['owner','admin','manager','user','viewer'], true)) {
            ApiResponse::badRequest('role inválido');
        }
        $sets[] = 'role = :role';   $params['role']  = $input['role'];
    }
    if (isset($input['perfil']))   {
        if (!in_array($input['perfil'], ['admin','user'], true)) ApiResponse::badRequest('perfil inválido');
        $sets[] = 'perfil = :perfil'; $params['perfil'] = $input['perfil'];
    }
    if (isset($input['status']))   {
        if (!in_array($input['status'], ['active','inactive'], true)) {
            ApiResponse::badRequest('status inválido');
        }
        $sets[] = 'status = :st';     $params['st']     = $input['status'];
    }
    if (isset($input['account_id'])) {
        $aid = (int) $input['account_id'];
        $ck = $pdo->prepare("SELECT id FROM accounts WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $ck->execute(['id' => $aid]);
        if (!$ck->fetchColumn()) ApiResponse::badRequest('Conta de destino não encontrada');
        $sets[] = 'account_id = :aid'; $params['aid'] = $aid;
    }
    if (isset($input['oab']))      { $sets[] = 'oab = :oab';        $params['oab']    = trim($input['oab']) ?: null; }
    if (isset($input['oab_uf']))   {
        $uf = strtoupper(trim($input['oab_uf']));
        if ($uf !== '' && strlen($uf) !== 2) ApiResponse::badRequest('UF inválida');
        $sets[] = 'oab_uf = :uf';   $params['uf']     = $uf ?: null;
    }
    if (isset($input['is_advogado'])) {
        $sets[] = 'is_advogado = :adv'; $params['adv'] = $input['is_advogado'] ? 1 : 0;
    }
    if (!empty($input['nova_senha'])) {
        $sets[] = 'senha_hash = :sh';
        $params['sh'] = password_hash($input['nova_senha'], PASSWORD_BCRYPT);
    }

    if (empty($sets)) ApiResponse::badRequest('Nada para atualizar');

    $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id')
        ->execute($params);

    MasterAudit::log('user.update', 'user', $id,
        "Usuário '{$prev['nome']}' editado via Painel Master",
        ['campos' => array_keys($input), 'antes_status' => $prev['status']]);

    ApiResponse::ok(['updated' => true]);
}

// ─── DELETE: soft-delete ─────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? $input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');

    $st = $pdo->prepare("SELECT nome, account_id FROM users WHERE id = :id LIMIT 1");
    $st->execute(['id' => $id]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$row) ApiResponse::notFound('Usuário não encontrado');

    $pdo->prepare("UPDATE users SET deleted_at = NOW(), status = 'inactive' WHERE id = :id")
        ->execute(['id' => $id]);

    MasterAudit::log('user.delete', 'user', $id,
        "Usuário '{$row['nome']}' removido (soft-delete) da conta #{$row['account_id']}");

    ApiResponse::ok(['deleted' => true]);
}

ApiResponse::methodNotAllowed();
