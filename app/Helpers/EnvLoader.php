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

    /**
     * Verifica se variáveis críticas estão definidas. Em APP_ENV=production,
     * aborta o request se algo essencial faltar (LGPD P1 — 2D.2).
     *
     * Variáveis exigidas em produção:
     *   • DB_PASS               — senha de banco (NUNCA usar sem senha em prod)
     *   • CRON_TOKEN            — token do cron tick (gere com openssl rand -hex 24)
     *   • BILLING_GATEWAY       — gateway real (stripe/mercadopago/etc), nunca null
     *   • MFA_ENCRYPTION_KEY    — exigido se algum super_admin tiver MFA habilitado
     *
     * Em dev (APP_ENV=development) só faz log warning das ausentes — não bloqueia.
     *
     * @return string[]  Lista de problemas encontrados (vazia se OK)
     */
    public static function validateProduction(): array
    {
        self::load();
        $env = strtolower(self::get('APP_ENV', 'development'));
        $isProd = in_array($env, ['production', 'prod'], true);

        $problems = [];

        if (self::get('DB_PASS', '') === '') {
            $problems[] = 'DB_PASS vazio (root sem senha não é aceito em produção)';
        }
        $cron = self::get('CRON_TOKEN', '');
        if ($cron === '' || $cron === 'yuris_cron_token_change_me') {
            $problems[] = 'CRON_TOKEN não configurado ou usando default inseguro';
        }
        $gw = strtolower(self::get('BILLING_GATEWAY', 'null'));
        if (in_array($gw, ['', 'null', 'noop', 'dev'], true)) {
            $problems[] = 'BILLING_GATEWAY=null/dev — em produção exige gateway real (stripe/mercadopago/asaas)';
        }
        // MFA_ENCRYPTION_KEY: só obrigatório se super_admin tiver MFA habilitado.
        // Não checamos aqui (precisaria de DB). Documentado no helper TotpHelper.

        // Logs sempre. Em prod, aborta.
        if (!empty($problems) && $isProd) {
            $msg = "[EnvLoader] CRITICAL: variáveis de produção ausentes/inseguras:\n  - "
                 . implode("\n  - ", $problems);
            error_log($msg);
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok'    => false,
                'error' => 'Serviço indisponível: configuração de produção inválida. Contate o operador.',
            ]);
            exit;
        }
        if (!empty($problems)) {
            error_log("[EnvLoader] WARNING (dev): configuração não-prod com problemas:\n  - "
                    . implode("\n  - ", $problems));
        }
        return $problems;
    }
}
