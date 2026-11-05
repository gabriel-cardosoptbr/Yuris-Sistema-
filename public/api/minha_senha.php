<?php
/**
 * minha_senha.php: a pessoa troca a PRÓPRIA senha (Configurações, aba Segurança).
 *
 * Não confundir com a redefinição pelo administrador, que continua em users.php.
 * Aqui a senha atual é obrigatória: sessão esquecida aberta não pode virar troca
 * de senha. O alvo é sempre o usuário da sessão, nunca um id vindo do corpo.
 *
 * Depois de trocar: ID de sessão novo e fim dos acessos com "Lembrar" (é para isso
 * que se troca senha quando se desconfia de alguém).
 */
require_once __DIR__ . '/_json_guard.php';
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\Core\TenantGuard;
use App\Usuarios\Lembrar;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

TenantGuard::requireSameOriginOrCsrf();
$ctx = AccountContext::fromSession();

function responder(array $corpo, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responder(['success' => false, 'error' => 'Método não suportado'], 405);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$atual = (string) ($input['senha_atual'] ?? '');
$nova  = (string) ($input['senha_nova'] ?? '');

if ($atual === '' || $nova === '') responder(['success' => false, 'error' => 'Preencha a senha atual e a nova.'], 422);
if (strlen($nova) < 8) responder(['success' => false, 'error' => 'A nova senha precisa ter pelo menos 8 caracteres.'], 422);
if (strlen($nova) > 72) responder(['success' => false, 'error' => 'A nova senha pode ter no máximo 72 caracteres.'], 422);

// Tentativa errada espera um pouco: a mesma sessão não testa senhas em sequência.
$_SESSION['senha_erros'] = (int) ($_SESSION['senha_erros'] ?? 0);
if ($_SESSION['senha_erros'] >= 5) {
    responder(['success' => false, 'error' => 'Muitas tentativas. Saia, entre de novo e tente outra vez.'], 429);
}

$userId = $ctx->getUserId();
$pdo = Database::getConnection();
$st = $pdo->prepare('SELECT senha_hash FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$st->execute([$userId]);
$hash = (string) $st->fetchColumn();

if ($hash === '' || !password_verify($atual, $hash)) {
    $_SESSION['senha_erros']++;
    responder(['success' => false, 'error' => 'A senha atual não confere.'], 403);
}
if (password_verify($nova, $hash)) {
    responder(['success' => false, 'error' => 'A nova senha precisa ser diferente da atual.'], 422);
}

$pdo->prepare('UPDATE users SET senha_hash = ? WHERE id = ?')
    ->execute([password_hash($nova, PASSWORD_BCRYPT), $userId]);

unset($_SESSION['senha_erros']);
session_regenerate_id(true);
Lembrar::revogarUsuario($userId);

responder(['success' => true]);
