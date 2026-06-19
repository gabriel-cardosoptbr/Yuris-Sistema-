<?php
/**
 * WhatsAppChannelAccessService — camada ÚNICA de autorização de canal WhatsApp.
 *
 * Princípios (spec de segurança Fase 2):
 *  - DENY BY DEFAULT. Sem registro ativo em whatsapp_channel_accounts => sem acesso.
 *  - channel_id = whatsapp_instances.id. NUNCA confiar em instance_name/account_id/
 *    channel_id vindos do front sem validar contra um grant ativo da conta.
 *  - DONO (access_type='owner') tem todas as permissões.
 *  - COMPARTILHADO (access_type='shared') só vale se a feature flag estiver LIGADA,
 *    e só concede as permissões granulares marcadas (can_view/can_send/can_sync).
 *  - 'manage' (conectar/QR/logout/excluir/webhook/credenciais) é EXCLUSIVO DO DONO.
 *    Conta compartilhada nunca administra o canal, mesmo que a coluna esteja 1.
 *  - getPipelineAccountId() NÃO é usado aqui (autorização de runtime); só serve pra
 *    validar hierarquia na hora de CONCEDER o vínculo (em create_filial).
 *
 * Uso típico no endpoint:
 *   $ctx = AccountContext::fromSession();
 *   $accountId = $ctx->getAccountId();
 *   $channelId = WhatsAppChannelAccessService::resolveRequestedChannel($pdo, $accountId, $in['channel_id'] ?? null);
 *   $ch = WhatsAppChannelAccessService::assert($pdo, $accountId, $channelId, 'send'); // 403+exit se negar
 *   // $ch['owner_account_id'] / $ch['instance_name'] resolvidos no backend.
 */

require_once __DIR__ . '/../Models/Database.php';
require_once __DIR__ . '/../Helpers/EnvLoader.php';

class WhatsAppChannelAccessService
{
    /** Feature flag global. Compartilhamento só vale quando LIGADA. */
    public static function sharingEnabled(): bool
    {
        $v = strtolower(trim(\App\Helpers\EnvLoader::get('WHATSAPP_SHARED_CHANNELS_ENABLED', 'false')));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /** Mapeia permissão -> coluna. */
    private static function permColumn(string $perm): ?string
    {
        switch ($perm) {
            case 'view':   return 'can_view';
            case 'send':   return 'can_send';
            case 'sync':   return 'can_sync';
            case 'manage': return 'can_manage';
            default:       return null;
        }
    }

    /** Grant ATIVO de (account, channel), ou null. */
    private static function grantRow(\PDO $pdo, int $accountId, int $channelId): ?array
    {
        $st = $pdo->prepare(
            'SELECT access_type, can_view, can_send, can_sync, can_manage
               FROM whatsapp_channel_accounts
              WHERE account_id = ? AND channel_id = ? AND revoked_at IS NULL
              LIMIT 1'
        );
        $st->execute([$accountId, $channelId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Verifica acesso. Retorna o contexto do canal (resolvido NO BACKEND) se a conta
     * tem $perm sobre $channelId; senão null. Deny-by-default.
     *
     * @return array{channel_id:int, owner_account_id:int, instance_name:string, status:string, access_type:string}|null
     */
    public static function check(\PDO $pdo, int $accountId, int $channelId, string $perm): ?array
    {
        if ($accountId <= 0 || $channelId <= 0) return null;
        $col = self::permColumn($perm);
        if ($col === null) return null;

        // Canal precisa existir (resolve dono + nome no backend, nunca do front).
        $st = $pdo->prepare('SELECT id, account_id, instance_name, status FROM whatsapp_instances WHERE id = ? LIMIT 1');
        $st->execute([$channelId]);
        $inst = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$inst) return null;

        $grant = self::grantRow($pdo, $accountId, $channelId);
        if (!$grant) return null; // sem vínculo => negado

        $isOwner = ($grant['access_type'] === 'owner');

        if ($isOwner) {
            $allowed = true; // dono tem todas as permissões
        } else {
            // Compartilhado: só com a flag ligada; e 'manage' nunca é concedido a shared.
            if (!self::sharingEnabled()) return null;
            if ($perm === 'manage')      return null; // exclusivo do dono
            $allowed = ((int)($grant[$col] ?? 0) === 1);
        }

        if (!$allowed) return null;

        return [
            'channel_id'       => (int)$inst['id'],
            'owner_account_id' => (int)$inst['account_id'],
            'instance_name'    => (string)$inst['instance_name'],
            'status'           => (string)$inst['status'],
            'access_type'      => (string)$grant['access_type'],
        ];
    }

    /**
     * Igual ao check, mas em caso de negação devolve 403 genérico e encerra.
     * Loga acessos administrativos (manage) pra auditoria.
     */
    public static function assert(\PDO $pdo, int $accountId, int $channelId, string $perm): array
    {
        $ch = self::check($pdo, $accountId, $channelId, $perm);
        if ($ch === null) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
            }
            // Mensagem genérica — não revela existência/posse do canal (anti-enumeração).
            echo json_encode(['ok' => false, 'error' => 'Acesso negado ao canal de WhatsApp.']);
            exit;
        }
        if ($perm === 'manage') {
            error_log(sprintf('[wa-channel-access] MANAGE account=%d channel=%d owner=%d type=%s',
                $accountId, $channelId, $ch['owner_account_id'], $ch['access_type']));
        }
        return $ch;
    }

    /**
     * IDs de canais que a conta pode VER. Dono sempre; compartilhados só com a flag
     * ligada (e can_view=1). Usado nas LISTAGENS — nunca expor canal não vinculado.
     *
     * @return int[]
     */
    public static function viewableChannelIds(\PDO $pdo, int $accountId): array
    {
        if ($accountId <= 0) return [];
        if (self::sharingEnabled()) {
            $st = $pdo->prepare(
                "SELECT channel_id FROM whatsapp_channel_accounts
                  WHERE account_id = ? AND revoked_at IS NULL
                    AND (access_type = 'owner' OR can_view = 1)"
            );
        } else {
            $st = $pdo->prepare(
                "SELECT channel_id FROM whatsapp_channel_accounts
                  WHERE account_id = ? AND revoked_at IS NULL AND access_type = 'owner'"
            );
        }
        $st->execute([$accountId]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /** Canal PRÓPRIO da conta (onde ela é dona). null se não tiver. */
    public static function ownChannelId(\PDO $pdo, int $accountId): ?int
    {
        if ($accountId <= 0) return null;
        $st = $pdo->prepare(
            "SELECT channel_id FROM whatsapp_channel_accounts
              WHERE account_id = ? AND access_type = 'owner' AND revoked_at IS NULL
              ORDER BY channel_id DESC LIMIT 1"
        );
        $st->execute([$accountId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    /**
     * Resolve o canal de uma requisição. Se o front mandou channel_id, valida que a
     * conta tem QUALQUER vínculo de visão sobre ele (senão nega via assert no caller);
     * se não mandou, usa o canal próprio. Nunca confia cegamente no valor do front.
     */
    public static function resolveRequestedChannel(\PDO $pdo, int $accountId, $requested): ?int
    {
        $req = (int)($requested ?? 0);
        if ($req > 0) {
            // Só aceita se estiver na lista de canais visíveis da conta (anti-tampering/enumeração).
            return in_array($req, self::viewableChannelIds($pdo, $accountId), true) ? $req : null;
        }
        return self::ownChannelId($pdo, $accountId);
    }

    /** Concede/atualiza um vínculo de canal (idempotente por (channel,account)). */
    public static function grant(\PDO $pdo, int $channelId, int $accountId, string $accessType, array $perms = [], ?int $grantedBy = null): bool
    {
        if ($channelId <= 0 || $accountId <= 0) return false;
        $accessType = in_array($accessType, ['owner', 'shared'], true) ? $accessType : 'shared';
        $isOwner = ($accessType === 'owner');
        $v = static fn(string $k) => $isOwner ? 1 : (int)!empty($perms[$k]);
        $st = $pdo->prepare(
            'INSERT INTO whatsapp_channel_accounts
                (channel_id, account_id, access_type, can_view, can_send, can_sync, can_manage, granted_by, created_at, revoked_at)
             VALUES (?,?,?,?,?,?,?,?,NOW(),NULL)
             ON DUPLICATE KEY UPDATE
                access_type = VALUES(access_type),
                can_view    = VALUES(can_view),
                can_send    = VALUES(can_send),
                can_sync    = VALUES(can_sync),
                can_manage  = VALUES(can_manage),
                granted_by  = VALUES(granted_by),
                revoked_at  = NULL'
        );
        $ok = $st->execute([
            $channelId, $accountId, $accessType,
            $v('can_view'), $v('can_send'), $v('can_sync'),
            $isOwner ? 1 : 0, // 'manage' nunca concedido a shared, mesmo se pedirem
            $grantedBy,
        ]);
        error_log(sprintf('[wa-channel-access] GRANT channel=%d account=%d type=%s by=%s',
            $channelId, $accountId, $accessType, $grantedBy === null ? 'system' : (string)$grantedBy));
        return (bool)$ok;
    }

    /** Revoga (soft) um vínculo. */
    public static function revoke(\PDO $pdo, int $channelId, int $accountId, ?int $by = null): bool
    {
        $st = $pdo->prepare(
            'UPDATE whatsapp_channel_accounts SET revoked_at = NOW()
              WHERE channel_id = ? AND account_id = ? AND revoked_at IS NULL'
        );
        $ok = $st->execute([$channelId, $accountId]);
        error_log(sprintf('[wa-channel-access] REVOKE channel=%d account=%d by=%s',
            $channelId, $accountId, $by === null ? 'system' : (string)$by));
        return (bool)$ok;
    }
}
