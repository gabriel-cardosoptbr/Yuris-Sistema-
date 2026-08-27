<?php
namespace App\Core;

require_once __DIR__ . '/EnvLoader.php';

/**
 * Crypto — utilitário simétrico AES-256-GCM pra cifrar credenciais at-rest.
 *
 * Casos de uso:
 *   • Chave AASP (aasp_integrations.chave_encrypted)
 *   • Futuros tokens de terceiros (DataJud Corporate, certificados etc.)
 *
 * Chave: APP_ENCRYPTION_KEY do .env. Aceita 3 formatos:
 *   • base64:<32 bytes em base64>   (recomendado — gerado com `openssl rand -base64 32`)
 *   • hex:<64 chars hexadecimais>   (gerado com `openssl rand -hex 32`)
 *   • raw                            (string de exatamente 32 bytes — desaconselhado)
 *
 * Formato cifrado (versionado, pra permitir rotação de algoritmo no futuro):
 *   v1:<base64(iv)>:<base64(ciphertext)>:<base64(tag)>
 *
 *   - iv:        12 bytes aleatório (NIST SP 800-38D pra GCM)
 *   - ciphertext: AES-256-GCM(plain, key, iv)
 *   - tag:        16 bytes (autenticador GCM — detecta adulteração)
 *
 * Decrypt valida o tag — adulteração lança RuntimeException.
 *
 * NUNCA logue Crypto::decrypt(). NUNCA grave o plain em log. NUNCA passe
 * a chave por query string sem mascarar.
 */
final class Crypto
{
    private const VERSION   = 'v1';
    private const CIPHER    = 'aes-256-gcm';
    private const KEY_BYTES = 32;  // AES-256 = 256 bits = 32 bytes
    private const IV_BYTES  = 12;  // GCM recomenda 96 bits = 12 bytes
    private const TAG_BYTES = 16;  // GCM tag = 128 bits = 16 bytes

    /**
     * Cifra uma string. Retorna formato versionado pronto pra gravar no DB.
     *
     * @throws \RuntimeException se APP_ENCRYPTION_KEY ausente/inválida ou OpenSSL falhar
     */
    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            throw new \RuntimeException('Crypto::encrypt: texto vazio não é cifrável');
        }
        $key = self::loadKey();
        $iv  = random_bytes(self::IV_BYTES);
        $tag = '';

        $ct = openssl_encrypt(
            $plain,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES
        );

        if ($ct === false) {
            throw new \RuntimeException('Crypto::encrypt: openssl_encrypt retornou false');
        }
        if (strlen($tag) !== self::TAG_BYTES) {
            throw new \RuntimeException('Crypto::encrypt: tag GCM com tamanho inesperado');
        }

        return self::VERSION
            . ':' . base64_encode($iv)
            . ':' . base64_encode($ct)
            . ':' . base64_encode($tag);
    }

    /**
     * Decifra string gerada por encrypt(). Verifica autenticidade GCM (tag).
     *
     * @throws \RuntimeException se chave ausente/inválida, formato inválido, tag inválido,
     *                            ou texto adulterado.
     */
    public static function decrypt(string $cipher): string
    {
        if ($cipher === '') {
            throw new \RuntimeException('Crypto::decrypt: cipher vazio');
        }
        $parts = explode(':', $cipher, 4);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            throw new \RuntimeException('Crypto::decrypt: formato inválido (esperado ' . self::VERSION . ':iv:ct:tag)');
        }
        [$ver, $ivB64, $ctB64, $tagB64] = $parts;

        $iv  = base64_decode($ivB64, true);
        $ct  = base64_decode($ctB64, true);
        $tag = base64_decode($tagB64, true);
        if ($iv === false || $ct === false || $tag === false) {
            throw new \RuntimeException('Crypto::decrypt: base64 inválido');
        }
        if (strlen($iv) !== self::IV_BYTES || strlen($tag) !== self::TAG_BYTES) {
            throw new \RuntimeException('Crypto::decrypt: tamanho de IV/TAG inesperado');
        }

        $key   = self::loadKey();
        $plain = openssl_decrypt(
            $ct,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plain === false) {
            throw new \RuntimeException('Crypto::decrypt: falha na decifragem (tag inválido ou chave errada)');
        }
        return $plain;
    }

    /**
     * Máscara visual COMPACTA: sempre devolve formato fixo "****XXXX"
     * (4 estrelas + últimos N caracteres). Comprimento total = 4 + $visibleTrailing.
     *
     * Decisão: usar tamanho fixo (em vez de proporcional ao input) por dois motivos:
     *   1) UI consistente — toda credencial mascarada tem mesma largura
     *   2) Cabe em VARCHAR(24) sem truncar
     *   3) Não vaza o tamanho original da credencial (info side-channel)
     */
    public static function mask(string $plain, int $visibleTrailing = 4): string
    {
        $len = strlen($plain);
        if ($len === 0) return '';
        if ($len <= $visibleTrailing) {
            // Credencial curtíssima: só estrelas (não expõe nem o final)
            return str_repeat('*', 8);
        }
        return str_repeat('*', 4) . substr($plain, -$visibleTrailing);
    }

    /**
     * Verifica se a chave está configurada (sem expor o valor).
     * Útil pra checagens defensivas em UIs/setup.
     */
    public static function isConfigured(): bool
    {
        try {
            self::loadKey();
            return true;
        } catch (\Throwable $_) {
            return false;
        }
    }

    /** Carrega chave de 32 bytes a partir do .env. Lança exceção se inválida. */
    private static function loadKey(): string
    {
        $raw = EnvLoader::get('APP_ENCRYPTION_KEY', '');
        if ($raw === '') {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY ausente no .env. ' .
                'Gere com: openssl rand -base64 32  e cole como APP_ENCRYPTION_KEY=base64:<valor>'
            );
        }

        // Aceita prefixos base64: / hex: / raw
        if (strncmp($raw, 'base64:', 7) === 0) {
            $key = base64_decode(substr($raw, 7), true);
            if ($key === false) {
                throw new \RuntimeException('APP_ENCRYPTION_KEY base64 inválido');
            }
        } elseif (strncmp($raw, 'hex:', 4) === 0) {
            $hex = substr($raw, 4);
            if (!preg_match('/^[0-9a-fA-F]+$/', $hex)) {
                throw new \RuntimeException('APP_ENCRYPTION_KEY hex inválido (somente 0-9a-f)');
            }
            $key = hex2bin($hex);
            if ($key === false) {
                throw new \RuntimeException('APP_ENCRYPTION_KEY hex2bin falhou');
            }
        } else {
            // Aceita raw apenas se tiver exatamente 32 bytes (caso contrário rejeita)
            $key = $raw;
        }

        if (strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY deve resultar em exatamente ' . self::KEY_BYTES .
                ' bytes (AES-256). Obtido: ' . strlen($key) . ' bytes. ' .
                'Use prefixo base64: ou hex:. Para gerar nova: openssl rand -base64 32'
            );
        }
        return $key;
    }
}
