<?php
namespace App\Models;

use App\Models\Database;

/**
 * Model: AccountVinculo
 *
 * Gerencia o relacionamento Matriz ↔ Filial.
 * Fluxo padrão (inspirado no Slack Connect / Google Workspace domain federation):
 *   1. Filial obtém o codigo_vinculo da Matriz
 *   2. Filial chama POST /api/account_vinculos com o código → status = 'pending'
 *   3. Notificação chega para owner/admin da Matriz
 *   4. Matriz aprova → status = 'active'
 */
class AccountVinculo
{
    // ─────────────────────────────────────────────────────────────────────────
    // LEITURA
    // ─────────────────────────────────────────────────────────────────────────

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM account_vinculos WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Lista todos os vínculos de uma conta (como matriz ou como filial) */
    public static function listByAccount(int $accountId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT av.*,
                    am.nome AS matriz_nome, am.tipo AS matriz_tipo,
                    af.nome AS filial_nome, af.tipo AS filial_tipo,
                    us.nome AS solicitado_por_nome,
                    ua.nome AS aprovado_por_nome
             FROM account_vinculos av
             LEFT JOIN accounts am ON am.id = av.matriz_account_id
             LEFT JOIN accounts af ON af.id = av.filial_account_id
             LEFT JOIN users us    ON us.id = av.solicitado_por
             LEFT JOIN users ua    ON ua.id = av.aprovado_por
             WHERE av.matriz_account_id = :acc1
                OR av.filial_account_id = :acc2
             ORDER BY av.created_at DESC'
        );
        $stmt->execute(['acc1' => $accountId, 'acc2' => $accountId]);
        return $stmt->fetchAll();
    }

    /** Retorna vínculo pendente entre duas contas específicas */
    public static function findPendente(int $matrizId, int $filialId): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM account_vinculos
             WHERE matriz_account_id = :m AND filial_account_id = :f
               AND status IN ("pending", "active")
             LIMIT 1'
        );
        $stmt->execute(['m' => $matrizId, 'f' => $filialId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ESCRITA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cria solicitação de vínculo (status=pending).
     * Idempotente: se já existe vínculo pending/active, retorna o ID existente.
     */
    public static function solicitar(int $matrizId, int $filialId, int $solicitadoPor): int
    {
        $existente = self::findPendente($matrizId, $filialId);
        if ($existente) return (int) $existente['id'];

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO account_vinculos
               (matriz_account_id, filial_account_id, status, solicitado_por, solicitado_em, created_at, updated_at)
             VALUES
               (:m, :f, "pending", :sp, NOW(), NOW(), NOW())'
        );
        $stmt->execute(['m' => $matrizId, 'f' => $filialId, 'sp' => $solicitadoPor]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Aprova vínculo (apenas owner/admin da Matriz pode chamar).
     * Validação de permissão deve ser feita no endpoint antes de chamar este método.
     */
    public static function aprovar(int $vinculoId, int $aprovadoPor): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE account_vinculos
             SET status = "active",
                 aprovado_por = :ap,
                 aprovado_em  = NOW(),
                 updated_at   = NOW()
             WHERE id = :id AND status = "pending"'
        );
        return $stmt->execute(['ap' => $aprovadoPor, 'id' => $vinculoId]) && $stmt->rowCount() > 0;
    }

    public static function rejeitar(int $vinculoId, int $rejeitadoPor): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE account_vinculos
             SET status = "rejected",
                 aprovado_por = :ap,
                 updated_at   = NOW()
             WHERE id = :id AND status = "pending"'
        );
        return $stmt->execute(['ap' => $rejeitadoPor, 'id' => $vinculoId]) && $stmt->rowCount() > 0;
    }

    public static function suspender(int $vinculoId, int $suspensoPor, string $motivo = ''): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE account_vinculos
             SET status = "suspended",
                 suspenso_em      = NOW(),
                 motivo_suspensao = :motivo,
                 updated_at       = NOW()
             WHERE id = :id AND status = "active"'
        );
        return $stmt->execute(['motivo' => $motivo, 'id' => $vinculoId]) && $stmt->rowCount() > 0;
    }

    public static function reativar(int $vinculoId): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE account_vinculos
             SET status = "active",
                 suspenso_em      = NULL,
                 motivo_suspensao = NULL,
                 updated_at       = NOW()
             WHERE id = :id AND status = "suspended"'
        );
        return $stmt->execute(['id' => $vinculoId]) && $stmt->rowCount() > 0;
    }
}
