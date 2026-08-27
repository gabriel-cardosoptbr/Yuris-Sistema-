<?php
namespace App\Lgpd;

use App\Core\Database;

/**
 * LgpdRequestRetentionJustification — justificativa estruturada quando um
 * dado NÃO pode ser excluído/anonimizado em resposta a solicitação LGPD.
 *
 * Tabela: `lgpd_request_retention_justifications` (migration 057).
 * Trigger SQL: UPDATE PARCIAL apenas em aprovado_por_user_id + aprovado_em
 * (fluxo 4-eyes). DELETE bloqueado totalmente.
 *
 * Cada entrada representa: "o dado X da entidade Y do request Z não pode ser
 * excluído porque [base_legal_retencao]: [justificativa]".
 */
final class LgpdRequestRetentionJustification
{
    public const BASES_LEGAIS = [
        'obrigacao_legal',
        'exercicio_direitos_processo',
        'cumprimento_contrato',
        'dados_terceiros',
        'sigilo_profissional',
        'seguranca_sistema',
        'auditoria_obrigatoria',
        'anonimizacao_inviavel',
        'outro',
    ];

    /**
     * Cria justificativa.
     * @return int id criado
     */
    public static function create(
        int $requestId,
        string $entidade,
        int $entidadeId,
        string $baseLegal,
        string $justificativa,
        int $responsavelUserId,
        ?int $findingId = null,
        ?string $prazoAte = null,
        ?string $fundamentacao = null
    ): int {
        if (!in_array($baseLegal, self::BASES_LEGAIS, true)) {
            throw new \InvalidArgumentException("base_legal_retencao invalida: $baseLegal");
        }
        if (trim($justificativa) === '') {
            throw new \InvalidArgumentException('justificativa obrigatoria');
        }

        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO lgpd_request_retention_justifications
               (request_id, entidade, entidade_id, finding_id,
                base_legal_retencao, prazo_retencao_ate,
                justificativa, fundamentacao_juridica,
                responsavel_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $requestId, $entidade, $entidadeId, $findingId,
            $baseLegal, $prazoAte,
            $justificativa, $fundamentacao,
            $responsavelUserId,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** Lista justificativas de uma solicitação. */
    public static function listByRequest(int $requestId): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            "SELECT j.*, u.nome AS responsavel_nome, a.nome AS aprovado_por_nome
             FROM lgpd_request_retention_justifications j
             LEFT JOIN users u ON u.id = j.responsavel_user_id
             LEFT JOIN users a ON a.id = j.aprovado_por_user_id
             WHERE j.request_id = ?
             ORDER BY j.created_at ASC, j.id ASC"
        );
        $st->execute([$requestId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Aprovação 4-eyes: outro user revisa e aprova a justificativa.
     * Quem criou NÃO pode aprovar.
     */
    public static function approve(int $id, int $byUserId): bool
    {
        $pdo = Database::getConnection();
        // Bloqueia auto-aprovação
        $st = $pdo->prepare("SELECT responsavel_user_id FROM lgpd_request_retention_justifications WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return false;
        if ((int)$row['responsavel_user_id'] === $byUserId) {
            throw new \LogicException('Auto-aprovacao nao permitida (4-eyes principle)');
        }

        $up = $pdo->prepare(
            "UPDATE lgpd_request_retention_justifications
             SET aprovado_por_user_id = :u, aprovado_em = NOW()
             WHERE id = :id"
        );
        return $up->execute(['u' => $byUserId, 'id' => $id]);
    }

    /** Conta retenções por base legal (para dashboard/relatório). */
    public static function countsByRequest(int $requestId): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            "SELECT base_legal_retencao, COUNT(*) AS qtd,
                    SUM(CASE WHEN aprovado_em IS NOT NULL THEN 1 ELSE 0 END) AS aprovados
             FROM lgpd_request_retention_justifications
             WHERE request_id = ?
             GROUP BY base_legal_retencao"
        );
        $st->execute([$requestId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
}
