<?php
namespace App\Helpers;

/**
 * PayloadMasker — mascaramento PII para payloads de webhook (LGPD).
 *
 * Modos:
 *   - minimal : retorna apenas chaves estruturais (id, type, status)
 *   - masked  : mantem o shape, mascara chaves sensiveis conhecidas
 *   - full    : retorna tal qual (exige aceite explicito no painel)
 *
 * Chaves consideradas sensiveis (case-insensitive):
 *   email/titular_email  -> email
 *   telefone/phone/celular/whatsapp/numero -> phone
 *   cpf                  -> cpf
 *   cnpj                 -> cnpj
 *   senha/password/secret/token/hash/senha_hash -> '***'
 */
class PayloadMasker
{
    private const STRUCTURAL_KEYS = ['id', 'type', 'status'];

    public static function email(?string $email): ?string
    {
        if ($email === null || $email === '') return $email;
        $at = strpos($email, '@');
        if ($at === false) return self::maskString($email, 1);
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at);
        return substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1)) . $domain;
    }

    public static function phone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') return $phone;
        $digits = preg_replace('/\D/', '', $phone);
        $len    = strlen($digits);
        if ($len < 4) return str_repeat('*', $len);
        return str_repeat('*', $len - 4) . substr($digits, -4);
    }

    public static function cpf(?string $cpf): ?string
    {
        if ($cpf === null || $cpf === '') return $cpf;
        $digits = preg_replace('/\D/', '', $cpf);
        if (strlen($digits) !== 11) return self::maskString($cpf, 3);
        return substr($digits, 0, 3) . '.***.***-' . substr($digits, -2);
    }

    public static function cnpj(?string $cnpj): ?string
    {
        if ($cnpj === null || $cnpj === '') return $cnpj;
        $digits = preg_replace('/\D/', '', $cnpj);
        if (strlen($digits) !== 14) return self::maskString($cnpj, 2);
        return substr($digits, 0, 2) . '.***.***/' . substr($digits, 8, 4) . '-**';
    }

    public static function maskString(?string $s, int $keep = 2): ?string
    {
        if ($s === null || $s === '') return $s;
        $len = strlen($s);
        if ($len <= $keep) return str_repeat('*', $len);
        return substr($s, 0, $keep) . str_repeat('*', $len - $keep);
    }

    /**
     * Aplica o modo de mascaramento sobre um array de dados (recursivo).
     */
    public static function applyMode(array $data, string $mode): array
    {
        if ($mode === 'full') return $data;
        if ($mode === 'minimal') {
            return array_intersect_key($data, array_flip(self::STRUCTURAL_KEYS));
        }
        return self::maskRecursive($data);
    }

    private static function maskRecursive(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $kl = strtolower((string)$k);
            if (is_array($v)) {
                $out[$k] = self::maskRecursive($v);
                continue;
            }
            if (!is_string($v) || $v === '') {
                $out[$k] = $v;
                continue;
            }
            switch ($kl) {
                case 'email':
                case 'titular_email':
                case 'user_email':
                    $out[$k] = self::email($v); break;
                case 'telefone':
                case 'phone':
                case 'celular':
                case 'whatsapp':
                case 'numero':
                    $out[$k] = self::phone($v); break;
                case 'cpf':
                    $out[$k] = self::cpf($v); break;
                case 'cnpj':
                    $out[$k] = self::cnpj($v); break;
                case 'senha':
                case 'password':
                case 'secret':
                case 'token':
                case 'hash':
                case 'senha_hash':
                case 'api_key':
                case 'apikey':
                case 'authorization':
                    $out[$k] = '***'; break;
                default:
                    $out[$k] = $v;
            }
        }
        return $out;
    }
}
