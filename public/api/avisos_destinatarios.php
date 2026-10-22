<?php
/**
 * /api/avisos_destinatarios.php: quem recebe cada aviso de tempo real da conta
 * (22/10/2026). Ver App\Notificacoes\Destinatarios.
 *
 *   GET                                         eventos, escolha atual e a equipe
 *   POST { evento, admins: bool, usuarios: [ids] }
 *
 * Só dono e administrador. A conta sai da sessão; usuário de outra conta
 * mandado no corpo é descartado em Destinatarios::salvar.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\Core\TenantGuard;
use App\Notificacoes\Destinatarios;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if (!$ctx->isOwnerOrAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Só administradores escolhem quem recebe os avisos.']);
    exit;
}
$accountId = (int) $ctx->getAccountId();
$method    = $_SERVER['REQUEST_METHOD'];
$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$pdo       = Database::getConnection();

$responder = static function () use ($pdo, $accountId): void {
    $st = $pdo->prepare('SELECT * FROM accounts WHERE id = ? LIMIT 1');
    $st->execute([$accountId]);
    $conta = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
    $eventos = [];
    foreach (Destinatarios::EVENTOS as $chave => $ev) {
        $eventos[] = $ev + ['chave' => $chave] + Destinatarios::config($conta, $chave);
    }
    $st = $pdo->prepare("SELECT u.id, u.nome, u.role, u.perfil,
                                (SELECT COUNT(*) FROM push_inscricoes p WHERE p.user_id = u.id AND p.account_id = u.account_id) AS aparelhos
                         FROM users u WHERE u.account_id = ? AND u.deleted_at IS NULL AND (u.status IS NULL OR u.status = 'active')
                         ORDER BY u.nome");
    $st->execute([$accountId]);
    $equipe = array_map(static fn(array $u): array => [
        'id'        => (int) $u['id'],
        'nome'      => (string) $u['nome'],
        'admin'     => in_array($u['role'] ?? '', ['owner', 'admin'], true) || ($u['perfil'] ?? '') === 'admin',
        'aparelhos' => (int) $u['aparelhos'],
    ], $st->fetchAll(\PDO::FETCH_ASSOC));
    echo json_encode(['success' => true, 'eventos' => $eventos, 'equipe' => $equipe], JSON_UNESCAPED_UNICODE);
};

if ($method === 'GET') {
    $responder();
    exit;
}

if ($method === 'POST') {
    TenantGuard::requireSameOriginOrCsrf();
    $evento = (string) ($input['evento'] ?? '');
    if (!isset(Destinatarios::EVENTOS[$evento])) {
        http_response_code(400);
        echo json_encode(['error' => 'Evento desconhecido']);
        exit;
    }
    $admins = filter_var($input['admins'] ?? false, FILTER_VALIDATE_BOOLEAN);
    Destinatarios::salvar($accountId, $evento, $admins, is_array($input['usuarios'] ?? null) ? $input['usuarios'] : []);
    $responder();
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
