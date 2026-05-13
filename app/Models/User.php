<?php
namespace App\Models;

use App\Models\Database;

class User
{
    public static function findByLogin($login)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE login = :login AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['login' => $login]);
        return $stmt->fetch();
    }

    public static function findById($id)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public static function create($nome, $login, $password_hash, $perfil = 'user')
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO users (nome, login, senha_hash, perfil, status, created_at, updated_at) VALUES (:nome, :login, :senha_hash, :perfil, :status, NOW(), NOW())');
        $stmt->execute([
            'nome' => $nome,
            'login' => $login,
            'senha_hash' => $password_hash,
            'perfil' => $perfil,
            'status' => 'active'
        ]);
        return $pdo->lastInsertId();
    }
}
