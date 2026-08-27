<?php
/**
 * Painel Master — busca global cross-tenant.
 *
 * GET /api/master/search.php?q=texto[&type=matriz|filial|advogado|usuario]
 *
 * Busca por:
 *   - accounts (matriz/filial) — nome, razao_social, cnpj, email, telefone, codigo_vinculo
 *   - users — nome, login (email), telefone, oab, codigo_advogado
 *
 * Retorna lista unificada com:
 *   { type: 'matriz|filial|advogado|usuario', id, label, sublabel, account_id, status }
 *
 * Acesso: super_admin apenas.
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ApiResponse.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Core\Database;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') ApiResponse::methodNotAllowed();

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) ApiResponse::ok(['results' => []]);

$type = $_GET['type'] ?? null;
if ($type !== null && !in_array($type, ['matriz','filial','advogado','usuario','all'], true)) {
    ApiResponse::badRequest('type inválido');
}

$pdo = Database::getConnection();
$results = [];
$like = '%' . $q . '%';
$digits = preg_replace('/\D/', '', $q);

// ─── 1. Busca em accounts (matriz/filial) ──────────────────────────────────
if ($type === null || $type === 'all' || $type === 'matriz' || $type === 'filial') {
    $where = [
        "a.deleted_at IS NULL",
        "(a.nome LIKE :q1
          OR a.razao_social LIKE :q2
          OR a.email LIKE :q3
          OR a.telefone LIKE :q4
          OR a.codigo_vinculo LIKE :q5"
        . ($digits !== '' ? " OR a.cnpj = :cnpj" : "")
        . ")"
    ];
    if ($type === 'matriz') $where[] = "a.tipo = 'matriz'";
    if ($type === 'filial') $where[] = "a.tipo = 'filial'";

    $params = ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
    if ($digits !== '') $params['cnpj'] = $digits;

    $sql =
        "SELECT a.id, a.nome, a.razao_social, a.cnpj, a.email, a.telefone,
                a.tipo, av.matriz_account_id AS matriz_id, a.status, a.cidade, a.estado,
                am.nome AS matriz_nome
         FROM accounts a
         LEFT JOIN account_vinculos av ON av.filial_account_id = a.id AND av.status = 'active'
         LEFT JOIN accounts am ON am.id = av.matriz_account_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY a.nome ASC
         LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $sub = [];
        if ($r['cnpj'])     $sub[] = 'CNPJ ' . $r['cnpj'];
        if ($r['cidade'])   $sub[] = $r['cidade'] . ($r['estado'] ? '/' . $r['estado'] : '');
        if ($r['tipo'] === 'filial' && $r['matriz_nome']) {
            $sub[] = 'matriz: ' . $r['matriz_nome'];
        }
        $results[] = [
            'type'       => $r['tipo'], // matriz | filial
            'id'         => (int) $r['id'],
            'label'      => $r['nome'],
            'sublabel'   => implode(' · ', $sub),
            'account_id' => (int) $r['id'],
            'status'     => $r['status'],
            'email'      => $r['email'],
        ];
    }
}

// ─── 2. Busca em users (advogados e usuários comuns) ────────────────────────
if ($type === null || $type === 'all' || $type === 'advogado' || $type === 'usuario') {
    $where = ['u.deleted_at IS NULL'];
    if ($type === 'advogado') $where[] = 'u.is_advogado = 1';
    if ($type === 'usuario')  $where[] = 'u.is_advogado = 0';

    $where[] =
        "(u.nome LIKE :q1
          OR u.login LIKE :q2
          OR u.telefone LIKE :q3
          OR u.oab LIKE :q4
          OR u.codigo_advogado LIKE :q5)";

    $params = ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];

    $sql =
        "SELECT u.id, u.nome, u.login AS email, u.telefone, u.oab, u.oab_uf,
                u.codigo_advogado, u.is_advogado, u.status, u.account_id,
                a.nome AS account_nome, a.tipo AS account_tipo
         FROM users u
         LEFT JOIN accounts a ON a.id = u.account_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY u.nome ASC
         LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $sub = [];
        if ($r['is_advogado']) {
            if ($r['oab']) $sub[] = 'OAB ' . $r['oab'] . ($r['oab_uf'] ? '/' . $r['oab_uf'] : '');
            if ($r['codigo_advogado']) $sub[] = $r['codigo_advogado'];
        }
        if ($r['email'])         $sub[] = $r['email'];
        if ($r['account_nome'])  $sub[] = '@ ' . $r['account_nome'];

        $results[] = [
            'type'       => $r['is_advogado'] ? 'advogado' : 'usuario',
            'id'         => (int) $r['id'],
            'label'      => $r['nome'],
            'sublabel'   => implode(' · ', $sub),
            'account_id' => (int) $r['account_id'],
            'status'     => $r['status'],
            'email'      => $r['email'],
        ];
    }
}

// Reordena: tipos na ordem matriz, filial, advogado, usuario
usort($results, function ($a, $b) {
    $order = ['matriz' => 1, 'filial' => 2, 'advogado' => 3, 'usuario' => 4];
    return ($order[$a['type']] ?? 9) <=> ($order[$b['type']] ?? 9);
});

ApiResponse::ok(['results' => array_slice($results, 0, 50)]);
