<?php
namespace App\Models;

use App\Models\Database;

/**
 * Model: AdvogadoVinculo
 *
 * Espelha o conceito de AccountVinculo (matriz ↔ filial), mas para o
 * relacionamento de CONTA entre matriz/filial (host) e um advogado solo
 * (account.tipo = 'advogado').
 *
 * Diferenças importantes:
 *   - O termo é `host_account_id` (matriz OU filial podem vincular)
 *   - `advogado_account_id` deve ser uma conta tipo='advogado'
 *   - sync_enabled + sync_<modulo> idênticos a account_vinculos
 *
 * Fluxo padrão:
 *   1. Host (matriz/filial) obtém codigo_advogado (ADV-XXXXXX) do advogado
 *   2. Host chama POST /api/advogado_vinculos com o código → status='pending'
 *      (ou já 'active' se for criação direta pelo host com auto-approve)
 *   3. Notificação para o advogado
 *   4. Advogado aceita → status='active'
 *
 * @see app/Models/AccountVinculo.php — modelo análogo para matriz↔filial
 */
class AdvogadoVinculo
{
    // ─────────────────────────────────────────────────────────────────────────
    // LEITURA
    // ─────────────────────────────────────────────────────────────────────────

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM advogado_vinculos WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Lista vínculos onde a conta é HOST (matriz/filial vendo seus advogados).
     *
     * OAB do advogado mora em users.oab (não accounts.oab). Fazemos LEFT JOIN
     * com 1 user qualquer da conta advogado pra trazer a OAB pro UI.
     */
    public static function listByHost(int $hostAccountId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT av.*,
                    ah.nome AS host_nome,     ah.tipo AS host_tipo,
                    aa.nome AS advogado_nome, aa.tipo AS advogado_tipo,
                    (SELECT u.oab FROM users u
                       WHERE u.account_id = aa.id
                         AND u.oab IS NOT NULL AND u.oab <> \'\'
                         AND u.deleted_at IS NULL
                       LIMIT 1) AS advogado_oab,
                    us.nome AS solicitado_por_nome,
                    ua.nome AS aprovado_por_nome
             FROM advogado_vinculos av
             LEFT JOIN accounts ah ON ah.id = av.host_account_id
             LEFT JOIN accounts aa ON aa.id = av.advogado_account_id
             LEFT JOIN users    us ON us.id = av.solicitado_por
             LEFT JOIN users    ua ON ua.id = av.aprovado_por
             WHERE av.host_account_id = :host
             ORDER BY av.created_at DESC'
        );
        $stmt->execute(['host' => $hostAccountId]);
        return $stmt->fetchAll();
    }

    /**
     * Lista vínculos onde a conta é ADVOGADO (advogado vendo quem o vinculou).
     */
    public static function listByAdvogado(int $advogadoAccountId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT av.*,
                    ah.nome AS host_nome,     ah.tipo AS host_tipo,
                    aa.nome AS advogado_nome, aa.tipo AS advogado_tipo,
                    us.nome AS solicitado_por_nome,
                    ua.nome AS aprovado_por_nome
             FROM advogado_vinculos av
             LEFT JOIN accounts ah ON ah.id = av.host_account_id
             LEFT JOIN accounts aa ON aa.id = av.advogado_account_id
             LEFT JOIN users    us ON us.id = av.solicitado_por
             LEFT JOIN users    ua ON ua.id = av.aprovado_por
             WHERE av.advogado_account_id = :adv
             ORDER BY av.created_at DESC'
        );
        $stmt->execute(['adv' => $advogadoAccountId]);
        return $stmt->fetchAll();
    }

    /**
     * Vínculo existente entre host e advogado (pending ou active).
     */
    public static function findEntre(int $hostId, int $advogadoId): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM advogado_vinculos
             WHERE host_account_id = :h AND advogado_account_id = :a
               AND status IN ("pending", "active")
             LIMIT 1'
        );
        $stmt->execute(['h' => $hostId, 'a' => $advogadoId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ESCRITA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cria solicitação de vínculo.
     * Idempotente: se já existe vínculo pending/active, retorna o ID existente.
     * Se $autoActivate=true, já cria como 'active' (uso: matriz convidou e
     * o advogado pode aceitar implicitamente — fluxo simplificado).
     */
    public static function solicitar(int $hostId, int $advogadoId, int $solicitadoPor, bool $autoActivate = false): int
    {
        $existente = self::findEntre($hostId, $advogadoId);
        if ($existente) return (int) $existente['id'];

        $pdo  = Database::getConnection();
        $status = $autoActivate ? 'active' : 'pending';
        $aprovadoEm = $autoActivate ? 'NOW()' : 'NULL';
        $aprovadoPor = $autoActivate ? ':sp' : 'NULL';
        $sql = "INSERT INTO advogado_vinculos
                  (host_account_id, advogado_account_id, status,
                   solicitado_por, aprovado_por, solicitado_em, aprovado_em,
                   created_at, updated_at)
                VALUES
                  (:h, :a, :st, :sp, $aprovadoPor, NOW(), $aprovadoEm, NOW(), NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['h' => $hostId, 'a' => $advogadoId, 'st' => $status, 'sp' => $solicitadoPor]);
        return (int) $pdo->lastInsertId();
    }

    public static function aprovar(int $vinculoId, int $aprovadoPor): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE advogado_vinculos
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
            'UPDATE advogado_vinculos
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
            'UPDATE advogado_vinculos
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
            'UPDATE advogado_vinculos
             SET status = "active",
                 suspenso_em      = NULL,
                 motivo_suspensao = NULL,
                 updated_at       = NOW()
             WHERE id = :id AND status = "suspended"'
        );
        return $stmt->execute(['id' => $vinculoId]) && $stmt->rowCount() > 0;
    }

    /**
     * Atualiza os flags de sincronização.
     * Aceita: sync_enabled, sync_processos, sync_cards, sync_tarefas (todos 0/1)
     */
    public static function updateSync(int $vinculoId, array $flags): bool
    {
        $allowed = ['sync_enabled', 'sync_processos', 'sync_cards', 'sync_tarefas'];
        $sets    = [];
        $params  = ['id' => $vinculoId];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $flags)) {
                $sets[]      = "$k = :$k";
                $params[$k]  = !empty($flags[$k]) ? 1 : 0;
            }
        }
        if (empty($sets)) return false;
        $sets[] = 'sync_updated_at = NOW()';
        $sets[] = 'updated_at      = NOW()';

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE advogado_vinculos SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        return $stmt->execute($params);
    }

    /**
     * IDs dos advogados que esta host puxa sincronizadamente para um módulo.
     * Usado por AccountContext::getAccessibleAccountIds.
     *
     * @param string $module 'cards' | 'processos' | 'tarefas' | '' (todos)
     */
    public static function advogadosAtivosDeHost(int $hostId, string $module = ''): array
    {
        $pdo = Database::getConnection();
        $sql = "SELECT advogado_account_id FROM advogado_vinculos
                WHERE host_account_id = :h
                  AND status = 'active'
                  AND sync_enabled = 1";
        if ($module === 'cards')     $sql .= ' AND sync_cards = 1';
        if ($module === 'processos') $sql .= ' AND sync_processos = 1';
        if ($module === 'tarefas')   $sql .= ' AND sync_tarefas = 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['h' => $hostId]);
        return array_map('intval', array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'advogado_account_id'));
    }

    /**
     * IDs das hosts (matrizes/filiais) que vincularam este advogado e estão
     * com sync ativo. Inverso de advogadosAtivosDeHost.
     */
    public static function hostsAtivasDeAdvogado(int $advogadoId, string $module = ''): array
    {
        $pdo = Database::getConnection();
        $sql = "SELECT host_account_id FROM advogado_vinculos
                WHERE advogado_account_id = :a
                  AND status = 'active'
                  AND sync_enabled = 1";
        if ($module === 'cards')     $sql .= ' AND sync_cards = 1';
        if ($module === 'processos') $sql .= ' AND sync_processos = 1';
        if ($module === 'tarefas')   $sql .= ' AND sync_tarefas = 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['a' => $advogadoId]);
        return array_map('intval', array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'host_account_id'));
    }
}
