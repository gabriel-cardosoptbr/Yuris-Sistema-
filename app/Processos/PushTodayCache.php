<?php
namespace App\Processos;

use App\Core\Database;

/**
 * Model: PushTodayCache
 *
 * Cache temporário de publicações do dia (DJEN/DataJud).
 * - Expira em 24h via push_today_cache.expires_at
 * - UNIQUE (account_id, hash_conteudo) impede duplicidade
 * - NÃO é histórico: a tarefa tick.php apaga registros vencidos
 *
 * Multi-tenant rígido: todo SELECT/INSERT/DELETE filtra account_id.
 */
class PushTodayCache
{
    // ─────────────────────────────────────────────────────────────────────────
    // ESCRITA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Insere (ou ignora se já existe) uma publicação no cache.
     * Retorna true se foi inserido, false se duplicado.
     */
    public static function upsert(array $data): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO push_today_cache
               (account_id, monitor_id, source_id, tribunal, data_disponibilizacao, data_publicacao,
                numero_processo, numero_processo_mascara, orgao, id_orgao,
                tipo_comunicacao, meio, meio_completo, classe_nome, classe_codigo,
                titulo, resumo, conteudo, url_origem, numero_comunicacao,
                hash_externo, hash_conteudo, payload_original,
                encontrado_em, expires_at)
             VALUES
               (:acc, :mon, :src, :trib, :ddisp, :dpub,
                :np, :npm, :org, :idorg,
                :tcom, :meio, :mcomp, :cnome, :ccod,
                :tit, :res, :cont, :url, :ncom,
                :hext, :hcont, :payload,
                NOW(), :exp)'
        );
        $stmt->execute([
            'acc'     => (int)$data['account_id'],
            // Etapa 10 add-on Monitoramentos: rastreia qual monitor disparou
            // a busca que populou esse item. NULL pra cache vindo de busca manual.
            'mon'     => isset($data['monitor_id']) ? (int)$data['monitor_id'] : null,
            'src'     => $data['source_id']             ?? 'djen',
            'trib'    => $data['tribunal']              ?? '',
            'ddisp'   => $data['data_disponibilizacao'] ?? date('Y-m-d'),
            'dpub'    => $data['data_publicacao']       ?? null,
            'np'      => $data['numero_processo']       ?? null,
            'npm'     => $data['numero_processo_mascara'] ?? null,
            'org'     => $data['orgao']                 ?? null,
            'idorg'   => $data['id_orgao']              ?? null,
            'tcom'    => $data['tipo_comunicacao']      ?? null,
            'meio'    => $data['meio']                  ?? null,
            'mcomp'   => $data['meio_completo']         ?? null,
            'cnome'   => $data['classe_nome']           ?? null,
            'ccod'    => $data['classe_codigo']         ?? null,
            'tit'     => $data['titulo']                ?? null,
            'res'     => $data['resumo']                ?? null,
            'cont'    => $data['conteudo']              ?? null,
            'url'     => $data['url_origem']            ?? null,
            'ncom'    => $data['numero_comunicacao']    ?? null,
            'hext'    => $data['hash_externo']          ?? null,
            'hcont'   => $data['hash_conteudo'],
            'payload' => isset($data['payload_original']) && is_array($data['payload_original'])
                            ? json_encode($data['payload_original'], JSON_UNESCAPED_UNICODE)
                            : ($data['payload_original'] ?? null),
            'exp'     => $data['expires_at'] ?? (date('Y-m-d') . ' 23:59:59'),
        ]);
        return $stmt->rowCount() > 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEITURA
    // ─────────────────────────────────────────────────────────────────────────

    /** Lista cache do dia para a conta atual, ordenado por data desc. */
    public static function listForAccount(int $accountId, array $opts = []): array
    {
        $pdo   = Database::getConnection();
        $where = ['account_id = :acc', 'expires_at > NOW()'];
        $bind  = ['acc' => $accountId];

        if (!empty($opts['tribunal'])) {
            $where[] = 'tribunal = :trib';
            $bind['trib'] = $opts['tribunal'];
        }
        if (!empty($opts['data_disponibilizacao'])) {
            $where[] = 'data_disponibilizacao = :ddisp';
            $bind['ddisp'] = $opts['data_disponibilizacao'];
        }
        if (!empty($opts['source_id'])) {
            $where[] = 'source_id = :src';
            $bind['src']  = $opts['source_id'];
        }
        $limit = (int)($opts['limit'] ?? 200);

        $sql  = 'SELECT * FROM push_today_cache WHERE ' . implode(' AND ', $where)
              . ' ORDER BY data_disponibilizacao DESC, id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Tira da lista do cache o que já foi salvo em push_events (promovido ao
     * marcar lida, favoritar, vincular...). A comparação é com TODA publicação
     * salva da conta, e não só com as que passaram no filtro da tela: com o
     * filtro "Não lidas", a publicação lida não volta na lista salva, e antes
     * disso a cópia dela no cache reaparecia como item novo, sem status (T5,
     * 10/11/2026).
     *
     * @param array<int,array> $linhas linhas de listForAccount()
     * @return array<int,array>
     */
    public static function semOsJaSalvos(int $accountId, array $linhas): array
    {
        $hashes = array_values(array_unique(array_filter(array_column($linhas, 'hash_conteudo'))));
        if (!$hashes) return $linhas;
        $pdo = Database::getConnection();
        $salvos = [];
        foreach (array_chunk($hashes, 400) as $lote) {
            $st = $pdo->prepare(
                'SELECT hash_conteudo FROM push_events
                  WHERE account_id = ? AND hash_conteudo IN (' . implode(',', array_fill(0, count($lote), '?')) . ')'
            );
            $st->execute(array_merge([$accountId], $lote));
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $h) $salvos[$h] = true;
        }
        return array_values(array_filter($linhas, static fn ($l) => !isset($salvos[$l['hash_conteudo'] ?? ''])));
    }

    /** Conta cache válido (não expirado) por conta. */
    public static function countActive(int $accountId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM push_today_cache
             WHERE account_id = :acc AND expires_at > NOW()'
        );
        $stmt->execute(['acc' => $accountId]);
        return (int)$stmt->fetchColumn();
    }

    /** Busca por hash dentro da conta (pra promover ao push_events). */
    public static function findByHash(int $accountId, string $hash): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM push_today_cache
             WHERE account_id = :acc AND hash_conteudo = :h LIMIT 1'
        );
        $stmt->execute(['acc' => $accountId, 'h' => $hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MANUTENÇÃO
    // ─────────────────────────────────────────────────────────────────────────

    /** Apaga TODOS os registros expirados (cross-tenant — só roda no cron). */
    public static function expireOld(): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM push_today_cache WHERE expires_at <= NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }
}
