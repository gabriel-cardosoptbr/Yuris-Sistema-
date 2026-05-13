<?php
namespace App\Helpers;

use App\Models\Database;
use App\Models\ResourceShare;
use App\Models\Account;

/**
 * AccountContext — Core do Multi-Tenancy
 *
 * REGRA CRÍTICA DE SEGURANÇA:
 *   NUNCA aceitar account_id vindo do frontend (body, query string, headers).
 *   O account_id é SEMPRE lido da sessão server-side (carregado no AuthController).
 *   Qualquer endpoint que aceitar account_id como parâmetro externo é uma falha de segurança direta.
 *
 * PADRÃO DE MERCADO:
 *   Shared-database com tenant_id por coluna + validação em todas as queries.
 *   Referência: Stripe (account_id em toda API), HubSpot portal_id, Salesforce org context.
 *
 * USO NOS ENDPOINTS:
 *   $ctx = AccountContext::fromSession();   // obtém contexto ou aborta com 401
 *   $ctx->getAccountId()                    // INT — tenant atual
 *   $ctx->getRole()                         // 'owner'|'admin'|'manager'|'user'|'viewer'
 *   $ctx->isOwnerOrAdmin()                  // bool
 *   $ctx->assertCanRead('processo', 42)     // void — aborta com 403 se não tiver acesso
 *   $ctx->assertCanWrite('processo', 42)    // void — aborta com 403 se não tiver acesso
 */
class AccountContext
{
    private int    $accountId;
    private string $accountTipo;   // 'matriz' | 'filial'
    private string $role;          // 'owner' | 'admin' | 'manager' | 'user' | 'viewer'
    private int    $userId;

    private function __construct(int $accountId, string $accountTipo, string $role, int $userId)
    {
        $this->accountId   = $accountId;
        $this->accountTipo = $accountTipo;
        $this->role        = $role;
        $this->userId      = $userId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FACTORY
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Instancia o contexto a partir da sessão ativa.
     * Aborta com HTTP 401 se a sessão não tiver account_id.
     */
    public static function fromSession(): self
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (empty($_SESSION['user_id']) || empty($_SESSION['account_id'])) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Sessão inválida ou expirada. Faça login novamente.']);
            exit;
        }

        return new self(
            (int)    $_SESSION['account_id'],
            (string) ($_SESSION['account_tipo'] ?? 'matriz'),
            (string) ($_SESSION['user_role']    ?? 'user'),
            (int)    $_SESSION['user_id']
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GETTERS
    // ─────────────────────────────────────────────────────────────────────────

    public function getAccountId(): int    { return $this->accountId; }
    public function getAccountTipo(): string { return $this->accountTipo; }
    public function getRole(): string      { return $this->role; }
    public function getUserId(): int       { return $this->userId; }
    public function isMatriz(): bool       { return $this->accountTipo === 'matriz'; }
    public function isFilial(): bool       { return $this->accountTipo === 'filial'; }

    public function isOwnerOrAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin']);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Verifica se o usuário tem permissão mínima.
     * Hierarquia: owner > admin > manager > user > viewer
     */
    public function hasMinRole(string $minRole): bool
    {
        $hierarchy = ['viewer' => 1, 'user' => 2, 'manager' => 3, 'admin' => 4, 'owner' => 5];
        $userLevel = $hierarchy[$this->role]    ?? 0;
        $minLevel  = $hierarchy[$minRole]       ?? 0;
        return $userLevel >= $minLevel;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VALIDAÇÃO DE ACESSO A RECURSOS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verifica se a conta do usuário é dona do recurso ou tem share ativo.
     * Retorna: 'owner' | 'view' | 'edit' | 'full' | null (sem acesso)
     */
    public function getResourcePermission(string $resourceType, int $resourceId): ?string
    {
        // 1. Recurso pertence à própria conta → acesso total
        if ($this->_resourceBelongsToAccount($resourceType, $resourceId)) {
            return 'owner';
        }

        // 2. Verifica resource_share
        return ResourceShare::getPermission($resourceType, $resourceId, $this->accountId);
    }

    /**
     * Aborta com 403 se a conta não tiver acesso de leitura ao recurso.
     */
    public function assertCanRead(string $resourceType, int $resourceId): void
    {
        $perm = $this->getResourcePermission($resourceType, $resourceId);
        if ($perm === null) {
            $this->_forbidden("Sem permissão de leitura para {$resourceType} #{$resourceId}");
        }
    }

    /**
     * Aborta com 403 se a conta não tiver acesso de escrita ao recurso.
     */
    public function assertCanWrite(string $resourceType, int $resourceId): void
    {
        $perm = $this->getResourcePermission($resourceType, $resourceId);
        if ($perm === null || $perm === 'view') {
            $this->_forbidden("Sem permissão de edição para {$resourceType} #{$resourceId}");
        }
    }

    /**
     * Aborta com 403 se não for owner do recurso (para delete/revogar share).
     */
    public function assertIsOwnerOfResource(string $resourceType, int $resourceId): void
    {
        if (!$this->_resourceBelongsToAccount($resourceType, $resourceId)) {
            $this->_forbidden("Apenas a conta dona pode realizar esta ação em {$resourceType} #{$resourceId}");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // QUERY HELPER: adiciona filtro de tenant em SQL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retorna array ['sql' => '...', 'params' => [...]] com cláusula WHERE para
     * filtrar recursos da conta + recursos compartilhados com a conta.
     *
     * USO:
     *   $filter = $ctx->buildResourceFilter('c', 'card');
     *   $sql = "SELECT * FROM cards c WHERE {$filter['sql']} AND c.deleted_at IS NULL";
     *   $stmt->execute($filter['params']);
     */
    public function buildResourceFilter(string $tableAlias, string $resourceType): array
    {
        $accountId = $this->accountId;
        $sql = "({$tableAlias}.account_id = :ctx_account_id
                 OR EXISTS (
                   SELECT 1 FROM resource_shares rs
                   WHERE rs.resource_type = :ctx_rtype
                     AND rs.resource_id   = {$tableAlias}.id
                     AND rs.status        = 'active'
                     AND (rs.to_account_id = :ctx_account_id2 OR rs.to_account_id IS NULL)
                 ))";
        return [
            'sql'    => $sql,
            'params' => [
                'ctx_account_id'  => $accountId,
                'ctx_rtype'       => $resourceType,
                'ctx_account_id2' => $accountId,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private static array $_tableMap = [
        'card'       => 'cards',
        'processo'   => 'processos',
        'contato'    => 'contatos',
        'task_board' => 'task_boards',
    ];

    private function _resourceBelongsToAccount(string $resourceType, int $resourceId): bool
    {
        $table = self::$_tableMap[$resourceType] ?? null;
        if (!$table) return false;

        $pdo = Database::getConnection();
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM {$table} WHERE id = :id AND account_id = :acc LIMIT 1"
            );
            $stmt->execute(['id' => $resourceId, 'acc' => $this->accountId]);
            return (bool) $stmt->fetch();
        } catch (\Throwable $e) {
            // coluna account_id não existe — single-tenant, acesso liberado
            $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $resourceId]);
            return (bool) $stmt->fetch();
        }
    }

    private function _forbidden(string $message): never
    {
        // Registra tentativa de acesso indevido
        error_log(sprintf(
            '[AccountContext:FORBIDDEN] user_id=%d account_id=%d | %s | URI=%s',
            $this->userId,
            $this->accountId,
            $message,
            $_SERVER['REQUEST_URI'] ?? '?'
        ));

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Acesso negado: ' . $message]);
        exit;
    }
}
