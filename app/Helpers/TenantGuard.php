<?php
namespace App\Helpers;

use App\Models\Database;

/**
 * TenantGuard — validações de acesso a recursos no padrão multi-tenant.
 *
 * Uso típico em endpoints de RECURSOS FILHOS (prazos, tarefas, comentários, anexos),
 * onde o tenant é herdado da entidade pai (processo / card / task_board).
 *
 *   TenantGuard::assertProcessoAcessivel($ctx, $processoId);
 *   TenantGuard::assertCardAcessivel($ctx, $cardId);
 *   TenantGuard::assertTaskAcessivel($ctx, $taskId);
 *
 * Aborta com HTTP 403 se a entidade pai não pertence ao tenant atual,
 * não está compartilhada via resource_share, nem foi liberada por module share.
 */
class TenantGuard
{
    /**
     * Verifica acesso a um processo (próprio, compartilhado ou herdado por matriz/filial).
     * Aborta com 403 se não tiver acesso.
     */
    public static function assertProcessoAcessivel(AccountContext $ctx, int $processoId): void
    {
        self::_assertResource($ctx, 'processos', 'processo', $processoId, 'p.deleted_at IS NULL');
    }

    public static function assertCardAcessivel(AccountContext $ctx, int $cardId): void
    {
        self::_assertResource($ctx, 'cards', 'card', $cardId, 'c.deleted_at IS NULL', 'c');
    }

    public static function assertTaskAcessivel(AccountContext $ctx, int $taskId): void
    {
        // Tarefa não tem account_id direto; herda via board_id → task_boards.account_id.
        $tenantIds = $ctx->getAccessibleAccountIds('tarefas');
        if (empty($tenantIds)) self::_forbid('Sem tenants acessíveis');

        $ph = []; $params = ['tid' => $taskId];
        foreach ($tenantIds as $i => $aid) {
            $k = "tgt_{$i}";
            $ph[] = ":{$k}";
            $params[$k] = (int)$aid;
        }
        $in = '(' . implode(',', $ph) . ')';

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT 1 FROM tasks t
            INNER JOIN task_boards b ON b.id = t.board_id
            WHERE t.id = :tid
              AND b.account_id IN $in
            LIMIT 1
        ");
        $stmt->execute($params);
        if (!$stmt->fetch()) self::_forbid("Tarefa #{$taskId} fora do tenant atual");
    }

    /**
     * Internal: valida que um recurso (processos|cards) é acessível pela sessão.
     *
     * Considera:
     *  - account_id pertencente ao tenant atual ou às filiais (getAccessibleAccountIds)
     *  - resource_share ativo com to_account_id no tenant OR to_user_id == usuário atual
     *  - module share liberando aquela aba inteira (via getAccessibleAccountIds($module))
     */
    private static function _assertResource(
        AccountContext $ctx,
        string $tabela,
        string $resourceType,
        int $resourceId,
        string $extraWhere = '1',
        string $alias = 'p'
    ): void {
        $module    = $resourceType === 'card' ? 'prospeccao' : 'processos';
        $tenantIds = $ctx->getAccessibleAccountIds($module);
        if (empty($tenantIds)) self::_forbid('Sem tenants acessíveis');

        $ph = []; $params = ['rid' => $resourceId, 'uid' => $ctx->getUserId(), 'rtype' => $resourceType];
        foreach ($tenantIds as $i => $aid) {
            $k = "tgr_{$i}";
            $ph[] = ":{$k}";
            $params[$k] = (int)$aid;
        }
        $in = '(' . implode(',', $ph) . ')';

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT 1 FROM {$tabela} {$alias}
            WHERE {$alias}.id = :rid
              AND {$extraWhere}
              AND (
                {$alias}.account_id IN $in
                OR EXISTS (
                  SELECT 1 FROM resource_shares rs
                  WHERE rs.resource_type = :rtype
                    AND rs.resource_id   = {$alias}.id
                    AND rs.status        = 'active'
                    AND (rs.to_account_id IN $in OR rs.to_user_id = :uid)
                )
              )
            LIMIT 1
        ");
        $stmt->execute($params);
        if (!$stmt->fetch()) self::_forbid("Recurso {$resourceType} #{$resourceId} sem permissão ou inexistente");
    }

    private static function _forbid(string $msg): never
    {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Acesso negado: ' . $msg]);
        exit;
    }

    /**
     * Defense-in-depth contra CSRF — combina 3 verificações em camadas:
     *   1. Cookies já são SameSite=Lax (browser bloqueia cross-site automatic
     *      no nível do navegador para POST cross-site).
     *   2. Header Origin OU Referer precisa começar com o host atual (same-origin).
     *   3. Se cliente envia token CSRF (header X-CSRF-Token ou body csrf_token),
     *      validamos contra $_SESSION['csrf_token'].
     *
     * A função aceita o request se:
     *   • Origin/Referer = same-origin    OU
     *   • Token CSRF presente e válido
     *
     * Rejeita 403 só se AMBOS faltarem (Origin/Referer cross-site E sem token).
     *
     * Histórico:
     *   2026-05-26 — adicionada na auditoria pré-deploy AWS. Endpoints como
     *   processo_history.php, processo_prazos.php, processo_tarefas.php e
     *   processes.php eram POST sem nenhuma verificação anti-CSRF — esta
     *   função fecha o gap mantendo retrocompat com JS legado que não envia
     *   token mas faz request same-origin (Origin/Referer batem).
     *
     * IMPORTANTE: chamar DEPOIS de session_start() pois lê $_SESSION.
     *
     * @param array $methods  Métodos HTTP a proteger. Default: state-changing only.
     */
    public static function requireSameOriginOrCsrf(array $methods = ['POST','PUT','PATCH','DELETE']): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, $methods, true)) return;

        // ── 1) Tenta validar via Origin/Referer same-origin ────────────────
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $selfOrigin = $scheme . '://' . $host;

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && stripos($origin, $selfOrigin) === 0) {
            return; // Origin same-origin → pass
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer !== '' && stripos($referer, $selfOrigin) === 0) {
            return; // Referer same-origin → pass
        }

        // ── 2) Fallback: token CSRF explícito ──────────────────────────────
        // Lê do header X-CSRF-Token ou do corpo (csrf_token em JSON / form)
        $sessToken = $_SESSION['csrf_token'] ?? null;
        if ($sessToken) {
            $headerTok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            $bodyTok   = $_POST['csrf_token'] ?? '';
            if ($bodyTok === '') {
                // Tenta JSON body (Content-Type: application/json)
                $raw = file_get_contents('php://input');
                if ($raw) {
                    $decoded = json_decode($raw, true);
                    $bodyTok = is_array($decoded) ? ($decoded['csrf_token'] ?? '') : '';
                }
            }

            if (($headerTok !== '' && hash_equals($sessToken, $headerTok)) ||
                ($bodyTok   !== '' && hash_equals($sessToken, $bodyTok))) {
                return; // Token bate → pass
            }
        }

        // ── 3) Bloqueio: Origin/Referer cross-site E sem token válido ─────
        self::_forbid('CSRF: request sem Origin/Referer same-origin nem token válido');
    }
}
