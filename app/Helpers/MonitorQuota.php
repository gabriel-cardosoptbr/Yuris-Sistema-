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
     * Soma de overrides ATIVOS da conta (master_grant + purchase + promo).
     *
     * Regra SOMA (não MAX): cliente pode empilhar diferentes sources.
     * Ignora overrides revogados (revoked_at NOT NULL) ou expirados.
     *
     * @return int  Quantidade liberada via override. 0 se nenhum override
     *              ativo OU se tabela ainda não existe (fail-soft).
     */
    public static function getOwnLimit(int $accountId): int
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(limit_value), 0) AS total
                   FROM account_quota_overrides
                   WHERE account_id    = :aid
                     AND feature_key   = :fk
                     AND revoked_at    IS NULL
                     AND (expires_at   IS NULL OR expires_at > NOW())"
            );
            $stmt->execute(['aid' => $accountId, 'fk' => self::FEATURE_KEY]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            // Tabela inexistente em ambiente legado — fail-soft (zero).
            return 0;
        }
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
     * Limite efetivo da conta — resolve hierarquia matriz↔filial e o
     * modelo híbrido (pool aberto vs alocação fixa).
     *
     * REGRAS:
     *   • Conta tipo=matriz (sem matriz_id) → own_limit
     *   • Conta tipo=filial:
     *       - has_allocations? sim → SOMA allocations recebidas
     *       - has_allocations? não → own_limit do pai (matriz_id) [pool]
     *   • Conta tipo=advogado → own_limit + allocations recebidas
     *     (advogado vinculado a host pode receber alocação direta)
     *   • Default (qualquer outro) → own_limit
     */
    public static function getEffectiveLimit(int $accountId): int
    {
        $acc = self::loadAccountMeta($accountId);
        if ($acc === null) return 0;

        $tipo     = $acc['tipo']      ?? null;
        $matrizId = $acc['matriz_id'] ?? null;

        // Matriz raiz
        if ($tipo === 'matriz') {
            return self::getOwnLimit($accountId);
        }

        // Filial
        if ($tipo === 'filial') {
            if (self::hasAllocations($accountId)) {
                return self::getAllocationsReceived($accountId);
            }
            // Pool aberto — filial usa cota do pai
            if ($matrizId !== null) {
                return self::getOwnLimit((int) $matrizId);
            }
            return self::getOwnLimit($accountId);
        }

        // Advogado solo (conta própria) — soma own + allocations recebidas
        if ($tipo === 'advogado') {
            return self::getOwnLimit($accountId)
                 + self::getAllocationsReceived($accountId);
        }

        // Fallback genérico
        return self::getOwnLimit($accountId);
    }

    /**
     * Conta os monitors ativos/pausados/erro com consome_cota=1 da conta
     * (NÃO agrega filiais — chame com escopo expandido se precisar).
     *
     * Inclui monitor_requests com status='pending' (D4 aprovada — pending
     * reserva cota pra evitar race condition).
     */
    public static function getCurrentUsage(int $accountId): int
    {
        try {
            $pdo = Database::getConnection();

            // Monitors ativos consumindo cota
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                   FROM push_monitors
                   WHERE account_id   = :aid
                     AND consome_cota = 1
                     AND status IN ('ativo','pausado','erro')"
            );
            $stmt->execute(['aid' => $accountId]);
            $usados = (int) $stmt->fetchColumn();

            // Requests pendentes reservam vaga (D4)
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                   FROM monitor_requests
                   WHERE account_id = :aid
                     AND status     = 'pending'"
            );
            $stmt->execute(['aid' => $accountId]);
            $pending = (int) $stmt->fetchColumn();

            return $usados + $pending;
        } catch (\Throwable $e) {
            return 0;
        }
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
