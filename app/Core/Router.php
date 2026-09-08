<?php

namespace App\Core;

/**
 * Router — a camada de rota de public/ (debito D3, 08/09/2026).
 *
 * ---------------------------------------------------------------------------
 * POR QUE ELA EXISTE
 * ---------------------------------------------------------------------------
 * Ate aqui, o caminho do arquivo em public/ ERA a URL. Consequencias:
 *
 *  1. public/ nao podia ser reorganizado: mover um arquivo mudava um endereco
 *     publico, quebrando link salvo, link de e-mail ja enviado e webhook.
 *  2. A URL limpa (/dashboard em vez de /dashboard.php) so existia em
 *     PRODUCAO, feita por rewrite no nginx do host, fora do repositorio.
 *     Localmente /dashboard dava 404. O ambiente de desenvolvimento nao
 *     reproduzia o de producao, e uma pagina-pasta nova exigia editar o vhost.
 *  3. O 404 brandado (public/404.php) estava MORTO em producao: o
 *     ErrorDocument que o ativava morava num zz-yuris.conf que nao existe mais
 *     no container. Endereco errado devolvia a pagina padrao do Apache,
 *     expondo "Apache/2.4.67 (Debian)".
 *
 * ---------------------------------------------------------------------------
 * COMO ELA E LIGADA, E POR QUE O RAIO DE ACAO E PEQUENO
 * ---------------------------------------------------------------------------
 * public/.htaccess so manda para ca o que NAO existe no disco:
 *
 *     RewriteCond %{REQUEST_FILENAME} !-f
 *     RewriteCond %{REQUEST_FILENAME} !-d
 *     RewriteRule . index.php [L]
 *
 * Ou seja: todo arquivo que existe continua sendo servido pelo Apache
 * exatamente como antes, sem passar por aqui. O router so ve URL que HOJE
 * daria 404. Um defeito aqui nao alcanca nenhuma pagina existente.
 *
 * ---------------------------------------------------------------------------
 * ORDEM DE RESOLUCAO
 * ---------------------------------------------------------------------------
 *  1. Tabela declarada em config/rotas.php (e onde entra pagina movida para
 *     fora de public/).
 *  2. Sondagem em public/: /x -> x.php, /x/ -> x/index.php. Isto replica
 *     em PHP o que o nginx faz em producao, entao local passa a se comportar
 *     como producao.
 *  3. Nada casou -> 404 brandado (HTML) ou JSON, se o caminho for /api/.
 */
final class Router
{
    /** Pastas de public/ que nunca sao servidas por rota. */
    private const PROIBIDAS = ['includes', 'uploads'];

    private static ?array $tabela = null;
    private static ?array $rotaAtual = null;

    /**
     * Caminho canonico da rota em curso (ex.: '/dpo'), ou null se a requisicao
     * nao passou pelo router. Usado para montar <link rel="canonical">: paginas
     * servidas por rota nao podem derivar isso de SCRIPT_NAME, que passa a
     * valer '/index.php'.
     */
    public static function caminhoAtual(): ?string
    {
        return self::$rotaAtual['canonica'] ?? null;
    }

    /** Tabela de rotas declarada em config/rotas.php. */
    public static function tabela(): array
    {
        if (self::$tabela === null) {
            $arq = self::raiz() . '/config/rotas.php';
            self::$tabela = is_file($arq) ? (array) require $arq : [];
        }
        return self::$tabela;
    }

    /** Permite ao teste injetar uma tabela sem tocar no arquivo real. */
    public static function definirTabela(?array $t): void
    {
        self::$tabela = $t;
    }

    private static function raiz(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /**
     * Transforma a URI crua no caminho normalizado, ou null se for hostil.
     * Rejeita byte nulo, segmento '..' e qualquer coisa que escape da raiz.
     */
    public static function normaliza(string $uri): ?string
    {
        $caminho = explode('?', $uri, 2)[0];
        $caminho = explode('#', $caminho, 2)[0];
        $caminho = rawurldecode($caminho);

        if ($caminho === '' || $caminho[0] !== '/') {
            $caminho = '/' . $caminho;
        }
        if (str_contains($caminho, "\0")) {
            return null;
        }
        $caminho = str_replace('\\', '/', $caminho);
        $caminho = preg_replace('#/+#', '/', $caminho);

        foreach (explode('/', $caminho) as $seg) {
            if ($seg === '..') {
                return null;
            }
        }
        return $caminho;
    }

    /**
     * Resolve um caminho para o arquivo que deve responder por ele.
     *
     * @return array{arquivo:string,canonica:string,origem:string}|null
     */
    public static function resolve(string $uri): ?array
    {
        $caminho = self::normaliza($uri);
        if ($caminho === null) {
            return null;
        }

        $raiz    = self::raiz();
        $publico = $raiz . '/public';

        // ── 1. tabela declarada ──────────────────────────────────────────
        $tabela = self::tabela();
        foreach (self::chaves($caminho) as $chave) {
            if (!isset($tabela[$chave])) {
                continue;
            }
            $rota = $tabela[$chave];
            $arq  = $raiz . '/' . ltrim(is_array($rota) ? $rota['arquivo'] : $rota, '/');
            if (!is_file($arq)) {
                return null;   // rota declarada apontando para o vazio: nao inventa
            }
            // A tabela e codigo do repositorio, nao entrada de usuario, mas um
            // '../' distraido numa linha dela serviria arquivo de fora do
            // projeto. A rota nao sai da raiz.
            $real = str_replace('\\', '/', (string) realpath($arq));
            if ($real === '' || !str_starts_with($real, $raiz . '/')) {
                return null;
            }
            return [
                'arquivo'  => $real,
                'canonica' => (is_array($rota) ? ($rota['canonica'] ?? $chave) : $chave),
                'origem'   => 'tabela',
            ];
        }

        // ── 2. sondagem em public/ (o que o nginx faz em producao) ───────
        $primeiro = explode('/', ltrim($caminho, '/'))[0];
        if (in_array($primeiro, self::PROIBIDAS, true)) {
            return null;
        }

        $sem = rtrim($caminho, '/');
        $candidatos = [];
        if ($sem !== '') {
            $candidatos[] = [$publico . $sem . '.php', $sem];
        }
        $candidatos[] = [$publico . $caminho . (str_ends_with($caminho, '/') ? '' : '/') . 'index.php', rtrim($caminho, '/') . '/'];

        foreach ($candidatos as [$arq, $canonica]) {
            if (!is_file($arq)) {
                continue;
            }
            $real = str_replace('\\', '/', (string) realpath($arq));
            if ($real === '' || !str_starts_with($real, $publico . '/')) {
                continue;   // symlink ou traversal apontando para fora de public/
            }
            // Nunca reentrar no proprio front controller.
            if ($real === $publico . '/index.php') {
                continue;
            }
            $relativo = substr($real, strlen($publico) + 1);
            if (in_array(explode('/', $relativo)[0], self::PROIBIDAS, true)) {
                continue;
            }
            return ['arquivo' => $real, 'canonica' => $canonica, 'origem' => 'public'];
        }

        return null;
    }

    /** Formas equivalentes de escrever o mesmo endereco. */
    private static function chaves(string $caminho): array
    {
        $c = [$caminho];
        if ($caminho !== '/' && str_ends_with($caminho, '/')) {
            $c[] = rtrim($caminho, '/');
        }
        if (str_ends_with($caminho, '.php')) {
            $c[] = substr($caminho, 0, -4);
        } else {
            $c[] = rtrim($caminho, '/') . '.php';
        }
        return array_values(array_unique(array_filter($c, fn ($x) => $x !== '')));
    }

    /**
     * Ponto de entrada em tempo de execucao. Devolve o ARQUIVO que public/index.php
     * deve incluir, ou null quando a resposta ja foi escrita (404 de API).
     *
     * Por que devolver em vez de incluir aqui: o require tem de acontecer no
     * ESCOPO GLOBAL. A primeira versao incluia a pagina de dentro deste metodo,
     * e as variaveis de topo do arquivo incluido viravam locais do metodo. O
     * efeito, pego na varredura diferencial: a home perdeu o icone do WhatsApp
     * em todos os botoes, porque v2/partials/_render.php faz `global $waSvg` e
     * o $waSvg definido por includes/lp_helpers.php nao era mais global.
     * Pagina PHP de topo de pilha espera escopo global; a camada de rota nao
     * pode tirar isso dela.
     */
    public static function resolveRequisicao(?string $uri = null): ?string
    {
        $uri  = $uri ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $rota = self::resolve($uri);

        if ($rota !== null) {
            self::$rotaAtual = $rota;
            return $rota['arquivo'];
        }
        return self::naoEncontrado($uri);
    }

    /**
     * 404: JSON quando o caminho e de API, HTML brandado no resto.
     * Devolve o arquivo a incluir, ou null se ja respondeu sozinho.
     */
    private static function naoEncontrado(string $uri): ?string
    {
        $caminho = self::normaliza($uri) ?? '/';
        http_response_code(404);

        if (str_starts_with($caminho, '/api/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                ['success' => false, 'error' => 'Endpoint nao encontrado'],
                JSON_UNESCAPED_UNICODE
            );
            return null;
        }

        $pagina = self::raiz() . '/public/404.php';
        if (is_file($pagina)) {
            return $pagina;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Pagina nao encontrada';
        return null;
    }
}
