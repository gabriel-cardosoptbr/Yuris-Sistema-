<?php
namespace App\Helpers;

/**
 * Url — geração de URLs respeitando o base path da aplicação.
 *
 * Em dev local (XAMPP), o app vive em http://localhost/sistema_vendas/
 * — então URLs absolutas precisam do prefixo /sistema_vendas/.
 *
 * Em produção Linux com DocumentRoot apontando direto pra public/, o app
 * vive em https://seu-dominio.com/ — sem prefixo.
 *
 * Esta classe centraliza essa decisão lendo APP_URL do .env e calculando
 * o base path. Substitui as 458+ ocorrências hardcoded de "/sistema_vendas/"
 * espalhadas pelo código (refactor incremental — não obrigatório para
 * deploy, mas recomendado para evitar gambiarra de Apache Alias).
 *
 * ──────────────────────────────────────────────────────────────────────
 * USO
 * ──────────────────────────────────────────────────────────────────────
 *
 * // PHP redirect:
 * header('Location: ' . \App\Helpers\Url::to('/login.php'));
 *
 * // HTML href:
 * <a href="<?= \App\Helpers\Url::to('/dashboard.php') ?>">Dashboard</a>
 *
 * // CSS/JS asset:
 * <link rel="stylesheet" href="<?= \App\Helpers\Url::asset('/assets/style.css') ?>">
 * <script src="<?= \App\Helpers\Url::asset('/assets/app.js') ?>"></script>
 *
 * // JS inline via base:
 * <base href="<?= \App\Helpers\Url::base() ?>/">  ← pro browser resolver relativos
 *
 * ──────────────────────────────────────────────────────────────────────
 * COMPORTAMENTO
 * ──────────────────────────────────────────────────────────────────────
 *
 * base() devolve só o prefixo (sem trailing slash):
 *   APP_URL=http://localhost/sistema_vendas        →  "/sistema_vendas"
 *   APP_URL=https://yuris.app.br                   →  ""
 *   APP_URL=https://app.com/yuris                  →  "/yuris"
 *
 * to('/login.php') concatena base() + path:
 *   dev   →  "/sistema_vendas/login.php"
 *   prod  →  "/login.php"
 *
 * asset() mesmo que to() mas com cache-buster opcional via filemtime.
 *
 * @since 2026-05-26 (auditoria pré-deploy AWS)
 */
class Url
{
    private static ?string $cached = null;

    /**
     * Retorna o base path (sem trailing slash). Calculado a partir de APP_URL.
     */
    public static function base(): string
    {
        if (self::$cached !== null) return self::$cached;

        $appUrl = EnvLoader::get('APP_URL', 'http://localhost/sistema_vendas');
        $parsed = parse_url($appUrl);
        $path   = $parsed['path'] ?? '';
        // Remove trailing slash pra ficar uniforme (concatenar com /algo depois)
        $path = rtrim($path, '/');
        return self::$cached = $path;
    }

    /**
     * Concatena base() + path. Sempre garante uma barra entre eles.
     *
     * @param string $path  Path relativo iniciando com `/` (ex: '/login.php')
     */
    public static function to(string $path): string
    {
        // Normaliza: garante que comece com /
        if ($path === '' || $path[0] !== '/') $path = '/' . $path;
        return self::base() . $path;
    }

    /**
     * Como to() mas com cache-buster automático via filemtime (defesa contra
     * cache antigo após deploy). Resolve o filemtime relativo ao DOCROOT.
     *
     * @param string $path     Path do asset (ex: '/assets/dashboard.css')
     * @param string|null $abs Absolute filesystem path do asset (opcional).
     *                         Se null, tenta resolver via __DIR__/../../public.
     */
    public static function asset(string $path, ?string $abs = null): string
    {
        $url = self::to($path);

        if ($abs === null) {
            // Tenta resolver relativo ao public/
            $abs = __DIR__ . '/../../public' . $path;
        }

        if (is_file($abs)) {
            $url .= '?v=' . filemtime($abs);
        }
        return $url;
    }

    /**
     * Reseta o cache (usado em testes ou se APP_URL mudar dinamicamente).
     */
    public static function reset(): void
    {
        self::$cached = null;
    }
}
