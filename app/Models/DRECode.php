<?php
namespace App\Models;

require_once __DIR__ . '/Database.php';

use App\Models\Database;

class DRECode
{
    public static function listAll()
    {
        $db = Database::getConnection();
        $st = $db->query('SELECT * FROM dre_codes WHERE ativo = 1 ORDER BY code');
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function find(int $id)
    {
        $db = Database::getConnection();
        $st = $db->prepare('SELECT * FROM dre_codes WHERE id = :id LIMIT 1');
        $st->execute(['id'=>$id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function create(array $data)
    {
        $db = Database::getConnection();
        $st = $db->prepare('INSERT INTO dre_codes (code, descricao, ativo) VALUES (:code, :descricao, :ativo)');
        $ok = $st->execute([
            'code' => $data['code'] ?? '',
            'descricao' => $data['descricao'] ?? null,
            'ativo' => isset($data['ativo']) ? (int)$data['ativo'] : 1,
        ]);
        return $ok ? (int)$db->lastInsertId() : 0;
    }

    public static function update(int $id, array $data)
    {
        $db = Database::getConnection();
        $st = $db->prepare('UPDATE dre_codes SET code = :code, descricao = :descricao, ativo = :ativo WHERE id = :id');
        return $st->execute([
            'code' => $data['code'] ?? '',
            'descricao' => $data['descricao'] ?? null,
            'ativo' => isset($data['ativo']) ? (int)$data['ativo'] : 1,
            'id' => $id
        ]);
    }

    public static function softDelete(int $id)
    {
        $db = Database::getConnection();
        $st = $db->prepare('UPDATE dre_codes SET ativo = 0 WHERE id = :id');
        return $st->execute(['id'=>$id]);
    }
}
