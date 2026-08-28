<?php
/**
 * /api/master/ai_agent_toggle.php — Painel Master: LIGA/DESLIGA o agente de IA de uma
 * conta (override absoluto do super-admin). Mexe em agent_configs.enabled de TODOS os
 * canais daquela conta. Nao mexe na conta em si (status/assinatura) — so no agente.
 *
 * Acesso: super_admin em sessao master_mode, nivel != viewer. CSRF obrigatorio. Auditado.
 *
 *   POST { account_id, enabled (0|1) }  -> { ok, data:{ account_id, enabled, configs, account_nome } }
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$pdo = \App\Core\Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Método não permitido']); exit;
}

$in   = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? ($in['_csrf'] ?? null));
if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
}
if ($ctx->getSuperAdminLevel() === 'viewer') {
    ApiResponse::forbidden('Seu nível de acesso (viewer) não permite alterar o agente.');
}

$accountId = (int)($in['account_id'] ?? 0);
$enabled   = !empty($in['enabled']) && $in['enabled'] !== '0' ? 1 : 0;
if ($accountId <= 0) ApiResponse::badRequest('account_id obrigatório.');

// Conta existe?
$accStmt = $pdo->prepare('SELECT nome FROM accounts WHERE id = ? LIMIT 1');
$accStmt->execute([$accountId]);
$accNome = $accStmt->fetchColumn();
if ($accNome === false) ApiResponse::badRequest('Conta não encontrada.');

// Tem agente configurado?
$cnt = (int)$pdo->query('SELECT COUNT(*) FROM agent_configs WHERE account_id = ' . $accountId)->fetchColumn();
if ($cnt === 0) ApiResponse::badRequest('Esta conta não tem agente configurado (nada para ' . ($enabled ? 'ativar' : 'pausar') . ').');

// Aplica em TODOS os canais/configs da conta.
$upd = $pdo->prepare('UPDATE agent_configs SET enabled = ? WHERE account_id = ?');
$upd->execute([$enabled, $accountId]);
$n = $upd->rowCount();

MasterAudit::log(
    'ai_agent.toggle', 'agent_config', $accountId,
    ($enabled ? 'Ativou' : 'Pausou') . ' o agente de IA da conta "' . $accNome . '" (' . $cnt . ' canal(is))',
    ['enabled' => $enabled, 'configs' => $cnt]
);

ApiResponse::ok([
    'account_id'   => $accountId,
    'account_nome' => $accNome,
    'enabled'      => $enabled,
    'configs'      => $cnt,
]);
