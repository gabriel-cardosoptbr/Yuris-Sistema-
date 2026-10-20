<?php
namespace App\Prospeccao;

use App\Core\AccountContext;
use App\Core\Database;

/**
 * O lead em mais de uma etapa e em mais de um setor, e o que o card dele mostra
 * além do cadastro (edição CRM, 20/10/2026, migration 156).
 *
 *   etapas   a principal é cards.coluna_id (onde o card é arrastado e o que o
 *            funil e o painel contam); as outras ficam em crm_card_etapas_extras
 *            e o card aparece também nelas, como cópia.
 *   setores  o principal é cards.team_id (o mesmo do Chat, migration 140); os
 *            outros ficam em crm_card_setores_extras. Lead sem setor ganha como
 *            principal o primeiro marcado.
 *   quadro   por lead: etapas e setores a mais, orçamentos (quantos, o último,
 *            o valor aprovado), contratos (quantos, assinados, aguardando) e o
 *            último contato registrado na ficha.
 *
 * Toda consulta filtra account_id; etapa ou setor de outra conta é recusado.
 */
final class QuadroDoLead
{
    /** @return array<int, array{id:int, nome:string}> */
    public static function etapasDaConta(int $accountId): array
    {
        $st = Database::getConnection()->prepare('SELECT id, nome FROM pipeline_columns WHERE account_id = ? ORDER BY ordem, id');
        $st->execute([$accountId]);
        return array_map(fn($c) => ['id' => (int) $c['id'], 'nome' => $c['nome']], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<int, array{id:int, nome:string, cor:string}> */
    public static function setoresDaConta(int $accountId): array
    {
        $st = Database::getConnection()->prepare('SELECT id, nome, cor FROM teams WHERE account_id = ? AND deleted_at IS NULL ORDER BY nome');
        $st->execute([$accountId]);
        return array_map(fn($t) => ['id' => (int) $t['id'], 'nome' => $t['nome'], 'cor' => $t['cor'] ?: '#3B82F6'], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    private static function lead(int $accountId, int $cardId): array
    {
        $st = Database::getConnection()->prepare('SELECT id, coluna_id, team_id FROM cards WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
        $st->execute([$cardId, $accountId]);
        $c = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$c) throw new \InvalidArgumentException('Lead não encontrado.');
        return $c;
    }

    /** @return array{etapas: array{principal:?int, extras:int[]}, setores: array{principal:?int, extras:int[]}} */
    public static function doLead(int $accountId, int $cardId): array
    {
        $c = self::lead($accountId, $cardId);
        $pdo = Database::getConnection();
        $e = $pdo->prepare('SELECT x.coluna_id FROM crm_card_etapas_extras x JOIN pipeline_columns p ON p.id = x.coluna_id AND p.account_id = x.account_id
                             WHERE x.account_id = ? AND x.card_id = ? ORDER BY p.ordem, p.id');
        $e->execute([$accountId, $cardId]);
        $s = $pdo->prepare('SELECT x.team_id FROM crm_card_setores_extras x JOIN teams t ON t.id = x.team_id AND t.account_id = x.account_id AND t.deleted_at IS NULL
                             WHERE x.account_id = ? AND x.card_id = ? ORDER BY t.nome');
        $s->execute([$accountId, $cardId]);
        $col = $c['coluna_id'] !== null ? (int) $c['coluna_id'] : null;
        $team = $c['team_id'] !== null ? (int) $c['team_id'] : null;
        // Área do serviço (ambientes da Inovaize): o que o lead quer comprar.
        $area = null;
        try {
            $a = $pdo->prepare('SELECT area_servico FROM cards WHERE id = ? AND account_id = ?');
            $a->execute([$cardId, $accountId]);
            $area = $a->fetchColumn() ?: null;
        } catch (\Throwable $e2) { $area = null; }
        return [
            'area_servico' => $area,
            'etapas' => ['principal' => $col, 'extras' => array_values(array_filter(array_map('intval', $e->fetchAll(\PDO::FETCH_COLUMN)), fn($x) => $x !== $col))],
            'setores' => ['principal' => $team, 'extras' => array_values(array_filter(array_map('intval', $s->fetchAll(\PDO::FETCH_COLUMN)), fn($x) => $x !== $team))],
        ];
    }

    /** Troca as etapas a mais. A principal não entra (muda arrastando o card). @param int[] $ids */
    public static function salvarEtapas(int $accountId, int $userId, int $cardId, array $ids): array
    {
        $c = self::lead($accountId, $cardId);
        $validos = array_column(self::etapasDaConta($accountId), 'id');
        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), [(int) $c['coluna_id']]));
        foreach ($ids as $i) if (!in_array($i, $validos, true)) throw new \InvalidArgumentException('Etapa não encontrada nesta conta.');
        self::trocar('crm_card_etapas_extras', 'coluna_id', $accountId, $userId, $cardId, $ids);
        return self::doLead($accountId, $cardId);
    }

    /**
     * Troca os setores do lead pelo conjunto marcado. O principal (cards.team_id)
     * fica; lead sem setor ganha o primeiro marcado como principal. @param int[] $ids
     */
    public static function salvarSetores(int $accountId, int $userId, int $cardId, array $ids): array
    {
        $c = self::lead($accountId, $cardId);
        $validos = array_column(self::setoresDaConta($accountId), 'id');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach ($ids as $i) if (!in_array($i, $validos, true)) throw new \InvalidArgumentException('Setor não encontrado nesta conta.');
        $principal = $c['team_id'] !== null ? (int) $c['team_id'] : null;
        if ($principal === null && $ids) {
            $principal = $ids[0];
            Database::getConnection()->prepare('UPDATE cards SET team_id = ? WHERE id = ? AND account_id = ? AND team_id IS NULL')->execute([$principal, $cardId, $accountId]);
        }
        self::trocar('crm_card_setores_extras', 'team_id', $accountId, $userId, $cardId, array_values(array_diff($ids, [$principal])));
        return self::doLead($accountId, $cardId);
    }

    private static function trocar(string $tabela, string $coluna, int $accountId, int $userId, int $cardId, array $ids): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $del = "DELETE FROM $tabela WHERE account_id = ? AND card_id = ?";
            $args = [$accountId, $cardId];
            if ($ids) { $del .= " AND $coluna NOT IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'; array_push($args, ...$ids); }
            $pdo->prepare($del)->execute($args);
            $ins = $pdo->prepare("INSERT IGNORE INTO $tabela (account_id, card_id, $coluna, criado_por) VALUES (?, ?, ?, ?)");
            foreach ($ids as $i) $ins->execute([$accountId, $cardId, $i, $userId ?: null]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * O que o card de cada lead mostra além do cadastro, de uma vez para o quadro.
     * @param int[] $accountIds contas que a sessão vê na prospecção
     * @return array{leads: array<int, array>, etapas: array, setores: array}
     */
    public static function quadro(array $accountIds, int $contaAtual): array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        $vazio = ['leads' => [], 'etapas' => self::etapasDaConta($contaAtual), 'setores' => self::setoresDaConta($contaAtual)];
        if (!$accountIds) return $vazio;
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $pdo = Database::getConnection();
        $out = [];
        $novo = fn() => ['setor' => null, 'setores_extras' => [], 'etapas_extras' => [],
            'orcamentos' => ['total' => 0, 'ultimo_status' => null, 'aprovado' => 0.0], 'contratos' => ['total' => 0, 'assinados' => 0, 'aguardando' => 0],
            'ultimo_contato' => null];

        // Setor principal e o básico para a cópia do card noutra etapa.
        $st = $pdo->prepare("SELECT c.id, c.team_id, c.cliente_nome, c.empresa_nome, c.cidade, c.uf,
                                    COALESCE(NULLIF(c.valor_fechado_final,0), NULLIF(c.valor_proposta,0), IFNULL(c.valor_estimado,0)) AS valor,
                                    u.nome AS responsavel
                               FROM cards c LEFT JOIN users u ON u.id = c.responsavel_user_id
                              WHERE c.account_id IN ($in) AND c.deleted_at IS NULL
                                AND (c.team_id IS NOT NULL
                                     OR EXISTS (SELECT 1 FROM crm_card_etapas_extras x WHERE x.card_id = c.id AND x.account_id = c.account_id)
                                     OR EXISTS (SELECT 1 FROM crm_card_setores_extras y WHERE y.card_id = c.id AND y.account_id = c.account_id))");
        $st->execute($accountIds);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            $out[$id] = $novo();
            $out[$id]['setor'] = $r['team_id'] !== null ? (int) $r['team_id'] : null;
            $out[$id]['nome'] = $r['cliente_nome'] ?: ($r['empresa_nome'] ?: 'Lead sem nome');
            $out[$id]['empresa'] = $r['empresa_nome'] ?: null;
            $out[$id]['local'] = trim(($r['cidade'] ?: '') . ($r['uf'] ? ' · ' . $r['uf'] : ''), ' ·') ?: null;
            $out[$id]['valor'] = (float) $r['valor'];
            $out[$id]['responsavel'] = $r['responsavel'] ?: null;
        }
        $x = $pdo->prepare("SELECT x.card_id, x.coluna_id FROM crm_card_etapas_extras x
                              JOIN cards c ON c.id = x.card_id AND c.account_id = x.account_id AND c.deleted_at IS NULL
                              JOIN pipeline_columns p ON p.id = x.coluna_id AND p.account_id = x.account_id
                             WHERE x.account_id IN ($in) AND (c.coluna_id IS NULL OR c.coluna_id <> x.coluna_id) ORDER BY p.ordem, p.id");
        $x->execute($accountIds);
        foreach ($x->fetchAll(\PDO::FETCH_ASSOC) as $r) if (isset($out[(int) $r['card_id']])) $out[(int) $r['card_id']]['etapas_extras'][] = (int) $r['coluna_id'];
        $y = $pdo->prepare("SELECT y.card_id, y.team_id FROM crm_card_setores_extras y
                              JOIN teams t ON t.id = y.team_id AND t.account_id = y.account_id AND t.deleted_at IS NULL
                             WHERE y.account_id IN ($in) ORDER BY t.nome");
        $y->execute($accountIds);
        foreach ($y->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['card_id'];
            if (isset($out[$id]) && (int) $r['team_id'] !== $out[$id]['setor']) $out[$id]['setores_extras'][] = (int) $r['team_id'];
        }

        // Orçamentos: quantos, o último e o valor aprovado.
        try {
            $o = $pdo->prepare("SELECT o.card_id, o.status, (SELECT COALESCE(SUM(ROUND(i.quantidade * i.valor_unitario - i.desconto, 2)), 0)
                                   FROM crm_orcamento_itens i WHERE i.orcamento_id = o.id AND i.account_id = o.account_id) AS total
                                  FROM crm_orcamentos o WHERE o.account_id IN ($in) AND o.card_id IS NOT NULL ORDER BY o.created_at, o.id");
            $o->execute($accountIds);
            foreach ($o->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $id = (int) $r['card_id'];
                $out[$id] ??= $novo();
                $out[$id]['orcamentos']['total']++;
                $out[$id]['orcamentos']['ultimo_status'] = $r['status'];
                if (in_array($r['status'], ['APROVADO', 'CONVERTIDO'], true)) $out[$id]['orcamentos']['aprovado'] += (float) $r['total'];
            }
        } catch (\Throwable $e) { /* sem orçamentos */ }

        // Contratos. O vendedor não vê contrato (sigiloso, só administração).
        if (AccountContext::vendedorDaSessao() === null) try {
            $k = $pdo->prepare("SELECT card_id, status, COUNT(*) AS n FROM crm_contratos
                                 WHERE account_id IN ($in) AND card_id IS NOT NULL AND status <> 'cancelado' GROUP BY card_id, status");
            $k->execute($accountIds);
            foreach ($k->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $id = (int) $r['card_id'];
                $out[$id] ??= $novo();
                $out[$id]['contratos']['total'] += (int) $r['n'];
                if ($r['status'] === 'concluido') $out[$id]['contratos']['assinados'] += (int) $r['n'];
                if (in_array($r['status'], ['aguardando', 'parcial'], true)) $out[$id]['contratos']['aguardando'] += (int) $r['n'];
            }
        } catch (\Throwable $e) { /* sem contratos */ }

        // Último contato registrado na ficha.
        try {
            $it = $pdo->prepare("SELECT entidade_id, MAX(ocorrido_em) AS quando FROM crm_interacoes
                                  WHERE account_id IN ($in) AND entidade = 'card' AND deleted_at IS NULL GROUP BY entidade_id");
            $it->execute($accountIds);
            foreach ($it->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $id = (int) $r['entidade_id'];
                $out[$id] ??= $novo();
                $out[$id]['ultimo_contato'] = $r['quando'];
            }
        } catch (\Throwable $e) { /* sem interações */ }

        foreach ($out as &$l) $l['orcamentos']['aprovado'] = round($l['orcamentos']['aprovado'], 2);
        unset($l);
        return ['leads' => $out] + $vazio;
    }
}
