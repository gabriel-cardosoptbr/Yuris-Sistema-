<?php
/**
 * GET/POST /api/whatsapp/roleta.php
 *
 * A tela Operação › Roleta (public/roleta.php): quem recebe os leads que a
 * prospecção automática abre, e em que proporção. Ver App\WhatsAppAgente\Roleta.
 *
 *   GET  -> { ok, disponivel, ligada, participantes, desde, da_vez, equipe, contagem, ultimos }
 *           da_vez = o vendedor que recebe o próximo lead ({user_id, atendente, vendedor}), ou null
 *   POST { ligada:0|1, participantes:[{user_id, percentual}], _csrf } -> o mesmo do GET
 *
 * Só owner/admin, para ler e para mudar: o atendente não vê a divisão. Só existe
 * para a conta que os fluxos do n8n atendem (a dona do token da prospecção), como
 * os botões Disparo e Follow-up (automacao_toggle.php).
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\WhatsAppAgente\Roleta;
use App\WhatsAppAgente\SdrFleetiflow;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid  = $_SESSION['user_id']    ?? null;
$csrf = $_SESSION['csrf_token'] ?? '';
if (!$uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$accountId = (int)$ctx->getAccountId();
if (!$ctx->isOwnerOrAdmin()) { http_response_code(403); echo json_encode(['error' => 'A roleta é configurada pela administração da conta']); exit; }
if ($accountId <= 0 || !SdrFleetiflow::contaDoTokenGlobal($accountId)) { echo json_encode(['ok' => true, 'disponivel' => false]); exit; }

$estado = function () use ($accountId): array {
    $st = Database::getConnection()->prepare("SELECT id, nome, role FROM users WHERE account_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY nome");
    $st->execute([$accountId]);
    $equipe = array_map(fn($r) => ['id' => (int)$r['id'], 'nome' => (string)$r['nome'], 'role' => (string)$r['role']], $st->fetchAll(\PDO::FETCH_ASSOC));
    try {
        $contagem = Roleta::contagem($accountId);
        $ultimos  = Roleta::ultimos($accountId, 20);
        $daVez    = Roleta::daVez($accountId);
        $pronta   = true;
    } catch (\Throwable $e) {
        $contagem = []; $ultimos = []; $daVez = null; $pronta = false; // migrations 170/171 ainda não rodaram
    }
    return ['ok' => true, 'disponivel' => true, 'pronta' => $pronta] + Roleta::config($accountId)
        + ['da_vez' => $daVez, 'equipe' => $equipe, 'contagem' => $contagem, 'ultimos' => $ultimos];
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode($estado(), JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Método não permitido']); exit; }

$in = json_decode((string)file_get_contents('php://input'), true) ?? [];
if (empty($in['_csrf']) || !hash_equals((string)$csrf, (string)$in['_csrf'])) { http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit; }

$participantes = is_array($in['participantes'] ?? null) ? array_values($in['participantes']) : [];
if ($erro = Roleta::salvar($accountId, $participantes, !empty($in['ligada']), (int)$uid)) {
    http_response_code(422); echo json_encode(['error' => $erro], JSON_UNESCAPED_UNICODE); exit;
}
echo json_encode($estado(), JSON_UNESCAPED_UNICODE); // relê do banco: confirma que gravou
