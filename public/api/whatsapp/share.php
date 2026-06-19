<?php
/**
 * /api/whatsapp/share.php — TENANT: a MATRIZ controla, por filial, o
 * compartilhamento do SEU canal WhatsApp.
 *
 * Espelha o endpoint Master (whatsapp_channels.php), mas o DONO do canal e
 * SEMPRE a conta logada. A matriz so consegue:
 *   - compartilhar o PROPRIO canal (ownChannelId da conta logada);
 *   - com FILIAIS ATIVAS dela (account_vinculos status=active);
 *   - permissoes granulares ver/enviar/sincronizar. 'manage' (conectar/QR/
 *     logout/excluir/webhook) NUNCA e concedido (exclusivo do dono). 'apagar
 *     mensagens' tambem fica com a matriz.
 *
 * Acesso: login + owner/admin da conta. CSRF nos POST. Auditado (account_audit_log).
 * O compartilhamento so vale em runtime com a feature flag
 * WHATSAPP_SHARED_CHANNELS_ENABLED ligada (controle da Inovaize).
 *
 *   GET                 -> { has_channel, sharing_enabled, channel, filiais:[{account_id,nome,shared,perms}] }
 *   POST action=grant   { filial_account_id, perms:{can_view,can_send,can_sync}, csrf_token }
 *   POST action=revoke  { filial_account_id, csrf_token }
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/WhatsAppInstance.php';
require_once __DIR__ . '/../../../app/Services/WhatsAppChannelAccessService.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Models\Account;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit; }
if (!$ctx->isOwnerOrAdmin()) {
    ApiResponse::forbidden('Apenas owner/admin pode gerenciar o compartilhamento de WhatsApp.');
}

$accountId  = $ctx->getAccountId();
$userId     = $ctx->getUserId();
$pdo        = \App\Models\Database::getConnection();
$model      = new WhatsAppInstance();
$ownChannel = WhatsAppChannelAccessService::ownChannelId($pdo, $accountId);
$method     = $_SERVER['REQUEST_METHOD'];

/** A conta logada e MATRIZ de $filialId com vinculo ATIVO? (gate de hierarquia) */
function _isMyActiveFilial(\PDO $pdo, int $matrizId, int $filialId): bool {
    if ($matrizId <= 0 || $filialId <= 0) return false;
    $st = $pdo->prepare(
        "SELECT 1 FROM account_vinculos av
           JOIN accounts f ON f.id = av.filial_account_id
          WHERE av.matriz_account_id = ? AND av.filial_account_id = ?
            AND av.status = 'active' AND f.deleted_at IS NULL
          LIMIT 1"
    );
    $st->execute([$matrizId, $filialId]);
    return (bool)$st->fetchColumn();
}

// ─── GET: estado do compartilhamento do canal da matriz ───────────────────────
if ($method === 'GET') {
    $sharing = WhatsAppChannelAccessService::sharingEnabled();
    if (!$ownChannel) {
        ApiResponse::ok(['has_channel' => false, 'sharing_enabled' => $sharing, 'channel' => null, 'filiais' => []]);
    }

    $inst = $model->find((int)$ownChannel);
    $channel = $inst ? [
        'channel_id'    => (int)$inst['id'],
        'instance_name' => (string)$inst['instance_name'],
        'status'        => (string)($inst['status'] ?? 'close'),
    ] : null;

    // Filiais ATIVAS da matriz logada
    $fil = $pdo->prepare(
        "SELECT av.filial_account_id AS account_id, a.nome
           FROM account_vinculos av
           JOIN accounts a ON a.id = av.filial_account_id
          WHERE av.matriz_account_id = ? AND av.status = 'active' AND a.deleted_at IS NULL
          ORDER BY a.nome ASC"
    );
    $fil->execute([$accountId]);
    $filiais = $fil->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    // Compartilhamentos ativos no canal proprio
    $sh = $pdo->prepare(
        "SELECT account_id, can_view, can_send, can_sync
           FROM whatsapp_channel_accounts
          WHERE channel_id = ? AND access_type = 'shared' AND revoked_at IS NULL"
    );
    $sh->execute([(int)$ownChannel]);
    $shareByAcc = [];
    foreach ($sh->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
        $shareByAcc[(int)$row['account_id']] = [
            'can_view' => (int)$row['can_view'],
            'can_send' => (int)$row['can_send'],
            'can_sync' => (int)$row['can_sync'],
        ];
    }

    $out = array_map(static function (array $f) use ($shareByAcc): array {
        $aid = (int)$f['account_id'];
        $p   = $shareByAcc[$aid] ?? null;
        return [
            'account_id' => $aid,
            'nome'       => $f['nome'],
            'shared'     => $p !== null,
            'perms'      => $p ?? ['can_view' => 1, 'can_send' => 1, 'can_sync' => 1],
        ];
    }, $filiais);

    ApiResponse::ok([
        'has_channel'     => true,
        'sharing_enabled' => $sharing,
        'channel'         => $channel,
        'filiais'         => $out,
    ]);
}

// ─── POST: grant / revoke ─────────────────────────────────────────────────────
if ($method === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? ($in['_csrf'] ?? null));
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
    }

    if (!$ownChannel) {
        ApiResponse::badRequest('Sua conta ainda não tem um canal WhatsApp próprio. Configure a conexão antes de compartilhar.');
    }

    $action = $in['action'] ?? '';
    $filial = (int)($in['filial_account_id'] ?? 0);
    if ($filial <= 0) ApiResponse::badRequest('filial_account_id é obrigatório.');

    if ($action === 'grant') {
        if (!_isMyActiveFilial($pdo, $accountId, $filial)) {
            ApiResponse::badRequest('Só é possível compartilhar com uma filial ativa vinculada à sua conta.');
        }
        // Apenas as permissoes granulares que a filial pode ter. 'manage' e
        // 'delete_messages' NAO sao concedidos por aqui (ficam com a matriz).
        $p = is_array($in['perms'] ?? null) ? $in['perms'] : [];
        $perms = [
            'can_view' => array_key_exists('can_view', $p) ? (int)!empty($p['can_view']) : 1,
            'can_send' => array_key_exists('can_send', $p) ? (int)!empty($p['can_send']) : 1,
            'can_sync' => array_key_exists('can_sync', $p) ? (int)!empty($p['can_sync']) : 1,
        ];
        if ($perms['can_view'] + $perms['can_send'] + $perms['can_sync'] === 0) {
            ApiResponse::badRequest('Selecione ao menos uma permissão (ou use "parar de compartilhar").');
        }

        $ok = WhatsAppChannelAccessService::grant($pdo, (int)$ownChannel, $filial, 'shared', $perms, $userId ?: null);
        if (!$ok) ApiResponse::serverError('Falha ao compartilhar o canal.');

        Account::audit($accountId, 'whatsapp_share.grant', [
            'user_id'     => (int)$userId,
            'entidade'    => 'whatsapp_channel',
            'entidade_id' => (int)$ownChannel,
            'detalhes'    => ['filial_account_id' => $filial, 'perms' => $perms],
        ]);

        ApiResponse::ok([
            'shared'  => true,
            'perms'   => $perms,
            'message' => 'WhatsApp compartilhado com a filial.',
        ]);
    }

    if ($action === 'revoke') {
        $changed = WhatsAppChannelAccessService::revoke($pdo, (int)$ownChannel, $filial, $userId ?: null);
        Account::audit($accountId, 'whatsapp_share.revoke', [
            'user_id'     => (int)$userId,
            'entidade'    => 'whatsapp_channel',
            'entidade_id' => (int)$ownChannel,
            'detalhes'    => ['filial_account_id' => $filial, 'changed' => $changed],
        ]);
        ApiResponse::ok(['shared' => false, 'message' => $changed ? 'Compartilhamento removido.' : 'Não havia compartilhamento ativo.']);
    }

    ApiResponse::badRequest('Ação desconhecida (use grant ou revoke).');
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
