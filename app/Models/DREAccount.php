<?php
namespace App\Models;

require_once __DIR__ . '/Database.php';

use App\Models\Database;

class DREAccount
{
    public static function listAll()
    {
        $db = Database::getConnection();
        $st = $db->query('SELECT * FROM dre_accounts WHERE ativo = 1 ORDER BY tipo, nome');
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function find(int $id)
    {
        $db = Database::getConnection();
        $st = $db->prepare('SELECT * FROM dre_accounts WHERE id = :id LIMIT 1');
        $st->execute(['id'=>$id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function create(array $data)
    {
        $db = Database::getConnection();
        $st = $db->prepare('INSERT INTO dre_accounts (codigo,nome,tipo,valor_fixo,recorrencia,data_referencia,ativo) VALUES (:codigo,:nome,:tipo,:valor_fixo,:recorrencia,:data_referencia,:ativo)');
        $ok = $st->execute([
            'codigo' => $data['codigo'] ?? null,
            'nome' => $data['nome'] ?? '',
            'tipo' => ($data['tipo'] ?? 'despesa'),
            'valor_fixo' => isset($data['valor_fixo']) ? (float)$data['valor_fixo'] : 0,
            'recorrencia' => ($data['recorrencia'] ?? 'fixa'),
            'data_referencia' => !empty($data['data_referencia']) ? $data['data_referencia'] : null,
            'ativo' => isset($data['ativo']) ? (int)$data['ativo'] : 1
        ]);
        return $ok ? (int)$db->lastInsertId() : 0;
    }

    public static function update(int $id, array $data)
    {
        $db = Database::getConnection();
        $st = $db->prepare('UPDATE dre_accounts SET codigo = :codigo, nome = :nome, tipo = :tipo, valor_fixo = :valor_fixo, recorrencia = :recorrencia, data_referencia = :data_referencia, ativo = :ativo WHERE id = :id');
        return $st->execute([
            'codigo' => $data['codigo'] ?? null,
            'nome' => $data['nome'] ?? '',
            'tipo' => ($data['tipo'] ?? 'despesa'),
            'valor_fixo' => isset($data['valor_fixo']) ? (float)$data['valor_fixo'] : 0,
            'recorrencia' => ($data['recorrencia'] ?? 'fixa'),
            'data_referencia' => !empty($data['data_referencia']) ? $data['data_referencia'] : null,
            'ativo' => isset($data['ativo']) ? (int)$data['ativo'] : 1,
            'id' => $id
        ]);
    }

    public static function softDelete(int $id)
    {
        $db = Database::getConnection();
        $st = $db->prepare('UPDATE dre_accounts SET ativo = 0 WHERE id = :id');
        return $st->execute(['id'=>$id]);
    }

    public static function summary()
    {
        $db = Database::getConnection();
        $st = $db->query("SELECT tipo, SUM(valor_fixo) as total FROM dre_accounts WHERE ativo = 1 GROUP BY tipo");
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $out = ['receita'=>0.0,'despesa'=>0.0];
        foreach ($rows as $r) {
            $out[$r['tipo']] = (float)$r['total'];
        }
        $out['resultado'] = $out['receita'] - $out['despesa'];
        return $out;
    }
}
