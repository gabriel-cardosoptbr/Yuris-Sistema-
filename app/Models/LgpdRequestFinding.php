<?php
namespace App\Models;

use App\Helpers\PIIMasker;

/**
 * LgpdRequestFinding — cada dado pessoal encontrado em uma busca LGPD.
 *
 * Tabela: `lgpd_request_findings` (migration 057). Trigger SQL permite
 * UPDATE apenas em: revisado_em, revisado_por_user_id, incluido_no_export,
 * pode_excluir, motivo_retencao. DELETE bloqueado totalmente.
 *
 * Princípio: valor cru NUNCA armazenado — sempre versão mascarada + hash
 * para comparação posterior.
 */
final class LgpdRequestFinding
{
    public const TIPOS_DADO = [
        'pii_basica',          // nome, e-mail, telefone, endereço
        'documento',           // CPF, RG, OAB, CNH
        'juridico_processual', // número CNJ, parte, peça
        'financeiro',          // dados bancários, valores
        'autenticacao',        // hash de senha, MFA secret
        'comunicacao',         // conteúdo de mensagem/chat
        'sensivel_lgpd',       // Art. 5 II
        'metadado',            // log, timestamp, IP
        'outro',
    ];

    public const BASES_LEGAIS = [
        'consentimento','contrato','obrigacao_legal','exercicio_direito',
        'interesse_legitimo','tutela_saude','protecao_credito','interesse_publico',
    ];

    /**
     * Registra um achado. Mascara o valor automaticamente.
     *
     * @param int $requestId    lgpd_requests.id
     * @param string $modulo    constante de LgpdRequestModule::MODULOS
     * @param string $entidade  nome da tabela (users, contatos, etc)
     * @param int $entidadeId   ID na tabela
     * @param string $tipoDado  constante de TIPOS_DADO
     * @param string $valorCru  valor original — será mascarado + hash, NÃO armazenado em claro
     * @param string|null $campo
     * @param int|null $accountId tenant onde foi achado
     * @param string|null $baseLegal
     * @param string|null $finalidade
     * @return int id criado
     */
    public static function record(
        int $requestId,
        string $modulo,
        string $entidade,
        int $entidadeId,
        string $tipoDado,
        string $valorCru,
        ?string $campo = null,
        ?int $accountId = null,
        ?string $baseLegal = null,
        ?string $finalidade = null
    ): int {
        if (!in_array($tipoDado, self::TIPOS_DADO, true)) {
            throw new \InvalidArgumentException("tipo_dado invalido: $tipoDado");
        }
        if ($baseLegal !== null && !in_array($baseLegal, self::BASES_LEGAIS, true)) {
            $baseLegal = null;
        }

        // Mascaramento automático
        $valorMascarado = $campo !== null
            ? PIIMasker::auto($valorCru, $campo)
            : PIIMasker::genericMask($valorCru);
        $hash = PIIMasker::hashForLookup($valorCru, $campo ?? 'generic');

        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO lgpd_request_findings
               (request_id, modulo, entidade, entidade_id, account_id,
                tipo_dado, campo, valor_mascarado, hash_valor,
                base_legal, finalidade)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $requestId, $modulo, $entidade, $entidadeId, $accountId,
            $tipoDado, $campo, $valorMascarado, $hash,
            $baseLegal, $finalidade,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** Lista findings de uma solicitação (cronológico). */
    public static function listByRequest(int $requestId, ?string $modulo = null): array
    {
        $pdo = Database::getConnection();
        $sql = "SELECT f.*, u.nome AS revisado_por_nome
                FROM lgpd_request_findings f
                LEFT JOIN users u ON u.id = f.revisado_por_user_id
                WHERE f.request_id = ?";
        $params = [$requestId];
        if ($modulo !== null) {
            $sql .= " AND f.modulo = ?";
            $params[] = $modulo;
        }
        $sql .= " ORDER BY f.modulo ASC, f.entidade ASC, f.id ASC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza campos de REVISÃO (única operação permitida pelo trigger).
     * @return bool sucesso
     */
    public static function review(
        int $findingId,
        int $byUserId,
        ?bool $incluirNoExport = null,
        ?bool $podeExcluir = null,
        ?string $motivoRetencao = null
    ): bool {
        $fields = ['revisado_em = NOW()', 'revisado_por_user_id = :uid'];
        $params = ['uid' => $byUserId, 'id' => $findingId];

        if ($incluirNoExport !== null) {
            $fields[] = 'incluido_no_export = :ie';
            $params['ie'] = $incluirNoExport ? 1 : 0;
        }
        if ($podeExcluir !== null) {
            $fields[] = 'pode_excluir = :pe';
            $params['pe'] = $podeExcluir ? 1 : 0;
        }
        if ($motivoRetencao !== null) {
            $fields[] = 'motivo_retencao = :mr';
            $params['mr'] = $motivoRetencao;
        }

        $sql = 'UPDATE lgpd_request_findings SET ' . implode(', ', $fields) . ' WHERE id = :id';
        return Database::getConnection()->prepare($sql)->execute($params);
    }

    /**
     * Contagens para badge do drawer.
     * Retorna: total, revisados, pendentes_revisao, no_export, retencao_marcada.
     */
    public static function countsByRequest(int $requestId): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            "SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN revisado_em IS NOT NULL THEN 1 ELSE 0 END) AS revisados,
               SUM(CASE WHEN revisado_em IS NULL THEN 1 ELSE 0 END) AS pendentes_revisao,
               SUM(CASE WHEN incluido_no_export = 1 THEN 1 ELSE 0 END) AS no_export,
               SUM(CASE WHEN pode_excluir = 0 THEN 1 ELSE 0 END) AS retencao_marcada
             FROM lgpd_request_findings
             WHERE request_id = ?"
        );
        $st->execute([$requestId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        return array_map('intval', $r);
    }

    /** Busca findings por hash (sem expor valor cru). Útil para detectar duplicatas. */
    public static function findByHash(string $hash, ?int $requestId = null): array
    {
        $pdo = Database::getConnection();
        $sql = "SELECT * FROM lgpd_request_findings WHERE hash_valor = ?";
        $params = [$hash];
        if ($requestId !== null) {
            $sql .= " AND request_id = ?";
            $params[] = $requestId;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
}
