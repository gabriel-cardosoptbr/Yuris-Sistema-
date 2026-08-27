<?php
namespace App\Prospeccao;

use App\Core\Database;

/**
 * ClienteOrigem — origens do cadastro de cliente (lista editável por tenant).
 * Espelha o padrão de ClienteSetor mas mais simples (sem cor).
 *
 * Storage: clientes.origem armazena o SLUG (string). Lookup por slug → nome
 * acontece no JOIN/JS quando renderiza.
 */
class ClienteOrigem
{
    /**
     * Lista origens do tenant.
     * Inclui clientes_count agregado pra impedir archive de origem em uso.
     */
    public static function listAll(array $filters = []): array
    {
        $ids = self::_normalizeAccountIds($filters);
        if (empty($ids)) return [];

        $pdo = Database::getConnection();
        $in  = self::_buildInClause($ids, 'coacc');

        $sql = "SELECT co.*,
                       (SELECT COUNT(*) FROM clientes c
                          WHERE c.account_id = co.account_id
                            AND c.origem = co.slug
                            AND c.deleted_at IS NULL) AS clientes_count
                  FROM clientes_origens co
                 WHERE co.account_id IN ({$in['placeholders']})";
        $params = $in['params'];

        if (empty($filters['include_inactive'])) $sql .= ' AND co.ativo = 1';
        $sql .= ' ORDER BY co.ordem ASC, co.id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function find(int $id, ?array $accountIds = null): ?array
    {
        $pdo = Database::getConnection();
        if ($accountIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
            if (empty($ids)) return null;
            $in = self::_buildInClause($ids, 'cofid');
            $stmt = $pdo->prepare("SELECT * FROM clientes_origens WHERE id = :id AND account_id IN ({$in['placeholders']}) LIMIT 1");
            $stmt->execute(['id' => $id] + $in['params']);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM clientes_origens WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Localiza origem pelo slug dentro de um tenant (usado pelo endpoint pra validar). */
    public static function findBySlug(string $slug, int $accountId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM clientes_origens WHERE account_id = :aid AND slug = :slug LIMIT 1');
        $stmt->execute(['aid' => $accountId, 'slug' => $slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        if (empty($data['account_id'])) {
            throw new \InvalidArgumentException('account_id é obrigatório');
        }
        if (empty($data['nome']) || !trim((string)$data['nome'])) {
            throw new \InvalidArgumentException('nome é obrigatório');
        }
        $pdo = Database::getConnection();
        $accountId = (int)$data['account_id'];
        $nome = trim((string)$data['nome']);
        $slug = self::_uniqueSlug($accountId, self::slugify($nome));

        if (!isset($data['ordem'])) {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(ordem),0) FROM clientes_origens WHERE account_id = ?');
            $stmt->execute([$accountId]);
            $data['ordem'] = (int)$stmt->fetchColumn() + 1;
        }

        $pdo->prepare(
            'INSERT INTO clientes_origens (account_id, nome, slug, ordem, ativo, created_at, updated_at)
             VALUES (:aid, :nome, :slug, :ordem, 1, NOW(), NOW())'
        )->execute([
            'aid'   => $accountId,
            'nome'  => $nome,
            'slug'  => $slug,
            'ordem' => (int)$data['ordem'],
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Atualiza origem (nome / ordem / ativo). Renomear gera novo slug + UPDATE
     * cascateado em `clientes` pra trocar a string armazenada (preserva vínculo).
     */
    public static function update(int $id, array $data, ?array $accountIds = null): bool
    {
        $pdo = Database::getConnection();
        $current = self::find($id, $accountIds);
        if (!$current) return false;

        $allowed = ['nome','ordem','ativo'];
        $fields = []; $params = ['id' => $id];

        foreach ($allowed as $k) {
            if (!array_key_exists($k, $data)) continue;
            if     ($k === 'ordem') $params[$k] = (int)$data[$k];
            elseif ($k === 'ativo') $params[$k] = !empty($data[$k]) ? 1 : 0;
            else                    $params[$k] = trim((string)$data[$k]);
            $fields[] = "$k = :$k";
        }

        // Rename → novo slug + cascade UPDATE em clientes.origem
        $oldSlug = (string)$current['slug'];
        $newSlug = null;
        if (isset($params['nome']) && $params['nome'] !== '' && $params['nome'] !== $current['nome']) {
            $newSlug = self::_uniqueSlug((int)$current['account_id'], self::slugify($params['nome']), $id);
            $params['slug'] = $newSlug;
            $fields[] = 'slug = :slug';
        }

        if (empty($fields)) return false;

        $tenantWhere = '';
        if ($accountIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
            if (empty($ids)) return false;
            $in = self::_buildInClause($ids, 'couacc');
            $tenantWhere = " AND account_id IN ({$in['placeholders']})";
            $params = $params + $in['params'];
        }

        $sql = 'UPDATE clientes_origens SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id' . $tenantWhere;
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute($params);
        if (!$ok || $stmt->rowCount() === 0) return false;

        // Cascade: atualiza clientes.origem com novo slug
        if ($newSlug !== null && $newSlug !== $oldSlug) {
            $pdo->prepare('UPDATE clientes SET origem = :new WHERE account_id = :aid AND origem = :old')
                ->execute(['new' => $newSlug, 'aid' => (int)$current['account_id'], 'old' => $oldSlug]);
        }
        return true;
    }

    public static function reorder(array $orderMap, array $accountIds): int
    {
        if (empty($orderMap) || empty($accountIds)) return 0;
        $pdo = Database::getConnection();
        $in  = self::_buildInClause($accountIds, 'coroacc');

        try {
            $pdo->beginTransaction();
            $upd = $pdo->prepare(
                "UPDATE clientes_origens SET ordem = :ordem, updated_at = NOW()
                  WHERE id = :id AND account_id IN ({$in['placeholders']})"
            );
            $n = 0;
            foreach ($orderMap as $item) {
                $id = (int)($item['id'] ?? 0);
                if (!$id) continue;
                $ord = (int)($item['ordem'] ?? 0);
                $upd->execute(['ordem' => $ord, 'id' => $id] + $in['params']);
                if ($upd->rowCount() > 0) $n++;
            }
            $pdo->commit();
            return $n;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return 0;
        }
    }

    /**
     * Arquiva origem (ativo=0). Recusa se há clientes ativos usando esse slug.
     */
    public static function archive(int $id, ?array $accountIds = null): array
    {
        $pdo = Database::getConnection();
        $current = self::find($id, $accountIds);
        if (!$current) return ['ok' => false, 'reason' => 'not_found'];

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE account_id = ? AND origem = ? AND deleted_at IS NULL');
        $stmt->execute([(int)$current['account_id'], (string)$current['slug']]);
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt > 0) return ['ok' => false, 'reason' => 'has_clients', 'count' => $cnt];

        $ok = $pdo->prepare('UPDATE clientes_origens SET ativo = 0, updated_at = NOW() WHERE id = ?')
                  ->execute([$id]);
        return ['ok' => (bool)$ok];
    }

    // ───────── helpers (mesmo padrão de ClienteSetor) ───────────────

    public static function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '_', $text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        $text = preg_replace('~[^_\w]+~', '', $text ?: '');
        $text = trim((string)$text, '_');
        $text = preg_replace('~_+~', '_', $text);
        $text = strtolower($text);
        return $text === '' ? 'origem' : $text;
    }

    private static function _uniqueSlug(int $accountId, string $base, ?int $excludeId = null): string
    {
        $pdo = Database::getConnection();
        $candidate = $base; $n = 1;
        while (true) {
            $sql = 'SELECT id FROM clientes_origens WHERE account_id = :aid AND slug = :slug';
            $params = ['aid' => $accountId, 'slug' => $candidate];
            if ($excludeId !== null) { $sql .= ' AND id != :ex'; $params['ex'] = $excludeId; }
            $sql .= ' LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if (!$stmt->fetchColumn()) return $candidate;
            $n++;
            $candidate = $base . '_' . $n;
            if ($n > 100) return $candidate;
        }
    }

    private static function _normalizeAccountIds(array $filters): array
    {
        if (!empty($filters['account_ids']) && is_array($filters['account_ids'])) {
            return array_values(array_filter(array_map('intval', $filters['account_ids']), fn($v) => $v > 0));
        }
        if (!empty($filters['account_id'])) return [(int)$filters['account_id']];
        return [];
    }

    private static function _buildInClause(array $ids, string $prefix): array
    {
        $ph = []; $params = [];
        foreach ($ids as $i => $id) {
            $k = "{$prefix}_{$i}";
            $ph[] = ":{$k}";
            $params[$k] = (int)$id;
        }
        return ['placeholders' => implode(',', $ph), 'params' => $params];
    }
}
