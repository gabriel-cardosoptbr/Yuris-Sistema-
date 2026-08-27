<?php
/**
 * /api/master/ai_webhook_token.php — Painel Master: GERAR/ROTACIONAR o segredo de
 * 2o fator do webhook (webhook_token) de UM canal.
 *
 * B3 Fase A (Onda 4). Isto NAO toca a Evolution: apenas gera um segredo dedicado ao
 * webhook, grava em whatsapp_settings (config_key='webhook_token', plaintext — mesma
 * convencao da evolution_api_key e do secret HMAC dos webhooks de saida) e devolve o
 * valor em claro UMA vez para o super-admin registrar. ATIVAR o envio pela Evolution
 * (setWebhook com o token) e a Fase B, feita em janela coordenada — por isso este
 * endpoint deliberadamente NAO chama a Evolution.
 *
 * O webhook.php (public/api/whatsapp/webhook.php) ja valida esse token em modo
 * COMPATIVEL: enquanto a Evolution nao enviar o token, a entrega segue normal.
 *
 * Acesso: super_admin em sessao master_mode; escrita exige nivel != viewer + CSRF. Auditado.
 *
 *   POST { instance_id, csrf_token }
 *     -> { ok, data:{ instance_id, token, token_preview, header_name, query_param, hook } }
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ApiResponse.php';
require_once __DIR__ . '/../../../app/Master/MasterAudit.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/WhatsAppInstance.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) ApiResponse::forbidden('Acesso somente pelo Painel Master.');

$pdo    = \App\Core\Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// URL canonica do webhook do Yuris (so para exibir a base; NAO carrega segredo).
const HOOK_DEFAULT = 'https://yuris.com.br/api/whatsapp/webhook.php';

if ($method === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? ($in['_csrf'] ?? null));
    if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
    }
    if ($ctx->getSuperAdminLevel() === 'viewer') ApiResponse::forbidden('Seu nível (viewer) não permite gerar o token do webhook.');

    // 1) Carrega o canal (sem escopo — Master ve tudo).
    $iid = (int)($in['instance_id'] ?? 0);
    if ($iid <= 0) ApiResponse::badRequest('instance_id obrigatório.');
    $wi   = new \WhatsAppInstance();
    $inst = $wi->find($iid);
    if (!$inst) ApiResponse::badRequest('Instância não encontrada.');
    $aid = (int)$inst['account_id'];

    // 2) Gera o segredo dedicado (256 bits) e grava plaintext (mesma convencao da
    //    evolution_api_key). random_bytes = CSPRNG. NUNCA logar o valor em claro.
    $token = bin2hex(random_bytes(32)); // 64 hex chars = 256 bits
    $wi->saveSetting($aid, 'webhook_token', $token);

    // 3) Hook base (so para exibir; sem segredo): URL global ou o default do Yuris.
    $st = $pdo->prepare("SELECT config_value FROM app_settings WHERE config_key = 'evolution_global_webhook_url' LIMIT 1");
    $st->execute();
    $hook = (string)($st->fetchColumn() ?: '');
    if ($hook === '') $hook = HOOK_DEFAULT;

    // 4) Audita SEM o valor do token (so o fato + preview mascarado).
    $preview = substr($token, 0, 6) . '...' . substr($token, -4);
    MasterAudit::log('ai_webhook.rotate_token', 'whatsapp_instance', $iid,
        'Gerou/rotacionou webhook_token do canal ' . ($inst['instance_name'] ?? ''),
        ['account_id' => $aid, 'token_preview' => $preview]);

    // 5) Devolve o token em claro UMA vez (o super-admin registra agora; nas leituras
    //    seguintes o painel mascara). A ATIVACAO na Evolution e a Fase B.
    ApiResponse::ok([
        'instance_id'   => $iid,
        'token'         => $token,          // exibir uma vez, nunca mais
        'token_preview' => $preview,
        'header_name'   => 'X-Webhook-Token',
        'query_param'   => 'wtoken',
        'hook'          => $hook,
    ]);
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
