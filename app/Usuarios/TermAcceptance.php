<?php
namespace App\Usuarios;

/**
 * TermAcceptance — registro de aceite de documento legal pelo titular (LGPD Art. 8º).
 *
 * Cada linha = 1 aceite específico (user + doc específico + timestamp).
 * Permite auditar: "quem aceitou termos versão 2026-05-23, quando, de qual IP".
 */
final class TermAcceptance
{
    /**
     * Registra aceite. Pode ser de user logado (user_id + account_id) ou
     * anônimo (titular_email, ex: banner de cookies pré-signup).
     *
     * @return int id da linha criada
     */
    public static function record(array $data): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO term_acceptances
              (legal_document_id, user_id, account_id, titular_email, ip, user_agent, contexto, accepted_at)
             VALUES
              (:doc, :uid, :acc, :email, :ip, :ua, :ctx, NOW())"
        );
        $stmt->execute([
            'doc'   => $data['legal_document_id'],
            'uid'   => $data['user_id']        ?? null,
            'acc'   => $data['account_id']     ?? null,
            'email' => $data['titular_email']  ?? null,
            'ip'    => $data['ip']             ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'ua'    => $data['user_agent']     ?? substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'ctx'   => $data['contexto']       ?? 'unknown',
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Verifica se um user aceitou a VERSÃO VIGENTE de um tipo de documento.
     * Retorna a linha do aceite mais recente, ou null.
     */
    public static function findLatest(int $userId, string $tipo): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT ta.*, ld.versao, ld.hash_conteudo
             FROM term_acceptances ta
             INNER JOIN legal_documents ld ON ld.id = ta.legal_document_id
             WHERE ta.user_id = :uid AND ld.tipo = :tipo
             ORDER BY ta.accepted_at DESC LIMIT 1"
        );
        $stmt->execute(['uid' => $userId, 'tipo' => $tipo]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Diz se o user aceitou a versão MAIS RECENTE do tipo. Útil para forçar
     * "reaceite" quando termos são atualizados.
     */
    public static function hasAcceptedLatest(int $userId, string $tipo): bool
    {
        $latestDoc = LegalDocument::findActive($tipo);
        if (!$latestDoc) return true; // sem doc publicado = nada a aceitar

        $latestAcc = self::findLatest($userId, $tipo);
        if (!$latestAcc) return false;

        return (int)$latestAcc['legal_document_id'] === (int)$latestDoc['id'];
    }
}
