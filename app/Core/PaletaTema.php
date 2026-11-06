<?php
namespace App\Core;

/**
 * PALETA DO YURIS (06/11/2026): o escuro do leão e o claro da águia. EM PRÉVIA.
 *
 * O PEDIDO. Duas fotos de referência do dono do produto:
 *
 *   escuro  um leão azul-preto de olhos azuis. "Não é um azul, é um azul com
 *           preto." Os botões ganham o azul mais claro dos olhos.
 *   claro   uma águia de penas prateadas e olhos azuis. "O tema claro não tem
 *           que ser branco, tem que ser prateado."
 *
 * A foto do leão foi medida pixel a pixel (TONS_LEAO: a saturação que o pelo tem
 * em cada nível de luz). A da águia não chegou em arquivo, e os tons dela foram
 * lidos da imagem.
 *
 * COMO FUNCIONA. O desenho do sistema tem as cores escritas à mão em dezenas de
 * arquivos. Em vez de reescrever todos, este conversor lê o CSS que já existe e
 * devolve uma folha com as declarações de cor, já na cor nova. É o mesmo caminho
 * do App\Master\TemaEscuroCrm, e pelo mesmo motivo: um segundo desenho mantido à
 * mão se desencontra do primeiro a cada mudança.
 *
 * A folha é servida por /api/tema.php e carregada pelo menu lateral só nas contas
 * Yuris, e por enquanto só em quem ligou a prévia (?paleta=1). As contas de marca
 * (Fleetiflow, Inovaize, Via Autodoc) têm identidade própria e nunca recebem.
 *
 * A PRIMEIRA VERSÃO FOI REPROVADA, POR DOIS ERROS QUE FICAM REGISTRADOS:
 *
 * 1. "Ficou roxo." O matiz medido no pelo do leão é 227 a 235, e foi usado ao pé
 *    da letra. Numa foto, cercado de preto, isso lê como azul. Num painel chapado
 *    lê como roxo. O matiz agora é o azul que o sistema já tinha: o que muda é a
 *    luz (mais perto do preto) e a saturação (a do pelo naquela luz).
 *
 * 2. "O menu ganhou um retângulo, as peças ficaram transparentes." A folha levava
 *    só as declarações cuja cor MUDAVA. Uma regra `.sidebar { background:#fff }`
 *    perdia, no desenho original, para outra de mesma força escrita depois dela,
 *    `.sidebar { background:transparent }`. A cópia recolorida da primeira vinha
 *    depois de tudo e passou a ganhar. Por isso a folha leva agora TODAS as
 *    declarações de cor, mudem ou não: a disputa entre elas se repete inteira,
 *    na mesma ordem, dentro da própria folha.
 *
 * 3. "Tá horrível os dois." A folha era UMA para o sistema todo, somando o
 *    <style> de todas as telas. Só que as telas repetem nomes de classe com
 *    desenhos diferentes: a regra de borda verde do `.kpi-card` de Finanças caía
 *    na Visão Geral, e o desenho do menu das contas de marca, guardado no arquivo
 *    do menu dentro de um `if`, caía no menu do Yuris (o "retângulo"). Agora cada
 *    tela tem a SUA folha, feita só do CSS que ela carrega e na ordem em que
 *    carrega, sem os blocos de marca.
 *
 * A CONFERÊNCIA. public/assets/_conferir_paleta.js compara cada elemento da tela
 * com a paleta desligada e ligada. A paleta só pode trocar cor: fundo que aparece,
 * degradê que some ou borda que muda de largura é defeito. Rodar em toda tela
 * antes de ligar para alguém.
 *
 * POR QUE :where(). Cada regra gerada tem a MESMA especificidade da original (o
 * prefixo de tema vai dentro de :where(), que vale zero). Somado à regra acima,
 * quem ganhava continua ganhando.
 *
 * O QUE NÃO ALCANÇA: cor escrita em atributo style="" e cor posta por JS.
 */
final class PaletaTema
{
    /** Saturação do pelo do leão em cada nível de luz (HSL), medida na foto. */
    private const TONS_LEAO = [[2.9, 87.0], [6.1, 68.0], [9.0, 56.5], [12.7, 44.6], [18.0, 37.0], [25.1, 31.2], [34.5, 28.4]];

    /** Azul dos olhos: matiz e saturação do botão. */
    private const OLHO_MATIZ = 207.0;
    private const OLHO_SATURACAO = 88.0;

    /** Prata da pena da águia: matiz e saturação das superfícies claras. */
    private const PRATA_MATIZ = 212.0;
    private const PRATA_SATURACAO = 36.0;
    /** Luz do que era branco puro, e quanto o resto desce a cada ponto abaixo dele. */
    private const PRATA_LUZ_DO_BRANCO = 95.0;
    private const PRATA_INCLINACAO = 1.3;

    /**
     * Telas que têm paleta. Cada uma recebe a folha feita SÓ das origens dela
     * (ver o erro 3 no topo). Tela fora da lista fica com o desenho original.
     */
    public const PAGINAS = [
        'dashboard', 'planejamento', 'prospeccao', 'clientes', 'tarefas', 'processos', 'intimacoes', 'juridico',
        'chat', 'chat_interno', 'atendimento', 'relatorios', 'desempenho', 'financas', 'usuarios', 'escritorios',
        'agente', 'webhooks', 'configuracoes',
    ];

    /** Folhas de /assets que são de marca, de ambiente ou do site público: nunca entram. */
    private const FOLHA_DE_FORA = '/^(ff-|fleetiflow-|ambientes|tarefas-areas|projetos|assinar|landing|seo-pages|styles\.)/';

    public static function conhece(string $pagina): bool
    {
        return in_array($pagina, self::PAGINAS, true);
    }

    /**
     * Muda quando a página, o menu, qualquer folha de /assets ou o próprio
     * conversor mudam. É mais largo que as origens reais da página, de propósito:
     * custa só a data dos arquivos, sem ler nenhum.
     */
    public static function versao(string $publico, string $pagina): int
    {
        $v = max((int) filemtime(__FILE__), (int) @filemtime($publico . '/' . $pagina . '.php'), (int) @filemtime($publico . '/includes/sidebar.php'));
        foreach (glob($publico . '/assets/*.css') ?: [] as $f) { $v = max($v, (int) filemtime($f)); }
        return $v;
    }

    /**
     * A folha de um tema ('escuro' do leão ou 'claro' da águia) para UMA tela.
     */
    public static function gerar(string $publico, string $pagina, string $tema): string
    {
        if (!self::conhece($pagina)) return '';
        $saida = [];
        $ctxAberto = null;
        foreach (self::origens($publico, $publico . '/' . $pagina . '.php') as $bloco) {
            foreach (self::regras($bloco) as $r) {
                $linha = self::converterRegra($r['sel'], $r['corpo'], $tema);
                if ($linha === null) continue;
                $ctx = implode('', array_map(static fn ($c) => $c . '{', $r['ctx']));
                if ($ctx !== $ctxAberto) {
                    if ($ctxAberto !== null && $ctxAberto !== '') $saida[] = str_repeat('}', substr_count($ctxAberto, '{'));
                    if ($ctx !== '') $saida[] = $ctx;
                    $ctxAberto = $ctx;
                }
                $saida[] = $linha;
            }
        }
        if ($ctxAberto !== null && $ctxAberto !== '') $saida[] = str_repeat('}', substr_count($ctxAberto, '{'));
        return implode("\n", $saida) . "\n";
    }

    /**
     * O CSS que a tela carrega, NA ORDEM em que ela carrega: cada <link> de
     * /assets, cada <style>, e o que o menu lateral traz no ponto em que entra.
     * A ordem importa: é ela que decide o empate entre regras de mesma força.
     *
     * @return string[] blocos de CSS
     */
    private static function origens(string $publico, string $arquivo, bool $menu = false): array
    {
        $php = (string) @file_get_contents($arquivo);
        if ($php === '') return [];
        $visao = self::soHtmlDoYuris($php);
        $posMenu = $menu ? false : strpos($php, 'includes/sidebar.php');

        // (?<!['"]): <style> dentro de string é a tela de "Acesso negado" de algumas
        // páginas. Na visão o PHP já virou espaço, então isso nem aparece; fica a
        // guarda para o caso de HTML que escreve HTML.
        $padrao = '~<link\b[^>]*?href="/assets/([\w.-]+\.css)[^"]*"[^>]*>|(?<![\'"])<style\b[^>]*>(.*?)</style>~is';
        preg_match_all($padrao, $visao, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $blocos = [];
        $doMenu = static function () use (&$blocos, &$posMenu, $publico): void {
            $blocos = array_merge($blocos, self::origens($publico, $publico . '/includes/sidebar.php', true));
            $posMenu = false;
        };
        foreach ($m as $achado) {
            if ($posMenu !== false && (int) $achado[0][1] > $posMenu) $doMenu();
            if (($achado[1][0] ?? '') !== '') {
                if (!preg_match(self::FOLHA_DE_FORA, $achado[1][0])) {
                    $blocos = array_merge($blocos, self::folha($publico . '/assets/' . $achado[1][0]));
                }
                continue;
            }
            $css = (string) ($achado[2][0] ?? '');
            // Bloco que DEFINE as cores da marca ou do ambiente é desenho de marca.
            if (preg_match('/--(ff-marca|amb-c)[\w-]*\s*:/', $css)) continue;
            $blocos[] = $css;
        }
        if ($posMenu !== false) $doMenu();
        return $blocos;
    }

    /**
     * O arquivo com tudo que não é HTML da conta Yuris trocado por espaços, sem
     * mudar a posição de nada.
     *
     * SOME O CÓDIGO PHP e SOME O QUE É DAS CONTAS DE MARCA. O arquivo do menu
     * guarda, dentro de um `if ($_isFleetiflow)`, o desenho inteiro da edição CRM;
     * a tela do Chat tem um `if ($isFleetiflow)` no meio do próprio <style>. A
     * página só imprime esses trechos para a conta de marca, mas este conversor lê
     * o arquivo, não a página: sem este corte, o menu do Yuris ganhava o desenho
     * do Fleetiflow. Quem diz o que está dentro de qual `if` é o analisador do
     * próprio PHP (token_get_all), não uma busca por texto.
     */
    private static function soHtmlDoYuris(string $php): string
    {
        $saida = '';
        $alt = [];      // ifs de dois-pontos abertos: 'marca', 'yuris' ou 'neutro'
        $chaves = [];   // ifs de chave abertos: [profundidade, tipo]
        $fundo = 0;
        $tokens = token_get_all($php);
        $n = count($tokens);
        $prox = static function (int $i) use ($tokens, $n): int {
            for ($j = $i + 1; $j < $n; $j++) {
                if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) return $j;
            }
            return $n;
        };
        $oposto = ['marca' => 'yuris', 'yuris' => 'marca', 'neutro' => 'neutro'];
        $ultimoDeChave = 'neutro';

        for ($i = 0; $i < $n; $i++) {
            $tk = $tokens[$i];
            $texto = is_array($tk) ? $tk[1] : $tk;
            $id = is_array($tk) ? $tk[0] : null;

            if ($id === T_INLINE_HTML) {
                $deMarca = in_array('marca', $alt, true) || in_array('marca', array_column($chaves, 1), true);
                $saida .= $deMarca ? preg_replace('/[^\n]/', ' ', $texto) : $texto;
                continue;
            }
            $saida .= preg_replace('/[^\n]/', ' ', $texto);

            if ($id === T_IF || $id === T_ELSEIF) {
                // junta a condição até fechar o parêntese
                $j = $prox($i); $nivel = 0; $cond = '';
                for (; $j < $n; $j++) {
                    $c = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                    if ($c === '(') $nivel++;
                    if ($c === ')') { $nivel--; if ($nivel === 0) break; }
                    $cond .= $c;
                }
                $tipo = self::tipoDaCondicao($cond);
                $depois = $prox($j);
                $abre = $depois < $n && !is_array($tokens[$depois]) ? $tokens[$depois] : '';
                if ($abre === ':') {
                    if ($id === T_ELSEIF && $alt) array_pop($alt);
                    $alt[] = $tipo;
                } elseif ($abre === '{') {
                    $chaves[] = [$fundo + 1, $tipo];
                }
            } elseif ($id === T_ELSE) {
                $depois = $prox($i);
                $abre = $depois < $n && !is_array($tokens[$depois]) ? $tokens[$depois] : '';
                if ($abre === ':' && $alt)  $alt[count($alt) - 1] = $oposto[$alt[count($alt) - 1]];
                elseif ($abre === '{')      $chaves[] = [$fundo + 1, $oposto[$ultimoDeChave]];
            } elseif ($id === T_ENDIF) {
                array_pop($alt);
            } elseif ($texto === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $fundo++;
            } elseif ($texto === '}') {
                if ($chaves && $chaves[count($chaves) - 1][0] === $fundo) $ultimoDeChave = array_pop($chaves)[1];
                $fundo = max(0, $fundo - 1);
            }
        }
        return $saida;
    }

    /** "Se é conta de marca", "se NÃO é" (bloco do Yuris), ou outra coisa. */
    private static function tipoDaCondicao(string $cond): string
    {
        if (!preg_match('/(!?)\s*\$\w*(fleetiflow|edicaoCrm|ehCrm|isCrm|tkAmbientes|ambCtx|tkArea)\w*/i', $cond, $m)) return 'neutro';
        return $m[1] === '!' ? 'yuris' : 'marca';
    }

    /** Uma folha de /assets, com o que ela importa antes dela. @return string[] */
    private static function folha(string $caminho): array
    {
        $css = (string) @file_get_contents($caminho);
        if ($css === '') return [];
        $blocos = [];
        if (preg_match_all('~@import\s+url\(\s*[\'"]?\./([\w.-]+\.css)[\'"]?\s*\)~', $css, $m)) {
            foreach ($m[1] as $importada) {
                if (!preg_match(self::FOLHA_DE_FORA, $importada)) $blocos = array_merge($blocos, self::folha(dirname($caminho) . '/' . $importada));
            }
        }
        $blocos[] = $css;
        return $blocos;
    }

    /* ------------------------------------------------------------- cores -- */

    /**
     * A cor nova, ou null se esta cor não muda.
     *
     * @param int[]  $rgb
     * @param string $tema 'escuro', 'claro' (regra do tema claro) ou 'claro-base'
     *                     (regra sem tema, valendo no claro: só o azul muda)
     * @param string $tipo 'fundo', 'borda', 'texto' ou 'outro'
     * @return int[]|null
     */
    public static function mapear(array $rgb, string $tema, string $tipo): ?array
    {
        // As mesmas cores se repetem centenas de vezes nas folhas.
        static $feitas = [];
        $chave = $tema . $tipo . ($rgb[0] << 16 | $rgb[1] << 8 | $rgb[2]);
        // Guardado dentro de uma lista para o "não muda" (null) também ficar em cache.
        return ($feitas[$chave] ??= [self::calcular($rgb, $tema, $tipo)])[0];
    }

    /** @return int[]|null */
    private static function calcular(array $rgb, string $tema, string $tipo): ?array
    {
        [$h, $s, $l] = self::hsl($rgb);

        if ($tema === 'escuro' && $l < 20 && $s >= 22 && $h >= 198 && $h <= 228) {
            // "Um azul com preto": o MESMO azul, mais perto do preto, com a
            // saturação que o pelo tem naquela luz. O matiz não gira para o anil
            // da foto (ver o erro 1 no topo). O fundo desce mais que o cartão,
            // senão o cartão some: na foto, é o pelo iluminado que desenha o rosto.
            $luz = max(2.2, $l * (0.78 + max(0.0, min(1.0, ($l - 7) / 10)) * 0.17));
            return self::rgb(max(212.0, min(220.0, $h)), min(72.0, max($s, self::saturacaoDoLeao($luz))), $luz);
        }

        if ($tema === 'claro') {
            $neutro = $s <= 14 || ($h >= 195 && $h <= 240 && $s <= 58);
            if ($neutro && $tipo === 'fundo' && $l >= 86) {
                return self::rgb(self::PRATA_MATIZ, self::PRATA_SATURACAO, self::PRATA_LUZ_DO_BRANCO - (100 - $l) * self::PRATA_INCLINACAO);
            }
            if ($neutro && $tipo === 'borda' && $l >= 60) {
                return self::rgb(self::PRATA_MATIZ, max($s, 26.0), $l - 9);
            }
            // Texto apagado some no prata: desce um pouco para manter a leitura.
            if ($neutro && $tipo === 'texto' && $l >= 36 && $l <= 72 && $s <= 45) {
                return self::rgb($h, $s, $l - 8);
            }
        }

        // Azul de botão (o aço antigo) vira o azul do olho, mais aceso.
        if ($l >= 20 && $l <= 48 && $s >= 36 && $s < 75 && $h >= 203 && $h <= 221) {
            return self::comLuminancia(self::OLHO_MATIZ, self::OLHO_SATURACAO, self::acender(self::luminancia($rgb), 1.5));
        }

        // Azul de destaque: gira para o azul do olho. Como texto, mantém a
        // luminância (o contraste não muda); como fundo, acende um pouco.
        if ($s >= 70 && $l >= 28 && $l <= 88 && $h > 210 && $h <= 228) {
            $y = self::luminancia($rgb);
            if ($tipo === 'fundo') $y = self::acender($y, 1.25);
            // No prata o texto azul precisa ficar abaixo deste teto para dar contraste 4,5.
            if ($tema !== 'escuro' && $tipo === 'texto') $y = min($y, 0.135);
            return self::comLuminancia(199 + ($h - 210) * (11 / 18), min(100.0, $s + 2), $y);
        }

        return null;
    }

    /**
     * Clareia um fundo azul sem passar do teto em que o texto branco ainda dá
     * contraste 4,5. Quem já está acima do teto fica como está.
     */
    private static function acender(float $y, float $fator): float
    {
        return $y >= 0.175 ? $y : min($y * $fator, 0.175);
    }

    private static function saturacaoDoLeao(float $luz): float
    {
        $t = self::TONS_LEAO;
        if ($luz <= $t[0][0]) return $t[0][1];
        for ($i = 1; $i < count($t); $i++) {
            if ($luz <= $t[$i][0]) {
                $p = ($luz - $t[$i - 1][0]) / ($t[$i][0] - $t[$i - 1][0]);
                return $t[$i - 1][1] + $p * ($t[$i][1] - $t[$i - 1][1]);
            }
        }
        return $t[count($t) - 1][1];
    }

    /** @return float[] matiz 0..360, saturação e luz 0..100 */
    public static function hsl(array $c): array
    {
        [$r, $g, $b] = [$c[0] / 255, $c[1] / 255, $c[2] / 255];
        $mx = max($r, $g, $b); $mn = min($r, $g, $b);
        $l = ($mx + $mn) / 2; $d = $mx - $mn;
        if ($d < 1e-9) return [0.0, 0.0, $l * 100];
        $s = $d / (1 - abs(2 * $l - 1));
        if ($mx === $r)     $h = fmod(($g - $b) / $d, 6);
        elseif ($mx === $g) $h = ($b - $r) / $d + 2;
        else                $h = ($r - $g) / $d + 4;
        $h *= 60; if ($h < 0) $h += 360;
        return [$h, $s * 100, $l * 100];
    }

    /** @return int[] */
    public static function rgb(float $h, float $s, float $l): array
    {
        $s = max(0.0, min(100.0, $s)) / 100; $l = max(0.0, min(100.0, $l)) / 100;
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60  => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default  => [$c, 0, $x],
        };
        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }

    /** Luminância relativa (WCAG), 0..1. */
    public static function luminancia(array $c): float
    {
        $f = static fn (int $v): float => ($v / 255) <= 0.03928 ? ($v / 255) / 12.92 : (($v / 255 + 0.055) / 1.055) ** 2.4;
        return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
    }

    /** A cor deste matiz e saturação que tem a luminância pedida. @return int[] */
    private static function comLuminancia(float $h, float $s, float $alvo): array
    {
        $min = 0.0; $max = 100.0;
        for ($i = 0; $i < 22; $i++) {
            $meio = ($min + $max) / 2;
            if (self::luminancia(self::rgb($h, $s, $meio)) < $alvo) $min = $meio; else $max = $meio;
        }
        return self::rgb($h, $s, ($min + $max) / 2);
    }

    /* --------------------------------------------------------------- CSS -- */

    private static function converterRegra(string $seletor, string $corpo, string $tema): ?string
    {
        // Regra de marca ou de ambiente: identidade própria, fica como está. Olha só
        // o SELETOR: regra comum que usa var(--ff-marca, #2563EB) no corpo vale para
        // o Yuris pela cor de reserva, e tem de ser copiada como as outras.
        if (preg_match('/data-ambiente|\.ff-|\.ffc\b/', $seletor)) return null;

        $escuroExplicito = (bool) preg_match('/:not\(\s*\[data-theme=["\']?light["\']?\]\s*\)|\[data-theme=["\']?dark["\']?\]/', $seletor);
        $claro = !$escuroExplicito && (bool) preg_match('/\[data-theme=["\']?light["\']?\]/', $seletor);

        if ($tema === 'escuro') {
            if ($claro) return null;
            $modo = 'escuro';
            $novoSeletor = $escuroExplicito ? $seletor : self::prefixar($seletor, ':not([data-theme="light"])');
        } else {
            if ($escuroExplicito) return null;
            $modo = $claro ? 'claro' : 'claro-base';
            $novoSeletor = $claro ? $seletor : self::prefixar($seletor, '[data-theme="light"]');
        }

        // TODA declaração de cor entra, mude ou não (ver o erro 2 no topo): só
        // assim a disputa entre regras de mesma força termina como terminava.
        $decl = [];
        foreach (self::declaracoes($corpo) as [$prop, $valor]) {
            if (!self::ehDeCor($prop)) continue;
            // Valor montado em PHP chega aqui vazio ou pela metade: fica com a regra original.
            if (preg_match('/^(!important)?$/', $valor)) continue;
            $decl[] = $prop . ':' . self::trocarCores($valor, $modo, self::tipoDaPropriedade($prop));
        }
        return $decl ? $novoSeletor . '{' . implode(';', $decl) . '}' : null;
    }

    /** Propriedades em que uma cor pode estar, incluindo as variáveis. */
    private static function ehDeCor(string $prop): bool
    {
        $p = strtolower($prop);
        return str_starts_with($p, '--') || str_starts_with($p, 'background') || str_starts_with($p, 'border')
            || str_starts_with($p, 'outline')
            || in_array($p, ['color', 'fill', 'stroke', 'box-shadow', 'text-shadow', 'caret-color', '-webkit-text-fill-color',
                             'accent-color', 'scrollbar-color', 'column-rule', 'text-decoration', 'text-decoration-color',
                             'filter', 'backdrop-filter', '-webkit-backdrop-filter', 'opacity'], true);
    }

    /** Põe a condição do tema em cada seletor da lista, sem mudar a especificidade. */
    private static function prefixar(string $seletores, string $condicao): string
    {
        $saida = [];
        foreach (self::dividir($seletores, ',') as $s) {
            $s = trim($s);
            if ($s === '') continue;
            if (preg_match('/^(html|:root)(?![\w-])/', $s, $m)) {
                $saida[] = $m[1] . ':where(' . $condicao . ')' . substr($s, strlen($m[1]));
            } else {
                $saida[] = ':where(html' . $condicao . ') ' . $s;
            }
        }
        return implode(',', $saida);
    }

    private static function tipoDaPropriedade(string $prop): string
    {
        $p = strtolower($prop);
        if (str_starts_with($p, '--')) {
            if (preg_match('/text|texto|tinta|ink|label|muted|-fg|color$/', $p)) return 'texto';
            return preg_match('/border|borda|linha|line/', $p) ? 'borda' : 'fundo';
        }
        if (str_starts_with($p, 'background')) return 'fundo';
        if (str_starts_with($p, 'border') || str_starts_with($p, 'outline') || $p === 'box-shadow') return 'borda';
        if (in_array($p, ['color', 'fill', 'stroke', '-webkit-text-fill-color', 'caret-color', 'text-shadow'], true)) return 'texto';
        return 'outro';
    }

    public static function trocarCores(string $valor, string $tema, string $tipo): string
    {
        return (string) preg_replace_callback(
            '/#([0-9a-fA-F]{6})([0-9a-fA-F]{2})?(?![0-9a-zA-Z_-])|rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(,\s*[\d.]+%?\s*)?\)/',
            static function (array $m) use ($tema, $tipo): string {
                if (($m[1] ?? '') !== '') {
                    $rgb = [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))];
                    $novo = self::mapear($rgb, $tema, $tipo);
                    return $novo === null ? $m[0] : sprintf('#%02X%02X%02X', ...$novo) . ($m[2] ?? '');
                }
                $novo = self::mapear([(int) $m[3], (int) $m[4], (int) $m[5]], $tema, $tipo);
                if ($novo === null) return $m[0];
                $alfa = isset($m[6]) ? preg_replace('/\s+/', '', $m[6]) : '';
                return ($alfa !== '' ? 'rgba(' : 'rgb(') . implode(',', $novo) . $alfa . ')';
            },
            $valor
        );
    }

    /**
     * Regras de uma folha, na ordem, com o contexto (@media) de cada uma.
     * Fica de fora o que não se recolore: impressão, @keyframes, @font-face.
     *
     * @return array<int, array{ctx: string[], sel: string, corpo: string}>
     */
    private static function regras(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', ' ', $css);
        $n = strlen($css);
        $regras = [];
        $ctx = [];
        $inicio = 0;
        $i = 0;
        while ($i < $n) {
            $c = $css[$i];
            if ($c === '"' || $c === "'") { $i = self::fimDaString($css, $i); continue; }
            if ($c === ';') { $inicio = $i + 1; $i++; continue; }
            if ($c === '}') { array_pop($ctx); $inicio = $i + 1; $i++; continue; }
            if ($c !== '{') { $i++; continue; }

            $preludio = trim(substr($css, $inicio, $i - $inicio));
            if (preg_match('/^@(media|supports|container|layer)\b/i', $preludio)) {
                $ctx[] = (string) preg_replace('/\s+/', ' ', $preludio);
                $inicio = $i + 1; $i++;
                continue;
            }
            $fim = self::fimDoBloco($css, $i);
            if ($preludio !== '' && $preludio[0] !== '@' && !preg_grep('/\bprint\b/i', $ctx)) {
                $regras[] = ['ctx' => $ctx, 'sel' => (string) preg_replace('/\s+/', ' ', $preludio), 'corpo' => substr($css, $i + 1, $fim - $i - 1)];
            }
            $i = $fim + 1;
            $inicio = $i;
        }
        return $regras;
    }

    /** @return array<int, array{0: string, 1: string}> propriedade e valor */
    private static function declaracoes(string $corpo): array
    {
        $saida = [];
        foreach (self::dividir($corpo, ';') as $d) {
            $p = strpos($d, ':');
            if ($p === false) continue;
            $prop = trim(substr($d, 0, $p));
            $valor = trim(substr($d, $p + 1));
            if ($prop === '' || $valor === '' || !preg_match('/^-{0,2}[a-zA-Z][\w-]*$/', $prop)) continue;
            $saida[] = [$prop, (string) preg_replace('/\s+/', ' ', $valor)];
        }
        return $saida;
    }

    /** Divide no separador, fora de parênteses e de aspas. @return string[] */
    private static function dividir(string $texto, string $sep): array
    {
        $partes = []; $nivel = 0; $inicio = 0; $n = strlen($texto);
        for ($i = 0; $i < $n; $i++) {
            $c = $texto[$i];
            if ($c === '"' || $c === "'") { $i = self::fimDaString($texto, $i) - 1; continue; }
            if ($c === '(') $nivel++;
            elseif ($c === ')') $nivel = max(0, $nivel - 1);
            elseif ($c === $sep && $nivel === 0) { $partes[] = substr($texto, $inicio, $i - $inicio); $inicio = $i + 1; }
        }
        $partes[] = substr($texto, $inicio);
        return $partes;
    }

    /** Posição logo depois da aspa que fecha a string aberta em $i. */
    private static function fimDaString(string $t, int $i): int
    {
        $aspa = $t[$i]; $n = strlen($t);
        for ($j = $i + 1; $j < $n; $j++) {
            if ($t[$j] === '\\') { $j++; continue; }
            if ($t[$j] === $aspa || $t[$j] === "\n") return $j + 1;
        }
        return $n;
    }

    /** Posição da chave que fecha o bloco aberto em $i. */
    private static function fimDoBloco(string $t, int $i): int
    {
        $nivel = 0; $n = strlen($t);
        for ($j = $i; $j < $n; $j++) {
            $c = $t[$j];
            if ($c === '"' || $c === "'") { $j = self::fimDaString($t, $j) - 1; continue; }
            if ($c === '{') $nivel++;
            elseif ($c === '}' && --$nivel === 0) return $j;
        }
        return $n - 1;
    }
}
