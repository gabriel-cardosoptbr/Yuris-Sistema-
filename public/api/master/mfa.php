<?php
/**
 * mfa.php — Endpoint de gestão 2FA / TOTP para super_admin (LGPD P0 / C-09).
 *
 * Ações:
 *   POST ?action=generate           — gera secret + URI otpauth + 10 backup codes
 *                                     (NÃO grava ainda — usuário precisa confirmar)
 *   POST ?action=confirm            — recebe código TOTP de teste + secret pendente
 *                                     se válido, grava cifrado + ativa mfa_enabled=1
 *   POST ?action=disable            — desativa 2FA (exige confirmação por senha + código atual)
 *   POST ?action=regenerate_backup  — regenera os 10 backup codes
 *   GET                             — retorna status atual {mfa_enabled, mfa_setup_at}
 *
 * Acesso: somente super_admin com sessão master_mode.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';
require_once __DIR__ . '/../../../app/Helpers/EnvLoader.php';
require_once __DIR__ . '/../../../app/Helpers/TotpHelper.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;
use App\Helpers\TotpHelper;
use App\Models\Database;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

// Master mode: bloqueia chamada via sessão "comum" (precisa ter logado pelo master_login.php)
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso permitido apenas dentro do Painel Master.');
}

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$userId = $ctx->getUserId();

// ─── GET status ──────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT id, user_id, nome, mfa_enabled, mfa_setup_at, mfa_last_used_at
         FROM super_admins WHERE user_id = :uid AND ativo = 1 LIMIT 1'
    );
    $stmt->execute(['uid' => $userId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) ApiResponse::notFound('Super admin não encontrado');

    ApiResponse::ok([
        'mfa_enabled'      => (int)$row['mfa_enabled'] === 1,
        'mfa_setup_at'     => $row['mfa_setup_at'],
        'mfa_last_used_at' => $row['mfa_last_used_at'],
    ]);
}

// ─── CSRF para mutações ─────────────────────────────────────────────────────
if (in_array($method, ['POST','PUT','DELETE','PATCH'])) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        ApiResponse::badRequest('CSRF inválido');
    }
}

// ─── POST generate ──────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'generate') {
    // Verifica que ainda não está ativado (segurança: evita sobrescrever sem disable)
    $stmt = $pdo->prepare('SELECT mfa_enabled FROM super_admins WHERE user_id = :uid AND ativo = 1');
    $stmt->execute(['uid' => $userId]);
    if ((int)$stmt->fetchColumn() === 1) {
        ApiResponse::badRequest('2FA já está ativo. Use disable + generate para trocar.');
    }

    try {
        // Sanity: tenta cifrar pra validar MFA_ENCRYPTION_KEY
        TotpHelper::encryptSecret('test');
    } catch (\Throwable $e) {
        ApiResponse::serverError('MFA_ENCRYPTION_KEY não configurada no servidor. ' . $e->getMessage());
    }

    $secret       = TotpHelper::generateSecret();
    $backupCodes  = TotpHelper::generateBackupCodes(10);

    // Pega email do super_admin pra label do QR
    $email = $pdo->prepare('SELECT login FROM users WHERE id = :uid LIMIT 1');
    $email->execute(['uid' => $userId]);
    $emailStr = (string)($email->fetchColumn() ?: 'super_admin');

    $uri = TotpHelper::buildUri($secret, $emailStr, 'Yuris Master');

    // Sessão temporária: guarda o pending até confirm (TTL implícito da sessão)
    $_SESSION['mfa_pending'] = [
        'secret'       => $secret,
        'backup_codes' => $backupCodes,
        'generated_at' => time(),
    ];

    // Retorna em claro UMA VEZ pro usuário copiar (frontend mostra QR + lista)
    ApiResponse::ok([
        'secret'       => $secret,    // base32 — pra digitar manual no app
        'uri'          => $uri,       // pra renderizar QR code
        'backup_codes' => $backupCodes,
        'issuer'       => 'Yuris Master',
        'account'      => $emailStr,
    ]);
}

// ─── POST confirm ───────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'confirm') {
    if (empty($_SESSION['mfa_pending']['secret'])) {
        ApiResponse::badRequest('Nenhum setup pendente. Gere o secret primeiro.');
    }
    $code = trim((string)($input['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) {
        ApiResponse::badRequest('Código deve ter 6 dígitos.');
    }

    $pending = $_SESSION['mfa_pending'];
    // Expira após 10 minutos
    if (time() - (int)$pending['generated_at'] > 600) {
        unset($_SESSION['mfa_pending']);
        ApiResponse::badRequest('Setup expirado. Gere novamente.');
    }

    if (!TotpHelper::verify($pending['secret'], $code)) {
        ApiResponse::badRequest('Código incorreto. Verifique a hora do seu dispositivo.');
    }

    // Cifra e grava
    $encSecret = TotpHelper::encryptSecret($pending['secret']);
    $hashes    = array_map([TotpHelper::class, 'hashBackupCode'], $pending['backup_codes']);
    $hashesJson = json_encode(array_values($hashes));

    $stmt = $pdo->prepare(
        'UPDATE super_admins SET
            mfa_enabled = 1,
            mfa_secret = :sec,
            mfa_backup_codes_hash = :bk,
            mfa_setup_at = NOW()
         WHERE user_id = :uid AND ativo = 1'
    );
    $stmt->execute(['sec' => $encSecret, 'bk' => $hashesJson, 'uid' => $userId]);

    MasterAudit::log('mfa.enabled', 'super_admin', $userId, 'Super admin habilitou 2FA TOTP', [
        'backup_codes_count' => count($pending['backup_codes']),
    ]);

    unset($_SESSION['mfa_pending']);
    ApiResponse::ok(['mfa_enabled' => true, 'message' => '2FA ativado com sucesso']);
}

// ─── POST disable ────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'disable') {
    $code = trim((string)($input['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) {
        ApiResponse::badRequest('Para desativar, informe um código TOTP atual válido.');
    }

    $stmt = $pdo->prepare('SELECT mfa_secret FROM super_admins WHERE user_id = :uid AND ativo = 1');
    $stmt->execute(['uid' => $userId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row || !$row['mfa_secret']) {
        ApiResponse::badRequest('2FA não está ativo.');
    }

    try {
        $secret = TotpHelper::decryptSecret($row['mfa_secret']);
    } catch (\Throwable $e) {
        ApiResponse::serverError('Falha ao decifrar secret: ' . $e->getMessage());
    }

    if (!TotpHelper::verify($secret, $code)) {
        ApiResponse::badRequest('Código incorreto.');
    }

    $pdo->prepare(
        'UPDATE super_admins SET
            mfa_enabled = 0,
            mfa_secret = NULL,
            mfa_backup_codes_hash = NULL,
            mfa_setup_at = NULL
         WHERE user_id = :uid'
    )->execute(['uid' => $userId]);

    MasterAudit::log('mfa.disabled', 'super_admin', $userId, 'Super admin desativou 2FA');
    ApiResponse::ok(['mfa_enabled' => false, 'message' => '2FA desativado']);
}

// ─── POST regenerate_backup ──────────────────────────────────────────────────
if ($method === 'POST' && $action === 'regenerate_backup') {
    $stmt = $pdo->prepare('SELECT mfa_enabled FROM super_admins WHERE user_id = :uid AND ativo = 1');
    $stmt->execute(['uid' => $userId]);
    if ((int)$stmt->fetchColumn() !== 1) {
        ApiResponse::badRequest('2FA não está ativo.');
    }

    $newCodes  = TotpHelper::generateBackupCodes(10);
    $hashes    = array_map([TotpHelper::class, 'hashBackupCode'], $newCodes);
    $hashesJson = json_encode(array_values($hashes));

    $pdo->prepare('UPDATE super_admins SET mfa_backup_codes_hash = :bk WHERE user_id = :uid')
        ->execute(['bk' => $hashesJson, 'uid' => $userId]);

    MasterAudit::log('mfa.backup_regenerated', 'super_admin', $userId, 'Backup codes regenerados');
    ApiResponse::ok(['backup_codes' => $newCodes]);
}

ApiResponse::badRequest('Ação não reconhecida');
