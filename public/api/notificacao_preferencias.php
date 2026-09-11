<?php
/**
 * /api/notificacao_preferencias.php — o que cada pessoa quer receber.
 *
 *   GET   devolve as quatro chaves, já com os padrões aplicados
 *   PUT   { chave: "movimento", ativo: false, csrf_token: "..." }
 *
 * ---------------------------------------------------------------------------
 * SÓ MEXE NAS PRÓPRIAS PREFERÊNCIAS
 * ---------------------------------------------------------------------------
 * Não existe parâmetro de usuário, nem para admin. Preferência de notificação é
 * do dono do ouvido: um administrador desligando o aviso de prazo de outra
 * pessoa criaria um silêncio que ninguém pediu e ninguém veria.
 *
 * O `user_id` sai SEMPRE da sessão, nunca do corpo da requisição.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\TenantGuard;
use App\Notificacoes\Aviso;

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
    echo json_encode([
        'success'     => true,
        'rotulos'     => Aviso::PREFERENCIAS,
        'preferencias'=> Aviso::preferenciasDe($userId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'PUT' || $method === 'POST') {
    // Escrita muda estado: exige mesma origem ou token, como o resto do sistema.
    TenantGuard::requireSameOriginOrCsrf();

    $chave = (string) ($input['chave'] ?? '');
    if (!isset(Aviso::PREFERENCIAS[$chave])) {
        http_response_code(400);
        echo json_encode(['error' => 'Chave desconhecida']);
        exit;
    }

    // `filter_var` porque o JSON pode trazer true, "true", 1 ou "1", e um
    // `(bool)"false"` daria TRUE, ligando o que a pessoa acabou de desligar.
    $ativo = filter_var($input['ativo'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($ativo === null) {
        $ativo = true;
    }

    $ok = Aviso::salvarPreferencia($accountId, $userId, $chave, $ativo);
    echo json_encode([
        'success'     => $ok,
        'preferencias'=> Aviso::preferenciasDe($userId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
