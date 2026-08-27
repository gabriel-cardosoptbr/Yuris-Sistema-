<?php
namespace App\Processos;

require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/../Core/Crypto.php';

use App\Core\Database;
use App\Core\Crypto;

/**
 * Model: AaspIntegration
 *
 * Credenciais e estado das integrações com a API de Intimações da AASP.
 *
 * MULTI-TENANT:
 *   • account_id NOT NULL em todos os SELECT/UPDATE/DELETE
 *   • Métodos de listagem/leitura sempre exigem $accountId
 *   • Apenas dueNow() é cross-tenant (uso exclusivo do cron)
 *
 * SEGURANÇA:
 *   • A chave AASP só fica em RAM dentro de getChavePlain()
 *   • Toda outra leitura (find/list) retorna chave_masked, não chave_encrypted
 *   • Auditoria opcional via audit() — gravar sempre que credencial muda
 */
class AaspIntegration
{
    // ─────────────────────────────────────────────────────────────────────────
    // ESCRITA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cria uma nova integração com a chave em texto puro (será cifrada aqui).
     * Retorna o ID criado.
     *
     * @param array  $data       account_id, nome, tipo, advogado_id?, codigos_associados?,
     *                           intervalo_minutos?, created_by?
     * @param string $chavePlain Chave da AASP em texto puro (será cifrada antes de gravar)
     *
     * @throws \RuntimeException em caso de erro de cifragem ou colisão UNIQUE
     */
    public static function create(array $data, string $chavePlain): int
    {
        if (empty($data['account_id'])) {
            throw new \RuntimeException('AaspIntegration::create: account_id obrigatório');
        }
        if (empty($data['nome'])) {
            throw new \RuntimeException('AaspIntegration::create: nome obrigatório');
        }
        if (trim($chavePlain) === '') {
            throw new \RuntimeException('AaspIntegration::create: chave vazia');
        }

        $tipo = ($data['tipo'] ?? 'associado') === 'empresa' ? 'empresa' : 'associado';
        $encrypted = Crypto::encrypt($chavePlain);
        $masked    = Crypto::mask($chavePlain, 4);

        $codigos = null;
        if ($tipo === 'empresa' && !empty($data['codigos_associados'])) {
            $list = is_array($data['codigos_associados']) ? $data['codigos_associados'] : [];
            $list = array_values(array_unique(array_map('intval', array_filter($list))));
            $codigos = $list ? json_encode($list) : null;
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO aasp_integrations
               (account_id, advogado_id, nome, tipo,
                chave_encrypted, chave_masked, codigos_associados,
                status, intervalo_minutos, created_by)
             VALUES
               (:acc, :adv, :nome, :tipo,
                :enc, :mask, :cod,
                :status, :interv, :cb)'
        );
        $stmt->execute([
            'acc'    => (int)$data['account_id'],
            'adv'    => !empty($data['advogado_id']) ? (int)$data['advogado_id'] : null,
            'nome'   => trim((string)$data['nome']),
            'tipo'   => $tipo,
            'enc'    => $encrypted,
            'mask'   => $masked,
            'cod'    => $codigos,
            'status' => $data['status'] ?? 'pending',
            'interv' => max(30, (int)($data['intervalo_minutos'] ?? 120)),
            'cb'     => !empty($data['created_by']) ? (int)$data['created_by'] : null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Atualiza apenas campos seguros (nome, tipo, status, intervalo, codigos_associados,
     * advogado_id). NÃO toca em chave_encrypted — pra trocar use rotateChave().
     */
    public static function update(int $id, int $accountId, array $data): bool
    {
        $allowed = ['nome', 'tipo', 'status', 'intervalo_minutos', 'advogado_id'];
        $sets    = [];
        $params  = ['id' => $id, 'acc' => $accountId];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]        = "{$col} = :{$col}";
                $params[$col]  = $data[$col];
            }
        }
        // codigos_associados precisa de json_encode
        if (array_key_exists('codigos_associados', $data)) {
            $list = is_array($data['codigos_associados']) ? $data['codigos_associados'] : [];
            $list = array_values(array_unique(array_map('intval', array_filter($list))));
            $sets[]                       = 'codigos_associados = :cod';
            $params['cod']                = $list ? json_encode($list) : null;
        }

        if (empty($sets)) return false;

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE aasp_integrations SET ' . implode(', ', $sets) .
            ' WHERE id = :id AND account_id = :acc'
        );
        return $stmt->execute($params);
    }

    /**
     * Troca a chave (rotação). Re-cifra e atualiza masked.
     *
     * @param bool $markActive Se true (chave foi testada OK antes da rotação),
     *                         mantém status='active'. Se false, volta pra 'pending'
     *                         (default seguro — espera próximo sync confirmar).
     */
    public static function rotateChave(int $id, int $accountId, string $novaChavePlain, bool $markActive = false): bool
    {
        if (trim($novaChavePlain) === '') {
            throw new \RuntimeException('AaspIntegration::rotateChave: chave vazia');
        }
        $pdo    = Database::getConnection();
        $status = $markActive ? 'active' : 'pending';
        $stmt   = $pdo->prepare(
            'UPDATE aasp_integrations
                SET chave_encrypted    = :enc,
                    chave_masked       = :mask,
                    status             = :status,
                    ultima_sync_status = NULL,
                    ultima_sync_erro   = NULL,
                    erros_consecutivos = 0
              WHERE id = :id AND account_id = :acc'
        );
        return $stmt->execute([
            'id'     => $id,
            'acc'    => $accountId,
            'enc'    => Crypto::encrypt($novaChavePlain),
            'mask'   => Crypto::mask($novaChavePlain, 4),
            'status' => $status,
        ]);
    }

    public static function delete(int $id, int $accountId): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'DELETE FROM aasp_integrations WHERE id = :id AND account_id = :acc'
        );
        return $stmt->execute(['id' => $id, 'acc' => $accountId]);
    }

    /** Marca sync OK + atualiza contadores e proxima_sync_em. */
    public static function markSyncSuccess(int $id, int $newCount, bool $diferencial = false): void
    {
        $pdo  = Database::getConnection();
        $extras = $diferencial
            ? ', ultimo_diferencial_em = NOW()'
            : '';
        $stmt = $pdo->prepare(
            "UPDATE aasp_integrations
                SET status                = 'active',
                    ultima_sync_em        = NOW(),
                    ultima_sync_status    = 'ok',
                    ultima_sync_erro      = NULL,
                    erros_consecutivos    = 0,
                    total_intimacoes_hoje = total_intimacoes_hoje + :n,
                    total_intimacoes_lifetime = total_intimacoes_lifetime + :n,
                    proxima_sync_em       = DATE_ADD(NOW(), INTERVAL intervalo_minutos MINUTE)
                    {$extras}
              WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'n' => max(0, $newCount)]);
    }

    /** Marca sync com erro + backoff exponencial até 24h. */
    public static function markSyncError(int $id, string $erro): void
    {
        $pdo  = Database::getConnection();
        // Backoff: 2^erros_consecutivos minutos, cap 24h (1440 min)
        $stmt = $pdo->prepare(
            'UPDATE aasp_integrations
                SET status              = "error",
                    ultima_sync_em      = NOW(),
                    ultima_sync_status  = "error",
                    ultima_sync_erro    = :erro,
                    erros_consecutivos  = erros_consecutivos + 1,
                    proxima_sync_em     = DATE_ADD(
                        NOW(),
                        INTERVAL LEAST(1440, intervalo_minutos * POW(2, LEAST(erros_consecutivos, 8))) MINUTE
                    )
              WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'erro' => mb_substr($erro, 0, 500)]);
    }

    /** Zera contador diário de intimações (chamado pelo cron à meia-noite). */
    public static function resetDailyCounter(): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->query('UPDATE aasp_integrations SET total_intimacoes_hoje = 0');
        return $stmt->rowCount();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEITURA — SEM CHAVE EM TEXTO PURO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lê 1 integração para uma conta (sem expor chave_encrypted).
     * Retorna chave_masked + metadados.
     */
    public static function find(int $id, int $accountId): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, account_id, advogado_id, nome, tipo, chave_masked,
                    codigos_associados, status, intervalo_minutos,
                    ultima_sync_em, ultima_sync_status, ultima_sync_erro,
                    proxima_sync_em, total_intimacoes_hoje, total_intimacoes_lifetime,
                    ultimo_diferencial_em, erros_consecutivos,
                    created_by, created_at, updated_at
               FROM aasp_integrations
              WHERE id = :id AND account_id = :acc
              LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'acc' => $accountId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['codigos_associados'] = $row['codigos_associados']
            ? (json_decode($row['codigos_associados'], true) ?: [])
            : [];
        return $row;
    }

    /** Lista todas as integrações da conta (sem chave_encrypted). */
    public static function listForAccount(int $accountId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, account_id, advogado_id, nome, tipo, chave_masked,
                    codigos_associados, status, intervalo_minutos,
                    ultima_sync_em, ultima_sync_status, ultima_sync_erro,
                    proxima_sync_em, total_intimacoes_hoje, total_intimacoes_lifetime,
                    erros_consecutivos, created_by, created_at, updated_at
               FROM aasp_integrations
              WHERE account_id = :acc
              ORDER BY status ASC, nome ASC, id DESC'
        );
        $stmt->execute(['acc' => $accountId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['codigos_associados'] = $r['codigos_associados']
                ? (json_decode($r['codigos_associados'], true) ?: [])
                : [];
        }
        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEITURA — COM CHAVE (USO INTERNO DO PROVIDER)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retorna a chave AASP decifrada em texto puro.
     *
     * USO INTERNO APENAS — chame imediatamente antes do HTTP request, e
     * não atribua a variável de longa duração nem logue.
     */
    public static function getChavePlain(int $id, int $accountId): string
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT chave_encrypted FROM aasp_integrations
              WHERE id = :id AND account_id = :acc
              LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'acc' => $accountId]);
        $enc = $stmt->fetchColumn();
        if (!$enc) {
            throw new \RuntimeException("AaspIntegration#{$id} não encontrada (acc={$accountId})");
        }
        return Crypto::decrypt((string)$enc);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CRON — CROSS-TENANT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lista integrações ativas vencidas, prontas pra sync.
     * USO EXCLUSIVO DO CRON — não filtra account_id (varre todas).
     */
    public static function dueNow(int $limit = 20): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, account_id, advogado_id, nome, tipo,
                    codigos_associados, intervalo_minutos,
                    ultimo_diferencial_em
               FROM aasp_integrations
              WHERE status = "active"
                AND (proxima_sync_em IS NULL OR proxima_sync_em <= NOW())
              ORDER BY COALESCE(ultima_sync_em, "1970-01-01") ASC
              LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['codigos_associados'] = $r['codigos_associados']
                ? (json_decode($r['codigos_associados'], true) ?: [])
                : [];
        }
        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUDITORIA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Registra evento de ciclo de vida da credencial (sem credencial em si).
     * Ações: created | rotated | tested | revoked | error | sync_ok | sync_fail
     */
    public static function audit(
        int $accountId,
        ?int $integrationId,
        string $action,
        ?int $actorUserId = null,
        string $detalhe = ''
    ): void {
        $allowed = ['created','rotated','tested','revoked','error','sync_ok','sync_fail'];
        if (!in_array($action, $allowed, true)) {
            $action = 'error';
            $detalhe = "[ação inválida: {$action}] " . $detalhe;
        }
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'INSERT INTO aasp_credential_audit
                   (account_id, integration_id, action, actor_user_id, ip, user_agent, detalhe)
                 VALUES
                   (:acc, :iid, :act, :uid, :ip, :ua, :det)'
            );
            $stmt->execute([
                'acc' => $accountId,
                'iid' => $integrationId,
                'act' => $action,
                'uid' => $actorUserId,
                'ip'  => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'ua'  => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'det' => mb_substr($detalhe, 0, 500),
            ]);
        } catch (\Throwable $e) {
            // Audit nunca pode quebrar o fluxo principal — apenas loga.
            error_log('[AaspIntegration::audit] ' . $e->getMessage());
        }
    }
}
