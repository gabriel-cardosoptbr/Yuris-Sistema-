<?php
// Script para criar/atualizar usuário admin (senha: senha123)
require __DIR__ . '/../app/Models/Database.php';

use App\Models\Database;

$login = 'admin';
$senha = 'senha123';
$nome = 'Administrador';
$perfil = 'admin';

$pdo = Database::getConnection();

$hash = password_hash($senha, PASSWORD_BCRYPT);

$stmt = $pdo->prepare('SELECT id FROM users WHERE login = :login LIMIT 1');
$stmt->execute(['login' => $login]);
$existing = $stmt->fetch();

if ($existing) {
    $stmt = $pdo->prepare('UPDATE users SET senha_hash = :hash, perfil = :perfil, updated_at = NOW(), deleted_at = NULL WHERE id = :id');
    $stmt->execute(['hash' => $hash, 'perfil' => $perfil, 'id' => $existing['id']]);
    echo "Usuário 'admin' atualizado.\n";
} else {
    $stmt = $pdo->prepare('INSERT INTO users (nome, login, senha_hash, perfil, status, created_at, updated_at) VALUES (:nome, :login, :hash, :perfil, :status, NOW(), NOW())');
    $stmt->execute(['nome' => $nome, 'login' => $login, 'hash' => $hash, 'perfil' => $perfil, 'status' => 'active']);
    echo "Usuário 'admin' criado.\n";
}

echo "Login: admin\nSenha: senha123\n";
