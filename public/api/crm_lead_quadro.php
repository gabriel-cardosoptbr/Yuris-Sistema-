<?php
/**
 * O lead em mais de uma etapa e em mais de um setor, e o que o card dele mostra
 * além do cadastro (edição CRM). Ver App\Prospeccao\QuadroDoLead.
 *
 *   GET  ?quadro=1        por lead: etapas e setores a mais, orçamentos, contratos,
 *                         último contato; mais as etapas e os setores da conta
 *   GET  ?card_id=N       etapas e setores do lead (principal e a mais)
 *   POST {acao:'etapas', card_id, etapas:[coluna_id...]}   as etapas a mais
 *   POST {acao:'setores', card_id, setores:[team_id...]}   os setores marcados
 *
 * Sessão do CRM, CSRF no POST, quem abre Prospecção. Lead de outra conta: 400.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Prospeccao\QuadroDoLead;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function lqResponder(array $corpo, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) lqResponder(['ok' => false, 'error' => 'Sessão expirada.'], 401);
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if ($ctx->getProduto() !== 'fleetiflow') lqResponder(['ok' => false, 'error' => 'Disponível só na edição CRM.'], 404);
$perms = $_SESSION['user_permissions'] ?? [];
$admin = strtolower((string) ($_SESSION['user_perfil'] ?? '')) === 'admin';
if (!$admin && !in_array('*', $perms, true) && !in_array('prospeccao', $perms, true)) lqResponder(['ok' => false, 'error' => 'Sem permissão para a prospecção.'], 403);
$acc = (int) $ctx->getAccountId();
$uid = (int) $ctx->getUserId();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['quadro'])) lqResponder(['ok' => true] + QuadroDoLead::quadro($ctx->getAccessibleAccountIds('prospeccao') ?: [$acc], $acc));
        lqResponder(['ok' => true, 'data' => QuadroDoLead::doLead($acc, (int) ($_GET['card_id'] ?? 0)),
            'etapas_conta' => QuadroDoLead::etapasDaConta($acc), 'setores_conta' => QuadroDoLead::setoresDaConta($acc)]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') lqResponder(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) lqResponder(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 419);
    $card = (int) ($in['card_id'] ?? 0);
    $data = match ((string) ($in['acao'] ?? '')) {
        'etapas'  => QuadroDoLead::salvarEtapas($acc, $uid, $card, (array) ($in['etapas'] ?? [])),
        'setores' => QuadroDoLead::salvarSetores($acc, $uid, $card, (array) ($in['setores'] ?? [])),
        default   => throw new \InvalidArgumentException('Ação desconhecida.'),
    };
    lqResponder(['ok' => true, 'data' => $data]);
} catch (\InvalidArgumentException $e) {
    lqResponder(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log('[crm_lead_quadro] ' . get_class($e) . ' ' . $e->getMessage());
    lqResponder(['ok' => false, 'error' => 'Não foi possível concluir agora.'], 500);
}
