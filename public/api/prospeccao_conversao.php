<?php

/**
 * /api/prospeccao_conversao.php — "Tornar cliente".
 *
 * GET  ?card_id=N   → prévia: pode converter? já converteu? há cliente parecido?
 * POST              → converte. Corpo:
 *                       card_id                 (obrigatório)
 *                       cliente_existente_id    (opcional: liga em vez de criar)
 *                       setor_id                (opcional: setor do cliente novo)
 *
 * Segurança, na ordem em que acontece:
 *   1. sessão + conta ativa (AccountContext)
 *   2. CSRF na escrita
 *   3. PERMISSÃO DE AÇÃO prospeccao.converter_cliente
 *   4. o card tem de pertencer a uma conta acessível (dentro do serviço)
 *   5. o cliente de destino tem de ser da MESMA conta do card (dentro do serviço)
 *
 * A permissão é de AÇÃO, não de página. O Yuris guarda permissão em
 * user_permissions.page, uma linha por tela. Aqui a mesma tabela guarda uma
 * chave com ponto, 'prospeccao.converter_cliente', que nenhuma tela usa como
 * nome. Assim a ação ganha controle próprio sem migration e sem um segundo
 * sistema de permissões concorrendo com o que já existe. Owner e admin
 * continuam passando pelo curinga '*' que o AuthController já grava.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Prospeccao\ConversaoCliente;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

$userId    = $ctx->getUserId();
$accountId = $ctx->getAccountId();
$tenantIds = $ctx->getAccessibleAccountIds('prospeccao');

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

/** Permissão de ação: curinga do owner/admin, ou a chave explícita. */
function podeConverter(): bool
{
    $perms = (array) ($_SESSION['user_permissions'] ?? []);
    if (in_array('*', $perms, true)) {
        return true;
    }
    return in_array(ConversaoCliente::PERMISSAO, $perms, true);
}

if ($method === 'GET') {
    $cardId = (int) ($_GET['card_id'] ?? 0);
    if ($cardId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'card_id é obrigatório']);
        exit;
    }

    $previa = ConversaoCliente::previa($cardId, $tenantIds);
    if ($previa['card'] === null) {
        http_response_code(404);
        echo json_encode(['error' => $previa['motivo'] ?? 'Prospecção não encontrada']);
        exit;
    }

    echo json_encode([
        'success'       => true,
        'pode_converter' => podeConverter(),
        'ja_convertida' => $previa['ja_convertida'],
        'cliente_id'    => $previa['cliente_id'],
        'cliente_nome'  => $previa['cliente_nome'],
        'motivo'        => $previa['motivo'],
        'candidatos'    => $previa['candidatos'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// ── CSRF ─────────────────────────────────────────────────────────────
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

// ── Permissão ────────────────────────────────────────────────────────
if (!podeConverter()) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Você não tem permissão para converter prospecções em clientes.',
        'code'  => 'sem_permissao',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cardId    = (int) ($input['card_id'] ?? 0);
$existente = isset($input['cliente_existente_id']) && $input['cliente_existente_id'] !== ''
    ? (int) $input['cliente_existente_id']
    : null;
$setorId   = isset($input['setor_id']) && $input['setor_id'] !== '' ? (int) $input['setor_id'] : null;

if ($cardId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'card_id é obrigatório']);
    exit;
}

$r = ConversaoCliente::converter($cardId, $accountId, $tenantIds, $userId, $existente, $setorId);

if (!$r['ok']) {
    // 409: o pedido é válido, o estado é que não permite (já convertida, corrida).
    http_response_code($r['cliente_id'] ? 409 : 400);
    echo json_encode([
        'error'      => $r['erro'],
        'cliente_id' => $r['cliente_id'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success'    => true,
    'cliente_id' => $r['cliente_id'],
    'criado'     => $r['criado'],
], JSON_UNESCAPED_UNICODE);
