<?php
namespace App\Processos;

use App\Core\Database;

/**
 * Model: PushQueryLog
 *
 * Log técnico de cada chamada ao provider (DJEN/DataJud).
 * NUNCA grava request_payload com PII — só hash (LGPD).
 */
class PushQueryLog
{
    public static function start(array $data): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO push_query_logs
               (account_id, monitor_id, source_id, origem_busca,
                data_inicio_filtro, data_fim_filtro,
                request_url, filtros_hash, started_at)
             VALUES
               (:acc, :mon, :src, :orig,
                :di, :df,
                :url, :fh, NOW())'
        );
        $stmt->execute([
            'acc'  => $data['account_id'] ?? null,
            'mon'  => $data['monitor_id'] ?? null,
            'src'  => $data['source_id']  ?? 'djen',
            'orig' => $data['origem_busca'] ?? 'manual',
            'di'   => $data['data_inicio_filtro'] ?? null,
            'df'   => $data['data_fim_filtro']    ?? null,
            'url'  => substr((string)($data['request_url'] ?? ''), 0, 500),
            'fh'   => $data['filtros_hash']       ?? null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function finish(int $id, array $data): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE push_query_logs
             SET response_status  = :rs,
                 response_hash    = :rh,
                 total_resultados = :tot,
                 status           = :st,
                 erro             = :err,
                 duracao_ms       = :dur,
                 finished_at      = NOW()
             WHERE id = :id'
        );
        return $stmt->execute([
            'id'  => $id,
            'rs'  => $data['response_status']  ?? null,
            'rh'  => $data['response_hash']    ?? null,
            'tot' => (int)($data['total_resultados'] ?? 0),
            'st'  => $data['status']           ?? 'ok',
            'err' => isset($data['erro']) ? substr((string)$data['erro'], 0, 500) : null,
            'dur' => $data['duracao_ms']       ?? null,
        ]);
    }
}
