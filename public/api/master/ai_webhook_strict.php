<?php
/**
 * /api/master/ai_webhook_strict.php — Painel Master: liga/desliga o MODO ESTRITO do
 * 2o fator do webhook (whatsapp_settings.webhook_token_strict). B3 Fase C.
 *
 * ESCOPO = CONTA, nao canal: o webhook_token e a flag webhook_token_strict vivem em
 * whatsapp_settings, chaveado por account_id (nao ha instance_id). Logo, ligar o
 * estrito vale para TODOS os canais da conta ao mesmo tempo. Recebe instance_id so
 * para identificar a conta (e auditar qual linha foi clicada); a UI avisa quando a
 * conta tem mais de um canal roteado. Sob a premissa de instancia unica (1 canal por
 * conta) isso coincide com "por canal".
 *
 * Com o estrito LIGADO, uma requisicao ao webhook dessa conta que NAO traga o
 * webhook_token (ou traga ERRADO) e rejeitada com 401 — fecha a janela de compat
 * (COMPAT vira REJECT em WhatsAppWebhookAuth::verify). Ligar SO depois de confirmar,
 * na aba "Saude do Webhook", que os canais da conta ja enviam o cracha (zero
 * webhook_compat / webhook_strict_missing por um periodo). Isto NAO toca a Evolution.
 *
 * Guard de seguranca: so DEIXA ligar se o canal TEM webhook_token setado — senao o
 * estrito seria inocuo (expected vazio -> OK) e daria falsa sensacao de protecao.
 *
 * Acesso: super_admin em sessao master_mode; escrita exige nivel != viewer + CSRF. Auditado.
 *   POST { instance_id, enabled(bool), csrf_token } -> { ok, data:{ instance_id, strict } }
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) ApiResponse::forbidden('Acesso somente pelo Painel Master.');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? ($in['_csrf'] ?? null));
    if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
    }
    if ($ctx->getSuperAdminLevel() === 'viewer') ApiResponse::forbidden('Seu nível (viewer) não permite alterar o modo estrito do webhook.');

    // 1) Carrega o canal (sem escopo — Master ve tudo).
    $iid = (int)($in['instance_id'] ?? 0);
    if ($iid <= 0) ApiResponse::badRequest('instance_id obrigatório.');
    $wi   = new \WhatsAppInstance();
    $inst = $wi->find($iid);
    if (!$inst) ApiResponse::badRequest('Instância não encontrada.');
    $aid = (int)$inst['account_id'];

    // 2) Interpreta o booleano (aceita true/1/"1"/"true").
    $raw     = $in['enabled'] ?? false;
    $enabled = ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true');

    // 3) Guard: so liga o estrito se o canal JA tem token — senao seria inocuo.
    if ($enabled) {
        $cfg = $wi->getSettings($aid);
        if (trim((string)($cfg['webhook_token'] ?? '')) === '') {
            ApiResponse::badRequest('Gere o crachá (webhook_token) deste canal e confirme que ele já está sendo enviado antes de ativar o modo estrito.');
        }
    }

    // 4) Persiste a flag (mesma convencao key-value das outras settings).
    $wi->saveSetting($aid, 'webhook_token_strict', $enabled ? '1' : '0');

    // 5) Audita (sem segredo). O escopo é a CONTA (todos os canais dela); o instance_name
    //    fica no log só para rastrear qual linha da aba foi clicada.
    MasterAudit::log('ai_webhook.set_strict', 'whatsapp_instance', $iid,
        ($enabled ? 'Ativou' : 'Desativou') . ' o modo estrito do webhook_token da conta #' . $aid . ' (via canal ' . ($inst['instance_name'] ?? '') . ')',
        ['account_id' => $aid, 'strict' => $enabled]);

    ApiResponse::ok(['instance_id' => $iid, 'strict' => $enabled]);
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
