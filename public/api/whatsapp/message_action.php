<?php
/**
 * api/whatsapp/message_action.php
 * ─────────────────────────────────────────────────────────────────────────────
 * P1-K (auditoria 2026-05-24): ações por mensagem individual.
 * Hoje suportado: DELETE (apagar mensagem outbound — revoke para todos via Evolution).
 *
 * POST { _csrf, action:'delete', message_id, jid, target_wamid }
 *
 * 1) Despacha "deleteMessage" para Evolution API (efeito visível no WhatsApp real)
 * 2) Marca is_deleted = 1 no banco local (filtrado por account_id pra evitar cross-tenant)
 * 3) Frontend renderiza bubble vazia "Mensagem apagada" no lugar
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/WhatsAppMessage.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/EvolutionApiService.php';
require_once __DIR__ . '/../../../app/WhatsAppAgente/WhatsAppChannelAccessService.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ErrorReporter.php';

use App\Core\AccountContext;
use App\Services\EvolutionApiService;

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

try {
    $ctx       = AccountContext::fromSession();
    $accountId = $ctx->getAccountId();
    $payload   = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($payload['_csrf']) || $payload['_csrf'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF inválido']);
        exit;
    }

    $action      = trim($payload['action'] ?? '');
    $messageId   = (int)($payload['message_id'] ?? 0);
    $jid         = trim($payload['jid'] ?? '');
    $targetWamid = trim($payload['target_wamid'] ?? '');

    if ($action !== 'delete') {
        http_response_code(400);
        echo json_encode(['error' => 'Ação não suportada (use action=delete)']);
        exit;
    }

    if (!$messageId || $jid === '' || $targetWamid === '') {
        http_response_code(400);
        echo json_encode(['error' => 'message_id, jid e target_wamid obrigatórios']);
        exit;
    }

    // Apagar mensagem = permissão DEDICADA 'delete_messages' no canal
    // (deny-by-default). Dono sempre tem; conta compartilhada só se concedido
    // explicitamente (off por padrão p/ filial). Canal/credenciais resolvidos no
    // backend (dono do canal), nunca do front.
    $pdo     = \App\Core\Database::getConnection();
    $ch      = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $payload['channel_id'] ?? null, 'delete_messages');
    $cfg     = $ch['cfg'];
    $name    = $ch['instance_name'];

    // 1) Despacha revoke para Evolution (best-effort)
    try {
        $evo  = new EvolutionApiService($cfg);
        $evo->deleteMessage($name, [
            'remoteJid' => $jid,
            'id'        => $targetWamid,
            'fromMe'    => true,
        ]);
    } catch (\Throwable $_) {}

    // 2) Soft delete local — escopado à CONTA REQUISITANTE (nunca ao dono do canal).
    //    Como WhatsAppMessage::save grava account_id = conta DONA da instância, uma
    //    conta com acesso COMPARTILHADO (filial) não casa o account_id das mensagens
    //    do canal e portanto NÃO consegue apagar mensagem da matriz nem de outra
    //    filial (markDeleted devolve false -> 403). O dono (matriz) apaga as suas.
    $model = new WhatsAppMessage();
    $ok    = $model->markDeleted($messageId, [$accountId]);
    if (!$ok) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão / mensagem não pertence à conta']);
        exit;
    }

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    \App\Core\ErrorReporter::handle($e);
}
