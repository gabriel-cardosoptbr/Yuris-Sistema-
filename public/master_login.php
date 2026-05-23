<?php
/**
 * Painel Master — portal de login isolado.
 *
 * URL pública: /sistema_vendas/public/master_login.php
 *
 * Diferenças do login regular (/login.php):
 *   • Só aceita usuários com registro ATIVO em super_admins
 *   • Mensagem genérica "acesso negado" pra não revelar quem é super
 *     (evita enumeration de contas privilegiadas)
 *   • Marca a sessão com $_SESSION['master_mode'] = true
 *     → o botão "Sair do Master" em master.php detecta e volta pra cá
 *       em vez de mandar pro app normal
 *   • Rate-limit reaproveita login_attempts (5 falhas em 15 min)
 *   • Visual roxo/violeta pra deixar claro que é outro contexto
 *
 * Segurança:
 *   • CSRF token obrigatório
 *   • Cookies HttpOnly + SameSite + Secure (se HTTPS)
 *   • session_regenerate_id após sucesso (anti-fixation)
 *   • Senha verificada com password_verify (bcrypt)
 *   • Nenhum log em texto plano de credenciais
 */
require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Models/User.php';

use App\Models\Database;
use App\Models\User;

// ── Cookie params seguros antes do session_start ────────────────────────────
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
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

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$flash = $_SESSION['flash_error_master'] ?? null;
unset($_SESSION['flash_error_master']);

// Se já está logado E é super_admin → atalho direto pro master.php
if (!empty($_SESSION['user_id']) && !empty($_SESSION['is_super_admin'])) {
    header('Location: /sistema_vendas/public/master.php');
    exit;
}

// ── POST: tenta autenticar ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo   = Database::getConnection();
    $login = trim($_POST['login']    ?? '');
    $pwd   = $_POST['password']      ?? '';
    $csrf  = $_POST['csrf_token']    ?? '';
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'cli';

    // CSRF
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        $_SESSION['flash_error_master'] = 'Token inválido. Recarregue a página.';
        header('Location: /sistema_vendas/public/master_login.php');
        exit;
    }

    // Rate-limit (reusa login_attempts, prefixa login pra não conflitar
    // com tentativas no /login.php normal)
    $rlLogin = '[MASTER]' . $login;
    try {
        $rl = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip = :ip AND login = :login
               AND created_at >= DATE_SUB(NOW(), INTERVAL 900 SECOND)
               AND success = 0"
        );
        $rl->execute(['ip' => $ip, 'login' => $rlLogin]);
        if ((int) $rl->fetchColumn() >= 5) {
            $_SESSION['flash_error_master'] = 'Muitas tentativas. Tente novamente em 15 minutos.';
            header('Location: /sistema_vendas/public/master_login.php');
            exit;
        }
    } catch (\Throwable $_e) { /* tabela ausente — sem rate limit */ }

    // Helper pra logar falha de forma uniforme
    $registerFail = function () use ($pdo, $ip, $rlLogin) {
        try {
            $pdo->prepare(
                'INSERT INTO login_attempts (ip, login, success, created_at) VALUES (:ip, :login, 0, NOW())'
            )->execute(['ip' => $ip, 'login' => $rlLogin]);
        } catch (\Throwable $_e) { /* silently */ }
    };

    // 1. Usuário existe?
    $user = User::findByLogin($login);
    if (!$user || !password_verify($pwd, $user['senha_hash'])) {
        $registerFail();
        // Mensagem GENÉRICA pra não vazar se o user existe ou não, nem se é super
        $_SESSION['flash_error_master'] = 'Credenciais inválidas ou acesso negado.';
        header('Location: /sistema_vendas/public/master_login.php');
        exit;
    }

    // 2. Está soft-deletado / inativo?
    if (!empty($user['deleted_at']) || (isset($user['status']) && $user['status'] !== 'active')) {
        $registerFail();
        $_SESSION['flash_error_master'] = 'Credenciais inválidas ou acesso negado.';
        header('Location: /sistema_vendas/public/master_login.php');
        exit;
    }

    // 3. É super_admin ATIVO?
    $sa = null;
    try {
        $stmtSa = $pdo->prepare(
            "SELECT id, nivel FROM super_admins
             WHERE user_id = :uid AND ativo = 1 LIMIT 1"
        );
        $stmtSa->execute(['uid' => $user['id']]);
        $sa = $stmtSa->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $_e) {
        // tabela ausente → nega
    }
    if (!$sa) {
        $registerFail();
        $_SESSION['flash_error_master'] = 'Credenciais inválidas ou acesso negado.';
        header('Location: /sistema_vendas/public/master_login.php');
        exit;
    }

    // 4. Carrega conta (tenant) do super_admin pra popular sessão completa
    $account = null;
    try {
        $stAcc = $pdo->prepare(
            'SELECT a.id, a.nome, a.tipo, a.status, a.plano
             FROM accounts a
             INNER JOIN users u ON u.account_id = a.id
             WHERE u.id = :uid AND a.deleted_at IS NULL LIMIT 1'
        );
        $stAcc->execute(['uid' => $user['id']]);
        $account = $stAcc->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $_e) { /* opcional */ }

    // 5. Sucesso — popula sessão e marca master_mode
    $_SESSION['user_id']           = (int) $user['id'];
    $_SESSION['user_nome']         = $user['nome'];
    $_SESSION['user_perfil']       = $user['perfil'] ?? 'admin';
    $_SESSION['user_role']         = $user['role']   ?? 'owner';
    $_SESSION['user_permissions']  = ['*'];
    $_SESSION['is_super_admin']    = true;
    $_SESSION['super_admin_level'] = $sa['nivel'] ?? 'operator';
    $_SESSION['master_mode']       = true;   // ← marca que veio via portal master

    if ($account) {
        $_SESSION['account_id']    = (int) $account['id'];
        $_SESSION['account_tipo']  = $account['tipo'];
        $_SESSION['account_nome']  = $account['nome'];
        $_SESSION['account_plano'] = $account['plano'] ?? 'basico';
    } else {
        // super_admin sem conta vinculada — usa user_id como account_id (legado)
        $_SESSION['account_id']    = (int) $user['id'];
        $_SESSION['account_tipo']  = 'matriz';
        $_SESSION['account_nome']  = $user['nome'];
        $_SESSION['account_plano'] = 'basico';
    }

    // Anti-fixation
    session_regenerate_id(true);

    // Atualiza last_login_at
    try {
        $pdo->prepare('UPDATE super_admins SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $sa['id']]);
    } catch (\Throwable $_e) {}

    // Limpa contador de falhas
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE ip = :ip AND login = :login AND success = 0')
            ->execute(['ip' => $ip, 'login' => $rlLogin]);
    } catch (\Throwable $_e) {}

    header('Location: /sistema_vendas/public/master.php');
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Painel Master — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/sistema_vendas/public/assets/favicon-192.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --purple-1:#a855f7;
      --purple-2:#7c3aed;
      --bg:#0a0418;
    }
    html, body { margin:0; padding:0; height:100%; }
    body {
      background-color: var(--bg);
      background-image:
        radial-gradient(ellipse at 18% 32%, rgba(168,85,247,0.18) 0%, transparent 55%),
        radial-gradient(ellipse at 82% 78%, rgba(124,58,237,0.12) 0%, transparent 60%);
      color:#E8EDF5;
      font-family: Inter, system-ui, sans-serif;
      display:flex; align-items:center; justify-content:center;
      min-height:100vh;
    }
    .ml-card {
      width:100%; max-width:420px;
      background: linear-gradient(165deg, #1a0f2e, #0a0418);
      border: 1px solid rgba(168,85,247,0.25);
      border-radius: 14px;
      padding: 36px 32px 28px;
      box-shadow: 0 30px 60px rgba(0,0,0,.55),
                  0 0 0 1px rgba(168,85,247,0.10) inset;
    }
    .ml-brand { text-align:center; margin-bottom:24px; }
    .ml-brand-ico {
      display:inline-flex; align-items:center; justify-content:center;
      width:54px; height:54px; border-radius:14px;
      background: linear-gradient(135deg, var(--purple-1), var(--purple-2));
      box-shadow: 0 8px 18px rgba(168,85,247,.35);
      font-size: 1.7rem; margin-bottom: 14px;
    }
    .ml-title {
      font-size: 1.45rem; font-weight: 700; color: #fff;
      letter-spacing: .01em; margin: 0 0 4px;
    }
    .ml-sub {
      font-size: .8rem; color: #b6a3d4;
      text-transform: uppercase; letter-spacing: .12em;
    }
    .ml-warn {
      margin-top: 14px; padding: 9px 12px;
      background: rgba(168,85,247,.07);
      border: 1px solid rgba(168,85,247,.20);
      border-radius: 8px;
      font-size: .74rem; color: #c084fc;
      text-align: center;
    }
    .ml-field { margin-bottom: 14px; }
    .ml-label {
      display:block; font-size:.7rem; color:#a89bbf;
      text-transform:uppercase; letter-spacing:.08em;
      margin-bottom: 5px; font-weight: 600;
    }
    .ml-input {
      width:100%; padding: 11px 13px;
      background: rgba(8,3,22,.7);
      border: 1px solid rgba(168,85,247,.18);
      border-radius: 8px;
      color: #fff; font-size: .92rem; font-family: inherit;
      transition: border-color .15s, box-shadow .15s;
      box-sizing: border-box;
    }
    .ml-input:focus {
      outline: none;
      border-color: rgba(168,85,247,.55);
      box-shadow: 0 0 0 4px rgba(168,85,247,.13);
    }
    .ml-btn {
      width: 100%; padding: 12px;
      background: linear-gradient(135deg, var(--purple-1), var(--purple-2));
      color: #fff; border: none; border-radius: 8px;
      font-weight: 700; font-size: .92rem; cursor: pointer;
      letter-spacing: .02em;
      transition: transform .12s, box-shadow .12s;
      margin-top: 6px;
    }
    .ml-btn:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(124,58,237,.35); }
    .ml-btn:active { transform: translateY(0); }
    .ml-flash {
      padding: 10px 13px;
      background: rgba(220,38,38,.12);
      border: 1px solid rgba(220,38,38,.40);
      border-radius: 8px;
      color: #fca5a5;
      font-size: .82rem;
      margin-bottom: 14px;
    }
    .ml-foot {
      text-align: center; margin-top: 22px;
      font-size: .72rem; color: #8b7daa;
    }
    .ml-foot a { color: #c084fc; text-decoration: none; }
    .ml-foot a:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <main class="ml-card">
    <div class="ml-brand">
      <div class="ml-brand-ico">🛰️</div>
      <h1 class="ml-title">Painel Master</h1>
      <div class="ml-sub">Acesso restrito · Super Admin</div>
      <div class="ml-warn">Este portal é separado do app principal. Suas tentativas são auditadas.</div>
    </div>

    <?php if ($flash): ?>
      <div class="ml-flash"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

      <div class="ml-field">
        <label class="ml-label" for="ml_login">E-mail / Login</label>
        <input class="ml-input" id="ml_login" name="login" type="text" required autofocus>
      </div>
      <div class="ml-field">
        <label class="ml-label" for="ml_pwd">Senha</label>
        <input class="ml-input" id="ml_pwd" name="password" type="password" required>
      </div>

      <button class="ml-btn" type="submit">Entrar no Painel Master →</button>
    </form>

    <div class="ml-foot">
      Procurando o app normal? <a href="/sistema_vendas/public/login.php">Ir pro login do sistema</a>
    </div>
  </main>
</body>
</html>
