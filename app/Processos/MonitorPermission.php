<?php
namespace App\Processos;

use App\Core\Database;

/**
 * MonitorPermission — regras de autorização do add-on Monitoramentos.
 *
 * D3 APROVADO — hardcoded (sem nova tabela de permissões granulares).
 * D7 APROVADO — toggle "advogado pode criar próprio" mora em
 *               accounts.configuracoes (JSON) — zero migration.
 *
 * MATRIZ DE PERMISSÕES (resumo):
 *
 *   AÇÃO                       super_admin  owner  admin  user(adv)  user
 *   ─────────────────────────  ─────────── ────── ────── ────────── ────
 *   ver os próprios monitors        ✓        ✓      ✓        ✓        ✓
 *   criar monitor pra si           ✓*       ✓     ✓**     ✓***     ✗
 *   criar monitor pra outra
 *     pessoa da conta              ✓*       ✓      ✓        ✗        ✗
 *   alocar cota pra filial         ✓*       ✓      ✓        ✗        ✗
 *     (só matriz raiz)
 *   aprovar/recusar solicitação    ✓*       ✓      ✓        ✗        ✗
 *   solicitar monitor              —        —      —        ✓        ✓
 *     (quem não pode criar)
 *   Master gerenciar overrides     ✓        ✗      ✗        ✗        ✗
 *     (set/revoke account quota)
 *
 *   *   super_admin tem todas as permissões em qualquer conta
 *   **  admin = perfil='admin' (legacy) OU role IN ('owner','admin')
 *   *** advogado (is_advogado=1) só se a flag da conta permitir
 *
 * FLAG `advogado_pode_criar_monitor`:
 *   Localização: accounts.configuracoes (JSON existente — D7).
 *   Default: true (advogado pode criar próprio se nada estiver definido).
 *   Matriz decide via UI futura em /configuracoes/monitoramentos.php.
 *
 * USO TÍPICO:
 *   if (!MonitorPermission::canCreate($ctx)) {
 *       ApiResponse::forbidden('Sem permissão pra cadastrar monitor');
 *   }
 *   // ou versão que aborta direto com 403:
 *   MonitorPermission::assertCanCreate($ctx);
 *
 * @since 2026-05-26 (Etapa 3 do add-on Monitoramentos)
 */
final class MonitorPermission
{
    /** Chave da flag no JSON `accounts.configuracoes`. */
    public const FLAG_KEY = 'advogado_pode_criar_monitor';

    /** Default da flag se ausente no JSON. Sintetiza a regra liberal. */
    public const FLAG_DEFAULT = true;

    // ──────────────────────────────────────────────────────────────────
    // VERIFICAÇÕES (retornam bool — não abortam)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Pode criar monitor? Regra:
     *   - super_admin: sempre
     *   - owner / admin role / perfil='admin': sim, na própria conta
     *   - advogado (is_advogado=1): só se a flag da conta permitir
     *   - demais: não
     *
     * @param AccountContext $ctx
     * @param int|null $targetAccountId  Conta onde o monitor será criado.
     *                                   Se NULL, assume a do próprio ctx.
     *                                   Se diferente do ctx, só super_admin
     *                                   pode criar pra outra conta.
     */
    public static function canCreate(AccountContext $ctx, ?int $targetAccountId = null): bool
    {
        // Super admin sempre pode (incluindo cross-tenant)
        if ($ctx->isSuperAdmin()) return true;

        $target = $targetAccountId ?? $ctx->getAccountId();

        // User comum não pode criar fora da própria conta
        if ($target !== $ctx->getAccountId()) return false;

        // Owner ou admin (role/perfil) — pode criar dentro da conta
        if ($ctx->isOwnerOrAdmin()) return true;

        // Advogado pode? Depende da flag
        if (self::isAdvogadoUser($ctx)) {
            return self::isAdvogadoAllowedToCreate($ctx->getAccountId());
        }

        return false;
    }

    /**
     * Pode alocar cota da matriz pra filiais/advogados?
     * Só admin da matriz raiz (account.tipo='matriz'). Filial não aloca.
     */
    public static function canManageQuotaAllocations(AccountContext $ctx): bool
    {
        if ($ctx->isSuperAdmin()) return true;
        if (!$ctx->isMatriz()) return false;
        return $ctx->isOwnerOrAdmin();
    }

    /**
     * Pode aprovar/recusar uma monitor_request?
     * Admin da conta destino (matriz OU filial). Super_admin sempre.
     */
    public static function canApproveRequest(AccountContext $ctx): bool
    {
        if ($ctx->isSuperAdmin()) return true;
        return $ctx->isOwnerOrAdmin();
    }

    /**
     * Pode gerenciar overrides via Painel Master?
     * Apenas super_admin com nível >= 'operator' (vide super_admins.nivel).
     * Aqui aceitamos qualquer super_admin — endpoint Master pode aplicar
     * regra mais granular com `getSuperAdminLevel()`.
     */
    public static function canMasterManage(AccountContext $ctx): bool
    {
        return $ctx->isSuperAdmin();
    }

    /**
     * Pode SOLICITAR monitor (fluxo monitor_requests)?
     * Qualquer usuário logado com acesso ao módulo. A solicitação cai
     * pra aprovação do admin.
     */
    public static function canRequestMonitor(AccountContext $ctx): bool
    {
        // Solicitar é mais permissivo que criar — não exige role
        return $ctx->getUserId() > 0;
    }

    // ──────────────────────────────────────────────────────────────────
    // FLAG DA CONTA (lê/escreve accounts.configuracoes JSON)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Lê a flag `advogado_pode_criar_monitor` do JSON `configuracoes`
     * da conta. Default = true (FLAG_DEFAULT) se ausente OU se JSON inválido.
     *
     * @return bool
     */
    public static function isAdvogadoAllowedToCreate(int $accountId): bool
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare('SELECT configuracoes FROM accounts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $accountId]);
            $raw  = $stmt->fetchColumn();
            if ($raw === false || $raw === null || $raw === '') {
                return self::FLAG_DEFAULT;
            }
            $cfg = json_decode((string) $raw, true);
            if (!is_array($cfg) || !array_key_exists(self::FLAG_KEY, $cfg)) {
                return self::FLAG_DEFAULT;
            }
            // Aceita true, "1", 1, "true" — qualquer outra coisa = false
            $v = $cfg[self::FLAG_KEY];
            return $v === true || $v === 1 || $v === '1' || $v === 'true';
        } catch (\Throwable $e) {
            return self::FLAG_DEFAULT;
        }
    }

    /**
     * Atualiza a flag no JSON `configuracoes`. Preserva os outros campos
     * (carrega → muda só a chave → grava de volta).
     *
     * Idempotente. NÃO loga sozinha — caller deve chamar
     * MonitorAudit::log('permission_changed', ...) depois.
     */
    public static function setAdvogadoAllowedToCreate(int $accountId, bool $allowed): bool
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare('SELECT configuracoes FROM accounts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $accountId]);
            $raw  = $stmt->fetchColumn();
            $cfg  = is_string($raw) && $raw !== ''
                ? (json_decode($raw, true) ?: [])
                : [];
            if (!is_array($cfg)) $cfg = [];

            $cfg[self::FLAG_KEY] = $allowed;

            $upd = $pdo->prepare(
                'UPDATE accounts
                    SET configuracoes = :cfg,
                        updated_at    = NOW()
                  WHERE id = :id'
            );
            $upd->execute([
                'cfg' => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id'  => $accountId,
            ]);
            return $upd->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[MonitorPermission::setAdvogadoAllowedToCreate] ' . $e->getMessage());
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // ASSERT (lançam 403 — usar no topo de endpoints)
    // ──────────────────────────────────────────────────────────────────

    public static function assertCanCreate(AccountContext $ctx, ?int $targetAccountId = null): void
    {
        if (!self::canCreate($ctx, $targetAccountId)) {
            self::forbid('Sem permissão para criar monitoramento nesta conta.');
        }
    }

    public static function assertCanManageQuotaAllocations(AccountContext $ctx): void
    {
        if (!self::canManageQuotaAllocations($ctx)) {
            self::forbid('Apenas a matriz pode alocar cota para filiais/advogados.');
        }
    }

    public static function assertCanApproveRequest(AccountContext $ctx): void
    {
        if (!self::canApproveRequest($ctx)) {
            self::forbid('Sem permissão para aprovar/recusar solicitação.');
        }
    }

    public static function assertCanMasterManage(AccountContext $ctx): void
    {
        if (!self::canMasterManage($ctx)) {
            self::forbid('Apenas super_admin pode gerenciar cotas via Painel Master.');
        }
    }

    public static function assertCanRequestMonitor(AccountContext $ctx): void
    {
        if (!self::canRequestMonitor($ctx)) {
            self::forbid('Sem permissão para solicitar monitoramento.');
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Internos
    // ──────────────────────────────────────────────────────────────────

    /**
     * Detecta se o user da sessão é advogado.
     * Lê de $_SESSION (cache do login) — fallback pra users.is_advogado.
     */
    private static function isAdvogadoUser(AccountContext $ctx): bool
    {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (isset($_SESSION['is_advogado'])) {
            return (bool) $_SESSION['is_advogado'];
        }
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare('SELECT is_advogado FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $ctx->getUserId()]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function forbid(string $msg): never
    {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'error' => $msg,
            'code'  => 'FORBIDDEN_MONITOR_ACTION',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
