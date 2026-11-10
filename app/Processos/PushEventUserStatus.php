<?php
namespace App\Processos;

use App\Core\Database;

/**
 * Model: PushEventUserStatus
 *
 * Status por usuário: lida, favorita, com prazo, comentário.
 * Uma publicação pode estar lida por um user e não-lida por outro.
 *
 * UNIQUE (event_id, user_id) — upsert seguro.
 */
class PushEventUserStatus
{
    /** Marca/desmarca lida. */
    public static function setLida(int $eventId, int $userId, int $accountId, bool $lida): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO push_event_user_status
               (event_id, user_id, account_id, lida, lida_em)
             VALUES (:e, :u, :a, :l, :le)
             ON DUPLICATE KEY UPDATE
               lida    = VALUES(lida),
               lida_em = VALUES(lida_em),
               updated_at = NOW()'
        );
        return $stmt->execute([
            'e'  => $eventId,
            'u'  => $userId,
            'a'  => $accountId,
            'l'  => $lida ? 1 : 0,
            'le' => $lida ? date('Y-m-d H:i:s') : null,
        ]);
    }

    /** Toggle favorita. Retorna o novo estado. */
    public static function toggleFavorita(int $eventId, int $userId, int $accountId): bool
    {
        $cur  = self::get($eventId, $userId);
        $next = !((int)($cur['favorita'] ?? 0) === 1);
        $pdo  = Database::getConnection();
        $pdo->prepare(
            'INSERT INTO push_event_user_status
               (event_id, user_id, account_id, favorita)
             VALUES (:e, :u, :a, :f)
             ON DUPLICATE KEY UPDATE favorita = VALUES(favorita), updated_at = NOW()'
        )->execute(['e' => $eventId, 'u' => $userId, 'a' => $accountId, 'f' => $next ? 1 : 0]);
        return $next;
    }

    /** Marca/desmarca com prazo (e data). */
    public static function setPrazo(int $eventId, int $userId, int $accountId, ?string $prazoData): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO push_event_user_status
               (event_id, user_id, account_id, com_prazo, prazo_data)
             VALUES (:e, :u, :a, :cp, :pd)
             ON DUPLICATE KEY UPDATE
               com_prazo  = VALUES(com_prazo),
               prazo_data = VALUES(prazo_data),
               updated_at = NOW()'
        );
        return $stmt->execute([
            'e'  => $eventId,
            'u'  => $userId,
            'a'  => $accountId,
            'cp' => $prazoData ? 1 : 0,
            'pd' => $prazoData,
        ]);
    }

    /** Salva/atualiza comentário interno. */
    public static function setComentario(int $eventId, int $userId, int $accountId, ?string $comentario): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO push_event_user_status
               (event_id, user_id, account_id, comentario)
             VALUES (:e, :u, :a, :c)
             ON DUPLICATE KEY UPDATE comentario = VALUES(comentario), updated_at = NOW()'
        );
        return $stmt->execute([
            'e' => $eventId,
            'u' => $userId,
            'a' => $accountId,
            'c' => $comentario,
        ]);
    }

    /** Lê status de um evento pra um user. */
    public static function get(int $eventId, int $userId): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM push_event_user_status WHERE event_id = :e AND user_id = :u LIMIT 1'
        );
        $stmt->execute(['e' => $eventId, 'u' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Status do usuário para publicações já salvas, pela chave hash_conteudo.
     * Serve à busca externa (push/search.php), cujos resultados chegam sem id:
     * sem isto, o que já foi lido voltava da busca como se nunca tivesse sido.
     *
     * @param string[] $hashes
     * @return array<string,array{id:int,lida:int,favorita:int,com_prazo:int}>
     */
    public static function statusPorHash(int $accountId, int $userId, array $hashes): array
    {
        $hashes = array_values(array_unique(array_filter($hashes, 'is_string')));
        if (!$hashes) return [];
        $pdo = Database::getConnection();
        $mapa = [];
        foreach (array_chunk($hashes, 400) as $lote) {
            $st = $pdo->prepare(
                'SELECT e.id, e.hash_conteudo, COALESCE(s.lida, 0) lida,
                        COALESCE(s.favorita, 0) favorita, COALESCE(s.com_prazo, 0) com_prazo
                   FROM push_events e
                   LEFT JOIN push_event_user_status s ON s.event_id = e.id AND s.user_id = ?
                  WHERE e.account_id = ? AND e.hash_conteudo IN (' . implode(',', array_fill(0, count($lote), '?')) . ')'
            );
            $st->execute(array_merge([$userId, $accountId], $lote));
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $mapa[$r['hash_conteudo']] = [
                    'id' => (int) $r['id'], 'lida' => (int) $r['lida'],
                    'favorita' => (int) $r['favorita'], 'com_prazo' => (int) $r['com_prazo'],
                ];
            }
        }
        return $mapa;
    }

    /** Conta não-lidas pro user (KPI). */
    public static function countNaoLidas(int $userId, int $accountId): int
    {
        // Não-lida = (a) sem registro OU (b) lida = 0
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM push_events e
             LEFT JOIN push_event_user_status s
               ON s.event_id = e.id AND s.user_id = :u
             WHERE e.account_id = :a AND e.status = "ativo" AND COALESCE(s.lida, 0) = 0'
        );
        $stmt->execute(['u' => $userId, 'a' => $accountId]);
        return (int)$stmt->fetchColumn();
    }
}
