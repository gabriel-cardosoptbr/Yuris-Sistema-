<?php
namespace App\Controllers;

use App\Models\User;
use App\Services\WebhookDispatcher;

class AuthController
{
    public static function attemptLogin()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Token inválido.';
            header('Location: /sistema_vendas/public/login.php');
            exit;
        }

            $login = trim($_POST['login'] ?? '');
            $password = $_POST['password'] ?? '';
            $user = User::findByLogin($login);

            // debug log (temporary) - do not expose in production
            $dbg = [];
            $dbg[] = "time=" . date('c');
            $dbg[] = "remote=" . ($_SERVER['REMOTE_ADDR'] ?? 'cli');
            $dbg[] = "login_post=" . $login;
            $dbg[] = "user_found=" . ($user ? '1' : '0');
            $dbg[] = "senha_hash=" . ($user['senha_hash'] ?? '');
            $pw_ok = ($user && password_verify($password, $user['senha_hash']));
            $dbg[] = "password_verify=" . ($pw_ok ? '1' : '0');
            @file_put_contents(__DIR__ . '/../../storage/login_debug.log', implode(' | ', $dbg) . PHP_EOL, FILE_APPEND);

            if (!$user) {
                $_SESSION['flash_error'] = 'Usuário ou senha inválidos.';
                header('Location: /sistema_vendas/public/login.php');
                exit;
            }
            if (!password_verify($password, $user['senha_hash'])) {
                $_SESSION['flash_error'] = 'Usuário ou senha inválidos.';
                header('Location: /sistema_vendas/public/login.php');
                exit;
            }
        // login ok
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_nome']  = $user['nome'];
        $_SESSION['user_perfil']= $user['perfil'];

        // ── Contexto de conta: usa tabela accounts se existir, fallback para usuário ──
        $pdo = \App\Models\Database::getConnection();

        $account = null;
        try {
            $stmtAcc = $pdo->prepare(
                'SELECT a.id, a.nome, a.tipo, a.status
                 FROM accounts a
                 INNER JOIN users u ON u.account_id = a.id
                 WHERE u.id = :uid AND a.deleted_at IS NULL LIMIT 1'
            );
            $stmtAcc->execute(['uid' => $user['id']]);
            $account = $stmtAcc->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // tabela accounts não existe — modo single-tenant
        }

        if ($account && $account['status'] === 'active') {
            $_SESSION['account_id']   = (int) $account['id'];
            $_SESSION['account_tipo'] = $account['tipo'];
            $_SESSION['account_nome'] = $account['nome'];
        } else {
            // single-tenant: usa id do usuário como account_id
            $_SESSION['account_id']   = (int) $user['id'];
            $_SESSION['account_tipo'] = 'matriz';
            $_SESSION['account_nome'] = $user['nome'];
        }

        // Carrega role do usuário (mais granular que perfil)
        $_SESSION['user_role'] = $user['role'] ?? ($user['perfil'] === 'admin' ? 'owner' : 'user');

        // load page permissions (admins/owners get wildcard, others get explicit list)
        if (in_array($_SESSION['user_role'], ['owner', 'admin']) || $user['perfil'] === 'admin') {
            $_SESSION['user_permissions'] = ['*'];
        } else {
            $stmt = $pdo->prepare('SELECT page FROM user_permissions WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            $_SESSION['user_permissions'] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        session_regenerate_id(true);

        require_once __DIR__ . '/../Services/WebhookDispatcher.php';
        WebhookDispatcher::fire('usuario.login', WebhookDispatcher::buildPayload('usuario.login', [
            'entity' => 'usuario', 'entity_id' => $user['id'],
            'data' => ['id' => $user['id'], 'nome' => $user['nome'], 'perfil' => $user['perfil'], 'ip' => $_SERVER['REMOTE_ADDR'] ?? null],
        ]));

        header('Location: /sistema_vendas/public/dashboard.php');
        exit;
    }

    public static function logout()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        foreach (array_keys($_SESSION) as $k) unset($_SESSION[$k]);
        session_destroy();
        header('Location: /sistema_vendas/public/login.php');
        exit;
    }
}
