<?php
namespace App\Models;

use App\Models\Database;

class PipelineColumn
{
    public static function listAll()
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT * FROM pipeline_columns ORDER BY ordem ASC');
        return $stmt->fetchAll();
    }

    public static function find($id)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM pipeline_columns WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public static function create($data)
    {
        $pdo = Database::getConnection();
        $slug = self::slugify($data['nome'] ?? '');
        $stmt = $pdo->prepare('INSERT INTO pipeline_columns (nome, slug, cor, ordem, conta_funil, conta_oportunidade, conta_fechado, conta_perdido, peso_projecao, created_at, updated_at) VALUES (:nome, :slug, :cor, :ordem, :conta_funil, :conta_oportunidade, :conta_fechado, :conta_perdido, :peso_projecao, NOW(), NOW())');
        $stmt->execute([
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

    public static function update($id, $data)
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
        $sql = 'UPDATE pipeline_columns SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public static function delete($id)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM pipeline_columns WHERE id = :id');
        return $stmt->execute(['id'=>$id]);
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
