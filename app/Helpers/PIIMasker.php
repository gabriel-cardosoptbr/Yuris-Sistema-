<?php
namespace App\Helpers;

/**
 * PIIMasker — utilitário de mascaramento de PII para `lgpd_request_findings`.
 *
 * Princípio: nunca armazenar valor cru em findings. Sempre uma versão
 * mascarada exibível + um hash SHA-256 para comparação posterior.
 *
 * Pepper: variável LGPD_DOC_HASH_PEPPER no .env. Sem pepper, hashes ficam
 * vulneráveis a rainbow tables. Em dev, usa fallback explícito documentado.
 *
 * Uso típico:
 *   $masked = PIIMasker::email('ana.silva@gmail.com');         // ana***@gmail.com
 *   $masked = PIIMasker::cpf('12345678901');                   // 123.***.***-01
 *   $masked = PIIMasker::telefone('11987654321');              // (11) ****-4321
 *   $hash   = PIIMasker::hashForLookup('12345678901', 'cpf');  // SHA-256(valor + pepper)
 *
 * Notas:
 *  - Funções de mascaramento NUNCA escrevem em log/output.
 *  - Mascaramento NÃO substitui consentimento — DPO ainda precisa marcar
 *    `incluido_no_export` antes de entregar.
 */
final class PIIMasker
{
    private const PEPPER_ENV_VAR = 'LGPD_DOC_HASH_PEPPER';
    private const FALLBACK_PEPPER_DEV = 'YURIS_LGPD_DEV_PEPPER_change_in_prod';

    // ─── Mascaramento ────────────────────────────────────────────────────────

    /**
     * Mascara e-mail mantendo domínio e primeira letra do local-part.
     *   ana.silva@gmail.com → ana***@gmail.com
     *   a@b.com             → a***@b.com
     *   ''                  → ''
     */
    public static function email(?string $email): string
    {
        $email = trim((string)$email);
        if ($email === '' || strpos($email, '@') === false) return $email;
        [$local, $domain] = explode('@', $email, 2);
        $local = (string)$local;
        $first = mb_substr($local, 0, max(1, min(3, intdiv(mb_strlen($local), 2))));
        return $first . '***@' . $domain;
    }

    /**
     * Mascara CPF mantendo 3 primeiros e 2 últimos dígitos.
     *   12345678901 → 123.***.***-01
     *   ''          → ''
     */
    public static function cpf(?string $cpf): string
    {
        $clean = preg_replace('/\D/', '', (string)$cpf);
        if (strlen($clean) < 11) return self::genericMask($cpf);
        return substr($clean, 0, 3) . '.***.***-' . substr($clean, -2);
    }

    /**
     * Mascara CNPJ mantendo 2 primeiros e 4 últimos dígitos do número raiz.
     * Exemplo: 12345678000190 vira "12.XXX.XXX (filial 0001) -90"
     * (Asteriscos omitidos do docblock por causarem fim de comment PHP.)
     */
    public static function cnpj(?string $cnpj): string
    {
        $clean = preg_replace('/\D/', '', (string)$cnpj);
        if (strlen($clean) < 14) return self::genericMask($cnpj);
        return substr($clean, 0, 2) . '.***.***/'
             . substr($clean, 8, 4) . '-'
             . substr($clean, -2);
    }

    /**
     * Mascara telefone mantendo DDD + 4 últimos dígitos.
     *   11987654321 → (11) ****-4321
     *   987654321   → ****-4321
     */
    public static function telefone(?string $tel): string
    {
        $clean = preg_replace('/\D/', '', (string)$tel);
        if (strlen($clean) < 8) return self::genericMask($tel);
        if (strlen($clean) >= 10) {
            $ddd = substr($clean, 0, 2);
            $end = substr($clean, -4);
            return "($ddd) ****-$end";
        }
        return '****-' . substr($clean, -4);
    }

    /**
     * Mascara RG (formato livre — mantém 1º e 2 últimos).
     *   12.345.678-9 → 1*******-9
     */
    public static function rg(?string $rg): string
    {
        $clean = preg_replace('/[^0-9A-Za-z]/', '', (string)$rg);
        if (strlen($clean) < 5) return self::genericMask($rg);
        return substr($clean, 0, 1) . str_repeat('*', strlen($clean) - 3) . substr($clean, -2);
    }

    /**
     * Mascara OAB (formato UF + número). Mantém UF + 2 últimos.
     *   SP123456 → SP****56
     */
    public static function oab(?string $oab): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$oab));
        if (strlen($clean) < 4) return self::genericMask($oab);
        // Tenta separar UF (2 letras) do número
        $uf  = substr($clean, 0, 2);
        $num = substr($clean, 2);
        if (strlen($num) < 3) return $uf . '****';
        return $uf . str_repeat('*', max(2, strlen($num) - 2)) . substr($num, -2);
    }

    /**
     * Mascara nome de pessoa: mantém primeiro nome e iniciais do sobrenome.
     *   Ana da Silva Costa → Ana d. S. C.
     *   João               → João
     */
    public static function nome(?string $nome): string
    {
        $nome = trim((string)$nome);
        if ($nome === '') return '';
        $partes = preg_split('/\s+/', $nome);
        if (count($partes) === 1) return $partes[0];
        $primeiro = array_shift($partes);
        $iniciais = array_map(
            fn($p) => mb_strtoupper(mb_substr($p, 0, 1)) . '.',
            $partes
        );
        return $primeiro . ' ' . implode(' ', $iniciais);
    }

    /**
     * Mascara endereço: mantém só cidade/UF se conseguir extrair.
     *   Rua X, 100, Centro, São Paulo/SP → *** São Paulo/SP
     */
    public static function endereco(?string $end): string
    {
        $end = trim((string)$end);
        if ($end === '') return '';
        // Heurística simples — pega últimas palavras após última vírgula
        $parts = explode(',', $end);
        $last  = trim(end($parts));
        return '*** ' . $last;
    }

    /**
     * Mascara conteúdo de mensagem/texto longo: mantém só primeiros e últimos 20 chars.
     */
    public static function texto(?string $txt, int $head = 30, int $tail = 15): string
    {
        $txt = trim((string)$txt);
        if (mb_strlen($txt) <= $head + $tail + 5) return $txt;
        return mb_substr($txt, 0, $head) . ' [...mascarado...] ' . mb_substr($txt, -$tail);
    }

    /**
     * Fallback genérico: mantém 1º e último char, mascara o resto.
     */
    public static function genericMask(?string $v): string
    {
        $v = (string)$v;
        $len = mb_strlen($v);
        if ($len <= 2) return $v === '' ? '' : str_repeat('*', $len);
        return mb_substr($v, 0, 1) . str_repeat('*', $len - 2) . mb_substr($v, -1);
    }

    /**
     * Decide automaticamente qual mascaramento usar pelo nome do campo.
     */
    public static function auto(?string $valor, string $campo): string
    {
        $campo = strtolower($campo);
        if ($valor === null || $valor === '') return '';
        if (str_contains($campo, 'email') || str_contains($campo, 'mail') || str_contains($campo, 'login')) {
            return self::email($valor);
        }
        if ($campo === 'cpf' || str_contains($campo, '_cpf')) return self::cpf($valor);
        if ($campo === 'cnpj' || str_contains($campo, '_cnpj')) return self::cnpj($valor);
        if ($campo === 'cpf_cnpj' || str_contains($campo, 'cpf_cnpj')) {
            $clean = preg_replace('/\D/', '', $valor);
            return strlen($clean) === 14 ? self::cnpj($valor) : self::cpf($valor);
        }
        if (str_contains($campo, 'telefone') || str_contains($campo, 'phone') || str_contains($campo, 'whatsapp') || str_contains($campo, 'celular')) {
            return self::telefone($valor);
        }
        if ($campo === 'rg' || str_contains($campo, '_rg')) return self::rg($valor);
        if ($campo === 'oab' || str_contains($campo, '_oab')) return self::oab($valor);
        if (str_contains($campo, 'nome') || str_contains($campo, 'name')) return self::nome($valor);
        if (str_contains($campo, 'endereco') || str_contains($campo, 'address')) return self::endereco($valor);
        if (str_contains($campo, 'descricao') || str_contains($campo, 'observacao')
            || str_contains($campo, 'message') || str_contains($campo, 'content')
            || str_contains($campo, 'conteudo')) return self::texto($valor);
        return self::genericMask($valor);
    }

    // ─── Hash para busca sem PII ─────────────────────────────────────────────

    /**
     * Gera SHA-256 do valor normalizado + pepper, para busca/comparação
     * sem armazenar valor cru.
     *
     * Normalização:
     *  - Documento numérico (cpf/cnpj/rg): remove tudo que não é dígito.
     *  - Email/login: minúsculo + trim.
     *  - Outros: trim.
     */
    public static function hashForLookup(string $valor, string $tipo = 'generic'): string
    {
        $tipo = strtolower($tipo);
        $normalized = match (true) {
            in_array($tipo, ['cpf','cnpj','rg','cpf_cnpj','documento'], true)
                => preg_replace('/\D/', '', $valor),
            in_array($tipo, ['email','login','mail'], true)
                => strtolower(trim($valor)),
            default
                => trim($valor),
        };
        return hash('sha256', $normalized . self::pepper());
    }

    private static function pepper(): string
    {
        $env = getenv(self::PEPPER_ENV_VAR);
        if (is_string($env) && $env !== '') return $env;
        // Em dev, retorna fallback documentado. Em prod, EnvLoader::validateProduction
        // deve bloquear bootstrap sem essa var.
        return self::FALLBACK_PEPPER_DEV;
    }
}
