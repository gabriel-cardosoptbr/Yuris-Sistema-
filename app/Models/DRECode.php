<?php
namespace App\Models;

require_once __DIR__ . '/Database.php';

use App\Models\Database;

class DRECode
{
    private static function _norm(array $f): array
    {
        if (!empty($f['account_ids']) && is_array($f['account_ids'])) {
            return array_values(array_filter(array_map('intval', $f['account_ids']), fn($v) => $v > 0));
        }
        if (!empty($f['account_id'])) return [(int)$f['account_id']];
        return [];
    }
    private static function _in(array $ids, string $prefix): array
    {
        $ph = []; $p = [];
        foreach ($ids as $i => $id) { $k = "{$prefix}_{$i}"; $ph[] = ":{$k}"; $p[$k] = (int)$id; }
        return ['sql' => implode(',', $ph), 'params' => $p];
    }

    public static function listAll(array $filters = [])
    {
        $ids = self::_norm($filters);
        if (empty($ids)) return [];
        $in = self::_in($ids, 'dcl');
        $db = Database::getConnection();
        $st = $db->prepare("SELECT * FROM dre_codes WHERE ativo = 1 AND account_id IN ({$in['sql']}) ORDER BY code");
        $st->execute($in['params']);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function find(int $id, ?array $accountIds = null)
    {
        $db = Database::getConnection();
        if ($accountIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
            if (empty($ids)) return null;
            $in = self::_in($ids, 'dcf');
            $st = $db->prepare("SELECT * FROM dre_codes WHERE id = :id AND account_id IN ({$in['sql']}) LIMIT 1");
            $st->execute(['id' => $id] + $in['params']);
            return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        $st = $db->prepare('SELECT * FROM dre_codes WHERE id = :id LIMIT 1');
        $st->execute(['id' => $id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function create(array $data)
    {
        if (empty($data['account_id'])) {
            throw new \InvalidArgumentException('account_id é obrigatório para criar código DRE');
        }
        $db = Database::getConnection();
        $st = $db->prepare('INSERT INTO dre_codes (account_id, code, descricao, ativo) VALUES (:account_id, :code, :descricao, :ativo)');
        $ok = $st->execute([
            'account_id' => (int)$data['account_id'],
            'code'       => $data['code'] ?? '',
            'descricao'  => $data['descricao'] ?? null,
            'ativo'      => isset($data['ativo']) ? (int)$data['ativo'] : 1,
        ]);
        return $ok ? (int)$db->lastInsertId() : 0;
    }

    public static function update(int $id, array $data, ?array $accountIds = null)
    {
        $db = Database::getConnection();
        $tenantSql = '';
        $params = [
            'code'      => $data['code'] ?? '',
            'descricao' => $data['descricao'] ?? null,
            'ativo'     => isset($data['ativo']) ? (int)$data['ativo'] : 1,
            'id'        => $id,
        ];
        if ($accountIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
            if (empty($ids)) return false;
            $in = self::_in($ids, 'dcu');
            $tenantSql = " AND account_id IN ({$in['sql']})";
            $params = $params + $in['params'];
        }
        $st = $db->prepare("UPDATE dre_codes SET code = :code, descricao = :descricao, ativo = :ativo WHERE id = :id{$tenantSql}");
        $ok = $st->execute($params);
        return $ok && $st->rowCount() > 0;
    }

    public static function softDelete(int $id, ?array $accountIds = null)
    {
        $db = Database::getConnection();
        if ($accountIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
            if (empty($ids)) return false;
            $in = self::_in($ids, 'dcd');
            $st = $db->prepare("UPDATE dre_codes SET ativo = 0 WHERE id = :id AND account_id IN ({$in['sql']})");
            $ok = $st->execute(['id' => $id] + $in['params']);
            return $ok && $st->rowCount() > 0;
        }
        $st = $db->prepare('UPDATE dre_codes SET ativo = 0 WHERE id = :id');
        return $st->execute(['id' => $id]);
    }
}
