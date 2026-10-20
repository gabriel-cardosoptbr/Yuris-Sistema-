<?php
namespace App\Clientes;

use App\Core\Database;

/**
 * Setores do cliente (edição CRM, 20/10/2026): as colunas do quadro de Clientes
 * (`clientes_setores`: Atrasado, Atenção, Em dia, Sem demanda...) em que o
 * cliente está. A coluna principal continua sendo `clientes.setor_id` (é onde o
 * card é arrastado); os setores a mais ficam em `crm_cliente_setores_extras`
 * (migration 155), e o card aparece também em cada um deles.
 *
 * Também monta a parte do card do quadro que vem dos valores: total por mês e
 * serviços e taxas (`quadro`).
 *
 * Toda consulta filtra account_id; setor de outra conta é recusado.
 */
final class SetoresDoCliente
{
    /** As colunas do quadro da conta. @return array<int, array{id:int, nome:string, cor:string}> */
    public static function daConta(int $accountId): array
    {
        $st = Database::getConnection()->prepare('SELECT id, nome, cor FROM clientes_setores WHERE account_id = ? AND ativo = 1 ORDER BY ordem, id');
        $st->execute([$accountId]);
        return array_map(fn($s) => ['id' => (int) $s['id'], 'nome' => $s['nome'], 'cor' => $s['cor'] ?: '#6366F1'], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array{principal: ?int, extras: int[]} */
    public static function doCliente(int $accountId, int $clienteId): array
    {
        $pdo = Database::getConnection();
        $c = $pdo->prepare('SELECT setor_id FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
        $c->execute([$clienteId, $accountId]);
        $principal = $c->fetchColumn();
        $st = $pdo->prepare(
            'SELECT e.setor_id FROM crm_cliente_setores_extras e
               JOIN clientes_setores s ON s.id = e.setor_id AND s.account_id = e.account_id AND s.ativo = 1
              WHERE e.account_id = ? AND e.cliente_id = ? ORDER BY s.ordem, s.id'
        );
        $st->execute([$accountId, $clienteId]);
        $extras = array_values(array_filter(array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN)), fn($id) => $id !== (int) $principal));
        return ['principal' => $principal !== false && $principal !== null ? (int) $principal : null, 'extras' => $extras];
    }

    /**
     * Troca os setores a mais do cliente pelos informados. A coluna principal não
     * entra (é mudada no cabeçalho da ficha ou arrastando o card).
     * @param int[] $setorIds
     */
    public static function salvar(int $accountId, int $userId, int $clienteId, array $setorIds): array
    {
        $pdo = Database::getConnection();
        $atual = self::doCliente($accountId, $clienteId);
        $c = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
        $c->execute([$clienteId, $accountId]);
        if (!(int) $c->fetchColumn()) throw new \InvalidArgumentException('Cliente não encontrado.');
        $validos = array_column(self::daConta($accountId), 'id');
        $ids = array_values(array_diff(array_unique(array_map('intval', $setorIds)), [(int) $atual['principal']]));
        foreach ($ids as $s) if (!in_array($s, $validos, true)) throw new \InvalidArgumentException('Setor não encontrado nesta conta.');

        $pdo->beginTransaction();
        try {
            $del = 'DELETE FROM crm_cliente_setores_extras WHERE account_id = ? AND cliente_id = ?';
            $args = [$accountId, $clienteId];
            if ($ids) { $del .= ' AND setor_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; array_push($args, ...$ids); }
            $pdo->prepare($del)->execute($args);
            $ins = $pdo->prepare('INSERT IGNORE INTO crm_cliente_setores_extras (account_id, cliente_id, setor_id, criado_por) VALUES (?, ?, ?, ?)');
            foreach ($ids as $s) $ins->execute([$accountId, $clienteId, $s, $userId ?: null]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::doCliente($accountId, $clienteId);
    }

    /**
     * Por cliente: os setores a mais e o dinheiro do card.
     * @param int[] $accountIds contas que a sessão vê no quadro
     * @return array<int, array{extras: int[], por_mes: float, avulso: float}>
     */
    public static function quadro(array $accountIds): array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        if (!$accountIds) return [];
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $pdo = Database::getConnection();
        $out = [];
        $st = $pdo->prepare("SELECT e.cliente_id, e.setor_id FROM crm_cliente_setores_extras e
                               JOIN clientes_setores s ON s.id = e.setor_id AND s.account_id = e.account_id AND s.ativo = 1
                               JOIN clientes c ON c.id = e.cliente_id AND c.account_id = e.account_id
                              WHERE e.account_id IN ($in) AND (c.setor_id IS NULL OR c.setor_id <> e.setor_id) ORDER BY s.ordem, s.id");
        $st->execute($accountIds);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[(int) $r['cliente_id']]['extras'][] = (int) $r['setor_id'];
        $v = $pdo->prepare("SELECT cliente_id, tipo, valor, ativo, fim FROM crm_cliente_valores WHERE account_id IN ($in)");
        $v->execute($accountIds);
        $porCliente = [];
        foreach ($v->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $porCliente[(int) $r['cliente_id']][] = ['tipo' => $r['tipo'], 'valor' => (float) $r['valor'], 'ativo' => (bool) $r['ativo'], 'fim' => $r['fim']];
        }
        foreach ($porCliente as $cli => $linhas) {
            $res = ValoresDoCliente::resumo($linhas);
            $out[$cli]['por_mes'] = $res['por_mes'];
            $out[$cli]['avulso'] = round(($res['servico'] ?? 0) + ($res['fixo'] ?? 0), 2);
        }
        foreach ($out as $cli => $d) $out[$cli] += ['extras' => [], 'por_mes' => 0.0, 'avulso' => 0.0];
        return $out;
    }
}
