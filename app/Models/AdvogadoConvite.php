<?php
namespace App\Models;

use App\Models\Database;

/** @deprecated Substituído pelo fluxo resource_shares + codigo_vinculo. Mantido apenas para histórico. */

/**
 * Model: AdvogadoConvite
 *
 * Convite para advogado associado externo (fora da conta) acompanhar um recurso.
 * Padrão: Figma invite link + GitHub repository collaborator invite.
 *
 * Segurança:
 *   - Token: bin2hex(random_bytes(32)) — 256 bits de entropia, criptograficamente seguro
 *   - Nunca usar uniqid(), rand(), ou md5(time())
 *   - Idempotente: segundo convite para mesmo email+recurso retorna o existente (se pending)
 *   - Expiração obrigatória: padrão 30 dias
 */
class AdvogadoConvite
{
    private static array $validTypes = ['card', 'processo', 'contato'];
    private static int   $defaultExpiracaoDias = 30;

    // ─────────────────────────────────────────────────────────────────────────
    // LEITURA
    // ─────────────────────────────────────────────────────────────────────────

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM advogado_convites WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Busca convite pelo token.
     * Usado na rota pública de aceite.
     * NUNCA retorna convite expirado sem checar — verificar status e expires_at no endpoint.
     */
    public static function findByToken(string $token): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT ac.*,
                    a.nome AS from_account_nome
             FROM advogado_convites ac
             LEFT JOIN accounts a ON a.id = ac.from_account_id
             WHERE ac.token_convite = :token
             LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Lista convites de um recurso específico */
    public static function listByResource(string $type, int $resourceId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT ac.*, u.nome AS convidado_user_nome
             FROM advogado_convites ac
             LEFT JOIN users u ON u.id = ac.convidado_user_id
             WHERE ac.resource_type = :type AND ac.resource_id = :rid
             ORDER BY ac.created_at DESC'
        );
        $stmt->execute(['type' => $type, 'rid' => $resourceId]);
        return $stmt->fetchAll();
    }

    /** Lista todos os convites que um e-mail recebeu (histórico do advogado) */
    public static function listByEmail(string $email): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT ac.*, a.nome AS from_account_nome
             FROM advogado_convites ac
             LEFT JOIN accounts a ON a.id = ac.from_account_id
             WHERE ac.convidado_email = :email
             ORDER BY ac.created_at DESC'
        );
        $stmt->execute(['email' => $email]);
        return $stmt->fetchAll();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ESCRITA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cria convite (idempotente).
     * Se já existe convite pending para mesmo email+recurso, retorna o token existente.
     */
    public static function criar(array $data): array
    {
        if (!in_array($data['resource_type'], self::$validTypes)) {
            throw new \InvalidArgumentException('resource_type inválido: ' . $data['resource_type']);
        }

        $pdo = Database::getConnection();

        // Idempotência: verifica convite pendente para o mesmo destino
        $stmt = $pdo->prepare(
            'SELECT * FROM advogado_convites
             WHERE resource_type = :type
               AND resource_id   = :rid
               AND convidado_email = :email
               AND status = "pending"
               AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([
            'type'  => $data['resource_type'],
            'rid'   => $data['resource_id'],
            'email' => $data['convidado_email'],
        ]);
        $existente = $stmt->fetch();
        if ($existente) {
            return ['id' => (int)$existente['id'], 'token' => $existente['token_convite'], 'novo' => false];
        }

        // Gera token seguro
        $token      = bin2hex(random_bytes(32)); // 64 chars, 256 bits
        $expiracoes = $data['expires_em_dias'] ?? self::$defaultExpiracaoDias;
        $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$expiracoes} days"));

        $stmt = $pdo->prepare(
            'INSERT INTO advogado_convites
               (resource_type, resource_id, from_account_id,
                convidado_email, convidado_nome, token_convite,
                permission_level, mensagem, status, expires_at, created_at)
             VALUES
               (:type, :rid, :from,
                :email, :nome, :token,
                :perm, :msg, "pending", :expires, NOW())'
        );
        $stmt->execute([
            'type'   => $data['resource_type'],
            'rid'    => $data['resource_id'],
            'from'   => $data['from_account_id'],
            'email'  => $data['convidado_email'],
            'nome'   => $data['convidado_nome']   ?? null,
            'token'  => $token,
            'perm'   => $data['permission_level'] ?? 'view',
            'msg'    => $data['mensagem']          ?? null,
            'expires'=> $expiresAt,
        ]);

        return ['id' => (int)$pdo->lastInsertId(), 'token' => $token, 'novo' => true];
    }

    /**
     * Aceita o convite.
     * Valida: status=pending, expires_at > NOW().
     * Opcional: vincula ao user_id se o e-mail já tem cadastro.
     */
    public static function aceitar(string $token, int $userId = null): bool
    {
        $convite = self::findByToken($token);

        if (!$convite)                            return false;
        if ($convite['status'] !== 'pending')     return false;
        if (strtotime($convite['expires_at']) < time()) {
            // Marca como expirado e recusa
            self::_marcarExpirado($convite['id']);
            return false;
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE advogado_convites
             SET status = "accepted",
                 convidado_user_id = :uid,
                 responded_at = NOW()
             WHERE id = :id AND status = "pending"'
        );
        return $stmt->execute(['uid' => $userId, 'id' => $convite['id']]) && $stmt->rowCount() > 0;
    }

    public static function rejeitar(string $token): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE advogado_convites
             SET status = "rejected", responded_at = NOW()
             WHERE token_convite = :token AND status = "pending"'
        );
        return $stmt->execute(['token' => $token]) && $stmt->rowCount() > 0;
    }

    public static function revogar(int $id, int $revogadoPor): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE advogado_convites
             SET status = "revoked", revoked_at = NOW(), revoked_by = :rb
             WHERE id = :id AND status IN ("pending","accepted")'
        );
        return $stmt->execute(['rb' => $revogadoPor, 'id' => $id]) && $stmt->rowCount() > 0;
    }

    /** Marca convites expirados (pode ser chamado por cron ou lazy) */
    public static function expirarVencidos(): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE advogado_convites
             SET status = "expired"
             WHERE status = "pending" AND expires_at <= NOW()'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────────────────────────

    private static function _marcarExpirado(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('UPDATE advogado_convites SET status = "expired" WHERE id = :id AND status = "pending"')
            ->execute(['id' => $id]);
    }
}
