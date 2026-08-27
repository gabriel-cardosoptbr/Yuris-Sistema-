<?php
namespace App\Usuarios;

/**
 * TotpHelper — RFC 6238 TOTP inline (HMAC-SHA1, 30s window, 6 dígitos).
 *
 * Compatível com:
 *   • Google Authenticator
 *   • Authy
 *   • 1Password
 *   • Bitwarden
 *   • Microsoft Authenticator
 *   • Aegis (Android)
 *
 * Sem dependências Composer — apenas funções nativas do PHP.
 *
 * USO:
 *   $secret = TotpHelper::generateSecret();
 *   $uri    = TotpHelper::buildUri($secret, 'silvana@example.com', 'Yuris Master');
 *   // exibe QR code do $uri pro usuário
 *   // ... usuário envia code de 6 dígitos
 *   if (TotpHelper::verify($secret, $code)) { ... }
 *
 * AT-REST: secret é Base32 puro; cifrar com encryptSecret() antes de gravar.
 */
final class TotpHelper
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Gera secret aleatório em Base32 (recomendado RFC 6238: ≥ 128 bits = 16 bytes = 26 chars Base32).
     * Default 20 bytes (160 bits) = 32 chars Base32 (alinhado com Google Authenticator).
     */
    public static function generateSecret(int $bytes = 20): string
    {
        $raw = random_bytes($bytes);
        return self::base32Encode($raw);
    }

    /**
     * Gera o código TOTP para um secret e timestamp (default = agora).
     * @return string 6 dígitos (com leading zeros, sempre)
     */
    public static function getCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $step      = (int) floor($timestamp / 30);

        // Step em big-endian 8 bytes
        $binStep = "\0\0\0\0" . pack('N*', $step);

        $secretRaw = self::base32Decode($secret);
        $hash      = hash_hmac('sha1', $binStep, $secretRaw, true);

        // Dynamic truncation (RFC 4226)
        $offset = ord($hash[19]) & 0xf;
        $code   = (
            ((ord($hash[$offset    ]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) <<  8) |
             (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica código TOTP com tolerância de ±$window steps (cada step = 30s).
     * Default window = 1 → aceita código atual + anterior + próximo (≈90s de tolerância).
     * Usa hash_equals (timing-safe).
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) return false;

        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = self::getCode($secret, $now + ($i * 30));
            if (hash_equals($candidate, $code)) return true;
        }
        return false;
    }

    /**
     * Constrói URI otpauth:// para gerar QR code.
     * O usuário escaneia → app instala o secret.
     */
    public static function buildUri(string $secret, string $accountLabel, string $issuer = 'Yuris Master'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);
        $query = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => 6,
            'period'    => 30,
        ]);
        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /**
     * Cifra o secret usando AES-256-CBC com chave do .env (MFA_ENCRYPTION_KEY).
     * Formato retornado: IV(16) || ciphertext. Pode ser armazenado em VARBINARY.
     *
     * @throws \RuntimeException se MFA_ENCRYPTION_KEY não estiver definida ou inválida.
     */
    public static function encryptSecret(string $secret): string
    {
        $key = self::getEncryptionKey();
        $iv  = random_bytes(16);
        $cipher = openssl_encrypt($secret, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('TotpHelper: falha ao cifrar secret');
        }
        return $iv . $cipher;
    }

    public static function decryptSecret(string $encrypted): string
    {
        if (strlen($encrypted) < 17) {
            throw new \RuntimeException('TotpHelper: ciphertext inválido');
        }
        $key       = self::getEncryptionKey();
        $iv        = substr($encrypted, 0, 16);
        $cipher    = substr($encrypted, 16);
        $plain     = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new \RuntimeException('TotpHelper: falha ao decifrar secret (chave incorreta?)');
        }
        return $plain;
    }

    /**
     * Gera N backup codes — strings de 10 chars (alfanuméricos uppercase, sem similares 0/O/1/I).
     * Retorna array de plaintext (mostre UMA VEZ ao usuário e descarte da memória).
     */
    public static function generateBackupCodes(int $count = 10): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // exclui 0/O/1/I/L
        $codes    = [];
        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            // formato XXXXX-XXXXX
            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5, 5);
        }
        return $codes;
    }

    /**
     * Hash de backup code via bcrypt — chame antes de armazenar JSON.
     */
    public static function hashBackupCode(string $code): string
    {
        return password_hash(strtoupper(trim($code)), PASSWORD_BCRYPT);
    }

    /**
     * Verifica um backup code candidato contra a lista de hashes JSON.
     * Retorna o INDEX do hash que bateu (para o caller remover), ou null se não bate.
     */
    public static function verifyBackupCode(string $candidate, array $hashList): ?int
    {
        $candidate = strtoupper(trim($candidate));
        foreach ($hashList as $i => $hash) {
            if (password_verify($candidate, $hash)) return (int)$i;
        }
        return null;
    }

    // ─── Internos ────────────────────────────────────────────────────────────

    private static function getEncryptionKey(): string
    {
        // Lazy-load EnvLoader pra evitar require_once externo
        if (!class_exists('App\\Core\\EnvLoader')) {
            require_once __DIR__ . '/../Core/EnvLoader.php';
        }
        $raw = \App\Core\EnvLoader::get('MFA_ENCRYPTION_KEY', '');
        if ($raw === '') {
            throw new \RuntimeException(
                'TotpHelper: MFA_ENCRYPTION_KEY não definida no .env. ' .
                'Gere com: openssl rand -base64 32'
            );
        }
        // Aceita base64: ou hex: como prefixo, ou raw 32 bytes
        if (str_starts_with($raw, 'base64:')) {
            $key = base64_decode(substr($raw, 7), true);
            if ($key === false) throw new \RuntimeException('TotpHelper: MFA_ENCRYPTION_KEY base64 inválida');
        } elseif (str_starts_with($raw, 'hex:')) {
            $key = hex2bin(substr($raw, 4));
            if ($key === false) throw new \RuntimeException('TotpHelper: MFA_ENCRYPTION_KEY hex inválida');
        } else {
            // assume base64 puro
            $key = base64_decode($raw, true);
            if ($key === false || strlen($key) !== 32) {
                // tenta como raw bytes
                $key = $raw;
            }
        }
        if (strlen($key) !== 32) {
            throw new \RuntimeException(
                'TotpHelper: MFA_ENCRYPTION_KEY deve ter 32 bytes (256 bits). ' .
                'Tem ' . strlen($key) . ' bytes.'
            );
        }
        return $key;
    }

    private static function base32Encode(string $raw): string
    {
        if ($raw === '') return '';
        $out = '';
        $bits = '';
        for ($i = 0, $len = strlen($raw); $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
        }
        $pad   = (5 - (strlen($bits) % 5)) % 5;
        $bits .= str_repeat('0', $pad);
        for ($i = 0, $len = strlen($bits); $i < $len; $i += 5) {
            $out .= self::ALPHABET[bindec(substr($bits, $i, 5))];
        }
        // padding com '=' (opcional, mas Google Auth aceita sem)
        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(rtrim($secret, '='));
        $bits = '';
        for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
            $pos = strpos(self::ALPHABET, $secret[$i]);
            if ($pos === false) continue; // ignora chars inválidos
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0, $len = strlen($bits) - 7; $i <= $len; $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }
}
