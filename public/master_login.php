<?php
/**
 * Painel Master — portal de login isolado.
 *
 * URL pública: /master_login.php
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
 * LGPD P0 (1.9) — 2FA / TOTP opt-in:
 *   • Fluxo 2-step quando super_admin tiver mfa_enabled=1
 *   • ?step=otp renderiza segundo passo de input do código TOTP
 *   • Aceita backup code (10 chars XXXXX-XXXXX) como fallback
 *   • Rate-limit reaproveitado com prefixo "[MASTER_OTP]"
 *
 * Segurança:
 *   • CSRF token obrigatório em ambos os passos
 *   • Cookies HttpOnly + SameSite + Secure (se HTTPS)
 *   • session_regenerate_id após sucesso (anti-fixation)
 *   • Senha verificada com password_verify (bcrypt)
 *   • Nenhum log em texto plano de credenciais
 *   • Sessão de "mfa_pending" expira em 5 minutos
 */
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Usuarios/User.php';
require_once __DIR__ . '/../app/Core/EnvLoader.php';
require_once __DIR__ . '/../app/Usuarios/TotpHelper.php';

use App\Core\Database;
use App\Usuarios\User;
use App\Usuarios\TotpHelper;

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
if (!empty($_SESSION['user_id']) && !empty($_SESSION['is_super_admin']) && !empty($_SESSION['master_mode'])) {
    header('Location: /master.php');
    exit;
}

$ip   = $_SERVER['REMOTE_ADDR'] ?? 'cli';
$step = $_GET['step'] ?? '';

// ─── Step OTP: 2º passo do login (após senha) ────────────────────────────────
$mfaPending = $_SESSION['mfa_pending_login'] ?? null;
// Expira sessão de pending após 5 min
if ($mfaPending && (time() - (int)($mfaPending['started_at'] ?? 0) > 300)) {
    unset($_SESSION['mfa_pending_login']);
    $mfaPending = null;
    $_SESSION['flash_error_master'] = 'Sessão de 2FA expirada. Faça login novamente.';
    header('Location: /master_login.php');
    exit;
}

if ($step === 'otp' && !$mfaPending) {
    // Não há setup pendente; manda pra tela de senha
    header('Location: /master_login.php');
    exit;
}

if ($step === 'otp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo  = Database::getConnection();
    $csrf = $_POST['csrf_token'] ?? '';
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        $_SESSION['flash_error_master'] = 'Token inválido. Recarregue a página.';
        header('Location: /master_login.php?step=otp');
        exit;
    }

    $rlLogin = '[MASTER_OTP]' . ($mfaPending['login'] ?? 'unknown');
    // Rate-limit de tentativas OTP (5 em 15min)
    try {
        $rl = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip = :ip AND login = :login
               AND created_at >= DATE_SUB(NOW(), INTERVAL 900 SECOND)
               AND success = 0"
        );
        $rl->execute(['ip' => $ip, 'login' => $rlLogin]);
        if ((int)$rl->fetchColumn() >= 5) {
            unset($_SESSION['mfa_pending_login']);
            $_SESSION['flash_error_master'] = 'Muitas tentativas. Tente novamente em 15 minutos.';
            header('Location: /master_login.php');
            exit;
        }
    } catch (\Throwable $_e) {}

    $registerFail = function () use ($pdo, $ip, $rlLogin) {
        try {
            $pdo->prepare('INSERT INTO login_attempts (ip, login, success, created_at) VALUES (:ip, :login, 0, NOW())')
                ->execute(['ip' => $ip, 'login' => $rlLogin]);
        } catch (\Throwable $_e) {}
    };

    $code = trim((string)($_POST['code'] ?? ''));
    $useBackup = !empty($_POST['backup']);

    $userId = (int)$mfaPending['user_id'];
    $saStmt = $pdo->prepare(
        'SELECT id, mfa_secret, mfa_backup_codes_hash FROM super_admins
         WHERE user_id = :uid AND ativo = 1 AND mfa_enabled = 1 LIMIT 1'
    );
    $saStmt->execute(['uid' => $userId]);
    $sa = $saStmt->fetch(\PDO::FETCH_ASSOC);

    if (!$sa || !$sa['mfa_secret']) {
        unset($_SESSION['mfa_pending_login']);
        $_SESSION['flash_error_master'] = 'Configuração 2FA inconsistente. Contate suporte.';
        header('Location: /master_login.php');
        exit;
    }

    $ok = false;
    if ($useBackup) {
        // Valida backup code (10 chars hifenizado ou 11 com hyphen)
        $hashes = json_decode($sa['mfa_backup_codes_hash'] ?? '[]', true) ?: [];
        $matchIdx = TotpHelper::verifyBackupCode($code, $hashes);
        if ($matchIdx !== null) {
            // Consome (remove o hash usado)
            array_splice($hashes, $matchIdx, 1);
            $pdo->prepare('UPDATE super_admins SET mfa_backup_codes_hash = :bk WHERE id = :id')
                ->execute(['bk' => json_encode(array_values($hashes)), 'id' => $sa['id']]);
            $ok = true;
        }
    } else {
        // Valida TOTP
        try {
            $secret = TotpHelper::decryptSecret($sa['mfa_secret']);
            $ok = TotpHelper::verify($secret, $code, 1);
        } catch (\Throwable $e) {
            error_log('[master_login] decifrar secret falhou: ' . $e->getMessage());
            $ok = false;
        }
    }

    if (!$ok) {
        $registerFail();
        $_SESSION['flash_error_master'] = $useBackup
            ? 'Backup code inválido ou já utilizado.'
            : 'Código incorreto. Verifique a hora do seu dispositivo.';
        header('Location: /master_login.php?step=otp');
        exit;
    }

    // ✓ OTP/backup válido → finaliza login (popula sessão completa)
    $_SESSION['user_id']           = $userId;
    $_SESSION['user_nome']         = $mfaPending['user_nome'];
    $_SESSION['user_perfil']       = $mfaPending['user_perfil'];
    $_SESSION['user_role']         = $mfaPending['user_role'];
    $_SESSION['user_permissions']  = ['*'];
    $_SESSION['is_super_admin']    = true;
    $_SESSION['super_admin_level'] = $mfaPending['super_admin_level'];
    $_SESSION['master_mode']       = true;
    $_SESSION['account_id']        = (int)$mfaPending['account_id'];
    $_SESSION['account_tipo']      = $mfaPending['account_tipo'];
    $_SESSION['account_nome']      = $mfaPending['account_nome'];
    $_SESSION['account_plano']     = $mfaPending['account_plano'];

    unset($_SESSION['mfa_pending_login']);
    session_regenerate_id(true);

    try {
        $pdo->prepare('UPDATE super_admins SET last_login_at = NOW(), mfa_last_used_at = NOW() WHERE id = :id')
            ->execute(['id' => $sa['id']]);
    } catch (\Throwable $_e) {}
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE ip = :ip AND login = :login AND success = 0')
            ->execute(['ip' => $ip, 'login' => $rlLogin]);
    } catch (\Throwable $_e) {}

    header('Location: /master.php');
    exit;
}

// ─── POST: tenta autenticar (passo 1 — senha) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step !== 'otp') {
    $pdo   = Database::getConnection();
    $login = trim($_POST['login']    ?? '');
    $pwd   = $_POST['password']      ?? '';
    $csrf  = $_POST['csrf_token']    ?? '';

    // CSRF
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        $_SESSION['flash_error_master'] = 'Token inválido. Recarregue a página.';
        header('Location: /master_login.php');
        exit;
    }

    // Rate-limit (reusa login_attempts, prefixa login pra não conflitar)
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
            header('Location: /master_login.php');
            exit;
        }
    } catch (\Throwable $_e) {}

    $registerFail = function () use ($pdo, $ip, $rlLogin) {
        try {
            $pdo->prepare('INSERT INTO login_attempts (ip, login, success, created_at) VALUES (:ip, :login, 0, NOW())')
                ->execute(['ip' => $ip, 'login' => $rlLogin]);
        } catch (\Throwable $_e) {}
    };

    // 1. Usuário existe?
    $user = User::findByLogin($login);
    if (!$user || !password_verify($pwd, $user['senha_hash'])) {
        $registerFail();
        $_SESSION['flash_error_master'] = 'Credenciais inválidas ou acesso negado.';
        header('Location: /master_login.php');
        exit;
    }

    // 2. Está soft-deletado / inativo?
    if (!empty($user['deleted_at']) || (isset($user['status']) && $user['status'] !== 'active')) {
        $registerFail();
        $_SESSION['flash_error_master'] = 'Credenciais inválidas ou acesso negado.';
        header('Location: /master_login.php');
        exit;
    }

    // 3. É super_admin ATIVO?
    $sa = null;
    try {
        $stmtSa = $pdo->prepare(
            "SELECT id, nivel, mfa_enabled FROM super_admins
             WHERE user_id = :uid AND ativo = 1 LIMIT 1"
        );
        $stmtSa->execute(['uid' => $user['id']]);
        $sa = $stmtSa->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $_e) {}
    if (!$sa) {
        $registerFail();
        $_SESSION['flash_error_master'] = 'Credenciais inválidas ou acesso negado.';
        header('Location: /master_login.php');
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
    } catch (\Throwable $_e) {}

    // Dados que serão usados pra finalizar sessão (após OTP se necessário)
    $sessionData = [
        'user_id'           => (int) $user['id'],
        'user_nome'         => $user['nome'],
        'user_perfil'       => $user['perfil'] ?? 'admin',
        'user_role'         => $user['role']   ?? 'owner',
        'super_admin_level' => $sa['nivel'] ?? 'operator',
        'account_id'        => $account ? (int)$account['id'] : (int)$user['id'],
        'account_tipo'      => $account ? $account['tipo']    : 'matriz',
        'account_nome'      => $account ? $account['nome']    : $user['nome'],
        'account_plano'     => $account ? ($account['plano'] ?? 'basico') : 'basico',
    ];

    // ─── LGPD P0 (1.9): 2FA TOTP — se habilitado, exige código antes de logar ─
    if ((int)($sa['mfa_enabled'] ?? 0) === 1) {
        // Limpa contador de falhas do passo 1 (senha estava correta)
        try {
            $pdo->prepare('DELETE FROM login_attempts WHERE ip = :ip AND login = :login AND success = 0')
                ->execute(['ip' => $ip, 'login' => $rlLogin]);
        } catch (\Throwable $_e) {}

        // Cria sessão temporária pendente (expira em 5min)
        $_SESSION['mfa_pending_login'] = array_merge($sessionData, [
            'login'      => $login,
            'started_at' => time(),
        ]);
        // Não regenera sessão ainda — só após OTP válido
        header('Location: /master_login.php?step=otp');
        exit;
    }
    // ──────────────────────────────────────────────────────────────────────────

    // 5. Sucesso SEM 2FA — popula sessão completa direto
    $_SESSION['user_id']           = $sessionData['user_id'];
    $_SESSION['user_nome']         = $sessionData['user_nome'];
    $_SESSION['user_perfil']       = $sessionData['user_perfil'];
    $_SESSION['user_role']         = $sessionData['user_role'];
    $_SESSION['user_permissions']  = ['*'];
    $_SESSION['is_super_admin']    = true;
    $_SESSION['super_admin_level'] = $sessionData['super_admin_level'];
    $_SESSION['master_mode']       = true;
    $_SESSION['account_id']        = $sessionData['account_id'];
    $_SESSION['account_tipo']      = $sessionData['account_tipo'];
    $_SESSION['account_nome']      = $sessionData['account_nome'];
    $_SESSION['account_plano']     = $sessionData['account_plano'];

    session_regenerate_id(true);

    try {
        $pdo->prepare('UPDATE super_admins SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $sa['id']]);
    } catch (\Throwable $_e) {}
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE ip = :ip AND login = :login AND success = 0')
            ->execute(['ip' => $ip, 'login' => $rlLogin]);
    } catch (\Throwable $_e) {}

    header('Location: /master.php');
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Painel Master — Yuris</title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --purple-1:#a855f7; --purple-2:#7c3aed; --bg:#0a0418; }
    html, body { margin:0; padding:0; height:100%; }
    body {
      background-color: var(--bg);
      background-image:
        radial-gradient(ellipse at 18% 32%, rgba(168,85,247,0.18) 0%, transparent 55%),
        radial-gradient(ellipse at 82% 78%, rgba(124,58,237,0.12) 0%, transparent 60%);
      color:#E8EDF5; font-family: Inter, system-ui, sans-serif;
      display:flex; align-items:center; justify-content:center; min-height:100vh;
    }
    .ml-card {
      width:100%; max-width:420px;
      background: linear-gradient(165deg, #1a0f2e, #0a0418);
      border: 1px solid rgba(168,85,247,0.25);
      border-radius: 14px;
      padding: 36px 32px 28px;
      box-shadow: 0 30px 60px rgba(0,0,0,.55), 0 0 0 1px rgba(168,85,247,0.10) inset;
    }
    .ml-brand { text-align:center; margin-bottom:24px; }
    .ml-brand-ico {
      display:inline-flex; align-items:center; justify-content:center;
      width:54px; height:54px; border-radius:14px;
      background: linear-gradient(135deg, var(--purple-1), var(--purple-2));
      box-shadow: 0 8px 18px rgba(168,85,247,.35);
      margin-bottom: 14px;
    }
    .ml-title { font-size: 1.45rem; font-weight: 700; color: #fff; letter-spacing: .01em; margin: 0 0 4px; }
    .ml-sub { font-size: .8rem; color: #b6a3d4; text-transform: uppercase; letter-spacing: .12em; }
    .ml-warn { margin-top: 14px; padding: 9px 12px; background: rgba(168,85,247,.07);
      border: 1px solid rgba(168,85,247,.20); border-radius: 8px;
      font-size: .74rem; color: #c084fc; text-align: center; }
    .ml-field { margin-bottom: 14px; }
    .ml-label { display:block; font-size:.7rem; color:#a89bbf; text-transform:uppercase;
      letter-spacing:.08em; margin-bottom: 5px; font-weight: 600; }
    .ml-input {
      width:100%; padding: 11px 13px; background: rgba(8,3,22,.7);
      border: 1px solid rgba(168,85,247,.18); border-radius: 8px;
      color: #fff; font-size: .92rem; font-family: inherit;
      transition: border-color .15s, box-shadow .15s; box-sizing: border-box;
    }
    .ml-input:focus { outline: none; border-color: rgba(168,85,247,.55); box-shadow: 0 0 0 4px rgba(168,85,247,.13); }
    .ml-input.otp {
      font-family: 'Courier New', monospace; font-size: 1.5rem;
      letter-spacing: .35em; text-align: center;
    }
    .ml-btn {
      width: 100%; padding: 12px;
      background: linear-gradient(135deg, var(--purple-1), var(--purple-2));
      color: #fff; border: none; border-radius: 8px;
      font-weight: 700; font-size: .92rem; cursor: pointer;
      letter-spacing: .02em; transition: transform .12s, box-shadow .12s;
      margin-top: 6px;
    }
    .ml-btn:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(124,58,237,.35); }
    .ml-btn:active { transform: translateY(0); }
    .ml-flash {
      padding: 10px 13px; background: rgba(220,38,38,.12);
      border: 1px solid rgba(220,38,38,.40); border-radius: 8px;
      color: #fca5a5; font-size: .82rem; margin-bottom: 14px;
    }
    .ml-foot { text-align: center; margin-top: 22px; font-size: .72rem; color: #8b7daa; }
    .ml-foot a { color: #c084fc; text-decoration: none; }
    .ml-foot a:hover { text-decoration: underline; }
    .ml-toggle {
      background: none; border: none; padding: 0; margin-top: 10px;
      color: #c084fc; font-size: .76rem; cursor: pointer; text-decoration: underline;
    }
  </style>
</head>
<body>
  <main class="ml-card">
    <div class="ml-brand">
      <div class="ml-brand-ico">
        <?php if ($step === 'otp'): ?>
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <?php else: ?>
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
        <?php endif; ?>
      </div>
      <h1 class="ml-title"><?= $step === 'otp' ? 'Verificação 2FA' : 'Painel Master' ?></h1>
      <div class="ml-sub"><?= $step === 'otp' ? 'Segundo fator · TOTP' : 'Acesso restrito · Super Admin' ?></div>
      <div class="ml-warn">
        <?= $step === 'otp'
            ? 'Abra seu app de autenticação (Google Authenticator, Authy, 1Password, etc) e digite o código de 6 dígitos.'
            : 'Este portal é separado do app principal. Suas tentativas são auditadas.' ?>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="ml-flash"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php if ($step === 'otp'): ?>
      <form method="post" autocomplete="off" id="otpForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="backup" id="useBackup" value="">

        <div class="ml-field">
          <label class="ml-label" for="ml_otp" id="otpLabel">Código de 6 dígitos</label>
          <input class="ml-input otp" id="ml_otp" name="code" type="text" maxlength="11"
                 inputmode="numeric" pattern="[0-9]{6}" required autofocus
                 placeholder="000000">
        </div>

        <button class="ml-btn" type="submit">Entrar →</button>
        <button class="ml-toggle" type="button" onclick="toggleBackup()">
          <span id="toggleTxt">Não tenho acesso ao app — usar backup code</span>
        </button>
      </form>
      <script>
        function toggleBackup() {
          const useBackup = document.getElementById('useBackup');
          const input     = document.getElementById('ml_otp');
          const label     = document.getElementById('otpLabel');
          const txt       = document.getElementById('toggleTxt');
          if (useBackup.value === '1') {
            useBackup.value = '';
            input.setAttribute('inputmode', 'numeric');
            input.setAttribute('pattern', '[0-9]{6}');
            input.setAttribute('maxlength', '11');
            input.setAttribute('placeholder', '000000');
            label.textContent = 'Código de 6 dígitos';
            txt.textContent = 'Não tenho acesso ao app — usar backup code';
          } else {
            useBackup.value = '1';
            input.removeAttribute('pattern');
            input.removeAttribute('inputmode');
            input.setAttribute('maxlength', '11');
            input.setAttribute('placeholder', 'XXXXX-XXXXX');
            label.textContent = 'Backup code (formato XXXXX-XXXXX)';
            txt.textContent = 'Voltar para o app de autenticação';
          }
          input.value = '';
          input.focus();
        }
      </script>
    <?php else: ?>
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

        <!-- LGPD Etapa 5: aceite obrigatório de termos -->
        <div class="ml-field" style="font-size:12px;color:#8b7daa;line-height:1.45">
          <label style="display:flex;align-items:flex-start;gap:7px;cursor:pointer">
            <input type="checkbox" name="aceite_termos" required style="margin-top:2px;accent-color:#a855f7">
            <span>
              Li e concordo com os
              <a href="/termos.php" target="_blank" style="color:#c084fc">Termos de Uso</a>
              e a
              <a href="/privacidade.php" target="_blank" style="color:#c084fc">Política de Privacidade</a>.
            </span>
          </label>
        </div>

        <button class="ml-btn" type="submit">Entrar no Painel Master →</button>
      </form>
    <?php endif; ?>

    <div class="ml-foot">
      <?php if ($step === 'otp'): ?>
        <a href="/master_login.php" onclick="return confirm('Cancelar e voltar ao login?')">← Voltar ao login</a>
      <?php else: ?>
        Procurando o app normal? <a href="/login.php">Ir pro login do sistema</a>
      <?php endif; ?>
    </div>
  </main>
  <!-- LGPD Etapa 5: banner de cookies -->
  <script src="/assets/cookie-consent.js?v=1"></script>
</body>
</html>
