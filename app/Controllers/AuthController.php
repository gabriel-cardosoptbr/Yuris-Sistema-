<?php
namespace App\Controllers;

use App\Models\User;
use App\Services\WebhookDispatcher;

/**
 * AuthController — login/logout + hardening de sessão.
 *
 * Mudanças desta versão (Fase 0 do plano de auditoria):
 *  - Removido log em texto plano (storage/login_debug.log) que vazava
 *    e-mails, IPs e hashes.
 *  - Adicionado check explícito de accounts.status='active' (bloqueia
 *    suspended/cancelled de logar).
 *  - Adicionado rate limiting básico (5 tentativas inválidas em 15min
 *    por IP+login) com fallback silencioso se a tabela login_attempts
 *    não existir.
 *  - Cookies de sessão com flags HttpOnly + SameSite=Lax + Secure
 *    (Secure só se HTTPS, pra não quebrar dev).
 *  - Senha em texto plano (users.senha_texto) deixa de ser populada;
 *    se a coluna ainda existir, é tratada como NULL.
 */
class AuthController
{
    /** Tabelas-dependências opcionais. Falhas são silenciosas. */
    private const RATE_LIMIT_MAX     = 5;
    private const RATE_LIMIT_WINDOW  = 900; // 15 min em segundos

    public static function attemptLogin()
    {
        self::ensureSessionStarted();

        // ── CSRF ────────────────────────────────────────────────────────────
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Token inválido.';
            header('Location: /sistema_vendas/public/login.php');
            exit;
        }

        $login    = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? 'cli';

        // ── Rate limit ──────────────────────────────────────────────────────
        if (self::isRateLimited($ip, $login)) {
            $_SESSION['flash_error'] = 'Muitas tentativas. Tente novamente em 15 minutos.';
            header('Location: /sistema_vendas/public/login.php');
            exit;
        }

        // ── Busca usuário ───────────────────────────────────────────────────
        $user = User::findByLogin($login);

        if (!$user || !password_verify($password, $user['senha_hash'])) {
            self::logFailedAttempt($ip, $login);
            $_SESSION['flash_error'] = 'Usuário ou senha inválidos.';
            header('Location: /sistema_vendas/public/login.php');
            exit;
        }

        // Usuário soft-deleted ou status inativo
        if (!empty($user['deleted_at']) || (isset($user['status']) && $user['status'] !== 'active')) {
            $_SESSION['flash_error'] = 'Esta conta está inativa. Contate o administrador.';
            header('Location: /sistema_vendas/public/login.php');
            exit;
        }

        // ── Verifica conta (tenant) ──────────────────────────────────────────
        $pdo     = \App\Models\Database::getConnection();
        $account = null;
        try {
            $stmtAcc = $pdo->prepare(
                'SELECT a.id, a.nome, a.tipo, a.status, a.plano, a.deleted_at
                 FROM accounts a
                 INNER JOIN users u ON u.account_id = a.id
                 WHERE u.id = :uid AND a.deleted_at IS NULL LIMIT 1'
            );
            $stmtAcc->execute(['uid' => $user['id']]);
            $account = $stmtAcc->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // tabela accounts ausente — modo single-tenant legado
        }

        // ⚠️ HARDENING: bloqueia status diferente de 'active' (Fase 0 audit)
        if ($account && $account['status'] !== 'active') {
            $msg = $account['status'] === 'suspended'
                 ? 'Conta suspensa. Entre em contato com o suporte.'
                 : 'Conta cancelada. Acesso negado.';
            $_SESSION['flash_error'] = $msg;
            header('Location: /sistema_vendas/public/login.php');
            exit;
        }

        // ── Login OK — popular sessão ──────────────────────────────────────
        $_SESSION['user_id']     = $user['id'];
        $_SESSION['user_nome']   = $user['nome'];
        $_SESSION['user_perfil'] = $user['perfil'];

        if ($account) {
            $_SESSION['account_id']    = (int) $account['id'];
            $_SESSION['account_tipo']  = $account['tipo'];
            $_SESSION['account_nome']  = $account['nome'];
            $_SESSION['account_plano'] = $account['plano'] ?? 'basico';
        } else {
            // single-tenant legacy: usa id do usuário como account_id
            $_SESSION['account_id']    = (int) $user['id'];
            $_SESSION['account_tipo']  = 'matriz';
            $_SESSION['account_nome']  = $user['nome'];
            $_SESSION['account_plano'] = 'basico';
        }

        // Role (mais granular que perfil)
        $_SESSION['user_role'] = $user['role'] ?? ($user['perfil'] === 'admin' ? 'owner' : 'user');

        // Page permissions (wildcards pra owner/admin; lista explícita pros demais)
        if (in_array($_SESSION['user_role'], ['owner', 'admin'], true) || $user['perfil'] === 'admin') {
            $_SESSION['user_permissions'] = ['*'];
        } else {
            try {
                $stmt = $pdo->prepare('SELECT page FROM user_permissions WHERE user_id = ?');
                $stmt->execute([$user['id']]);
                $_SESSION['user_permissions'] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            } catch (\Throwable $_e) {
                $_SESSION['user_permissions'] = [];
            }
        }

        // Anti-fixation
        session_regenerate_id(true);

        // Super admin (Painel Master) — checagem opcional sem quebrar legado
        try {
            $sa = $pdo->prepare('SELECT nivel FROM super_admins WHERE user_id = :uid AND ativo = 1 LIMIT 1');
            $sa->execute(['uid' => $user['id']]);
            $row = $sa->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $_SESSION['is_super_admin']    = true;
                $_SESSION['super_admin_level'] = $row['nivel'] ?? 'operator';
                $pdo->prepare('UPDATE super_admins SET last_login_at = NOW() WHERE user_id = :uid')
                    ->execute(['uid' => $user['id']]);
            }
        } catch (\Throwable $_e) { /* tabela ainda não existe — silencioso */ }

        // Rate-limit: zera contador em login bem-sucedido
        self::clearFailedAttempts($ip, $login);

        require_once __DIR__ . '/../Services/WebhookDispatcher.php';
        // P0 LGPD: passa account_id do usuário que logou, não global
        $loginAcc = isset($user['account_id']) ? (int)$user['account_id'] : null;
        WebhookDispatcher::fire($loginAcc, 'usuario.login', WebhookDispatcher::buildPayload('usuario.login', [
            'entity' => 'usuario', 'entity_id' => $user['id'],
            'data' => ['id' => $user['id'], 'nome' => $user['nome'], 'perfil' => $user['perfil'], 'ip' => $ip],
        ]));

        header('Location: /sistema_vendas/public/dashboard.php');
        exit;
    }

    public static function logout()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        foreach (array_keys($_SESSION) as $k) unset($_SESSION[$k]);

        // Remove cookie da sessão (defesa em profundidade)
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']);
        }

        session_destroy();
        header('Location: /sistema_vendas/public/login.php');
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers internos
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Inicializa sessão com cookies seguros (HttpOnly + SameSite + Secure).
     * Idempotente — não derruba sessão já iniciada.
     */
    private static function ensureSessionStarted(): void
    {
        if (session_status() !== PHP_SESSION_NONE) return;
        // Configura ANTES de session_start (no-op se já houver headers enviados)
        if (!headers_sent()) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_start();
    }

    /**
     * Verifica se IP+login excedeu o limite de tentativas.
     * Falha silenciosa se a tabela login_attempts ainda não existir.
     */
    private static function isRateLimited(string $ip, string $login): bool
    {
        try {
            $pdo = \App\Models\Database::getConnection();
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE ip = :ip AND login = :login
                   AND created_at >= DATE_SUB(NOW(), INTERVAL " . self::RATE_LIMIT_WINDOW . " SECOND)
                   AND success = 0"
            );
            $stmt->execute(['ip' => $ip, 'login' => $login]);
            return (int)$stmt->fetchColumn() >= self::RATE_LIMIT_MAX;
        } catch (\Throwable $_e) {
            return false; // se tabela não existe, não bloqueia
        }
    }

    private static function logFailedAttempt(string $ip, string $login): void
    {
        try {
            $pdo = \App\Models\Database::getConnection();
            $pdo->prepare(
                'INSERT INTO login_attempts (ip, login, success, created_at) VALUES (:ip, :login, 0, NOW())'
            )->execute(['ip' => $ip, 'login' => $login]);
        } catch (\Throwable $_e) { /* fail silently */ }
    }

    private static function clearFailedAttempts(string $ip, string $login): void
    {
        try {
            $pdo = \App\Models\Database::getConnection();
            $pdo->prepare(
                'DELETE FROM login_attempts WHERE ip = :ip AND login = :login AND success = 0'
            )->execute(['ip' => $ip, 'login' => $login]);
        } catch (\Throwable $_e) { /* fail silently */ }
    }
}
