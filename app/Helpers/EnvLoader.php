<?php
namespace App\Helpers;

/**
 * EnvLoader — parser .env minimalista (sem dependências externas).
 *
 * Lê o arquivo .env na raiz do projeto (se existir) e popula em
 * $_ENV/getenv. Aceita valores quoted ("foo bar"), comments (#),
 * e linhas vazias. Não suporta substituição de variáveis ${VAR}
 * — pra manter simples; adicionar depois se precisar.
 *
 * Uso:
 *   require_once __DIR__ . '/app/Helpers/EnvLoader.php';
 *   App\Helpers\EnvLoader::load();
 *   $host = App\Helpers\EnvLoader::get('DB_HOST', '127.0.0.1');
 */
final class EnvLoader
{
    private static bool $loaded = false;
    /** @var array<string,string> */
    private static array $vars = [];

    public static function load(?string $path = null): void
    {
        if (self::$loaded) return;
        $path = $path ?: dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($path) || !is_readable($path)) {
            self::$loaded = true;
            return;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) { self::$loaded = true; return; }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eqPos = strpos($line, '=');
            if ($eqPos === false) continue;
            $key = trim(substr($line, 0, $eqPos));
            $val = trim(substr($line, $eqPos + 1));
            // Remove quotes
            if (strlen($val) >= 2) {
                $first = $val[0];
                $last  = $val[strlen($val) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $val = substr($val, 1, -1);
                }
            }
            // Remove inline comment (apenas em valores não-quoted)
            if (strpos($val, ' #') !== false) {
                $val = trim(substr($val, 0, strpos($val, ' #')));
            }
            self::$vars[$key] = $val;
            // Não sobrescreve se já existir no ambiente real
            if (getenv($key) === false) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
            }
        }
        self::$loaded = true;
    }

    public static function get(string $key, string $default = ''): string
    {
        self::load();
        $val = getenv($key);
        if ($val !== false && $val !== '') return $val;
        return self::$vars[$key] ?? $default;
    }
}
