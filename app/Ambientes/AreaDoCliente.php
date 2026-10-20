<?php
namespace App\Ambientes;

use App\Core\Database;

/**
 * Em que áreas cada cliente está (20/10/2026). Não há cadastro por área: o
 * cliente é um só, e está em Software e/ou em Digital pelo que contratou e pelo
 * que a equipe faz para ele:
 *
 *   software  linha de valor com área software, ou quadro da área software
 *   digital   linha de valor com área digital, quadro da área digital,
 *             frequência contratada ou campanha
 *
 * e, nas duas, a área marcada direto na ficha (`crm_cliente_areas`, migration
 * 161): cliente recém-fechado, ainda sem nada cadastrado, já entra na área.
 *
 * É o filtro de Clientes nos ambientes (`?amb=software`) e a base das contagens
 * por área da Empresa, que contam cada cliente uma vez só.
 */
final class AreaDoCliente
{
    public const AREAS = ['software', 'digital'];

    /**
     * @param int[]|null $clienteIds null = todos da conta
     * @return array<int, string[]> cliente_id => áreas
     */
    public static function mapa(int $accountId, ?array $clienteIds = null): array
    {
        if ($clienteIds !== null && !$clienteIds) return [];
        $sql = "SELECT DISTINCT x.cliente_id, x.area FROM (
                    SELECT cliente_id, CONVERT(area USING utf8mb4) COLLATE utf8mb4_unicode_ci AS area FROM crm_cliente_valores
                     WHERE account_id = :a1 AND ativo = 1 AND area IN ('software', 'digital')
                    UNION ALL
                    SELECT cliente_id, CONVERT(area USING utf8mb4) COLLATE utf8mb4_unicode_ci AS area FROM task_boards
                     WHERE account_id = :a2 AND ativo = 1 AND cliente_id IS NOT NULL AND area IN ('software', 'digital')
                    UNION ALL
                    SELECT cliente_id, CONVERT('digital' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS area FROM crm_cliente_frequencias WHERE account_id = :a3
                    UNION ALL
                    SELECT cliente_id, CONVERT('digital' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS area FROM crm_campanhas WHERE account_id = :a4
                    UNION ALL
                    SELECT cliente_id, CONVERT(area USING utf8mb4) COLLATE utf8mb4_unicode_ci AS area FROM crm_cliente_areas WHERE account_id = :a6
                ) x
                JOIN clientes c ON c.id = x.cliente_id AND c.account_id = :a5 AND c.deleted_at IS NULL
                WHERE 1 = 1";
        $p = ['a1' => $accountId, 'a2' => $accountId, 'a3' => $accountId, 'a4' => $accountId, 'a5' => $accountId, 'a6' => $accountId];
        if ($clienteIds !== null) {
            $ph = [];
            foreach (array_values($clienteIds) as $i => $id) { $ph[] = ":c$i"; $p["c$i"] = (int) $id; }
            $sql .= ' AND x.cliente_id IN (' . implode(',', $ph) . ')';
        }
        $st = Database::getConnection()->prepare($sql);
        $st->execute($p);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[(int) $r['cliente_id']][] = $r['area'];
        return $out;
    }

    /** Áreas marcadas direto na ficha (só estas; as que vêm do resto não entram aqui). @return string[] */
    public static function marcadas(int $accountId, int $clienteId): array
    {
        $st = Database::getConnection()->prepare('SELECT area FROM crm_cliente_areas WHERE account_id = ? AND cliente_id = ? ORDER BY area');
        $st->execute([$accountId, $clienteId]);
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Troca as áreas marcadas na ficha. Cliente de outra conta é recusado.
     * @param string[] $areas
     */
    public static function marcar(int $accountId, int $userId, int $clienteId, array $areas): array
    {
        $areas = array_values(array_unique(array_map('strval', $areas)));
        foreach ($areas as $a) if (!in_array($a, self::AREAS, true)) throw new \InvalidArgumentException('Área inválida.');
        $pdo = Database::getConnection();
        $c = $pdo->prepare('SELECT id FROM clientes WHERE id = ? AND account_id = ? AND deleted_at IS NULL');
        $c->execute([$clienteId, $accountId]);
        if (!$c->fetchColumn()) throw new \InvalidArgumentException('Cliente não encontrado nesta conta.');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM crm_cliente_areas WHERE account_id = ? AND cliente_id = ?')->execute([$accountId, $clienteId]);
            $ins = $pdo->prepare('INSERT INTO crm_cliente_areas (cliente_id, area, account_id, criado_por) VALUES (?, ?, ?, ?)');
            foreach ($areas as $a) $ins->execute([$clienteId, $a, $accountId, $userId ?: null]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::marcadas($accountId, $clienteId);
    }

    /**
     * Ids dos clientes de uma área, já com a regra do vendedor (só os dele).
     * @return int[]
     */
    public static function clientesDaArea(int $accountId, string $area, ?int $soDoResponsavel = null): array
    {
        if (!in_array($area, self::AREAS, true)) return [];
        $ids = [];
        foreach (self::mapa($accountId) as $c => $areas) if (in_array($area, $areas, true)) $ids[] = $c;
        if (!$ids || $soDoResponsavel === null) return $ids;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::getConnection()->prepare("SELECT id FROM clientes WHERE account_id = ? AND responsavel_id = ? AND id IN ($in)");
        $st->execute(array_merge([$accountId, $soDoResponsavel], $ids));
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }
}
