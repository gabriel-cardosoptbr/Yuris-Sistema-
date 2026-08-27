<?php
namespace App\Processos;

use App\Core\Database;

/**
 * Model: PushEvent
 *
 * Publicações PERSISTENTES — gravadas apenas quando o usuário interage
 * (lida, favorita, com prazo, comentário, vínculo a processo).
 *
 * Persistência multi-tenant rígida: account_id NOT NULL.
 * Anti-dup: UNIQUE (account_id, hash_conteudo) — idempotente.
 */
class PushEvent
{
    /**
     * Insere ou atualiza um evento persistente.
     * Retorna ID do registro (novo ou existente).
     *
     * AUTO-LINK: se processo_id não foi passado e existe entrada em
     * push_processo_links pro mesmo (account_id + numero_processo),
     * preenche automaticamente. Aplica-se a TODA intimação nova
     * (cron, sync, manual, persist) — zero intervenção.
     */
    public static function upsert(array $data): int
    {
        // Auto-link: tenta vincular processo automaticamente via PushProcessoLink
        // se ainda não foi vinculado e temos numero_processo no payload.
        if (empty($data['processo_id']) && !empty($data['numero_processo']) && !empty($data['account_id'])) {
            // Lazy require pra evitar ciclo de inclusão
            if (!class_exists('\\App\\Processos\\PushProcessoLink')) {
                require_once __DIR__ . '/PushProcessoLink.php';
            }
            $autoProcId = \App\Processos\PushProcessoLink::findProcessoId(
                (int)$data['account_id'],
                (string)$data['numero_processo']
            );
            if ($autoProcId > 0) {
                $data['processo_id'] = $autoProcId;
            }
        }
        $pdo  = Database::getConnection();
        $pdo->prepare(
            'INSERT INTO push_events
               (account_id, source_id, advogado_id, processo_id, monitor_id,
                tipo_evento, titulo, resumo, conteudo,
                data_evento, data_disponibilizacao, data_publicacao,
                tribunal, orgao, id_orgao,
                numero_processo, numero_processo_mascara,
                tipo_comunicacao, meio, meio_completo, classe_nome, classe_codigo,
                advogado_nome, advogado_oab,
                url_origem, numero_comunicacao, hash_externo, hash_conteudo,
                payload_original, origem_busca, status)
             VALUES
               (:acc, :src, :adv, :proc, :mon,
                :te, :tit, :res, :cont,
                :dev, :ddisp, :dpub,
                :trib, :org, :idorg,
                :np, :npm,
                :tcom, :meio, :mcomp, :cnome, :ccod,
                :anome, :aoab,
                :url, :ncom, :hext, :hcont,
                :payload, :orig, :status)
             ON DUPLICATE KEY UPDATE
                updated_at  = NOW(),
                titulo      = COALESCE(VALUES(titulo), titulo),
                resumo      = COALESCE(VALUES(resumo), resumo),
                conteudo    = COALESCE(VALUES(conteudo), conteudo),
                advogado_id = COALESCE(VALUES(advogado_id), advogado_id),
                processo_id = COALESCE(VALUES(processo_id), processo_id),
                monitor_id  = COALESCE(VALUES(monitor_id), monitor_id)'
        )->execute([
            'acc'    => (int)$data['account_id'],
            'src'    => $data['source_id']             ?? 'djen',
            'adv'    => $data['advogado_id']           ?? null,
            'proc'   => $data['processo_id']           ?? null,
            'mon'    => $data['monitor_id']            ?? null,
            'te'     => $data['tipo_evento']           ?? 'publicacao',
            'tit'    => $data['titulo']                ?? null,
            'res'    => $data['resumo']                ?? null,
            'cont'   => $data['conteudo']              ?? null,
            'dev'    => $data['data_evento']           ?? date('Y-m-d H:i:s'),
            'ddisp'  => $data['data_disponibilizacao'] ?? date('Y-m-d'),
            'dpub'   => $data['data_publicacao']       ?? null,
            'trib'   => $data['tribunal']              ?? '',
            'org'    => $data['orgao']                 ?? null,
            'idorg'  => $data['id_orgao']              ?? null,
            'np'     => $data['numero_processo']       ?? null,
            'npm'    => $data['numero_processo_mascara'] ?? null,
            'tcom'   => $data['tipo_comunicacao']      ?? null,
            'meio'   => $data['meio']                  ?? null,
            'mcomp'  => $data['meio_completo']         ?? null,
            'cnome'  => $data['classe_nome']           ?? null,
            'ccod'   => $data['classe_codigo']         ?? null,
            'anome'  => $data['advogado_nome']         ?? null,
            'aoab'   => $data['advogado_oab']          ?? null,
            'url'    => $data['url_origem']            ?? null,
            'ncom'   => $data['numero_comunicacao']    ?? null,
            'hext'   => $data['hash_externo']          ?? null,
            'hcont'  => $data['hash_conteudo'],
            'payload'=> isset($data['payload_original']) && is_array($data['payload_original'])
                          ? json_encode($data['payload_original'], JSON_UNESCAPED_UNICODE)
                          : ($data['payload_original'] ?? null),
            'orig'   => $data['origem_busca']          ?? 'manual',
            'status' => $data['status']                ?? 'ativo',
        ]);
        // ID: retorna existente se já tinha
        $stmt = $pdo->prepare(
            'SELECT id FROM push_events WHERE account_id = :acc AND hash_conteudo = :h LIMIT 1'
        );
        $stmt->execute(['acc' => (int)$data['account_id'], 'h' => $data['hash_conteudo']]);
        return (int)$stmt->fetchColumn();
    }

    /** Lista persistente, filtrado por conta + opções. */
    public static function listForAccount(int $accountId, array $opts = []): array
    {
        $pdo   = Database::getConnection();
        $where = ['account_id = :acc', 'status = "ativo"'];
        $bind  = ['acc' => $accountId];

        if (!empty($opts['data_inicio'])) {
            $where[] = 'data_disponibilizacao >= :di';
            $bind['di'] = $opts['data_inicio'];
        }
        if (!empty($opts['data_fim'])) {
            $where[] = 'data_disponibilizacao <= :df';
            $bind['df'] = $opts['data_fim'];
        }
        if (!empty($opts['tribunal'])) {
            $where[] = 'tribunal = :trib';
            $bind['trib'] = $opts['tribunal'];
        }
        if (!empty($opts['numero_processo'])) {
            $where[] = 'numero_processo = :np';
            $bind['np'] = $opts['numero_processo'];
        }
        $limit = (int)($opts['limit'] ?? 100);

        $sql  = 'SELECT * FROM push_events WHERE ' . implode(' AND ', $where)
              . ' ORDER BY data_disponibilizacao DESC, id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function findByIdForAccount(int $id, int $accountId): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM push_events WHERE id = :id AND account_id = :acc LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'acc' => $accountId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
