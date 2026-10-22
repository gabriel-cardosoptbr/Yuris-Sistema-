<?php
/**
 * /api/avisos_push.php: este aparelho recebe avisos no celular ou no computador
 * (Web Push, 22/10/2026). Ver App\Notificacoes\Push.
 *
 *   GET                                        { chave, aparelhos }
 *   POST { acao: "inscrever", endpoint, keys: { p256dh, auth } }
 *   POST { acao: "remover", endpoint }
 *   POST { acao: "testar" }                    manda um aviso de teste para os aparelhos da pessoa
 *
 * Sempre a própria pessoa: usuário e conta saem da sessão, nunca do corpo.
 * A sessão fica aberta para escrita porque a inscrição guarda nela o aparelho,
 * que o logout apaga (quem sai para de receber avisos com nome de cliente).
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\TenantGuard;
use App\Notificacoes\Push;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

$userId    = (int) $ctx->getUserId();
$accountId = (int) $ctx->getAccountId();
$method    = $_SERVER['REQUEST_METHOD'];
$input     = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET') {
    session_write_close();
    echo json_encode(['success' => true, 'chave' => Push::chavePublica(), 'aparelhos' => Push::aparelhos($userId, $accountId)]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

TenantGuard::requireSameOriginOrCsrf();
$acao = (string) ($input['acao'] ?? '');

if ($acao === 'inscrever') {
    $ok = Push::inscrever($accountId, $userId, (string) ($input['endpoint'] ?? ''),
        (string) ($input['keys']['p256dh'] ?? ''), (string) ($input['keys']['auth'] ?? ''));
    if (!$ok) http_response_code(422);
    echo json_encode(['success' => $ok, 'aparelhos' => Push::aparelhos($userId, $accountId)]);
    exit;
}

if ($acao === 'remover') {
    Push::remover($userId, (string) ($input['endpoint'] ?? ''));
    unset($_SESSION['push_endpoint_hash']);
    echo json_encode(['success' => true, 'aparelhos' => Push::aparelhos($userId, $accountId)]);
    exit;
}

if ($acao === 'testar') {
    session_write_close();
    Push::enfileirar($accountId, $userId, [
        'tipo' => 'teste', 'titulo' => 'Avisos ligados neste aparelho',
        'corpo' => 'É assim que chega um lead novo. Toque para abrir o sistema.', 'url' => '/dashboard',
    ]);
    $aceitos = Push::enviarFila();
    echo json_encode(['success' => $aceitos > 0, 'aceitos' => $aceitos]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Ação desconhecida']);
