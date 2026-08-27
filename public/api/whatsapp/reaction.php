<?php
/**
 * api/whatsapp/reaction.php
 * ─────────────────────────────────────────────────────────────────────────────
 * P2-M (auditoria 2026-05-24): envia uma reaction (emoji) numa mensagem
 * existente. Salva no banco e despacha para Evolution API.
 *
 * POST { _csrf, jid, message_id, target_wamid, emoji }
 *   emoji = '' remove a reaction.
 *
 * Persiste em whatsapp_reactions (UNIQUE por reactor — sobrescreve emoji
 * anterior do mesmo reactor). Frontend agrega por emoji e renderiza
 * abaixo da bubble alvo.
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

    $jid         = trim($payload['jid'] ?? '');
    $targetWamid = trim($payload['target_wamid'] ?? '');
    $emoji       = (string)($payload['emoji'] ?? '');
    $fromMe      = !empty($payload['from_me']);

    if ($jid === '' || $targetWamid === '') {
        http_response_code(400);
        echo json_encode(['error' => 'jid e target_wamid obrigatórios']);
        exit;
    }

    // Reagir é equivalente a ENVIAR → permissão 'send' no canal (deny-by-default).
    // Canal/credenciais resolvidos no backend (dono do canal), nunca do front.
    $pdo        = \App\Core\Database::getConnection();
    $ch         = WhatsAppChannelAccessService::resolveForRequest($pdo, $accountId, $payload['channel_id'] ?? null, 'send');
    $instanceId = (int)$ch['channel_id'];
    $ownerId    = (int)$ch['owner_account_id'];
    $cfg        = $ch['cfg'];
    $name       = $ch['instance_name'];

    // 1) Persiste no banco local (UPSERT por reactor='me'). account_id = DONO do
    //    canal, pra manter coerência com as mensagens (escopadas pelo dono).
    $model = new WhatsAppMessage();
    $model->upsertReaction([
        'account_id'   => $ownerId,
        'instance_id'  => $instanceId,
        'target_wamid' => $targetWamid,
        'reactor_jid'  => 'me',
        'reactor_name' => $_SESSION['user_nome'] ?? 'Você',
        'emoji'        => $emoji,
        'is_from_me'   => 1,
    ]);

    // 2) Despacha para Evolution API (best-effort — não bloqueia salvar local)
    // Mas captura o resultado pra retornar ao frontend (debug / status visivel).
    $evoResult = null;
    try {
        $evo  = new EvolutionApiService($cfg); // $cfg/$name já resolvidos (dono do canal)

        // Em grupos, a Evolution exige `participant` no key (jid do autor real
        // da msg sendo reagida). Sem isso, o WhatsApp não encontra a msg alvo
        // e a reação não aparece no celular. Buscamos no DB.
        $key = [
            'remoteJid' => $jid,
            'id'        => $targetWamid,
            'fromMe'    => (bool)$fromMe,
        ];
        if (str_ends_with($jid, '@g.us')) {
            $pdoLk = \App\Core\Database::getConnection();
            $stLk = $pdoLk->prepare(
                'SELECT participant_jid FROM whatsapp_messages
                  WHERE instance_id = ? AND wamid = ? LIMIT 1'
            );
            $stLk->execute([$instanceId, $targetWamid]);
            $partJid = $stLk->fetchColumn();
            if ($partJid) $key['participant'] = $partJid;
        }

        $evoResult = $evo->sendReaction($name, $key, $emoji);
        // Log se Evolution retornou erro (ajuda diagnosticar quando reaction nao
        // aparece no WhatsApp real)
        if (($evoResult['_http'] ?? 0) >= 400) {
            // F4 (auditoria): mascara dígitos (remoteJid/participant no key, telefone no body).
            error_log(sprintf(
                '[reaction] Evolution rejeitou: http=%d key=%s body=%s',
                $evoResult['_http'] ?? 0,
                preg_replace('/\d{5,}/', '*****', json_encode($key)),
                preg_replace('/\d{5,}/', '*****', substr(json_encode($evoResult), 0, 500))
            ));
        }
    } catch (\Throwable $e) {
        error_log('[reaction] Evolution exception: ' . $e->getMessage());
    }

    echo json_encode([
        'ok'    => true,
        'emoji' => $emoji,
        // Espelha o status do Evolution pro front saber se replicou
        'evolution' => [
            'http'    => $evoResult['_http'] ?? null,
            'ok'      => isset($evoResult['_http']) && $evoResult['_http'] < 400,
            'message' => $evoResult['message'] ?? ($evoResult['error'] ?? null),
        ],
    ]);

} catch (Throwable $e) {
    \App\Core\ErrorReporter::handle($e);
}
