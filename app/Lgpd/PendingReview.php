<?php
namespace App\Lgpd;

/**
 * PendingReview — itens pendentes de revisão/conclusão antes do go-live.
 *
 * Uso EXCLUSIVO interno (super_admin/DPO via Painel Master).
 * Substitui o disclaimer público "(modelo inicial — pendente revisão jurídica)"
 * que era visível nas páginas legais — agora isso fica em backoffice.
 *
 * Itens vêm seedados via migration 056. DPO marca como concluído ou dispensado
 * conforme avança. Painel mostra badge com pendentes que bloqueiam produção.
 */
final class PendingReview
{
    public const CATEGORIAS = [
        'documento_legal',
        'operador_dpa',
        'configuracao_env',
        'seguranca_tecnica',
        'treinamento',
        'auditoria_externa',
        'designacao_dpo',
        'outro',
    ];

    public const PRIORIDADES = ['baixa', 'media', 'alta', 'critica'];

    public const STATUSES = [
        'pendente', 'em_revisao', 'concluido', 'dispensado', 'bloqueado',
    ];

    /** Busca por id. */
    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT * FROM pending_reviews WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Lista com filtros opcionais.
     * Filtros: status, categoria, prioridade, responsavel, bloqueia, q (busca em titulo)
     */
    public static function listAll(array $f = []): array
    {
        $pdo = Database::getConnection();
        $w = [];
        $p = [];

        // IMPORTANTE: prefixo "pr." obrigatorio — tabela users (JOIN) tambem
        // tem coluna "status", causa "Column 'status' in where clause is ambiguous"
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $w[] = 'pr.status = :st';
            $p['st'] = $f['status'];
        }
        if (!empty($f['categoria']) && in_array($f['categoria'], self::CATEGORIAS, true)) {
            $w[] = 'pr.categoria = :cat';
            $p['cat'] = $f['categoria'];
        }
        if (!empty($f['prioridade']) && in_array($f['prioridade'], self::PRIORIDADES, true)) {
            $w[] = 'pr.prioridade = :pr';
            $p['pr'] = $f['prioridade'];
        }
        if (!empty($f['responsavel'])) {
            $w[] = 'pr.responsavel = :resp';
            $p['resp'] = $f['responsavel'];
        }
        if (isset($f['bloqueia']) && $f['bloqueia'] !== '') {
            $w[] = 'pr.bloqueia_producao = :bl';
            $p['bl'] = !empty($f['bloqueia']) ? 1 : 0;
        }
        if (!empty($f['q'])) {
            $w[] = '(pr.titulo LIKE :q OR pr.descricao LIKE :q)';
            $p['q'] = '%' . $f['q'] . '%';
        }

        $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
        $sql = "SELECT pr.*, u.nome AS concluido_por_nome
                FROM pending_reviews pr
                LEFT JOIN users u ON u.id = pr.concluido_por_user_id
                $where
                ORDER BY
                  CASE pr.status WHEN 'pendente' THEN 0
                                 WHEN 'em_revisao' THEN 1
                                 WHEN 'bloqueado' THEN 2
                                 WHEN 'dispensado' THEN 3
                                 ELSE 4 END,
                  CASE pr.prioridade WHEN 'critica' THEN 0
                                     WHEN 'alta' THEN 1
                                     WHEN 'media' THEN 2
                                     WHEN 'baixa' THEN 3 END,
                  pr.prazo ASC,
                  pr.titulo ASC";
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza campos permitidos (status, prioridade, notas_internas, prazo, responsavel).
     * Quando vira concluido/dispensado, marca concluido_em + concluido_por_user_id.
     */
    public static function update(int $id, array $data, ?int $byUserId = null): bool
    {
        $pdo = Database::getConnection();
        $old = self::findById($id);
        if (!$old) return false;

        $allowed = [
            'status', 'prioridade', 'notas_internas', 'prazo', 'responsavel',
            'bloqueia_producao', 'descricao',
        ];
        $fields = []; $params = ['id' => $id];

        foreach ($allowed as $f) {
            if (!array_key_exists($f, $data)) continue;
            $v = $data[$f];

            if ($f === 'status'     && !in_array($v, self::STATUSES, true))    continue;
            if ($f === 'prioridade' && !in_array($v, self::PRIORIDADES, true)) continue;
            if ($f === 'bloqueia_producao') $v = !empty($v) ? 1 : 0;
            if ($f === 'prazo' && $v === '') $v = null;

            $fields[]    = "$f = :$f";
            $params[$f]  = $v;
        }

        // Auto-marca concluido_em / concluido_por_user_id na transição
        if (isset($data['status'])) {
            $finalStatuses = ['concluido', 'dispensado'];
            $virouFinal    = in_array($data['status'], $finalStatuses, true)
                          && !in_array($old['status'], $finalStatuses, true);
            $deixouFinal   = !in_array($data['status'], $finalStatuses, true)
                          && in_array($old['status'], $finalStatuses, true);

            if ($virouFinal) {
                $fields[] = 'concluido_em = NOW()';
                $fields[] = 'concluido_por_user_id = :cpu';
                $params['cpu'] = $byUserId;
            } elseif ($deixouFinal) {
                // Reabriu (voltou pra pendente/em_revisao) — limpa data de conclusão
                $fields[] = 'concluido_em = NULL';
                $fields[] = 'concluido_por_user_id = NULL';
            }
        }

        if (empty($fields)) return false;
        $sql = 'UPDATE pending_reviews SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);
        return true;
    }

    /**
     * Contagens p/ badge no Painel Master:
     *   total, pendentes, em_revisao, concluidos, bloqueadores (status != concluido/dispensado
     *   E bloqueia_producao = 1), criticos_abertos
     */
    public static function counts(): array
    {
        $pdo = Database::getConnection();
        $row = $pdo->query(
            "SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN status = 'pendente'   THEN 1 ELSE 0 END) AS pendentes,
               SUM(CASE WHEN status = 'em_revisao' THEN 1 ELSE 0 END) AS em_revisao,
               SUM(CASE WHEN status = 'concluido'  THEN 1 ELSE 0 END) AS concluidos,
               SUM(CASE WHEN status = 'dispensado' THEN 1 ELSE 0 END) AS dispensados,
               SUM(CASE WHEN status = 'bloqueado'  THEN 1 ELSE 0 END) AS bloqueados,
               SUM(CASE WHEN status NOT IN ('concluido','dispensado')
                          AND bloqueia_producao = 1 THEN 1 ELSE 0 END) AS bloqueadores_golive,
               SUM(CASE WHEN status NOT IN ('concluido','dispensado')
                          AND prioridade = 'critica' THEN 1 ELSE 0 END) AS criticos_abertos
             FROM pending_reviews"
        )->fetch(\PDO::FETCH_ASSOC);
        return array_map('intval', $row ?: []);
    }
}
