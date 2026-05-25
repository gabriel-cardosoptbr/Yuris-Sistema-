<?php
namespace App\Models;

require_once __DIR__ . '/Database.php';

use App\Models\Database;

/**
 * Model: PushProcessoLink
 *
 * Mapeia "numero_processo (CNJ)" → "processo_id (CRM)" por tenant.
 * Quando user vincula 1 intimação a 1 processo, criamos entrada aqui.
 * A partir daí, todas as próximas intimações com o mesmo número são
 * auto-vinculadas em PushEvent::upsert sem intervenção do user.
 *
 * Multi-tenant rígido: todo SELECT/UPDATE/DELETE filtra account_id.
 */
class PushProcessoLink
{
    /**
     * Cria/atualiza link processo↔intimação pra um tenant.
     * Idempotente (UNIQUE em account_id + numero_processo).
     */
    public static function linkProcesso(
        int $accountId,
        string $numeroProcesso,
        int $processoId,
        ?int $createdBy = null
    ): bool {
        $numero = self::normalizaNumero($numeroProcesso);
        if ($numero === '' || $processoId <= 0) return false;

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO push_processo_links
               (account_id, numero_processo, processo_id, created_by)
             VALUES (:acc, :np, :pid, :cb)
             ON DUPLICATE KEY UPDATE
               processo_id = VALUES(processo_id),
               updated_at  = NOW()'
        );
        return $stmt->execute([
            'acc' => $accountId,
            'np'  => $numero,
            'pid' => $processoId,
            'cb'  => $createdBy,
        ]);
    }

    /**
     * Busca processo_id vinculado a esse numero_processo nesse tenant.
     * Retorna 0 se não houver link.
     */
    public static function findProcessoId(int $accountId, string $numeroProcesso): int
    {
        $numero = self::normalizaNumero($numeroProcesso);
        if ($numero === '') return 0;
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT processo_id FROM push_processo_links
              WHERE account_id = :acc AND numero_processo = :np LIMIT 1'
        );
        $stmt->execute(['acc' => $accountId, 'np' => $numero]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Remove link (caso user desfaça). NÃO desvincula intimações que já
     * foram processadas — só impede futuras de serem auto-vinculadas.
     */
    public static function unlinkProcesso(int $accountId, string $numeroProcesso): bool
    {
        $numero = self::normalizaNumero($numeroProcesso);
        if ($numero === '') return false;
        $pdo  = Database::getConnection();
        return $pdo->prepare(
            'DELETE FROM push_processo_links WHERE account_id = :acc AND numero_processo = :np'
        )->execute(['acc' => $accountId, 'np' => $numero]);
    }

    /**
     * Aplica vínculo retroativamente: UPDATE em todas push_events com mesmo
     * numero_processo no tenant que ainda não tinham processo_id.
     * Retorna número de linhas afetadas.
     */
    public static function aplicarRetroativo(
        int $accountId,
        string $numeroProcesso,
        int $processoId
    ): int {
        $numero = self::normalizaNumero($numeroProcesso);
        if ($numero === '' || $processoId <= 0) return 0;
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE push_events
                SET processo_id = :pid
              WHERE account_id      = :acc
                AND numero_processo = :np
                AND processo_id     IS NULL'
        );
        $stmt->execute(['acc' => $accountId, 'np' => $numero, 'pid' => $processoId]);
        return $stmt->rowCount();
    }

    /** Lista todos os links de um tenant (uso master/admin). */
    public static function listForAccount(int $accountId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, numero_processo, processo_id, created_by, created_at, updated_at
               FROM push_processo_links
              WHERE account_id = :acc
              ORDER BY updated_at DESC'
        );
        $stmt->execute(['acc' => $accountId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Normaliza o número do processo (só dígitos). Cobre 2 formatos comuns:
     * "1007778-20.2025.8.26.0554" → "10077782020258260554"
     * "10077782020258260554"      → "10077782020258260554"
     */
    private static function normalizaNumero(string $n): string
    {
        $d = preg_replace('/\D/', '', $n);
        return $d ?: '';
    }
}
