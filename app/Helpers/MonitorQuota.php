<?php
namespace App\Helpers;

use App\Models\Database;

/**
 * MonitorQuota — cálculo de cota de monitoramentos (add-on do plano).
 *
 * MODELO COMERCIAL APROVADO (2026-05-26 — D1 a D10):
 *   Plano do sistema NÃO inclui monitoramento.
 *   Cota efetiva só via account_quota_overrides (source=master_grant OU
 *   purchase), com regra de SOMA (cliente pode empilhar grant promo +
 *   purchase ativos).
 *   Distribuição matriz↔filial = HÍBRIDA: pool aberto por default;
 *   alocação fixa opcional via monitor_quota_allocations.
 *
 * SEMÂNTICA DE COTA:
 *   - getOwnLimit($acc)         → soma overrides ATIVOS da própria conta
 *   - getAllocationsReceived($acc) → soma allocations recebidas como
 *                                    target_account_id (filial reservou)
 *   - getEffectiveLimit($acc)   → quanto a conta efetivamente pode usar:
 *                                   matriz raiz: own_limit
 *                                   filial: se has_allocation → soma
 *                                   das allocations; senão → fallback
 *                                   ao own_limit do pai (pool aberto)
 *                                   advogado: own_limit + allocations
 *                                   recebidas
 *   - getCurrentUsage($acc)     → COUNT(push_monitors) consumindo cota
 *                                   (status ativo/pausado/erro AND
 *                                   consome_cota=1) + COUNT(pending
 *                                   requests reservadas — D4)
 *
 * FAIL-SOFT (mesmo padrão BillingGuard):
 *   Se as tabelas novas (account_quota_overrides etc.) ainda não existirem
 *   — ex.: produção antes da migração 072 — retorna 0 ou silenciosamente
 *   libera, sem derrubar o fluxo principal.
 *
 * @since 2026-05-26 (Etapa 2 do add-on Monitoramentos)
 */
final class MonitorQuota
{
    /** Feature key padronizada pra todas as queries de override. */
    public const FEATURE_KEY = 'monitors.limit';

    /** Status de push_monitors que consomem cota (D4 + briefing). */
    public const STATUS_CONSUMINDO = ['ativo', 'pausado', 'erro'];

    /**
     * Limite efetivo da própria conta (plano + overrides somados).
     *
     * EVOLUÇÃO 2026-05-26 (Etapa 4):
     *   Delega pra BillingGuard::getLimit() — agora é a única fonte de
     *   verdade pra "quanto a conta tem direito". BillingGuard soma
     *   plan_features.limit_value + account_quota_overrides ativos.
     *
     *   No modelo comercial aprovado:
     *     plan_features.monitors.limit = 0 (mig 077 seedou)
     *     SUM(overrides) = N (Master configurou via Painel Master)
     *     getOwnLimit = 0 + N = N
     *
     * @return int  Limite efetivo. 0 se sem cota.
     */
    public static function getOwnLimit(int $accountId): int
    {
        // Carrega BillingGuard se ainda não carregado (loader manual sem composer)
        if (!class_exists('App\\Helpers\\BillingGuard')) {
            require_once __DIR__ . '/BillingGuard.php';
        }
        $limit = BillingGuard::getLimit($accountId, self::FEATURE_KEY);
        // null (ilimitado) → trata como muito grande mas finito pra evitar overflow.
        //   Em monitors.limit ilimitado seria caso extremo (master config absurda).
        if ($limit === null)  return PHP_INT_MAX;
        // false (infra ausente / sem subscription) → 0 (fail-soft).
        if ($limit === false) return 0;
        return (int) $limit;
    }

    /**
     * Soma das allocations recebidas como destino (filial/advogado).
     *
     * Se vazia: filial usa do pool do tenant pai (modelo híbrido).
     * Se preenchida: filial está limitada à soma alocada (mesmo se pool
     * sobrar).
     *
     * @return int Quantidade reservada pra esse account como destino.
     */
    public static function getAllocationsReceived(int $accountId): int
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(allocated), 0) AS total
                   FROM monitor_quota_allocations
                   WHERE target_account_id = :aid
                     AND status            = 'active'"
            );
            $stmt->execute(['aid' => $accountId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Verifica se a conta tem allocations recebidas (decide pool aberto
     * vs alocação fixa).
     */
    public static function hasAllocations(int $accountId): bool
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                "SELECT 1 FROM monitor_quota_allocations
                   WHERE target_account_id = :aid
                     AND status            = 'active'
                   LIMIT 1"
            );
            $stmt->execute(['aid' => $accountId]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Escopo de cota — quais accounts compartilham o MESMO pool com $accountId.
     *
     * Pra qualquer conta no scope, o limite efetivo é o mesmo e o uso atual
     * é SOMADO entre todas (D4 reserva inter-tenant, fix cross-tenant 2026-05-26).
     *
     * Retorna ['scope' => int[], 'mode' => string, 'matriz_id' => int|null]
     *   - mode='shared_pool'        → matriz + filiais sem allocation fixa
     *   - mode='fixed_allocation'   → filial isolada com reserva fixa
     *   - mode='standalone'         → advogado solo, matriz sem filiais, etc.
     */
    public static function getQuotaScope(int $accountId): array
    {
        $acc  = self::loadAccountMeta($accountId);
        $tipo = $acc['tipo'] ?? null;

        // Filial com allocation recebida → escopo isolado, usa só sua reserva
        if ($tipo === 'filial' && self::hasAllocations($accountId)) {
            return ['scope' => [$accountId], 'mode' => 'fixed_allocation', 'matriz_id' => null];
        }

        // Advogado: scope é a própria conta (modelo 1 conta = 1 advogado)
        // Allocations recebidas por advogado SOMAM ao próprio limit mas não criam pool compartilhado.
        if ($tipo === 'advogado') {
            return ['scope' => [$accountId], 'mode' => 'standalone', 'matriz_id' => null];
        }

        // Matriz: pool = matriz + filiais sem allocation fixa
        if ($tipo === 'matriz') {
            return [
                'scope'     => self::buildSharedPoolScope($accountId),
                'mode'      => 'shared_pool',
                'matriz_id' => $accountId,
            ];
        }

        // Filial em pool aberto (sem allocation): resolve matriz e usa scope dela
        if ($tipo === 'filial') {
            $matrizId = self::resolveMatrizId($accountId);
            if ($matrizId) {
                return [
                    'scope'     => self::buildSharedPoolScope($matrizId),
                    'mode'      => 'shared_pool',
                    'matriz_id' => $matrizId,
                ];
            }
        }

        return ['scope' => [$accountId], 'mode' => 'standalone', 'matriz_id' => null];
    }

    /**
     * Constrói lista de account_ids no pool aberto da matriz: matriz +
     * filiais vinculadas com sync_monitoramentos=1 E sem allocation fixa.
     */
    private static function buildSharedPoolScope(int $matrizId): array
    {
        $scope = [$matrizId];
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                "SELECT av.filial_account_id
                   FROM account_vinculos av
                  WHERE av.matriz_account_id   = :mid
                    AND av.status              = 'active'
                    AND av.sync_enabled        = 1
                    AND av.sync_monitoramentos = 1"
            );
            $stmt->execute(['mid' => $matrizId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $fid) {
                $fid = (int) $fid;
                // Filial com allocation fixa SAI do pool aberto
                if (self::hasAllocations($fid)) continue;
                $scope[] = $fid;
            }
        } catch (\Throwable $e) { /* fail-soft */ }
        return array_values(array_unique($scope));
    }

    /**
     * Soma allocations ATIVAS dadas POR uma matriz (parent_account_id).
     * Diferente de getAllocationsReceived (target_account_id).
     */
    private static function sumActiveAllocationsFrom(int $parentAccountId): int
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(allocated), 0)
                   FROM monitor_quota_allocations
                  WHERE parent_account_id = :pid
                    AND status            = 'active'"
            );
            $stmt->execute(['pid' => $parentAccountId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Limite efetivo da conta — resolve scope (matriz↔filial híbrido).
     *
     * REGRAS:
     *   • fixed_allocation (filial reservada) → SOMA allocations recebidas
     *   • shared_pool (matriz + filiais) → own_limit(matriz) - allocations_dadas
     *   • standalone (advogado, matriz isolada) → own_limit + allocations recebidas
     */
    public static function getEffectiveLimit(int $accountId): int
    {
        $sc = self::getQuotaScope($accountId);

        if ($sc['mode'] === 'fixed_allocation') {
            return self::getAllocationsReceived($accountId);
        }

        if ($sc['mode'] === 'shared_pool') {
            $matrizId = (int) $sc['matriz_id'];
            $own      = self::getOwnLimit($matrizId);
            $alloc    = self::sumActiveAllocationsFrom($matrizId);
            return max(0, $own - $alloc);
        }

        // standalone
        return self::getOwnLimit($accountId)
             + self::getAllocationsReceived($accountId);
    }

    /**
     * Resolve a matriz pai de uma filial via account_vinculos.
     * Respeita sync_enabled=1 AND sync_monitoramentos=1 — se a matriz
     * desligou o sync de monitoramentos, a filial fica isolada (0).
     */
    private static function resolveMatrizId(int $filialAccountId): ?int
    {
        static $cache = [];
        if (array_key_exists($filialAccountId, $cache)) return $cache[$filialAccountId];
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                "SELECT matriz_account_id FROM account_vinculos
                   WHERE filial_account_id = :fid
                     AND status            = 'active'
                     AND sync_enabled      = 1
                     AND sync_monitoramentos = 1
                   LIMIT 1"
            );
            $stmt->execute(['fid' => $filialAccountId]);
            $v = $stmt->fetchColumn();
            return $cache[$filialAccountId] = $v ? (int) $v : null;
        } catch (\Throwable $e) {
            return $cache[$filialAccountId] = null;
        }
    }

    /**
     * Conta SÓ os monitors da própria conta (sem agregar scope).
     * Use quando precisar reportar "quanto ESSA conta está usando" (ex.: linha
     * da matriz na tabela de divisão, mostrando o uso só da matriz vs filiais).
     */
    public static function getOwnUsage(int $accountId): int
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM push_monitors
                  WHERE account_id   = :aid
                    AND consome_cota = 1
                    AND status IN ('ativo','pausado','erro')"
            );
            $stmt->execute(['aid' => $accountId]);
            $usados = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM monitor_requests
                  WHERE account_id = :aid AND status = 'pending'"
            );
            $stmt->execute(['aid' => $accountId]);
            return $usados + (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Uso EFETIVO da cota — agrega scope (pool aberto = matriz + filiais).
     *
     * FIX 2026-05-26 (bloqueador #3 + #5 da auditoria E2E): D4 cross-tenant.
     *   Antes: cada filial contava só o próprio uso. Pool aberto não somava
     *   entre filiais nem com a matriz, então 3 filiais podiam estourar a
     *   cota sem ninguém perceber. Agora qualquer conta do scope vê o uso
     *   AGREGADO de todas as contas que compartilham o pool.
     *
     * Inclui monitor_requests com status='pending' (D4 — pending reserva
     * vaga pra evitar race condition mesmo cross-tenant).
     */
    public static function getCurrentUsage(int $accountId): int
    {
        $sc = self::getQuotaScope($accountId);
        return self::getAggregatedUsage($sc['scope']);
    }

    /**
     * Soma usage de várias contas (matriz + filiais agregadas).
     * Útil pro Painel Master e pro KPI "uso por tenant" na matriz.
     */
    public static function getAggregatedUsage(array $accountIds): int
    {
        if (empty($accountIds)) return 0;
        try {
            $pdo = Database::getConnection();
            $placeholders = [];
            $params       = [];
            foreach ($accountIds as $i => $aid) {
                $key = "a{$i}";
                $placeholders[] = ":{$key}";
                $params[$key]   = (int) $aid;
            }
            $in = '(' . implode(',', $placeholders) . ')';

            // Monitors
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                   FROM push_monitors
                   WHERE account_id IN {$in}
                     AND consome_cota = 1
                     AND status IN ('ativo','pausado','erro')"
            );
            $stmt->execute($params);
            $u1 = (int) $stmt->fetchColumn();

            // Requests pending
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                   FROM monitor_requests
                   WHERE account_id IN {$in}
                     AND status     = 'pending'"
            );
            $stmt->execute($params);
            $u2 = (int) $stmt->fetchColumn();

            return $u1 + $u2;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Disponível = limite efetivo - usado. Nunca negativo (clamp em 0).
     */
    public static function getAvailable(int $accountId): int
    {
        return max(0, self::getEffectiveLimit($accountId) - self::getCurrentUsage($accountId));
    }

    /**
     * Versão batch do getQuotaStatus — pra listas que precisam de N contas
     * sem N+1 chamadas externas. Internamente ainda chama getQuotaStatus
     * por conta (5 queries cada), mas reusa caches static de loadAccountMeta
     * e resolveMatrizId, então fica O(N * 5) sem N² join.
     *
     * Retorna mapa: [accountId => quota_status_array]
     *
     * IMPORTANTE: esta é a fonte de verdade pra listagens (Master "Todas
     * as contas", relatórios, etc.). NUNCA reimplementar a lógica de cota
     * em SQL inline em outros endpoints — sempre passe por aqui.
     */
    public static function getQuotaStatusBatch(array $accountIds): array
    {
        $out = [];
        foreach ($accountIds as $id) {
            $id = (int) $id;
            if ($id <= 0) continue;
            $out[$id] = self::getQuotaStatus($id);
        }
        return $out;
    }

    /**
     * Status completo pra exibição no UI (modal de monitor, dashboard
     * Master, badge "X de Y", etc.).
     *
     * @return array{
     *   account_id:int,
     *   own_limit:int,
     *   allocations_received:int,
     *   has_allocations:bool,
     *   effective_limit:int,
     *   current_usage:int,
     *   available:int,
     *   percent_used:int,
     *   has_quota:bool
     * }
     */
    public static function getQuotaStatus(int $accountId): array
    {
        $own    = self::getOwnLimit($accountId);
        $alloc  = self::getAllocationsReceived($accountId);
        $hasAlloc = self::hasAllocations($accountId);
        $limit  = self::getEffectiveLimit($accountId);
        $used   = self::getCurrentUsage($accountId);
        $avail  = max(0, $limit - $used);
        $pct    = $limit > 0 ? (int) round(($used / $limit) * 100) : 0;

        return [
            'account_id'           => $accountId,
            'own_limit'            => $own,
            'allocations_received' => $alloc,
            'has_allocations'      => $hasAlloc,
            'effective_limit'      => $limit,
            'current_usage'        => $used,
            'available'            => $avail,
            'percent_used'         => $pct,
            'has_quota'            => $limit > 0,
        ];
    }

    /**
     * Lança 402 (Payment Required) com mensagem amigável se a conta não
     * tem cota disponível. Usar no topo de endpoints de criação.
     *
     * Mensagem reflete o modelo comercial: "monitoramento é produto
     * separado, fale com comercial OU solicite via admin".
     */
    public static function assertCanCreate(int $accountId): void
    {
        $limit = self::getEffectiveLimit($accountId);
        $used  = self::getCurrentUsage($accountId);

        if ($limit <= 0) {
            self::deny(
                'sem_cota_contratada',
                'Sua conta ainda não tem monitoramentos contratados. ' .
                'Cada OAB/advogado monitorado é um produto separado do ' .
                'seu plano. Fale com seu gerente comercial pra contratar.',
                ['limit' => 0, 'used' => $used]
            );
        }
        if ($used >= $limit) {
            self::deny(
                'limite_atingido',
                "Limite de monitoramentos atingido ({$used}/{$limit}). " .
                'Contrate mais monitoramentos ou cancele algum existente ' .
                'pra liberar vaga.',
                ['limit' => $limit, 'used' => $used]
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Internos
    // ──────────────────────────────────────────────────────────────────

    private static function loadAccountMeta(int $accountId): ?array
    {
        static $cache = [];
        if (isset($cache[$accountId])) return $cache[$accountId];
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT tipo, matriz_id, status FROM accounts WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $accountId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            $cache[$accountId] = $row;
            return $row;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function deny(string $code, string $msg, array $extra = []): never
    {
        if (!headers_sent()) {
            http_response_code(402); // Payment Required
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'error' => $msg,
            'code'  => $code,
        ] + $extra, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
