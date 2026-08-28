<?php
require_once __DIR__ . '/../app/bootstrap.php';

// Usage: php scripts/check_user.php login password
if ($argc < 3) {
    echo "Usage: php scripts/check_user.php <login> <password>\n";
    exit(2);
}
$login = $argv[1];
$pwd = $argv[2];

// Use the Database class directly
$db = \App\Core\Database::getConnection();
$stmt = $db->prepare('SELECT id, nome, login, senha_hash, perfil, deleted_at FROM users WHERE login = :login LIMIT 1');
$stmt->execute(['login' => $login]);
$user = $stmt->fetch();
if (!$user) {
    echo "NOT_FOUND\n";
    exit(3);
}
$ok = password_verify($pwd, $user['senha_hash']);
echo json_encode([
    'found' => true,
    'id' => $user['id'],
    'nome' => $user['nome'],
    'login' => $user['login'],
    'perfil' => $user['perfil'],
    'password_match' => $ok
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit($ok ? 0 : 4);
