<?php
namespace App\Helpers;

/**
 * EnvLoader — parser .env minimalista e seguro.
 */
final class EnvLoader
{
    private static bool $loaded = false;
    private static bool $loading = false;

    /** @var array<string,string> */
    private static array $vars = [];

    public static function load(?string $path = null): void
    {
        if (self::$loaded || self::$loading) {
            return;
        }

        self::$loading = true;

        $path = $path ?: dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($path) || !is_readable($path)) {
            self::$loaded = true;
            self::$loading = false;
            return;
        }

        $handle = fopen($path, 'r');

        if (!$handle) {
            self::$loaded = true;
            self::$loading = false;
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $eqPos = strpos($line, '=');

            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            $val = trim(substr($line, $eqPos + 1));

            if ($key === '') {
                continue;
            }

            if (strlen($val) >= 2) {
                $first = $val[0];
                $last = $val[strlen($val) - 1];

                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $val = substr($val, 1, -1);
                }
            }

            if (strpos($val, ' #') !== false) {
                $val = trim(substr($val, 0, strpos($val, ' #')));
            }

            self::$vars[$key] = $val;

            if (getenv($key) === false) {
                putenv($key . '=' . $val);
            }

            $_ENV[$key] = $val;
        }

        fclose($handle);

        self::$loaded = true;
        self::$loading = false;
    }

    public static function get(string $key, string $default = ''): string
    {
        self::load();

        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }

        $val = getenv($key);

        if ($val !== false && $val !== '') {
            return (string) $val;
        }

        return self::$vars[$key] ?? $default;
    }

    public static function validateProduction(): array
    {
        self::load();

        $env = strtolower(self::get('APP_ENV', 'development'));
        $isProd = in_array($env, ['production', 'prod'], true);

        $problems = [];

        if (self::get('DB_PASS', '') === '') {
            $problems[] = 'DB_PASS vazio';
        }

        $cron = self::get('CRON_TOKEN', '');

        if ($cron === '' || $cron === 'yuris_cron_token_change_me') {
            $problems[] = 'CRON_TOKEN não configurado ou usando default inseguro';
        }

        $gw = strtolower(self::get('BILLING_GATEWAY', 'null'));

        if (in_array($gw, ['', 'null', 'noop', 'dev'], true)) {
            $problems[] = 'BILLING_GATEWAY=null/dev';
        }

        if (self::get('APP_ENCRYPTION_KEY', '') === '') {
            $problems[] = 'APP_ENCRYPTION_KEY ausente';
        }

        if (!empty($problems) && $isProd) {
            error_log('[EnvLoader] CRITICAL: ' . implode(' | ', $problems));

            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
            }

            echo json_encode([
                'ok' => false,
                'error' => 'Serviço indisponível: configuração de produção inválida.'
            ]);

            exit;
        }

        if (!empty($problems)) {
            error_log('[EnvLoader] WARNING dev: ' . implode(' | ', $problems));
        }

        return $problems;
    }
}
