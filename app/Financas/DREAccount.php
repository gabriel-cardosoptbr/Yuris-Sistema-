<?php
namespace App\Financas;

require_once __DIR__ . '/../Core/Database.php';

use App\Core\Database;

/**
 * DREAccount — contas do plano DRE.
 *
 * Multi-tenant: account_id obrigatório em listagens. Aceita:
 *   - account_ids: int[]  (preferencial — matriz inclui filiais via getAccessibleAccountIds)
 *   - account_id:  int    (legado — embrulha em array)
 */
class DREAccount
{
    private static function _normalizeAccountIds(array $f): array
    {
        if (!empty($f['account_ids']) && is_array($f['account_ids'])) {
            return array_values(array_filter(array_map('intval', $f['account_ids']), fn($v) => $v > 0));
        }
        if (!empty($f['account_id'])) return [(int)$f['account_id']];
        return [];
    }

    private static function _buildIn(array $ids, string $prefix): array
    {
        $ph = []; $params = [];
        foreach ($ids as $i => $id) {
            $k = "{$prefix}_{$i}";
            $ph[] = ":{$k}";
            $params[$k] = (int)$id;
        }
        return ['sql' => implode(',', $ph), 'params' => $params];
    }

    public static function listAll(array $filters = [])
    {
        $ids = self::_normalizeAccountIds($filters);
        if (empty($ids)) return [];
        $in = self::_buildIn($ids, 'dre');
        $db = Database::getConnection();
        $st = $db->prepare("SELECT * FROM dre_accounts WHERE ativo = 1 AND account_id IN ({$in['sql']}) ORDER BY tipo, nome");
        $st->execute($in['params']);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function find(int $id, ?array $accountIds = null)
    {
        $db = Database::getConnection();
        if ($accountIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
            if (empty($ids)) return null;
            $in = self::_buildIn($ids, 'dref');
            $st = $db->prepare("SELECT * FROM dre_accounts WHERE id = :id AND account_id IN ({$in['sql']}) LIMIT 1");
            $st->execute(['id' => $id] + $in['params']);
            return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        $st = $db->prepare('SELECT * FROM dre_accounts WHERE id = :id LIMIT 1');
        $st->execute(['id' => $id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function create(array $data)
    {
        if (empty($data['account_id'])) {
            throw new \InvalidArgumentException('account_id é obrigatório para criar conta DRE');
        }
        $db = Database::getConnection();
        $st = $db->prepare('INSERT INTO dre_accounts (account_id, codigo, nome, tipo, valor_fixo, recorrencia, data_referencia, ativo) VALUES (:account_id, :codigo, :nome, :tipo, :valor_fixo, :recorrencia, :data_referencia, :ativo)');
        $ok = $st->execute([
            'account_id'      => (int)$data['account_id'],
            'codigo'          => $data['codigo'] ?? null,
            'nome'            => $data['nome'] ?? '',
            'tipo'            => ($data['tipo'] ?? 'despesa'),
            'valor_fixo'      => isset($data['valor_fixo']) ? (float)$data['valor_fixo'] : 0,
            'recorrencia'     => ($data['recorrencia'] ?? 'fixa'),
            'data_referencia' => !empty($data['data_referencia']) ? $data['data_referencia'] : null,
            'ativo'           => isset($data['ativo']) ? (int)$data['ativo'] : 1
        ]);
        return $ok ? (int)$db->lastInsertId() : 0;
    }

    public static function update(int $id, array $data, ?array $accountIds = null)
    {
        $db = Database::getConnection();
        $tenantSql = '';
        $params = [
            'codigo'          => $data['codigo'] ?? null,
            'nome'            => $data['nome'] ?? '',
            'tipo'            => ($data['tipo'] ?? 'despesa'),
            'valor_fixo'      => isset($data['valor_fixo']) ? (float)$data['valor_fixo'] : 0,
            'recorrencia'     => ($data['recorrencia'] ?? 'fixa'),
            'data_referencia' => !empty($data['data_referencia']) ? $data['data_referencia'] : null,
            'ativo'           => isset($data['ativo']) ? (int)$data['ativo'] : 1,
            'id'              => $id,
        ];
        if ($accountIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
            if (empty($ids)) return false;
            $in = self::_buildIn($ids, 'dreu');
            $tenantSql = " AND account_id IN ({$in['sql']})";
            $params = $params + $in['params'];
        }
        $st = $db->prepare("UPDATE dre_accounts SET codigo = :codigo, nome = :nome, tipo = :tipo, valor_fixo = :valor_fixo, recorrencia = :recorrencia, data_referencia = :data_referencia, ativo = :ativo WHERE id = :id{$tenantSql}");
        $ok = $st->execute($params);
        return $ok && $st->rowCount() > 0;
    }

    public static function softDelete(int $id, ?array $accountIds = null)
    {
        $db = Database::getConnection();
        if ($accountIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
            if (empty($ids)) return false;
            $in = self::_buildIn($ids, 'dred');
            $st = $db->prepare("UPDATE dre_accounts SET ativo = 0 WHERE id = :id AND account_id IN ({$in['sql']})");
            $ok = $st->execute(['id' => $id] + $in['params']);
            return $ok && $st->rowCount() > 0;
        }
        $st = $db->prepare('UPDATE dre_accounts SET ativo = 0 WHERE id = :id');
        return $st->execute(['id' => $id]);
    }

    public static function summary(array $filters = [])
    {
        $ids = self::_normalizeAccountIds($filters);
        $out = ['receita' => 0.0, 'despesa' => 0.0, 'resultado' => 0.0];
        if (empty($ids)) return $out;
        $in = self::_buildIn($ids, 'dres');
        $db = Database::getConnection();
        $st = $db->prepare("SELECT tipo, SUM(valor_fixo) as total FROM dre_accounts WHERE ativo = 1 AND account_id IN ({$in['sql']}) GROUP BY tipo");
        $st->execute($in['params']);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[$r['tipo']] = (float)$r['total'];
        }
        $out['resultado'] = $out['receita'] - $out['despesa'];
        return $out;
    }
}
