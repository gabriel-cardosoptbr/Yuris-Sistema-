<?php
namespace App\Core;

/**
 * UrlLimpa: o endereço das páginas sem ".php" (20/10/2026, pedido do dono do
 * produto: "tá horrível esse PHP").
 *
 * A camada de rota (Router, config/rotas.php, public/index.php) já serve
 * /dashboard a partir de public/dashboard.php. Faltava quem chega pelo endereço
 * antigo ir para o limpo: /dashboard.php?x=1 recebe 301 para /dashboard?x=1, e o
 * #fragmento (o token de /assinar#t=...) o navegador mantém sozinho.
 *
 * Feito aqui, e não no .htaccess, porque em produção o Apache fica atrás do nginx
 * com HTTPS: o redirecionamento do Apache montaria o endereço com http://. Daqui
 * o destino vai relativo.
 *
 * Só age quando TUDO isto vale (o resto do sistema não percebe):
 *   - GET ou HEAD (um POST redirecionado perderia o corpo: login, formulários);
 *   - o caminho pedido é exatamente /<página>.php da raiz de public/ e é este
 *     arquivo que está rodando (página servida pela rota tem SCRIPT_NAME
 *     /index.php e caminho sem .php: não entra, e por isso não há laço);
 *   - não é API, subpasta, linha de comando, nem uma das páginas que mantêm o
 *     endereço (FICAM).
 */
final class UrlLimpa
{
    /** Páginas da raiz que não mudam de endereço. login-fleetiflow tem regra própria (vai para "/" no domínio CRM). */
    private const FICAM = ['index', 'index-v1-legacy', 'login-fleetiflow', '404'];

    public static function redirecionar(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        $metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($metodo !== 'GET' && $metodo !== 'HEAD') return;
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $caminho = (string) parse_url($uri, PHP_URL_PATH);
        if (!preg_match('#^/([a-z0-9_-]+)\.php$#i', $caminho, $m)) return;
        if (strcasecmp(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), $m[1] . '.php') !== 0) return;
        if (in_array(strtolower($m[1]), self::FICAM, true)) return;
        $qs = (string) parse_url($uri, PHP_URL_QUERY);
        header('Location: /' . $m[1] . ($qs !== '' ? '?' . $qs : ''), true, 301);
        exit;
    }

    /**
     * O href limpo de um link interno: 'dashboard.php?x=1' vira '/dashboard?x=1' e
     * 'configuracoes/privacidade.php' vira '/configuracoes/privacidade'. Absoluto,
     * para valer em qualquer profundidade. Link externo, âncora ou sem .php volta igual.
     */
    public static function href(string $href): string
    {
        if ($href === '' || preg_match('#^(https?:|//|\#|mailto:|tel:|javascript:)#i', $href)) return $href;
        if (!preg_match('#^/?([a-z0-9_/-]+)\.php((?:[?\#].*)?)$#i', $href, $m)) return $href;
        if (in_array(strtolower(basename($m[1])), self::FICAM, true)) return $href;
        return '/' . $m[1] . $m[2];
    }
}
