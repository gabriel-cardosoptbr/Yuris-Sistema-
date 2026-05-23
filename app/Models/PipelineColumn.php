<?php
namespace App\Models;

use App\Models\Database;

class PipelineColumn
{
    /**
     * Lista colunas do funil. Aceita:
     *   account_ids  array  — sessão matriz inclui filiais vinculadas
     *   account_id   int    — legado
     * Sem filtro de tenant retorna vazio (NUNCA expõe colunas de outras contas).
     */
    public static function listAll(array $filters = [])
    {
        $ids = self::_normalizeAccountIds($filters);
        if (empty($ids)) return [];

        $pdo = Database::getConnection();
        $in  = self::_buildInClause($ids, 'pcacc');
        $stmt = $pdo->prepare("SELECT * FROM pipeline_columns WHERE account_id IN ({$in['placeholders']}) ORDER BY ordem ASC");
        $stmt->execute($in['params']);
        return $stmt->fetchAll();
    }

    public static function find($id, ?array $accountIds = null)
    {
        $pdo = Database::getConnection();
        if ($accountIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
            if (empty($ids)) return false;
            $in  = self::_buildInClause($ids, 'pcfid');
            $stmt = $pdo->prepare("SELECT * FROM pipeline_columns WHERE id = :id AND account_id IN ({$in['placeholders']}) LIMIT 1");
            $stmt->execute(['id' => $id] + $in['params']);
            return $stmt->fetch();
        }
        $stmt = $pdo->prepare('SELECT * FROM pipeline_columns WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public static function create($data)
    {
        if (empty($data['account_id'])) {
            throw new \InvalidArgumentException('account_id é obrigatório para criar coluna');
        }
        $pdo = Database::getConnection();
        $slug = self::slugify($data['nome'] ?? '');
        $stmt = $pdo->prepare('INSERT INTO pipeline_columns (account_id, nome, slug, cor, ordem, conta_funil, conta_oportunidade, conta_fechado, conta_perdido, peso_projecao, created_at, updated_at) VALUES (:account_id, :nome, :slug, :cor, :ordem, :conta_funil, :conta_oportunidade, :conta_fechado, :conta_perdido, :peso_projecao, NOW(), NOW())');
        $stmt->execute([
            'account_id' => (int)$data['account_id'],
            'nome' => $data['nome'] ?? '',
            'slug' => $slug,
            'cor' => $data['cor'] ?? '#EEEEEE',
            'ordem' => isset($data['ordem']) ? (int)$data['ordem'] : 0,
            'conta_funil' => !empty($data['conta_funil']) ? 1 : 0,
            'conta_oportunidade' => !empty($data['conta_oportunidade']) ? 1 : 0,
            'conta_fechado' => !empty($data['conta_fechado']) ? 1 : 0,
            'conta_perdido' => !empty($data['conta_perdido']) ? 1 : 0,
            'peso_projecao' => isset($data['peso_projecao']) ? (int)$data['peso_projecao'] : 0
        ]);
        return $pdo->lastInsertId();
    }

    /**
     * Atualiza apenas se a coluna pertence a um dos account_ids permitidos.
     * Se $accountIds for null, sem validação de tenant (uso interno).
     */
    public static function update($id, $data, ?array $accountIds = null)
    {
        $pdo = Database::getConnection();
        $fields = [];
        $params = ['id'=>$id];
        $allowed = ['nome','cor','ordem','conta_funil','conta_oportunidade','conta_fechado','conta_perdido','peso_projecao'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                if (in_array($k, ['conta_funil','conta_oportunidade','conta_fechado','conta_perdido'])) {
                    $params[$k] = !empty($data[$k]) ? 1 : 0;
                } elseif ($k === 'ordem' || $k === 'peso_projecao') {
                    $params[$k] = (int)$data[$k];
                } else {
                    $params[$k] = $data[$k];
                }
                $fields[] = "$k = :$k";
            }
        }
        if (isset($params['nome'])) {
            $params['slug'] = self::slugify($params['nome']);
            $fields[] = "slug = :slug";
        }
        if (empty($fields)) return false;

        $tenantWhere = '';
        if ($accountIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
            if (empty($ids)) return false;
            $in = self::_buildInClause($ids, 'pcuacc');
            $tenantWhere = " AND account_id IN ({$in['placeholders']})";
            $params = $params + $in['params'];
        }
        $sql = 'UPDATE pipeline_columns SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id' . $tenantWhere;
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute($params);
        return $ok && $stmt->rowCount() > 0;
    }

    public static function delete($id, ?array $accountIds = null)
    {
        $pdo = Database::getConnection();
        if ($accountIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $accountIds), fn($v) => $v > 0));
            if (empty($ids)) return false;
            $in = self::_buildInClause($ids, 'pcdacc');
            $stmt = $pdo->prepare("DELETE FROM pipeline_columns WHERE id = :id AND account_id IN ({$in['placeholders']})");
            $ok = $stmt->execute(['id' => $id] + $in['params']);
            return $ok && $stmt->rowCount() > 0;
        }
        $stmt = $pdo->prepare('DELETE FROM pipeline_columns WHERE id = :id');
        return $stmt->execute(['id'=>$id]);
    }

    private static function _normalizeAccountIds(array $filters): array
    {
        if (!empty($filters['account_ids']) && is_array($filters['account_ids'])) {
            return array_values(array_filter(array_map('intval', $filters['account_ids']), fn($v) => $v > 0));
        }
        if (!empty($filters['account_id'])) return [(int) $filters['account_id']];
        return [];
    }

    private static function _buildInClause(array $ids, string $prefix): array
    {
        $ph     = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $k          = "{$prefix}_{$i}";
            $ph[]       = ":{$k}";
            $params[$k] = (int) $id;
        }
        return ['placeholders' => implode(',', $ph), 'params' => $params];
    }

    private static function slugify($text)
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        if (empty($text)) return 'coluna';
        return $text;
    }
}
