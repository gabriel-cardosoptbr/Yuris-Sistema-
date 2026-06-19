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
require_once __DIR__ . '/../Models/WhatsAppInstance.php';
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
            case 'view':            return 'can_view';
            case 'send':            return 'can_send';
            case 'sync':            return 'can_sync';
            case 'delete_messages': return 'can_delete_messages';
            case 'manage':          return 'can_manage';
            default:                return null;
        }
    }

    /** Grant ATIVO de (account, channel), ou null. */
    private static function grantRow(\PDO $pdo, int $accountId, int $channelId): ?array
    {
        $st = $pdo->prepare(
            'SELECT access_type, can_view, can_send, can_sync, can_delete_messages, can_manage
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
            self::deny();
        }
        if ($perm === 'manage') {
            error_log(sprintf('[wa-channel-access] MANAGE account=%d channel=%d owner=%d type=%s',
                $accountId, $channelId, $ch['owner_account_id'], $ch['access_type']));
        }
        return $ch;
    }

    /**
     * Resposta 403 GENÉRICA + exit. Nunca revela se o canal existe/é de quem
     * (anti-enumeração/IDOR). Centralizada para que TODO ponto de negação use a
     * mesma mensagem e os mesmos headers.
     */
    public static function deny(): void
    {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode(['ok' => false, 'error' => 'Acesso negado ao canal de WhatsApp.']);
        exit;
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

    /**
     * Primeiro canal COMPARTILHADO visível pra conta (filial herdando o canal da
     * matriz). Só faz sentido com a flag ligada — o caller é quem decide chamar.
     * Determinístico: mais recente primeiro.
     */
    public static function firstSharedChannelId(\PDO $pdo, int $accountId): ?int
    {
        if ($accountId <= 0) return null;
        $st = $pdo->prepare(
            "SELECT channel_id FROM whatsapp_channel_accounts
              WHERE account_id = ? AND access_type = 'shared'
                AND revoked_at IS NULL AND can_view = 1
              ORDER BY created_at DESC, channel_id DESC LIMIT 1"
        );
        $st->execute([$accountId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    /** A conta tem QUALQUER vínculo ativo (dono ou compartilhado)? */
    public static function hasAnyGrant(\PDO $pdo, int $accountId): bool
    {
        if ($accountId <= 0) return false;
        $st = $pdo->prepare(
            'SELECT 1 FROM whatsapp_channel_accounts
              WHERE account_id = ? AND revoked_at IS NULL LIMIT 1'
        );
        $st->execute([$accountId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * RESOLUÇÃO ÚNICA de canal para um endpoint do pacote WhatsApp. Faz TUDO no
     * backend, nunca confiando no front:
     *
     *   1) resolve o channel_id efetivo, nesta ordem de precedência:
     *        a) channel_id pedido pelo front — só se for VISÍVEL à conta
     *           (anti-tampering/enumeração); caso contrário, NEGA.
     *        b) canal PRÓPRIO (onde a conta é dona) — o padrão.
     *        c) com a flag LIGADA, o primeiro canal COMPARTILHADO visível
     *           (filial herdando o canal da matriz).
     *        d) bootstrap do canal próprio a partir das settings da conta
     *           (compat com o findOrCreate legado; default 'yuris-crm'),
     *           SÓ quando a conta não tem NENHUM vínculo (nem dono, nem
     *           compartilhado). Assim uma filial herdeira nunca ganha um canal
     *           próprio vazio que competiria com o da matriz.
     *   2) AUTORIZA ($perm) — deny-by-default; 403 genérico + exit se negar.
     *   3) carrega a config Evolution do DONO do canal (base_url/api_key/instance);
     *      NUNCA da conta requisitante nem do front.
     *
     * @param mixed  $requestedChannelId  channel_id que o front mandou (ou null)
     * @param string $perm                view|send|sync|delete_messages|manage
     * @return array{
     *   channel_id:int, owner_account_id:int, instance_name:string,
     *   instance_row:array, cfg:array, access_type:string, is_owner:bool, shared:bool
     * }
     */
    public static function resolveForRequest(\PDO $pdo, int $accountId, $requestedChannelId, string $perm): array
    {
        if ($accountId <= 0) self::deny();
        $model = new \WhatsAppInstance();

        $channelId = null;

        // (a) canal pedido explicitamente — só se visível.
        $req = (int)($requestedChannelId ?? 0);
        if ($req > 0) {
            if (!in_array($req, self::viewableChannelIds($pdo, $accountId), true)) {
                self::deny();
            }
            $channelId = $req;
        }

        // (b) canal próprio (default).
        if ($channelId === null) {
            $channelId = self::ownChannelId($pdo, $accountId);
        }

        // (c) filial herdando: só com a flag ligada.
        if ($channelId === null && self::sharingEnabled()) {
            $channelId = self::firstSharedChannelId($pdo, $accountId);
        }

        // (d) bootstrap do próprio — só se a conta não tem NENHUM vínculo.
        if ($channelId === null && !self::hasAnyGrant($pdo, $accountId)) {
            $cfg0     = $model->getSettings($accountId);
            $instName = $cfg0['evolution_instance'] ?? 'yuris-crm';
            $row      = $model->findOrCreate($instName, '', $accountId);
            $channelId = (int)$row['id'];
            self::grant($pdo, $channelId, $accountId, 'owner', [], null); // idempotente
        }

        if ($channelId === null) self::deny();

        // (2) autoriza (deny-by-default).
        $ch = self::assert($pdo, $accountId, (int)$channelId, $perm);

        // (3) config Evolution SEMPRE do DONO do canal — nunca do front/filial.
        $cfg     = $model->getSettings((int)$ch['owner_account_id']);
        $instRow = $model->find((int)$ch['channel_id']); // já autorizado; sem escopo

        return [
            'channel_id'       => (int)$ch['channel_id'],
            'owner_account_id' => (int)$ch['owner_account_id'],
            'instance_name'    => (string)$ch['instance_name'],
            'instance_row'     => is_array($instRow) ? $instRow : [],
            'cfg'              => $cfg,
            'access_type'      => (string)$ch['access_type'],
            'is_owner'         => ($ch['access_type'] === 'owner'),
            'shared'           => ($ch['access_type'] !== 'owner'),
        ];
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
                (channel_id, account_id, access_type, can_view, can_send, can_sync, can_delete_messages, can_manage, granted_by, created_at, revoked_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW(),NULL)
             ON DUPLICATE KEY UPDATE
                access_type         = VALUES(access_type),
                can_view            = VALUES(can_view),
                can_send            = VALUES(can_send),
                can_sync            = VALUES(can_sync),
                can_delete_messages = VALUES(can_delete_messages),
                can_manage          = VALUES(can_manage),
                granted_by          = VALUES(granted_by),
                revoked_at          = NULL'
        );
        $ok = $st->execute([
            $channelId, $accountId, $accessType,
            $v('can_view'), $v('can_send'), $v('can_sync'),
            $v('can_delete_messages'),
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
