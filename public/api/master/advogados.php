<?php
/**
 * Painel Master — CRUD de advogados.
 *
 * Advogado é um users com is_advogado = 1, oab + oab_uf preenchidos,
 * e (opcionalmente) codigo_advogado (ADV-XXXXXX) único global.
 *
 * GET    /api/master/advogados.php             → lista todos
 * GET    /api/master/advogados.php?id=X        → detalhe
 * GET    /api/master/advogados.php?account_id=X → filtrados por conta
 * POST   /api/master/advogados.php             → cria
 * PATCH  /api/master/advogados.php             → atualiza
 * DELETE /api/master/advogados.php?id=X        → soft-delete
 *
 * Acesso: super_admin apenas. CSRF nas escritas.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;
use App\Models\Database;

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

/** Gera próximo código ADV-XXXXXX único */
function _gerarCodigoAdvogado(\PDO $pdo): string {
    for ($i = 0; $i < 12; $i++) {
        $codigo = 'ADV-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE codigo_advogado = :c LIMIT 1");
        $stmt->execute(['c' => $codigo]);
        if (!$stmt->fetchColumn()) return $codigo;
    }
    return 'ADV-' . bin2hex(random_bytes(3));
}

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        $stmt = $pdo->prepare(
            "SELECT u.id, u.nome, u.login AS email, u.telefone, u.oab, u.oab_uf,
                    u.codigo_advogado, u.is_advogado, u.status, u.role, u.perfil,
                    u.account_id, u.created_at, u.updated_at,
                    a.nome AS account_nome, a.tipo AS account_tipo,
                    am.id AS matriz_id, am.nome AS matriz_nome
             FROM users u
             LEFT JOIN accounts a  ON a.id  = u.account_id
             LEFT JOIN account_vinculos av ON av.filial_account_id = a.id AND av.status = 'active'
             LEFT JOIN accounts am ON am.id = av.matriz_account_id
             WHERE u.id = :id AND u.is_advogado = 1 AND u.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) ApiResponse::notFound('Advogado não encontrado');
        ApiResponse::ok($row);
    }

    $where  = ['u.is_advogado = 1', 'u.deleted_at IS NULL'];
    $params = [];

    if (!empty($_GET['account_id'])) {
        $where[] = 'u.account_id = :acc';
        $params['acc'] = (int) $_GET['account_id'];
    }
    if (!empty($_GET['status'])) {
        $where[] = 'u.status = :st';
        $params['st'] = $_GET['status'];
    }
    if (!empty($_GET['q'])) {
        $q = '%' . $_GET['q'] . '%';
        $where[] = '(u.nome LIKE :q OR u.login LIKE :q2 OR u.oab LIKE :q3 OR u.codigo_advogado LIKE :q4)';
        $params['q'] = $q; $params['q2'] = $q; $params['q3'] = $q; $params['q4'] = $q;
    }

    $sql =
        "SELECT u.id, u.nome, u.login AS email, u.telefone, u.oab, u.oab_uf,
                u.codigo_advogado, u.status, u.account_id, u.created_at,
                a.nome AS account_nome, a.tipo AS account_tipo
         FROM users u
         LEFT JOIN accounts a ON a.id = u.account_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY u.nome ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    ApiResponse::ok(['advogados' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
}

if ($method === 'POST') {
    $nome     = trim($input['nome']      ?? '');
    $email    = trim($input['email']     ?? '');
    $oab      = trim($input['oab']       ?? '');
    $oab_uf   = strtoupper(trim($input['oab_uf'] ?? ''));
    $accId    = (int) ($input['account_id'] ?? 0);

    if ($nome === '' || $email === '' || $accId <= 0) {
        ApiResponse::badRequest('Nome, email e account_id são obrigatórios');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ApiResponse::badRequest('Email inválido');
    }
    if ($oab_uf !== '' && strlen($oab_uf) !== 2) {
        ApiResponse::badRequest('UF da OAB deve ter 2 letras');
    }

    // Conta existe?
    $acc = $pdo->prepare("SELECT id FROM accounts WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $acc->execute(['id' => $accId]);
    if (!$acc->fetchColumn()) ApiResponse::badRequest('Conta não encontrada');

    // Email duplicado?
    $dup = $pdo->prepare("SELECT id FROM users WHERE login = :em AND deleted_at IS NULL LIMIT 1");
    $dup->execute(['em' => $email]);
    if ($dup->fetchColumn()) ApiResponse::badRequest('Já existe usuário com este email');

    // OAB duplicada?
    if ($oab !== '') {
        $stOab = $pdo->prepare("SELECT id FROM users WHERE oab = :o AND deleted_at IS NULL LIMIT 1");
        $stOab->execute(['o' => $oab]);
        if ($stOab->fetchColumn()) ApiResponse::badRequest('Já existe advogado com esta OAB');
    }

    $senhaTexto  = trim($input['senha'] ?? '');
    $senhaGerada = $senhaTexto === '';
    if ($senhaGerada) {
        $alpha = 'abcdefghijkmnpqrstuvwxyz23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $senhaTexto = '';
        for ($i = 0; $i < 10; $i++) $senhaTexto .= $alpha[random_int(0, strlen($alpha) - 1)];
    }

    $codigo = _gerarCodigoAdvogado($pdo);

    $pdo->prepare(
        "INSERT INTO users
           (account_id, nome, login, senha_hash, perfil, role, status, telefone,
            oab, oab_uf, codigo_advogado, is_advogado, created_at, updated_at)
         VALUES
           (:aid, :nome, :login, :sh, 'user', 'user', 'active', :tel,
            :oab, :uf, :codigo, 1, NOW(), NOW())"
    )->execute([
        'aid'    => $accId,
        'nome'   => $nome,
        'login'  => $email,
        'sh'     => password_hash($senhaTexto, PASSWORD_BCRYPT),
        'tel'    => trim($input['telefone'] ?? '') ?: null,
        'oab'    => $oab ?: null,
        'uf'     => $oab_uf ?: null,
        'codigo' => $codigo,
    ]);
    $userId = (int) $pdo->lastInsertId();

    MasterAudit::log('advogado.create', 'user', $userId,
        "Advogado '{$nome}' (OAB {$oab}/{$oab_uf}) criado via Painel Master",
        ['account_id' => $accId, 'oab' => $oab, 'oab_uf' => $oab_uf, 'codigo' => $codigo]);

    $resp = ['id' => $userId, 'codigo_advogado' => $codigo];
    if ($senhaGerada) $resp['senha_gerada'] = $senhaTexto;
    ApiResponse::ok($resp);
}

if ($method === 'PATCH') {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');

    // Confirma que é advogado
    $st = $pdo->prepare("SELECT id, nome FROM users WHERE id = :id AND is_advogado = 1 AND deleted_at IS NULL LIMIT 1");
    $st->execute(['id' => $id]);
    $prev = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$prev) ApiResponse::notFound('Advogado não encontrado');

    $sets = []; $params = ['id' => $id];

    if (isset($input['nome']))     { $sets[] = 'nome = :nome';         $params['nome']     = trim($input['nome']); }
    if (isset($input['email']))    {
        $em = trim($input['email']);
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) ApiResponse::badRequest('Email inválido');
        // Garante não duplicar
        $du = $pdo->prepare("SELECT id FROM users WHERE login = :em AND id != :id AND deleted_at IS NULL LIMIT 1");
        $du->execute(['em' => $em, 'id' => $id]);
        if ($du->fetchColumn()) ApiResponse::badRequest('Já existe outro usuário com este email');
        $sets[] = 'login = :login'; $params['login'] = $em;
    }
    if (isset($input['telefone'])) { $sets[] = 'telefone = :tel';      $params['tel']      = trim($input['telefone']) ?: null; }
    if (isset($input['oab']))      {
        $oab = trim($input['oab']);
        if ($oab !== '') {
            $du = $pdo->prepare("SELECT id FROM users WHERE oab = :o AND id != :id AND deleted_at IS NULL LIMIT 1");
            $du->execute(['o' => $oab, 'id' => $id]);
            if ($du->fetchColumn()) ApiResponse::badRequest('Outra OAB já está em uso');
        }
        $sets[] = 'oab = :oab'; $params['oab'] = $oab ?: null;
    }
    if (isset($input['oab_uf']))   {
        $uf = strtoupper(trim($input['oab_uf']));
        if ($uf !== '' && strlen($uf) !== 2) ApiResponse::badRequest('UF inválida');
        $sets[] = 'oab_uf = :uf'; $params['uf'] = $uf ?: null;
    }
    if (isset($input['status']))   {
        if (!in_array($input['status'], ['active','inactive'], true)) {
            ApiResponse::badRequest('Status inválido');
        }
        $sets[] = 'status = :st'; $params['st'] = $input['status'];
    }
    if (isset($input['account_id'])) {
        $accId = (int) $input['account_id'];
        $acc = $pdo->prepare("SELECT id FROM accounts WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $acc->execute(['id' => $accId]);
        if (!$acc->fetchColumn()) ApiResponse::badRequest('Conta de destino não encontrada');
        $sets[] = 'account_id = :aid'; $params['aid'] = $accId;
    }
    if (!empty($input['nova_senha'])) {
        $sets[] = 'senha_hash = :sh';
        $params['sh'] = password_hash($input['nova_senha'], PASSWORD_BCRYPT);
    }

    if (empty($sets)) ApiResponse::badRequest('Nenhum campo pra atualizar');

    $sql = 'UPDATE users SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    MasterAudit::log('advogado.update', 'user', $id,
        "Advogado '{$prev['nome']}' editado via Painel Master",
        ['campos' => array_keys($input)]);

    ApiResponse::ok(['updated' => true]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? $input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');

    $st = $pdo->prepare("SELECT nome FROM users WHERE id = :id AND is_advogado = 1 LIMIT 1");
    $st->execute(['id' => $id]);
    $nome = $st->fetchColumn();
    if (!$nome) ApiResponse::notFound('Advogado não encontrado');

    $pdo->prepare("UPDATE users SET deleted_at = NOW(), status = 'inactive' WHERE id = :id")
        ->execute(['id' => $id]);

    MasterAudit::log('advogado.delete', 'user', $id,
        "Advogado '{$nome}' removido (soft-delete) via Painel Master");

    ApiResponse::ok(['deleted' => true]);
}

ApiResponse::methodNotAllowed();
