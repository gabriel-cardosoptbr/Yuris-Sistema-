<?php
namespace App\Clientes;

use App\Core\Database;

/**
 * Setores do cliente (edição CRM, 20/10/2026, migration 154): a que setor da conta
 * o cliente pertence (Concessionária, Locação...), podendo ser mais de um. O setor
 * é o mesmo do lead e do Chat (`teams`, ver app/Usuarios/Team.php); o lead tem um,
 * o cliente tem quantos precisar.
 *
 * Também monta o que o card do quadro de Clientes mostra além do cadastro: os
 * setores e o total por mês dos valores combinados (`quadro`).
 *
 * Toda consulta filtra account_id; setor de outra conta é recusado.
 */
final class SetoresDoCliente
{
    /** Os setores da conta, para escolher. @return array<int, array{id:int, nome:string, cor:string}> */
    public static function daConta(int $accountId): array
    {
        $st = Database::getConnection()->prepare('SELECT id, nome, cor FROM teams WHERE account_id = ? AND deleted_at IS NULL ORDER BY nome');
        $st->execute([$accountId]);
        return array_map(fn($t) => ['id' => (int) $t['id'], 'nome' => $t['nome'], 'cor' => $t['cor'] ?: '#3B82F6'], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return int[] ids dos setores do cliente */
    public static function doCliente(int $accountId, int $clienteId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT s.team_id FROM crm_cliente_setores s JOIN teams t ON t.id = s.team_id AND t.account_id = s.account_id AND t.deleted_at IS NULL
              WHERE s.account_id = ? AND s.cliente_id = ? ORDER BY t.nome'
        );
        $st->execute([$accountId, $clienteId]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Troca o conjunto de setores do cliente pelo informado. @param int[] $teamIds */
    public static function salvar(int $accountId, int $userId, int $clienteId, array $teamIds): array
    {
        $pdo = Database::getConnection();
        $c = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
        $c->execute([$clienteId, $accountId]);
        if (!(int) $c->fetchColumn()) throw new \InvalidArgumentException('Cliente não encontrado.');
        $validos = array_column(self::daConta($accountId), 'id');
        $ids = array_values(array_unique(array_map('intval', $teamIds)));
        foreach ($ids as $t) if (!in_array($t, $validos, true)) throw new \InvalidArgumentException('Setor não encontrado nesta conta.');

        $pdo->beginTransaction();
        try {
            $del = 'DELETE FROM crm_cliente_setores WHERE account_id = ? AND cliente_id = ?';
            $args = [$accountId, $clienteId];
            if ($ids) { $del .= ' AND team_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; array_push($args, ...$ids); }
            $pdo->prepare($del)->execute($args);
            $ins = $pdo->prepare('INSERT IGNORE INTO crm_cliente_setores (account_id, cliente_id, team_id, criado_por) VALUES (?, ?, ?, ?)');
            foreach ($ids as $t) $ins->execute([$accountId, $clienteId, $t, $userId ?: null]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::doCliente($accountId, $clienteId);
    }

    /**
     * O que o card de cada cliente mostra: setores e total por mês.
     * @param int[] $accountIds contas que a sessão vê no quadro
     * @return array<int, array{setores: array, por_mes: float, avulso: float}>
     */
    public static function quadro(array $accountIds): array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        if (!$accountIds) return [];
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $pdo = Database::getConnection();
        $out = [];
        $st = $pdo->prepare("SELECT s.cliente_id, t.id, t.nome, t.cor FROM crm_cliente_setores s
                               JOIN teams t ON t.id = s.team_id AND t.account_id = s.account_id AND t.deleted_at IS NULL
                              WHERE s.account_id IN ($in) ORDER BY t.nome");
        $st->execute($accountIds);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['cliente_id']]['setores'][] = ['id' => (int) $r['id'], 'nome' => $r['nome'], 'cor' => $r['cor'] ?: '#3B82F6'];
        }
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
        foreach ($out as $cli => $d) $out[$cli] += ['setores' => [], 'por_mes' => 0.0, 'avulso' => 0.0];
        return $out;
    }
}
