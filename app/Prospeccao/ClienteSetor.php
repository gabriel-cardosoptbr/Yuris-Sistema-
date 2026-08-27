<?php
namespace App\Prospeccao;

use App\Core\Database;

/**
 * ClienteSetor — colunas do kanban de Clientes (uma etapa operacional do escritório).
 *
 * Espelha o padrão de PipelineColumn, mas SEM herança matriz→filial:
 * cada conta (matriz, filial, advogado) tem seus próprios setores.
 *
 * Decisão deliberada: setores representam fluxo INTERNO operacional, e cada
 * filial pode ter seu próprio (matriz Cível ≠ filial Trabalhista). Mais flexível
 * que herdar o conjunto fixo da matriz. Se mudar de ideia, evolução fácil.
 */
class ClienteSetor
{
    /**
     * Lista setores do tenant. account_ids obrigatório.
     * Por padrão só retorna ativos. Passe include_inactive=true pra incluir arquivados.
     */
    public static function listAll(array $filters = []): array
    {
        $ids = self::_normalizeAccountIds($filters);
        if (empty($ids)) return [];

        $pdo = Database::getConnection();
        $in  = self::_buildInClause($ids, 'csacc');

        $sql = "SELECT cs.*,
                       (SELECT COUNT(*) FROM clientes c
                          WHERE c.setor_id = cs.id AND c.deleted_at IS NULL) AS clientes_count
                  FROM clientes_setores cs
                 WHERE cs.account_id IN ({$in['placeholders']})";
        $params = $in['params'];

        if (empty($filters['include_inactive'])) {
            $sql .= ' AND cs.ativo = 1';
        }
        $sql .= ' ORDER BY cs.ordem ASC, cs.id ASC';

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
            $in = self::_buildInClause($ids, 'csfid');
            $stmt = $pdo->prepare("SELECT * FROM clientes_setores WHERE id = :id AND account_id IN ({$in['placeholders']}) LIMIT 1");
            $stmt->execute(['id' => $id] + $in['params']);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM clientes_setores WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cria setor. account_id obrigatório. Slug é gerado a partir do nome e
     * deduplicado (se já existir slug igual no tenant, append -2, -3, ...).
     */
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

        // Ordem: se não passada, vai pro fim (max+1)
        if (!isset($data['ordem'])) {
            $stmtMax = $pdo->prepare('SELECT COALESCE(MAX(ordem),0) FROM clientes_setores WHERE account_id = ?');
            $stmtMax->execute([$accountId]);
            $data['ordem'] = (int)$stmtMax->fetchColumn() + 1;
        }

        $pdo->prepare(
            'INSERT INTO clientes_setores (account_id, nome, slug, cor, ordem, ativo, created_at, updated_at)
             VALUES (:aid, :nome, :slug, :cor, :ordem, 1, NOW(), NOW())'
        )->execute([
            'aid'   => $accountId,
            'nome'  => $nome,
            'slug'  => $slug,
            'cor'   => self::_normalizeCor($data['cor'] ?? '#6366f1'),
            'ordem' => (int)$data['ordem'],
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Atualiza setor (nome/cor/ordem/ativo). Multi-tenant enforce via accountIds.
     * Se nome mudou, slug é re-gerado (com dedupe).
     */
    public static function update(int $id, array $data, ?array $accountIds = null): bool
    {
        $pdo = Database::getConnection();

        $allowed = ['nome','cor','ordem','ativo'];
        $fields = []; $params = ['id' => $id];

        foreach ($allowed as $k) {
            if (!array_key_exists($k, $data)) continue;
            if     ($k === 'ordem') $params[$k] = (int)$data[$k];
            elseif ($k === 'ativo') $params[$k] = !empty($data[$k]) ? 1 : 0;
            elseif ($k === 'cor')   $params[$k] = self::_normalizeCor($data[$k]);
            else                    $params[$k] = trim((string)$data[$k]);
            $fields[] = "$k = :$k";
        }

        // Se nome mudou, atualiza slug
        if (isset($params['nome']) && $params['nome'] !== '') {
            $current = self::find($id);
            if ($current) {
                $params['slug'] = self::_uniqueSlug(
                    (int)$current['account_id'],
                    self::slugify($params['nome']),
                    $id  // exclui o próprio id da deduplicação
                );
                $fields[] = 'slug = :slug';
            }
        }

        if (empty($fields)) return false;

        $tenantWhere = '';
        if ($accountIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
            if (empty($ids)) return false;
            $in = self::_buildInClause($ids, 'csuacc');
            $tenantWhere = " AND account_id IN ({$in['placeholders']})";
            $params = $params + $in['params'];
        }

        $sql = 'UPDATE clientes_setores SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id' . $tenantWhere;
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute($params);
        return $ok && $stmt->rowCount() > 0;
    }

    /**
     * Reordena setores em lote. Cada item: ['id' => X, 'ordem' => Y].
     * Multi-tenant enforce: só atualiza setores do tenant.
     */
    public static function reorder(array $orderMap, array $accountIds): int
    {
        if (empty($orderMap) || empty($accountIds)) return 0;
        $pdo = Database::getConnection();
        $in  = self::_buildInClause($accountIds, 'csroacc');

        try {
            $pdo->beginTransaction();
            $upd = $pdo->prepare(
                "UPDATE clientes_setores SET ordem = :ordem, updated_at = NOW()
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
     * "Arquiva" setor: marca ativo=0. NÃO deleta. Antes de arquivar, valida que
     * não há clientes ativos vinculados — se houver, retorna ['ok'=>false, 'reason'=>'has_clients', 'count'=>N].
     */
    public static function archive(int $id, ?array $accountIds = null): array
    {
        $pdo = Database::getConnection();
        $setor = self::find($id, $accountIds);
        if (!$setor) return ['ok' => false, 'reason' => 'not_found'];

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE setor_id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt > 0) {
            return ['ok' => false, 'reason' => 'has_clients', 'count' => $cnt];
        }

        $ok = $pdo->prepare('UPDATE clientes_setores SET ativo = 0, updated_at = NOW() WHERE id = ?')
                  ->execute([$id]);
        return ['ok' => (bool)$ok];
    }

    // ───────── helpers ──────────────────────────────────────────────

    public static function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text ?: '');
        $text = trim((string)$text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        return $text === '' ? 'setor' : $text;
    }

    /** Garante slug único no tenant. Se já existir, append -2, -3, ... */
    private static function _uniqueSlug(int $accountId, string $base, ?int $excludeId = null): string
    {
        $pdo = Database::getConnection();
        $candidate = $base;
        $n = 1;
        while (true) {
            $sql = 'SELECT id FROM clientes_setores WHERE account_id = :aid AND slug = :slug';
            $params = ['aid' => $accountId, 'slug' => $candidate];
            if ($excludeId !== null) { $sql .= ' AND id != :ex'; $params['ex'] = $excludeId; }
            $sql .= ' LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if (!$stmt->fetchColumn()) return $candidate;
            $n++;
            $candidate = $base . '-' . $n;
            if ($n > 100) return $candidate;  // sanity guard
        }
    }

    private static function _normalizeCor(?string $cor): string
    {
        $cor = trim((string)($cor ?? ''));
        if ($cor === '') return '#6366f1';
        if (!preg_match('/^#[0-9A-Fa-f]{3,8}$/', $cor)) return '#6366f1';
        return $cor;
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
