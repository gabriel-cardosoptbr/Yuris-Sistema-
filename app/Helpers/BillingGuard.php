<?php
namespace App\Helpers;

use App\Models\Database;

/**
 * BillingGuard — verifica limites de plano antes de criar recursos.
 *
 * Lê a subscription ativa da conta, busca a feature em plan_features,
 * conta o uso atual em usage_counters (atualiza on-demand) e bloqueia
 * com 402 (Payment Required) se exceder.
 *
 * Uso típico em endpoints de CREATE:
 *   BillingGuard::assertCanCreate($accountId, 'max_processos', 'processos');
 *
 * Comportamento:
 *   - limit_value NULL  → ilimitado (libera)
 *   - limit_value 0     → feature desabilitada (bloqueia)
 *   - limit_value N     → bloqueia se count atual >= N
 *
 * SAFE-FAIL: se as tabelas de billing ainda não existirem (ex: deploy
 * antes da migration 038), o guard libera silenciosamente.
 */
final class BillingGuard
{
    /**
     * Lança 402 se o limite foi atingido.
     */
    public static function assertCanCreate(int $accountId, string $featureKey, string $countTable): void
    {
        $limit = self::getLimit($accountId, $featureKey);
        if ($limit === null)  return;          // ilimitado
        if ($limit === false) return;          // billing infra ausente — libera
        if ($limit === 0) {
            self::deny("Feature '{$featureKey}' não está disponível no seu plano.");
        }

        $count = self::getCurrentCount($accountId, $countTable);
        if ($count >= $limit) {
            self::deny(
                "Limite do plano atingido para '{$featureKey}' ({$count}/{$limit}). " .
                "Faça upgrade pra continuar."
            );
        }
    }

    /**
     * Retorna o limit_value EFETIVO da conta para uma feature.
     *
     * EVOLUÇÃO 2026-05-26 (Etapa 4 add-on Monitoramentos):
     *   Agora soma `plan_features.limit_value + SUM(account_quota_overrides
     *   ativos)`. Compat retroativa: módulos antigos (max_users, max_processos,
     *   etc.) continuam funcionando porque overrides são opt-in — se a tabela
     *   estiver vazia ou inexistente, comportamento é idêntico ao anterior.
     *
     *   Casos de uso da nova semântica:
     *   • plan_features.max_processos=100 + override(+5) = 105 efetivos.
     *   • plan_features.monitors.limit=0 + override(+10) = 10 efetivos.
     *   • plan_features.limit_value=NULL (ilimitado) → ignora overrides
     *     (ilimitado + qualquer coisa = ilimitado).
     *
     * Retorno:
     * - null  → ilimitado (plan_features.limit_value=NULL e is_enabled=1)
     * - false → infra de billing indisponível (use como "libera")
     * - int   → limite numérico = base do plano + overrides ativos
     */
    public static function getLimit(int $accountId, string $featureKey): int|null|false
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT pf.limit_value, pf.is_enabled
                 FROM subscriptions s
                 INNER JOIN plan_features pf ON pf.plan_id = s.plan_id
                 WHERE s.account_id = :aid
                   AND s.status IN ("trialing","active","past_due")
                   AND pf.feature_key = :fk
                 ORDER BY (s.status="active") DESC, s.id DESC
                 LIMIT 1'
            );
            $stmt->execute(['aid' => $accountId, 'fk' => $featureKey]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) return false;
            if (!$row['is_enabled']) return 0;

            // Base do plano (NULL = ilimitado — não soma overrides)
            if ($row['limit_value'] === null) return null;

            $base = (int) $row['limit_value'];

            // Soma overrides ATIVOS (Etapa 4 add-on Monitoramentos).
            // Cliente pode empilhar grant promo + purchase. Fail-soft se a
            // tabela ainda não existir (deploy antes da migração 072).
            $overrideSum = self::sumActiveOverrides($pdo, $accountId, $featureKey);

            return $base + $overrideSum;
        } catch (\Throwable $_e) {
            return false;
        }
    }

    /**
     * Retorna apenas a base do plano (sem somar overrides).
     * Útil pro Painel Master mostrar "Plano: X + Overrides: Y = Total: Z".
     */
    public static function getBaseLimit(int $accountId, string $featureKey): int|null|false
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT pf.limit_value, pf.is_enabled
                 FROM subscriptions s
                 INNER JOIN plan_features pf ON pf.plan_id = s.plan_id
                 WHERE s.account_id = :aid
                   AND s.status IN ("trialing","active","past_due")
                   AND pf.feature_key = :fk
                 ORDER BY (s.status="active") DESC, s.id DESC
                 LIMIT 1'
            );
            $stmt->execute(['aid' => $accountId, 'fk' => $featureKey]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) return false;
            if (!$row['is_enabled']) return 0;
            return $row['limit_value'] === null ? null : (int) $row['limit_value'];
        } catch (\Throwable $_e) {
            return false;
        }
    }

    /**
     * Retorna a soma dos overrides ATIVOS pra uma conta + feature.
     * Fail-soft: 0 se tabela ausente (ambiente pre-mig 072).
     */
    public static function getOverrideSum(int $accountId, string $featureKey): int
    {
        try {
            return self::sumActiveOverrides(Database::getConnection(), $accountId, $featureKey);
        } catch (\Throwable $_e) {
            return 0;
        }
    }

    /**
     * Soma overrides ativos. Internal — assume PDO já aberto.
     * Regra: active = revoked_at IS NULL AND (expires_at IS NULL OR > NOW())
     */
    private static function sumActiveOverrides(\PDO $pdo, int $accountId, string $featureKey): int
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(limit_value), 0)
                   FROM account_quota_overrides
                  WHERE account_id  = :aid
                    AND feature_key = :fk
                    AND revoked_at  IS NULL
                    AND (expires_at IS NULL OR expires_at > NOW())'
            );
            $stmt->execute(['aid' => $accountId, 'fk' => $featureKey]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $_e) {
            // Tabela ausente (pre-mig 072) — fail-soft retornando zero.
            // Comportamento idêntico ao BillingGuard antes desta Etapa 4.
            return 0;
        }
    }

    /**
     * Conta registros ativos (deleted_at IS NULL) na tabela informada para o tenant.
     * Cacheia em usage_counters por mês.
     */
    public static function getCurrentCount(int $accountId, string $table): int
    {
        // Tabelas válidas (whitelist)
        $allowed = ['processos','cards','users','tasks','contatos','accounts','task_boards'];
        if (!in_array($table, $allowed, true)) return 0;

        try {
            $pdo = Database::getConnection();
            $hasDeleted = self::columnExists($pdo, $table, 'deleted_at');
            $where = "account_id = :aid" . ($hasDeleted ? " AND deleted_at IS NULL" : "");
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['aid' => $accountId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $_e) {
            return 0;
        }
    }

    /**
     * Verifica se uma feature está habilitada pro plano da conta.
     * Útil pra esconder UI sem precisar checar limite.
     */
    public static function isFeatureEnabled(int $accountId, string $featureKey): bool
    {
        $limit = self::getLimit($accountId, $featureKey);
        if ($limit === false) return true;     // infra ausente — libera
        if ($limit === 0)     return false;
        return true;
    }

    /**
     * Retorna info da subscription atual da conta (ou null).
     */
    public static function getCurrentSubscription(int $accountId): ?array
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT s.*, p.slug AS plan_slug, p.nome AS plan_nome
                 FROM subscriptions s
                 INNER JOIN plans p ON p.id = s.plan_id
                 WHERE s.account_id = :aid
                 ORDER BY (s.status="active") DESC, s.id DESC LIMIT 1'
            );
            $stmt->execute(['aid' => $accountId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $_e) {
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────────────

    private static function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c LIMIT 1"
            );
            $stmt->execute(['t' => $table, 'c' => $column]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $_e) {
            return false;
        }
    }

    private static function deny(string $msg): never
    {
        if (!headers_sent()) {
            http_response_code(402); // Payment Required
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => $msg, 'code' => 'PLAN_LIMIT_EXCEEDED']);
        exit;
    }
}
