<?php
namespace App\Lgpd;

use App\Core\Database;

/**
 * LegalDocument — versionamento de termos/políticas legais (LGPD).
 *
 * Tipos suportados: privacy_policy, terms_of_use, cookies_policy, dpa,
 *                   security_policy, privacy_notice.
 *
 * USO:
 *   $doc = LegalDocument::findActive('privacy_policy');
 *   if ($doc) echo $doc['conteudo_md'];
 *
 *   LegalDocument::publish('terms_of_use', '2026-05-23', 'Termos de Uso', '# ...');
 */
final class LegalDocument
{
    /** Retorna o documento ATIVO + vigente do tipo (ou null). */
    public static function findActive(string $tipo): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT * FROM legal_documents
             WHERE tipo = :tipo
               AND ativo = 1
               AND (vigente_ate IS NULL OR vigente_ate > NOW())
               AND publicado_em IS NOT NULL
               AND publicado_em <= NOW()
             ORDER BY publicado_em DESC LIMIT 1"
        );
        $stmt->execute(['tipo' => $tipo]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Lista todas as versões de um tipo, mais recente primeiro.
     */
    public static function listVersions(string $tipo, int $limit = 20): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT id, tipo, versao, titulo, ativo, publicado_em, vigente_ate, hash_conteudo
             FROM legal_documents
             WHERE tipo = :tipo
             ORDER BY publicado_em DESC LIMIT " . max(1, min(100, $limit))
        );
        $stmt->execute(['tipo' => $tipo]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Publica uma nova versão. Arquiva (vigente_ate=NOW) a anterior do mesmo tipo.
     * Retorna o id criado.
     */
    public static function publish(string $tipo, string $versao, string $titulo,
                                   string $conteudoMd, ?int $createdByUserId = null): int
    {
        $pdo = Database::getConnection();
        $hash = hash('sha256', $conteudoMd);
        $pdo->beginTransaction();
        try {
            // Arquiva versão anterior do mesmo tipo (se houver)
            $pdo->prepare(
                "UPDATE legal_documents SET ativo = 0, vigente_ate = NOW()
                 WHERE tipo = :tipo AND ativo = 1"
            )->execute(['tipo' => $tipo]);

            $pdo->prepare(
                "INSERT INTO legal_documents
                  (tipo, versao, titulo, conteudo_md, hash_conteudo, publicado_em, ativo, criado_por_user_id)
                 VALUES (:tipo, :v, :tit, :md, :hash, NOW(), 1, :uid)"
            )->execute([
                'tipo' => $tipo,
                'v'    => $versao,
                'tit'  => $titulo,
                'md'   => $conteudoMd,
                'hash' => $hash,
                'uid'  => $createdByUserId,
            ]);

            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Busca por id (qualquer estado). */
    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM legal_documents WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
