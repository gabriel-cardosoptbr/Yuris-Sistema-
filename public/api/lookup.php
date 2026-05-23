<?php
/**
 * API: /api/lookup.php
 *
 * Busca unificada por código:
 *   GET ?codigo=XXXX
 *     - tenta accounts.codigo_vinculo  → retorna tipo='conta'
 *     - senão users.codigo_advogado    → retorna tipo='advogado'
 *     - senão 404
 *
 * Usado pelo modal de "Adicionar vínculo" para descobrir o que o usuário colou:
 * código de matriz/filial OU código de advogado individual.
 */
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Models\Database;
use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

AccountContext::fromSession();  // exige sessão válida

$codigo = trim($_GET['codigo'] ?? '');
if ($codigo === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro codigo é obrigatório']);
    exit;
}

$pdo = Database::getConnection();

// 1) Tenta como conta (matriz/filial)
$stmt = $pdo->prepare(
    "SELECT id, nome, tipo, plano, status
     FROM accounts
     WHERE codigo_vinculo = :codigo AND deleted_at IS NULL AND status = 'active'
     LIMIT 1"
);
$stmt->execute(['codigo' => $codigo]);
$conta = $stmt->fetch(\PDO::FETCH_ASSOC);
if ($conta) {
    echo json_encode([
        'tipo' => 'conta',
        'data' => [
            'id'     => (int)$conta['id'],
            'nome'   => $conta['nome'],
            'tipo'   => $conta['tipo'],
            'plano'  => $conta['plano'],
            'status' => $conta['status'],
        ],
    ]);
    exit;
}

// 2) Tenta como advogado individual
$stmt = $pdo->prepare(
    "SELECT u.id, u.nome, u.login, u.codigo_advogado, u.account_id,
            a.nome AS account_nome, a.tipo AS account_tipo
     FROM users u
     LEFT JOIN accounts a ON a.id = u.account_id
     WHERE u.codigo_advogado = :codigo AND u.deleted_at IS NULL AND u.status = 'active'
     LIMIT 1"
);
$stmt->execute(['codigo' => $codigo]);
$adv = $stmt->fetch(\PDO::FETCH_ASSOC);
if ($adv) {
    echo json_encode([
        'tipo' => 'advogado',
        'data' => [
            'user_id'         => (int)$adv['id'],
            'nome'            => $adv['nome'],
            'email'           => $adv['login'],
            'codigo_advogado' => $adv['codigo_advogado'],
            'account_id'      => (int)$adv['account_id'],
            'account_nome'    => $adv['account_nome'],
            'account_tipo'    => $adv['account_tipo'],
        ],
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Código não encontrado']);
