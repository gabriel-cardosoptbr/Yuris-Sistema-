<?php
namespace App\Contratos;

use App\Core\Crypto;
use App\Core\EnvLoader;

/**
 * Segredos do módulo de contratos. Tudo gerado no servidor, com random_bytes.
 *
 *   uuid()               identificador permanente (contrato, versão, participante,
 *                        assinatura, arquivo, convite, código). UUID v4.
 *   codigoVerificacao()  o código público de consulta de autenticidade (60 bits,
 *                        base32 de Crockford, XXXX-XXXX-XXXX). NÃO dá acesso a
 *                        nada além da página de verificação.
 *   token()              o segredo do link de assinatura: 256 bits. Só o SHA-256
 *                        dele vai para o banco (hashToken). Função diferente do
 *                        código de verificação, e os dois nunca se derivam.
 *   codigoUnico()        o código de 6 dígitos mandado por WhatsApp. Baixa entropia:
 *                        guardado só como HMAC com chave do servidor (hmac()).
 *   cifrar()/decifrar()  CPF e telefone em repouso, pelo App\Core\Crypto (AES-256-GCM,
 *                        APP_ENCRYPTION_KEY), o mesmo que já guarda credenciais.
 *   selar()/conferirSelo() o selo da plataforma no manifesto: Ed25519 (libsodium),
 *                        chave privada num ARQUIVO fora do banco e do repositório
 *                        (CONTRATOS_CHAVE_ASSINATURA no .env aponta para ele).
 *
 * Nada daqui é gravado em log. Nunca passe token, código ou chave para error_log.
 */
final class Cripto
{
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    public static function codigoVerificacao(): string
    {
        $c = '';
        for ($i = 0; $i < 12; $i++) $c .= self::CROCKFORD[random_int(0, 31)];
        return substr($c, 0, 4) . '-' . substr($c, 4, 4) . '-' . substr($c, 8, 4);
    }

    /** Normaliza o que a pessoa digitou na verificação (minúsculas, sem traço, O→0, I/L→1). */
    public static function normalizarCodigoVerificacao(string $bruto): ?string
    {
        $s = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $bruto) ?? '');
        $s = strtr($s, ['O' => '0', 'I' => '1', 'L' => '1']);
        if (strlen($s) !== 12 || strspn($s, self::CROCKFORD) !== 12) return null;
        return substr($s, 0, 4) . '-' . substr($s, 4, 4) . '-' . substr($s, 8, 4);
    }

    /** 256 bits, em base64 para URL (43 caracteres). */
    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function codigoUnico(int $digitos = 6): string
    {
        return str_pad((string) random_int(0, 10 ** $digitos - 1), $digitos, '0', STR_PAD_LEFT);
    }

    /** HMAC-SHA256 com a chave do servidor, separado por contexto (código, CPF...). */
    public static function hmac(string $contexto, string $valor): string
    {
        return hash_hmac('sha256', $contexto . "\x1f" . $valor, self::chaveHmac());
    }

    public static function cifrar(string $texto): string
    {
        return Crypto::encrypt($texto);
    }

    public static function decifrar(?string $cifrado): ?string
    {
        if ($cifrado === null || $cifrado === '') return null;
        return Crypto::decrypt($cifrado);
    }

    /** JSON canônico: chaves em ordem, sem escapar acento e barra. Base dos hashes e do selo. */
    public static function canonico(mixed $dados): string
    {
        return json_encode(self::ordenar($dados), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function ordenar(mixed $v): mixed
    {
        if (!is_array($v)) return $v;
        if (array_is_list($v)) return array_map([self::class, 'ordenar'], $v);
        ksort($v, SORT_STRING);
        foreach ($v as $k => $x) $v[$k] = self::ordenar($x);
        return $v;
    }

    // ── Selo da plataforma (Ed25519) ──────────────────────────────────────

    /** @return array{chave_id:string, algoritmo:string, assinatura:string} */
    public static function selar(string $bytes): array
    {
        $sk = self::chavePrivada();
        $pk = sodium_crypto_sign_publickey_from_secretkey($sk);
        $sig = sodium_crypto_sign_detached($bytes, $sk);
        sodium_memzero($sk);
        return ['chave_id' => self::chaveId($pk), 'algoritmo' => 'Ed25519', 'assinatura' => base64_encode($sig)];
    }

    public static function conferirSelo(string $bytes, string $assinaturaB64, string $chavePublicaB64): bool
    {
        $sig = base64_decode($assinaturaB64, true);
        $pk  = base64_decode($chavePublicaB64, true);
        if ($sig === false || $pk === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return false;
        return sodium_crypto_sign_verify_detached($sig, $bytes, $pk);
    }

    /** @return array{chave_id:string, algoritmo:string, chave_publica:string}|null */
    public static function chavePublica(): ?array
    {
        try { $sk = self::chavePrivada(); } catch (\RuntimeException) { return null; }
        $pk = sodium_crypto_sign_publickey_from_secretkey($sk);
        sodium_memzero($sk);
        return ['chave_id' => self::chaveId($pk), 'algoritmo' => 'Ed25519', 'chave_publica' => base64_encode($pk)];
    }

    public static function seloConfigurado(): bool
    {
        return self::chavePublica() !== null;
    }

    public static function chaveId(string $pk): string
    {
        return substr(hash('sha256', $pk), 0, 16);
    }

    /** @return array{secreta:string, publica:string, chave_id:string} em base64 (para o script de instalação). */
    public static function gerarParDeChaves(): array
    {
        $kp = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($kp);
        $pk = sodium_crypto_sign_publickey($kp);
        return ['secreta' => base64_encode($sk), 'publica' => base64_encode($pk), 'chave_id' => self::chaveId($pk)];
    }

    private static function chavePrivada(): string
    {
        $arq = EnvLoader::get('CONTRATOS_CHAVE_ASSINATURA');
        if ($arq === '' || !is_file($arq) || !is_readable($arq)) {
            throw new \RuntimeException('Chave do selo dos contratos não configurada (CONTRATOS_CHAVE_ASSINATURA).');
        }
        $sk = base64_decode(trim((string) file_get_contents($arq)), true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Chave do selo dos contratos inválida.');
        }
        return $sk;
    }

    /**
     * Chave do HMAC: CONTRATOS_SEGREDO se houver; senão derivada (HKDF) da
     * APP_ENCRYPTION_KEY, com contexto próprio, para nunca ser a mesma chave da cifra.
     */
    private static function chaveHmac(): string
    {
        static $k = null;
        if ($k !== null) return $k;
        $proprio = EnvLoader::get('CONTRATOS_SEGREDO');
        $base = $proprio !== '' ? self::bytesDaChave($proprio) : self::bytesDaChave(EnvLoader::get('APP_ENCRYPTION_KEY'));
        if ($base === null || strlen($base) < 32) {
            throw new \RuntimeException('Chave do servidor para os contratos não configurada.');
        }
        return $k = hash_hkdf('sha256', $base, 32, 'yuris-contratos-hmac-v1');
    }

    private static function bytesDaChave(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') return null;
        if (str_starts_with($v, 'base64:')) { $b = base64_decode(substr($v, 7), true); return $b === false ? null : $b; }
        if (str_starts_with($v, 'hex:')) { $b = @hex2bin(substr($v, 4)); return $b === false ? null : $b; }
        return $v;
    }
}
