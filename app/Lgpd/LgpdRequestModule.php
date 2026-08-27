<?php
namespace App\Lgpd;

/**
 * LgpdRequestModule — registra quais módulos foram pesquisados durante o
 * atendimento a uma solicitação LGPD (Art. 18). Cada busca em uma fonte
 * (users, contatos, processos, whatsapp, etc.) gera 1 linha.
 *
 * Tabela: `lgpd_request_modules` (migration 057). IMUTÁVEL (trigger SQL).
 * Insert-only; histórico auditável.
 */
final class LgpdRequestModule
{
    public const MODULOS = [
        'usuarios', 'contatos', 'leads_cards', 'processos', 'whatsapp',
        'chat_interno', 'financeiro', 'task_attachments', 'logs_auditoria',
        'consentimentos', 'aceites_termos', 'outros',
    ];

    /**
     * Registra uma busca em módulo. Retorna o id criado.
     *
     * @param int $requestId       lgpd_requests.id
     * @param string $modulo       constante de MODULOS
     * @param int $userId          DPO que fez a busca
     * @param int $total           Quantos registros bateram
     * @param string|null $resumo  Texto curto descritivo
     */
    public static function record(
        int $requestId,
        string $modulo,
        int $userId,
        int $total = 0,
        ?string $resumo = null,
        ?string $observacao = null
    ): int {
        if (!in_array($modulo, self::MODULOS, true)) {
            throw new \InvalidArgumentException("Modulo invalido: $modulo");
        }
        $rid = class_exists('App\\Core\\RequestId')
            ? \App\Core\RequestId::get()
            : null;

        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO lgpd_request_modules
               (request_id, modulo, pesquisado_por_user_id,
                resultado_resumo, total_registros, observacao,
                request_id_correlacao)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$requestId, $modulo, $userId, $resumo, $total, $observacao, $rid]);
        return (int)$pdo->lastInsertId();
    }

    /** Lista módulos pesquisados de uma solicitação (cronológico). */
    public static function listByRequest(int $requestId): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            "SELECT m.*, u.nome AS pesquisado_por_nome
             FROM lgpd_request_modules m
             LEFT JOIN users u ON u.id = m.pesquisado_por_user_id
             WHERE m.request_id = ?
             ORDER BY m.pesquisado_em ASC, m.id ASC"
        );
        $st->execute([$requestId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Resumo agregado por módulo (último resultado por módulo). */
    public static function summary(int $requestId): array
    {
        $rows = self::listByRequest($requestId);
        $byModulo = [];
        foreach ($rows as $r) {
            $m = $r['modulo'];
            // Mantém só o registro mais recente por módulo (já vem ordenado ASC, então sobrescreve)
            $byModulo[$m] = [
                'modulo'          => $m,
                'pesquisado_em'   => $r['pesquisado_em'],
                'total_registros' => (int)$r['total_registros'],
                'resumo'          => $r['resultado_resumo'],
                'pesquisado_por'  => $r['pesquisado_por_nome'],
            ];
        }
        return array_values($byModulo);
    }

    /** Quais módulos JÁ foram pesquisados (lista única). */
    public static function pesquisadosByRequest(int $requestId): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            "SELECT DISTINCT modulo FROM lgpd_request_modules WHERE request_id = ?"
        );
        $st->execute([$requestId]);
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }
}
