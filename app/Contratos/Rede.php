<?php
namespace App\Contratos;

use App\Core\EnvLoader;

/**
 * IP e navegador de quem está do outro lado, para as evidências.
 *
 * Em produção a requisição chega assim: navegador → nginx do servidor → Apache do
 * container. O Apache vê o IP do nginx (rede interna do Docker), e o IP de verdade
 * vem no X-Forwarded-For, que o nginx acrescenta. Só se confia nesse cabeçalho
 * quando quem conectou é um proxy confiável (rede privada ou loopback, ou a lista
 * CONTRATOS_PROXIES_CONFIAVEIS); de trás para frente, o primeiro endereço que não é
 * proxy confiável é o cliente. Sem isso, qualquer um forjaria o próprio IP.
 */
final class Rede
{
    public static function ip(?array $server = null): ?string
    {
        $s = $server ?? $_SERVER;
        $remoto = (string) ($s['REMOTE_ADDR'] ?? '');
        if ($remoto === '' || !filter_var($remoto, FILTER_VALIDATE_IP)) return null;
        if (!self::confiavel($remoto)) return $remoto;

        $xff = (string) ($s['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff === '') return $remoto;
        $cadeia = array_reverse(array_filter(array_map('trim', explode(',', $xff))));
        foreach ($cadeia as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) return $remoto;
            if (!self::confiavel($ip)) return $ip;
        }
        return $remoto;
    }

    public static function navegador(?array $server = null): ?string
    {
        $ua = (string) (($server ?? $_SERVER)['HTTP_USER_AGENT'] ?? '');
        return $ua === '' ? null : mb_substr($ua, 0, 400);
    }

    private static function confiavel(string $ip): bool
    {
        $lista = array_filter(array_map('trim', explode(',', EnvLoader::get('CONTRATOS_PROXIES_CONFIAVEIS'))));
        if ($lista) return in_array($ip, $lista, true);
        if ($ip === '127.0.0.1' || $ip === '::1') return true;
        // Rede privada (o nginx do servidor fala com o container pela rede do Docker).
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
