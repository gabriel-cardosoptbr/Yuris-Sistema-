<?php
/**
 * /api/master/whatsapp_channels.php — Fase 2 (Parte D): conceder/revogar
 * COMPARTILHAMENTO de canal WhatsApp (matriz -> filial), só pelo Painel Master.
 *
 * O compartilhamento NÃO é inferido de hierarquia em runtime: ele exige um
 * registro 'shared' EXPLÍCITO em whatsapp_channel_accounts, criado aqui. Esta é a
 * ÚNICA porta de concessão. A autorização em runtime (endpoints de dados) é feita
 * pela WhatsAppChannelAccessService e só vale com a feature flag ligada.
 *
 * Regra de hierarquia (validada na CONCESSÃO, não em runtime):
 *   só é possível compartilhar o canal de uma conta DONA (matriz) com uma FILIAL
 *   que tenha vínculo ATIVO com ela em account_vinculos. Qualquer outro alvo é
 *   recusado (422) — impede transformar a hierarquia num bypass genérico.
 *
 * Acesso: super_admin em sessão master_mode. CSRF nos POST. Tudo auditado.
 *
 *   GET  (default | ?list=1)         -> canais + compartilhamentos + filiais elegíveis
 *   POST action=grant   {channel_id, account_id, perms:{can_view,can_send,can_sync,can_delete_messages}}
 *   POST action=revoke  {channel_id, account_id}
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';
require_once __DIR__ . '/../../../app/Services/WhatsAppChannelAccessService.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;

session_start();
header('Cache-Control: no-store');
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$pdo    = \App\Models\Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

/** Canal (whatsapp_instances) resolvido NO BACKEND, com a conta dona. null se não existe. */
function _channel(\PDO $pdo, int $channelId): ?array {
    $st = $pdo->prepare(
        "SELECT wi.id, wi.instance_name, wi.status, wi.account_id AS owner_account_id,
                a.nome AS owner_nome, a.tipo AS owner_tipo
           FROM whatsapp_instances wi
           JOIN accounts a ON a.id = wi.account_id AND a.deleted_at IS NULL
          WHERE wi.id = ? LIMIT 1"
    );
    $st->execute([$channelId]);
    $r = $st->fetch(\PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Existe vínculo ATIVO matriz(owner)->filial(target) em account_vinculos?
 * É a checagem de hierarquia da CONCESSÃO. Sem ela, não se compartilha.
 */
function _isActiveFilialOf(\PDO $pdo, int $ownerAccountId, int $targetAccountId): bool {
    $st = $pdo->prepare(
        "SELECT 1
           FROM account_vinculos av
           JOIN accounts f ON f.id = av.filial_account_id
          WHERE av.matriz_account_id = ? AND av.filial_account_id = ?
            AND av.status = 'active' AND f.deleted_at IS NULL
          LIMIT 1"
    );
    $st->execute([$ownerAccountId, $targetAccountId]);
    return (bool)$st->fetchColumn();
}

// ─── GET: canais + compartilhamentos + filiais elegíveis ──────────────────────
if ($method === 'GET') {
    $channels = $pdo->query(
        "SELECT wi.id AS channel_id, wi.instance_name, wi.status,
                wi.account_id AS owner_account_id, a.nome AS owner_nome, a.tipo AS owner_tipo
           FROM whatsapp_instances wi
           JOIN accounts a ON a.id = wi.account_id AND a.deleted_at IS NULL
          ORDER BY a.nome ASC, wi.instance_name ASC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $shareSt = $pdo->prepare(
        "SELECT wca.account_id, a.nome, a.tipo,
                wca.can_view, wca.can_send, wca.can_sync, wca.can_delete_messages, wca.created_at
           FROM whatsapp_channel_accounts wca
           JOIN accounts a ON a.id = wca.account_id
          WHERE wca.channel_id = ? AND wca.access_type = 'shared' AND wca.revoked_at IS NULL
          ORDER BY a.nome ASC"
    );
    $eligSt = $pdo->prepare(
        "SELECT f.id AS account_id, f.nome, f.tipo
           FROM account_vinculos av
           JOIN accounts f ON f.id = av.filial_account_id
          WHERE av.matriz_account_id = ? AND av.status = 'active' AND f.deleted_at IS NULL
            AND f.id NOT IN (
                SELECT account_id FROM whatsapp_channel_accounts
                 WHERE channel_id = ? AND revoked_at IS NULL
            )
          ORDER BY f.nome ASC"
    );

    $out = [];
    foreach ($channels as $c) {
        $cid = (int)$c['channel_id'];
        $shareSt->execute([$cid]);
        $shares = array_map(static function (array $s): array {
            return [
                'account_id'          => (int)$s['account_id'],
                'nome'                => $s['nome'],
                'tipo'                => $s['tipo'],
                'can_view'            => (int)$s['can_view'],
                'can_send'            => (int)$s['can_send'],
                'can_sync'            => (int)$s['can_sync'],
                'can_delete_messages' => (int)$s['can_delete_messages'],
                'granted_at'          => $s['created_at'],
            ];
        }, $shareSt->fetchAll(\PDO::FETCH_ASSOC) ?: []);

        $eligSt->execute([(int)$c['owner_account_id'], $cid]);
        $eligible = $eligSt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $out[] = [
            'channel_id'       => $cid,
            'instance_name'    => $c['instance_name'],
            'status'           => $c['status'],
            'owner_account_id' => (int)$c['owner_account_id'],
            'owner_nome'       => $c['owner_nome'],
            'owner_tipo'       => $c['owner_tipo'],
            'shares'           => $shares,
            'eligible'         => array_map(static fn($e) => [
                'account_id' => (int)$e['account_id'], 'nome' => $e['nome'], 'tipo' => $e['tipo'],
            ], $eligible),
        ];
    }

    ApiResponse::ok([
        'sharing_enabled' => WhatsAppChannelAccessService::sharingEnabled(),
        'channels'        => $out,
    ]);
}

// ─── POST: grant / revoke ─────────────────────────────────────────────────────
if ($method === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['_csrf'] ?? ($in['csrf_token'] ?? null));
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
    }

    // Super admin de nível 'viewer' é read-only: pode listar (GET), mas não pode
    // conceder/revogar acesso a canal (mutação sensível de multi-tenant). Bloqueio
    // dirigido — só nega o nível explicitamente restrito, p/ nunca travar um admin
    // legítimo cujo nível seja 'super'/'operator'/null.
    if ($ctx->getSuperAdminLevel() === 'viewer') {
        ApiResponse::forbidden('Super admin de nível "viewer" não pode gerenciar compartilhamentos.');
    }

    $action    = $in['action'] ?? '';
    $channelId = (int)($in['channel_id'] ?? 0);
    $accountId = (int)($in['account_id'] ?? 0);
    if ($channelId <= 0 || $accountId <= 0) {
        ApiResponse::badRequest('channel_id e account_id são obrigatórios');
    }

    $ch = _channel($pdo, $channelId);
    if (!$ch) ApiResponse::badRequest('Canal não encontrado');
    $ownerId = (int)$ch['owner_account_id'];

    // Nunca mexer no registro do DONO por esta porta (evita rebaixar owner->shared
    // via ON DUPLICATE KEY do grant). O dono não é "compartilhado".
    if ($accountId === $ownerId) {
        ApiResponse::badRequest('A conta dona já tem acesso total ao próprio canal; não há o que compartilhar.');
    }

    if ($action === 'grant') {
        // Hierarquia: o alvo PRECISA ser filial ativa da conta dona do canal.
        if (!_isActiveFilialOf($pdo, $ownerId, $accountId)) {
            ApiResponse::badRequest('Só é possível compartilhar com uma filial ativa da conta dona do canal.');
        }

        // Permissões granulares. can_manage NUNCA é concedido a compartilhado (o
        // service força 0); aqui sequer aceitamos o campo. Default = view+send+sync.
        $p = is_array($in['perms'] ?? null) ? $in['perms'] : [];
        $perms = [
            'can_view'            => array_key_exists('can_view', $p) ? (int)!empty($p['can_view']) : 1,
            'can_send'            => array_key_exists('can_send', $p) ? (int)!empty($p['can_send']) : 1,
            'can_sync'            => array_key_exists('can_sync', $p) ? (int)!empty($p['can_sync']) : 1,
            'can_delete_messages' => (int)!empty($p['can_delete_messages']), // off por padrão p/ filial
        ];

        $ok = WhatsAppChannelAccessService::grant(
            $pdo, $channelId, $accountId, 'shared', $perms,
            (int)($_SESSION['user_id'] ?? 0) ?: null
        );
        if (!$ok) ApiResponse::serverError('Falha ao conceder compartilhamento');

        MasterAudit::log(
            'whatsapp_channel.grant', 'whatsapp_instance', $channelId,
            "Canal '{$ch['instance_name']}' (dono #{$ownerId}) compartilhado com conta #{$accountId}",
            ['owner_account_id' => $ownerId, 'target_account_id' => $accountId, 'perms' => $perms]
        );

        ApiResponse::ok([
            'channel_id' => $channelId,
            'account_id' => $accountId,
            'perms'      => $perms,
            'message'    => 'Compartilhamento concedido. Só vale em runtime com a flag WHATSAPP_SHARED_CHANNELS_ENABLED ligada.',
        ]);
    }

    if ($action === 'revoke') {
        // Revogar não re-valida hierarquia (revogar é sempre seguro), mas EXIGE que
        // exista um compartilhamento ATIVO — senão a auditoria registraria revogação
        // falsa (nada mudou). O service só revoga linhas 'shared' (dono nunca é alvo).
        $hasShare = $pdo->prepare(
            "SELECT 1 FROM whatsapp_channel_accounts
              WHERE channel_id = ? AND account_id = ? AND access_type = 'shared' AND revoked_at IS NULL
              LIMIT 1"
        );
        $hasShare->execute([$channelId, $accountId]);
        if (!$hasShare->fetchColumn()) {
            ApiResponse::badRequest('Não há compartilhamento ativo desse canal com essa conta.');
        }

        $ok = WhatsAppChannelAccessService::revoke(
            $pdo, $channelId, $accountId, (int)($_SESSION['user_id'] ?? 0) ?: null
        );
        if (!$ok) ApiResponse::serverError('Falha ao revogar compartilhamento');

        MasterAudit::log(
            'whatsapp_channel.revoke', 'whatsapp_instance', $channelId,
            "Compartilhamento do canal '{$ch['instance_name']}' (dono #{$ownerId}) revogado da conta #{$accountId}",
            ['owner_account_id' => $ownerId, 'target_account_id' => $accountId]
        );

        ApiResponse::ok([
            'channel_id' => $channelId,
            'account_id' => $accountId,
            'message'    => 'Compartilhamento revogado.',
        ]);
    }

    ApiResponse::badRequest('Ação desconhecida (use grant ou revoke)');
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
