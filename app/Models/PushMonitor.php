<?php
namespace App\Models;

use App\Models\Database;

/**
 * Model: PushMonitor
 *
 * Fila de monitoramento: cada linha representa "vigia esta OAB/processo a cada X min".
 * Cron tick.php seleciona vencidos (proxima_consulta_em <= NOW) e dispara provider.
 */
class PushMonitor
{
    public static function create(array $data): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO push_monitors
               (account_id, advogado_id, processo_id, source_id,
                tipo_monitoramento, valor_monitorado, nome_complementar, uf, tribunal,
                status, prioridade, intervalo_minutos,
                proxima_consulta_em, created_by)
             VALUES
               (:acc, :adv, :proc, :src,
                :tipo, :valor, :nomec, :uf, :trib,
                :status, :prio, :interv,
                :prox, :cb)'
        );
        $stmt->execute([
            'acc'    => (int)$data['account_id'],
            'adv'    => $data['advogado_id']        ?? null,
            'proc'   => $data['processo_id']        ?? null,
            'src'    => $data['source_id']          ?? 'djen',
            'tipo'   => $data['tipo_monitoramento'] ?? 'oab',
            'valor'  => $data['valor_monitorado'],
            'nomec'  => $data['nome_complementar']  ?? null,
            'uf'     => $data['uf']                 ?? null,
            'trib'   => $data['tribunal']           ?? null,
            'status' => $data['status']             ?? 'ativo',
            'prio'   => $data['prioridade']         ?? 5,
            'interv' => $data['intervalo_minutos']  ?? 120,
            'prox'   => $data['proxima_consulta_em'] ?? date('Y-m-d H:i:s'),
            'cb'     => $data['created_by']         ?? null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Atualiza nome_complementar de um monitor existente (idempotente).
     * Usado quando user salva perfil novamente e quer combinar nome no monitor OAB.
     */
    public static function setNomeComplementar(int $id, int $accountId, ?string $nome): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE push_monitors SET nome_complementar = :n
             WHERE id = :id AND account_id = :acc'
        );
        return $stmt->execute(['id' => $id, 'acc' => $accountId, 'n' => ($nome === '' ? null : $nome)]);
    }

    public static function listForAccount(int $accountId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM push_monitors WHERE account_id = :acc ORDER BY status ASC, prioridade ASC, id DESC'
        );
        $stmt->execute(['acc' => $accountId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Pega N monitores vencidos prontos pra rodar (cross-tenant — só cron). */
    public static function dueNow(int $limit = 20): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM push_monitors
             WHERE status = "ativo"
               AND (proxima_consulta_em IS NULL OR proxima_consulta_em <= NOW())
             ORDER BY prioridade ASC, COALESCE(ultima_consulta_em, "1970-01-01") ASC
             LIMIT ' . (int)$limit
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function markSuccess(int $id, string $hashResultado): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE push_monitors
             SET ultima_consulta_em    = NOW(),
                 proxima_consulta_em   = DATE_ADD(NOW(), INTERVAL intervalo_minutos MINUTE),
                 ultimo_hash_resultado = :h,
                 ultimo_erro           = NULL,
                 erros_consecutivos    = 0
             WHERE id = :id'
        );
        return $stmt->execute(['id' => $id, 'h' => $hashResultado]);
    }

    public static function markError(int $id, string $erro): bool
    {
        // Backoff exponencial: dobra intervalo até teto 24h (1440 min)
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE push_monitors
             SET ultima_consulta_em  = NOW(),
                 proxima_consulta_em = DATE_ADD(NOW(), INTERVAL LEAST(intervalo_minutos * POWER(2, erros_consecutivos + 1), 1440) MINUTE),
                 ultimo_erro         = :e,
                 erros_consecutivos  = erros_consecutivos + 1,
                 status              = IF(erros_consecutivos >= 5, "erro", status)
             WHERE id = :id'
        );
        return $stmt->execute(['id' => $id, 'e' => substr($erro, 0, 255)]);
    }

    public static function delete(int $id, int $accountId): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM push_monitors WHERE id = :id AND account_id = :acc');
        return $stmt->execute(['id' => $id, 'acc' => $accountId]);
    }
}
