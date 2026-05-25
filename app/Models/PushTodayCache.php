<?php
namespace App\Models;

use App\Models\Database;

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
               (account_id, source_id, tribunal, data_disponibilizacao, data_publicacao,
                numero_processo, numero_processo_mascara, orgao, id_orgao,
                tipo_comunicacao, meio, meio_completo, classe_nome, classe_codigo,
                titulo, resumo, conteudo, url_origem, numero_comunicacao,
                hash_externo, hash_conteudo, payload_original,
                encontrado_em, expires_at)
             VALUES
               (:acc, :src, :trib, :ddisp, :dpub,
                :np, :npm, :org, :idorg,
                :tcom, :meio, :mcomp, :cnome, :ccod,
                :tit, :res, :cont, :url, :ncom,
                :hext, :hcont, :payload,
                NOW(), :exp)'
        );
        $stmt->execute([
            'acc'     => (int)$data['account_id'],
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
