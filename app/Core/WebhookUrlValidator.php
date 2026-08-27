<?php
namespace App\Core;

/**
 * WebhookUrlValidator — protecao SSRF para URLs de webhook.
 *
 * Bloqueia destinos perigosos antes de POSTar dados pro destino do user:
 *   - localhost / 127.0.0.0/8
 *   - 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 (RFC1918)
 *   - 169.254.0.0/16 (link-local + metadata cloud AWS/GCP/Azure)
 *   - 100.64.0.0/10 (CGNAT)
 *   - ::1, fc00::/7, fe80::/10 (IPv6 privadas)
 *   - 0.0.0.0
 *
 * Em dev, pode liberar com WEBHOOK_ALLOW_PRIVATE=1 no .env.
 *
 * Uso:
 *   list($ok, $erro) = WebhookUrlValidator::isPublicUrl($url);
 *   if (!$ok) { http_response_code(400); echo json_encode(['error'=>$erro]); exit; }
 */
class WebhookUrlValidator
{
    public static function isPublicUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '') return [false, 'URL vazia'];

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return [false, 'URL invalida'];
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return [false, 'Apenas http/https sao permitidos'];
        }

        $host = $parts['host'] ?? '';
        if ($host === '') return [false, 'Host ausente'];

        // Bypass para dev autorizado
        if (self::allowPrivate()) {
            return [true, null];
        }

        // Bloqueia hostnames literais comuns
        $hostLower = strtolower($host);
        $forbiddenNames = ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'];
        if (in_array($hostLower, $forbiddenNames, true)) {
            return [false, 'Endereco local nao permitido (' . $host . ')'];
        }

        // Resolve hostname pra IP (proteje contra DNS rebinding parcialmente)
        $ips = self::resolveHost($host);
        if (empty($ips)) {
            return [false, 'Nao foi possivel resolver o host'];
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return [false, "IP {$ip} (host {$host}) nao eh roteavel publicamente"];
            }
        }

        return [true, null];
    }

    /** Resolve A + AAAA records. Retorna lista de IPs (v4/v6 misturados). */
    private static function resolveHost(string $host): array
    {
        // IP literal
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        $a    = @gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        $ips = array_merge($ips, $a);
        foreach ($aaaa as $r) {
            if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
        return array_values(array_unique($ips));
    }

    /**
     * True se o IP for roteavel publicamente (nao loopback, nao privado, nao link-local).
     */
    private static function isPublicIp(string $ip): bool
    {
        // filter_var ja exclui as faixas reservadas com NO_PRIV_RANGE|NO_RES_RANGE
        $opts = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $opts) === false) {
            return false;
        }
        // Extra: bloqueia explicitamente 100.64.0.0/10 (CGNAT) que filter_var nao pega
        if (self::inCidr($ip, '100.64.0.0', 10)) return false;
        // 0.0.0.0
        if ($ip === '0.0.0.0') return false;
        return true;
    }

    private static function inCidr(string $ip, string $base, int $mask): bool
    {
        $ipLong   = ip2long($ip);
        $baseLong = ip2long($base);
        if ($ipLong === false || $baseLong === false) return false;
        $maskLong = -1 << (32 - $mask);
        return ($ipLong & $maskLong) === ($baseLong & $maskLong);
    }

    private static function allowPrivate(): bool
    {
        $env = $_ENV['WEBHOOK_ALLOW_PRIVATE'] ?? getenv('WEBHOOK_ALLOW_PRIVATE');
        return $env === '1' || $env === 'true' || $env === true;
    }
}
