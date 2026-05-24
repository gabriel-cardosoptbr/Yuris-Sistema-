<?php
namespace App\Models;

/**
 * LgpdRequestAttachment — múltiplos anexos por solicitação LGPD.
 *
 * Tabela: `lgpd_request_attachments` (migration 057).
 * Trigger SQL: UPDATE PARCIAL (apenas `visibilidade` e `descricao`). DELETE bloqueado.
 *
 * Storage: arquivos em `storage/lgpd_requests/<request_id>/<sha256>.<ext>`.
 * Nome original preservado em `nome_arquivo` apenas como label.
 */
final class LgpdRequestAttachment
{
    public const CATEGORIAS = [
        'documento_titular',
        'procuracao',
        'export_dados',
        'comprovante_envio',
        'evidencia_analise',
        'justificativa_juridica',
        'outro',
    ];

    public const VISIBILIDADES = ['interno', 'entregue_titular'];

    /** Diretório raiz para storage de anexos LGPD. */
    public static function storageDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
             . 'storage' . DIRECTORY_SEPARATOR . 'lgpd_requests';
    }

    /**
     * Registra anexo já armazenado em disco.
     *
     * Caller é responsável por:
     *  - calcular SHA-256 do arquivo
     *  - mover o arquivo para o destino final
     *  - validar MIME via finfo_file
     *
     * @param int $requestId
     * @param string $nomeOriginal nome conforme o titular subiu
     * @param string $caminho path relativo a sistema_vendas/storage/
     * @param string $mime
     * @param int $tamanho bytes
     * @param string $sha256
     * @param string $categoria
     * @param int $uploadedBy user_id
     * @param string|null $descricao
     * @param string $visibilidade
     * @return int id criado
     */
    public static function record(
        int $requestId,
        string $nomeOriginal,
        string $caminho,
        string $mime,
        int $tamanho,
        string $sha256,
        string $categoria,
        int $uploadedBy,
        ?string $descricao = null,
        string $visibilidade = 'interno'
    ): int {
        if (!in_array($categoria, self::CATEGORIAS, true)) {
            throw new \InvalidArgumentException("categoria invalida: $categoria");
        }
        if (!in_array($visibilidade, self::VISIBILIDADES, true)) {
            $visibilidade = 'interno';
        }
        if (strlen($sha256) !== 64) {
            throw new \InvalidArgumentException('sha256 deve ter 64 chars hex');
        }

        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO lgpd_request_attachments
               (request_id, nome_arquivo, caminho_arquivo, tipo_arquivo,
                tamanho, sha256, categoria, visibilidade, descricao,
                uploaded_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $requestId, $nomeOriginal, $caminho, $mime,
            $tamanho, $sha256, $categoria, $visibilidade, $descricao,
            $uploadedBy,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** Lista anexos de uma solicitação. Filtro opcional de visibilidade. */
    public static function listByRequest(int $requestId, ?string $visibilidade = null): array
    {
        $pdo = Database::getConnection();
        $sql = "SELECT a.*, u.nome AS uploaded_by_nome
                FROM lgpd_request_attachments a
                LEFT JOIN users u ON u.id = a.uploaded_by_user_id
                WHERE a.request_id = ?";
        $params = [$requestId];
        if ($visibilidade !== null) {
            $sql .= " AND a.visibilidade = ?";
            $params[] = $visibilidade;
        }
        $sql .= " ORDER BY a.created_at ASC, a.id ASC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Busca por id. */
    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT * FROM lgpd_request_attachments WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Atualiza campos PERMITIDOS pelo trigger (visibilidade + descricao).
     * @return bool sucesso
     */
    public static function updateMeta(int $id, ?string $visibilidade = null, ?string $descricao = null): bool
    {
        $fields = []; $params = ['id' => $id];
        if ($visibilidade !== null) {
            if (!in_array($visibilidade, self::VISIBILIDADES, true)) {
                throw new \InvalidArgumentException("visibilidade invalida: $visibilidade");
            }
            $fields[] = 'visibilidade = :v';
            $params['v'] = $visibilidade;
        }
        if ($descricao !== null) {
            $fields[] = 'descricao = :d';
            $params['d'] = $descricao;
        }
        if (empty($fields)) return false;

        $sql = 'UPDATE lgpd_request_attachments SET ' . implode(', ', $fields) . ' WHERE id = :id';
        return Database::getConnection()->prepare($sql)->execute($params);
    }

    /**
     * Garante que o diretório do request existe e está protegido.
     * Retorna o path absoluto do diretório.
     */
    public static function ensureRequestDir(int $requestId): string
    {
        $dir = self::storageDir() . DIRECTORY_SEPARATOR . $requestId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        // .htaccess protegendo (defesa em profundidade — storage/ já é bloqueado)
        $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "Require all denied\n");
        }
        return $dir;
    }
}
