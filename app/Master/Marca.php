<?php
namespace App\Master;

/**
 * Marca: o nome, a cor, o logo e o domínio de uma conta da edição CRM comercial.
 *
 * ---------------------------------------------------------------------------
 * O QUE MUDOU
 * ---------------------------------------------------------------------------
 * A edição CRM comercial (`configuracoes.produto = 'fleetiflow'`) nasceu para
 * uma conta só, e a marca Fleetiflow ficou escrita no código: nome no menu e na
 * aba, ícone, favicon, o azul #015DFC em cada tela. Para criar a mesma edição
 * para outra marca (Inovaize, Autodoc...) bastaria uma conta nova, mas ela
 * apareceria com o nome e o logo do Fleetiflow.
 *
 * Agora a marca é DADO da conta, em `accounts.configuracoes.marca`:
 *
 *   { "nome": "Inovaize", "subtitulo": "Central Comercial", "cor": "#7C3AED",
 *     "dominio": "crm.inovaize.com.br", "logo": "<sha1>", "icone": "<sha1>" }
 *
 * e as imagens em `account_marca_arquivos` (migration 133), servidas por
 * `/api/marca_arquivo.php?h=<sha1>`. O Painel Master cria e edita.
 *
 * Conta CRM SEM `marca` gravada (a Fleetiflow original) continua exatamente
 * como era: `padraoFleetiflow()` devolve os mesmos arquivos e a mesma paleta.
 *
 * Tudo aqui é PURO, menos o que tem `\PDO` na assinatura.
 */
final class Marca
{
    public const COR_PADRAO       = '#015DFC';
    public const SUBTITULO_PADRAO = 'Central Comercial';
    public const TIPOS            = ['logo', 'icone'];
    public const MIMES            = ['image/png', 'image/jpeg', 'image/webp'];
    /** Logo de tela de login não precisa de mais que isso; acima, é foto sem tratar. */
    public const MAX_BYTES        = 1_500_000;

    /** A paleta original do Fleetiflow, tom a tom, para não mudar um pixel da conta que já existe. */
    private const PALETA_FLEETIFLOW = [
        'marca'  => '#015DFC', 'forte' => '#013DF2', 'suave' => '#D6E4FF',
        'media'  => '#A9C6FF', 'clara' => '#6D9DFD', 'escura' => '#0B2A6B',
        'rgb'    => '1,93,252', 'texto' => '#FFFFFF',
    ];

    /* ===================================================================== */
    /* resolução                                                              */
    /* ===================================================================== */

    /**
     * A marca pronta para a tela, a partir da linha de `accounts`.
     *
     * @return array{nome:string, subtitulo:string, cor:string, paleta:array<string,string>,
     *               logo_url:?string, icone_url:?string, favicon_url:string,
     *               inicial:string, dominio:?string, personalizada:bool}
     */
    public static function daConta(array $account): array
    {
        $config = json_decode((string) ($account['configuracoes'] ?? ''), true);
        $m = is_array($config) && is_array($config['marca'] ?? null) ? $config['marca'] : null;

        if ($m === null || trim((string) ($m['nome'] ?? '')) === '') {
            return self::padraoFleetiflow();
        }

        $nome  = self::texto($m['nome'], 60);
        $cor   = self::validarCor($m['cor'] ?? null) ?? self::COR_PADRAO;
        $logo  = self::hashValido($m['logo'] ?? null) ? self::urlArquivo($m['logo']) : null;
        $icone = self::hashValido($m['icone'] ?? null) ? self::urlArquivo($m['icone']) : null;

        return [
            'nome'          => $nome,
            'subtitulo'     => self::texto($m['subtitulo'] ?? '', 60) ?: self::SUBTITULO_PADRAO,
            'cor'           => $cor,
            'paleta'        => self::paleta($cor),
            'logo_url'      => $logo,
            'icone_url'     => $icone,
            'favicon_url'   => $icone ?? self::faviconDeLetra(self::inicial($nome), $cor),
            'inicial'       => self::inicial($nome),
            'dominio'       => self::validarDominio($m['dominio'] ?? null),
            'agente'        => self::agente($m['agente_nome'] ?? ''),
            'agente_webhook'=> self::validarWebhook($m['agente_webhook'] ?? null),
            'personalizada' => true,
        ];
    }

    /** Nome do agente de IA e o artigo que vai antes dele nas frases da tela. */
    private static function agente(mixed $nome): array
    {
        $n = self::texto($nome, 40);
        return $n === '' ? ['nome' => 'IA', 'artigo' => 'a'] : ['nome' => $n, 'artigo' => ''];
    }

    /** Endereço do agente de pré-venda (n8n ou outro): só https. */
    public static function validarWebhook(mixed $url): ?string
    {
        $u = trim((string) $url);
        if ($u === '' || strlen($u) > 500) return null;
        if (!filter_var($u, FILTER_VALIDATE_URL)) return null;
        return strtolower((string) parse_url($u, PHP_URL_SCHEME)) === 'https' ? $u : null;
    }

    /** A marca da conta Fleetiflow original, igual ao que estava escrito no código. */
    public static function padraoFleetiflow(): array
    {
        return [
            'nome'          => 'Fleetiflow',
            'subtitulo'     => self::SUBTITULO_PADRAO,
            'cor'           => self::COR_PADRAO,
            'paleta'        => self::PALETA_FLEETIFLOW,
            'logo_url'      => '/sistema_vendas/Imagens/fleetiflow-horizontal.png',
            'icone_url'     => '/sistema_vendas/Imagens/fleetiflow-icone.png',
            'favicon_url'   => '/assets/fleetiflow-favicon-192.png?v=1',
            'inicial'       => 'F',
            'dominio'       => null,
            'agente'        => ['nome' => 'Vitória', 'artigo' => 'a'],
            'agente_webhook'=> null,   // a Vitória usa o endereço do .env (SdrFleetiflow::url)
            'personalizada' => false,
        ];
    }

    /* ===================================================================== */
    /* validação                                                              */
    /* ===================================================================== */

    /**
     * Confere e limpa o que veio do formulário. Lança InvalidArgumentException
     * com mensagem para a tela quando algo não serve.
     *
     * @return array{nome:string, subtitulo:string, cor:string, dominio:?string,
     *               agente_nome:string, agente_webhook:?string}
     */
    public static function normalizar(array $entrada): array
    {
        $nome = self::texto($entrada['nome'] ?? '', 60);
        if ($nome === '') {
            throw new \InvalidArgumentException('Informe o nome da marca.');
        }
        $corBruta = trim((string) ($entrada['cor'] ?? ''));
        $cor = $corBruta === '' ? self::COR_PADRAO : self::validarCor($corBruta);
        if ($cor === null) {
            throw new \InvalidArgumentException('Cor inválida. Use o formato #RRGGBB.');
        }
        $domBruto = trim((string) ($entrada['dominio'] ?? ''));
        $dominio  = $domBruto === '' ? null : self::validarDominio($domBruto);
        if ($domBruto !== '' && $dominio === null) {
            throw new \InvalidArgumentException('Domínio inválido. Use só o endereço, por exemplo crm.suaempresa.com.br.');
        }
        $hookBruto = trim((string) ($entrada['agente_webhook'] ?? ''));
        $hook = $hookBruto === '' ? null : self::validarWebhook($hookBruto);
        if ($hookBruto !== '' && $hook === null) {
            throw new \InvalidArgumentException('Endereço do agente inválido. Use um endereço https completo.');
        }
        return [
            'nome'           => $nome,
            'subtitulo'      => self::texto($entrada['subtitulo'] ?? '', 60) ?: self::SUBTITULO_PADRAO,
            'cor'            => $cor,
            'dominio'        => $dominio,
            'agente_nome'    => self::texto($entrada['agente_nome'] ?? '', 40),
            'agente_webhook' => $hook,
        ];
    }

    /** '#abc', 'ABCDEF', '#AbCdEf' => '#AABBCC'. Qualquer outra coisa => null. */
    public static function validarCor(mixed $cor): ?string
    {
        $c = ltrim(trim((string) $cor), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/', $c)) {
            $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
        }
        return preg_match('/^[0-9a-fA-F]{6}$/', $c) ? '#' . strtoupper($c) : null;
    }

    /**
     * Só o host, minúsculo: aceita colarem "https://crm.x.com.br/login" e guarda
     * "crm.x.com.br". Exige ponto, só letras/dígitos/hífen (domínio com acento
     * entra na forma xn--). Domínio do próprio Yuris é recusado.
     */
    public static function validarDominio(mixed $dominio): ?string
    {
        $d = strtolower(trim((string) $dominio));
        if ($d === '') return null;
        $d = (string) preg_replace('#^[a-z]+://#', '', $d);
        $d = explode('/', $d, 2)[0];
        $d = (string) preg_replace('/:\d+$/', '', $d);
        $d = rtrim($d, '.');
        if (strlen($d) > 190 || !str_contains($d, '.')) return null;
        foreach (explode('.', $d) as $parte) {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $parte)) return null;
        }
        if (preg_match('/(^|\.)yuris\.com\.br$/', $d) || $d === 'localhost') return null;
        return $d;
    }

    /* ===================================================================== */
    /* cor                                                                    */
    /* ===================================================================== */

    /**
     * Os tons usados pelas telas, derivados da cor principal. Para o azul do
     * Fleetiflow devolve a paleta original, tom a tom.
     *
     * @return array{marca:string, forte:string, suave:string, media:string,
     *               clara:string, escura:string, rgb:string, texto:string}
     */
    public static function paleta(string $hex): array
    {
        $hex = self::validarCor($hex) ?? self::COR_PADRAO;
        if ($hex === self::COR_PADRAO) return self::PALETA_FLEETIFLOW;

        // A cor principal aparece como texto em fundo branco (links, abas,
        // valores) e como fundo de botão. Cor clara demais (dourado, amarelo,
        // laranja) não dá leitura no branco: ela escurece até o contraste
        // WCAG de 4,5:1. Os tons claros (suave, media, clara) continuam
        // saindo da cor original, então o fundo dos selos guarda o tom vivo.
        $original = self::rgb($hex);
        $rgb      = self::legivelNoBranco($original);
        return [
            'marca'  => self::misturar($rgb, [0, 0, 0], 1.0),
            'forte'  => self::misturar($rgb, [0, 0, 0], 0.82),
            'suave'  => self::misturar($original, [255, 255, 255], 0.16),
            'media'  => self::misturar($original, [255, 255, 255], 0.34),
            'clara'  => self::misturar($original, [255, 255, 255], 0.57),
            'escura' => self::misturar($rgb, [0, 0, 0], 0.42),
            'rgb'    => implode(',', $rgb),
            // Texto sobre a cor da marca: o de MAIOR contraste (WCAG) entre o
            // branco e o grafite #1F2937. O corte antigo (luminância > 0,55)
            // deixava branco em dourado e laranja, que fica ilegível.
            'texto'  => self::textoSobre($rgb),
        ];
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    /** $peso da cor da marca, o resto da outra cor. */
    private static function misturar(array $cor, array $com, float $peso): string
    {
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $v = (int) round($com[$i] + ($cor[$i] - $com[$i]) * $peso);
            $out .= sprintf('%02X', max(0, min(255, $v)));
        }
        return $out;
    }

    /** A cor, escurecida aos poucos até ter contraste 4,5:1 com o branco. */
    private static function legivelNoBranco(array $rgb): array
    {
        for ($peso = 1.0; $peso > 0.2; $peso -= 0.02) {
            $c = array_map(static fn (int $v): int => (int) round($v * $peso), $rgb);
            if (1.05 / (self::luminancia($c) + 0.05) >= 4.5) return $c;
        }
        return array_map(static fn (int $v): int => (int) round($v * 0.2), $rgb);
    }

    /** Branco ou grafite, o que tiver mais contraste (WCAG) com a cor. */
    private static function textoSobre(array $rgb): string
    {
        $l = self::luminancia($rgb);
        $contrasteBranco  = 1.05 / ($l + 0.05);
        $contrasteGrafite = ($l + 0.05) / (self::luminancia([0x1F, 0x29, 0x37]) + 0.05);
        return $contrasteGrafite > $contrasteBranco ? '#1F2937' : '#FFFFFF';
    }

    /** Luminância relativa (WCAG), de 0 a 1. */
    private static function luminancia(array $rgb): float
    {
        $c = array_map(static function (int $v): float {
            $s = $v / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, $rgb);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /* ===================================================================== */
    /* imagens                                                                */
    /* ===================================================================== */

    /** Aceita "data:image/png;base64,...." ou o base64 puro. Devolve o binário. */
    public static function decodificarUpload(string $entrada): string
    {
        $b64 = str_contains($entrada, ',') ? explode(',', $entrada, 2)[1] : $entrada;
        $bin = base64_decode(preg_replace('/\s+/', '', $b64), true);
        if ($bin === false || $bin === '') {
            throw new \InvalidArgumentException('Arquivo de imagem inválido.');
        }
        return $bin;
    }

    /**
     * Confere que é PNG, JPEG ou WebP de verdade, pelos bytes, e dentro do
     * tamanho. SVG fica de fora de propósito: é um documento que roda script, e
     * esta imagem é servida sem sessão, do domínio do sistema.
     *
     * @return array{mime:string, largura:?int, altura:?int}
     */
    public static function validarImagem(string $bin): array
    {
        if (strlen($bin) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Imagem grande demais (máximo 1,5 MB).');
        }
        $mime = match (true) {
            str_starts_with($bin, "\x89PNG\r\n\x1A\n")                         => 'image/png',
            str_starts_with($bin, "\xFF\xD8\xFF")                              => 'image/jpeg',
            str_starts_with($bin, 'RIFF') && substr($bin, 8, 4) === 'WEBP'     => 'image/webp',
            default                                                            => null,
        };
        if ($mime === null) {
            throw new \InvalidArgumentException('Use uma imagem PNG, JPG ou WebP.');
        }
        $info = @getimagesizefromstring($bin);
        if ($info === false && $mime !== 'image/webp') {
            throw new \InvalidArgumentException('A imagem está corrompida.');
        }
        return [
            'mime'    => $mime,
            'largura' => $info ? (int) $info[0] : null,
            'altura'  => $info ? (int) $info[1] : null,
        ];
    }

    /** Grava (ou troca) a imagem da conta e devolve o hash, que vai para a marca. */
    public static function salvarArquivo(\PDO $pdo, int $accountId, string $tipo, string $bin): string
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException('Tipo de imagem inválido.');
        }
        $info = self::validarImagem($bin);
        $hash = sha1($bin);
        $pdo->prepare(
            'INSERT INTO account_marca_arquivos (account_id, tipo, mime, conteudo, hash, largura, altura)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mime = VALUES(mime), conteudo = VALUES(conteudo), hash = VALUES(hash),
                                     largura = VALUES(largura), altura = VALUES(altura)'
        )->execute([$accountId, $tipo, $info['mime'], $bin, $hash, $info['largura'], $info['altura']]);
        return $hash;
    }

    public static function removerArquivo(\PDO $pdo, int $accountId, string $tipo): void
    {
        $pdo->prepare('DELETE FROM account_marca_arquivos WHERE account_id = ? AND tipo = ?')
            ->execute([$accountId, $tipo]);
    }

    public static function urlArquivo(string $hash): string
    {
        return '/api/marca_arquivo.php?h=' . $hash;
    }

    public static function hashValido(mixed $h): bool
    {
        return is_string($h) && preg_match('/^[0-9a-f]{40}$/', $h) === 1;
    }

    /* ===================================================================== */
    /* gravação e domínio                                                     */
    /* ===================================================================== */

    /**
     * Grava a marca dentro de `configuracoes`, preservando o resto do JSON
     * (produto e qualquer outra chave). $imagens: tipo => hash, ou null para
     * tirar a imagem; tipo ausente mantém o que estava.
     */
    public static function gravar(\PDO $pdo, int $accountId, array $marca, array $imagens = []): void
    {
        $st = $pdo->prepare('SELECT configuracoes FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$accountId]);
        $config = json_decode((string) $st->fetchColumn(), true);
        if (!is_array($config)) $config = [];

        $atual = is_array($config['marca'] ?? null) ? $config['marca'] : [];
        $nova  = [
            'nome'           => $marca['nome'],
            'subtitulo'      => $marca['subtitulo'],
            'cor'            => $marca['cor'],
            'dominio'        => $marca['dominio'],
            'agente_nome'    => $marca['agente_nome'] ?? '',
            'agente_webhook' => $marca['agente_webhook'] ?? null,
        ];
        foreach (self::TIPOS as $t) {
            if (array_key_exists($t, $imagens)) {
                if ($imagens[$t] !== null) $nova[$t] = $imagens[$t];
            } elseif (self::hashValido($atual[$t] ?? null)) {
                $nova[$t] = $atual[$t];
            }
        }
        $config['marca'] = $nova;

        $pdo->prepare('UPDATE accounts SET configuracoes = ?, updated_at = NOW() WHERE id = ?')
            ->execute([json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $accountId]);
    }

    /**
     * A conta CRM ativa cujo domínio de marca é este host, ou null. É o que faz
     * a tela de login de crm.inovaize.com.br aparecer com a marca Inovaize.
     * Procura pelo texto do JSON e confirma decodificando (portável entre o
     * MySQL de produção e o MariaDB de desenvolvimento).
     */
    public static function contaPorDominio(\PDO $pdo, string $host, ?int $excetoConta = null): ?array
    {
        $host = self::validarDominio($host);
        if ($host === null) return null;
        $like = '%' . addcslashes('"dominio":"' . $host . '"', '%_\\') . '%';
        $st = $pdo->prepare(
            "SELECT id, nome, status, configuracoes FROM accounts
              WHERE deleted_at IS NULL AND configuracoes LIKE ?"
        );
        $st->execute([$like]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($excetoConta !== null && (int) $row['id'] === $excetoConta) continue;
            if (Account::getProduto($row) !== 'fleetiflow') continue;
            $m = self::daConta($row);
            if ($m['personalizada'] && $m['dominio'] === $host) return $row;
        }
        return null;
    }

    /* ===================================================================== */
    /* miúdos                                                                 */
    /* ===================================================================== */

    private static function texto(mixed $v, int $max): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $v)));
        return mb_substr($s, 0, $max);
    }

    private static function inicial(string $nome): string
    {
        $c = mb_substr(trim($nome), 0, 1);
        return $c === '' ? '?' : mb_strtoupper($c);
    }

    /** Favicon em SVG com a inicial na cor da marca, para marca sem ícone enviado. */
    private static function faviconDeLetra(string $letra, string $cor): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
             . '<rect width="64" height="64" rx="14" fill="' . $cor . '"/>'
             . '<text x="32" y="44" font-family="Arial,Helvetica,sans-serif" font-size="36" font-weight="700" '
             . 'text-anchor="middle" fill="' . self::paleta($cor)['texto'] . '">'
             . htmlspecialchars($letra, ENT_XML1) . '</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
