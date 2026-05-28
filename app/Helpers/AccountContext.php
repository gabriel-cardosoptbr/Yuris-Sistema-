<?php
namespace App\Helpers;

// Auto-loads das dependências internas — qualquer endpoint que use
// AccountContext NÃO precisa requirir Account/ResourceShare manualmente.
// Era a causa raiz do bug: a matriz não puxava processos/cards da filial
// quando o endpoint esquecia o require, porque listFiliaisVinculadas
// lançava "Class Account not found" e o try/catch silenciava.
require_once __DIR__ . '/../Models/Database.php';
require_once __DIR__ . '/../Models/Account.php';
require_once __DIR__ . '/../Models/ResourceShare.php';

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

    /** Cache do conjunto de account_ids acessíveis (próprio + filiais se matriz), por módulo */
    private array $cachedAccessibleIdsByModule = [];

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

    /**
     * Verifica o status atual da conta no banco (não da sessão).
     * Se a conta estiver suspended/cancelled/inactive, força logout
     * destruindo a sessão e redirecionando para /login.
     *
     * Para super_admin (Painel Master), nunca bloqueia — ele tem que poder
     * acessar mesmo quando há contas em qualquer estado.
     *
     * Uso típico no topo de telas autenticadas:
     *   AccountContext::fromSession()->assertAccountActive();
     *
     * Comportamento:
     *   - active / trial / overdue → libera (overdue ainda vê o sistema; só limita)
     *   - suspended / cancelled / inactive → destrói sessão + redirect/401
     *
     * Cacheia o status na sessão por 60s pra não bater no DB a cada request.
     */
    public function assertAccountActive(): void
    {
        // Super admin nunca é bloqueado por status de tenant
        if ($this->isSuperAdmin()) return;

        // Cache leve (60s) — evita N queries por request
        $cacheKey = '_acc_status_cache_ts';
        $cacheVal = '_acc_status_cache_val';
        $now      = time();
        if (!empty($_SESSION[$cacheKey])
            && ($now - (int)$_SESSION[$cacheKey] < 60)
            && !empty($_SESSION[$cacheVal])) {
            $status = $_SESSION[$cacheVal];
        } else {
            try {
                $pdo  = Database::getConnection();
                $stmt = $pdo->prepare("SELECT status FROM accounts WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $this->accountId]);
                $status = (string) $stmt->fetchColumn();
            } catch (\Throwable $_e) {
                return; // DB indisponível — não derruba sessão
            }
            $_SESSION[$cacheKey] = $now;
            $_SESSION[$cacheVal] = $status;
        }

        // Estados que NÃO bloqueiam acesso
        if (in_array($status, ['active','trial','overdue',''], true)) return;

        // Estados bloqueantes: suspended / cancelled / inactive
        $msg = match ($status) {
            'suspended' => 'Conta suspensa. Entre em contato com o suporte.',
            'cancelled' => 'Conta cancelada. Acesso negado.',
            'inactive'  => 'Conta inativa.',
            default     => 'Acesso bloqueado.',
        };

        // Destrói a sessão (igual logout)
        foreach (array_keys($_SESSION) as $k) unset($_SESSION[$k]);
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();

        // Resposta: JSON pra APIs, redirect pra telas
        $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')
              || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg, 'code' => 'ACCOUNT_BLOCKED']);
            exit;
        }
        header('Location: /login.php?msg=' . urlencode($msg));
        exit;
    }

    public function isOwnerOrAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin']);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Retorna o account_id "dono" do pipeline de prospecção para esta sessão.
     *
     * Regra de negócio (Opção 2 — pipeline único da matriz):
     *  - Matriz                      → próprio account_id (define o pipeline)
     *  - Filial vinculada (active)   → matriz_account_id  (herda)
     *  - Filial isolada              → próprio account_id (vira sua própria matriz)
     *
     * IMPORTANTE: a herança só acontece quando o vínculo está active.
     * Se o vínculo for suspended/pending/rejected, filial volta a usar o próprio.
     * O flag sync_cards NÃO afeta o pipeline (a herança das colunas é estrutural;
     * sync_cards controla a VISIBILIDADE dos leads, não o esquema).
     */
    public function getPipelineAccountId(): int
    {
        if ($this->isMatriz()) return $this->accountId;

        // Cache simples (consulta DB só uma vez por request)
        if (isset($this->_cachedPipelineAccountId)) return $this->_cachedPipelineAccountId;

        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                "SELECT matriz_account_id FROM account_vinculos
                 WHERE filial_account_id = :acc AND status = 'active'
                 LIMIT 1"
            );
            $stmt->execute(['acc' => $this->accountId]);
            $matrizId = (int) $stmt->fetchColumn();
            return $this->_cachedPipelineAccountId = ($matrizId > 0 ? $matrizId : $this->accountId);
        } catch (\Throwable $e) {
            return $this->_cachedPipelineAccountId = $this->accountId;
        }
    }

    /**
     * Indica se esta sessão herda o pipeline de outra conta (= filial vinculada).
     * Usado pelo frontend pra desabilitar o botão "Alterar Colunas".
     */
    public function isPipelineInherited(): bool
    {
        return $this->getPipelineAccountId() !== $this->accountId;
    }

    /** @var int|null */
    private ?int $_cachedPipelineAccountId = null;

    /**
     * Verifica se o usuário logado é super_admin (Painel Master).
     * Lê de $_SESSION['is_super_admin'] (populado no AuthController),
     * com fallback consultando a tabela super_admins.
     *
     * NÃO altera o comportamento dos métodos existentes — isto é
     * checagem ADICIONAL pra rotas do Painel Master.
     */
    public function isSuperAdmin(): bool
    {
        // Sessão já marcou? (atalho rápido)
        if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin']) {
            return true;
        }
        // Fallback DB (caso a sessão seja antiga, pré-fase3)
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                "SELECT 1 FROM super_admins WHERE user_id = :uid AND ativo = 1 LIMIT 1"
            );
            $stmt->execute(['uid' => $this->userId]);
            $is = (bool) $stmt->fetchColumn();
            if ($is) $_SESSION['is_super_admin'] = true;
            return $is;
        } catch (\Throwable $_e) {
            return false;
        }
    }

    /**
     * Nível do super admin: 'viewer' | 'operator' | 'super' | null
     */
    public function getSuperAdminLevel(): ?string
    {
        if (!$this->isSuperAdmin()) return null;
        if (!empty($_SESSION['super_admin_level'])) {
            return (string) $_SESSION['super_admin_level'];
        }
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT nivel FROM super_admins WHERE user_id = :uid AND ativo = 1 LIMIT 1");
            $stmt->execute(['uid' => $this->userId]);
            $lvl = $stmt->fetchColumn();
            if ($lvl) $_SESSION['super_admin_level'] = $lvl;
            return $lvl ?: null;
        } catch (\Throwable $_e) {
            return null;
        }
    }

    /**
     * Aborta com 403 se a sessão atual não for super_admin.
     * Use no início de qualquer endpoint /api/master/*.
     */
    public function assertSuperAdmin(): void
    {
        if (!$this->isSuperAdmin()) {
            $this->_forbidden('Apenas super administradores podem acessar este recurso.');
        }
    }

    /**
     * Retorna os account_ids que esta sessão tem acesso AUTOMÁTICO.
     *
     * Regra de negócio (matriz herda filiais):
     *   - Matriz → [matriz_id, ...filiais_ativas_ids]   (vê tudo das filiais vinculadas)
     *   - Filial → [filial_id]                          (NÃO vê a matriz)
     *
     * Quando $module é informado, INCLUI também as contas que liberaram esse
     * módulo via resource_shares (resource_type='module', module_key=$module).
     *
     * Sincronização granular (account_vinculos.sync_*):
     *   - sync_enabled    — toggle mestre da filial (se 0, a filial é invisível)
     *   - sync_cards      — visível em /api/cards.php e tela /prospeccao.php
     *   - sync_processos  — visível em /api/processes.php e telas afins
     *   - sync_tarefas    — visível em /api/tasks.php, /api/task_boards.php
     *
     * Mapeamento $module → flag:
     *   'prospeccao'    → sync_cards
     *   'processos'     → sync_processos
     *   'juridico'      → sync_processos (jurídico = processos)
     *   'tarefas'       → sync_tarefas
     *   'dashboard'     → só sync_enabled (KPIs agregam tudo)
     *   null / outros   → só sync_enabled
     *
     * Compartilhamentos pontuais de RECURSO (resource_shares com resource_id)
     * continuam sendo checados separadamente nos filtros das listagens.
     */
    public function getAccessibleAccountIds(?string $module = null): array
    {
        $cacheKey = $module ?? '__base__';
        if (isset($this->cachedAccessibleIdsByModule[$cacheKey])) {
            return $this->cachedAccessibleIdsByModule[$cacheKey];
        }

        $ids = [$this->accountId];
        if ($this->isMatriz()) {
            try {
                $filiais = Account::listFiliaisVinculadas($this->accountId);
                // Determina qual flag por-módulo deve ser checada além do toggle mestre
                $moduleFlag = self::_syncFlagForModule($module);
                foreach ($filiais as $f) {
                    $fid = (int) $f['id'];
                    if ($fid <= 0 || in_array($fid, $ids, true)) continue;

                    // Toggle mestre desligado → filial 100% invisível
                    if (array_key_exists('sync_enabled', $f) && (int)$f['sync_enabled'] === 0) continue;

                    // Flag específica do módulo (se aplicável) também desligada → pula esta filial neste módulo
                    if ($moduleFlag !== null
                        && array_key_exists($moduleFlag, $f)
                        && (int)$f[$moduleFlag] === 0) {
                        continue;
                    }

                    $ids[] = $fid;
                }
            } catch (\Throwable $e) {
                error_log('[AccountContext::getAccessibleAccountIds] ' . $e->getMessage());
            }
        }

        // ── Advogados associados vinculados a esta conta ─────────────────────
        // Tanto matriz QUANTO filial podem vincular advogados (advogado_vinculos).
        // Quando vínculo está active + sync_enabled=1 + sync_<module>=1, a host
        // vê tudo do advogado naquele módulo (Cards/Processos/Tarefas).
        // Espelha exatamente a lógica de filial.
        if ($this->accountTipo !== 'advogado') {
            try {
                if (!class_exists('App\\Models\\AdvogadoVinculo')) {
                    require_once __DIR__ . '/../Models/AdvogadoVinculo.php';
                }
                // Mapeia $module pra string usada no método (cards/processos/tarefas/'')
                $modKey = match (self::_syncFlagForModule($module)) {
                    'sync_cards'     => 'cards',
                    'sync_processos' => 'processos',
                    'sync_tarefas'   => 'tarefas',
                    default          => '',  // só checa sync_enabled
                };
                $advogadoIds = \App\Models\AdvogadoVinculo::advogadosAtivosDeHost($this->accountId, $modKey);
                foreach ($advogadoIds as $aid) {
                    if ($aid > 0 && !in_array($aid, $ids, true)) $ids[] = $aid;
                }
            } catch (\Throwable $e) {
                error_log('[AccountContext::getAccessibleAccountIds:advogado] ' . $e->getMessage());
            }
        }
        // Observação: quando ESTA conta é o advogado, NÃO inclui automaticamente
        // a host. Advogado solo continua vendo só dados próprios — a colaboração
        // acontece via processos compartilhados (resource_shares) ou quando a host
        // puxa dados pra DENTRO dela. O painel "Quem me vinculou" do advogado é
        // só leitura informacional.

        // Inclui contas que liberaram este módulo para mim (account ou user)
        if ($module !== null && $module !== '') {
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare(
                    "SELECT DISTINCT from_account_id
                     FROM resource_shares
                     WHERE resource_type = 'module'
                       AND module_key    = :mk
                       AND status        = 'active'
                       AND (to_account_id = :acc OR to_user_id = :uid)"
                );
                $stmt->execute(['mk' => $module, 'acc' => $this->accountId, 'uid' => $this->userId]);
                foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $aid) {
                    $aid = (int) $aid;
                    if ($aid > 0 && !in_array($aid, $ids, true)) $ids[] = $aid;
                }
            } catch (\Throwable $e) {
                error_log('[AccountContext::getAccessibleAccountIds:module] ' . $e->getMessage());
            }
        }

        return $this->cachedAccessibleIdsByModule[$cacheKey] = $ids;
    }

    /**
     * Helper para SQL: gera placeholders + params para a cláusula
     *   {alias}.account_id IN (:k0, :k1, ...)
     *
     * Uso típico em endpoints/models:
     *   $f = $ctx->buildAccountInClause('p', 'pacc');
     *   $sql = "SELECT * FROM processos p WHERE {$f['sql']} AND p.deleted_at IS NULL";
     *   $stmt->execute($f['params'] + [...]);
     */
    public function buildAccountInClause(string $tableAlias, string $paramPrefix = 'ctx_acc'): array
    {
        $ids = $this->getAccessibleAccountIds();
        $placeholders = [];
        $params       = [];
        foreach ($ids as $i => $aid) {
            $key            = "{$paramPrefix}_{$i}";
            $placeholders[] = ":{$key}";
            $params[$key]   = (int) $aid;
        }
        return [
            'sql'    => "{$tableAlias}.account_id IN (" . implode(',', $placeholders) . ")",
            'params' => $params,
        ];
    }

    /**
     * Mapeia um módulo para o flag de sincronização granular correspondente
     * na tabela account_vinculos. Retorna null quando o módulo não tem flag
     * específico (cai apenas no sync_enabled mestre).
     */
    private static function _syncFlagForModule(?string $module): ?string
    {
        switch ($module) {
            case 'prospeccao':         return 'sync_cards';
            case 'cards':              return 'sync_cards';
            case 'processos':          return 'sync_processos';
            case 'juridico':           return 'sync_processos';
            case 'tarefas':            return 'sync_tarefas';
            // dashboard / financas / chat / null → só sync_enabled
            default:                   return null;
        }
    }

    /**
     * Retorna os usuários acessíveis à sessão atual, com info de tenant
     * pronta para renderizar selects agrupados.
     *
     * Filtra por todos os account_ids acessíveis (matriz + filiais vinculadas).
     * Cada linha inclui: id, nome, account_id, account_nome, account_tipo.
     *
     * Ordenação: matriz primeiro, depois filiais (alfabético), e dentro de cada
     * conta os usuários em ordem alfabética. Usuários soft-deletados/inativos
     * são omitidos.
     *
     * @param bool        $onlyActive  Se true (default), exclui usuários com status != 'active'
     * @param string|null $module      Se informado (ex: 'tarefas', 'cards', 'processos'),
     *                                  respeita a flag de sync por-módulo das filiais —
     *                                  filiais com aquele módulo desligado são omitidas.
     *                                  Default null = só o toggle mestre de sync.
     * @return array<int, array{id:int,nome:string,account_id:int,account_nome:string,account_tipo:string}>
     */
    public function getAccessibleUsers(bool $onlyActive = true, ?string $module = null): array
    {
        $accountIds = $this->getAccessibleAccountIds($module);
        if (empty($accountIds)) return [];

        try {
            $pdo = Database::getConnection();
            $ph  = [];
            $params = [];
            foreach ($accountIds as $i => $aid) {
                $key            = "uacc_{$i}";
                $ph[]           = ":{$key}";
                $params[$key]   = (int) $aid;
            }
            $statusFilter = $onlyActive ? "AND u.status = 'active'" : '';
            $sql =
                "SELECT u.id, u.nome,
                        u.account_id,
                        a.nome AS account_nome,
                        a.tipo AS account_tipo
                 FROM users u
                 INNER JOIN accounts a ON a.id = u.account_id
                 WHERE u.deleted_at IS NULL
                   {$statusFilter}
                   AND u.account_id IN (" . implode(',', $ph) . ")
                 ORDER BY
                   CASE WHEN a.tipo = 'matriz' THEN 0 ELSE 1 END,
                   a.nome ASC,
                   u.nome ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[AccountContext::getAccessibleUsers] ' . $e->getMessage());
            return [];
        }
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
        // Acesso por dono — inclui filiais quando a sessão é matriz
        $inOwn   = $this->buildAccountInClause($tableAlias, 'ctx_own');
        // Acesso via share — também aceita compartilhamento direcionado a qualquer
        // account_id acessível (ex: share para a filial é visível pela matriz)
        $inShare = $this->buildAccountInClause('rs_to_alias', 'ctx_shr');

        // Reescreve cláusula do share substituindo o alias temporário pelo campo real
        $shareInSql = str_replace('rs_to_alias.account_id', 'rs.to_account_id', $inShare['sql']);

        $sql = "({$inOwn['sql']}
                 OR EXISTS (
                   SELECT 1 FROM resource_shares rs
                   WHERE rs.resource_type = :ctx_rtype
                     AND rs.resource_id   = {$tableAlias}.id
                     AND rs.status        = 'active'
                     AND ({$shareInSql} OR rs.to_account_id IS NULL)
                 ))";

        return [
            'sql'    => $sql,
            'params' => $inOwn['params'] + $inShare['params'] + ['ctx_rtype' => $resourceType],
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
            // Inclui as filiais quando a sessão é matriz (acesso herdado)
            $inClause = $this->buildAccountInClause('t', 'rba');
            $stmt = $pdo->prepare(
                "SELECT 1 FROM {$table} t WHERE t.id = :id AND {$inClause['sql']} LIMIT 1"
            );
            $stmt->execute(['id' => $resourceId] + $inClause['params']);
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
