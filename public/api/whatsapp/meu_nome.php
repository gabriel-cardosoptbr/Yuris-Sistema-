<?php
/**
 * GET/POST /api/whatsapp/meu_nome.php
 *
 * O nome com que o vendedor aparece nas mensagens do WhatsApp (abertura do disparo,
 * follow-up, apresentação da IA e rótulos da equipe). Ver App\WhatsAppAgente\VendedorDoLead.
 *
 *   GET  -> { ok, disponivel, pronta, nome_cadastro, nome_mensagens, aparece_como }
 *   POST { nome_mensagens, user_id?, _csrf } -> o mesmo do GET (do usuário alterado)
 *           nome_mensagens vazio volta ao primeiro nome do cadastro.
 *
 * Cada um muda o PRÓPRIO nome. owner/admin muda o de qualquer pessoa da conta (user_id).
 * Só existe para a conta que os fluxos do n8n atendem, como a Roleta.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\WhatsAppAgente\SdrFleetiflow;
use App\WhatsAppAgente\VendedorDoLead;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid  = (int)($_SESSION['user_id'] ?? 0);
$csrf = $_SESSION['csrf_token'] ?? '';
if (!$uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$accountId = (int)$ctx->getAccountId();
if ($accountId <= 0 || !SdrFleetiflow::contaDoTokenGlobal($accountId)) { echo json_encode(['ok' => true, 'disponivel' => false]); exit; }

$estado = function (int $quem) use ($accountId): array {
    $st = Database::getConnection()->prepare('SELECT nome, ' . VendedorDoLead::colunaSql('users') . " AS escolhido FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL AND status = 'active' LIMIT 1");
    $st->execute([$quem, $accountId]);
    $r = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$r) return ['ok' => false, 'error' => 'Usuário não encontrado.'];
    return [
        'ok' => true, 'disponivel' => true, 'pronta' => VendedorDoLead::temColuna(),
        'user_id' => $quem, 'nome_cadastro' => (string)$r['nome'],
        'nome_mensagens' => $r['escolhido'] !== null ? (string)$r['escolhido'] : '',
        'aparece_como' => VendedorDoLead::nomeNaMensagem((string)$r['nome'], $r['escolhido']),
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode($estado($uid), JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Método não permitido']); exit; }

$in = json_decode((string)file_get_contents('php://input'), true) ?? [];
if (empty($in['_csrf']) || !hash_equals((string)$csrf, (string)$in['_csrf'])) { http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit; }

$alvo = (int)($in['user_id'] ?? $uid);
if ($alvo !== $uid && !$ctx->isOwnerOrAdmin()) { http_response_code(403); echo json_encode(['error' => 'Só a administração muda o nome de outra pessoa']); exit; }

if ($erro = VendedorDoLead::salvarNome($accountId, $alvo, (string)($in['nome_mensagens'] ?? ''), $uid)) {
    http_response_code(422); echo json_encode(['error' => $erro], JSON_UNESCAPED_UNICODE); exit;
}
echo json_encode($estado($alvo), JSON_UNESCAPED_UNICODE); // relê do banco: confirma que gravou
